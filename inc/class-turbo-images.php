<?php
/**
 * WebP and AVIF images.
 *
 * The image audits on a tube page are not about the code: a 480x270 JPEG at
 * 43 KB is a 43 KB download whether it is lazy, eager or inlined, and the same
 * picture as WebP is a third of that. This module produces those WebP files
 * beside the originals and lets the page offer them, without touching the
 * theme, the media library's filenames or anything a rollback would need.
 *
 * A converted file is a sibling, never a replacement: `1-304.jpg` stays exactly
 * where WordPress put it, and `1-304.webp` sits next to it. Every rewrite is
 * therefore optional at both ends - a page still works with the files deleted,
 * and deleting this plugin leaves the library untouched. Only a file that is
 * actually smaller than what it stands in for is kept, so an already-tight PNG
 * or a two-colour icon is never made bigger in the name of a better format.
 *
 * AVIF is the second format, and it is held to a stronger test than WebP: it
 * is kept only when it beats the WebP file it would replace, because there is
 * no point handing a browser a newer format that is not smaller. On the small
 * thumbnails a card grid uses that turns out to be a narrow win, and at the
 * smallest sizes the file can come out larger, for several times the encoding
 * time. That is why the switch ships off: a site whose library holds larger
 * photographs can turn it on, and a site serving 240-480px cards loses nothing
 * by leaving it alone.
 *
 * @package Majestic Turbo
 */

defined( 'ABSPATH' ) || exit;

/**
 * Generate WebP siblings and find them for the page transformer.
 */
class Majestic_Turbo_Images {

	/**
	 * Default WebP quality.
	 *
	 * 75, not the 82 a photograph is usually saved at, for a reason that only
	 * shows up in a card grid: the theme's thumbnails are already tight JPEGs,
	 * and at 82 the WebP of a 320x180 crop came out no smaller - so that size
	 * had no WebP file at all, and a browser choosing between the remaining
 * candidates jumped to the 480 wide one. 75 is where each size still beats
 * its JPEG in the widths a card grid serves, and the picture is still
 * indistinguishable at the size the grid displays it.
	 */
	const QUALITY = 75;

	/**
	 * Default AVIF quality.
	 *
 * Chosen so the AVIF has to be meaningfully smaller than the WebP at matched
 * sharpness before it is kept. A quality that merely ties the WebP's size at
 * matched sharpness converts almost nothing and saves the pass little; the
 * default sits below that point, where the files are smaller for a small loss
 * of detail.
 *
 * The number is a starting point, not a promise: `majestic_turbo_avif_quality`
	 * moves it, and the conversion keeps the file only when it beats the WebP
	 * beside it, so a quality that is too high costs the pass and never a byte.
	 */
	const AVIF_QUALITY = 50;

	/**
	 * Option that holds the last conversion run and its recent file results.
	 *
	 * A conversion that reports only a total is a black box: the administrator
	 * cannot tell a library that had nothing left to do from one whose files
	 * were all bigger in the new format. The last run is kept as an option, not
	 * a log file, because the option is bounded, needs no cleanup and cannot be
	 * fetched from the uploads URL by accident.
	 */
	const STATUS_OPTION = 'majestic_turbo_image_status';

	/**
	 * File results kept for the last run.
	 *
	 * Enough to see what a small batch did and a sample of a large one. The
	 * counters beside it cover the whole run; this list is the detail a person
	 * reads after the fact.
	 */
	const STATUS_RECENT = 40;

	/**
	 * When the conversion of one upload has to stop and leave the rest.
	 *
	 * The upload path is synchronous: WordPress does not answer the request
	 * until every size is written, and this module adds an encode per size per
	 * format on top - AVIF taking several times as long as WebP on the same
	 * crop. A large poster on a shared host can therefore push an upload past
	 * `max_execution_time`, and a fatal there is worse than an upload without
	 * converted files: the attachment metadata never gets stored. A deadline
	 * turns that into a partial conversion the batch pass finishes later.
	 *
	 * @var float Unix time after which no new file is started, 0 for no limit.
	 */
	private static $deadline = 0.0;

	/**
	 * Register the hooks.
	 *
	 * Connected when either format is switched on, not only WebP: a site that
	 * serves AVIF alone still has to convert what it uploads, and the AVIF
	 * pass works without a WebP file beside it (it falls back to comparing
	 * against the JPEG).
	 *
	 * @return void
	 */
	public static function init() {
		if ( ! self::any_enabled() ) {
			return;
		}

		// After WordPress has written the size variants: converting before
		// that would miss every size the upload creates.
		add_filter( 'wp_generate_attachment_metadata', array( __CLASS__, 'on_upload' ), 20, 2 );
	}

	/**
	 * Whether the current conversion has run out of its upload budget.
	 *
	 * @return bool
	 */
	private static function out_of_time() {
		return self::$deadline > 0 && microtime( true ) >= self::$deadline;
	}

	/**
	 * Whether the feature is switched on.
	 *
	 * @return bool
	 */
	public static function enabled() {
		return majestic_turbo_option_is_on( 'webp_images' );
	}

	/**
	 * Whether either format is switched on.
	 *
	 * The two formats stand alone: a host whose GD build has no WebP but does
	 * have AVIF serves AVIF, and one with WebP alone serves WebP. Nothing in
	 * the pipeline may require the other format to exist, and neither switch
	 * depends on the other.
	 *
	 * @return bool
	 */
	public static function any_enabled() {
		return self::enabled() || self::avif_enabled();
	}

	/**
	 * Whether AVIF is switched on.
	 *
	 * The read path asks only for the setting: an AVIF source is printed when
	 * the file beside the original exists, which is the only fact that matters
	 * to a browser. Whether this host can *write* AVIF is checked where a file
	 * is written, so a page view never has to probe the image editor - and a
	 * library converted on a host that could write AVIF keeps being served on
	 * one that cannot.
	 *
	 * @return bool
	 */
	public static function avif_enabled() {
		return majestic_turbo_option_is_on( 'avif_images' );
	}

	/**
	 * Whether this server can write WebP at all.
	 *
	 * @return bool
	 */
	public static function supports() {
		static $supports = null;

		if ( null === $supports ) {
			$supports = wp_image_editor_supports( array( 'mime_type' => 'image/webp' ) );
		}

		return (bool) $supports;
	}

	/**
	 * Whether this server can write AVIF at all.
	 *
	 * GD has written AVIF since PHP 8.1 and Imagick when the build carries
	 * libheif, so the answer is a property of the host rather than of the PHP
	 * version alone.
	 *
	 * @return bool
	 */
	public static function avif_supports() {
		static $supports = null;

		if ( null === $supports ) {
			$supports = wp_image_editor_supports( array( 'mime_type' => 'image/avif' ) );
		}

		return (bool) $supports;
	}

	/**
	 * The quality new WebP files are written at.
	 *
	 * @return int
	 */
	public static function quality() {
		/**
		 * Filter the WebP quality.
		 *
		 * @param int $quality 1-100.
		 */
		return max( 1, min( 100, (int) apply_filters( 'majestic_turbo_webp_quality', self::QUALITY ) ) );
	}

	/**
	 * The quality new AVIF files are written at.
	 *
	 * @return int
	 */
	public static function avif_quality() {
		/**
		 * Filter the AVIF quality.
		 *
		 * @param int $quality 1-100.
		 */
		return max( 1, min( 100, (int) apply_filters( 'majestic_turbo_avif_quality', self::AVIF_QUALITY ) ) );
	}

	/**
	 * The WebP sibling of a media URL, when one exists and is current.
	 *
	 * The date check is what keeps a re-cropped thumbnail from serving the
	 * picture of the old crop: WordPress writes a new JPEG every time a size
	 * is regenerated, while the WebP beside it keeps its old timestamp until
	 * this module converts it again.
	 *
	 * @param string $url Image URL.
	 * @return string WebP URL, or an empty string when there is nothing to use.
	 */
	public static function sibling( $url ) {
		if ( ! self::enabled() ) {
			return '';
		}

		return self::sibling_of( $url, 'webp' );
	}

	/**
	 * The AVIF sibling of a media URL, when one exists, is current and wins.
	 *
	 * Held to two checks the WebP sibling does not need. The shared one is the
	 * timestamp: a re-cropped thumbnail must not serve the picture of the old
	 * crop. The extra one is size - an AVIF that is not smaller than the WebP
	 * beside it is a newer format in a bigger download, so it is not offered
	 * even if something else wrote it into the library.
	 *
	 * @param string $url Image URL.
	 * @return string AVIF URL, or an empty string when there is nothing to use.
	 */
	public static function avif_sibling( $url ) {
		if ( ! self::avif_enabled() ) {
			return '';
		}

		$avif = self::sibling_of( $url, 'avif' );

		if ( '' === $avif ) {
			return '';
		}

		$path = self::path( $url );
		$webp = '' === $path ? '' : self::swap_extension( $path, 'webp' );

		if ( '' !== $webp && is_file( $webp ) && is_file( self::swap_extension( $path, 'avif' ) ) ) {
			$avif_size = (int) @filesize( self::swap_extension( $path, 'avif' ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- a failed stat falls through to the URL below.
			$webp_size = (int) @filesize( $webp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- same.

			if ( $avif_size > 0 && $webp_size > 0 && $avif_size >= $webp_size ) {
				return '';
			}
		}

		return $avif;
	}

	/**
	 * The sibling of a media URL in one format, when it exists and is current.
	 *
	 * @param string $url    Image URL.
	 * @param string $format webp or avif.
	 * @return string
	 */
	private static function sibling_of( $url, $format ) {
		$url = trim( (string) $url );

		if ( '' === $url || ! preg_match( '/\.(?:jpe?g|png)(?:\?|$)/i', $url ) ) {
			return '';
		}

		$path = self::path( $url );

		if ( '' === $path || ! is_file( $path ) ) {
			return '';
		}

		$target = self::swap_extension( $path, $format );

		if ( '' === $target || ! is_file( $target ) ) {
			return '';
		}

		$source_time = (int) @filemtime( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- a race with a deletion is handled by the check below.
		$target_time = (int) @filemtime( $target ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- same.

		if ( $target_time > 0 && $source_time > 0 && $target_time < $source_time ) {
			return '';
		}

		return self::swap_extension( $url, $format );
	}

	/**
	 * Filesystem path of a media URL inside the uploads directory.
	 *
	 * @param string $url Image URL.
	 * @return string Absolute path, or an empty string for a URL outside uploads.
	 */
	public static function path( $url ) {
		$uploads = wp_upload_dir( null, false );

		if ( ! empty( $uploads['error'] ) || empty( $uploads['baseurl'] ) || empty( $uploads['basedir'] ) ) {
			return '';
		}

		$url   = trim( (string) $url );
		$parts = '' === $url ? false : wp_parse_url( $url );
		$base  = wp_parse_url( $uploads['baseurl'] );

		if ( ! is_array( $parts ) || ! is_array( $base ) || empty( $parts['path'] ) || empty( $base['path'] ) ) {
			return '';
		}

		/*
		 * Compare paths, not full URLs. A page can print `https://` while the
		 * uploads record says `http://` - a site moved to TLS behind a proxy - and
		 * both name the same file. Matching the whole string left every image
		 * without a converted sibling, so no `<picture>` was printed at all.
		 *
		 * The host still has to be this site's: a third-party image that happens
		 * to live under the same path must not be offered a local file it cannot
		 * reach. `www.` is the same host; a CDN that mirrors uploads filters
		 * `wp_upload_dir()`, so its host arrives in the base URL instead.
		 */
		if ( ! empty( $parts['host'] ) && ! empty( $base['host'] ) && ! self::same_host( $parts['host'], $base['host'] ) ) {
			return '';
		}

		$base_path = untrailingslashit( $base['path'] );

		if ( 0 !== strpos( $parts['path'], $base_path . '/' ) ) {
			return '';
		}

		$relative = substr( $parts['path'], strlen( $base_path ) );

		if ( '' === $relative || false !== strpos( $relative, '..' ) ) {
			return '';
		}

		return untrailingslashit( $uploads['basedir'] ) . $relative;
	}

	/**
	 * Whether two host names are the same site.
	 *
	 * @param string $a First host.
	 * @param string $b Second host.
	 * @return bool
	 */
	private static function same_host( $a, $b ) {
		$a = strtolower( preg_replace( '/^www\./i', '', (string) $a ) );
		$b = strtolower( preg_replace( '/^www\./i', '', (string) $b ) );

		return '' !== $a && $a === $b;
	}

	/**
	 * Swap the extension of a path or URL for one of the converted formats.
	 *
	 * The format is checked against the two this module writes, so a call
	 * cannot turn a source path into an arbitrary filename; and the swap only
	 * happens on a JPEG or PNG, so a caller that passes something else gets its
	 * own string back rather than a second name for the same file.
	 *
	 * @param string $value  Path or URL.
	 * @param string $format webp or avif.
	 * @return string Empty when the extension cannot follow, the input otherwise.
	 */
	private static function swap_extension( $value, $format ) {
		if ( ! in_array( $format, array( 'webp', 'avif' ), true ) ) {
			return '';
		}

		if ( ! preg_match( '/\.(?:jpe?g|png)(?=\?|$)/i', (string) $value ) ) {
			return '';
		}

		return (string) preg_replace( '/\.(?:jpe?g|png)(?=\?|$)/i', '.' . $format, (string) $value );
	}

	/**
	 * Swap the extension of a URL for WebP, keeping its query string.
	 *
	 * @param string $url Image URL.
	 * @return string
	 */
	public static function webp_url( $url ) {
		return self::swap_extension( $url, 'webp' );
	}

	/**
	 * Convert one file, when that is worth doing.
	 *
	 * @param string $file  Absolute path.
	 * @param bool   $force Rewrite a WebP that is already there.
	 * @return array{converted: bool, saved: int, reason: string, file: string, format: string, before: int, after: int}
	 */
	public static function convert_file( $file, $force = false ) {
		return self::convert_format( $file, 'webp', self::quality(), $force, '' );
	}

	/**
	 * Convert one file to AVIF, when that is worth doing.
	 *
	 * @param string $file  Absolute path.
	 * @param bool   $force Rewrite an AVIF that is already there.
	 * @return array{converted: bool, saved: int, reason: string, file: string, format: string, before: int, after: int}
	 */
	public static function convert_avif( $file, $force = false ) {
		return self::convert_format( $file, 'avif', self::avif_quality(), $force, self::swap_extension( (string) $file, 'webp' ) );
	}

	/**
	 * Write one converted sibling, keeping it only when it is smaller.
	 *
	 * `$compare` is what the new file has to beat. For WebP that is the JPEG
	 * or PNG it was made from; for AVIF it is the WebP beside it, because a
	 * newer format that does not save bytes has nothing to offer a browser. A
	 * missing WebP falls back to the source, so an AVIF made before the WebP
	 * pass still has a baseline.
	 *
	 * @param string $file    Absolute path.
	 * @param string $format  webp or avif.
	 * @param int    $quality 1-100.
	 * @param bool   $force   Rewrite a file that is already there.
	 * @param string $compare File the new one has to be smaller than.
	 * @return array{converted: bool, saved: int, reason: string, file: string, format: string, before: int, after: int}
	 */
	private static function convert_format( $file, $format, $quality, $force, $compare ) {
		$result = array(
			'converted' => false,
			'saved'     => 0,
			'reason'    => '',
			'file'      => (string) $file,
			'format'    => (string) $format,
			'before'    => 0,
			'after'     => 0,
		);

		$label = strtoupper( $format );

		if ( 'webp' === $format ? ! self::supports() : ! self::avif_supports() ) {
			$result['reason'] = 'this server cannot write ' . $label;

			return $result;
		}

		if ( ! is_file( $file ) ) {
			$result['reason'] = 'the file is missing';

			return $result;
		}

		if ( ! preg_match( '/\.(?:jpe?g|png)$/i', $file ) ) {
			$result['reason'] = 'not a JPEG or PNG';

			return $result;
		}

		$target = self::swap_extension( $file, $format );

		if ( '' === $target || $target === $file ) {
			$result['reason'] = 'not a JPEG or PNG';

			return $result;
		}

		if ( ! $force && is_file( $target ) ) {
			$result['reason'] = 'already converted';

			return $result;
		}

		$before = (int) @filesize( $file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- handled through the size check below.

		$result['before'] = max( 0, $before );

		if ( $before < 1 ) {
			$result['reason'] = 'the file is empty';

			return $result;
		}

		$baseline = $before;
		$against  = '';

		if ( '' !== $compare && $compare !== $file && is_file( $compare ) ) {
			$other = (int) @filesize( $compare ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- a failed stat keeps the source baseline.

			if ( $other > 0 ) {
				$baseline = $other;
				$against  = $compare;
			}
		}

		$editor = wp_get_image_editor( $file );

		if ( is_wp_error( $editor ) ) {
			$result['reason'] = $editor->get_error_message();

			return $result;
		}

		if ( ! $editor->supports_mime_type( 'image/' . $format ) ) {
			$result['reason'] = 'the image editor cannot write ' . $label;

			return $result;
		}

		$editor->set_quality( $quality );

		/*
		 * The editor throws that number away. `set_quality()` is called again
		 * from `get_output_format()` while saving, because the output mime
		 * differs from the file's: it resets to the default for the format,
		 * which is 82 for AVIF and 86 for WebP. Every file this module wrote
		 * before this filter was therefore at 86 rather than at the quality
		 * the setting named, which made every converted file larger than the
		 * setting had asked for. The filter is the only hook that survives that reset, so it is
		 * attached for the one save and removed straight after.
		 */
		$mime   = 'image/' . $format;
		$pin    = static function ( $value, $type ) use ( $mime, $quality ) {
			return $mime === $type ? $quality : $value;
		};

		add_filter( 'wp_editor_set_quality', $pin, 10, 2 );

		$saved = $editor->save( $target, $mime );

		remove_filter( 'wp_editor_set_quality', $pin, 10 );

		if ( is_wp_error( $saved ) ) {
			$result['reason'] = $saved->get_error_message();

			return $result;
		}

		$after = (int) @filesize( $saved['path'] ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- a failed write reports 0 below.

		$result['after'] = max( 0, $after );

		// A file that is not smaller is a worse download in a better format.
		if ( $after < 1 || $after >= $baseline ) {
			@unlink( $saved['path'] ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_unlink -- a file this call just wrote.

			$result['after'] = 0;

			if ( 'webp' === $format ) {
				$result['reason'] = 'the WebP was not smaller';
			} elseif ( '' !== $against ) {
				$result['reason'] = 'the AVIF was not smaller than the WebP';
			} else {
				// No WebP beside it to beat, so the original is the baseline.
				$result['reason'] = 'the AVIF was not smaller than the original';
			}

			return $result;
		}

		$result['converted'] = true;
		$result['saved']     = $baseline - $after;

		return $result;
	}

	/**
	 * Convert every size of one attachment.
	 *
	 * `files` and `saved` are the WebP pass, measured against the originals.
	 * `avif_files` and `avif_saved` are the AVIF pass, measured against the
	 * WebP files it replaces, so adding the two savings together is what the
	 * site actually stops downloading - not a double count. `deferred` counts
	 * the files left alone because an upload ran out of its budget; the batch
	 * pass picks them up on its next run.
	 *
	 * The WebP half is skipped when that switch is off, so an AVIF-only site
	 * does not fill its uploads directory with files nothing will ever offer.
	 *
	 * `details` is the same work file by file: one entry per file and format,
	 * with its own before/after size and the reason a file was left alone. The
	 * counters above are what a summary needs; the list is what answers "why did
	 * this attachment produce nothing" after the fact.
	 *
	 * @param int  $attachment_id Attachment ID.
	 * @param bool $force         Rewrite files that already exist.
	 * @return array{files: int, saved: int, kept: int, avif_files: int, avif_saved: int, deferred: int, details: array<int, array<string, mixed>>}
	 */
	public static function convert_attachment( $attachment_id, $force = false ) {
		$out = array(
			'files'      => 0,
			'saved'      => 0,
			'kept'       => 0,
			'avif_files' => 0,
			'avif_saved' => 0,
			'deferred'   => 0,
			'details'    => array(),
		);

		/*
		 * A format this host cannot write is dropped here rather than per file:
		 * a run on an AVIF-only host should not record one "cannot write WebP"
		 * line for every size of every attachment.
		 */
		$webp = self::enabled() && self::supports();
		$avif = self::avif_enabled() && self::avif_supports();

		if ( ! $webp && ! $avif ) {
			return $out;
		}

		$original = get_attached_file( (int) $attachment_id );

		if ( ! is_string( $original ) || '' === $original || ! is_file( $original ) ) {
			return $out;
		}

		$files = array( $original );
		$meta  = wp_get_attachment_metadata( (int) $attachment_id );
		$dir   = dirname( $original );

		if ( ! empty( $meta['sizes'] ) && is_array( $meta['sizes'] ) ) {
			foreach ( $meta['sizes'] as $size ) {
				if ( ! empty( $size['file'] ) ) {
					$files[] = $dir . '/' . $size['file'];
				}
			}
		}

		/*
		 * Sizes on disk that the metadata does not list. A theme that adds an
		 * image size after the library was uploaded, or a regeneration that
		 * stored the crop but not the record, leaves exactly this: the card
		 * requests `1-104-336x189.jpg` and nothing in `$meta` names it. A
		 * conversion pass that trusted the metadata alone would leave the
		 * images the page actually paints as the only ones without a converted
		 * sibling.
		 */
		$base = pathinfo( $original, PATHINFO_FILENAME );

		if ( '' !== $base ) {
			foreach ( (array) glob( $dir . '/' . $base . '-*' ) as $candidate ) {
				if ( preg_match( '/^' . preg_quote( $base, '/' ) . '-\d+x\d+\.(?:jpe?g|png)$/i', basename( $candidate ) ) ) {
					$files[] = $candidate;
				}
			}
		}

		foreach ( array_unique( $files ) as $file ) {
			if ( self::out_of_time() ) {
				++$out['deferred'];
				$out['details'][] = array(
					'file'      => (string) $file,
					'format'    => $webp ? 'webp' : 'avif',
					'converted' => false,
					'saved'     => 0,
					'reason'    => 'the upload ran out of its time budget',
					'deferred'  => true,
					'before'    => (int) @filesize( $file ), // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- a failed stat is reported as 0.
					'after'     => 0,
				);

				continue;
			}

			$converted = false;

			if ( $webp ) {
				$result = self::convert_file( $file, $force );

				$out['details'][] = $result;

				if ( $result['converted'] ) {
					++$out['files'];
					$out['saved'] += $result['saved'];
					$converted = true;
				}
			}

			if ( ! $avif || self::out_of_time() ) {
				if ( ! $webp ) {
					++$out['deferred'];
					$out['details'][] = array(
						'file'      => (string) $file,
						'format'    => 'avif',
						'converted' => false,
						'saved'     => 0,
						'reason'    => 'the upload ran out of its time budget',
						'deferred'  => true,
						'before'    => (int) @filesize( $file ), // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- a failed stat is reported as 0.
						'after'     => 0,
					);
				} elseif ( ! $converted ) {
					++$out['kept'];
				}

				continue;
			}

			$result = self::convert_avif( $file, $force );

			$out['details'][] = $result;

			if ( $result['converted'] ) {
				++$out['avif_files'];
				$out['avif_saved'] += $result['saved'];
				$converted = true;
			}

			if ( ! $converted ) {
				++$out['kept'];
			}
		}

		return $out;
	}

	/**
	 * An empty run record.
	 *
	 * @return array<string, mixed>
	 */
	private static function empty_status() {
		return array(
			'time'        => 0,
			'source'      => '',
			'attachments' => 0,
			'files'       => 0,
			'avif_files'  => 0,
			'saved'       => 0,
			'avif_saved'  => 0,
			'skipped'     => 0,
			'deferred'    => 0,
			'reasons'     => array(),
			'recent'      => array(),
		);
	}

	/**
	 * The last conversion run, with its recent file results.
	 *
	 * A stored value written by an older version, or one someone edited, is
	 * completed from the defaults rather than trusted: a status screen that
	 * warns about an undefined index is worse than no status screen.
	 *
	 * @return array<string, mixed>
	 */
	public static function status() {
		if ( ! function_exists( 'get_option' ) ) {
			return self::empty_status();
		}

		$status = get_option( self::STATUS_OPTION, array() );

		if ( ! is_array( $status ) ) {
			$status = array();
		}

		$status = array_merge( self::empty_status(), $status );

		if ( ! is_array( $status['reasons'] ) ) {
			$status['reasons'] = array();
		}

		if ( ! is_array( $status['recent'] ) ) {
			$status['recent'] = array();
		}

		return $status;
	}

	/**
	 * Start a new run record.
	 *
	 * @param string $source   What started it: upload, cli or admin.
	 * @param int    $expected Attachments the run expects to walk, for context.
	 * @return array<string, mixed>
	 */
	public static function run_start( $source, $expected = 0 ) {
		$run             = self::empty_status();
		$run['time']     = time();
		$run['source']   = in_array( $source, array( 'upload', 'cli', 'admin' ), true ) ? $source : 'manual';
		$run['expected'] = max( 0, (int) $expected );

		return $run;
	}

	/**
	 * Add one attachment's result to a run record.
	 *
	 * The counters are the whole run; `recent` keeps the last few file results
	 * so the status screen can show what the last batch saw. Reasons are counted
	 * for the files that were left alone, which is the question a total cannot
	 * answer: "already converted" and "the WebP was not smaller" both end in the
	 * same zero.
	 *
	 * @param array<string, mixed> $run           Run record.
	 * @param int                  $attachment_id Attachment ID.
	 * @param array<string, mixed> $result        Return value of convert_attachment().
	 * @return array<string, mixed>
	 */
	public static function run_add( $run, $attachment_id, $result ) {
		++$run['attachments'];
		$run['files']      += (int) ( isset( $result['files'] ) ? $result['files'] : 0 );
		$run['avif_files'] += (int) ( isset( $result['avif_files'] ) ? $result['avif_files'] : 0 );
		$run['saved']      += (int) ( isset( $result['saved'] ) ? $result['saved'] : 0 );
		$run['avif_saved'] += (int) ( isset( $result['avif_saved'] ) ? $result['avif_saved'] : 0 );
		$run['skipped']    += (int) ( isset( $result['kept'] ) ? $result['kept'] : 0 );
		$run['deferred']   += (int) ( isset( $result['deferred'] ) ? $result['deferred'] : 0 );

		$details = isset( $result['details'] ) && is_array( $result['details'] ) ? $result['details'] : array();
		$files   = array();

		foreach ( $details as $detail ) {
			if ( ! is_array( $detail ) ) {
				continue;
			}

			$converted = ! empty( $detail['converted'] );
			$reason    = isset( $detail['reason'] ) ? (string) $detail['reason'] : '';
			$file      = isset( $detail['file'] ) ? (string) $detail['file'] : '';
			$format    = isset( $detail['format'] ) ? (string) $detail['format'] : '';

			/*
			 * A converted result names the source it read. What the status should
			 * show is the sibling it wrote, because that is the file the page now
			 * offers; a skipped result keeps the source, because nothing was
			 * written and the reason is about that file.
			 */
			$shown = $file;

			if ( $converted && in_array( $format, array( 'webp', 'avif' ), true ) ) {
				$shown = (string) preg_replace( '/\.(?:jpe?g|png)$/i', '.' . $format, $file );
			}

			$run['recent'][] = array(
				'attachment' => (int) $attachment_id,
				'file'       => '' !== $shown ? basename( $shown ) : '',
				'format'     => $format,
				'converted'  => $converted,
				'deferred'   => ! empty( $detail['deferred'] ),
				'before'     => isset( $detail['before'] ) ? (int) $detail['before'] : 0,
				'after'      => isset( $detail['after'] ) ? (int) $detail['after'] : 0,
				'saved'      => isset( $detail['saved'] ) ? (int) $detail['saved'] : 0,
				'reason'     => $reason,
			);

			// Deferred files were never checked, so they are counted by the
			// deferred counter and not as a reason a check was left alone.
			if ( ! empty( $detail['deferred'] ) ) {
				continue;
			}

			$key = '' !== $file ? $file : '#' . count( $files );

			if ( ! isset( $files[ $key ] ) ) {
				$files[ $key ] = array( 'converted' => false, 'reason' => '' );
			}

			if ( $converted ) {
				$files[ $key ]['converted'] = true;
			}

			/*
			 * "already converted" is the fallback, not the answer: a file checked
			 * in both formats is one file that produced nothing, and the reason
			 * worth reporting is the pass that lost, not the one already done.
			 */
			if ( '' !== $reason && 'already converted' !== $reason ) {
				$files[ $key ]['reason'] = $reason;
			} elseif ( '' === $files[ $key ]['reason'] ) {
				$files[ $key ]['reason'] = $reason;
			}
		}

		/*
		 * One reason per file, so the table adds up to the skipped counter beside
		 * it. Counting the format passes would report two skipped "files" for a
		 * JPEG whose WebP and AVIF were both left alone, and the two numbers on
		 * the screen would never agree.
		 */
		foreach ( $files as $file ) {
			if ( $file['converted'] ) {
				continue;
			}

			$reason = '' !== $file['reason'] ? $file['reason'] : 'no reason recorded';

			$run['reasons'][ $reason ] = ( isset( $run['reasons'][ $reason ] ) ? (int) $run['reasons'][ $reason ] : 0 ) + 1;
		}

		if ( count( $run['recent'] ) > self::STATUS_RECENT ) {
			$run['recent'] = array_slice( $run['recent'], -self::STATUS_RECENT );
		}

		arsort( $run['reasons'] );

		return $run;
	}

	/**
	 * Store a finished run record.
	 *
	 * Autoload is off: this is read on the settings screen and from a WP-CLI
	 * status call, not on a front-end request.
	 *
	 * @param array<string, mixed> $run Run record.
	 * @return array<string, mixed> The stored record.
	 */
	public static function run_finish( $run ) {
		unset( $run['expected'] );

		if ( function_exists( 'update_option' ) ) {
			update_option( self::STATUS_OPTION, $run, false );
		}

		return $run;
	}

	/**
	 * Attachment IDs a conversion pass walks, in ID order.
	 *
	 * A library that predates the plugin has no WebP files, so a new upload
	 * hook converts nothing on a site that already has its images. This is
	 * the read side of the settings screen button that converts that library
	 * a batch at a time.
	 *
	 * @param int $offset How many attachments to skip.
	 * @param int $limit  How many to return.
	 * @return int[]
	 */
	public static function library_ids( $offset, $limit ) {
		$ids = get_posts(
			array(
				'post_type'        => 'attachment',
				'post_status'      => 'inherit',
				'post_mime_type'   => array( 'image/jpeg', 'image/png' ),
				'numberposts'      => max( 1, (int) $limit ),
				'offset'           => max( 0, (int) $offset ),
				'fields'           => 'ids',
				'orderby'          => 'ID',
				'order'            => 'ASC',
				'no_found_rows'    => true,
				'suppress_filters' => false,
			)
		);

		return array_map( 'intval', (array) $ids );
	}

	/**
	 * How many attachments the conversion pass has to walk.
	 *
	 * @return int
	 */
	public static function library_count() {
		global $wpdb;

		return (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- one count behind a button a human pressed.
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = %s AND post_status = %s AND post_mime_type IN ( %s, %s )",
				'attachment',
				'inherit',
				'image/jpeg',
				'image/png'
			)
		);
	}

	/**
	 * Convert a new upload once its sizes exist.
	 *
	 * Runs inside the request that uploaded the file, which is the point: the
	 * post is published with converted files already on disk, so the first
	 * visit never downloads the JPEG. The budget keeps that from turning a
	 * large poster into a failed upload.
	 *
	 * @param array $metadata      Attachment metadata.
	 * @param int   $attachment_id Attachment ID.
	 * @return array The metadata, unchanged.
	 */
	public static function on_upload( $metadata, $attachment_id ) {
		$mime = (string) get_post_mime_type( (int) $attachment_id );

		if ( 'image/jpeg' !== $mime && 'image/png' !== $mime ) {
			return $metadata;
		}

		/**
		 * Filter how long one upload may spend converting, in seconds.
		 *
		 * 0 removes the limit. Files a short budget leaves behind keep their
		 * JPEG (and any WebP already written) and are converted by the next
		 * pass over the media library.
		 *
		 * @param float $seconds Seconds.
		 */
		$budget = (float) apply_filters( 'majestic_turbo_upload_budget', 8 );

		self::$deadline = $budget > 0 ? microtime( true ) + $budget : 0.0;

		$run    = self::run_start( 'upload', 1 );
		$result = self::convert_attachment( (int) $attachment_id );

		self::$deadline = 0.0;

		self::run_finish( self::run_add( $run, (int) $attachment_id, $result ) );

		return $metadata;
	}
}
