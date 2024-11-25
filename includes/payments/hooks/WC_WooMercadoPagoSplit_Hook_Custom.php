<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class WC_WooMercadoPagoSplit_Hook_Custom extends WC_WooMercadoPagoSplit_Hook_Abstract
{
    /**
     * WC_WooMercadoPagoSplit_Hook_Custom constructor.
     * @param $payment
     */
    public function __construct($payment)
    {
        parent::__construct($payment);
    }

    /**
     * Load Hooks
     */
    public function loadHooks()
    {
        parent::loadHooks();
        if (!empty($this->payment->settings['enabled']) && $this->payment->settings['enabled'] === 'yes') {
            add_action('wp_enqueue_scripts', array($this, 'add_checkout_scripts_custom'));
            add_action('woocommerce_after_checkout_form', array($this, 'add_mp_settings_script_custom'));
            add_action('woocommerce_thankyou', array($this, 'update_mp_settings_script_custom'));
        }
    }

    /**
     * Add Discount
     */
    public function add_discount()
    {
        if (!isset($_POST['mercadopago_custom'])) {
            return;
        }
        if (is_admin() && !defined('DOING_AJAX') || is_cart()) {
            return;
        }

        $custom_checkout = sanitize_text_field($_POST['mercadopago_custom']);
        parent::add_discount_abst($custom_checkout);
    }

    /**
     * @return bool
     * @throws WC_WooMercadoPagoSplit_Exception
     */
    public function custom_process_admin_options()
    {
        return parent::custom_process_admin_options();
    }

    /**
     * Add Checkout Scripts
     */
    public function add_checkout_scripts_custom()
    {
        if (is_checkout() && $this->payment->is_available() && !get_query_var('order-received')) {
            $suffix = defined('SCRIPT_DEBUG') && SCRIPT_DEBUG ? '' : '.min';
            wp_enqueue_script(
                'woocommerce-mercadopago-split-checkout',
                plugins_url('../../assets/js/credit-card' . $suffix . '.js', plugin_dir_path(__FILE__)),
                array('jquery'),
                WC_WooMercadoPagoSplit_Constants::VERSION,
                true
            );

            wp_localize_script(
                'woocommerce-mercadopago-split-checkout',
                'wc_mercadopago_params',
                array(
                    'site_id'               => $this->payment->getOption('_site_id_v1'),
                    'public_key'            => $this->payment->getPublicKey(),
                    'coupon_mode'           => isset($this->payment->logged_user_email) ? $this->payment->coupon_mode : 'no',
                    'discount_action_url'   => $this->payment->discount_action_url,
                    'payer_email'           => esc_js($this->payment->logged_user_email),
                    'apply'                 => __('Apply', 'woocommerce-mercadopago-split'),
                    'remove'                => __('Remove', 'woocommerce-mercadopago-split'),
                    'coupon_empty'          => __('Please, inform your coupon code', 'woocommerce-mercadopago-split'),
                    'choose'                => __('To choose', 'woocommerce-mercadopago-split'),
                    'other_bank'            => __('Other bank', 'woocommerce-mercadopago-split'),
                    'discount_info1'        => __('You will save', 'woocommerce-mercadopago-split'),
                    'discount_info2'        => __('with discount of', 'woocommerce-mercadopago-split'),
                    'discount_info3'        => __('Total of your purchase:', 'woocommerce-mercadopago-split'),
                    'discount_info4'        => __('Total of your purchase with discount:', 'woocommerce-mercadopago-split'),
                    'discount_info5'        => __('*After payment approval', 'woocommerce-mercadopago-split'),
                    'discount_info6'        => __('Terms and conditions of use', 'woocommerce-mercadopago-split'),
                    'loading'               => plugins_url('../../assets/images/loading.gif', plugin_dir_path(__FILE__)),
                    'check'                 => plugins_url('../../ assets/images/check.png', plugin_dir_path(__FILE__)),
                    'error'                 => plugins_url('../../assets/images/error.png', plugin_dir_path(__FILE__))
                )
            );
        }
    }

    /**
     * Add Mercado Pago Settings Script
     */
    public function add_mp_settings_script_custom()
    {
        parent::add_mp_settings_script();
    }

    /**
     * Update Mercado Pago Settings Script
     * @param $order_id
     */
    public function update_mp_settings_script_custom($order_id)
    {
        echo parent::update_mp_settings_script($order_id);
    }
}
