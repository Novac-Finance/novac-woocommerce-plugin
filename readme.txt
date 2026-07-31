=== Novac Woo ===
Contributors: engineeringnovac
Tags: payments, mastercard, visa, woocommerce, commerce, storefront
Requires at least: 6.0
Tested up to: 6.9
Requires PHP: 7.3
Stable tag: 1.0.2
License: GPLv3
License URI: http://www.gnu.org/licenses/gpl-3.0.html
Accept both international and local payments on from your store.

== Description ==

collect both international an local payments on your store via novac.

= Plugin Features =
* Collections: Card, Bank Transfer, and USSD.

= Requirements =

1. WordPress 6.0 or newer.
2. Novac Merchant Account [API Keys](https://www.app.novacpayment.com/settings/api-keys)
3. WooCommerce 7.6 or newer.
4. Supported PHP version: 7.4.0 or newer is recommended.

== Installation ==
= Manual Installation =
1.  Download the plugin zip file.
2.  Login to your WordPress Admin. Click on "Plugins > Add New" from the left menu.
3.  Click on the "Upload" option, then click "Choose File" to select the zip file you downloaded. Click "OK" and "Install Now" to complete the installation.
4.  Activate the plugin.
5.  Click on "WooCommerce > Settings" from the left menu and click the "Checkout" tab.
6.  Click on the __Novac__ link from the available Checkout Options
7. Configure your __Novac__ settings accordingly.

== Source Code & Build Process ==
This plugin uses modern build tools to generate production-ready JavaScript and CSS. The original source code is available in a public repository:

📌 **Source Code Repository:**
[GitHub - novac-woo](https://github.com/novac/novac-woo)

** Build Process **
The full, unminified source code for our plugin is  publicly available at: https://github.com/novac/novac-woo
Our plugin uses modern build tools, including Webpack (wp-scripts) and UglifyJS, to generate production-ready JavaScript and CSS.

For FTP manual installation, [check here](http://codex.wordpress.org/Managing_Plugins#Manual_Plugin_Installation).

= Configure the plugin =
To configure the plugin, go to __WooCommerce > Settings__ from the left menu, click __Checkout__ tab. Click on __Novac__.
Alternatively you can see the novac button on the sidebar. click it.

* __Enable/Disable__ - check the box to enable Novac.
* Configure your general setting by providing your merchant secret key and public key.
* Testmode is enabled by default. To make live collections disable Test mode.
* Click __Save Changes__ to save your changes in each section.

= Webhooks =

= 1.0.1 =
* Fixed bug that incorrectly transitioned transactions from Pending/Failed to Successful.
= 1.0.0 =
*   First release
*   Added: Support for WooCommerce Blocks.
*   Updated: WooCommerce Checkout Process
*   Added: Webhook Handler Acknowledgement.
*   Added: Support for HPOS.
*   Added: compatibility with WooCommerce 7.1 to 6.9.1

== External Services ==

This plugin integrates with external services to process payments and provide a seamless checkout experience. Below is a detailed explanation of the services used:

1. **Novac Inline Payment Integration**
   - **What it is used for**: This service facilitates payment processing through the Novac Inline Payment system.
   - **Data Sent**: The plugin sends transaction details, such as the amount, currency, and user-specific identifiers, to Novac's API endpoints.
     - **API Endpoint**: `https://api.novacpayment.com/api/v1/`
   - **When data is sent**:
     - Data is sent to Novac's API when a user initiates a payment.
     - The inline script is loaded on the checkout page for payment functionality.
   - **Links**:
     - [Novac Terms of Service](https://www.novacpayment.com/uk/terms-of-use)
     - [Novac Privacy Policy](https://www.novacpayment.com/uk/privacy-policy)

= Contribution =

We love to get your input. you can also include or suggest feature via Github [here](https://github.com/novac/novac-woo/issues)

== Screenshots ==



== Other Notes ==