<?php
/**
 * Demo catalogue: 6 products, 3 workshops (with dated sessions), a few
 * reservations so availability varies, and the About page.
 *
 * Idempotent: records are matched by slug, and sessions are only created for
 * workshops that have none. Session dates are relative to install time so the
 * schedule is always upcoming.
 */

namespace TerraThread\Core\Seeder;

use DateTimeImmutable;
use TerraThread\Core;
use TerraThread\Core\Bookings;
use TerraThread\Core\Schema;

defined( 'ABSPATH' ) || exit;

function run(): void {
	$materials = seed_materials();

	foreach ( products() as $product ) {
		$id = seed_post( Core\PRODUCT, $product, array( Core\MATERIAL => array( $materials[ $product['material'] ] ) ) );
		attach_photo( $id, $product['slug'], $product['alt'] );
	}

	foreach ( workshops() as $workshop ) {
		$id = seed_post( Core\WORKSHOP, $workshop );
		attach_photo( $id, $workshop['slug'], $workshop['alt'] );
		if ( $id && ! Bookings\sessions_for( $id, false ) ) {
			seed_sessions( $id, $workshop['sessions'] );
		}
	}

	seed_page( 'about', 'Our Studio', about_content() );

	echo "    seeded catalogue: 6 products, 3 workshops\n";
}

function seed_materials(): array {
	$ids = array();
	foreach ( array( 'ceramics' => 'Ceramics', 'leather' => 'Leather', 'textiles' => 'Textiles', 'woodwork' => 'Woodwork', 'home-fragrance' => 'Home Fragrance' ) as $slug => $name ) {
		$term        = term_exists( $slug, Core\MATERIAL ) ?: wp_insert_term( $name, Core\MATERIAL, array( 'slug' => $slug ) );
		$ids[ $slug ] = (int) ( is_array( $term ) ? $term['term_id'] : $term );
	}
	return $ids;
}

function seed_post( string $type, array $data, array $terms = array() ): int {
	$existing = get_page_by_path( $data['slug'], OBJECT, $type );
	if ( $existing ) {
		return $existing->ID;
	}

	$id = wp_insert_post(
		array(
			'post_type'    => $type,
			'post_status'  => 'publish',
			'post_name'    => $data['slug'],
			'post_title'   => $data['title'],
			'post_excerpt' => $data['excerpt'],
			'post_content' => $data['content'],
			'menu_order'   => $data['order'] ?? 0,
			'meta_input'   => $data['meta'],
			'tax_input'    => $terms,
		),
		true
	);

	if ( is_wp_error( $id ) ) {
		fwrite( STDERR, "Seeding {$data['slug']} failed: " . $id->get_error_message() . "\n" );
		return 0;
	}

	// tax_input requires a user with assign_terms; set terms explicitly for CLI.
	foreach ( $terms as $taxonomy => $term_ids ) {
		wp_set_object_terms( $id, $term_ids, $taxonomy );
	}

	return $id;
}

/**
 * Import seed/images/{slug}.jpg into the media library and make it the
 * post's featured image. Skips posts that already have one, so photos an
 * editor has replaced in wp-admin are never overwritten.
 */
function attach_photo( int $post_id, string $slug, string $alt ): void {
	$source = dirname( __DIR__ ) . "/seed/images/$slug.jpg";
	if ( ! $post_id || has_post_thumbnail( $post_id ) || ! is_file( $source ) ) {
		return;
	}

	require_once ABSPATH . 'wp-admin/includes/image.php';

	$upload = wp_upload_bits( "$slug.jpg", null, (string) file_get_contents( $source ) );
	if ( ! empty( $upload['error'] ) ) {
		fwrite( STDERR, "Photo for $slug failed: {$upload['error']}\n" );
		return;
	}

	$attachment_id = wp_insert_attachment(
		array(
			'post_title'     => get_the_title( $post_id ),
			'post_mime_type' => 'image/jpeg',
			'post_status'    => 'inherit',
		),
		$upload['file'],
		$post_id
	);

	wp_update_attachment_metadata( $attachment_id, wp_generate_attachment_metadata( $attachment_id, $upload['file'] ) );
	update_post_meta( $attachment_id, '_wp_attachment_image_alt', $alt );
	set_post_thumbnail( $post_id, $attachment_id );
}

function seed_page( string $slug, string $title, string $content ): void {
	if ( ! get_page_by_path( $slug ) ) {
		wp_insert_post(
			array(
				'post_type'    => 'page',
				'post_status'  => 'publish',
				'post_name'    => $slug,
				'post_title'   => $title,
				'post_content' => $content,
			)
		);
	}
}

/**
 * Keep the demo schedule upcoming: for each workshop whose first session is less
 * than $lead_days away (or past), move all its sessions forward by whole weeks,
 * so weekdays, spacing and existing reservations are kept. Returns sessions moved.
 */
function roll_forward( int $lead_days = 5 ): int {
	global $wpdb;
	$table = Schema\sessions_table();
	$min   = ( new DateTimeImmutable( 'now', wp_timezone() ) )->modify( "+$lead_days days" );
	$moved = 0;

	// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$firsts = $wpdb->get_results( "SELECT workshop_id, MIN(starts_at) AS first FROM $table WHERE status = 'scheduled' GROUP BY workshop_id", ARRAY_A );

	foreach ( $firsts as $row ) {
		$first = new DateTimeImmutable( $row['first'], wp_timezone() );
		if ( $first >= $min ) {
			continue;
		}
		$weeks = (int) ceil( ( $min->getTimestamp() - $first->getTimestamp() ) / WEEK_IN_SECONDS );
		$days  = $weeks * 7;

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$ids = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM $table WHERE workshop_id = %d AND status = 'scheduled'", $row['workshop_id'] ) );
		foreach ( $ids as $id ) {
			$s = $wpdb->get_row( $wpdb->prepare( "SELECT starts_at, ends_at FROM $table WHERE id = %d", $id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->update(
				$table,
				array(
					'starts_at' => ( new DateTimeImmutable( $s['starts_at'] ) )->modify( "+$days days" )->format( 'Y-m-d H:i:s' ),
					'ends_at'   => ( new DateTimeImmutable( $s['ends_at'] ) )->modify( "+$days days" )->format( 'Y-m-d H:i:s' ),
				),
				array( 'id' => $id )
			);
			++$moved;
		}
	}

	return $moved;
}

/**
 * @param array<array{days: int, time: string, booked?: int[]}> $sessions
 */
function seed_sessions( int $workshop_id, array $sessions ): void {
	global $wpdb;
	$today = new DateTimeImmutable( 'today', wp_timezone() );
	$guests = array( array( 'Ava Lindqvist', 'ava@example.test' ), array( 'Noah Bautista', 'noah@example.test' ), array( 'Priya Raman', 'priya@example.test' ), array( 'Leo Marchetti', 'leo@example.test' ) );

	foreach ( $sessions as $s ) {
		$start      = $today->modify( "+{$s['days']} days {$s['time']}" );
		$session_id = Bookings\add_session( $workshop_id, $start->format( 'Y-m-d H:i:s' ) );

		foreach ( $s['booked'] ?? array() as $i => $seats ) {
			[ $name, $email ] = $guests[ $i % count( $guests ) ];
			$wpdb->insert(
				Schema\bookings_table(),
				array(
					'session_id' => $session_id,
					'reference'  => 'TT-' . strtoupper( wp_generate_password( 6, false, false ) ),
					'name'       => $name,
					'email'      => $email,
					'seats'      => $seats,
					'status'     => 'confirmed',
					'created_at' => current_time( 'mysql' ),
				)
			);
		}
	}
}

function options( array $options ): string {
	// meta_input values are unslashed on save, so slash the JSON to keep its escapes intact.
	return wp_slash( wp_json_encode( $options, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) );
}

/**
 * Shared glaze choices. $first is listed first so the default selection
 * matches the glaze shown in the product photo.
 */
function glaze_swatches( string $first = 'raw-terracotta' ): array {
	$glazes = array(
		array( 'value' => 'raw-terracotta', 'label' => 'Raw Terracotta', 'price' => 0, 'color' => '#C07A4F' ),
		array( 'value' => 'blush', 'label' => 'Blush', 'price' => 0, 'color' => '#D6A397' ),
		array( 'value' => 'speckled-bone', 'label' => 'Speckled Bone', 'price' => 0, 'color' => '#E6DFD3' ),
		array( 'value' => 'celadon', 'label' => 'Celadon', 'price' => 12, 'color' => '#9DB09A' ),
		array( 'value' => 'iron-speckle', 'label' => 'Iron Speckle', 'price' => 8, 'color' => '#6B5446' ),
	);
	usort( $glazes, fn( $a, $b ) => ( $b['value'] === $first ) <=> ( $a['value'] === $first ) );
	return $glazes;
}

function para( string ...$paragraphs ): string {
	return implode( "\n\n", array_map( fn( $p ) => "<!-- wp:paragraph -->\n<p>$p</p>\n<!-- /wp:paragraph -->", $paragraphs ) );
}

function products(): array {
	return array(
		array(
			'slug'     => 'ember-vase',
			'title'    => 'Ember Vase',
			'material' => 'ceramics',
			'order'    => 1,
			'alt'      => 'Ribbed blush-pink ceramic vase holding pale dried pampas grass against a white wall',
			'excerpt'  => 'Wheel-thrown stoneware with fine ribbing and a soft matte glaze, fired slow in our gas kiln.',
			'content'  => para(
				'Every Ember Vase begins as a single pound of local stoneware, centred and pulled on the wheel in one sitting. The ribbing is cut by hand while the clay is still leather-hard.',
				'Fired to cone 6 and glazed inside for water-tightness, it holds a single branch as easily as a full armful of dried grasses.'
			),
			'meta'     => array(
				'tt_price'      => 68,
				'tt_stock'      => 12,
				'tt_sku'        => 'TT-CER-001',
				'tt_art'        => 'vase',
				'tt_lead_time'  => 'Made to order · ships in 2–3 weeks',
				'tt_dimensions' => 'Bud 14 cm · Classic 22 cm · Tall 30 cm',
				'tt_care'       => 'Hand wash. Watertight; not for outdoor frost.',
				'tt_options'    => options(
					array(
						array( 'key' => 'glaze', 'label' => 'Glaze', 'type' => 'swatch', 'choices' => glaze_swatches( 'blush' ) ),
						array(
							'key'     => 'size',
							'label'   => 'Size',
							'type'    => 'select',
							'choices' => array(
								array( 'value' => 'bud', 'label' => 'Bud — 14 cm', 'price' => 0 ),
								array( 'value' => 'classic', 'label' => 'Classic — 22 cm', 'price' => 24 ),
								array( 'value' => 'tall', 'label' => 'Tall — 30 cm', 'price' => 46 ),
							),
						),
						array( 'key' => 'engraving', 'label' => 'Carved initials', 'type' => 'text', 'help' => 'Hand-carved into the foot ring before firing.', 'price' => 14, 'maxlength' => 4, 'placeholder' => 'e.g. M.O.' ),
						array( 'key' => 'gift_wrap', 'label' => 'Linen gift wrap', 'type' => 'checkbox', 'price' => 6 ),
					)
				),
			),
		),
		array(
			'slug'     => 'wanderer-leather-journal',
			'title'    => 'Wanderer Leather Journal',
			'material' => 'leather',
			'order'    => 2,
			'alt'      => 'Open brown leather notebook on a café table beside an iced coffee and a small plant',
			'excerpt'  => 'Vegetable-tanned leather cover with a refillable, hand-stitched signature.',
			'content'  => para(
				'Cut from a single hide of Italian vegetable-tanned leather and saddle-stitched with waxed linen thread. The cover darkens and softens with every trip.',
				'Inside: a 96-page signature of 120 gsm cotton paper that slips out when it is full, so the cover can travel with you for decades.'
			),
			'meta'     => array(
				'tt_price'      => 54,
				'tt_stock'      => 20,
				'tt_sku'        => 'TT-LEA-002',
				'tt_art'        => 'journal',
				'tt_lead_time'  => 'Ships in 3–5 days',
				'tt_dimensions' => 'A5 · 15 × 21 cm',
				'tt_care'       => 'Condition twice a year with beeswax balm.',
				'tt_options'    => options(
					array(
						array(
							'key'     => 'leather',
							'label'   => 'Leather',
							'type'    => 'swatch',
							'choices' => array(
								array( 'value' => 'cognac', 'label' => 'Cognac', 'price' => 0, 'color' => '#8A4A2E' ),
								array( 'value' => 'espresso', 'label' => 'Espresso', 'price' => 0, 'color' => '#3B2A22' ),
								array( 'value' => 'natural', 'label' => 'Natural', 'price' => 0, 'color' => '#D2B38C' ),
								array( 'value' => 'oxblood', 'label' => 'Oxblood', 'price' => 6, 'color' => '#5E2328' ),
							),
						),
						array(
							'key'     => 'paper',
							'label'   => 'Paper',
							'type'    => 'select',
							'choices' => array(
								array( 'value' => 'dotted', 'label' => 'Dotted grid', 'price' => 0 ),
								array( 'value' => 'lined', 'label' => 'Lined', 'price' => 0 ),
								array( 'value' => 'blank', 'label' => 'Blank', 'price' => 0 ),
								array( 'value' => 'watercolour', 'label' => 'Watercolour, 300 gsm', 'price' => 8 ),
							),
						),
						array( 'key' => 'engraving', 'label' => 'Debossed cover text', 'type' => 'text', 'help' => '$10 setup + $0.75 per letter.', 'price' => 10, 'price_per_char' => 0.75, 'maxlength' => 20, 'placeholder' => 'A name or a short line' ),
						array( 'key' => 'gift_wrap', 'label' => 'Linen gift wrap', 'type' => 'checkbox', 'price' => 6 ),
					)
				),
			),
		),
		array(
			'slug'     => 'hearth-serving-bowl',
			'title'    => 'Hearth Serving Bowl',
			'material' => 'ceramics',
			'order'    => 3,
			'alt'      => 'Speckled pale stoneware bowl holding two lemons on a linen cloth',
			'excerpt'  => 'Speckled stoneware with a softly uneven rim, for fruit, salads and bread.',
			'content'  => para(
				'Thrown wide and trimmed thin at the rim, Hearth is our answer to the bowl that sits at the centre of every long dinner.',
				'Food-safe glazes, dishwasher-friendly, and heavy enough to stay put when the table gets lively.'
			),
			'meta'     => array(
				'tt_price'      => 82,
				'tt_stock'      => 3,
				'tt_sku'        => 'TT-CER-003',
				'tt_art'        => 'bowl',
				'tt_lead_time'  => 'Ships in 1 week',
				'tt_dimensions' => '32 cm wide · 9 cm deep',
				'tt_care'       => 'Dishwasher and microwave safe.',
				'tt_options'    => options(
					array(
						array( 'key' => 'glaze', 'label' => 'Glaze', 'type' => 'swatch', 'choices' => glaze_swatches( 'speckled-bone' ) ),
						array( 'key' => 'engraving', 'label' => 'Inscription under the rim', 'type' => 'text', 'help' => 'Hand-lettered in underglaze.', 'price' => 18, 'maxlength' => 30, 'placeholder' => 'For the Okafor table' ),
						array( 'key' => 'gift_wrap', 'label' => 'Linen gift wrap', 'type' => 'checkbox', 'price' => 6 ),
					)
				),
			),
		),
		array(
			'slug'     => 'loom-linen-throw',
			'title'    => 'Loom Linen Throw',
			'material' => 'textiles',
			'order'    => 4,
			'alt'      => 'Close-up of undyed handwoven linen ending in a long, loose fringe',
			'excerpt'  => 'Handwoven Belgian linen, plant-dyed and finished with a knotted fringe.',
			'content'  => para(
				'Woven on our eight-shaft floor loom from long-staple Belgian flax, then dyed in small batches with madder root, weld and walnut hull.',
				'Linen gets softer with every wash — this throw will feel better in ten years than the day it arrives.'
			),
			'meta'     => array(
				'tt_price'      => 145,
				'tt_stock'      => 4,
				'tt_sku'        => 'TT-TEX-004',
				'tt_art'        => 'throw',
				'tt_lead_time'  => 'Woven to order · ships in 3 weeks',
				'tt_dimensions' => 'Lap 120 × 150 cm · Bed 180 × 230 cm',
				'tt_care'       => 'Cool machine wash, line dry.',
				'tt_options'    => options(
					array(
						array(
							'key'     => 'colour',
							'label'   => 'Dye',
							'type'    => 'swatch',
							'choices' => array(
								array( 'value' => 'oat', 'label' => 'Undyed Oat', 'price' => 0, 'color' => '#D9CBB4' ),
								array( 'value' => 'madder', 'label' => 'Madder Rust', 'price' => 0, 'color' => '#A9553A' ),
								array( 'value' => 'weld', 'label' => 'Weld Sage', 'price' => 0, 'color' => '#9AA17A' ),
								array( 'value' => 'walnut', 'label' => 'Walnut', 'price' => 10, 'color' => '#5C4433' ),
							),
						),
						array(
							'key'     => 'size',
							'label'   => 'Size',
							'type'    => 'select',
							'choices' => array(
								array( 'value' => 'lap', 'label' => 'Lap — 120 × 150 cm', 'price' => 0 ),
								array( 'value' => 'bed', 'label' => 'Bed — 180 × 230 cm', 'price' => 65 ),
							),
						),
						array( 'key' => 'engraving', 'label' => 'Embroidered monogram', 'type' => 'text', 'help' => 'Stitched by hand in the corner.', 'price' => 22, 'maxlength' => 3, 'placeholder' => 'e.g. IH' ),
						array( 'key' => 'gift_wrap', 'label' => 'Linen gift wrap', 'type' => 'checkbox', 'price' => 6 ),
					)
				),
			),
		),
		array(
			'slug'     => 'grove-oak-board',
			'title'    => 'Grove Oak Board',
			'material' => 'woodwork',
			'order'    => 5,
			'alt'      => 'Oak paddle board with a long handle on white boards, a small bowl of blueberries on top',
			'excerpt'  => 'Solid white oak with a long handle, shaped by hand and oiled until it glows.',
			'content'  => para(
				'Each board is cut from a single plank of fallen oak sourced within 80 km of the studio, then shaped by hand, handle and all.',
				'Finished with food-safe oil. Use it for bread and cheese, or hang it by the handle between meals.'
			),
			'meta'     => array(
				'tt_price'      => 96,
				'tt_stock'      => 9,
				'tt_sku'        => 'TT-WOD-005',
				'tt_art'        => 'board',
				'tt_lead_time'  => 'Ships in 1–2 weeks',
				'tt_dimensions' => 'Everyday 30 × 22 cm · Host 45 × 28 cm',
				'tt_care'       => 'Hand wash, oil monthly.',
				'tt_options'    => options(
					array(
						array(
							'key'     => 'size',
							'label'   => 'Size',
							'type'    => 'select',
							'choices' => array(
								array( 'value' => 'everyday', 'label' => 'Everyday — 30 × 22 cm', 'price' => 0 ),
								array( 'value' => 'host', 'label' => 'Host — 45 × 28 cm', 'price' => 38 ),
							),
						),
						array(
							'key'     => 'finish',
							'label'   => 'Finish',
							'type'    => 'select',
							'choices' => array(
								array( 'value' => 'oil', 'label' => 'Food-safe mineral oil', 'price' => 0 ),
								array( 'value' => 'beeswax', 'label' => 'Beeswax & carnauba', 'price' => 6 ),
							),
						),
						array( 'key' => 'engraving', 'label' => 'Engraved message', 'type' => 'text', 'help' => '$12 setup + $0.50 per letter.', 'price' => 12, 'price_per_char' => 0.5, 'maxlength' => 40, 'placeholder' => 'Est. 2026 — The Reyes Kitchen' ),
						array( 'key' => 'gift_wrap', 'label' => 'Linen gift wrap', 'type' => 'checkbox', 'price' => 6 ),
					)
				),
			),
		),
		array(
			'slug'     => 'smoke-and-fig-candle',
			'title'    => 'Smoke & Fig Candle',
			'material' => 'home-fragrance',
			'order'    => 6,
			'alt'      => 'Lit wood-wick candle in a glass jar on a dark wooden table, its brass lid beside it',
			'excerpt'  => 'Coconut-soy wax and a crackling wood wick, poured into a reusable glass jar. 50-hour burn.',
			'content'  => para(
				'Hand-poured in small batches. Once the wax is gone, wash out the jar and it lives on as a home for buttons, matches or a cutting from the garden.',
				'Cotton wick, phthalate-free fragrance oils, roughly 50 hours of slow, even burn.'
			),
			'meta'     => array(
				'tt_price'      => 38,
				'tt_stock'      => 30,
				'tt_sku'        => 'TT-FRA-006',
				'tt_art'        => 'candle',
				'tt_lead_time'  => 'Ships in 2–4 days',
				'tt_dimensions' => '220 g · 9 cm tall',
				'tt_care'       => 'Trim wick to 5 mm before each burn.',
				'tt_options'    => options(
					array(
						array(
							'key'     => 'scent',
							'label'   => 'Scent',
							'type'    => 'select',
							'choices' => array(
								array( 'value' => 'smoke-fig', 'label' => 'Smoke & Fig', 'price' => 0 ),
								array( 'value' => 'cedar-moss', 'label' => 'Cedar & Moss', 'price' => 0 ),
								array( 'value' => 'orange-blossom', 'label' => 'Orange Blossom & Clay', 'price' => 0 ),
							),
						),
						array(
							'key'     => 'lid',
							'label'   => 'Lid',
							'type'    => 'swatch',
							'choices' => array(
								array( 'value' => 'brass', 'label' => 'Brushed Brass', 'price' => 0, 'color' => '#B8955A' ),
								array( 'value' => 'black', 'label' => 'Matte Black', 'price' => 0, 'color' => '#2E2A27' ),
								array( 'value' => 'copper', 'label' => 'Copper', 'price' => 4, 'color' => '#B26E45' ),
							),
						),
						array( 'key' => 'engraving', 'label' => 'Custom label', 'type' => 'text', 'help' => 'Letterpress-printed on the lid tag.', 'price' => 6, 'maxlength' => 28, 'placeholder' => 'Happy housewarming' ),
					)
				),
			),
		),
	);
}

function workshops(): array {
	return array(
		array(
			'slug'     => 'wheel-throwing-foundations',
			'title'    => 'Wheel Throwing Foundations',
			'alt'      => 'Clay-covered hands shaping a tall terracotta vase on a potter’s wheel',
			'excerpt'  => 'Centre, open and pull your first cylinders — then take home two finished pieces.',
			'content'  => para(
				'A slow, hands-on morning at the wheel. We start with wedging and centring, move to opening and pulling walls, and finish by trimming the best of your pieces.',
				'Your two favourite pieces are glazed and fired by the studio and ready for collection three weeks later.'
			),
			'meta'     => array(
				'tt_price'          => 120,
				'tt_capacity'       => 8,
				'tt_duration'       => 180,
				'tt_instructor'     => 'Mara Okafor',
				'tt_instructor_bio' => 'Studio founder and potter for 14 years, trained in Mashiko, Japan.',
				'tt_location'       => 'The Clay Room',
				'tt_level'          => 'Beginner',
				'tt_art'            => 'vase',
				'tt_includes'       => 'Clay, tools, apron, glazing & firing of two pieces, tea and pastries',
			),
			'sessions' => array(
				array( 'days' => 9, 'time' => '10:00', 'booked' => array( 2, 3, 1 ) ),
				array( 'days' => 16, 'time' => '10:00', 'booked' => array( 2 ) ),
				array( 'days' => 30, 'time' => '14:00' ),
			),
		),
		array(
			'slug'     => 'leather-journal-binding',
			'title'    => 'Leather Journal Binding',
			'alt'      => 'A leatherworker’s bench with punches and pliers hanging above strips of leather',
			'excerpt'  => 'Cut, punch and saddle-stitch a leather journal you will carry for years.',
			'content'  => para(
				'Learn the fundamentals of hand leatherwork: pattern cutting, edge burnishing, pricking irons and the saddle stitch.',
				'You will leave with a finished A5 journal cover and a refill signature you bound yourself.'
			),
			'meta'     => array(
				'tt_price'          => 95,
				'tt_capacity'       => 6,
				'tt_duration'       => 150,
				'tt_instructor'     => 'Tomás Reyes',
				'tt_instructor_bio' => 'Leatherworker and bookbinder; makes every Wanderer journal in the shop.',
				'tt_location'       => 'The Bench Room',
				'tt_level'          => 'All levels',
				'tt_art'            => 'journal',
				'tt_includes'       => 'Vegetable-tanned leather, waxed thread, paper, tools, a glass of wine',
			),
			'sessions' => array(
				array( 'days' => 12, 'time' => '18:00', 'booked' => array( 2, 2, 2 ) ), // sold out
				array( 'days' => 26, 'time' => '18:00', 'booked' => array( 1 ) ),
				array( 'days' => 40, 'time' => '13:00' ),
			),
		),
		array(
			'slug'     => 'natural-dye-and-weaving',
			'title'    => 'Natural Dye & Weaving',
			'alt'      => 'Balls of brown and grey wool yarn on a wooden table beside a work in progress',
			'excerpt'  => 'Brew dye baths from madder and weld, then weave a wall hanging on a frame loom.',
			'content'  => para(
				'A full afternoon split between the dye garden and the loom. You will mordant and dye skeins of wool with plants grown at the studio, then warp a frame loom and weave a small tapestry.',
				'Take home your loom, your weaving and a sampler card of every colour we brewed.'
			),
			'meta'     => array(
				'tt_price'          => 145,
				'tt_capacity'       => 10,
				'tt_duration'       => 240,
				'tt_instructor'     => 'Ines Halvorsen',
				'tt_instructor_bio' => 'Textile artist and natural dyer whose work hangs in the Craft Council collection.',
				'tt_location'       => 'The Dye Garden & Loom Loft',
				'tt_level'          => 'Intermediate',
				'tt_art'            => 'throw',
				'tt_includes'       => 'Frame loom to keep, dyed wool, mordants, lunch',
			),
			'sessions' => array(
				array( 'days' => 20, 'time' => '10:00', 'booked' => array( 2, 1 ) ),
				array( 'days' => 34, 'time' => '10:00' ),
			),
		),
	);
}

function about_content(): string {
	return para(
		'Terra & Thread is a four-person studio working in clay, leather, linen and wood. Everything in the shop is made a few steps from where it is packed — slowly, in small runs, from materials we can trace.',
		'We teach because we were taught. Our masterclasses are small by design: never more than ten people, always with the maker who makes the pieces you see in the shop.'
	);
}
