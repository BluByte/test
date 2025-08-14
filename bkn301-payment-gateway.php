<?php

/*
 * Plugin Name: BKN301 Payment Gateway
 * Plugin URI: https://bitbucket.org/bkn301sm/301pay.plugins.woocommerce/src/main/
 * Description: This is a payment gateway for WooCommerce that integrates with BKN301.
 * Version: 1.3.0
 * Author: BKN301
 * Author URI: https://www.bkn301.com
 * License: GPL2
 */

require 'jobs/check-payment.php';

/*
 * This action hook registers our PHP class as a WooCommerce payment gateway
 * @param array $gateways an array of payment gateways
 * @return array the updated array of payment gateways
 */
add_filter('woocommerce_payment_gateways', 'bkn301_add_gateway_class');
function bkn301_add_gateway_class($gateways)
{
    $gateways[] = 'WC_bkn301_Gateway'; // your class name is here
    return $gateways;
}

/*
 * Initializes the BKN301 payment gateway class.
 * The class itself, please note that it is inside plugins_loaded action hook
 */
add_action('plugins_loaded', 'bkn301_init_gateway_class');
function bkn301_init_gateway_class()
{
    class WC_bkn301_Gateway extends WC_Payment_Gateway
    {
        public $baseUrl = 'https://api.301pay.sm/api/1.0/';

        /**
         * Whether logging is enabled.
         *
         * @since 1.0.0
         * @var bool
         */
        public static $log_enabled = false;

        /**
         * Logger instance.
         *
         * @since 1.0.0
         * @var WC_Logger|false
         */
        public static $log = null;

        /**
         * Initialize the gateway.
         */
        public function __construct()
        {
            $this->id = 'bkn301'; // payment gateway plugin ID
            $this->icon = ''; // URL of the icon that will be displayed on checkout page near your gateway name
            $this->has_fields = true; // in case you need a custom credit card form
            $this->method_title = 'BKN301 Gateway';
            $this->method_description = 'Version 1.3.0'; // will be displayed on the options page

            // gateways can support subscriptions, refunds, saved payment methods,
            // but in this tutorial we begin with simple payments
            $this->supports = array(
                'products',
                'refunds',
            );

            // Method with all the options fields
            $this->init_form_fields();

            // Load the settings.
            $this->init_settings();
            $this->title = $this->get_option('title');
            $this->description = $this->get_option('description');
            $this->enabled = $this->get_option('enabled');
            $this->testmode = 'yes' === $this->get_option('testmode');
            $this->debug = 'yes' === $this->get_option('debug', 'no');
            $this->merchant_id = $this->testmode ? $this->get_option('test_merchant_id') : $this->get_option('merchant_id');
            $this->merchant_api = $this->testmode ? $this->get_option('test_merchant_api_key') : $this->get_option('merchant_api_key');
            $this->success_status = $this->get_option('success_status');
            $this->fail_status = $this->get_option('fail_status');
            $this->hold_status = $this->get_option('hold_status');
            $this->ok = 'bkn301/ok';
            $this->fail = 'bkn301/fail';

            self::$log_enabled = $this->debug;

            // This action hook saves the settings
            add_action('woocommerce_api_' . $this->ok, array($this, 'return_from_payment_form_ok'));
            add_action('woocommerce_api_' . $this->fail, array($this, 'return_from_payment_form_fail'));
            add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));
            add_action('woocommerce_order_status_on-processing_to_completed', array($this, 'capture_authorized_payment'));
        }

        /**
         * Initialise gateway settings.
         *
         * @since 1.0.0
         */
        public function init_form_fields()
        {
            $this->form_fields = include 'settings/gateway.php';
        }

        /**
         * Create a log entry
         *
         * @param string $message Log message.
         * @uses  BKN301_gateway::$log_enabled
         * @uses  BKN301_gateway::$log
         */
        public static function log($message, $level = 'info')
        {
            if (self::$log_enabled) {
                if (empty(self::$log)) {
                    self::$log = wc_get_logger();
                }

                switch ($level) {
                    case 'info':
                        self::$log->info( $message );
                        break;
                    case 'warning':
                        self::$log->warning( $message );
                        break;
                    case 'error':
                        self::$log->error( $message );
                        break;
                    case 'critical':
                        self::$log->critical( $message );
                        break;
                    default:
                        self::$log->debug( $message );
                        break;
                }
            }
        }

        /**
         * Process a payment.
         *
         * @param int $order_id Order ID.
         * @return array
         */
        public function process_payment($order_id)
        {
            // we need it to get any order detailes
            $order = wc_get_order($order_id);

            $this->log(sprintf('payment method used for order id : %s is %s', $order->get_id(), $order->get_payment_method()));
            if ($order->get_payment_method() != 'bkn301') {
                return;
            }

            $body = [
                'reference' => 'order' . strval($order->get_id()),
                'amount' => $order->get_total(),
                'currency' => get_woocommerce_currency(),
                'customer' => array(
                    'emailAddress' => $order->get_billing_email()
                ),
                'returnUrls' => array(
                    'returnUrl' => $this->get_return_url($order),
                    'cancelUrl' => wc_get_page_permalink('checkout'),
                )
            ];
            $body = wp_json_encode($body);

            /*
             * Array with parameters for API interaction
             */
            $args = array(
                'method' => 'POST',

                'headers' => array(
                    'Content-Type' => 'application/json',
                    'x-SubmerchantId' => $this->merchant_id,
                    'x-SubmerchantApiKey' => $this->merchant_api,
                    'x-LiveMode' => $this->testmode ? 'false' : 'true',
                    'httpversion' => '1.0',
                    'sslverify' => false,
                    'data_format' => 'body',

                ),
                'body' => $body
            );
            $this->log(sprintf('HTTP request to server : %s', print_r($args,true)));
            $submit_url = $this->baseUrl . 'payments';

            /*
             * Your API interaction could be built with wp_remote_post()
             */
            $response = wp_remote_post($submit_url, $args);
            //$this->log('Post response: ' . print_r($response, true));

            $body = json_decode($response['body'], true);

            /*
             * Save BKN301 payment id
             */
            $order->update_meta_data( '_transaction_id', $body['id'] );
            $order->save();

            if (is_wp_error($response)) {

                wc_print_notice('Connection error.', 'error');
                $this->log(sprintf('Inside process_payment(). order current status is %s', $order->get_status()));
                $this->log(sprintf('Inside process_payment(). wp_remote_post() returned an error: %s', $response->get_error_message()), 'error');

                if (!wp_next_scheduled('bkn_check_order')) {
                    $cron_status = wp_schedule_single_event(time() + (absint(5) * 60), 'bkn_check_order', array($order_id, 1));
                    if ($cron_status) {
                        $this->log('Inside process_payment(). bkn_check_order hook scheduled to check payment status');
                    }
                }

                return array(
                    'result' => 'fail',
                    'message' => $response->get_error_message(),
                );
            }

            // it could be different depending on your payment processor
            if ($response['response']['code'] != 200) {

                wc_print_notice('Please try again.', 'error');
                $this->log(sprintf('BKN301 payment page load failed. Change order status to %s', $this->hold_status));
                $order->update_status($this->hold_status, __('Payment status updated from BKN gateway.', 'BKN301_gateway'));
                $r_sc = $response['http_response']->get_status();
                $r_body = $response['http_response']->get_data();
                $this->log(sprintf('transaction failed status is not ok. HTTP status: %s, body: %s', $r_sc, $r_body), 'error');
                $this->log(sprintf('HTTP response from server : %s', print_r($response,true)));
                if (!wp_next_scheduled('bkn_check_order')) {
                    $cron_status = wp_schedule_single_event(time() + (absint(5) * 60), 'bkn_check_order', array($order_id, 1));
                    if ($cron_status) {
                        $this->log('Inside process_payment(). bkn_check_order hook scheduled to check payment status');
                    }
                }

                return array(
                    'result' => 'fail',
                    'message' => 'Please try again.',
                );
            }

            // Redirect to the BKN301 payment page
            return array(
                'result' => 'success',
                'redirect' => $body['redirectUrl'],
            );
        }

        function process_refund($order_id, $amount = null, $reason = '')
        {
            try {
                $transaction_id = get_payment_id($order_id);
                $args = array(
                    'method' => 'POST',

                    'headers' => array(
                        'Content-Type' => 'application/json',
                        'x-SubmerchantId' => $this->merchant_id,
                        'x-SubmerchantApiKey' => $this->merchant_api,
                        'x-LiveMode' => $this->testmode ? 'false' : 'true',
                        'httpversion' => '1.0',
                        'sslverify' => false,
                        'data_format' => 'body',

                    ),
                    'body' => wp_json_encode(
                        array(
                            'paymentId' => $transaction_id,
                            'amount' => $amount,

                        )
                    )
                );

                $refund_url = $this->baseUrl . 'refunds';
                $response = wp_remote_post($refund_url, $args);

                $response = json_decode($response);

                if ($response['response']['code'] = 200) {

                    $this->log(sprintf('refund succeed: %s'));
                    return true;
                } else {
                    $this->log(sprintf('refund failed: %s'));
                    return false;
                }
            } catch (Exception $e) {
                wc_print_notice('refund not completed.', 'error');
                $this->log(sprintf('refund failed: %s', $e->getMessage()));

                return false;
            }
        }

        /**
         * This function is triggered when the payment form is submitted successfully.
         * At present this method is not gettiing triggered.
         * Refer it's alternative working method process_after_payment_complete in jobs/check-payment.php
         * @deprecated return_from_payment_form_ok
         * @param int $order_id Order ID.
         * @return array
         */
        function return_from_payment_form_ok($order_id)
        {
            //$order = wc_get_order($_REQUEST['order']);
            //$order_id = $order->get_id();

            try {
                $transaction_id = get_payment_id($order_id);
                $this->log(sprintf('Fetched payment id is %s for order id %d', $transaction_id, $order_id));

                if (!$transaction_id) {
                    throw new Exception(__('transaction not found', 'BKN301_gateway'));
                }

                $paymentStatus = get_payment_status($transaction_id);
                $paymentStatus = json_decode($paymentStatus['body']);

            } catch (Exception $e) {
                wc_print_notice('transaction not found' . $e, 'error');
                $this->log(sprintf('transaction not found for order id %d', $order_id));
            }

            try {

                if ($paymentStatus->status == 'succeeded') {

                    $order->update_status($this->success_status, __('Payment completed.', 'BKN301_gateway'));
                    wc_print_notice('Payment completed.', 'success');
                    wp_redirect($this->get_return_url($order));

                } else {
                    wc_print_notice('Payment not completed status return wrong.', 'error');
                    $this->log(sprintf('transaction failed status is not ok 2 we got statuscode : %s', $paymentStatus->status));
                    wp_redirect(wc_get_page_permalink('checkout'));
                    return;
                }
            } catch (Exception $e) {
                wc_print_notice('Payment not completed.', 'error');
                $this->log(sprintf('transaction failed: %s', $e->getMessage()));
                wp_redirect(wc_get_page_permalink('checkout'));
                return;
            } catch (Exception $e) {
                wc_print_notice('transaction not found', 'error');
                $this->log(sprintf('transaction failed: %s', $e->getMessage()));
                wp_redirect(wc_get_page_permalink('checkout'));
                return;
            }
            wp_redirect($this->get_return_url($order));
        }

        function return_from_payment_form_fail()
        {
            wc_print_notice('Payment not completed.', 'error');
            $this->log(sprintf('payment failed'));
            wp_redirect(wc_get_page_permalink('checkout'));
        }

        function capture_authorized_payment($order_id)
        {
            $order = wc_get_order($order_id);

            $this->log(sprintf('payment method used for order id : %s is %s', $order->get_id(), $order->get_payment_method()));
            if ($order->get_payment_method() != 'bkn301') {
                return;
            }

            try {
                $transaction_id = $this->get_transaction_id_by_order_id($order_id);
                $this->log('status is : ' . $transaction_id);
                $status = $this->get_order_status($transaction_id);

            } catch (Exception $e) {

                $this->log(sprintf('transaction not found'));
            }

            try {

                if ($status->status == 'succeeded') {
                    $order->payment_complete();
                    $order->update_status($this->success_status, __('Payment completed.', 'BKN301_gateway'));
                } else {
                    $order->update_status($this->fail_status, __('Payment failed.', 'BKN301_gateway'));
                    $this->log(sprintf('transaction failed status is  statuscode : %s', $status->status));
                }
            } catch (Exception $e) {
                wc_print_notice('Payment not completed.', 'error');
                $this->log(sprintf('transaction failed: %s', $e->getMessage()));
                wp_redirect(wc_get_page_permalink('checkout'));
                return;
            }
        }
    }
}
