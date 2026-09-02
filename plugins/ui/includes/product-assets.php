<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function pnk_product_enqueue_assets() {
	if ( ! function_exists( 'pnk_product_route' ) || pnk_product_route() === null ) {
		return;
	}

	wp_enqueue_style(
		'presentonika-fonts',
		'https://fonts.googleapis.com/css2?family=Manrope:wght@500;700;800&family=Inter:wght@400;500;600;700&display=swap',
		array(),
		null
	);

	wp_enqueue_style(
		'presentonika-product',
		PRESENTONIKA_UI_URL . 'assets/css/presentonika-product.css',
		array( 'presentonika-ui', 'presentonika-fonts' ),
		PRESENTONIKA_UI_VERSION
	);

	wp_enqueue_script(
		'presentonika-product',
		PRESENTONIKA_UI_URL . 'assets/js/presentonika-product.js',
		array(),
		PRESENTONIKA_UI_VERSION,
		true
	);
}
add_action( 'wp_enqueue_scripts', 'pnk_product_enqueue_assets', 99 );
