<?php
/**
 * Product data access and the pricing engine.
 *
 * Option definitions (tt_options meta, JSON) drive both the configurator UI and
 * the server-side quote. The browser previews prices instantly; quote() is the
 * source of truth and is what the bag records.
 *
 * Option shape:
 *   { key, label, type: swatch|select|text|checkbox, help?,
 *     choices?: [{ value, label, price, color? }],      // swatch|select
 *     price?, price_per_char?, maxlength?, placeholder? } // text|checkbox
 */

namespace TerraThread\Core\Catalog;

use TerraThread\Core;
use WP_Error;
use WP_Post;

defined( 'ABSPATH' ) || exit;

const OPTION_TYPES = array( 'swatch', 'select', 'text', 'checkbox' );
const MAX_QUANTITY = 10;

/** Letters, numbers, spaces and gentle punctuation — what a maker can engrave. */
const ENGRAVING_PATTERN = "/^[\\p{L}\\p{N} .,'&!?\\-]*$/u";

function format_money( float $amount ): string {
	return '$' . number_format( $amount, ( floor( $amount ) == $amount ) ? 0 : 2 );
}

/**
 * Normalised product record, or null if the post is not a published product.
 */
function product( int|WP_Post $post ): ?array {
	$post = get_post( $post );
	if ( ! $post || Core\PRODUCT !== $post->post_type ) {
		return null;
	}

	$terms = get_the_terms( $post, Core\MATERIAL );

	return array(
		'id'         => $post->ID,
		'title'      => get_the_title( $post ),
		'url'        => get_permalink( $post ),
		'excerpt'    => get_the_excerpt( $post ),
		'price'      => (float) get_post_meta( $post->ID, 'tt_price', true ),
		'stock'      => (int) get_post_meta( $post->ID, 'tt_stock', true ),
		'sku'        => (string) get_post_meta( $post->ID, 'tt_sku', true ),
		'art'        => (string) get_post_meta( $post->ID, 'tt_art', true ),
		'image_id'   => (int) get_post_thumbnail_id( $post ),
		'lead_time'  => (string) get_post_meta( $post->ID, 'tt_lead_time', true ),
		'dimensions' => (string) get_post_meta( $post->ID, 'tt_dimensions', true ),
		'care'       => (string) get_post_meta( $post->ID, 'tt_care', true ),
		'options'    => options( $post->ID ),
		'materials'  => is_array( $terms ) ? wp_list_pluck( $terms, 'name', 'slug' ) : array(),
	);
}

function options( int $product_id ): array {
	$decoded = json_decode( (string) get_post_meta( $product_id, 'tt_options', true ), true );
	return is_array( $decoded ) ? normalise_options( $decoded ) : array();
}

/**
 * Meta sanitiser: rejects malformed JSON instead of storing it.
 */
function sanitize_options_json( $value ): string {
	// update_metadata() has already unslashed the value by the time this runs.
	$decoded = is_array( $value ) ? $value : json_decode( (string) $value, true );
	return is_array( $decoded ) ? wp_json_encode( normalise_options( $decoded ) ) : '[]';
}

function normalise_options( array $raw ): array {
	$clean = array();

	foreach ( $raw as $opt ) {
		$type = $opt['type'] ?? '';
		$key  = sanitize_key( $opt['key'] ?? '' );
		if ( ! $key || ! in_array( $type, OPTION_TYPES, true ) ) {
			continue;
		}

		$out = array(
			'key'   => $key,
			'label' => sanitize_text_field( $opt['label'] ?? $key ),
			'type'  => $type,
			'help'  => sanitize_text_field( $opt['help'] ?? '' ),
		);

		if ( in_array( $type, array( 'swatch', 'select' ), true ) ) {
			$out['choices'] = array();
			foreach ( (array) ( $opt['choices'] ?? array() ) as $choice ) {
				$value = sanitize_key( $choice['value'] ?? '' );
				if ( ! $value ) {
					continue;
				}
				$out['choices'][] = array(
					'value' => $value,
					'label' => sanitize_text_field( $choice['label'] ?? $value ),
					'price' => round( (float) ( $choice['price'] ?? 0 ), 2 ),
					'color' => sanitize_hex_color( $choice['color'] ?? '' ) ?: null,
				);
			}
			if ( ! $out['choices'] ) {
				continue;
			}
		} else {
			$out['price'] = round( (float) ( $opt['price'] ?? 0 ), 2 );
		}

		if ( 'text' === $type ) {
			$out['price_per_char'] = round( (float) ( $opt['price_per_char'] ?? 0 ), 2 );
			$out['maxlength']      = max( 1, min( 60, (int) ( $opt['maxlength'] ?? 24 ) ) );
			$out['placeholder']    = sanitize_text_field( $opt['placeholder'] ?? '' );
		}

		$clean[] = $out;
	}

	return $clean;
}

/**
 * Price a configuration. Unknown keys are ignored; invalid values are errors.
 *
 * @return array{unit: float, total: float, quantity: int, lines: array, selection: array}|WP_Error
 */
function quote( array $product, array $selection, int $quantity = 1 ): array|WP_Error {
	if ( $product['stock'] < 1 ) {
		return new WP_Error( 'tt_sold_out', __( 'This piece is sold out.', 'terra-thread' ), array( 'status' => 409 ) );
	}
	if ( $quantity < 1 || $quantity > min( MAX_QUANTITY, $product['stock'] ) ) {
		return new WP_Error(
			'tt_quantity',
			sprintf( __( 'Choose between 1 and %d.', 'terra-thread' ), min( MAX_QUANTITY, $product['stock'] ) ),
			array( 'status' => 400 )
		);
	}

	$unit  = $product['price'];
	$lines = array( array( 'label' => __( 'Base price', 'terra-thread' ), 'amount' => $unit ) );
	$clean = array();

	foreach ( $product['options'] as $opt ) {
		$key   = $opt['key'];
		$value = $selection[ $key ] ?? null;

		switch ( $opt['type'] ) {
			case 'swatch':
			case 'select':
				$choice = null;
				foreach ( $opt['choices'] as $c ) {
					if ( $c['value'] === $value ) {
						$choice = $c;
					}
				}
				// Choice options always have a value: fall back to the first one.
				$choice ??= $opt['choices'][0];
				if ( null !== $value && $choice['value'] !== $value ) {
					return invalid( $opt );
				}
				$clean[ $key ] = $choice['value'];
				if ( $choice['price'] ) {
					$lines[] = array( 'label' => $opt['label'] . ': ' . $choice['label'], 'amount' => $choice['price'] );
					$unit   += $choice['price'];
				}
				break;

			case 'text':
				$text = trim( preg_replace( '/\s+/u', ' ', (string) $value ) );
				if ( '' === $text ) {
					break;
				}
				if ( mb_strlen( $text ) > $opt['maxlength'] || ! preg_match( ENGRAVING_PATTERN, $text ) ) {
					return invalid( $opt );
				}
				$chars         = mb_strlen( str_replace( ' ', '', $text ) );
				$amount        = round( $opt['price'] + $chars * $opt['price_per_char'], 2 );
				$clean[ $key ] = $text;
				$lines[]       = array( 'label' => sprintf( '%s “%s”', $opt['label'], $text ), 'amount' => $amount );
				$unit         += $amount;
				break;

			case 'checkbox':
				if ( filter_var( $value, FILTER_VALIDATE_BOOLEAN ) ) {
					$clean[ $key ] = true;
					$lines[]       = array( 'label' => $opt['label'], 'amount' => $opt['price'] );
					$unit         += $opt['price'];
				}
				break;
		}
	}

	$unit = round( $unit, 2 );

	return array(
		'unit'      => $unit,
		'total'     => round( $unit * $quantity, 2 ),
		'quantity'  => $quantity,
		'lines'     => $lines,
		'selection' => $clean,
	);
}

function invalid( array $opt ): WP_Error {
	return new WP_Error(
		'tt_invalid_option',
		sprintf( __( 'Please check “%s”.', 'terra-thread' ), $opt['label'] ),
		array( 'status' => 400, 'option' => $opt['key'] )
	);
}
