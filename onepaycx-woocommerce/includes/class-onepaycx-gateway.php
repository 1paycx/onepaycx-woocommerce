<?php
/**
 * 1pay.cx WooCommerce payment gateway.
 *
 * Flow: checkout -> create payment on 1pay.cx -> redirect buyer to the hosted
 * checkout -> buyer pays by card -> 1pay.cx POSTs a signed notify to our
 * wc-api endpoint -> we complete the order. The notify is the source of truth.
 *
 * Two modes:
 * - wallet (default for new installs): the owner pastes an EVM address; the first
 *   save registers the store with 1pay.cx, which returns a live and a test key that
 *   are kept in WordPress options and never shown. Payout addresses, methods and
 *   coins are synced to 1pay.cx on every save.
 * - account (existing installs that already have an API key, or a store connected
 *   to a 1pay.cx account): the API key in `api_key` is used as before.
 * Payments, notifications and status checks are the same code path in both modes.
 */

if (!defined('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/class-onepaycx-evm.php';

class OnePayCx_Gateway extends WC_Payment_Gateway {
    /** @var string The merchant's 1pay.cx API key (account mode). Declared to avoid a PHP 8.2 dynamic-property notice. */
    public $api_key = '';

    /** @var bool Send buyers straight to the card processor (skip the 1pay.cx page). */
    public $direct = false;

    /** Largest clock skew accepted on a timestamped (V2) signature, in seconds. */
    const SIGNATURE_TOLERANCE = 300;

    /** Gateway settings option (WooCommerce stores every form field here). */
    const OPTION_KEY = 'woocommerce_onepaycx_settings';
    /** This gateway's settings screen, relative to wp-admin. */
    const SETTINGS_PATH = 'admin.php?page=wc-settings&tab=checkout&section=onepaycx';
    /** Return URL for https://1pay.cx/connect, which appends code=<code>&state=<state>. */
    const CONNECT_RETURN_PATH = 'admin.php?page=wc-settings&tab=checkout&section=onepaycx&onepaycx_connect=1';

    /** One-time state for the connect round trip to 1pay.cx. */
    const STATE_TRANSIENT = 'onepaycx_connect_state';
    const STATE_TTL       = 1800;
    /** Cached GET /v1/me facts for the settings screen: payouts waiting, wallet-only. */
    const STATUS_TRANSIENT = 'onepaycx_me_status';
    const STATUS_TTL       = 300;
    /** Cached GET /v1/methods for account-mode payments. */
    const METHODS_TRANSIENT = 'onepaycx_account_methods';
    const METHODS_TTL       = 600;
    /** Per-user notice carried across the redirect after connect (suffixed with the user id). */
    const NOTICE_TRANSIENT = 'onepaycx_notice_';

    const EVM_RE       = '/^0x[0-9a-fA-F]{40}$/';
    const LIVE_KEY_RE  = '/^pk_[0-9a-f]{8}\.[0-9a-f]{48}$/';
    const TEST_KEY_RE  = '/^pk_test_[0-9a-f]{8}\.[0-9a-f]{48}$/';
    const FIAT_ID_RE   = '/^[a-zA-Z0-9_-]{1,40}$/';
    const CRYPTO_ID_RE = '/^[a-z0-9-]{1,40}$/';

    public function __construct() {
        $this->id                 = 'onepaycx';
        $this->method_title       = '1pay.cx';
        $this->method_description  = __('Accept card and crypto payments with 1pay.cx. Buyers pay on a hosted checkout. Paste your EVM wallet address to start selling with no signup, or connect your 1pay.cx account.', '1pay-cx-payments');
        $this->has_fields         = false;
        $this->icon               = '';
        // No 'refunds' — card refunds aren't supported by this model (see notice below).
        $this->supports           = array('products');

        $this->init_form_fields();
        $this->init_settings();

        $this->enabled     = $this->get_option('enabled');
        $this->title       = $this->get_option('title');
        $this->description = $this->get_option('description');
        $this->api_key     = $this->get_option('api_key');
        $this->direct      = 'yes' === $this->get_option('direct_checkout');

        add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));
        // Settlement-layer notify lands here: https://<store>/wc-api/onepaycx
        add_action('woocommerce_api_onepaycx', array($this, 'handle_webhook'));
    }

    public function init_form_fields() {
        $this->form_fields = array(
            'enabled' => array(
                'title'   => __('Enable/Disable', '1pay-cx-payments'),
                'type'    => 'checkbox',
                'label'   => __('Enable 1pay.cx payments', '1pay-cx-payments'),
                'default' => 'no',
            ),
            'mode' => array(
                'title'       => __('Mode', '1pay-cx-payments'),
                'type'        => 'select',
                'options'     => array(
                    'wallet'  => __('Just my wallet (no signup)', '1pay-cx-payments'),
                    'account' => __('My 1pay.cx account', '1pay-cx-payments'),
                ),
                // Existing installs that already have an API key stay in account mode.
                'default'     => $this->default_mode(),
                'description' => __('Just my wallet: every payment is sent on to the wallet addresses below. My 1pay.cx account: payouts follow your 1pay.cx dashboard.', '1pay-cx-payments'),
            ),
            'evm_address' => array(
                'title'       => __('EVM wallet address', '1pay-cx-payments'),
                'type'        => 'text',
                'default'     => '',
                'placeholder' => '0x…',
                'description' => __('Wallet mode. 0x followed by 40 letters and numbers; the same address is used on every EVM network. Use a wallet you control, not an exchange deposit address.', '1pay-cx-payments'),
            ),
            'title' => array(
                'title'       => __('Title', '1pay-cx-payments'),
                'type'        => 'text',
                'description' => __('What buyers see at checkout.', '1pay-cx-payments'),
                'default'     => __('Pay with card or crypto', '1pay-cx-payments'),
                'desc_tip'    => true,
            ),
            'description' => array(
                'title'   => __('Description', '1pay-cx-payments'),
                'type'    => 'textarea',
                'default' => __('Visa, Mastercard, Apple Pay, Google Pay, Cash App, Venmo or crypto.', '1pay-cx-payments'),
            ),
            'api_key' => array(
                'title'       => __('API key', '1pay-cx-payments'),
                'type'        => 'password',
                'description' => __('Account mode. From your 1pay.cx dashboard: Settings -> API keys. Looks like pk_xxx.yyy', '1pay-cx-payments'),
                'default'     => '',
                'desc_tip'    => true,
            ),
            'direct_checkout' => array(
                'title'       => __('Direct to card processor', '1pay-cx-payments'),
                'type'        => 'checkbox',
                'label'       => __('Skip the 1pay.cx payment page — buyers go straight to the card processor', '1pay-cx-payments'),
                'description' => __('Requires the buyer\'s email, which WooCommerce always collects at checkout (the billing email is passed automatically). If an order has no email, the buyer sees the standard payment page instead. Note: direct mode skips the 1pay.cx page, so buyers will not be offered Bitcoin or Monero.', '1pay-cx-payments'),
                'default'     => 'no',
            ),
            'payout_addresses' => array(
                'title'       => __('Other coin addresses', '1pay-cx-payments'),
                'type'        => 'textarea',
                'default'     => '',
                'description' => __('Wallet mode. Your address per coin as JSON, for example {"xmr": "4…", "btc": "bc1…"}.', '1pay-cx-payments'),
            ),
            'fiat_disabled' => array(
                'title'       => __('Card and wallet methods switched off', '1pay-cx-payments'),
                'type'        => 'textarea',
                'default'     => '',
                'description' => __('Method ids as a JSON list. Methods added later are switched on automatically.', '1pay-cx-payments'),
            ),
            'crypto_accepted' => array(
                'title'       => __('Coins accepted', '1pay-cx-payments'),
                'type'        => 'textarea',
                'default'     => '',
                'description' => __('Coin ids as a JSON list. Leave empty to accept every coin available to this store.', '1pay-cx-payments'),
            ),
            'test_mode' => array(
                'title'       => __('Test mode', '1pay-cx-payments'),
                'type'        => 'checkbox',
                'label'       => __('Create test payments (no money moves)', '1pay-cx-payments'),
                'description' => __('Wallet mode uses this store\'s test key. Account mode uses your account\'s test key, which Connect stores for you, or a pasted pk_test_ key; without one, 1pay.cx is not offered at checkout while test mode is on.', '1pay-cx-payments'),
                'default'     => 'no',
            ),
        );
    }

    // ---------------------------------------------------------------------
    // Modes and keys
    // ---------------------------------------------------------------------

    /**
     * The mode a settings array means: an explicit `mode`, else `account` when an API key
     * is already set (stores from before wallet mode existed), else `wallet`.
     */
    public static function resolve_mode($settings) {
        $settings = is_array($settings) ? $settings : array();
        $mode = isset($settings['mode']) ? (string) $settings['mode'] : '';
        if ('wallet' === $mode || 'account' === $mode) {
            return $mode;
        }
        return (isset($settings['api_key']) && '' !== trim((string) $settings['api_key'])) ? 'account' : 'wallet';
    }

    /** `wallet` or `account`. */
    public function get_mode() {
        return self::resolve_mode($this->settings);
    }

    /** Form default for `mode`, from what is stored (WooCommerce fills unset fields with it). */
    private function default_mode() {
        return self::resolve_mode(self::stored_settings());
    }

    /**
     * The key payments and notifications use: the wallet store's live or test key (by
     * `test_mode`) in wallet mode, and `api_key` in account mode. Empty when not set up.
     *
     * Account mode and `test_mode`: a live `api_key` is swapped for the account's test key
     * (`onepaycx_account_test_key`, stored by the connect flow) and, without a usable one,
     * the key is empty, so the gateway is unavailable rather than taking live payments. A
     * pasted pk_test_ key is a test key by its prefix and is used as it is.
     */
    public function get_active_key() {
        return self::resolve_active_key(is_array($this->settings) ? $this->settings : array());
    }

    /**
     * get_active_key() for a settings array, so the static connect and adopt paths pick the
     * same key as the gateway. Unset fields mean their form defaults (`test_mode` 'no',
     * `api_key` '').
     */
    private static function resolve_active_key(array $settings) {
        $test_mode = isset($settings['test_mode']) && 'yes' === $settings['test_mode'];
        if ('wallet' === self::resolve_mode($settings)) {
            return (string) get_option($test_mode ? 'onepaycx_wallet_test_key' : 'onepaycx_wallet_live_key', '');
        }
        $key = isset($settings['api_key']) ? trim((string) $settings['api_key']) : '';
        if ($test_mode && 1 === preg_match(self::LIVE_KEY_RE, $key)) {
            $test = trim((string) get_option('onepaycx_account_test_key', ''));
            return 1 === preg_match(self::TEST_KEY_RE, $test) ? $test : '';
        }
        return $key;
    }

    /** Not offered at checkout until the store has a key to create payments with. */
    public function is_available() {
        return parent::is_available() && '' !== $this->get_active_key();
    }

    /** True for a pk_test_ key: such a store may only ever accept test payments. */
    private function is_test_key() {
        return strpos($this->get_active_key(), 'pk_test_') === 0;
    }

    /** A usable EVM payout address: 0x plus 40 hex digits, not the zero address, with a valid EIP-55 checksum if mixed case. */
    public static function is_evm_address($value) {
        return is_string($value) && 1 === preg_match(self::EVM_RE, $value) && '' === OnePayCx_Evm::problem($value);
    }

    private static function stored_settings() {
        $settings = get_option(self::OPTION_KEY, array());
        return is_array($settings) ? $settings : array();
    }

    // ---------------------------------------------------------------------
    // Field sanitizers (WooCommerce calls validate_<key>_field on save)
    // ---------------------------------------------------------------------

    public function validate_mode_field($key, $value) {
        $value = is_string($value) ? sanitize_text_field(wp_unslash($value)) : '';
        return in_array($value, array('wallet', 'account'), true) ? $value : $this->get_mode();
    }

    public function validate_evm_address_field($key, $value) {
        if (is_null($value)) {
            return (string) $this->get_option($key);
        }
        return is_string($value) ? (string) preg_replace('/\s+/', '', sanitize_text_field(wp_unslash($value))) : '';
    }

    /**
     * The API key as WooCommerce's password field would clean it, except that a form that
     * leaves it out keeps the saved key. A different key is a different account, so the test
     * key the connect flow stored for the old one (see get_active_key()) is dropped with it.
     */
    public function validate_api_key_field($key, $value) {
        $current = trim((string) $this->get_option($key));
        if (!is_string($value)) {
            return $current;
        }
        $new = trim(wp_unslash($value));
        if ($new !== $current) {
            delete_option('onepaycx_account_test_key');
        }
        return $new;
    }

    /**
     * Payout addresses can be replaced, not removed: 1pay.cx never deletes one, and the coin may
     * still be accepted and forwarded to it. So a coin whose field is posted blank (or left out)
     * keeps the address already saved, and this field always shows what the server holds.
     */
    public function validate_payout_addresses_field($key, $value) {
        if (is_null($value)) {
            return (string) $this->get_option($key);
        }
        $saved  = self::clean_address_map(json_decode((string) $this->get_option($key), true));
        $posted = self::clean_address_map(self::decode_posted($value));
        $map    = array_merge($saved, $posted); // saved order kept, posted values replace in place
        return $map ? wp_json_encode($map) : '';
    }

    public function validate_fiat_disabled_field($key, $value) {
        if (is_null($value)) {
            return (string) $this->get_option($key);
        }
        return wp_json_encode(self::clean_ids(self::decode_posted($value), self::FIAT_ID_RE));
    }

    public function validate_crypto_accepted_field($key, $value) {
        if (is_null($value)) {
            return (string) $this->get_option($key);
        }
        if (is_string($value) && '' === trim(wp_unslash($value))) {
            return ''; // unset: every coin the store can take
        }
        return wp_json_encode(self::clean_ids(self::decode_posted($value), self::CRYPTO_ID_RE));
    }

    /** A posted JSON string or array (WordPress slashes both). */
    private static function decode_posted($value) {
        if (is_array($value)) {
            return wp_unslash($value);
        }
        $decoded = json_decode(wp_unslash((string) $value), true);
        return is_array($decoded) ? $decoded : array();
    }

    /** Unique ids matching $pattern, in order. */
    private static function clean_ids($list, $pattern) {
        $out = array();
        foreach ((array) $list as $id) {
            if (is_string($id) && preg_match($pattern, $id) && !in_array($id, $out, true)) {
                $out[] = $id;
            }
            if (count($out) >= 100) {
                break;
            }
        }
        return $out;
    }

    /** coin id => address, whitespace and markup stripped. The API validates the format. */
    private static function clean_address_map($map) {
        $out = array();
        foreach ((array) $map as $asset => $address) {
            if (!is_string($asset) || !preg_match(self::CRYPTO_ID_RE, $asset) || !is_scalar($address)) {
                continue;
            }
            $address = substr((string) preg_replace('/\s+/', '', sanitize_text_field((string) $address)), 0, 128);
            if ('' !== $address) {
                $out[$asset] = $address;
            }
        }
        return $out;
    }

    /** A stored JSON list option as clean ids. */
    private static function json_list($stored, $pattern) {
        $decoded = json_decode((string) $stored, true);
        return is_array($decoded) ? self::clean_ids($decoded, $pattern) : array();
    }

    // ---------------------------------------------------------------------
    // Saving
    // ---------------------------------------------------------------------

    /**
     * On save. Account mode: validate the API key against GET /v1/me and cache the
     * webhook secret (used to verify notify signatures). Wallet mode: validate the EVM
     * address, register the store once, sync payout addresses, methods and coins, then
     * cache the webhook secret of the active key's mode.
     */
    public function process_admin_options() {
        $previous_evm = trim((string) $this->get_option('evm_address'));
        $saved = parent::process_admin_options();
        $this->api_key = (string) $this->get_option('api_key');
        delete_transient(self::STATUS_TRANSIENT);
        delete_transient(self::METHODS_TRANSIENT);

        if ('wallet' === $this->get_mode()) {
            $this->save_wallet_store($previous_evm);
        } else {
            $this->verify_account_key();
        }

        return $saved;
    }

    private function verify_account_key() {
        $key = trim((string) $this->get_option('api_key'));
        if ('' !== $key) {
            // The key's own /v1/me verifies it. Its secret is cached only when it is also the
            // active key; in test mode (R12) the account test key's secret signs the payments.
            $active = $this->get_active_key();
            if ($active === $key) {
                $res = self::cache_webhook_secret($key);
            } else {
                $api = new OnePayCx_Api($key);
                $res = $api->me();
            }
            if (self::has_secret($res)) {
                if ('' === $active) {
                    // Test mode with a live key and no account test key: nothing is offered at checkout yet.
                    WC_Admin_Settings::add_message(__('1pay.cx: API key verified.', '1pay-cx-payments'));
                    WC_Admin_Settings::add_error(__('1pay.cx: test mode is on, but this store has no 1pay.cx test key. Connect your account again, or paste a pk_test_ API key. Until then 1pay.cx is not offered at checkout.', '1pay-cx-payments'));
                    return;
                }
                WC_Admin_Settings::add_message(__('1pay.cx: API key verified. You are ready to accept payments.', '1pay-cx-payments'));
                if ($active !== $key) {
                    $this->refresh_webhook_secret();
                }
            } else {
                WC_Admin_Settings::add_error(sprintf(
                    /* translators: %s: upstream error */
                    __('1pay.cx: could not verify the API key (%s). Double-check it in your 1pay.cx dashboard.', '1pay-cx-payments'),
                    $res['error'] ? $res['error'] : __('unknown error', '1pay-cx-payments')
                ));
            }
        }
    }

    private function save_wallet_store($previous_evm) {
        $evm  = (string) $this->get_option('evm_address');
        $live = (string) get_option('onepaycx_wallet_live_key', '');

        $evm_ok = self::is_evm_address($evm);
        if (!$evm_ok) {
            if ('' !== $live && self::is_evm_address($previous_evm)) {
                // Keep showing the address 1pay.cx actually pays out to.
                $this->update_option('evm_address', $previous_evm);
            }
            WC_Admin_Settings::add_error(self::evm_error_message($evm));
            if ('' === $live) {
                return; // registration needs a valid address
            }
            // A registered store still syncs the rest of what was just saved locally
            // (payout addresses, methods, coins), with the EVM address left out.
        }

        $registered_now = false;
        if ('' === $live) {
            $res  = OnePayCx_Api::register(array(
                'evmAddress'    => $evm,
                // The host the owner's browser comes back to (the claim and connect pages
                // return to wp-admin), which can differ from the Site Address.
                'siteUrl'       => self::admin_site_url(),
                'storeName'     => self::store_name(),
                'platform'      => 'woocommerce',
                'pluginVersion' => ONEPAYCX_VERSION,
            ));
            $data = is_array($res['data']) ? $res['data'] : array();
            $live_key = (isset($data['liveKey']) && is_string($data['liveKey'])) ? $data['liveKey'] : '';
            $test_key = (isset($data['testKey']) && is_string($data['testKey'])) ? $data['testKey'] : '';
            // A test key must never be stored as the live one, nor the reverse.
            if ('blocked' === $res['error']) {
                WC_Admin_Settings::add_error(self::blocked_message());
                return;
            }
            if (!$res['ok'] || !preg_match(self::LIVE_KEY_RE, $live_key) || !preg_match(self::TEST_KEY_RE, $test_key)) {
                WC_Admin_Settings::add_error(sprintf(
                    /* translators: %s: reason from 1pay.cx */
                    __('1pay.cx: this store could not be set up (%s). Check the wallet address and save again.', '1pay-cx-payments'),
                    self::error_text($res)
                ));
                return;
            }
            update_option('onepaycx_wallet_live_key', $live_key);
            update_option('onepaycx_wallet_test_key', $test_key);
            if (isset($data['merchantId']) && is_string($data['merchantId'])) {
                update_option('onepaycx_merchant_id', sanitize_text_field($data['merchantId']));
            }
            $live = $live_key;
            $registered_now = true;
            WC_Admin_Settings::add_message(__('1pay.cx: your store is set up. Payments are sent on to your wallet automatically.', '1pay-cx-payments'));
        }

        // Registration only carries the EVM address; everything else goes in the sync. The
        // address is left out right after registering and when this edit was invalid.
        $body = $this->store_settings_body($evm_ok && !$registered_now);
        // Always the wallet LIVE key, whatever test_mode says: the API refuses test keys on
        // the wallet-store settings and claim-link endpoints (403 test_mode).
        $api  = new OnePayCx_Api($live);
        $res  = $api->save_store_settings($body);
        if ($res['ok']) {
            $this->apply_rejections(is_array($res['data']) ? $res['data'] : array(), array_key_exists('cryptoAccepted', $body));
        } elseif (403 === $res['status'] && 'not_wallet_store' === $res['error']) {
            if ($this->adopt_claimed_store()) {
                return; // switch_to_account() cached the secret of the new active key
            }
        } elseif ('blocked' === $res['error']) {
            // The new EVM address was refused and nothing was saved: show the one 1pay.cx pays out to.
            if (self::is_evm_address($previous_evm)) {
                $this->update_option('evm_address', $previous_evm);
            }
            WC_Admin_Settings::add_error(self::blocked_message());
        } else {
            WC_Admin_Settings::add_error(sprintf(
                /* translators: %s: reason from 1pay.cx */
                __('1pay.cx: your payment settings could not be saved to 1pay.cx (%s). Save again in a moment.', '1pay-cx-payments'),
                self::error_text($res)
            ));
        }

        $this->refresh_webhook_secret();
    }

    /** PUT /wallet-stores/settings body from the saved fields. */
    private function store_settings_body($include_evm) {
        $body = array();
        if ($include_evm) {
            $body['evmAddress'] = (string) $this->get_option('evm_address');
        }
        $addresses = self::clean_address_map(json_decode((string) $this->get_option('payout_addresses'), true));
        if ($addresses) {
            $body['payoutAddresses'] = $addresses;
        }
        $body['fiatDisabled'] = self::json_list($this->get_option('fiat_disabled'), self::FIAT_ID_RE);
        // Unset means "every coin this store can take", so the server default stands.
        if ('' !== trim((string) $this->get_option('crypto_accepted'))) {
            $body['cryptoAccepted'] = self::json_list($this->get_option('crypto_accepted'), self::CRYPTO_ID_RE);
        }
        $name = self::store_name();
        if ('' !== $name) {
            $body['storeName'] = $name;
        }
        return $body;
    }

    /**
     * Turn off every coin the API refused (an invalid address, or no payout address)
     * and say which, so the form matches what buyers are offered.
     */
    private function apply_rejections(array $data, $sent_coins) {
        $rejected = array();
        if (isset($data['rejected']) && is_array($data['rejected'])) {
            foreach ($data['rejected'] as $row) {
                if (is_array($row) && isset($row['asset']) && is_string($row['asset']) && preg_match(self::CRYPTO_ID_RE, $row['asset'])) {
                    $rejected[$row['asset']] = (isset($row['reason']) && is_string($row['reason'])) ? $row['reason'] : '';
                }
            }
        }
        $accepted = (isset($data['accepted']) && is_array($data['accepted'])) ? self::clean_ids($data['accepted'], self::CRYPTO_ID_RE) : null;

        $off = array();
        $raw = trim((string) $this->get_option('crypto_accepted'));
        if ('' !== $raw) {
            $current = self::json_list($raw, self::CRYPTO_ID_RE);
            foreach ($current as $id) {
                if (isset($rejected[$id]) || ($sent_coins && null !== $accepted && !in_array($id, $accepted, true))) {
                    $off[] = $id;
                }
            }
            if ($off) {
                $this->update_option('crypto_accepted', wp_json_encode(array_values(array_diff($current, $off))));
            }
        }

        foreach ($rejected as $asset => $reason) {
            WC_Admin_Settings::add_error(sprintf(
                /* translators: 1: coin name, 2: why 1pay.cx refused it */
                __('1pay.cx: %1$s was turned off (%2$s). Add a valid address to accept it.', '1pay-cx-payments'),
                self::coin_name($asset),
                self::reason_text($reason)
            ));
        }
        foreach (array_diff($off, array_keys($rejected)) as $asset) {
            WC_Admin_Settings::add_error(sprintf(
                /* translators: %s: coin name */
                __('1pay.cx: %s was turned off. It needs a valid payout address before buyers can pay with it.', '1pay-cx-payments'),
                self::coin_name($asset)
            ));
        }
    }

    /**
     * The store's wallet-only record became a 1pay.cx account (claimed on 1pay.cx): its
     * keys keep working, but payout wallets now follow the dashboard. Switch to account
     * mode with the same keys.
     */
    private function adopt_claimed_store() {
        $me = self::adopt_wallet_keys();
        if (false === $me) {
            return false;
        }
        $this->init_settings();
        $this->api_key = (string) $this->get_option('api_key');
        WC_Admin_Settings::add_message(__('1pay.cx: this store is now part of your 1pay.cx account. Payouts and payout wallets are managed in your dashboard.', '1pay-cx-payments'));
        self::report_secret_failure($me);
        return true;
    }

    /**
     * Move the wallet store's keys into account mode (see switch_to_account()). False when
     * there is no valid wallet live key, else the secret refresh result.
     */
    private static function adopt_wallet_keys() {
        $live = (string) get_option('onepaycx_wallet_live_key', '');
        if (!preg_match(self::LIVE_KEY_RE, $live)) {
            return false;
        }
        return self::switch_to_account($live, (string) get_option('onepaycx_wallet_test_key', ''));
    }

    /**
     * Put the store in account mode with these keys: the one path used by the connect
     * callback and by claimed-store adoption. The live key goes in `api_key`; the test key
     * goes in `onepaycx_account_test_key` when it has the test format, and otherwise the
     * slot is cleared, so a previous account's test key is never paired with this live key
     * (get_active_key() then fails closed in test mode). `test_mode` is left as the owner
     * set it. Clears the cached account facts and caches the webhook secret of the
     * resulting active key.
     *
     * @return array|null The /v1/me result of the secret refresh; null without an active key.
     */
    private static function switch_to_account($live, $test, $merchant_id = null) {
        $settings = self::stored_settings();
        $settings['api_key'] = $live;
        $settings['mode']    = 'account';
        update_option(self::OPTION_KEY, $settings);
        if (1 === preg_match(self::TEST_KEY_RE, (string) $test)) {
            update_option('onepaycx_account_test_key', $test);
        } else {
            delete_option('onepaycx_account_test_key');
        }
        if (is_string($merchant_id) && '' !== $merchant_id) {
            update_option('onepaycx_merchant_id', sanitize_text_field($merchant_id));
        }
        delete_transient(self::STATUS_TRANSIENT);
        delete_transient(self::METHODS_TRANSIENT);
        return self::cache_webhook_secret(self::resolve_active_key($settings));
    }

    /** Cache the webhook secret of the active key's mode (test and live are signed differently). */
    private function refresh_webhook_secret() {
        self::report_secret_failure(self::cache_webhook_secret($this->get_active_key()));
    }

    /**
     * GET /v1/me with $key and cache its webhook secret (and merchant id).
     *
     * @return array|null The /v1/me result; null when $key is empty.
     */
    private static function cache_webhook_secret($key) {
        if ('' === (string) $key) {
            return null;
        }
        $api = new OnePayCx_Api($key);
        $res = $api->me();
        if (self::has_secret($res)) {
            update_option('onepaycx_webhook_secret', $res['data']['webhookSecret']);
            if (isset($res['data']['id']) && is_string($res['data']['id'])) {
                update_option('onepaycx_merchant_id', sanitize_text_field($res['data']['id']));
            }
        }
        return $res;
    }

    /** A /v1/me result that carries a webhook secret. */
    private static function has_secret($res) {
        return is_array($res) && !empty($res['ok']) && is_array($res['data'])
            && !empty($res['data']['webhookSecret']) && is_string($res['data']['webhookSecret']);
    }

    /** Admin error for a secret refresh that ran and failed (null means there was no key to refresh). */
    private static function report_secret_failure($res) {
        if (null === $res || self::has_secret($res)) {
            return;
        }
        WC_Admin_Settings::add_error(sprintf(
            /* translators: %s: reason from 1pay.cx */
            __('1pay.cx: could not read this store\'s notification secret (%s). Save again in a moment.', '1pay-cx-payments'),
            self::error_text($res)
        ));
    }

    private static function store_name() {
        $name = trim(html_entity_decode((string) get_bloginfo('name'), ENT_QUOTES, 'UTF-8'));
        return function_exists('mb_substr') ? mb_substr($name, 0, 100) : substr($name, 0, 100);
    }

    private static function error_text(array $res) {
        if ('invalid_address' === $res['error']) {
            return __('the wallet address was refused', '1pay-cx-payments');
        }
        if ('blocked' === $res['error']) {
            return __('this payout address can\'t be used with 1pay.cx; contact support', '1pay-cx-payments');
        }
        if ('limit' === $res['error']) {
            return __('your 1pay.cx account has as many API keys as it can hold', '1pay-cx-payments');
        }
        if (429 === $res['status']) {
            return __('too many attempts, try again later', '1pay-cx-payments');
        }
        return '' !== $res['error'] ? sanitize_text_field($res['error']) : __('unknown error', '1pay-cx-payments');
    }

    /** 403 `blocked`: an address 1pay.cx has blocked, at registration or as a new EVM address. */
    private static function blocked_message() {
        return __('1pay.cx: This payout address can\'t be used with 1pay.cx. Contact support.', '1pay-cx-payments');
    }

    /** Why $evm cannot be saved as the payout wallet. */
    private static function evm_error_message($evm) {
        if ('' === $evm) {
            return __('1pay.cx: add your EVM wallet address to start accepting payments.', '1pay-cx-payments');
        }
        $problem = OnePayCx_Evm::problem($evm);
        if ('zero' === $problem) {
            return __('1pay.cx: that is the zero address, which nobody can spend from. Paste the address of your own wallet.', '1pay-cx-payments');
        }
        if ('checksum' === $problem) {
            return __('1pay.cx: that address has a typo: its mix of capital and small letters does not match its checksum. Copy it again from your wallet.', '1pay-cx-payments');
        }
        return __('1pay.cx: that isn\'t an EVM address. It should be 0x followed by 40 letters and numbers.', '1pay-cx-payments');
    }

    /**
     * scheme://host[:port] of wp-admin: the store's site as 1pay.cx records it at registration.
     * The claim page returns the browser to wp-admin and accepts only that registered host, so
     * it is taken from admin_url() (the WordPress Address), not home_url() (the Site Address),
     * which some stores serve from another host.
     */
    private static function admin_site_url() {
        $parts = wp_parse_url(admin_url('/'));
        if (!is_array($parts) || empty($parts['host'])) {
            return home_url();
        }
        $scheme = isset($parts['scheme']) ? strtolower((string) $parts['scheme']) : 'https';
        return $scheme . '://' . $parts['host'] . (isset($parts['port']) ? ':' . (int) $parts['port'] : '');
    }

    private static function reason_text($reason) {
        if ('invalid_address' === $reason) {
            return __('the address is not valid for this coin', '1pay-cx-payments');
        }
        if ('asset_unknown' === $reason) {
            return __('this coin is not available', '1pay-cx-payments');
        }
        return '' !== $reason ? sanitize_text_field($reason) : __('not accepted', '1pay-cx-payments');
    }

    /** A coin's display name from the cached catalogue, else its id in capitals. */
    private static function coin_name($id) {
        $catalogue = get_transient('onepaycx_catalogue');
        if (is_array($catalogue) && isset($catalogue['crypto']) && is_array($catalogue['crypto'])) {
            foreach ($catalogue['crypto'] as $coin) {
                if (is_array($coin) && isset($coin['id'], $coin['name']) && $coin['id'] === $id && is_string($coin['name'])) {
                    return sanitize_text_field($coin['name']);
                }
            }
        }
        return strtoupper($id);
    }

    // ---------------------------------------------------------------------
    // Account connect, claimed stores and admin notices
    // ---------------------------------------------------------------------

    /**
     * https://1pay.cx/connect for this store. The state is stored in the
     * onepaycx_connect_state transient and checked when 1pay.cx sends the owner back
     * to the settings screen with onepaycx_connect=1&code=…&state=….
     */
    public static function connect_url() {
        $state = get_transient(self::STATE_TRANSIENT);
        if (!is_string($state) || !preg_match('/^[A-Za-z0-9]{32,64}$/', $state)) {
            $state = wp_generate_password(32, false, false);
            set_transient(self::STATE_TRANSIENT, $state, self::STATE_TTL);
        }
        $return = admin_url(self::CONNECT_RETURN_PATH);
        $host   = (string) wp_parse_url($return, PHP_URL_HOST);
        return ONEPAYCX_CHECKOUT_BASE . '/connect?site=' . rawurlencode($host) . '&return=' . rawurlencode($return) . '&state=' . rawurlencode($state);
    }

    /** This gateway's settings screen. */
    private static function is_settings_request(array $query) {
        return isset($query['page'], $query['tab'], $query['section'])
            && 'wc-settings' === $query['page'] && 'checkout' === $query['tab'] && 'onepaycx' === $query['section'];
    }

    /**
     * The owner coming back from https://1pay.cx/connect to CONNECT_RETURN_PATH with
     * code=<code>&state=<state> appended. Returns null when $query is not a connect
     * callback, else ['ok' => bool, 'message' => string]. The capability and the state
     * (against the onepaycx_connect_state transient) are checked before the code is
     * exchanged.
     */
    public static function handle_connect_callback(array $query) {
        if (!self::is_settings_request($query) || !isset($query['onepaycx_connect']) || '1' !== $query['onepaycx_connect']) {
            return null;
        }
        $code = (isset($query['code']) && is_string($query['code'])) ? sanitize_text_field($query['code']) : '';
        if ('' === $code) {
            return null;
        }
        if (!current_user_can('manage_woocommerce')) {
            return array('ok' => false, 'message' => __('1pay.cx: you do not have permission to connect this store.', '1pay-cx-payments'));
        }
        $state    = (isset($query['state']) && is_string($query['state'])) ? sanitize_text_field($query['state']) : '';
        $expected = get_transient(self::STATE_TRANSIENT);
        if (!is_string($expected) || '' === $expected || '' === $state || !hash_equals($expected, $state)) {
            return array('ok' => false, 'message' => __('1pay.cx: this connection link has expired or was already used. Click Connect again.', '1pay-cx-payments'));
        }
        delete_transient(self::STATE_TRANSIENT); // single use

        $res  = OnePayCx_Api::exchange_code($code);
        $data = is_array($res['data']) ? $res['data'] : array();
        $live = (isset($data['liveKey']) && is_string($data['liveKey'])) ? $data['liveKey'] : '';
        $test = (isset($data['testKey']) && is_string($data['testKey'])) ? $data['testKey'] : '';
        if ('limit' === $res['error']) {
            // The account's API key cap (2 until its email is verified): reconnecting this site
            // replaces its own keys, so only other keys can be in the way.
            return array('ok' => false, 'message' => __('1pay.cx: your 1pay.cx account already has as many API keys as it can hold. Verify your email address, or revoke API keys you no longer use in your 1pay.cx dashboard (Settings › API keys), then click Connect again.', '1pay-cx-payments'));
        }
        if (!$res['ok'] || !preg_match(self::LIVE_KEY_RE, $live)) {
            return array('ok' => false, 'message' => sprintf(
                /* translators: %s: reason from 1pay.cx */
                __('1pay.cx: could not connect your account (%s). Click Connect again.', '1pay-cx-payments'),
                self::error_text($res)
            ));
        }

        self::switch_to_account($live, $test, (isset($data['merchantId']) && is_string($data['merchantId'])) ? $data['merchantId'] : null);
        return array('ok' => true, 'message' => __('1pay.cx: connected. New payments from this store go to your 1pay.cx account.', '1pay-cx-payments'));
    }

    /**
     * A wallet-only store that its owner turned into a 1pay.cx account (GET /v1/me says
     * walletOnly: false) switches to account mode with the same keys. That holds in wallet
     * mode, and in account mode while no API key is set: the account card offers "Create a
     * free account for this store instead" there too, with the wallet store's keys. Returns
     * null when nothing changed, else ['ok' => true, 'message' => string].
     */
    public static function maybe_adopt_claimed_store() {
        if (!current_user_can('manage_woocommerce')) {
            return null;
        }
        $settings = self::stored_settings();
        $api_key  = isset($settings['api_key']) ? trim((string) $settings['api_key']) : '';
        if ('wallet' !== self::resolve_mode($settings) && '' !== $api_key) {
            return null; // an account (connected or pasted) is in use: never replace it
        }
        $status = self::store_status($settings);
        if (!is_array($status) || !array_key_exists('walletOnly', $status) || false !== $status['walletOnly'] || false === self::adopt_wallet_keys()) {
            return null;
        }
        return array('ok' => true, 'message' => __('1pay.cx: your account is ready. This store now uses your 1pay.cx account, and payouts follow your dashboard.', '1pay-cx-payments'));
    }

    /**
     * admin_init on this gateway's settings screen: finish a connect round trip or adopt
     * a claimed store, then redirect so the code leaves the address bar.
     */
    public static function maybe_handle_connect() {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- the connect state transient is the nonce; values are sanitized below.
        $query = wp_unslash($_GET);
        if (!is_array($query) || !self::is_settings_request($query)) {
            return;
        }
        $result = self::handle_connect_callback($query);
        if (null === $result) {
            $result = self::maybe_adopt_claimed_store();
        }
        if (null === $result) {
            return;
        }
        set_transient(self::NOTICE_TRANSIENT . get_current_user_id(), $result, 60);
        wp_safe_redirect(admin_url(self::SETTINGS_PATH));
        exit;
    }

    /**
     * GET /v1/me facts the settings screen shows, cached for 5 minutes (failures too, so
     * an unreachable API costs one request per 5 minutes). Uses the live key: waiting
     * payouts are real money. Account mode reads `api_key`; with none set, the wallet
     * store's own live key (the record a claim from the account card turns into the account).
     */
    private static function store_status(array $settings) {
        $cached = get_transient(self::STATUS_TRANSIENT);
        if (is_array($cached)) {
            return $cached;
        }
        $key = isset($settings['api_key']) ? trim((string) $settings['api_key']) : '';
        if ('wallet' === self::resolve_mode($settings) || '' === $key) {
            $key = (string) get_option('onepaycx_wallet_live_key', '');
        }
        if ('' === $key) {
            return null;
        }
        $api    = new OnePayCx_Api($key);
        $res    = $api->me();
        $status = array();
        if ($res['ok'] && is_array($res['data'])) {
            $data = $res['data'];
            if (isset($data['walletOnly']) && is_bool($data['walletOnly'])) {
                $status['walletOnly'] = $data['walletOnly'];
            }
            $waiting = (isset($data['payoutsWaiting']) && is_array($data['payoutsWaiting'])) ? $data['payoutsWaiting'] : array();
            if (isset($data['businessName']) && is_string($data['businessName'])) {
                $status['name'] = sanitize_text_field($data['businessName']);
            }
            $status['count'] = (isset($waiting['count']) && is_numeric($waiting['count'])) ? max(0, (int) $waiting['count']) : 0;
            $status['usd']   = (isset($waiting['usd']) && is_numeric($waiting['usd'])) ? max(0.0, (float) $waiting['usd']) : 0.0;
        }
        set_transient(self::STATUS_TRANSIENT, $status, self::STATUS_TTL);
        return $status;
    }

    /** admin_notices on this gateway's settings screen: the connect result and waiting payouts. */
    public static function render_admin_notices() {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only screen check.
        $query = wp_unslash($_GET);
        if (!is_array($query) || !self::is_settings_request($query) || !current_user_can('manage_woocommerce')) {
            return;
        }

        $flash_key = self::NOTICE_TRANSIENT . get_current_user_id();
        $flash     = get_transient($flash_key);
        if (is_array($flash) && isset($flash['message'])) {
            delete_transient($flash_key);
            printf(
                '<div class="notice %1$s"><p>%2$s</p></div>',
                !empty($flash['ok']) ? 'notice-success' : 'notice-error',
                esc_html((string) $flash['message'])
            );
        }

        $status = self::store_status(self::stored_settings());
        if (is_array($status) && !empty($status['count'])) {
            $count = (int) $status['count'];
            printf('<div class="notice notice-warning"><p>%s</p></div>', esc_html(sprintf(
                /* translators: 1: number of payouts, 2: their total in US dollars */
                _n(
                    '1pay.cx: %1$d payout worth about $%2$s is waiting to be sent to you. Small amounts wait until they reach the minimum for their network, and some payouts are checked before they are sent.',
                    '1pay.cx: %1$d payouts worth about $%2$s are waiting to be sent to you. Small amounts wait until they reach the minimum for their network, and some payouts are checked before they are sent.',
                    $count,
                    '1pay-cx-payments'
                ),
                $count,
                number_format((float) $status['usd'], 2)
            )));
        }
    }

    // ---------------------------------------------------------------------
    // Settings screen: panel, assets and the "Create free account" action
    // ---------------------------------------------------------------------

    /** WooCommerce calls this to draw the gateway's settings; the panel replaces the generic field table. */
    public function admin_options() {
        // The panel carries its own Save button (still named "save", so WooCommerce saves the form as usual).
        $GLOBALS['hide_save_button'] = true;
        require_once __DIR__ . '/class-onepaycx-settings-ui.php';
        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- every dynamic part is escaped where the markup is built.
        echo OnePayCx_Settings_UI::render($this->panel_context());
    }

    /**
     * What the panel shows: the saved settings, the live catalogue and, for a connected
     * account, what its dashboard allows (GET /v1/methods) and its business name.
     */
    public function panel_context() {
        $wallet  = 'wallet' === $this->get_mode();
        $api_key = trim((string) $this->get_option('api_key'));
        $key_ok  = 1 === preg_match(self::LIVE_KEY_RE, $api_key) || 1 === preg_match(self::TEST_KEY_RE, $api_key);
        $connected = !$wallet && $key_ok;
        $test_mode = 'yes' === $this->get_option('test_mode');
        $active    = $this->get_active_key();

        $status = $connected ? self::store_status(self::stored_settings()) : null;
        $locked = array('fiat' => array(), 'crypto' => array());
        if (!$wallet && '' !== $active) {
            $locked = self::locked_methods($this->panel_account_methods());
        }
        $crypto = trim((string) $this->get_option('crypto_accepted'));

        return array(
            'prefix'           => $this->get_field_key(''),
            'wallet'           => $wallet,
            'mode'             => $wallet ? 'wallet' : 'account',
            'enabled'          => 'yes' === $this->get_option('enabled'),
            'test_mode'        => $test_mode,
            'evm'              => (string) $this->get_option('evm_address'),
            'addresses'        => self::clean_address_map(json_decode((string) $this->get_option('payout_addresses'), true)),
            'fiat_disabled'    => self::json_list($this->get_option('fiat_disabled'), self::FIAT_ID_RE),
            'crypto_accepted'  => '' === $crypto ? null : self::json_list($crypto, self::CRYPTO_ID_RE),
            'title'            => (string) $this->get_option('title'),
            'description'      => (string) $this->get_option('description'),
            'direct'           => 'yes' === $this->get_option('direct_checkout'),
            'api_key'          => $api_key,
            'connected'        => $connected,
            'account_name'     => (is_array($status) && isset($status['name'])) ? (string) $status['name'] : '',
            'key_is_test'      => $test_mode || 0 === strpos($active, 'pk_test_'),
            'test_key_missing' => $connected && $test_mode && '' === $active,
            'registered'       => 1 === preg_match(self::LIVE_KEY_RE, (string) get_option('onepaycx_wallet_live_key', '')),
            'catalogue'        => self::panel_catalogue(),
            'locked_fiat'      => $locked['fiat'],
            'locked_crypto'    => $locked['crypto'],
            'urls'             => array(
                'connect'   => self::connect_url(),
                'claim'     => wp_nonce_url(admin_url('admin-post.php?action=onepaycx_claim'), 'onepaycx_claim'),
                'dashboard' => ONEPAYCX_CHECKOUT_BASE . '/app',
                'terms'     => ONEPAYCX_CHECKOUT_BASE . '/terms',
                'aup'       => ONEPAYCX_CHECKOUT_BASE . '/acceptable-use',
                'support'   => ONEPAYCX_SUPPORT_URL,
            ),
        );
    }

    /**
     * The public catalogue for the panel: from the 10-minute cache, else fetched. A failed
     * fetch is remembered for a minute, so an unreachable API costs one wait, not one per page load.
     */
    private static function panel_catalogue() {
        $cached = get_transient('onepaycx_catalogue');
        if (is_array($cached)) {
            return $cached;
        }
        if (get_transient('onepaycx_catalogue_fail')) {
            return null;
        }
        $res = OnePayCx_Api::catalogue();
        if (!$res['ok'] || !is_array($res['data'])) {
            set_transient('onepaycx_catalogue_fail', 1, 60);
            return null;
        }
        return $res['data'];
    }

    /** GET /v1/methods for the connected account (cached 10 minutes by account_methods()); a failure is remembered for a minute. */
    private function panel_account_methods() {
        if (!is_array(get_transient(self::METHODS_TRANSIENT)) && get_transient('onepaycx_methods_fail')) {
            return null;
        }
        $methods = $this->account_methods();
        if (null === $methods) {
            set_transient('onepaycx_methods_fail', 1, 60);
        }
        return $methods;
    }

    /** Ids the account's dashboard has switched off: fiat accepted=false, and coins that are available but not accepted. */
    private static function locked_methods($methods) {
        $locked = array('fiat' => array(), 'crypto' => array());
        if (!is_array($methods)) {
            return $locked;
        }
        foreach (array('fiat' => self::FIAT_ID_RE, 'crypto' => self::CRYPTO_ID_RE) as $kind => $pattern) {
            if (empty($methods[$kind]) || !is_array($methods[$kind])) {
                continue;
            }
            foreach ($methods[$kind] as $row) {
                if (!is_array($row) || !isset($row['id']) || !is_string($row['id']) || !preg_match($pattern, $row['id'])) {
                    continue;
                }
                $unavailable = 'crypto' === $kind && array_key_exists('available', $row) && true !== $row['available'];
                if (!$unavailable && array_key_exists('accepted', $row) && false === $row['accepted']) {
                    $locked[$kind][] = $row['id'];
                }
            }
        }
        return $locked;
    }

    /** admin_enqueue_scripts: the panel's CSS and JS, on this gateway's settings screen only. */
    public static function enqueue_admin_assets($hook_suffix = '') {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only screen check.
        $query = wp_unslash($_GET);
        if (!is_array($query) || !self::is_settings_request($query) || !current_user_can('manage_woocommerce')) {
            return;
        }
        wp_enqueue_style('onepaycx-settings', plugins_url('assets/admin/settings.css', ONEPAYCX_PLUGIN_FILE), array(), ONEPAYCX_VERSION);
        // In the head on purpose: the script marks the page as script-enabled before first paint,
        // which is what switches off the plain-form fallback layout.
        wp_enqueue_script('onepaycx-settings', plugins_url('assets/admin/settings.js', ONEPAYCX_PLUGIN_FILE), array(), ONEPAYCX_VERSION, false);
    }

    /**
     * admin-post.php?action=onepaycx_claim (the "Create free account" buttons): asks 1pay.cx
     * for a claim link for this wallet store and sends the browser there. The claim page
     * returns to the settings screen, which then adopts the claimed store (see
     * maybe_adopt_claimed_store()).
     */
    public static function handle_claim_request() {
        if (!current_user_can('manage_woocommerce')) {
            wp_die(esc_html__('You do not have permission to do this.', '1pay-cx-payments'), '', array('response' => 403));
        }
        check_admin_referer('onepaycx_claim');
        $target = self::claim_destination();
        if ($target['external']) {
            wp_redirect($target['url']); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- the 1pay.cx host is checked in claim_destination().
        } else {
            wp_safe_redirect($target['url']);
        }
        exit;
    }

    /** Where handle_claim_request() sends the browser: the claim link (external), or the settings screen with a notice. */
    private static function claim_destination() {
        // The claim changes what /v1/me says about this store; do not wait out the 5-minute cache on return.
        delete_transient(self::STATUS_TRANSIENT);

        $live = (string) get_option('onepaycx_wallet_live_key', '');
        if (1 !== preg_match(self::LIVE_KEY_RE, $live)) {
            return self::claim_failed(__('1pay.cx: save your wallet address first, then create your account.', '1pay-cx-payments'));
        }
        // The wallet LIVE key even in test mode: the API refuses test keys here (403 test_mode).
        $api = new OnePayCx_Api($live);
        $res = $api->claim_link(admin_url(self::CONNECT_RETURN_PATH));
        if (403 === $res['status'] && 'not_wallet_store' === $res['error'] && false !== self::adopt_wallet_keys()) {
            // Already an account: switch to it instead.
            return self::claim_failed(__('1pay.cx: this store is now part of your 1pay.cx account. Payouts and payout wallets are managed in your dashboard.', '1pay-cx-payments'), true);
        }
        if ('return_mismatch' === $res['error']) {
            // 1pay.cx sends the browser back only to the host this store registered with, and
            // wp-admin is now on another one (the site moved, or its WordPress Address changed).
            return self::claim_failed(__('1pay.cx: this store was set up under a different web address, so an account cannot be created for it from here. Sign up at 1pay.cx and use Connect instead, or contact support.', '1pay-cx-payments'));
        }
        $url = (is_array($res['data']) && isset($res['data']['url']) && is_string($res['data']['url'])) ? $res['data']['url'] : '';
        if (!$res['ok'] || !self::is_claim_url($url)) {
            return self::claim_failed(sprintf(
                /* translators: %s: reason from 1pay.cx */
                __('1pay.cx: could not start creating your account (%s). Try again in a moment.', '1pay-cx-payments'),
                $res['ok'] ? __('unexpected answer', '1pay-cx-payments') : self::error_text($res)
            ));
        }
        return array('url' => $url, 'external' => true);
    }

    /** Back to the settings screen with a notice (shown by render_admin_notices()). */
    private static function claim_failed($message, $ok = false) {
        set_transient(self::NOTICE_TRANSIENT . get_current_user_id(), array('ok' => (bool) $ok, 'message' => $message), 60);
        return array('url' => admin_url(self::SETTINGS_PATH), 'external' => false);
    }

    /** Only an https://1pay.cx/connect link is ever followed, never the host the API response names. */
    private static function is_claim_url($url) {
        $parts = wp_parse_url($url);
        $base  = wp_parse_url(ONEPAYCX_CHECKOUT_BASE);
        return is_array($parts) && is_array($base)
            && isset($parts['scheme'], $parts['host'], $parts['path'], $base['host'])
            && 'https' === strtolower($parts['scheme'])
            && strtolower($parts['host']) === strtolower($base['host'])
            && !isset($parts['user'], $parts['pass'], $parts['port'])
            && '/connect' === $parts['path'];
    }

    // ---------------------------------------------------------------------
    // Payments and notifications
    // ---------------------------------------------------------------------

    /** Card refunds aren't supported — show that in the admin instead of a broken button. */
    public function can_refund_order($order) {
        return false;
    }

    /**
     * Account mode only: the card methods / coins to offer when the owner switched some
     * off. Empty (nothing sent, checkout unchanged) when nothing was narrowed. Wallet
     * stores sync their choices to 1pay.cx instead.
     */
    public function account_payment_filters() {
        $extra    = array();
        $disabled = self::json_list($this->get_option('fiat_disabled'), self::FIAT_ID_RE);
        $coins    = trim((string) $this->get_option('crypto_accepted'));
        if (!$disabled && '' === $coins) {
            return $extra;
        }
        $methods = $this->account_methods();

        if ($disabled) {
            $ids = self::accepted_ids($methods, 'fiat');
            if (null === $ids) {
                $catalogue = OnePayCx_Api::catalogue();
                $ids = $catalogue['ok'] ? self::accepted_ids($catalogue['data'], 'fiat') : null;
            }
            if (null !== $ids) {
                $extra['cardMethods'] = array_values(array_diff($ids, $disabled));
            }
        }

        if ('' !== $coins) {
            $chosen  = self::json_list($coins, self::CRYPTO_ID_RE);
            $allowed = self::accepted_ids($methods, 'crypto');
            if (null === $allowed) {
                // The checkout intersects this with what the account accepts.
                $extra['cryptoAssets'] = $chosen;
            } else {
                $kept = array_values(array_intersect($allowed, $chosen));
                if (count($kept) < count($allowed)) {
                    $extra['cryptoAssets'] = $kept;
                }
            }
        }
        return $extra;
    }

    /** GET /v1/methods for the active key, cached for 10 minutes; null when unavailable. */
    private function account_methods() {
        $cached = get_transient(self::METHODS_TRANSIENT);
        if (is_array($cached)) {
            return $cached;
        }
        $key = $this->get_active_key();
        if ('' === $key) {
            return null;
        }
        $api = new OnePayCx_Api($key);
        $res = $api->methods();
        if (!$res['ok'] || !is_array($res['data'])) {
            return null;
        }
        set_transient(self::METHODS_TRANSIENT, $res['data'], self::METHODS_TTL);
        return $res['data'];
    }

    /** Ids in $data[$kind] that are accepted (entries without `accepted` count as accepted); null if absent. */
    private static function accepted_ids($data, $kind) {
        if (!is_array($data) || !isset($data[$kind]) || !is_array($data[$kind])) {
            return null;
        }
        $ids = array();
        foreach ($data[$kind] as $method) {
            if (is_array($method) && isset($method['id']) && is_string($method['id'])
                && (!array_key_exists('accepted', $method) || true === $method['accepted'])
                && !in_array($method['id'], $ids, true)) {
                $ids[] = $method['id'];
            }
        }
        return $ids;
    }

    public function process_payment($order_id) {
        $order = wc_get_order($order_id);
        if (!$order) {
            return array('result' => 'failure');
        }

        $body = array(
            'amount'    => (float) $order->get_total(),
            'currency'  => $order->get_currency(),
            'email'     => $order->get_billing_email(),
            'reference' => (string) $order_id,
            'notifyUrl' => WC()->api_request_url('onepaycx'),
            'redirectUrl' => $this->get_return_url($order),
        );
        if ('account' === $this->get_mode()) {
            $body = array_merge($body, $this->account_payment_filters());
        }

        $api = new OnePayCx_Api($this->get_active_key());
        $res = $api->create_payment($body);

        if (!$res['ok'] || empty($res['data']['checkoutUrl'])) {
            $msg = $res['error'] ? $res['error'] : __('payment could not be started', '1pay-cx-payments');
            /* translators: %s: payment service error message. */
            wc_add_notice(sprintf(__('1pay.cx error: %s', '1pay-cx-payments'), $msg), 'error');
            /* translators: %s: payment service error message. */
            $order->add_order_note(sprintf(__('1pay.cx: create payment failed (%s).', '1pay-cx-payments'), $msg));
            return array('result' => 'failure');
        }

        $payment_id = isset($res['data']['id']) ? (string) $res['data']['id'] : '';
        $order->update_meta_data('_onepaycx_payment_id', $payment_id);
        $order->update_status('pending', __('Awaiting 1pay.cx payment.', '1pay-cx-payments'));
        $order->save();

        // checkoutUrl is a path like /pay/<id>; make it absolute.
        $checkout = $res['data']['checkoutUrl'];
        $redirect = (strpos($checkout, 'http') === 0) ? $checkout : ONEPAYCX_CHECKOUT_BASE . $checkout;

        // Direct mode: skip the 1pay.cx page and cascade straight to the processor.
        // Only valid when the payment carries an email (we always send the billing
        // email above) — without one the checkout page must ask for it.
        if ($this->direct && $order->get_billing_email()) {
            $redirect .= (strpos($redirect, '?') === false ? '?' : '&') . 'direct=1';
        }

        WC()->cart->empty_cart();

        return array(
            'result'   => 'success',
            'redirect' => $redirect,
        );
    }

    /**
     * Verify a delivery against one secret. Prefers the timestamped header
     * (X-1pay-Signature-V2: t=<unix>,v1=<hex>, HMAC of "<t>.<body>"), which also
     * bounds replays to SIGNATURE_TOLERANCE; falls back to the legacy body-only
     * header only when V2 is absent (older API).
     */
    public static function signature_ok($secret, $body, $v2, $legacy) {
        if (!$secret) {
            return false;
        }
        if ($v2 !== '') {
            $parts = array();
            foreach (explode(',', $v2) as $kv) {
                $pair = explode('=', $kv, 2);
                if (count($pair) === 2) {
                    $parts[trim($pair[0])] = trim($pair[1]);
                }
            }
            $t = (isset($parts['t']) && ctype_digit($parts['t'])) ? (int) $parts['t'] : 0;
            if ($t <= 0 || abs(time() - $t) > self::SIGNATURE_TOLERANCE || empty($parts['v1'])) {
                return false;
            }
            return hash_equals(hash_hmac('sha256', $t . '.' . $body, $secret), (string) $parts['v1']);
        }
        return $legacy !== '' && hash_equals(hash_hmac('sha256', $body, $secret), $legacy);
    }

    /** Amount and currency of a payment (notify data or API row) equal the order's total. */
    public static function amount_matches($order, array $p) {
        if (!isset($p['amount']) || !is_numeric($p['amount']) || empty($p['currency'])) {
            return false;
        }
        if (strtoupper((string) $p['currency']) !== strtoupper((string) $order->get_currency())) {
            return false;
        }
        return abs((float) $p['amount'] - (float) $order->get_total()) < 0.01;
    }

    /** Stop handling this request with a status and a short reason. */
    private static function finish($status, $message) {
        status_header($status);
        exit(esc_html($message));
    }

    /**
     * Signed notify from the settlement layer. A valid signature only proves the
     * event is ours, not that it is about THIS order, so before completing the
     * order we also require: the payment id stored on the order at checkout, the
     * order's total and currency, the mode of the active key, and the API's
     * own view of the payment (GET /v1/payments/:id must say paid).
     */
    public function handle_webhook() {
        $body   = file_get_contents('php://input');
        $v2     = isset($_SERVER['HTTP_X_1PAY_SIGNATURE_V2']) ? sanitize_text_field(wp_unslash($_SERVER['HTTP_X_1PAY_SIGNATURE_V2'])) : '';
        $legacy = isset($_SERVER['HTTP_X_1PAY_SIGNATURE']) ? sanitize_text_field(wp_unslash($_SERVER['HTTP_X_1PAY_SIGNATURE'])) : '';

        if ($v2 === '' && $legacy === '') {
            self::finish(400, 'missing signature');
        }

        $key    = $this->get_active_key();
        $secret = (string) get_option('onepaycx_webhook_secret');
        if (!self::signature_ok($secret, $body, $v2, $legacy)) {
            // Test and live payments are signed with different secrets; a store saved
            // before that split may hold the other one. Re-read ours once (throttled).
            if ($key && !get_transient('onepaycx_secret_refresh')) {
                set_transient('onepaycx_secret_refresh', 1, 60);
                $api = new OnePayCx_Api($key);
                $me  = $api->me();
                if ($me['ok'] && is_array($me['data']) && !empty($me['data']['webhookSecret']) && is_string($me['data']['webhookSecret'])) {
                    $secret = $me['data']['webhookSecret'];
                    update_option('onepaycx_webhook_secret', $secret);
                }
            }
            if (!self::signature_ok($secret, $body, $v2, $legacy)) {
                self::finish(401, 'bad signature');
            }
        }

        $payload = json_decode($body, true);
        $data    = (is_array($payload) && isset($payload['data']) && is_array($payload['data'])) ? $payload['data'] : array();
        $event   = (is_array($payload) && isset($payload['event'])) ? (string) $payload['event'] : '';

        if ($event !== 'payment.paid' || empty($data['reference']) || !isset($data['status']) || $data['status'] !== 'paid') {
            self::finish(200, 'ignored');
        }

        // A live store never accepts a test payment, nor a test store a live one.
        $livemode = array_key_exists('livemode', $data) ? ($data['livemode'] !== false) : true;
        if ($livemode === $this->is_test_key()) {
            self::finish(400, 'mode mismatch');
        }

        $order = wc_get_order((int) $data['reference']);
        if (!$order || $order->get_payment_method() !== $this->id) {
            self::finish(404, 'order not found');
        }

        // Idempotent: a repeated notify is a no-op.
        if ($order->is_paid()) {
            self::finish(200, 'already paid');
        }

        // The payment must be the one this order created, not any paid payment on the account.
        $payment_id = isset($data['paymentId']) ? (string) $data['paymentId'] : '';
        $stored_id  = (string) $order->get_meta('_onepaycx_payment_id');
        if ($payment_id === '' || $stored_id === '' || !hash_equals($stored_id, $payment_id)) {
            $order->add_order_note(__('1pay.cx: ignored a notify whose payment id does not match this order.', '1pay-cx-payments'));
            self::finish(400, 'payment mismatch');
        }

        if (!self::amount_matches($order, $data)) {
            $order->add_order_note(__('1pay.cx: ignored a notify whose amount or currency does not match this order.', '1pay-cx-payments'));
            self::finish(400, 'amount mismatch');
        }

        // Ask the API directly. A payment of the other mode is invisible to our key (404).
        $api   = new OnePayCx_Api($key);
        $check = $api->get_payment($payment_id);
        if (!$check['ok'] || !is_array($check['data'])) {
            // Transient: a non-2xx makes the platform retry later.
            self::finish(503, 'could not confirm payment');
        }
        $remote = $check['data'];
        if (!isset($remote['status']) || $remote['status'] !== 'paid' || !self::amount_matches($order, $remote)) {
            $order->add_order_note(__('1pay.cx: a notify said paid but the API does not confirm it; order left unpaid.', '1pay-cx-payments'));
            self::finish(409, 'not confirmed');
        }

        $order->payment_complete($payment_id);
        /* translators: %s: verified 1pay.cx payment identifier. */
        $order->add_order_note(sprintf(__('1pay.cx: payment confirmed (payment %s).', '1pay-cx-payments'), $payment_id));

        self::finish(200, 'ok');
    }
}
