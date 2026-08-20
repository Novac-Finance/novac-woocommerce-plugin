<?php
/**
 * Main Class of the Plugin.
 *
 * @package    Novac/WooCommerce
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

/**
 * Main Class
 *
 * @since 1.0.0
 */
class Novac {
    /**
     * Plugin version.
     *
     * @var string
     */
    public string $version = '1.0.3';

    /**
     * Plugin API version.
     *
     * @var string
     */
    public string $api_version = 'v2';

    /**
     * Plugin Instance.
     *
     * @var Novac|null
     */
    public static ?Novac $instance = null;

    /**
     * Novac Constructor
     */
    public function __construct() {
        $this->define_constants();
        $this->includes();
        $this->init();
    }

    /**
     * Main Instance.
     */
    public static function instance(): Novac {
        self::$instance = is_null( self::$instance ) ? new self() : self::$instance;

        return self::$instance;
    }

    /**
     * Define general constants.
     *
     * @param string      $name  constant name.
     * @param string|bool $value constant value.
     */
    private function define( string $name, $value ) {
        if ( ! defined( $name ) ) {
            define( $name, $value );
        }
    }

    /**
     * Define Novac Constants.
     */
    private function define_constants() {
        $this->define( 'NOVAC_WOO_VERSION', $this->version );
        $this->define( 'NOVAC_WOO_MINIMUM_WP_VERSION', '5.8' );
        $this->define( 'NOVAC_WOO_PLUGIN_URL', plugin_dir_url( NOVAC_WOO_PLUGIN_FILE ) );
        $this->define( 'NOVAC_WOO_PLUGIN_BASENAME', plugin_basename( NOVAC_WOO_PLUGIN_FILE ) );
        $this->define( 'NOVAC_WOO_PLUGIN_DIR', plugin_dir_path( NOVAC_WOO_PLUGIN_FILE ) );
        $this->define( 'NOVAC_WOO_DIR_PATH', plugin_dir_path( NOVAC_WOO_PLUGIN_FILE ) );
        $this->define( 'NOVAC_WOO_MIN_WC_VER', '6.9.1' );
        $this->define( 'NOVAC_WOO_URL', trailingslashit( plugins_url( '/', NOVAC_WOO_PLUGIN_FILE ) ) );
        $this->define( 'NOVAC_WOO_ALLOWED_WEBHOOK_IP_ADDRESS', '18.233.137.110');
        $this->define( 'NOVAC_WOO_EPSILON', 0.01 );
    }

    /**
     * Initialize the plugin.
     * Checks for an existing instance of this class in the global scope and if it doesn't find one, creates it.
     *
     * @return void
     */
    private function init() {
        $notices = new Novac_Notices();

        // Check if WooCommerce is active.
        if ( ! class_exists( 'WooCommerce' ) ) {
            add_action( 'admin_notices', array( $notices, 'woocommerce_not_installed' ) );
            return;
        }

        if ( ! class_exists( 'WC_Payment_Gateway' ) ) {
            return;
        }

        if ( version_compare( WC_VERSION, NOVAC_WOO_MIN_WC_VER, '<' ) ) {
            add_action( 'admin_notices', array( $notices, 'woocommerce_wc_not_supported' ) );
            return;
        }

        register_activation_hook( __FILE__, array( $this, 'activate' ) );
        register_deactivation_hook( __FILE__, array( $this, 'deactivate' ) );

        add_action(
            'admin_print_styles',
            function () {
                // using admin_print_styles.
                $image_url = plugin_dir_url( NOVAC_WOO_PLUGIN_FILE ) . 'assets/img/novac-30x30.png';
                echo '<style> .dashicons-novac {
					background-image: url("' . esc_url( $image_url ) . '");
					background-repeat: no-repeat;
					background-position: center; 
			}</style>';
            }
        );

        add_action( 'admin_menu', array( $this, 'add_wc_admin_menu' ) );
        $this->register_novac_wc_page_items();
        $this->register_payment_gateway();

        include_once NOVAC_WOO_PLUGIN_DIR . 'inc/rest-api/class-novac-settings-rest-controller.php';
        $settings__endpoint = new Novac_Settings_Rest_Controller();
        add_action( 'rest_api_init', array( $settings__endpoint, 'register_routes' ) );
    }

    /**
     * Cloning is forbidden.
     *
     * @since 1.0.0
     */
    public function __clone() {}

    /**
     * Unserializing instances of this class is forbidden.
     *
     * @since 1.0.0
     */
    public function __wakeup() {}

    /**
     * Register the WooCommerce Settings Page.
     *
     * @since 1.0.0
     */
    public function add_wc_admin_menu() {
        wc_admin_register_page(
            array(
                'id'       => 'novac-wc-page',
                'title'    => __( 'Novac', 'novac-woo' ),
                'path'     => '/novac',
                'nav_args' => array(
                    'parent'       => 'woocommerce',
                    'is_top_level' => true,
                    'menuId'       => 'plugins',
                ),
                'position' => 3,
                'icon'     => 'dashicons-novac',
            )
        );
    }

    /**
     * Include Novac Icon for Sidebar Setup.
     */

    /**
     * Include required core files used in admin and on the frontend.
     */
    public function includes() {
        // Include classes that can run on WP Freely.
        include_once dirname( NOVAC_WOO_PLUGIN_FILE ) . '/inc/notices/class-novac-notices.php';
    }

    /**
     * This handles actions on plugin activation.
     *
     * @return void
     */
    public static function activate() {
        if ( ! class_exists( 'WooCommerce' ) ) {
            $notices = new Novac_Notices();
            add_action( 'admin_notices', array( $notices, 'woocommerce_not_installed' ) );
        }
    }

    /**
     * This handles actions on plugin deactivation.
     *
     * @return void
     */
    public static function deactivate() {
        // Deactivation logic.
    }

    /**
     * Handle Novac WooCommerce Page Items.
     */
    public function register_novac_wc_page_items() {
        if ( ! method_exists( '\Automattic\WooCommerce\Admin\Features\Navigation\Menu', 'add_plugin_category' ) ||
            ! method_exists( '\Automattic\WooCommerce\Admin\Features\Navigation\Menu', 'add_plugin_item' )
        ) {
            return;
        }
        \Automattic\WooCommerce\Admin\Features\Navigation\Menu::add_plugin_category(
            array(
                'id'         => 'novac-root',
                'title'      => 'Novac',
                'capability' => 'view_woocommerce_reports',
            )
        );
        \Automattic\WooCommerce\Admin\Features\Navigation\Menu::add_plugin_item(
            array(
                'id'         => 'novac-1',
                'parent'     => 'novac-root',
                'title'      => 'Novac 1',
                'capability' => 'view_woocommerce_reports',
                'url'        => 'https://developer.novacpayment.com/',
            )
        );
        \Automattic\WooCommerce\Admin\Features\Navigation\Menu::add_plugin_item(
            array(
                'id'         => 'novac-2',
                'parent'     => 'novac-root',
                'title'      => 'Novac 2',
                'capability' => 'view_woocommerce_reports',
                'url'        => 'https://developer.novacpayment.com',
            )
        );
        \Automattic\WooCommerce\Admin\Features\Navigation\Menu::add_plugin_category(
            array(
                'id'              => 'sub-menu',
                'parent'          => 'novac-root',
                'title'           => 'Novac Menu',
                'capability'      => 'view_woocommerce_reports',
                'backButtonLabel' => 'Novac',
            )
        );
        \Automattic\WooCommerce\Admin\Features\Navigation\Menu::add_plugin_item(
            array(
                'id'         => 'sub-menu-child-1',
                'parent'     => 'sub-menu',
                'title'      => 'Sub Menu Child 1',
                'capability' => 'view_woocommerce_reports',
                'url'        => 'http//:www.google.com',
            )
        );
        \Automattic\WooCommerce\Admin\Features\Navigation\Menu::add_plugin_item(
            array(
                'id'         => 'sub-menu-child-2',
                'parent'     => 'sub-menu',
                'title'      => 'Sub Menu Child 2',
                'capability' => 'view_woocommerce_reports',
                'url'        => 'https://developer.novacpayment.com',
            )
        );
    }

    /**
     * Register Novac as a Payment Gateway.
     *
     * @return void
     */
    public function register_payment_gateway() {
        require_once dirname( NOVAC_WOO_PLUGIN_FILE ) . '/inc/class-novac-payment-gateway.php';

        add_filter( 'woocommerce_payment_gateways', array( 'Novac', 'add_gateway_to_woocommerce_gateway_list' ), 99 );
    }

    /**
     * Add the Gateway to WooCommerce
     *
     * @param  array $methods Existing gateways in WooCommerce.
     *
     * @return array Gateway list with our gateway added
     */
    public static function add_gateway_to_woocommerce_gateway_list( array $methods ): array {

        $methods[] = 'Novac_Payment_Gateway';

        return $methods;
    }

}