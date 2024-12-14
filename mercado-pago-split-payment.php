<?php

/**
 * Plugin Name: Mercado Pago Split (WooCommerce + WCFM)
 * Plugin URI: https://juntoaqui.com.br
 * Description: Configure the payment options and accept payments with cards, ticket and money of Mercado Pago account.
 * Version: 1.0.0
 * Author: Eli Silva (hack do Mercado Pago payments for WooCommerce)
 * Author URI: https://juntoaqui.com.br
 * Text Domain: woocommerce-mercadopago-split
 * WC requires at least: 3.0.0
 * WC tested up to: 4.7.0
 * @package MercadoPago
 * @category Core
 * @author Eli Silva (hack do Mercado Pago payments for WooCommerce)
 */

// Impedir acesso direto
if (!defined('ABSPATH')) {
    exit;
}

// Verificar se WooCommerce e WCFM estão ativos
function check_required_plugins() {
    if ( ! is_plugin_active( 'woocommerce/woocommerce.php' ) ) {
        // WooCommerce não está ativo
        deactivate_plugins( plugin_basename( __FILE__ ) );
        add_action( 'admin_notices', 'woocommerce_plugin_not_active_notice' );
        return;
    }

    if ( ! is_plugin_active( 'wc-frontend-manager/wcfm.php' ) ) {
        // WCFM não está ativo
        deactivate_plugins( plugin_basename( __FILE__ ) );
        add_action( 'admin_notices', 'wcfm_plugin_not_active_notice' );
        return;
    }
}

add_action('admin_init', 'check_required_plugins');

// Exibir aviso se o WooCommerce não estiver ativo
function woocommerce_plugin_not_active_notice() {
    echo '<div class="error"><p><strong>Mercado Pago Split (WooCommerce + WCFM)</strong> requer o plugin WooCommerce. Por favor, instale e ative o WooCommerce.</p></div>';
}

// Exibir aviso se o WCFM não estiver ativo
function wcfm_plugin_not_active_notice() {
    echo '<div class="error"><p><strong>Mercado Pago Split (WooCommerce + WCFM)</strong> requer o plugin WCFM Marketplace. Por favor, instale e ative o WCFM Marketplace.</p></div>';
}

// Adicionar Gateway de Pagamento ao WCFM
add_filter('wcfm_marketplace_withdrwal_payment_methods', function ($payment_methods) {
    $payment_methods['mercado_pago'] = 'Mercado Pago';
    return $payment_methods;
});

// Adicionar os campos do Mercado Pago ao WCFM
add_filter('wcfm_marketplace_settings_fields_withdrawal_payment_keys', function ($payment_keys, $wcfm_withdrawal_options) {
    $gateway_slug = 'mercado_pago';

    $payment_mercado_pago_keys = [
        "withdrawal_{$gateway_slug}_connect" => [
            'label' => __('Clique aqui para conectar ao Mercado Pago', 'wc-multivendor-marketplace'),
            'type' => 'html',
            'class' => "wcfm_ele withdrawal_mode withdrawal_mode_live withdrawal_mode_{$gateway_slug}",
            'label_class' => "wcfm_title withdrawal_mode withdrawal_mode_live withdrawal_mode_{$gateway_slug}",
            'html' => sprintf(
                '<a href="%s" class="button wcfm-action-btn" target="_blank">%s</a>',
                'https://auth.mercadopago.com/authorization?client_id=6591097965975471&response_type=code&platform_id=mp&state=' . uniqid() . '&redirect_uri=https://juntoaqui.com.br/gerenciar-loja/settings/',
                __('Clique aqui para conectar ao Mercado Pago', 'wc-multivendor-marketplace')
            ),
        ],
    ];

    if (current_user_can('administrator')) {
        $admin_mercado_pago_keys = [
            "withdrawal_{$gateway_slug}_client_id" => [
                'label' => __('Client ID', 'wc-multivendor-marketplace'),
                'type' => 'text',
                'value' => get_option('mercado_pago_client_id', ''), 
                'desc' => __('Adicione seu Client ID aqui.', 'wc-multivendor-marketplace'),
            ],
            "withdrawal_{$gateway_slug}_client_secret" => [
                'label' => __('Client Secret', 'wc-multivendor-marketplace'),
                'type' => 'text',
                'value' => get_option('mercado_pago_client_secret', ''), 
                'desc' => __('Adicione seu Client Secret aqui.', 'wc-multivendor-marketplace'),
            ],
            "withdrawal_{$gateway_slug}_redirect_url" => [
                'label' => __('URL de Redirecionamento', 'wc-multivendor-marketplace'),
                'type' => 'text',
                'value' => 'https://juntoaqui.com.br/gerenciar-loja/settings/', 
                'desc' => __('Esta é a URL de redirecionamento para o Mercado Pago.', 'wc-multivendor-marketplace'),
            ],
        ];

        $payment_keys = array_merge($payment_keys, $admin_mercado_pago_keys);
    }

    $payment_keys = array_merge($payment_keys, $payment_mercado_pago_keys);
    return $payment_keys;
}, 50, 2);

// Adicionar Campo de Token OAuth para o Vendedor
add_filter('wcfm_marketplace_settings_fields_billing', function ($vendor_billing_fields, $vendor_id) {
    $gateway_slug = 'mercado_pago';
    $vendor_data = get_user_meta($vendor_id, 'wcfmmp_profile_settings', true);
    if (!$vendor_data) $vendor_data = [];

    $mercado_pago_token = isset($vendor_data['payment'][$gateway_slug]['token']) ? esc_attr($vendor_data['payment'][$gateway_slug]['token']) : '';

    $vendor_mercado_pago_billing_fields = [
        $gateway_slug => [
            'label' => __('Mercado Pago Token', 'wc-frontend-manager'),
            'name' => 'payment[' . $gateway_slug . '][token]',
            'type' => 'text',
            'value' => $mercado_pago_token,
            'custom_attributes' => ['readonly' => 'readonly'],
            'desc' => sprintf('<a href="%s" target="_blank">%s</a>', 
                'https://auth.mercadopago.com/authorization?client_id=6591097965975471&response_type=code&platform_id=mp&state=' . uniqid() . '&redirect_uri=https://juntoaqui.com.br/gerenciar-loja/settings/', 
                __('Clique aqui para conectar ao Mercado Pago', 'wc-multivendor-marketplace')),
        ],
    ];

    return array_merge($vendor_billing_fields, $vendor_mercado_pago_billing_fields);
}, 50, 2);

// Função para Refresh Token
function refresh_mercado_pago_token($client_id, $client_secret, $refresh_token) {
    $url = 'https://api.mercadopago.com/oauth/token';
    $data = [
        'grant_type' => 'refresh_token',
        'client_id' => $client_id,
        'client_secret' => $client_secret,
        'refresh_token' => $refresh_token,
    ];

    $response = wp_remote_post($url, [
        'body' => $data,
        'headers' => ['Content-Type' => 'application/x-www-form-urlencoded'],
    ]);

    if (is_wp_error($response)) {
        return false;
    }

    $response_body = json_decode(wp_remote_retrieve_body($response), true);
    if (isset($response_body['access_token'])) {
        update_option('mercado_pago_access_token', $response_body['access_token']);
        update_option('mercado_pago_refresh_token', $response_body['refresh_token']);
        return true;
    }

    return false;
}

// Exemplo de uso do refresh token
add_action('init', function() {
    $client_id = get_option('mercado_pago_client_id');
    $client_secret = get_option('mercado_pago_client_secret');
    $refresh_token = get_option('mercado_pago_refresh_token');

    if ($refresh_token) {
        refresh_mercado_pago_token($client_id, $client_secret, $refresh_token);
    }
});

// Implementação do Split de Pagamento
class WCFMmp_Gateway_Mercado_Pago {
    public function process_payment($withdrawal_id, $vendor_id, $withdraw_amount, $withdraw_charges, $transaction_mode = 'auto') {
        global $WCFMmp;

        $this->vendor_id = $vendor_id;
        $this->withdraw_amount = $withdraw_amount;
        $this->currency = get_woocommerce_currency();
        $this->transaction_mode = $transaction_mode;
        $this->receiver_token = get_user_meta($this->vendor_id, 'wcfmmp_profile_settings', true)['payment']['mercado_pago']['token'];

        if (empty($this->receiver_token)) {
            return false;
        }

        $url = 'https://api.mercadopago.com/v1/payments';
        $headers = [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $this->receiver_token
        ];

        // Defina os valores para o split de pagamento
        $marketplace_percentage = 0.1; // 10% para o marketplace
        $vendor_amount = $this->withdraw_amount * (1 - $marketplace_percentage);
        $marketplace_amount = $this->withdraw_amount * $marketplace_percentage;

        $body = [
            'transaction_amount' => $this->withdraw_amount,
            'currency_id' => $this->currency,
            'description' => 'Retirada de fundos do vendedor',
            'payer' => [
                'email' => get_user_meta($this->vendor_id, 'billing_email', true)
            ],
            'additional_info' => [
                'items' => [
                    [
                        'title' => 'Venda no Marketplace',
                        'quantity' => 1,
                        'unit_price' => $this->withdraw_amount,
                    ]
                ],
                'split' => [
                    [
                        'recipient_id' => 'RECIPIENT_ID_DO_VENDEDOR', // ID do vendedor
                        'amount' => $vendor_amount,
                    ],
                    [
                        'recipient_id' => 'RECIPIENT_ID_DO_MARKETPLACE', // ID do marketplace
                        'amount' => $marketplace_amount,
                    ]
                ]
            ]
        ];

        $response = wp_remote_post($url, [
            'headers' => $headers,
            'body' => json_encode($body)
        ]);

        if (is_wp_error($response)) {
            return false;
        }

        $response_body = wp_remote_retrieve_body($response);
        $response_data = json_decode($response_body, true);

        if (isset($response_data['status']) && $response_data['status'] == 'approved') {
            $WCFMmp->withdrawal->add_withdrawal_payment_success($withdrawal_id);
            return true;
        }

        return false;
    }
}
