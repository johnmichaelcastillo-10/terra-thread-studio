<?php
/**
 * Public REST API (namespace tt/v1).
 *
 *   GET  /workshops/{id}/sessions   live seat availability
 *   POST /bookings                  reserve seats
 *   POST /products/{id}/quote       authoritative price for a configuration
 */

namespace TerraThread\Core\Rest;

use TerraThread\Core\Bookings;
use TerraThread\Core\Catalog;
use WP_Error;
use WP_REST_Request;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

const NS = 'tt/v1';

function register_routes(): void {
	register_rest_route(
		NS,
		'/workshops/(?P<id>\d+)/sessions',
		array(
			'methods'             => WP_REST_Server::READABLE,
			'permission_callback' => '__return_true',
			'callback'            => __NAMESPACE__ . '\get_sessions',
			'args'                => array( 'id' => array( 'type' => 'integer', 'required' => true ) ),
		)
	);

	register_rest_route(
		NS,
		'/bookings',
		array(
			'methods'             => WP_REST_Server::CREATABLE,
			'permission_callback' => '__return_true',
			'callback'            => __NAMESPACE__ . '\create_booking',
			'args'                => array(
				'session_id' => array( 'type' => 'integer', 'required' => true, 'minimum' => 1 ),
				'seats'      => array( 'type' => 'integer', 'required' => true, 'minimum' => 1, 'maximum' => Bookings\MAX_SEATS_PER_BOOKING ),
				'name'       => array( 'type' => 'string', 'required' => true, 'minLength' => 2, 'maxLength' => 120, 'sanitize_callback' => 'sanitize_text_field' ),
				'email'      => array( 'type' => 'string', 'required' => true, 'format' => 'email', 'sanitize_callback' => 'sanitize_email' ),
				'website'    => array( 'type' => 'string', 'default' => '' ), // honeypot
			),
		)
	);

	register_rest_route(
		NS,
		'/products/(?P<id>\d+)/quote',
		array(
			'methods'             => WP_REST_Server::CREATABLE,
			'permission_callback' => '__return_true',
			'callback'            => __NAMESPACE__ . '\quote_product',
			'args'                => array(
				'id'       => array( 'type' => 'integer', 'required' => true ),
				'options'  => array( 'type' => 'object', 'default' => array() ),
				'quantity' => array( 'type' => 'integer', 'default' => 1, 'minimum' => 1, 'maximum' => Catalog\MAX_QUANTITY ),
			),
		)
	);
}

function get_sessions( WP_REST_Request $request ) {
	$workshop = Bookings\workshop( (int) $request['id'] );
	if ( ! $workshop || 'publish' !== get_post_status( $workshop['id'] ) ) {
		return new WP_Error( 'tt_not_found', __( 'Workshop not found.', 'terra-thread' ), array( 'status' => 404 ) );
	}

	$response = rest_ensure_response(
		array(
			'workshop' => array_intersect_key( $workshop, array_flip( array( 'id', 'title', 'price', 'duration', 'instructor', 'location', 'level' ) ) ),
			'sessions' => $workshop['sessions'],
			'max_seats_per_booking' => Bookings\MAX_SEATS_PER_BOOKING,
		)
	);
	$response->header( 'Cache-Control', 'no-store' );
	return $response;
}

function create_booking( WP_REST_Request $request ) {
	if ( '' !== trim( (string) $request['website'] ) ) {
		return new WP_Error( 'tt_rejected', __( 'Booking rejected.', 'terra-thread' ), array( 'status' => 400 ) );
	}

	// Sanitisers run before schema validation, so re-check the cleaned values.
	if ( ! is_email( $request['email'] ) ) {
		return new WP_Error( 'tt_email', __( 'Please enter a valid email address.', 'terra-thread' ), array( 'status' => 400, 'field' => 'email' ) );
	}
	if ( mb_strlen( trim( $request['name'] ) ) < 2 ) {
		return new WP_Error( 'tt_name', __( 'Please tell us your name.', 'terra-thread' ), array( 'status' => 400, 'field' => 'name' ) );
	}

	if ( ! within_rate_limit( 'booking', 8, 10 * MINUTE_IN_SECONDS ) ) {
		return new WP_Error( 'tt_rate_limited', __( 'Too many reservations from this connection. Please wait a few minutes.', 'terra-thread' ), array( 'status' => 429 ) );
	}

	$result = Bookings\create_booking( (int) $request['session_id'], (int) $request['seats'], $request['name'], $request['email'] );

	return is_wp_error( $result ) ? $result : new \WP_REST_Response( $result, 201 );
}

function quote_product( WP_REST_Request $request ) {
	$product = Catalog\product( (int) $request['id'] );
	if ( ! $product || 'publish' !== get_post_status( $product['id'] ) ) {
		return new WP_Error( 'tt_not_found', __( 'Product not found.', 'terra-thread' ), array( 'status' => 404 ) );
	}

	$quote = Catalog\quote( $product, (array) $request['options'], (int) $request['quantity'] );
	if ( is_wp_error( $quote ) ) {
		return $quote;
	}

	return rest_ensure_response(
		$quote + array(
			'product' => array(
				'id'    => $product['id'],
				'title' => $product['title'],
				'url'   => $product['url'],
				'art'   => $product['art'],
				'stock' => $product['stock'],
			),
		)
	);
}

/**
 * Fixed-window limiter keyed on a hash of the client IP.
 */
function within_rate_limit( string $bucket, int $max, int $window ): bool {
	$ip  = $_SERVER['REMOTE_ADDR'] ?? 'unknown'; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
	$key = 'tt_rl_' . $bucket . '_' . md5( $ip );
	$hits = (int) get_transient( $key );

	if ( $hits >= $max ) {
		return false;
	}

	set_transient( $key, $hits + 1, $window );
	return true;
}
