<?php
/**
 * The settings screen.
 *
 * One screen under Settings -> Majestic Turbo: every switch the plugin reads,
 * what it changes, and the state of the generated files it is sitting on. The
 * fields are generated from majestic_turbo_option_definitions(), so a new
 * module adds its switch in one place and the screen, the sanitizer and the
 * front end all learn about it together.
 *
 * @package Majestic Turbo
 */

defined( 'ABSPATH' ) || exit;

/**
 * Register and render the settings screen.
 */
class Majestic_Turbo_Settings {

	/**
	 * Settings group name.
	 */
	const GROUP = 'majestic_turbo';

	/**
	 * Register the hooks.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_init', array( __CLASS__, 'register' ) );
		add_action( 'admin_post_majestic_turbo_purge', array( __CLASS__, 'handle_purge' ) );
		add_action( 'admin_post_majestic_turbo_images', array( __CLASS__, 'handle_images' ) );
		add_filter( 'plugin_action_links_' . MAJESTIC_TURBO_BASENAME, array( __CLASS__, 'action_links' ) );
	}

	/**
	 * Add the screen under Settings.
	 *
	 * @return void
	 */
	public static function menu() {
		add_options_page(
			__( 'Majestic Turbo', 'majestic-turbo' ),
			__( 'Majestic Turbo', 'majestic-turbo' ),
			'manage_options',
			'majestic-turbo',
			array( __CLASS__, 'render' )
		);
	}

	/**
	 * Add a Settings link to the plugins list.
	 *
	 * @param string[] $links Action links.
	 * @return string[]
	 */
	public static function action_links( $links ) {
		array_unshift(
			$links,
			'<a href="' . esc_url( admin_url( 'options-general.php?page=majestic-turbo' ) ) . '">' . esc_html__( 'Settings', 'majestic-turbo' ) . '</a>'
		);

		return $links;
	}

	/**
	 * Register the option and its fields.
	 *
	 * @return void
	 */
	public static function register() {
		register_setting(
			self::GROUP,
			MAJESTIC_TURBO_OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( __CLASS__, 'sanitize' ),
				'default'           => array(),
			)
		);

		add_settings_section(
			'majestic_turbo_main',
			__( 'What Majestic Turbo does to a page', 'majestic-turbo' ),
			array( __CLASS__, 'section_intro' ),
			'majestic-turbo'
		);

		foreach ( majestic_turbo_option_definitions() as $key => $definition ) {
			add_settings_field(
				'majestic_turbo_' . $key,
				esc_html( $definition['label'] ),
				array( __CLASS__, 'field' ),
				'majestic-turbo',
				'majestic_turbo_main',
				array(
					'key'        => $key,
					'definition' => $definition,
				)
			);
		}
	}

	/**
	 * The section's opening paragraph.
	 *
	 * @return void
	 */
	public static function section_intro() {
		printf(
			'<p>%s</p><p class="description">%s</p>',
			esc_html__( 'These switches rewrite the finished page on its way out. Switch one off and the page is left exactly as WordPress and the theme built it.', 'majestic-turbo' ),
			esc_html__( 'Generated files live in the uploads directory, so an update of the theme, this plugin or WordPress never has to touch them, and clearing the cache is always safe.', 'majestic-turbo' )
		);
	}

	/**
	 * Render one field.
	 *
	 * @param array $args Field arguments.
	 * @return void
	 */
	public static function field( $args ) {
		$key        = (string) $args['key'];
		$definition = (array) $args['definition'];
		$value      = majestic_turbo_option( $key );
		$name       = MAJESTIC_TURBO_OPTION . '[' . $key . ']';

		if ( 'checkbox' === $definition['type'] ) {
			printf(
				'<label><input type="checkbox" name="%1$s" value="on" %2$s /> %3$s</label>',
				esc_attr( $name ),
				checked( 'on', $value, false ),
				esc_html( $definition['description'] )
			);

			return;
		}

		if ( 'number' === $definition['type'] ) {
			printf(
				'<input type="number" class="small-text" name="%1$s" value="%2$s" min="%3$s" max="%4$s" step="1" /> <p class="description">%5$s</p>',
				esc_attr( $name ),
				esc_attr( (string) $value ),
				esc_attr( (string) $definition['min'] ),
				esc_attr( (string) $definition['max'] ),
				esc_html( $definition['description'] )
			);

			return;
		}

		printf(
			'<textarea name="%1$s" rows="6" class="large-text code">%2$s</textarea><p class="description">%3$s</p>',
			esc_attr( $name ),
			esc_textarea( (string) $value ),
			esc_html( $definition['description'] )
		);
	}

	/**
	 * Keep a submitted value inside what its field declares.
	 *
	 * @param mixed $input Submitted option array.
	 * @return array<string, mixed>
	 */
	public static function sanitize( $input ) {
		$input   = is_array( $input ) ? $input : array();
		$clean   = array();

		foreach ( majestic_turbo_option_definitions() as $key => $definition ) {
			$submitted = array_key_exists( $key, $input ) ? $input[ $key ] : null;

			switch ( $definition['type'] ) {
				case 'checkbox':
					$clean[ $key ] = 'on' === $submitted ? 'on' : 'off';
					break;

			case 'number':
				$clean[ $key ] = min(
					(int) $definition['max'],
					max( (int) $definition['min'], absint( is_scalar( $submitted ) ? $submitted : 0 ) )
				);
				break;

			default:
				// A field arrives as a string; anything else - an array from a
				// hand-written POST - is not a value to store.
				$clean[ $key ] = sanitize_textarea_field( is_scalar( $submitted ) ? (string) $submitted : '' );
				break;
			}
		}

		// The bundle signature and the cached pages both depend on the
		// switches above, so saving this screen is a rebuild, not a toggle.
		Majestic_Turbo_Cache::purge( 'css' );
		Majestic_Turbo_Cache::purge( 'js' );
		Majestic_Turbo_Page_Cache::forget();

		return $clean;
	}

	/**
	 * Render the screen.
	 *
	 * @return void
	 */
	public static function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$purged = isset( $_GET['mt_purged'] ) ? absint( $_GET['mt_purged'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- a read-only notice flag.

		echo '<div class="wrap">';
		echo '<h1>' . esc_html__( 'Majestic Turbo', 'majestic-turbo' ) . '</h1>';

		self::early_notice();

		if ( isset( $_GET['mt_converted'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- a read-only notice flag.
			self::images_notice();
		}

		if ( $purged ) {
			printf(
				'<div class="notice notice-success is-dismissible"><p>%s</p></div>',
				esc_html(
					sprintf(
						/* translators: %d: number of files removed. */
						_n( '%d generated file removed.', '%d generated files removed.', $purged, 'majestic-turbo' ),
						$purged
					)
				)
			);
		}

		echo '<form action="' . esc_url( admin_url( 'options.php' ) ) . '" method="post">';
		settings_fields( self::GROUP );
		do_settings_sections( 'majestic-turbo' );
		submit_button();
		echo '</form>';

		self::status();

		echo '</div>';
	}

	/**
	 * Say whether the page is really answered before WordPress.
	 *
	 * The switch alone proves nothing: WordPress includes the drop-in only
	 * when WP_CACHE is on, and only one plugin can own the file. Both are
	 * stated here rather than left to be discovered from a slow TTFB.
	 *
	 * @return void
	 */
	private static function early_notice() {
		if ( ! majestic_turbo_option_is_on( 'page_cache' ) || ! majestic_turbo_option_is_on( 'early_cache' ) ) {
			return;
		}

		if ( Majestic_Turbo_Page_Cache::has_foreign_dropin() ) {
			printf(
				'<div class="notice notice-warning"><p>%s</p></div>',
				esc_html__( 'Another plugin already owns wp-content/advanced-cache.php, so early serving is off and the page cache answers after WordPress loads. Deactivate that plugin if you want Majestic Turbo to answer first.', 'majestic-turbo' )
			);

			return;
		}

		if ( ! defined( 'WP_CACHE' ) || ! WP_CACHE ) {
			printf(
				'<div class="notice notice-info"><p>%s</p></div>',
				esc_html__( 'To answer a cached page before WordPress loads, add this line to wp-config.php: define( \'WP_CACHE\', true );', 'majestic-turbo' )
			);

			return;
		}

		if ( ! Majestic_Turbo_Page_Cache::dropin_is_ours( Majestic_Turbo_Page_Cache::dropin_path() ) ) {
			printf(
				'<div class="notice notice-warning"><p>%s</p></div>',
				esc_html__( 'The early drop-in is not in place. wp-content is probably not writable, so the page cache will keep answering after WordPress loads.', 'majestic-turbo' )
			);
		}
	}

	/**
	 * The state of the generated files and what each module answers for.
	 *
	 * @return void
	 */
	private static function status() {
		$rows = array(
			'css'        => array( __( 'Combined stylesheets', 'majestic-turbo' ), 'css' ),
			'js'         => array( __( 'Minified scripts', 'majestic-turbo' ), 'js' ),
			'page-cache' => array( __( 'Stored pages', 'majestic-turbo' ), 'page-cache' ),
		);

		echo '<h2>' . esc_html__( 'Generated files', 'majestic-turbo' ) . '</h2>';
		echo '<table class="widefat striped" style="max-width:720px"><thead><tr>';
		echo '<th>' . esc_html__( 'What', 'majestic-turbo' ) . '</th>';
		echo '<th>' . esc_html__( 'Directory', 'majestic-turbo' ) . '</th>';
		echo '<th>' . esc_html__( 'Files', 'majestic-turbo' ) . '</th>';
		echo '<th>' . esc_html__( 'Size', 'majestic-turbo' ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach ( $rows as $row ) {
			$directory = Majestic_Turbo_Cache::path( $row[1] );
			$files     = ( '' !== $directory && is_dir( $directory ) ) ? glob( $directory . '/*' ) : array();
			$files     = array_filter( (array) $files, 'is_file' );

			printf(
				'<tr><td>%1$s</td><td><code>%2$s</code></td><td>%3$d</td><td>%4$s</td></tr>',
				esc_html( $row[0] ),
				esc_html( '' === $directory ? __( 'uploads is not writable', 'majestic-turbo' ) : $directory ),
				count( $files ),
				esc_html( size_format( Majestic_Turbo_Cache::bytes( $row[1] ), 1 ) )
			);
		}

		echo '</tbody></table>';

		echo '<h2>' . esc_html__( 'What each switch answers', 'majestic-turbo' ) . '</h2>';
		echo '<table class="widefat striped" style="max-width:920px"><thead><tr>';
		echo '<th>' . esc_html__( 'PageSpeed audit', 'majestic-turbo' ) . '</th>';
		echo '<th>' . esc_html__( 'Answered by', 'majestic-turbo' ) . '</th>';
		echo '</tr></thead><tbody>';

		$audits = array(
			__( 'Eliminate render-blocking resources', 'majestic-turbo' ) => __( 'Combine CSS and inline critical CSS', 'majestic-turbo' ),
			__( 'Reduce unused CSS', 'majestic-turbo' )                 => __( 'Reduce unused CSS', 'majestic-turbo' ),
			__( 'Minify CSS', 'majestic-turbo' )                        => __( 'Minify CSS', 'majestic-turbo' ),
			__( 'Minify JavaScript', 'majestic-turbo' )                 => __( 'Minify JavaScript', 'majestic-turbo' ),
			__( 'Reduce unused JavaScript', 'majestic-turbo' )          => __( 'Drop unused block CSS, defer JavaScript', 'majestic-turbo' ),
			__( 'Serve static assets with an efficient cache policy', 'majestic-turbo' ) => __( 'Combine CSS, Minify JavaScript - generated files are content-addressed', 'majestic-turbo' ),
			__( 'Reduce initial server response time', 'majestic-turbo' ) => __( 'Cache pages', 'majestic-turbo' ),
			__( 'Avoid an excessive DOM size', 'majestic-turbo' )       => __( 'Minify HTML (the review stays manual)', 'majestic-turbo' ),
			__( 'Largest Contentful Paint image was not lazily loaded', 'majestic-turbo' ) => __( 'Image hints', 'majestic-turbo' ),
			__( 'Avoid enormous network payloads', 'majestic-turbo' )   => __( 'Minify CSS, Minify JavaScript, Minify HTML', 'majestic-turbo' ),
		);

		foreach ( $audits as $audit => $answer ) {
			printf( '<tr><td>%1$s</td><td>%2$s</td></tr>', esc_html( $audit ), esc_html( $answer ) );
		}

		echo '</tbody></table>';

		self::images_section();

		echo '<h2>' . esc_html__( 'Clear the cache', 'majestic-turbo' ) . '</h2>';
		echo '<p>' . esc_html__( 'Deletes every generated stylesheet, script and stored page. Nothing else is touched, and the next visit rebuilds what it needs.', 'majestic-turbo' ) . '</p>';
		printf(
			'<form action="%1$s" method="post"><input type="hidden" name="action" value="majestic_turbo_purge" />%2$s%3$s</form>',
			esc_url( admin_url( 'admin-post.php' ) ),
			wp_nonce_field( 'majestic_turbo_purge', '_mt_nonce', true, false ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- wp_nonce_field returns escaped markup.
			get_submit_button( __( 'Clear the cache', 'majestic-turbo' ), 'secondary', 'submit', false ) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- core builds this markup and escapes the label.
		);
	}

	/**
	 * The WebP section of the screen.
	 *
	 * A site that installs the plugin on top of an existing library has no
	 * WebP file next to anything yet, and the upload hook only fires for a
	 * file that arrives after activation. This is the button that catches the
	 * library up, one batch at a time, so a shared host with a 30 second PHP
	 * limit still finishes.
	 *
	 * @return void
	 */
	private static function images_section() {
		$total = class_exists( 'Majestic_Turbo_Images' ) ? Majestic_Turbo_Images::library_count() : 0;

		echo '<h2>' . esc_html__( 'WebP and AVIF images', 'majestic-turbo' ) . '</h2>';

		$webp = class_exists( 'Majestic_Turbo_Images' ) && Majestic_Turbo_Images::supports();
		$avif = class_exists( 'Majestic_Turbo_Images' ) && Majestic_Turbo_Images::avif_supports();

		if ( ! $webp && ! $avif ) {
			echo '<p>' . esc_html__( 'This server can write neither WebP nor AVIF: the GD or Imagick build PHP is running supports no converted format, so there is nothing to generate here.', 'majestic-turbo' ) . '</p>';

			return;
		}

		$webp_on = $webp && majestic_turbo_option_is_on( 'webp_images' );
		$avif_on = $avif && majestic_turbo_option_is_on( 'avif_images' );

		if ( ! $webp_on && ! $avif_on ) {
			echo '<p>' . esc_html__( 'Both formats are switched off, so a converted file would never be offered. Switch one on above first.', 'majestic-turbo' ) . '</p>';

			return;
		}

		printf(
			'<p>%s</p>',
			esc_html(
				sprintf(
					/* translators: %d: number of JPEG and PNG attachments in the library. */
					_n( '%d attachment can be checked. Each one is converted only when the new file is smaller than the original.', '%d attachments can be checked. Each one is converted only when the new file is smaller than the original.', $total, 'majestic-turbo' ),
					$total
				)
			)
		);

		echo '<p>' . esc_html__( 'New uploads are converted as they arrive: the request that stores the image also writes the converted files beside it, and an import that sideloads images is converted the same way.', 'majestic-turbo' ) . '</p>';

		/*
		 * The two formats stand alone. Saying which one this host can actually
		 * write is the difference between a switch that does nothing and a
		 * switch the administrator can reason about.
		 */
		if ( ! $webp ) {
			echo '<p>' . esc_html__( 'This server cannot write WebP, so only AVIF files are generated - each one kept when it is smaller than the original image.', 'majestic-turbo' ) . '</p>';
		} elseif ( ! $avif ) {
			echo '<p>' . esc_html__( 'This server cannot write AVIF, so only WebP files are generated. The AVIF switch above has nothing to offer here.', 'majestic-turbo' ) . '</p>';
		} elseif ( $avif_on ) {
			echo '<p>' . esc_html__( 'AVIF is on: each converted file also gets an AVIF sibling, kept only when it is smaller than the WebP beside it.', 'majestic-turbo' ) . '</p>';
		} else {
			echo '<p>' . esc_html__( 'This server can write AVIF. It is switched off above because it takes several times as long to encode, and at the quality that matches WebP sharpness the files are only somewhat smaller.', 'majestic-turbo' ) . '</p>';
		}

		echo self::images_form( 0, __( 'Convert the media library', 'majestic-turbo' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts.

		self::images_status();
	}

	/**
	 * The last conversion run, with the reasons files were left alone.
	 *
	 * A batch that reports only "converted 4 files" cannot tell a library that
	 * was already done from one whose images are bigger in the new format. The
	 * counters and the last file results are stored by the image module, so
	 * this panel answers the question after the browser has moved on - and the
	 * same record is what `wp majestic-turbo images --status` prints.
	 *
	 * @return void
	 */
	private static function images_status() {
		if ( ! class_exists( 'Majestic_Turbo_Images' ) ) {
			return;
		}

		$status = Majestic_Turbo_Images::status();

		echo '<h3>' . esc_html__( 'Last conversion', 'majestic-turbo' ) . '</h3>';

		if ( (int) $status['time'] < 1 ) {
			echo '<p>' . esc_html__( 'No conversion has run yet. Uploading an image or running a batch above records what happened here.', 'majestic-turbo' ) . '</p>';

			return;
		}

		$sources = array(
			'upload' => __( 'an upload', 'majestic-turbo' ),
			'cli'    => __( 'WP-CLI', 'majestic-turbo' ),
			'admin'  => __( 'this screen', 'majestic-turbo' ),
			'manual' => __( 'a manual call', 'majestic-turbo' ),
		);

		$source = isset( $sources[ $status['source'] ] ) ? $sources[ $status['source'] ] : (string) $status['source'];
		$saved  = (int) $status['saved'] + (int) $status['avif_saved'];
		$files  = (int) $status['files'] + (int) $status['avif_files'];

		printf(
			'<p>%s</p>',
			esc_html(
				sprintf(
					/* translators: 1: time ago, 2: date and time, 3: what started the run, 4: attachments, 5: files converted, 6: WebP count, 7: AVIF count, 8: bytes saved, 9: files skipped. */
					__( '%1$s ago (%2$s) by %3$s: %4$d attachment(s) checked, %5$d file(s) converted (%6$d WebP, %7$d AVIF), %8$s saved, %9$d file(s) skipped.', 'majestic-turbo' ),
					human_time_diff( (int) $status['time'] ),
					wp_date( 'Y-m-d H:i:s', (int) $status['time'] ),
					$source,
					(int) $status['attachments'],
					$files,
					(int) $status['files'],
					(int) $status['avif_files'],
					size_format( $saved, 1 ),
					(int) $status['skipped']
				)
			)
		);

		if ( (int) $status['deferred'] > 0 ) {
			printf(
				'<p><strong>%s</strong></p>',
				esc_html(
					sprintf(
						/* translators: %d: number of files left for the next pass. */
						_n( '%d file was left for the next pass because the upload ran out of its time budget.', '%d files were left for the next pass because the upload ran out of its time budget.', (int) $status['deferred'], 'majestic-turbo' ),
						(int) $status['deferred']
					)
				)
			);
		}

		if ( ! empty( $status['reasons'] ) ) {
			echo '<h4>' . esc_html__( 'Why files were left alone', 'majestic-turbo' ) . '</h4>';
			echo '<table class="widefat striped" style="max-width:720px"><thead><tr>';
			echo '<th>' . esc_html__( 'Reason', 'majestic-turbo' ) . '</th>';
			echo '<th>' . esc_html__( 'Files', 'majestic-turbo' ) . '</th>';
			echo '</tr></thead><tbody>';

			foreach ( $status['reasons'] as $reason => $count ) {
				printf( '<tr><td>%1$s</td><td>%2$d</td></tr>', esc_html( (string) $reason ), (int) $count );
			}

			echo '</tbody></table>';
		}

		$recent = array_slice( (array) $status['recent'], -40 );

		if ( $recent ) {
			echo '<h4>' . esc_html__( 'Recent file results', 'majestic-turbo' ) . '</h4>';
			echo '<table class="widefat striped" style="max-width:920px"><thead><tr>';
			echo '<th>' . esc_html__( 'File', 'majestic-turbo' ) . '</th>';
			echo '<th>' . esc_html__( 'Format', 'majestic-turbo' ) . '</th>';
			echo '<th>' . esc_html__( 'Result', 'majestic-turbo' ) . '</th>';
			echo '<th>' . esc_html__( 'Before', 'majestic-turbo' ) . '</th>';
			echo '<th>' . esc_html__( 'After', 'majestic-turbo' ) . '</th>';
			echo '<th>' . esc_html__( 'Saved or reason', 'majestic-turbo' ) . '</th>';
			echo '</tr></thead><tbody>';

			foreach ( $recent as $entry ) {
				$converted = ! empty( $entry['converted'] );
				$deferred  = ! empty( $entry['deferred'] );
				$detail    = $converted
					? size_format( (int) $entry['saved'], 1 )
					: ( '' !== (string) $entry['reason'] ? (string) $entry['reason'] : __( 'no reason recorded', 'majestic-turbo' ) );
				$result    = $converted ? __( 'Converted', 'majestic-turbo' ) : ( $deferred ? __( 'Deferred', 'majestic-turbo' ) : __( 'Skipped', 'majestic-turbo' ) );

				printf(
					'<tr><td><code>%1$s</code></td><td>%2$s</td><td>%3$s</td><td>%4$s</td><td>%5$s</td><td>%6$s</td></tr>',
					esc_html( (string) $entry['file'] ),
					esc_html( strtoupper( (string) $entry['format'] ) ),
					esc_html( $result ),
					esc_html( (int) $entry['before'] > 0 ? size_format( (int) $entry['before'], 1 ) : '-' ),
					esc_html( (int) $entry['after'] > 0 ? size_format( (int) $entry['after'], 1 ) : '-' ),
					esc_html( $detail )
				);
			}

			echo '</tbody></table>';
		}

		echo '<p class="description">' . esc_html__( 'The counters cover the whole run; the table keeps the last 40 file results. The same record is available from the command line with wp majestic-turbo images --status.', 'majestic-turbo' ) . '</p>';
	}

	/**
	 * The result of one conversion batch.
	 *
	 * @return void
	 */
	private static function images_notice() {
		$converted = isset( $_GET['mt_converted'] ) ? absint( $_GET['mt_converted'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- a read-only notice flag.
		$saved     = isset( $_GET['mt_saved'] ) ? absint( $_GET['mt_saved'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- a read-only notice flag.
		$next      = isset( $_GET['mt_next'] ) ? absint( $_GET['mt_next'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- a read-only notice flag.

		$skipped = isset( $_GET['mt_skipped'] ) ? absint( $_GET['mt_skipped'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- a read-only notice flag.

		$summary = sprintf(
			/* translators: 1: number of files converted, 2: bytes saved. */
			_n( 'Converted %1$d file and saved %2$s.', 'Converted %1$d files and saved %2$s.', $converted, 'majestic-turbo' ),
			$converted,
			size_format( $saved, 1 )
		);

		if ( $skipped > 0 ) {
			$summary .= ' ' . sprintf(
				/* translators: %d: number of files checked and left alone. */
				_n( '%d more file was checked and needed no conversion.', '%d more files were checked and needed no conversion.', $skipped, 'majestic-turbo' ),
				$skipped
			);
		}

		printf(
			'<div class="notice notice-success"><p><strong>%1$s</strong></p>%2$s</div>',
			esc_html( $summary ),
			$next > 0 ? self::images_form( $next, __( 'Continue with the next batch', 'majestic-turbo' ) ) : '' // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts.
		);
	}

	/**
	 * The form that runs one conversion batch from an offset.
	 *
	 * @param int    $offset Where the batch starts.
	 * @param string $label  Button label.
	 * @return string
	 */
	private static function images_form( $offset, $label ) {
		return sprintf(
			'<form action="%1$s" method="post" style="margin:12px 0"><input type="hidden" name="action" value="majestic_turbo_images" /><input type="hidden" name="mt_offset" value="%2$d" />%3$s%4$s</form>',
			esc_url( admin_url( 'admin-post.php' ) ),
			(int) $offset,
			wp_nonce_field( 'majestic_turbo_images', '_mt_nonce', true, false ),
			get_submit_button( $label, 'secondary', 'submit', false ) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- core builds this markup and escapes the label.
		);
	}

	/**
	 * Convert one batch of the library, then send the browser back.
	 *
	 * The batch size is filterable because a fast host with a generous
	 * `max_execution_time` can convert far more per click than a shared one,
	 * and a batch that times out mid-file leaves the library half converted.
	 *
	 * @return void
	 */
	public static function handle_images() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to do that.', 'majestic-turbo' ), 403 );
		}

		check_admin_referer( 'majestic_turbo_images', '_mt_nonce' );

		$offset  = isset( $_POST['mt_offset'] ) ? absint( $_POST['mt_offset'] ) : 0;
		$limit   = (int) apply_filters( 'majestic_turbo_image_batch', 15 );
		$limit   = max( 1, min( 100, $limit ) );
		$saved   = 0;
		$files   = 0;
		$skipped = 0;

		if ( class_exists( 'Majestic_Turbo_Images' ) && Majestic_Turbo_Images::any_enabled() ) {
			$ids = Majestic_Turbo_Images::library_ids( $offset, $limit );
			$run = Majestic_Turbo_Images::run_start( 'admin', count( $ids ) );

			foreach ( $ids as $id ) {
				$result   = Majestic_Turbo_Images::convert_attachment( (int) $id );
				$files   += (int) $result['files'] + (int) $result['avif_files'];
				$saved   += (int) $result['saved'] + (int) $result['avif_saved'];
				$skipped += (int) $result['kept'];
				$run      = Majestic_Turbo_Images::run_add( $run, (int) $id, $result );
			}

			Majestic_Turbo_Images::run_finish( $run );

			$offset += count( $ids );

			// The stored pages carry the markup this just changed - a picture
			// that was not there a moment ago.
			if ( $files > 0 ) {
				Majestic_Turbo_Page_Cache::forget();
			}
		} else {
			$offset = 0;
		}

		$left = $offset > 0 ? max( 0, Majestic_Turbo_Images::library_count() - $offset ) : 0;

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'         => 'majestic-turbo',
					'mt_converted' => $files,
					'mt_saved'     => $saved,
					'mt_skipped'   => $skipped,
					'mt_next'      => $left > 0 ? $offset : 0,
				),
				admin_url( 'options-general.php' )
			)
		);
		exit;
	}

	/**
	 * Handle the purge button.
	 *
	 * @return void
	 */
	public static function handle_purge() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to do that.', 'majestic-turbo' ), 403 );
		}

		check_admin_referer( 'majestic_turbo_purge', '_mt_nonce' );

		$removed = majestic_turbo_purge();

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'      => 'majestic-turbo',
					'mt_purged' => (int) $removed,
				),
				admin_url( 'options-general.php' )
			)
		);
		exit;
	}
}
