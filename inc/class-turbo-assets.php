<?php
/**
 * Asset pipeline: combine and minify local stylesheets, minify and defer local
 * scripts, and drop the block styles a classic page never uses.
 *
 * WordPress offers no hook that says "this is the final list of stylesheets";
 * wp_enqueue_scripts is the last moment before wp_head prints them, so the
 * queue is rebuilt there at the end of the line. Every handle that is not a
 * local file is left exactly as it was, and every handle that is gets replaced
 * by a generated file with the same position in the cascade.
 *
 * Combining is safe only because Majestic_Turbo_CSS::urls_absolute() resolves
 * each sheet's relative references against the sheet it came from: a combined
 * file in uploads carries no relationship to the theme's assets directory, so
 * a `../fonts/x.woff2` has to become absolute before the two are one file.
 *
 * @package Majestic Turbo
 */

defined( 'ABSPATH' ) || exit;

/**
 * Rebuild the enqueued assets as fast, cacheable files.
 */
class Majestic_Turbo_Assets {

	/**
	 * Handle of the combined stylesheet.
	 */
	const HANDLE = 'majestic-turbo-css';

	/**
	 * The stylesheet the current request uses.
	 *
	 * Filled by styles() and read by the page transformer, which needs the file
	 * to reduce it against the markup that was just rendered.
	 *
	 * @var array{path: string, url: string, key: string}|null
	 */
	private static $bundle = null;

	/**
	 * Inline CSS WordPress attached to the handles that were combined.
	 *
	 * @var string[]
	 */
	private static $inline_css = array();

	/**
	 * Register the hooks.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'strip_block_css' ), 9998 );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'styles' ), 9999 );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'scripts' ), 9999 );
		add_filter( 'style_loader_tag', array( __CLASS__, 'mark_bundle' ), 10, 2 );
		add_filter( 'wp_resource_hints', array( __CLASS__, 'resource_hints' ), 10, 2 );
	}

	/**
	 * The stylesheet this request built, if any.
	 *
	 * @return array{path: string, url: string, key: string}|null
	 */
	public static function bundle() {
		return self::$bundle;
	}

	/**
	 * Inline CSS that belonged to a combined handle.
	 *
	 * @return string[]
	 */
	public static function inline_css() {
		return self::$inline_css;
	}

	/**
	 * Whether the current page can contain blocks at all.
	 *
	 * The block library and the global stylesheet are two of the biggest
	 * stylesheets on a page and a classic tube template uses neither: every
	 * card, header and footer is theme markup, not a block. The check is
	 * deliberately pessimistic - one block in the content, one block widget in
	 * any sidebar, a block theme, or a site builder's canvas and the CSS stays
	 * - because a false positive leaves byte savings on the table while a false
	 * negative renders a page with unstyled blocks.
	 *
	 * @return bool
	 */
	public static function page_has_blocks() {
		if ( function_exists( 'wp_is_block_theme' ) && wp_is_block_theme() ) {
			return true;
		}

		if ( is_singular() ) {
			$post = get_post();

			if ( $post && function_exists( 'has_blocks' ) && has_blocks( $post->post_content ) ) {
				return true;
			}
		}

		$sidebars = wp_get_sidebars_widgets();

		foreach ( (array) $sidebars as $widgets ) {
			foreach ( (array) $widgets as $widget ) {
				if ( 0 === strpos( (string) $widget, 'block-' ) ) {
					return true;
				}
			}
		}

		/**
		 * Filter whether the current page needs the block library CSS.
		 *
		 * @param bool $has_blocks Whether a block was found.
		 */
		return (bool) apply_filters( 'majestic_turbo_page_has_blocks', false );
	}

	/**
	 * Dequeue the block stylesheets a classic page does not use.
	 *
	 * Gutenberg's styles are printed on every page whether or not a block is on
	 * it, and the global stylesheet carries the theme.json tokens a block would
	 * consume. On a classic theme's templates neither is used; on a page with a
	 * block, a block widget or a block theme both stay.
	 *
	 * @return void
	 */
	public static function strip_block_css() {
		if ( ! majestic_turbo_option_is_on( 'strip_block_css' ) || self::page_has_blocks() ) {
			return;
		}

		foreach ( array( 'wp-block-library', 'wp-block-library-theme', 'global-styles', 'classic-theme-styles' ) as $handle ) {
			wp_dequeue_style( $handle );
		}
	}

	/**
	 * Replace the local stylesheets with one minified, combined file.
	 *
	 * @return void
	 */
	public static function styles() {
		$combine = majestic_turbo_option_is_on( 'combine_css' );
		$minify  = majestic_turbo_option_is_on( 'minify_css' );

		if ( ! $combine && ! $minify ) {
			return;
		}

		$styles = wp_styles();
		$queue  = $styles->queue;

		if ( ! $queue ) {
			return;
		}

		$excluded = majestic_turbo_option_lines( 'exclude_css' );
		$parts    = array();
		$inline   = array();
		$handles  = array();
		$prepared = array();

		foreach ( $queue as $handle ) {
			if ( self::HANDLE === $handle || ! isset( $styles->registered[ $handle ] ) ) {
				continue;
			}

			$registered = $styles->registered[ $handle ];
			$media      = isset( $registered->args ) && is_string( $registered->args ) ? strtolower( trim( $registered->args ) ) : '';

			/*
			 * A stylesheet with a media attribute is only meant to apply
			 * somewhere - a print sheet, a sheet for one breakpoint. The bundle
			 * has a single media attribute of its own, so folding that sheet into
			 * it would apply its rules everywhere: the print rules would print,
			 * and the breakpoint's rules would stop being conditional. Such a
			 * sheet keeps its own link and is minified in place.
			 */
			$scoped = '' !== $media && 'all' !== $media;

			$source = self::local_file( $registered->src );

			if ( ! $source || self::is_excluded( $handle, $registered->src, $excluded ) ) {
				continue;
			}

			/*
			 * Core's stylesheets stay where core put them. The block library is
			 * already handled by strip_block_css() and the global styles are
			 * printed inline; what is left - dashicons, the admin bar - belongs to
			 * screens this plugin should not be rewriting, and bundling it with
			 * the theme's rules would put core's UI behind the reducer as well.
			 */
			if ( false !== strpos( $source['path'], '/wp-includes/' ) ) {
				continue;
			}

			$css = file_get_contents( $source['path'] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- a theme or plugin stylesheet this pipeline is processing.

			if ( false === $css || '' === trim( $css ) ) {
				continue;
			}

			$css = Majestic_Turbo_CSS::urls_absolute( $css, dirname( $source['url'] ) );

			if ( $minify ) {
				$css = Majestic_Turbo_CSS::minify( $css );
			}

			if ( '' === $css ) {
				continue;
			}

			if ( ! $scoped ) {
				/*
				 * The handle is printed as a comment so a human reading the bundle
				 * can tell where a rule came from - unless the sheet is minified,
				 * because the reducer detects "already minified" by looking for a
				 * newline or a comment. A bundle carrying either would be
				 * re-minified on every render, which is the most expensive thing
				 * this class does. The handle is stripped to the characters a
				 * handle is allowed to have so a stray comment terminator in it
				 * cannot end the comment early.
				 */
				$parts[] = $minify
					? $css
					: '/* ' . preg_replace( '/[^a-z0-9_-]/i', '', $handle ) . " */\n" . $css;

				$handles[] = $handle;

				/*
				 * The inline CSS a handle carried has to follow the rule it was
				 * attached to. It is collected only for a handle that is going to
				 * be removed from the queue: a scoped sheet is still printed, and
				 * WordPress prints its inline block itself.
				 */
				$after = $styles->get_data( $handle, 'after' );

				if ( is_array( $after ) ) {
					foreach ( $after as $block ) {
						if ( is_string( $block ) && '' !== trim( $block ) ) {
							$inline[] = $minify ? Majestic_Turbo_CSS::minify( $block ) : $block;
						}
					}
				}
			}

			$prepared[ $handle ] = array(
				'css'    => $css,
				'url'    => $source['url'],
				'scoped' => $scoped,
			);
		}

		if ( ! $prepared ) {
			return;
		}

		self::$inline_css = array_values( array_filter( $inline ) );

		/*
		 * Sheets that keep a request of their own: every one of them in
		 * minify-only mode, and the media-scoped ones when combining. A scoped
		 * sheet is only rewritten when minifying gives it something new to
		 * write.
		 */
		$written = array();

		foreach ( $prepared as $handle => $item ) {
			if ( $combine && ( ! $item['scoped'] || ! $minify ) ) {
				continue;
			}

			$key  = md5( $item['url'] . '|' . MAJESTIC_TURBO_VERSION . '|' . md5( $item['css'] ) );
			$file = 'css/' . sanitize_file_name( $handle ) . '-' . $key . '.css';

			if ( ! Majestic_Turbo_Cache::put_once( $file, $item['css'] ) ) {
				continue;
			}

			$styles->registered[ $handle ]->src = Majestic_Turbo_Cache::file_url( $file );
			$styles->registered[ $handle ]->ver = null;

			// Every one of these files is named by the page that was just
			// built, so none of them may be pruned as "old".
			$written[] = $file;
		}

		if ( ! $combine ) {
			Majestic_Turbo_Cache::prune( 'css', 12, $written );

			// The inline blocks stay on the handles that carried them: every
			// handle is still printed, so re-adding them would print the same
			// rules twice.
			return;
		}

		if ( ! $parts ) {
			// Only media-scoped sheets on this page: nothing to bundle, and
			// their inline blocks are still on their own handles.
			self::$inline_css = array();

			Majestic_Turbo_Cache::prune( 'css', 12, $written );

			return;
		}

		$signature = md5( implode( '|', array( MAJESTIC_TURBO_VERSION, (string) $minify, implode( '||', $parts ) ) ) );
		$file      = 'css/bundle-' . $signature . '.css';
		$path      = Majestic_Turbo_Cache::path( $file );

		if ( '' === $path ) {
			return;
		}

		// A minified sheet is joined with nothing between the parts, so the
		// bundle has no line break and the reducer can tell it is already
		// minified. An unminified one keeps a newline per sheet, which is what
		// makes the finished file readable beside its comments.
		if ( ! Majestic_Turbo_Cache::put_once( $file, implode( $minify ? '' : "\n", $parts ) ) ) {
			return;
		}

		Majestic_Turbo_Cache::prune( 'css', 12, array_merge( $written, array( $file ) ) );

		// Removing by value rather than by the index array_search() returned:
		// a handle that was not found would come back as false, and unset()
		// with a false key empties the first entry of the queue.
		$styles->queue = array_values( array_diff( $styles->queue, $handles ) );

		foreach ( $handles as $handle ) {
			$styles->done[] = $handle;
		}

		wp_enqueue_style(
			self::HANDLE,
			Majestic_Turbo_Cache::file_url( $file ),
			array(),
			$signature
		);

		// The collected inline blocks follow the combined sheet, in the order
		// the queue had them. They cannot ride on the old handles - those are
		// no longer printed - so they are attached to the one handle that is.
		if ( self::$inline_css ) {
			wp_add_inline_style( self::HANDLE, implode( "\n", self::$inline_css ) );
		}

		self::$bundle = array(
			'path' => $path,
			'url'  => Majestic_Turbo_Cache::file_url( $file ),
			'key'  => $signature,
		);
	}

	/**
	 * Minify the local scripts and give them a loading strategy.
	 *
	 * @return void
	 */
	public static function scripts() {
		$minify = majestic_turbo_option_is_on( 'minify_js' );
		$defer  = majestic_turbo_option_is_on( 'defer_js' );

		if ( ! $minify && ! $defer ) {
			return;
		}

		$scripts  = wp_scripts();
		$excluded = majestic_turbo_option_lines( 'exclude_js' );
		$written  = array();

		foreach ( $scripts->queue as $handle ) {
			if ( ! isset( $scripts->registered[ $handle ] ) ) {
				continue;
			}

			$registered = $scripts->registered[ $handle ];

			if ( ! $registered->src ) {
				continue;
			}

			if ( self::is_excluded( $handle, $registered->src, $excluded ) ) {
				continue;
			}

			$source = self::local_file( $registered->src );

			if ( ! $source ) {
				continue;
			}

			/*
			 * Core's own scripts are already minified and are not this plugin's
			 * to rewrite: touching them would put a generated copy of WordPress in
			 * uploads, where the next security release could not replace it. They
			 * stay render-blocking for the same reason they are not deferred -
			 * jQuery and its inline dependents in a theme are written for a
			 * parser-blocking `$`, and a deferred jQuery is the classic way to
			 * break a theme that assumes otherwise. A site that wants it can move
			 * the script itself.
			 */
			if ( false !== strpos( $source['path'], '/wp-includes/' ) ) {
				continue;
			}

			if ( $minify ) {
				$javascript = file_get_contents( $source['path'] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- a theme or plugin script.

				if ( false !== $javascript && '' !== trim( $javascript ) ) {
					$minified  = Majestic_Turbo_JS::minify( $javascript );
					$signature = md5( $source['url'] . '|' . MAJESTIC_TURBO_VERSION . '|' . md5( $minified ) );
					$file      = 'js/' . sanitize_file_name( basename( $source['path'], '.js' ) ) . '-' . $signature . '.js';

					if ( '' !== $minified && Majestic_Turbo_Cache::put_once( $file, $minified ) ) {
						$scripts->registered[ $handle ]->src = Majestic_Turbo_Cache::file_url( $file );
						$scripts->registered[ $handle ]->ver = null;

						$written[] = $file;
					}
				}
			}

			$strategy = isset( $scripts->registered[ $handle ]->extra['strategy'] ) ? (string) $scripts->registered[ $handle ]->extra['strategy'] : '';

			/**
			 * Filter whether one script may be deferred.
			 *
			 * A script that another inline block reads synchronously, or one
			 * that writes into the document while it parses, must not be
			 * deferred. Excluding it here is the supported way to say so.
			 *
			 * @param bool   $can_defer Whether the script can be deferred.
			 * @param string $handle    Script handle.
			 */
			if ( $defer && '' === $strategy && apply_filters( 'majestic_turbo_defer_script', true, $handle ) ) {
				wp_script_add_data( $handle, 'strategy', 'defer' );
			}
		}

		// Once, after the queue rather than once per script: the files just
		// written are the ones the page names, so they are never the oldest.
		if ( $written ) {
			Majestic_Turbo_Cache::prune( 'js', 12, $written );
		}
	}

	/**
	 * Mark the combined stylesheet's tag so the page transformer can find it.
	 *
	 * @param string $tag    The link element.
	 * @param string $handle Style handle.
	 * @return string
	 */
	public static function mark_bundle( $tag, $handle ) {
		if ( self::HANDLE !== $handle ) {
			return $tag;
		}

		return str_replace( '<link ', '<link data-turbo="css" ', $tag );
	}

	/**
	 * Announce the hosts an administrator listed.
	 *
	 * @param array  $urls          Resource hints.
	 * @param string $relation_type Hint type.
	 * @return array
	 */
	public static function resource_hints( $urls, $relation_type ) {
		if ( 'preconnect' !== $relation_type ) {
			return $urls;
		}

		foreach ( majestic_turbo_option_lines( 'preconnect' ) as $host ) {
			$host = preg_replace( '#^https?://#i', '', $host );
			$host = rtrim( $host, '/' );

			if ( '' === $host || false !== strpos( $host, ' ' ) ) {
				continue;
			}

			$urls[] = array(
				'href'        => 'https://' . $host,
				'crossorigin' => 'anonymous',
			);
		}

		return $urls;
	}

	/**
	 * Resolve a WordPress asset URL to a readable local file.
	 *
	 * Anything hosted elsewhere - a CDN, a Google Fonts stylesheet, a script
	 * from an ad network - is left alone: this plugin cannot read a file it
	 * does not host, and rewriting the URL would only break it.
	 *
	 * @param string $src Registered source, absolute or root-relative.
	 * @return array{path: string, url: string}|false
	 */
	private static function local_file( $src ) {
		$src = (string) $src;

		if ( '' === $src || false !== strpos( $src, 'data:' ) ) {
			return false;
		}

		$url = $src;

		if ( 0 === strpos( $url, '//' ) ) {
			$url = set_url_scheme( $url );
		} elseif ( 0 === strpos( $url, '/' ) ) {
			$url = home_url( $url );
		} elseif ( ! preg_match( '#^https?://#i', $url ) ) {
			$url = content_url( '/' . ltrim( $url, '/' ) );
		}

		$base_url = content_url();
		$base_dir = WP_CONTENT_DIR;

		// A theme or plugin can be installed outside wp-content; the constants
		// are the authority on where the files really are.
		if ( 0 === strpos( $url, content_url() ) ) {
			$relative = substr( $url, strlen( content_url() ) );
		} elseif ( 0 === strpos( $url, includes_url() ) && defined( 'ABSPATH' ) ) {
			$base_url = includes_url();
			$base_dir = ABSPATH . WPINC;
			$relative = substr( $url, strlen( includes_url() ) );
		} else {
			return false;
		}

		$relative = strtok( $relative, '?#' );

		if ( false === $relative || '' === $relative ) {
			return false;
		}

		$path = wp_normalize_path( $base_dir . '/' . ltrim( $relative, '/' ) );

		return is_readable( $path ) ? array( 'path' => $path, 'url' => untrailingslashit( $base_url ) . '/' . ltrim( $relative, '/' ) ) : false;
	}

	/**
	 * Whether a handle or its path is on the exclusion list.
	 *
	 * @param string   $handle   Asset handle.
	 * @param string   $src      Registered source.
	 * @param string[] $excluded Excluded handles or path fragments.
	 * @return bool
	 */
	private static function is_excluded( $handle, $src, $excluded ) {
		foreach ( $excluded as $needle ) {
			if ( $handle === $needle || false !== strpos( (string) $src, $needle ) ) {
				return true;
			}
		}

		return false;
	}
}
