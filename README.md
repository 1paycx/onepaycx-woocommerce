# 1pay.cx for WooCommerce

Accept card, Bitcoin and Monero payments in WooCommerce with [1pay.cx](https://1pay.cx).
Buyers pay on a hosted, branded checkout; card sales settle to you in USDC on Polygon,
crypto lands on your 1pay.cx balance at a flat 1%. No KYB, no rolling reserves, no chargebacks.

- **Version 1.1.0** for WordPress 6.0+ and PHP 7.4+
- Adds "Pay by card or crypto" to both the classic and the block-based checkout
- Orders complete automatically through a signed webhook, whichever way the buyer paid
- No card data ever touches your server; all traffic goes to `api.1pay.cx` and `1pay.cx` only
- Licence: GPL-2.0-or-later (see `LICENSE`)

## Install

1. Download the ready-made archive: https://1pay.cx/plugins/onepaycx-woocommerce.zip
   (or zip the `onepaycx-woocommerce` folder in this repository).
2. WordPress admin: **Plugins → Add New → Upload Plugin**, upload the zip, activate.
3. **WooCommerce → Settings → Payments → 1pay.cx**: enable, paste your API key
   (1pay.cx dashboard → **Settings → API keys**, it looks like `pk_xxx.yyy`) and save.
   The plugin verifies the key and configures the webhook on its own.

That is the whole setup. Your checkout now offers "Pay by card or crypto".

## How it works

1. The buyer picks 1pay.cx at checkout. The plugin creates a payment through the 1pay.cx API
   with the order total and the order id as the reference.
2. The buyer is redirected to the hosted checkout and pays by card or from any crypto wallet.
3. 1pay.cx calls the plugin's callback with a signed `payment.paid` event. The signature is
   verified with your account's webhook secret before the order is marked paid.
4. The buyer returns to the order-received page. If the callback was delayed, the return trip
   checks the payment status too.

## Building the zip

```bash
zip -rq onepaycx-woocommerce.zip onepaycx-woocommerce -x '*.DS_Store' '*/.*'
```

Lint every PHP file with `php -l` before a release. Changes to the classes that extend
WooCommerce must be compile-tested against stub parents: a signature mismatch there is a
fatal error that white-screens a live store.

## Support

- Documentation and every integration: https://1pay.cx/docs
- Service status: https://1pay.cx/status
- Contact: `hello@1pay.cx` on Jabber/XMPP, or the chat on any 1pay.cx page
