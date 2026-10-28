<?php
/**
 * Tiny HTTP client for the 1pay.cx REST API. Never logs or echoes the key/secret.
 *
 * Every method returns array{ok:bool, status:int, data:mixed, error:string}.
 * Registration, the connect-code exchange and the public method catalogue are
 * unauthenticated (no Authorization header); everything else sends the store's key.
 */

if (!defined('ABSPATH')) {
    exit;
}

class OnePayCx_Api {
    /** Seconds the public method catalogue is cached in the onepaycx_catalogue transient. */
    const CATALOGUE_TTL = 600;

    /** @var string */
    private $key;

    public function __construct($key) {
        $this->key = (string) $key;
    }

    /** GET /v1/me — validates the key and returns { id, livemode, webhookSecret, walletOnly, payoutsWaiting } (secret of the key's mode). */
    public function me() {
        return $this->request('GET', '/v1/me');
    }

    /** POST /v1/payments — create a hosted-checkout payment. */
    public function create_payment(array $body) {
        return $this->request('POST', '/v1/payments', $body);
    }

    /** GET /v1/payments/:id — the payment as the API sees it (only visible to a key of the same mode). */
    public function get_payment($id) {
        return $this->request('GET', '/v1/payments/' . rawurlencode((string) $id));
    }

    /**
     * PUT /wallet-stores/settings — a wallet-only store's payout addresses, methods and
     * coins. Returns { accepted: string[], rejected: {asset, reason}[] }; 403
     * not_wallet_store once the store has become a 1pay.cx account.
     */
    public function save_store_settings(array $body): array {
        return $this->request('PUT', '/wallet-stores/settings', $body);
    }

    /** POST /wallet-stores/claim-link — a short-lived https://1pay.cx/connect?claim=… link for this store. */
    public function claim_link(string $return_url): array {
        return $this->request('POST', '/wallet-stores/claim-link', array('returnUrl' => $return_url));
    }

    /** GET /v1/methods — the card methods and coins this account allows, each with `accepted`. */
    public function methods(): array {
        return $this->request('GET', '/v1/methods');
    }

    /**
     * POST /wallet-stores (no key yet) — registers a wallet-only store:
     * { evmAddress, siteUrl, storeName?, platform: 'woocommerce', pluginVersion? } -> { merchantId, liveKey, testKey }.
     */
    public static function register(array $body): array {
        return self::send('POST', '/wallet-stores', $body, null);
    }

    /** POST /wallet-stores/connect/exchange — trades a one-time connect code for { merchantId, liveKey, testKey }. */
    public static function exchange_code(string $code): array {
        return self::send('POST', '/wallet-stores/connect/exchange', array('code' => $code), null);
    }

    /** GET /payment-methods — the public card-method and coin catalogue, cached for 10 minutes. */
    public static function catalogue(): array {
        $cached = get_transient('onepaycx_catalogue');
        if (is_array($cached)) {
            return array('ok' => true, 'status' => 200, 'data' => $cached, 'error' => '');
        }
        $res = self::send('GET', '/payment-methods', null, null);
        if ($res['ok'] && is_array($res['data'])) {
            set_transient('onepaycx_catalogue', $res['data'], self::CATALOGUE_TTL);
        }
        return $res;
    }

    /**
     * @return array{ok:bool, status:int, data:mixed, error:string}
     */
    private function request($method, $path, $body = null) {
        return self::send($method, $path, $body, $this->key);
    }

    /**
     * One request to ONEPAYCX_API_BASE. A null $key sends no Authorization header.
     *
     * @return array{ok:bool, status:int, data:mixed, error:string}
     */
    private static function send($method, $path, $body, $key) {
        $headers = array(
            'Accept'     => 'application/json',
            // Lets 1pay.cx record which integration created the payment.
            'User-Agent' => 'onepaycx-woocommerce/' . ONEPAYCX_VERSION,
        );
        if ($key !== null) {
            $headers['Authorization'] = 'Bearer ' . $key;
        }
        $args = array(
            'method'  => $method,
            'timeout' => 20,
            'headers' => $headers,
        );
        if ($body !== null) {
            $args['headers']['Content-Type'] = 'application/json';
            $args['body'] = wp_json_encode($body);
        }

        $res = wp_remote_request(ONEPAYCX_API_BASE . $path, $args);
        if (is_wp_error($res)) {
            return array('ok' => false, 'status' => 0, 'data' => null, 'error' => $res->get_error_message());
        }

        $status = (int) wp_remote_retrieve_response_code($res);
        $data   = json_decode(wp_remote_retrieve_body($res), true);
        $ok     = $status >= 200 && $status < 300;
        $error  = $ok ? '' : (is_array($data) && isset($data['error']) ? (string) $data['error'] : ('HTTP ' . $status));

        return array('ok' => $ok, 'status' => $status, 'data' => $data, 'error' => $error);
    }
}
