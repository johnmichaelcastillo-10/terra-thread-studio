<?php
/**
 * Title: Material values
 * Slug: terra-thread/values
 * Categories: text
 * Description: Four short statements about materials and process.
 */

$tt_values = array(
	array( __( 'Wheel-thrown', 'terra-thread' ), __( 'Local red stoneware, fired slow in a gas kiln.', 'terra-thread' ) ),
	array( __( 'Vegetable-tanned', 'terra-thread' ), __( 'Leather that ages instead of wearing out.', 'terra-thread' ) ),
	array( __( 'Plant-dyed', 'terra-thread' ), __( 'Madder, weld and walnut from our own garden.', 'terra-thread' ) ),
	array( __( 'Traceable timber', 'terra-thread' ), __( 'Fallen hardwood from within 80 km.', 'terra-thread' ) ),
);
?>
<!-- wp:group {"align":"wide","className":"tt-values","layout":{"type":"grid","minimumColumnWidth":"14rem"}} -->
<div class="wp-block-group alignwide tt-values">
<?php foreach ( $tt_values as $tt_i => [ $tt_title, $tt_text ] ) : ?>
<!-- wp:group {"className":"tt-value","layout":{"type":"default"}} -->
<div class="wp-block-group tt-value"><!-- wp:paragraph {"className":"tt-value__num"} -->
<p class="tt-value__num"><?php echo esc_html( sprintf( '%02d', $tt_i + 1 ) ); ?></p>
<!-- /wp:paragraph -->

<!-- wp:heading {"className":"tt-value__title"} -->
<h2 class="wp-block-heading tt-value__title"><?php echo esc_html( $tt_title ); ?></h2>
<!-- /wp:heading -->

<!-- wp:paragraph -->
<p><?php echo esc_html( $tt_text ); ?></p>
<!-- /wp:paragraph --></div>
<!-- /wp:group -->
<?php endforeach; ?>
</div>
<!-- /wp:group -->
