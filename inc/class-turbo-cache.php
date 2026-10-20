<?php
/**
 * The generated-file store.
 *
 * Everything this plugin builds - the combined stylesheet, the minified
 * scripts, the cached pages - is a file under one directory in uploads, so
 * there is a single place to look at, a single place to delete and a single
 * place a server rule can attach cache headers to. Nothing is written into the
 * theme or the plugin: an update replaces those directories wholesale, and a
 * generated file inside one would either be lost on the next update or, worse,
 * survive as a stale copy that no longer matches the code beside it.
 *
 * @package Majestic Turbo
 */

defined( 'ABSPATH' ) || exit;

/**
 * Read and write generated files.
 */
class Majestic_Turbo_Cache {

	/**
	 * Directory name inside uploads.
	 */
	const DIRECTORY = 'majestic-turbo';

	/**
	 * Absolute path of the store, resolved once.
	 *
	 * @var string|null
	 */
	private static $root = null;

	/**
	 * Public URL of the store, resolved once.
	 *
	 * @var string|null
	 */
	private static $url = null;

	/**
	 * Absolute path of the store.
	 *
	 * An empty string means uploads is not writable, and every caller treats
	 * that as "build nothing" rather than "write somewhere else": a plugin that
	 * falls back to the plugin directory is a plugin that writes into a
	 * directory an update will replace.
	 *
	 * @return string
	 */
	public static function root() {
		if ( null !== self::$root ) {
			return self::$root;
		}

		$uploads = wp_upload_dir( null, false );

		if ( ! empty( $uploads['error'] ) || empty( $uploads['basedir'] ) ) {
			self::$root = '';

			return self::$root;
		}

		self::$root = untrailingslashit( $uploads['basedir'] ) . '/' . self::DIRECTORY;

		return self::$root;
	}

	/**
	 * Public URL of the store.
	 *
	 * @return string
	 */
	public static function url() {
		if ( null !== self::$url ) {
			return self::$url;
		}

		$uploads = wp_upload_dir( null, false );

		if ( ! empty( $uploads['error'] ) || empty( $uploads['baseurl'] ) ) {
			self::$url = '';

			return self::$url;
		}

		self::$url = self::site_scheme( untrailingslashit( $uploads['baseurl'] ) ) . '/' . self::DIRECTORY;

		return self::$url;
	}

	/**
	 * Give a media URL the scheme the site is served with.
	 *
	 * A site behind a TLS proxy - or one moved to https without updating the
	 * database - keeps an `http://` uploads base URL while its pages are
	 * served as `https://`. A stylesheet or script built from that URL is
	 * mixed content and the browser refuses it, so the page runs with none of
	 * the combined CSS and none of the deferred JavaScript. The host has to
	 * match for this to apply: an offloaded CDN keeps the URL it was given.
	 *
	 * @param string $url Base URL of the store.
	 * @return string
	 */
	private static function site_scheme( $url ) {
		$home = wp_parse_url( home_url() );
		$base = wp_parse_url( $url );

		if ( ! is_array( $home ) || ! is_array( $base ) ) {
			return $url;
		}

		if ( empty( $home['scheme'] ) || empty( $home['host'] ) || empty( $base['scheme'] ) || empty( $base['host'] ) ) {
			return $url;
		}

		if ( strtolower( $home['scheme'] ) === strtolower( $base['scheme'] ) ) {
			return $url;
		}

		$a = strtolower( preg_replace( '/^www\./i', '', (string) $home['host'] ) );
		$b = strtolower( preg_replace( '/^www\./i', '', (string) $base['host'] ) );

		if ( $a !== $b ) {
			return $url;
		}

		return strtolower( $home['scheme'] ) . substr( $url, strlen( $base['scheme'] ) );
	}

	/**
	 * Turn a relative path into an absolute one, refusing anything that tries
	 * to leave the store.
	 *
	 * @param string $relative Path inside the store.
	 * @return string Absolute path, or an empty string when the path is unsafe.
	 */
	public static function path( $relative ) {
		$relative = self::clean( $relative );
		$root     = self::root();

		if ( '' === $relative || '' === $root ) {
			return '';
		}

		return $root . '/' . $relative;
	}

	/**
	 * Turn a relative path into a public URL.
	 *
	 * @param string $relative Path inside the store.
	 * @return string
	 */
	public static function file_url( $relative ) {
		$relative = self::clean( $relative );
		$url      = self::url();

		if ( '' === $relative || '' === $url ) {
			return '';
		}

		return $url . '/' . $relative;
	}

	/**
	 * Normalize a relative path and reject traversal.
	 *
	 * @param string $relative Path inside the store.
	 * @return string
	 */
	public static function clean( $relative ) {
		$relative = str_replace( '\\', '/', (string) $relative );
		$relative = ltrim( $relative, '/' );

		if ( '' === $relative || false !== strpos( $relative, '..' ) || false !== strpos( $relative, "\0" ) ) {
			return '';
		}

		// A dot in a directory name is fine; a path segment that is only dots is
		// not, and neither is a scheme or a stream wrapper.
		if ( preg_match( '#^[a-z]+://#i', $relative ) ) {
			return '';
		}

		return $relative;
	}

	/**
	 * Create the store and its server guards.
	 *
	 * @return bool Whether the store is usable.
	 */
	public static function ensure() {
		$root = self::root();

		if ( '' === $root ) {
			return false;
		}

		if ( ! is_dir( $root ) && ! wp_mkdir_p( $root ) ) {
			return false;
		}

		self::write_guards( $root );

		return is_writable( $root ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_is_writable -- a single capability probe, not a filesystem abstraction.
	}

	/**
	 * Write the .htaccess and index.php a public directory needs.
	 *
	 * @param string $root Store directory.
	 * @return void
	 */
	private static function write_guards( $root ) {
		$htaccess = $root . '/.htaccess';

		if ( ! file_exists( $htaccess ) ) {
			$rules = implode(
				"\n",
				array(
					'# Generated by Majestic Turbo. Nothing here is executed, and the',
					'# stored pages are for the plugin to read, never for a visitor.',
					'<IfModule mod_authz_core.c>',
					"\t<FilesMatch \"\\.(?:html|json|gz)$\">",
					"\t\tRequire all denied",
					"\t</FilesMatch>",
					'</IfModule>',
					'<IfModule !mod_authz_core.c>',
					"\t<FilesMatch \"\\.(?:html|json|gz)$\">",
					"\t\tOrder allow,deny",
					"\t\tDeny from all",
					"\t</FilesMatch>",
					'</IfModule>',
					'<IfModule mod_expires.c>',
					"\tExpiresActive On",
					"\tExpiresByType text/css \"access plus 1 year\"",
					"\tExpiresByType application/javascript \"access plus 1 year\"",
					'</IfModule>',
					'<IfModule mod_deflate.c>',
					"\tAddOutputFilterByType DEFLATE text/css application/javascript",
					'</IfModule>',
					'',
				)
			);

			// Written directly rather than through put(): put() calls this method
			// to guard a new directory, and a guard that was written through it
			// would ask for another guard for ever.
			file_put_contents( $htaccess, $rules, LOCK_EX ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- one small server rule file this class owns.
		}

		$index = $root . '/index.php';

		if ( ! file_exists( $index ) ) {
			file_put_contents( $index, "<?php\n// Silence is golden.\n", LOCK_EX ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- one small guard file this class owns.
		}
	}

	/**
	 * Read a stored file when it is younger than a lifetime.
	 *
	 * @param string $relative Path inside the store.
	 * @param int    $max_age  Maximum age in seconds; 0 means any age.
	 * @return string|false File contents, or false when missing or stale.
	 */
	public static function get( $relative, $max_age = 0 ) {
		$path = self::path( $relative );

		if ( '' === $path || ! is_file( $path ) ) {
			return false;
		}

		if ( $max_age > 0 ) {
			$modified = (int) filemtime( $path );

			if ( $modified > 0 && ( time() - $modified ) > $max_age ) {
				return false;
			}
		}

		$contents = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- a generated file this class wrote.

		return false === $contents ? false : $contents;
	}

	/**
	 * Write a file atomically.
	 *
	 * A reader either sees the previous file or the new one, never a half
	 * written stylesheet: the contents go to a temporary name in the same
	 * directory and are renamed into place, which is atomic on every
	 * filesystem this ships to. Two requests racing to build the same bundle
	 * therefore cost one wasted write and cannot corrupt the live file.
	 *
	 * @param string $relative Path inside the store.
	 * @param string $contents File contents.
	 * @return bool
	 */
	public static function put( $relative, $contents ) {
		$relative = self::clean( $relative );
		$root     = self::root();

		if ( '' === $relative || '' === $root || '' === (string) $contents ) {
			return false;
		}

		$path = $root . '/' . $relative;
		$dir  = dirname( $path );

		if ( ! is_dir( $dir ) && ! wp_mkdir_p( $dir ) ) {
			return false;
		}

		self::write_guards( $root );

		$temporary = $path . '.' . wp_generate_password( 8, false, false ) . '.tmp';

		$written = file_put_contents( $temporary, $contents, LOCK_EX ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- an atomic write to a generated file.

		if ( false === $written || strlen( (string) $contents ) !== $written ) {
			@unlink( $temporary ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- a failed cleanup must not mask the write failure.

			return false;
		}

		if ( ! @rename( $temporary, $path ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- reported by the return value.
			@unlink( $temporary ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- a failed cleanup must not mask the write failure.

			return false;
		}

		return true;
	}

	/**
	 * Write a file only while it is missing.
	 *
	 * @param string $relative Path inside the store.
	 * @param string $contents File contents.
	 * @return bool Whether the file exists afterwards.
	 */
	public static function put_once( $relative, $contents ) {
		$path = self::path( $relative );

		if ( '' !== $path && is_file( $path ) ) {
			return true;
		}

		return self::put( $relative, $contents );
	}

	/**
	 * Delete one file.
	 *
	 * @param string $relative Path inside the store.
	 * @return bool
	 */
	public static function delete( $relative ) {
		$path = self::path( $relative );

		if ( '' === $path || ! is_file( $path ) ) {
			return false;
		}

		return (bool) @unlink( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- the return value is the answer.
	}

	/**
	 * Age of a stored file, in seconds.
	 *
	 * @param string $relative Path inside the store.
	 * @return int
	 */
	public static function age( $relative ) {
		$path = self::path( $relative );

		if ( '' === $path || ! is_file( $path ) ) {
			return -1;
		}

		return max( 0, time() - (int) filemtime( $path ) );
	}

	/**
	 * Total bytes used by a subdirectory.
	 *
	 * @param string $subdirectory Directory inside the store.
	 * @return int
	 */
	public static function bytes( $subdirectory = '' ) {
		$path = ( '' === (string) $subdirectory ) ? self::root() : self::path( $subdirectory );

		if ( '' === $path || ! is_dir( $path ) ) {
			return 0;
		}

		$total = 0;

		try {
			$files = new RecursiveIteratorIterator(
				new RecursiveDirectoryIterator( $path, FilesystemIterator::SKIP_DOTS )
			);
		} catch ( UnexpectedValueException $exception ) {
			return 0;
		}

		foreach ( $files as $file ) {
			if ( $file->isFile() ) {
				$total += (int) $file->getSize();
			}
		}

		return $total;
	}

	/**
	 * Remove the oldest generated files in a directory once there are too many.
	 *
	 * A content-addressed filename is never overwritten, so every code or
	 * setting change leaves the previous bundle behind. Keeping the newest few
	 * means a rollback still finds its file while a year of edits does not fill
	 * the uploads directory.
	 *
	 * Two rules keep the count from removing a file that is in use. `$preserve`
	 * names the files the caller just generated, which is the whole set in the
	 * minify-only mode: every stylesheet a page loads is a separate file there,
	 * so a count of six would delete the seventh handle's sheet while the page
	 * still names it. And a file younger than `$grace` is never removed, in
	 * case a request that is building the same page in parallel wrote it a
	 * moment ago.
	 *
	 * @param string   $subdirectory Directory inside the store.
	 * @param int      $keep         Files to keep beyond the preserved ones.
	 * @param string[] $preserve     Basenames that are in use and must survive.
	 * @param int      $grace        Seconds a file is left alone whatever its age.
	 * @return int Files removed.
	 */
	public static function prune( $subdirectory, $keep = 12, $preserve = array(), $grace = DAY_IN_SECONDS ) {
		$directory = self::path( $subdirectory );

		if ( '' === $directory || ! is_dir( $directory ) ) {
			return 0;
		}

		$preserve = array_map( 'basename', array_map( 'strval', (array) $preserve ) );

		$files = glob( $directory . '/*' );
		$files = array_filter(
			(array) $files,
			function ( $file ) use ( $preserve ) {
				$name = basename( $file );

				return is_file( $file ) && '.htaccess' !== $name && 'index.php' !== $name && ! in_array( $name, $preserve, true );
			}
		);

		$keep = max( 0, (int) $keep );

		if ( count( $files ) <= $keep ) {
			return 0;
		}

		usort(
			$files,
			function ( $first, $second ) {
				return filemtime( $second ) - filemtime( $first );
			}
		);

		$now     = time();
		$removed = 0;

		foreach ( array_slice( $files, $keep ) as $file ) {
			$modified = (int) @filemtime( $file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- a file removed by a parallel purge is not an error.

			if ( $modified > 0 && ( $now - $modified ) < $grace ) {
				continue;
			}

			if ( @unlink( $file ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_unlink -- best effort on a superseded bundle.
				++$removed;
			}
		}

		return $removed;
	}

	/**
	 * Delete generated files.
	 *
	 * @param string $subdirectory Directory inside the store, or empty for all.
	 * @return int Files removed.
	 */
	public static function purge( $subdirectory = '' ) {
		// An empty subdirectory means the whole store, which clean() maps to
		// nothing on purpose: there is no relative path that names the root.
		$path = ( '' === (string) $subdirectory ) ? self::root() : self::path( $subdirectory );

		if ( '' === $path || ! is_dir( $path ) ) {
			return 0;
		}

		$removed = 0;

		try {
			$files = new RecursiveIteratorIterator(
				new RecursiveDirectoryIterator( $path, FilesystemIterator::SKIP_DOTS ),
				RecursiveIteratorIterator::CHILD_FIRST
			);
		} catch ( UnexpectedValueException $exception ) {
			// A directory the process cannot read is not a directory this
			// routine can empty; the caller gets zero rather than a fatal.
			return 0;
		}

		foreach ( $files as $file ) {
			// The guards are part of the store, not output: removing them would
			// leave stored pages readable at a public URL until the next build.
			if ( '.htaccess' === $file->getFilename() || 'index.php' === $file->getFilename() ) {
				continue;
			}

			if ( $file->isDir() ) {
				@rmdir( $file->getPathname() ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- best effort on an empty directory.
				continue;
			}

			if ( @unlink( $file->getPathname() ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_unlink -- best effort on a generated file.
				++$removed;
			}
		}

		return $removed;
	}
}
