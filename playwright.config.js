/**
 * Playwright configuration for the Novac WooCommerce E2E suite.
 *
 * Targets the wp-env "tests" instance on :8889 so a run never disturbs the
 * :8888 development site.
 */

const { defineConfig, devices } = require( '@playwright/test' );

const BASE_URL = process.env.WP_BASE_URL || 'http://localhost:8889';

module.exports = defineConfig( {
	testDir: './tests/e2e/specs',
	globalSetup: require.resolve( './tests/e2e/global-setup.js' ),
	outputDir: './artifacts/test-results',

	/*
	 * Payment flows mutate shared store state — the cart, the gateway settings,
	 * and the harness scenario all live in one WordPress install — so specs run
	 * one at a time. Correctness matters more than wall-clock here; the whole
	 * suite is still minutes, not hours.
	 */
	fullyParallel: false,
	workers: 1,

	forbidOnly: !! process.env.CI,
	retries: process.env.CI ? 1 : 0,
	timeout: 60_000,
	expect: { timeout: 15_000 },

	reporter: process.env.CI
		? [
				[ 'list' ],
				[ 'html', { outputFolder: './artifacts/playwright-report', open: 'never' } ],
				[ 'json', { outputFile: './artifacts/results.json' } ],
		  ]
		: [ [ 'list' ] ],

	use: {
		baseURL: BASE_URL,
		storageState: './artifacts/admin-state.json',
		actionTimeout: 15_000,
		navigationTimeout: 30_000,
		trace: 'retain-on-failure',
		screenshot: 'only-on-failure',
		video: process.env.CI ? 'retain-on-failure' : 'off',
	},

	projects: [
		{
			name: 'chromium',
			use: { ...devices[ 'Desktop Chrome' ] },
		},
	],
} );
