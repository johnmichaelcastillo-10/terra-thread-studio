<?php
/**
 * terra/theme-toggle: light/dark switch, run by studio.js (hidden until then). The icon
 * shows the theme it switches to; style.css swaps it with the theme tokens.
 */
?>
<button type="button" <?php echo get_block_wrapper_attributes( array( 'class' => 'tt-theme-toggle' ) ); // phpcs:ignore WordPress.Security.EscapeOutput ?> data-tt-theme-toggle hidden
	data-label-dark="<?php esc_attr_e( 'Switch to dark theme', 'terra-thread' ); ?>"
	data-label-light="<?php esc_attr_e( 'Switch to light theme', 'terra-thread' ); ?>"
	aria-label="<?php esc_attr_e( 'Switch to dark theme', 'terra-thread' ); ?>">
	<svg class="icon-moon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M20 14.5A8 8 0 0 1 9.5 4a8 8 0 1 0 10.5 10.5Z"/></svg>
	<svg class="icon-sun" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" aria-hidden="true" focusable="false"><circle cx="12" cy="12" r="4"/><path d="M12 2.5v2M12 19.5v2M4.6 4.6l1.4 1.4M18 18l1.4 1.4M2.5 12h2M19.5 12h2M4.6 19.4 6 18M18 6l1.4-1.4"/></svg>
</button>
