(function ($) {
    'use strict';

    var gatewayId = (typeof novacBuyNow !== 'undefined') ? novacBuyNow.gatewayId : 'novac';

    // ── Detection ──────────────────────────────────────────────────────────────

    function isBlocksCheckout() {
        return !!document.querySelector('.wp-block-woocommerce-checkout');
    }

    // ── Classic (shortcode) checkout ───────────────────────────────────────────

    function selectNovacClassic() {
        var radioId = 'payment_method_' + gatewayId;
        var radio   = document.getElementById(radioId);
        if (!radio) return;

        if (!radio.checked) {
            radio.checked = true;
            $(radio).trigger('change');
            $('body').trigger('update_checkout');
        }

        var placeOrder = document.getElementById('place_order');
        if (placeOrder) {
            placeOrder.scrollIntoView({ behavior: 'smooth', block: 'center' });
            placeOrder.focus();
        }
    }

    function initClassic() {
        $('body').on('updated_checkout', function () {
            selectNovacClassic();
        });
        $(document).ready(function () {
            selectNovacClassic();
        });
    }

    // ── WooCommerce Blocks checkout ────────────────────────────────────────────

    function selectNovacBlocks(container) {
        // Blocks renders radio inputs with a different id convention.
        var radioId = 'radio-control-wc-payment-method-options-' + gatewayId;
        var radio   = document.getElementById(radioId);

        if (radio && !radio.checked) {
            // Click (not just .checked = true) so React's synthetic event fires.
            radio.click();
        }

        var placeOrderBtn = container.querySelector(
            '.wc-block-components-checkout-place-order-button'
        );
        if (placeOrderBtn) {
            placeOrderBtn.scrollIntoView({ behavior: 'smooth', block: 'center' });
            placeOrderBtn.focus();
        }
    }

    function initBlocks() {
        var container = document.querySelector('.wp-block-woocommerce-checkout');
        if (!container) return;

        // React renders payment options asynchronously; poll with MutationObserver.
        var attempted = false;
        var observer  = new MutationObserver(function () {
            var radioId = 'radio-control-wc-payment-method-options-' + gatewayId;
            if (document.getElementById(radioId)) {
                if (!attempted) {
                    attempted = true;
                    selectNovacBlocks(container);
                }
                observer.disconnect();
            }
        });

        observer.observe(container, { childList: true, subtree: true });

        // Fallback: if radio is already present before observer fires.
        var radioId = 'radio-control-wc-payment-method-options-' + gatewayId;
        if (document.getElementById(radioId)) {
            attempted = true;
            observer.disconnect();
            selectNovacBlocks(container);
        }
    }

    // ── Entry point ────────────────────────────────────────────────────────────

    if (isBlocksCheckout()) {
        initBlocks();
    } else {
        initClassic();
    }

}(jQuery));
