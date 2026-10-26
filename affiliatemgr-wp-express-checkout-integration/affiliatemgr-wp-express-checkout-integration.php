<?php
/*
Plugin Name: Affiliates Manager WP Express Checkout Integration
Plugin URI: https://wpaffiliatemanager.com/affiliates-manager-wp-express-checkout-integration/
Description: Process an affiliate commission via Affiliates Manager after a WP Express Checkout payment.
Version: 1.0.1
Author: wp.insider, affmngr
Author URI: https://wpaffiliatemanager.com
*/

if (!defined('ABSPATH')){
    exit;
}

function wpam_wpec_payment_completed($payment, $order_id, $item_id) {
    
    WPAM_Logger::log_debug('WP Express Checkout Integration - wpec_payment_completed hook triggered for order id: '.$order_id);  
    //Check the referrer data
    $wpam_id = (isset($_COOKIE['wpam_id']) && !empty($_COOKIE['wpam_id'])) ? sanitize_text_field($_COOKIE['wpam_id']) : '';
    if (empty($wpam_id)) {
        WPAM_Logger::log_debug('WP Express Checkout integration - affiliate ID is not present. This customer was not referred by an affiliate.');
        return;
    }
    $purchaseAmount = get_post_meta($order_id, 'wpec_total_price', true);
    $buyer_email = get_post_meta($order_id, 'wpec_order_customer_email', true);
    $args = array();
    $args['txn_id'] = $order_id;
    $args['amount'] = $purchaseAmount;
    $args['aff_id'] = $wpam_id;
    $args['no_comm_override'] = '1';
    if(isset($buyer_email) && !empty($buyer_email)){
        $args['email'] = $buyer_email;
    }
    else{
        $buyer_email = '';
    }
    WPAM_Logger::log_debug('WP Express Checkout integration - awarding commission for order ID: ' . $order_id . ', Purchase amount: ' . $purchaseAmount . ', Affiliate ID: ' . $wpam_id . ', Buyer Email: ' . $buyer_email);
    do_action('wpam_process_affiliate_commission', $args);              
}

add_action('wpec_payment_completed', 'wpam_wpec_payment_completed', 10, 3);
