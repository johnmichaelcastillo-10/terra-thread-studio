<?php
/**
 * Plugin Name:       Terra & Thread Core
 * Description:       Content model, pricing engine, workshop bookings and REST API for Terra & Thread Studio.
 * Version:           1.0.0
 * Requires at least: 6.5
 * Requires PHP:      8.1
 * Author:            Terra & Thread Studio
 * Text Domain:       terra-thread
 */

namespace TerraThread\Core;

defined( 'ABSPATH' ) || exit;

const VERSION = '1.0.0';

require_once __DIR__ . '/includes/content-model.php';
require_once __DIR__ . '/includes/schema.php';
require_once __DIR__ . '/includes/catalog.php';
require_once __DIR__ . '/includes/bookings.php';
require_once __DIR__ . '/includes/rest.php';
require_once __DIR__ . '/includes/seeder.php';

if ( is_admin() ) {
	require_once __DIR__ . '/includes/admin.php';
}

add_action( 'init', __NAMESPACE__ . '\register_content_model' );
add_action( 'plugins_loaded', __NAMESPACE__ . '\Schema\maybe_upgrade' );
add_action( 'rest_api_init', __NAMESPACE__ . '\Rest\register_routes' );

// The users sitemap lists author archives, which would publish the admin username.
add_filter( 'wp_sitemaps_add_provider', fn( $provider, $name ) => 'users' === $name ? false : $provider, 10, 2 );

register_activation_hook(
	__FILE__,
	function () {
		register_content_model();
		Schema\install();
		flush_rewrite_rules( false );
	}
);
