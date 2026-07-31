<?php
/**
 * Configuration-time validation for the Novac gateway.
 *
 * Centralises the checks that must happen *before* a customer reaches the
 * payment step: credential presence/format, live credential verification and
 * per-currency minimum chargeable amounts.
 *
 * @class          Novac_Validator
 * @package    Novac/WooCommerce
 * @subpackage Novac/WooCommerce/util
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

/**
 * Validation helpers shared by the gateway, the REST controller and the blocks integration.
 *
 * @since 1.0.2
 */
final class Novac_Validator {

    /**
     * Expected prefix for each key, indexed by "<mode>_<type>".
     *
     * @var array<string, string>
     */
    private const KEY_PREFIXES = array(
        'live_public' => 'nc_livepk_',
        'live_secret' => 'nc_livesk_',
        'test_public' => 'nc_testpk_',
        'test_secret' => 'nc_testsk_',
    );

    /**
     * Minimum length of a key, including its prefix.
     *
     * Keys are a prefix plus an opaque token; anything shorter than this is a
     * truncated paste rather than a real credential.
     *
     * @var int
     */
    private const KEY_MIN_LENGTH = 20;

    /**
     * Minimum chargeable amount per currency, in major units.
     *
     * NGN 100 is the only threshold confirmed against the Novac API. Currencies
     * absent from this map are treated as having no known minimum, so the
     * gateway is not hidden on a guess. Extend via the
     * `novac_woo_minimum_amounts` filter as thresholds are confirmed.
     *
     * @var array<string, float>
     */
    private const MINIMUM_AMOUNTS = array(
        'NGN' => 100.00,
    );

    /**
     * Human-readable label for a settings key.
     *
     * @param string $setting_key One of the four key setting names.
     * @return string
     */
    public static function get_key_label( string $setting_key ): string {
        $labels = array(
            'live_public_key' => __( 'Live Public Key', 'novac-woo' ),
            'live_secret_key' => __( 'Live Secret Key', 'novac-woo' ),
            'test_public_key' => __( 'Test Public Key', 'novac-woo' ),
            'test_secret_key' => __( 'Test Secret Key', 'novac-woo' ),
        );

        return $labels[ $setting_key ] ?? $setting_key;
    }

    /**
     * The expected prefix for a given key setting.
     *
     * @param string $setting_key One of the four key setting names.
     * @return string Empty string when the setting is not a Novac key.
     */
    public static function get_expected_prefix( string $setting_key ): string {
        $lookup = str_replace( '_key', '', $setting_key );

        return self::KEY_PREFIXES[ $lookup ] ?? '';
    }

    /**
     * The four key setting names, in display order.
     *
     * @return string[]
     */
    public static function get_key_setting_names(): array {
        return array( 'live_public_key', 'live_secret_key', 'test_public_key', 'test_secret_key' );
    }

    /**
     * Validate the format of a single API key.
     *
     * @param string $setting_key One of the four key setting names.
     * @param mixed  $value       The submitted value.
     * @return true|WP_Error True when valid, WP_Error describing the problem otherwise.
     */
    public static function validate_key( string $setting_key, $value ) {
        $label  = self::get_key_label( $setting_key );
        $prefix = self::get_expected_prefix( $setting_key );

        if ( '' === $prefix ) {
            // Not a key field; nothing to validate.
            return true;
        }

        if ( ! is_string( $value ) || '' === trim( $value ) ) {
            return new WP_Error(
                'novac_key_required',
                sprintf(
                    /* translators: %s: name of the key field, e.g. "Live Secret Key". */
                    __( '%s is required.', 'novac-woo' ),
                    $label
                ),
                array( 'status' => WP_Http::BAD_REQUEST )
            );
        }

        $value = trim( $value );

        if ( 0 !== strpos( $value, $prefix ) ) {
            return new WP_Error(
                'novac_key_prefix',
                sprintf(
                    /* translators: 1: name of the key field. 2: expected prefix, e.g. "nc_livesk_". */
                    __( '%1$s does not look right — it should start with "%2$s". Copy the key again from your Novac merchant dashboard.', 'novac-woo' ),
                    $label,
                    $prefix
                ),
                array( 'status' => WP_Http::BAD_REQUEST )
            );
        }

        if ( strlen( $value ) < self::KEY_MIN_LENGTH ) {
            return new WP_Error(
                'novac_key_length',
                sprintf(
                    /* translators: %s: name of the key field. */
                    __( '%s looks incomplete. Copy the whole key from your Novac merchant dashboard.', 'novac-woo' ),
                    $label
                ),
                array( 'status' => WP_Http::BAD_REQUEST )
            );
        }

        return true;
    }

    /**
     * Check whether a key is present and correctly formatted.
     *
     * @param string $setting_key One of the four key setting names.
     * @param mixed  $value       The stored value.
     * @return bool
     */
    public static function is_key_usable( string $setting_key, $value ): bool {
        return true === self::validate_key( $setting_key, $value );
    }

    /**
     * Names of the keys required for a given mode.
     *
     * @param bool $is_live Whether the gateway is in live mode.
     * @return string[]
     */
    public static function get_required_keys_for_mode( bool $is_live ): array {
        return $is_live
            ? array( 'live_public_key', 'live_secret_key' )
            : array( 'test_public_key', 'test_secret_key' );
    }

    /**
     * Find the keys that are missing or malformed for the active mode.
     *
     * @param array $settings The gateway settings array.
     * @param bool  $is_live  Whether the gateway is in live mode.
     * @return string[] Setting names that fail validation. Empty when the mode is fully configured.
     */
    public static function get_unusable_keys( array $settings, bool $is_live ): array {
        $unusable = array();

        foreach ( self::get_required_keys_for_mode( $is_live ) as $setting_key ) {
            if ( ! self::is_key_usable( $setting_key, $settings[ $setting_key ] ?? '' ) ) {
                $unusable[] = $setting_key;
            }
        }

        return $unusable;
    }

    /**
     * The minimum chargeable amount for a currency.
     *
     * @param string $currency ISO 4217 currency code.
     * @return float|null The minimum in major units, or null when no minimum is known.
     */
    public static function get_minimum_amount( string $currency ): ?float {
        /**
         * Filters the minimum chargeable amount per currency.
         *
         * Only currencies present in the returned map are enforced. Add an entry
         * to enforce a threshold for a currency the plugin does not yet know about.
         *
         * @param array<string, float> $minimums Map of ISO 4217 code to minimum amount in major units.
         * @since 1.0.2
         */
        $minimums = apply_filters( 'novac_woo_minimum_amounts', self::MINIMUM_AMOUNTS );

        $currency = strtoupper( $currency );

        if ( ! isset( $minimums[ $currency ] ) ) {
            return null;
        }

        return (float) $minimums[ $currency ];
    }

    /**
     * Whether an amount clears the minimum for its currency.
     *
     * Amounts of zero (free orders, or contexts where no total is available) are
     * treated as passing, so the gateway is not hidden outside a real checkout.
     *
     * @param float  $amount   Amount in major units.
     * @param string $currency ISO 4217 currency code.
     * @return bool
     */
    public static function meets_minimum_amount( float $amount, string $currency ): bool {
        $minimum = self::get_minimum_amount( $currency );

        if ( null === $minimum || $amount <= 0 ) {
            return true;
        }

        return $amount >= $minimum;
    }

    /**
     * Verify a secret key against the Novac API.
     *
     * Novac exposes no dedicated credential-check endpoint, so this probes the
     * verify endpoint with a reference that cannot exist. The transaction lookup
     * is expected to fail; what matters is *how* it fails. A 401/403 means the
     * credential was rejected, anything else means it was accepted and the
     * lookup simply found nothing. Transport failures are reported as unknown
     * rather than as a bad key, so a blip does not block a valid save.
     *
     * @param string $secret_key The secret key to verify.
     * @param string $base_url   The Novac API base URL, with trailing slash.
     * @return array{status: string, message: string} Status is one of pass|fail|unknown.
     */
    public static function verify_credentials( string $secret_key, string $base_url ): array {
        if ( '' === trim( $secret_key ) ) {
            return array(
                'status'  => 'fail',
                'message' => __( 'No secret key to verify.', 'novac-woo' ),
            );
        }

        $probe_reference = 'WOO_0_' . wp_generate_password( 12, false );

        $response = wp_safe_remote_request(
            $base_url . 'checkout/' . rawurlencode( $probe_reference ) . '/verify',
            array(
                'method'  => 'GET',
                'timeout' => 15,
                'headers' => array(
                    'Content-Type'  => 'application/json',
                    'Authorization' => 'Bearer ' . $secret_key,
                ),
            )
        );

        if ( is_wp_error( $response ) ) {
            return array(
                'status'  => 'unknown',
                'message' => sprintf(
                    /* translators: %s: transport error message. */
                    __( 'Could not reach Novac to verify the key (%s). The key was saved but has not been checked.', 'novac-woo' ),
                    $response->get_error_message()
                ),
            );
        }

        $code = (int) wp_remote_retrieve_response_code( $response );

        if ( in_array( $code, array( 401, 403 ), true ) ) {
            return array(
                'status'  => 'fail',
                'message' => __( 'Novac rejected this secret key. Check that you copied the right key for the selected mode.', 'novac-woo' ),
            );
        }

        if ( $code >= 500 ) {
            return array(
                'status'  => 'unknown',
                'message' => sprintf(
                    /* translators: %s: HTTP status code. */
                    __( 'Novac returned a server error (HTTP %s) during verification. The key was saved but has not been checked.', 'novac-woo' ),
                    $code
                ),
            );
        }

        return array(
            'status'  => 'pass',
            'message' => __( 'Novac accepted this secret key.', 'novac-woo' ),
        );
    }

    /**
     * Extract a human-readable error message from a Novac API response body.
     *
     * @param string $body Raw response body.
     * @return string Empty string when no message could be found.
     */
    public static function extract_api_message( string $body ): string {
        $decoded = json_decode( $body );

        if ( ! is_object( $decoded ) ) {
            return '';
        }

        foreach ( array( 'message', 'error', 'detail' ) as $field ) {
            if ( ! empty( $decoded->$field ) && is_string( $decoded->$field ) ) {
                return sanitize_text_field( $decoded->$field );
            }
        }

        if ( ! empty( $decoded->data->message ) && is_string( $decoded->data->message ) ) {
            return sanitize_text_field( $decoded->data->message );
        }

        return '';
    }
}
