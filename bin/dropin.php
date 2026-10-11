<?php
/**
 * Copies the SQLite plugin's db.copy template to wp-content/db.php.
 *
 * The implementation-path placeholder is intentionally left in place: the
 * template falls back to __DIR__ . '/plugins/sqlite-database-integration',
 * which keeps the drop-in portable when the repository is moved or cloned.
 */

$content = dirname( __DIR__ ) . '/public/wp-content';
$source  = $content . '/plugins/sqlite-database-integration/db.copy';
$target  = $content . '/db.php';

if ( ! is_file( $source ) ) {
	fwrite( STDERR, "Missing $source — download the SQLite plugin first.\n" );
	exit( 1 );
}

$dropin = str_replace( '{SQLITE_PLUGIN}', 'sqlite-database-integration/load.php', file_get_contents( $source ) );

if ( is_file( $target ) && file_get_contents( $target ) === $dropin ) {
	echo "    db.php is up to date\n";
	exit( 0 );
}

file_put_contents( $target, $dropin );
echo "    wrote wp-content/db.php\n";
