<?php
/**
 * Payment Handler for Woo Invoice to RepairShopr
 * 
 * Handles payment creation and management in RepairShopr
 */

if (!defined('ABSPATH')) {
    exit;
}

class WIR_Payment_Handler {

    /**
     * Order meta holding the ID of the RepairShopr payment this plugin created for the order.
     */
    const META_RS_PAYMENT_ID = '_wir_rs_payment_id';

    /**
     * Pages of GET /payments (25 per page, newest first) to scan for a matching ref_num.
     * RS ignores a customer_id filter on this endpoint, so the scan is the only way to
     * find a payment that is not listed on its invoice.
     */
    const PAYMENT_SCAN_MAX_PAGES = 10;

    /**
     * Find a RepairShopr payment already recorded for this order, whether or not RS
     * applied it to the invoice.
     *
     * RS accepts a payment larger than the invoice total and reports success, but keeps
     * it off the invoice's payments list. Checking the invoice alone therefore lets a
     * second payment through (orders 10108 and 1529, Nov 2026).
     *
     * @param WC_Order $order
     * @param int $invoice_id RepairShopr invoice ID
     * @return array { payment: array|null, applied: bool, error: string }
     */
    public static function find_existing_payment($order, $invoice_id) {
        $transaction_id = (string) $order->get_transaction_id();

        // 1. A payment RS lists on the invoice.
        $on_invoice = self::check_payment_exists($invoice_id, $order->get_total(), $transaction_id);
        if ($on_invoice) {
            return array('payment' => $on_invoice, 'applied' => true, 'error' => '');
        }

        // 2. The payment this plugin created for the order earlier.
        $stored_id = $order->get_meta(self::META_RS_PAYMENT_ID);
        if ($stored_id) {
            $res = WIR_API_Client::get('payments/' . rawurlencode($stored_id));
            if (!is_wp_error($res) && isset($res['payment'])) {
                return array('payment' => $res['payment'], 'applied' => false, 'error' => '');
            }
            $http_code = 0;
            if (is_wp_error($res)) {
                $data = $res->get_error_data();
                $http_code = is_array($data) && isset($data['http_code']) ? (int) $data['http_code'] : 0;
            }
            if ($http_code !== 404) {
                $reason = is_wp_error($res) ? $res->get_error_message() : 'unexpected response';
                return array('payment' => null, 'applied' => false, 'error' => 'Could not check RepairShopr payment #' . $stored_id . ' (' . $reason . ').');
            }
            // Deleted in RepairShopr: forget it so a new payment can be sent.
            error_log('woo_inv_to_rs: Stored RS payment ' . $stored_id . ' for order ' . $order->get_id() . ' no longer exists; clearing it.');
            $order->delete_meta_data(self::META_RS_PAYMENT_ID);
            $order->save();
        }

        // 3. Same transaction reference among recent payments (covers orders paid before
        //    the payment ID was stored).
        if ($transaction_id !== '') {
            $found = self::find_payment_by_ref($transaction_id, $order);
            if (is_wp_error($found)) {
                return array('payment' => null, 'applied' => false, 'error' => 'Could not search RepairShopr payments (' . $found->get_error_message() . ').');
            }
            if ($found) {
                return array('payment' => $found, 'applied' => false, 'error' => '');
            }
        }

        return array('payment' => null, 'applied' => false, 'error' => '');
    }

    /**
     * Scan recent RepairShopr payments (newest first) for one with this ref_num, stopping
     * once payments are older than the order.
     *
     * @param string $ref_num WooCommerce transaction ID
     * @param WC_Order $order
     * @return array|false|WP_Error Matching payment, false if none, WP_Error on API failure
     */
    private static function find_payment_by_ref($ref_num, $order) {
        $created = $order->get_date_created();
        $cutoff = $created ? $created->getTimestamp() - DAY_IN_SECONDS : 0;

        for ($page = 1; $page <= self::PAYMENT_SCAN_MAX_PAGES; $page++) {
            $res = WIR_API_Client::get('payments', array('page' => $page));
            if (is_wp_error($res)) {
                return $res;
            }
            $payments = isset($res['payments']) && is_array($res['payments']) ? $res['payments'] : array();
            if (empty($payments)) {
                return false;
            }
            foreach ($payments as $payment) {
                if (isset($payment['ref_num']) && (string) $payment['ref_num'] === $ref_num) {
                    error_log('woo_inv_to_rs: Found RS payment ' . $payment['id'] . ' with ref_num ' . $ref_num);
                    return $payment;
                }
            }
            $last = end($payments);
            $last_ts = isset($last['created_at']) ? strtotime($last['created_at']) : false;
            if ($last_ts !== false && $last_ts < $cutoff) {
                return false;
            }
            if (isset($res['meta']['total_pages']) && $page >= (int) $res['meta']['total_pages']) {
                return false;
            }
        }

        error_log('woo_inv_to_rs: Scanned ' . self::PAYMENT_SCAN_MAX_PAGES . ' pages of RS payments without reaching order ' . $order->get_id() . ' date; no ref_num match found.');
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
    public static function check_payment_exists($invoice_id, $amount, $transaction_id = '') {
        // Get invoice details to check existing payments by ID
        $invoice = WIR_API_Client::get('invoices/' . $invoice_id);
        
        if (is_wp_error($invoice) || !isset($invoice['invoice']) || empty($invoice['invoice']['payments'])) {
            error_log('woo_inv_to_rs: No payments found for invoice ID: ' . $invoice_id);
            return false;
        }
        
        $invoice = $invoice['invoice'];
        
        $payments = $invoice['payments'];
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

    /**
     * Get RepairShopr payment method name by ID
     * 
     * @param string $payment_method_id RepairShopr payment method ID
     * @return string|false Payment method name or false if not found
     */
    public static function get_payment_method_name($payment_method_id) {
        $payment_methods = WIR_API_Client::get_payment_methods();
        
        if ($payment_methods) {
            foreach ($payment_methods as $pm) {
                if ($pm['id'] == $payment_method_id) {
                    return $pm['name'];
                }
            }
        }
        
        return false;
    }

    /**
     * Send payment to RepairShopr for a given order
     * 
     * @param int $order_id The WooCommerce order ID
     * @return array Result array with success/error status and message
     */
    public static function send_payment_to_repairshopr($order_id) {
        error_log('woo_inv_to_rs: Starting payment sync for order ' . $order_id);

        if ($order_id <= 0) {
            error_log('woo_inv_to_rs: Invalid order ID (Send Payment)');
            return array('success' => false, 'message' => 'Invalid order ID');
        }

        $order = wc_get_order($order_id);
        if (!$order) {
            error_log('woo_inv_to_rs: Order not found (Send Payment)');
            return array('success' => false, 'message' => 'Order not found');
        }
        if (!$order->is_paid()) {
            error_log('woo_inv_to_rs: Order not paid (Send Payment)');
            return array('success' => false, 'message' => 'Order is not paid');
        }

        // Get payment method mapping
        $wc_payment_method = $order->get_payment_method();
        $payment_mapping = get_option('woo_inv_to_rs_payment_mapping', array());
        $rs_payment_method_id = isset($payment_mapping[$wc_payment_method]) ? $payment_mapping[$wc_payment_method] : '';
        if (!$rs_payment_method_id) {
            error_log('woo_inv_to_rs: No RepairShopr payment method mapped for WooCommerce method: ' . $wc_payment_method);
            return array('success' => false, 'message' => 'No RepairShopr payment method mapped for this WooCommerce payment method.');
        }

        // Get customer in RepairShopr
        $customer_id = WIR_Customer_Handler::get_or_create_customer($order);
        if (!$customer_id) {
            error_log('woo_inv_to_rs: Failed to create/find customer in RepairShopr (Send Payment)');
            return array('success' => false, 'message' => 'Failed to create/find customer in RepairShopr.');
        }

        // Find invoice in RepairShopr by order number
        $prefix = get_option('woo_inv_to_rs_invoice_prefix', '');
        $invoice_number = $prefix . $order->get_order_number();
        $invoice = WIR_API_Client::get_invoice_by_number($invoice_number);
        
        if (!$invoice || !isset($invoice['id'])) {
            error_log('woo_inv_to_rs: Could not find RepairShopr invoice for order ' . $order_id . ' (number: ' . $invoice_number . ')');
            return array('success' => false, 'message' => 'Could not find RepairShopr invoice for this order.');
        }
        
        $invoice_id = $invoice['id'];
        error_log('woo_inv_to_rs: Found invoice_id: ' . $invoice_id . ' for order_id: ' . $order_id);

        // The RS invoice total must match the order. If RS calculated it differently
        // (e.g. a line saved at qty 0, or its own tax), RS accepts the payment but does
        // not apply it, and the invoice keeps showing as unpaid.
        $wc_total_cents = intval(round(floatval($order->get_total()) * 100));
        $rs_total_cents = isset($invoice['total']) ? intval(round(floatval($invoice['total']) * 100)) : null;
        if ($rs_total_cents === null || $rs_total_cents !== $wc_total_cents) {
            $msg = sprintf(
                'RepairShopr invoice %s total is $%s but the WooCommerce order total is $%s. Payment was not sent. Fix the invoice in RepairShopr so the totals match, then click Send Payment.',
                $invoice_number,
                $rs_total_cents === null ? '?' : number_format($rs_total_cents / 100, 2, '.', ''),
                number_format($wc_total_cents / 100, 2, '.', '')
            );
            error_log('woo_inv_to_rs: ' . $msg);
            $order->add_order_note($msg);
            return array('success' => false, 'message' => $msg);
        }

        // Check for a payment already recorded for this order, applied or not
        $existing = self::find_existing_payment($order, $invoice_id);

        if ($existing['error'] !== '') {
            $msg = $existing['error'] . ' Payment was not sent, to avoid a duplicate.';
            error_log('woo_inv_to_rs: ' . $msg);
            return array('success' => false, 'message' => $msg);
        }

        if ($existing['payment']) {
            $existing_id = isset($existing['payment']['id']) ? $existing['payment']['id'] : '?';
            if ($existing['applied']) {
                error_log('woo_inv_to_rs: Payment already exists for invoice ' . $invoice_id . ' with Payment ID: ' . $existing_id);
                return array('success' => false, 'message' => 'Payment already exists for this invoice in RepairShopr.');
            }
            $msg = sprintf(
                'RepairShopr payment #%s ($%s, ref %s) already exists for this order but is not applied to invoice %s. Apply that payment to the invoice in RepairShopr instead of sending a new one.',
                $existing_id,
                isset($existing['payment']['payment_amount']) ? number_format(floatval($existing['payment']['payment_amount']), 2, '.', '') : '?',
                isset($existing['payment']['ref_num']) && $existing['payment']['ref_num'] !== '' ? $existing['payment']['ref_num'] : 'none',
                $invoice_number
            );
            error_log('woo_inv_to_rs: ' . $msg);
            $order->add_order_note($msg);
            return array('success' => false, 'message' => $msg);
        }

        // Prepare payment data - use exact WooCommerce total with precision handling
        $wc_total = $order->get_total();
        $amount_cents = intval(round($wc_total * 100));
        $payment_amount = number_format($wc_total, 2, '.', ''); // Use WooCommerce total directly
        
        error_log('woo_inv_to_rs: Payment Amount Precision - WC Total: ' . $wc_total . ', Amount Cents: ' . $amount_cents . ', Payment Amount: ' . $payment_amount);
        
        $address_street = $order->get_billing_address_1();
        $address_city = $order->get_billing_city();
        $address_zip = $order->get_billing_postcode();
        
        // Get RepairShopr payment method name for API
        $payment_method_name = self::get_payment_method_name($rs_payment_method_id);
        if (!$payment_method_name) {
            error_log('woo_inv_to_rs: Could not resolve RepairShopr payment method name for id ' . $rs_payment_method_id);
            return array('success' => false, 'message' => 'Could not resolve RepairShopr payment method name.');
        }

        // Build payment body
        $payment_data = array(
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
        error_log('woo_inv_to_rs: Payment payload to RepairShopr: ' . json_encode($payment_data));
        if (function_exists('wp_debug_backtrace_summary')) {
            if (defined('WP_DEBUG_LOG') && WP_DEBUG_LOG) {
                $logfile = WP_CONTENT_DIR . '/debug.log';
                $logline = date('c') . ' woo_inv_to_rs: Payment payload to RepairShopr: ' . json_encode($payment_data) . PHP_EOL;
                file_put_contents($logfile, $logline, FILE_APPEND);
            }
        }

        // Send payment to RepairShopr
        $payment = WIR_API_Client::create_payment($payment_data);

        // Log the payment API response to error_log for debug_log visibility
        error_log('woo_inv_to_rs: Payment API Response: ' . json_encode($payment));

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
        $logline = date('c') . ' woo_inv_to_rs: Payment API Response: ' . json_encode($payment) . PHP_EOL;
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

        if (!$payment) {
            error_log('woo_inv_to_rs: Error sending payment to RepairShopr');
            return array('success' => false, 'message' => 'Error sending payment to RepairShopr');
        }

        if (!empty($payment['success'])) {
            $payment_id = isset($payment['id']) ? $payment['id'] : null;
            if (!$payment_id) {
                error_log('woo_inv_to_rs: Could not extract payment ID from RepairShopr response.');
                $order->add_order_note('RepairShopr: payment created but RepairShopr did not return its ID. Check the invoice in RepairShopr before sending again.');
                return array('success' => true, 'message' => 'Payment sent to RepairShopr, but RepairShopr did not return its ID. Check the invoice in RepairShopr.');
            }

            // Remember the payment so later sends can find it even if RS leaves it off the invoice.
            $order->update_meta_data(self::META_RS_PAYMENT_ID, (string) $payment_id);
            $order->save();

            // Confirm RS actually applied it: the payment response lists invoice_ids even
            // when the invoice itself shows no payment.
            $applied = false;
            $check = WIR_API_Client::get('invoices/' . $invoice_id);
            if (!is_wp_error($check) && !empty($check['invoice']['payments']) && is_array($check['invoice']['payments'])) {
                foreach ($check['invoice']['payments'] as $applied_payment) {
                    if (isset($applied_payment['id']) && (string) $applied_payment['id'] === (string) $payment_id) {
                        $applied = true;
                        break;
                    }
                }
            }

            if (!$applied) {
                $msg = sprintf(
                    'RepairShopr payment #%s ($%s) was created but RepairShopr has not applied it to invoice %s. Apply it to the invoice in RepairShopr. Do not send another payment.',
                    $payment_id,
                    $payment_amount,
                    $invoice_number
                );
                error_log('woo_inv_to_rs: ' . $msg);
                $order->add_order_note($msg);
                return array('success' => false, 'message' => $msg);
            }

            error_log('woo_inv_to_rs: Payment ' . $payment_id . ' successfully applied in RepairShopr for order ' . $order_id);
            $order->add_order_note(sprintf('RepairShopr: payment #%s ($%s) applied to invoice %s.', $payment_id, $payment_amount, $invoice_number));

            return array('success' => true, 'message' => 'Payment successfully applied in RepairShopr.');
        } else {
            error_log('woo_inv_to_rs: Failed to apply payment in RepairShopr for order ' . $order_id . '. Response: ' . json_encode($payment));
            return array('success' => false, 'message' => 'Failed to apply payment in RepairShopr. Response: ' . json_encode($payment));
        }
    }

    /**
     * Automatic payment sync function - called by WooCommerce hook
     * 
     * @param int $order_id The WooCommerce order ID
     */
    public static function auto_send_payment_to_repairshopr($order_id) {
        // Check if auto-sync payment is enabled
        if (get_option('woo_inv_to_rs_auto_sync_payment', '') !== '1') {
            error_log("woo_inv_to_rs: Auto-sync payment is disabled. Skipping payment sync for order $order_id.");
            return;
        }

        error_log("woo_inv_to_rs: Auto-sync payment is enabled. Starting payment sync for order $order_id.");
        
        // Call the payment function
        $result = self::send_payment_to_repairshopr($order_id);
        
        if ($result['success']) {
            error_log("woo_inv_to_rs: Auto payment sync successful for order $order_id: " . $result['message']);
        } else {
            error_log("woo_inv_to_rs: Auto payment sync failed for order $order_id: " . $result['message']);
        }
    }

    /**
     * Verify payment status in RepairShopr
     * 
     * @param int $order_id The WooCommerce order ID
     * @return array Result with payment verification details
     */
    public static function verify_payment_status($order_id) {
        error_log('woo_inv_to_rs: Starting payment verification for order ' . $order_id);

        if ($order_id <= 0) {
            error_log('woo_inv_to_rs: Invalid order ID (Verify Payment)');
            return array('success' => false, 'message' => 'Invalid order ID');
        }

        $order = wc_get_order($order_id);
        if (!$order) {
            error_log('woo_inv_to_rs: Order not found (Verify Payment)');
            return array('success' => false, 'message' => 'Order not found');
        }

        $invoice_prefix = get_option('woo_inv_to_rs_invoice_prefix', '');
        $invoice_number = $invoice_prefix . $order->get_order_number();
        $invoice = WIR_API_Client::get_invoice_by_number($invoice_number);

        if (!$invoice) {
            error_log('woo_inv_to_rs: RepairShopr invoice not found for payment verification. Invoice number: ' . $invoice_number);
            return array(
                'success' => false,
                'message' => sprintf(
                    'RepairShopr Invoice %s not found.',
                    esc_html($invoice_number)
                )
            );
        }

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
            return array(
                'success' => true,
                'message' => 'Invoice is Paid!',
                'paid' => true,
                'details' => $payment_details
            );
        } else {
            $result = array(
                'success' => true,
                'message' => 'Invoice is Unpaid!',
                'paid' => false,
                'details' => $payment_details
            );

            // An unpaid invoice may still have a payment RS recorded but did not apply.
            // Say so, so nobody sends a second one.
            if (isset($invoice['id'])) {
                $existing = self::find_existing_payment($order, $invoice['id']);
                if ($existing['payment'] && !$existing['applied']) {
                    $result['warning'] = sprintf(
                        'RepairShopr shows invoice %s as unpaid, but payment #%s ($%s) already exists for this order and is not applied to it. Apply that payment to the invoice in RepairShopr. Do not click Send Payment.',
                        $invoice_number,
                        isset($existing['payment']['id']) ? $existing['payment']['id'] : '?',
                        isset($existing['payment']['payment_amount']) ? number_format(floatval($existing['payment']['payment_amount']), 2, '.', '') : '?'
                    );
                    $result['message'] = $result['warning'];
                } elseif ($existing['error'] !== '') {
                    $result['warning'] = $existing['error'] . ' Check RepairShopr for an existing payment before clicking Send Payment.';
                }
            }

            return $result;
        }
    }
}
