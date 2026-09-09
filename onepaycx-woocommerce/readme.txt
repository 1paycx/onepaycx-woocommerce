=== 1pay.cx for WooCommerce ===
Contributors: 1paycx
Tags: woocommerce, payment gateway, card payments, bitcoin, monero
Requires at least: 6.0
Tested up to: 6.6
Requires PHP: 7.4
Stable tag: 1.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Accept card, Bitcoin and Monero payments in WooCommerce with 1pay.cx. Buyers pay on a hosted checkout; you settle in crypto.

== Description ==

1pay.cx lets your WooCommerce store accept card payments and Bitcoin or Monero.
Your buyer pays on a hosted, branded checkout — by card, or from any crypto wallet
with no ID check. Card sales settle to you in USDC; crypto is credited to your
1pay.cx balance at a flat 1% and can be withdrawn to your own wallet any time.
There is nothing to configure beyond pasting your API key.

* Adds "Pay by card or crypto" to both the classic and block-based WooCommerce checkout.
* Orders are completed automatically via a signed webhook — no manual reconciliation,
  whichever way the buyer paid.
* No card data ever touches your server.

== Installation ==

1. In WordPress admin, go to Plugins -> Add New -> Upload Plugin and upload the ZIP.
2. Activate the plugin.
3. Go to WooCommerce -> Settings -> Payments -> 1pay.cx.
4. Enable it, paste your API key (from your 1pay.cx dashboard: Settings -> API keys), and Save.
   The plugin verifies the key and configures the webhook automatically.

That's it — your checkout now offers "Pay by card or crypto".

== Frequently Asked Questions ==

= Where do I get an API key? =
Sign in at https://1pay.cx, then open Settings -> API keys and copy the key (pk_...).

= Do I need to configure a webhook? =
No. The plugin passes its own callback URL with each order and fetches the signing
secret automatically when you save your API key.

= Do buyers need an account or ID to pay with crypto? =
No. They pick Bitcoin or Monero on the checkout, get an exact amount and address
locked for 20 minutes, and pay from any wallet. The order completes once the
network confirms.

= Are refunds supported? =
Card refunds are not supported by this payment method; issue refunds via your usual
store-credit or manual process.

== Changelog ==

= 1.1.0 =
* Checkout now also offers Bitcoin and Monero; orders complete the same way whichever
  method the buyer used. Default gateway title is now "Pay by card or crypto".

= 1.0.0 =
* Initial release: classic + block checkout, automatic order completion via signed webhook.
