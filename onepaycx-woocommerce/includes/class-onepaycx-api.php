<?php
/**
 * Tiny HTTP client for the 1pay.cx REST API. Never logs or echoes the key/secret.
 */

if (!defined('ABSPATH')) {
    exit;
}

class OnePayCx_Api {
    /** @var string */
    private $key;

    public function __construct($key) {
        $this->key = (string) $key;
    }

    /** GET /v1/me — validates the key and returns { id, email, webhookSecret }. */
    public function me() {
        return $this->request('GET', '/v1/me');
    }

    /** POST /v1/payments — create a hosted-checkout payment. */
    public function create_payment(array $body) {
        return $this->request('POST', '/v1/payments', $body);
    }

    /**
     * @return array{ok:bool, status:int, data:mixed, error:string}
     */
    private function request($method, $path, $body = null) {
        $args = array(
            'method'  => $method,
            'timeout' => 20,
            'headers' => array(
                'Authorization' => 'Bearer ' . $this->key,
                'Accept'        => 'application/json',
            ),
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
