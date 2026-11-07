<?php
/*
Plugin Name: Affiliates Manager Simple Membership Integration
Plugin URI: https://wpaffiliatemanager.com
Description: Process an affiliate commission via Affiliates Manager after a Simple Membership payment.
Version: 1.0.5
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

/*
 * Checkout payments (all gateways) and every PayPal Standard subscription payment.
 */
function wpam_simple_membership_payment_completed($ipn_data)
{
    $txn_id = isset($ipn_data['txn_id'])? $ipn_data['txn_id'] : '';
    $subscr_id = isset($ipn_data['subscr_id'])? $ipn_data['subscr_id'] : '';
    $txn_type = isset($ipn_data['txn_type'])? $ipn_data['txn_type'] : '';
    WPAM_Logger::log_debug('Simple Membership Integration - Payment completed hook fired. txn id: '.$txn_id.', subscr id: '.$subscr_id.', txn type: '.$txn_type);

    //PayPal Standard sends every payment of a subscription (the first one and the renewals) through this hook.
    if($txn_type == 'subscr_payment' && wpam_swpm_get_renewal_setting() == 'no'){
        $result = wpam_swpm_classify_paypal_std_payment($ipn_data);
        if($result['classification'] != 'checkout'){
            WPAM_Logger::log_debug('Simple Membership Integration - Renewal commissions are disabled. No commission for this PayPal Standard payment ('.$result['reason'].'). txn id: '.$txn_id.', subscr id: '.$subscr_id);
            return;
        }
        WPAM_Logger::log_debug('Simple Membership Integration - Renewal commissions are disabled. This PayPal Standard payment is the first payment of the subscription ('.$result['reason'].'). txn id: '.$txn_id.', subscr id: '.$subscr_id);
    }

    $purchaseAmount = isset($ipn_data['mc_gross'])? $ipn_data['mc_gross'] : ''; //TODO - later calculate sub-total only
    wpam_swpm_award_commission($ipn_data, $purchaseAmount);
}

add_action("swpm_payment_ipn_processed", "wpam_simple_membership_payment_completed");

/*
 * Stripe subscription renewals (and the first payment after a free trial).
 * Fired before the payment record is saved, and only when the member account exists.
 */
function wpam_simple_membership_recurring_payment_received($ipn_data)
{
    $gateway = isset($ipn_data['gateway'])? $ipn_data['gateway'] : '';
    $txn_type = isset($ipn_data['txn_type'])? $ipn_data['txn_type'] : '';
    //PayPal Standard payments are handled by the payment completed hook, PayPal subscription (PPCP) payments by the sale completed hook.
    if($txn_type == 'subscr_payment' || $txn_type == 'pp_subscription_sale_completed_webhook'){
        return;
    }
    $txn_id = isset($ipn_data['txn_id'])? $ipn_data['txn_id'] : '';
    $subscr_id = isset($ipn_data['subscr_id'])? $ipn_data['subscr_id'] : '';
    if($gateway != 'stripe-sca-subs' || $txn_type != 'recurring_payment'){
        WPAM_Logger::log_debug('Simple Membership Integration - Recurring payment of an unsupported type (gateway: '.$gateway.', txn type: '.$txn_type.'). No commission. txn id: '.$txn_id.', subscr id: '.$subscr_id);
        return;
    }
    if(wpam_swpm_get_renewal_setting() != 'yes'){
        WPAM_Logger::log_debug('Simple Membership Integration - Stripe subscription renewal payment. Renewal commissions are not enabled. No commission. txn id: '.$txn_id.', subscr id: '.$subscr_id);
        return;
    }
    if(empty($txn_id) || empty($subscr_id)){
        WPAM_Logger::log_debug('Simple Membership Integration - Stripe subscription renewal payment without a transaction ID or subscription ID. No commission. txn id: '.$txn_id.', subscr id: '.$subscr_id);
        return;
    }
    WPAM_Logger::log_debug('Simple Membership Integration - Stripe subscription renewal payment. Renewal commissions are enabled. txn id: '.$txn_id.', subscr id: '.$subscr_id);
    wpam_swpm_award_commission($ipn_data, wpam_swpm_get_payment_amount($ipn_data));
}

add_action("swpm_recurring_payment_received", "wpam_simple_membership_recurring_payment_received");

/*
 * PayPal subscription (PPCP) payments. Fired after the payment record is saved.
 * The checkout itself gets its commission at checkout (payment completed hook, with the order ID).
 */
function wpam_simple_membership_ppcp_sale_completed($ipn_data)
{
    $txn_id = isset($ipn_data['txn_id'])? $ipn_data['txn_id'] : '';
    $subscr_id = isset($ipn_data['subscr_id'])? $ipn_data['subscr_id'] : '';
    if(wpam_swpm_get_renewal_setting() != 'yes'){
        WPAM_Logger::log_debug('Simple Membership Integration - PayPal subscription payment. Renewal commissions are not enabled. No commission. txn id: '.$txn_id.', subscr id: '.$subscr_id);
        return;
    }
    $result = wpam_swpm_classify_ppcp_sale($ipn_data);
    if($result['classification'] != 'after_checkout'){
        WPAM_Logger::log_debug('Simple Membership Integration - PayPal subscription payment: '.$result['classification'].' ('.$result['reason'].'). No commission. txn id: '.$txn_id.', subscr id: '.$subscr_id);
        return;
    }
    WPAM_Logger::log_debug('Simple Membership Integration - PayPal subscription payment after the checkout ('.$result['reason'].'). Renewal commissions are enabled. txn id: '.$txn_id.', subscr id: '.$subscr_id);
    wpam_swpm_award_commission($ipn_data, wpam_swpm_get_payment_amount($ipn_data));
}

add_action("swpm_paypal_subscription_sale_completed_webhook_processed", "wpam_simple_membership_ppcp_sale_completed");

/*
 * Creates the commission for this payment, if an affiliate referred the subscription.
 */
function wpam_swpm_award_commission($ipn_data, $purchaseAmount)
{
    $txn_id = isset($ipn_data['txn_id'])? $ipn_data['txn_id'] : '';
    $subscr_id = isset($ipn_data['subscr_id'])? $ipn_data['subscr_id'] : '';
    if($purchaseAmount === null){
        WPAM_Logger::log_debug('Simple Membership Integration - No valid payment amount. No commission. txn id: '.$txn_id.', subscr id: '.$subscr_id);
        return;
    }
    $tracking_value = wpam_swpm_get_tracking_value($ipn_data);
    if(empty($tracking_value)){
        WPAM_Logger::log_debug('Simple Membership Integration - No Tracking data present. No Need to track affiliate commission. txn id: '.$txn_id.', subscr id: '.$subscr_id);
        return;
    }
    WPAM_Logger::log_debug('Simple Membership Integration - Tracking data present. Need to track affiliate commission. Tracking value: '.$tracking_value);
    $buyer_email = '';
    if(isset($ipn_data['payer_email']) && !empty($ipn_data['payer_email'])) {
        $buyer_email = $ipn_data['payer_email'];
    }
    $requestTracker = new WPAM_Tracking_RequestTracker();
    $requestTracker->handleCheckoutWithRefKey( $txn_id, $purchaseAmount, $tracking_value, $buyer_email);
    WPAM_Logger::log_debug('Simple Membership Integration - Commission tracked for transaction ID: '.$txn_id.'. Purchase amt: '.$purchaseAmount);
}

/*
 * The affiliate tracking value of this payment. Order: the original checkout of the subscription (saved in the member's
 * profile), then the subscription's agreement record: wpam_tracking (saved by this plugin) first, then wpam_id (for
 * example from the [wpam_custom_input] shortcode). Then the wpam_tracking of this payment.
 */
function wpam_swpm_get_tracking_value($ipn_data)
{
    $subscr_id = isset($ipn_data['subscr_id'])? $ipn_data['subscr_id'] : '';
    $sources = array();
    if(!empty($subscr_id)){
        $sources['original checkout'] = SwpmTransactions::get_original_custom_value_from_transactions_cpt($subscr_id);
        $agreement_id = wpam_swpm_get_unique_agreement_id($subscr_id, array('stripe_subscription_new', 'pp_subscription_new', 'pp_std_subscription_new'));
        if(!empty($agreement_id)){
            $sources['agreement record'] = get_post_meta($agreement_id, 'custom', true);
        }
    }
    $sources['this payment'] = isset($ipn_data['custom'])? $ipn_data['custom'] : '';
    foreach($sources as $source => $custom){
        if(empty($custom) || !is_string($custom)){
            continue;
        }
        $values = array();
        parse_str($custom, $values);
        $keys = ($source == 'this payment') ? array('wpam_tracking') : array('wpam_tracking', 'wpam_id');
        foreach($keys as $key){
            if(isset($values[$key]) && is_string($values[$key]) && $values[$key] !== ''){
                WPAM_Logger::log_debug('Simple Membership Integration - Tracking value found in the '.$source.' ('.$key.').');
                return $values[$key];
            }
        }
    }
    return '';
}

/*
 * The payment amount of a renewal: payment_amount, then mc_gross. Null if neither is a number.
 */
function wpam_swpm_get_payment_amount($ipn_data)
{
    foreach(array('payment_amount', 'mc_gross') as $key){
        if(isset($ipn_data[$key]) && is_numeric($ipn_data[$key])){
            return $ipn_data[$key];
        }
    }
    return null;
}

/*
 * Renewal commission setting: 'yes', 'no', or 'legacy' when it was never saved (keeps the behaviour of version 1.0.4:
 * PayPal Standard renewals get a commission, Stripe and PayPal subscription (PPCP) renewals don't).
 */
function wpam_swpm_get_renewal_setting()
{
    $value = get_option('wpam_swpm_renewal_commission', '');
    if($value === 'yes' || $value === 'no'){
        return $value;
    }
    return 'legacy';
}

/*
 * The ID of the subscription agreement record of a subscription (the "subscription created" record), when there is exactly one.
 */
function wpam_swpm_get_unique_agreement_id($subscr_id, $txn_types)
{
    if(empty($subscr_id)){
        return 0;
    }
    $ids = get_posts(array(
        'post_type' => 'swpm_transactions',
        'posts_per_page' => 2,
        'fields' => 'ids',
        'meta_query' => array(
            array('key' => 'subscr_id', 'value' => $subscr_id, 'compare' => '='),
            array('key' => 'txn_type', 'value' => $txn_types, 'compare' => 'IN'),
        ),
    ));
    return count($ids) === 1 ? (int) $ids[0] : 0;
}

/*
 * PayPal Standard: is this payment the first payment of the subscription? Uses the saved records, so it can be wrong when
 * the notifications are processed out of order or records were deleted.
 * Returns array('classification' => 'checkout'|'after_checkout'|'unknown', 'reason' => '...'). Only 'checkout' gets a commission.
 */
function wpam_swpm_classify_paypal_std_payment($ipn_data)
{
    $txn_id = isset($ipn_data['txn_id'])? $ipn_data['txn_id'] : '';
    $subscr_id = isset($ipn_data['subscr_id'])? $ipn_data['subscr_id'] : '';
    if(empty($txn_id) || empty($subscr_id)){
        return array('classification' => 'unknown', 'reason' => 'no transaction or subscription ID');
    }

    //Another payment of this subscription is already saved (this payment is saved before the hook is fired).
    $agreement_types = array('stripe_subscription_new', 'pp_subscription_new', 'pp_std_subscription_new');
    $record_ids = get_posts(array(
        'post_type' => 'swpm_transactions',
        'posts_per_page' => -1,
        'fields' => 'ids',
        'meta_query' => array(
            array('key' => 'subscr_id', 'value' => $subscr_id, 'compare' => '='),
        ),
    ));
    foreach($record_ids as $record_id){
        if(get_post_meta($record_id, 'txn_id', true) == $txn_id){
            continue;
        }
        if(in_array(get_post_meta($record_id, 'txn_type', true), $agreement_types, true)){
            continue;
        }
        return array('classification' => 'after_checkout', 'reason' => 'another payment of this subscription is already saved');
    }

    //With a free trial, the first payment is the payment after the trial.
    $trial = wpam_swpm_get_paypal_std_trial($subscr_id, $ipn_data);
    if($trial['free_trial'] === true){
        return array('classification' => 'after_checkout', 'reason' => 'first payment after a free trial ('.$trial['source'].')');
    }
    if($trial['free_trial'] === null){
        return array('classification' => 'checkout', 'reason' => 'first payment; trial unknown, treated as no free trial');
    }
    return array('classification' => 'checkout', 'reason' => 'first payment, no free trial ('.$trial['source'].')');
}

/*
 * PayPal Standard: was the subscription started with a free trial? From the agreement record (Simple Membership 4.8.7+),
 * else from the payment button's current settings. free_trial: true, false, or null (unknown).
 */
function wpam_swpm_get_paypal_std_trial($subscr_id, $ipn_data)
{
    $agreement_id = wpam_swpm_get_unique_agreement_id($subscr_id, array('pp_std_subscription_new'));
    if(!empty($agreement_id)){
        $is_trial_txn = get_post_meta($agreement_id, 'is_trial_txn', true);
        $trial_amount = get_post_meta($agreement_id, 'trial_amount', true);
        if($is_trial_txn === 'no'){
            return array('free_trial' => false, 'source' => 'agreement record');
        }
        if($is_trial_txn === 'yes' && is_numeric($trial_amount)){
            return array('free_trial' => ((float) $trial_amount == 0), 'source' => 'agreement record');
        }
    }

    //Fallback: the trial settings of the payment button (they may have been changed since the checkout).
    $button_id = !empty($agreement_id) ? get_post_meta($agreement_id, 'payment_button_id', true) : '';
    if(empty($button_id) && isset($ipn_data['item_number'])){
        $button_id = $ipn_data['item_number'];
    }
    if(empty($button_id) || !is_numeric($button_id) || get_post_type((int) $button_id) !== 'swpm_payment_button'){
        return array('free_trial' => null, 'source' => 'unknown');
    }
    $trial_cycle = get_post_meta((int) $button_id, 'trial_billing_cycle', true);
    if(empty($trial_cycle)){
        return array('free_trial' => false, 'source' => 'payment button');
    }
    $trial_amount = get_post_meta((int) $button_id, 'trial_billing_amount', true);
    if($trial_amount === '' || (is_numeric($trial_amount) && (float) $trial_amount == 0)){
        return array('free_trial' => true, 'source' => 'payment button');
    }
    if(is_numeric($trial_amount)){
        return array('free_trial' => false, 'source' => 'payment button');
    }
    return array('free_trial' => null, 'source' => 'unknown');
}

/*
 * PayPal subscription (PPCP): is this saved sale a payment after the checkout? Only 'after_checkout' gets a commission.
 * Uses Simple Membership's rule for the refund of the checkout payment (SWPM_PayPal_Utility_Functions::get_checkout_txn_id_if_first_sale),
 * so a sale that this rule identifies as the checkout payment never gets a second commission.
 * Returns array('classification' => 'after_checkout'|'unknown', 'reason' => '...').
 */
function wpam_swpm_classify_ppcp_sale($ipn_data)
{
    if(!class_exists('SWPM_PayPal_Utility_Functions') || !method_exists('SWPM_PayPal_Utility_Functions', 'get_checkout_txn_id_if_first_sale')){
        return array('classification' => 'unknown', 'reason' => 'this Simple Membership version does not support it');
    }
    $txn_id = isset($ipn_data['txn_id'])? (string) $ipn_data['txn_id'] : '';
    $subscr_id = isset($ipn_data['subscr_id'])? (string) $ipn_data['subscr_id'] : '';
    if($txn_id === '' || $subscr_id === ''){
        return array('classification' => 'unknown', 'reason' => 'no transaction or subscription ID');
    }

    //The saved record of this sale.
    $row = SwpmTransactions::get_transaction_row_by_txn_id_and_subscription_id($txn_id, $subscr_id, true);
    $sale_type = 'pp_subscription_sale_completed_webhook';
    if(empty($row) || empty($row->id) || (string) $row->txn_id !== $txn_id || (string) $row->subscr_id !== $subscr_id || (isset($row->txn_type) ? $row->txn_type : get_post_meta($row->id, 'txn_type', true)) !== $sale_type){
        return array('classification' => 'unknown', 'reason' => 'the saved record of this payment was not found');
    }

    $agreement_id = wpam_swpm_get_unique_agreement_id($subscr_id, array('pp_subscription_new'));
    if(empty($agreement_id)){
        return array('classification' => 'unknown', 'reason' => 'no single subscription agreement record');
    }

    //Free trial (from the saved trial info): no payment was made at checkout, so every payment is after the checkout.
    $is_trial_txn = get_post_meta($agreement_id, 'is_trial_txn', true);
    $trial_amount = get_post_meta($agreement_id, 'trial_amount', true);
    if($is_trial_txn === 'yes' && is_numeric($trial_amount) && (float) $trial_amount == 0){
        return array('classification' => 'after_checkout', 'reason' => 'the saved trial info identifies a free trial');
    }

    //Otherwise: the earliest saved sale of the subscription must be the one that Simple Membership identifies as the
    //checkout payment, and this sale must be saved after it.
    $first_sale_ids = get_posts(array(
        'post_type' => 'swpm_transactions',
        'posts_per_page' => 1,
        'fields' => 'ids',
        'orderby' => 'ID',
        'order' => 'ASC',
        'meta_query' => array(
            array('key' => 'subscr_id', 'value' => $subscr_id, 'compare' => '='),
            array('key' => 'txn_type', 'value' => $sale_type, 'compare' => '='),
        ),
    ));
    if(empty($first_sale_ids)){
        return array('classification' => 'unknown', 'reason' => 'no saved payment found');
    }
    $first_sale_id = (int) $first_sale_ids[0];
    if($first_sale_id === (int) $row->id){
        return array('classification' => 'unknown', 'reason' => 'first saved payment of the subscription; the checkout payment or unclear');
    }
    $first_sale_row = SwpmTransactions::get_txn_post_meta_data_in_object_format($first_sale_id);
    $checkout_txn_id = SWPM_PayPal_Utility_Functions::get_checkout_txn_id_if_first_sale($first_sale_row);
    if(empty($checkout_txn_id)){
        return array('classification' => 'unknown', 'reason' => 'the first saved payment is not identified as the checkout payment');
    }
    $first_sale_time = strtotime(get_post_field('post_date_gmt', $first_sale_id));
    $this_sale_time = strtotime(get_post_field('post_date_gmt', (int) $row->id));
    if(empty($first_sale_time) || empty($this_sale_time) || $this_sale_time <= $first_sale_time){
        return array('classification' => 'unknown', 'reason' => 'not saved after the checkout payment');
    }
    return array('classification' => 'after_checkout', 'reason' => 'saved after the checkout payment');
}

/*
 * Settings page (under the Affiliates Manager menu).
 */
function wpam_swpm_integration_admin_menu($menu_parent_slug)
{
    add_submenu_page($menu_parent_slug, __("Simple Membership", 'wpam'), __("Simple Membership", 'wpam'), 'manage_options', 'wpam-swpm-integration', 'wpam_swpm_integration_admin_interface');
}

add_action('wpam_after_main_admin_menu', 'wpam_swpm_integration_admin_menu');

function wpam_swpm_integration_admin_interface()
{
    if(!current_user_can('manage_options')){
        return;
    }
    echo '<div class="wrap"><h2>Affiliates Manager and Simple Membership</h2>';

    if(isset($_POST['wpam_swpm_save_settings'])){
        check_admin_referer('wpam_swpm_save_settings');
        $value = (isset($_POST['wpam_swpm_renewal_commission']) && $_POST['wpam_swpm_renewal_commission'] === 'yes') ? 'yes' : 'no';
        update_option('wpam_swpm_renewal_commission', $value);
        echo '<div id="message" class="updated fade"><p>Settings saved.</p></div>';
    }

    $setting = wpam_swpm_get_renewal_setting();
    ?>
    <form action="" method="POST">
        <?php wp_nonce_field('wpam_swpm_save_settings'); ?>
        <table class="form-table">
            <tr valign="top">
                <th scope="row">Commission on renewal payments</th>
                <td>
                    <label><input type="radio" name="wpam_swpm_renewal_commission" value="yes" <?php checked($setting, 'yes'); ?> /> Yes: enable commissions on supported renewal payments.</label><br />
                    <label><input type="radio" name="wpam_swpm_renewal_commission" value="no" <?php checked($setting, 'no'); ?> /> No: disable renewal commissions; keep checkout commissions.</label>
                    <p class="description">With a free trial, the payments after the trial are renewals. PayPal subscription payments that can't be told apart from the checkout payment get no commission.</p>
                    <?php if($setting == 'legacy'){ ?>
                        <p class="description"><strong>Not saved yet.</strong> Until you save it: PayPal Standard renewals get a commission, Stripe and PayPal subscription renewals don't.</p>
                    <?php } ?>
                    <?php if(!class_exists('SWPM_PayPal_Utility_Functions') || !method_exists('SWPM_PayPal_Utility_Functions', 'get_checkout_txn_id_if_first_sale')){ ?>
                        <p class="description"><strong>Note:</strong> renewal commissions for PayPal subscriptions (the PayPal subscription buttons, not PayPal Standard) need a newer version of Simple Membership. With this version they don't get a commission.</p>
                    <?php } ?>
                </td>
            </tr>
        </table>
        <input type="submit" name="wpam_swpm_save_settings" class="button-primary" value="Save" />
    </form>
    </div>
    <?php
}

/*
 * On a new site (no Simple Membership payment records), the renewal setting starts as 'no'. Other sites keep the
 * behaviour of version 1.0.4 until the setting is saved.
 */
function wpam_swpm_integration_activate()
{
    if(get_option('wpam_swpm_renewal_commission', false) === false && !wpam_swpm_site_has_payment_records()){
        add_option('wpam_swpm_renewal_commission', 'no');
    }
}

register_activation_hook(__FILE__, 'wpam_swpm_integration_activate');

function wpam_swpm_site_has_payment_records()
{
    global $wpdb;
    if($wpdb->get_var($wpdb->prepare("SELECT ID FROM {$wpdb->posts} WHERE post_type = %s LIMIT 1", 'swpm_transactions'))){
        return true;
    }
    $table = $wpdb->prefix.'swpm_payments_tbl';
    if($wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $wpdb->esc_like($table))) === $table){
        if($wpdb->get_var("SELECT id FROM {$table} LIMIT 1")){
            return true;
        }
    }
    return false;
}
