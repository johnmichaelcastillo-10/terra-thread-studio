<?php
/**
 * terra/product-configurator
 *
 * Server-renders a complete, accessible form (prices included) so the page is
 * meaningful before JS loads; configurator.js then adds live pricing, the
 * engraving preview and add-to-bag.
 *
 * @var array    $attributes
 * @var WP_Block $block
 */

use TerraThread\Core;
use TerraThread\Theme;

if ( ! Theme\core_ready() ) {
	echo Theme\core_missing_notice(); // phpcs:ignore WordPress.Security.EscapeOutput
	return;
}

$tt_product = \TerraThread\Core\Catalog\product( (int) ( $block->context['postId'] ?? get_the_ID() ) );
if ( ! $tt_product ) {
	echo '<p class="tt-notice">' . esc_html__( 'The product configurator appears on single product pages.', 'terra-thread' ) . '</p>';
	return;
}

$tt_uid      = 'tt-' . $tt_product['id'];
$tt_sold_out = $tt_product['stock'] < 1;
$tt_max_qty  = max( 1, min( \TerraThread\Core\Catalog\MAX_QUANTITY, $tt_product['stock'] ) );
$tt_material = $tt_product['materials'] ? array_key_first( $tt_product['materials'] ) : '';
$tt_engraved = false;

$tt_plus = fn( float $amount ) => $amount > 0 ? '+' . Theme\money( $amount ) : '';

$tt_data = array(
	'id'      => $tt_product['id'],
	'title'   => $tt_product['title'],
	'url'     => $tt_product['url'],
	'art'     => $tt_product['art'],
	'image'   => $tt_product['image_id'] ? wp_get_attachment_image_url( $tt_product['image_id'], 'tt-thumb' ) : '',
	'price'   => $tt_product['price'],
	'stock'   => $tt_product['stock'],
	'maxQty'  => $tt_max_qty,
	'options' => $tt_product['options'],
);
?>
<section <?php echo get_block_wrapper_attributes( array( 'class' => 'tt-configurator' ) ); // phpcs:ignore WordPress.Security.EscapeOutput ?> data-tt-configurator>
	<script type="application/json" data-tt-config><?php echo wp_json_encode( $tt_data, JSON_HEX_TAG | JSON_HEX_AMP ); ?></script>

	<div class="tt-configurator__gallery">
		<?php
		$tt_swatch  = current( array_filter( $tt_product['options'], fn( $o ) => 'swatch' === $o['type'] ) );
		$tt_overlay = '';
		if ( $tt_swatch ) {
			$tt_overlay .= sprintf(
				'<p class="tt-finish" data-tt-finish><i style="--swatch:%1$s" data-tt-finish-dot></i><span><span class="tt-finish__label">%2$s</span> <span data-tt-finish-name>%3$s</span></span></p>',
				esc_attr( $tt_swatch['choices'][0]['color'] ?: '#B9A994' ),
				esc_html( $tt_swatch['label'] ),
				esc_html( $tt_swatch['choices'][0]['label'] )
			);
		}
		$tt_overlay .= '<p class="tt-proof" data-tt-proof hidden><span class="tt-proof__label">' . esc_html__( 'Your engraving', 'terra-thread' ) . '</span><span class="tt-proof__text" data-tt-engraving-preview></span></p>';
		?>
		<figure class="tt-stage tt-stage--photo">
			<?php
			echo Theme\media( // phpcs:ignore WordPress.Security.EscapeOutput
				$tt_product,
				'full',
				array( 'loading' => 'eager', 'fetchpriority' => 'high', 'sizes' => '(max-width: 1024px) 100vw, 52vw' ),
				$tt_overlay
			);
			?>
			<?php echo Theme\stock_badge( $tt_product['stock'] ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
		</figure>
		<p class="tt-stage__caption"><?php esc_html_e( 'Each piece is made by hand, so glaze, grain and dye will vary slightly from the photo.', 'terra-thread' ); ?></p>
	</div>

	<div class="tt-configurator__panel">
		<nav class="tt-crumbs" aria-label="<?php esc_attr_e( 'Breadcrumb', 'terra-thread' ); ?>">
			<a href="<?php echo esc_url( get_post_type_archive_link( Core\PRODUCT ) ); ?>"><?php esc_html_e( 'Shop', 'terra-thread' ); ?></a>
			<?php if ( $tt_material ) : ?>
				<span aria-hidden="true">/</span>
				<a href="<?php echo esc_url( get_term_link( $tt_material, Core\MATERIAL ) ); ?>"><?php echo esc_html( $tt_product['materials'][ $tt_material ] ); ?></a>
			<?php endif; ?>
		</nav>

		<h1 class="tt-configurator__title"><?php echo esc_html( $tt_product['title'] ); ?></h1>

		<p class="tt-price">
			<span class="tt-price__amount" data-tt-unit><?php echo esc_html( Theme\money( $tt_product['price'] ) ); ?></span>
			<span class="tt-price__note" data-tt-unit-note hidden></span>
		</p>

		<p class="tt-lede"><?php echo esc_html( $tt_product['excerpt'] ); ?></p>

		<form class="tt-options" data-tt-form novalidate>
			<?php foreach ( $tt_product['options'] as $tt_opt ) : ?>
				<?php
				$tt_id   = $tt_uid . '-' . $tt_opt['key'];
				$tt_help = $tt_opt['help'] ? '<p class="tt-help" id="' . esc_attr( $tt_id ) . '-help">' . esc_html( $tt_opt['help'] ) . '</p>' : '';
				?>

				<?php if ( 'swatch' === $tt_opt['type'] ) : ?>
					<fieldset class="tt-field tt-field--swatch">
						<legend class="tt-field__label">
							<?php echo esc_html( $tt_opt['label'] ); ?>
							<span class="tt-field__value" data-tt-value-for="<?php echo esc_attr( $tt_opt['key'] ); ?>"><?php echo esc_html( $tt_opt['choices'][0]['label'] ); ?></span>
						</legend>
						<div class="tt-swatches">
							<?php foreach ( $tt_opt['choices'] as $tt_i => $tt_choice ) : ?>
								<label class="tt-swatch" title="<?php echo esc_attr( trim( $tt_choice['label'] . ' ' . $tt_plus( $tt_choice['price'] ) ) ); ?>">
									<input type="radio" name="<?php echo esc_attr( $tt_opt['key'] ); ?>" value="<?php echo esc_attr( $tt_choice['value'] ); ?>" <?php checked( 0, $tt_i ); ?>>
									<span class="tt-swatch__dot" style="--swatch:<?php echo esc_attr( $tt_choice['color'] ?: '#B9A994' ); ?>"></span>
									<span class="screen-reader-text"><?php echo esc_html( trim( $tt_choice['label'] . ' ' . $tt_plus( $tt_choice['price'] ) ) ); ?></span>
								</label>
							<?php endforeach; ?>
						</div>
						<?php echo $tt_help; // phpcs:ignore WordPress.Security.EscapeOutput ?>
					</fieldset>

				<?php elseif ( 'select' === $tt_opt['type'] ) : ?>
					<div class="tt-field">
						<label class="tt-field__label" for="<?php echo esc_attr( $tt_id ); ?>"><?php echo esc_html( $tt_opt['label'] ); ?></label>
						<div class="tt-select">
							<select id="<?php echo esc_attr( $tt_id ); ?>" name="<?php echo esc_attr( $tt_opt['key'] ); ?>">
								<?php foreach ( $tt_opt['choices'] as $tt_choice ) : ?>
									<option value="<?php echo esc_attr( $tt_choice['value'] ); ?>"><?php echo esc_html( $tt_choice['label'] . ( $tt_choice['price'] ? '  (' . $tt_plus( $tt_choice['price'] ) . ')' : '' ) ); ?></option>
								<?php endforeach; ?>
							</select>
						</div>
						<?php echo $tt_help; // phpcs:ignore WordPress.Security.EscapeOutput ?>
					</div>

				<?php elseif ( 'text' === $tt_opt['type'] ) : ?>
					<div class="tt-field tt-field--text">
						<div class="tt-field__head">
							<label class="tt-field__label" for="<?php echo esc_attr( $tt_id ); ?>"><?php echo esc_html( $tt_opt['label'] ); ?> <span class="tt-optional"><?php esc_html_e( 'optional', 'terra-thread' ); ?></span></label>
							<span class="tt-field__price" data-tt-price-for="<?php echo esc_attr( $tt_opt['key'] ); ?>"><?php echo esc_html( $tt_plus( $tt_opt['price'] ) ); ?></span>
						</div>
						<input
							type="text"
							id="<?php echo esc_attr( $tt_id ); ?>"
							name="<?php echo esc_attr( $tt_opt['key'] ); ?>"
							maxlength="<?php echo (int) $tt_opt['maxlength']; ?>"
							placeholder="<?php echo esc_attr( $tt_opt['placeholder'] ); ?>"
							autocomplete="off"
							spellcheck="false"
							<?php echo $tt_help ? 'aria-describedby="' . esc_attr( $tt_id ) . '-help"' : ''; ?>
							<?php
							if ( ! $tt_engraved ) {
								echo 'data-tt-engrave';
								$tt_engraved = true;
							}
							?>
						>
						<div class="tt-field__meta">
							<?php echo $tt_help; // phpcs:ignore WordPress.Security.EscapeOutput ?>
							<span class="tt-counter" data-tt-counter-for="<?php echo esc_attr( $tt_opt['key'] ); ?>" aria-hidden="true">0 / <?php echo (int) $tt_opt['maxlength']; ?></span>
						</div>
						<p class="tt-field__error" data-tt-error-for="<?php echo esc_attr( $tt_opt['key'] ); ?>" hidden></p>
					</div>

				<?php elseif ( 'checkbox' === $tt_opt['type'] ) : ?>
					<label class="tt-check">
						<input type="checkbox" name="<?php echo esc_attr( $tt_opt['key'] ); ?>" value="1">
						<span class="tt-check__box" aria-hidden="true"></span>
						<span class="tt-check__label"><?php echo esc_html( $tt_opt['label'] ); ?></span>
						<span class="tt-field__price"><?php echo esc_html( $tt_plus( $tt_opt['price'] ) ); ?></span>
					</label>
				<?php endif; ?>
			<?php endforeach; ?>

			<div class="tt-buy">
				<div class="tt-qty" role="group" aria-label="<?php esc_attr_e( 'Quantity', 'terra-thread' ); ?>">
					<button type="button" class="tt-qty__btn" data-tt-step="-1" aria-label="<?php esc_attr_e( 'Decrease quantity', 'terra-thread' ); ?>">−</button>
					<input class="tt-qty__input" type="number" name="quantity" value="1" min="1" max="<?php echo (int) $tt_max_qty; ?>" inputmode="numeric" aria-label="<?php esc_attr_e( 'Quantity', 'terra-thread' ); ?>" <?php disabled( $tt_sold_out ); ?>>
					<button type="button" class="tt-qty__btn" data-tt-step="1" aria-label="<?php esc_attr_e( 'Increase quantity', 'terra-thread' ); ?>">+</button>
				</div>
				<button type="submit" class="tt-button tt-button--primary tt-buy__submit" data-tt-add <?php disabled( $tt_sold_out ); ?>>
					<?php if ( $tt_sold_out ) : ?>
						<?php esc_html_e( 'Sold out', 'terra-thread' ); ?>
					<?php else : ?>
						<span><?php esc_html_e( 'Add to bag', 'terra-thread' ); ?></span>
						<span class="tt-buy__total" data-tt-total><?php echo esc_html( Theme\money( $tt_product['price'] ) ); ?></span>
					<?php endif; ?>
				</button>
			</div>

			<details class="tt-breakdown">
				<summary><?php esc_html_e( 'Price breakdown', 'terra-thread' ); ?></summary>
				<ul class="tt-breakdown__lines" data-tt-lines>
					<li><span><?php esc_html_e( 'Base price', 'terra-thread' ); ?></span><span><?php echo esc_html( Theme\money( $tt_product['price'] ) ); ?></span></li>
				</ul>
			</details>

			<p class="tt-form-status" role="status" aria-live="polite" data-tt-status></p>
		</form>

		<dl class="tt-specs">
			<?php
			foreach ( array(
				__( 'Lead time', 'terra-thread' )  => $tt_product['lead_time'],
				__( 'Dimensions', 'terra-thread' ) => $tt_product['dimensions'],
				__( 'Care', 'terra-thread' )       => $tt_product['care'],
				__( 'SKU', 'terra-thread' )        => $tt_product['sku'],
			) as $tt_label => $tt_value ) :
				if ( $tt_value ) :
					?>
					<div><dt><?php echo esc_html( $tt_label ); ?></dt><dd><?php echo esc_html( $tt_value ); ?></dd></div>
					<?php
				endif;
			endforeach;
			?>
		</dl>
	</div>
</section>
