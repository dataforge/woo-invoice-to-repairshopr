<?php
/**
 * Customer Handler for Woo Invoice to RepairShopr
 * 
 * Handles customer creation and management in RepairShopr
 */

if (!defined('ABSPATH')) {
    exit;
}

class WIR_Customer_Handler {
    
    /**
     * Get RepairShopr customer by email
     * 
     * @param string $email Customer email
     * @return int|false Customer ID or false if not found
     */
    public static function get_customer_id_by_email($email) {
        $customer = WIR_API_Client::get_customer_by_email($email);
        
        if ($customer && isset($customer['id'])) {
            return $customer['id'];
        }
        
        return false;
    }

    /**
     * Create RepairShopr customer from WooCommerce order
     * 
     * @param WC_Order $order WooCommerce order object
     * @return int|false Customer ID or false on failure
     */
    public static function create_customer_from_order($order) {
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
        $customer_data = array(
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
        $customer_data['address'] = $address ?: null;
        $customer_data['address_2'] = $address_2 ?: null;
        $customer_data['city'] = $city ?: null;
        $customer_data['state'] = $state ?: null;
        $customer_data['zip'] = $zip ?: null;
        $customer_data['business_and_full_name'] = $business_name ? $business_name : $fullname;
        $customer_data['business_then_name'] = $business_name ? $business_name : $fullname;

        // Use complex URL building with fallback like original
        $api_base = get_option('woo_inv_to_rs_api_url', '');
        if ($api_base) {
            // Remove any trailing /customers or /customers/ from the base
            // Always append /customers to the API base (which should NOT end with /customers)
            $api_url = rtrim($api_base, '/') . '/customers';
        } else {
            $api_url = get_option('woo_inv_to_rs_customer_url', 'https://your-subdomain.repairshopr.com/api/v1/customers');
        }

        // Log the API request
        error_log('RepairShopr Customer API Request: ' . json_encode($customer_data));

        $api_key = WIR_Encryption::get_api_key();
        if (empty($api_key)) {
            return false;
        }

        $response = wp_remote_post($api_url, array(
            'headers' => array(
                'Authorization' => 'Bearer ' . $api_key,
                'Content-Type' => 'application/json'
            ),
            'body' => json_encode($customer_data)
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

    /**
     * Get or create customer in RepairShopr
     * 
     * @param WC_Order $order WooCommerce order object
     * @return int|false Customer ID or false on failure
     */
    public static function get_or_create_customer($order) {
        $customer_email = $order->get_billing_email();
        
        // First try to get existing customer
        $customer_id = self::get_customer_id_by_email($customer_email);
        
        if ($customer_id) {
            error_log("woo_inv_to_rs: Customer found in RepairShopr with ID: $customer_id");
            return $customer_id;
        }
        
        // Customer not found, create new one
        error_log("woo_inv_to_rs: Customer not found in RepairShopr. Attempting to create...");
        $customer_id = self::create_customer_from_order($order);
        
        if ($customer_id) {
            error_log("woo_inv_to_rs: Customer created in RepairShopr with ID: $customer_id");
            return $customer_id;
        }
        
        error_log('woo_inv_to_rs: Failed to create customer in RepairShopr');
        return false;
    }
}
