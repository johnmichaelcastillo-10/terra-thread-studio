<?php
/**
 * Rendering helpers shared by the theme's dynamic blocks.
 */

namespace TerraThread\Theme;

defined( 'ABSPATH' ) || exit;

const ART_TINTS = array(
	'vase'    => '#B5583A',
	'journal' => '#9A5B34',
	'bowl'    => '#E9E1D3',
	'throw'   => '#D9CBB4',
	'board'   => '#5C4030',
	'candle'  => '#B5583A',
);

function core_ready(): bool {
	return function_exists( '\TerraThread\Core\Catalog\product' );
}

function core_missing_notice(): string {
	return '<p class="tt-notice">' . esc_html__( 'Activate the Terra & Thread Core plugin to show studio content here.', 'terra-thread' ) . '</p>';
}

function money( float $amount ): string {
	return \TerraThread\Core\Catalog\format_money( $amount );
}

/**
 * Default tint: the first swatch colour of the product, else the art's own.
 */
function product_tint( array $product ): string {
	foreach ( $product['options'] as $opt ) {
		if ( 'swatch' === $opt['type'] && ! empty( $opt['choices'][0]['color'] ) ) {
			return $opt['choices'][0]['color'];
		}
	}
	return ART_TINTS[ $product['art'] ] ?? '#B5583A';
}

/**
 * Inline SVG illustration. Shapes are styled by class (.art-body etc.) so the
 * configurator can recolour them live through the --art-tint custom property.
 */
function art( string $name, string $tint = '', string $extra = '' ): string {
	static $cache = array();

	$name = array_key_exists( $name, ART_TINTS ) ? $name : 'vase';
	if ( ! isset( $cache[ $name ] ) ) {
		$cache[ $name ] = (string) file_get_contents( get_theme_file_path( "assets/art/$name.svg" ) );
	}

	$tint = sanitize_hex_color( $tint ) ?: ART_TINTS[ $name ];

	return sprintf(
		'<div class="tt-art" data-art="%1$s" style="--art-tint:%2$s">%3$s%4$s</div>',
		esc_attr( $name ),
		esc_attr( $tint ),
		$cache[ $name ], // Trusted theme file.
		$extra // phpcs:ignore WordPress.Security.EscapeOutput -- callers escape.
	);
}

/**
 * The record's featured photo (responsive srcset, lazy by default), or the
 * SVG illustration when no photo has been set yet.
 *
 * @param array  $record  Product or workshop record with image_id and art.
 * @param string $size    Registered image size.
 * @param array  $attrs   Extra <img> attributes (e.g. loading, sizes).
 */
function media( array $record, string $size = 'tt-portrait', array $attrs = array(), string $extra = '' ): string {
	if ( ! empty( $record['image_id'] ) ) {
		$img = wp_get_attachment_image( $record['image_id'], $size, false, $attrs + array( 'class' => 'tt-photo__img' ) );
		if ( $img ) {
			return '<div class="tt-photo">' . $img . $extra . '</div>';
		}
	}
	$tint = isset( $record['options'] ) ? product_tint( $record ) : '';
	return art( $record['art'] ?? 'vase', $tint, $extra );
}

function stock_badge( int $stock ): string {
	if ( $stock < 1 ) {
		return '<span class="tt-badge tt-badge--muted">' . esc_html__( 'Sold out', 'terra-thread' ) . '</span>';
	}
	if ( $stock <= 5 ) {
		/* translators: %d: units left */
		return '<span class="tt-badge">' . esc_html( sprintf( __( 'Only %d left', 'terra-thread' ), $stock ) ) . '</span>';
	}
	return '';
}

function product_card( array $p ): string {
	$material = $p['materials'] ? reset( $p['materials'] ) : '';

	ob_start();
	?>
	<article class="tt-card">
		<a class="tt-card__media" href="<?php echo esc_url( $p['url'] ); ?>" tabindex="-1" aria-hidden="true">
			<?php echo media( $p, 'tt-portrait', array( 'sizes' => '(max-width: 560px) 100vw, (max-width: 900px) 50vw, 420px', 'alt' => '' ) ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
			<?php echo stock_badge( $p['stock'] ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
		</a>
		<div class="tt-card__body">
			<?php if ( $material ) : ?>
				<p class="tt-eyebrow"><?php echo esc_html( $material ); ?></p>
			<?php endif; ?>
			<h3 class="tt-card__title"><a href="<?php echo esc_url( $p['url'] ); ?>"><?php echo esc_html( $p['title'] ); ?></a></h3>
			<p class="tt-card__excerpt"><?php echo esc_html( $p['excerpt'] ); ?></p>
			<p class="tt-card__price">
				<?php if ( $p['options'] ) : ?><span class="tt-card__from"><?php esc_html_e( 'from', 'terra-thread' ); ?></span><?php endif; ?>
				<?php echo esc_html( money( $p['price'] ) ); ?>
			</p>
		</div>
	</article>
	<?php
	return (string) ob_get_clean();
}

/**
 * Seats indicator: a row of dots, filled for taken seats.
 */
function seat_meter( int $capacity, int $left ): string {
	$dots = '';
	for ( $i = 0; $i < $capacity; $i++ ) {
		$dots .= '<i class="' . ( $i < $capacity - $left ? 'is-taken' : '' ) . '"></i>';
	}

	if ( 0 === $left ) {
		$label = __( 'Sold out', 'terra-thread' );
	} else {
		/* translators: %d: seats left */
		$label = sprintf( _n( '%d seat left', '%d seats left', $left, 'terra-thread' ), $left );
	}

	return sprintf(
		'<span class="tt-seats%s"><span class="tt-seats__dots" aria-hidden="true">%s</span><span class="tt-seats__label">%s</span></span>',
		0 === $left ? ' is-sold-out' : ( $left <= 2 ? ' is-low' : '' ),
		$dots,
		esc_html( $label )
	);
}

function duration_label( int $minutes ): string {
	$h = intdiv( $minutes, 60 );
	$m = $minutes % 60;
	return trim( ( $h ? $h . ' hr' . ( $h > 1 ? 's' : '' ) : '' ) . ( $m ? " $m min" : '' ) );
}
