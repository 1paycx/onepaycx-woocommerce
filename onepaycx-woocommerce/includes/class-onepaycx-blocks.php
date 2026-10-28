<?php
/**
 * Makes the gateway available in the WooCommerce block-based (React) checkout,
 * not just the classic shortcode checkout.
 */

if (!defined('ABSPATH')) {
    exit;
}

use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;

final class OnePayCx_Blocks_Support extends AbstractPaymentMethodType {
    protected $name = 'onepaycx';

    // Must be `protected` (or weaker) to match the parent — narrowing to private is
    // a compile-time fatal on the versions of WooCommerce that declare it protected.
    /** @var array */
    protected $settings = array();

    public function initialize() {
        $this->settings = get_option('woocommerce_onepaycx_settings', array());
    }

    public function is_active() {
        return !empty($this->settings['enabled']) && 'yes' === $this->settings['enabled'];
    }

    public function get_payment_method_script_handles() {
        wp_register_script(
            'onepaycx-blocks',
            plugins_url('../assets/blocks/onepaycx.js', __FILE__),
            array('wc-blocks-registry', 'wp-element', 'wp-html-entities'),
            ONEPAYCX_VERSION,
            true
        );
        return array('onepaycx-blocks');
    }

    public function get_payment_method_data() {
        return array(
            'title'       => isset($this->settings['title']) ? $this->settings['title'] : __('Pay by card or crypto', '1pay-cx-payments'),
            'description' => isset($this->settings['description']) ? $this->settings['description'] : '',
            'supports'    => array('products'),
        );
    }
}
