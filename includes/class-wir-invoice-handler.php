<?php
/**
 * Invoice Handler for Woo Invoice to RepairShopr
 * 
 * Handles invoice creation and management in RepairShopr
 */

if (!defined('ABSPATH')) {
    exit;
}

class WIR_Invoice_Handler {
    
    /**
     * Check if an invoice already exists in RepairShopr by invoice number
     *
     * @param string $invoice_number The invoice number to check
     * @return array|false Returns invoice data if found, false if not found
     */
    public static function check_invoice_exists($invoice_number) {
        $invoice = WIR_API_Client::get_invoice_by_number($invoice_number);
        
        if ($invoice && isset($invoice['id'])) {
            error_log('woo_inv_to_rs: Invoice ' . $invoice_number . ' found with ID: ' . $invoice['id']);
            return $invoice;
        }
        
        error_log('woo_inv_to_rs: Invoice ' . $invoice_number . ' not found in RepairShopr');
        return false;
    }

    /**
     * Create RepairShopr invoice from WooCommerce order
     * 
     * @param WC_Order $order WooCommerce order object
     * @param int $customer_id RepairShopr customer ID
     * @return bool True on success, false on failure
     */
    public static function create_invoice($order, $customer_id) {
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
        $invoice_data = array(
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

        // Use complex URL building with fallback like original
        $api_base = get_option('woo_inv_to_rs_api_url', '');
        if ($api_base) {
            $api_url = rtrim($api_base, '/') . '/invoices';
        } else {
            $api_url = get_option('woo_inv_to_rs_invoice_url', 'https://your-subdomain.repairshopr.com/api/v1/invoices');
        }

        error_log('RepairShopr API Request (Create Invoice): ' . json_encode($invoice_data));

        $api_key = WIR_Encryption::get_api_key();
        if (empty($api_key)) {
            error_log('Failed to create invoice in RepairShopr - no API key');
            return false;
        }

        $response = wp_remote_post($api_url, array(
            'headers' => array(
                'Authorization' => 'Bearer ' . $api_key,
                'Content-Type' => 'application/json',
                'Accept' => 'application/json'
            ),
            'body' => json_encode($invoice_data)
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

        $invoice = $response_data['invoice'];
        
        if (!$invoice || !isset($invoice['id'])) {
            error_log('Failed to create invoice in RepairShopr');
            return false;
        }

        $invoice_id = $invoice['id'];

        // Add remaining line items one by one
        if (!empty($line_items)) {
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
                $line_item = WIR_API_Client::add_line_item($invoice_id, $li_body);
                
                if (!$line_item) {
                    error_log('Failed to add line item to invoice ' . $invoice_id);
                } else {
                    // Special handling for Electronic Payment Fee: update price with PUT if needed
                    if ($is_epf && isset($line_item['id'])) {
                        $line_item_id = $line_item['id'];
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
                        
                        error_log('woo_inv_to_rs: Electronic Payment Fee detected - updating price to: ' . $epf_price);
                        error_log('RepairShopr API Request (Update Fee Line Item): ' . json_encode($update_line_item));
                        
                        $updated_line_item = WIR_API_Client::update_line_item($invoice_id, $line_item_id, $update_line_item);
                        
                        if ($updated_line_item) {
                            error_log('woo_inv_to_rs: Electronic Payment Fee line item successfully updated with price: ' . $epf_price);
                            error_log('RepairShopr Line Item Updated: ' . json_encode($updated_line_item));
                        } else {
                            error_log('woo_inv_to_rs: Electronic Payment Fee line item update failed.');
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
            $verification_invoice = WIR_API_Client::get_invoice_by_number(get_option('woo_inv_to_rs_invoice_prefix', '') . $order->get_order_number());
            
            if ($verification_invoice) {
                $rs_total = floatval($verification_invoice['total']);
                
                error_log('woo_inv_to_rs: Rounding Check - WC Total: ' . $wc_total . ', RS Total: ' . $rs_total);
                
                // Calculate the difference
                $total_difference = $wc_total - $rs_total;
                
                // Check if difference is within acceptable range for rounding correction
                if (abs($total_difference) > 0.001 && abs($total_difference) <= 0.10) {
                    error_log('woo_inv_to_rs: Rounding difference detected: ' . $total_difference . '. Adding rounding correction line item.');
                    
                    // Round the difference to exactly 2 decimal places to avoid precision issues
                    $rounded_difference = round($total_difference, 2);
                    
                    // Add rounding correction line item
                    $rounding_correction_body = array(
                        'id' => 0,
                        'line_discount_percent' => 0,
                        'discount_dollars' => '0',
                        'product_id' => $rounding_correction_product_id,
                        'price' => $rounded_difference, // Send as float like other line items
                        'cost' => 0,
                        'quantity' => 1,
                        'taxable' => false // Rounding corrections should not be taxable
                    );
                    
                    error_log('woo_inv_to_rs: Adding rounding correction line item: ' . json_encode($rounding_correction_body));
                    
                    $rounding_line_item = WIR_API_Client::add_line_item($invoice_id, $rounding_correction_body);
                    
                    if ($rounding_line_item && isset($rounding_line_item['id'])) {
                        $rounding_line_item_id = $rounding_line_item['id'];
                        
                        // Update the rounding correction price with PUT request (same approach as EPF)
                        $update_rounding_item = array(
                            'price' => $rounded_difference
                        );
                        
                        error_log('woo_inv_to_rs: Rounding correction detected - updating price to: ' . $rounded_difference);
                        error_log('RepairShopr API Request (Update Rounding Line Item): ' . json_encode($update_rounding_item));
                        
                        $updated_rounding_item = WIR_API_Client::update_line_item($invoice_id, $rounding_line_item_id, $update_rounding_item);
                        
                        if ($updated_rounding_item) {
                            error_log('woo_inv_to_rs: Rounding correction line item successfully updated with price: ' . $rounded_difference);
                            error_log('RepairShopr Rounding Line Item Updated: ' . json_encode($updated_rounding_item));
                        } else {
                            error_log('woo_inv_to_rs: Rounding correction line item update failed.');
                        }
                        
                        error_log('woo_inv_to_rs: Rounding correction successfully applied. Amount: ' . $total_difference);
                    } else {
                        error_log('woo_inv_to_rs: Rounding correction detected but could not get line_item ID from response');
                    }
                } elseif (abs($total_difference) > 0.10) {
                    error_log('woo_inv_to_rs: Total difference (' . $total_difference . ') exceeds maximum rounding correction limit of $0.10. No correction applied.');
                } else {
                    error_log('woo_inv_to_rs: Totals match within acceptable precision. No rounding correction needed.');
                }
            } else {
                error_log('woo_inv_to_rs: Could not verify invoice totals for rounding correction.');
            }
        } else {
            error_log('woo_inv_to_rs: Rounding correction not configured. Skipping rounding correction check.');
        }

        error_log('RepairShopr Invoice created successfully with all line items. Invoice ID: ' . $invoice_id);
        return true;
    }

    /**
     * Send invoice to RepairShopr for a given order
     * 
     * @param int $order_id The WooCommerce order ID
     * @return array|bool Result array or boolean
     */
    public static function send_invoice_to_repairshopr($order_id) {
        $order = wc_get_order($order_id);
        
        // Check if the order is paid
        if (!$order->is_paid()) {
            error_log("woo_inv_to_rs: Order $order_id is not paid. Skipping RepairShopr integration.");
            return false;
        }

        $customer_email = $order->get_billing_email();

        error_log("woo_inv_to_rs: Starting to process paid order $order_id for customer email: $customer_email");

        // Check if invoice already exists in RepairShopr
        $invoice_prefix = get_option('woo_inv_to_rs_invoice_prefix', '');
        $invoice_number = $invoice_prefix . $order->get_order_number();
        $existing_invoice = self::check_invoice_exists($invoice_number);
        
        if ($existing_invoice) {
            error_log("woo_inv_to_rs: Invoice $invoice_number already exists in RepairShopr with ID: " . $existing_invoice['id']);
            return array('exists' => true, 'invoice_id' => $existing_invoice['id'], 'message' => 'Invoice already exists in RepairShopr');
        }

        // Get or create customer in RepairShopr
        $customer_id = WIR_Customer_Handler::get_or_create_customer($order);
        if (!$customer_id) {
            error_log('woo_inv_to_rs: Failed to create/find customer in RepairShopr');
            return false;
        }

        // Create invoice in RepairShopr
        $invoice_created = self::create_invoice($order, $customer_id);
        if (!$invoice_created) {
            error_log('woo_inv_to_rs: Failed to create invoice in RepairShopr');
            return false;
        }

        error_log("woo_inv_to_rs: Invoice successfully created in RepairShopr for order $order_id");
        return true;
    }
}
