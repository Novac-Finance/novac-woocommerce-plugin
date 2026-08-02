/**
 * Buy Now button, and the blocks checkout integration.
 */

const { test, expect } = require( '@playwright/test' );
const { harness } = require( '../utils/harness' );
const { PRODUCTS, emptyCart, addProductToCart } = require( '../utils/shop' );

test.describe( 'Buy Now button', () => {
	test.beforeEach( async ( { request } ) => {
		await harness( request ).reset();
	} );

	test( 'is absent while the setting is off', async ( { page, request } ) => {
		await harness( request ).configureWorkingGateway( { buy_now_enabled: 'no' } );

		await page.goto( `/product/${ PRODUCTS.aboveMinimum }/` );

		await expect( page.locator( '.novac-buy-now-btn' ) ).toHaveCount( 0 );
	} );

	test( 'appears on the product page when enabled', async ( { page, request } ) => {
		await harness( request ).configureWorkingGateway( { buy_now_enabled: 'yes' } );

		await page.goto( `/product/${ PRODUCTS.aboveMinimum }/` );

		await expect( page.locator( '.novac-buy-now-btn' ) ).toBeVisible();
	} );

	test( 'skips the cart and preselects Novac at checkout', async ( { page, request } ) => {
		await harness( request ).configureWorkingGateway( { buy_now_enabled: 'yes' } );

		await emptyCart( page );
		await page.goto( `/product/${ PRODUCTS.aboveMinimum }/` );
		await page.locator( '.novac-buy-now-btn' ).click();
		await page.waitForLoadState( 'domcontentloaded' );

		expect( page.url() ).toContain( 'novac_buy_now=1' );

		// WooCommerce hides the radio when only one gateway is available, so
		// assert it is present and selected rather than visible.
		const novacRadio = page.locator( '#payment_method_novac' );
		await expect( novacRadio ).toHaveCount( 1 );
		await expect( novacRadio ).toBeChecked();
	} );
} );

test.describe( 'Blocks checkout', () => {
	test.beforeEach( async ( { request } ) => {
		await harness( request ).reset();
	} );

	test( 'offers Novac when the gateway is usable', async ( { page, request } ) => {
		await harness( request ).configureWorkingGateway();

		await addProductToCart( page, PRODUCTS.aboveMinimum );
		await page.goto( '/checkout/' );
		await page.waitForLoadState( 'networkidle' );

		await expect( page.getByText( /Novac/i ).first() ).toBeVisible();
	} );

	test( 'hides Novac when the active mode has no keys', async ( { page, request } ) => {
		await harness( request ).setSettings( {
			enabled: 'yes',
			go_live: 'no',
			test_public_key: '',
			test_secret_key: '',
		} );

		await addProductToCart( page, PRODUCTS.aboveMinimum );
		await page.goto( '/checkout/' );
		await page.waitForLoadState( 'networkidle' );

		// is_active() defers to the gateway's is_available(), so the block
		// checkout must hide Novac under exactly the same conditions.
		await expect( page.locator( '[data-testid*="novac"], #radio-control-wc-payment-method-options-novac' ) ).toHaveCount( 0 );
	} );

	test( 'hides Novac when the cart is below the NGN minimum', async ( { page, request } ) => {
		await harness( request ).configureWorkingGateway();

		await addProductToCart( page, PRODUCTS.belowMinimum );
		await page.goto( '/checkout/' );
		await page.waitForLoadState( 'networkidle' );

		await expect( page.locator( '#radio-control-wc-payment-method-options-novac' ) ).toHaveCount( 0 );
	} );
} );
