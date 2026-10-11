<?php
/**
 * Workshops, sessions and seat reservations.
 *
 * Session times are stored as site-local wall-clock datetimes (like post_date).
 */

namespace TerraThread\Core\Bookings;

use DateTimeImmutable;
use TerraThread\Core;
use TerraThread\Core\Schema;
use WP_Error;
use WP_Post;

defined( 'ABSPATH' ) || exit;

const MAX_SEATS_PER_BOOKING = 4;

function workshop( int|WP_Post $post ): ?array {
	$post = get_post( $post );
	if ( ! $post || Core\WORKSHOP !== $post->post_type ) {
		return null;
	}

	$sessions = sessions_for( $post->ID );

	return array(
		'id'             => $post->ID,
		'title'          => get_the_title( $post ),
		'url'            => get_permalink( $post ),
		'excerpt'        => get_the_excerpt( $post ),
		'price'          => (float) get_post_meta( $post->ID, 'tt_price', true ),
		'capacity'       => (int) get_post_meta( $post->ID, 'tt_capacity', true ),
		'duration'       => (int) get_post_meta( $post->ID, 'tt_duration', true ),
		'instructor'     => (string) get_post_meta( $post->ID, 'tt_instructor', true ),
		'instructor_bio' => (string) get_post_meta( $post->ID, 'tt_instructor_bio', true ),
		'location'       => (string) get_post_meta( $post->ID, 'tt_location', true ),
		'level'          => (string) get_post_meta( $post->ID, 'tt_level', true ),
		'art'            => (string) get_post_meta( $post->ID, 'tt_art', true ),
		'image_id'       => (int) get_post_thumbnail_id( $post ),
		'includes'       => (string) get_post_meta( $post->ID, 'tt_includes', true ),
		'sessions'       => $sessions,
		'next'           => $sessions[0] ?? null,
	);
}

/**
 * Upcoming workshops ordered by their next session.
 */
function upcoming_workshops( int $limit = -1 ): array {
	$posts = get_posts(
		array(
			'post_type'   => Core\WORKSHOP,
			'post_status' => 'publish',
			'numberposts' => -1,
		)
	);

	$workshops = array_filter( array_map( __NAMESPACE__ . '\workshop', $posts ), fn( $w ) => $w && $w['next'] );
	usort( $workshops, fn( $a, $b ) => strcmp( $a['next']['starts_at'], $b['next']['starts_at'] ) );

	return $limit > 0 ? array_slice( $workshops, 0, $limit ) : array_values( $workshops );
}

/**
 * Sessions with live seat counts.
 */
function sessions_for( int $workshop_id, bool $upcoming_only = true ): array {
	global $wpdb;
	$sessions = Schema\sessions_table();
	$bookings = Schema\bookings_table();

	$where = $upcoming_only ? $wpdb->prepare( 'AND s.starts_at >= %s', current_time( 'mysql' ) ) : '';

	// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table names are internal.
	$rows = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT s.id, s.workshop_id, s.starts_at, s.ends_at, s.capacity, s.status,
				COALESCE(SUM(b.seats), 0) AS booked
			FROM $sessions s
			LEFT JOIN $bookings b ON b.session_id = s.id AND b.status = 'confirmed'
			WHERE s.workshop_id = %d AND s.status = 'scheduled' $where
			GROUP BY s.id, s.workshop_id, s.starts_at, s.ends_at, s.capacity, s.status
			ORDER BY s.starts_at ASC",
			$workshop_id
		),
		ARRAY_A
	);

	return array_map( __NAMESPACE__ . '\format_session', $rows ?: array() );
}

function format_session( array $row ): array {
	$tz    = wp_timezone();
	$start = new DateTimeImmutable( $row['starts_at'], $tz );
	$end   = new DateTimeImmutable( $row['ends_at'], $tz );
	$left  = max( 0, (int) $row['capacity'] - (int) $row['booked'] );

	return array(
		'id'         => (int) $row['id'],
		'workshop'   => (int) $row['workshop_id'],
		'starts_at'  => $row['starts_at'],
		'date'       => $start->format( 'Y-m-d' ),
		'iso'        => $start->format( DATE_ATOM ),
		'label_date' => wp_date( 'l, F j', $start->getTimestamp() ),
		'label_time' => wp_date( 'g:i a', $start->getTimestamp() ) . ' – ' . wp_date( 'g:i a', $end->getTimestamp() ),
		'capacity'   => (int) $row['capacity'],
		'seats_left' => $left,
		'sold_out'   => 0 === $left,
	);
}

function add_session( int $workshop_id, string $starts_at, ?int $capacity = null ): int {
	global $wpdb;

	$duration = max( 30, (int) get_post_meta( $workshop_id, 'tt_duration', true ) );
	$start    = new DateTimeImmutable( $starts_at, wp_timezone() );

	$wpdb->insert(
		Schema\sessions_table(),
		array(
			'workshop_id' => $workshop_id,
			'starts_at'   => $start->format( 'Y-m-d H:i:s' ),
			'ends_at'     => $start->modify( "+{$duration} minutes" )->format( 'Y-m-d H:i:s' ),
			'capacity'    => $capacity ?? (int) get_post_meta( $workshop_id, 'tt_capacity', true ),
			'status'      => 'scheduled',
		),
		array( '%d', '%s', '%s', '%d', '%s' )
	);

	return (int) $wpdb->insert_id;
}

/**
 * Reserve seats. The capacity check and insert run inside one transaction so
 * concurrent requests cannot oversell a session (SQLite serialises writers).
 *
 * @return array{reference: string, seats: int, session: array}|WP_Error
 */
function create_booking( int $session_id, int $seats, string $name, string $email ): array|WP_Error {
	global $wpdb;

	if ( $seats < 1 || $seats > MAX_SEATS_PER_BOOKING ) {
		return new WP_Error( 'tt_seats', sprintf( __( 'You can reserve 1–%d seats at a time.', 'terra-thread' ), MAX_SEATS_PER_BOOKING ), array( 'status' => 400 ) );
	}

	$sessions = Schema\sessions_table();
	$bookings = Schema\bookings_table();

	$wpdb->query( 'START TRANSACTION' );

	// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$row = $wpdb->get_row(
		$wpdb->prepare(
			"SELECT s.id, s.workshop_id, s.starts_at, s.ends_at, s.capacity, s.status,
				COALESCE(SUM(b.seats), 0) AS booked
			FROM $sessions s
			LEFT JOIN $bookings b ON b.session_id = s.id AND b.status = 'confirmed'
			WHERE s.id = %d
			GROUP BY s.id, s.workshop_id, s.starts_at, s.ends_at, s.capacity, s.status",
			$session_id
		),
		ARRAY_A
	);

	if ( ! $row || 'scheduled' !== $row['status'] || $row['starts_at'] < current_time( 'mysql' ) ) {
		$wpdb->query( 'ROLLBACK' );
		return new WP_Error( 'tt_session', __( 'That session is no longer available.', 'terra-thread' ), array( 'status' => 404 ) );
	}

	$left = (int) $row['capacity'] - (int) $row['booked'];
	if ( $seats > $left ) {
		$wpdb->query( 'ROLLBACK' );
		$message = $left > 0
			? sprintf( _n( 'Only %d seat is left in this session.', 'Only %d seats are left in this session.', $left, 'terra-thread' ), $left )
			: __( 'This session just sold out.', 'terra-thread' );
		return new WP_Error( 'tt_capacity', $message, array( 'status' => 409, 'seats_left' => max( 0, $left ) ) );
	}

	$reference = 'TT-' . strtoupper( wp_generate_password( 6, false, false ) );
	$inserted  = $wpdb->insert(
		$bookings,
		array(
			'session_id' => $session_id,
			'reference'  => $reference,
			'name'       => $name,
			'email'      => $email,
			'seats'      => $seats,
			'status'     => 'confirmed',
			'created_at' => current_time( 'mysql' ),
		),
		array( '%d', '%s', '%s', '%s', '%d', '%s', '%s' )
	);

	if ( ! $inserted ) {
		$wpdb->query( 'ROLLBACK' );
		return new WP_Error( 'tt_db', __( 'We could not save your reservation. Please try again.', 'terra-thread' ), array( 'status' => 500 ) );
	}

	$wpdb->query( 'COMMIT' );

	$row['booked'] = (int) $row['booked'] + $seats;
	$session       = format_session( $row );

	send_confirmation( $email, $name, $reference, $seats, (int) $row['workshop_id'], $session );

	return array(
		'reference' => $reference,
		'seats'     => $seats,
		'session'   => $session,
	);
}

function send_confirmation( string $email, string $name, string $reference, int $seats, int $workshop_id, array $session ): void {
	$subject = sprintf( __( 'Your seat at %s — %s', 'terra-thread' ), get_the_title( $workshop_id ), $reference );
	$body    = sprintf(
		"Hi %s,\n\nYou're booked for %s.\n\n%s, %s\nSeats: %d\nReference: %s\n\nSee you in the studio,\nTerra & Thread",
		$name,
		get_the_title( $workshop_id ),
		$session['label_date'],
		$session['label_time'],
		$seats,
		$reference
	);

	// Local environments rarely have mail configured; never fail a booking over it.
	try {
		wp_mail( $email, $subject, $body );
	} catch ( \Throwable $e ) {
		error_log( 'Terra & Thread booking email failed: ' . $e->getMessage() );
	}
}

/**
 * Recent bookings for the admin screen.
 */
function recent_bookings( int $limit = 100 ): array {
	global $wpdb;
	$sessions = Schema\sessions_table();
	$bookings = Schema\bookings_table();

	// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	return $wpdb->get_results(
		$wpdb->prepare(
			"SELECT b.*, s.workshop_id, s.starts_at
			FROM $bookings b
			JOIN $sessions s ON s.id = b.session_id
			ORDER BY b.created_at DESC, b.id DESC
			LIMIT %d",
			$limit
		),
		ARRAY_A
	) ?: array();
}
