/**
 * NOVAC-01 — the gateway must not be offered when it cannot take a payment.
 */

const { test, expect } = require( '@playwright/test' );
const { harness, VALID_KEYS } = require( '../utils/harness' );
const { PRODUCTS, addProductToCart, gotoClassicCheckout, novacIsOffered } = require( '../utils/shop' );

test.describe( 'Gateway availability', () => {
	test.beforeEach( async ( { request } ) => {
		await harness( request ).reset();
	} );

	test( 'is hidden at checkout when the active mode has no keys', async ( { page, request } ) => {
		await harness( request ).setSettings( {
			enabled: 'yes',
			go_live: 'no',
			test_public_key: '',
			test_secret_key: '',
		} );

		await addProductToCart( page, PRODUCTS.aboveMinimum );
		await gotoClassicCheckout( page );

		expect( await novacIsOffered( page ) ).toBe( false );
	} );

	test( 'is hidden when the active mode has malformed keys', async ( { page, request } ) => {
		await harness( request ).setSettings( {
			enabled: 'yes',
			go_live: 'no',
			test_public_key: 'nope',
			test_secret_key: 'also-nope',
		} );

		await addProductToCart( page, PRODUCTS.aboveMinimum );
		await gotoClassicCheckout( page );

		expect( await novacIsOffered( page ) ).toBe( false );
	} );

	test( 'is hidden when the keys belong to the other mode', async ( { page, request } ) => {
		// Live keys present, but the store is in test mode.
		await harness( request ).setSettings( {
			enabled: 'yes',
			go_live: 'no',
			live_public_key: VALID_KEYS.live_public_key,
			live_secret_key: VALID_KEYS.live_secret_key,
			test_public_key: '',
			test_secret_key: '',
		} );

		await addProductToCart( page, PRODUCTS.aboveMinimum );
		await gotoClassicCheckout( page );

		expect( await novacIsOffered( page ) ).toBe( false );
	} );

	test( 'is offered once the active mode is fully configured', async ( { page, request } ) => {
		await harness( request ).configureWorkingGateway();

		await addProductToCart( page, PRODUCTS.aboveMinimum );
		await gotoClassicCheckout( page );

		expect( await novacIsOffered( page ) ).toBe( true );
	} );

	test( 'is hidden while the gateway is switched off', async ( { page, request } ) => {
		await harness( request ).configureWorkingGateway( { enabled: 'no' } );

		await addProductToCart( page, PRODUCTS.aboveMinimum );
		await gotoClassicCheckout( page );

		expect( await novacIsOffered( page ) ).toBe( false );
	} );

	test( 'warns the merchant in wp-admin when enabled without usable keys', async ( {
		page,
		request,
	} ) => {
		await harness( request ).setSettings( {
			enabled: 'yes',
			go_live: 'no',
			test_public_key: '',
			test_secret_key: '',
		} );

		await page.goto( '/wp-admin/index.php' );

		await expect( page.getByText( /Novac is enabled but not usable/i ) ).toBeVisible();
	} );

	test( 'does not warn once the gateway is usable', async ( { page, request } ) => {
		await harness( request ).configureWorkingGateway();

		await page.goto( '/wp-admin/index.php' );

		await expect( page.getByText( /Novac is enabled but not usable/i ) ).toHaveCount( 0 );
	} );
} );
