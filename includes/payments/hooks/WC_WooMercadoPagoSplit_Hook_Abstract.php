<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly
}

/**
 * Class WC_WooMercadoPagoSplit_Hook_Abstract
 */
abstract class WC_WooMercadoPagoSplit_Hook_Abstract
{
    public $payment;
    public $class;
    public $mpInstance;
    public $publicKey;
    public $testUser ;
    public $siteId;

    /**
     * WC_WooMercadoPagoSplit_Hook_Abstract constructor.
     *
     * @param WC_Payment_Gateway $payment
     */
    public function __construct( WC_Payment_Gateway $payment )
    {
        $this->payment = $payment;
        $this->class = get_class( $payment );
        $this->mpInstance = $payment->mp;
        $this->publicKey = $payment->getPublicKey();
        $this->testUser  = get_option( '_test_user_v1' );
        $this->siteId = get_option( '_site_id_v1' );

        $this->loadHooks();
    }

    /**
     * Load Hooks
     */
    public function loadHooks()
    {
        add_action( 'woocommerce_update_options_payment_gateways_' . $this->payment->id, [ $this, 'custom_process_admin_options' ] );
        add_action( 'woocommerce_cart_calculate_fees', [ $this, 'add_discount' ], 10 );
        add_filter( 'woocommerce_gateway_title', [ $this, 'get_payment_method_title' ], 10, 2 );

        add_action( 'admin_notices', function() {
            WC_WooMercadoPagoSplit_Helpers_CurrencyConverter::getInstance()->notices( $this->payment );
        });

        if ( ! empty( $this->payment->settings['enabled'] ) && $this->payment->settings['enabled'] === 'yes' ) {
            add_action( 'woocommerce_after_checkout_form', [ $this, 'add_mp_settings_script' ] );
            add_action( 'woocommerce_thankyou', [ $this, 'update_mp_settings_script' ] );
        }
    }

    /**
     * Add Discount
     *
     * @param array $checkout
     */
    public function add_discount_abst( array $checkout )
    {
        if ( isset( $checkout['discount'], $checkout['coupon_code'] ) && 
             ! empty( $checkout['discount'] ) && 
             ! empty( $checkout['coupon_code'] ) && 
             $checkout['discount'] > 0 && 
             WC()->session->chosen_payment_method === $this->payment->id ) {

            $this->payment->log->write_log( __FUNCTION__, $this->class . ' trying to apply discount...' );

            $value = ( $this->payment->site_data['currency'] === 'COP' || $this->payment->site_data['currency'] === 'CLP' ) 
                ? floor( $checkout['discount'] / $checkout['currency_ratio'] ) 
                : floor( $checkout['discount'] / $checkout['currency_ratio'] * 100 ) / 100;

            global $woocommerce;

            if ( apply_filters( 'wc_mercadopago_custommodule_apply_discount', 0 < $value, $woocommerce->cart ) ) {
                $woocommerce->cart->add_fee( sprintf( __( 'Discount for coupon %s', 'woocommerce-mercadopago-split' ), esc_attr( $checkout['campaign'] ) ), ( $value * -1 ), false );
            }
        }
    }

    /**
     * Get Payment Method Title
     *
     * @param string $title
     * @param string $id
     * @return string
     */
    public function get_payment_method_title( $title, $id )
    {
        if ( ! preg_match( '/woo-mercado-pago-s plit/', $id ) ) {
            return $title;
        }

        if ( $id !== $this->payment->id ) {
            return $title;
        }

        if ( ! is_checkout() && !( defined( 'DOING_AJAX' ) && DOING_AJAX ) ) {
            return $title;
        }

        if ( $title !== $this->payment->title && ( $this->payment->commission === 0 && $this->payment->gateway_discount === 0 ) ) {
            return $title;
        }

        if ( ! is_numeric( $this->payment->gateway_discount ) || $this->payment->commission > 99 || $this->payment->gateway_discount > 99 ) {
            return $title;
        }

        $total = (float) WC()->cart->subtotal;
        $price_discount = $total * ( $this->payment->gateway_discount / 100 );
        $price_commission = $total * ( $this->payment->commission / 100 );

        if ( $this->payment->gateway_discount > 0 && $this->payment->commission > 0 ) {
            $title .= ' (' . __( 'discount of', 'woocommerce-mercadopago-split' ) . ' ' . strip_tags( wc_price( $price_discount ) ) . __( ' and fee of', 'woocommerce-mercadopago-split' ) . ' ' . strip_tags( wc_price( $price_commission ) ) . ')';
        } elseif ( $this->payment->gateway_discount > 0 ) {
            $title .= ' (' . __( 'discount of', 'woocommerce-mercadopago-split' ) . ' ' . strip_tags( wc_price( $price_discount ) ) . ')';
        } elseif ( $this->payment->commission > 0 ) {
            $title .= ' (' . __( 'fee of', 'woocommerce-mercadopago-split' ) . ' ' . strip_tags( wc_price( $price_commission ) ) . ')';
        }

        return $title;
    }

    /**
     * Add MP Settings Script
     */
    public function add_mp_settings_script()
    {
        if ( ! empty( $this->publicKey ) && ! $this->testUser  && isset( WC()->payment_gateways ) ) {
            $woo = WC_WooMercadoPagoSplit_Module::woocommerce_instance();
            $gateways = $woo->payment_gateways->get_available_payment_gateways();

            $available_payments = array_map( function( $gateway ) {
                return $gateway->id;
            }, $gateways );

            $available_payments = str_replace( '-', '_', implode( ', ', $available_payments ) );
            $logged_user_email = wp_get_current_user()->ID !== 0 ? wp_get_current_user()->user_email : null;
        }
    }

    /**
     * Update MP Settings Script
     *
     * @param int $order_id
     * @return string|void
     */
    public function update_mp_settings_script( int $order_id )
    {
        if ( ! empty( $this->publicKey ) && ! $this->testUser  ) {
            // Implement the script update logic here if needed
        }
    }

    /**
     * Custom Process Admin Options
     *
     * @return bool
     * @throws WC_WooMercadoPagoSplit_Exception
     */
    public function custom_process_admin_options()
    {
        $oldData = [];
        $valueCredentialProduction = null;

        $this->payment->init_settings();
        $post_data = $this->payment->get_post_data();

        foreach ( $this->payment->get_form_fields() as $key => $field ) {
            if ( 'title' !== $this->payment->get_field_type( $field ) ) {
                $value = $this->payment->get_field_value( $key, $field, $post_data );
                $oldData[$key] = $this->payment->settings[$key] ?? null;

                if ( $key === 'checkout_credential_prod' ) {
                    $valueCredentialProduction = $value;
                }

                $commonConfigs = $this->payment->getCommonConfigs();
                if ( in_array( $key, $commonConfigs, true ) ) {
                    if ( $this->validateCredentials( $key, $value, $valueCredentialProduction ) ) {
                        continue;
                    }
                    update_option( $key, $value, true );
                }

                $this->payment->settings[$key] = $this->payment->get_field_value( $key, $field, $post_data );
            }
        }

        $result = update_option( $this->payment->get_option_key(), apply_filters( 'woocommerce_settings_api_sanit ized_fields_' . $this->payment->id, $this->payment->settings ) );

        WC_WooMercadoPagoSplit_Helpers_CurrencyConverter::getInstance()->scheduleNotice(
            $this->payment,
            $oldData,
            $this->payment->settings
        );

        return $result;
    }

    /**
     * Validate Credentials
     *
     * @param string $key
     * @param string $value
     * @param string|null $valueCredentialProduction
     * @return bool
     * @throws WC_WooMercadoPagoSplit_Exception
     */
    private function validateCredentials( string $key, string $value, ?string $valueCredentialProduction = null ): bool
    {
        if ( $key === '_mp_public_key_test' && $value === $this->payment->mp_public_key_test ) {
            return true;
        }

        if ( $key === '_mp_access_token_test' && $value === $this->payment->mp_access_token_test ) {
            return true;
        }

        if ( $key === '_mp_public_key_prod' && $value === $this->payment->mp_public_key_prod ) {
            return true;
        }

        if ( $key === '_mp_access_token_prod' && $value === $this->payment->mp_access_token_prod ) {
            return true;
        }

        if ( $this->validatePublicKey( $key, $value ) ) {
            return true;
        }

        if ( $this->validateAccessToken( $key, $value, $valueCredentialProduction ) ) {
            return true;
        }

        return false;
    }

    /**
     * Validate Public Key
     *
     * @param string $key
     * @param string $value
     * @return bool
     */
    private function validatePublicKey( string $key, string $value ): bool
    {
        if ( ! in_array( $key, [ '_mp_public_key_test', '_mp_public_key_prod' ], true ) ) {
            return false;
        }

        if ( $key === '_mp_public_key_prod' && ! WC_WooMercadoPagoSplit_Credentials::validateCredentialsProd( $this->mpInstance, null, $value ) ) {
            update_option( $key, '', true );
            add_action( 'admin_notices', [ $this, 'noticeInvalidPublicKeyProd' ] );
            return true;
        }

        if ( $key === '_mp_public_key_test' && ! WC_WooMercadoPagoSplit_Credentials::validateCredentialsTest( $this->mpInstance, null, $value ) ) {
            update_option( $key, '', true );
            add_action( 'admin_notices', [ $this, 'noticeInvalidPublicKeyTest' ] );
            return true;
        }

        return false;
    }

    /**
     * Validate Access Token
     *
     * @param string $key
     * @param string $value
     * @param string|null $isProduction
     * @return bool
     * @throws WC_WooMercadoPagoSplit_Exception
     */
    private function validateAccessToken( string $key, string $value, ?string $isProduction = null ): bool
    {
        if ( ! in_array( $key, [ '_mp_access_token_prod', '_mp_access_token_test' ], true ) ) {
            return false;
        }

        if ( $key === '_mp_access_token_prod' && ! WC_WooMercadoPagoSplit_Credentials::validateCredentialsProd( $this->mpInstance, $value, null ) ) {
            add_action( 'admin_notices', [ $this, 'noticeInvalidProdCredentials' ] );
            update_option( $key, '', true );
            return true;
        }

        if ( $key === '_mp_access_token_test' && ! WC_WooMercadoPagoSplit_Credentials::validateCredentialsTest( $this->mpInstance, $value, null ) ) {
            add_action( 'admin_notices', [ $this, 'noticeInvalidTestCredentials' ] );
            update_option( $key, '', true );
            return true;
        }

        if ( empty( $isProduction ) ) {
            $isProduction = $this->payment->isProductionMode();
        }

        if ( WC_WooMercadoPagoSplit_Credentials::access_token_is_valid( $value ) ) {
            update_option( $key, $value, true );

            if ( $key === '_mp_access_token_prod' ) {
                $homolog_validate = $this->mpInstance->getCredentialsWrapper( $value );
                $homolog_validate = isset( $homolog_validate['homologated'] ) && $homolog_validate['homologated'] ? 1 : 0;
                update ```php
                update_option( 'homolog_validate', $homolog_validate, true );

                if ( $isProduction === 'yes' && $homolog_validate === 0 ) {
                    add_action( 'admin_notices', [ $this, 'enablePaymentNotice' ] );
                }
            }

            if ( ( $key === '_mp_access_token_prod' && $isProduction === 'yes' ) || ( $key === '_mp_access_token_test' && $isProduction === 'no' ) ) {
                WC_WooMercadoPagoSplit_Credentials::updatePaymentMethods( $this->mpInstance, $value );
                WC_WooMercadoPagoSplit_Credentials::updateTicketMethod( $this->mpInstance, $value );
            }

            return true;
        }

        if ( $key === '_mp_access_token_prod' ) {
            update_option( '_mp_public_key_prod', '', true );
            WC_WooMercadoPagoSplit_Credentials::setNoCredentials();
            add_action( 'admin_notices', [ $this, 'noticeInvalidProdCredentials' ] );
        } else {
            update_option( '_mp_public_key_test', '', true );
            add_action( 'admin_notices', [ $this, 'noticeInvalidTestCredentials' ] );
        }

        update_option( $key, '', true );
        return true;
    }

    /**
     * Admin Notice for Invalid Production Public Key
     */
    public function noticeInvalidPublicKeyProd()
    {
        $type = 'error';
        $message = __('<b>Public Key</b> production credential is invalid. Review the field to receive real payments.', 'woocommerce-mercadopago-split');
        echo WC_WooMercadoPagoSplit_Notices::getAlertFrame($message, $type);
    }

    /**
     * Admin Notice for Invalid Test Public Key
     */
    public function noticeInvalidPublicKeyTest()
    {
        $type = 'error';
        $message = __('<b>Public Key</b> test credential is invalid. Review the field to perform tests in your store.', 'woocommerce-mercadopago-split');
        echo WC_WooMercadoPagoSplit_Notices::getAlertFrame($message, $type);
    }

    /**
     * Admin Notice for Invalid Production Access Token
     */
    public function noticeInvalidProdCredentials()
    {
        $type = 'error';
        $message = __('<b>Access Token</b> production credential is invalid. Remember that it must be complete to receive real payments.', 'woocommerce-mercadopago-split');
        echo WC_WooMercadoPagoSplit_Notices::getAlertFrame($message, $type);
    }

    /**
     * Admin Notice for Invalid Test Access Token
     */
    public function noticeInvalidTestCredentials()
    {
        $type = 'error';
        $message = __('<b>Access Token</b> test credential is invalid. Review the field to perform tests in your store.', 'woocommerce-mercadopago-split');
        echo WC_WooMercadoPagoSplit_Notices::getAlertFrame($message, $type);
    }

    /**
     * Enable Payment Notice
     */
    public function enablePaymentNotice()
    {
        $type = 'notice-warning';
        $message = __('Fill in your credentials to enable payment methods.', 'woocommerce-mercadopago-split');
        echo WC_WooMercadoPagoSplit_Notices::getAlertFrame($message, $type);
    }
}