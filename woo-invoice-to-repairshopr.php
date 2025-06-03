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

function woo_inv_to_rs_get_repairshopr_customer($email) {
    $api_url = "https://dataforgesys.repairshopr.com/api/v1/customers?email=" . urlencode($email);

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
    $api_url = "https://dataforgesys.repairshopr.com/api/v1/customers";

    $body = array(
        'firstname' => $order->get_billing_first_name(),
        'lastname' => $order->get_billing_last_name(),
        'email' => $order->get_billing_email(),
        'mobile' => $order->get_billing_phone(),
        'address' => $order->get_billing_address_1(),
        'address_2' => $order->get_billing_address_2(),
        'city' => $order->get_billing_city(),
        'state' => $order->get_billing_state(),
        'zip' => $order->get_billing_postcode(),
        'notes' => 'Created by WooCommerce',
        'get_sms' => true,
        'opt_out' => false,
        'no_email' => false,
        'get_billing' => true,
        'get_marketing' => true,
        'get_reports' => true,
        'tax_rate_id' => 40354,
        'properties' => array(),
        'consent' => array()
    );

    // Add business_name only if billing company is not empty
    $billing_company = $order->get_billing_company();
    if (!empty($billing_company)) {
        $body['business_name'] = $billing_company;
    }

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

    return $data['customer']['id'];
}

function woo_inv_to_rs_create_repairshopr_invoice($order, $customer_id) {
    $api_url = "https://dataforgesys.repairshopr.com/api/v1/invoices";

    // Create invoice without line items
    $body = array(
        'balance_due' => '0.00',
        'customer_id' => $customer_id,
        'number' => 'WOO-' . $order->get_order_number(),
        'date' => $order->get_date_created()->format('Y-m-d\TH:i:s.000P'),
        'customer_business_then_name' => $order->get_billing_company() ?: $order->get_formatted_billing_full_name(),
        'due_date' => $order->get_date_created()->format('Y-m-d'),
        'subtotal' => number_format($order->get_subtotal(), 2, '.', ''),
        'total' => number_format($order->get_total(), 2, '.', ''),
        'tax' => number_format($order->get_total_tax(), 2, '.', ''),
        'verified_paid' => true,
        'tech_marked_paid' => true,
        'is_paid' => true,
        'note' => 'Order created from WooCommerce'
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

    // Now add line items
    foreach ($order->get_items() as $item) {
        $product = $item->get_product();
        $sku = $product->get_sku();

        if ($sku) {
            $line_item = array(
                'item' => $product->get_name(), // Use the product name for the 'item' field
                'product_id' => $sku, // Use the SKU as the product_id
                'price' => number_format($item->get_total() / $item->get_quantity(), 2, '.', ''),
                'quantity' => strval($item->get_quantity()),
                'taxable' => true
            );

            $line_item_url = $api_url . '/' . $invoice_id . '/line_items';

            error_log('RepairShopr API Request (Add Line Item): ' . json_encode($line_item));

            $line_item_response = wp_remote_post($line_item_url, array(
                'headers' => array(
                    'Authorization' => 'Bearer ' . woo_inv_to_rs_get_api_key(),
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json'
                ),
                'body' => json_encode($line_item)
            ));

            error_log('RepairShopr API Response (Add Line Item): ' . wp_remote_retrieve_body($line_item_response));

            if (is_wp_error($line_item_response)) {
                error_log('RepairShopr API Error (Add Line Item): ' . $line_item_response->get_error_message());
            }
        } else {
            error_log('SKU not found for product: ' . $product->get_name());
        }
    }

    
    // add the Electronic Payment Fee
    foreach ($order->get_fees() as $fee) {
        if ($fee->get_name() == 'Electronic Payment Fee') {
            $fee_total = $fee->get_total();
            $fee_total_formatted = number_format($fee_total, 2, '.', '');
            
            error_log('WooCommerce Fee Details: Name: ' . $fee->get_name() . ', Total: ' . $fee_total . ', Formatted Total: ' . $fee_total_formatted);
            
            $line_item = array(
                'item' => 'Electronic Payment Fee',
                'product_id' => '9263351', // The RepairShopr item ID
                'price' => $fee_total_formatted,
                'quantity' => '1',
                'taxable' => true
            );

            $line_item_url = $api_url . '/' . $invoice_id . '/line_items';

            error_log('RepairShopr API Request (Add Fee Line Item): ' . json_encode($line_item));

            $line_item_response = wp_remote_post($line_item_url, array(
                'headers' => array(
                    'Authorization' => 'Bearer ' . woo_inv_to_rs_get_api_key(),
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json'
                ),
                'body' => json_encode($line_item)
            ));

            $response_body = wp_remote_retrieve_body($line_item_response);
            error_log('RepairShopr API Response (Add Fee Line Item): ' . $response_body);

            if (is_wp_error($line_item_response)) {
                error_log('RepairShopr API Error (Add Fee Line Item): ' . $line_item_response->get_error_message());
            } else {
                $response_data = json_decode($response_body, true);
                if (isset($response_data['line_item']['id'])) {
                    $line_item_id = $response_data['line_item']['id'];
                    error_log('RepairShopr Line Item Created: ' . json_encode($response_data['line_item']));
                    
                    // Update the line item with the correct price
                    $update_line_item = array(
                        'price' => $fee_total_formatted
                    );
                    $update_url = $api_url . '/' . $invoice_id . '/line_items/' . $line_item_id;
                    
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
                            error_log('RepairShopr Line Item Updated: ' . json_encode($update_data['line_item']));
                        } else {
                            error_log('RepairShopr Line Item Update Failed. Response: ' . $update_body);
                        }
                    }
                } else {
                    error_log('RepairShopr Line Item Creation Failed. Response: ' . $response_body);
                }
            }
            break; // Exit the loop after processing the Electronic Payment Fee
        }
    }

    error_log('RepairShopr Invoice created successfully with line items. Invoice ID: ' . $invoice_id);
    return true;
}

// Add a new column to the WooCommerce orders table
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

// Add content to the new column
add_action('manage_shop_order_posts_custom_column', 'woo_inv_to_rs_order_repairshopr_column_content', 20, 2);
function woo_inv_to_rs_order_repairshopr_column_content($column, $post_id) {
    if ($column == 'repairshopr') {
        echo '<button type="button" class="button woo_inv_to_rs-send-to-repairshopr" data-order-id="' . esc_attr($post_id) . '">Send to RepairShopr</button>';
    }
}

// Enqueue JavaScript for AJAX functionality
function woo_inv_to_rs_enqueue_admin_scripts($hook) {
    if ('edit.php' !== $hook || !isset($_GET['post_type']) || $_GET['post_type'] !== 'shop_order') {
        return;
    }
    error_log('woo_inv_to_rs: Attempting to enqueue admin script');
    wp_enqueue_script('wir-admin-script', plugin_dir_url(__FILE__) . 'admin-script.js', array('jquery'), '1.0', true);
    wp_localize_script('wir-admin-script', 'woo_inv_to_rs_ajax', array(
        'ajax_url' => admin_url('admin-ajax.php'),
        'nonce' => wp_create_nonce('woo_inv_to_rs_nonce')
    ));
    error_log('woo_inv_to_rs: Admin script enqueued');
}
add_action('admin_enqueue_scripts', 'woo_inv_to_rs_enqueue_admin_scripts');

// AJAX handler for sending invoice to RepairShopr
add_action('wp_ajax_woo_inv_to_rs_send_to_repairshopr', 'woo_inv_to_rs_ajax_send_to_repairshopr');
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
        if ($result) {
            error_log('woo_inv_to_rs: Invoice sent to RepairShopr for order ' . $order_id);
            wp_send_json_success(array('message' => 'Invoice sent to RepairShopr'));
        } else {
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

function woo_invoice_to_repairshopr_settings_page() {
    if (!current_user_can('manage_options')) {
        wp_die(__('You do not have sufficient permissions to access this page.'));
    }

    $api_key = woo_inv_to_rs_get_api_key();
    $masked_key = '';
    if (!empty($api_key) && strlen($api_key) > 4) {
        $masked_key = str_repeat('*', max(0, strlen($api_key) - 4)) . substr($api_key, -4);
    } elseif (!empty($api_key)) {
        $masked_key = str_repeat('*', strlen($api_key));
    }

    if (isset($_POST['woo_inv_to_rs_api_key']) && check_admin_referer('woo_inv_to_rs_settings_nonce', 'woo_inv_to_rs_settings_nonce')) {
        $submitted_key = sanitize_text_field($_POST['woo_inv_to_rs_api_key']);
        // Only update if the submitted key is not the masked value (i.e., user entered a new key)
        if ($submitted_key !== $masked_key && $submitted_key !== '') {
            woo_inv_to_rs_set_api_key($submitted_key);
            echo '<div class="updated"><p>API Key updated.</p></div>';
            // Refresh $api_key and $masked_key after update
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
    ?>
    <div class="wrap">
        <h2>RepairShopr API Settings</h2>
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
            </table>
            <?php submit_button(); ?>
        </form>
    </div>
    <?php
}

// No closing PHP tag to avoid potential whitespace issues
