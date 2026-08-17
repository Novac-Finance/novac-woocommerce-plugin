<?php
/**
 * Plugin Name: Novac Woo
 * Plugin URI: https://developer.novacpayment.com
 * Description: This plugin is the official plugin of Novac.
 * Version: 1.0.2
 * Author: Novac
 * Author URI: https://www.app.novacpayment.com
 * Developer: Novac Developers
 * Developer URI: https://developer.novacpayment.com
 * Text Domain: novac-woo
 * Domain Path: /languages
 *
 * WC requires at least: 2.2
 * WC tested up to: 2.3
 * Requires at least: 5.8
 * Requires PHP: 7.4
 * Requires Plugins: woocommerce
 *
 * License: GNU General Public License v3.0
 * License URI: http://www.gnu.org/licenses/gpl-3.0.html
 *
 * @package NovacWoo
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

if ( ! defined( 'NOVAC_WOO_PLUGIN_FILE' ) ) {
    define( 'NOVAC_WOO_PLUGIN_FILE', __FILE__ );
}

/**
 * Add the Settings link to the plugin
 *
 * @param  array $links Existing links on the plugin page.
 *
 * @return array Existing links with our settings link added
 */
function novac_woo_plugin_action_links( array $links ): array {

    $novac_settings_url = esc_url( get_admin_url( null, 'admin.php?page=wc-admin&path=%2Fnovac' ) );
    array_unshift( $links, "<a title='Novac WooCommerce Settings Page' href='$novac_settings_url'>Setup</a>" );

    return $links;
}
add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), 'novac_woo_plugin_action_links' );

/**
 * Initialize Novac WooCommerce.
 */
function novac_woo_bootstrap() {
    if ( ! class_exists( 'WooCommerce' ) ) {
        return;
    }

    if ( ! class_exists( 'Novac' ) ) {
        include_once dirname( NOVAC_WOO_PLUGIN_FILE ) . '/inc/class-novac.php';
        // Global for backwards compatibility.
        $GLOBALS['novac'] = Novac::instance();
    }
}

add_action( 'plugins_loaded', 'novac_woo_bootstrap', 99 );

/**
 * Run a queued webhook event.
 *
 * Registered here rather than in the gateway constructor: Action Scheduler runs
 * jobs in a separate request where WooCommerce is loaded but the payment
 * gateways are not necessarily instantiated, so a callback added from the
 * constructor would never fire. Asking WC for its gateways instantiates them.
 *
 * @param string $txn_ref   Transaction reference.
 * @param int    $order_id  WooCommerce order id.
 * @param string $status    Status carried by the webhook.
 * @param string $claim_key Idempotency key.
 * @return void
 */
function novac_woo_run_queued_webhook( $txn_ref, $order_id, $status = '', $claim_key = '' ) {
    if ( ! function_exists( 'WC' ) ) {
        return;
    }

    $gateways = WC()->payment_gateways()->payment_gateways();

    if ( isset( $gateways['novac'] ) && method_exists( $gateways['novac'], 'process_webhook_event' ) ) {
        $gateways['novac']->process_webhook_event( (string) $txn_ref, (int) $order_id, (string) $status, (string) $claim_key );
    }
}

add_action( 'novac_process_webhook', 'novac_woo_run_queued_webhook', 10, 4 );

/**
 * Expire a webhook idempotency claim.
 *
 * @param string $claim_key Idempotency key.
 * @return void
 */
function novac_woo_release_webhook_claim( $claim_key ) {
    delete_option( (string) $claim_key );
}

add_action( 'novac_release_webhook_claim', 'novac_woo_release_webhook_claim', 10, 1 );

/**
 * Register the admin JS.
 */
function novac_woo_add_extension_register_script() {

    if ( ! class_exists( 'WooCommerce' ) ) {
        return;
    }

    if ( ! class_exists( 'Automattic\WooCommerce\Admin\Loader' ) && version_compare( WC_VERSION, '6.3', '<' ) && ! \Automattic\WooCommerce\Admin\Loader::is_admin_or_embed_page() ) {
        return;
    }

    if ( ! class_exists( 'Automattic\WooCommerce\Admin\Loader' ) && version_compare( WC_VERSION, '6.3', '>=' ) && ! \Automattic\WooCommerce\Admin\PageController::is_admin_or_embed_page() ) {
        return;
    }

    $script_path       = '/build/settings.js';
    $script_asset_path = dirname( NOVAC_WOO_PLUGIN_FILE ) . '/build/settings.asset.php';
    $script_asset      = file_exists( $script_asset_path )
        ? require_once $script_asset_path
        : array(
            'dependencies' => array(),
            'version'      => NOVAC_WOO_VERSION,
        );

    wp_register_script(
        'novac-admin-js',
        plugins_url( 'build/settings.js', NOVAC_WOO_PLUGIN_FILE ),
        array_merge( array( 'wp-element', 'wp-data', 'moment', 'wp-api' ), $script_asset['dependencies'] ),
        $script_asset['version'],
        true
    );

    $novac_fallback_settings = array(
        'enabled'            => 'no',
        'go_live'            => 'no',
        'title'              => 'Novac',
        'description'        => '',
        'payment_style'      => 'redirect',
        'live_public_key'    => '',
        'live_secret_key'    => '',
        'test_public_key'    => '',
        'test_secret_key'    => '',
        'autocomplete_order' => 'no',
        'buy_now_enabled'    => 'no',
    );

    // Merge rather than replace: a store saved before a setting existed would
    // otherwise hand the settings page an object with missing fields.
    $novac_default_settings = get_option( 'woocommerce_novac_settings', array() );
    $novac_default_settings = wp_parse_args(
        is_array( $novac_default_settings ) ? $novac_default_settings : array(),
        $novac_fallback_settings
    );

    wp_localize_script(
        'novac-admin-js',
        'novacData',
        array(
            'asset_plugin_url' => plugins_url( '', NOVAC_WOO_PLUGIN_FILE ),
            'asset_plugin_dir' => plugins_url( '', NOVAC_WOO_PLUGIN_DIR ),
            'novac_logo'      => plugins_url( 'assets/img/logo.svg', NOVAC_WOO_PLUGIN_FILE ),
            'novac_defaults'  => $novac_default_settings,
            'novac_webhook'   => WC()->api_request_url( 'Novac_Payment_Webhook' ),
        )
    );

    wp_enqueue_script( 'novac-admin-js' );

    wp_register_style(
        'novac_admin_css',
        plugins_url( 'assets/admin/style/index.css', NOVAC_WOO_PLUGIN_FILE ),
        array(),
        NOVAC_WOO_VERSION
    );

    wp_enqueue_style( 'novac_admin_css' );
}

add_action( 'admin_enqueue_scripts', 'novac_woo_add_extension_register_script' );


/**
 * Register the Novac payment gateway for WooCommerce Blocks.
 *
 * @return void
 */
function novac_woocommerce_blocks_support() {
    if ( ! class_exists( 'WooCommerce' ) ) {
        return;
    }

    if ( class_exists( 'Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType' ) ) {
        require_once dirname( NOVAC_WOO_PLUGIN_FILE ) . '/inc/block/class-novac-block-support.php';
        add_action(
            'woocommerce_blocks_payment_method_type_registration',
            function ( Automattic\WooCommerce\Blocks\Payments\PaymentMethodRegistry $payment_method_registry ) {

                $payment_method_registry->register( new Novac_Block_Support() );
            }
        );
    }
}

// add woocommerce block support.
add_action( 'woocommerce_blocks_loaded', 'novac_woocommerce_blocks_support' );

add_action(
    'before_woocommerce_init',
    function () {
        if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
            \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
        }
    }
);