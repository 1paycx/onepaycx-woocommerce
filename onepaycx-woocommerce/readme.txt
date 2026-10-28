=== High Risk Card & Crypto Payment Gateway with Instant Payouts ===
Contributors: 1pay
Tags: woocommerce, crypto payments, bitcoin, usdc, high risk
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 7.4
Requires Plugins: woocommerce
WC requires at least: 7.0
WC tested up to: 11.2
Stable tag: 3.0.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Accept card and crypto payments in WooCommerce with no signup. Paste your wallet address and every payment is sent on to your own wallets.

== Description ==

Accept cards and crypto in WooCommerce with no account: paste a wallet address and get paid to your own wallet. No business verification is needed to start, subject to our [Acceptable Use Policy](https://1pay.cx/acceptable-use). Suitable for eligible high-risk businesses.

### How wallet-only mode works

1. **Paste an EVM address** (starts with 0x) from a wallet you control. It receives payouts on every EVM network. Never use an exchange deposit address: an exchange may not credit payouts on every network.
2. **Choose how buyers pay.** All card and wallet methods (including new ones) and USDC and USDT on Polygon, USDC on Base and USDT on BNB Chain are on by default. Switch any off or add more EVM coins.
3. **Get paid.** Card payouts usually arrive as USDC on Polygon, but can arrive as another coin or stablecoin (for example USDT on BNB Chain, ETH or POL) on Polygon, Base, Ethereum or BNB Chain, always to this same address.
4. **Add other coins** (Monero, Bitcoin, Litecoin, Solana, TRON, XRP), each with its own payout address.

Monero pays out once about $10 builds up; Bitcoin, Litecoin and Ethereum at about $200; other networks right away. Amounts below a network's payout minimum wait for the next payment. A waiting payout shows as a WooCommerce notice.

The plugin is free. Crypto costs 1% plus the network fee. Cards cost 2.9% plus the card processor's 1–5% (about 4–8% all-in).

### Optional free account

Start one in the settings to keep your store's history, payout wallets and keys, or connect an existing account or API key. It adds swaps, statistics, balances, email alerts (wallet-only stores get no email) and a custom API. Existing API-key stores keep working unchanged.

Questions or need help? Join our Telegram channel: [t.me/onepaycx_official](https://t.me/onepaycx_official).

### Features

* Hosted checkout for cards, supported mobile wallets, Bitcoin, Litecoin, Monero and supported stablecoins.
* Orders marked paid automatically after a signed notification and a separate status check.
* Classic and block checkout; HPOS compatible.
* Custom checkout title and description.
* Optional direct card checkout that skips the card-or-crypto page.
* Test mode: test orders move no money.
* Payment references and confirmation notes saved on the order.
* Card details never pass through your WordPress server.

== Installation ==

1. Install and activate WooCommerce.
2. In **Plugins > Add New**, search for "High Risk Card & Crypto Payment Gateway with Instant Payouts", install it and activate it. You can also upload the plugin ZIP.
3. Open **WooCommerce > Settings > Payments > 1pay.cx**.
4. Enable the gateway, paste your EVM wallet address and choose your payment methods and coins. For Monero, Bitcoin, Litecoin, Solana, TRON or XRP, open the coin and add its payout address.
5. Save. Your store is registered with 1pay.cx on the first save and the payment-notification secret is retrieved automatically. Look for confirmation before accepting payments.
6. Switch on **Test mode**, place a test order and check your checkout and order updates. Switch it off when you are ready for live payments.

Already have a 1pay.cx account? Choose **My 1pay.cx account** in the settings and use **Connect**, or paste an API key created in **Settings > API keys** in your 1pay.cx dashboard (with permission to create and read payments). Payouts then follow your account settings.

[Setup and API documentation](https://1pay.cx/docs#woocommerce) | [Service status](https://1pay.cx/status) | [Fees](https://1pay.cx/merchants#pricing)

== External services ==

The plugin needs the 1pay.cx payment service, operated by 1pay.cx, to take payments. It sends all requests to `https://api.1pay.cx`; you and your buyers visit `https://1pay.cx` for checkout, connect and account pages. The plugin loads no analytics, tracking, remote scripts or remote images. Like any web request, each call reveals your server's IP address and the plugin's user agent (`onepaycx-woocommerce/<version>`).

= Store setup (wallet-only mode) =

* **First save with a valid wallet address:** sends your EVM address, site URL, store name and plugin version to `https://api.1pay.cx/wallet-stores`, and keeps the live and test API keys it returns in your WordPress options.
* **Every settings save:** sends all your payout addresses, the payment methods you switched off, the coins you accept and your store name to `https://api.1pay.cx/wallet-stores/settings`, which replies with the coins accepted or refused.

= Each order =

* **When a buyer chooses 1pay.cx:** sends the order amount, currency, billing email, order reference and your return and notification URLs to `https://api.1pay.cx/v1/payments`, then sends the buyer to `https://1pay.cx` to pay.
* **In account mode, with narrowed methods or coins:** also sends those choices, and may first read the methods and coins your account allows from `https://api.1pay.cx/v1/methods` (with your API key) and the public list from `https://api.1pay.cx/payment-methods`, each cached for ten minutes.

= Status checks =

* **Each signed payment notification:** checks the payment's status at `https://api.1pay.cx/v1/payments/{payment-id}` with your API key before marking the order paid.
* **A failed notification signature** (for example after switching test mode): re-reads your signing secret from `https://api.1pay.cx/v1/me` with your API key, at most once a minute, and checks again.
* **Settings screen:** reads your store's status (merchant ID, business name, signing secret, number and value of waiting payouts) from `https://api.1pay.cx/v1/me` at most every five minutes and after each save, and the method and coin lists from `https://api.1pay.cx/payment-methods` (sends nothing about your store) and `https://api.1pay.cx/v1/methods`.

= Account features (optional) =

* **Saving or refreshing an API key:** sends it to `https://api.1pay.cx/v1/me` to validate it and fetch your merchant ID and signing secret.
* **Connect:** opens `https://1pay.cx/connect` with your site's host name and settings-screen address, then sends the one-time code you return with to `https://api.1pay.cx/wallet-stores/connect/exchange` for your account's API key.
* **Create free account:** sends your settings-screen address to `https://api.1pay.cx/wallet-stores/claim-link` for a short-lived link, opened at `https://1pay.cx/connect`.

[Terms of Service](https://1pay.cx/terms) | [Privacy Policy](https://1pay.cx/privacy) | [Acceptable Use Policy](https://1pay.cx/acceptable-use)

== Screenshots ==

1. The 1pay.cx settings screen in WooCommerce: choose wallet-only mode (no signup) or your 1pay.cx account, paste your payout wallet, and pick the card and wallet methods buyers can use.
2. Crypto payments: EVM coins pay out to the same wallet. Turn on Monero, Bitcoin, Litecoin, Solana, TRON or XRP and paste your address for each.
3. Classic WooCommerce checkout with the 1pay.cx "Pay with card or crypto" option (the title and description are yours to set).
4. Block-based WooCommerce checkout with the same 1pay.cx payment method.
5. A demonstration order after payment confirmation, with the payment reference and confirmation note recorded in WooCommerce.

== Frequently Asked Questions ==

= Do I need a 1pay.cx account? =

No. In wallet-only mode, which is the default for new installs, you paste a wallet address and start selling. An account is optional and adds swaps, statistics, balances, email alerts and a custom API. Business verification is not required to get started; your business must comply with the service's terms and acceptable-use policy.

= Which wallet should I use? =

Use an EVM wallet that you control, for example a hardware or software wallet, and keep its recovery phrase safe. Do not use an exchange deposit address. Card payouts usually arrive as USDC on Polygon; sometimes as another coin or stablecoin (for example USDT on BNB Chain, ETH or POL) on Polygon, Base, Ethereum or BNB Chain, always to this same address, and an exchange may not credit every coin or network. Anyone who can change this plugin's settings can change where your payouts go, so protect your WordPress admin accounts. With a 1pay.cx account, payout address changes go through the dashboard and its usual security.

= Does this plugin require WooCommerce? =

Yes. This is a WooCommerce payment gateway for store orders. Version 2.0.0 made this listing WooCommerce-only; the page-button block and shortcode from the earlier standalone WordPress release are not included.

= Which payment methods can my customers use? =

The hosted checkout supports cards and available mobile wallets, Bitcoin, Litecoin, Monero and supported stablecoins. Availability varies by country, device, order amount, network and the methods you switched on. A method that is unavailable for a particular buyer will not be offered to that buyer.

= Why can't I switch on Monero, Bitcoin, Litecoin, Solana, TRON or XRP? =

These coins need their own payout address, because your EVM address cannot receive them. Open the coin in the settings, paste a valid address for that network and save. One Solana address covers SOL, USDC and USDT on Solana, and one TRON address covers TRX and USDT on TRON. Payouts to an XRP address are sent to your own wallet only, with no destination tag.

= When and where do I receive my funds? =

In wallet-only mode every payment is sent on to your own wallet automatically. Card payouts usually arrive as USDC on Polygon; sometimes as another coin or stablecoin (for example USDT on BNB Chain, ETH or POL) on Polygon, Base, Ethereum or BNB Chain, always to this same address (your EVM address). Monero payouts are sent once about $10 has built up, and Bitcoin, Litecoin and Ethereum payouts once about $200 has built up. A payout can also be held for a review, and amounts below a network's payout minimum wait for the next payment. If a payout is waiting, the settings screen shows a notice. With a 1pay.cx account, payouts follow your account settings and balance. Timing depends on the payment method and network; the plugin does not guarantee instant payout.

= Can I change my wallet address or coins later? =

Yes. Change the address and save; the new EVM address is used for every EVM network from then on. A coin's payout address can be replaced but not removed: to stop accepting a coin, switch it off. Money still arriving in a coin you switched off is still sent to that coin's saved address.

= Does 1pay.cx email me? =

Not in wallet-only mode. Wallet-only stores have no email address on file, so a notice in WooCommerce shows when a payout is waiting. Create a free 1pay.cx account if you want email alerts.

= How do I get a 1pay.cx account later? =

Open the plugin settings and choose **Create free account**. You set an email and password on 1pay.cx, and your store becomes a regular account with its history, payout wallets and keys unchanged. To use an account you already have, choose **Connect** instead: future sales go to that account, and the earlier wallet-only history stays with the wallet-only store.

= I already use an API key. What changes in 3.0.0? =

Nothing. Existing API-key stores keep working unchanged and stay in account mode, with the same checkout, notifications and payouts as before. Only new installs start in wallet-only mode.

= What are the fees? =

The plugin has no purchase or subscription fee. Crypto payments cost 1% plus the network fee. Card payments cost 2.9% plus the card processor's 1–5% (about 4–8% all-in). Check the current pricing and the amount shown at checkout before paying.

= Why does checkout say there are no available payment methods? =

First check that WooCommerce and this plugin are active, the 1pay.cx gateway is enabled, and your settings were saved with a valid wallet address (or, in account mode, a verified API key). In wallet-only mode a store that has not been saved yet is not offered at checkout. Check your store's currency, the order amount and the methods you switched on. Individual card or wallet methods may have country, device or minimum-amount restrictions. Check the service-status page if the gateway was working previously.

= My customer paid, but the order is still pending. What should I check? =

Check the notes on the WooCommerce order. Your site must be reachable over HTTPS so 1pay.cx can deliver the signed payment notification. A firewall, maintenance page or security plugin must not block the WooCommerce callback URL. A crypto payment may still be waiting for network confirmations. If you need help, provide support with the WooCommerce order number and 1pay.cx payment ID; never send your API key.

= Do I need to register a webhook manually? =

No. The plugin sends the callback URL with each payment and retrieves the signing secret automatically when you save. Payment notifications are verified before the order is updated.

= Does this work with block checkout and HPOS? =

Yes. The plugin includes a payment method for WooCommerce's block-based checkout as well as classic checkout, and declares compatibility with High-Performance Order Storage.

= Can I test without moving money? =

Yes. Switch on **Test mode** in the gateway settings, place a test order and use the test checkout to simulate its outcome. Test payments do not move funds. Wallet-only stores receive a test key when they are registered. Stores that use an account need a test key from that account (Connect provides one, or paste a key that starts with pk_test_). Switch Test mode off when you are ready to accept real payments.

= Does the plugin support multiple currencies? =

The plugin sends your WooCommerce order's currency to 1pay.cx. Your currency must be supported by the payment service and available payment methods. In wallet-only mode the payout asset follows the wallets you saved; with an account it is configured in your 1pay.cx account.

= What does direct card checkout do? =

It sends the buyer directly to card checkout instead of the 1pay.cx payment selection page. WooCommerce supplies the order's billing email. Because the selection page is skipped, this option does not offer crypto payment methods. Leave it disabled if you want buyers to choose between cards and crypto.

= Can I issue refunds from WooCommerce? =

This gateway does not provide automatic card refunds through WooCommerce. Arrange refunds through your agreed manual or store-credit process and explain your refund policy to customers before purchase.

= Are any businesses restricted? =

Yes. Your use of the service must comply with the [Acceptable Use Policy](https://1pay.cx/acceptable-use) and [Terms of Service](https://1pay.cx/terms). Review those policies before connecting your store; installing the plugin does not override service restrictions. Saving the settings in wallet-only mode records your acceptance of them.

= How do I get support for an order or setup problem? =

Use the support chat at 1pay.cx or the WordPress.org support forum for this plugin. Include the plugin, WordPress and WooCommerce versions, your order number and the payment ID when applicable. Do not post customer information, API keys or webhook secrets in a public support topic.

== Changelog ==

= 3.0.1 =
* New: Telegram support links in the settings screen and on the Plugins list, for questions and help.
* Changed: a shorter, clearer plugin description.

= 3.0.0 =
* New: wallet-only mode, the default for new installs. Paste an EVM wallet address and start selling with no signup; every payment is sent on to your own wallets.
* New: choose payment methods and coins inside WooCommerce. Monero, Bitcoin, Litecoin, Solana, TRON and XRP are switched on with their own payout address.
* New: a redesigned settings screen with a test-mode switch, bundled icons and live address checks.
* New: start a free 1pay.cx account from the settings screen, keeping the store's history, or connect an existing account.
* New: a notice in WooCommerce when a payout is waiting to be sent.
* New: an External services section that lists what is sent to 1pay.cx and when.
* Existing API-key stores keep working unchanged and stay in account mode.
* New installs start with a new default checkout title and description.

= 2.0.0 =
* Makes the existing 1pay.cx Payments listing the WooCommerce payment gateway. WooCommerce is required; the earlier standalone page-button block and shortcode are not part of this release.
* Includes classic checkout, block checkout and HPOS compatibility.
* Adds setup instructions, service disclosures, troubleshooting, screenshots and a detailed FAQ for WooCommerce merchants.
* Uses the assigned 1pay-cx-payments translation domain and account contributor.
* Retains signed payment notifications, mode checks and independent API verification before an order is marked paid.

= 1.2.0 =
* WooCommerce gateway hardening: verifies the stored payment ID, order amount, currency and mode, and checks paid status through the API before updating an order.
* Verifies timestamped payment signatures and refreshes mode-specific secrets.

== Upgrade Notice ==

= 3.0.1 =
Adds Telegram support links. No settings change.

= 3.0.0 =
Existing API-key stores keep working unchanged. New installs start in wallet-only mode with no signup. The settings screen has been redesigned; review it after updating.

= 2.0.0 =
This listing now provides the WooCommerce gateway. Install and activate WooCommerce, then connect an API key in WooCommerce > Settings > Payments > 1pay.cx. The earlier standalone WordPress page-button block and shortcode are not included.
