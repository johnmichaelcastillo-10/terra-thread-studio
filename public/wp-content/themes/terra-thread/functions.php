<?php
/**
 * Terra & Thread — block theme bootstrap.
 *
 * Presentation only: data, pricing and bookings live in the terra-thread-core
 * plugin. Every dynamic block degrades to a notice if the plugin is inactive.
 */

namespace TerraThread\Theme;

defined( 'ABSPATH' ) || exit;

const VERSION = '1.0.0';

require_once __DIR__ . '/inc/template-tags.php';

add_action( 'after_setup_theme', __NAMESPACE__ . '\setup' );
add_action( 'init', __NAMESPACE__ . '\register_assets', 5 );
add_action( 'init', __NAMESPACE__ . '\register_blocks' );
add_action( 'wp_enqueue_scripts', __NAMESPACE__ . '\enqueue_front' );
add_action( 'enqueue_block_editor_assets', __NAMESPACE__ . '\enqueue_editor' );
add_filter( 'wp_resource_hints', __NAMESPACE__ . '\font_preconnect', 10, 2 );
add_filter( 'block_categories_all', __NAMESPACE__ . '\block_category' );
add_action( 'wp_head', __NAMESPACE__ . '\favicon', 2 );
add_action( 'wp_head', __NAMESPACE__ . '\theme_before_paint', 0 );

/**
 * A theme chosen with the header toggle applies before first paint, so a dark choice never
 * flashes light. Blocking and inline on purpose; the static exporter hashes it into the CSP.
 * studio.js reads and writes the same key.
 */
function theme_before_paint(): void {
	wp_print_inline_script_tag(
		"try{var t=localStorage.getItem('tt-theme');if(t==='light'||t==='dark')document.documentElement.dataset.theme=t}catch(e){}"
	);
}

// Every current browser renders emoji natively; WordPress's emoji loader also starts a
// blob: worker that the static site's Content-Security-Policy blocks.
// Unhooking the deprecated print_emoji_styles back-compat hook (not wp_enqueue_emoji_styles)
// is what stops the styles without triggering a deprecation notice.
remove_action( 'wp_head', 'print_emoji_detection_script', 7 );
remove_action( 'wp_print_styles', 'print_emoji_styles' );
remove_action( 'admin_print_scripts', 'print_emoji_detection_script' );
remove_action( 'admin_print_styles', 'print_emoji_styles' );
add_filter( 'emoji_svg_url', '__return_false' );

function favicon(): void {
	if ( ! has_site_icon() ) {
		printf( '<link rel="icon" href="%s" type="image/svg+xml">' . "\n", esc_url( get_theme_file_uri( 'assets/favicon.svg' ) ) );
	}
}

function setup(): void {
	add_theme_support( 'wp-block-styles' );
	add_theme_support( 'editor-styles' );
	add_editor_style( array( fonts_url(), 'style.css' ) );
	remove_theme_support( 'core-block-patterns' );
}

function fonts_url(): string {
	return 'https://fonts.googleapis.com/css2?family=Fraunces:ital,opsz,wght@0,9..144,300..600;1,9..144,300..500&family=Instrument+Sans:wght@400;500;600&display=swap';
}

function asset_version( string $relative ): string {
	$path = get_theme_file_path( $relative );
	return file_exists( $path ) ? VERSION . '.' . filemtime( $path ) : VERSION;
}

/**
 * Script handles are registered early so block.json can reference them as
 * viewScript and WordPress only loads them on pages that render the block.
 */
function register_assets(): void {
	wp_register_style( 'tt-fonts', fonts_url(), array(), null ); // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion
	wp_register_style( 'tt-theme', get_stylesheet_uri(), array( 'tt-fonts' ), asset_version( 'style.css' ) );

	$scripts = array(
		'tt-studio'       => array( 'assets/js/studio.js', array() ),
		'tt-configurator' => array( 'assets/js/configurator.js', array( 'tt-studio' ) ),
		'tt-booking'      => array( 'assets/js/booking.js', array( 'tt-studio' ) ),
	);

	foreach ( $scripts as $handle => [ $file, $deps ] ) {
		wp_register_script(
			$handle,
			get_theme_file_uri( $file ),
			$deps,
			asset_version( $file ),
			array( 'strategy' => 'defer', 'in_footer' => true )
		);
	}

	wp_add_inline_script(
		'tt-studio',
		'window.ttStudio = ' . wp_json_encode(
			array(
				'rest'     => esc_url_raw( rest_url( 'tt/v1/' ) ),
				'currency' => 'USD',
				'locale'   => str_replace( '_', '-', get_locale() ),
				'shopUrl'  => get_post_type_archive_link( 'tt_product' ) ?: home_url( '/shop/' ),
			)
		) . ';',
		'before'
	);
}

function enqueue_front(): void {
	wp_enqueue_style( 'tt-theme' );
	wp_enqueue_script( 'tt-studio' ); // Bag drawer lives in the header on every page.
}

function enqueue_editor(): void {
	wp_enqueue_script(
		'tt-editor',
		get_theme_file_uri( 'assets/js/editor.js' ),
		array( 'wp-blocks', 'wp-element', 'wp-server-side-render', 'wp-block-editor' ),
		asset_version( 'assets/js/editor.js' ),
		true
	);
	wp_add_inline_script( 'tt-editor', 'window.ttBlocks = ' . wp_json_encode( block_names() ) . ';', 'before' );
}

function block_names(): array {
	return array_map( fn( $dir ) => 'terra/' . basename( $dir ), glob( __DIR__ . '/blocks/*', GLOB_ONLYDIR ) ?: array() );
}

function register_blocks(): void {
	foreach ( glob( __DIR__ . '/blocks/*', GLOB_ONLYDIR ) ?: array() as $dir ) {
		register_block_type( $dir );
	}
}

function block_category( array $categories ): array {
	array_unshift( $categories, array( 'slug' => 'terra-thread', 'title' => __( 'Terra & Thread', 'terra-thread' ) ) );
	return $categories;
}

function font_preconnect( array $urls, string $relation ): array {
	if ( 'preconnect' === $relation ) {
		$urls[] = array( 'href' => 'https://fonts.gstatic.com', 'crossorigin' );
	}
	return $urls;
}
