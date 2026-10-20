<?php
/**
 * The cache warmer.
 *
 * A cache that is only filled by visitors is cold exactly when it matters: the
 * moment a video is published, the home page and the new permalink are the two
 * URLs everyone - and the crawler - asks for first, and both would be built
 * from scratch. This does that work once, in the background, so the first real
 * visitor gets the stored page.
 *
 * The warm request is an ordinary anonymous GET to the site's own URL, which
 * is the point: it goes through the same router, the same query and the same
 * transformer a visitor would, and the page cache stores it because it cannot
 * tell the two apart. Rendering the page in-process instead would prime a key
 * built from a request that does not exist, and any difference between the two
 * paths would be a cache that never hits.
 *
 * @package Majestic Turbo
 */

defined( 'ABSPATH' ) || exit;

/**
 * Build the URL list, prime it, and schedule the warm after a publish.
 */
class Majestic_Turbo_Warm {

	/**
	 * The cron hook a publish schedules.
	 */
	const EVENT = 'majestic_turbo_warm';

	/**
	 * Register the hooks.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( self::EVENT, array( __CLASS__, 'run_event' ), 10, 1 );
		add_action( 'transition_post_status', array( __CLASS__, 'on_transition' ), 20, 3 );
	}

	/**
	 * Queue a warm when a post becomes - or stays - published.
	 *
	 * Both halves matter and both are one event: a video going from draft to
	 * publish is a new URL, and an edit to a live video clears the cache that
	 * held it. Either way the two URLs are stale afterwards, so both schedule
	 * the same job. The job is delayed rather than run inline, because the
	 * editor should not wait on two HTTP requests to save a post.
	 *
	 * @param string  $new_status Status the post is moving to.
	 * @param string  $old_status Status it is moving from.
	 * @param WP_Post $post       The post.
	 * @return void
	 */
	public static function on_transition( $new_status, $old_status, $post ) {
		if ( 'publish' !== $new_status || ! $post instanceof WP_Post ) {
			return;
		}

		if ( ! majestic_turbo_option_is_on( 'page_cache' ) ) {
			return;
		}

		if ( wp_is_post_revision( $post ) || wp_is_post_autosave( $post ) ) {
			return;
		}

		if ( ! is_post_type_viewable( $post->post_type ) ) {
			return;
		}

		self::schedule( $post->ID );
	}

	/**
	 * Schedule one post's warm, unless it is already queued.
	 *
	 * @param int $post_id Post ID.
	 * @return bool Whether the job is queued.
	 */
	public static function schedule( $post_id ) {
		$post_id = (int) $post_id;

		if ( $post_id < 1 || ! function_exists( 'wp_schedule_single_event' ) ) {
			return false;
		}

		/**
		 * Filter how long after a publish the warm runs.
		 *
		 * A short delay lets the editor finish saving revisions and lets the
		 * invalidation hooks that fired on the same save finish clearing the
		 * old entry, so the warm stores the page as it is now.
		 *
		 * @param int $seconds Delay in seconds.
		 */
		$delay = max( 1, (int) apply_filters( 'majestic_turbo_warm_delay', 30 ) );

		if ( wp_next_scheduled( self::EVENT, array( $post_id ) ) ) {
			return true;
		}

		return (bool) wp_schedule_single_event( time() + $delay, self::EVENT, array( $post_id ) );
	}

	/**
	 * Run the scheduled warm for one post.
	 *
	 * @param int $post_id Post ID.
	 * @return void
	 */
	public static function run_event( $post_id ) {
		$post = get_post( (int) $post_id );

		// A post deleted, trashed or sent back to draft while the job waited
		// has no page to prime, and warming its old permalink would store a
		// 404 - or worse, the page of whatever took the slug.
		if ( ! $post instanceof WP_Post || 'publish' !== $post->post_status ) {
			return;
		}

		self::warm( self::urls( $post ) );
	}

	/**
	 * The URLs a publish makes stale: the home page and the post itself.
	 *
	 * @param WP_Post|null $post The published post, or null for home only.
	 * @return string[] Absolute URLs on this site, unique and in order.
	 */
	public static function urls( $post = null ) {
		$urls = array( home_url( '/' ) );

		if ( $post instanceof WP_Post && 'publish' === $post->post_status ) {
			$permalink = get_permalink( $post );

			if ( is_string( $permalink ) && '' !== $permalink ) {
				$urls[] = $permalink;
			}
		}

		/**
		 * Filter the URLs a warm primes.
		 *
		 * Term archives, a page that lists the new video, a hub the theme
		 * builds - any of them can be added here.
		 *
		 * @param string[]     $urls Absolute URLs.
		 * @param WP_Post|null $post The post being warmed, when there is one.
		 */
		$urls = apply_filters( 'majestic_turbo_warm_urls', $urls, $post );

		return self::prepare( $urls );
	}

	/**
	 * Normalize, filter and deduplicate a list of URLs.
	 *
	 * @param mixed $urls Candidate URLs, absolute or site-relative.
	 * @return string[]
	 */
	public static function prepare( $urls ) {
		$base   = home_url( '/' );
		$unique = array();

		foreach ( (array) $urls as $url ) {
			$clean = self::normalize( $url, $base );

			if ( '' === $clean ) {
				continue;
			}

			// A key, not a value: assigning twice keeps the first position,
			// so the home page stays first however often it is listed.
			$unique[ $clean ] = true;
		}

		return array_keys( $unique );
	}

	/**
	 * Turn one candidate into an absolute URL on this site, or nothing.
	 *
	 * The warmer only primes pages this site owns: a URL on another host would
	 * be a request the plugin has no business making, and a URL with a
	 * fragment names the same page as one without. The path is kept exactly -
	 * a trailing slash is what the permalink structure chose, and adding or
	 * dropping one would warm a URL that redirects.
	 *
	 * @param mixed  $url  Candidate URL or path.
	 * @param string $base Site URL.
	 * @return string
	 */
	public static function normalize( $url, $base ) {
		$url = trim( (string) $url );

		if ( '' === $url ) {
			return '';
		}

		$home = wp_parse_url( (string) $base );

		if ( ! is_array( $home ) || empty( $home['host'] ) ) {
			return '';
		}

		$scheme    = isset( $home['scheme'] ) && 'https' === strtolower( $home['scheme'] ) ? 'https' : 'http';
		$authority = strtolower( (string) $home['host'] ) . ( ! empty( $home['port'] ) ? ':' . (int) $home['port'] : '' );
		$origin    = $scheme . '://' . $authority;

		// A protocol-relative URL is the site's own scheme, not a new host.
		if ( 0 === strpos( $url, '//' ) ) {
			$url = $scheme . ':' . $url;
		}

		// A bare path is relative to the origin: the page cache keys on the
		// request path, which starts at the domain, not at the install.
		if ( '/' === $url[0] ) {
			$url = $origin . $url;
		}

		$parts = wp_parse_url( $url );

		if ( ! is_array( $parts ) || empty( $parts['host'] ) || empty( $parts['scheme'] ) ) {
			return '';
		}

		if ( ! in_array( strtolower( $parts['scheme'] ), array( 'http', 'https' ), true ) ) {
			return '';
		}

		$part_origin = strtolower( $parts['host'] );

		if ( ! empty( $parts['port'] ) ) {
			$part_origin .= ':' . (int) $parts['port'];
		}

		if ( $part_origin !== $authority ) {
			return '';
		}

		$path = isset( $parts['path'] ) && '' !== $parts['path'] ? $parts['path'] : '/';

		// A path that walks upwards is not a page; refusing it here keeps the
		// warmer from being talked into requesting something else entirely.
		if ( false !== strpos( $path, '..' ) ) {
			return '';
		}

		$query = isset( $parts['query'] ) && '' !== $parts['query'] ? '?' . $parts['query'] : '';

		return $scheme . '://' . $part_origin . $path . $query;
	}

	/**
	 * Request each URL so the page cache stores it.
	 *
	 * @param string[] $urls Absolute URLs.
	 * @return array<string, array<string, mixed>> Results keyed by URL.
	 */
	public static function warm( $urls ) {
		$results = array();

		foreach ( (array) $urls as $url ) {
			$response = wp_remote_get(
				$url,
				array(
					'timeout'     => (int) apply_filters( 'majestic_turbo_warm_timeout', 15 ),
					'redirection' => 3,
					'blocking'    => true,
					// A warm is not a visit: the origin builds and stores the
					// page, while an edge in front of it may hold its own copy.
					// Asking it not to answer from that copy keeps the origin
					// - the only place this plugin can fill - up to date.
					'headers'     => array(
						'Cache-Control'         => 'no-cache',
						'X-Majestic-Turbo-Warm' => '1',
					),
					'user-agent'  => 'Majestic-Turbo/' . MAJESTIC_TURBO_VERSION . '; ' . home_url( '/' ),
				)
			);

			$results[ $url ] = self::classify( $url, $response );
		}

		return $results;
	}

	/**
	 * Describe one response in the terms a cache owner cares about.
	 *
	 * @param string     $url      Requested URL.
	 * @param array|WP_Error $response Response from wp_remote_get().
	 * @return array<string, mixed>
	 */
	public static function classify( $url, $response ) {
		if ( is_wp_error( $response ) ) {
			return array(
				'url'    => $url,
				'ok'     => false,
				'state'  => 'error',
				'detail' => $response->get_error_message(),
			);
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$hit  = strtoupper( trim( (string) wp_remote_retrieve_header( $response, 'x-majestic-turbo-cache' ) ) );

		if ( 'HIT' === $hit ) {
			$state = 'hit';
		} elseif ( 'MISS' === $hit ) {
			$state = 'stored';
		} else {
			// A 200 with no cache header means the page was served by
			// something that is not the cache - a CDN holding an old copy, or
			// the plugin switched off between the job and the request.
			$state = 'uncached';
		}

		return array(
			'url'    => $url,
			'ok'     => 200 === $code,
			'state'  => $state,
			'detail' => 'HTTP ' . $code,
		);
	}
}
