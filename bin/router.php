<?php
/**
 * Router for PHP's built-in web server:
 *   php -S localhost:8080 -t public bin/router.php
 *
 * Serves real files directly, blocks dotfiles and PHP inside wp-content, and sends
 * everything else to WordPress so pretty permalinks work without Apache/nginx.
 */

$tt_public = realpath( __DIR__ . '/../public' );
$tt_path   = rawurldecode( (string) parse_url( $_SERVER['REQUEST_URI'], PHP_URL_PATH ) );

// Dotfiles, any PHP under wp-content (drop-ins, plugin/theme internals) and the config are never served directly.
if ( preg_match( '#/\.|^/wp-content/.*\.php#i', $tt_path ) || str_ends_with( $tt_path, 'wp-config.php' ) ) {
	http_response_code( 403 );
	exit( 'Forbidden' );
}

$tt_file = $tt_public . $tt_path;

if ( '/' !== $tt_path && file_exists( $tt_file ) ) {
	if ( is_file( $tt_file ) || file_exists( rtrim( $tt_file, '/' ) . '/index.php' ) ) {
		return false; // Let the built-in server handle it (static file, PHP file or directory index).
	}
}

$_SERVER['SCRIPT_NAME']     = '/index.php';
$_SERVER['SCRIPT_FILENAME'] = $tt_public . '/index.php';
$_SERVER['PHP_SELF']        = '/index.php';

chdir( $tt_public );
require $tt_public . '/index.php';
