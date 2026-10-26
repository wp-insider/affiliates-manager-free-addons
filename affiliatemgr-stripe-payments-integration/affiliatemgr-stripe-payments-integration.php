<?php
/*
Plugin Name: Affiliates Manager Stripe Payments Integration
Plugin URI: https://wpaffiliatemanager.com/affiliates-manager-stripe-payments-integration/
Description: Process an affiliate commission via Affiliates Manager after a Stripe Payments checkout.
Version: 1.0.2
Author: wp.insider, affmngr
Author URI: https://wpaffiliatemanager.com
*/

if (!defined('ABSPATH')){
    exit;
}

function wpam_asp_stripe_payment_completed( $post_data, $charge ) {
    
    WPAM_Logger::log_debug('Stripe Payments Integration - asp_stripe_payment_completed hook triggered.');
    //Required Parameters
    $purchaseAmount = $post_data['item_price'];//Sale Amount
    $order_id = $post_data['txn_id'];//Transaction ID    
    $buyer_email = $post_data['stripeEmail'];//Email address
    
    //Optional parameter
    $reference = $post_data['item_name'];//Use the item name as the reference   
    $ip_address = isset($charge->client_ip) ? $charge->client_ip : '';
    
    //Check the referrer data
    $wpam_id = (isset($_COOKIE['wpam_id']) && !empty($_COOKIE['wpam_id'])) ? $_COOKIE['wpam_id'] : '';
    if(empty($wpam_id) && !empty($ip_address)){
        WPAM_Logger::log_debug('Stripe Payments integration - Checking affiliate ID using customer IP address.');
        $wpam_id = WPAM_Click_Tracking::get_referrer_id_from_ip_address($ip_address);
    }
    if (empty($wpam_id)) {
        WPAM_Logger::log_debug('Stripe Payments integration - affiliate ID is not present. This customer was not referred by an affiliate.');
        return;
    }
    $args = array();
    $args['txn_id'] = $order_id;
    $args['amount'] = $purchaseAmount;
    $args['aff_id'] = $wpam_id;
    $args['email'] = $buyer_email;
    WPAM_Logger::log_debug('Stripe Payments integration - awarding commission for order ID: ' . $order_id . ', Purchase amount: ' . $purchaseAmount . ', Affiliate ID: ' . $wpam_id . ', Buyer Email: ' . $buyer_email);
    do_action('wpam_process_affiliate_commission', $args);              
}
add_action( 'asp_stripe_payment_completed', 'wpam_asp_stripe_payment_completed', 10, 2 );

function wpam_asp_subscription_invoice_paid($sub_id, $payment_data) {
    
    WPAM_Logger::log_debug('Stripe Payments Integration - asp_subscription_invoice_paid hook triggered.');
    
    $sub_additional_data = get_post_meta($sub_id, 'sub_additional_data', false);
    if(isset($sub_additional_data['wpam_id']) && !empty($sub_additional_data['wpam_id'])) {
        $wpam_id = $sub_additional_data['wpam_id'];
        $paid_amount = $payment_data['amount_paid'];
        $purchaseAmount = $paid_amount/100;
        $transaction_id = $payment_data['charge']; // Stripe charge ID
	$buyer_email = get_post_meta($sub_id, 'customer_email', true);
        $args = array();
        $args['txn_id'] = $transaction_id;
        $args['amount'] = $purchaseAmount;
        $args['aff_id'] = $wpam_id;
        $args['email'] = $buyer_email;
        WPAM_Logger::log_debug('Stripe Payments integration - awarding commission for order ID: ' . $transaction_id . ', Purchase amount: ' . $purchaseAmount . ', Affiliate ID: ' . $wpam_id . ', Buyer Email: ' . $buyer_email);
        do_action('wpam_process_affiliate_commission', $args); 
    }
    else{
        WPAM_Logger::log_debug('Stripe Payments integration - affiliate ID is not present. This customer was not referred by an affiliate.');
        return;
    }
}
add_action('asp_subscription_invoice_paid', 'wpam_asp_subscription_invoice_paid', 10, 2);
