<?php
/**
 * Answer a cached page before WordPress loads.
 *
 * The page cache in the main plugin runs on plugins_loaded, which means
 * WordPress core, every active plugin and the database connection have already
 * been paid for. On a fast local machine that is a few milliseconds; on shared
 * hosting it is the largest part of time to first byte. This class is the same
 * answer, read straight from disk in the advanced-cache.php drop-in, before any
 * of that work happens.
 *
 * Nothing here may call a WordPress function: at this point only plugin.php and
 * load.php have been included. The request rules and the key live in
 * Majestic_Turbo_Key, which both this class and the plugin include, so the two
 * paths cannot compute different files for the same request.
 *
 * @package Majestic Turbo
 */

defined( 'ABSPATH' ) || exit;

/**
 * Build and send an early cache response.
 */
final class Majestic_Turbo_Early {

	/**
	 * Build the response for one request.
	 *
	 * Returns null for every case this class is not certain about - a miss, an
	 * expired entry, a personal cookie, a malformed file - and the normal
	 * WordPress request then renders the page as if the drop-in were not there.
	 *
	 * @param array      $config  Generated configuration.
	 * @param array      $server  Request server variables.
	 * @param array      $cookies Request cookies.
	 * @param int|null   $time    Current timestamp, for tests.
	 * @return array{status: int, headers: array<string, string>, body: string}|null
	 */
	public static function response( $config, $server, $cookies, $time = null ) {
		if ( empty( $config['enabled'] ) || empty( $config['dir'] ) ) {
			return null;
		}

		if ( defined( 'DONOTCACHEPAGE' ) && DONOTCACHEPAGE ) {
			return null;
		}

		$method = isset( $server['REQUEST_METHOD'] ) ? (string) $server['REQUEST_METHOD'] : '';
		$uri    = isset( $server['REQUEST_URI'] ) ? (string) $server['REQUEST_URI'] : '';

		if ( ! Majestic_Turbo_Key::allowed( $method, $uri, array_keys( (array) $cookies ) ) ) {
			return null;
		}

		$salt  = Majestic_Turbo_Key::site_salt();
		$agent = isset( $server['HTTP_USER_AGENT'] ) ? (string) $server['HTTP_USER_AGENT'] : '';

		$key = Majestic_Turbo_Key::hash(
			array(
				'host'   => isset( $config['host'] ) ? (string) $config['host'] : '',
				'path'   => (string) parse_url( $uri, PHP_URL_PATH ),
				'query'    => Majestic_Turbo_Key::query( $uri ),
				'mobile'   => Majestic_Turbo_Key::is_mobile( $agent ) ? 'mobile' : 'desktop',
				'language' => Majestic_Turbo_Key::language( $cookies ),
				'theme'    => isset( $config['theme'] ) ? (string) $config['theme'] : '',
				'salt'   => $salt,
				'plugin' => isset( $config['plugin'] ) ? (string) $config['plugin'] : '',
			)
		);

		$base = rtrim( (string) $config['dir'], '/\\' ) . '/page-cache/';
		$file = $base . $key . '.html.gz';

		if ( ! is_file( $file ) || ! is_readable( $file ) ) {
			return null;
		}

		$written = (int) filemtime( $file );
		$meta    = self::meta( $base . $key . '.json' );

		if ( ! empty( $meta['time'] ) ) {
			$written = (int) $meta['time'];
		}

		$ttl = (int) ( isset( $config['ttl'] ) ? $config['ttl'] : 0 );
		$now = null === $time ? time() : (int) $time;

		if ( $ttl > 0 && $written > 0 && ( $now - $written ) > $ttl ) {
			return null;
		}

		$raw = file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- a generated file this plugin wrote.

		if ( false === $raw || '' === $raw ) {
			return null;
		}

		$html = self::decode( $raw );

		if ( '' === $html ) {
			return null;
		}

		$headers = array(
			'Last-Modified'          => gmdate( 'D, d M Y H:i:s', $written ) . ' GMT',
			'X-Majestic-Turbo-Cache' => 'HIT',
			'Vary'                   => 'Accept-Encoding, Cookie, User-Agent',
			'Cache-Control'          => 'public, max-age=0, s-maxage=' . $ttl . ', stale-while-revalidate=30',
		);

		if ( self::is_not_modified( $server, $written ) ) {
			return array(
				'status'  => 304,
				'headers' => $headers,
				'body'    => '',
			);
		}

		$headers['Content-Type'] = 'text/html; charset=' . ( isset( $config['charset'] ) ? (string) $config['charset'] : 'UTF-8' );

		return array(
			'status'  => 200,
			'headers' => $headers,
			'body'    => $html,
		);
	}

	/**
	 * Send a response, or do nothing so WordPress can render the page.
	 *
	 * @param array $config Generated configuration.
	 * @return void
	 */
	public static function serve( $config ) {
		$response = self::response( $config, $_SERVER, $_COOKIE ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- read-only routing, the same values WordPress itself reads.

		if ( null === $response ) {
			return;
		}

		if ( ! headers_sent() ) {
			http_response_code( (int) $response['status'] );

			foreach ( $response['headers'] as $name => $value ) {
				header( $name . ': ' . $value );
			}
		}

		if ( 304 !== (int) $response['status'] ) {
			echo $response['body']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- the site's own stored markup.
		}

		exit;
	}

	/**
	 * Read an entry's metadata.
	 *
	 * @param string $file JSON path.
	 * @return array<string, mixed>
	 */
	private static function meta( $file ) {
		if ( ! is_readable( $file ) ) {
			return array();
		}

		$raw = file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- a generated file this plugin wrote.

		if ( false === $raw || '' === $raw ) {
			return array();
		}

		$meta = json_decode( $raw, true );

		return is_array( $meta ) ? $meta : array();
	}

	/**
	 * Whether the browser's copy is still current.
	 *
	 * @param array $server  Request server variables.
	 * @param int   $written Time the stored page was written.
	 * @return bool
	 */
	private static function is_not_modified( $server, $written ) {
		if ( empty( $server['HTTP_IF_MODIFIED_SINCE'] ) || $written < 1 ) {
			return false;
		}

		$since = strtotime( (string) $server['HTTP_IF_MODIFIED_SINCE'] );

		return false !== $since && $since > 0 && $written <= $since;
	}

	/**
	 * Decompress a stored page.
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
}
