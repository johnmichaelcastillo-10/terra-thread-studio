<?php
/**
 * Moves workshop sessions forward by whole weeks when the next one is too close
 * or already past, so a published export always shows upcoming dates.
 * Run by the publish scripts before every export: php bin/roll-dates.php
 */

if ( 'cli' !== PHP_SAPI ) {
	exit( 1 );
}

$_SERVER['HTTP_HOST']   = 'localhost';
$_SERVER['REQUEST_URI'] = '/';

require dirname( __DIR__ ) . '/public/wordpress/wp-load.php';

$moved = \TerraThread\Core\Seeder\roll_forward();
echo $moved ? "    moved $moved workshop sessions forward\n" : "    workshop dates are current\n";
