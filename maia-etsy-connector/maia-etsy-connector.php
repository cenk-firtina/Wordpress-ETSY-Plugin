<?php
/**
 * Plugin Name: Maia Etsy Connector
 * Plugin URI:  https://maiaconcept.com
 * Description: WooCommerce ürünlerini Etsy Open API v3 ile Etsy mağazanıza taslak olarak aktarır. Toplu aktarım, OAuth 2.0 + PKCE, kategori/kargo eşleştirme ve hata kaydı içerir.
 * Version:     1.0.0
 * Author:      Cenk Fırtına
 * Text Domain: maia-etsy-connector
 * Domain Path: /languages
 * Requires PHP: 8.2
 * Requires at least: 6.2
 * WC requires at least: 8.0
 * Requires Plugins: woocommerce
 *
 * @package MaiaEtsyConnector
 */

defined( 'ABSPATH' ) || exit;

define( 'MEC_VERSION', '1.0.0' );
define( 'MEC_FILE', __FILE__ );
define( 'MEC_PATH', plugin_dir_path( __FILE__ ) );
define( 'MEC_URL', plugin_dir_url( __FILE__ ) );
define( 'MEC_BASENAME', plugin_basename( __FILE__ ) );

// Class autoloader: MEC_Product_Sync -> includes|admin/class-mec-product-sync.php
spl_autoload_register(
	static function ( $class ) {
		if ( 0 !== strpos( $class, 'MEC_' ) ) {
			return;
		}
		$slug = strtolower( str_replace( '_', '-', substr( $class, 4 ) ) );
		foreach ( array( 'includes', 'admin' ) as $dir ) {
			$path = MEC_PATH . $dir . '/class-mec-' . $slug . '.php';
			if ( is_readable( $path ) ) {
				require_once $path;
				return;
			}
		}
	}
);

/**
 * HPOS compatibility declaration.
 */
add_action(
	'before_woocommerce_init',
	static function () {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', MEC_FILE, true );
		}
	}
);

/**
 * Boot the plugin once WooCommerce is loaded, so WC CRUD and Action Scheduler exist.
 */
add_action(
	'plugins_loaded',
	static function () {
		if ( ! class_exists( 'WooCommerce' ) ) {
			add_action(
				'admin_notices',
				static function () {
					echo '<div class="notice notice-error"><p>';
					echo esc_html__( 'Maia Etsy Connector için WooCommerce etkin olmalıdır.', 'maia-etsy-connector' );
					echo '</p></div>';
				}
			);
			return;
		}
		load_plugin_textdomain( 'maia-etsy-connector', false, dirname( MEC_BASENAME ) . '/languages' );
		MEC_Plugin::instance();
	}
);

// Activation: create the log table and store the DB schema version.
register_activation_hook( MEC_FILE, array( 'MEC_Logger', 'install_table' ) );
