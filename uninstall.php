<?php
/**
 * Uninstall: remove the options, the scheduled jobs and every generated file.
 *
 * Deactivation already stops the plugin from changing a page; uninstalling is
 * the point at which the administrator has said the site should not carry it
 * any more, so the option rows, the queued warm jobs and the cache directory
 * go too. Nothing else is touched - no post, no user, no other plugin's data.
 *
 * On multisite this runs once for the network, so it walks every site: an
 * option row and an uploads directory are per site, and deleting only the
 * current one would leave the plugin's data behind on every other.
 *
 * @package Majestic Turbo
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

/*
 * The early-cache drop-in belongs to the network's wp-content, not to one
 * site, so it is removed once rather than inside the per-site loop. Only a
 * file this plugin wrote is touched.
 */
if ( defined( 'WP_CONTENT_DIR' ) && is_file( WP_CONTENT_DIR . '/advanced-cache.php' ) ) {
	$majestic_turbo_dropin = file_get_contents( WP_CONTENT_DIR . '/advanced-cache.php' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reading the drop-in to identify it.

	if ( false !== $majestic_turbo_dropin && false !== strpos( $majestic_turbo_dropin, 'Majestic Turbo early cache' ) ) {
		@unlink( WP_CONTENT_DIR . '/advanced-cache.php' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_unlink -- best effort.
	}
}

/**
 * Remove one site's data.
 *
 * @return void
 */
function majestic_turbo_uninstall_site() {
	delete_option( 'majestic_turbo_options' );
	delete_option( 'majestic_turbo_image_status' );

	wp_clear_scheduled_hook( 'majestic_turbo_warm' );

	$uploads = wp_upload_dir( null, false );

	if ( ! empty( $uploads['error'] ) || empty( $uploads['basedir'] ) ) {
		return;
	}

	$root = untrailingslashit( $uploads['basedir'] ) . '/majestic-turbo';

	if ( ! is_dir( $root ) ) {
		return;
	}

	try {
		$files = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS ),
			RecursiveIteratorIterator::CHILD_FIRST
		);
	} catch ( UnexpectedValueException $exception ) {
		// An unreadable directory is not one this routine can empty.
		return;
	}

	foreach ( $files as $file ) {
		if ( $file->isDir() ) {
			@rmdir( $file->getPathname() ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- best effort.
			continue;
		}

		@unlink( $file->getPathname() ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_unlink -- best effort.
	}

	@rmdir( $root ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- best effort.
}

if ( is_multisite() ) {
	$majestic_turbo_sites = get_sites(
		array(
			'fields' => 'ids',
			'number' => 0,
		)
	);

	foreach ( (array) $majestic_turbo_sites as $majestic_turbo_site ) {
		switch_to_blog( (int) $majestic_turbo_site );
		majestic_turbo_uninstall_site();
		restore_current_blog();
	}
} else {
	majestic_turbo_uninstall_site();
}
