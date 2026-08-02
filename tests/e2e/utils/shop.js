/**
 * Storefront helpers: getting a cart into a known state and filling checkout.
 */

const { expect } = require( '@playwright/test' );

/** Product slugs created by tests/e2e/bin/setup-store.sh. */
const PRODUCTS = {
	aboveMinimum: 'novac-above-minimum', // NGN 5000
	belowMinimum: 'novac-below-minimum', // NGN 50
};

/**
 * Empty the cart.
 *
 * @param {import('@playwright/test').Page} page Page.
 */
async function emptyCart( page ) {
	// The cart lives in the session and persists between specs, so every spec
	// that cares about the total has to start from empty.
	await page.goto( '/classic-cart/' );
	await page.waitForLoadState( 'domcontentloaded' );

	// Follow each remove link as a plain navigation rather than clicking it.
	// WooCommerce wires those links up to AJAX and detaches the node mid-flight,
	// which makes click() hang even though the removal succeeded.
	for ( let i = 0; i < 20; i++ ) {
		const remove = page.locator( 'a.remove' ).first();

		if ( ! ( await remove.count() ) ) {
			return;
		}

		const href = await remove.getAttribute( 'href' );

		if ( ! href ) {
			return;
		}

		await page.goto( href );
		await page.waitForLoadState( 'domcontentloaded' );
	}

	throw new Error( 'Could not empty the cart after 20 removals.' );
}

/**
 * Put a single product in an otherwise empty cart.
 *
 * @param {import('@playwright/test').Page} page Page.
 * @param {string}                          slug Product slug.
 */
async function addProductToCart( page, slug ) {
	await emptyCart( page );
	await page.goto( `/product/${ slug }/` );
	await page.locator( 'button[name="add-to-cart"]' ).click();
	await page.waitForLoadState( 'domcontentloaded' );

	// Adding to cart redirects or updates in place depending on settings;
	// either way the item must actually be there before a spec proceeds.
	await page.goto( '/classic-cart/' );
	await expect( page.locator( '.cart_item, .wc-block-cart-items__row' ).first() ).toBeVisible();
}

/**
 * Fill the classic checkout billing form with valid details.
 *
 * @param {import('@playwright/test').Page} page Page.
 */
async function fillClassicCheckout( page ) {
	// Which billing fields exist depends on the store's country, whether
	// shipping is enabled, and whether the cart is all-virtual. Fill whatever
	// is actually on the page rather than assuming a fixed set.
	const values = {
		'#billing_first_name': 'Ada',
		'#billing_last_name': 'Tester',
		'#billing_company': 'Test Co',
		'#billing_address_1': '1 Test Street',
		'#billing_city': 'Lagos',
		'#billing_postcode': '100001',
		'#billing_phone': '08000000000',
		'#billing_email': 'ada.tester@example.com',
	};

	for ( const [ selector, value ] of Object.entries( values ) ) {
		const field = page.locator( selector );

		if ( ( await field.count() ) && ( await field.first().isVisible() ) ) {
			await field.first().fill( value );
		}
	}

	const country = page.locator( '#billing_country' );

	if ( await country.count() ) {
		await country.selectOption( 'NG' ).catch( () => {} );
	}

	const state = page.locator( '#billing_state' );

	if ( ( await state.count() ) && ( await state.first().isVisible() ) ) {
		await state.first().selectOption( { index: 1 } ).catch( () => {} );
	}
}

/**
 * Wait until the checkout is not mid-AJAX-update.
 *
 * WooCommerce covers the form with a blockUI overlay while it refreshes order
 * review, and that overlay swallows clicks. Editing billing fields or choosing
 * a payment method both trigger a refresh, so anything that clicks afterwards
 * has to wait for it to clear.
 *
 * @param {import('@playwright/test').Page} page Page.
 */
async function waitForCheckoutIdle( page ) {
	await expect( page.locator( '.blockUI.blockOverlay' ) ).toHaveCount( 0, { timeout: 30_000 } );
}

/**
 * Whether Novac is offered as a payment method on the current checkout page.
 *
 * @param {import('@playwright/test').Page} page Page.
 * @return {Promise<boolean>} True when the Novac radio is present.
 */
async function novacIsOffered( page ) {
	return ( await page.locator( '#payment_method_novac' ).count() ) > 0;
}

/**
 * Go to the classic checkout with the billing form filled in.
 *
 * @param {import('@playwright/test').Page} page Page.
 */
async function gotoClassicCheckout( page ) {
	await page.goto( '/classic-checkout/' );
	await page.waitForLoadState( 'domcontentloaded' );
}

/**
 * Fill the checkout, select Novac, and submit the order.
 *
 * @param {import('@playwright/test').Page} page Page.
 */
async function placeOrderWithNovac( page ) {
	await gotoClassicCheckout( page );
	await fillClassicCheckout( page );
	await waitForCheckoutIdle( page );

	await page.locator( '#payment_method_novac' ).check();
	await waitForCheckoutIdle( page );

	await page.locator( '#place_order' ).click();
}

module.exports = {
	PRODUCTS,
	emptyCart,
	addProductToCart,
	fillClassicCheckout,
	novacIsOffered,
	gotoClassicCheckout,
	waitForCheckoutIdle,
	placeOrderWithNovac,
};
