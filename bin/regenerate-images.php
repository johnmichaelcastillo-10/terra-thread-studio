<?php
/**
 * Rebuilds every image attachment's resized copies with the current settings (sizes and the
 * WebP output format set in the core plugin), then deletes the copies the new metadata no
 * longer lists. Originals are untouched.
 *
 *   docker compose exec -u www-data web php bin/regenerate-images.php
 *   (or php bin/regenerate-images.php on the host setup)
 */

if ( 'cli' !== PHP_SAPI ) {
	exit( 1 );
}

$_SERVER['HTTP_HOST']   = 'localhost';
$_SERVER['REQUEST_URI'] = '/';

require dirname( __DIR__ ) . '/public/wordpress/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/image.php';

$ids = get_posts(
	array(
		'post_type'      => 'attachment',
		'post_mime_type' => 'image',
		'post_status'    => 'inherit',
		'numberposts'    => -1,
		'fields'         => 'ids',
	)
);

$saved = 0;
foreach ( $ids as $id ) {
	$file = get_attached_file( $id );
	if ( ! $file || ! file_exists( $file ) ) {
		echo "    skipped $id: original missing\n";
		continue;
	}
	$dir    = dirname( $file );
	$before = wp_get_attachment_metadata( $id );
	$old    = is_array( $before['sizes'] ?? null ) ? wp_list_pluck( $before['sizes'], 'file' ) : array();
	$bytes  = array_sum( array_map( static fn( $f ) => is_file( "$dir/$f" ) ? filesize( "$dir/$f" ) : 0, $old ) );

	$meta = wp_generate_attachment_metadata( $id, $file );
	if ( empty( $meta['sizes'] ) ) {
		echo "    skipped $id: no sizes generated\n";
		continue;
	}
	wp_update_attachment_metadata( $id, $meta );

	$new = wp_list_pluck( $meta['sizes'], 'file' );
	foreach ( array_diff( $old, $new ) as $stale ) {
		wp_delete_file( "$dir/$stale" );
	}
	$after  = array_sum( array_map( static fn( $f ) => filesize( "$dir/$f" ), $new ) );
	$saved += $bytes - $after;
	printf( "    %-34s %5d KB -> %5d KB\n", basename( $file ), $bytes / 1024, $after / 1024 );
}
printf( "    %d images, %d KB smaller in total\n", count( $ids ), $saved / 1024 );
