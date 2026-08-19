/**
 * Webhook handler.
 *
 * Driven over HTTP rather than through the browser. The handler restricts
 * callers by address, which is worth asserting on its own: a webhook anyone can
 * call would let a stranger mark orders paid.
 *
 * The specs send an X-Forwarded-For naming the allowed address, and the harness
 * declares the Docker network as a trusted proxy so the plugin believes it —
 * emulating a store behind Cloudflare or a load balancer. Note that Apache's
 * mod_remoteip is also rewriting REMOTE_ADDR from that header in this image, so
 * the trust decision the plugin itself makes is covered by the resolveIp()
 * cases below rather than by these HTTP requests.
 */

const { test, expect } = require( '@playwright/test' );
const { harness } = require( '../utils/harness' );
const { PRODUCTS, addProductToCart, placeOrderWithNovac } = require( '../utils/shop' );

const WEBHOOK_PATH = '/?wc-api=Novac_Payment_Webhook';
const ALLOWED_IP = '18.233.137.110';

/**
 * POST a webhook payload.
 *
 * @param {import('@playwright/test').APIRequestContext} request Request context.
 * @param {Object}                                       payload Webhook body.
 * @param {string}                                       ip      Source IP to claim.
 * @return {Promise<import('@playwright/test').APIResponse>} Response.
 */
function sendWebhook( request, payload, ip = ALLOWED_IP ) {
	return request.post( WEBHOOK_PATH, {
		headers: {
			'Content-Type': 'application/json',
			'X-Forwarded-For': ip,
		},
		data: payload,
	} );
}

/**
 * Create a pending Novac order and return its id and transaction reference.
 *
 * @param {import('@playwright/test').Page}             page    Page.
 * @param {import('@playwright/test').APIRequestContext} request Request context.
 * @return {Promise<{id: number, reference: string}>} Order details.
 */
async function createPendingOrder( page, request ) {
	const api = harness( request );

	await addProductToCart( page, PRODUCTS.aboveMinimum );
	await placeOrderWithNovac( page );

	await expect( page.getByTestId( 'novac-hosted-page' ) ).toBeVisible();

	const reference = await page.getByTestId( 'novac-reference' ).innerText();
	const order = await api.latestOrder();

	return { id: order.id, reference: reference.trim() };
}

test.describe( 'Webhook handler', () => {
	test.beforeEach( async ( { request } ) => {
		const api = harness( request );
		await api.reset();
		await api.configureWorkingGateway();
	} );

	test( 'answers the connectivity ping', async ( { request } ) => {
		const response = await sendWebhook( request, { notify: 'test_assess', data: {} } );

		expect( response.status() ).toBe( 200 );
		expect( await response.json() ).toMatchObject( { status: 'success' } );
	} );

	test( 'refuses a caller from an unexpected address', async ( { request } ) => {
		const response = await sendWebhook(
			request,
			{ notify: 'test_assess', data: {} },
			'203.0.113.9'
		);

		expect( response.status() ).toBe( 401 );
	} );

	test( 'refuses a caller with no forwarded header at all', async ( { request } ) => {
		const response = await request.post( WEBHOOK_PATH, {
			headers: { 'Content-Type': 'application/json' },
			data: { notify: 'test_assess', data: {} },
		} );

		expect( response.status() ).toBe( 401 );
	} );

	// These go through the harness rather than real HTTP on purpose. The
	// WordPress Docker image runs Apache's mod_remoteip with the private ranges
	// listed as internal proxies, so it overwrites REMOTE_ADDR from
	// X-Forwarded-For before PHP is reached — a real request from the test
	// runner can never exercise the plugin's own trust decision. The cases below
	// are what protects a store whose web server is not configured to do that.
	test( 'ignores a forwarded header when no proxy is declared', async ( { request } ) => {
		const api = harness( request );

		const { ip } = await api.resolveIp( {
			remote_addr: '203.0.113.9',
			forwarded: ALLOWED_IP,
			trusted: [],
		} );

		// The caller named Novac's address in a header they set themselves.
		// Only the socket address means anything here.
		expect( ip ).toBe( '203.0.113.9' );
	} );

	test( 'believes a forwarded header from a declared proxy', async ( { request } ) => {
		const api = harness( request );

		const { ip } = await api.resolveIp( {
			remote_addr: '10.0.0.5',
			forwarded: ALLOWED_IP,
			trusted: [ '10.0.0.0/8' ],
		} );

		expect( ip ).toBe( ALLOWED_IP );
	} );

	test( 'takes the last untrusted hop, so prepended entries cannot lie', async ( {
		request,
	} ) => {
		const api = harness( request );

		// An attacker sends "X-Forwarded-For: <novac ip>"; the real proxy appends
		// their own address. Reading left to right would believe the attacker.
		const { ip } = await api.resolveIp( {
			remote_addr: '10.0.0.5',
			forwarded: `${ ALLOWED_IP }, 203.0.113.9`,
			trusted: [ '10.0.0.0/8' ],
		} );

		expect( ip ).toBe( '203.0.113.9' );
	} );

	test( 'walks back through chained proxies to the real client', async ( { request } ) => {
		const api = harness( request );

		const { ip } = await api.resolveIp( {
			remote_addr: '10.0.0.5',
			forwarded: `${ ALLOWED_IP }, 10.0.0.7`,
			trusted: [ '10.0.0.0/8' ],
		} );

		expect( ip ).toBe( ALLOWED_IP );
	} );

	test( 'falls back to the socket address on a malformed chain', async ( { request } ) => {
		const api = harness( request );

		const { ip } = await api.resolveIp( {
			remote_addr: '10.0.0.5',
			forwarded: 'not-an-ip',
			trusted: [ '10.0.0.0/8' ],
		} );

		expect( ip ).toBe( '10.0.0.5' );
	} );

	test( 'does not match an IPv4 caller against an IPv6 proxy range', async ( {
		request,
	} ) => {
		const api = harness( request );

		const { ip } = await api.resolveIp( {
			remote_addr: '10.0.0.5',
			forwarded: ALLOWED_IP,
			trusted: [ '2001:db8::/32' ],
		} );

		// The proxy does not match, so the header stays unbelieved.
		expect( ip ).toBe( '10.0.0.5' );
	} );

	test( 'rejects a reference that did not come from this plugin', async ( { request } ) => {
		const response = await sendWebhook( request, {
			notify: 'transaction',
			notifyType: 'payment',
			data: { transactionReference: 'SOMEONE_ELSES_REF', status: 'successful' },
		} );

		expect( response.status() ).toBe( 400 );
	} );

	test( 'rejects a payload with no data object', async ( { request } ) => {
		const response = await sendWebhook( request, { notify: '' } );

		expect( response.status() ).toBe( 400 );
	} );

	test( 'acknowledges without waiting on the Novac API', async ( { page, request } ) => {
		const api = harness( request );
		await api.setScenario( { verify_txn_state: 'successful' } );

		const order = await createPendingOrder( page, request );

		const started = Date.now();
		const response = await sendWebhook( request, {
			notify: 'transaction',
			notifyType: 'payment',
			data: { transactionReference: order.reference, status: 'successful' },
		} );
		const elapsed = Date.now() - started;

		expect( response.status() ).toBe( 200 );
		expect( await response.json() ).toMatchObject( { status: 'success' } );

		// The old handler slept two seconds before it did anything at all, then
		// held the connection open across the verify call. Novac's sender gave
		// up first. Verification now happens off the request.
		expect( elapsed ).toBeLessThan( 2000 );

		// Nothing has touched the order yet — the work is still queued.
		expect( ( await api.order( order.id ) ).status ).toBe( 'pending' );
	} );

	test( 'completes an order on a verified successful transaction', async ( { page, request } ) => {
		const api = harness( request );
		await api.setScenario( { verify_txn_state: 'successful' } );

		const order = await createPendingOrder( page, request );

		const response = await sendWebhook( request, {
			notify: 'transaction',
			notifyType: 'payment',
			data: { transactionReference: order.reference, status: 'successful' },
		} );

		expect( response.status() ).toBe( 200 );

		await api.runQueuedWebhooks();

		const detail = await api.order( order.id );
		expect( [ 'processing', 'completed' ] ).toContain( detail.status );
	} );

	test( 'holds an order when the verified amount does not match', async ( { page, request } ) => {
		const api = harness( request );
		await api.setScenario( { verify_txn_state: 'successful', verify_amount: '3.00' } );

		const order = await createPendingOrder( page, request );

		await sendWebhook( request, {
			notify: 'transaction',
			notifyType: 'payment',
			data: { transactionReference: order.reference, status: 'successful' },
		} );
		await api.runQueuedWebhooks();

		const detail = await api.order( order.id );
		expect( detail.status ).toBe( 'on-hold' );
	} );

	test( 'fails an order the API reports as failed', async ( { page, request } ) => {
		const api = harness( request );
		await api.setScenario( { verify_txn_state: 'failed' } );

		const order = await createPendingOrder( page, request );

		await sendWebhook( request, {
			notify: 'transaction',
			notifyType: 'payment',
			data: { transactionReference: order.reference, status: 'failed' },
		} );
		await api.runQueuedWebhooks();

		const detail = await api.order( order.id );
		expect( detail.status ).toBe( 'failed' );
	} );

	test( 'trusts the API over the webhook payload', async ( { page, request } ) => {
		const api = harness( request );

		// The webhook claims success; the verification call says otherwise.
		// The order must follow the API, not the caller.
		await api.setScenario( { verify_txn_state: 'failed' } );

		const order = await createPendingOrder( page, request );

		await sendWebhook( request, {
			notify: 'transaction',
			notifyType: 'payment',
			data: { transactionReference: order.reference, status: 'successful' },
		} );
		await api.runQueuedWebhooks();

		const detail = await api.order( order.id );
		expect( detail.status ).toBe( 'failed' );
	} );

	test( 'processes a redelivered event only once', async ( { page, request } ) => {
		const api = harness( request );
		await api.setScenario( { verify_txn_state: 'successful' } );

		const order = await createPendingOrder( page, request );
		const payload = {
			notify: 'transaction',
			notifyType: 'payment',
			data: { transactionReference: order.reference, status: 'successful' },
		};

		const first = await sendWebhook( request, payload );
		const second = await sendWebhook( request, payload );
		const third = await sendWebhook( request, payload );

		// All three are acknowledged — a retrying sender must not see an error —
		// but only the first is allowed to queue any work.
		expect( [ first, second, third ].map( ( r ) => r.status() ) ).toEqual( [ 200, 200, 200 ] );
		expect( await first.json() ).toMatchObject( { message: 'Webhook accepted' } );
		expect( await second.json() ).toMatchObject( { message: 'Already received' } );
		expect( await third.json() ).toMatchObject( { message: 'Already received' } );

		expect( await api.runQueuedWebhooks() ).toMatchObject( { ran: 1 } );

		const detail = await api.order( order.id );
		expect( [ 'processing', 'completed' ] ).toContain( detail.status );

		// One transition, so one "payment verified" note, not three.
		const verified = detail.notes.filter( ( note ) =>
			note.includes( 'Payment verified and successful' )
		);
		expect( verified ).toHaveLength( 1 );
	} );

	test( 'still processes a reversal after a success', async ( { page, request } ) => {
		const api = harness( request );
		await api.setScenario( { verify_txn_state: 'successful' } );

		const order = await createPendingOrder( page, request );

		await sendWebhook( request, {
			notify: 'transaction',
			notifyType: 'payment',
			data: { transactionReference: order.reference, status: 'successful' },
		} );
		await api.runQueuedWebhooks();
		expect( [ 'processing', 'completed' ] ).toContain(
			( await api.order( order.id ) ).status
		);

		// Same reference, different event. Claiming on the reference alone would
		// swallow this as a duplicate and leave the store thinking it was paid.
		await api.setScenario( { verify_txn_state: 'reversed' } );

		const response = await sendWebhook( request, {
			notify: 'transaction',
			notifyType: 'payment',
			data: { transactionReference: order.reference, status: 'reversed' },
		} );

		expect( await response.json() ).toMatchObject( { message: 'Webhook accepted' } );

		await api.runQueuedWebhooks();

		expect( ( await api.order( order.id ) ).status ).toBe( 'on-hold' );
	} );

	test( 'recovers a stranded job when the merchant opens wp-admin', async ( {
		page,
		request,
	} ) => {
		const api = harness( request );
		await api.setScenario( { verify_txn_state: 'successful' } );

		const order = await createPendingOrder( page, request );

		await sendWebhook( request, {
			notify: 'transaction',
			notifyType: 'payment',
			data: { transactionReference: order.reference, status: 'successful' },
		} );

		// Never drained — this is the store whose cron or loopback is blocked,
		// so Action Scheduler queued the job and nothing ever ran it.
		await api.ageQueuedWebhooks( 600 );
		expect( ( await api.order( order.id ) ).status ).toBe( 'pending' );

		await page.goto( '/wp-admin/' );

		const detail = await api.order( order.id );
		expect( [ 'processing', 'completed' ] ).toContain( detail.status );

		// Self-healing must not hide the misconfiguration that caused it.
		await expect(
			page.locator( '.notice-warning', {
				hasText: 'not being processed on time',
			} )
		).toBeVisible();
	} );

	test( 'processes inline while the queue is known to be stalled', async ( {
		page,
		request,
	} ) => {
		const api = harness( request );
		await api.setScenario( { verify_txn_state: 'successful' } );

		// Strand one job to establish that the queue is not draining.
		const stranded = await createPendingOrder( page, request );
		await sendWebhook( request, {
			notify: 'transaction',
			notifyType: 'payment',
			data: { transactionReference: stranded.reference, status: 'successful' },
		} );
		await api.ageQueuedWebhooks( 600 );

		// The next delivery must not join a queue that demonstrably never runs.
		const order = await createPendingOrder( page, request );
		const response = await sendWebhook( request, {
			notify: 'transaction',
			notifyType: 'payment',
			data: { transactionReference: order.reference, status: 'successful' },
		} );

		expect( response.status() ).toBe( 200 );
		expect( await response.json() ).toMatchObject( {
			message: 'Order Processed Successfully',
		} );

		// Handled during the webhook request itself, with nothing draining a queue.
		const detail = await api.order( order.id );
		expect( [ 'processing', 'completed' ] ).toContain( detail.status );
	} );

	test( 'gives up rather than looping on an unusable verify response', async ( {
		page,
		request,
	} ) => {
		const api = harness( request );

		// HTTP 200 with a body that decodes to nothing. The old loop only
		// counted transport errors and non-200 responses, so this one spun
		// until the worker hit max_execution_time.
		await api.setScenario( { verify_raw_body: '' } );

		const order = await createPendingOrder( page, request );

		await sendWebhook( request, {
			notify: 'transaction',
			notifyType: 'payment',
			data: { transactionReference: order.reference, status: 'successful' },
		} );
		await api.runQueuedWebhooks();

		const detail = await api.order( order.id );
		expect( detail.status ).toBe( 'on-hold' );

		// Bounded at three attempts.
		const verifyCalls = ( await api.requests() ).filter( ( entry ) =>
			entry.url.includes( `/checkout/${ order.reference }/verify` )
		);
		expect( verifyCalls ).toHaveLength( 3 );
	} );
} );
