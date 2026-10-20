<?php
/**
 * The full-page cache.
 *
 * Time to first byte is the one PageSpeed metric no amount of minification
 * moves: a tube home page is a query, twenty cards, six sidebars and a handful
 * of counters, and none of that work is needed twice for the same anonymous
 * visitor. The finished page is stored once and answered from disk on the next
 * request - before WordPress runs the query, not after.
 *
 * The cache is deliberately narrow. Only GET, only a visitor with no login,
 * comment or shop cookie, only a small allowlist of query arguments, never a
 * preview, a feed, a sitemap, a 404, a password-protected post or an admin
 * screen, and nothing whose response is not an HTML document. A site that
 * stores one personalized page has a privacy bug, which is why every escape
 * hatch - a cookie, a nonce in the URL, a POST, a plugin setting
 * DONOTCACHEPAGE - is a reason to render instead of a reason to store.
 *
 * @package Majestic Turbo
 */

defined( 'ABSPATH' ) || exit;

/**
 * Store and serve anonymous HTML responses.
 */
class Majestic_Turbo_Page_Cache {

	/**
	 * Query arguments a cached page may carry.
	 *
	 * Each one changes the markup in a way the key includes, so two views of an
	 * archive are two entries rather than one wrong one. Anything else - a
	 * tracking parameter, a nonce, a filter a plugin invented - makes the
	 * request uncacheable rather than part of a key that would grow without
	 * bound. The list itself lives in Majestic_Turbo_Key, which the drop-in
	 * includes too.
	 */

	/**
	 * Register the hooks.
	 *
	 * @return void
	 */
	public static function init() {
		/*
		 * The drop-in is written on the request after an option, theme or plugin
		 * change. Refreshing it here means a site that was updated by FTP still
		 * gets a correct drop-in on its next ordinary request, and a site that
		 * switched the cache off does not keep serving from the old one.
		 */
		self::refresh_dropin();

		if ( ! self::option_on( 'page_cache' ) ) {
			return;
		}

		add_action( 'plugins_loaded', array( __CLASS__, 'serve' ), 1 );
		add_action( 'template_redirect', array( __CLASS__, 'capture' ), 0 );

		/*
		 * Everything that changes markup a stored page already contains. A
		 * comment changes a count in the template as surely as an edit changes
		 * the post, and a widget moved in the customizer changes every page - a
		 * flush that missed either would serve yesterday's page until the
		 * lifetime ran out. None of these callbacks take the hook's arguments:
		 * the answer is always "throw it all away".
		 */
		$hooks = array(
			'save_post',
			'deleted_post',
			'trashed_post',
			'untrashed_post',
			'edit_term',
			'delete_term',
			'wp_update_nav_menu',
			'customize_save_after',
			'switch_theme',
			'update_option_sidebars_widgets',
			'comment_post',
			'edit_comment',
			'deleted_comment',
			'trashed_comment',
			'wp_set_comment_status',
			'transition_comment_status',
			'wp_update_nav_menu_item',
			'update_option_' . MAJESTIC_TURBO_OPTION,
		);

		foreach ( $hooks as $hook ) {
			add_action( $hook, array( __CLASS__, 'flush' ), 10, 0 );
		}

		/*
		 * Options that are printed on every page rather than stored in one post.
		 * The site title and tagline sit in the document title and the header,
		 * the permalink structure is in every link, and the reading settings
		 * decide what the front page and the archives contain - none of them
		 * fires save_post, so without these a stored page keeps the old value
		 * until its lifetime runs out.
		 */
		$global_options = array(
			'blogname',
			'blogdescription',
			'permalink_structure',
			'posts_per_page',
			'show_on_front',
			'page_on_front',
			'page_for_posts',
			'WPLANG',
			'timezone_string',
			'gmt_offset',
			'date_format',
			'time_format',
			'start_of_week',
		);

		foreach ( $global_options as $option ) {
			add_action( 'update_option_' . $option, array( __CLASS__, 'flush' ), 10, 0 );
		}

		/*
		 * The drop-in embeds the theme version, the plugin version and the
		 * lifetime. These are the moments those change, and waiting for the next
		 * request would let the old drop-in answer one page first.
		 */
		foreach ( array( 'switch_theme', 'upgrader_process_complete', 'update_option_' . MAJESTIC_TURBO_OPTION ) as $hook ) {
			add_action( $hook, array( __CLASS__, 'refresh_dropin' ), 20, 0 );
		}
	}

	/**
	 * Serve a stored page before WordPress queries anything.
	 *
	 * @return void
	 */
	public static function serve() {
		if ( ! self::is_cacheable() ) {
			return;
		}

		$key  = self::key();
		$html = Majestic_Turbo_Cache::get( 'page-cache/' . $key . '.html.gz', self::ttl() );

		if ( false === $html || '' === $html ) {
			return;
		}

		$meta = Majestic_Turbo_Cache::get( 'page-cache/' . $key . '.json' );
		$meta = $meta ? json_decode( $meta, true ) : array();
		$html = self::decode( $html );

		if ( '' === $html ) {
			return;
		}

		$meta = is_array( $meta ) ? $meta : array();

		/*
		 * A browser is told max-age=0, so it revalidates on every visit. Without
		 * a 304 that revalidation costs the whole page again; with one it costs
		 * a header line, and the page the browser already has stays valid.
		 */
		if ( self::is_not_modified( $meta ) ) {
			self::send_not_modified( $meta );
		}

		self::send_headers( 'HIT', $meta );

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- the stored page is the site's own escaped output.
		echo $html;
		exit;
	}

	/**
	 * Open the buffer that stores the page WordPress renders.
	 *
	 * @return void
	 */
	public static function capture() {
		if ( ! self::is_cacheable() || is_404() || is_search() || is_preview() || is_feed() ) {
			return;
		}

		$post = get_queried_object();

		if ( $post instanceof WP_Post && post_password_required( $post ) ) {
			return;
		}

		ob_start( array( __CLASS__, 'store' ) );
	}

	/**
	 * Store the finished page and hand it back to the visitor.
	 *
	 * @param string $html Page markup.
	 * @return string
	 */
	public static function store( $html ) {
		$html = (string) $html;

		if ( '' === trim( $html ) || strlen( $html ) > 4 * MB_IN_BYTES ) {
			return $html;
		}

		if ( defined( 'DONOTCACHEPAGE' ) && DONOTCACHEPAGE ) {
			return $html;
		}

		if ( 200 !== (int) http_response_code() ) {
			return $html;
		}

		// A response that sets a cookie is a response built for one visitor -
		// a comment author, a shop session, a login redirect - and storing it
		// would hand that visitor's page to the next one.
		foreach ( headers_list() as $header ) {
			if ( 0 === stripos( $header, 'set-cookie:' ) ) {
				return $html;
			}
		}

		/*
		 * Only a document belongs in the cache, and only a document may have its
		 * content type replaced below. A sitemap, a feed and a plugin's JSON
		 * endpoint are all 200 responses to an anonymous GET, so none of them can
		 * be recognized by status or method alone; the header the response
		 * already carries is what tells them apart. Before this check a stored
		 * sitemap was answered as text/html, which is a sitemap a crawler cannot
		 * read.
		 */
		if ( ! self::is_document( self::content_type() ) ) {
			return $html;
		}

		$key  = self::key();
		$file = 'page-cache/' . $key;

		Majestic_Turbo_Cache::put( $file . '.html.gz', self::encode( $html ) );
		Majestic_Turbo_Cache::put(
			$file . '.json',
			(string) wp_json_encode(
				array(
					'url'   => home_url( isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '/' ),
					'time'  => time(),
					'bytes' => strlen( $html ),
				)
			)
		);

		self::send_headers( 'MISS' );
		self::prune();

		return $html;
	}

	/**
	 * The Content-Type the response is going out with.
	 *
	 * WordPress sets a content type for everything it answers, and PHP sends
	 * text/html when it does not. Reading the header that is already queued
	 * keeps this decision on the response itself rather than on a guess about
	 * which request made it.
	 *
	 * @return string
	 */
	private static function content_type() {
		foreach ( headers_list() as $header ) {
			if ( 0 === stripos( $header, 'content-type:' ) ) {
				return trim( substr( $header, strlen( 'content-type:' ) ) );
			}
		}

		return '';
	}

	/**
	 * Whether a content type names an HTML document.
	 *
	 * An empty value counts as a document: a normal page has no explicit
	 * Content-Type yet when the buffer closes, and the web server will send
	 * text/html for it.
	 *
	 * @param string $type Content type header value.
	 * @return bool
	 */
	private static function is_document( $type ) {
		$type = strtolower( trim( (string) $type ) );

		if ( '' === $type ) {
			return true;
		}

		return 0 === strpos( $type, 'text/html' ) || 0 === strpos( $type, 'application/xhtml+xml' );
	}

	/**
	 * Delete every stored page.
	 *
	 * @return int Files removed.
	 */
	public static function flush() {
		return Majestic_Turbo_Cache::purge( 'page-cache' );
	}

	/**
	 * Forget the cache entirely, including the directory.
	 *
	 * @return void
	 */
	public static function forget() {
		self::flush();
	}

	/**
	 * Whether the current request may be answered from, and stored in, the
	 * cache.
	 *
	 * @return bool
	 */
	public static function is_cacheable() {
		if ( ! self::option_on( 'page_cache' ) ) {
			return false;
		}

		if ( is_admin() || wp_doing_ajax() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) || ( defined( 'WP_CLI' ) && WP_CLI ) ) {
			return false;
		}

		if ( defined( 'DONOTCACHEPAGE' ) && DONOTCACHEPAGE ) {
			return false;
		}

		$method = isset( $_SERVER['REQUEST_METHOD'] ) ? (string) wp_unslash( $_SERVER['REQUEST_METHOD'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- a request method is compared, never stored or printed.
		$uri    = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- read-only routing: the URI is parsed and hashed, never printed.

		if ( ! Majestic_Turbo_Key::allowed( $method, $uri, array_keys( (array) $_COOKIE ) ) ) {
			return false;
		}

		/**
		 * Filter whether the current request may use the page cache.
		 *
		 * The early drop-in cannot run filters: it answers before plugins load.
		 * A site that needs this filter for correctness should switch the early
		 * cache off and keep the in-plugin one.
		 *
		 * @param bool $cacheable Whether the request is cacheable.
		 */
		return (bool) apply_filters( 'majestic_turbo_page_cacheable', true );
	}

	/**
	 * The key one request's page is stored under.
	 *
	 * Everything that changes the markup is in here: the host, the path, the
	 * allowed query arguments, whether the theme served the mobile variant, and
	 * the theme and plugin versions, so an update never serves yesterday's
	 * page. A salted hash keeps the stored filename free of any part of a URL a
	 * visitor could steer.
	 *
	 * @return string
	 */
	public static function key() {
		$uri = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) : '/'; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- read-only routing: the URI is parsed and hashed, never printed.

		/*
		 * The theme's version comes from the stylesheet, not from a constant the
		 * theme defines: the cache is served on plugins_loaded, before the theme
		 * is loaded, so a constant read there is undefined while the same read at
		 * template time is not - and the two would key the same page differently,
		 * which is a cache that never hits. The drop-in reads the same version
		 * from its generated configuration.
		 */
		$theme = wp_get_theme();

		return Majestic_Turbo_Key::hash(
			array(
				'host'   => (string) wp_parse_url( home_url(), PHP_URL_HOST ),
				'path'   => (string) wp_parse_url( $uri, PHP_URL_PATH ),
				'query'    => Majestic_Turbo_Key::query( $uri ),
				'mobile'   => function_exists( 'wp_is_mobile' ) && wp_is_mobile() ? 'mobile' : 'desktop',
				'language' => Majestic_Turbo_Key::language( $_COOKIE ), // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- a cookie value that is hashed, never printed or stored.
				'theme'    => $theme ? (string) $theme->get( 'Version' ) : '',
				/*
				 * The stored file sits in uploads, and on nginx the .htaccess guard
				 * beside it does nothing: a site secret in the hash is what stops a
				 * visitor from computing the filename of a page they did not store.
				 * It also forces every entry to miss when the salt is rotated.
				 */
				'salt'   => Majestic_Turbo_Key::site_salt(),
				'plugin' => self::plugin_version(),
			)
		);
	}

	/**
	 * Whether the browser's copy is still current.
	 *
	 * Only If-Modified-Since is honoured: the cache stores no validator other
	 * than the time it was written, and an entry is replaced rather than
	 * edited, so that time is a faithful last-modified date.
	 *
	 * @param array $meta Stored metadata.
	 * @return bool
	 */
	private static function is_not_modified( $meta ) {
		if ( empty( $meta['time'] ) || empty( $_SERVER['HTTP_IF_MODIFIED_SINCE'] ) ) {
			return false;
		}

		$since = strtotime( (string) wp_unslash( $_SERVER['HTTP_IF_MODIFIED_SINCE'] ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- parsed as a date and compared, never printed.

		if ( false === $since || $since <= 0 ) {
			return false;
		}

		return (int) $meta['time'] <= $since;
	}

	/**
	 * Answer a revalidation with 304 Not Modified.
	 *
	 * A 304 carries no body, so the page is not read from disk at all - the
	 * metadata is enough to decide. It is sent without Content-Type, which a
	 * 304 must not need.
	 *
	 * @param array $meta Stored metadata.
	 * @return void
	 */
	private static function send_not_modified( $meta ) {
		if ( headers_sent() ) {
			return;
		}

		if ( ! empty( $meta['time'] ) ) {
			header( 'Last-Modified: ' . gmdate( 'D, d M Y H:i:s', (int) $meta['time'] ) . ' GMT' );
		}

		header( 'X-Majestic-Turbo-Cache: HIT' );
		header( 'Vary: Accept-Encoding, Cookie, User-Agent' );
		header( 'Cache-Control: public, max-age=0, s-maxage=' . majestic_turbo_cache_ttl() . ', stale-while-revalidate=30' );
		status_header( 304 );

		exit;
	}

	/**
	 * Send the headers a cached page needs.
	 *
	 * @param string $status Either HIT or MISS.
	 * @param array  $meta   Stored metadata.
	 * @return void
	 */
	private static function send_headers( $status, $meta = array() ) {
		if ( headers_sent() ) {
			return;
		}

		if ( ! empty( $meta['time'] ) ) {
			header( 'Last-Modified: ' . gmdate( 'D, d M Y H:i:s', (int) $meta['time'] ) . ' GMT' );
		}

		header( 'Content-Type: text/html; charset=' . get_bloginfo( 'charset' ) );
		header( 'X-Majestic-Turbo-Cache: ' . $status );

		/*
		 * User-Agent is in Vary because the key carries it: a theme that serves
		 * a different page to a phone is stored under its own key, and a shared
		 * cache told only about Accept-Encoding and Cookie would hand the
		 * desktop variant to the next phone - or the phone's to a desktop.
		 */
		header( 'Vary: Accept-Encoding, Cookie, User-Agent' );

		/*
		 * max-age=0 keeps a shared proxy from reusing the page for a signed-in
		 * visitor, while s-maxage lets a CDN hold it for the same lifetime the
		 * local cache uses. A browser therefore always revalidates against the
		 * edge, and the edge answers without touching the origin.
		 */
		$ttl = self::ttl();
		header( 'Cache-Control: public, max-age=0, s-maxage=' . $ttl . ', stale-while-revalidate=30' );
	}

	/**
	 * Encode a page for storage.
	 *
	 * Storing gzip means a ten thousand entry cache is a third of the size on
	 * disk, and the bytes are only decompressed for the response that is being
	 * sent anyway.
	 *
	 * @param string $html Markup.
	 * @return string
	 */
	private static function encode( $html ) {
		if ( function_exists( 'gzencode' ) ) {
			$compressed = gzencode( $html, 6 );

			if ( false !== $compressed ) {
				return "\x1F" . 'MT' . $compressed;
			}
		}

		return $html;
	}

	/**
	 * Decode a stored page.
	 *
	 * @param string $stored Stored bytes.
	 * @return string
	 */
	private static function decode( $stored ) {
		if ( 0 === strpos( $stored, "\x1F" . 'MT' ) ) {
			$html = function_exists( 'gzdecode' ) ? gzdecode( substr( $stored, 3 ) ) : false;

			return false === $html ? '' : $html;
		}

		return $stored;
	}

	/**
	 * The plugin version the key is salted with.
	 *
	 * @return string
	 */
	private static function plugin_version() {
		return defined( 'MAJESTIC_TURBO_VERSION' ) ? (string) MAJESTIC_TURBO_VERSION : '';
	}

	/**
	 * A switch, read without assuming the plugin's helpers exist.
	 *
	 * The page cache runs in the plugin, where the helpers are defined, and in
	 * the early drop-in, where they are not. Keeping the default here avoids a
	 * second definition of what an unset option means.
	 *
	 * @param string $key Option key.
	 * @return bool
	 */
	private static function option_on( $key ) {
		if ( function_exists( 'majestic_turbo_option_is_on' ) ) {
			return majestic_turbo_option_is_on( $key );
		}

		$defaults = array(
			'page_cache' => 'on',
			'early_cache' => 'on',
		);
		$options  = get_option( 'majestic_turbo_options', array() );
		$default  = isset( $defaults[ $key ] ) ? $defaults[ $key ] : 'off';

		return is_array( $options ) && array_key_exists( $key, $options )
			? 'on' === $options[ $key ]
			: 'on' === $default;
	}

	/**
	 * The cache lifetime in seconds, with the plugin's helper or without it.
	 *
	 * @return int
	 */
	private static function ttl() {
		if ( function_exists( 'majestic_turbo_cache_ttl' ) ) {
			return majestic_turbo_cache_ttl();
		}

		$options = get_option( 'majestic_turbo_options', array() );
		$minutes = is_array( $options ) && isset( $options['page_cache_ttl'] ) ? (int) $options['page_cache_ttl'] : 60;

		return max( 60, $minutes * 60 );
	}

	/**
	 * Path of the WordPress drop-in.
	 *
	 * @return string
	 */
	public static function dropin_path() {
		return defined( 'WP_CONTENT_DIR' ) ? WP_CONTENT_DIR . '/advanced-cache.php' : '';
	}

	/**
	 * Whether the drop-in on disk was written by this plugin.
	 *
	 * Any other caching plugin's drop-in is left alone. Two of them cannot
	 * coexist: WordPress includes one file, so whichever wrote last would win.
	 *
	 * @param string $path Absolute path.
	 * @return bool
	 */
	public static function dropin_is_ours( $path ) {
		if ( '' === (string) $path || ! is_file( $path ) ) {
			return false;
		}

		$source = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reading the drop-in to identify it.

		return false !== $source && false !== strpos( $source, 'Majestic Turbo early cache' );
	}

	/**
	 * Whether another plugin owns the drop-in slot.
	 *
	 * @return bool
	 */
	public static function has_foreign_dropin() {
		$path = self::dropin_path();

		return '' !== $path && is_file( $path ) && ! self::dropin_is_ours( $path );
	}

	/**
	 * The drop-in source for the site's current configuration.
	 *
	 * Everything the early path needs is embedded: the store path, the site
	 * host, the theme and plugin versions, the lifetime and the charset. The
	 * class it calls reads the request and the files; nothing here needs an
	 * option, a filter or a database.
	 *
	 * @return string
	 */
	private static function dropin_source() {
		$dir = Majestic_Turbo_Cache::root();

		if ( '' === $dir ) {
			return '';
		}

		$theme  = wp_get_theme();
		$config = array(
			'enabled' => self::option_on( 'page_cache' ) && self::option_on( 'early_cache' ),
			'dir'     => wp_normalize_path( $dir ),
			'ttl'     => self::ttl(),
			'host'    => (string) wp_parse_url( home_url(), PHP_URL_HOST ),
			'theme'   => $theme ? (string) $theme->get( 'Version' ) : '',
			'plugin'  => defined( 'MAJESTIC_TURBO_VERSION' ) ? (string) MAJESTIC_TURBO_VERSION : '',
			'charset' => (string) get_bloginfo( 'charset' ),
		);

		$key_file   = wp_normalize_path( MAJESTIC_TURBO_DIR . 'inc/class-turbo-key.php' );
		$early_file = wp_normalize_path( MAJESTIC_TURBO_DIR . 'inc/class-turbo-early.php' );

		/*
		 * A drop-in that answers before the plugin loads can outlive the files it
		 * was generated for: after an update it would keep serving entries stored
		 * under the old version until they expired, and never the new ones. The
		 * modification times are baked in and compared before anything is served;
		 * a changed file makes this an ordinary request, WordPress loads, and the
		 * plugin rewrites the drop-in.
		 */
		$stamps = array();

		foreach ( array( get_stylesheet_directory() . '/style.css', get_template_directory() . '/style.css', MAJESTIC_TURBO_DIR . 'majestic-turbo.php' ) as $file ) {
			$file = wp_normalize_path( $file );

			if ( is_file( $file ) ) {
				$stamps[ $file ] = (int) filemtime( $file );
			}
		}

		return "<?php\n"
			. "/**\n"
			. " * Majestic Turbo early cache.\n"
			. " *\n"
			. " * Generated by the plugin. Do not edit: it is rewritten when an option,\n"
			. " * the theme or the plugin changes, and removed when the cache is switched off.\n"
			. " */\n\n"
			. "if ( ! defined( 'ABSPATH' ) ) {\n\treturn;\n}\n\n"
			/*
			 * A plugin directory removed by FTP leaves this file behind. The
			 * guard turns that into an ordinary uncached request instead of a
			 * fatal on files that no longer exist.
			 */
			. '$mt_key   = ' . var_export( $key_file, true ) . ";\n"
			. '$mt_early = ' . var_export( $early_file, true ) . ";\n\n"
			. "if ( ! is_file( \$mt_key ) || ! is_file( \$mt_early ) ) {\n\treturn;\n}\n\n"
			. '$mt_stamps = ' . var_export( $stamps, true ) . ";\n\n"
			. "foreach ( \$mt_stamps as \$mt_file => \$mt_time ) {\n\tif ( ! is_file( \$mt_file ) || filemtime( \$mt_file ) !== \$mt_time ) {\n\t\treturn;\n\t}\n}\n\n"
			. "require_once \$mt_key;\n"
			. "require_once \$mt_early;\n\n"
			. 'Majestic_Turbo_Early::serve( ' . var_export( $config, true ) . " );\n";
	}

	/**
	 * Write the drop-in, or remove it when early serving is off.
	 *
	 * Called on every request, and on the hooks that change what it embeds. A
	 * file that already matches is not touched, so a read-only wp-content costs
	 * nothing but the comparison.
	 *
	 * @return void
	 */
	public static function refresh_dropin() {
		$path = self::dropin_path();

		if ( '' === $path ) {
			return;
		}

		if ( ! self::option_on( 'page_cache' ) || ! self::option_on( 'early_cache' ) ) {
			self::remove_dropin();

			return;
		}

		if ( is_file( $path ) && ! self::dropin_is_ours( $path ) ) {
			// Another plugin owns the slot; overwriting it would break that
			// plugin's cache, and the in-plugin cache still works below.
			return;
		}

		$source = self::dropin_source();

		if ( '' === $source ) {
			return;
		}

		if ( is_file( $path ) ) {
			$current = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- comparing our own generated file.

			if ( $current === $source ) {
				return;
			}
		}

		file_put_contents( $path, $source, LOCK_EX ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- the WordPress drop-in this plugin owns.
	}

	/**
	 * Install the drop-in.
	 *
	 * @return bool
	 */
	public static function install_dropin() {
		self::refresh_dropin();

		return self::dropin_is_ours( self::dropin_path() );
	}

	/**
	 * Remove the drop-in, if this plugin wrote it.
	 *
	 * @return void
	 */
	public static function remove_dropin() {
		$path = self::dropin_path();

		if ( '' !== $path && self::dropin_is_ours( $path ) ) {
			@unlink( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_unlink -- a generated file this plugin owns.
		}
	}

	/**
	 * Delete entries that are past twice their lifetime.
	 *
	 * @return void
	 */
	private static function prune() {
		// One request in fifty is enough to keep a busy site tidy without
		// walking the directory on every store.
		if ( 1 !== wp_rand( 1, 50 ) ) {
			return;
		}

		$directory = Majestic_Turbo_Cache::path( 'page-cache' );

		if ( '' === $directory || ! is_dir( $directory ) ) {
			return;
		}

		$expires = time() - ( self::ttl() * 2 );
		$files   = glob( $directory . '/*' );

		foreach ( (array) $files as $file ) {
			if ( is_file( $file ) && filemtime( $file ) < $expires ) {
				@unlink( $file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_unlink -- best effort on a stale cache entry.
			}
		}
	}
}
