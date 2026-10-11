<?php
/**
 * terra/product-grid
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

$tt_args = array(
	'post_type'   => Core\PRODUCT,
	'post_status' => 'publish',
	'numberposts' => (int) $attributes['count'],
	'orderby'     => array( 'menu_order' => 'ASC', 'date' => 'DESC' ),
);

$tt_current_term = is_tax( Core\MATERIAL ) ? get_queried_object() : null;
if ( $tt_current_term ) {
	$tt_args['tax_query'] = array( array( 'taxonomy' => Core\MATERIAL, 'terms' => $tt_current_term->term_id ) ); // phpcs:ignore WordPress.DB.SlowDBQuery
}

if ( $attributes['related'] ) {
	$tt_post_id           = (int) ( $block->context['postId'] ?? get_the_ID() );
	$tt_args['exclude']   = array( $tt_post_id );
	$tt_args['orderby']   = 'rand';
	$tt_args['numberposts'] = $attributes['count'] > 0 ? (int) $attributes['count'] : 3;
}

$tt_products = array_filter( array_map( '\TerraThread\Core\Catalog\product', get_posts( $tt_args ) ) );
?>
<div <?php echo get_block_wrapper_attributes( array( 'class' => 'tt-product-grid' ) ); // phpcs:ignore WordPress.Security.EscapeOutput ?>>
	<?php if ( $attributes['showFilters'] ) : ?>
		<?php $tt_terms = get_terms( array( 'taxonomy' => Core\MATERIAL, 'hide_empty' => true ) ); ?>
		<nav class="tt-filters" aria-label="<?php esc_attr_e( 'Filter by material', 'terra-thread' ); ?>">
			<a class="tt-chip<?php echo $tt_current_term ? '' : ' is-active'; ?>" href="<?php echo esc_url( get_post_type_archive_link( Core\PRODUCT ) ); ?>"<?php echo $tt_current_term ? '' : ' aria-current="page"'; ?>><?php esc_html_e( 'All pieces', 'terra-thread' ); ?></a>
			<?php foreach ( is_array( $tt_terms ) ? $tt_terms : array() as $tt_term ) : ?>
				<?php $tt_active = $tt_current_term && $tt_current_term->term_id === $tt_term->term_id; ?>
				<a class="tt-chip<?php echo $tt_active ? ' is-active' : ''; ?>" href="<?php echo esc_url( get_term_link( $tt_term ) ); ?>"<?php echo $tt_active ? ' aria-current="page"' : ''; ?>>
					<?php echo esc_html( $tt_term->name ); ?> <span class="tt-chip__count"><?php echo (int) $tt_term->count; ?></span>
				</a>
			<?php endforeach; ?>
		</nav>
	<?php endif; ?>

	<?php if ( $tt_products ) : ?>
		<div class="tt-grid">
			<?php foreach ( $tt_products as $tt_product ) : ?>
				<?php echo Theme\product_card( $tt_product ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
			<?php endforeach; ?>
		</div>
	<?php else : ?>
		<p class="tt-notice"><?php esc_html_e( 'Nothing on the shelves here yet — new pieces come out of the kiln every few weeks.', 'terra-thread' ); ?></p>
	<?php endif; ?>
</div>
