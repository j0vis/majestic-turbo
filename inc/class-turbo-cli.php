<?php
/**
 * WP-CLI commands.
 *
 * The page cache fills itself from visits, which leaves one gap a shell cannot
 * otherwise close: priming a site whose traffic is not there yet - a fresh
 * install, a staging copy, a deploy that just flushed everything - and seeing
 * whether the priming worked. Both belong in `wp`, not in a browser.
 *
 * @package Majestic Turbo
 */

defined( 'ABSPATH' ) || exit;

/**
 * Manage the generated cache from the command line.
 */
class Majestic_Turbo_CLI {

	/**
	 * Prime the page cache for the home page, new posts and given URLs.
	 *
	 * Each URL is requested the way a visitor requests it, so the page cache
	 * stores the page the next visitor will be served. Prints one line per URL
	 * with what the cache did: `stored` for a page that was built and saved,
	 * `hit` for one that was already there, `uncached` when something other
	 * than the cache answered.
	 *
	 * ## OPTIONS
	 *
	 * [<url>...]
	 * : URLs to prime, absolute or relative to the site root.
	 *
	 * [--post=<id>...]
	 * : Post IDs to prime alongside the home page.
	 *
	 * [--limit=<number>]
	 * : How many of the newest published posts to prime when no URL and no
	 * post is given.
	 * ---
	 * default: 5
	 * ---
	 *
	 * [--dry-run]
	 * : Print the URLs that would be requested and stop.
	 *
	 * ## EXAMPLES
	 *
	 *     # The home page and the five newest posts.
	 *     $ wp majestic-turbo warm
	 *     Success: Primed 6 URLs.
	 *
	 *     # The home page and one post, by ID.
	 *     $ wp majestic-turbo warm --post=482
	 *
	 *     # Any page on this site.
	 *     $ wp majestic-turbo warm /category/amateur/ /
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Flags.
	 * @return void
	 */
	public function warm( $args, $assoc_args ) {
		if ( ! majestic_turbo_option_is_on( 'page_cache' ) ) {
			WP_CLI::error( 'The page cache is switched off, so a warm would store nothing. Turn it on under Settings > Majestic Turbo.' );
		}

		$requested = array();
		$skipped   = 0;

		foreach ( (array) $args as $url ) {
			$clean = Majestic_Turbo_Warm::normalize( $url, home_url( '/' ) );

			// Saying so beats warming the home page and reporting success: a
			// typo or another site's URL would otherwise look like it worked.
			if ( '' === $clean ) {
				WP_CLI::warning( sprintf( 'Skipped %s: not an http(s) page on this site.', $url ) );
				++$skipped;

				continue;
			}

			$requested[] = $clean;
		}

		$ids = self::ids( isset( $assoc_args['post'] ) ? $assoc_args['post'] : array() );

		foreach ( $ids as $id ) {
			$post = get_post( $id );

			if ( ! $post instanceof WP_Post || 'publish' !== $post->post_status ) {
				WP_CLI::warning( sprintf( 'Skipped post %d: it is not published.', $id ) );
				++$skipped;

				continue;
			}

			$permalink = get_permalink( $post );

			if ( is_string( $permalink ) && '' !== $permalink ) {
				$requested[] = $permalink;
			}
		}

		if ( ( $args || $ids ) && ! $requested ) {
			WP_CLI::error( 'None of the given URLs is a page on this site, so there is nothing to warm.' );
		}

		if ( ! $args && ! $ids ) {
			$limit = isset( $assoc_args['limit'] ) ? max( 0, (int) $assoc_args['limit'] ) : 5;

			foreach ( self::newest( $limit ) as $id ) {
				$permalink = get_permalink( $id );

				if ( is_string( $permalink ) && '' !== $permalink ) {
					$requested[] = $permalink;
				}
			}
		}

		// The home page is in every warm: it is the page a new post changes,
		// and the one a crawler asks for first.
		$urls = Majestic_Turbo_Warm::prepare( array_merge( array( home_url( '/' ) ), $requested ) );

		if ( ! $urls ) {
			WP_CLI::error( 'No URL on this site to warm.' );
		}

		if ( isset( $assoc_args['dry-run'] ) ) {
			foreach ( $urls as $url ) {
				WP_CLI::log( $url );
			}

			WP_CLI::success( sprintf( '%d URL(s) would be warmed.', count( $urls ) ) );

			return;
		}

		if ( $skipped ) {
			WP_CLI::log( sprintf( '%d request(s) skipped.', $skipped ) );
		}

		$results = Majestic_Turbo_Warm::warm( $urls );
		$failed  = 0;

		foreach ( $results as $result ) {
			if ( ! $result['ok'] ) {
				++$failed;
			}

			WP_CLI::log( sprintf( '%-8s %-7s %s', $result['state'], $result['detail'], $result['url'] ) );
		}

		if ( $failed ) {
			WP_CLI::error( sprintf( '%d of %d URL(s) could not be warmed. A 4xx means nothing is published at that URL; a transport error means the site cannot reach itself from the shell, which a blocked loopback or a password-protected staging site will cause.', $failed, count( $results ) ) );
		}

		WP_CLI::success( sprintf( 'Primed %d URL(s).', count( $results ) ) );
	}

	/**
	 * Generate the WebP - and, when it is switched on, the AVIF - version of
	 * every thumbnail in the media library.
	 *
	 * A converted file is written beside each JPEG or PNG, never over it, and
	 * only when it is smaller than what it stands in for: WebP against the
	 * original, AVIF against the WebP. Pages start offering them as soon as
	 * they exist: the page cache is cleared at the end so a stored page cannot
	 * keep pointing at the original.
	 *
	 * ## OPTIONS
	 *
	 * [--limit=<number>]
	 * : How many attachments to convert. 0 means every one of them.
	 * ---
	 * default: 200
	 * ---
	 *
	 * [--force]
	 * : Convert again where a WebP file already exists.
	 *
	 * [--dry-run]
	 * : Count what would be converted and write nothing.
	 *
	 * [--verbose]
	 * : Print one line per file: its size before and after, or why it was
	 * left alone.
	 *
	 * [--status]
	 * : Print the last conversion run and its recent file results, then stop.
	 *
	 * ## EXAMPLES
	 *
	 *     # Convert up to 200 attachments.
	 *     $ wp majestic-turbo images
	 *
	 *     # Convert the whole library.
	 *     $ wp majestic-turbo images --limit=0
	 *
	 *     # See what the last run did, file by file.
	 *     $ wp majestic-turbo images --status
	 *
	 *     # Watch one batch decide every file.
	 *     $ wp majestic-turbo images --limit=5 --verbose
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Flags.
	 * @return void
	 */
	public function images( $args, $assoc_args ) {
		if ( isset( $assoc_args['status'] ) ) {
			self::print_image_status();

			return;
		}

		$webp_on = majestic_turbo_option_is_on( 'webp_images' );
		$avif_on = majestic_turbo_option_is_on( 'avif_images' );

		if ( ! $webp_on && ! $avif_on ) {
			WP_CLI::error( 'WebP and AVIF images are both switched off, so nothing would be served. Turn one on under Settings > Majestic Turbo.' );
		}

		if ( ! Majestic_Turbo_Images::supports() && ! Majestic_Turbo_Images::avif_supports() ) {
			WP_CLI::error( 'This server can write neither WebP nor AVIF: the GD or Imagick build PHP is running supports no converted format.' );
		}

		/*
		 * The two formats are independent. A host with AVIF but no WebP converts
		 * AVIF, one with WebP and no AVIF converts WebP, and a format that was
		 * switched on but cannot be written here is a warning, not the end of
		 * the run - the other one is still worth producing.
		 */
		if ( $webp_on && ! Majestic_Turbo_Images::supports() ) {
			WP_CLI::warning( 'WebP is switched on, but this server cannot write it. Converting AVIF only.' );
		}

		if ( $avif_on && ! Majestic_Turbo_Images::avif_supports() && Majestic_Turbo_Images::supports() ) {
			WP_CLI::warning( 'AVIF is switched on, but this server cannot write it. Converting WebP only.' );
		}

		$limit   = isset( $assoc_args['limit'] ) ? (int) $assoc_args['limit'] : 200;
		$force   = isset( $assoc_args['force'] );
		$dry     = isset( $assoc_args['dry-run'] );
		$verbose = isset( $assoc_args['verbose'] );

		$ids = get_posts(
			array(
				'post_type'      => 'attachment',
				'post_status'    => 'inherit',
				'post_mime_type' => array( 'image/jpeg', 'image/png' ),
				'numberposts'    => $limit > 0 ? $limit : -1,
				'fields'         => 'ids',
				'orderby'        => 'ID',
				'order'          => 'ASC',
			)
		);

		$ids = array_map( 'intval', (array) $ids );

		if ( ! $ids ) {
			WP_CLI::success( 'No JPEG or PNG attachments to convert.' );

			return;
		}

		if ( $dry ) {
			foreach ( $ids as $id ) {
				WP_CLI::log( sprintf( 'would convert attachment %d (%s)', $id, self::title( $id ) ) );
			}

			WP_CLI::success( sprintf( '%d attachment(s) would be converted. Run again without --dry-run to write the files.', count( $ids ) ) );

			return;
		}

		WP_CLI::log( self::image_settings_line() );

		$run = Majestic_Turbo_Images::run_start( 'cli', count( $ids ) );

		foreach ( $ids as $id ) {
			$result = Majestic_Turbo_Images::convert_attachment( $id, $force );
			$run    = Majestic_Turbo_Images::run_add( $run, $id, $result );

			$converted = (int) $result['files'] + (int) $result['avif_files'];
			$saved     = (int) $result['saved'] + (int) $result['avif_saved'];
			$line      = sprintf( 'attachment %1$d  %2$s  ', $id, self::title( $id ) );

			if ( $converted > 0 ) {
				$line .= sprintf(
					'%1$d file(s) converted (%2$d WebP, %3$d AVIF), %4$s saved',
					$converted,
					(int) $result['files'],
					(int) $result['avif_files'],
					size_format( $saved )
				);
			} else {
				$line .= 'nothing converted';
			}

			$line .= sprintf( ', %d file(s) skipped', (int) $result['kept'] );

			if ( (int) $result['deferred'] > 0 ) {
				$line .= sprintf( ', %d deferred', (int) $result['deferred'] );
			}

			WP_CLI::log( $line );

			if ( $verbose ) {
				self::print_details( $result['details'] );
			}
		}

		$run = Majestic_Turbo_Images::run_finish( $run );

		if ( $run['files'] + $run['avif_files'] < 1 ) {
			WP_CLI::success( self::image_summary( $run, 'Nothing converted:' ) . ' The page cache was not touched.' );

			return;
		}

		majestic_turbo_purge();

		WP_CLI::success( self::image_summary( $run, 'Converted' ) . ' The page cache was cleared.' );
	}

	/**
	 * Print the last conversion run and a sample of its files.
	 *
	 * @return void
	 */
	private static function print_image_status() {
		$status = Majestic_Turbo_Images::status();

		WP_CLI::log( self::image_settings_line() );

		if ( (int) $status['time'] < 1 ) {
			WP_CLI::log( 'No conversion has run yet on this site.' );

			return;
		}

		WP_CLI::log(
			sprintf(
				'Last run %1$s ago (%2$s) by %3$s.',
				human_time_diff( (int) $status['time'] ),
				wp_date( 'Y-m-d H:i:s', (int) $status['time'] ),
				self::source_label( (string) $status['source'] )
			)
		);

		WP_CLI::log( self::image_summary( $status, 'Result:' ) );
		self::print_reasons( $status['reasons'] );

		$recent = array_slice( (array) $status['recent'], -40 );

		if ( $recent ) {
			WP_CLI::log( 'Recent files:' );

			foreach ( $recent as $entry ) {
				self::print_detail( $entry );
			}
		}
	}

	/**
	 * One line describing the formats and qualities a run would use.
	 *
	 * @return string
	 */
	private static function image_settings_line() {
		if ( ! Majestic_Turbo_Images::supports() ) {
			$webp = 'unavailable on this server';
		} else {
			$webp = majestic_turbo_option_is_on( 'webp_images' ) ? 'on at quality ' . Majestic_Turbo_Images::quality() : 'off';
		}

		if ( ! Majestic_Turbo_Images::avif_supports() ) {
			$avif = 'unavailable on this server';
		} else {
			$avif = majestic_turbo_option_is_on( 'avif_images' ) ? 'on at quality ' . Majestic_Turbo_Images::avif_quality() : 'off';
		}

		return sprintf( 'WebP: %1$s. AVIF: %2$s.', $webp, $avif );
	}

	/**
	 * A one-line summary of a run record.
	 *
	 * @param array<string, mixed> $run  Run record.
	 * @param string               $lead Prefix, e.g. Converted.
	 * @return string
	 */
	private static function image_summary( $run, $lead ) {
		$webp    = (int) ( isset( $run['files'] ) ? $run['files'] : 0 );
		$avif    = (int) ( isset( $run['avif_files'] ) ? $run['avif_files'] : 0 );
		$saved   = (int) ( isset( $run['saved'] ) ? $run['saved'] : 0 ) + (int) ( isset( $run['avif_saved'] ) ? $run['avif_saved'] : 0 );
		$skipped = (int) ( isset( $run['skipped'] ) ? $run['skipped'] : 0 );
		$defer   = (int) ( isset( $run['deferred'] ) ? $run['deferred'] : 0 );
		$summary = sprintf(
			'%1$s %2$d file(s)%3$s, %4$s saved',
			$lead,
			$webp + $avif,
			$avif > 0 ? sprintf( ' - %d of them AVIF', $avif ) : '',
			size_format( $saved )
		);

		if ( $skipped > 0 ) {
			$summary .= sprintf( '; %d file(s) skipped', $skipped );
		}

		if ( $defer > 0 ) {
			$summary .= sprintf( '; %d deferred by the time budget', $defer );
		}

		return $summary;
	}

	/**
	 * Print the reasons files were left alone, most common first.
	 *
	 * @param array<string, int> $reasons Reason counts.
	 * @return void
	 */
	private static function print_reasons( $reasons ) {
		if ( ! $reasons ) {
			return;
		}

		WP_CLI::log( 'Skipped because:' );

		foreach ( $reasons as $reason => $count ) {
			WP_CLI::log( sprintf( '  %s: %d', $reason, (int) $count ) );
		}
	}

	/**
	 * Print one file per line, with its sizes or the reason it was skipped.
	 *
	 * @param array<int, array<string, mixed>> $details File results.
	 * @return void
	 */
	private static function print_details( $details ) {
		foreach ( (array) $details as $detail ) {
			self::print_detail( $detail );
		}
	}

	/**
	 * Print one file result.
	 *
	 * @param array<string, mixed> $entry File result.
	 * @return void
	 */
	private static function print_detail( $entry ) {
		$file   = isset( $entry['file'] ) ? basename( (string) $entry['file'] ) : '';
		$format = isset( $entry['format'] ) ? strtoupper( (string) $entry['format'] ) : '';

		if ( ! empty( $entry['converted'] ) ) {
			WP_CLI::log(
				sprintf(
					'    %1$-28s %2$-4s %3$s -> %4$s  saved %5$s',
					$file,
					$format,
					size_format( (int) ( isset( $entry['before'] ) ? $entry['before'] : 0 ) ),
					size_format( (int) ( isset( $entry['after'] ) ? $entry['after'] : 0 ) ),
					size_format( (int) ( isset( $entry['saved'] ) ? $entry['saved'] : 0 ) )
				)
			);

			return;
		}

		WP_CLI::log(
			sprintf(
				'    %1$-28s %2$-4s %3$s: %4$s',
				$file,
				$format,
				! empty( $entry['deferred'] ) ? 'deferred' : 'skipped',
				isset( $entry['reason'] ) && '' !== $entry['reason'] ? $entry['reason'] : 'no reason recorded'
			)
		);
	}

	/**
	 * A short attachment title for a terminal line.
	 *
	 * @param int $id Attachment ID.
	 * @return string
	 */
	private static function title( $id ) {
		$title = trim( wp_strip_all_tags( (string) get_the_title( $id ) ) );

		if ( '' === $title ) {
			return '(no title)';
		}

		return wp_html_excerpt( $title, 60, '...' );
	}

	/**
	 * A human name for what started a run.
	 *
	 * @param string $source Stored source key.
	 * @return string
	 */
	private static function source_label( $source ) {
		$labels = array(
			'upload' => 'an upload',
			'cli'    => 'WP-CLI',
			'admin'  => 'the settings screen',
			'manual' => 'a manual call',
		);

		return isset( $labels[ $source ] ) ? $labels[ $source ] : $source;
	}

	/**
	 * Delete every generated file: the stylesheet, the scripts and the pages.
	 *
	 * ## EXAMPLES
	 *
	 *     $ wp majestic-turbo purge
	 *     Success: Removed 14 generated file(s).
	 *
	 * @return void
	 */
	public function purge() {
		$removed = majestic_turbo_purge();

		WP_CLI::success( sprintf( 'Removed %d generated file(s).', (int) $removed ) );
	}

	/**
	 * Read post IDs out of a flag that accepts a list.
	 *
	 * WP-CLI hands `--post=1 --post=2` over as an array and `--post=1,2` as one
	 * string, and a caller who pastes `--post=1, 2` gets the space too.
	 *
	 * @param mixed $value Flag value.
	 * @return int[]
	 */
	private static function ids( $value ) {
		$ids = array();

		foreach ( (array) $value as $chunk ) {
			foreach ( explode( ',', (string) $chunk ) as $id ) {
				$id = (int) trim( $id );

				if ( $id > 0 && ! in_array( $id, $ids, true ) ) {
					$ids[] = $id;
				}
			}
		}

		return $ids;
	}

	/**
	 * IDs of the newest published posts of the viewable types.
	 *
	 * Attachments are left out: a media library entry has no page of its own
	 * on most sites, and warming its attachment URL would store a redirect.
	 *
	 * @param int $limit How many.
	 * @return int[]
	 */
	private static function newest( $limit ) {
		if ( $limit < 1 ) {
			return array();
		}

		$types = get_post_types( array( 'public' => true ), 'names' );
		unset( $types['attachment'] );

		$ids = get_posts(
			array(
				'post_type'        => array_values( $types ),
				'post_status'      => 'publish',
				'numberposts'      => $limit,
				'fields'           => 'ids',
				'no_found_rows'    => true,
				'suppress_filters' => false,
			)
		);

		return array_map( 'intval', (array) $ids );
	}
}
