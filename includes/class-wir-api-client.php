<?php
/**
 * RepairShopr API Client for Woo Invoice to RepairShopr
 * 
 * Handles all API communications with RepairShopr
 */

if (!defined('ABSPATH')) {
    exit;
}

class WIR_API_Client {
    
    /**
     * Get the base API URL
     * 
     * @return string The base API URL
     */
    public static function get_api_base() {
        $api_base = get_option('woo_inv_to_rs_api_url', '');
        // Sanitize: remove any trailing endpoint (e.g., /customers, /invoices, /payment_methods)
        return preg_replace('#/(customers|invoices|payment_methods)$#', '', rtrim($api_base, '/'));
    }

    /**
     * Get the API key
     * 
     * @return string The API key
     */
    public static function get_api_key() {
        return WIR_Encryption::get_api_key();
    }

    /**
     * Make a GET request to the RepairShopr API
     * 
     * @param string $endpoint The API endpoint (without base URL)
     * @param array $params Optional query parameters
     * @return array|WP_Error The response data or WP_Error on failure
     */
    public static function get($endpoint, $params = array()) {
        $api_base = self::get_api_base();
        $api_key = self::get_api_key();
        
        if (empty($api_base) || empty($api_key)) {
            return new WP_Error('api_config', 'API URL or API Key not configured');
        }
        
        $url = rtrim($api_base, '/') . '/' . ltrim($endpoint, '/');
        
        if (!empty($params)) {
            $url .= '?' . http_build_query($params);
        }
        
        error_log('woo_inv_to_rs: API GET Request: ' . $url);
        
        $response = wp_remote_get($url, array(
            'headers' => array(
                'Authorization' => 'Bearer ' . $api_key,
                'Accept' => 'application/json'
            )
        ));
        
        return self::handle_response($response);
    }

    /**
     * Make a POST request to the RepairShopr API
     * 
     * @param string $endpoint The API endpoint (without base URL)
     * @param array $data The data to send
     * @return array|WP_Error The response data or WP_Error on failure
     */
    public static function post($endpoint, $data = array()) {
        $api_base = self::get_api_base();
        $api_key = self::get_api_key();
        
        if (empty($api_base) || empty($api_key)) {
            return new WP_Error('api_config', 'API URL or API Key not configured');
        }
        
        $url = rtrim($api_base, '/') . '/' . ltrim($endpoint, '/');
        
        error_log('woo_inv_to_rs: API POST Request: ' . $url);
        error_log('woo_inv_to_rs: API POST Data: ' . json_encode($data));
        
        $response = wp_remote_post($url, array(
            'headers' => array(
                'Authorization' => 'Bearer ' . $api_key,
                'Content-Type' => 'application/json',
                'Accept' => 'application/json'
            ),
            'body' => json_encode($data)
        ));
        
        return self::handle_response($response);
    }

    /**
     * Make a PUT request to the RepairShopr API
     * 
     * @param string $endpoint The API endpoint (without base URL)
     * @param array $data The data to send
     * @return array|WP_Error The response data or WP_Error on failure
     */
    public static function put($endpoint, $data = array()) {
        $api_base = self::get_api_base();
        $api_key = self::get_api_key();
        
        if (empty($api_base) || empty($api_key)) {
            return new WP_Error('api_config', 'API URL or API Key not configured');
        }
        
        $url = rtrim($api_base, '/') . '/' . ltrim($endpoint, '/');
        
        error_log('woo_inv_to_rs: API PUT Request: ' . $url);
        error_log('woo_inv_to_rs: API PUT Data: ' . json_encode($data));
        
        $response = wp_remote_request($url, array(
            'method' => 'PUT',
            'headers' => array(
                'Authorization' => 'Bearer ' . $api_key,
                'Content-Type' => 'application/json',
                'Accept' => 'application/json'
            ),
            'body' => json_encode($data)
        ));
        
        return self::handle_response($response);
    }

    /**
     * Handle API response
     * 
     * @param array|WP_Error $response The wp_remote_* response
     * @return array|WP_Error The parsed response data or WP_Error
     */
    private static function handle_response($response) {
        if (is_wp_error($response)) {
            error_log('woo_inv_to_rs: API Error: ' . $response->get_error_message());
            return $response;
        }
        
        $body = wp_remote_retrieve_body($response);
        $http_code = wp_remote_retrieve_response_code($response);
        
        error_log('woo_inv_to_rs: API Response (HTTP ' . $http_code . '): ' . $body);
        
        $data = json_decode($body, true);
        
        if ($http_code >= 400) {
            $error_message = 'HTTP ' . $http_code;
            if (isset($data['error'])) {
                $error_message .= ': ' . $data['error'];
            }
            return new WP_Error('api_error', $error_message, array('response' => $data, 'http_code' => $http_code));
        }
        
        return $data;
    }

    /**
     * Get customer by email
     * 
     * @param string $email Customer email
     * @return array|false Customer data or false if not found
     */
    public static function get_customer_by_email($email) {
        $api_key = self::get_api_key();
        if (empty($api_key)) {
            return false;
        }

        // Always use the base API URL and call the customers endpoint as a relative path
        $endpoint = 'customers?email=' . urlencode(strtolower($email));
        $response = self::get($endpoint);

        // Log the API response
        error_log('RepairShopr Get Customer API Response: ' . json_encode($response));

        if (is_wp_error($response) || empty($response['customers'])) {
            return false;
        }

        return $response['customers'][0];
    }

    /**
     * Create customer
     * 
     * @param array $customer_data Customer data
     * @return array|false Customer data or false on failure
     */
    public static function create_customer($customer_data) {
        $response = self::post('customers', $customer_data);
        
        if (is_wp_error($response)) {
            return false;
        }
        
        if (isset($response['customer'])) {
            return $response['customer'];
        }
        
        return false;
    }

    /**
     * Get invoice by number
     * 
     * @param string $invoice_number Invoice number
     * @return array|false Invoice data or false if not found
     */
    public static function get_invoice_by_number($invoice_number) {
        $response = self::get('invoices/' . urlencode($invoice_number));
        
        if (is_wp_error($response)) {
            return false;
        }
        
        if (isset($response['invoice'])) {
            return $response['invoice'];
        }
        
        return false;
    }

    /**
     * Create invoice
     * 
     * @param array $invoice_data Invoice data
     * @return array|false Invoice data or false on failure
     */
    public static function create_invoice($invoice_data) {
        $response = self::post('invoices', $invoice_data);
        
        if (is_wp_error($response)) {
            return false;
        }
        
        if (isset($response['invoice'])) {
            return $response['invoice'];
        }
        
        return false;
    }

    /**
     * Add line item to invoice
     * 
     * @param int $invoice_id Invoice ID
     * @param array $line_item_data Line item data
     * @return array|false Line item data or false on failure
     */
    public static function add_line_item($invoice_id, $line_item_data) {
        $response = self::post('invoices/' . $invoice_id . '/line_items', $line_item_data);
        
        if (is_wp_error($response)) {
            return false;
        }
        
        if (isset($response['line_item'])) {
            return $response['line_item'];
        }
        
        return false;
    }

    /**
     * Update line item
     * 
     * @param int $invoice_id Invoice ID
     * @param int $line_item_id Line item ID
     * @param array $line_item_data Line item data
     * @return array|false Line item data or false on failure
     */
    public static function update_line_item($invoice_id, $line_item_id, $line_item_data) {
        $response = self::put('invoices/' . $invoice_id . '/line_items/' . $line_item_id, $line_item_data);
        
        if (is_wp_error($response)) {
            return false;
        }
        
        if (isset($response['line_item'])) {
            return $response['line_item'];
        }
        
        return false;
    }

    /**
     * Create payment
     * 
     * @param array $payment_data Payment data
     * @return array|false Payment data or false on failure
     */
    public static function create_payment($payment_data) {
        $response = self::post('payments', $payment_data);
        
        if (is_wp_error($response)) {
            return false;
        }
        
        if (isset($response['payment'])) {
            return $response['payment'];
        }
        
        return false;
    }

    /**
     * Get payment methods
     * 
     * @return array|false Payment methods or false on failure
     */
    public static function get_payment_methods() {
        $response = self::get('payment_methods');
        
        if (is_wp_error($response)) {
            return false;
        }
        
        if (isset($response['payment_methods'])) {
            return $response['payment_methods'];
        }
        
        return false;
    }
}
