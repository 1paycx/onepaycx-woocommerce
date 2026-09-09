<?php
/**
 * Plugin Name: 1pay.cx for WooCommerce
 * Plugin URI: https://1pay.cx/docs#woocommerce
 * Description: Accept card, Bitcoin and Monero payments in WooCommerce with 1pay.cx. Buyers pay on a hosted checkout; you settle in crypto. Paste your API key and go live.
 * Version: 1.1.0
 * Author: 1pay.cx
 * Author URI: https://1pay.cx
 * License: GPL-2.0-or-later
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * WC requires at least: 7.0
 * Text Domain: onepaycx
 *
 * All traffic goes to 1pay.cx / api.1pay.cx only.
 */

if (!defined('ABSPATH')) {
    exit;
}

define('ONEPAYCX_VERSION', '1.1.0');
define('ONEPAYCX_API_BASE', 'https://api.1pay.cx');   // REST API host (calls /v1/...)
define('ONEPAYCX_CHECKOUT_BASE', 'https://1pay.cx');  // hosted checkout host (/pay/<id>)
define('ONEPAYCX_PLUGIN_FILE', __FILE__);
define('ONEPAYCX_MIN_PHP', '7.4');
define('ONEPAYCX_MIN_WC', '7.0');

/**
 * Show a dismissible-free error notice in wp-admin. Used for every "we can't run
 * here" case so the plugin degrades to a message instead of a fatal.
 */
function onepaycx_admin_notice($message) {
    add_action('admin_notices', function () use ($message) {
        echo '<div class="notice notice-error"><p>' . esc_html($message) . '</p></div>';
    });
}

/**
 * Returns a human-readable reason the plugin can't run (PHP too old, WooCommerce
 * missing or too old), or '' when the environment is fine.
 */
function onepaycx_requirements_error() {
    if (version_compare(PHP_VERSION, ONEPAYCX_MIN_PHP, '<')) {
        return sprintf('1pay.cx for WooCommerce requires PHP %s or newer — this server runs PHP %s. Ask your host to upgrade PHP.', ONEPAYCX_MIN_PHP, PHP_VERSION);
    }
    if (!class_exists('WooCommerce')) {
        return '1pay.cx for WooCommerce requires WooCommerce to be installed and active.';
    }
    if (defined('WC_VERSION') && version_compare(WC_VERSION, ONEPAYCX_MIN_WC, '<')) {
        return sprintf('1pay.cx for WooCommerce requires WooCommerce %s or newer — this store runs WooCommerce %s.', ONEPAYCX_MIN_WC, WC_VERSION);
    }
    return '';
}

/**
 * True only if it's safe to declare a subclass of $parent_class — i.e. the class
 * exists and every abstract method it declares is one we implement ($implemented).
 *
 * This is the guard a try/catch cannot provide: "class X contains abstract method
 * Y and must be declared abstract" is a compile-time fatal thrown when our file is
 * required, not a catchable exception. By inspecting the parent's abstract methods
 * FIRST (via reflection, without loading our subclass), we skip loading entirely
 * when a newer WooCommerce added a method we don't implement — turning a fatal into
 * a no-op. This is what makes the plugin safe across WooCommerce versions.
 */
function onepaycx_can_extend($parent_class, array $implemented) {
    if (!class_exists($parent_class)) {
        return false;
    }
    try {
        $ref = new ReflectionClass($parent_class);
        foreach ($ref->getMethods(ReflectionMethod::IS_ABSTRACT) as $method) {
            if (!in_array($method->getName(), $implemented, true)) {
                return false; // parent requires a method we don't implement — don't risk a fatal
            }
        }
    } catch (\Throwable $e) {
        return false;
    }
    return true;
}

/**
 * Refuse activation — with a clear message — when the environment can't support
 * the plugin, instead of activating and then fataling on the next page load.
 * This is what stops a bad environment from ever white-screening the store.
 */
register_activation_hook(__FILE__, function () {
    if (version_compare(PHP_VERSION, ONEPAYCX_MIN_PHP, '<')) {
        wp_die(
            esc_html(sprintf('1pay.cx for WooCommerce needs PHP %s+ (this server has %s). Upgrade PHP in your hosting panel, then activate again.', ONEPAYCX_MIN_PHP, PHP_VERSION)),
            'Activation blocked',
            array('back_link' => true)
        );
    }
    $wc_active = class_exists('WooCommerce');
    if (!$wc_active && function_exists('is_plugin_active')) {
        $wc_active = is_plugin_active('woocommerce/woocommerce.php');
    }
    if (!$wc_active) {
        wp_die(
            esc_html('1pay.cx for WooCommerce needs WooCommerce installed and active first. Activate WooCommerce, then activate this plugin.'),
            'Activation blocked',
            array('back_link' => true)
        );
    }
});

/**
 * Boot the gateway. Everything that touches WooCommerce is loaded here, behind a
 * requirements gate and wrapped in try/catch, so a version mismatch degrades to
 * an admin notice — never a fatal that takes the site down.
 */
add_action('plugins_loaded', function () {
    $error = onepaycx_requirements_error();
    if ($error !== '') {
        onepaycx_admin_notice($error);
        return;
    }

    // Canonical gate: only declare our gateway once WooCommerce's base class is
    // loaded AND it declares no abstract method we don't implement (guards against
    // a future WooCommerce changing the gateway contract).
    if (!onepaycx_can_extend('WC_Payment_Gateway', array('process_payment'))) {
        onepaycx_admin_notice('1pay.cx for WooCommerce is not compatible with this version of WooCommerce. Please update the 1pay.cx plugin.');
        return;
    }

    try {
        require_once __DIR__ . '/includes/class-onepaycx-api.php';
        require_once __DIR__ . '/includes/class-onepaycx-gateway.php';
    } catch (\Throwable $e) {
        onepaycx_admin_notice('1pay.cx for WooCommerce could not load: ' . $e->getMessage());
        return;
    }

    add_filter('woocommerce_payment_gateways', function ($gateways) {
        $gateways[] = 'OnePayCx_Gateway';
        return $gateways;
    });
});

// Declare compatibility with WooCommerce High-Performance Order Storage (HPOS).
// Guard the method too — older WooCommerce has the class but not this method.
add_action('before_woocommerce_init', function () {
    if (class_exists(\Automattic\WooCommerce\Utilities\FeaturesUtil::class)
        && method_exists(\Automattic\WooCommerce\Utilities\FeaturesUtil::class, 'declare_compatibility')) {
        try {
            \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', ONEPAYCX_PLUGIN_FILE, true);
        } catch (\Throwable $e) {
            // Non-fatal: HPOS declaration is optional.
        }
    }
});

// Register the block-based checkout integration — optional, and fully isolated so
// a Blocks version mismatch can never break the classic checkout or the site.
add_action('woocommerce_blocks_loaded', function () {
    // Pre-check the abstract contract before declaring our subclass. If this
    // WooCommerce's AbstractPaymentMethodType requires a method we don't implement
    // (the exact thing that fataled on WooCommerce 11), skip the block integration
    // entirely — the classic-checkout gateway is unaffected. This CANNOT be done
    // with try/catch, because the missing-method fatal isn't a catchable exception.
    if (!onepaycx_can_extend(
        'Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType',
        array('initialize', 'is_active', 'get_payment_method_script_handles', 'get_payment_method_data')
    )) {
        return;
    }
    try {
        require_once __DIR__ . '/includes/class-onepaycx-blocks.php';
        add_action('woocommerce_blocks_payment_method_type_registration', function ($registry) {
            try {
                $registry->register(new OnePayCx_Blocks_Support());
            } catch (\Throwable $e) {
                // Block checkout integration is optional; never let it break the site.
            }
        });
    } catch (\Throwable $e) {
        // Ignore — the classic checkout gateway still works without blocks.
    }
});

// "Settings" link on the Plugins list row.
add_filter('plugin_action_links_' . plugin_basename(__FILE__), function ($links) {
    $url = admin_url('admin.php?page=wc-settings&tab=checkout&section=onepaycx');
    array_unshift($links, '<a href="' . esc_url($url) . '">' . esc_html__('Settings', 'onepaycx') . '</a>');
    return $links;
});
