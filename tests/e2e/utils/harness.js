/**
 * Client for the Novac E2E harness mu-plugin.
 *
 * Everything a spec needs to drive the mocked Novac API: choose what the API
 * returns, read back what the plugin actually sent, and inspect orders and
 * settings without going through the browser.
 */

const E2E_TOKEN = process.env.NOVAC_E2E_TOKEN || 'novac-e2e-local-token';

/** Well-formed keys that satisfy the plugin's prefix and length rules. */
const VALID_KEYS = {
	live_public_key: 'nc_livepk_e2e0000000000000000000000',
	live_secret_key: 'nc_livesk_e2e0000000000000000000000',
	test_public_key: 'nc_testpk_e2e0000000000000000000000',
	test_secret_key: 'nc_testsk_e2e0000000000000000000000',
};

/**
 * Build a harness client bound to a Playwright request context.
 *
 * @param {import('@playwright/test').APIRequestContext} request Request context.
 * @return {Object} Harness client.
 */
function harness( request ) {
	const headers = { 'X-Novac-E2E-Token': E2E_TOKEN };

	const call = async ( method, route, data ) => {
		const response = await request[ method ]( `/wp-json/novac-e2e/v1${ route }`, {
			headers: { ...headers, 'Content-Type': 'application/json' },
			...( data ? { data } : {} ),
		} );

		if ( ! response.ok() ) {
			throw new Error(
				`Harness ${ method.toUpperCase() } ${ route } failed: HTTP ${ response.status() } ` +
					`— ${ await response.text() }`
			);
		}

		return response.json();
	};

	return {
		/** Clear the scenario and the recorded request log. */
		reset: () => call( 'post', '/reset' ),

		/**
		 * Script what the mocked Novac API returns.
		 *
		 * @param {Object} scenario Partial scenario; unset keys fall back to defaults.
		 */
		setScenario: ( scenario ) => call( 'post', '/scenario', scenario ),

		/** Every outbound request the plugin made to the Novac API. */
		requests: () => call( 'get', '/requests' ),

		/** The most recent request whose URL contains the given fragment. */
		lastRequestTo: async ( fragment ) => {
			const all = await call( 'get', '/requests' );
			const matches = all.filter( ( entry ) => entry.url.includes( fragment ) );
			return matches.length ? matches[ matches.length - 1 ] : null;
		},

		/** Current gateway settings straight from the option row. */
		getSettings: () => call( 'get', '/settings' ),

		/**
		 * Write gateway settings directly, bypassing the settings screen.
		 *
		 * Used to put the store into a given configuration quickly; specs that
		 * are *about* the settings screen drive the UI instead.
		 *
		 * @param {Object} settings Settings to merge in.
		 */
		setSettings: ( settings ) => call( 'post', '/settings', settings ),

		/**
		 * Configure the gateway as a working, enabled test-mode store.
		 *
		 * @param {Object} overrides Extra settings to merge.
		 */
		configureWorkingGateway: ( overrides = {} ) =>
			call( 'post', '/settings', {
				enabled: 'yes',
				go_live: 'no',
				title: 'Novac',
				description: 'Pay with Novac',
				payment_style: 'redirect',
				autocomplete_order: 'no',
				buy_now_enabled: 'no',
				...VALID_KEYS,
				...overrides,
			} ),

		/**
		 * Change store-level settings (currency, base country).
		 *
		 * @param {Object} options Allow-listed store options.
		 */
		setStore: ( options ) => call( 'post', '/store', options ),

		/** Read one order's status, total and notes. */
		order: ( id ) => call( 'get', `/order/${ id }` ),

		/** The most recently created order. */
		latestOrder: () => call( 'get', '/latest-order' ),
	};
}

module.exports = { harness, VALID_KEYS, E2E_TOKEN };
