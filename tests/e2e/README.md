# Novac WooCommerce — E2E tests

Playwright suite driving a real WordPress + WooCommerce install through
[`@wordpress/env`](https://www.npmjs.com/package/@wordpress/env).

## Why the Novac API is mocked

The suite never calls `api.novacpayment.com`. A mu-plugin
(`mu-plugins/novac-e2e-harness.php`) filters the gateway's base URL to a
same-host address and hooks WordPress's `pre_http_request` to answer every
Novac request from a scripted scenario. Nothing leaves the container, and the
runner never has to resolve the real API hostname.

This is deliberate:

- The workflow runs twice a day. Against the real API that would create real
  transactions on a real merchant account, twice a day, forever.
- CI would need live secrets.
- Most of what needs testing is the *failure* branches — a 401 from bad
  credentials, a sub-minimum rejection, a 2xx with no payment link, a timeout.
  You cannot ask a real payment API to produce those on demand.

The harness also stands in for Novac's hosted payment page, so the redirect
flow completes in-browser without leaving the local site.

## Running locally

Requires Docker.

```bash
pnpm install
pnpm exec playwright install --with-deps chromium

pnpm run test:e2e:setup   # start wp-env, build assets, bootstrap the store
pnpm run test:e2e         # run the suite
```

Useful variants:

```bash
pnpm run test:e2e:headed              # watch it drive the browser
pnpm run test:e2e:ui                  # Playwright's interactive UI
pnpm run test:e2e -- settings.spec.js # one file
pnpm run test:e2e:report              # open the HTML report after a run
```

Tear down with `pnpm run env:stop`, or `pnpm run env:destroy` to wipe the
database and start clean.

## Layout

```
tests/e2e/
├── bin/setup-store.sh              WP-CLI store bootstrap (idempotent)
├── mu-plugins/novac-e2e-harness.php  API mock, control endpoints, hosted page
├── utils/harness.js                Client for the harness control endpoints
├── utils/shop.js                   Cart and checkout helpers
├── global-setup.js                 Admin login, harness liveness check
└── specs/                          The tests
```

## Writing a test

Script the API, then drive the browser:

```js
const { harness } = require( '../utils/harness' );

test( 'surfaces the rejection reason', async ( { page, request } ) => {
    const api = harness( request );

    await api.reset();
    await api.configureWorkingGateway();
    await api.setScenario( {
        initiate_status: 400,
        initiate_body: { message: 'Amount is below the minimum chargeable value' },
    } );

    // ...place an order, assert the message reaches the customer
} );
```

### Scenario options

| Key | Default | Effect |
|---|---|---|
| `initiate_status` | `200` | HTTP status for `POST /initiate` |
| `initiate_body` | `null` | Explicit body; `null` builds a success payload |
| `initiate_error` | `''` | Non-empty returns a `WP_Error` (transport failure) |
| `verify_status` | `200` | HTTP status for the verify call |
| `verify_txn_state` | `successful` | `successful` / `pending` / `failed` / `cancelled` / `reversed` |
| `verify_amount` | `null` | `null` echoes the order total; set a value to force a mismatch |
| `verify_currency` | `null` | `null` echoes the order currency |
| `verify_error` | `''` | Non-empty returns a `WP_Error` |
| `verify_raw_body` | `null` | Non-null returns HTTP 200 carrying that exact body — use `''` for the unusable-body case |
| `probe_status` | `200` | Status for the credential probe made on settings save |

`configureWorkingGateway()` puts the store into an enabled, test-mode,
fully-keyed state — the starting point for most specs.

### Queued webhook work

The webhook handler acknowledges the delivery and queues verification on
Action Scheduler, so the order is untouched when the response comes back. A
spec that wants to assert on order state drains the queue first:

```js
await sendWebhook( request, payload );
await api.runQueuedWebhooks();     // runs the queued novac_process_webhook jobs

const detail = await api.order( order.id );
```

Only that hook is drained. The claim-expiry jobs are scheduled a day out, and
running them early would defeat the idempotency they exist to provide.

To reach the *stalled queue* branches — the safety net for stores where cron or
loopback is blocked and nothing ever runs the queue — backdate the jobs instead
of draining them:

```js
await sendWebhook( request, payload );
await api.ageQueuedWebhooks( 600 );   // now overdue past the stall threshold

await page.goto( '/wp-admin/' );      // admin_init sweep runs it in-process
```

`ageQueuedWebhooks()` also clears the plugin's cached stall verdict, so the very
next request sees the state it just created rather than a minute-old answer.

## Store fixtures

`setup-store.sh` creates:

- Currency **NGN**, taxes and shipping off, guest checkout on
- `novac-above-minimum` — NGN 5000
- `novac-below-minimum` — NGN 50 (below the NGN 100 threshold)
- `/classic-cart/` and `/classic-checkout/` shortcode pages, alongside
  WooCommerce's own block-based `/cart/` and `/checkout/`

Both checkout types are covered: most specs use the classic flow, and
`buy-now-and-blocks.spec.js` exercises the block checkout.

## CI

`.github/workflows/e2e.yml` runs at **00:00 and 12:00 UTC** daily, on
`workflow_dispatch`, and on PRs touching plugin or test code.

Two things worth knowing:

- GitHub's cron is UTC-only and makes no punctuality guarantee — scheduled runs
  can be delayed under load.
- Scheduled runs are disabled automatically after 60 days of repository
  inactivity. A repo that goes quiet stops being tested silently.

On a scheduled failure the workflow opens (or comments on) an issue labelled
`e2e-failure`, so an unattended break is not lost. Create that label, or the
notify step cannot apply it.

Failed runs upload the Playwright HTML report, traces for failed specs, and the
WordPress debug log.

## Environment quirks the bootstrap works around

These are not optional tweaks — the suite cannot run without them, and each one
cost a debugging cycle to find. `setup-store.sh` handles all three.

- **Apache ships with `AllowOverride None`**, so `.htaccess` is ignored and every
  pretty permalink 404s, including `/wp-json/` and all product and cart URLs.
  The bootstrap writes an Apache conf enabling overrides *and* writes
  `.htaccess` itself, because WP-CLI declines to generate it in this image.
  The script fails loudly if `/wp-json/` still 404s afterwards.
- **WooCommerce's "coming soon" mode** renders a fixed footer banner that sits
  over the Place order button and swallows the click. The bootstrap launches the
  store (`woocommerce_coming_soon=no`).
- **The plugin is mounted via `mappings`, not listed in `plugins`.** wp-env
  activates the `plugins` list in order, and Novac declares
  `Requires Plugins: woocommerce`, so activation fails and `wp-env start` exits
  non-zero — which fails the CI job before a single test runs. The bootstrap
  activates WooCommerce first, then Novac.

## Known constraints

- Specs share one WordPress install and one cart session, so they run serially
  (`workers: 1`). Do not add `test.describe.parallel`.
- The webhook specs assert the plugin's IP restriction by sending
  `X-Forwarded-For: 18.233.137.110`, matching `NOVAC_WOO_ALLOWED_WEBHOOK_IP_ADDRESS`.
  If that constant changes, `webhook.spec.js` must change with it.
- WooCommerce hides the payment-method radio when only one gateway is available.
  Assert on presence and checked state, not visibility.
- Cart removal links are AJAX-wired and detach mid-click, which hangs
  `click()`. `emptyCart()` follows the link's `href` as a plain navigation
  instead — do not "simplify" it back to a click.
- The classic checkout covers itself with a blockUI overlay during every
  order-review refresh. Anything that clicks after editing a field must call
  `waitForCheckoutIdle()` first.
