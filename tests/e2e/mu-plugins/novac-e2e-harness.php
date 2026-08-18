<?php
/**
 * Novac E2E test harness.
 *
 * Loaded as a mu-plugin only in the wp-env test environment. It does three things:
 *
 * 1. Intercepts every outbound HTTP request to the Novac API and answers it from
 *    a scripted scenario, so the suite never touches the real payment API. That
 *    keeps runs deterministic, avoids creating real transactions twice a day,
 *    and — critically — makes the failure branches (401, sub-minimum rejection,
 *    malformed responses) testable at all.
 * 2. Records the intercepted requests so tests can assert on what the plugin
 *    actually sent (amount, currency, auth header, callback URL).
 * 3. Serves a stand-in for Novac's hosted payment page, so the redirect flow
 *    completes in-browser without leaving the local site.
 *
 * Control endpoints live under /wp-json/novac-e2e/v1/ and are gated on a shared
 * token, so this file is inert unless NOVAC_E2E is defined.
 *
 * @package Novac/WooCommerce/Tests
 */

defined( 'ABSPATH' ) || exit;

if ( ! defined( 'NOVAC_E2E' ) || ! NOVAC_E2E ) {
    return;
}

/**
 * Test harness for the Novac E2E suite.
 */
final class Novac_E2E_Harness {

    /**
     * Option holding the active scenario.
     */
    private const SCENARIO_OPTION = 'novac_e2e_scenario';

    /**
     * Option holding recorded outbound requests.
     */
    private const REQUESTS_OPTION = 'novac_e2e_requests';

    /**
     * Host the plugin talks to in production.
     */
    private const NOVAC_HOST = 'api.novacpayment.com';

    /**
     * Boot the harness.
     */
    public static function init(): void {
        add_filter( 'novac_woo_api_base_url', array( __CLASS__, 'mock_base_url' ) );
        add_filter( 'pre_http_request', array( __CLASS__, 'intercept' ), 10, 3 );
        add_filter( 'novac_woo_trusted_proxies', array( __CLASS__, 'trusted_proxies' ) );
        add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
        add_action( 'init', array( __CLASS__, 'maybe_render_hosted_page' ) );
    }

    /**
     * Point the plugin at a same-host URL instead of the real API.
     *
     * Requests are still short-circuited by intercept() and never leave the
     * container. Redirecting the base URL keeps the runner from needing to
     * resolve api.novacpayment.com at all: wp_safe_remote_request() validates
     * the URL before the transport runs, and that validation resolves the
     * hostname. A same-host URL always passes.
     *
     * @return string
     */
    public static function mock_base_url(): string {
        return home_url( '/novac-e2e-api/v1/' );
    }

    /**
     * Stand in for the reverse proxy a real store sits behind.
     *
     * The suite drives the webhook endpoint over HTTP from outside the
     * container, so the request always arrives from the Docker network rather
     * than from Novac. Declaring that network as a trusted proxy makes the
     * plugin believe the X-Forwarded-For the specs send — exactly as a store
     * behind Cloudflare or a load balancer would.
     *
     * Setting `trust_proxy` false removes it, which is how a spec reaches the
     * case that matters: a spoofed forwarded header on a store with no proxy
     * in front of it must not get past the allowlist.
     *
     * @param array $proxies Existing trusted proxies.
     * @return array
     */
    public static function trusted_proxies( array $proxies ): array {
        $scenario = self::scenario();

        if ( empty( $scenario['trust_proxy'] ) ) {
            return $proxies;
        }

        return array_merge(
            $proxies,
            array( '127.0.0.1', '::1', '10.0.0.0/8', '172.16.0.0/12', '192.168.0.0/16' )
        );
    }

    /**
     * Whether a URL is bound for the Novac API, real or mocked.
     *
     * @param string $url Request URL.
     * @return bool
     */
    private static function is_novac_url( string $url ): bool {
        if ( self::NOVAC_HOST === wp_parse_url( $url, PHP_URL_HOST ) ) {
            return true;
        }

        return false !== strpos( $url, '/novac-e2e-api/v1/' );
    }

    /**
     * The scenario currently in force, merged over defaults.
     *
     * @return array
     */
    private static function scenario(): array {
        $stored = get_option( self::SCENARIO_OPTION, array() );

        return array_merge(
            array(
                // Response to POST /initiate.
                'initiate_status'  => 200,
                'initiate_body'    => null,   // Null means "build a success body".
                'initiate_error'   => '',     // Non-empty returns a WP_Error instead.
                // Response to GET /checkout/{ref}/verify.
                'verify_status'    => 200,
                'verify_txn_state' => 'successful',
                'verify_amount'    => null,   // Null means "echo the order total".
                'verify_currency'  => null,   // Null means "echo the order currency".
                'verify_error'     => '',
                'verify_raw_body'  => null,   // Non-null returns HTTP 200 carrying this exact body.
                // Response to the credential-probe verify call made on settings save.
                'probe_status'     => 200,
                // Whether the store is treated as sitting behind a reverse proxy.
                'trust_proxy'      => true,
            ),
            is_array( $stored ) ? $stored : array()
        );
    }

    /**
     * Short-circuit outbound requests aimed at the Novac API.
     *
     * @param false|array|WP_Error $preempt     Short-circuit value.
     * @param array                $parsed_args Request arguments.
     * @param string               $url         Request URL.
     * @return false|array|WP_Error
     */
    public static function intercept( $preempt, $parsed_args, $url ) {
        if ( ! self::is_novac_url( (string) $url ) ) {
            return $preempt;
        }

        $scenario = self::scenario();

        self::record( $url, $parsed_args );

        if ( false !== strpos( $url, '/initiate' ) ) {
            return self::respond_to_initiate( $url, $parsed_args, $scenario );
        }

        if ( false !== strpos( $url, '/verify' ) ) {
            return self::respond_to_verify( $url, $scenario );
        }

        return self::response( 404, array( 'message' => 'Unmapped Novac endpoint in the E2E harness: ' . $url ) );
    }

    /**
     * Build the response to a payment-initiation call.
     *
     * @param string $url         Request URL.
     * @param array  $parsed_args Request arguments.
     * @param array  $scenario    Active scenario.
     * @return array|WP_Error
     */
    private static function respond_to_initiate( $url, $parsed_args, array $scenario ) {
        if ( '' !== $scenario['initiate_error'] ) {
            return new WP_Error( 'http_request_failed', $scenario['initiate_error'] );
        }

        if ( null !== $scenario['initiate_body'] ) {
            return self::response( (int) $scenario['initiate_status'], $scenario['initiate_body'] );
        }

        if ( 200 !== (int) $scenario['initiate_status'] ) {
            // A rejection with no explicit body still carries a reason, the way
            // the real API does — that reason is what the plugin must surface.
            return self::response(
                (int) $scenario['initiate_status'],
                array( 'message' => 'Simulated Novac rejection (HTTP ' . $scenario['initiate_status'] . ').' )
            );
        }

        $body      = json_decode( (string) ( $parsed_args['body'] ?? '' ), true );
        $reference = $body['transactionReference'] ?? 'WOO_0_unknown';
        $callback  = $body['redirectUrl'] ?? home_url( '/' );

        return self::response(
            200,
            array(
                'status' => true,
                'data'   => array(
                    'paymentRedirectUrl'   => self::hosted_page_url( $reference, $callback ),
                    'transactionReference' => $reference,
                ),
            )
        );
    }

    /**
     * Build the response to a transaction-verification call.
     *
     * @param string $url      Request URL.
     * @param array  $scenario Active scenario.
     * @return array|WP_Error
     */
    private static function respond_to_verify( $url, array $scenario ) {
        $reference = self::reference_from_verify_url( $url );

        // The settings page probes with a reference that cannot belong to an
        // order; that call is the credential check, not a transaction lookup.
        if ( ! self::reference_maps_to_order( $reference ) ) {
            return self::response(
                (int) $scenario['probe_status'],
                array( 'message' => 'Transaction not found.' )
            );
        }

        if ( '' !== $scenario['verify_error'] ) {
            return new WP_Error( 'http_request_failed', $scenario['verify_error'] );
        }

        if ( 200 !== (int) $scenario['verify_status'] ) {
            return self::response(
                (int) $scenario['verify_status'],
                array( 'message' => 'Simulated verification failure.' )
            );
        }

        // A 200 carrying a body the plugin cannot use: empty, truncated, or an
        // HTML error page substituted by a proxy. Worth scripting on its own,
        // because this is the response the retry loop used to spin on forever
        // without ever counting an attempt.
        if ( null !== $scenario['verify_raw_body'] ) {
            return self::response( 200, (string) $scenario['verify_raw_body'] );
        }

        $order    = self::order_from_reference( $reference );
        $amount   = $scenario['verify_amount'];
        $currency = $scenario['verify_currency'];

        if ( null === $amount ) {
            $amount = $order ? $order->get_total() : '0';
        }

        if ( null === $currency ) {
            $currency = $order ? $order->get_currency() : get_woocommerce_currency();
        }

        return self::response(
            200,
            array(
                'status' => true,
                'data'   => array(
                    'status'    => $scenario['verify_txn_state'],
                    'amount'    => $amount,
                    'currency'  => $currency,
                    'reference' => $reference,
                ),
            )
        );
    }

    /**
     * Extract the transaction reference from a verify URL.
     *
     * @param string $url Request URL.
     * @return string
     */
    private static function reference_from_verify_url( string $url ): string {
        $path = (string) wp_parse_url( $url, PHP_URL_PATH );

        if ( preg_match( '#/checkout/([^/]+)/verify#', $path, $matches ) ) {
            return rawurldecode( $matches[1] );
        }

        return '';
    }

    /**
     * Whether a reference points at a real order in this store.
     *
     * @param string $reference Transaction reference.
     * @return bool
     */
    private static function reference_maps_to_order( string $reference ): bool {
        return null !== self::order_from_reference( $reference );
    }

    /**
     * Resolve the order a reference belongs to.
     *
     * @param string $reference Transaction reference.
     * @return WC_Order|null
     */
    private static function order_from_reference( string $reference ): ?WC_Order {
        if ( 0 !== strpos( $reference, 'WOO_' ) ) {
            return null;
        }

        $parts = explode( '_', $reference );

        if ( count( $parts ) < 2 ) {
            return null;
        }

        $order = wc_get_order( (int) $parts[1] );

        return $order instanceof WC_Order ? $order : null;
    }

    /**
     * URL of the stand-in hosted payment page.
     *
     * @param string $reference Transaction reference.
     * @param string $callback  URL the plugin expects the customer to return to.
     * @return string
     */
    private static function hosted_page_url( string $reference, string $callback ): string {
        return add_query_arg(
            array(
                'novac_e2e_pay' => '1',
                'reference'     => rawurlencode( $reference ),
                'callback'      => rawurlencode( $callback ),
            ),
            home_url( '/' )
        );
    }

    /**
     * Render the stand-in for Novac's hosted payment page.
     *
     * Gives the browser somewhere real to land after the redirect, with buttons
     * that return to the plugin's callback the way the real gateway would.
     *
     * @return void
     */
    public static function maybe_render_hosted_page(): void {
        if ( empty( $_GET['novac_e2e_pay'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
            return;
        }

        $reference = isset( $_GET['reference'] ) ? sanitize_text_field( rawurldecode( wp_unslash( $_GET['reference'] ) ) ) : '';
        $callback  = isset( $_GET['callback'] ) ? rawurldecode( wp_unslash( $_GET['callback'] ) ) : home_url( '/' );

        $pay    = add_query_arg( array( 'reference' => rawurlencode( $reference ) ), $callback );
        $cancel = add_query_arg(
            array(
                'reference' => rawurlencode( $reference ),
                'status'    => 'cancelled',
            ),
            $callback
        );

        header( 'Content-Type: text/html; charset=utf-8' );
        ?>
        <!doctype html>
        <html <?php language_attributes(); ?>>
        <head>
            <meta charset="utf-8" />
            <meta name="viewport" content="width=device-width, initial-scale=1" />
            <title>Novac E2E hosted payment page</title>
        </head>
        <body>
            <h1 data-testid="novac-hosted-page">Novac (E2E stand-in)</h1>
            <p>Reference: <code data-testid="novac-reference"><?php echo esc_html( $reference ); ?></code></p>
            <p>
                <a data-testid="novac-pay" href="<?php echo esc_url( $pay ); ?>">Complete payment</a>
            </p>
            <p>
                <a data-testid="novac-cancel" href="<?php echo esc_url( $cancel ); ?>">Cancel payment</a>
            </p>
        </body>
        </html>
        <?php
        exit;
    }

    /**
     * Store an intercepted request for later assertion.
     *
     * @param string $url         Request URL.
     * @param array  $parsed_args Request arguments.
     * @return void
     */
    private static function record( string $url, array $parsed_args ): void {
        $recorded = get_option( self::REQUESTS_OPTION, array() );
        $recorded = is_array( $recorded ) ? $recorded : array();

        $recorded[] = array(
            'url'     => $url,
            'method'  => $parsed_args['method'] ?? 'GET',
            'headers' => $parsed_args['headers'] ?? array(),
            'body'    => $parsed_args['body'] ?? null,
            'time'    => microtime( true ),
        );

        update_option( self::REQUESTS_OPTION, $recorded, false );
    }

    /**
     * Shape a value like a WP HTTP API response.
     *
     * @param int   $status HTTP status code.
     * @param mixed $body   Response body; arrays are JSON-encoded.
     * @return array
     */
    private static function response( int $status, $body ): array {
        return array(
            'headers'  => array( 'content-type' => 'application/json' ),
            'body'     => is_string( $body ) ? $body : wp_json_encode( $body ),
            'response' => array(
                'code'    => $status,
                'message' => get_status_header_desc( $status ),
            ),
            'cookies'  => array(),
            'filename' => null,
        );
    }

    /**
     * Register the control endpoints the test suite drives the harness with.
     *
     * @return void
     */
    public static function register_routes(): void {
        $auth = array( __CLASS__, 'check_token' );

        register_rest_route(
            'novac-e2e/v1',
            '/scenario',
            array(
                'methods'             => 'POST',
                'permission_callback' => $auth,
                'callback'            => static function ( WP_REST_Request $request ) {
                    $scenario = $request->get_json_params();
                    update_option( self::SCENARIO_OPTION, is_array( $scenario ) ? $scenario : array(), false );

                    return new WP_REST_Response( array( 'scenario' => self::scenario() ), 200 );
                },
            )
        );

        register_rest_route(
            'novac-e2e/v1',
            '/requests',
            array(
                'methods'             => 'GET',
                'permission_callback' => $auth,
                'callback'            => static function () {
                    $recorded = get_option( self::REQUESTS_OPTION, array() );

                    return new WP_REST_Response( is_array( $recorded ) ? $recorded : array(), 200 );
                },
            )
        );

        register_rest_route(
            'novac-e2e/v1',
            '/reset',
            array(
                'methods'             => 'POST',
                'permission_callback' => $auth,
                'callback'            => static function () {
                    delete_option( self::SCENARIO_OPTION );
                    delete_option( self::REQUESTS_OPTION );
                    self::clear_webhook_claims();

                    return new WP_REST_Response( array( 'reset' => true ), 200 );
                },
            )
        );

        // The webhook handler acknowledges immediately and queues the real work
        // on Action Scheduler. In production the queue is drained by a loopback
        // request; waiting on that in a test would be slow and flaky, so a spec
        // drains it explicitly and then asserts on the order.
        register_rest_route(
            'novac-e2e/v1',
            '/run-actions',
            array(
                'methods'             => 'POST',
                'permission_callback' => $auth,
                'callback'            => static function () {
                    return new WP_REST_Response( array( 'ran' => self::run_pending_webhook_actions() ), 200 );
                },
            )
        );

        /*
         * Resolve a caller address from synthetic request state.
         *
         * This one is deliberately not driven over real HTTP. The WordPress
         * Docker image enables Apache's mod_remoteip with the private ranges as
         * internal proxies, so it rewrites REMOTE_ADDR from X-Forwarded-For
         * before PHP ever runs — meaning a real request cannot exercise the
         * plugin's own trust decision. Feeding the values in directly tests the
         * logic that actually protects a store whose web server is not doing
         * that for it.
         */
        register_rest_route(
            'novac-e2e/v1',
            '/resolve-ip',
            array(
                'methods'             => 'POST',
                'permission_callback' => $auth,
                'callback'            => static function ( WP_REST_Request $request ) {
                    $params  = (array) $request->get_json_params();
                    $trusted = isset( $params['trusted'] ) ? (array) $params['trusted'] : array();

                    $override = static function () use ( $trusted ) {
                        return $trusted;
                    };

                    $original = $_SERVER;

                    $_SERVER['REMOTE_ADDR'] = (string) ( $params['remote_addr'] ?? '' );

                    if ( isset( $params['forwarded'] ) ) {
                        $_SERVER['HTTP_X_FORWARDED_FOR'] = (string) $params['forwarded'];
                    } else {
                        unset( $_SERVER['HTTP_X_FORWARDED_FOR'] );
                    }

                    // Priority 99 so this wins over the harness's own stand-in proxy.
                    add_filter( 'novac_woo_trusted_proxies', $override, 99 );

                    $gateways = WC()->payment_gateways()->payment_gateways();
                    $resolved = isset( $gateways['novac'] ) ? $gateways['novac']->novac_get_client_ip() : 'NO_GATEWAY';

                    remove_filter( 'novac_woo_trusted_proxies', $override, 99 );

                    $_SERVER = $original;

                    return new WP_REST_Response( array( 'ip' => $resolved ), 200 );
                },
            )
        );

        // Backdate queued jobs so a spec can reach the stalled-queue branches
        // without waiting out the real five-minute threshold.
        register_rest_route(
            'novac-e2e/v1',
            '/age-actions',
            array(
                'methods'             => 'POST',
                'permission_callback' => $auth,
                'callback'            => static function ( WP_REST_Request $request ) {
                    $seconds = (int) ( $request->get_json_params()['seconds'] ?? 600 );

                    return new WP_REST_Response( array( 'aged' => self::age_webhook_actions( $seconds ) ), 200 );
                },
            )
        );

        // Lets a test read gateway settings and order state without a browser.
        register_rest_route(
            'novac-e2e/v1',
            '/settings',
            array(
                'methods'             => 'GET',
                'permission_callback' => $auth,
                'callback'            => static function () {
                    return new WP_REST_Response( get_option( 'woocommerce_novac_settings', array() ), 200 );
                },
            )
        );

        register_rest_route(
            'novac-e2e/v1',
            '/settings',
            array(
                'methods'             => 'POST',
                'permission_callback' => $auth,
                'callback'            => static function ( WP_REST_Request $request ) {
                    $settings = $request->get_json_params();
                    $existing = get_option( 'woocommerce_novac_settings', array() );
                    $existing = is_array( $existing ) ? $existing : array();

                    update_option( 'woocommerce_novac_settings', array_merge( $existing, (array) $settings ) );

                    return new WP_REST_Response( get_option( 'woocommerce_novac_settings' ), 200 );
                },
            )
        );

        // Store-level switches a spec needs (currency, mainly). Restricted to
        // an allow-list so a stray call cannot rewrite arbitrary options.
        register_rest_route(
            'novac-e2e/v1',
            '/store',
            array(
                'methods'             => 'POST',
                'permission_callback' => $auth,
                'callback'            => static function ( WP_REST_Request $request ) {
                    $allowed = array( 'woocommerce_currency', 'woocommerce_default_country' );
                    $params  = (array) $request->get_json_params();
                    $applied = array();

                    foreach ( $params as $key => $value ) {
                        if ( in_array( $key, $allowed, true ) ) {
                            update_option( $key, sanitize_text_field( (string) $value ) );
                            $applied[ $key ] = get_option( $key );
                        }
                    }

                    return new WP_REST_Response( $applied, 200 );
                },
            )
        );

        register_rest_route(
            'novac-e2e/v1',
            '/order/(?P<id>\d+)',
            array(
                'methods'             => 'GET',
                'permission_callback' => $auth,
                'callback'            => static function ( WP_REST_Request $request ) {
                    $order = wc_get_order( (int) $request['id'] );

                    if ( ! $order instanceof WC_Order ) {
                        return new WP_REST_Response( array( 'error' => 'not found' ), 404 );
                    }

                    return new WP_REST_Response(
                        array(
                            'id'       => $order->get_id(),
                            'status'   => $order->get_status(),
                            'total'    => $order->get_total(),
                            'currency' => $order->get_currency(),
                            'method'   => $order->get_payment_method(),
                            'notes'    => array_map(
                                static function ( $note ) {
                                    return wp_strip_all_tags( $note->content );
                                },
                                wc_get_order_notes( array( 'order_id' => $order->get_id() ) )
                            ),
                        ),
                        200
                    );
                },
            )
        );

        // The most recent order, so a test can find what checkout just created.
        register_rest_route(
            'novac-e2e/v1',
            '/latest-order',
            array(
                'methods'             => 'GET',
                'permission_callback' => $auth,
                'callback'            => static function () {
                    $orders = wc_get_orders(
                        array(
                            'limit'   => 1,
                            'orderby' => 'date',
                            'order'   => 'DESC',
                        )
                    );

                    if ( empty( $orders ) ) {
                        return new WP_REST_Response( array( 'error' => 'no orders' ), 404 );
                    }

                    $order = $orders[0];

                    return new WP_REST_Response(
                        array(
                            'id'     => $order->get_id(),
                            'status' => $order->get_status(),
                            'total'  => $order->get_total(),
                            'method' => $order->get_payment_method(),
                        ),
                        200
                    );
                },
            )
        );
    }

    /**
     * Run every queued webhook job synchronously.
     *
     * Only the webhook hook is drained: the claim-expiry jobs are scheduled a
     * day out and running them early would defeat the idempotency they exist
     * to provide.
     *
     * @return int Number of actions run.
     */
    private static function run_pending_webhook_actions(): int {
        if ( ! class_exists( 'ActionScheduler' ) || ! class_exists( 'ActionScheduler_Store' ) ) {
            return 0;
        }

        $store  = ActionScheduler::store();
        $runner = ActionScheduler::runner();

$ran = 0;

do {
    $action_ids = $store->query_actions(
        array(
            'hook'     => 'novac_process_webhook',
            'status'   => ActionScheduler_Store::STATUS_PENDING,
            'per_page' => 50,
            'orderby'  => 'date',
            'order'    => 'ASC',
        )
    );

    foreach ( (array) $action_ids as $action_id ) {
        $runner->process_action( (int) $action_id, 'Novac E2E' );
        ++$ran;
    }
} while ( ! empty( $action_ids ) );

return $ran;
    }

    /**
     * Backdate the pending webhook jobs.
     *
     * The plugin only treats the queue as stalled once a job is overdue by
     * NOVAC_WOO_QUEUE_STALL_SECONDS. Moving the scheduled date backwards gets a
     * spec to that state immediately instead of sleeping through it.
     *
     * @param int $seconds How far back to move the scheduled dates.
     * @return int Rows changed.
     */
    private static function age_webhook_actions( int $seconds ): int {
        global $wpdb;

        // The plugin caches its stall verdict for a minute; drop it so the very
        // next request sees the state this call just created.
        delete_transient( 'novac_webhook_queue_stalled' );
        delete_transient( 'novac_webhook_sweep_lock' );

        $table = $wpdb->prefix . 'actionscheduler_actions';

        if ( $table !== $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) ) {
            return 0;
        }

        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        return (int) $wpdb->query(
            $wpdb->prepare(
                "UPDATE {$table}
                    SET scheduled_date_gmt   = DATE_SUB( scheduled_date_gmt, INTERVAL %d SECOND ),
                        scheduled_date_local = DATE_SUB( scheduled_date_local, INTERVAL %d SECOND )
                  WHERE hook = 'novac_process_webhook'
                    AND status = 'pending'",
                $seconds,
                $seconds
            )
        );
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
    }

    /**
     * Drop the idempotency claims and queue-health state left by earlier tests.
     *
     * @return void
     */
    private static function clear_webhook_claims(): void {
        global $wpdb;

        $names = $wpdb->get_col(
            "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE 'novac\\_wh\\_%'"
        );

        foreach ( (array) $names as $name ) {
            delete_option( $name );
        }

        delete_transient( 'novac_webhook_queue_stalled' );
        delete_transient( 'novac_webhook_queue_unhealthy' );
        delete_transient( 'novac_webhook_sweep_lock' );

        // A job left queued by the previous spec would read as a stalled queue
        // in the next one, so the suite starts each test with an empty queue.
        if ( function_exists( 'as_unschedule_all_actions' ) ) {
            as_unschedule_all_actions( 'novac_process_webhook' );
            as_unschedule_all_actions( 'novac_release_webhook_claim' );
        }
    }

    /**
     * Gate the control endpoints on the shared test token.
     *
     * @param WP_REST_Request $request Incoming request.
     * @return bool
     */
    public static function check_token( $request ): bool {
        if ( ! defined( 'NOVAC_E2E_TOKEN' ) ) {
            return false;
        }

        return hash_equals( (string) NOVAC_E2E_TOKEN, (string) $request->get_header( 'x-novac-e2e-token' ) );
    }
}

Novac_E2E_Harness::init();
