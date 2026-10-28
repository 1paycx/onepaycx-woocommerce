<?php
/**
 * The settings panel shown inside WooCommerce > Settings > Payments > 1pay.cx.
 *
 * Pure rendering: the gateway gathers the saved settings and the live catalogue into a
 * context array (OnePayCx_Gateway::panel_context()) and this class turns it into markup.
 *
 * Every control is a real form field that posts under woocommerce_onepaycx_<field>, so the
 * page works as a plain form without JavaScript and saves through the gateway's normal
 * process_admin_options() path:
 *   - fiat_disabled[]    checkbox ticked = method switched OFF (the saved list is a blocklist,
 *                        so methods added later are on by default); shown as an on/off switch.
 *   - crypto_accepted[]  checkbox ticked = coin accepted.
 *   - payout_addresses[<asset id>]  one text field per coin that needs its own address.
 * Both lists always post an empty entry, so switching everything back on saves an empty
 * list instead of "field not posted, keep the old value".
 *
 * All text is translatable (text domain 1pay-cx-payments). All images ship with the plugin.
 */

if (!defined('ABSPATH')) {
    exit;
}

final class OnePayCx_Settings_UI {
    /** The eight methods shown first, in this order; the rest sit behind "Show N more". */
    const POPULAR = array('card', 'applepay', 'googlepay', 'cashapp', 'venmo', 'revolut', 'robinhood', 'coinbase');

    /** Method marks that ship as logo images (assets/icons/<id>.png). */
    const ICON_PNG = array('banxa', 'coinbase', 'rampnetwork', 'revolut', 'topper', 'transak');
    /** Method marks that ship as generated tiles (assets/icons/<id>.svg). Anything else gets a monogram. */
    const ICON_SVG = array(
        'applepay', 'binance', 'blockchaincom', 'card', 'cashapp', 'crossmint', 'googlepay', 'ideal', 'interac', 'klarna',
        'koywe', 'meld', 'mercuryo', 'moonpay', 'particle', 'paypal', 'ramp', 'robinhood', 'sardine',
        'simpleswap', 'simplex', 'stripe', 'transfi', 'upi', 'utorg', 'venmo', 'wert',
    );

    const FIAT_ID_RE   = '/^[a-zA-Z0-9_-]{1,40}$/';
    const CRYPTO_ID_RE = '/^[a-z0-9-]{1,40}$/';

    // ---------------------------------------------------------------------
    // Static layout: which networks and coins the panel knows how to show
    // ---------------------------------------------------------------------

    /** EVM networks: rows of coin pills, all paid to the one EVM address. [asset id, label, default on] */
    public static function networks() {
        return array(
            array('id' => 'polygon', 'name' => 'Polygon', 'icon' => 'pol', 'timing' => 'now', 'min' => 0,
                'coins' => array(array('usdc-polygon', 'USDC', true), array('usdt-polygon', 'USDT', true), array('pol', 'POL', false))),
            array('id' => 'base', 'name' => 'Base', 'icon' => 'base', 'timing' => 'now', 'min' => 0,
                'coins' => array(array('usdc-base', 'USDC', true), array('eth-base', 'ETH', false))),
            array('id' => 'arbitrum', 'name' => 'Arbitrum', 'icon' => 'arb', 'timing' => 'now', 'min' => 0,
                'coins' => array(array('usdc-arbitrum', 'USDC', false), array('usdt-arbitrum', 'USDT', false), array('eth-arbitrum', 'ETH', false))),
            array('id' => 'bsc', 'name' => 'BNB Chain', 'icon' => 'bnb', 'timing' => 'now', 'min' => 0,
                'coins' => array(array('usdt-bsc', 'USDT', true), array('usdc-bsc', 'USDC', false), array('bnb', 'BNB', false))),
            array('id' => 'ethereum', 'name' => 'Ethereum', 'icon' => 'eth', 'timing' => 'batch', 'min' => 50,
                'coins' => array(array('usdc-ethereum', 'USDC', false), array('usdt-ethereum', 'USDT', false), array('eth', 'ETH', false))),
        );
    }

    /**
     * Coins that need their own payout address. `key` is the payout_addresses key (an asset id the
     * server maps: sol covers sol/usdc-solana/usdt-solana, trx covers trx/usdt-tron); `subs` are the
     * token pills of a coin whose one address covers several assets. [asset id, label, default on]
     */
    public static function coins() {
        $base58 = '[1-9A-HJ-NP-Za-km-z]';
        return array(
            array('id' => 'xmr', 'key' => 'xmr', 'name' => 'Monero', 'tick' => 'XMR', 'icon' => 'xmr', 'timing' => 'xmr', 'ph' => '4… or 8…', 'subs' => array(),
                're' => '^([48][0-9AB]' . $base58 . '{93}|4[0-9AB]' . $base58 . '{104})$'),
            array('id' => 'btc', 'key' => 'btc', 'name' => 'Bitcoin', 'tick' => 'BTC', 'icon' => 'btc', 'timing' => 'batch', 'ph' => 'bc1…, 1… or 3…', 'subs' => array(),
                're' => '^(bc1[02-9ac-hj-np-z]{11,71}|[13]' . $base58 . '{25,34})$'),
            array('id' => 'ltc', 'key' => 'ltc', 'name' => 'Litecoin', 'tick' => 'LTC', 'icon' => 'ltc', 'timing' => 'batch', 'ph' => 'ltc1…, L… or M…', 'subs' => array(),
                're' => '^(ltc1[02-9ac-hj-np-z]{11,71}|[LM3]' . $base58 . '{26,33})$'),
            array('id' => 'sol', 'key' => 'sol', 'name' => 'Solana', 'tick' => 'SOL', 'icon' => 'sol', 'timing' => 'now', 'ph' => '', 'subs' => array(array('sol', 'SOL', true), array('usdc-solana', 'USDC', true), array('usdt-solana', 'USDT', false)),
                're' => '^' . $base58 . '{32,44}$'),
            array('id' => 'tron', 'key' => 'trx', 'name' => 'TRON', 'tick' => 'TRX', 'icon' => 'trx', 'timing' => 'now', 'ph' => 'T…', 'subs' => array(array('usdt-tron', 'USDT', true), array('trx', 'TRX', false)),
                're' => '^T' . $base58 . '{33}$'),
            array('id' => 'xrp', 'key' => 'xrp', 'name' => 'XRP', 'tick' => 'XRP', 'icon' => 'xrp', 'timing' => 'now', 'ph' => 'r…', 'subs' => array(),
                're' => '^r' . $base58 . '{24,34}$'),
        );
    }

    /** Asset ids a coin card controls: its sub-pills, or the coin itself. */
    private static function coin_assets(array $coin) {
        if ($coin['subs']) {
            $ids = array();
            foreach ($coin['subs'] as $sub) {
                $ids[] = $sub[0];
            }
            return $ids;
        }
        return array($coin['id']);
    }

    /** Does $address look right for $coin? Same patterns the script uses (they are passed to it as data-re). */
    public static function address_state(array $coin, $address) {
        $address = trim((string) $address);
        if ('' === $address) {
            return 'empty';
        }
        return 1 === preg_match('/' . $coin['re'] . '/D', $address) ? 'valid' : 'invalid';
    }

    // ---------------------------------------------------------------------
    // Small helpers
    // ---------------------------------------------------------------------

    private static function icon_url($file) {
        return plugins_url('assets/icons/' . $file, ONEPAYCX_PLUGIN_FILE);
    }

    /** Escaped translated text with %1$s-style slots filled by already-escaped HTML. */
    private static function fmt($translated, array $html_args) {
        return vsprintf(esc_html($translated), $html_args);
    }

    private static function dom_id($prefix, $id) {
        return $prefix . preg_replace('/[^a-zA-Z0-9_-]/', '-', (string) $id);
    }

    private static function checked($on) {
        return $on ? ' checked' : '';
    }

    private static function icon_svg($kind) {
        if ('ok' === $kind) {
            return '<svg width="14" height="14" viewBox="0 0 14 14" aria-hidden="true"><circle cx="7" cy="7" r="7" fill="#00a32a"/><path d="M4 7.2l2 2 4-4.4" fill="none" stroke="#fff" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"/></svg>';
        }
        if ('bad' === $kind) {
            return '<svg width="14" height="14" viewBox="0 0 14 14" aria-hidden="true"><circle cx="7" cy="7" r="7" fill="#d63638"/><path d="M4.5 4.5l5 5M9.5 4.5l-5 5" stroke="#fff" stroke-width="1.7" stroke-linecap="round"/></svg>';
        }
        if ('send' === $kind) {
            return '<svg width="14" height="14" viewBox="0 0 24 24" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 2 11 13"/><path d="M22 2 15 22 11 13 2 9z"/></svg>';
        }
        return '<svg width="14" height="14" viewBox="0 0 14 14" aria-hidden="true"><circle cx="7" cy="7" r="7" fill="#dba617"/><path d="M7 3.5v4.2" stroke="#fff" stroke-width="1.8" stroke-linecap="round"/><circle cx="7" cy="10.2" r="1" fill="#fff"/></svg>';
    }

    /** A link to the Telegram channel, opened in a new tab. */
    private static function support_link($url, $class, $inner_html) {
        return '<a class="' . esc_attr($class) . '" href="' . esc_url($url) . '" target="_blank" rel="noopener noreferrer">' . $inner_html . '</a>';
    }

    private static function msg($class, $icon, $text_html, $extra_class = '') {
        return '<div class="op-msg ' . esc_attr($class . ($extra_class ? ' ' . $extra_class : '')) . '">' . ('' === $icon ? '' : self::icon_svg($icon)) . '<span>' . $text_html . '</span></div>';
    }

    private static function timing_label($timing) {
        if ('xmr' === $timing) {
            return __('Sent once $10 builds up', '1pay-cx-payments');
        }
        if ('batch' === $timing) {
            return __('Sent once $200 builds up', '1pay-cx-payments');
        }
        return __('Sent right away', '1pay-cx-payments');
    }

    private static function bad_address_message($coin_id) {
        switch ($coin_id) {
            case 'xmr':
                return __('That isn’t a Monero address. Monero addresses start with 4 or 8 and are 95 characters long.', '1pay-cx-payments');
            case 'btc':
                return __('That isn’t a Bitcoin address. It should start with bc1, 1 or 3.', '1pay-cx-payments');
            case 'ltc':
                return __('That isn’t a Litecoin address. It should start with ltc1, L or M.', '1pay-cx-payments');
            case 'sol':
                return __('That isn’t a Solana address.', '1pay-cx-payments');
            case 'tron':
                return __('That isn’t a TRON address. It starts with T and is 34 characters long.', '1pay-cx-payments');
            default:
                return __('That isn’t an XRP address. It starts with r.', '1pay-cx-payments');
        }
    }

    /** 0x1234…abcd */
    public static function short_address($address) {
        $address = (string) $address;
        return substr($address, 0, 6) . '…' . substr($address, -4);
    }

    // ---------------------------------------------------------------------
    // Catalogue
    // ---------------------------------------------------------------------

    /** Valid fiat rows from the catalogue, popular eight first; null when the catalogue is unavailable. */
    private static function fiat_rows($catalogue) {
        if (!is_array($catalogue) || empty($catalogue['fiat']) || !is_array($catalogue['fiat'])) {
            return null;
        }
        $rows = array();
        foreach ($catalogue['fiat'] as $row) {
            if (is_array($row) && isset($row['id']) && is_string($row['id']) && preg_match(self::FIAT_ID_RE, $row['id']) && !isset($rows[$row['id']])) {
                $rows[$row['id']] = $row;
            }
        }
        $ordered = array();
        foreach (self::POPULAR as $id) {
            if (isset($rows[$id])) {
                $ordered[] = $rows[$id];
                unset($rows[$id]);
            }
        }
        return array_merge($ordered, array_values($rows));
    }

    /** id => row for the coins in the catalogue; null when it is unavailable (then every known coin is offered). */
    private static function crypto_index($catalogue) {
        if (!is_array($catalogue) || empty($catalogue['crypto']) || !is_array($catalogue['crypto'])) {
            return null;
        }
        $index = array();
        foreach ($catalogue['crypto'] as $row) {
            if (is_array($row) && isset($row['id']) && is_string($row['id']) && preg_match(self::CRYPTO_ID_RE, $row['id'])) {
                $index[$row['id']] = $row;
            }
        }
        return $index ? $index : null;
    }

    private static function method_name(array $row) {
        $id   = (string) $row['id'];
        $name = isset($row['name']) && is_string($row['name']) ? trim(sanitize_text_field($row['name'])) : '';
        if ('' === $name || 0 === strcasecmp($name, $id)) {
            return 'card' === $id ? __('Visa / Mastercard', '1pay-cx-payments') : ucfirst($id);
        }
        return function_exists('mb_substr') ? mb_substr($name, 0, 60) : substr($name, 0, 60);
    }

    /** "Worldwide", "US buyers", "SE, NO buyers", "31 countries", then "min $15" (from $5) and "buyer signs in". */
    private static function method_sub(array $row) {
        $parts = array();
        $codes = array();
        $countries = array_key_exists('countries', $row) ? $row['countries'] : null;
        if (is_array($countries)) {
            foreach ($countries as $code) {
                if (is_string($code) && preg_match('/^[A-Za-z]{2}$/', $code)) {
                    $codes[] = strtoupper($code);
                }
            }
        }
        if (null === $countries) {
            $parts[] = __('Worldwide', '1pay-cx-payments');
        } elseif (!$codes) {
            $parts[] = __('Selected countries', '1pay-cx-payments');
        } elseif (count($codes) <= 2) {
            /* translators: %s: country codes, for example "US" or "SE, NO" */
            $parts[] = sprintf(__('%s buyers', '1pay-cx-payments'), implode(', ', $codes));
        } else {
            /* translators: %d: number of countries */
            $parts[] = sprintf(_n('%d country', '%d countries', count($codes), '1pay-cx-payments'), count($codes));
        }
        $min = (isset($row['minAmountUsd']) && is_numeric($row['minAmountUsd'])) ? round((float) $row['minAmountUsd'], 2) : 0.0;
        if ($min >= 5) {
            /* translators: %s: minimum order in US dollars, for example "15" */
            $parts[] = sprintf(__('min $%s', '1pay-cx-payments'), floor($min) === $min ? (string) (int) $min : number_format($min, 2, '.', ''));
        }
        if (!empty($row['login'])) {
            $parts[] = __('buyer signs in', '1pay-cx-payments');
        }
        return implode(' · ', $parts);
    }

    private static function method_icon($id, $name) {
        if (in_array($id, self::ICON_PNG, true)) {
            return '<img class="op-ico img" src="' . esc_url(self::icon_url($id . '.png')) . '" alt="" width="32" height="32" loading="lazy">';
        }
        if (in_array($id, self::ICON_SVG, true)) {
            return '<img class="op-ico" src="' . esc_url(self::icon_url($id . '.svg')) . '" alt="" width="32" height="32" loading="lazy">';
        }
        $initial = strtoupper(substr((string) preg_replace('/[^A-Za-z0-9]/', '', $name), 0, 1));
        return '<span class="op-ico" style="background:#50575e" aria-hidden="true">' . esc_html('' === $initial ? '•' : $initial) . '</span>';
    }

    // ---------------------------------------------------------------------
    // Sections
    // ---------------------------------------------------------------------

    private static function header(array $c) {
        $p = $c['prefix'];
        $html  = '<div class="op-card op-head">';
        $html .= '<img class="op-mark" src="' . esc_url(self::icon_url('logo.svg')) . '" alt="" width="44" height="44">';
        $html .= '<div><div class="t">' . esc_html__('High Risk Card & Crypto Payment Gateway', '1pay-cx-payments') . '</div>';
        $html .= '<div class="s">' . esc_html__('by 1pay.cx · instant payouts to your own wallet', '1pay-cx-payments') . '</div></div>';
        $html .= '<div class="right">';
        $html .= self::support_link($c['urls']['support'], 'op-help', self::icon_svg('send') . esc_html__('Help on Telegram', '1pay-cx-payments'));
        $html .= '<span class="op-pillstat op-pill-wallet">' . esc_html__('Wallet only · no signup', '1pay-cx-payments') . '</span>';
        $html .= '<span class="op-pillstat op-pill-conn">' . esc_html__('Account · connected', '1pay-cx-payments') . '</span>';
        $html .= '<span class="op-pillstat op-pill-noconn">' . esc_html__('Account · not connected', '1pay-cx-payments') . '</span>';
        $html .= '<span class="op-hlabel" title="' . esc_attr__('Create test payments (no money moves)', '1pay-cx-payments') . '">' . esc_html__('Test mode', '1pay-cx-payments')
            . '<input type="checkbox" class="op-flag" id="op-test-mode" name="' . esc_attr($p . 'test_mode') . '" value="1"' . self::checked($c['test_mode']) . '>'
            . '<label class="op-sw" for="op-test-mode"><span class="op-sr">' . esc_html__('Test mode', '1pay-cx-payments') . '</span></label></span>';
        $html .= '<span class="op-hlabel">' . esc_html__('Enabled', '1pay-cx-payments')
            . '<input type="checkbox" class="op-flag" id="op-enabled" name="' . esc_attr($p . 'enabled') . '" value="1"' . self::checked($c['enabled']) . '>'
            . '<label class="op-sw" for="op-enabled"><span class="op-sr">' . esc_html__('Enabled', '1pay-cx-payments') . '</span></label></span>';
        $html .= '</div></div>';
        return $html;
    }

    private static function mode_cards(array $c) {
        $p = $c['prefix'];
        $wallet = 'wallet' === $c['mode'];
        $html  = '<div class="op-card"><h2>' . esc_html__('How do you want to get paid?', '1pay-cx-payments') . '</h2>';
        $html .= '<p class="lead">' . esc_html__('You can switch any time. Your settings below stay the same.', '1pay-cx-payments') . '</p><div class="op-modes">';
        $html .= '<label class="op-modewrap"><input type="radio" class="op-flag" name="' . esc_attr($p . 'mode') . '" value="wallet"' . self::checked($wallet) . '>'
            . '<span class="op-mode"><span class="dot"></span><span class="op-badge">' . esc_html__('No signup · Recommended', '1pay-cx-payments') . '</span>'
            . '<span class="mt" style="display:block">' . esc_html__('Just my wallet', '1pay-cx-payments') . '</span>'
            . '<span class="md" style="display:block">' . esc_html__('Paste a wallet address and start selling. Every payment is sent straight to your wallet. No account, no API keys.', '1pay-cx-payments') . '</span></span></label>';
        $html .= '<label class="op-modewrap"><input type="radio" class="op-flag" name="' . esc_attr($p . 'mode') . '" value="account"' . self::checked(!$wallet) . '>'
            . '<span class="op-mode"><span class="dot"></span><span class="op-badge grey">' . esc_html__('Free account', '1pay-cx-payments') . '</span>'
            . '<span class="mt" style="display:block">' . esc_html__('My 1pay.cx account', '1pay-cx-payments') . '</span>'
            . '<span class="md" style="display:block">' . esc_html__('Connect your account for swaps, statistics, balances, email alerts and the custom API.', '1pay-cx-payments') . '</span></span></label>';
        $html .= '</div></div>';
        return $html;
    }

    private static function wallet_card(array $c) {
        $p     = $c['prefix'];
        $state = '' === trim($c['evm']) ? 'empty' : (OnePayCx_Gateway::is_evm_address(trim($c['evm'])) ? 'valid' : 'invalid');
        $html  = '<div class="op-card op-modecard only-wallet" id="op-wallet-card" data-evm="' . esc_attr($state) . '">';
        $html .= '<h2>' . esc_html__('Your payout wallet', '1pay-cx-payments') . '</h2>';
        $html .= '<p class="lead">' . esc_html__('Card payments and EVM coins are paid out to this address.', '1pay-cx-payments') . '</p>';
        $html .= '<label class="fl" for="onepaycx-evm">' . esc_html__('EVM wallet address', '1pay-cx-payments') . '</label>';
        $html .= '<input class="op-in op-evm" id="onepaycx-evm" type="text" name="' . esc_attr($p . 'evm_address') . '" value="' . esc_attr($c['evm']) . '" placeholder="0x…" spellcheck="false" autocomplete="off" data-re="' . esc_attr('^0x(?!0{40}$)[0-9a-fA-F]{40}$') . '">';
        $html .= self::msg('warn', 'warn', esc_html__('Add your wallet address to start accepting payments.', '1pay-cx-payments'), 'm-empty');
        $html .= self::msg('ok', 'ok', esc_html__('Valid EVM address. The same address works on every network below.', '1pay-cx-payments'), 'm-valid');
        $html .= self::msg('bad', 'bad', esc_html__('That isn’t an EVM address. It should be 0x followed by 40 letters and numbers.', '1pay-cx-payments'), 'm-invalid');
        $html .= '<div class="op-chips m-valid">';
        foreach (array('Polygon', 'Base', 'Arbitrum', 'BNB Chain', 'Ethereum') as $network) {
            $html .= '<span class="op-chip">' . esc_html($network) . '</span>';
        }
        $html .= '</div>';
        $html .= '<div class="op-callout"><b>' . esc_html__('Use a wallet you control', '1pay-cx-payments') . '</b>'
            . esc_html__(', like MetaMask, Trust Wallet or Rabby, not an exchange deposit address. Card payouts usually arrive as USDC on Polygon; sometimes as another coin or stablecoin (for example USDT on BNB Chain, ETH or POL) on Polygon, Base, Ethereum or BNB Chain, always to this same address. Some exchanges won\'t credit those.', '1pay-cx-payments') . '</div>';
        $html .= '</div>';
        return $html;
    }

    private static function account_card(array $c) {
        $p    = $c['prefix'];
        $mark = '<img class="mk" src="' . esc_url(self::icon_url('logo.svg')) . '" alt="" width="22" height="22">';
        $html = '<div class="op-card op-modecard only-account" id="op-account-card"><h2>' . esc_html__('Your 1pay.cx account', '1pay-cx-payments') . '</h2>';

        // Not connected
        $html .= '<div class="op-notconn" id="op-notconnected">';
        $html .= '<p class="lead">' . esc_html__('Opens 1pay.cx. Sign in, or create a free account in the same step, and you\'ll come straight back here. No keys to copy.', '1pay-cx-payments') . '</p>';
        $html .= '<a class="op-btn-dark" id="op-connect-btn" href="' . esc_url($c['urls']['connect']) . '">' . $mark . esc_html__('Connect 1pay.cx account', '1pay-cx-payments') . '</a>';
        if (empty($c['api_key'])) {
            $html .= self::msg('warn', 'warn', esc_html__('Connect your 1pay.cx account or paste an API key to finish setting up.', '1pay-cx-payments'));
        }
        $html .= '</div>';

        // Connected
        $name    = (string) $c['account_name'];
        $initial = '' === $name ? '1' : strtoupper(function_exists('mb_substr') ? mb_substr($name, 0, 1) : substr($name, 0, 1));
        $html .= '<div class="op-connbox" id="op-connected"><div class="op-conn"><div class="avatar" aria-hidden="true">' . esc_html($initial) . '</div><div class="grow">';
        $html .= '<div class="n">' . ('' === $name ? esc_html__('Connected', '1pay-cx-payments') : esc_html(sprintf(
            /* translators: %s: the 1pay.cx account's business name */
            __('Connected: %s', '1pay-cx-payments'),
            $name
        ))) . '</div>';
        $html .= '<div class="d">' . ($c['key_is_test'] ? esc_html__('Test mode', '1pay-cx-payments') : esc_html__('Live mode', '1pay-cx-payments')) . ' · ' . esc_html__('new payments from this store go to your 1pay.cx account', '1pay-cx-payments') . '</div></div>';
        $html .= '<button type="button" class="op-linkish op-jsonly" id="op-disconnect">' . esc_html__('Disconnect', '1pay-cx-payments') . '</button></div>';
        $html .= '<div class="op-callout info">' . self::fmt(
            /* translators: %s: link to the 1pay.cx dashboard */
            __('Payouts, balances and settlement coins come from your %s. Choose below what buyers can pay with on this store.', '1pay-cx-payments'),
            array('<a href="' . esc_url($c['urls']['dashboard']) . '" target="_blank" rel="noopener">' . esc_html__('1pay.cx dashboard', '1pay-cx-payments') . '</a>')
        ) . '</div>';
        if (!empty($c['test_key_missing'])) {
            $html .= self::msg('warn', 'warn', esc_html__('Test mode is on, but this store has no 1pay.cx test key. Connect your account again, or paste a pk_test_ API key. Until then 1pay.cx is not offered at checkout.', '1pay-cx-payments'));
        }
        $html .= '</div>';

        // API key box and the secondary links
        $html .= '<div class="op-keyline"><button type="button" class="op-linkish op-jsonly" id="op-key-toggle"><span class="t-new">' . esc_html__('Paste an API key instead', '1pay-cx-payments') . '</span><span class="t-conn">' . esc_html__('Use a different API key', '1pay-cx-payments') . '</span></button>';
        if (!empty($c['registered']) && empty($c['connected'])) {
            $html .= '<span class="op-jsonly muted"> · </span><a class="op-linkish" id="op-claim-link" href="' . esc_url($c['urls']['claim']) . '">' . esc_html__('Create a free account for this store instead', '1pay-cx-payments') . '</a>';
        }
        $html .= '<div class="op-keybox is-collapsed" id="op-keybox"><label class="fl" for="onepaycx-api-key">' . esc_html__('API key', '1pay-cx-payments') . '</label>'
            . '<input class="op-in" id="onepaycx-api-key" type="password" name="' . esc_attr($p . 'api_key') . '" value="' . esc_attr($c['api_key']) . '" placeholder="' . esc_attr__('pk_… or pk_test_…', '1pay-cx-payments') . '" spellcheck="false" autocomplete="new-password">'
            . '<div class="op-msg muted"><span>' . esc_html__('Find it under Developers › API keys in your dashboard. Test keys (pk_test_) create test payments.', '1pay-cx-payments') . '</span></div></div>';
        $html .= '</div></div>';
        return $html;
    }

    private static function tile(array $row, $on, $locked, $extra, $blocked_title, $prefix) {
        $id    = (string) $row['id'];
        $name  = self::method_name($row);
        $input = self::dom_id('op-m-', $id);
        $html  = '<div class="op-tilebox op-blockable' . ($extra ? ' is-extra' : '') . '">';
        // Ticked = switched off. A method the 1pay.cx dashboard has switched off is shown off and not posted.
        $html .= '<input type="checkbox" class="op-flag" id="' . esc_attr($input) . '" name="' . esc_attr($prefix . 'fiat_disabled[]') . '" value="' . esc_attr($id) . '"'
            . self::checked(!$on) . ($locked ? ' disabled' : '') . ' aria-label="' . esc_attr(sprintf(
                /* translators: %s: payment method name */
                __('Switch off %s', '1pay-cx-payments'),
                $name
            )) . '">';
        $html .= '<div class="op-tile">' . self::method_icon($id, $name);
        $html .= '<div class="grow"><div class="nm">' . esc_html($name) . '</div><div class="sb">'
            . ($locked ? esc_html__('Switched off in your 1pay.cx dashboard', '1pay-cx-payments') : esc_html(self::method_sub($row))) . '</div></div>';
        $html .= '<label class="op-sw" for="' . esc_attr($input) . '"' . ('' !== $blocked_title ? ' title="' . esc_attr($blocked_title) . '"' : '') . '></label>';
        $html .= '</div></div>';
        return $html;
    }

    private static function methods_card(array $c, $evm_ok) {
        $p    = $c['prefix'];
        $rows = self::fiat_rows($c['catalogue']);
        $html = '<div class="op-card"><h2>' . esc_html__('Card and wallet payments', '1pay-cx-payments') . '</h2>';
        $html .= '<p class="lead"><span class="only-wallet">' . esc_html__('Buyers only see the options that work in their country and on their device. These pay out to your EVM wallet.', '1pay-cx-payments') . '</span>'
            . '<span class="only-account">' . esc_html__('Buyers only see the options that work in their country and on their device. Payouts follow your account settings.', '1pay-cx-payments') . '</span></p>';

        $disabled = array_flip($c['fiat_disabled']);
        $shown    = array();
        if (null === $rows) {
            $html .= self::msg('warn', 'warn', esc_html__('The list of payment methods could not be loaded. Reload this page in a moment. Your choices are kept.', '1pay-cx-payments'));
        } else {
            $locked_ids = array_flip($c['locked_fiat']);
            $blocked    = $c['wallet'] && !$evm_ok;
            $title      = $blocked ? __('Add your wallet first', '1pay-cx-payments') : '';
            $popular    = 0;
            $html .= '<div class="op-tiles" id="op-methods">';
            $on_count = 0;
            foreach ($rows as $row) {
                $id     = (string) $row['id'];
                $locked = isset($locked_ids[$id]);
                $on     = !$locked && !isset($disabled[$id]);
                $extra  = !in_array($id, self::POPULAR, true);
                $on_count += $on ? 1 : 0;
                $popular  += $extra ? 0 : 1;
                $shown[$id] = true;
                $html .= self::tile($row, $on, $locked, $extra, $title, $p);
            }
            $html .= '</div>';
            $extras = count($rows) - $popular;
            $html .= '<div class="op-more" id="op-more-line">';
            if ($extras > 0) {
                $html .= '<button type="button" class="op-linkish op-jsonly" id="op-more-btn" data-total="' . esc_attr((string) count($rows)) . '"><span class="t-more">' . esc_html(sprintf(
                    /* translators: %d: number of extra payment methods */
                    _n('Show %d more way to pay', 'Show %d more ways to pay', $extras, '1pay-cx-payments'),
                    $extras
                )) . '</span><span class="t-less">' . esc_html__('Show fewer', '1pay-cx-payments') . '</span></button><span class="muted op-jsonly"> · </span>';
            }
            $html .= '<span class="muted">' . self::fmt(
                /* translators: 1: number of methods switched on, 2: number of methods */
                __('%1$s of %2$s on', '1pay-cx-payments'),
                array('<span id="op-count-on">' . (int) $on_count . '</span>', (int) count($rows))
            ) . ' · ' . esc_html__('new methods we add are switched on automatically', '1pay-cx-payments') . '</span></div>';
        }
        // Blocked ids that are not listed (catalogue unavailable, or a method that went away) are kept as they are.
        $html .= '<input type="hidden" name="' . esc_attr($p . 'fiat_disabled[]') . '" value="">';
        foreach (array_keys($disabled) as $id) {
            if (!isset($shown[$id])) {
                $html .= '<input type="hidden" name="' . esc_attr($p . 'fiat_disabled[]') . '" value="' . esc_attr($id) . '">';
            }
        }
        $html .= '</div>';
        return $html;
    }

    /** The set of asset ids switched on, given what was saved (null = never narrowed) and the mode. */
    private static function accepted_set(array $c, $index) {
        if (is_array($c['crypto_accepted'])) {
            return array_flip($c['crypto_accepted']);
        }
        $on = array();
        if (!$c['wallet']) {
            // Existing and connected accounts: everything the platform offers is on until narrowed.
            $ids = null !== $index ? array_keys($index) : array();
            if (null === $index) {
                foreach (self::networks() as $n) {
                    foreach ($n['coins'] as $coin) {
                        $ids[] = $coin[0];
                    }
                }
                foreach (self::coins() as $coin) {
                    $ids = array_merge($ids, self::coin_assets($coin));
                }
            }
            foreach ($ids as $id) {
                $on[$id] = true;
            }
            return $on;
        }
        // A fresh wallet store: the stablecoins on the cheap networks. Coins that need their own address start off.
        foreach (self::networks() as $n) {
            foreach ($n['coins'] as $coin) {
                if ($coin[2]) {
                    $on[$coin[0]] = true;
                }
            }
        }
        return $on;
    }

    private static function pill($asset, $label, $icon, $on, $prefix, $blockable, $locked, $default_on = false) {
        $input = self::dom_id('op-c-', $asset);
        return '<label class="op-pillwrap' . ($blockable ? ' op-blockable' : '') . '">'
            . '<input type="checkbox" class="op-flag" id="' . esc_attr($input) . '" name="' . esc_attr($prefix . 'crypto_accepted[]') . '" value="' . esc_attr($asset) . '"'
            . self::checked($on && !$locked) . ($locked ? ' disabled' : '') . ($default_on ? ' data-default="1"' : '') . '>'
            . '<span class="op-pill"><img src="' . esc_url(self::icon_url($icon . '.svg')) . '" width="14" height="14" alt="">' . esc_html($label) . '</span></label>';
    }

    private static function coin_icon_for_label($label, $fallback) {
        $map = array('USDC' => 'usdc', 'USDT' => 'usdt', 'ETH' => 'eth', 'POL' => 'pol', 'BNB' => 'bnb');
        return isset($map[$label]) ? $map[$label] : $fallback;
    }

    private static function networks_html(array $c, $index, array $on, $evm_ok) {
        $locked = array_flip($c['locked_crypto']);
        $html   = '<div class="op-nets" id="op-nets">';
        foreach (self::networks() as $n) {
            $pills = '';
            $min   = 0;
            foreach ($n['coins'] as $coin) {
                if (null !== $index && !isset($index[$coin[0]])) {
                    continue; // not offered by the platform right now
                }
                if (null !== $index && isset($index[$coin[0]]['minPaymentUsd']) && is_numeric($index[$coin[0]]['minPaymentUsd'])) {
                    $min = max($min, (float) $index[$coin[0]]['minPaymentUsd']);
                }
                $pills .= self::pill($coin[0], $coin[1], self::coin_icon_for_label($coin[1], $n['icon']), isset($on[$coin[0]]), $c['prefix'], true, isset($locked[$coin[0]]));
            }
            if ('' === $pills) {
                continue;
            }
            if (null === $index) {
                $min = $n['min']; // catalogue unavailable: the known floor
            }
            $meta = '';
            if ($min > 0) {
                /* translators: %s: smallest order in US dollars, for example "50" */
                $meta .= '<span class="op-chip">' . esc_html(sprintf(__('Min order $%s', '1pay-cx-payments'), floor($min) === (float) $min ? (string) (int) $min : number_format($min, 2, '.', ''))) . '</span>';
            }
            $meta .= '<span class="op-chip ' . ('batch' === $n['timing'] ? 'batch' : 'fast') . '">' . esc_html(self::timing_label('batch' === $n['timing'] ? 'batch' : 'now')) . '</span>';
            $html .= '<div class="op-net"><div class="nn"><img src="' . esc_url(self::icon_url($n['icon'] . '.svg')) . '" width="20" height="20" alt="">' . esc_html($n['name']) . '</div>'
                . '<div class="op-pills">' . $pills . '</div><div class="meta">' . $meta . '</div></div>';
        }
        return $html . '</div>';
    }

    private static function coins_html(array $c, $index, array $on) {
        $p      = $c['prefix'];
        $locked = array_flip($c['locked_crypto']);
        $html   = '<div class="op-coins" id="op-coins">';
        foreach (self::coins() as $coin) {
            $assets = array();
            foreach (self::coin_assets($coin) as $asset) {
                if (null === $index || isset($index[$asset])) {
                    $assets[] = $asset;
                }
            }
            if (!$assets) {
                continue;
            }
            $address = isset($c['addresses'][$coin['key']]) ? (string) $c['addresses'][$coin['key']] : '';
            $state   = self::address_state($coin, $address);
            $any_on  = false;
            foreach ($assets as $asset) {
                $any_on = $any_on || isset($on[$asset]);
            }
            $is_locked = true;
            foreach ($assets as $asset) {
                $is_locked = $is_locked && isset($locked[$asset]);
            }
            $has_subs = (bool) $coin['subs'];
            $input    = self::dom_id('op-coin-', $coin['id']);

            $html .= '<div class="op-coinbox">';
            // One real checkbox per coin: the coin itself, or (Solana, TRON) an unnamed master switch for the token pills.
            if ($has_subs) {
                $html .= '<input type="checkbox" class="op-flag op-master" id="' . esc_attr($input) . '"' . self::checked($any_on && !$is_locked) . ($is_locked ? ' disabled' : '') . '>';
            } else {
                $html .= '<input type="checkbox" class="op-flag" id="' . esc_attr($input) . '" name="' . esc_attr($p . 'crypto_accepted[]') . '" value="' . esc_attr($coin['id']) . '"' . self::checked($any_on && !$is_locked) . ($is_locked ? ' disabled' : '') . '>';
            }
            // data-saved: 1pay.cx holds an address for this coin. It can be replaced but never removed, so a blank field
            // is not "no address" (see the script's "kept" state).
            $html .= '<div class="op-coin" data-coin="' . esc_attr($coin['id']) . '" data-addr="' . esc_attr($state) . '" data-saved="' . ('' === trim($address) ? '0' : '1') . '">';
            $html .= '<div class="row"><img class="op-ico round" src="' . esc_url(self::icon_url($coin['icon'] . '.svg')) . '" width="32" height="32" alt="' . esc_attr($coin['tick']) . '">';
            $html .= '<div class="grow"><div class="nm">' . esc_html($coin['name']);
            if ($has_subs) {
                $ticks = array();
                foreach ($coin['subs'] as $sub) {
                    $ticks[] = $sub[1];
                }
                $html .= ' <span class="muted" style="font-weight:400">(' . esc_html(implode(', ', $ticks)) . ')</span>';
            }
            $html .= '</div><div class="sb">' . esc_html(self::timing_label($coin['timing'])) . '</div></div>';
            $html .= '<span class="state ok st-ok">' . esc_html__('Accepted', '1pay-cx-payments') . '</span>'
                . '<span class="state need st-empty">' . esc_html__('Needs an address', '1pay-cx-payments') . '</span>'
                . '<span class="state need st-invalid">' . esc_html__('Check the address', '1pay-cx-payments') . '</span>';
            $html .= '<label class="op-sw' . ($has_subs ? ' op-jsonly' : '') . '" for="' . esc_attr($input) . '"><span class="op-sr">' . esc_html(sprintf(
                /* translators: %s: coin name */
                __('Accept %s', '1pay-cx-payments'),
                $coin['name']
            )) . '</span></label></div>';

            $html .= '<div class="body">';
            if ($has_subs) {
                $html .= '<div class="op-subp"><span class="lbl">' . esc_html__('Accept:', '1pay-cx-payments') . '</span>';
                foreach ($coin['subs'] as $sub) {
                    if (!in_array($sub[0], $assets, true)) {
                        continue;
                    }
                    $html .= self::pill($sub[0], $sub[1], self::coin_icon_for_label($sub[1], $coin['icon']), isset($on[$sub[0]]), $p, false, isset($locked[$sub[0]]), $sub[2]);
                }
                $html .= '</div>';
            }
            // Wallet mode: the address for this coin.
            $field = self::dom_id('onepaycx-addr-', $coin['id']);
            $html .= '<div class="only-wallet op-addr">';
            $html .= '<label class="fl" for="' . esc_attr($field) . '">' . esc_html(sprintf(
                /* translators: %s: coin name, for example Bitcoin */
                __('Your %s address', '1pay-cx-payments'),
                $coin['name']
            )) . '</label>';
            $placeholder = 'sol' === $coin['id'] ? __('Your Solana address', '1pay-cx-payments') : $coin['ph'];
            $html .= '<input class="op-in" type="text" id="' . esc_attr($field) . '" name="' . esc_attr($p . 'payout_addresses[' . $coin['key'] . ']') . '" value="' . esc_attr($address) . '" placeholder="' . esc_attr($placeholder) . '" spellcheck="false" autocomplete="off" data-re="' . esc_attr($coin['re']) . '">';
            $html .= self::msg('warn', 'warn', esc_html(sprintf(
                /* translators: 1: coin ticker, 2: coin name */
                __('Buyers can’t pay in %1$s until you add your %2$s address.', '1pay-cx-payments'),
                $coin['tick'],
                $coin['name']
            )), 'a-empty');
            $html .= self::msg('ok', 'ok', esc_html(sprintf(
                /* translators: 1: coin name, 2: when payouts are sent, for example "Sent right away" */
                __('Valid %1$s address. %2$s.', '1pay-cx-payments'),
                $coin['name'],
                self::timing_label($coin['timing'])
            )), 'a-valid');
            $html .= self::msg('bad', 'bad', esc_html(self::bad_address_message($coin['id'])), 'a-invalid');
            if ('' !== trim($address)) {
                $html .= self::msg('muted', '', esc_html(sprintf(
                    /* translators: %s: coin name, for example Bitcoin */
                    __('To stop accepting %s, switch it off. Addresses can be replaced, not removed.', '1pay-cx-payments'),
                    $coin['name']
                )), 'a-saved');
            }
            if ('xrp' === $coin['id']) {
                $html .= self::msg('muted', '', esc_html__('Use your own XRP wallet. Exchange addresses that need a destination tag won’t work.', '1pay-cx-payments'));
            }
            $html .= '</div>';
            // Account mode: payouts follow the dashboard.
            $html .= '<div class="only-account op-msg muted"><span>' . esc_html(sprintf(
                /* translators: %s: coin name */
                __('Paid to your 1pay.cx balance, or to your %s payout wallet if you set one in the dashboard.', '1pay-cx-payments'),
                $coin['name']
            )) . '</span></div>';
            $html .= '</div></div></div>';
        }
        return $html . '</div>';
    }

    private static function crypto_card(array $c, $evm_ok) {
        $p     = $c['prefix'];
        $index = self::crypto_index($c['catalogue']);
        $on    = self::accepted_set($c, $index);

        $html  = '<div class="op-card"><h2>' . esc_html__('Crypto payments', '1pay-cx-payments') . '</h2>';
        $html .= '<p class="lead"><span class="only-wallet">' . esc_html__('Buyers pay from their own crypto wallet. Each payment is sent on to you automatically.', '1pay-cx-payments') . '</span>'
            . '<span class="only-account">' . esc_html__('Buyers pay from their own crypto wallet. Payments go to your 1pay.cx balance or your dashboard payout wallets.', '1pay-cx-payments') . '</span></p>';

        $html .= '<div class="op-sect">' . esc_html__('Paid to your EVM wallet', '1pay-cx-payments') . ' '
            . '<span class="op-chip only-account">' . esc_html__('follows your account settings', '1pay-cx-payments') . '</span>'
            . '<span class="op-chip chip-missing only-wallet">' . esc_html__('add your wallet above first', '1pay-cx-payments') . '</span>'
            . '<span class="op-chip addr chip-addr only-wallet" id="op-evm-chip-addr">' . self::fmt(
                /* translators: %s: the owner's shortened wallet address */
                __('to %s', '1pay-cx-payments'),
                array('<span id="op-evm-short">' . esc_html($evm_ok ? self::short_address(trim($c['evm'])) : '') . '</span>')
            ) . '</span></div>';
        $html .= self::networks_html($c, $index, $on, $evm_ok);

        $html .= '<div class="op-sect" style="margin-top:20px">' . esc_html__('Needs its own wallet address', '1pay-cx-payments') . ' '
            . '<span class="op-chip">' . esc_html__('turn a coin on, then paste your address for it', '1pay-cx-payments') . '</span></div>';
        $html .= self::coins_html($c, $index, $on);

        // Always post the list (an empty entry among them), and keep coins this page does not list.
        $html .= '<input type="hidden" name="' . esc_attr($p . 'crypto_accepted[]') . '" value="">';
        if (is_array($c['crypto_accepted'])) {
            $known = array();
            foreach (self::networks() as $n) {
                foreach ($n['coins'] as $coin) {
                    $known[$coin[0]] = true;
                }
            }
            foreach (self::coins() as $coin) {
                foreach (self::coin_assets($coin) as $asset) {
                    $known[$asset] = true;
                }
            }
            foreach ($c['crypto_accepted'] as $asset) {
                if (!isset($known[$asset]) || (null !== $index && !isset($index[$asset]))) {
                    $html .= '<input type="hidden" name="' . esc_attr($p . 'crypto_accepted[]') . '" value="' . esc_attr($asset) . '">';
                }
            }
        }
        $html .= '</div>';
        return $html;
    }

    private static function checkout_card(array $c) {
        $p = $c['prefix'];
        $html  = '<div class="op-card"><h2>' . esc_html__('What buyers see at checkout', '1pay-cx-payments') . '</h2><div class="op-row2">';
        $html .= '<div><label class="fl" for="onepaycx-title">' . esc_html__('Title', '1pay-cx-payments') . '</label>'
            . '<input class="op-in txt" id="onepaycx-title" type="text" name="' . esc_attr($p . 'title') . '" value="' . esc_attr($c['title']) . '"></div>';
        $html .= '<div><label class="fl" for="onepaycx-description">' . esc_html__('Description', '1pay-cx-payments') . '</label>'
            . '<textarea class="op-in txt" id="onepaycx-description" name="' . esc_attr($p . 'description') . '">' . esc_textarea($c['description']) . '</textarea></div>';
        $html .= '</div><label class="op-check"><input type="checkbox" name="' . esc_attr($p . 'direct_checkout') . '" value="1"' . self::checked($c['direct']) . '> <span>'
            . esc_html__('Skip the card-or-crypto choice and open card checkout straight away', '1pay-cx-payments') . '</span></label></div>';
        return $html;
    }

    private static function cta(array $c) {
        $html  = '<div class="op-cta only-wallet" id="op-cta"><div><div class="ct">' . esc_html__('Want swaps, statistics or a custom API?', '1pay-cx-payments') . '</div>';
        $html .= '<div class="cs">' . esc_html__('Create a free 1pay.cx account. This store\'s sales history comes with you, and your settings stay as they are.', '1pay-cx-payments') . '</div></div>';
        if (!empty($c['registered'])) {
            $html .= '<a class="op-btn-lime" id="op-claim-cta" href="' . esc_url($c['urls']['claim']) . '">' . esc_html__('Create free account', '1pay-cx-payments') . '</a>';
        } else {
            $html .= '<span class="op-btn-lime is-disabled" id="op-claim-cta" title="' . esc_attr__('Save your wallet address first', '1pay-cx-payments') . '">' . esc_html__('Create free account', '1pay-cx-payments') . '</span>';
        }
        return $html . '</div>';
    }

    /** Where to ask: wallet-only stores have no email or dashboard, so this is their way to reach us. */
    private static function support(array $c) {
        $html  = '<div class="op-support" id="op-support"><span class="op-support-icon">' . self::icon_svg('send') . '</span>';
        $html .= '<div><div class="ct">' . esc_html__('Questions or stuck?', '1pay-cx-payments') . '</div>';
        $html .= '<div class="cs">' . esc_html__('Ask the 1pay.cx team in our Telegram channel.', '1pay-cx-payments') . '</div></div>';
        return $html . self::support_link($c['urls']['support'], 'op-btn-outline', esc_html__('Open Telegram', '1pay-cx-payments')) . '</div>';
    }

    // ---------------------------------------------------------------------
    // The whole panel
    // ---------------------------------------------------------------------

    /** The panel markup for $c (see OnePayCx_Gateway::panel_context()). */
    public static function render(array $c) {
        $evm    = trim((string) $c['evm']);
        $evm_ok = OnePayCx_Gateway::is_evm_address($evm);

        $classes = array('onepaycx-panel', $c['wallet'] ? 'is-wallet' : 'is-account');
        if ($c['connected']) {
            $classes[] = 'is-connected';
        }
        if (!$evm_ok) {
            $classes[] = 'evm-missing';
        }

        $html  = '<h2 class="screen-reader-text">' . esc_html__('1pay.cx', '1pay-cx-payments') . '</h2>';
        $html .= '<div id="onepaycx-panel" class="' . esc_attr(implode(' ', $classes)) . '"'
            . ' data-unset-crypto="' . (null === $c['crypto_accepted'] ? '1' : '0') . '"'
            . ' data-blocked-title="' . esc_attr__('Add your wallet first', '1pay-cx-payments') . '">';
        $html .= self::header($c);
        $html .= self::mode_cards($c);
        $html .= self::wallet_card($c);
        $html .= self::account_card($c);
        $html .= self::methods_card($c, $evm_ok);
        $html .= self::crypto_card($c, $evm_ok);
        $html .= self::checkout_card($c);
        $html .= self::cta($c);
        $html .= self::support($c);
        $html .= '<div class="op-fees">' . esc_html__('Fees: cards 2.9% plus the card processor\'s 1–5% (about 4–8% all-in). Crypto 1% plus the network fee.', '1pay-cx-payments') . '</div>';
        $html .= '<div class="op-savebar"><button type="submit" name="save" class="button-primary woocommerce-save-button" value="' . esc_attr__('Save changes', '1pay-cx-payments') . '">' . esc_html__('Save changes', '1pay-cx-payments') . '</button></div>';
        $html .= '<p class="op-terms only-wallet">' . self::fmt(
            /* translators: 1: link to the Terms, 2: link to the Acceptable Use Policy */
            __('By saving you agree to the %1$s and the %2$s.', '1pay-cx-payments'),
            array(
                '<a href="' . esc_url($c['urls']['terms']) . '" target="_blank" rel="noopener">' . esc_html__('Terms', '1pay-cx-payments') . '</a>',
                '<a href="' . esc_url($c['urls']['aup']) . '" target="_blank" rel="noopener">' . esc_html__('Acceptable Use Policy', '1pay-cx-payments') . '</a>',
            )
        ) . '</p>';
        $html .= '</div>';
        return $html;
    }
}
