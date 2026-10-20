<?php
/**
 * Plugin Name:       Majestic Turbo
 * Description:       Minifies and reduces CSS, minifies and defers JavaScript, minifies HTML, hints the largest contentful paint, converts images to WebP and/or AVIF and serves cached pages, so a PageSpeed audit has nothing left to report. Works with any theme.
 * Version:           1.6.8
 * Requires at least: 6.5
 * Requires PHP:      7.4
 * Author:            Majestic Tube
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       majestic-turbo
 * Domain Path:       /languages
 *
 * @package Majestic Turbo
 */

defined( 'ABSPATH' ) || exit;

define( 'MAJESTIC_TURBO_VERSION', '1.6.8' );
define( 'MAJESTIC_TURBO_FILE', __FILE__ );
define( 'MAJESTIC_TURBO_DIR', plugin_dir_path( __FILE__ ) );
define( 'MAJESTIC_TURBO_URL', plugin_dir_url( __FILE__ ) );
define( 'MAJESTIC_TURBO_BASENAME', plugin_basename( __FILE__ ) );
define( 'MAJESTIC_TURBO_OPTION', 'majestic_turbo_options' );

/**
 * The CSS safelist a fresh install starts with.
 *
 * The list is generic on purpose: state classes and the UI libraries a theme or
 * page builder commonly adds after load are exactly what the rendered HTML
 * cannot reveal. Majestic Tube's player prefixes are appended only while that
 * theme is the active template - its player is built in JavaScript, so its
 * rules have to be kept - and a site running any other theme starts without
 * selectors that refer to markup it will never print.
 *
 * @return string One selector prefix per line.
 */
function majestic_turbo_default_css_keep() {
	$keep = ".is-\n.has-\n.js-\n.no-js\n.js\n.js-enabled\n.screen-reader-text\n.skip-link\n.turbo-\n.wp-block-\n.notice\n.updated\n.error\n.player\n.modal\n.dropdown\n.tooltip\n.swiper\n.slick-\n.mfp-\n.fancybox\n.select2\n.chosen-\n.woocommerce-";

	if ( function_exists( 'get_template' ) && 'majestic-tube' === get_template() ) {
		$keep .= "\n.wpst-\n.vjs-\n.mt-icon\n#wpst-user-modal";
	}

	return $keep;
}

/**
 * The switchboard.
 *
 * Every module reads its own state through this map, so the defaults live in
 * one place: the settings screen, the front end and the uninstall routine all
 * agree on what "on" means, and a new module is a row here plus its file.
 *
 * @return array<string, array<string, mixed>> Keyed by option name.
 */
function majestic_turbo_option_definitions() {
	$definitions = array(
		'minify_html'        => array(
			'label'       => __( 'Minify HTML', 'majestic-turbo' ),
			'description' => __( 'Collapse whitespace between tags, drop HTML comments and minify inline styles and scripts in the finished page.', 'majestic-turbo' ),
			'type'        => 'checkbox',
			'default'     => 'on',
		),
		'minify_css'         => array(
			'label'       => __( 'Minify CSS', 'majestic-turbo' ),
			'description' => __( 'Strip comments and dead whitespace from every local stylesheet the page loads.', 'majestic-turbo' ),
			'type'        => 'checkbox',
			'default'     => 'on',
		),
		'combine_css'        => array(
			'label'       => __( 'Combine CSS', 'majestic-turbo' ),
			'description' => __( 'Serve every local stylesheet as one cacheable file instead of one request per handle.', 'majestic-turbo' ),
			'type'        => 'checkbox',
			'default'     => 'on',
		),
		'reduce_unused_css'  => array(
			'label'       => __( 'Reduce unused CSS', 'majestic-turbo' ),
			'description' => __( 'Remove rules whose selectors the rendered page never uses. Rules for classes a script adds later are kept by the safelist below. This reads the combined stylesheet, so it needs Combine CSS.', 'majestic-turbo' ),
			'type'        => 'checkbox',
			'default'     => 'on',
		),
		'critical_css'       => array(
			'label'       => __( 'Inline critical CSS', 'majestic-turbo' ),
			'description' => __( 'Print the page\'s rules in the head instead of a render-blocking request. When the used sheet is too large to inline whole, the above-the-fold rules are inlined and the rest loads without blocking the first paint. This reads the combined stylesheet, so it needs Combine CSS.', 'majestic-turbo' ),
			'type'        => 'checkbox',
			'default'     => 'on',
		),
		'minify_js'          => array(
			'label'       => __( 'Minify JavaScript', 'majestic-turbo' ),
			'description' => __( 'Strip comments and indentation from local theme and plugin scripts. Nothing is renamed or rewritten, so a script that works keeps working.', 'majestic-turbo' ),
			'type'        => 'checkbox',
			'default'     => 'on',
		),
		'defer_js'           => array(
			'label'       => __( 'Defer JavaScript', 'majestic-turbo' ),
			'description' => __( 'Add defer to local scripts that do not already name a loading strategy, so parsing is not interrupted.', 'majestic-turbo' ),
			'type'        => 'checkbox',
			'default'     => 'on',
		),
		'image_hints'        => array(
			'label'       => __( 'Image hints', 'majestic-turbo' ),
			'description' => __( 'Mark every card in the first screenful as a largest contentful paint candidate - eager and high priority, with the first one also preloaded - and lazy-load the rest.', 'majestic-turbo' ),
			'type'        => 'checkbox',
			'default'     => 'on',
		),
		'webp_images'        => array(
			'label'       => __( 'WebP images', 'majestic-turbo' ),
			'description' => __( 'Offer a WebP version of each image when it is smaller than the original, and generate it beside the file. Skipped automatically on a server whose GD or Imagick cannot write WebP. A library that already exists is converted once with `wp majestic-turbo images`.', 'majestic-turbo' ),
			'type'        => 'checkbox',
			'default'     => 'on',
		),
		'avif_images'        => array(
			'label'       => __( 'AVIF images', 'majestic-turbo' ),
			'description' => __( 'Offer an AVIF version in front of the WebP one, kept only when it is smaller - than the WebP beside it when there is one, otherwise than the original. Encoding takes several times longer than WebP and the size win is modest on the small thumbnails a card grid serves, which is why it is off by default.', 'majestic-turbo' ),
			'type'        => 'checkbox',
			'default'     => 'off',
		),
		'strip_block_css'    => array(
			'label'       => __( 'Drop unused block CSS', 'majestic-turbo' ),
			'description' => __( 'Skip the block library and global styles when the page, its widgets and its sidebars contain no blocks. The largest single win on a classic, non-block theme.', 'majestic-turbo' ),
			'type'        => 'checkbox',
			'default'     => 'on',
		),
		'page_cache'         => array(
			'label'       => __( 'Cache pages', 'majestic-turbo' ),
			'description' => __( 'Store the finished HTML of anonymous visits and answer the next one without running WordPress. Signed-in visitors, POSTs, feeds and sitemaps are never cached, and neither is any response that is not an HTML document.', 'majestic-turbo' ),
			'type'        => 'checkbox',
			'default'     => 'on',
		),
		'early_cache'        => array(
			'label'       => __( 'Serve before WordPress', 'majestic-turbo' ),
			'description' => __( 'Write the advanced-cache.php drop-in so a stored page is answered before WordPress, the plugins and the database load. This is the switch that moves time to first byte. It needs define( \'WP_CACHE\', true ) in wp-config.php; without that line WordPress never reads the drop-in and the in-plugin cache serves instead.', 'majestic-turbo' ),
			'type'        => 'checkbox',
			'default'     => 'on',
		),
		'page_cache_ttl'     => array(
			'label'       => __( 'Cache lifetime', 'majestic-turbo' ),
			'description' => __( 'Minutes a stored page stays fresh. A published, edited or deleted post clears the whole cache immediately.', 'majestic-turbo' ),
			'type'        => 'number',
			'default'     => 60,
			'min'         => 1,
			'max'         => 1440,
		),
		'css_keep'           => array(
			'label'       => __( 'CSS safelist', 'majestic-turbo' ),
			'description' => __( 'One selector prefix per line. Rules for markup a script builds after load belong here so they are never reduced away. The default covers common state classes and UI libraries; add your theme\'s or player\'s prefixes if it builds markup in JavaScript.', 'majestic-turbo' ),
			'type'        => 'textarea',
			'default'     => majestic_turbo_default_css_keep(),
		),
		'exclude_css'        => array(
			'label'       => __( 'Excluded stylesheets', 'majestic-turbo' ),
			'description' => __( 'One handle or path fragment per line. A stylesheet named here is loaded exactly as WordPress enqueued it.', 'majestic-turbo' ),
			'type'        => 'textarea',
			'default'     => '',
		),
		'exclude_js'         => array(
			'label'       => __( 'Excluded scripts', 'majestic-turbo' ),
			'description' => __( 'One handle or path fragment per line. A script named here is loaded exactly as WordPress enqueued it.', 'majestic-turbo' ),
			'type'        => 'textarea',
			'default'     => "wp-i18n\nwp-hooks\ncomment-reply",
		),
		'preconnect'         => array(
			'label'       => __( 'Preconnect hosts', 'majestic-turbo' ),
			'description' => __( 'One hostname per line - the video CDN, an ad server, an analytics endpoint. Each is announced in the head so the DNS and TLS handshake overlaps the first paint.', 'majestic-turbo' ),
			'type'        => 'textarea',
			'default'     => '',
		),
	);

	/**
	 * Filter the option map.
	 *
	 * @param array<string, array<string, mixed>> $definitions Option definitions.
	 */
	return apply_filters( 'majestic_turbo_option_definitions', $definitions );
}

/**
 * Every option with its stored value applied.
 *
 * @return array<string, mixed>
 */
function majestic_turbo_options() {
	static $options = null;

	if ( null === $options ) {
		$stored  = get_option( MAJESTIC_TURBO_OPTION, array() );
		$stored  = is_array( $stored ) ? $stored : array();
		$options = array();

		foreach ( majestic_turbo_option_definitions() as $key => $definition ) {
			$options[ $key ] = array_key_exists( $key, $stored ) ? $stored[ $key ] : $definition['default'];
		}
	}

	return $options;
}

/**
 * Read one option.
 *
 * @param string $key     Option name.
 * @param mixed  $default Fallback when the name is unknown.
 * @return mixed
 */
function majestic_turbo_option( $key, $default = null ) {
	$options = majestic_turbo_options();

	return array_key_exists( $key, $options ) ? $options[ $key ] : $default;
}

/**
 * Whether a switch is on.
 *
 * @param string $key Option name.
 * @return bool
 */
function majestic_turbo_option_is_on( $key ) {
	return 'on' === majestic_turbo_option( $key, 'off' );
}

/**
 * One line of a textarea option, as an array.
 *
 * @param string $key Option name.
 * @return string[]
 */
function majestic_turbo_option_lines( $key ) {
	$lines = preg_split( '/[\r\n,]+/', (string) majestic_turbo_option( $key, '' ) );
	$lines = array_map( 'trim', (array) $lines );

	return array_values( array_filter( $lines, 'strlen' ) );
}

/**
 * The cache lifetime in seconds.
 *
 * @return int
 */
function majestic_turbo_cache_ttl() {
	$minutes = (int) majestic_turbo_option( 'page_cache_ttl', 60 );

	/**
	 * Filter how long a stored page is served before it is rebuilt.
	 *
	 * @param int $seconds Lifetime in seconds.
	 */
	return max( 60, (int) apply_filters( 'majestic_turbo_cache_ttl', $minutes * MINUTE_IN_SECONDS ) );
}

require_once MAJESTIC_TURBO_DIR . 'inc/class-turbo-cache.php';
require_once MAJESTIC_TURBO_DIR . 'inc/class-turbo-key.php';
require_once MAJESTIC_TURBO_DIR . 'inc/class-turbo-early.php';
require_once MAJESTIC_TURBO_DIR . 'inc/class-turbo-css.php';
require_once MAJESTIC_TURBO_DIR . 'inc/class-turbo-js.php';
require_once MAJESTIC_TURBO_DIR . 'inc/class-turbo-assets.php';
require_once MAJESTIC_TURBO_DIR . 'inc/class-turbo-html.php';
require_once MAJESTIC_TURBO_DIR . 'inc/class-turbo-images.php';
require_once MAJESTIC_TURBO_DIR . 'inc/class-turbo-page-cache.php';
require_once MAJESTIC_TURBO_DIR . 'inc/class-turbo-warm.php';
require_once MAJESTIC_TURBO_DIR . 'inc/class-turbo-settings.php';

Majestic_Turbo_Assets::init();
Majestic_Turbo_HTML::init();
Majestic_Turbo_Images::init();
Majestic_Turbo_Page_Cache::init();
Majestic_Turbo_Warm::init();
Majestic_Turbo_Settings::init();

/**
 * Clear every generated file.
 *
 * The one entry point the settings screen, the uninstall routine and a theme
 * switch share, so a purge cannot mean three slightly different things.
 *
 * @return int Files removed.
 */
function majestic_turbo_purge() {
	$removed = Majestic_Turbo_Cache::purge();

	/**
	 * Fires after the generated files are removed.
	 *
	 * @param int $removed Files removed.
	 */
	do_action( 'majestic_turbo_purged', $removed );

	return $removed;
}

/**
 * Register the WP-CLI commands.
 *
 * Only under `wp`: the class calls WP_CLI, and a front-end request that loaded
 * it would be loading a command it can never run.
 *
 * The function is not called `majestic_turbo_cli`, though that is the obvious
 * name. PHP function names are case-insensitive, so a function of that name
 * makes `function_exists( 'Majestic_Turbo_CLI' )` answer true, and WP-CLI
 * treats a class name that is also a function as the function - the command
 * would silently become this registration callback instead of the class. The
 * two names must differ by more than case.
 *
 * @return void
 */
function majestic_turbo_register_commands() {
	if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
		return;
	}

	require_once MAJESTIC_TURBO_DIR . 'inc/class-turbo-cli.php';

	WP_CLI::add_command( 'majestic-turbo', 'Majestic_Turbo_CLI' );
}
add_action( 'cli_init', 'majestic_turbo_register_commands' );

/**
 * Rebuild the cache directory after an activation.
 *
 * @return void
 */
function majestic_turbo_activate() {
	Majestic_Turbo_Cache::ensure();
	Majestic_Turbo_Page_Cache::forget();
	Majestic_Turbo_Page_Cache::install_dropin();
}
register_activation_hook( __FILE__, 'majestic_turbo_activate' );

/**
 * Remove the generated files when the plugin is switched off.
 *
 * Deactivation is a decision to stop changing the page, so nothing this plugin
 * wrote is left behind to be served by a router that is no longer running.
 *
 * @return void
 */
function majestic_turbo_deactivate() {
	Majestic_Turbo_Cache::purge();
	Majestic_Turbo_Page_Cache::forget();

	// A drop-in left behind would keep answering from a cache nothing flushes.
	Majestic_Turbo_Page_Cache::remove_dropin();

	// The warm jobs would otherwise sit in the cron table until each one ran
	// and found no listener for its hook.
	wp_clear_scheduled_hook( Majestic_Turbo_Warm::EVENT );
}
register_deactivation_hook( __FILE__, 'majestic_turbo_deactivate' );
