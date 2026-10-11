<?php
/**
 * Post types, taxonomy and registered meta.
 */

namespace TerraThread\Core;

defined( 'ABSPATH' ) || exit;

const PRODUCT  = 'tt_product';
const WORKSHOP = 'tt_workshop';
const MATERIAL = 'tt_material';

/**
 * Crops used by the storefront. Registered with the content model (not the
 * theme) so they exist when the seeder imports photos during install.
 */
function register_image_sizes(): void {
	add_image_size( 'tt-portrait', 960, 1200, true );
	add_image_size( 'tt-thumb', 240, 300, true );
}

function register_content_model(): void {
	register_image_sizes();

	register_post_type(
		PRODUCT,
		array(
			'labels'       => array(
				'name'          => __( 'Products', 'terra-thread' ),
				'singular_name' => __( 'Product', 'terra-thread' ),
				'add_new_item'  => __( 'Add product', 'terra-thread' ),
				'edit_item'     => __( 'Edit product', 'terra-thread' ),
				'all_items'     => __( 'All products', 'terra-thread' ),
			),
			'public'       => true,
			'has_archive'  => 'shop',
			'rewrite'      => array( 'slug' => 'shop', 'with_front' => false ),
			'menu_icon'    => 'dashicons-store',
			'menu_position' => 20,
			'supports'     => array( 'title', 'editor', 'excerpt', 'thumbnail', 'custom-fields' ),
			'show_in_rest' => true,
			'taxonomies'   => array( MATERIAL ),
		)
	);

	register_post_type(
		WORKSHOP,
		array(
			'labels'       => array(
				'name'          => __( 'Workshops', 'terra-thread' ),
				'singular_name' => __( 'Workshop', 'terra-thread' ),
				'add_new_item'  => __( 'Add workshop', 'terra-thread' ),
				'edit_item'     => __( 'Edit workshop', 'terra-thread' ),
				'all_items'     => __( 'All workshops', 'terra-thread' ),
			),
			'public'       => true,
			'has_archive'  => 'workshops',
			'rewrite'      => array( 'slug' => 'workshops', 'with_front' => false ),
			'menu_icon'    => 'dashicons-calendar-alt',
			'menu_position' => 21,
			'supports'     => array( 'title', 'editor', 'excerpt', 'thumbnail', 'custom-fields' ),
			'show_in_rest' => true,
		)
	);

	register_taxonomy(
		MATERIAL,
		PRODUCT,
		array(
			'labels'            => array(
				'name'          => __( 'Materials', 'terra-thread' ),
				'singular_name' => __( 'Material', 'terra-thread' ),
			),
			'hierarchical'      => true,
			'show_admin_column' => true,
			'show_in_rest'      => true,
			'rewrite'           => array( 'slug' => 'material', 'with_front' => false ),
		)
	);

	$string = array( 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' );
	$number = array( 'type' => 'number', 'sanitize_callback' => fn( $v ) => round( max( 0, (float) $v ), 2 ) );
	$int    = array( 'type' => 'integer', 'sanitize_callback' => fn( $v ) => max( 0, (int) $v ) );

	$product_meta = array(
		'tt_price'      => $number,
		'tt_stock'      => $int,
		'tt_sku'        => $string,
		'tt_art'        => $string,
		'tt_lead_time'  => $string,
		'tt_dimensions' => $string,
		'tt_care'       => $string,
		// JSON-encoded option definitions; validated by Catalog\sanitize_options_json().
		'tt_options'    => array( 'type' => 'string', 'sanitize_callback' => __NAMESPACE__ . '\Catalog\sanitize_options_json' ),
	);

	$workshop_meta = array(
		'tt_price'          => $number,
		'tt_capacity'       => $int,
		'tt_duration'       => $int, // minutes
		'tt_instructor'     => $string,
		'tt_instructor_bio' => $string,
		'tt_location'       => $string,
		'tt_level'          => $string,
		'tt_art'            => $string,
		'tt_includes'       => $string,
	);

	foreach ( array( PRODUCT => $product_meta, WORKSHOP => $workshop_meta ) as $post_type => $fields ) {
		foreach ( $fields as $key => $args ) {
			register_post_meta(
				$post_type,
				$key,
				$args + array(
					'single'        => true,
					'show_in_rest'  => true,
					'auth_callback' => fn() => current_user_can( 'edit_posts' ),
				)
			);
		}
	}
}
