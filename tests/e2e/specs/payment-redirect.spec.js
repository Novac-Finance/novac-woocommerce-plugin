/**
 * The redirect payment flow, end to end, including the failure branches that
 * used to collapse into "Something went wrong. Please contact us to get assistance."
 */

const { test, expect } = require( '@playwright/test' );
const { harness } = require( '../utils/harness' );
const { PRODUCTS, addProductToCart, placeOrderWithNovac } = require( '../utils/shop' );

test.describe( 'Redirect payment flow', () => {
	test.beforeEach( async ( { page, request } ) => {
		const api = harness( request );
		await api.reset();
		await api.configureWorkingGateway();
		await addProductToCart( page, PRODUCTS.aboveMinimum );
	} );

	test( 'completes a successful payment and marks the order paid', async ( { page, request } ) => {
		const api = harness( request );
		await api.setScenario( { initiate_status: 200, verify_txn_state: 'successful' } );

		await placeOrderWithNovac( page );

		// The plugin should hand the browser to Novac's hosted page.
		await expect( page.getByTestId( 'novac-hosted-page' ) ).toBeVisible();

		await page.getByTestId( 'novac-pay' ).click();

		// The customer must land on the order-received page, not be dumped back
		// on checkout or sent to an empty redirect.
		await page.waitForURL( /order-received/ );
		await expect( page.getByText( 'Thank you. Your order has been received.' ) ).toBeVisible();

		const order = await api.latestOrder();
		expect( order.method ).toBe( 'novac' );
		expect( [ 'processing', 'completed' ] ).toContain( order.status );
	} );

	test( 'sends the correct amount, currency and credentials to the API', async ( {
		page,
		request,
	} ) => {
		const api = harness( request );
		await api.setScenario( { initiate_status: 200 } );

		await placeOrderWithNovac( page );
		await expect( page.getByTestId( 'novac-hosted-page' ) ).toBeVisible();

		const initiate = await api.lastRequestTo( '/initiate' );
		expect( initiate ).not.toBeNull();

		const body = JSON.parse( initiate.body );
		expect( body.currency ).toBe( 'NGN' );
		expect( Number( body.amount ) ).toBe( 5000 );
		expect( body.transactionReference ).toMatch( /^WOO_\d+_/ );
		expect( body.checkoutCustomerData.email ).toBe( 'ada.tester@example.com' );
	} );

	test( 'autocompletes the order when that setting is on', async ( { page, request } ) => {
		const api = harness( request );
		await api.configureWorkingGateway( { autocomplete_order: 'yes' } );
		await api.setScenario( { verify_txn_state: 'successful' } );

		await placeOrderWithNovac( page );
		await page.getByTestId( 'novac-pay' ).click();
		await page.waitForLoadState( 'domcontentloaded' );

		const order = await api.latestOrder();
		expect( order.status ).toBe( 'completed' );
	} );

	test( 'cancelling at the gateway cancels the order', async ( { page, request } ) => {
		const api = harness( request );
		await api.setScenario( { verify_txn_state: 'cancelled' } );

		await placeOrderWithNovac( page );
		await page.getByTestId( 'novac-cancel' ).click();
		await page.waitForLoadState( 'domcontentloaded' );

		const order = await api.latestOrder();
		expect( order.status ).toBe( 'cancelled' );
	} );

	test( 'a rejected credential is reported as a store problem, not a card problem', async ( {
		page,
		request,
	} ) => {
		const api = harness( request );
		await api.setScenario( { initiate_status: 401 } );

		await placeOrderWithNovac( page );

		// The defect: HTTP 401 is not a WP_Error, so it took the success branch
		// and the customer got a generic message with no cause recorded anywhere.
		await expect( page.getByText( /not correctly configured for this store/i ) ).toBeVisible();
		await expect( page.getByText( /Something went wrong/i ) ).toHaveCount( 0 );

		const order = await api.latestOrder();
		const detail = await api.order( order.id );
		expect( detail.notes.join( ' ' ) ).toMatch( /rejected the store credentials/i );
	} );

	test( "surfaces the API's own rejection reason", async ( { page, request } ) => {
		const api = harness( request );
		await api.setScenario( {
			initiate_status: 400,
			initiate_body: { message: 'Amount is below the minimum chargeable value' },
		} );

		await placeOrderWithNovac( page );

		await expect(
			page.getByText( /Amount is below the minimum chargeable value/i )
		).toBeVisible();
	} );

	test( 'treats a 2xx with no payment link as a failure', async ( { page, request } ) => {
		const api = harness( request );
		await api.setScenario( {
			initiate_status: 200,
			initiate_body: { status: true, data: {} },
		} );

		await placeOrderWithNovac( page );

		// Previously this produced an empty redirect and a generic error.
		await expect( page.getByText( /did not return a payment page/i ) ).toBeVisible();
		expect( page.url() ).not.toContain( 'novac_e2e_pay' );
	} );

	test( 'reports a transport failure without blaming the customer', async ( { page, request } ) => {
		const api = harness( request );
		await api.setScenario( { initiate_error: 'cURL error 28: timed out' } );

		await placeOrderWithNovac( page );

		await expect( page.getByText( /could not reach Novac/i ) ).toBeVisible();
	} );

	test( 'holds the order when the paid amount does not match', async ( { page, request } ) => {
		const api = harness( request );
		await api.setScenario( { verify_txn_state: 'successful', verify_amount: '1.00' } );

		await placeOrderWithNovac( page );
		await page.getByTestId( 'novac-pay' ).click();
		await page.waitForLoadState( 'domcontentloaded' );

		const order = await api.latestOrder();
		expect( order.status ).toBe( 'on-hold' );
	} );
} );
