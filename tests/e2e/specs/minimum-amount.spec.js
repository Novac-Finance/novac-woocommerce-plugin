/**
 * NOVAC-02 — totals below the per-currency minimum must fail loudly, not silently.
 */

const { test, expect } = require( '@playwright/test' );
const { harness } = require( '../utils/harness' );
const { PRODUCTS, addProductToCart, gotoClassicCheckout, novacIsOffered } = require( '../utils/shop' );

test.describe( 'Minimum chargeable amount', () => {
	test.beforeEach( async ( { request } ) => {
		const api = harness( request );
		await api.reset();
		await api.configureWorkingGateway();
	} );

	test( 'hides the gateway when an NGN cart is below the minimum', async ( { page } ) => {
		await addProductToCart( page, PRODUCTS.belowMinimum ); // NGN 50
		await gotoClassicCheckout( page );

		expect( await novacIsOffered( page ) ).toBe( false );
	} );

	test( 'explains why, rather than just disappearing', async ( { page } ) => {
		await addProductToCart( page, PRODUCTS.belowMinimum );
		await gotoClassicCheckout( page );

		// The silent failure in the report was a customer left on checkout with
		// no modal and no explanation. A notice naming the threshold is the fix.
		await expect( page.getByText( /below the .*100.* minimum/i ) ).toBeVisible();
	} );

	test( 'shows the same notice on the cart page', async ( { page } ) => {
		await addProductToCart( page, PRODUCTS.belowMinimum );
		await page.goto( '/classic-cart/' );

		await expect( page.getByText( /below the .*100.* minimum/i ) ).toBeVisible();
	} );

	test( 'offers the gateway once the cart clears the minimum', async ( { page } ) => {
		await addProductToCart( page, PRODUCTS.aboveMinimum ); // NGN 5000
		await gotoClassicCheckout( page );

		expect( await novacIsOffered( page ) ).toBe( true );
		await expect( page.getByText( /below the .*minimum/i ) ).toHaveCount( 0 );
	} );

	test( 'applies no minimum to a currency without a known threshold', async ( {
		page,
		request,
	} ) => {
		const api = harness( request );

		// Only NGN has a confirmed minimum. Other currencies must not be blocked
		// on a guess — a wrongly hidden gateway is its own defect.
		await api.setStore( { woocommerce_currency: 'USD' } );

		try {
			await addProductToCart( page, PRODUCTS.belowMinimum );
			await gotoClassicCheckout( page );

			expect( await novacIsOffered( page ) ).toBe( true );
		} finally {
			// Restore NGN even if the assertion above fails, so one failure does
			// not cascade into every spec that runs after it.
			await api.setStore( { woocommerce_currency: 'NGN' } );
		}
	} );
} );
