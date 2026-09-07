<?php
/**
 * Plugin Name: FakturaOnline pro WooCommerce
 * Description: Vystavuje faktury ve FakturaOnline po dokončení objednávky.
 * Version: 0.1.0
 * Requires at least: 6.4
 * Requires PHP: 8.0
 * Requires Plugins: woocommerce
 * Author: FakturaOnline
 * Text Domain: fakturaonline-woocommerce
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 */

defined( 'ABSPATH' ) || exit;

define( 'FO_WC_VERSION', '0.1.0' );
define( 'FO_WC_PATH', plugin_dir_path( __FILE__ ) );
define( 'FO_WC_URL', plugin_dir_url( __FILE__ ) );

require_once FO_WC_PATH . 'includes/class-fo-settings.php';
require_once FO_WC_PATH . 'includes/class-fo-api-client.php';
require_once FO_WC_PATH . 'includes/class-fo-invoice-builder.php';
require_once FO_WC_PATH . 'includes/class-fo-order-hooks.php';

// Declare HPOS compatibility — order meta is accessed only through WC_Order.
add_action( 'before_woocommerce_init', function () {
	if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
	}
} );

add_action( 'plugins_loaded', function () {
	if ( ! class_exists( 'WooCommerce' ) ) {
		add_action( 'admin_notices', function () {
			echo '<div class="notice notice-error"><p>' . esc_html__( 'FakturaOnline pro WooCommerce vyžaduje aktivní WooCommerce.', 'fakturaonline-woocommerce' ) . '</p></div>';
		} );
		return;
	}
	FO_Settings::init();
	FO_Order_Hooks::init();
} );
