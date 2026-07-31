import { registerPlugin } from "@wordpress/plugins";
import { addFilter } from "@wordpress/hooks";
import { __, sprintf } from '@wordpress/i18n';
import { useState } from '@wordpress/element';
import { Fragment } from '@wordpress/element';
import {
    Panel,
    PanelBody,
    CheckboxControl,
    ToggleControl,
    SelectControl,
} from '@wordpress/components';
import { WooNavigationItem } from "@woocommerce/navigation";
import apiFetch from '@wordpress/api-fetch';

import strings from './strings';
import Page from 'wcnovac/admin/components/page';
import Input from 'wcnovac/admin/components/input';

import './index.scss';

const NAMESPACE = "novac/v1";
const ENDPOINT  = "/settings";

// ─── Brand tokens (inline so they always win over WP admin CSS) ───────────────
const C = {
    dark:        '#0A0E1A',
    navy:        '#141827',
    green:       '#C3D364',
    greenDim:    'rgba(110,231,96,0.14)',
    greenBorder: 'rgba(110,231,96,0.30)',
    white:       '#FFFFFF',
    muted:       '#8997AE',
    border:      'rgba(255,255,255,0.08)',
    orange:      '#FFBA3E',
    orangeDim:   'rgba(255,186,62,0.12)',
    orangeBorder:'rgba(255,186,62,0.28)',
    red:         '#FF6E6E',
    redDim:      'rgba(255,110,110,0.12)',
    redBorder:   'rgba(255,110,110,0.28)',
};

const badge = (bg, color, border) => ({
    display: 'inline-flex',
    alignItems: 'center',
    gap: '6px',
    padding: '4px 12px',
    borderRadius: '20px',
    fontSize: '12px',
    fontWeight: 600,
    background: bg,
    color,
    border: `1px solid ${border}`,
});

// ─── Sub-components ───────────────────────────────────────────────────────────

const NovacHero = ({ firstName, pluginUrl }) => (
    <div style={{
        position: 'relative',
        borderRadius: '10px',
        overflow: 'hidden',
        marginBottom: '18px',
        boxShadow: '0 4px 24px rgba(0,0,0,0.30)',
    }}>
        <img
            src={ `${pluginUrl}/assets/img/WP_banner_772x250.png` }
            alt="Safe and Secure Checkout by Novac"
            style={{ width: '100%', height: 'auto', display: 'block' }}
        />
        <div style={{
            position: 'absolute',
            bottom: 0, left: 0, right: 0,
            padding: '20px 28px',
            background: 'linear-gradient(transparent, rgba(10,14,26,0.92))',
            display: 'flex',
            flexDirection: 'column',
            gap: '3px',
        }}>
            <span style={{ color: C.white, fontWeight: 700, fontSize: '15px', lineHeight: 1.4 }}>
                { `Hi ${ firstName }, welcome back!` }
            </span>
            <span style={{ color: 'rgba(255,255,255,0.60)', fontSize: '12.5px' }}>
                { __( 'Manage your Novac payment gateway settings below.', 'novac-woo' ) }
            </span>
        </div>
    </div>
);

const Dot = ({ color }) => (
    <span style={{
        display: 'inline-block',
        width: '7px', height: '7px',
        borderRadius: '50%',
        background: color,
        boxShadow: `0 0 5px ${color}`,
        flexShrink: 0,
    }} />
);

const NovacStatusBar = ({ isEnabled, isLive, readiness }) => {
    // "Active" must mean "actually offered at checkout". When keys are missing
    // the gateway is hidden, so say so rather than showing a green light.
    const notReady = isEnabled && !! readiness;

    let statusStyle = badge(C.redDim, C.red, C.redBorder);
    let statusColor = C.red;
    let statusLabel = __( 'Inactive', 'novac-woo' );

    if ( notReady ) {
        statusStyle = badge(C.orangeDim, C.orange, C.orangeBorder);
        statusColor = C.orange;
        statusLabel = __( 'Not at checkout', 'novac-woo' );
    } else if ( isEnabled ) {
        statusStyle = badge(C.greenDim, C.green, C.greenBorder);
        statusColor = C.green;
        statusLabel = __( 'Active', 'novac-woo' );
    }

    return (
        <div style={{
            display: 'flex',
            alignItems: 'center',
            gap: '14px',
            padding: '12px 20px',
            background: C.navy,
            border: `1px solid ${C.border}`,
            borderRadius: '8px',
            marginBottom: '16px',
        }}>
            <span style={{
                fontSize: '11px', fontWeight: 700,
                textTransform: 'uppercase', letterSpacing: '0.6px',
                color: C.muted, flexShrink: 0,
            }}>
                { __( 'Status', 'novac-woo' ) }
            </span>

            <span style={ statusStyle }>
                <Dot color={ statusColor } />
                { statusLabel }
            </span>

            <span style={ badge(isLive ? C.greenDim : C.orangeDim, isLive ? C.green : C.orange, isLive ? C.greenBorder : C.orangeBorder) }>
                <Dot color={ isLive ? C.green : C.orange } />
                { isLive ? __( 'Live Mode', 'novac-woo' ) : __( 'Test Mode', 'novac-woo' ) }
            </span>
        </div>
    );
};

/**
 * Always-visible warning naming exactly which keys are missing and where they live.
 *
 * The panels are collapsible, so this sits above them: a merchant filling in
 * live keys must not have to open the Test Mode accordion to discover why the
 * gateway still is not appearing at checkout.
 *
 * @param {Object} props           Component props.
 * @param {Object} props.readiness Result of getReadiness(), or null.
 */
const NovacReadinessWarning = ({ readiness }) => {
    if ( ! readiness ) {
        return null;
    }

    const { isLive, labels } = readiness;

    const where = isLive
        ? __( 'Add them under "API/Webhook Settings".', 'novac-woo' )
        : __( 'Add them under "Test Mode" below, or switch to Live mode if you are using live keys.', 'novac-woo' );

    return (
        <div
            role="status"
            style={{
                display: 'flex',
                alignItems: 'flex-start',
                gap: '10px',
                margin: '0 0 16px',
                padding: '12px 16px',
                background: '#fffbf0',
                borderLeft: `3px solid ${ C.orange }`,
                borderRadius: '0 5px 5px 0',
                fontSize: '13px',
                lineHeight: 1.55,
                color: '#7a5200',
            }}
        >
            <span aria-hidden="true" style={{ fontWeight: 700, flexShrink: 0 }}>!</span>
            <span>
                <strong>
                    { sprintf(
                        /* translators: %s: live or test. */
                        __( 'Novac is switched on but is not appearing at checkout, because you are in %s mode and these are missing:', 'novac-woo' ),
                        isLive ? __( 'Live', 'novac-woo' ) : __( 'Test', 'novac-woo' )
                    ) }
                </strong>
                { ` ${ labels.join( ', ' ) }. ${ where }` }
            </span>
        </div>
    );
};

const NovacWebhookBox = ({ url }) => {
    const [copied, setCopied] = useState(false);

    const handleCopy = () => {
        navigator.clipboard.writeText(url).then(() => {
            setCopied(true);
            setTimeout(() => setCopied(false), 2200);
        });
    };

    return (
        <div style={{
            background: 'rgba(10,14,26,0.55)',
            border: `1px solid ${C.greenBorder}`,
            borderRadius: '8px',
            padding: '16px',
            margin: '18px 0',
        }}>
            <span style={{
                display: 'block',
                fontSize: '10.5px', fontWeight: 700,
                textTransform: 'uppercase', letterSpacing: '0.9px',
                color: C.green, marginBottom: '10px',
            }}>
                { __( 'Webhook URL', 'novac-woo' ) }
            </span>

            <div style={{ display: 'flex', gap: '10px', alignItems: 'stretch' }}>
                <code style={{
                    flex: 1,
                    fontFamily: "'SFMono-Regular', Consolas, monospace",
                    fontSize: '11.5px',
                    color: 'rgba(255,255,255,0.85)',
                    background: 'rgba(0,0,0,0.30)',
                    padding: '9px 12px',
                    borderRadius: '5px',
                    border: `1px solid ${C.border}`,
                    wordBreak: 'break-all',
                    lineHeight: 1.5,
                }}>
                    { url }
                </code>

                <button
                    onClick={ handleCopy }
                    style={{
                        flexShrink: 0,
                        padding: '8px 16px',
                        border: `1px solid ${C.greenBorder}`,
                        borderRadius: '5px',
                        background: copied ? C.greenDim : 'transparent',
                        color: C.green,
                        fontSize: '12px', fontWeight: 600,
                        cursor: 'pointer',
                        transition: 'background 0.15s ease',
                    }}
                >
                    { copied ? __( '✓ Copied!', 'novac-woo' ) : __( 'Copy', 'novac-woo' ) }
                </button>
            </div>

            <p style={{ margin: '10px 0 0', fontSize: '12px', color: C.red, lineHeight: 1.5 }}>
                { __( 'Paste this URL in the Webhook section of your ', 'novac-woo' ) }
                <a
                    href="https://merchant.novac.com/merchant/settings"
                    target="_blank"
                    rel="noreferrer"
                    style={{ color: C.green, textDecoration: 'none' }}
                >
                    { __( 'Novac Merchant Dashboard', 'novac-woo' ) }
                </a>.
            </p>
        </div>
    );
};

const NovacSaveButton = ({ children, onClick }) => {
    const [isBusy, setIsBusy] = useState(false);

    return (
        <button
            disabled={ isBusy }
            onClick={ () => { setIsBusy(true); onClick(setIsBusy); } }
            style={{
                padding: '9px 22px',
                background: isBusy ? 'rgba(110,231,96,0.5)' : C.green,
                color: C.dark,
                border: 'none',
                borderRadius: '6px',
                fontWeight: 700,
                fontSize: '13px',
                cursor: isBusy ? 'not-allowed' : 'pointer',
                transition: 'background 0.15s ease, transform 0.1s ease',
            }}
        >
            { isBusy ? __( 'Saving…', 'novac-woo' ) : children }
        </button>
    );
};

const TestModeButton = ({ onClick, isLive }) => {
    const style = isLive
        ? { background: 'transparent', color: '#FF6E6E', border: '1px solid rgba(255,110,110,0.4)' }
        : { background: 'transparent', color: C.green, border: `1px solid ${C.greenBorder}` };

    return (
        <button
            onClick={ onClick }
            style={{
                ...style,
                padding: '9px 22px',
                borderRadius: '6px',
                fontWeight: 700,
                fontSize: '13px',
                cursor: 'pointer',
            }}
        >
            { isLive ? strings.button.enable_test_mode : strings.button.disable_test_mode }
        </button>
    );
};

/**
 * Inline result banner for save and credential-verification outcomes.
 *
 * @param {Object} props         Component props.
 * @param {string} props.status  One of success|error|warning.
 * @param {string} props.message Message to display.
 */
const NovacResultBanner = ({ status, message }) => {
    if ( ! message ) {
        return null;
    }

    const palette = {
        success: { bg: '#f0f8ea', border: '#7ba428', text: '#3c5215', icon: '✓' },
        error:   { bg: '#fdf0f0', border: '#d63638', text: '#8a1f21', icon: '✕' },
        warning: { bg: '#fffbf0', border: C.orange, text: '#7a5200', icon: '!' },
    }[ status ] ?? { bg: '#f0f6fc', border: '#72aee6', text: '#1d3c56', icon: 'i' };

    return (
        <div
            role={ status === 'error' ? 'alert' : 'status' }
            style={{
                display: 'flex',
                alignItems: 'flex-start',
                gap: '10px',
                margin: '0 0 16px',
                padding: '11px 14px',
                background: palette.bg,
                borderLeft: `3px solid ${ palette.border }`,
                borderRadius: '0 5px 5px 0',
                fontSize: '13px',
                lineHeight: 1.5,
                color: palette.text,
            }}
        >
            <span aria-hidden="true" style={{ fontWeight: 700, flexShrink: 0 }}>{ palette.icon }</span>
            <span>{ message }</span>
        </div>
    );
};

const PanelActions = ({ children }) => (
    <div style={{ marginTop: '22px', display: 'flex', justifyContent: 'flex-end' }}>
        { children }
    </div>
);

const SectionHint = ({ children }) => (
    <p style={{ fontSize: '13px', color: '#666', marginBottom: '18px', lineHeight: 1.5 }}>
        { children }
    </p>
);

// ─── Validation ───────────────────────────────────────────────────────────────
// Mirrors Novac_Validator on the PHP side. Each field has its own prefix — a
// live field must not accept a test key, which is a common cause of a gateway
// that looks configured but is rejected at payment time.
const KEY_PREFIXES = {
    live_public_key: 'nc_livepk_',
    live_secret_key: 'nc_livesk_',
    test_public_key: 'nc_testpk_',
    test_secret_key: 'nc_testsk_',
};

const KEY_LABELS = {
    live_public_key: __( 'Live Public Key', 'novac-woo' ),
    live_secret_key: __( 'Live Secret Key', 'novac-woo' ),
    test_public_key: __( 'Test Public Key', 'novac-woo' ),
    test_secret_key: __( 'Test Secret Key', 'novac-woo' ),
};

const KEY_MIN_LENGTH = 20;

/**
 * Validate one key field.
 *
 * @param {string}  settingKey One of the four key setting names.
 * @param {string}  value      The current field value.
 * @param {boolean} required   Whether an empty value is an error.
 * @return {string} An error message, or '' when the value is acceptable.
 */
const validateKey = ( settingKey, value, required = true ) => {
    const prefix = KEY_PREFIXES[ settingKey ];

    if ( ! prefix ) {
        return '';
    }

    const trimmed = ( value ?? '' ).trim();
    const label   = KEY_LABELS[ settingKey ] ?? settingKey;

    if ( ! trimmed ) {
        return required
            ? sprintf( /* translators: %s: field name. */ __( '%s is required.', 'novac-woo' ), label )
            : '';
    }

    if ( ! trimmed.startsWith( prefix ) ) {
        return sprintf(
            /* translators: 1: field name. 2: expected prefix. */
            __( '%1$s should start with "%2$s".', 'novac-woo' ),
            label,
            prefix
        );
    }

    if ( trimmed.length < KEY_MIN_LENGTH ) {
        return sprintf(
            /* translators: %s: field name. */
            __( '%s looks incomplete — copy the whole key.', 'novac-woo' ),
            label
        );
    }

    return '';
};

// Key fields owned by each panel. A panel's save button only validates its own
// fields — a missing test key must never block saving live keys, because the
// two live behind separate save buttons in separate accordions.
const PANEL_KEY_FIELDS = {
    api:      [ 'live_secret_key', 'live_public_key' ],
    test:     [ 'test_secret_key', 'test_public_key' ],
    checkout: [],
};

/**
 * Validate the key fields belonging to one panel.
 *
 * Only the format is checked. An empty field is not an error here: whether the
 * gateway has what it needs is reported by getReadiness() as a warning, and
 * enforced for real by the gateway's is_available() on the server.
 *
 * @param {Object} settings The settings about to be saved.
 * @param {Array}  fields   Key field names owned by the saving panel.
 * @return {Object} Map of field name to error message. Empty when valid.
 */
const validatePanel = ( settings, fields ) => {
    const errors = {};

    fields.forEach( ( settingKey ) => {
        const message = validateKey( settingKey, settings[ settingKey ], false );

        if ( message ) {
            errors[ settingKey ] = message;
        }
    } );

    return errors;
};

/**
 * Report whether Novac can actually appear at checkout.
 *
 * @param {Object} settings The current settings.
 * @return {Object|null} Details of what is missing, or null when ready.
 */
const getReadiness = ( settings ) => {
    if ( settings.enabled !== 'yes' ) {
        return null;
    }

    const isLive   = settings.go_live === 'yes';
    const required = isLive
        ? [ 'live_public_key', 'live_secret_key' ]
        : [ 'test_public_key', 'test_secret_key' ];

    const missing = required.filter(
        ( settingKey ) => validateKey( settingKey, settings[ settingKey ], true ) !== ''
    );

    if ( missing.length === 0 ) {
        return null;
    }

    return {
        isLive,
        missing,
        labels: missing.map( ( settingKey ) => KEY_LABELS[ settingKey ] ?? settingKey ),
    };
};

// ─── Save helper ──────────────────────────────────────────────────────────────
/**
 * Persist settings, refusing to send a payload the server would reject.
 *
 * @param {Object}   data      The settings to save.
 * @param {Function} setIsBusy Busy-state setter from the save button.
 * @param {Object}   handlers  onInvalid/onError/onSuccess callbacks.
 * @param {Array}    fields    Key field names owned by the saving panel.
 */
const saveSettings = ( data, setIsBusy, handlers = {}, fields = [] ) => {
    const { onInvalid, onError, onSuccess } = handlers;

    const errors = validatePanel( data, fields );

    if ( Object.keys( errors ).length > 0 ) {
        onInvalid?.( errors );
        setIsBusy( false );
        return;
    }

    apiFetch({
        path: NAMESPACE + ENDPOINT,
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': wpApiSettings.nonce },
        data,
    })
        .then( ( response ) => {
            onSuccess?.( response );
            setIsBusy( false );
        } )
        .catch( ( err ) => {
            console.error( 'Novac: error saving settings', err );
            onError?.( err );
            setIsBusy( false );
        } );
};

// ─── Main component ───────────────────────────────────────────────────────────
const NovacSettings = () => {
    const default_settings = novacData?.novac_defaults;
    const firstName        = wcSettings.admin?.currentUserData?.first_name || 'there';
    const pluginUrl        = novacData?.asset_plugin_url ?? '';

    const [novacSettings, setNovacSettings] = useState(default_settings);
    const [errors, setErrors] = useState({
        live_secret_key: '', live_public_key: '',
        test_secret_key: '', test_public_key: '',
    });
    const [banner, setBanner] = useState(null);
    // Which panel a banner belongs to, so a save result is reported next to the
    // button that triggered it rather than in all three panels at once.
    const [activePanel, setActivePanel] = useState(null);

    const handleChange = (key, value) =>
        setNovacSettings(prev => ({ ...prev, [key]: value }));

    const handleKeyChange = (key, value) => {
        handleChange(key, value);
        // Flag a bad format as it is typed, but do not nag about an empty field
        // until the merchant actually tries to save.
        setErrors(prev => ({ ...prev, [key]: validateKey(key, value, false) }));
    };

    /**
     * Save handler shared by every panel: validates, then reports the outcome.
     *
     * Validation is scoped to the panel doing the saving, so one panel's blank
     * fields can never block another panel's save button.
     *
     * @param {string}   panel     Which panel is saving: api|test|checkout.
     * @param {Function} setIsBusy Busy-state setter from the save button.
     * @param {Object}   overrides Settings to merge before saving.
     */
    const handleSave = (panel, setIsBusy, overrides = {}) => {
        const payload = { ...novacSettings, ...overrides };
        const fields  = PANEL_KEY_FIELDS[ panel ] ?? [];

        setBanner(null);
        setActivePanel(panel);

        saveSettings(payload, setIsBusy, {
            onInvalid: (fieldErrors) => {
                setErrors(prev => ({ ...prev, ...fieldErrors }));
                setBanner({
                    status: 'error',
                    message: __( 'Nothing was saved. Please correct the highlighted fields.', 'novac-woo' ),
                });
            },
            onError: (err) => {
                setBanner({
                    status: 'error',
                    message: err?.message
                        || __( 'Novac could not save your settings. Please try again.', 'novac-woo' ),
                });
            },
            onSuccess: (response) => {
                // Only clear the errors this panel is responsible for.
                setErrors(prev => {
                    const next = { ...prev };
                    fields.forEach((settingKey) => { next[settingKey] = ''; });
                    return next;
                });

                const verification = response?.verification;

                // A save that succeeded but whose credentials Novac rejected is
                // the case that used to reach the customer as a checkout error.
                if ( verification?.status === 'fail' ) {
                    setBanner({ status: 'error', message: verification.message });
                } else if ( verification?.status === 'unknown' ) {
                    setBanner({ status: 'warning', message: verification.message });
                } else if ( verification?.status === 'pass' ) {
                    setBanner({
                        status: 'success',
                        message: __( 'Settings saved. Novac verified your credentials.', 'novac-woo' ),
                    });
                } else if ( verification?.status === 'skipped' ) {
                    setBanner({ status: 'success', message: verification.message });
                } else {
                    setBanner({ status: 'success', message: __( 'Settings saved.', 'novac-woo' ) });
                }
            },
        }, fields);
    };

    const isLive    = novacSettings.go_live === 'yes';
    const isEnabled = novacSettings.enabled === 'yes';
    const readiness = getReadiness( novacSettings );

    const panelBanner = ( panel ) =>
        activePanel === panel
            ? <NovacResultBanner status={ banner?.status } message={ banner?.message } />
            : null;

    return (
        <Fragment>
            <Page isNarrow>

                <NovacHero firstName={ firstName } pluginUrl={ pluginUrl } />

                <NovacStatusBar isEnabled={ isEnabled } isLive={ isLive } readiness={ readiness } />

                <NovacReadinessWarning readiness={ readiness } />

                {/* ── API & Webhook ── */}
                <Panel>
                    <PanelBody title={ strings.settings.general } initialOpen={ true }>

                        { panelBanner('api') }

                        <div style={{ paddingBottom: '18px', borderBottom: '1px solid #eee', marginBottom: '20px' }}>
                            <ToggleControl
                                checked={ isEnabled }
                                label={ __( 'Enable Novac Payments', 'novac-woo' ) }
                                help={ isLive
                                    ? __( 'Allow customers to pay with Novac at checkout. Uses your live keys.', 'novac-woo' )
                                    : __( 'Allow customers to pay with Novac at checkout. You are in test mode, so the test keys under "Test Mode" are the ones used.', 'novac-woo' ) }
                                onChange={ () => handleChange('enabled', isEnabled ? 'no' : 'yes') }
                            />
                        </div>

                        <Input
                            labelName={ __( 'Live Secret Key', 'novac-woo' ) }
                            initialValue={ novacSettings.live_secret_key }
                            onChange={ v => handleKeyChange('live_secret_key', v) }
                            isConfidential
                            isRequired={ isEnabled && isLive }
                            help={ __( 'Starts with nc_livesk_', 'novac-woo' ) }
                            error={ errors.live_secret_key }
                        />
                        <Input
                            labelName={ __( 'Live Public Key', 'novac-woo' ) }
                            initialValue={ novacSettings.live_public_key }
                            onChange={ v => handleKeyChange('live_public_key', v) }
                            isRequired={ isEnabled && isLive }
                            help={ __( 'Starts with nc_livepk_', 'novac-woo' ) }
                            error={ errors.live_public_key }
                        />

                        <NovacWebhookBox url={ novacData.novac_webhook } />

                        <PanelActions>
                            <NovacSaveButton onClick={ setIsBusy => handleSave('api', setIsBusy) }>
                                { strings.button.save_settings }
                            </NovacSaveButton>
                        </PanelActions>
                    </PanelBody>
                </Panel>

                {/* ── Checkout Settings ── */}
                <Panel>
                    <PanelBody title={ strings.settings.checkout } initialOpen={ false }>
                        { panelBanner('checkout') }

                        <SectionHint>
                            { __( 'Configure how Novac behaves on the checkout page.', 'novac-woo' ) }
                        </SectionHint>

                        <CheckboxControl
                            checked={ novacSettings.autocomplete_order === 'yes' }
                            label={ __( 'Autocomplete order after confirmed payment', 'novac-woo' ) }
                            help={ __( 'Automatically move the order to Completed once payment is verified.', 'novac-woo' ) }
                            onChange={ () => handleChange(
                                'autocomplete_order',
                                novacSettings.autocomplete_order === 'yes' ? 'no' : 'yes'
                            ) }
                        />

                        <CheckboxControl
                            checked={ novacSettings.buy_now_enabled === 'yes' }
                            label={ __( 'Enable "Buy Now" button on product pages', 'novac-woo' ) }
                            help={ __( 'Adds a "Buy Now" button next to "Add to Cart" that skips the cart and goes straight to checkout.', 'novac-woo' ) }
                            onChange={ () => handleChange(
                                'buy_now_enabled',
                                novacSettings.buy_now_enabled === 'yes' ? 'no' : 'yes'
                            ) }
                        />

                        <div style={{ marginTop: '16px' }}>
                            <Input
                                labelName={ __( 'Payment method title', 'novac-woo' ) }
                                initialValue={ novacSettings.title }
                                onChange={ v => handleChange('title', v) }
                            />
                        </div>

                        <SelectControl
                            label={ __( 'Payment style on checkout', 'novac-woo' ) }
                            value={ novacSettings.payment_style }
                            options={ [{ label: 'Redirect', value: 'redirect' }] }
                            onChange={ v => handleChange('payment_style', v) }
                        />

                        <PanelActions>
                            <NovacSaveButton onClick={ setIsBusy => handleSave('checkout', setIsBusy) }>
                                { strings.button.save_settings }
                            </NovacSaveButton>
                        </PanelActions>
                    </PanelBody>
                </Panel>

                {/* ── Test Mode ── */}
                <Panel>
                    <PanelBody title={ strings.sandboxMode.title } initialOpen={ false }>

                        <div style={{
                            fontSize: '13px', color: '#555', lineHeight: 1.6,
                            marginBottom: '20px', padding: '12px 14px',
                            background: '#fffbf0',
                            borderLeft: `3px solid ${C.orange}`,
                            borderRadius: '0 5px 5px 0',
                        }}>
                            { strings.sandboxMode.description }
                        </div>

                        { panelBanner('test') }

                        <Input
                            labelName={ __( 'Test Secret Key', 'novac-woo' ) }
                            initialValue={ novacSettings.test_secret_key }
                            onChange={ v => handleKeyChange('test_secret_key', v) }
                            isConfidential
                            isRequired={ isEnabled && ! isLive }
                            help={ __( 'Starts with nc_testsk_', 'novac-woo' ) }
                            error={ errors.test_secret_key }
                        />
                        <Input
                            labelName={ __( 'Test Public Key', 'novac-woo' ) }
                            initialValue={ novacSettings.test_public_key }
                            onChange={ v => handleKeyChange('test_public_key', v) }
                            isRequired={ isEnabled && ! isLive }
                            help={ __( 'Starts with nc_testpk_', 'novac-woo' ) }
                            error={ errors.test_public_key }
                        />

                        <PanelActions>
                            <div style={{ display: 'flex', gap: '10px', alignItems: 'center' }}>
                                <NovacSaveButton onClick={ setIsBusy => handleSave('test', setIsBusy) }>
                                    { strings.button.save_settings }
                                </NovacSaveButton>
                                <TestModeButton
                                    isLive={ isLive }
                                    onClick={ () => {
                                        const next = isLive ? 'no' : 'yes';
                                        setNovacSettings( prev => ({ ...prev, go_live: next }) );
                                        handleSave( 'test', () => {}, { go_live: next } );
                                    } }
                                />
                            </div>
                        </PanelActions>
                    </PanelBody>
                </Panel>

            </Page>
        </Fragment>
    );
};

// ─── Register page ────────────────────────────────────────────────────────────
addFilter("woocommerce_admin_pages_list", "novac", (pages) => {
    pages.push({
        container: NovacSettings,
        path: "/novac",
        wpOpenMenu: "toplevel_page_woocommerce",
        breadcrumbs: ["Novac"],
    });
    return pages;
});

const NovacNav = () => (
    <WooNavigationItem parentMenu="novac-root" item="novac-1">
        <a className="components-button" href="https://novac.com/1">Novac</a>
    </WooNavigationItem>
);

registerPlugin("my-plugin", { render: NovacNav });
