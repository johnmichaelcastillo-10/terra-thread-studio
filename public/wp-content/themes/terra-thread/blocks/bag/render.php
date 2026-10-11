<?php
/**
 * terra/bag — the drawer contents are rendered client-side by studio.js from
 * the bag stored in localStorage.
 */
?>
<div <?php echo get_block_wrapper_attributes( array( 'class' => 'tt-bag' ) ); // phpcs:ignore WordPress.Security.EscapeOutput ?>>
	<button type="button" class="tt-bag__toggle" data-tt-bag-open aria-haspopup="dialog">
		<svg viewBox="0 0 24 24" width="20" height="20" aria-hidden="true" focusable="false"><path d="M6 8h12l-1 12H7L6 8z" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linejoin="round"/><path d="M9 8V6a3 3 0 0 1 6 0v2" fill="none" stroke="currentColor" stroke-width="1.5"/></svg>
		<span class="tt-bag__label"><?php esc_html_e( 'Bag', 'terra-thread' ); ?></span>
		<span class="tt-bag__count" data-tt-bag-count>0</span>
		<span class="screen-reader-text" data-tt-bag-sr><?php esc_html_e( '0 items', 'terra-thread' ); ?></span>
	</button>

	<dialog class="tt-drawer" data-tt-bag-drawer aria-labelledby="tt-bag-title">
		<div class="tt-drawer__inner">
			<header class="tt-drawer__head">
				<h2 id="tt-bag-title"><?php esc_html_e( 'Your bag', 'terra-thread' ); ?></h2>
				<button type="button" class="tt-icon-btn" data-tt-close aria-label="<?php esc_attr_e( 'Close bag', 'terra-thread' ); ?>">×</button>
			</header>
			<div class="tt-drawer__body" data-tt-bag-items></div>
			<footer class="tt-drawer__foot" data-tt-bag-foot>
				<p class="tt-drawer__subtotal"><span><?php esc_html_e( 'Subtotal', 'terra-thread' ); ?></span><strong data-tt-bag-subtotal>$0</strong></p>
				<p class="tt-help"><?php esc_html_e( 'Shipping and taxes are calculated at checkout.', 'terra-thread' ); ?></p>
				<button type="button" class="tt-button tt-button--primary tt-button--block" data-tt-checkout><?php esc_html_e( 'Checkout', 'terra-thread' ); ?></button>
				<p class="tt-help tt-drawer__demo" data-tt-checkout-note hidden><?php esc_html_e( 'This is a portfolio demo — payments are not connected, so no order was placed.', 'terra-thread' ); ?></p>
			</footer>
		</div>
	</dialog>
</div>
