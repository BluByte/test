<?php

function get_bkn301_payment_gateway_settings() {
    
    $available_gateways = WC()->payment_gateways->get_available_payment_gateways();
    //$logger = wc_get_logger();
    //$logger->info("Available gateways: " . print_r($available_gateways, true));

    $bkn301PaymentGateway = $available_gateways['bkn301'];

    if (!isset($bkn301PaymentGateway)) {
        return;
    }

    $settings['testmode'] = $testmode = 'yes' === $bkn301PaymentGateway->get_option('testmode');
    $settings['log_enabled'] = $log_enabled = 'yes' === $bkn301PaymentGateway->get_option('debug', 'no');
    $settings['merchant_id'] = $testmode ? $bkn301PaymentGateway->get_option('test_merchant_id') : $bkn301PaymentGateway->get_option('merchant_id');
    $settings['merchant_api'] = $testmode ? $bkn301PaymentGateway->get_option('test_merchant_api_key') : $bkn301PaymentGateway->get_option('merchant_api_key');
    $settings['success_status'] = $bkn301PaymentGateway->get_option('success_status');
    $settings['fail_status'] = $bkn301PaymentGateway->get_option('fail_status');
    $settings['hold_status'] = $bkn301PaymentGateway->get_option('hold_status');
    return $settings;
}

/**
 * This function is triggered after a payment is completed and updates the order status.
 *
 * @param int $order_id The order ID.
 */
add_action('woocommerce_thankyou', 'process_after_payment_complete', 10, 1);
function process_after_payment_complete($order_id)
{
    $logger = wc_get_logger();
    $order = wc_get_order($order_id);

    if ($order->get_payment_method() != 'bkn301') {
        return;
    }

    $isPaid = get_post_meta($order_id, 'order_paid', true);

    if (isset($isPaid) && $isPaid == 'yes') {
        $logger->info(sprintf('Order %d is already paid. Skipping payment verification.', $order_id));
        return;
    }


    $settings = get_bkn301_payment_gateway_settings();
    //$logger->info("BKN301 settings: " . print_r($settings, true));

    try {
        $transaction_id = get_payment_id($order_id);
        $logger->info(sprintf('Fetched payment id is %s for order id %d', $transaction_id, $order_id));

        if (!$transaction_id) {
            throw new Exception(__('transaction not found', 'BKN301_gateway'));
        }

        $paymentInfo = get_payment_status($transaction_id);
        if (is_wp_error($paymentInfo)) {
            throw new Exception(__('Something went wrong. Please come back after sometime.', 'error'));
        }

        if ($paymentInfo['response']['code'] != 200) {
            throw new Exception(__('Error in verifying payment details. Please contact customer care.', 'error'));
        }

        $paymentInfo = json_decode($paymentInfo['body']);
        //$logger->info(sprintf('transaction status response: %s', print_r($paymentInfo, true)));

        if ($paymentInfo->status == 'succeeded') {

            $order->update_status($settings['success_status'], __('Payment completed.', 'BKN301_gateway'));
            $order->update_meta_data( 'order_paid', 'yes' );
            $order->save();
            $logger->info(sprintf('Order status maked as: %s', $settings['success_status']));
            wc_print_notice('Your order have been placed.', 'success');
            //wp_redirect($bkn301PaymentGateway->get_return_url($order));

        } else {

            wc_print_notice('Your order could not be placed.', 'error');
            $logger->info(sprintf('transaction status is not ok, we got status : %s', $paymentInfo->status));
            throw new Exception(__('payment failed', 'BKN301_gateway'));
        }

    } catch (Exception $e) {
        wc_print_notice($e->getMessage(), 'error');
        $logger->info(sprintf('transaction failed: %s', $e->getMessage()));

        if (!wp_next_scheduled('bkn_check_order')) {
            $cron_status = wp_schedule_single_event(time() + (absint(5) * 60), 'bkn_check_order', array($order_id, 1));
            if ($cron_status) {
                $logger->info('Inside process_after_payment_complete(). bkn_check_order hook scheduled to check payment status');
            }
        }

        wp_redirect(wc_get_page_permalink('checkout'));
        return;
    }

    //wp_redirect($bkn301PaymentGateway->get_return_url($order));
}

/**
 * Check the status of an order with BKN301
 *
 * @param int $order_id The order ID.
 * @param int $tries_count The number of times the function has been called.
 */
add_action('bkn_check_order', 'check_order_status', 10, 2);
function check_order_status($order_id, $tries_count): void
{
    $logger = wc_get_logger();
    $order = wc_get_order($order_id);

    if ($order->get_payment_method() == 'bkn301') {

        $settings = get_bkn301_payment_gateway_settings();
        $logger->info('Checking payment status for order id: ' . $order_id);
        $payment_id = get_payment_id($order_id);
        $payment_status = get_payment_status($payment_id);
        //$logger->info('Payment status response from BKN301: ' . print_r(json_decode($payment_status), true));

        if (is_wp_error($payment_status)) {

            $logger->info('Encounterd error in calling payment update');
            if ($tries_count < 6) {
                wp_clear_scheduled_hook('bkn_check_order', array($order_id, $tries_count));

                $cron_status = wp_schedule_single_event(time() + (absint(10) * 60), 'bkn_check_order', array($order_id, $tries_count + 1));
                if ($cron_status) {
                    $logger->info('Inside scheduled_event(). bkn_check_order hook scheduled to check payment status');
                }
            }
            return;
        }

        $statusBody = json_decode($payment_status['body']);
        $logger->info(print_r($statusBody->status, true));

        if ( in_array($statusBody->status, array("created", "pending", "authorizing", "requiresCapture", "processing")) ) {

            // If transactions are not yet processed completely, check payment status after 10 minutes
            $logger->info('Payment transaction not completed, retrying after 10 minutes');

            if ($tries_count < 6) {
                wp_clear_scheduled_hook('bkn_check_order', array($order_id, $tries_count));

                $cron_status = wp_schedule_single_event(time() + (absint(10) * 60), 'bkn_check_order', array($order_id, $tries_count + 1));
                if ($cron_status) {
                    $logger->info('Inside scheduled_event(). bkn_check_order hook scheduled to check payment status');
                }
            }
            $order->update_status($settings['hold_status'], __('Payment processing.', 'BKN301_gateway'));

        } elseif (in_array($statusBody->status, array("partiallyRefunded", "refunded"))) {

            // If order is partially refunded, set to refunded
            $logger->info(sprintf('Payment %s for order_id %s in scheduled event', $statusBody->status, $order_id));
            wp_clear_scheduled_hook('bkn_check_order', array($order_id, $tries_count));
            $order->update_status("refunded", __('Payment refunded.', 'BKN301_gateway'));

        } elseif ($statusBody->status == "succeeded") {

            // If order is in "succeeded" or "failed" state, set the order to respective state
            $logger->info(sprintf('Payment %s for order_id %s in scheduled event', $statusBody->status, $order_id));
            wp_clear_scheduled_hook('bkn_check_order', array($order_id, $tries_count));
            $order->update_status($settings['success_status'], $order_id, __('Payment status updated from BKN gateway.', 'BKN301_gateway'));
        } else {

            $logger->info(sprintf('Payment %s for order_id %s in scheduled event', $statusBody->status, $order_id));
            $order->update_status($settings['fail_status'], __('Payment failed.', 'BKN301_gateway'));
        }
    }
}

/**
 * Get the payment ID for an order
 *
 * @param int $order_id The order ID
 * @return string The payment ID
 */
function get_payment_id($order_id)
{
    $logger = wc_get_logger();

    //Uncomment below to get complete meta details for this order
    //$meta = get_post_meta($order_id);
    //$logger->info("Order meta: " . print_r($meta, true));

    $paymentId = get_post_meta($order_id, '_transaction_id', true);
    $logger->info("BKN301 payment id: ". print_r($paymentId, true));
    return $paymentId;
}

/**
 * Get the payment status from BKN301
 *
 * @param string $transaction_id The transaction ID.
 * @return array The payment details.
 */
function get_payment_status($transaction_id)
{
    $logger = wc_get_logger();
    $settings = get_bkn301_payment_gateway_settings();

    if(!isset($settings)) {
        $logger->info("BKN301 settings not found");
        return new WP_Error('bkn301_settings_not_found', __('BKN301 settings not found.', 'BKN301_gateway'), array('status' => 400));
    }
    
    $args = array(
        'method' => 'GET',
        'headers' => array(
            'Content-Type' => 'application/json',
            'x-SubmerchantId' => $settings['merchant_id'],
            'x-SubmerchantApiKey' => $settings['merchant_api'],
            'x-LiveMode' => $settings['testmode'] ? 'false' : 'true',
            'httpversion' => '1.0',
            'sslverify' => false,
            'data_format' => 'body',
        )
    );

    $submit_url = 'https://api.301pay.sm/api/1.0/payments/' . $transaction_id;
    $logger->info('Sending request to ' . $submit_url);

    /*
     * Your API interaction could be built with wp_remote_post()
     */
    $response = wp_remote_get($submit_url, $args);

    return $response;
}
