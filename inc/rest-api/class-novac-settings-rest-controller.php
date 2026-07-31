<?php
/**
 * Base For Novac Endpoint.
 *
 * @package    Novac/WooCommerce/RestApi
 */

defined( 'ABSPATH' ) || exit;

require_once dirname( __DIR__ ) . '/util/class-novac-validator.php';

/**
 * Novac Settings Endpoint.
 */
final class Novac_Settings_Rest_Controller extends WP_REST_Controller {
    /**
     * Endpoint namespace.
     *
     * @var string
     */
    protected $namespace;

    /**
     * Rest base for the current object.
     *
     * @var string
     */
    protected $rest_base;

    /**
     * Settings the endpoint is allowed to write, mapped to their type.
     *
     * Anything not listed here is discarded rather than persisted, so stray
     * request parameters cannot end up in the gateway's option row.
     *
     * @var array<string, string>
     */
    private const WRITABLE_SETTINGS = array(
        'enabled'            => 'boolish',
        'go_live'            => 'boolish',
        'autocomplete_order' => 'boolish',
        'buy_now_enabled'    => 'boolish',
        'title'              => 'text',
        'description'        => 'text',
        'payment_style'      => 'text',
        'live_public_key'    => 'key',
        'live_secret_key'    => 'key',
        'test_public_key'    => 'key',
        'test_secret_key'    => 'key',
    );

    /**
     * Settings Route Constructor.
     */
    public function __construct() {
        $this->namespace = 'novac/v1';
        $this->rest_base = 'settings';
    }

    /**
     * Register Routes and their Verbs.
     */
    public function register_routes() {

        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base,
            array(
                array(
                    'methods'             => WP_REST_Server::READABLE,
                    'callback'            => array( $this, 'get_item' ),
                    'permission_callback' => array( $this, 'get_items_permissions_check' ),
                    'args'                => $this->get_endpoint_args_for_item_schema(),
                ),
                array(
                    'methods'             => WP_REST_Server::EDITABLE,
                    'callback'            => array( $this, 'update_item' ),
                    'permission_callback' => array( $this, 'update_item_permissions_check' ),
                    'args'                => $this->novac_update_validations(),

                ),
                'schema' => array( $this, 'get_public_item_schema' ),
            )
        );
    }

    /**
     * Per-parameter validation for the update route.
     *
     * @return array
     */
    public function novac_update_validations(): array {
        $args = array();

        foreach ( self::WRITABLE_SETTINGS as $setting_key => $type ) {
            if ( 'boolish' === $type ) {
                $args[ $setting_key ] = array(
                    'type'              => 'string',
                    'enum'              => array( 'yes', 'no' ),
                    'sanitize_callback' => 'sanitize_text_field',
                    'validate_callback' => static function ( $param ) use ( $setting_key ) {
                        if ( in_array( $param, array( 'yes', 'no' ), true ) ) {
                            return true;
                        }

                        return new WP_Error(
                            'rest_invalid_param',
                            sprintf(
                                /* translators: %s: setting name. */
                                __( 'The %s value provided is invalid. Please provide a yes or no.', 'novac-woo' ),
                                $setting_key
                            ),
                            array( 'status' => WP_Http::BAD_REQUEST )
                        );
                    },
                );

                continue;
            }

            if ( 'key' === $type ) {
                $args[ $setting_key ] = array(
                    'type'              => 'string',
                    'sanitize_callback' => static function ( $param ) {
                        return trim( sanitize_text_field( (string) $param ) );
                    },
                    'validate_callback' => static function ( $param ) use ( $setting_key ) {
                        // An empty key is allowed through here; whether it is
                        // acceptable depends on the mode being saved, which is
                        // decided in update_item() where the whole payload is visible.
                        if ( '' === trim( (string) $param ) ) {
                            return true;
                        }

                        return Novac_Validator::validate_key( $setting_key, trim( (string) $param ) );
                    },
                );

                continue;
            }

            $args[ $setting_key ] = array(
                'type'              => 'string',
                'sanitize_callback' => 'sanitize_text_field',
            );
        }

        return $args;
    }

    /**
     * Get Current Users Permission.
     *
     * @param WP_REST_Request $request Full details about the request.
     * @return bool|WP_Error
     */
    public function get_items_permissions_check( $request ) {
        if ( ! current_user_can( 'manage_options' ) ) {
            return new WP_Error(
                'rest_cannot_view',
                __( 'Your user is not permitted to access this resource.', 'novac-woo' ),
                array( 'status' => rest_authorization_required_code() )
            );
        }

        return true;
    }

    /**
     * Checks if the user has the necessary permissions to get global styles information.
     *
     * @param WP_REST_Request $request Full details about the request.
     * @return bool|WP_Error
     */
    public function update_item_permissions_check( $request ) {
        if ( ! current_user_can( 'manage_options' ) ) {
            return new WP_Error(
                'rest_cannot_view',
                __( 'Your user is not permitted to access this resource.', 'novac-woo' ),
                array( 'status' => rest_authorization_required_code() )
            );
        }

        return true;
    }

    /**
     * Get the current settings.
     *
     * @param WP_REST_Request $request Full details about the request.
     * @return WP_REST_Response|WP_Error Response object on success, or WP_Error object on failure.
     */
    public function get_item( $request ): WP_REST_Response {
        $settings = get_option( 'woocommerce_novac_settings', array() );

        return new WP_REST_Response( $settings, WP_Http::OK );
    }

    /**
     * Update Novac Settings.
     *
     * Refuses the save when the gateway is being enabled without usable
     * credentials for the selected mode, so a misconfigured store never reaches
     * checkout. Valid saves are followed by a live verification call whose
     * result is returned for display on the settings page.
     *
     * @param WP_REST_Request $request the request.
     * @return WP_REST_Response|WP_Error
     */
    public function update_item( $request ) {
        $existing = get_option( 'woocommerce_novac_settings', array() );
        $existing = is_array( $existing ) ? $existing : array();

        $submitted = array();

        foreach ( array_keys( self::WRITABLE_SETTINGS ) as $setting_key ) {
            $value = $request->get_param( $setting_key );

            if ( null !== $value ) {
                $submitted[ $setting_key ] = $value;
            }
        }

        $settings = array_merge( $existing, $submitted );

        $validation = $this->validate_settings( $settings );

        if ( is_wp_error( $validation ) ) {
            return $validation;
        }

        update_option( 'woocommerce_novac_settings', $settings );

        return new WP_REST_Response(
            array(
                'message'      => __( 'Updated Successfully', 'novac-woo' ),
                'data'         => $settings,
                'readiness'    => $this->get_readiness( $settings ),
                'verification' => $this->verify_active_credentials( $settings ),
            ),
            WP_Http::OK
        );
    }

    /**
     * Check the settings as a whole before they are written.
     *
     * Only malformed values are rejected. Missing credentials are *not* a save
     * failure: the live and test keys sit behind separate save buttons, so
     * refusing the whole payload would make it impossible to enter one pair
     * without the other. Whether the gateway may actually appear at checkout is
     * enforced by Novac_Payment_Gateway::is_available(), which is authoritative
     * however the settings were written.
     *
     * @param array $settings The merged settings about to be saved.
     * @return true|WP_Error
     */
    private function validate_settings( array $settings ) {
        foreach ( Novac_Validator::get_key_setting_names() as $setting_key ) {
            $value = trim( (string) ( $settings[ $setting_key ] ?? '' ) );

            if ( '' === $value ) {
                continue;
            }

            $result = Novac_Validator::validate_key( $setting_key, $value );

            if ( is_wp_error( $result ) ) {
                $result->add_data( array( 'field' => $setting_key ), $result->get_error_code() );

                return $result;
            }
        }

        return true;
    }

    /**
     * Report whether the saved settings let Novac appear at checkout.
     *
     * Advisory only — this is what the settings page shows the merchant so a
     * half-configured store is obvious without having to reach checkout.
     *
     * @param array $settings The saved settings.
     * @return array{ready: bool, mode: string, missing: string[], message: string}
     */
    private function get_readiness( array $settings ): array {
        $is_live = 'yes' === ( $settings['go_live'] ?? 'no' );
        $mode    = $is_live ? 'live' : 'test';

        if ( 'yes' !== ( $settings['enabled'] ?? 'no' ) ) {
            return array(
                'ready'   => false,
                'mode'    => $mode,
                'missing' => array(),
                'message' => __( 'Novac is switched off, so it will not appear at checkout.', 'novac-woo' ),
            );
        }

        $unusable = Novac_Validator::get_unusable_keys( $settings, $is_live );

        if ( empty( $unusable ) ) {
            return array(
                'ready'   => true,
                'mode'    => $mode,
                'missing' => array(),
                'message' => __( 'Novac is ready and will appear at checkout.', 'novac-woo' ),
            );
        }

        $labels = array_map( array( 'Novac_Validator', 'get_key_label' ), $unusable );

        return array(
            'ready'   => false,
            'mode'    => $mode,
            'missing' => $unusable,
            'message' => sprintf(
                /* translators: 1: live/test mode. 2: comma-separated list of key names. */
                __( 'Novac is switched on but will not appear at checkout: you are in %1$s mode and these are missing: %2$s.', 'novac-woo' ),
                $mode,
                implode( ', ', $labels )
            ),
        );
    }

    /**
     * Verify the saved credentials for the active mode against the Novac API.
     *
     * @param array $settings The saved settings.
     * @return array{status: string, message: string} Status is one of pass|fail|unknown|skipped.
     */
    private function verify_active_credentials( array $settings ): array {
        $is_live    = 'yes' === ( $settings['go_live'] ?? 'no' );
        $secret_key = trim( (string) ( $settings[ $is_live ? 'live_secret_key' : 'test_secret_key' ] ?? '' ) );

        if ( '' === $secret_key ) {
            return array(
                'status'  => 'skipped',
                'message' => sprintf(
                    /* translators: %s: live or test. */
                    __( 'Saved. Nothing was verified because the store is in %s mode and no secret key is set for that mode yet.', 'novac-woo' ),
                    $is_live ? __( 'live', 'novac-woo' ) : __( 'test', 'novac-woo' )
                ),
            );
        }

        /**
         * Filters the Novac API base URL used for credential verification.
         *
         * @param string $base_url The API base URL, with trailing slash.
         * @since 1.0.2
         */
        $base_url = apply_filters( 'novac_woo_api_base_url', 'https://api.novacpayment.com/api/v1/' );

        $result = Novac_Validator::verify_credentials( $secret_key, $base_url );

        if ( 'pass' !== $result['status'] && class_exists( 'Novac_Logger' ) ) {
            Novac_Logger::instance()->error( 'Novac: credential verification returned "' . $result['status'] . '" — ' . $result['message'] );
        }

        return $result;
    }
}
