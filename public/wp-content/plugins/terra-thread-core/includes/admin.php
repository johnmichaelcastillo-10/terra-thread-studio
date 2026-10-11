<?php
/**
 * Admin: meta boxes for products and workshops, session management and a
 * read-only reservations screen.
 */

namespace TerraThread\Core\Admin;

use TerraThread\Core;
use TerraThread\Core\Bookings;
use TerraThread\Core\Catalog;
use TerraThread\Core\Schema;

defined( 'ABSPATH' ) || exit;

const NONCE = 'tt_meta_nonce';

add_action( 'add_meta_boxes', __NAMESPACE__ . '\add_boxes' );
add_action( 'save_post_' . Core\PRODUCT, __NAMESPACE__ . '\save_product' );
add_action( 'save_post_' . Core\WORKSHOP, __NAMESPACE__ . '\save_workshop' );
add_action( 'admin_menu', __NAMESPACE__ . '\add_bookings_page' );
add_filter( 'manage_' . Core\PRODUCT . '_posts_columns', __NAMESPACE__ . '\product_columns' );
add_action( 'manage_' . Core\PRODUCT . '_posts_custom_column', __NAMESPACE__ . '\product_column', 10, 2 );

const ART = array( 'vase', 'journal', 'bowl', 'throw', 'board', 'candle' );

function add_boxes(): void {
	add_meta_box( 'tt_product', __( 'Pricing, stock & options', 'terra-thread' ), __NAMESPACE__ . '\product_box', Core\PRODUCT, 'normal', 'high' );
	add_meta_box( 'tt_workshop', __( 'Workshop details', 'terra-thread' ), __NAMESPACE__ . '\workshop_box', Core\WORKSHOP, 'normal', 'high' );
	add_meta_box( 'tt_sessions', __( 'Sessions & seats', 'terra-thread' ), __NAMESPACE__ . '\sessions_box', Core\WORKSHOP, 'normal', 'default' );
}

function field( string $name, string $label, $value, string $type = 'text', array $attrs = array() ): void {
	$extra = '';
	foreach ( $attrs as $k => $v ) {
		$extra .= sprintf( ' %s="%s"', esc_attr( $k ), esc_attr( $v ) );
	}
	printf(
		'<p><label for="%1$s"><strong>%2$s</strong></label><br><input class="widefat" type="%3$s" id="%1$s" name="%1$s" value="%4$s"%5$s></p>',
		esc_attr( $name ),
		esc_html( $label ),
		esc_attr( $type ),
		esc_attr( (string) $value ),
		$extra // phpcs:ignore WordPress.Security.EscapeOutput -- escaped above.
	);
}

function art_select( int $post_id ): void {
	$current = get_post_meta( $post_id, 'tt_art', true );
	echo '<p><label for="tt_art"><strong>' . esc_html__( 'Illustration', 'terra-thread' ) . '</strong></label><br><select id="tt_art" name="tt_art">';
	foreach ( ART as $art ) {
		printf( '<option value="%1$s" %2$s>%1$s</option>', esc_attr( $art ), selected( $current, $art, false ) );
	}
	echo '</select></p>';
}

function product_box( \WP_Post $post ): void {
	wp_nonce_field( 'tt_save', NONCE );
	$m = fn( $k ) => get_post_meta( $post->ID, $k, true );

	echo '<div style="display:grid;grid-template-columns:repeat(3,1fr);gap:0 16px">';
	field( 'tt_price', __( 'Base price ($)', 'terra-thread' ), $m( 'tt_price' ), 'number', array( 'step' => '0.01', 'min' => '0' ) );
	field( 'tt_stock', __( 'Stock', 'terra-thread' ), $m( 'tt_stock' ), 'number', array( 'min' => '0' ) );
	field( 'tt_sku', __( 'SKU', 'terra-thread' ), $m( 'tt_sku' ) );
	field( 'tt_lead_time', __( 'Lead time', 'terra-thread' ), $m( 'tt_lead_time' ) );
	field( 'tt_dimensions', __( 'Dimensions', 'terra-thread' ), $m( 'tt_dimensions' ) );
	field( 'tt_care', __( 'Care', 'terra-thread' ), $m( 'tt_care' ) );
	echo '</div>';
	art_select( $post->ID );

	$json = wp_json_encode( Catalog\options( $post->ID ), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
	printf(
		'<p><label for="tt_options"><strong>%s</strong></label><br><textarea id="tt_options" name="tt_options" rows="14" class="widefat code">%s</textarea><span class="description">%s</span></p>',
		esc_html__( 'Custom options (JSON)', 'terra-thread' ),
		esc_textarea( $json ),
		esc_html__( 'Types: swatch, select (choices with price/color), text (price, price_per_char, maxlength), checkbox (price). Invalid entries are dropped on save.', 'terra-thread' )
	);
}

function workshop_box( \WP_Post $post ): void {
	wp_nonce_field( 'tt_save', NONCE );
	$m = fn( $k ) => get_post_meta( $post->ID, $k, true );

	echo '<div style="display:grid;grid-template-columns:repeat(3,1fr);gap:0 16px">';
	field( 'tt_instructor', __( 'Instructor', 'terra-thread' ), $m( 'tt_instructor' ) );
	field( 'tt_price', __( 'Price per seat ($)', 'terra-thread' ), $m( 'tt_price' ), 'number', array( 'step' => '0.01', 'min' => '0' ) );
	field( 'tt_capacity', __( 'Default seats per session', 'terra-thread' ), $m( 'tt_capacity' ), 'number', array( 'min' => '1' ) );
	field( 'tt_duration', __( 'Duration (minutes)', 'terra-thread' ), $m( 'tt_duration' ), 'number', array( 'min' => '30', 'step' => '15' ) );
	field( 'tt_level', __( 'Level', 'terra-thread' ), $m( 'tt_level' ) );
	field( 'tt_location', __( 'Room', 'terra-thread' ), $m( 'tt_location' ) );
	echo '</div>';
	field( 'tt_instructor_bio', __( 'Instructor bio', 'terra-thread' ), $m( 'tt_instructor_bio' ) );
	field( 'tt_includes', __( "What's included", 'terra-thread' ), $m( 'tt_includes' ) );
	art_select( $post->ID );
}

function sessions_box( \WP_Post $post ): void {
	$sessions = Bookings\sessions_for( $post->ID, false );

	if ( $sessions ) {
		echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Date', 'terra-thread' ) . '</th><th>' . esc_html__( 'Time', 'terra-thread' ) . '</th><th>' . esc_html__( 'Booked', 'terra-thread' ) . '</th><th>' . esc_html__( 'Cancel', 'terra-thread' ) . '</th></tr></thead><tbody>';
		foreach ( $sessions as $s ) {
			printf(
				'<tr><td>%s</td><td>%s</td><td>%d / %d</td><td><label><input type="checkbox" name="tt_cancel_sessions[]" value="%d"> %s</label></td></tr>',
				esc_html( $s['label_date'] ),
				esc_html( $s['label_time'] ),
				(int) ( $s['capacity'] - $s['seats_left'] ),
				(int) $s['capacity'],
				(int) $s['id'],
				esc_html__( 'cancel', 'terra-thread' )
			);
		}
		echo '</tbody></table>';
	} else {
		echo '<p>' . esc_html__( 'No sessions scheduled yet.', 'terra-thread' ) . '</p>';
	}

	field( 'tt_new_session', __( 'Add a session', 'terra-thread' ), '', 'datetime-local' );
}

function can_save( int $post_id ): bool {
	return isset( $_POST[ NONCE ] )
		&& wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST[ NONCE ] ) ), 'tt_save' )
		&& ! wp_is_post_autosave( $post_id )
		&& current_user_can( 'edit_post', $post_id );
}

function save_fields( int $post_id, array $keys ): void {
	foreach ( $keys as $key ) {
		if ( isset( $_POST[ $key ] ) ) {
			// $_POST is slashed and update_post_meta() unslashes; registered sanitize callbacks run inside it.
			update_post_meta( $post_id, $key, $_POST[ $key ] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		}
	}
}

function save_product( int $post_id ): void {
	if ( can_save( $post_id ) ) {
		save_fields( $post_id, array( 'tt_price', 'tt_stock', 'tt_sku', 'tt_lead_time', 'tt_dimensions', 'tt_care', 'tt_art', 'tt_options' ) );
	}
}

function save_workshop( int $post_id ): void {
	global $wpdb;
	if ( ! can_save( $post_id ) ) {
		return;
	}

	save_fields( $post_id, array( 'tt_instructor', 'tt_instructor_bio', 'tt_price', 'tt_capacity', 'tt_duration', 'tt_level', 'tt_location', 'tt_includes', 'tt_art' ) );

	$new = sanitize_text_field( wp_unslash( $_POST['tt_new_session'] ?? '' ) );
	if ( $new && strtotime( $new ) ) {
		Bookings\add_session( $post_id, str_replace( 'T', ' ', $new ) . ':00' );
	}

	foreach ( array_map( 'absint', (array) ( $_POST['tt_cancel_sessions'] ?? array() ) ) as $session_id ) {
		$wpdb->update( Schema\sessions_table(), array( 'status' => 'cancelled' ), array( 'id' => $session_id, 'workshop_id' => $post_id ) );
	}
}

function product_columns( array $columns ): array {
	return array_slice( $columns, 0, 2 ) + array( 'tt_price' => __( 'Price', 'terra-thread' ), 'tt_stock' => __( 'Stock', 'terra-thread' ) ) + $columns;
}

function product_column( string $column, int $post_id ): void {
	if ( 'tt_price' === $column ) {
		echo esc_html( Catalog\format_money( (float) get_post_meta( $post_id, 'tt_price', true ) ) );
	} elseif ( 'tt_stock' === $column ) {
		echo (int) get_post_meta( $post_id, 'tt_stock', true );
	}
}

function add_bookings_page(): void {
	add_submenu_page( 'edit.php?post_type=' . Core\WORKSHOP, __( 'Reservations', 'terra-thread' ), __( 'Reservations', 'terra-thread' ), 'edit_posts', 'tt-reservations', __NAMESPACE__ . '\render_bookings_page' );
}

function render_bookings_page(): void {
	$rows = Bookings\recent_bookings();

	echo '<div class="wrap"><h1>' . esc_html__( 'Reservations', 'terra-thread' ) . '</h1>';
	echo '<table class="widefat striped"><thead><tr>';
	foreach ( array( 'Reference', 'Guest', 'Email', 'Workshop', 'Session', 'Seats', 'Booked at' ) as $h ) {
		echo '<th>' . esc_html( $h ) . '</th>';
	}
	echo '</tr></thead><tbody>';

	if ( ! $rows ) {
		echo '<tr><td colspan="7">' . esc_html__( 'No reservations yet.', 'terra-thread' ) . '</td></tr>';
	}

	foreach ( $rows as $r ) {
		printf(
			'<tr><td><code>%s</code></td><td>%s</td><td>%s</td><td><a href="%s">%s</a></td><td>%s</td><td>%d</td><td>%s</td></tr>',
			esc_html( $r['reference'] ),
			esc_html( $r['name'] ),
			esc_html( $r['email'] ),
			esc_url( get_edit_post_link( (int) $r['workshop_id'] ) ),
			esc_html( get_the_title( (int) $r['workshop_id'] ) ),
			esc_html( mysql2date( 'M j, g:i a', $r['starts_at'] ) ),
			(int) $r['seats'],
			esc_html( mysql2date( 'M j, Y g:i a', $r['created_at'] ) )
		);
	}

	echo '</tbody></table></div>';
}
