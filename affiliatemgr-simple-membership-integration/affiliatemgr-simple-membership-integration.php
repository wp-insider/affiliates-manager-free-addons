<?php
/*
Plugin Name: Affiliates Manager Simple Membership Integration
Plugin URI: https://wpaffiliatemanager.com
Description: Process an affiliate commission via Affiliates Manager after a Simple Membership payment.
Version: 1.0.4
Author: wp.insider, affmngr
Author URI: https://wpaffiliatemanager.com
*/

function wpam_simple_membership_add_custom_parameters($custom_field_value)
{
    if(isset($_COOKIE['wpam_id']))
    {
        $name = 'wpam_tracking';
        $value = $_COOKIE['wpam_id'];
        $new_val = $name.'='.$value;
        $current_val = $custom_field_value;
        if(empty($current_val)){
            $custom_field_value = $new_val;
        }
        else{
            $custom_field_value = $current_val.'&'.$new_val;
        }
        WPAM_Logger::log_debug('Simple Membership Integration - Adding custom field value. New value: '.$custom_field_value);
    }
    else if(isset($_COOKIE[WPAM_PluginConfig::$RefKey]))
    {
        $name = 'wpam_tracking';
        $value = $_COOKIE[WPAM_PluginConfig::$RefKey];
        $new_val = $name.'='.$value;
        $current_val = $custom_field_value;
        if(empty($current_val)){
            $custom_field_value = $new_val;
        }
        else{
            $custom_field_value = $current_val.'&'.$new_val;
        }
        WPAM_Logger::log_debug('Simple Membership Integration - Adding custom field value. New value: '.$custom_field_value);
    }
    return $custom_field_value;
}

add_filter("swpm_custom_field_value_filter", "wpam_simple_membership_add_custom_parameters");

function wpam_simple_membership_payment_completed($ipn_data)
{
    
    $custom_data = isset($ipn_data['custom'])? $ipn_data['custom'] : '';
    $subscr_id = isset($ipn_data['subscr_id'])? $ipn_data['subscr_id'] : '';
    WPAM_Logger::log_debug('Simple Membership Integration - Payment completed hook fired. txn id: '.$ipn_data['txn_id'].', subscr id: '.$subscr_id.', Custom field value: '.$custom_data);
    $custom_values = array();
    parse_str($custom_data, $custom_values);
    $custom_data_orig = SwpmTransactions::get_original_custom_value_from_transactions_cpt($subscr_id);
    if(isset($custom_data_orig) && !empty($custom_data_orig)){
        WPAM_Logger::log_debug('Simple Membership Integration - Original custom field value: '.$custom_data_orig);
        $custom_orig_arr = array();
        parse_str($custom_data_orig, $custom_orig_arr);
        if(isset($custom_orig_arr['wpam_id']) && !empty($custom_orig_arr['wpam_id'])){
            $custom_values['wpam_tracking'] = $custom_orig_arr['wpam_id'];
        }
    }
    if(isset($custom_values['wpam_tracking']) && !empty($custom_values['wpam_tracking']))
    {
        $tracking_value = $custom_values['wpam_tracking'];
        WPAM_Logger::log_debug('Simple Membership Integration - Tracking data present. Need to track affiliate commission. Tracking value: '.$tracking_value);
        $purchaseLogId = $ipn_data['txn_id'];
        $purchaseAmount = $ipn_data['mc_gross']; //TODO - later calculate sub-total only
        $strRefKey = $tracking_value;
        $buyer_email = '';
        if(isset($ipn_data['payer_email']) && !empty($ipn_data['payer_email'])) {
            $buyer_email = $ipn_data['payer_email'];
        }
        $requestTracker = new WPAM_Tracking_RequestTracker();
        $requestTracker->handleCheckoutWithRefKey( $purchaseLogId, $purchaseAmount, $strRefKey, $buyer_email);
        WPAM_Logger::log_debug('Simple Membership Integration - Commission tracked for transaction ID: '.$purchaseLogId.'. Purchase amt: '.$purchaseAmount);
    }
    else{
        WPAM_Logger::log_debug('Simple Membership Integration - No Tracking data present. No Need to track affiliate commission.');
    }
}

add_action("swpm_payment_ipn_processed", "wpam_simple_membership_payment_completed");
