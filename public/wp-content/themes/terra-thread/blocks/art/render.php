<?php
/**
 * terra/art
 *
 * @var array $attributes
 */

use TerraThread\Theme;

$tt_shape = in_array( $attributes['shape'], array( 'arch', 'round', 'plain' ), true ) ? $attributes['shape'] : 'arch';
?>
<figure <?php echo get_block_wrapper_attributes( array( 'class' => 'tt-stage tt-stage--' . $tt_shape ) ); // phpcs:ignore WordPress.Security.EscapeOutput ?>>
	<?php echo Theme\art( $attributes['art'], $attributes['tint'] ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
</figure>
