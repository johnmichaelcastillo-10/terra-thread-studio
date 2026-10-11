<?php
/**
 * Terra & Thread Studio — WordPress configuration.
 *
 * WordPress core lives in /public/wordpress (downloaded by bin/setup) and is
 * never edited. Our code lives in /public/wp-content. The database is a single
 * SQLite file stored outside the web root in /database.
 */

$tt_root = dirname( __DIR__ );

/* ── Database: SQLite via the SQLite Database Integration drop-in ───────── */
define( 'DB_ENGINE', 'sqlite' );
define( 'DB_DIR', $tt_root . '/database/' );
define( 'DB_FILE', 'terra-thread.sqlite' );

// Not used by SQLite, but WordPress expects them to exist.
define( 'DB_NAME', 'terra_thread' );
define( 'DB_USER', '' );
define( 'DB_PASSWORD', '' );
define( 'DB_HOST', 'localhost' );
define( 'DB_CHARSET', 'utf8mb4' );
define( 'DB_COLLATE', '' );

$table_prefix = 'wp_';

/* ── URLs: core in a subdirectory, site served from the web root ────────── */
if ( ! empty( $_SERVER['HTTP_HOST'] ) ) {
	$tt_scheme = ( ! empty( $_SERVER['HTTPS'] ) && 'off' !== $_SERVER['HTTPS'] ) ? 'https' : 'http';
	$tt_url    = $tt_scheme . '://' . $_SERVER['HTTP_HOST'];
} else {
	$tt_url = getenv( 'TT_URL' ) ?: 'http://localhost:8090';
}

define( 'WP_HOME', $tt_url );
define( 'WP_SITEURL', $tt_url . '/wordpress' );
define( 'WP_CONTENT_DIR', __DIR__ . '/wp-content' );
define( 'WP_CONTENT_URL', $tt_url . '/wp-content' );

/* ── Keys & salts: generated per checkout by bin/install.php ────────────── */
if ( file_exists( $tt_root . '/config/salts.php' ) ) {
	require $tt_root . '/config/salts.php';
}

/* ── Environment ────────────────────────────────────────────────────────── */
define( 'WP_ENVIRONMENT_TYPE', getenv( 'WP_ENVIRONMENT_TYPE' ) ?: 'local' );
define( 'WP_DEBUG', 'production' !== WP_ENVIRONMENT_TYPE );
define( 'WP_DEBUG_LOG', $tt_root . '/logs/debug.log' );
define( 'WP_DEBUG_DISPLAY', false );
define( 'SCRIPT_DEBUG', false );
define( 'DISALLOW_FILE_EDIT', true );
define( 'AUTOMATIC_UPDATER_DISABLED', true );
define( 'WP_POST_REVISIONS', 10 );

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/wordpress/' );
}

require_once ABSPATH . 'wp-settings.php';
