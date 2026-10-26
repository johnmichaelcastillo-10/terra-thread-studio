<?php
/**
 * Title: Studio hero
 * Slug: terra-thread/hero
 * Categories: banner
 * Description: Editorial hero with headline, calls to action and an arched illustration.
 */
?>
<!-- wp:group {"align":"wide","className":"tt-hero","layout":{"type":"default"}} -->
<div class="wp-block-group alignwide tt-hero"><!-- wp:columns {"verticalAlignment":"center","className":"tt-hero__cols"} -->
<div class="wp-block-columns are-vertically-aligned-center tt-hero__cols"><!-- wp:column {"verticalAlignment":"center","width":"56%"} -->
<div class="wp-block-column is-vertically-aligned-center" style="flex-basis:56%"><!-- wp:paragraph {"className":"tt-eyebrow"} -->
<p class="tt-eyebrow"><?php esc_html_e( 'Clay · Leather · Linen · Wood', 'terra-thread' ); ?></p>
<!-- /wp:paragraph -->

<!-- wp:heading {"level":1,"className":"tt-hero__title"} -->
<h1 class="wp-block-heading tt-hero__title"><?php esc_html_e( 'Objects made slowly,', 'terra-thread' ); ?> <em><?php esc_html_e( 'by hand.', 'terra-thread' ); ?></em></h1>
<!-- /wp:heading -->

<!-- wp:paragraph {"className":"tt-hero__lede"} -->
<p class="tt-hero__lede"><?php esc_html_e( 'Wheel-thrown ceramics, saddle-stitched leather and plant-dyed linen from our studio — plus small-group masterclasses where you learn from the makers themselves.', 'terra-thread' ); ?></p>
<!-- /wp:paragraph -->

<!-- wp:buttons -->
<div class="wp-block-buttons"><!-- wp:button -->
<div class="wp-block-button"><a class="wp-block-button__link wp-element-button" href="<?php echo esc_url( home_url( '/shop/' ) ); ?>"><?php esc_html_e( 'Shop the collection', 'terra-thread' ); ?></a></div>
<!-- /wp:button -->

<!-- wp:button {"className":"is-style-outline"} -->
<div class="wp-block-button is-style-outline"><a class="wp-block-button__link wp-element-button" href="<?php echo esc_url( home_url( '/workshops/' ) ); ?>"><?php esc_html_e( 'Book a masterclass', 'terra-thread' ); ?></a></div>
<!-- /wp:button --></div>
<!-- /wp:buttons --></div>
<!-- /wp:column -->

<!-- wp:column {"verticalAlignment":"center","width":"44%","className":"tt-hero__art"} -->
<div class="wp-block-column is-vertically-aligned-center tt-hero__art" style="flex-basis:44%"><!-- wp:image {"sizeSlug":"full","linkDestination":"none","className":"tt-arch-photo"} -->
<figure class="wp-block-image size-full tt-arch-photo"><img src="<?php echo esc_url( get_theme_file_uri( 'assets/images/hero.webp' ) ); ?>" alt="<?php esc_attr_e( 'Dried poppy seed heads and small white flowers resting on a fold of grey linen', 'terra-thread' ); ?>" width="960" height="640" fetchpriority="high"/></figure>
<!-- /wp:image -->

<!-- wp:image {"sizeSlug":"full","linkDestination":"none","className":"tt-hero__accent"} -->
<figure class="wp-block-image size-full tt-hero__accent"><img src="<?php echo esc_url( get_theme_file_uri( 'assets/images/hero-accent.webp' ) ); ?>" alt="<?php esc_attr_e( 'A wood-wick candle in a glass jar seen from above, its brass lid beside it', 'terra-thread' ); ?>" width="400" height="400"/></figure>
<!-- /wp:image --></div>
<!-- /wp:column --></div>
<!-- /wp:columns --></div>
<!-- /wp:group -->
