<?php
/**
 * AJAX Handlers for Woo Invoice to RepairShopr
 * 
 * Handles all AJAX requests from the admin interface
 */

if (!defined('ABSPATH')) {
    exit;
}

class WIR_AJAX_Handlers {
    
    /**
     * Initialize AJAX handlers
     */
    public static function init() {
        add_action('wp_ajax_woo_inv_to_rs_send_to_repairshopr', array(__CLASS__, 'send_invoice'));
        add_action('wp_ajax_woo_inv_to_rs_send_payment_to_repairshopr', array(__CLASS__, 'send_payment'));
        add_action('wp_ajax_woo_inv_to_rs_verify_invoice', array(__CLASS__, 'verify_invoice'));
        add_action('wp_ajax_woo_inv_to_rs_verify_payment', array(__CLASS__, 'verify_payment'));
        add_action('wp_ajax_woo_inv_to_rs_test_api', array(__CLASS__, 'test_api'));
    }

    /**
     * AJAX handler for testing API connection
     */
    public static function test_api() {
        if (!current_user_can('manage_options') || !check_ajax_referer('woo_inv_to_rs_test_api', 'nonce', false)) {
            wp_send_json_error('Unauthorized');
        }

        $api_base = WIR_API_Client::get_api_base();
        $api_key  = WIR_API_Client::get_api_key();

        if (empty($api_base) || empty($api_key)) {
            wp_send_json_error('API URL or API Key not configured. Save your settings first.');
        }

        $url = rtrim($api_base, '/') . '/payment_methods';
        $response = wp_remote_get($url, array(
            'headers' => array(
                'Authorization' => 'Bearer ' . $api_key,
                'Accept'        => 'application/json',
            ),
            'timeout' => 15,
        ));

        if (is_wp_error($response)) {
            wp_send_json_error('Connection failed: ' . $response->get_error_message());
        }

        $code = wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);

        if ($code === 401 || $code === 403) {
            wp_send_json_error('Authentication failed (HTTP ' . $code . '). Check your API Key.');
        }

        if ($code !== 200) {
            wp_send_json_error('Unexpected response (HTTP ' . $code . '). Check your API URL.');
        }

        if (!empty($body['payment_methods'])) {
            $count = count($body['payment_methods']);
            wp_send_json_success('Connected! Found ' . $count . ' payment method' . ($count !== 1 ? 's' : '') . '.');
        }

        wp_send_json_success('Connected to RepairShopr API.');
    }

    /**
     * AJAX handler for sending invoice to RepairShopr
     */
    public static function send_invoice() {
        error_log('woo_inv_to_rs: AJAX handler for Send Invoice triggered');

        if (!current_user_can('edit_shop_orders') || !check_ajax_referer('woo_inv_to_rs_nonce', 'nonce', false)) {
            error_log('woo_inv_to_rs: Permission check failed (Send Invoice)');
            wp_send_json_error(array('message' => 'Permission denied'));
            return;
        }

        $order_id = isset($_POST['order_id']) ? intval($_POST['order_id']) : 0;
        error_log('woo_inv_to_rs: Order ID (Send Invoice): ' . $order_id);

        if ($order_id > 0) {
            // Check if auto-sync invoice is enabled
            if (get_option('woo_inv_to_rs_auto_sync_invoice', '') !== '1') {
                error_log("woo_inv_to_rs: Auto-sync invoice is disabled. Processing manual invoice sync for order $order_id.");
            }

            $result = WIR_Invoice_Handler::send_invoice_to_repairshopr($order_id);
            
            // Handle different result types
            if (is_array($result) && isset($result['exists']) && $result['exists']) {
                // Invoice already exists
                error_log('woo_inv_to_rs: Invoice already exists for order ' . $order_id);
                wp_send_json_error(array('message' => $result['message']));
            } elseif ($result === true) {
                // Invoice successfully created
                error_log('woo_inv_to_rs: Invoice sent to RepairShopr for order ' . $order_id);
                wp_send_json_success(array('message' => 'Invoice sent to RepairShopr'));
            } else {
                // Failed to create invoice
                error_log('woo_inv_to_rs: Failed to send invoice to RepairShopr for order ' . $order_id);
                wp_send_json_error(array('message' => 'Failed to send invoice to RepairShopr. Check error logs for details.'));
            }
        } else {
            error_log('woo_inv_to_rs: Invalid order ID (Send Invoice)');
            wp_send_json_error(array('message' => 'Invalid order ID'));
        }
    }

    /**
     * AJAX handler for sending payment to RepairShopr
     */
    public static function send_payment() {
        error_log('woo_inv_to_rs: AJAX handler for Send Payment triggered');

        if (!current_user_can('edit_shop_orders') || !check_ajax_referer('woo_inv_to_rs_nonce', 'nonce', false)) {
            error_log('woo_inv_to_rs: Permission check failed (Send Payment)');
            wp_send_json_error(array('message' => 'Permission denied'));
            return;
        }

        $order_id = isset($_POST['order_id']) ? intval($_POST['order_id']) : 0;
        error_log('woo_inv_to_rs: Order ID (Send Payment): ' . $order_id);

        // Call the payment handler
        $result = WIR_Payment_Handler::send_payment_to_repairshopr($order_id);
        
        if ($result['success']) {
            wp_send_json_success(array('message' => $result['message']));
        } else {
            wp_send_json_error(array('message' => $result['message']));
        }
    }

    /**
     * AJAX handler for verifying invoice totals
     */
    public static function verify_invoice() {
        error_log('woo_inv_to_rs: AJAX handler for Verify Invoice triggered');

        if (!current_user_can('edit_shop_orders') || !check_ajax_referer('woo_inv_to_rs_nonce', 'nonce', false)) {
            error_log('woo_inv_to_rs: Permission check failed (Verify Invoice)');
            wp_send_json_error(array('message' => 'Permission denied'));
            return;
        }

        $order_id = isset($_POST['order_id']) ? intval($_POST['order_id']) : 0;
        error_log('woo_inv_to_rs: Order ID (Verify Invoice): ' . $order_id);

        if ($order_id <= 0) {
            error_log('woo_inv_to_rs: Invalid order ID (Verify Invoice)');
            wp_send_json_error(array('message' => 'Invalid order ID'));
            return;
        }

        $order = wc_get_order($order_id);
        if (!$order) {
            error_log('woo_inv_to_rs: Order not found (Verify Invoice)');
            wp_send_json_error(array('message' => 'Order not found'));
            return;
        }

        $woocommerce_total = floatval($order->get_total());
        $invoice_prefix = get_option('woo_inv_to_rs_invoice_prefix', '');
        $invoice_number = $invoice_prefix . $order->get_order_number();
        
        $invoice = WIR_API_Client::get_invoice_by_number($invoice_number);

        if (!$invoice || !isset($invoice['total'])) {
            error_log('woo_inv_to_rs: RepairShopr invoice not found or total missing for invoice number: ' . $invoice_number);
            wp_send_json_error(array(
                'message' => sprintf(
                    'RepairShopr Invoice %s not found or total missing.',
                    esc_html($invoice_number)
                )
            ));
            return;
        }

        $repairshopr_total = floatval($invoice['total']);

        error_log('woo_inv_to_rs: WooCommerce Total: ' . $woocommerce_total . ', RepairShopr Total: ' . $repairshopr_total);

        $difference = abs($woocommerce_total - $repairshopr_total);
        
        if ($difference == 0) { // Exact match only
            wp_send_json_success(array(
                'message' => 'Totals Match!',
                'match' => true,
                'woocommerce_total' => $woocommerce_total,
                'repairshopr_total' => $repairshopr_total,
                'difference' => $difference
            ));
        } else {
            wp_send_json_success(array(
                'message' => sprintf('Totals Mismatch! Difference: $%.2f', $difference),
                'match' => false,
                'woocommerce_total' => $woocommerce_total,
                'repairshopr_total' => $repairshopr_total,
                'difference' => $difference
            ));
        }
    }

    /**
     * AJAX handler for verifying payment status
     */
    public static function verify_payment() {
        error_log('woo_inv_to_rs: AJAX handler for Verify Payment triggered');

        if (!current_user_can('edit_shop_orders') || !check_ajax_referer('woo_inv_to_rs_nonce', 'nonce', false)) {
            error_log('woo_inv_to_rs: Permission check failed (Verify Payment)');
            wp_send_json_error(array('message' => 'Permission denied'));
            return;
        }

        $order_id = isset($_POST['order_id']) ? intval($_POST['order_id']) : 0;
        error_log('woo_inv_to_rs: Order ID (Verify Payment): ' . $order_id);

        // Call the payment handler verification method
        $result = WIR_Payment_Handler::verify_payment_status($order_id);
        
        if ($result['success']) {
            wp_send_json_success($result);
        } else {
            wp_send_json_error($result);
        }
    }
}
