# 1pay.cx for WooCommerce

Accept card and crypto payments in WooCommerce with [1pay.cx](https://1pay.cx), with no signup:
paste a wallet address and every payment is sent on to your own wallets. No business
verification is needed to start, subject to the
[Acceptable Use Policy](https://1pay.cx/acceptable-use).

- **Version 3.0.1** for WordPress 6.0+, WooCommerce 7.0+ and PHP 7.4+
- Classic and block checkout; HPOS compatible
- Orders complete automatically after a signed notification and a separate status check
- Test mode: test orders move no money
- No card data ever touches your server; all traffic goes to `api.1pay.cx` and `1pay.cx` only
- Licence: GPL-2.0-or-later (see `LICENSE`)

Also on WordPress.org: search for "High Risk Card & Crypto Payment Gateway with Instant Payouts".

## Install

1. Download the ready-made archive: https://1pay.cx/plugins/onepaycx-woocommerce.zip
   (or zip the `onepaycx-woocommerce` folder in this repository).
2. WordPress admin: **Plugins → Add New → Upload Plugin**, upload the zip, activate.
3. **WooCommerce → Settings → Payments → 1pay.cx**: enable the gateway, paste an EVM wallet
   address (starts with `0x`) from a wallet you control, choose your payment methods and coins,
   and save. Your store registers on the first save.
4. Switch on **Test mode**, place a test order, check it completes, then switch test mode off.

Never use an exchange deposit address: an exchange may not credit payouts on every network.

Already have a 1pay.cx account? Choose **My 1pay.cx account** in the settings and use
**Connect**, or paste an API key from **Settings → API keys** in your dashboard.

## Payouts

- **Card payments** usually arrive as USDC on Polygon, but can arrive as another coin or
  stablecoin (for example USDT on BNB Chain, ETH or POL) on Polygon, Base, Ethereum or BNB Chain,
  always to the same EVM address.
- **EVM coins** you accept go to that same address.
- **Other coins** (Monero, Bitcoin, Litecoin, Solana, TRON, XRP) each need their own payout
  address in the settings.

## Fees

The plugin is free. Crypto costs 1% plus the network fee. Cards cost 2.9% plus the card
processor's 1–5% (about 4–8% all-in). Details: https://1pay.cx/merchants#pricing

## How it works

1. The buyer picks 1pay.cx at checkout. The plugin creates a payment through the 1pay.cx API
   with the order total and the order id as the reference.
2. The buyer is redirected to the hosted checkout and pays by card or from any crypto wallet.
3. 1pay.cx calls the plugin's callback with a signed `payment.paid` event. The plugin verifies
   the signature, checks the payment's status with the API, then marks the order paid.

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
- Telegram: https://t.me/onepaycx_official, or the chat on any 1pay.cx page
