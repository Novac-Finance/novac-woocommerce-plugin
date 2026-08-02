#!/usr/bin/env bash
#
# Bootstrap the wp-env test store into a known state for the E2E suite.
#
# Idempotent: safe to re-run against an existing environment.

set -euo pipefail

WP="npx wp-env run tests-cli wp"

# ---------------------------------------------------------------------------
# Apache in the wp-env image ships with `AllowOverride None`, so .htaccess is
# ignored and every pretty permalink 404s — including /wp-json/ and the product
# and cart pages the suite navigates. Enable overrides before anything else.
# ---------------------------------------------------------------------------
echo "==> Enabling .htaccess overrides so pretty permalinks resolve"
WP_CONTAINER=$(docker ps --filter "name=tests-wordpress" --format "{{.Names}}" | head -1)

if [ -z "$WP_CONTAINER" ]; then
  echo "    Could not find the tests-wordpress container. Is wp-env running?" >&2
  exit 1
fi

# WP-CLI declines to generate .htaccess in this image ("requires special
# configuration"), so write both the override and the rewrite rules ourselves.
docker exec "$WP_CONTAINER" bash -c 'cat > /etc/apache2/conf-enabled/wp-permalinks.conf <<EOF
<Directory /var/www/html>
    Options FollowSymLinks
    AllowOverride All
    Require all granted
</Directory>
EOF
cat > /var/www/html/.htaccess <<EOF
# BEGIN WordPress
<IfModule mod_rewrite.c>
RewriteEngine On
RewriteRule .* - [E=HTTP_AUTHORIZATION:%{HTTP:Authorization}]
RewriteBase /
RewriteRule ^index\.php\$ - [L]
RewriteCond %{REQUEST_FILENAME} !-f
RewriteCond %{REQUEST_FILENAME} !-d
RewriteRule . /index.php [L]
</IfModule>
# END WordPress
EOF
chown www-data:www-data /var/www/html/.htaccess
apache2ctl graceful' >/dev/null 2>&1

# Apache needs a moment to come back after a graceful restart.
for _ in $(seq 1 15); do
  if curl -fsS -o /dev/null "http://localhost:8889/"; then break; fi
  sleep 1
done

# Novac is mounted rather than registered in .wp-env.json's "plugins", because
# wp-env activates that list before WooCommerce exists and Novac declares
# `Requires Plugins: woocommerce` — which makes `wp-env start` exit non-zero and
# fail the CI job. Activating here guarantees WooCommerce comes first.
echo "==> Activating plugins"
$WP plugin activate woocommerce
$WP plugin activate novac-woo

echo "==> Permalinks (WooCommerce needs pretty permalinks for wc-api endpoints)"
$WP rewrite structure '/%postname%/' --hard
$WP rewrite flush --hard

echo "==> Store currency and address"
$WP option update woocommerce_currency NGN
$WP option update woocommerce_default_country 'NG:LA'
$WP option update woocommerce_store_address '1 Test Street'
$WP option update woocommerce_store_city 'Lagos'
$WP option update woocommerce_store_postcode '100001'

echo "==> Skip onboarding so the admin never redirects mid-test"
$WP option update woocommerce_onboarding_profile '{"skipped":true}' --format=json
# These may not exist yet, so create-or-update and never fail the run on them.
$WP option update woocommerce_task_list_hidden yes 2>/dev/null \
  || $WP option add woocommerce_task_list_hidden yes 2>/dev/null || true
$WP option update woocommerce_task_list_welcome_modal_dismissed yes 2>/dev/null \
  || $WP option add woocommerce_task_list_welcome_modal_dismissed yes 2>/dev/null || true
$WP transient delete _wc_activation_redirect 2>/dev/null || true

echo "==> Launching the store (its 'coming soon' banner covers the Place order button)"
$WP option update woocommerce_coming_soon no
$WP option update woocommerce_store_pages_only no
$WP option update woocommerce_private_link no

echo "==> Shipping and tax off, so totals stay exactly what the tests set"
$WP option update woocommerce_calc_taxes no
$WP option update woocommerce_enable_shipping_calc no
$WP option update woocommerce_ship_to_countries 'disabled'

echo "==> Guest checkout on, account creation off"
$WP option update woocommerce_enable_guest_checkout yes
$WP option update woocommerce_enable_signup_and_login_from_checkout no

echo "==> Products"
# Priced deliberately either side of the NGN 100 minimum (NOVAC-02).
create_product() {
  local slug="$1" title="$2" price="$3"
  local existing
  existing=$($WP post list --post_type=product --name="$slug" --field=ID --format=ids)

  if [ -z "$existing" ]; then
    local id
    id=$($WP post create --post_type=product --post_title="$title" --post_name="$slug" \
         --post_status=publish --porcelain)
    $WP post meta update "$id" _price "$price"
    $WP post meta update "$id" _regular_price "$price"
    $WP post meta update "$id" _virtual yes
    $WP post meta update "$id" _manage_stock no
    $WP post meta update "$id" _stock_status instock
    echo "    created $slug (#$id) at $price"
  else
    $WP post meta update "$existing" _price "$price" >/dev/null
    $WP post meta update "$existing" _regular_price "$price" >/dev/null
    echo "    reused $slug (#$existing) at $price"
  fi
}

create_product "novac-above-minimum" "Novac Above Minimum" "5000"
create_product "novac-below-minimum" "Novac Below Minimum" "50"

echo "==> Classic (shortcode) cart and checkout pages, alongside WooCommerce's block pages"
create_page() {
  local slug="$1" title="$2" content="$3"
  local existing
  existing=$($WP post list --post_type=page --name="$slug" --field=ID --format=ids)

  if [ -z "$existing" ]; then
    $WP post create --post_type=page --post_title="$title" --post_name="$slug" \
        --post_status=publish --post_content="$content" --porcelain
    echo "    created page $slug"
  else
    echo "    reused page $slug"
  fi
}

create_page "classic-cart" "Classic Cart" '[woocommerce_cart]'
create_page "classic-checkout" "Classic Checkout" '[woocommerce_checkout]'

# Point WooCommerce at the shortcode checkout by default: the classic flow is
# where most of the gateway's behaviour lives. Block specs navigate explicitly.
CLASSIC_CHECKOUT_ID=$($WP post list --post_type=page --name=classic-checkout --field=ID --format=ids)
CLASSIC_CART_ID=$($WP post list --post_type=page --name=classic-cart --field=ID --format=ids)
$WP option update woocommerce_checkout_page_id "$CLASSIC_CHECKOUT_ID"
$WP option update woocommerce_cart_page_id "$CLASSIC_CART_ID"

echo "==> Resetting Novac gateway settings to a known-empty state"
$WP option delete woocommerce_novac_settings 2>/dev/null || true

echo "==> Verifying pretty permalinks actually resolve"
REST_CODE=$(curl -s -o /dev/null -w '%{http_code}' "http://localhost:8889/wp-json/")

if [ "$REST_CODE" != "200" ]; then
  echo "    /wp-json/ returned HTTP $REST_CODE — pretty permalinks are not working." >&2
  echo "    The suite navigates product and cart URLs, so it cannot run in this state." >&2
  exit 1
fi

echo "    /wp-json/ OK"
echo "==> Done. Store is ready."
