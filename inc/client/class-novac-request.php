<?php
/**
 * The file that defines class to handle requests to Novac.
 *
 * A class definition that includes attributes and functions used across both the
 * public-facing side of the site and the admin area.
 *
 * @link       https://developer.novacpayment.com
 * @since      1.0.0
 *
 * @package    Novac/WooCommerce
 * @subpackage Novac/WooCommerce/client
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

/**
 * Class Novac_Request file.
 *
 * @package Novac\Client
 */
class Novac_Request {
    /**
     * Callback URL handed to Novac for the customer's return journey.
     *
     * Declared rather than created on the fly: PHP 8.2 deprecates dynamic
     * properties, and they become a fatal error in PHP 9.
     *
     * @var string
     */
    private string $notify_url;

    /**
     *  Pointer to gateway making the request.
     */
    public function __construct() {
        $this->notify_url = WC()->api_request_url( 'WC_Novac_Payment_Gateway' );
    }

    /**
     * This method prepares the payload for the request
     *
     * @param \WC_Order $order Order object.
     * @param string    $secret_key APi key.
     * @param bool      $testing is ci.
     * @throws \InvalidArgumentException When the secret key is not supplied.
     *
     * @return array
     */
    public function get_prepared_payload( \WC_Order $order, string $secret_key, bool $testing = false ): array {
        $order_id = $order->get_id();
        $txnref   = 'WOO_' . $order_id . '_' . time();
        $amount   = $order->get_total();
        $currency = $order->get_currency();
        $email    = $order->get_billing_email();
        $phone_number = $order->get_billing_phone();
        $first_name   = $order->get_billing_first_name();
        $last_name    = $order->get_billing_last_name();
        $description  = 'Payment for Order #' . $order_id . ' by ' . $first_name . ' ' . $last_name;

        if ( $testing ) {
            $txnref = 'WOO_' . $order_id . '_TEST';
        }

        if ( empty( $secret_key ) ) {
            // let admin know that the secret key is not set.
            throw new \InvalidArgumentException( 'This Payment Method is current unavailable as Administrator is yet to Configure it.Please contact Administrator for more information.' );
        }

        // Parse the base URL to check for existing query parameters.
        $url_parts = wp_parse_url( $this->notify_url );

        // If the base URL already has query parameters, merge them with new ones.
        if ( isset( $url_parts['query'] ) ) {
            // Convert the query string to an array.
            parse_str( $url_parts['query'], $query_array );

            // Add the new parameters to the existing query array.
            $query_array['order_id'] = $order_id;

            // Rebuild the query string with the new parameters.
            $new_query_string = http_build_query( $query_array );

            // if host is localhost and a port is specified include it
            if ( isset( $url_parts['port'] ) ) {
                $url_parts['host'] .= ':' . $url_parts['port'];
            }

            // Rebuild the final URL with the new query string.
            $callback_url = $url_parts['scheme'] . '://' . $url_parts['host'] . $url_parts['path'] . '?' . $new_query_string;
        } else {
            // If no existing query parameters, simply append the new ones.
            $callback_url = add_query_arg(
                array(
                    'order_id' => $order_id,
                ),
                $this->notify_url
            );
        }

        return array(
            'email'     => $email,
            'amount'    => $amount,
            'currency'  => $currency,
            'reference' => $txnref,
            'callback'  => $callback_url,
            'phone'     => $phone_number,
            'first_name' => $first_name,
            'last_name'  => $last_name,
            'description' => $description,
            'tx_ref'     => $txnref,
        );
    }
}