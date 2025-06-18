<?php
/*
Plugin Name: Woo Invoice to RepairShopr
Plugin URI: https://github.com/dataforge/woo-invoice-to-repairshopr
Description: Sends invoice details to RepairShopr when an invoice is paid in WooCommerce.
Version: 1.11
Author: Dataforge
GitHub Plugin URI: https://github.com/dataforge/woo-invoice-to-repairshopr

*/

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Securely store an API key in the WordPress options table.
 * Uses AES-256-CBC encryption with AUTH_KEY or a custom secret.
 *
 * @param string $option_name The option key to store the encrypted value under.
 * @param string $api_key The plaintext API key to store.
 */
function save_encrypted_api_key($option_name, $api_key) {
    $secret = defined('REPAIRSHOPR_SYNC_SECRET') ? REPAIRSHOPR_SYNC_SECRET : (defined('AUTH_KEY') ? AUTH_KEY : '');
    if (!empty($secret)) {
        $iv = substr(hash('sha256', $secret), 0, 16);
        $encrypted = openssl_encrypt($api_key, 'AES-256-CBC', $secret, 0, $iv);
        update_option($option_name, $encrypted);
    } else {
        // Fallback: store plaintext (not recommended)
        update_option($option_name, $api_key);
    }
}

/**
 * Retrieve and decrypt an API key from the WordPress options table.
 * Uses AES-256-CBC decryption with AUTH_KEY or a custom secret.
 *
 * @param string $option_name The option key where the encrypted value is stored.
 * @return string|false The decrypted API key, or false if not found or decryption fails.
 */
function get_encrypted_api_key($option_name) {
    $secret = defined('REPAIRSHOPR_SYNC_SECRET') ? REPAIRSHOPR_SYNC_SECRET : (defined('AUTH_KEY') ? AUTH_KEY : '');
    $encrypted = get_option($option_name);
    if (!empty($secret) && !empty($encrypted)) {
        $iv = substr(hash('sha256', $secret), 0, 16);
        $decrypted = openssl_decrypt($encrypted, 'AES-256-CBC', $secret, 0, $iv);
        return $decrypted !== false ? $decrypted : false;
    }
    return $encrypted; // fallback: plaintext (not recommended)
}

// Wrapper for plugin usage
function woo_inv_to_rs_get_api_key() {
    $api_key = get_encrypted_api_key('woo_inv_to_rs_api_key');
    return $api_key ? $api_key : '';
}

function woo_inv_to_rs_set_api_key($api_key) {
    save_encrypted_api_key('woo_inv_to_rs_api_key', $api_key);
}

// Hook into WooCommerce order payment completed
add_action('woocommerce_payment_complete', 'woo_inv_to_rs_send_invoice_to_repairshopr');

function woo_inv_to_rs_send_invoice_to_repairshopr($order_id) {
    $order = wc_get_order($order_id);
    
    // Check if the order is paid
    if (!$order->is_paid()) {
        error_log("woo_inv_to_rs: Order $order_id is not paid. Skipping RepairShopr integration.");
        return;
    }

    $customer_email = $order->get_billing_email();

    error_log("woo_inv_to_rs: Starting to process paid order $order_id for customer email: $customer_email");

    // Check if invoice already exists in RepairShopr
    $invoice_prefix = get_option('woo_inv_to_rs_invoice_prefix', '');
    $invoice_number = $invoice_prefix . $order->get_order_number();
    $existing_invoice = woo_inv_to_rs_check_invoice_exists($invoice_number);
    
    if ($existing_invoice) {
        error_log("woo_inv_to_rs: Invoice $invoice_number already exists in RepairShopr with ID: " . $existing_invoice['id']);
        return array('exists' => true, 'invoice_id' => $existing_invoice['id'], 'message' => 'Invoice already exists in RepairShopr');
    }

    // Check if customer exists in RepairShopr
    $customer_id = woo_inv_to_rs_get_repairshopr_customer($customer_email);

    if (!$customer_id) {
        error_log("woo_inv_to_rs: Customer not found in RepairShopr. Attempting to create...");
        // Create customer in RepairShopr
        $customer_id = woo_inv_to_rs_create_repairshopr_customer($order);
        if (!$customer_id) {
            error_log('woo_inv_to_rs: Failed to create customer in RepairShopr');
            return false;
        }
        error_log("woo_inv_to_rs: Customer created in RepairShopr with ID: $customer_id");
    } else {
        error_log("woo_inv_to_rs: Customer found in RepairShopr with ID: $customer_id");
    }

    // Create invoice in RepairShopr
    $invoice_created = woo_inv_to_rs_create_repairshopr_invoice($order, $customer_id);
    if (!$invoice_created) {
        error_log('woo_inv_to_rs: Failed to create invoice in RepairShopr');
        return false;
    }

    error_log("woo_inv_to_rs: Invoice successfully created in RepairShopr for order $order_id");
    return true;
}

/**
 * Check if an invoice already exists in RepairShopr by invoice number
 *
 * @param string $invoice_number The invoice number to check
 * @return array|false Returns invoice data if found, false if not found
 */
function woo_inv_to_rs_check_invoice_exists($invoice_number) {
    $api_base = get_option('woo_inv_to_rs_api_url', '');
    if (empty($api_base)) {
        error_log('woo_inv_to_rs: API URL not configured for invoice check');
        return false;
    }
    
    $api_key = woo_inv_to_rs_get_api_key();
    if (empty($api_key)) {
        error_log('woo_inv_to_rs: API key not configured for invoice check');
        return false;
    }
    
    $invoice_url = rtrim($api_base, '/') . '/invoices/' . urlencode($invoice_number);
    
    error_log('woo_inv_to_rs: Checking if invoice exists: ' . $invoice_url);
    
    $response = wp_remote_get($invoice_url, array(
        'headers' => array(
            'Authorization' => 'Bearer ' . $api_key,
            'Accept' => 'application/json'
        )
    ));
    
    if (is_wp_error($response)) {
        error_log('woo_inv_to_rs: Error checking invoice existence: ' . $response->get_error_message());
        return false;
    }
    
    $body = wp_remote_retrieve_body($response);
    $data = json_decode($body, true);
    
    // Check if invoice was found
    if (!empty($data['invoice']['id'])) {
        error_log('woo_inv_to_rs: Invoice ' . $invoice_number . ' found with ID: ' . $data['invoice']['id']);
        return $data['invoice'];
    }
    
    error_log('woo_inv_to_rs: Invoice ' . $invoice_number . ' not found in RepairShopr');
    return false;
}

/**
 * Check if a payment already exists for an invoice in RepairShopr
 *
 * @param int $invoice_id The RepairShopr invoice ID
 * @param float $amount The payment amount to check for
 * @param string $transaction_id The WooCommerce transaction ID
 * @return array|false Returns payment data if found, false if not found
 */
function woo_inv_to_rs_check_payment_exists($invoice_id, $amount, $transaction_id = '') {
    $api_base = get_option('woo_inv_to_rs_api_url', '');
    if (empty($api_base)) {
        error_log('woo_inv_to_rs: API URL not configured for payment check');
        return false;
    }
    
    $api_key = woo_inv_to_rs_get_api_key();
    if (empty($api_key)) {
        error_log('woo_inv_to_rs: API key not configured for payment check');
        return false;
    }
    
    // Get invoice details to check existing payments
    $invoice_url = rtrim($api_base, '/') . '/invoices/' . $invoice_id;
    
    error_log('woo_inv_to_rs: Checking for existing payments on invoice ID: ' . $invoice_id);
    
    $response = wp_remote_get($invoice_url, array(
        'headers' => array(
            'Authorization' => 'Bearer ' . $api_key,
            'Accept' => 'application/json'
        )
    ));
    
    if (is_wp_error($response)) {
        error_log('woo_inv_to_rs: Error checking invoice for payments: ' . $response->get_error_message());
        return false;
    }
    
    $body = wp_remote_retrieve_body($response);
    $data = json_decode($body, true);
    
    if (empty($data['invoice']['payments'])) {
        error_log('woo_inv_to_rs: No payments found for invoice ID: ' . $invoice_id);
        return false;
    }
    
    $payments = $data['invoice']['payments'];
    $amount_formatted = number_format($amount, 2, '.', '');
    
    // Check for duplicate payments by amount and/or transaction ID
    foreach ($payments as $payment) {
        $payment_amount = isset($payment['payment_amount']) ? number_format(floatval($payment['payment_amount']), 2, '.', '') : '0.00';
        $payment_ref = isset($payment['ref_num']) ? $payment['ref_num'] : '';
        
        // Check if payment amount matches
        if ($payment_amount === $amount_formatted) {
            // If we have a transaction ID, check if it matches too
            if (!empty($transaction_id) && !empty($payment_ref)) {
                if ($payment_ref === $transaction_id) {
                    error_log('woo_inv_to_rs: Found duplicate payment by amount and transaction ID for invoice ' . $invoice_id . ': Payment ID ' . $payment['id']);
                    return $payment;
                }
            } else {
                // No transaction ID to compare, just match by amount
                error_log('woo_inv_to_rs: Found duplicate payment by amount for invoice ' . $invoice_id . ': Payment ID ' . $payment['id']);
                return $payment;
            }
        }
    }
    
    error_log('woo_inv_to_rs: No duplicate payment found for invoice ' . $invoice_id . ' with amount ' . $amount_formatted);
    return false;
}

function woo_inv_to_rs_get_repairshopr_customer($email) {
    $api_base = get_option('woo_inv_to_rs_api_url', '');
    if ($api_base) {
        // Remove any trailing /customers or /customers/ from the base
        // Always append /customers to the API base (which should NOT end with /customers)
        $base_url = rtrim($api_base, '/') . '/customers';
    } else {
        $base_url = get_option('woo_inv_to_rs_customer_url', 'https://your-subdomain.repairshopr.com/api/v1/customers');
    }
    // Convert email to lowercase to match RepairShopr's email normalization
    $api_url = $base_url . '?email=' . urlencode(strtolower($email));

    // Log the API request URL
    error_log('RepairShopr Get Customer API Request URL: ' . $api_url);

    $response = wp_remote_get($api_url, array(
        'headers' => array(
            'Authorization' => 'Bearer ' . woo_inv_to_rs_get_api_key(),
            'Accept' => 'application/json'
        )
    ));

    // Log the API response
    error_log('RepairShopr Get Customer API Response: ' . wp_remote_retrieve_body($response));

    if (is_wp_error($response)) {
        return false;
    }

    $body = wp_remote_retrieve_body($response);
    $data = json_decode($body, true);

    if (!empty($data['customers'])) {
        return $data['customers'][0]['id'];
    }

    return false;
}

function woo_inv_to_rs_create_repairshopr_customer($order) {
    $api_base = get_option('woo_inv_to_rs_api_url', '');
    if ($api_base) {
        // Remove any trailing /customers or /customers/ from the base
        // Always append /customers to the API base (which should NOT end with /customers)
        $api_url = rtrim($api_base, '/') . '/customers';
    } else {
        $api_url = get_option('woo_inv_to_rs_customer_url', 'https://your-subdomain.repairshopr.com/api/v1/customers');
    }

    // Gather as many fields as possible from WooCommerce order
    $billing_firstname = $order->get_billing_first_name();
    $billing_lastname = $order->get_billing_last_name();
    $billing_fullname = trim($billing_firstname . ' ' . $billing_lastname);
    $billing_company = $order->get_billing_company();
    $billing_email = $order->get_billing_email();
    $billing_phone = $order->get_billing_phone();
    $billing_address_1 = $order->get_billing_address_1();
    $billing_address_2 = $order->get_billing_address_2();
    $billing_city = $order->get_billing_city();
    $billing_state = $order->get_billing_state();
    $billing_postcode = $order->get_billing_postcode();

    // Shipping info as fallback if billing is missing
    $shipping_firstname = $order->get_shipping_first_name();
    $shipping_lastname = $order->get_shipping_last_name();
    $shipping_fullname = trim($shipping_firstname . ' ' . $shipping_lastname);
    $shipping_company = $order->get_shipping_company();
    $shipping_email = method_exists($order, 'get_shipping_email') ? $order->get_shipping_email() : '';
    $shipping_phone = method_exists($order, 'get_shipping_phone') ? $order->get_shipping_phone() : '';
    $shipping_address_1 = $order->get_shipping_address_1();
    $shipping_address_2 = $order->get_shipping_address_2();
    $shipping_city = $order->get_shipping_city();
    $shipping_state = $order->get_shipping_state();
    $shipping_postcode = $order->get_shipping_postcode();

    // Use billing as primary, fallback to shipping if empty
    $firstname = $billing_firstname ?: $shipping_firstname;
    $lastname = $billing_lastname ?: $shipping_lastname;
    $fullname = $billing_fullname ?: $shipping_fullname;
    $business_name = $billing_company ?: $shipping_company;
    $email = strtolower($billing_email ?: $shipping_email); // Convert email to lowercase to match RepairShopr's normalization
    $phone = $billing_phone ?: $shipping_phone;
    $address = $billing_address_1 ?: $shipping_address_1;
    $address_2 = $billing_address_2 ?: $shipping_address_2;
    $city = $billing_city ?: $shipping_city;
    $state = $billing_state ?: $shipping_state;
    $zip = $billing_postcode ?: $shipping_postcode;

    // Build the customer body
    $body = array(
        'firstname' => $firstname,
        'lastname' => $lastname,
        'fullname' => $fullname,
        'business_name' => $business_name,
        'email' => $email,
        'phone' => $phone,
        'mobile' => $phone,
        'address' => $address,
        'address_2' => $address_2,
        'city' => $city,
        'state' => $state,
        'zip' => $zip,
        'notes' => get_option('woo_inv_to_rs_notes', 'Created by WooCommerce'),
        'get_sms' => get_option('woo_inv_to_rs_get_sms', '1') === '1',
        'opt_out' => get_option('woo_inv_to_rs_opt_out', '') === '1',
        'no_email' => get_option('woo_inv_to_rs_no_email', '') === '1',
        'get_billing' => get_option('woo_inv_to_rs_get_billing', '1') === '1',
        'get_marketing' => get_option('woo_inv_to_rs_get_marketing', '1') === '1',
        'get_reports' => get_option('woo_inv_to_rs_get_reports', '1') === '1',
        'tax_rate_id' => get_option('woo_inv_to_rs_tax_rate_id', '40354'),
        'properties' => array(),
        'consent' => array()
    );

    // Add additional fields if available (null if not)
    $body['address'] = $address ?: null;
    $body['address_2'] = $address_2 ?: null;
    $body['city'] = $city ?: null;
    $body['state'] = $state ?: null;
    $body['zip'] = $zip ?: null;
    $body['business_and_full_name'] = $business_name ? $business_name : $fullname;
    $body['business_then_name'] = $business_name ? $business_name : $fullname;

    // Log the API request
    error_log('RepairShopr Customer API Request: ' . json_encode($body));

    $response = wp_remote_post($api_url, array(
        'headers' => array(
            'Authorization' => 'Bearer ' . woo_inv_to_rs_get_api_key(),
            'Content-Type' => 'application/json'
        ),
        'body' => json_encode($body)
    ));

    // Log the API response
    error_log('RepairShopr Customer API Response: ' . wp_remote_retrieve_body($response));

    if (is_wp_error($response)) {
        return false;
    }

    $body = wp_remote_retrieve_body($response);
    $data = json_decode($body, true);

    // Check if customer creation was successful before accessing the customer ID
    if (isset($data['customer']['id'])) {
        return $data['customer']['id'];
    } else {
        // Log the error response for debugging
        error_log('woo_inv_to_rs: RepairShopr Customer Creation Failed: ' . json_encode($data));
        return false;
    }
}

function woo_inv_to_rs_create_repairshopr_invoice($order, $customer_id) {
    $api_base = get_option('woo_inv_to_rs_api_url', '');
    if ($api_base) {
        $api_url = rtrim($api_base, '/') . '/invoices';
    } else {
        $api_url = get_option('woo_inv_to_rs_invoice_url', 'https://your-subdomain.repairshopr.com/api/v1/invoices');
    }

    // Get WooCommerce authoritative totals (these are the correct values)
    $wc_subtotal = $order->get_subtotal();
    $wc_total_tax = $order->get_total_tax();
    $wc_total = $order->get_total();
    $wc_fees_total = 0;
    
    error_log('woo_inv_to_rs: WooCommerce Authoritative Totals - Subtotal: ' . $wc_subtotal . ', Tax: ' . $wc_total_tax . ', Total: ' . $wc_total);

    // Build line_items array from order items with precision handling
    $line_items = array();
    $line_item_totals = array(); // Track individual totals for precision adjustment

    foreach ($order->get_items() as $item) {
        $product = $item->get_product();
        $sku = $product ? $product->get_sku() : '';
        $product_id = $sku ? $sku : 0;
        $quantity = $item->get_quantity();
        
        // Use WooCommerce's calculated line total, not recalculated price
        $line_total = $item->get_total();
        $price = $quantity > 0 ? $line_total / $quantity : 0;
        
        // Store with high precision for later adjustment
        $line_item_totals[] = $line_total;

        // Only set 'taxable' if the setting is enabled AND the order is taxable (sales tax charged)
        $taxable_flag = (get_option('woo_inv_to_rs_taxable', '1') === '1') && ($wc_total_tax > 0);

        $line_items[] = array(
            'item' => $product ? $product->get_name() : $item->get_name(),
            'name' => $product ? $product->get_name() : $item->get_name(),
            'product_id' => $product_id,
            'quantity' => (int)$quantity,
            'cost' => 0,
            'price' => $price, // Keep high precision initially
            'discount_percent' => 0,
            'taxable' => $taxable_flag,
            'wc_line_total' => $line_total // Store WooCommerce line total for reference
        );
    }

    // Add Electronic Payment Fee as a line item if present
    $epf_name = trim(get_option('woo_inv_to_rs_epf_name', 'Electronic Payment Fee'));
    $epf_product_id = trim(get_option('woo_inv_to_rs_epf_product_id', '9263351'));
    if ($epf_name !== '' && $epf_product_id !== '') {
        foreach ($order->get_fees() as $fee) {
            error_log('woo_inv_to_rs: Found fee: ' . $fee->get_name() . ' value: ' . $fee->get_total());
            if ($fee->get_name() == $epf_name) {
                $fee_total = $fee->get_total();
                $wc_fees_total += $fee_total;
                error_log('woo_inv_to_rs: Matched Electronic Payment Fee "' . $epf_name . '" with value: ' . $fee_total);
                
                // Only set 'taxable' if the setting is enabled AND the order is taxable (sales tax charged)
                $taxable_flag = (get_option('woo_inv_to_rs_taxable', '1') === '1') && ($wc_total_tax > 0);
                
                $line_items[] = array(
                    'item' => $epf_name,
                    'name' => $epf_name,
                    'product_id' => $epf_product_id,
                    'quantity' => 1,
                    'cost' => 0,
                    'price' => $fee_total, // Keep exact fee amount
                    'discount_percent' => 0,
                    'taxable' => $taxable_flag,
                    'upc_code' => '',
                    'tax_note' => '',
                    'wc_line_total' => $fee_total
                );
                $line_item_totals[] = $fee_total;
                break;
            }
        }
    }

    // Calculate current subtotal from line items
    $calculated_subtotal = array_sum($line_item_totals);
    
    // Apply precision correction to ensure exact match with WooCommerce subtotal + fees
    $target_subtotal = $wc_subtotal + $wc_fees_total;
    $subtotal_difference = $target_subtotal - $calculated_subtotal;
    
    error_log('woo_inv_to_rs: Subtotal Analysis - WC Subtotal: ' . $wc_subtotal . ', WC Fees: ' . $wc_fees_total . ', Target: ' . $target_subtotal . ', Calculated: ' . $calculated_subtotal . ', Difference: ' . $subtotal_difference);

    // Distribute any rounding difference proportionally across line items
    if (abs($subtotal_difference) > 0.001 && !empty($line_items)) {
        error_log('woo_inv_to_rs: Applying precision correction of ' . $subtotal_difference);
        
        // Find the largest line item to absorb the difference (most stable approach)
        $largest_item_index = 0;
        $largest_amount = 0;
        
        for ($i = 0; $i < count($line_items); $i++) {
            if ($line_items[$i]['wc_line_total'] > $largest_amount) {
                $largest_amount = $line_items[$i]['wc_line_total'];
                $largest_item_index = $i;
            }
        }
        
        // Apply the correction to the largest line item
        $quantity = $line_items[$largest_item_index]['quantity'];
        if ($quantity > 0) {
            $adjusted_price = $line_items[$largest_item_index]['price'] + ($subtotal_difference / $quantity);
            $line_items[$largest_item_index]['price'] = $adjusted_price;
            error_log('woo_inv_to_rs: Applied precision correction to line item ' . $largest_item_index . ': ' . $subtotal_difference . ', new price: ' . $adjusted_price);
        }
    }

    // Final formatting of prices for RepairShopr API
    foreach ($line_items as &$item) {
        $item['price'] = number_format($item['price'], 2, '.', '');
        unset($item['wc_line_total']); // Remove our tracking field
    }
    unset($item); // Break reference

// Create invoice with just the first line item
$first_line_item = array_shift($line_items);
$body = array(
    'balance_due' => '0.00',
    'customer_id' => $customer_id,
    'number' => get_option('woo_inv_to_rs_invoice_prefix', '') . $order->get_order_number(),
    'date' => $order->get_date_created()->format('Y-m-d\TH:i:s.000P'),
    'customer_business_then_name' => $order->get_billing_company() ?: $order->get_formatted_billing_full_name(),
    'due_date' => $order->get_date_created()->format('Y-m-d'),
    'subtotal' => number_format($order->get_subtotal(), 2, '.', ''),
    'total' => number_format($order->get_total(), 2, '.', ''),
    'tax' => number_format($order->get_total_tax(), 2, '.', ''),
    // Only set paid flags if the setting is enabled AND the order is paid
    'verified_paid' => (get_option('woo_inv_to_rs_verified_paid', '1') === '1') && $order->is_paid(),
    'tech_marked_paid' => (get_option('woo_inv_to_rs_tech_marked_paid', '1') === '1') && $order->is_paid(),
    'is_paid' => (get_option('woo_inv_to_rs_is_paid', '1') === '1') && $order->is_paid(),
    'note' => get_option('woo_inv_to_rs_invoice_note', 'Order created from WooCommerce'),
    'line_items' => array($first_line_item)
);

    error_log('RepairShopr API Request (Create Invoice): ' . json_encode($body));

    $response = wp_remote_post($api_url, array(
        'headers' => array(
            'Authorization' => 'Bearer ' . woo_inv_to_rs_get_api_key(),
            'Content-Type' => 'application/json',
            'Accept' => 'application/json'
        ),
        'body' => json_encode($body)
    ));

    error_log('RepairShopr API Response (Create Invoice): ' . wp_remote_retrieve_body($response));

    if (is_wp_error($response)) {
        error_log('RepairShopr API Error: ' . $response->get_error_message());
        return false;
    }

    $response_data = json_decode(wp_remote_retrieve_body($response), true);

    if (!isset($response_data['invoice']['id'])) {
        error_log('Failed to create invoice in RepairShopr');
        return false;
    }

    $invoice_id = $response_data['invoice']['id'];

    // Add remaining line items one by one
    if (!empty($line_items)) {
        $api_base = get_option('woo_inv_to_rs_api_url', '');
        if ($api_base) {
            $line_item_url = rtrim($api_base, '/') . '/invoices/' . $invoice_id . '/line_items';
        } else {
            // Use the default only if the user has not set the API URL
            $line_item_url = get_option('woo_inv_to_rs_invoice_url', 'https://your-subdomain.repairshopr.com/api/v1/invoices');
            $line_item_url = rtrim($line_item_url, '/') . '/' . $invoice_id . '/line_items';
        }

        foreach ($line_items as $li) {
            // Check if this is an Electronic Payment Fee BEFORE modifying the line item data
            $epf_name = trim(get_option('woo_inv_to_rs_epf_name', 'Electronic Payment Fee'));
            $epf_product_id = trim(get_option('woo_inv_to_rs_epf_product_id', '9263351'));
            $is_epf = (
                isset($li['product_id']) && $li['product_id'] == $epf_product_id &&
                isset($li['item']) && $li['item'] == $epf_name
            );
            
            error_log('woo_inv_to_rs: Processing line item - EPF Name: "' . $epf_name . '", EPF Product ID: "' . $epf_product_id . '", Is EPF: ' . ($is_epf ? 'true' : 'false'));
            if (isset($li['item'])) {
                error_log('woo_inv_to_rs: Line item name: "' . $li['item'] . '"');
            }
            if (isset($li['product_id'])) {
                error_log('woo_inv_to_rs: Line item product_id: "' . $li['product_id'] . '"');
            }
            
            // If product_id is set, do not send 'item' or 'name' (RepairShopr will use the product)
            if (!empty($li['product_id'])) {
                $li_body = array(
                    'id' => 0,
                    'line_discount_percent' => 0,
                    'discount_dollars' => '0',
                    'product_id' => $li['product_id'],
                    'price' => isset($li['price']) ? floatval($li['price']) : 0.0,
                    'cost' => 0,
                    'quantity' => isset($li['quantity']) ? floatval($li['quantity']) : 1.0,
                    'taxable' => isset($li['taxable']) ? $li['taxable'] : false
                );
            } else {
                $li_body = array(
                    'id' => 0,
                    'line_discount_percent' => 0,
                    'discount_dollars' => '0',
                    'item' => isset($li['item']) ? $li['item'] : '',
                    'name' => isset($li['item']) ? $li['item'] : '',
                    'price' => isset($li['price']) ? floatval($li['price']) : 0.0,
                    'cost' => 0,
                    'quantity' => isset($li['quantity']) ? floatval($li['quantity']) : 1.0,
                    'taxable' => isset($li['taxable']) ? $li['taxable'] : false
                );
            }
            error_log('RepairShopr API Request (Add Line Item): ' . json_encode($li_body));
            $li_response = wp_remote_post($line_item_url, array(
                'headers' => array(
                    'Authorization' => 'Bearer ' . woo_inv_to_rs_get_api_key(),
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json'
                ),
                'body' => json_encode($li_body)
            ));
            $li_response_body = wp_remote_retrieve_body($li_response);
            error_log('RepairShopr API Response (Add Line Item): ' . $li_response_body);
            if (is_wp_error($li_response)) {
                error_log('Failed to add line item to invoice ' . $invoice_id . ': ' . $li_response->get_error_message());
            } else {
                // Special handling for Electronic Payment Fee: update price with PUT if needed
                $li_response_data = json_decode($li_response_body, true);
                if ($is_epf && isset($li_response_data['line_item']['id'])) {
                    $line_item_id = $li_response_data['line_item']['id'];
                    $epf_price = isset($li['price']) ? floatval($li['price']) : 0.0;
                    
                    // Debug: Log the original line item data to see what price we have
                    error_log('woo_inv_to_rs: EPF Line Item Debug - Original $li array: ' . json_encode($li));
                    error_log('woo_inv_to_rs: EPF Line Item Debug - Extracted price: ' . $epf_price);
                    
                    // If price is 0, try to get the fee amount directly from the order
                    if ($epf_price == 0.0) {
                        error_log('woo_inv_to_rs: EPF price is 0, attempting to get fee amount directly from order');
                        foreach ($order->get_fees() as $fee) {
                            if ($fee->get_name() == $epf_name) {
                                $epf_price = floatval($fee->get_total());
                                error_log('woo_inv_to_rs: Found EPF in order fees with amount: ' . $epf_price);
                                break;
                            }
                        }
                    }
                    
                    $update_line_item = array(
                        'price' => $epf_price
                    );
                    $update_url = rtrim($line_item_url, '/') . '/' . $line_item_id;
                    error_log('woo_inv_to_rs: Electronic Payment Fee detected - updating price to: ' . $epf_price);
                    error_log('RepairShopr API Request (Update Fee Line Item): ' . json_encode($update_line_item));
                    $update_response = wp_remote_request($update_url, array(
                        'method' => 'PUT',
                        'headers' => array(
                            'Authorization' => 'Bearer ' . woo_inv_to_rs_get_api_key(),
                            'Content-Type' => 'application/json',
                            'Accept' => 'application/json'
                        ),
                        'body' => json_encode($update_line_item)
                    ));
                    $update_body = wp_remote_retrieve_body($update_response);
                    error_log('RepairShopr API Response (Update Fee Line Item): ' . $update_body);
                    if (is_wp_error($update_response)) {
                        error_log('RepairShopr API Error (Update Fee Line Item): ' . $update_response->get_error_message());
                    } else {
                        $update_data = json_decode($update_body, true);
                        if (isset($update_data['line_item'])) {
                            error_log('woo_inv_to_rs: Electronic Payment Fee line item successfully updated with price: ' . $epf_price);
                            error_log('RepairShopr Line Item Updated: ' . json_encode($update_data['line_item']));
                        } else {
                            error_log('woo_inv_to_rs: Electronic Payment Fee line item update failed. Response: ' . $update_body);
                        }
                    }
                } elseif ($is_epf) {
                    error_log('woo_inv_to_rs: Electronic Payment Fee detected but could not get line_item ID from response');
                }
            }
        }
    }

    // Post-creation rounding correction check
    error_log('woo_inv_to_rs: Starting post-creation rounding correction check for Invoice ID: ' . $invoice_id);
    
    // Get rounding correction settings
    $rounding_correction_product_id = trim(get_option('woo_inv_to_rs_rounding_correction_product_id', ''));
    
    // Only proceed with rounding correction if Product ID is configured
    if ($rounding_correction_product_id !== '') {
        // Fetch the created invoice to check totals
        $verification_url = rtrim($api_base, '/') . '/invoices/' . $invoice_id;
        $verification_response = wp_remote_get($verification_url, array(
            'headers' => array(
                'Authorization' => 'Bearer ' . woo_inv_to_rs_get_api_key(),
                'Accept' => 'application/json'
            )
        ));
        
        if (!is_wp_error($verification_response)) {
            $verification_body = wp_remote_retrieve_body($verification_response);
            $verification_data = json_decode($verification_body, true);
            
            if (isset($verification_data['invoice'])) {
                $rs_invoice = $verification_data['invoice'];
                $rs_total = floatval($rs_invoice['total']);
                
                error_log('woo_inv_to_rs: Rounding Check - WC Total: ' . $wc_total . ', RS Total: ' . $rs_total);
                
                // Calculate the difference
                $total_difference = $wc_total - $rs_total;
                
                // Check if difference is within acceptable range for rounding correction
                if (abs($total_difference) > 0.001 && abs($total_difference) <= 0.10) {
                    error_log('woo_inv_to_rs: Rounding difference detected: ' . $total_difference . '. Adding rounding correction line item.');
                    
                    // Round the difference to exactly 2 decimal places to avoid precision issues
                    $rounded_difference = round($total_difference, 2);
                    
                    // Add rounding correction line item
                    $line_item_url = rtrim($api_base, '/') . '/invoices/' . $invoice_id . '/line_items';
                    
                    $rounding_correction_body = array(
                        'id' => 0,
                        'line_discount_percent' => 0,
                        'discount_dollars' => '0',
                        'product_id' => $rounding_correction_product_id,
                        'price' => number_format($rounded_difference, 2, '.', ''), // Format as string with 2 decimal places
                        'cost' => 0,
                        'quantity' => 1,
                        'taxable' => false // Rounding corrections should not be taxable
                    );
                    
                    error_log('woo_inv_to_rs: Adding rounding correction line item: ' . json_encode($rounding_correction_body));
                    
                    $rounding_response = wp_remote_post($line_item_url, array(
                        'headers' => array(
                            'Authorization' => 'Bearer ' . woo_inv_to_rs_get_api_key(),
                            'Content-Type' => 'application/json',
                            'Accept' => 'application/json'
                        ),
                        'body' => json_encode($rounding_correction_body)
                    ));
                    
                    if (!is_wp_error($rounding_response)) {
                        $rounding_result = wp_remote_retrieve_body($rounding_response);
                        error_log('woo_inv_to_rs: Rounding correction line item added: ' . $rounding_result);
                        
                        $rounding_data = json_decode($rounding_result, true);
                        if (isset($rounding_data['line_item'])) {
                            error_log('woo_inv_to_rs: Rounding correction successfully applied. Amount: ' . $total_difference);
                        }
                    } else {
                        error_log('woo_inv_to_rs: Failed to add rounding correction line item: ' . $rounding_response->get_error_message());
                    }
                } elseif (abs($total_difference) > 0.10) {
                    error_log('woo_inv_to_rs: Total difference (' . $total_difference . ') exceeds maximum rounding correction limit of $0.10. No correction applied.');
                } else {
                    error_log('woo_inv_to_rs: Totals match within acceptable precision. No rounding correction needed.');
                }
            }
        } else {
            error_log('woo_inv_to_rs: Could not verify invoice totals for rounding correction: ' . $verification_response->get_error_message());
        }
    } else {
        error_log('woo_inv_to_rs: Rounding correction not configured. Skipping rounding correction check.');
    }

    error_log('RepairShopr Invoice created successfully with all line items. Invoice ID: ' . $invoice_id);
    return true;
}

/**
 * Add RepairShopr column to WooCommerce Orders table (legacy and HPOS).
 */
// Legacy orders table
add_filter('manage_edit-shop_order_columns', 'woo_inv_to_rs_add_order_repairshopr_column');
function woo_inv_to_rs_add_order_repairshopr_column($columns) {
    $new_columns = array();
    foreach ($columns as $key => $column) {
        $new_columns[$key] = $column;
        if ($key === 'order_number') {
            $new_columns['repairshopr'] = __('RepairShopr', 'woocommerce-invoice-to-repairshopr');
        }
    }
    return $new_columns;
}

// Legacy orders table content
add_action('manage_shop_order_posts_custom_column', 'woo_inv_to_rs_order_repairshopr_column_content', 20, 2);
function woo_inv_to_rs_order_repairshopr_column_content($column, $post_id) {
    error_log('woo_inv_to_rs: custom column content called. $column=' . $column . ', $post_id=' . $post_id);
    if ($column == 'repairshopr') {
        error_log('woo_inv_to_rs: rendering Send Invoice and Send Payment buttons for order ' . $post_id);
        echo '<button type="button" class="button woo_inv_to_rs-send-to-repairshopr" data-order-id="' . esc_attr($post_id) . '">Send Invoice</button> ';
        echo '<button type="button" class="button woo_inv_to_rs-send-payment" data-order-id="' . esc_attr($post_id) . '">Send Payment</button> ';
        echo '<button type="button" class="button woo_inv_to_rs-verify-invoice" data-order-id="' . esc_attr($post_id) . '">RS Verify Invoice</button> ';
        echo '<button type="button" class="button woo_inv_to_rs-verify-payment" data-order-id="' . esc_attr($post_id) . '">RS Verify Payment</button>';
    }
}

// HPOS orders table column
add_filter('woocommerce_shop_order_list_table_columns', 'woo_inv_to_rs_hpos_add_order_repairshopr_column', 20);
function woo_inv_to_rs_hpos_add_order_repairshopr_column($columns) {
    $new_columns = array();
    foreach ($columns as $key => $column) {
        $new_columns[$key] = $column;
        if ($key === 'order_number') {
            $new_columns['repairshopr'] = __('RepairShopr', 'woocommerce-invoice-to-repairshopr');
        }
    }
    return $new_columns;
}

// HPOS orders table content
add_action('woocommerce_shop_order_list_table_custom_column', 'woo_inv_to_rs_hpos_order_repairshopr_column_content', 20, 2);
function woo_inv_to_rs_hpos_order_repairshopr_column_content($column, $order) {
    if ($column === 'repairshopr') {
        $order_id = is_object($order) && method_exists($order, 'get_id') ? $order->get_id() : (is_numeric($order) ? $order : 0);
        if ($order_id) {
            echo '<button type="button" class="button woo_inv_to_rs-send-to-repairshopr" data-order-id="' . esc_attr($order_id) . '">Send Invoice</button> ';
            echo '<button type="button" class="button woo_inv_to_rs-send-payment" data-order-id="' . esc_attr($order_id) . '">Send Payment</button> ';
            echo '<button type="button" class="button woo_inv_to_rs-verify-invoice" data-order-id="' . esc_attr($order_id) . '">RS Verify Invoice</button> ';
            echo '<button type="button" class="button woo_inv_to_rs-verify-payment" data-order-id="' . esc_attr($order_id) . '">RS Verify Payment</button>';
        }
    }
}

/**
 * Enqueue JavaScript for AJAX functionality on legacy and new WooCommerce orders pages.
 */
function woo_inv_to_rs_enqueue_admin_scripts($hook) {
    error_log('woo_inv_to_rs: admin_enqueue_scripts called. $hook=' . $hook . ', $_GET[page]=' . (isset($_GET['page']) ? $_GET['page'] : 'NOT SET'));
    // Legacy orders page (edit.php?post_type=shop_order) or legacy table on wc-orders page
    if (
        ('edit.php' === $hook && isset($_GET['post_type']) && $_GET['post_type'] === 'shop_order') ||
        ($hook === 'woocommerce_page_wc-orders')
    ) {
        error_log('woo_inv_to_rs: Attempting to enqueue admin script (legacy orders page or legacy table on wc-orders)');
        wp_enqueue_script('wir-admin-script', plugin_dir_url(__FILE__) . 'admin-script.js', array('jquery'), time(), true);
        wp_localize_script('wir-admin-script', 'woo_inv_to_rs_ajax', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('woo_inv_to_rs_nonce')
        ));
        error_log('woo_inv_to_rs: Admin script enqueued (legacy or wc-orders legacy table)');
    }

    // New WooCommerce Admin (wc-orders) React-based page
    if (
        $hook === 'toplevel_page_wc-orders' ||
        $hook === 'woocommerce_page_wc-orders' ||
        (isset($_GET['page']) && $_GET['page'] === 'wc-orders')
    ) {
        error_log('woo_inv_to_rs: Attempting to enqueue admin script (wc-orders React page)');
        wp_enqueue_script(
            'wir-admin-wc-orders',
            plugin_dir_url(__FILE__) . 'admin-wc-orders.js',
            array(), // No dependencies, uses global wp/wc
            time(), // Use time() for cache busting
            true
        );
        // Localize nonce for AJAX
        wp_localize_script('wir-admin-wc-orders', 'woo_inv_to_rs_ajax', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('woo_inv_to_rs_nonce')
        ));
        error_log('woo_inv_to_rs: Admin script enqueued (wc-orders)');
    }
}
add_action('admin_enqueue_scripts', 'woo_inv_to_rs_enqueue_admin_scripts');

// AJAX handler for sending invoice to RepairShopr
add_action('wp_ajax_woo_inv_to_rs_send_to_repairshopr', 'woo_inv_to_rs_ajax_send_to_repairshopr');
add_action('wp_ajax_woo_inv_to_rs_send_payment_to_repairshopr', 'woo_inv_to_rs_ajax_send_payment_to_repairshopr');
add_action('wp_ajax_woo_inv_to_rs_verify_invoice', 'woo_inv_to_rs_ajax_verify_invoice');
add_action('wp_ajax_woo_inv_to_rs_verify_payment', 'woo_inv_to_rs_ajax_verify_payment');

function woo_inv_to_rs_ajax_send_payment_to_repairshopr() {
    error_log('woo_inv_to_rs: AJAX handler for Send Payment triggered');

    if (!current_user_can('edit_shop_orders') || !check_ajax_referer('woo_inv_to_rs_nonce', 'nonce', false)) {
        error_log('woo_inv_to_rs: Permission check failed (Send Payment)');
        wp_send_json_error(array('message' => 'Permission denied'));
        return;
    }

    $order_id = isset($_POST['order_id']) ? intval($_POST['order_id']) : 0;
    error_log('woo_inv_to_rs: Order ID (Send Payment): ' . $order_id);

    if ($order_id > 0) {
        $order = wc_get_order($order_id);
        if (!$order) {
            error_log('woo_inv_to_rs: Order not found (Send Payment)');
            wp_send_json_error(array('message' => 'Order not found'));
            return;
        }
        if (!$order->is_paid()) {
            error_log('woo_inv_to_rs: Order not paid (Send Payment)');
            wp_send_json_error(array('message' => 'Order is not paid'));
            return;
        }

        // Get payment method mapping
        $wc_payment_method = $order->get_payment_method();
        $payment_mapping = get_option('woo_inv_to_rs_payment_mapping', array());
        $rs_payment_method_id = isset($payment_mapping[$wc_payment_method]) ? $payment_mapping[$wc_payment_method] : '';
        if (!$rs_payment_method_id) {
            error_log('woo_inv_to_rs: No RepairShopr payment method mapped for WooCommerce method: ' . $wc_payment_method);
            wp_send_json_error(array('message' => 'No RepairShopr payment method mapped for this WooCommerce payment method.'));
            return;
        }

        // Get customer in RepairShopr
        $customer_email = $order->get_billing_email();
        $customer_id = woo_inv_to_rs_get_repairshopr_customer($customer_email);
        if (!$customer_id) {
            $customer_id = woo_inv_to_rs_create_repairshopr_customer($order);
            if (!$customer_id) {
                error_log('woo_inv_to_rs: Failed to create/find customer in RepairShopr (Send Payment)');
                wp_send_json_error(array('message' => 'Failed to create/find customer in RepairShopr.'));
                return;
            }
        }

        // Find invoice in RepairShopr by order number
        $prefix = get_option('woo_inv_to_rs_invoice_prefix', '');
        $invoice_number = $prefix . $order->get_order_number();
// Get and sanitize API base (no hardcoded default)
$api_base = get_option('woo_inv_to_rs_api_url', '');
// Sanitize: remove any trailing endpoint (e.g., /customers, /invoices, /payment_methods)
$api_base = preg_replace('#/(customers|invoices|payment_methods)$#', '', rtrim($api_base, '/'));
$invoice_url = $api_base . '/invoices/' . urlencode($invoice_number);
        $api_key = woo_inv_to_rs_get_api_key();
        $invoice_id = 0;
if ($api_key) {
    // Log the invoice lookup API call
    if (defined('WP_DEBUG_LOG') && WP_DEBUG_LOG) {
        $logfile = WP_CONTENT_DIR . '/debug.log';
        $logline = date('c') . ' woo_inv_to_rs: Invoice lookup API Request URL: ' . $invoice_url . PHP_EOL;
        file_put_contents($logfile, $logline, FILE_APPEND);
    }
    error_log('woo_inv_to_rs: Invoice lookup API Request URL: ' . $invoice_url);

    $response = wp_remote_get($invoice_url, array(
        'headers' => array(
            'Authorization' => 'Bearer ' . $api_key,
            'Accept' => 'application/json'
        )
    ));

    // Log the invoice lookup API response
    $response_body = wp_remote_retrieve_body($response);
    if (defined('WP_DEBUG_LOG') && WP_DEBUG_LOG) {
        $logfile = WP_CONTENT_DIR . '/debug.log';
        $logline = date('c') . ' woo_inv_to_rs: Invoice lookup API Response: ' . $response_body . PHP_EOL;
        file_put_contents($logfile, $logline, FILE_APPEND);
    }
    error_log('woo_inv_to_rs: Invoice lookup API Response: ' . $response_body);

    if (!is_wp_error($response)) {
        $body = $response_body;
        $data = json_decode($body, true);
        // Correct extraction for /invoices/{number} endpoint
        if (!empty($data['invoice']['id'])) {
            $invoice_id = $data['invoice']['id'];
        }
    }
}
        error_log('woo_inv_to_rs: Attempting to match invoice_number: ' . $invoice_number . ' for order_id: ' . $order_id . '. Found invoice_id: ' . $invoice_id);
        if (!$invoice_id) {
            error_log('woo_inv_to_rs: Could not find RepairShopr invoice for order ' . $order_id . ' (number: ' . $invoice_number . ')');
            wp_send_json_error(array('message' => 'Could not find RepairShopr invoice for this order.'));
            return;
        }

        // Check if payment already exists for this invoice
        $order_total = $order->get_total();
        $transaction_id = $order->get_transaction_id();
        $existing_payment = woo_inv_to_rs_check_payment_exists($invoice_id, $order_total, $transaction_id);
        
        if ($existing_payment) {
            error_log('woo_inv_to_rs: Payment already exists for invoice ' . $invoice_id . ' with Payment ID: ' . $existing_payment['id']);
            wp_send_json_error(array('message' => 'Payment already exists for this invoice in RepairShopr.'));
            return;
        }

        // Prepare payment data - use exact WooCommerce total with precision handling
        $wc_total = $order->get_total();
        $amount_cents = intval(round($wc_total * 100));
        $payment_amount = number_format($wc_total, 2, '.', ''); // Use WooCommerce total directly
        
        error_log('woo_inv_to_rs: Payment Amount Precision - WC Total: ' . $wc_total . ', Amount Cents: ' . $amount_cents . ', Payment Amount: ' . $payment_amount);
        $address_street = $order->get_billing_address_1();
        $address_city = $order->get_billing_city();
        $address_zip = $order->get_billing_postcode();
        $payment_method_name = '';
        // Get RepairShopr payment method name for API
$pm_url = $api_base . '/payment_methods';
        $rs_payment_method = '';
        if ($api_key) {
            $response = wp_remote_get($pm_url, array(
                'headers' => array(
                    'Authorization' => 'Bearer ' . $api_key,
                    'Accept' => 'application/json'
                )
            ));
            if (!is_wp_error($response)) {
                $body = wp_remote_retrieve_body($response);
                $data = json_decode($body, true);
                if (!empty($data['payment_methods'])) {
                    foreach ($data['payment_methods'] as $pm) {
                        if ($pm['id'] == $rs_payment_method_id) {
                            $payment_method_name = $pm['name'];
                            break;
                        }
                    }
                }
            }
        }
        if (!$payment_method_name) {
            error_log('woo_inv_to_rs: Could not resolve RepairShopr payment method name for id ' . $rs_payment_method_id);
            wp_send_json_error(array('message' => 'Could not resolve RepairShopr payment method name.'));
            return;
        }

        // Build payment body
        $payment_body = array(
            'customer_id' => $customer_id,
            'invoice_id' => $invoice_id,
            'invoice_number' => $invoice_number,
            'amount_cents' => $amount_cents,
            'address_street' => $address_street,
            'address_city' => $address_city,
            'address_zip' => $address_zip,
            'payment_method' => $payment_method_name,
            'ref_num' => $order->get_transaction_id(),
            'register_id' => 0,
            'signature_name' => '',
            'signature_data' => '',
            // RepairShopr expects full ISO 8601 with milliseconds and Z suffix, e.g. 2019-10-28T00:00:00.000Z
            'applied_at'     => $order->get_date_paid()
                                   ? $order->get_date_paid()->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.000\Z')
                                   : gmdate('Y-m-d\TH:i:s.000\Z'),
            'signature_date' => $order->get_date_paid() ? $order->get_date_paid()->format('c') : date('c'),
            'credit_card_number' => '',
            'date_month' => '',
            'date_year' => '',
            'cvv' => '',
            'lastname' => $order->get_billing_last_name(),
            'firstname' => $order->get_billing_first_name(),
            'apply_payments' => array(
                strval($invoice_id) => $payment_amount
            )
        );

        // Log the payment payload for debugging
        error_log('woo_inv_to_rs: Payment payload to RepairShopr: ' . json_encode($payment_body));
        if (function_exists('wp_debug_backtrace_summary')) {
            if (defined('WP_DEBUG_LOG') && WP_DEBUG_LOG) {
                $logfile = WP_CONTENT_DIR . '/debug.log';
                $logline = date('c') . ' woo_inv_to_rs: Payment payload to RepairShopr: ' . json_encode($payment_body) . PHP_EOL;
                file_put_contents($logfile, $logline, FILE_APPEND);
            }
        }

        // Send payment to RepairShopr
$payment_url = $api_base . '/payments';
        $response = wp_remote_post($payment_url, array(
            'headers' => array(
                'Authorization' => 'Bearer ' . $api_key,
                'Content-Type' => 'application/json',
                'Accept' => 'application/json'
            ),
            'body' => json_encode($payment_body)
        ));

        $body = wp_remote_retrieve_body($response);

        // Log the payment API response to error_log for debug_log visibility
        error_log('woo_inv_to_rs: Payment API Response: ' . $body);

        // Debug: log before writing payment API response
        $logfile = WP_CONTENT_DIR . '/debug.log';
        $prelog = date('c') . ' woo_inv_to_rs: About to log Payment API Response' . PHP_EOL;
        $fh = fopen($logfile, 'a');
        if ($fh) {
            fwrite($fh, $prelog);
            fflush($fh);
            fclose($fh);
        }

        // Log the payment API response immediately after the request, before any error handling
        $logline = date('c') . ' woo_inv_to_rs: Payment API Response: ' . $body . PHP_EOL;
        $fh = fopen($logfile, 'a');
        if ($fh) {
            fwrite($fh, $logline);
            fflush($fh);
            fclose($fh);
        }

        // Debug: log after writing payment API response
        $postlog = date('c') . ' woo_inv_to_rs: Finished logging Payment API Response' . PHP_EOL;
        $fh = fopen($logfile, 'a');
        if ($fh) {
            fwrite($fh, $postlog);
            fflush($fh);
            fclose($fh);
        }

        // Log HTTP status code
        $http_code = wp_remote_retrieve_response_code($response);
        $statuslog = date('c') . ' woo_inv_to_rs: Payment API HTTP status: ' . $http_code . PHP_EOL;
        $fh = fopen($logfile, 'a');
        if ($fh) {
            fwrite($fh, $statuslog);
            fflush($fh);
            fclose($fh);
        }

        if (is_wp_error($response)) {
            error_log('woo_inv_to_rs: Error sending payment to RepairShopr: ' . $response->get_error_message());
            wp_send_json_error(array('message' => 'Error sending payment to RepairShopr: ' . $response->get_error_message()));
            return;
        }

        $data = json_decode($body, true);

        if (!empty($data['payment']['success'])) {
            error_log('woo_inv_to_rs: Payment successfully applied in RepairShopr for order ' . $order_id);

            // Additional debug: fetch payment details and compare invoice_ids
            $payment_id = isset($data['payment']['id']) ? $data['payment']['id'] : null;
            if ($payment_id) {
                $payment_url = $api_base . '/payments/' . $payment_id;
                $payment_response = wp_remote_get($payment_url, array(
                    'headers' => array(
                        'Authorization' => 'Bearer ' . $api_key,
                        'Accept' => 'application/json'
                    )
                ));
                $payment_body = wp_remote_retrieve_body($payment_response);
                $payment_data = json_decode($payment_body, true);
                $applied_invoice_ids = isset($payment_data['payment']['invoice_ids']) ? $payment_data['payment']['invoice_ids'] : [];
                $logline = date('c') . ' woo_inv_to_rs: Payment ID ' . $payment_id . ' applied to invoice_ids: ' . json_encode($applied_invoice_ids) . ' (expected: ' . $invoice_id . ')' . PHP_EOL;
                error_log('woo_inv_to_rs: Payment ID ' . $payment_id . ' applied to invoice_ids: ' . json_encode($applied_invoice_ids) . ' (expected: ' . $invoice_id . ')');
                if (defined('WP_DEBUG_LOG') && WP_DEBUG_LOG) {
                    $logfile = WP_CONTENT_DIR . '/debug.log';
                    file_put_contents($logfile, $logline, FILE_APPEND);
                }
            } else {
                error_log('woo_inv_to_rs: Could not extract payment ID from RepairShopr response.');
            }

            wp_send_json_success(array('message' => 'Payment successfully applied in RepairShopr.'));
        } else {
            error_log('woo_inv_to_rs: Failed to apply payment in RepairShopr for order ' . $order_id . '. Response: ' . $body);
            wp_send_json_error(array('message' => 'Failed to apply payment in RepairShopr. Response: ' . $body));
        }
    } else {
        error_log('woo_inv_to_rs: Invalid order ID (Send Payment)');
        wp_send_json_error(array('message' => 'Invalid order ID'));
    }
}

function woo_inv_to_rs_ajax_verify_invoice() {
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

    $api_base = get_option('woo_inv_to_rs_api_url', '');
    $api_key = woo_inv_to_rs_get_api_key();
    $invoice_prefix = get_option('woo_inv_to_rs_invoice_prefix', '');

    if (empty($api_base) || empty($api_key)) {
        error_log('woo_inv_to_rs: RepairShopr API URL or API Key not configured.');
        wp_send_json_error(array('message' => 'RepairShopr API URL or API Key not configured.'));
        return;
    }

    $invoice_number = $invoice_prefix . $order->get_order_number();
    $invoice_api_url = rtrim($api_base, '/') . '/invoices/' . urlencode($invoice_number);

    error_log('woo_inv_to_rs: Fetching RepairShopr invoice from: ' . $invoice_api_url);

    $response = wp_remote_get($invoice_api_url, array(
        'headers' => array(
            'Authorization' => 'Bearer ' . $api_key,
            'Accept' => 'application/json'
        )
    ));

    if (is_wp_error($response)) {
        error_log('woo_inv_to_rs: Error fetching RepairShopr invoice: ' . $response->get_error_message());
        wp_send_json_error(array('message' => 'Error fetching RepairShopr invoice: ' . $response->get_error_message()));
        return;
    }

    $body = wp_remote_retrieve_body($response);
    $data = json_decode($body, true);

    if (empty($data['invoice']) || !isset($data['invoice']['total'])) {
        error_log('woo_inv_to_rs: RepairShopr invoice not found or total missing for invoice number: ' . $invoice_number);
        wp_send_json_error(array(
            'message' => sprintf(
                'RepairShopr Invoice %s not found or total missing.',
                esc_html($invoice_number)
            )
        ));
        return;
    }

    $repairshopr_total = floatval($data['invoice']['total']);

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

function woo_inv_to_rs_ajax_verify_payment() {
    error_log('woo_inv_to_rs: AJAX handler for Verify Payment triggered');

    if (!current_user_can('edit_shop_orders') || !check_ajax_referer('woo_inv_to_rs_nonce', 'nonce', false)) {
        error_log('woo_inv_to_rs: Permission check failed (Verify Payment)');
        wp_send_json_error(array('message' => 'Permission denied'));
        return;
    }

    $order_id = isset($_POST['order_id']) ? intval($_POST['order_id']) : 0;
    error_log('woo_inv_to_rs: Order ID (Verify Payment): ' . $order_id);

    if ($order_id <= 0) {
        error_log('woo_inv_to_rs: Invalid order ID (Verify Payment)');
        wp_send_json_error(array('message' => 'Invalid order ID'));
        return;
    }

    $order = wc_get_order($order_id);
    if (!$order) {
        error_log('woo_inv_to_rs: Order not found (Verify Payment)');
        wp_send_json_error(array('message' => 'Order not found'));
        return;
    }

    $api_base = get_option('woo_inv_to_rs_api_url', '');
    $api_key = woo_inv_to_rs_get_api_key();
    $invoice_prefix = get_option('woo_inv_to_rs_invoice_prefix', '');

    if (empty($api_base) || empty($api_key)) {
        error_log('woo_inv_to_rs: RepairShopr API URL or API Key not configured.');
        wp_send_json_error(array('message' => 'RepairShopr API URL or API Key not configured.'));
        return;
    }

    $invoice_number = $invoice_prefix . $order->get_order_number();
    $invoice_api_url = rtrim($api_base, '/') . '/invoices/' . urlencode($invoice_number);

    error_log('woo_inv_to_rs: Fetching RepairShopr invoice for payment verification from: ' . $invoice_api_url);

    $response = wp_remote_get($invoice_api_url, array(
        'headers' => array(
            'Authorization' => 'Bearer ' . $api_key,
            'Accept' => 'application/json'
        )
    ));

    if (is_wp_error($response)) {
        error_log('woo_inv_to_rs: Error fetching RepairShopr invoice for payment verification: ' . $response->get_error_message());
        wp_send_json_error(array('message' => 'Error fetching RepairShopr invoice: ' . $response->get_error_message()));
        return;
    }

    $body = wp_remote_retrieve_body($response);
    $data = json_decode($body, true);

    if (empty($data['invoice'])) {
        error_log('woo_inv_to_rs: RepairShopr invoice not found for payment verification. Invoice number: ' . $invoice_number);
        wp_send_json_error(array(
            'message' => sprintf(
                'RepairShopr Invoice %s not found.',
                esc_html($invoice_number)
            )
        ));
        return;
    }

    $invoice = $data['invoice'];
    $is_paid = isset($invoice['is_paid']) ? $invoice['is_paid'] : false;
    $balance_due = isset($invoice['balance_due']) ? $invoice['balance_due'] : null;
    $payments = isset($invoice['payments']) ? $invoice['payments'] : array();

    error_log('woo_inv_to_rs: RepairShopr Invoice Payment Status - is_paid: ' . ($is_paid ? 'true' : 'false') . ', balance_due: ' . $balance_due . ', payments count: ' . count($payments));

    // Check if invoice is paid based on multiple criteria
    $payment_verified = false;
    $payment_details = array();

    // Primary check: is_paid flag should be true
    if ($is_paid === true) {
        $payment_verified = true;
        $payment_details['is_paid'] = true;
    }

    // Secondary check: balance_due should be "0.0" or 0
    if ($balance_due !== null && (floatval($balance_due) === 0.0 || $balance_due === "0.0" || $balance_due === "0")) {
        $payment_details['balance_due_zero'] = true;
        if (!$payment_verified) {
            $payment_verified = true; // Consider paid if balance is zero even if is_paid is false
        }
    } else {
        $payment_details['balance_due_zero'] = false;
    }

    // Additional check: verify payments array has entries
    if (!empty($payments)) {
        $total_payment_amount = 0;
        foreach ($payments as $payment) {
            if (isset($payment['payment_amount'])) {
                $total_payment_amount += floatval($payment['payment_amount']);
            }
        }
        $payment_details['payments_exist'] = true;
        $payment_details['total_payment_amount'] = $total_payment_amount;
        $payment_details['payments_count'] = count($payments);
    } else {
        $payment_details['payments_exist'] = false;
        $payment_details['total_payment_amount'] = 0;
        $payment_details['payments_count'] = 0;
    }

    if ($payment_verified) {
        wp_send_json_success(array(
            'message' => 'Invoice is Paid!',
            'paid' => true,
            'details' => $payment_details
        ));
    } else {
        wp_send_json_success(array(
            'message' => 'Invoice is Unpaid!',
            'paid' => false,
            'details' => $payment_details
        ));
    }
}

function woo_inv_to_rs_ajax_send_to_repairshopr() {
    error_log('woo_inv_to_rs: AJAX handler triggered');

    if (!current_user_can('edit_shop_orders') || !check_ajax_referer('woo_inv_to_rs_nonce', 'nonce', false)) {
        error_log('woo_inv_to_rs: Permission check failed');
        wp_send_json_error(array('message' => 'Permission denied'));
        return;
    }

    $order_id = isset($_POST['order_id']) ? intval($_POST['order_id']) : 0;
    error_log('woo_inv_to_rs: Order ID: ' . $order_id);

    if ($order_id > 0) {
        $result = woo_inv_to_rs_send_invoice_to_repairshopr($order_id);
        
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
        error_log('woo_inv_to_rs: Invalid order ID');
        wp_send_json_error(array('message' => 'Invalid order ID'));
    }
}

add_action('admin_menu', 'woo_invoice_to_repairshopr_menu');

function woo_invoice_to_repairshopr_menu() {
    $plugin_name = 'Woo Invoice to RepairShopr'; // Keep in sync with Plugin Name in header
    add_submenu_page(
        'woocommerce',
        $plugin_name,
        $plugin_name,
        'manage_options',
        'woo-invoice-to-repairshopr',
        'woo_invoice_to_repairshopr_settings_page'
    );
}



// Tabbed admin interface: Main and Settings
function woo_invoice_to_repairshopr_settings_page() {
    if (!current_user_can('manage_options')) {
        wp_die(__('You do not have sufficient permissions to access this page.'));
    }

    // Determine active tab
    $tab = isset($_GET['tab']) ? sanitize_key($_GET['tab']) : 'main';

    // Tab navigation
    $tabs = [
        'main' => 'Main',
        'settings' => 'Settings'
    ];
    echo '<div class="wrap">';
    echo '<h2 class="nav-tab-wrapper">';
    foreach ($tabs as $tab_key => $tab_label) {
        $active = ($tab === $tab_key) ? ' nav-tab-active' : '';
        $url = admin_url('admin.php?page=woo-invoice-to-repairshopr&tab=' . $tab_key);
        echo '<a href="' . esc_url($url) . '" class="nav-tab' . $active . '">' . esc_html($tab_label) . '</a>';
    }
    echo '</h2>';

    if ($tab === 'main') {
        // Main tab: placeholder description
        echo '<div style="margin-top:2em;">';
        echo '<h3>Woo Invoice to RepairShopr</h3>';
        echo '<p>This plugin sends invoice details to RepairShopr when an invoice is paid in WooCommerce.</p>';
        echo '<p>More features and status information will appear here in the future.</p>';
        echo '</div>';
    } elseif ($tab === 'settings') {
        // Settings tab: API key and update check, plus new settings
        $api_key = woo_inv_to_rs_get_api_key();
        $masked_key = '';
        if (!empty($api_key) && strlen($api_key) > 4) {
            $masked_key = str_repeat('*', max(0, strlen($api_key) - 4)) . substr($api_key, -4);
        } elseif (!empty($api_key)) {
            $masked_key = str_repeat('*', strlen($api_key));
        }

        // Load current settings
        $api_url = get_option('woo_inv_to_rs_api_url', '');
        $customer_url = get_option('woo_inv_to_rs_customer_url', 'https://your-subdomain.repairshopr.com/api/v1/customers');
        $invoice_url = get_option('woo_inv_to_rs_invoice_url', 'https://your-subdomain.repairshopr.com/api/v1/invoices');
        $tax_rate_id = get_option('woo_inv_to_rs_tax_rate_id', '40354');
        $epf_product_id = get_option('woo_inv_to_rs_epf_product_id', '9263351');
        $epf_name = get_option('woo_inv_to_rs_epf_name', 'Electronic Payment Fee');
        $rounding_correction_name = get_option('woo_inv_to_rs_rounding_correction_name', '');
        $rounding_correction_product_id = get_option('woo_inv_to_rs_rounding_correction_product_id', '');
        $notes = get_option('woo_inv_to_rs_notes', 'Created by WooCommerce');
        $invoice_note = get_option('woo_inv_to_rs_invoice_note', 'Order created from WooCommerce');
        $get_sms = get_option('woo_inv_to_rs_get_sms', '1');
        $opt_out = get_option('woo_inv_to_rs_opt_out', '');
        $no_email = get_option('woo_inv_to_rs_no_email', '');
        $get_billing = get_option('woo_inv_to_rs_get_billing', '1');
        $get_marketing = get_option('woo_inv_to_rs_get_marketing', '1');
        $get_reports = get_option('woo_inv_to_rs_get_reports', '1');
        $taxable = get_option('woo_inv_to_rs_taxable', '1');
        $verified_paid = get_option('woo_inv_to_rs_verified_paid', '1');
        $tech_marked_paid = get_option('woo_inv_to_rs_tech_marked_paid', '1');
        $is_paid = get_option('woo_inv_to_rs_is_paid', '1');

        // Handle settings update
        if (isset($_POST['woo_inv_to_rs_settings_submit']) && check_admin_referer('woo_inv_to_rs_settings_nonce', 'woo_inv_to_rs_settings_nonce')) {
            // API Key
            if (isset($_POST['woo_inv_to_rs_api_key'])) {
                $submitted_key = sanitize_text_field($_POST['woo_inv_to_rs_api_key']);
                if ($submitted_key !== $masked_key && $submitted_key !== '') {
                    woo_inv_to_rs_set_api_key($submitted_key);
                    echo '<div class="updated"><p>API Key updated.</p></div>';
                    $api_key = woo_inv_to_rs_get_api_key();
                    if (!empty($api_key) && strlen($api_key) > 4) {
                        $masked_key = str_repeat('*', max(0, strlen($api_key) - 4)) . substr($api_key, -4);
                    } elseif (!empty($api_key)) {
                        $masked_key = str_repeat('*', strlen($api_key));
                    }
                } else {
                    echo '<div class="updated"><p>API Key unchanged.</p></div>';
                }
            }
                        // Electronic Payment Fee settings
            $epf_name_submitted = isset($_POST['woo_inv_to_rs_epf_name']) ? trim(sanitize_text_field($_POST['woo_inv_to_rs_epf_name'])) : '';
            $epf_product_id_submitted = isset($_POST['woo_inv_to_rs_epf_product_id']) ? trim(sanitize_text_field($_POST['woo_inv_to_rs_epf_product_id'])) : '';
            if (($epf_name_submitted !== '' && $epf_product_id_submitted === '') || ($epf_name_submitted === '' && $epf_product_id_submitted !== '')) {
                echo '<div class="error"><p><strong>Both Electronic Payment Fee Name and Product ID must be filled together, or both left blank. Fee settings not saved.</strong></p></div>';
            } else {
                update_option('woo_inv_to_rs_epf_name', $epf_name_submitted);
                update_option('woo_inv_to_rs_epf_product_id', $epf_product_id_submitted);
            }
            
            // Rounding Correction settings - only Product ID is needed now
            $rounding_correction_product_id_submitted = isset($_POST['woo_inv_to_rs_rounding_correction_product_id']) ? trim(sanitize_text_field($_POST['woo_inv_to_rs_rounding_correction_product_id'])) : '';
            update_option('woo_inv_to_rs_rounding_correction_product_id', $rounding_correction_product_id_submitted);
            
            // Invoice Prefix (numbers only)
            if (isset($_POST['woo_inv_to_rs_invoice_prefix'])) {
                $prefix = trim($_POST['woo_inv_to_rs_invoice_prefix']);
                if ($prefix === '' || ctype_digit($prefix)) {
                    update_option('woo_inv_to_rs_invoice_prefix', $prefix);
                } else {
                    echo '<div class="error"><p>Invoice Number Prefix must be numbers only.</p></div>';
                }
            }
            // Other settings
            update_option('woo_inv_to_rs_api_url', esc_url_raw($_POST['woo_inv_to_rs_api_url']));
            update_option('woo_inv_to_rs_customer_url', esc_url_raw($_POST['woo_inv_to_rs_customer_url']));
            update_option('woo_inv_to_rs_invoice_url', esc_url_raw($_POST['woo_inv_to_rs_invoice_url']));
            update_option('woo_inv_to_rs_tax_rate_id', sanitize_text_field($_POST['woo_inv_to_rs_tax_rate_id']));
            update_option('woo_inv_to_rs_epf_name', sanitize_text_field($_POST['woo_inv_to_rs_epf_name']));
            update_option('woo_inv_to_rs_epf_product_id', sanitize_text_field($_POST['woo_inv_to_rs_epf_product_id']));
            update_option('woo_inv_to_rs_notes', sanitize_text_field($_POST['woo_inv_to_rs_notes']));
            update_option('woo_inv_to_rs_invoice_note', sanitize_text_field($_POST['woo_inv_to_rs_invoice_note']));
            // Save payment mapping
            if (isset($_POST['woo_inv_to_rs_payment_mapping']) && is_array($_POST['woo_inv_to_rs_payment_mapping'])) {
                update_option('woo_inv_to_rs_payment_mapping', array_map('sanitize_text_field', $_POST['woo_inv_to_rs_payment_mapping']));
            }
            update_option('woo_inv_to_rs_get_sms', isset($_POST['woo_inv_to_rs_get_sms']) ? '1' : '');
            update_option('woo_inv_to_rs_opt_out', isset($_POST['woo_inv_to_rs_opt_out']) ? '1' : '');
            update_option('woo_inv_to_rs_no_email', isset($_POST['woo_inv_to_rs_no_email']) ? '1' : '');
            update_option('woo_inv_to_rs_get_billing', isset($_POST['woo_inv_to_rs_get_billing']) ? '1' : '');
            update_option('woo_inv_to_rs_get_marketing', isset($_POST['woo_inv_to_rs_get_marketing']) ? '1' : '');
            update_option('woo_inv_to_rs_get_reports', isset($_POST['woo_inv_to_rs_get_reports']) ? '1' : '');
            update_option('woo_inv_to_rs_taxable', isset($_POST['woo_inv_to_rs_taxable']) ? '1' : '');
            update_option('woo_inv_to_rs_verified_paid', isset($_POST['woo_inv_to_rs_verified_paid']) ? '1' : '');
            update_option('woo_inv_to_rs_tech_marked_paid', isset($_POST['woo_inv_to_rs_tech_marked_paid']) ? '1' : '');
            update_option('woo_inv_to_rs_is_paid', isset($_POST['woo_inv_to_rs_is_paid']) ? '1' : '');
            echo '<div class="updated"><p>Settings updated.</p></div>';
            // Refresh values
            $customer_url = get_option('woo_inv_to_rs_customer_url', 'https://your-subdomain.repairshopr.com/api/v1/customers');
            $invoice_url = get_option('woo_inv_to_rs_invoice_url', 'https://your-subdomain.repairshopr.com/api/v1/invoices');
            $tax_rate_id = get_option('woo_inv_to_rs_tax_rate_id', '40354');
            $epf_product_id = get_option('woo_inv_to_rs_epf_product_id', '9263351');
            $notes = get_option('woo_inv_to_rs_notes', 'Created by WooCommerce');
            $invoice_note = get_option('woo_inv_to_rs_invoice_note', 'Order created from WooCommerce');
            $get_sms = get_option('woo_inv_to_rs_get_sms', '1');
            $opt_out = get_option('woo_inv_to_rs_opt_out', '');
            $no_email = get_option('woo_inv_to_rs_no_email', '');
            $get_billing = get_option('woo_inv_to_rs_get_billing', '1');
            $get_marketing = get_option('woo_inv_to_rs_get_marketing', '1');
            $get_reports = get_option('woo_inv_to_rs_get_reports', '1');
            $taxable = get_option('woo_inv_to_rs_taxable', '1');
            $verified_paid = get_option('woo_inv_to_rs_verified_paid', '1');
            $tech_marked_paid = get_option('woo_inv_to_rs_tech_marked_paid', '1');
            $is_paid = get_option('woo_inv_to_rs_is_paid', '1');
        }

        // Handle "Trigger WP-Cron Plugin Update Check" button
        if (isset($_POST['woo_inv_to_rs_check_update']) && check_admin_referer('woo_inv_to_rs_settings_nonce', 'woo_inv_to_rs_settings_nonce')) {
            do_action('wp_update_plugins');
            if (function_exists('wp_clean_plugins_cache')) {
                wp_clean_plugins_cache(true);
            }
            delete_site_transient('update_plugins');
            if (function_exists('wp_update_plugins')) {
                wp_update_plugins();
            }
            $plugin_file = plugin_basename(__FILE__);
            $update_plugins = get_site_transient('update_plugins');
            $update_msg = '';
            if (isset($update_plugins->response) && isset($update_plugins->response[$plugin_file])) {
                $new_version = $update_plugins->response[$plugin_file]->new_version;
                $update_msg = '<div class="updated"><p>Update available: version ' . esc_html($new_version) . '.</p></div>';
            } else {
                $update_msg = '<div class="updated"><p>No update available for this plugin.</p></div>';
            }
            echo $update_msg;
        }
        ?>
        <div style="margin-top:2em;">
            <form method="post" action="">
                <?php wp_nonce_field('woo_inv_to_rs_settings_nonce', 'woo_inv_to_rs_settings_nonce'); ?>
                <table class="form-table">
                    <tr>
                        <th><label for="woo_inv_to_rs_api_key">API Key</label></th>
                        <td>
                            <input type="text" id="woo_inv_to_rs_api_key" name="woo_inv_to_rs_api_key" value="<?php echo esc_attr($masked_key); ?>" class="regular-text" autocomplete="off">
                            <p class="description">For security, only the last 4 characters are shown. Enter a new key to update.</p>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="woo_inv_to_rs_api_url">API URL</label></th>
                        <td>
                            <input type="text" id="woo_inv_to_rs_api_url" name="woo_inv_to_rs_api_url" value="<?php echo esc_attr($api_url); ?>" class="regular-text" autocomplete="off">
                            <p class="description">Example: <code>https://your-subdomain.repairshopr.com/api/v1</code> (do NOT include <code>/customers</code> or <code>/invoices</code> at the end)</p>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="woo_inv_to_rs_invoice_prefix">Invoice Number Prefix (Must be numbers only)</label></th>
                        <td>
                            <input type="text" id="woo_inv_to_rs_invoice_prefix" name="woo_inv_to_rs_invoice_prefix" value="<?php echo esc_attr(get_option('woo_inv_to_rs_invoice_prefix', '')); ?>" class="regular-text" autocomplete="off" pattern="\d*">
                            <p class="description">This prefix will be prepended to all invoice numbers sent to RepairShopr. Leave blank for no prefix. Numbers only.</p>
                        </td>
                    </tr>
                </table>

                <h2 style="margin-top:2em;">Invoice Details</h2>
                <table class="form-table">
                    <tr>
                        <th><label for="woo_inv_to_rs_tax_rate_id">Tax Rate ID</label></th>
                        <td>
                            <input type="number" id="woo_inv_to_rs_tax_rate_id" name="woo_inv_to_rs_tax_rate_id" value="<?php echo esc_attr($tax_rate_id); ?>" class="regular-text" autocomplete="off">
                        </td>
                    </tr>
<tr>
                        <th><label for="woo_inv_to_rs_epf_name">Electronic Payment Fee Name</label></th>
                        <td>
<input type="text" id="woo_inv_to_rs_epf_name" name="woo_inv_to_rs_epf_name" value="<?php echo esc_attr($epf_name); ?>" class="regular-text" autocomplete="off">
                            <p class="description">Optional. Enter the exact name of the fee as it appears in your WooCommerce orders (e.g., "Credit Card Surcharge", "Online Payment Fee"). <strong>If you enter a value here, you must also enter a Product ID below. Both fields must be filled for the fee to be exported. If both are left blank, no fee will be exported to RepairShopr.</strong></p>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="woo_inv_to_rs_epf_product_id">Electronic Payment Fee Product ID</label></th>
                        <td>
<input type="text" id="woo_inv_to_rs_epf_product_id" name="woo_inv_to_rs_epf_product_id" value="<?php echo esc_attr($epf_product_id); ?>" class="regular-text" autocomplete="off">
                            <p class="description">Optional. Enter the RepairShopr Product ID to use for the electronic payment fee. <strong>If you enter a value here, you must also enter a Fee Name above. Both fields must be filled for the fee to be exported. If both are left blank, no fee will be exported to RepairShopr.</strong></p>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="woo_inv_to_rs_rounding_correction_product_id">Rounding Correction Product ID</label></th>
                        <td>
                            <input type="text" id="woo_inv_to_rs_rounding_correction_product_id" name="woo_inv_to_rs_rounding_correction_product_id" value="<?php echo esc_attr($rounding_correction_product_id); ?>" class="regular-text" autocomplete="off">
                            <p class="description">Optional. Enter the RepairShopr Product ID to use for rounding corrections. If this field is not entered then no correction will be made. The RepairShopr item should NOT be marked as taxable.</p>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="woo_inv_to_rs_invoice_note">Invoice Note</label></th>
                        <td>
                            <input type="text" id="woo_inv_to_rs_invoice_note" name="woo_inv_to_rs_invoice_note" value="<?php echo esc_attr($invoice_note); ?>" class="regular-text" autocomplete="off">
                        </td>
                    </tr>
                    <tr>
<th>Invoice Flags</th>
<td>
    <label>
        <input type="checkbox" name="woo_inv_to_rs_taxable" <?php checked($taxable, '1'); ?>>
        taxable
        <span style="color:#666;font-size:90%;">(Set only if the WooCommerce order is taxable and sales tax was charged)</span>
    </label><br>
    <label>
        <input type="checkbox" name="woo_inv_to_rs_verified_paid" <?php checked($verified_paid, '1'); ?>>
        verified_paid
        <span style="color:#666;font-size:90%;">(Set only if the WooCommerce order is paid)</span>
    </label><br>
    <label>
        <input type="checkbox" name="woo_inv_to_rs_tech_marked_paid" <?php checked($tech_marked_paid, '1'); ?>>
        tech_marked_paid
        <span style="color:#666;font-size:90%;">(Set only if the WooCommerce order is paid)</span>
    </label><br>
    <label>
        <input type="checkbox" name="woo_inv_to_rs_is_paid" <?php checked($is_paid, '1'); ?>>
        is_paid
        <span style="color:#666;font-size:90%;">(Set only if the WooCommerce order is paid)</span>
    </label>
</td>
                    </tr>
                </table>

                <h2 style="margin-top:2em;">Payments</h2>
                <table class="form-table">
                    <tr>
                        <th>Payment Method Mapping</th>
                        <td>
<?php
// Fetch WooCommerce payment gateways
if (class_exists('WC_Payment_Gateways')) {
    $gateways = WC_Payment_Gateways::instance()->get_available_payment_gateways();
} else {
    $gateways = array();
}

// Fetch RepairShopr payment methods via API
$repairshopr_methods = array();
$api_key = woo_inv_to_rs_get_api_key();
// Get and sanitize API URL (no hardcoded default)
$api_url = get_option('woo_inv_to_rs_api_url', '');
// Sanitize: remove any trailing endpoint (e.g., /customers, /invoices, /payment_methods)
$api_url = preg_replace('#/(customers|invoices|payment_methods)$#', '', rtrim($api_url, '/'));
$pm_url = $api_url . '/payment_methods';
$repairshopr_api_error = '';
if ($api_key) {
    $response = wp_remote_get($pm_url, array(
        'headers' => array(
            'Authorization' => 'Bearer ' . $api_key,
            'Accept' => 'application/json'
        )
    ));
    if (!is_wp_error($response)) {
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);
        if (!empty($data['payment_methods'])) {
            foreach ($data['payment_methods'] as $pm) {
                $repairshopr_methods[$pm['id']] = $pm['name'];
            }
        } else {
            $repairshopr_api_error = !empty($data['error']) ? $data['error'] : $body;
        }
    } else {
        $repairshopr_api_error = $response->get_error_message();
    }
}

// Load saved mapping
$payment_mapping = get_option('woo_inv_to_rs_payment_mapping', array());

if (!empty($repairshopr_api_error)) {
    echo '<div style="color:red; font-weight:bold; margin-bottom:1em;">Error loading RepairShopr payment methods: ' . esc_html($repairshopr_api_error) . '</div>';
}

// Render mapping UI
if (!empty($gateways)) {
    foreach ($gateways as $gw_id => $gw) {
        $label = esc_html($gw->get_title());
        $selected = isset($payment_mapping[$gw_id]) ? $payment_mapping[$gw_id] : '';
        echo '<div style="margin-bottom:8px;">';
        echo '<label>' . $label . ' (' . esc_html($gw_id) . '): </label>';
        echo '<select name="woo_inv_to_rs_payment_mapping[' . esc_attr($gw_id) . ']">';
        echo '<option value="">-- Select RepairShopr Payment Method --</option>';
        foreach ($repairshopr_methods as $rs_id => $rs_name) {
            $sel = selected($selected, $rs_id, false);
            echo '<option value="' . esc_attr($rs_id) . '" ' . $sel . '>' . esc_html($rs_name) . '</option>';
        }
        echo '</select>';
        echo '</div>';
    }
} else {
    echo '<em>No WooCommerce payment gateways found.</em>';
}
?>
                        </td>
                    </tr>
                </table>

                <h2 style="margin-top:2em;">Customer Details</h2>
                <table class="form-table">
                    <tr>
                        <th><label for="woo_inv_to_rs_notes">Customer Notes</label></th>
                        <td>
                            <input type="text" id="woo_inv_to_rs_notes" name="woo_inv_to_rs_notes" value="<?php echo esc_attr($notes); ?>" class="regular-text" autocomplete="off">
                        </td>
                    </tr>
                    <tr>
                        <th>Customer Flags</th>
                        <td>
                            <label><input type="checkbox" name="woo_inv_to_rs_get_sms" <?php checked($get_sms, '1'); ?>> get_sms</label><br>
                            <label><input type="checkbox" name="woo_inv_to_rs_get_billing" <?php checked($get_billing, '1'); ?>> get_billing</label><br>
                            <label><input type="checkbox" name="woo_inv_to_rs_get_marketing" <?php checked($get_marketing, '1'); ?>> get_marketing</label><br>
                            <label><input type="checkbox" name="woo_inv_to_rs_get_reports" <?php checked($get_reports, '1'); ?>> get_reports</label><br>
                            <label><input type="checkbox" name="woo_inv_to_rs_opt_out" <?php checked($opt_out, '1'); ?>> opt_out</label><br>
                            <label><input type="checkbox" name="woo_inv_to_rs_no_email" <?php checked($no_email, '1'); ?>> no_email</label>
                        </td>
                    </tr>
                </table>
                <input type="hidden" name="woo_inv_to_rs_settings_submit" value="1">
                <?php submit_button('Save Settings'); ?>
            </form>
            <form method="post" action="" style="margin-top:2em;">
                <?php wp_nonce_field('woo_inv_to_rs_settings_nonce', 'woo_inv_to_rs_settings_nonce'); ?>
                <input type="hidden" name="woo_inv_to_rs_check_update" value="1">
                <?php submit_button('Check for Plugin Updates', 'secondary'); ?>
            </form>
        </div>
        <?php
    }
    echo '</div>';
}

// No closing PHP tag to avoid potential whitespace issues
