/**
 * Playwright global setup.
 *
 * Signs in to WordPress once and persists the session, so individual specs do
 * not each pay the cost of a login round-trip. Also asserts the test harness
 * mu-plugin is actually live — without it every payment spec would silently hit
 * the real Novac API, which is exactly what this suite must never do.
 */

const { chromium, request } = require( '@playwright/test' );
const fs = require( 'fs' );
const path = require( 'path' );

const BASE_URL = process.env.WP_BASE_URL || 'http://localhost:8889';
const ADMIN_USER = process.env.WP_ADMIN_USER || 'admin';
const ADMIN_PASSWORD = process.env.WP_ADMIN_PASSWORD || 'password';
const E2E_TOKEN = process.env.NOVAC_E2E_TOKEN || 'novac-e2e-local-token';

const STATE_PATH = path.resolve( __dirname, '../../artifacts/admin-state.json' );

module.exports = async () => {
	fs.mkdirSync( path.dirname( STATE_PATH ), { recursive: true } );

	// 1. Confirm the harness is loaded and answering before anything else runs.
	const api = await request.newContext( { baseURL: BASE_URL } );
	const probe = await api.post( '/wp-json/novac-e2e/v1/reset', {
		headers: { 'X-Novac-E2E-Token': E2E_TOKEN },
	} );

	if ( ! probe.ok() ) {
		throw new Error(
			`Novac E2E harness is not responding at ${ BASE_URL }/wp-json/novac-e2e/v1/reset ` +
				`(HTTP ${ probe.status() }).\n` +
				'Check that wp-env is running and that NOVAC_E2E / NOVAC_E2E_TOKEN are set in .wp-env.json.\n' +
				'Without the harness the suite would call the real Novac API — refusing to continue.'
		);
	}

	await api.dispose();

	// 2. Log in and bank the session.
	const browser = await chromium.launch();
	const page = await browser.newPage( { baseURL: BASE_URL } );

	await page.goto( '/wp-login.php' );
	await page.fill( '#user_login', ADMIN_USER );
	await page.fill( '#user_pass', ADMIN_PASSWORD );
	await page.click( '#wp-submit' );
	await page.waitForURL( /wp-admin/ );

	await page.context().storageState( { path: STATE_PATH } );
	await browser.close();
};
