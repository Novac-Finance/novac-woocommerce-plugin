/**
 * Settings screen: credential validation at configuration time (NOVAC-01).
 */

const { test, expect } = require( '@playwright/test' );
const { harness, VALID_KEYS } = require( '../utils/harness' );

const SETTINGS_URL = '/wp-admin/admin.php?page=wc-admin&path=%2Fnovac';

/**
 * Open the Novac settings screen and wait for the React app to render.
 *
 * @param {import('@playwright/test').Page} page Page.
 */
async function gotoSettings( page ) {
	await page.goto( SETTINGS_URL );
	await expect( page.getByText( 'API/Webhook Settings' ) ).toBeVisible();
}

/**
 * Locate one settings panel by its title.
 *
 * Panels are scoped rather than indexed: WordPress unmounts a PanelBody's
 * children when it is collapsed, so nth-based selectors shift depending on
 * which panels happen to be open.
 *
 * @param {import('@playwright/test').Page} page  Page.
 * @param {string}                          title Panel title.
 * @return {import('@playwright/test').Locator} Panel locator.
 */
function panel( page, title ) {
	return page
		.locator( '.components-panel__body' )
		.filter( { has: page.getByRole( 'button', { name: new RegExp( title, 'i' ) } ) } );
}

/**
 * Expand a collapsed settings panel by its title.
 *
 * @param {import('@playwright/test').Page} page  Page.
 * @param {string}                          title Panel title.
 */
async function openPanel( page, title ) {
	const toggle = panel( page, title ).getByRole( 'button', { name: new RegExp( title, 'i' ) } );

	if ( ( await toggle.getAttribute( 'aria-expanded' ) ) === 'false' ) {
		await toggle.click();
	}
}

/**
 * The Save button inside a given panel.
 *
 * @param {import('@playwright/test').Page} page  Page.
 * @param {string}                          title Panel title.
 * @return {import('@playwright/test').Locator} Button locator.
 */
function saveButton( page, title ) {
	return panel( page, title ).getByRole( 'button', { name: 'Save Configuration' } );
}

/**
 * Wait for a panel to report a successful save.
 *
 * Saving is asynchronous, so reading the stored settings without waiting for
 * the result banner races the request. The banner also distinguishes outcomes:
 * success and warning render as role="status", a rejection as role="alert".
 *
 * @param {import('@playwright/test').Page} page  Page.
 * @param {string}                          title Panel title.
 */
async function expectSaveSucceeded( page, title ) {
	await expect( panel( page, title ).getByRole( 'status' ) ).toBeVisible();
	await expect( panel( page, title ).getByRole( 'alert' ) ).toHaveCount( 0 );
}

/**
 * Wait for a panel to reject a save.
 *
 * @param {import('@playwright/test').Page} page  Page.
 * @param {string}                          title Panel title.
 */
async function expectSaveRejected( page, title ) {
	await expect( panel( page, title ).getByRole( 'alert' ).first() ).toBeVisible();
}

test.describe( 'Novac settings screen', () => {
	test.beforeEach( async ( { request } ) => {
		const api = harness( request );
		await api.reset();
		await api.setSettings( {
			enabled: 'no',
			go_live: 'no',
			live_public_key: '',
			live_secret_key: '',
			test_public_key: '',
			test_secret_key: '',
		} );
	} );

	test( 'saves a well-formed key pair and reports verification', async ( { page, request } ) => {
		const api = harness( request );

		// Verification only runs against the mode the store is actually in, so
		// put it in live mode to exercise the live keys being entered below.
		await api.setSettings( { go_live: 'yes' } );
		await api.setScenario( { probe_status: 200 } );

		await gotoSettings( page );

		await page.getByLabel( /Live Secret Key/i ).fill( VALID_KEYS.live_secret_key );
		await page.getByLabel( /Live Public Key/i ).fill( VALID_KEYS.live_public_key );
		await saveButton( page, 'API/Webhook Settings' ).click();

		await expect( page.getByText( /verified your credentials/i ) ).toBeVisible();

		const saved = await api.getSettings();
		expect( saved.live_secret_key ).toBe( VALID_KEYS.live_secret_key );
		expect( saved.live_public_key ).toBe( VALID_KEYS.live_public_key );
	} );

	test( 'rejects a malformed key and does not persist it', async ( { page, request } ) => {
		const api = harness( request );

		await gotoSettings( page );

		await page.getByLabel( /Live Secret Key/i ).fill( 'totally-not-a-novac-key' );
		await expect( page.getByText( /should start with "nc_livesk_"/i ) ).toBeVisible();

		await saveButton( page, 'API/Webhook Settings' ).click();
		await expectSaveRejected( page, 'API/Webhook Settings' );
		await expect( page.getByText( /Nothing was saved/i ) ).toBeVisible();

		const saved = await api.getSettings();
		expect( saved.live_secret_key || '' ).not.toBe( 'totally-not-a-novac-key' );
	} );

	test( 'rejects a test key pasted into a live field', async ( { page } ) => {
		await gotoSettings( page );

		// The bug class this guards: a live field accepting a test-prefixed key
		// looks configured but is refused by the API at payment time.
		await page.getByLabel( /Live Secret Key/i ).fill( VALID_KEYS.test_secret_key );

		await expect( page.getByText( /should start with "nc_livesk_"/i ) ).toBeVisible();
	} );

	test( 'surfaces a rejected credential from the verification call', async ( { page, request } ) => {
		const api = harness( request );
		await api.setScenario( { probe_status: 401 } );

		await gotoSettings( page );

		await openPanel( page, "Test Mode" );
		await page.getByLabel( /Test Secret Key/i ).fill( VALID_KEYS.test_secret_key );
		await page.getByLabel( /Test Public Key/i ).fill( VALID_KEYS.test_public_key );
		await saveButton( page, 'Test Mode' ).click();

		await expect( page.getByText( /Novac rejected this secret key/i ) ).toBeVisible();
	} );

	test( 'warns that the gateway is enabled but not reaching checkout', async ( { page, request } ) => {
		const api = harness( request );

		// Enabled in test mode with no test keys: the store looks on, but is not.
		await api.setSettings( { enabled: 'yes', go_live: 'no' } );

		await gotoSettings( page );

		await expect(
			page.getByText( /switched on but is not appearing at checkout/i )
		).toBeVisible();
		await expect( page.getByText( 'Not at checkout' ) ).toBeVisible();
	} );

	test( 'saving live keys in test mode is not blocked by empty test keys', async ( {
		page,
		request,
	} ) => {
		const api = harness( request );

		// Regression guard. The live and test keys sit behind separate save
		// buttons in separate accordions; validating the active mode's pair on
		// every save made it impossible to enter one pair without the other,
		// and reported the error in a panel that did not contain the fields.
		await api.setSettings( { enabled: 'yes', go_live: 'no' } );

		await gotoSettings( page );

		await page.getByLabel( /Live Secret Key/i ).fill( VALID_KEYS.live_secret_key );
		await page.getByLabel( /Live Public Key/i ).fill( VALID_KEYS.live_public_key );
		await saveButton( page, 'API/Webhook Settings' ).click();

		// The save must go through even though the *test* keys are still blank.
		await expectSaveSucceeded( page, 'API/Webhook Settings' );
		await expect( page.getByText( /Nothing was saved/i ) ).toHaveCount( 0 );

		const saved = await api.getSettings();
		expect( saved.live_secret_key ).toBe( VALID_KEYS.live_secret_key );
		expect( saved.live_public_key ).toBe( VALID_KEYS.live_public_key );
	} );

	test( 'shows the webhook URL for the merchant to copy', async ( { page } ) => {
		await gotoSettings( page );

		await expect( page.getByText( 'Webhook URL' ) ).toBeVisible();
		await expect( page.getByText( /wc-api\/Novac_Payment_Webhook/i ) ).toBeVisible();
	} );
} );
