<?php
/**
 * The file that defines the class that register novac as a payment gateway on the cart and checkout block.
 *
 * A class that defines a block type.
 *
 * @link       https://developer.novacpayment.com
 * @since      1.0.0
 *
 * @package    Novac/WooCommerce
 * @subpackage Novac/WooCommerce/block
 */

defined( 'ABSPATH' ) || exit;

use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;

/**
 * Class Novac_Block_Support.
 *
 * @since 2.3.2
 * @extends AbstractPaymentMethodType
 * @package Novac
 */
final class Novac_Block_Support extends AbstractPaymentMethodType {
    /**
     * Name of the payment method.
     *
     * @var string
     */
    protected $name = 'novac';

    /**
     * Settings from the WP options table
     *
     * @var WC_Payment_Gateway
     */
    protected $gateway;

    /**
     * Initialize the Block.
     *
     * @inheritDoc
     */
    public function initialize() {
        $this->settings = get_option( 'woocommerce_novac_settings', array() );

        if ( version_compare( WC_VERSION, '6.9.1', '<' ) ) {
            // For backwards compatibility.
            if ( ! class_exists( 'Novac_Payment_Gateway' ) ) {
                require_once dirname( NOVAC_WOO_PLUGIN_FILE ) . '/inc/class-novac-payment-gateway.php';
            }

            $this->gateway = new Novac_Payment_Gateway();
        } else {
            $gateways      = WC()->payment_gateways->payment_gateways();
            $this->gateway = $gateways[ $this->name ] ?? null;
        }
    }

    /**
     * Returns if this payment method should be active. If false, the scripts will not be enqueued.
     *
     * @return boolean
     */
    public function is_active(): bool {
        if ( version_compare( WC_VERSION, '6.9.0', '>' ) ) {
            $gateways = WC()->payment_gateways->payment_gateways();

            if ( ! isset( $gateways[ $this->name ] ) ) {
                return false;
            }
        }

        if ( ! $this->gateway instanceof WC_Payment_Gateway ) {
            return false;
        }

        // Defers to the gateway's is_available(), so the block checkout hides
        // Novac under the same conditions as the classic checkout.
        return $this->gateway->is_available();
    }

    /**
     * Returns an array of supported features.
     *
     * @return string[]
     */
    public function get_supported_features(): array {
        if ( ! $this->gateway instanceof WC_Payment_Gateway ) {
            return array();
        }

        return $this->gateway->supports;
    }

    /**
     * Returns an array of scripts/handles to be registered for this payment method.
     *
     * @return array
     */
    public function get_payment_method_script_handles(): array {

        $asset_path   = dirname( NOVAC_WOO_PLUGIN_FILE ) . '/build/index.asset.php';
        $version      = NOVAC_WOO_VERSION;
        $dependencies = array();
        if ( file_exists( $asset_path ) ) {
            $asset        = require $asset_path;
            $version      = is_array( $asset ) && isset( $asset['version'] )
                ? $asset['version']
                : $version;
            $dependencies = is_array( $asset ) && isset( $asset['dependencies'] )
                ? $asset['dependencies']
                : $dependencies;
        }
        wp_register_script(
            'wc-novac-blocks',
            NOVAC_WOO_URL . '/build/index.js',
            array_merge( $dependencies ),
            $version,
            true
        );
        wp_set_script_translations(
            'wc-novac-blocks',
            'novac'
        );

        return array(
            'wc-novac-blocks',
        );
    }

    /**
     * Returns an array of key=>value pairs of data made available to the payment methods script.
     *
     * @return array
     */
    public function get_payment_method_data(): array {
        return array(
            'icons'       => $this->get_icons(),
            'supports'    => array_filter( $this->get_supported_features(), array( $this->gateway, 'supports' ) ),
            'isAdmin'     => is_admin(),
            'public_key'  => ( 'yes' === ( $this->settings['go_live'] ?? 'no' ) )
                ? ( $this->settings['live_public_key'] ?? '' )
                : ( $this->settings['test_public_key'] ?? '' ),
            'asset_url'   => plugins_url( 'assets', NOVAC_WOO_PLUGIN_FILE ),
            'title'       => $this->settings['title'] ?? __( 'Novac', 'novac-woo' ),
            'description' => $this->settings['description'] ?? '',
        );
    }

    /**
     * Returns an array of icons for the payment method.
     *
     * @return array
     */
    private function get_icons(): array {
        $icons_src = array(
            'visa'       => array(
                'src' => dirname( NOVAC_WOO_PLUGIN_FILE ) . '/assets/img/visa.svg',
                'alt' => __( 'Visa', 'novac-woo' ),
            ),
            'amex'       => array(
                'src' => dirname( NOVAC_WOO_PLUGIN_FILE ) . '/assets/img/amex.svg',
                'alt' => __( 'American Express', 'novac-woo' ),
            ),
            'mastercard' => array(
                'src' => dirname( NOVAC_WOO_PLUGIN_FILE ) . '/assets/img/mastercard.svg',
                'alt' => __( 'Mastercard', 'novac-woo' ),
            ),
        );

        if ( 'USD' === get_woocommerce_currency() ) {
            $icons_src['discover'] = array(
                'src' => dirname( NOVAC_WOO_PLUGIN_FILE ) . '/assets/img/discover.svg',
                'alt' => _x( 'Discover', 'Name of credit card', 'novac-woo' ),
            );
            $icons_src['jcb']      = array(
                'src' => dirname( NOVAC_WOO_PLUGIN_FILE ) . '/assets/img/jcb.svg',
                'alt' => __( 'JCB', 'novac-woo' ),
            );
            $icons_src['diners']   = array(
                'src' => dirname( NOVAC_WOO_PLUGIN_FILE ) . '/assets/img/diners.svg',
                'alt' => __( 'Diners', 'novac-woo' ),
            );
        }
        return $icons_src;
    }
}