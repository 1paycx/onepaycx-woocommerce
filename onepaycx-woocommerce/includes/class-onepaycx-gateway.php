<?php
/**
 * 1pay.cx WooCommerce payment gateway.
 *
 * Flow: checkout -> create payment on 1pay.cx -> redirect buyer to the hosted
 * checkout -> buyer pays by card -> 1pay.cx POSTs a signed notify to our
 * wc-api endpoint -> we complete the order. The notify is the source of truth.
 */

if (!defined('ABSPATH')) {
    exit;
}

class OnePayCx_Gateway extends WC_Payment_Gateway {
    /** @var string The merchant's 1pay.cx API key. Declared to avoid a PHP 8.2 dynamic-property notice. */
    public $api_key = '';

    /** @var bool Send buyers straight to the card processor (skip the 1pay.cx page). */
    public $direct = false;

    public function __construct() {
        $this->id                 = 'onepaycx';
        $this->method_title       = '1pay.cx';
        $this->method_description  = __('Accept card, Bitcoin and Monero payments with 1pay.cx. Buyers pay on a hosted checkout; card sales settle in USDC and crypto lands in your 1pay.cx balance. Paste your API key to go live.', 'onepaycx');
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
                'title'   => __('Enable/Disable', 'onepaycx'),
                'type'    => 'checkbox',
                'label'   => __('Enable 1pay.cx card payments', 'onepaycx'),
                'default' => 'no',
            ),
            'title' => array(
                'title'       => __('Title', 'onepaycx'),
                'type'        => 'text',
                'description' => __('What buyers see at checkout.', 'onepaycx'),
                'default'     => __('Pay by card or crypto', 'onepaycx'),
                'desc_tip'    => true,
            ),
            'description' => array(
                'title'   => __('Description', 'onepaycx'),
                'type'    => 'textarea',
                'default' => __('Pay securely by card, Bitcoin or Monero. You will be redirected to complete your payment.', 'onepaycx'),
            ),
            'api_key' => array(
                'title'       => __('API key', 'onepaycx'),
                'type'        => 'password',
                'description' => __('From your 1pay.cx dashboard: Settings -> API keys. Looks like pk_xxx.yyy', 'onepaycx'),
                'default'     => '',
                'desc_tip'    => true,
            ),
            'direct_checkout' => array(
                'title'       => __('Direct to card processor', 'onepaycx'),
                'type'        => 'checkbox',
                'label'       => __('Skip the 1pay.cx payment page — buyers go straight to the card processor', 'onepaycx'),
                'description' => __('Requires the buyer\'s email, which WooCommerce always collects at checkout (the billing email is passed automatically). If an order has no email, the buyer sees the standard payment page instead. Note: direct mode skips the 1pay.cx page, so buyers will not be offered Bitcoin or Monero.', 'onepaycx'),
                'default'     => 'no',
            ),
        );
    }

    /**
     * On save: validate the API key against GET /v1/me and cache the webhook
     * secret (used to verify notify signatures). A bad key surfaces an error.
     */
    public function process_admin_options() {
        $saved = parent::process_admin_options();

        $key = $this->get_option('api_key');
        if ($key) {
            $api = new OnePayCx_Api($key);
            $res = $api->me();
            if ($res['ok'] && !empty($res['data']['webhookSecret'])) {
                update_option('onepaycx_webhook_secret', $res['data']['webhookSecret']);
                update_option('onepaycx_merchant_id', isset($res['data']['id']) ? $res['data']['id'] : '');
                WC_Admin_Settings::add_message(__('1pay.cx: API key verified. You are ready to accept card payments.', 'onepaycx'));
            } else {
                WC_Admin_Settings::add_error(sprintf(
                    /* translators: %s: upstream error */
                    __('1pay.cx: could not verify the API key (%s). Double-check it in your 1pay.cx dashboard.', 'onepaycx'),
                    $res['error'] ? $res['error'] : __('unknown error', 'onepaycx')
                ));
            }
        }

        return $saved;
    }

    /** Card refunds aren't supported — show that in the admin instead of a broken button. */
    public function can_refund_order($order) {
        return false;
    }

    public function process_payment($order_id) {
        $order = wc_get_order($order_id);
        if (!$order) {
            return array('result' => 'failure');
        }

        $api = new OnePayCx_Api($this->api_key);
        $res = $api->create_payment(array(
            'amount'    => (float) $order->get_total(),
            'currency'  => $order->get_currency(),
            'email'     => $order->get_billing_email(),
            'reference' => (string) $order_id,
            'notifyUrl' => WC()->api_request_url('onepaycx'),
            'redirectUrl' => $this->get_return_url($order),
        ));

        if (!$res['ok'] || empty($res['data']['checkoutUrl'])) {
            $msg = $res['error'] ? $res['error'] : __('payment could not be started', 'onepaycx');
            wc_add_notice(sprintf(__('1pay.cx error: %s', 'onepaycx'), $msg), 'error');
            $order->add_order_note(sprintf(__('1pay.cx: create payment failed (%s).', 'onepaycx'), $msg));
            return array('result' => 'failure');
        }

        $payment_id = isset($res['data']['id']) ? (string) $res['data']['id'] : '';
        $order->update_meta_data('_onepaycx_payment_id', $payment_id);
        $order->update_status('pending', __('Awaiting 1pay.cx card payment.', 'onepaycx'));
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
     * Signed notify from the settlement layer. Verify the signature, then
     * complete the matching order (idempotent).
     */
    public function handle_webhook() {
        $body      = file_get_contents('php://input');
        $signature = isset($_SERVER['HTTP_X_1PAY_SIGNATURE']) ? $_SERVER['HTTP_X_1PAY_SIGNATURE'] : '';
        $secret    = get_option('onepaycx_webhook_secret');

        if (!$secret || !$signature) {
            status_header(400);
            exit('missing signature');
        }

        $expected = hash_hmac('sha256', $body, $secret);
        if (!hash_equals($expected, $signature)) {
            status_header(401);
            exit('bad signature');
        }

        $payload = json_decode($body, true);
        $data    = isset($payload['data']) ? $payload['data'] : array();
        $event   = isset($payload['event']) ? $payload['event'] : '';

        if ($event !== 'payment.paid' || empty($data['reference'])) {
            status_header(200);
            exit('ignored');
        }

        $order = wc_get_order((int) $data['reference']);
        if (!$order) {
            status_header(404);
            exit('order not found');
        }

        // Idempotent: a repeated notify is a no-op.
        if ($order->is_paid()) {
            status_header(200);
            exit('already paid');
        }

        $payment_id = isset($data['paymentId']) ? (string) $data['paymentId'] : '';
        $order->payment_complete($payment_id);
        $order->add_order_note(sprintf(__('1pay.cx: card payment confirmed (payment %s).', 'onepaycx'), $payment_id));

        status_header(200);
        exit('ok');
    }
}
