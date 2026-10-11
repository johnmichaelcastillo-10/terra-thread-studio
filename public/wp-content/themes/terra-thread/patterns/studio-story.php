<?php
/**
 * Title: Studio story
 * Slug: terra-thread/studio-story
 * Categories: about
 * Description: Split section introducing the studio, with key numbers.
 */
?>
<!-- wp:group {"align":"full","className":"tt-story","layout":{"type":"constrained"}} -->
<div class="wp-block-group alignfull tt-story"><!-- wp:columns {"align":"wide","verticalAlignment":"center","className":"tt-story__cols"} -->
<div class="wp-block-columns alignwide are-vertically-aligned-center tt-story__cols"><!-- wp:column {"verticalAlignment":"center","width":"42%"} -->
<div class="wp-block-column is-vertically-aligned-center" style="flex-basis:42%"><!-- wp:image {"sizeSlug":"full","linkDestination":"none","className":"tt-arch-photo"} -->
<figure class="wp-block-image size-full tt-arch-photo"><img src="<?php echo esc_url( get_theme_file_uri( 'assets/images/studio.jpg' ) ); ?>" alt="<?php esc_attr_e( 'Studio shelves stacked with unglazed cups, jugs and bowls waiting for the kiln', 'terra-thread' ); ?>" loading="lazy"/></figure>
<!-- /wp:image --></div>
<!-- /wp:column -->

<!-- wp:column {"verticalAlignment":"center","width":"58%"} -->
<div class="wp-block-column is-vertically-aligned-center" style="flex-basis:58%"><!-- wp:paragraph {"className":"tt-eyebrow"} -->
<p class="tt-eyebrow"><?php esc_html_e( 'The studio', 'terra-thread' ); ?></p>
<!-- /wp:paragraph -->

<!-- wp:heading -->
<h2 class="wp-block-heading"><?php esc_html_e( 'Four makers, one converted dairy barn, and no shortcuts.', 'terra-thread' ); ?></h2>
<!-- /wp:heading -->

<!-- wp:paragraph -->
<p><?php esc_html_e( 'We work in small runs so every piece passes through the hands of the person who designed it. When something sells out, it is gone until the next firing, weaving or tanning — and we think that is how it should be.', 'terra-thread' ); ?></p>
<!-- /wp:paragraph -->

<!-- wp:group {"className":"tt-stats","layout":{"type":"flex","flexWrap":"wrap"}} -->
<div class="wp-block-group tt-stats"><!-- wp:paragraph {"className":"tt-stat"} -->
<p class="tt-stat"><strong>14</strong> <?php esc_html_e( 'years at the wheel', 'terra-thread' ); ?></p>
<!-- /wp:paragraph -->

<!-- wp:paragraph {"className":"tt-stat"} -->
<p class="tt-stat"><strong>80 km</strong> <?php esc_html_e( 'timber radius', 'terra-thread' ); ?></p>
<!-- /wp:paragraph -->

<!-- wp:paragraph {"className":"tt-stat"} -->
<p class="tt-stat"><strong>≤ 10</strong> <?php esc_html_e( 'makers per class', 'terra-thread' ); ?></p>
<!-- /wp:paragraph --></div>
<!-- /wp:group -->

<!-- wp:paragraph {"className":"tt-link-arrow"} -->
<p class="tt-link-arrow"><a href="<?php echo esc_url( home_url( '/about/' ) ); ?>"><?php esc_html_e( 'Meet the makers', 'terra-thread' ); ?></a></p>
<!-- /wp:paragraph --></div>
<!-- /wp:column --></div>
<!-- /wp:columns --></div>
<!-- /wp:group -->
