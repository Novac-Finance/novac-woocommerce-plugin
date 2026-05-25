(function () {
    'use strict';

    function isBlocksProductPage() {
        // WC Blocks renders an add-to-cart block; classic pages use form.cart
        return !document.querySelector('form.cart');
    }

    function addToCartViaStoreApi(btn) {
        var productId   = btn.getAttribute('data-product-id');
        var checkoutUrl = btn.getAttribute('data-checkout-url');
        var nonce       = btn.getAttribute('data-nonce');

        if (!productId || !checkoutUrl) return;

        btn.disabled = true;

        fetch('/wp-json/wc/store/v1/cart/add-item', {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json',
                'Nonce':        nonce || '',
            },
            body: JSON.stringify({ id: parseInt(productId, 10), quantity: 1 }),
        })
        .then(function (res) {
            if (!res.ok) { throw new Error('Store API error ' + res.status); }
            window.location.href = checkoutUrl;
        })
        .catch(function () {
            btn.disabled = false;
        });
    }

    // Capture phase runs before WC's bubble-phase jQuery handlers.
    document.addEventListener('click', function (e) {
        var btn = e.target.closest('.novac-buy-now-btn');
        if (!btn) return;

        e.preventDefault();
        e.stopImmediatePropagation();

        // ── Blocks product page path ───────────────────────────────────────────
        if (isBlocksProductPage()) {
            addToCartViaStoreApi(btn);
            return;
        }

        // ── Classic (shortcode) product page path ──────────────────────────────
        var form = btn.closest('form.cart');
        if (!form) return;

        // When form.submit() is called programmatically no submit button's
        // name/value is sent. Inject add-to-cart so WooCommerce processes the
        // cart addition (value comes from the real add-to-cart button).
        var addToCartBtn = form.querySelector('button[name="add-to-cart"], input[name="add-to-cart"][type="submit"]');
        if (addToCartBtn && !form.querySelector('input[name="add-to-cart"][type="hidden"]')) {
            var productField = document.createElement('input');
            productField.type  = 'hidden';
            productField.name  = 'add-to-cart';
            productField.value = addToCartBtn.value;
            form.appendChild(productField);
        }

        // Signal the PHP redirect filter to send the customer to checkout.
        if (!form.querySelector('input[name="novac_buy_now"]')) {
            var buyNowField = document.createElement('input');
            buyNowField.type  = 'hidden';
            buyNowField.name  = 'novac_buy_now';
            buyNowField.value = '1';
            form.appendChild(buyNowField);
        }

        // Native prototype call bypasses all jQuery / WC AJAX submit handlers.
        HTMLFormElement.prototype.submit.call(form);
    }, true);

}());
