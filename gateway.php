<?php


if (!defined('ABSPATH')) {
    exit;
}

/**
 * Settings for Bkn301 payment Gateway
 */
return array(
    'enabled' => array(
        'title'       => 'Enable/Disable',
        'label'       => 'Enable BKN301 Gateway',
        'type'        => 'checkbox',
        'description' => '',
        'default'     => 'no'
    ),
    'title' => array(
        'title'       => 'Title',
        'type'        => 'text',
        'description' => 'This controls the title which the user sees during checkout.',
        'default'     => 'Credit Card',
        'desc_tip'    => true
    ),
    'description' => array(
        'title'       => 'Description',
        'type'        => 'textarea',
        'description' => 'This controls the description which the user sees during checkout.',
        'default'     => 'pay via BKN301 payment',
    ),
    'testmode' => array(
        'title'       => 'Test mode',
        'label'       => 'Enable Test Mode',
        'type'        => 'checkbox',
        'description' => 'Place the payment gateway in test mode using test API keys.',
        'default'     => 'yes',
        'desc_tip'    => true,
    ),
    'debug'         => array(
        'title'       => __( 'Debug Log', 'tbc-gateway-free' ),
        'type'        => 'checkbox',
        'label'       => __( 'Enable logging', 'tbc-gateway-free' ),
        'default'     => 'no',
        /* translators: %s: log file path */
        'description' => sprintf( __( 'Log events, such as IPN requests, inside <code>%s</code>', 'BKN' ), WC_Log_Handler_File::get_log_file_path( 'BKN301_gateway' ) ),
    ),
    'success_status' => array(
        'title'       => 'sucess status',
        'label'       => 'Enable Test Mode',
        'type'        => 'select',
        'description' => 'Place the payment gateway in test mode using test API keys.',

        'default'     => 'Processing',
        'desc_tip'    => true,
        'options' => array(
            'processing' => 'processing',
            'completed' => 'completed',
            'canceled' => 'canceled',
            'failed' => 'failed',
            'on-hold' => 'on-Hold',

        )
    ),
    'fail_status' => array(
        'title'       => 'fail  status',
        'label'       => 'Enable Test Mode',
        'type'        => 'select',
        'description' => 'Place the payment gateway in test mode using test API keys.',

        'default'     => 'failed',
        'desc_tip'    => true,
        'options' => array(
            'processing' => 'processing',
            'completed' => 'completed',
            'canceled' => 'canceled',
            'failed' => 'failed',
            'on-hold' => 'on-Hold',

        )
    ),
    'hold_status' => array(
        'title'       => 'hold status',
        'label'       => 'Enable Test Mode',
        'type'        => 'select',
        'description' => 'Place the payment gateway in test mode using test API keys.',

        'default'     => 'on-hold',
        'desc_tip'    => true,
        'options' => array(
            'processing' => 'processing',
            'completed' => 'completed',
            'canceled' => 'canceled',
            'failed' => 'failed',
            'on-hold' => 'on-Hold',

        )
    ),
    'test_merchant_id' => array(
        'title'       => 'Test Publishable merchant ID',
        'type'        => 'text'
    ),
    'test_merchant_api_key' => array(
        'title'       => 'Test API Key',
        'type'        => 'password',
    ),
    'merchant_id' => array(
        'title'       => 'Live merchant id',
        'type'        => 'text'
    ),
    'merchant_api_key' => array(
        'title'       => 'Live merchant Key',
        'type'        => 'password'
    )
);
