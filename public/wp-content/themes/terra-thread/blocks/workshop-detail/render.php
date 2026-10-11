<?php
/**
 * terra/workshop-detail
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

$tt_w = \TerraThread\Core\Bookings\workshop( (int) ( $block->context['postId'] ?? get_the_ID() ) );
if ( ! $tt_w ) {
	echo '<p class="tt-notice">' . esc_html__( 'Workshop details appear on single workshop pages.', 'terra-thread' ) . '</p>';
	return;
}

$tt_any_open = (bool) array_filter( $tt_w['sessions'], fn( $s ) => ! $s['sold_out'] );
?>
<section <?php echo get_block_wrapper_attributes( array( 'class' => 'tt-workshop-detail' ) ); // phpcs:ignore WordPress.Security.EscapeOutput ?>>
	<div class="tt-workshop-detail__media">
		<figure class="tt-stage tt-stage--arch tt-stage--photo">
			<?php echo Theme\media( $tt_w, 'full', array( 'loading' => 'eager', 'fetchpriority' => 'high', 'sizes' => '(max-width: 1024px) 100vw, 45vw' ) ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
		</figure>
	</div>

	<div class="tt-workshop-detail__panel">
		<nav class="tt-crumbs" aria-label="<?php esc_attr_e( 'Breadcrumb', 'terra-thread' ); ?>">
			<a href="<?php echo esc_url( get_post_type_archive_link( Core\WORKSHOP ) ); ?>"><?php esc_html_e( 'Masterclasses', 'terra-thread' ); ?></a>
		</nav>
		<h1 class="tt-configurator__title"><?php echo esc_html( $tt_w['title'] ); ?></h1>
		<p class="tt-lede"><?php echo esc_html( $tt_w['excerpt'] ); ?></p>

		<div class="tt-instructor">
			<span class="tt-instructor__avatar" aria-hidden="true"><?php echo esc_html( mb_substr( $tt_w['instructor'], 0, 1 ) ); ?></span>
			<p><strong><?php echo esc_html( $tt_w['instructor'] ); ?></strong><br><?php echo esc_html( $tt_w['instructor_bio'] ); ?></p>
		</div>

		<dl class="tt-specs tt-specs--grid">
			<div><dt><?php esc_html_e( 'Price', 'terra-thread' ); ?></dt><dd><?php echo esc_html( Theme\money( $tt_w['price'] ) ); ?> <?php esc_html_e( 'per seat', 'terra-thread' ); ?></dd></div>
			<div><dt><?php esc_html_e( 'Duration', 'terra-thread' ); ?></dt><dd><?php echo esc_html( Theme\duration_label( $tt_w['duration'] ) ); ?></dd></div>
			<div><dt><?php esc_html_e( 'Level', 'terra-thread' ); ?></dt><dd><?php echo esc_html( $tt_w['level'] ); ?></dd></div>
			<div><dt><?php esc_html_e( 'Class size', 'terra-thread' ); ?></dt><dd>
				<?php
				/* translators: %d: seats */
				echo esc_html( sprintf( __( 'Up to %d makers', 'terra-thread' ), $tt_w['capacity'] ) );
				?>
			</dd></div>
			<div><dt><?php esc_html_e( 'Room', 'terra-thread' ); ?></dt><dd><?php echo esc_html( $tt_w['location'] ); ?></dd></div>
			<div><dt><?php esc_html_e( 'Included', 'terra-thread' ); ?></dt><dd><?php echo esc_html( $tt_w['includes'] ); ?></dd></div>
		</dl>

		<h2 class="tt-section-label"><?php esc_html_e( 'Upcoming dates', 'terra-thread' ); ?></h2>
		<?php if ( $tt_w['sessions'] ) : ?>
			<ul class="tt-dates">
				<?php foreach ( $tt_w['sessions'] as $tt_s ) : ?>
					<li class="tt-dates__item<?php echo $tt_s['sold_out'] ? ' is-sold-out' : ''; ?>">
						<div>
							<p class="tt-dates__day"><?php echo esc_html( $tt_s['label_date'] ); ?></p>
							<p class="tt-dates__time"><?php echo esc_html( $tt_s['label_time'] ); ?></p>
						</div>
						<?php echo Theme\seat_meter( $tt_s['capacity'], $tt_s['seats_left'] ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
						<button type="button" class="tt-button tt-button--ghost" data-tt-book="<?php echo (int) $tt_w['id']; ?>" data-tt-session="<?php echo (int) $tt_s['id']; ?>" <?php disabled( $tt_s['sold_out'] ); ?>>
							<?php echo $tt_s['sold_out'] ? esc_html__( 'Full', 'terra-thread' ) : esc_html__( 'Reserve', 'terra-thread' ); ?>
						</button>
					</li>
				<?php endforeach; ?>
			</ul>
			<button type="button" class="tt-button tt-button--primary tt-button--block" data-tt-book="<?php echo (int) $tt_w['id']; ?>" <?php disabled( ! $tt_any_open ); ?>>
				<?php esc_html_e( 'Open booking calendar', 'terra-thread' ); ?>
			</button>
		<?php else : ?>
			<p class="tt-notice"><?php esc_html_e( 'No dates scheduled right now — new sessions are announced monthly.', 'terra-thread' ); ?></p>
		<?php endif; ?>
	</div>
</section>
