import { registerPlugin } from "@wordpress/plugins";
import { addFilter } from "@wordpress/hooks";
import { __ } from '@wordpress/i18n';
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

const NovacStatusBar = ({ isEnabled, isLive }) => (
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

        <span style={ badge(isEnabled ? C.greenDim : C.redDim, isEnabled ? C.green : C.red, isEnabled ? C.greenBorder : C.redBorder) }>
            <Dot color={ isEnabled ? C.green : C.red } />
            { isEnabled ? __( 'Active', 'novac-woo' ) : __( 'Inactive', 'novac-woo' ) }
        </span>

        <span style={ badge(isLive ? C.greenDim : C.orangeDim, isLive ? C.green : C.orange, isLive ? C.greenBorder : C.orangeBorder) }>
            <Dot color={ isLive ? C.green : C.orange } />
            { isLive ? __( 'Live Mode', 'novac-woo' ) : __( 'Test Mode', 'novac-woo' ) }
        </span>
    </div>
);

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

// ─── Save helper ──────────────────────────────────────────────────────────────
const saveSettings = (data, setIsBusy) => {
    apiFetch({
        path: NAMESPACE + ENDPOINT,
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': wpApiSettings.nonce },
        data,
    })
        .then(() => setIsBusy(false))
        .catch((err) => { console.error('Novac: error saving settings', err); setIsBusy(false); });
};

const validateKey = (key, type) => {
    if (type === 'public') return key.startsWith('nc_livepk_') || key.startsWith('nc_testpk_');
    if (type === 'secret') return key.startsWith('nc_livesk_') || key.startsWith('nc_testsk_');
    return false;
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

    const handleChange = (key, value) =>
        setNovacSettings(prev => ({ ...prev, [key]: value }));

    const handleKeyChange = (key, value, type) => {
        handleChange(key, value);
        setErrors(prev => ({
            ...prev,
            [key]: validateKey(value, type) ? '' : 'Invalid key format',
        }));
    };

    const isLive    = novacSettings.go_live === 'yes';
    const isEnabled = novacSettings.enabled === 'yes';

    return (
        <Fragment>
            <Page isNarrow>

                <NovacHero firstName={ firstName } pluginUrl={ pluginUrl } />

                <NovacStatusBar isEnabled={ isEnabled } isLive={ isLive } />

                {/* ── API & Webhook ── */}
                <Panel>
                    <PanelBody title={ strings.settings.general } initialOpen={ true }>

                        <div style={{ paddingBottom: '18px', borderBottom: '1px solid #eee', marginBottom: '20px' }}>
                            <ToggleControl
                                checked={ isEnabled }
                                label={ __( 'Enable Novac Payments', 'novac-woo' ) }
                                help={ __( 'Allow customers to pay with Novac at checkout.', 'novac-woo' ) }
                                onChange={ () => handleChange('enabled', isEnabled ? 'no' : 'yes') }
                            />
                        </div>

                        <Input
                            labelName={ __( 'Live Secret Key', 'novac-woo' ) }
                            initialValue={ novacSettings.live_secret_key }
                            onChange={ v => handleKeyChange('live_secret_key', v, 'secret') }
                            isConfidential
                            error={ errors.live_secret_key }
                        />
                        <Input
                            labelName={ __( 'Live Public Key', 'novac-woo' ) }
                            initialValue={ novacSettings.live_public_key }
                            onChange={ v => handleKeyChange('live_public_key', v, 'public') }
                            error={ errors.live_public_key }
                        />

                        <NovacWebhookBox url={ novacData.novac_webhook } />

                        <PanelActions>
                            <NovacSaveButton onClick={ setIsBusy => saveSettings(novacSettings, setIsBusy) }>
                                { strings.button.save_settings }
                            </NovacSaveButton>
                        </PanelActions>
                    </PanelBody>
                </Panel>

                {/* ── Checkout Settings ── */}
                <Panel>
                    <PanelBody title={ strings.settings.checkout } initialOpen={ false }>
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
                            <NovacSaveButton onClick={ setIsBusy => saveSettings(novacSettings, setIsBusy) }>
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

                        <Input
                            labelName={ __( 'Test Secret Key', 'novac-woo' ) }
                            initialValue={ novacSettings.test_secret_key }
                            onChange={ v => handleKeyChange('test_secret_key', v, 'secret') }
                            isConfidential
                            error={ errors.test_secret_key }
                        />
                        <Input
                            labelName={ __( 'Test Public Key', 'novac-woo' ) }
                            initialValue={ novacSettings.test_public_key }
                            onChange={ v => handleKeyChange('test_public_key', v, 'public') }
                            error={ errors.test_public_key }
                        />

                        <PanelActions>
                            <div style={{ display: 'flex', gap: '10px', alignItems: 'center' }}>
                                <NovacSaveButton onClick={ setIsBusy => saveSettings(novacSettings, setIsBusy) }>
                                    { strings.button.save_settings }
                                </NovacSaveButton>
                                <TestModeButton
                                    isLive={ isLive }
                                    onClick={ () => {
                                        const next    = isLive ? 'no' : 'yes';
                                        const updated = { ...novacSettings, go_live: next };
                                        setNovacSettings(updated);
                                        saveSettings(updated, () => {});
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
