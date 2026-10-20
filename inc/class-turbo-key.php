<?php
/**
 * The pure half of the page cache.
 *
 * Deciding whether a request may be cached, and under which name, has to give
 * the same answer in two very different places: inside WordPress, and in the
 * early drop-in that runs before WordPress has loaded wp-includes. This class
 * therefore uses nothing but PHP - no options, no filters, no theme functions -
 * so both callers can include it and neither can drift from the other.
 *
 * @package Majestic Turbo
 */

defined( 'ABSPATH' ) || exit;

/**
 * Cache key and request rules.
 */
final class Majestic_Turbo_Key {

	/**
	 * Query arguments a cached page may carry.
	 *
	 * @var string[]
	 */
	const ALLOWED_QUERY = array( 'paged', 'page', 'filter', 'orderby' );

	/**
	 * Cookies that name the language a visitor is reading in.
	 *
	 * A multilingual plugin can switch the language of the same URL with a
	 * cookie, which leaves the path and the query identical while the markup
	 * is a different language. The value is part of the cache key instead of
	 * a reason to skip the cache, so every language gets its own entry and
	 * the cache still works on a multilingual site.
	 *
	 * @var string[]
	 */
	const LANGUAGE_COOKIES = array(
		'wp-wpml_current_language',
		'_icl_current_language',
		'pll_language',
		'wpml_language',
	);

	/**
	 * Cookie prefixes that mean the response is personal.
	 *
	 * @var string[]
	 */
	const PRIVATE_COOKIES = array(
		'wordpress_logged_in_',
		'wordpress_sec_',
		'wp-postpass_',
		'comment_author_',
		'wp_woocommerce_session_',
		'woocommerce_items_in_cart',
		'woocommerce_cart_hash',
		'edd_items_in_cart',
	);

	/**
	 * Path fragments that are never cached.
	 *
	 * @var string[]
	 */
	const PRIVATE_PATHS = array(
		'/wp-admin',
		'/wp-login',
		'/wp-json',
		'/xmlrpc.php',
		'/wp-cron.php',
		'/wp-comments-post.php',
		'/feed',
		'/sitemap',
		/*
		 * WordPress core serves its sitemap at /wp-sitemap.xml, which no
		 * '/sitemap' check matches: the character before "sitemap" is a hyphen,
		 * not a slash. It is XML with no query string, so without this line it
		 * was stored and then answered with an HTML content type.
		 */
		'/wp-sitemap',
		'/robots.txt',
	);

	/**
	 * Whether a request may be answered from the cache.
	 *
	 * Deliberately narrow, and deliberately identical in the early drop-in:
	 * only GET, only a visitor with no login, comment or shop cookie, only the
	 * query arguments the key understands, and never a path WordPress would
	 * answer differently for every visitor.
	 *
	 * @param string   $method  Request method.
	 * @param string   $uri     Request URI, path and query.
	 * @param string[] $cookies Cookie names.
	 * @return bool
	 */
	public static function allowed( $method, $uri, $cookies ) {
		if ( 'GET' !== strtoupper( (string) $method ) ) {
			return false;
		}

		$uri = (string) $uri;

		if ( '' === $uri ) {
			return false;
		}

		$path = strtolower( (string) parse_url( $uri, PHP_URL_PATH ) );

		foreach ( self::PRIVATE_PATHS as $needle ) {
			if ( false !== strpos( $path, $needle ) ) {
				return false;
			}
		}

		foreach ( (array) $cookies as $cookie ) {
			foreach ( self::PRIVATE_COOKIES as $prefix ) {
				if ( 0 === strpos( (string) $cookie, $prefix ) ) {
					return false;
				}
			}
		}

		$query = array();

		parse_str( (string) parse_url( $uri, PHP_URL_QUERY ), $query );

		foreach ( array_keys( $query ) as $name ) {
			if ( ! in_array( (string) $name, self::ALLOWED_QUERY, true ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * The canonical query string a key carries.
	 *
	 * @param string $uri Request URI.
	 * @return string
	 */
	public static function query( $uri ) {
		$query = array();

		parse_str( (string) parse_url( (string) $uri, PHP_URL_QUERY ), $query );
		$query = array_intersect_key( $query, array_flip( self::ALLOWED_QUERY ) );
		ksort( $query );

		return http_build_query( $query );
	}

	/**
	 * The language a visitor chose, as a value safe to put in a key.
	 *
	 * The known language cookies are read in a fixed order, so the plugin and
	 * the drop-in cannot disagree about which one wins, and the value is
	 * reduced to the letters and digits a language code is made of: it is
	 * hashed, never printed, and a site whose cookie holds something else
	 * simply gets a cache that misses.
	 *
	 * @param array $cookies Request cookies, name to value.
	 * @return string
	 */
	public static function language( $cookies ) {
		if ( ! is_array( $cookies ) ) {
			return '';
		}

		foreach ( self::LANGUAGE_COOKIES as $name ) {
			if ( empty( $cookies[ $name ] ) || ! is_scalar( $cookies[ $name ] ) ) {
				continue;
			}

			$value = strtolower( (string) $cookies[ $name ] );
			$value = (string) preg_replace( '/[^a-z0-9_-]/', '', $value );
			$value = substr( $value, 0, 16 );

			if ( '' !== $value ) {
				return $value;
			}
		}

		return '';
	}

	/**
	 * The site salt the cache key is hashed with.
	 *
	 * The wp_salt() function lives in pluggable.php, which is not loaded when
	 * the drop-in runs, but the constants it reads come from wp-config.php and
	 * are defined by then. Reading them directly gives both paths the same value without a
	 * file to leak or rotate separately.
	 *
	 * @return string
	 */
	public static function site_salt() {
		foreach ( array( 'NONCE_SALT', 'AUTH_SALT', 'SECURE_AUTH_SALT', 'LOGGED_IN_SALT' ) as $constant ) {
			if ( defined( $constant ) && '' !== (string) constant( $constant ) ) {
				return (string) constant( $constant );
			}
		}

		return '';
	}

	/**
	 * Whether this request is the phone variant of a page.
	 *
	 * A copy of wp_is_mobile(), which is not loaded when the drop-in runs. If
	 * the two ever disagree the drop-in computes a key that is not on disk, so
	 * the worst outcome is a miss, never the wrong page.
	 *
	 * @param string $user_agent User-Agent header.
	 * @return bool
	 */
	public static function is_mobile( $user_agent ) {
		$user_agent = (string) $user_agent;

		if ( '' === $user_agent ) {
			return false;
		}

		foreach ( array( 'Mobile', 'Android', 'Silk/', 'Kindle', 'BlackBerry', 'Opera Mini', 'Opera Mobi' ) as $needle ) {
			if ( false !== strpos( $user_agent, $needle ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Hash the parts of a key.
	 *
	 * The order is fixed and every caller passes the same set: the host, the
	 * path without its trailing slash, the canonical query, the phone/desktop
	 * split, the language, the theme version, the site salt and the plugin
	 * version. A change to any of them retires every stored page.
	 *
	 * @param array $parts Key parts.
	 * @return string
	 */
	public static function hash( $parts ) {
		$ordered = array(
			'host'     => isset( $parts['host'] ) ? (string) $parts['host'] : '',
			'path'     => rtrim( (string) ( isset( $parts['path'] ) ? $parts['path'] : '' ), '/\\' ),
			'query'    => (string) ( isset( $parts['query'] ) ? $parts['query'] : '' ),
			'mobile'   => (string) ( isset( $parts['mobile'] ) ? $parts['mobile'] : 'desktop' ),
			'language' => (string) ( isset( $parts['language'] ) ? $parts['language'] : '' ),
			'theme'    => (string) ( isset( $parts['theme'] ) ? $parts['theme'] : '' ),
			'salt'     => (string) ( isset( $parts['salt'] ) ? $parts['salt'] : '' ),
			'plugin'   => (string) ( isset( $parts['plugin'] ) ? $parts['plugin'] : '' ),
		);

		return md5( implode( '|', $ordered ) );
	}
}
