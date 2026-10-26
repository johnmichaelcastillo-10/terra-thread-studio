<?php
/**
 * terra/workshop-schedule
 *
 * @var array $attributes
 */

use TerraThread\Theme;

if ( ! Theme\core_ready() ) {
	echo Theme\core_missing_notice(); // phpcs:ignore WordPress.Security.EscapeOutput
	return;
}

$tt_workshops = \TerraThread\Core\Bookings\upcoming_workshops( (int) $attributes['limit'] );
$tt_layout    = 'cards' === $attributes['layout'] ? 'cards' : 'list';
// h2 right under the page's h1 (the masterclass archive), h3 under a section heading (home).
$tt_heading   = 2 === (int) ( $attributes['headingLevel'] ?? 3 ) ? 'h2' : 'h3';
?>
<div <?php echo get_block_wrapper_attributes( array( 'class' => 'tt-schedule tt-schedule--' . $tt_layout ) ); // phpcs:ignore WordPress.Security.EscapeOutput ?>>
	<?php if ( ! $tt_workshops ) : ?>
		<p class="tt-notice"><?php esc_html_e( 'New dates are being set — check back soon.', 'terra-thread' ); ?></p>
	<?php endif; ?>

	<?php foreach ( $tt_workshops as $tt_w ) : ?>
		<?php
		$tt_next      = $tt_w['next'];
		$tt_start     = strtotime( $tt_next['iso'] );
		$tt_available = array_filter( $tt_w['sessions'], fn( $s ) => ! $s['sold_out'] );
		$tt_more      = count( $tt_w['sessions'] ) - 1;
		?>
		<article class="tt-workshop">
			<a class="tt-workshop__media" href="<?php echo esc_url( $tt_w['url'] ); ?>" tabindex="-1" aria-hidden="true">
				<?php echo Theme\media( $tt_w, 'tt-portrait', array( 'alt' => '', 'sizes' => '(max-width: 781px) 100vw, 400px' ) + Theme\secondary_image_attrs( 'h2' === $tt_heading ? 2 : 3 ) ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
			</a>
			<a class="tt-workshop__date" href="<?php echo esc_url( $tt_w['url'] ); ?>" tabindex="-1" aria-hidden="true">
				<span class="tt-workshop__month"><?php echo esc_html( wp_date( 'M', $tt_start ) ); ?></span>
				<span class="tt-workshop__day"><?php echo esc_html( wp_date( 'j', $tt_start ) ); ?></span>
				<span class="tt-workshop__weekday"><?php echo esc_html( wp_date( 'D', $tt_start ) ); ?></span>
			</a>

			<div class="tt-workshop__body">
				<p class="tt-eyebrow"><?php echo esc_html( $tt_w['level'] ); ?> · <?php echo esc_html( Theme\duration_label( $tt_w['duration'] ) ); ?></p>
				<<?php echo $tt_heading; // phpcs:ignore WordPress.Security.EscapeOutput -- h2 or h3. ?> class="tt-workshop__title"><a href="<?php echo esc_url( $tt_w['url'] ); ?>"><?php echo esc_html( $tt_w['title'] ); ?></a></<?php echo $tt_heading; // phpcs:ignore WordPress.Security.EscapeOutput ?>>
				<p class="tt-workshop__meta">
					<?php
					/* translators: %s: instructor name */
					echo esc_html( sprintf( __( 'with %s', 'terra-thread' ), $tt_w['instructor'] ) );
					?>
					· <?php echo esc_html( $tt_next['label_time'] ); ?>
				</p>
				<?php if ( 'list' === $tt_layout ) : ?>
					<p class="tt-workshop__excerpt"><?php echo esc_html( $tt_w['excerpt'] ); ?></p>
				<?php endif; ?>
				<div class="tt-workshop__availability">
					<?php echo Theme\seat_meter( $tt_next['capacity'], $tt_next['seats_left'] ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
					<?php if ( $tt_more > 0 ) : ?>
						<span class="tt-workshop__more">
							<?php
							/* translators: %d: number of additional dates */
							echo esc_html( sprintf( _n( '+%d more date', '+%d more dates', $tt_more, 'terra-thread' ), $tt_more ) );
							?>
						</span>
					<?php endif; ?>
				</div>
			</div>

			<div class="tt-workshop__actions">
				<p class="tt-workshop__price"><?php echo esc_html( Theme\money( $tt_w['price'] ) ); ?> <span><?php esc_html_e( 'per seat', 'terra-thread' ); ?></span></p>
				<button
					type="button"
					class="tt-button tt-button--primary"
					data-tt-book="<?php echo (int) $tt_w['id']; ?>"
					<?php disabled( ! $tt_available ); ?>
				>
					<?php echo $tt_available ? esc_html__( 'Choose a date', 'terra-thread' ) : esc_html__( 'Fully booked', 'terra-thread' ); ?>
				</button>
				<a class="tt-link" href="<?php echo esc_url( $tt_w['url'] ); ?>"><?php esc_html_e( 'Details', 'terra-thread' ); ?></a>
			</div>
		</article>
	<?php endforeach; ?>
</div>
