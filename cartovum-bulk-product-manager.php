<?php
/**
 * Plugin Name: Cartovum Bulk Product Manager
 * Description: A WooCommerce product management tool that makes finding products, bulk editing stock status and attributes, and everyday product administration faster and easier. Changes run in small batches, every run is logged, and any run can be put back.
 * Version:     1.3.9
 * Author:      Cartovum Agency
 * Author URI:  https://cartovumagency.com
 * License:     GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: cartovum-bulk-product-manager
 * Requires PHP: 7.4
 * Requires at least: 6.0
 * WC requires at least: 6.0
 * WC tested up to: 11.1
 */

defined( 'ABSPATH' ) || exit;

define( 'SWBM_VERSION', '1.3.9' );

/**
 * Converting typed attributes. While true, the dry run works and nothing is written.
 * The store owner approved the dry run on 16 Sept 2026, so conversion is on, limited by the stage below.
 */
define( 'SWBM_CONVERT_DRY_RUN_ONLY', false );

/**
 * Product statuses that may be converted at the current stage (comma separated).
 * Stage 6 is the 30 draft products only. Widen this only after the owner approves the next stage.
 */
define( 'SWBM_CONVERT_ALLOWED_STATUSES', 'draft' );

/**
 * Individual products that may also be converted at the current stage, whatever their status
 * (comma separated IDs). Stage 7 is these 10 live products, approved by the owner on 20 Sept 2026.
 */
define( 'SWBM_CONVERT_ALLOWED_IDS', '1102,280,147,2252,12677,81,166,401,197,65' );
define( 'SWBM_FILE', __FILE__ );
define( 'SWBM_PATH', plugin_dir_path( __FILE__ ) );
define( 'SWBM_URL', plugin_dir_url( __FILE__ ) );

/** Capability required for every screen and every AJAX endpoint. */
define( 'SWBM_CAP', 'manage_woocommerce' );

/** Products handled per AJAX request. Small on purpose: no single blocking request. */
define( 'SWBM_BATCH_SIZE', 10 );

/** Where "Contact Support" (Plugins screen) and the contact button in "View details" go. */
define( 'SWBM_SUPPORT_URL', 'https://cartovumagency.com/contact/' );

require_once SWBM_PATH . 'includes/class-swbm-query.php';
require_once SWBM_PATH . 'includes/class-swbm-jobs.php';
require_once SWBM_PATH . 'includes/class-swbm-guard.php';
require_once SWBM_PATH . 'includes/class-swbm-stock.php';
require_once SWBM_PATH . 'includes/class-swbm-attributes.php';
require_once SWBM_PATH . 'includes/class-swbm-convert.php';
require_once SWBM_PATH . 'includes/class-swbm-ajax.php';
require_once SWBM_PATH . 'includes/class-swbm-admin.php';

add_action(
	'plugins_loaded',
	function () {
		if ( ! class_exists( 'WooCommerce' ) ) {
			add_action(
				'admin_notices',
				function () {
					echo '<div class="notice notice-error"><p>' .
						esc_html__( 'Cartovum Bulk Product Manager needs WooCommerce to be active.', 'cartovum-bulk-product-manager' ) .
						'</p></div>';
				}
			);
			return;
		}

		SWBM_Admin::init();
		SWBM_Ajax::init();
	}
);

/** Declare compatibility with WooCommerce High-Performance Order Storage. This plugin never touches orders. */
add_action(
	'before_woocommerce_init',
	function () {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', SWBM_FILE, true );
		}
	}
);

/**
 * A "Contact Support" link in this plugin's row on the Plugins screen. There is no Plugin URI header, so
 * WordPress shows no "Visit plugin site" link; this is the only link after the author's name.
 * Registered outside the WooCommerce check, so it is there even when WooCommerce is not active.
 */
add_filter(
	'plugin_row_meta',
	function ( $links, $file ) {
		if ( plugin_basename( SWBM_FILE ) !== $file ) {
			return $links;
		}

		$links[] = sprintf(
			'<a href="%1$s" target="_blank" rel="noopener noreferrer">%2$s<span class="screen-reader-text"> %3$s</span></a>',
			esc_url( SWBM_SUPPORT_URL ),
			esc_html__( 'Contact Support', 'cartovum-bulk-product-manager' ),
			/* translators: accessibility text for a link that opens in a new tab */
			esc_html__( '(opens in a new tab)', 'cartovum-bulk-product-manager' )
		);

		return $links;
	},
	10,
	2
);
