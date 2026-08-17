<?php
/**
 * The file that defines the core plugin class
 *
 * A class definition that includes attributes and functions used across both the
 * public-facing side of the site and the admin area.
 *
 * @link       https://developer.novacpayment.com
 * @since      1.0.0
 *
 * @package    Novac/WooCommerce
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/util/class-novac-logger.php';
require_once __DIR__ . '/util/class-novac-validator.php';

/**
 * Novac x WooCommerce Integration Class.
 */
class Novac_Payment_Gateway extends WC_Payment_Gateway {
    /**
     * Public Key
     *
     * @var string the public key
     */
    protected string $public_key;
    /**
     * Secret Key
     *
     * @var string the secret key.
     */
    protected string $secret_key;
    /**
     * Test Public Key
     *
     * @var string the test public key.
     */
    private string $test_public_key;
    /**
     * Test Secret Key.
     *
     * @var string the test secret key.
     */
    private string $test_secret_key;
    /**
     * Live Public Key
     *
     * @var string the live public key
     */
    private string $live_public_key;
    /**
     * Go Live Status.
     *
     * @var string the go live status.
     */
    private string $go_live;
    /**
     * Live Secret Key.
     *
     * @var string the live secret key.
     */
    private string $live_secret_key;
    /**
     * Auto Complete Order.
     *
     * @var false|mixed|null
     */
    private $auto_complete_order;

    /**
     * Buy Now Button enabled.
     *
     * @var string yes|no
     */
    private string $buy_now_enabled;
    /**
     * Logger
     *
     * @var WC_Logger the logger.
     */
    private Novac_Logger $logger;

    /**
     * Base Url
     *
     * @var string the base url
     */
    private string $base_url;

    /**
     * Payment Style
     *
     * @var string the payment style
     */
    private string $payment_style;

    /**
     * Country
     *
     * @var string the country
     */
    private string $country;

    /**
     * Constructor.
     *
     * @return void
     */
    public function __construct() {
        /**
         * Filters the Novac API base URL.
         *
         * Lets a staging or test environment point the gateway at a stand-in
         * without patching the plugin. Must keep the trailing slash.
         *
         * @param string $base_url The API base URL, with trailing slash.
         * @since 1.0.2
         */
        $this->base_url           = trailingslashit( apply_filters( 'novac_woo_api_base_url', 'https://api.novacpayment.com/api/v1/' ) );
        $this->id                 = 'novac';
        $this->icon               = plugins_url( 'assets/img/logo.png', NOVAC_WOO_PLUGIN_FILE );
        $this->has_fields         = false;
        $this->method_title       = 'Novac';
        $this->method_description = 'Novac ' . __( 'allows you to receive payments in multiple currencies.', 'novac-woo' );

        $this->init_form_fields();
        $this->init_settings();

        $this->title               = $this->get_option( 'title' );
        $this->description         = $this->get_option( 'description' );
        $this->enabled             = $this->get_option( 'enabled' );
        $this->test_public_key     = $this->get_option( 'test_public_key' );
        $this->test_secret_key     = $this->get_option( 'test_secret_key' );
        $this->live_public_key     = $this->get_option( 'live_public_key' );
        $this->live_secret_key     = $this->get_option( 'live_secret_key' );
        $this->auto_complete_order = $this->get_option( 'autocomplete_order' );
        $this->go_live             = $this->get_option( 'go_live' );
        $this->payment_style       = $this->get_option( 'payment_style' );
        $this->buy_now_enabled     = $this->get_option( 'buy_now_enabled', 'no' );
        $this->country             = '';
        $this->supports            = array(
            'products',
        );

        add_action( 'admin_notices', array( $this, 'admin_notices' ) );
        add_action( 'woocommerce_receipt_' . $this->id, array( $this, 'receipt_page' ) );

        // Tell the customer why Novac is missing before they try to pay, rather than after.
        add_action( 'woocommerce_before_checkout_form', array( $this, 'minimum_amount_notice' ) );
        add_action( 'woocommerce_before_cart', array( $this, 'minimum_amount_notice' ) );
        add_action( 'woocommerce_api_wc_novac_payment_gateway', array( $this, 'novac_verify_payment' ) );

        // Webhook listener/API hook.
        add_action( 'woocommerce_api_novac_payment_webhook', array( $this, 'novac_notification_handler' ) );

        // Buy Now button.
        if ( 'yes' === $this->buy_now_enabled ) {
            add_action( 'woocommerce_after_add_to_cart_button', array( $this, 'render_buy_now_button' ) );
            add_filter( 'woocommerce_add_to_cart_validation', array( $this, 'add_buy_now_validation' ), 10, 3 );
            add_filter( 'woocommerce_add_to_cart_redirect', array( $this, 'handle_buy_now_redirect' ), 99 );
            add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_buy_now_script' ) );
            add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_checkout_preselect_script' ) );
        }

        if ( is_admin() ) {
            add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
            add_filter( 'woocommerce_order_actions', array( $this, 'add_novac_requery_action' ) );
            add_action( 'woocommerce_order_action_novac_requery_transaction', array( $this, 'process_novac_requery_action' ) );
        }

        $this->public_key = $this->test_public_key;
        $this->secret_key = $this->test_secret_key;

        if ( 'yes' === $this->go_live ) {
            $this->public_key = $this->live_public_key;
            $this->secret_key = $this->live_secret_key;
        }
        $this->logger = Novac_Logger::instance();

//        add_action( 'wp_enqueue_scripts', array( $this, 'payment_scripts' ) );
    }

    /**
     * Whether the gateway should be offered at checkout.
     *
     * Runs before the payment method is rendered, so configuration problems and
     * unchargeable cart totals hide the gateway instead of surfacing as a
     * generic failure once the customer has already clicked pay.
     *
     * @return bool
     */
    public function is_available() {
        if ( ! parent::is_available() ) {
            return false;
        }

        if ( ! empty( $this->get_unusable_keys() ) ) {
            return false;
        }

        if ( ! $this->current_total_meets_minimum() ) {
            return false;
        }

        return true;
    }

    /**
     * Keys that are missing or malformed for the currently selected mode.
     *
     * @return string[] Setting names. Empty when the active mode is fully configured.
     */
    public function get_unusable_keys(): array {
        return Novac_Validator::get_unusable_keys( $this->settings, 'yes' === $this->go_live );
    }

    /**
     * The total that would be charged in the current request context.
     *
     * Falls back through the order being paid for, then the cart. Returns 0.0
     * when neither is available (admin screens, REST requests), which callers
     * treat as "no amount to check".
     *
     * @return array{total: float, currency: string}
     */
    protected function get_current_charge(): array {
        $order_id = absint( get_query_var( 'order-pay' ) );

        if ( $order_id > 0 ) {
            $order = wc_get_order( $order_id );

            if ( $order instanceof WC_Order ) {
                return array(
                    'total'    => (float) $order->get_total(),
                    'currency' => $order->get_currency(),
                );
            }
        }

        if ( function_exists( 'WC' ) && WC()->cart && ! WC()->cart->is_empty() ) {
            return array(
                'total'    => (float) WC()->cart->get_total( 'edit' ),
                'currency' => get_woocommerce_currency(),
            );
        }

        return array(
            'total'    => 0.0,
            'currency' => get_woocommerce_currency(),
        );
    }

    /**
     * Whether the amount in play clears the minimum for its currency.
     *
     * @return bool
     */
    protected function current_total_meets_minimum(): bool {
        $charge = $this->get_current_charge();

        return Novac_Validator::meets_minimum_amount( $charge['total'], $charge['currency'] );
    }

    /**
     * Show a notice when Novac is hidden purely because the total is too low.
     *
     * Without this the customer sees a checkout with no Novac option and no
     * explanation — the silent failure described in NOVAC-02.
     *
     * @return void
     */
    public function minimum_amount_notice(): void {
        if ( 'yes' !== $this->enabled || ! empty( $this->get_unusable_keys() ) ) {
            return;
        }

        $charge = $this->get_current_charge();

        if ( Novac_Validator::meets_minimum_amount( $charge['total'], $charge['currency'] ) ) {
            return;
        }

        $minimum = Novac_Validator::get_minimum_amount( $charge['currency'] );

        if ( null === $minimum ) {
            return;
        }

        wc_print_notice(
            sprintf(
                /* translators: 1: payment method title, e.g. "Novac". 2: formatted minimum amount, e.g. "₦100.00". */
                esc_html__( '%1$s is unavailable for this order because the total is below the %2$s minimum. Add a little more to your basket to pay with %1$s, or choose another payment method.', 'novac-woo' ),
                esc_html( $this->title ),
                wp_strip_all_tags( wc_price( $minimum, array( 'currency' => $charge['currency'] ) ) )
            ),
            'notice'
        );
    }

    /**
     * WooCommerce admin settings override.
     */
    public function admin_options() {
        ?>

        <table class="form-table">
            <tr valign="top">
                <th scope="row">
                    <label><?php esc_attr_e( 'Webhook Instruction', 'novac-woo' ); ?></label>
                </th>
                <td class="forminp forminp-text">
                    <p class="description">
                        <?php esc_attr_e( 'Please add this webhook URL and paste on the webhook section on your dashboard', 'novac-woo' ); ?><strong style="color: blue"><pre><code><?php echo esc_url( WC()->api_request_url( 'Novac_Payment_Webhook' ) ); ?></code></pre></strong><a href="https://merchant.novac.com/merchant/settings" target="_blank">Merchant Account</a>
                    </p>
                </td>
            </tr>
            <?php
            $this->generate_settings_html();
            ?>
        </table>
        <?php
    }

    /**
     * Initial gateway settings form fields.
     *
     * @return void
     */
    public function init_form_fields() {

        $this->form_fields = array(

            'enabled'            => array(
                'title'       => __( 'Enable/Disable', 'novac-woo' ),
                'label'       => __( 'Enable Novac', 'novac-woo' ),
                'type'        => 'checkbox',
                'description' => __( 'Enable Novac as a payment option on the checkout page', 'novac-woo' ),
                'default'     => 'no',
                'desc_tip'    => true,
            ),
            'title'              => array(
                'title'       => __( 'Payment method title', 'novac-woo' ),
                'type'        => 'text',
                'description' => __( 'Optional', 'novac-woo' ),
                'default'     => 'Novac',
            ),
            'description'        => array(
                'title'       => __( 'Payment method description', 'novac-woo' ),
                'type'        => 'text',
                'description' => __( 'Optional', 'novac-woo' ),
                'default'     => 'Powered by Novac: Accepts Mastercard, Visa, Verve.',
            ),
            'test_public_key'    => array(
                'title'       => __( 'Test Public Key', 'novac-woo' ),
                'type'        => 'text',
                'description' => __( 'Required! Enter your Novac test public key here', 'novac-woo' ),
                'default'     => '',
            ),
            'test_secret_key'    => array(
                'title'       => __( 'Test Secret Key', 'novac-woo' ),
                'type'        => 'password',
                'description' => __( 'Required! Enter your Novac test secret key here', 'novac-woo' ),
                'default'     => '',
            ),
            'live_public_key'    => array(
                'title'       => __( 'Live Public Key', 'novac-woo' ),
                'type'        => 'text',
                'description' => __( 'Required! Enter your Novac live public key here', 'novac-woo' ),
                'default'     => '',
            ),
            'live_secret_key'    => array(
                'title'       => __( 'Live Secret Key', 'novac-woo' ),
                'type'        => 'password',
                'description' => __( 'Required! Enter your Novac live secret key here', 'novac-woo' ),
                'default'     => '',
            ),
            'autocomplete_order' => array(
                'title'       => __( 'Autocomplete Order After Payment', 'novac-woo' ),
                'label'       => __( 'Autocomplete Order', 'novac-woo' ),
                'type'        => 'checkbox',
                'class'       => 'novac-autocomplete-order',
                'description' => __( 'If enabled, the order will be marked as complete after successful payment', 'novac-woo' ),
                'default'     => 'no',
                'desc_tip'    => true,
            ),
            'payment_style'      => array(
                'title'       => __( 'Payment Style on checkout', 'novac-woo' ),
                'type'        => 'select',
                'description' => __( 'Optional - Choice of payment style to use. Either inline or redirect. (Default: inline)', 'novac-woo' ),
                'options'     => array(
                    'redirect' => esc_html_x( 'Redirect', 'payment_style', 'novac-woo' ),
                ),
                'default'     => 'redirect',
            ),
            'go_live'            => array(
                'title'       => __( 'Mode', 'novac-woo' ),
                'label'       => __( 'Live mode', 'novac-woo' ),
                'type'        => 'checkbox',
                'description' => __( 'Check this box if you\'re using your live keys.', 'novac-woo' ),
                'default'     => 'no',
                'desc_tip'    => true,
            ),
            'buy_now_enabled'    => array(
                'title'       => __( 'Buy Now Button', 'novac-woo' ),
                'label'       => __( 'Show a "Buy Now" button on product pages', 'novac-woo' ),
                'type'        => 'checkbox',
                'description' => __( 'Adds a "Buy Now" button to product pages that skips the cart and goes directly to checkout.', 'novac-woo' ),
                'default'     => 'no',
                'desc_tip'    => true,
            ),
        );
    }

    /**
     * Order id
     *
     * @param int $order_id  Order id.
     *
     * @return array|void
     */
    public function process_payment( $order_id ) {
        // For Redirect Checkout.
        if ( 'redirect' === $this->payment_style ) {
            return $this->process_redirect_payments( $order_id );
        }

        // For inline Checkout.
        $order = wc_get_order( $order_id );

        $configuration_error = $this->get_configuration_error( $order );

        if ( null !== $configuration_error ) {
            wc_add_notice( $configuration_error['customer'], 'error' );
            $this->logger->error( 'Novac: Refusing to render the payment modal for order ' . $order_id . '. ' . $configuration_error['log'] );
            $order->add_order_note( $configuration_error['log'] );

            return array(
                'result'   => 'fail',
                'redirect' => wc_get_checkout_url(),
            );
        }

        $custom_nonce = wp_create_nonce();
        $this->logger->info( 'Rendering Payment Modal' );

        return array(
            'result'   => 'success',
            'redirect' => $order->get_checkout_payment_url( true ) . "&_wpnonce=$custom_nonce",
        );
    }

    /**
     * Get Secret Key
     *
     * @return string
     */
    public function get_secret_key(): string {
        return $this->secret_key;
    }

    /**
     * Get Public Key
     *
     * @return string
     */
    public function get_public_key(): string {
        return $this->public_key;
    }

    /**
     * Order id
     *
     * @param int $order_id  Order id.
     *
     * @return array|void
     */
    public function process_redirect_payments( $order_id ) {
        include_once __DIR__ . '/client/class-novac-request.php';

        $order = wc_get_order( $order_id );

        $configuration_error = $this->get_configuration_error( $order );

        if ( null !== $configuration_error ) {
            wc_add_notice( $configuration_error['customer'], 'error' );
            $this->logger->error( 'Novac: Refusing to start payment for order ' . $order_id . '. ' . $configuration_error['log'] );
            $order->add_order_note( $configuration_error['log'] );

            return array(
                'result'   => 'fail',
                'redirect' => $order->get_checkout_payment_url( true ),
            );
        }

        try {
            $novac_request = ( new Novac_Request() )->get_prepared_payload( $order, $this->get_secret_key() );
            update_post_meta( $order_id, '_novac_txn_ref', $novac_request['reference']);
            $this->logger->info( 'Novac: Generating Payment link for order :' . $order_id );
        } catch ( \InvalidArgumentException $novac_request_error ) {
            wc_add_notice( esc_html( $novac_request_error->getMessage() ), 'error' );
            // redirect user to check out page.
            $this->logger->error( 'Novac: Failed in Generating Payment link for order :' . $order_id . '. ' . $novac_request_error->getMessage() );
            return array(
                'result'   => 'fail',
                'redirect' => $order->get_checkout_payment_url( true ),
            );
        }

        $custom_nonce               = wp_create_nonce();
        $novac_request['callback'] = $novac_request['callback'] . '&_wpnonce=' . $custom_nonce;
//        $test_callback              = 'https://h4ea8vpiy6.sharedwithexpose.com?wc-api=WC_Novac_Payment_Gateway&order_id='. $order_id .'&_wpnonce=' . $custom_nonce;

        // Initiate Communication with Novac.

        $body = [
                'transactionReference' => $novac_request['tx_ref'],
                'amount'      => $novac_request['amount'],
                'currency'    => $novac_request['currency'] ?? 'NGN',
                'redirectUrl' => $novac_request['callback'],
                'checkoutCustomerData' => [
                        'email' => $novac_request['email'],
                        'firstName' => $novac_request['first_name'] ?? '',
                        'lastName' => $novac_request['last_name'] ?? '',
                        'phoneNumber' => $novac_request['phone'] ?? ''
                ],
                'checkoutCustomizationData' => [
                        'logoUrl' => get_site_icon_url() ?? home_url( '/favicon.ico' ),
                        'paymentMethodLogoUrl' => '',
                        'checkoutModalTitle' => $novac_request['description'],
                ]
        ];

        $body = wp_json_encode( $body );

        $this->logger->info( 'Request Object for order' . $order_id . ':' . $body );

        $args = array(
            'method'  => 'POST',
            'headers' => array(
                'Content-Type'  => 'application/json',
                'Authorization' => 'Bearer ' . $this->get_public_key(),
            ),
            'body'    => $body,
        );

        $response = wp_safe_remote_request( $this->base_url . 'initiate', $args );

        $failure = array(
            'result'   => 'fail',
            'redirect' => $order->get_checkout_payment_url( true ),
        );

        if ( is_wp_error( $response ) ) {
            wc_add_notice( esc_html__( 'We could not reach Novac to start your payment. Please try again in a moment, or choose another payment method.', 'novac-woo' ), 'error' );
            $this->logger->error( 'Novac: Unable to Connect to Novac for order ' . $order_id . '. Transport error: ' . $response->get_error_message() );
            $order->add_order_note( esc_html__( 'Novac: could not be reached to start the payment. See the Novac log for details.', 'novac-woo' ) );

            return $failure;
        }

        $http_code     = (int) wp_remote_retrieve_response_code( $response );
        $response_body = wp_remote_retrieve_body( $response );
        $decoded       = json_decode( $response_body );

        $this->logger->info( 'Novac: initiate response for order ' . $order_id . ' (HTTP ' . $http_code . '): ' . $response_body );

        // Novac rejected the request. Surface its reason instead of failing generically.
        if ( $http_code < 200 || $http_code >= 300 ) {
            $api_message = Novac_Validator::extract_api_message( $response_body );

            if ( in_array( $http_code, array( 401, 403 ), true ) ) {
                // A credential problem is the merchant's to fix, so do not blame the customer's card.
                wc_add_notice( esc_html__( 'Novac is not correctly configured for this store, so your payment could not be started. Please contact us — no payment has been taken.', 'novac-woo' ), 'error' );
                $this->logger->error( 'Novac: initiate rejected for order ' . $order_id . ' with HTTP ' . $http_code . ' — the API keys were refused. ' . $api_message );
                $order->add_order_note(
                    sprintf(
                        /* translators: 1: HTTP status code. 2: message returned by the Novac API. */
                        esc_html__( 'Novac: payment could not be started because the API rejected the store credentials (HTTP %1$s). %2$s', 'novac-woo' ),
                        $http_code,
                        esc_html( $api_message )
                    )
                );

                return $failure;
            }

            wc_add_notice(
                $api_message
                    ? sprintf(
                        /* translators: %s: message returned by the Novac API. */
                        esc_html__( 'Novac could not start this payment: %s', 'novac-woo' ),
                        esc_html( $api_message )
                    )
                    : esc_html__( 'Novac could not start this payment. Please try again, or choose another payment method.', 'novac-woo' ),
                'error'
            );
            $this->logger->error( 'Novac: initiate failed for order ' . $order_id . ' with HTTP ' . $http_code . '. ' . $api_message );
            $order->add_order_note(
                sprintf(
                    /* translators: 1: HTTP status code. 2: message returned by the Novac API. */
                    esc_html__( 'Novac: payment initiation failed (HTTP %1$s). %2$s', 'novac-woo' ),
                    $http_code,
                    esc_html( $api_message )
                )
            );

            return $failure;
        }

        $redirect_url = $decoded->data->paymentRedirectUrl ?? '';

        // A 2xx with no redirect URL is still a failure — do not send the customer to an empty URL.
        if ( empty( $redirect_url ) ) {
            $api_message = Novac_Validator::extract_api_message( $response_body );

            wc_add_notice(
                $api_message
                    ? sprintf(
                        /* translators: %s: message returned by the Novac API. */
                        esc_html__( 'Novac could not start this payment: %s', 'novac-woo' ),
                        esc_html( $api_message )
                    )
                    : esc_html__( 'Novac did not return a payment page for this order. Please try again, or choose another payment method.', 'novac-woo' ),
                'error'
            );
            $this->logger->error( 'Novac: initiate returned HTTP ' . $http_code . ' for order ' . $order_id . ' but no paymentRedirectUrl. Body: ' . $response_body );
            $order->add_order_note( esc_html__( 'Novac: no payment link was returned for this order. See the Novac log for the full response.', 'novac-woo' ) );

            return $failure;
        }

        $this->logger->info( 'Novac: redirecting customer to the payment link :' . $redirect_url );

        return array(
            'result'   => 'success',
            'redirect' => $redirect_url,
        );
    }

    /**
     * Check the gateway can actually charge this order before contacting Novac.
     *
     * Returns a customer-facing message and a separate diagnostic message for
     * the merchant, so a configuration problem is never reported to the
     * customer as a payment failure.
     *
     * @param WC_Order $order The order about to be paid for.
     * @return array{customer: string, log: string}|null Null when the order can be charged.
     */
    protected function get_configuration_error( WC_Order $order ): ?array {
        $unusable = $this->get_unusable_keys();

        if ( ! empty( $unusable ) ) {
            $labels = array_map( array( 'Novac_Validator', 'get_key_label' ), $unusable );

            return array(
                'customer' => esc_html__( 'Novac is not available for this store yet. Please contact us or choose another payment method — no payment has been taken.', 'novac-woo' ),
                'log'      => sprintf(
                    /* translators: 1: live/test mode. 2: comma-separated list of key names. */
                    esc_html__( 'Novac: the gateway is enabled in %1$s mode but these keys are missing or malformed: %2$s. Set them on the Novac settings page.', 'novac-woo' ),
                    'yes' === $this->go_live ? 'live' : 'test',
                    implode( ', ', $labels )
                ),
            );
        }

        $currency = $order->get_currency();
        $total    = (float) $order->get_total();

        if ( ! Novac_Validator::meets_minimum_amount( $total, $currency ) ) {
            $minimum = (float) Novac_Validator::get_minimum_amount( $currency );

            return array(
                'customer' => sprintf(
                    /* translators: 1: payment method title. 2: formatted minimum amount. */
                    esc_html__( '%1$s cannot process this order because the total is below the %2$s minimum. Please choose another payment method.', 'novac-woo' ),
                    esc_html( $this->title ),
                    wp_strip_all_tags( wc_price( $minimum, array( 'currency' => $currency ) ) )
                ),
                'log'      => sprintf(
                    /* translators: 1: order total. 2: minimum amount. 3: currency code. */
                    esc_html__( 'Novac: order total %1$s is below the %2$s minimum chargeable amount for %3$s.', 'novac-woo' ),
                    $total,
                    $minimum,
                    $currency
                ),
            );
        }

        return null;
    }

    /**
     * Handles admin notices
     *
     * @return void
     */
    public function admin_notices(): void {

        if ( 'yes' !== $this->enabled ) {
            return;
        }

        $unusable = $this->get_unusable_keys();

        if ( empty( $unusable ) ) {
            return;
        }

        $labels = array_map( array( 'Novac_Validator', 'get_key_label' ), $unusable );

        $message = sprintf(
            /* translators: 1: comma-separated list of key names. 2: settings page url. */
            __( '<strong>Novac is enabled but not usable.</strong> The following must be set correctly before Novac can appear at checkout: %1$s. <a href="%2$s">Review your Novac settings</a>.', 'novac-woo' ),
            esc_html( implode( ', ', $labels ) ),
            esc_url( admin_url( 'admin.php?page=wc-admin&path=%2Fnovac' ) )
        );

        printf(
            '<div class="notice notice-error"><p>%s</p></div>',
            wp_kses(
                $message,
                array(
                    'strong' => array(),
                    'a'      => array( 'href' => array() ),
                )
            )
        );
    }

    /**
     * Checkout receipt page
     *
     * @param int $order_id Order id.
     *
     * @return void
     */
    public function receipt_page( int $order_id ) {
        $order = wc_get_order( $order_id );
    }

    /**
     * Loads (enqueue) static files (js & css) for the checkout page.
     *
     * @return void
     */
    public function payment_scripts() {

        // Load only on checkout page.
        if ( ! is_checkout_pay_page() && ! isset( $_GET['key'] ) ) {
            return;
        }

        if ( ! isset( $_REQUEST['_wpnonce'] ) ) {
            return;
        }

        $expiry_message = sprintf(
        /* translators: %s: shop cart url */
            __( 'Sorry, your session has expired. <a href="%s" class="wc-backward">Return to shop</a>', 'novac-woo' ),
            esc_url( wc_get_page_permalink( 'shop' ) )
        );

        $nonce_value = sanitize_text_field( wp_unslash( $_REQUEST['_wpnonce'] ) );

        $order_key = urldecode( sanitize_text_field( wp_unslash( $_GET['key'] ) ) );
        $order_id  = absint( get_query_var( 'order-pay' ) );

        $order = wc_get_order( $order_id );

        if ( empty( $nonce_value ) || ! wp_verify_nonce( $nonce_value ) ) {

            WC()->session->set( 'refresh_totals', true );
            wc_add_notice( __( 'We were unable to process your order, please try again.', 'novac-woo' ) );
            wp_safe_redirect( $order->get_cancel_order_url() );
            return;
        }

        if ( $this->id !== $order->get_payment_method() ) {
            return;
        }

        wp_enqueue_script( 'jquery' );

        $novac_inline_link = 'https://inlinepay.novac.com/novac-inline-custom.js';

        wp_enqueue_script( 'novac', $novac_inline_link, array( 'jquery' ), NOVAC_WOO_VERSION, false );

        $checkout_frontend_script = 'assets/js/checkout.js';
        if ( 'yes' === $this->go_live ) {
            $checkout_frontend_script = 'assets/js/checkout.min.js';
        }

        wp_enqueue_script( 'novacwoo_js', plugins_url( $checkout_frontend_script, NOVAC_WOO_PLUGIN_FILE ), array( 'jquery', 'novac-woo' ), NOVAC_WOO_VERSION, false );

        $payment_args = array();

        if ( is_checkout_pay_page() && get_query_var( 'order-pay' ) ) {
            $email         = $order->get_billing_email();
            $amount        = $order->get_total();
            $txnref        = 'WOO_' . $order_id . '_' . time();
            $the_order_id  = $order->get_id();
            $the_order_key = $order->get_order_key();
            $currency      = $order->get_currency();
            $custom_nonce  = wp_create_nonce();
            $redirect_url  = WC()->api_request_url( 'Novac_Payment_Gateway' ) . '?order_id=' . $order_id . '&_wpnonce=' . $custom_nonce;

            if ( $the_order_id === $order_id && $the_order_key === $order_key ) {

                $payment_args['email']        = $email;
                $payment_args['amount']       = $amount;
                $payment_args['tx_ref']       = $txnref;
                $payment_args['currency']     = $currency;
                $payment_args['public_key']   = $this->public_key;
                $payment_args['redirect_url'] = $redirect_url;
                $payment_args['phone_number'] = $order->get_billing_phone();
                $payment_args['first_name']   = $order->get_billing_first_name();
                $payment_args['last_name']    = $order->get_billing_last_name();
                $payment_args['consumer_id']  = $order->get_customer_id();
                $payment_args['ip_address']   = $order->get_customer_ip_address();
                $payment_args['title']        = esc_html__( 'Order Payment', 'novac-woo' );
                $payment_args['description']  = 'Payment for Order: ' . $order_id;
                $payment_args['logo']         = wp_get_attachment_url( get_theme_mod( 'custom_logo' ) );
                $payment_args['checkout_url'] = wc_get_checkout_url();
                $payment_args['cancel_url']   = $order->get_cancel_order_url();
            }
            update_post_meta( $order_id, '_novac_txn_ref', $txnref );
        }

        wp_localize_script( 'novacwoo_js', 'novac_args', $payment_args );
    }

    /**
     * Check Amount Equals.
     *
     * Checks to see whether the given amounts are equal using a proper floating
     * point comparison with an Epsilon which ensures that insignificant decimal
     * places are ignored in the comparison.
     *
     * eg. 100.00 is equal to 100.0001
     *
     * @param Float $amount1 1st amount for comparison.
     * @param Float $amount2  2nd amount for comparison.
     * @since 2.3.3
     * @return bool
     */
    public function amounts_equal( $amount1, $amount2 ): bool {
        return ! ( abs( floatval( $amount1 ) - floatval( $amount2 ) ) > NOVAC_WOO_EPSILON );
    }


    /**
     * Verify payment made on the checkout page
     *
     * @return void
     */
    public function novac_verify_payment() {
        $public_key = $this->public_key;
        $secret_key = $this->secret_key;
        $logger     = $this->logger;


        if ( ! isset( $_GET['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ) ) ) {
            if ( isset( $_GET['order_id'] ) ) {
                // Handle expired Session.
                $order_id = urldecode( sanitize_text_field( wp_unslash( $_GET['order_id'] ) ) ) ?? sanitize_text_field( wp_unslash( $_GET['order_id'] ) );
                $order_id = intval( $order_id );
                $order    = wc_get_order( $order_id );

                if ( $order instanceof WC_Order ) {
                    WC()->session->set( 'refresh_totals', true );
                    wc_add_notice( __( 'We were unable to process your order, please try again.', 'novac-woo' ) );
                    $admin_note  = esc_html__( 'Attention: Customer session expired. ', 'novac-woo' ) . '<br>';
                    $admin_note .= esc_html__( 'Customer should try again. order has status is now pending payment.', 'novac-woo' );
                    $order->add_order_note( $admin_note );
                    wp_safe_redirect( $order->get_cancel_order_url() );
                }
                die();
            }
        }

        if ( isset( $_POST['reference'] ) || isset( $_GET['reference'] ) ) {
            $txn_ref  = urldecode( sanitize_text_field( wp_unslash( $_GET['reference'] ) ) ) ?? sanitize_text_field( wp_unslash( $_POST['reference'] ) );
            $o        = explode( '_', sanitize_text_field( $txn_ref ) );
            $order_id = intval( $o[1] );
            $order    = wc_get_order( $order_id );
            $sec_key  = $this->get_secret_key();

            // Communicate with Novac to confirm payment.
            $max_attempts = 3;
            $attempt      = 0;
            $success      = false;

            while ( $attempt < $max_attempts && ! $success ) {
                $args = array(
                    'method'  => 'GET',
                    'headers' => array(
                        'Content-Type'  => 'application/json',
                        'Authorization' => 'Bearer ' . $sec_key,
                    ),
                );

                $order->add_order_note( esc_html__( 'verifying the Payment of Novac...', 'novac-woo' ) );
                $this->logger->info( 'Verifying payment for order:' . $order_id . ' with transaction reference:' . $txn_ref );

                $response = wp_safe_remote_request( $this->base_url . 'checkout/' . $txn_ref . '/verify', $args );

                if ( ! is_wp_error( $response ) && wp_remote_retrieve_response_code( $response ) === 200 ) {
                    // Request successful.
                    $current_response                  = \json_decode( $response['body'] );
                    $is_cancelled_or_pending_on_novac = in_array( $current_response->data->status, array( 'cancelled', 'pending' ), true );
                    if ( isset( $_GET['status'] ) && 'cancelled' === $_GET['status'] && $is_cancelled_or_pending_on_novac ) {
                        if ( $order instanceof WC_Order ) {
                            $order->add_order_note( esc_html__( 'The customer clicked on the cancel button on Checkout.', 'novac-woo' ) );
                            $order->update_status( 'cancelled' );
                            $admin_note = esc_html__( 'Attention: Customer clicked on the cancel button on the payment gateway. We have updated the order to cancelled status. ', 'novac-woo' ) . '<br>';
                            $order->add_order_note( $admin_note );
                            $this->logger->info( 'Customer cancelled payment for order:' . $order_id . ' with transaction reference:' . $txn_ref );
                        }
                        header( 'Location: ' . wc_get_cart_url() );
                        die();
                    } else {
                        if ( 'pending' === $current_response->data->status ) {

                            if ( $order instanceof WC_Order ) {
                                $order->add_order_note( esc_html__( 'Payment Attempt Failed. Please Try Again.', 'novac-woo' ) );
                                $admin_note = esc_html__( 'Customer Payment Attempt failed. Advise customer to try again with a different Payment Method', 'novac-woo' ) . '<br>';
                                $order->add_order_note( $admin_note );
                                $this->logger->info( 'Customer failed payment for order:' . $order_id . ' with transaction reference:' . $txn_ref );
                            }
                            header( 'Location: ' . wc_get_checkout_url() );
                            die();
                        }

                        if ( 'failed' === $current_response->data->status ) {

                            if ( $order instanceof WC_Order ) {
                                $order->add_order_note( esc_html__( 'Payment Attempt Failed. Try Again', 'novac-woo' ) );
                                $order->update_status( 'failed' );
                                $admin_note = esc_html__( 'Payment Failed ', 'novac-woo' ) . '<br>';
                                $this->logger->info( 'Customer failed payment for order:' . $order_id . ' with transaction reference:' . $txn_ref );
                                $admin_note .= esc_html__( 'Reason: Non-Given', 'novac-woo' );
                                $order->add_order_note( $admin_note );
                            }
                            header( 'Location: ' . wc_get_checkout_url() );
                            die();
                        }

                        $success = true;
                    }
                } else {
                    // Retry.
                    ++$attempt;
                    usleep( 2000000 ); // Wait for 2 seconds before retrying (adjust as needed).
                }
            }

            if ( ! $success ) {
                // Get the transaction from your DB using the transaction reference (txref)
                // Queue it for requery. Preferably using a queue system. The requery should be about 15 minutes after.
                // Ask the customer to contact your support and you should escalate this issue to the Novac support team. Send this as an email and as a notification on the page. just incase the page timesout or disconnects.
                $order->add_order_note( esc_html__( 'The payment didn\'t return a valid response. It could have timed out or abandoned by the customer on Novac', 'novac-woo' ) );
                $order->update_status( 'on-hold' );
                $customer_note  = 'Thank you for your order.<br>';
                $customer_note .= 'We had an issue confirming your payment, but we have put your order <strong>on-hold</strong>. ';
                $customer_note .= esc_html__( 'Please, contact us for information regarding this order.', 'novac-woo' );
                $admin_note     = esc_html__( 'Attention: New order has been placed on hold because we could not get a definite response from the payment gateway. Kindly contact the Novac support team at support@novacpayment.com to confirm the payment.', 'novac-woo' ) . ' <br>';
                $admin_note    .= esc_html__( 'Payment Reference: ', 'novac-woo' ) . $txn_ref;

                $order->add_order_note( $customer_note, 1 );
                $order->add_order_note( $admin_note );

                wc_add_notice( $customer_note, 'notice' );
                $this->logger->error( 'Failed to verify transaction ' . $txn_ref . ' after multiple attempts.' );
            } else {
                // Transaction verified successfully.
                // Proceed with setting the payment on hold.
                $response = json_decode( $response['body'] );
                $this->logger->info( "verify response: ".wp_json_encode( $response ) );
                if ( (bool) $response->data->status ) {
                    $amount = (float) $response->data->amount;
                    if ( $response->data->currency !== $order->get_currency() || ! $this->amounts_equal( $amount, $order->get_total() ) ) {
                        $order->update_status( 'on-hold' );
                        $customer_note  = 'Thank you for your order.<br>';
                        $customer_note .= 'Your payment successfully went through, but we have to put your order <strong>on-hold</strong> ';
                        $customer_note .= 'because the we couldn\t verify your order. Please, contact us for information regarding this order.';
                        $admin_note     = esc_html__( 'Attention: New order has been placed on hold because of incorrect payment amount or currency. Please, look into it.', 'novac-woo' ) . '<br>';
                        $admin_note    .= esc_html__( 'Amount paid: ', 'novac-woo' ) . $response->data->currency . ' ' . $amount . ' <br>' . esc_html__( 'Order amount: ', 'novac-woo' ) . $order->get_currency() . ' ' . $order->get_total() . ' <br>' . esc_html__( ' Reference: ', 'novac-woo' ) . $response->data->reference;
                        $order->add_order_note( $customer_note, 1 );
                        $order->add_order_note( $admin_note );
                    } else {
                        $order->payment_complete( $order->get_id() );
                        if ( 'yes' === $this->auto_complete_order ) {
                            $order->update_status( 'completed' );
                        }
                        $order->add_order_note( 'Payment was successful on Novac' );
                        $order->add_order_note( 'novac-woo  reference: ' . $txn_ref );

                        $customer_note  = 'Thank you for your order.<br>';
                        $customer_note .= 'Your payment was successful, we are now <strong>processing</strong> your order.';
                        $order->add_order_note( $customer_note, 1 );
                    }
                }
            }
            wc_add_notice( $customer_note, 'notice' );
            WC()->cart->empty_cart();

            $redirect_url = $this->get_return_url( $order );
            header( 'Location: ' . $redirect_url );
            die();
        }

        wp_safe_redirect( home_url() );
        die();
    }

    /**
     * Get the Ip of the current request.
     *
     * @return string
     */
    public function novac_get_client_ip() {
        $ip_keys = array(
            'HTTP_CLIENT_IP',
            'HTTP_X_FORWARDED_FOR',
            'HTTP_X_FORWARDED',
            'HTTP_X_CLUSTER_CLIENT_IP',
            'HTTP_FORWARDED_FOR',
            'HTTP_FORWARDED',
            'REMOTE_ADDR',
        );

        foreach ( $ip_keys as $key ) {
            if ( ! empty( $_SERVER[ $key ] ) ) {
                $ip_list = explode( ',', sanitize_text_field( wp_unslash( $_SERVER[ $key ] ) ) );
                foreach ( $ip_list as $ip ) {
                    $ip = trim( $ip );
                    if ( filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) ) {
                        return $ip;
                    }
                }
            }
        }

        return 'UNKNOWN';
    }

    /**
     * Verify a transaction against the Novac API.
     *
     * Bounded: increments the attempt counter on every pass, and treats an
     * HTTP 200 carrying an unusable body as a failed attempt rather than a
     * reason to loop again.
     *
     * @param string $txn_ref      The Novac transaction reference.
     * @param int    $max_attempts Number of attempts before giving up.
     * @return object|null The decoded API response, or null when verification failed.
     */
    protected function verify_transaction( string $txn_ref, int $max_attempts = 3 ): ?object {
        $args = array(
            'method'  => 'GET',
            'timeout' => 10,
            'headers' => array(
                'Content-Type'  => 'application/json',
                'Authorization' => 'Bearer ' . $this->get_secret_key(),
            ),
        );

        for ( $attempt = 1; $attempt <= $max_attempts; $attempt++ ) {
            $response = wp_safe_remote_request( $this->base_url . 'checkout/' . $txn_ref . '/verify', $args );

            if ( is_wp_error( $response ) ) {
                $this->logger->error( 'Novac: verify transport error for ' . $txn_ref . ' (attempt ' . $attempt . '): ' . $response->get_error_message() );
            } else {
                $code = (int) wp_remote_retrieve_response_code( $response );
                $body = wp_remote_retrieve_body( $response );

                if ( 200 === $code ) {
                    $decoded = json_decode( $body );

                    if ( $decoded instanceof \stdClass && isset( $decoded->data ) ) {
                        return $decoded;
                    }

                    // A 200 with an unusable body is a failed attempt, not a retry-forever condition.
                    $this->logger->error( 'Novac: verify returned 200 with an unusable body for ' . $txn_ref . ' (attempt ' . $attempt . '): ' . substr( (string) $body, 0, 500 ) );
                } else {
                    $this->logger->error( 'Novac: verify returned HTTP ' . $code . ' for ' . $txn_ref . ' (attempt ' . $attempt . ').' );
                }
            }

            if ( $attempt < $max_attempts ) {
                sleep( 2 );
            }
        }

        return null;
    }

    /**
     * Build the idempotency key for a webhook event.
     *
     * Keyed on reference *and* notify type *and* status, not the reference
     * alone — otherwise a later `reversed` event on a transaction that already
     * succeeded would be silently swallowed as a duplicate.
     *
     * @param string $txn_ref     Transaction reference.
     * @param string $notify_type The notifyType field.
     * @param string $status      The transaction status carried by the webhook.
     * @return string
     */
    protected function get_webhook_claim_key( string $txn_ref, string $notify_type, string $status ): string {
        return 'novac_wh_' . md5( $txn_ref . '|' . $notify_type . '|' . $status );
    }

    /**
     * Claim a webhook event for processing.
     *
     * add_option() is backed by a unique index on option_name, so a duplicate
     * insert loses the race rather than both winning.
     *
     * @param string $claim_key The idempotency key.
     * @return bool True when this request won the claim, false when already claimed.
     */
    protected function claim_webhook_event( string $claim_key ): bool {
        // Autoload 'no' — these are write-once and never read on a normal page load.
        $claimed = add_option( $claim_key, time(), '', 'no' );

        if ( $claimed && function_exists( 'as_schedule_single_action' ) ) {
            as_schedule_single_action( time() + DAY_IN_SECONDS, 'novac_release_webhook_claim', array( $claim_key ), 'novac' );
        }

        return $claimed;
    }

    /**
     * Drop a claim so a later delivery of the same event can be retried.
     *
     * @param string $claim_key The idempotency key.
     * @return void
     */
    public function release_webhook_claim( string $claim_key ): void {
        delete_option( $claim_key );
    }

    /**
     * Process Webhook notifications.
     *
     * Validates and acknowledges in-request; verification and order mutation
     * are handed to Action Scheduler so the response is never held open on
     * an outbound API call or on WooCommerce's synchronous email dispatch.
     */
    public function novac_notification_handler() {
        /*
         * TODO: the allowlist below is the only thing authenticating this
         * endpoint, and novac_get_client_ip() reads X-Forwarded-For, which the
         * caller controls unless a trusted proxy strips it. Ask Novac whether
         * they send a signature header; if they do, verify it here —
         * hash_hmac( 'SHA512', $raw, $this->get_secret_key() ) — and demote the
         * IP check to a second line of defence.
         */

        if ( NOVAC_WOO_ALLOWED_WEBHOOK_IP_ADDRESS !== $this->novac_get_client_ip() ) {
            $this->logger->info( 'Fraudulent Webhook Notification Attempt [Access Restricted]: ' . (string) $this->novac_get_client_ip() );
            wp_send_json(
                array(
                    'status'  => 'error',
                    'message' => 'Unauthorized Access (Restriction)',
                ),
                WP_Http::UNAUTHORIZED
            );
        }

        $raw   = file_get_contents( 'php://input' );
        $event = json_decode( $raw );

        if ( ! $event instanceof \stdClass || empty( $event->notify ) || empty( $event->data ) ) {
            $this->logger->info( 'Novac: malformed webhook: ' . substr( (string) $raw, 0, 1000 ) );
            wp_send_json(
                array(
                    'status'  => 'error',
                    'message' => 'Webhook sent is deformed. missing data object.',
                ),
                WP_Http::BAD_REQUEST
            );
        }

        if ( 'test_assess' === $event->notify ) {
            wp_send_json(
                array(
                    'status'  => 'success',
                    'message' => 'Webhook Test Successful. handler is accessible',
                ),
                WP_Http::OK
            );
        }

        $this->logger->info( 'Webhook: ' . wp_json_encode( $event ) );

        if ( 'transaction' !== $event->notify && 'banktransfer' !== $event->notify ) {
            wp_send_json(
                array(
                    'status'  => 'failed',
                    'message' => 'Unable to process this webhook notification',
                ),
                WP_Http::OK
            );
        }

        $event_data = $event->data;
        // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
        $txn_ref = isset( $event_data->transactionReference ) ? sanitize_text_field( (string) $event_data->transactionReference ) : '';

        // check if transaction reference starts with WOO on hpos enabled.
        if ( '' === $txn_ref || 'WOO_' !== substr( $txn_ref, 0, 4 ) ) {
            wp_send_json(
                array(
                    'status'  => 'failed',
                    'message' => 'The transaction reference ' . $txn_ref . ' is not a Novac WooCommerce Generated transaction',
                ),
                WP_Http::BAD_REQUEST
            );
        }

        $parts    = explode( '_', $txn_ref );
        $order_id = isset( $parts[1] ) ? intval( $parts[1] ) : 0;
        $order    = $order_id > 0 ? wc_get_order( $order_id ) : false;

        if ( ! $order instanceof WC_Order ) {
            wp_send_json(
                array(
                    'status'  => 'failed',
                    'message' => 'This transaction does not exist.',
                ),
                WP_Http::BAD_REQUEST
            );
        }

        /**
         * Fires after the webhook has been received and validated.
         *
         * @param string $event The webhook event, JSON encoded.
         * @since 1.0.0
         */
        do_action( 'novac_webhook_after_action', wp_json_encode( $event ) );

        // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
        $notify_type = isset( $event->notifyType ) ? (string) $event->notifyType : '';
        $status      = isset( $event_data->status ) ? (string) $event_data->status : '';
        $claim_key   = $this->get_webhook_claim_key( $txn_ref, $notify_type, $status );

        if ( ! $this->claim_webhook_event( $claim_key ) ) {
            $this->logger->info( 'Novac: duplicate webhook delivery ignored for ' . $txn_ref . ' (' . $notify_type . '/' . $status . ').' );
            wp_send_json(
                array(
                    'status'  => 'success',
                    'message' => 'Already received',
                ),
                WP_Http::OK
            );
        }

        $queue_stalled = function_exists( 'novac_woo_webhook_queue_is_stalled' ) && novac_woo_webhook_queue_is_stalled();

        if ( function_exists( 'as_enqueue_async_action' ) && ! $queue_stalled ) {
            as_enqueue_async_action(
                'novac_process_webhook',
                array( $txn_ref, $order_id, $status, $claim_key ),
                'novac'
            );

            wp_send_json(
                array(
                    'status'  => 'success',
                    'message' => 'Webhook accepted',
                ),
                WP_Http::OK
            );
        }

        /*
         * Either Action Scheduler is missing, or its queue has demonstrably
         * stopped draining. Process inline instead. That is slow enough that
         * Novac's sender may time out and retry — which the claim above makes
         * safe — but a store whose scheduler is broken must not answer 200 to
         * a payment it then silently never processes.
         */
        $this->logger->error(
            $queue_stalled
                ? 'Novac: webhook queue is not draining, processing ' . $txn_ref . ' inline.'
                : 'Novac: Action Scheduler unavailable, processing ' . $txn_ref . ' inline.'
        );

        // Finish the work even if the sender hangs up mid-verification.
        ignore_user_abort( true );

        if ( function_exists( 'wc_set_time_limit' ) ) {
            wc_set_time_limit( 60 );
        }

        $this->process_webhook_event( $txn_ref, $order_id, $status, $claim_key );

        wp_send_json(
            array(
                'status'  => 'success',
                'message' => 'Order Processed Successfully',
            ),
            WP_Http::OK
        );
    }

    /**
     * Verify a transaction and apply the result to its order.
     *
     * Runs out of band via Action Scheduler. Safe to take seconds — nothing is
     * waiting on it.
     *
     * @param string $txn_ref   Transaction reference.
     * @param int    $order_id  WooCommerce order id.
     * @param string $status    Status carried by the webhook (informational).
     * @param string $claim_key Idempotency key, released when processing could not complete.
     * @return void
     */
    public function process_webhook_event( string $txn_ref, int $order_id, string $status = '', string $claim_key = '' ): void {
        $order = wc_get_order( $order_id );

        if ( ! $order instanceof WC_Order ) {
            $this->logger->error( 'Novac: order ' . $order_id . ' vanished before webhook processing for ' . $txn_ref . '.' );
            return;
        }

        // Re-check status here, not only at receipt — the order may have moved on
        // between acknowledgement and this job running.
        $current_order_status = $order->get_status();
        $statuses_in_question = array( 'pending', 'on-hold', 'cancelled', 'failed' );

        if ( ! in_array( $current_order_status, $statuses_in_question, true ) && 'reversed' !== $status ) {
            $this->logger->info( 'Novac: order ' . $order_id . ' already in status "' . $current_order_status . '", skipping webhook ' . $txn_ref . '.' );
            return;
        }

        $api_response = $this->verify_transaction( $txn_ref );

        if ( null === $api_response ) {
            $order->update_status( 'on-hold' );
            $admin_note  = esc_html__( 'Attention: Order placed on hold — no valid response from Novac after 3 attempts. Contact support@novacpayment.com.', 'novac-woo' ) . '<br>';
            $admin_note .= esc_html__( 'Payment Reference: ', 'novac-woo' ) . $txn_ref;
            $order->add_order_note( $admin_note );
            $this->logger->error( 'Novac: failed to verify transaction ' . $txn_ref . ' after 3 attempts.' );

            // Let a subsequent delivery of the same event try again.
            if ( '' !== $claim_key ) {
                $this->release_webhook_claim( $claim_key );
            }

            return;
        }

        $novac_status = (string) ( $api_response->data->status ?? 'unknown' );
        $this->logger->info( 'Webhook verify response for ' . $txn_ref . ': ' . wp_json_encode( $api_response->data ) );

        // WooCommerce's own status list, for the mapping below:
        // https://github.com/woocommerce/woocommerce/blob/22af34971b26c7852057cb4f5585204bbe010e44/plugins/woocommerce/src/Enums/OrderStatus.php#L14
        switch ( $novac_status ) {
            case 'successful':
                $amount   = (float) ( $api_response->data->amount ?? 0 );
                $currency = (string) ( $api_response->data->currency ?? '' );

                if ( $currency !== $order->get_currency() || ! $this->amounts_equal( $amount, $order->get_total() ) ) {
                    $order->update_status( 'on-hold' );
                    $admin_note  = esc_html__( 'Attention: Order on hold — amount or currency mismatch. Please review.', 'novac-woo' ) . '<br>';
                    $admin_note .= esc_html__( 'Amount paid: ', 'novac-woo' ) . $currency . ' ' . $amount . '<br>';
                    $admin_note .= esc_html__( 'Order amount: ', 'novac-woo' ) . $order->get_currency() . ' ' . $order->get_total();
                    $order->add_order_note( $admin_note );
                } else {
                    $order->payment_complete( $txn_ref );

                    if ( 'yes' === $this->auto_complete_order ) {
                        $order->update_status( 'completed' );
                    }

                    $order->add_order_note( esc_html__( 'Payment verified and successful on Novac.', 'novac-woo' ) );
                    $order->add_order_note( esc_html__( 'Novac reference: ', 'novac-woo' ) . $txn_ref );
                    $order->add_order_note( 'Thank you for your order.<br>Your payment was successful, we are now <strong>processing</strong> your order.', 1 );
                }
                break;

            case 'pending':
                $order->update_status( 'on-hold' );
                $order->add_order_note( esc_html__( 'Payment still pending on Novac. Order placed on hold pending confirmation.', 'novac-woo' ) );
                break;

            case 'cancelled':
                $order->update_status( 'cancelled' );
                $order->add_order_note( esc_html__( 'Transaction cancelled by the customer on Novac.', 'novac-woo' ) );
                break;

            case 'abandoned':
            case 'failed':
                $order->update_status( 'failed' );
                $order->add_order_note(
                    sprintf(
                        /* translators: %s: novac status */
                        esc_html__( 'Payment %s on Novac. Order marked as failed.', 'novac-woo' ),
                        $novac_status
                    )
                );
                break;

            case 'reversed':
                $order->update_status( 'on-hold' );
                $order->add_order_note( esc_html__( 'Payment reversed on Novac. Order placed on hold — a new payment is required.', 'novac-woo' ) );
                break;

            default:
                $order->update_status( 'on-hold' );
                $order->add_order_note(
                    sprintf(
                        /* translators: %s: novac status */
                        esc_html__( 'Unrecognised Novac status "%s". Order placed on hold for manual review.', 'novac-woo' ),
                        $novac_status
                    )
                );
                break;
        }
    }

    /**
     * Add "Check Novac Transaction Status" to the Order Actions dropdown.
     *
     * @param array $actions Existing order actions.
     * @return array
     */
    public function add_novac_requery_action( array $actions ): array {
        global $theorder;

        if ( ! $theorder instanceof WC_Order ) {
            return $actions;
        }

        if ( 'novac' !== $theorder->get_payment_method() ) {
            return $actions;
        }

        $actions['novac_requery_transaction'] = __( 'Check Novac Transaction Status', 'novac-woo' );

        return $actions;
    }

    /**
     * Handle the "Check Novac Transaction Status" order action.
     *
     * Queries the Novac verify endpoint and adds an order note with the result.
     *
     * @param WC_Order $order The order being acted on.
     * @return void
     */
    public function process_novac_requery_action( WC_Order $order ): void {
        $order_id = $order->get_id();
        $txn_ref  = get_post_meta( $order_id, '_novac_txn_ref', true );

        if ( empty( $txn_ref ) ) {
            $order->add_order_note( __( 'Novac: No transaction reference found for this order. Cannot query status.', 'novac-woo' ) );
            return;
        }

        $args = array(
            'method'  => 'GET',
            'headers' => array(
                'Content-Type'  => 'application/json',
                'Authorization' => 'Bearer ' . $this->secret_key,
            ),
        );

        $this->logger->info( 'Manual requery for order ' . $order_id . ' with reference ' . $txn_ref );

        $response = wp_safe_remote_request( $this->base_url . 'checkout/' . $txn_ref . '/verify', $args );

        if ( is_wp_error( $response ) ) {
            $order->add_order_note(
                sprintf(
                    /* translators: %s: error message */
                    __( 'Novac: Transaction status check failed — %s', 'novac-woo' ),
                    $response->get_error_message()
                )
            );
            $this->logger->error( 'Requery error for order ' . $order_id . ': ' . $response->get_error_message() );
            return;
        }

        $http_code = wp_remote_retrieve_response_code( $response );
        $body      = json_decode( wp_remote_retrieve_body( $response ) );

        if ( 200 !== $http_code || empty( $body->data ) ) {
            $order->add_order_note(
                sprintf(
                    /* translators: %s: HTTP status code */
                    __( 'Novac: Transaction status check returned an unexpected response (HTTP %s). Please check the Novac dashboard.', 'novac-woo' ),
                    $http_code
                )
            );
            return;
        }

        $status   = $body->data->status ?? 'unknown';
        $amount   = $body->data->amount ?? 0;
        $currency = $body->data->currency ?? '';

        $note = sprintf(
            /* translators: 1: status 2: currency 3: amount 4: reference */
            __( 'Novac manual requery — Status: %1$s | Amount: %2$s %3$s | Reference: %4$s', 'novac-woo' ),
            strtoupper( (string) $status ),
            $currency,
            $amount,
            $txn_ref
        );

        $order->add_order_note( $note );
        $this->logger->info( 'Requery result for order ' . $order_id . ': ' . wp_json_encode( $body->data ) );
    }

    /**
     * Enqueue the checkout pre-select script when arriving from a Buy Now click.
     *
     * @return void
     */
    public function enqueue_checkout_preselect_script(): void {
        if ( ! is_checkout() || empty( $_GET['novac_buy_now'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
            return;
        }

        wp_enqueue_script(
            'novac-checkout-preselect',
            plugins_url( 'assets/js/checkout-preselect.js', NOVAC_WOO_PLUGIN_FILE ),
            array( 'jquery' ),
            NOVAC_WOO_VERSION,
            true
        );

        wp_localize_script(
            'novac-checkout-preselect',
            'novacBuyNow',
            array( 'gatewayId' => $this->id )
        );
    }

    /**
     * Enqueue the Buy Now script on single product pages.
     *
     * @return void
     */
    public function enqueue_buy_now_script(): void {
        if ( ! is_product() ) {
            return;
        }

        wp_enqueue_script(
            'novac-buy-now',
            plugins_url( 'assets/js/buy-now.js', NOVAC_WOO_PLUGIN_FILE ),
            array(),
            NOVAC_WOO_VERSION,
            true
        );
    }

    /**
     * Render the "Buy Now" button on product pages.
     *
     * The button submits the existing add-to-cart form (so variation selects and
     * quantity are included automatically) and signals the redirect filter below
     * to send the customer straight to checkout instead of the cart.
     *
     * @return void
     */
    public function render_buy_now_button(): void {
        global $product;

        if ( ! $product instanceof WC_Product ) {
            return;
        }

        printf(
            '<button type="submit" name="novac_buy_now" value="1" class="button alt novac-buy-now-btn" data-product-id="%s" data-checkout-url="%s" data-nonce="%s">%s</button>',
            esc_attr( (string) $product->get_id() ),
            esc_attr( add_query_arg( 'novac_buy_now', '1', wc_get_checkout_url() ) ),
            esc_attr( wp_create_nonce( 'wc_store_api' ) ),
            esc_html__( 'Buy now with Novac', 'novac-woo' )
        );
    }

    /**
     * Redirect straight to checkout after a "Buy Now" add-to-cart.
     * Pre-selects Novac in the WC session so the payment radio is already
     * chosen when the checkout page renders.
     *
     * @param string $url The default redirect URL (usually the cart).
     * @return string
     */
    public function handle_buy_now_redirect( string $url ): string {
        if ( ! empty( $_POST['novac_buy_now'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
            WC()->session->set( 'chosen_payment_method', $this->id );
            return add_query_arg( 'novac_buy_now', '1', wc_get_checkout_url() );
        }

        return $url;
    }

    public function add_buy_now_validation( $passed, $product_id, $quantity ) {
        if ( $passed && isset( $_REQUEST['myplugin_buy_now'] ) ) {
            WC()->cart->empty_cart();
        }
        return $passed;
    }
}
