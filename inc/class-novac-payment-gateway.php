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
        $this->base_url           = 'https://api.novacpayment.com/api/v1/';
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
        $this->country             = '';
        $this->supports            = array(
            'products',
        );

        add_action( 'admin_notices', array( $this, 'admin_notices' ) );
        add_action( 'woocommerce_receipt_' . $this->id, array( $this, 'receipt_page' ) );
        add_action( 'woocommerce_api_wc_novac_payment_gateway', array( $this, 'novac_verify_payment' ) );

        // Webhook listener/API hook.
        add_action( 'woocommerce_api_novac_payment_webhook', array( $this, 'novac_notification_handler' ) );

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

        try {
            $novac_request = ( new Novac_Request() )->get_prepared_payload( $order, $this->get_secret_key() );
            update_post_meta( $order_id, '_novac_txn_ref', $novac_request['reference']);
            $this->logger->info( 'Novac: Generating Payment link for order :' . $order_id );
        } catch ( \InvalidArgumentException $novac_request_error ) {
            wc_add_notice( $novac_request_error, 'error' );
            // redirect user to check out page.
            $this->logger->error( 'Novac: Failed in Generating Payment link for order :' . $order_id );
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

        if ( ! is_wp_error( $response ) ) {
            // TODO: Get customer id.
            $this->logger->info( 'Novac: redirecting customer to the payment link :' . $response->data->paymentRedirectUrl );
            $this->logger->info( $response['body'] );
            $response = json_decode( $response['body'] );
            return array(
                'result'   => 'success',
                'redirect' => $response->data->paymentRedirectUrl,
            );
        } else {
            wc_add_notice( 'Unable to Connect to Novac.', 'error' );
            $this->logger->error( 'Novac: Unable to Connect to Novac. API Response: ' . $response->get_error_message() );
            // redirect user to checkout page.
            return array(
                'result'   => 'fail',
                'redirect' => $order->get_checkout_payment_url( true ),
            );
        }
    }

    /**
     * Handles admin notices
     *
     * @return void
     */
    public function admin_notices(): void {

        if ( 'yes' === $this->enabled ) {

            if ( empty( $this->public_key ) || empty( $this->secret_key ) ) {

                $message = sprintf(
                /* translators: %s: url */
                    __( 'For Novac on appear on checkout. Please <a href="%s">set your Novac API keys</a> to be able to accept payments.', 'novac-woo' ),
                    esc_url( admin_url( 'admin.php?page=wc-settings&tab=checkout&section=novac' ) )
                );
            }
        }
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
                $admin_note     = esc_html__( 'Attention: New order has been placed on hold because we could not get a definite response from the payment gateway. Kindly contact the Novac support team at developers@novac.com to confirm the payment.', 'novac-woo' ) . ' <br>';
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
     * Process Webhook notifications.
     */
    public function novac_notification_handler() {
        $public_key = $this->public_key;
        $secret_key = $this->secret_key;
        $logger     = $this->logger;
        $sdk        = $this->sdk;

//        $merchant_secret_hash = hash_hmac( 'SHA512', $public_key, $secret_key );

//        if ( NOVAC_WOO_ALLOWED_WEBHOOK_IP_ADDRESS !== $this->novac_get_client_ip() ) {
//            $this->logger->info( 'Faudulent Webhook Notification Attempt [Access Restricted]: ' . (string) $this->novac_get_client_ip() );
//            wp_send_json(
//                array(
//                    'status'  => 'error',
//                    'message' => 'Unauthorized Access (Restriction)',
//                ),
//                WP_Http::UNAUTHORIZED
//            );
//        }

        $event = file_get_contents( 'php://input' );

        http_response_code( 200 );
        $event = json_decode( $event );

        if ( empty( $event->notify ) && empty( $event->data ) ) {
            $this->logger->info( 'Webhook: ' . wp_json_encode( $event ) );
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

        if ( 'transaction' === $event->notify || 'banktransfer' === $event->notify ) {
            sleep( 2 );
            // phpcs:ignore
            $event_type = $event->notifyType;
            $event_data = $event->data;

            // check if transaction reference starts with WOO on hpos enabled.
            if ( empty( $event_data->transactionReference ) || substr( (string) $event_data->transactionReference, 0, 4 ) !== 'WOO_' ) {
                wp_send_json(
                    array(
                        'status'  => 'failed',
                        'message' => 'The transaction reference ' . ( $event_data->transactionReference ?? '' ) . ' is not a Novac WooCommerce Generated transaction',
                    ),
                    WP_Http::BAD_REQUEST
                );
            }

            $txn_ref  = sanitize_text_field( $event_data->transactionReference );
            $o        = explode( '_', $txn_ref );
            $order_id = intval( $o[1] );
            $order    = wc_get_order( $order_id );

            // get order status.
            if ( ! $order ) {
                wp_send_json(
                    array(
                        'status'  => 'failed',
                        'message' => 'This transaction does not exist.',
                    ),
                    WP_Http::BAD_REQUEST
                );
            }

            $current_order_status = $order->get_status();

            /**
             * Fires after the webhook has been processed.
             *
             * @param string $event The webhook event.
             * @since 1.0.0
             */
            do_action( 'novac_webhook_after_action', wp_json_encode( $event, true ) );
            $statuses_in_question = array( 'pending', 'on-hold', 'cancelled', 'reversed' );
            if ( 'failed' === $current_order_status ) {
                // NOTE: customer must have tried to make payment again in the same session.
                $statuses_in_question[] = 'failed';
            }

            if ( ! in_array( $current_order_status, $statuses_in_question, true ) ) {
                wp_send_json(
                    array(
                        'status'  => 'error',
                        'message' => 'Order already processed',
                    ),
                    WP_Http::CREATED
                );
            }

            // Verify transaction and give value.
            // Communicate with Novac to confirm payment.
            $max_attempts = 3;
            $attempt      = 0;
            $success      = false;

            while ( $attempt < $max_attempts && ! $success ) {
                $args = array(
                    'method'  => 'GET',
                    'headers' => array(
                        'Content-Type'  => 'application/json',
                        'Authorization' => 'Bearer ' . $secret_key,
                    ),
                );

                $order->add_order_note( esc_html__( 'verifying the Payment on Novac...', 'novac-woo' ) );

                $response = wp_safe_remote_request( $this->base_url . 'checkout/' . $txn_ref . '/verify', $args );

                if ( ! is_wp_error( $response ) && wp_remote_retrieve_response_code( $response ) === 200 ) {
                    // Request successful.
                    $current_response                  = \json_decode( $response['body'] );
                    $is_cancelled_or_pending_on_novac = in_array( $current_response->data->status, array( 'cancelled', 'pending' ), true );
                    if ( 'cancelled' === $event_data->status && $is_cancelled_or_pending_on_novac ) { // phpcs:ignore
                        if ( $order instanceof WC_Order ) {
                            $order->add_order_note( esc_html__( 'The customer clicked on the cancel button on Checkout.', 'novac-woo' ) );
                            $order->update_status( 'cancelled' );
                            $admin_note = esc_html__( 'Attention: Customer clicked on the cancel button on the payment gateway. We have updated the order to cancelled status. ', 'novac-woo' ) . '<br>';
                            $order->add_order_note( $admin_note );
                        }
                    } else {
                        if ( 'pending' === $current_response->data->status ) {

                            if ( $order instanceof WC_Order ) {
                                $order->add_order_note( esc_html__( 'Payment Attempt Failed. Please Try Again.', 'novac-woo' ) );
                                $admin_note = esc_html__( 'Customer Payment Attempt failed. Advise customer to try again with a different Payment Method', 'novac-woo' ) . '<br>';
                                $admin_note .= esc_html__( 'Reason: Unknown', 'novac-woo' );

                                $order->add_order_note( $admin_note );
                            }
                        }

                        if ( 'failed' === $current_response->data->status || 'abandoned' === $current_response->data->status ) {

                            if ( $order instanceof WC_Order ) {
                                $order->add_order_note( esc_html__( 'Payment Attempt Failed. Try Again', 'novac-woo' ) );
                                $order->update_status( 'failed' );
                                $admin_note = esc_html__( 'Payment Failed ', 'novac-woo' ) . '<br>';
                                $admin_note .= esc_html__( 'Reason: Non-Given', 'novac-woo' );
                                $order->add_order_note( $admin_note );
                            }
                        }

                        if ( 'reversed' === $current_response->data->status ) {
                            $order->add_order_note( esc_html__( 'Payment Reversed. A new payment is required', 'novac-woo' ) );
                            $order->update_status( 'pending' );
                            $admin_note = esc_html__( 'Payment Reversed ', 'novac-woo' ) . '<br>';
                            $admin_note .= esc_html__( 'Reason: Non-Given', 'novac-woo' );
                            $order->add_order_note( $admin_note );
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
                $admin_note  = esc_html__( 'Attention: New order has been placed on hold because we could not get a definite response from the payment gateway. Kindly contact the Novac support team at developers@novac.com to confirm the payment.', 'novac-woo' ) . ' <br>';
                $admin_note .= esc_html__( 'Payment Reference: ', 'novac-woo' ) . $txn_ref;
                $order->add_order_note( $admin_note );
                $this->logger->error( 'Failed to verify transaction ' . $txn_ref . ' after multiple attempts.' );
            } else {
                // Transaction verified successfully.
                // Proceed with setting the payment on hold.
                $response = json_decode( $response['body'] );
                $this->logger->info( wp_json_encode( $response ) );
                if ( (bool) $response->data->status ) {
                    $amount = (float) $response->data->requested_amount;
                    if ( $response->data->currency !== $order->get_currency() || ! $this->amounts_equal( $amount, $order->get_total() ) ) {
                        $order->update_status( 'on-hold' );
                        $admin_note  = esc_html__( 'Attention: New order has been placed on hold because of incorrect payment amount or currency. Please, look into it.', 'novac-woo' ) . '<br>';
                        $admin_note .= esc_html__( 'Amount paid: ', 'novac-woo' ) . $response->data->currency . ' ' . $amount . ' <br>' . esc_html__( 'Order amount: ', 'novac-woo' ) . $order->get_currency() . ' ' . $order->get_total() . ' <br>' . esc_html__( ' Reference: ', 'novac-woo' ) . $response->data->reference;
                        $order->add_order_note( $admin_note );
                    } else {
                        $order->payment_complete( $order->get_id() );
                        if ( 'yes' === $this->auto_complete_order ) {
                            $order->update_status( 'completed' );
                        }
                        $order->add_order_note( 'Payment was successful on novac-woo' );
                        $order->add_order_note( 'Novac  reference: ' . $txn_ref );

                        $customer_note  = 'Thank you for your order.<br>';
                        $customer_note .= 'Your payment was successful, we are now <strong>processing</strong> your order.';
                        $order->add_order_note( $customer_note, 1 );
                    }
                }
            }

            wp_send_json(
                array(
                    'status'  => 'success',
                    'message' => 'Order Processed Successfully',
                ),
                WP_Http::CREATED
            );
        }

        wp_send_json(
            array(
                'status'  => 'failed',
                'message' => 'Unable to process this webhook notification',
            ),
            WP_Http::CREATED
        );
        exit();
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
}
