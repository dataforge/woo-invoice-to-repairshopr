<?php
/**
 * Main Plugin Class for Woo Invoice to RepairShopr
 * 
 * Handles plugin initialization and coordination between all components
 */

if (!defined('ABSPATH')) {
    exit;
}

class WIR_Plugin {
    
    /**
     * Plugin version
     */
    const VERSION = '1.11';
    
    /**
     * Single instance of the plugin
     */
    private static $instance = null;
    
    /**
     * Get single instance of the plugin
     * 
     * @return WIR_Plugin
     */
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    /**
     * Constructor
     */
    private function __construct() {
        $this->load_dependencies();
        $this->init_hooks();
    }
    
    /**
     * Load all required class files
     */
    private function load_dependencies() {
        $includes_path = plugin_dir_path(__FILE__);
        
        require_once $includes_path . 'class-wir-encryption.php';
        require_once $includes_path . 'class-wir-api-client.php';
        require_once $includes_path . 'class-wir-customer-handler.php';
        require_once $includes_path . 'class-wir-invoice-handler.php';
        require_once $includes_path . 'class-wir-payment-handler.php';
        require_once $includes_path . 'class-wir-ajax-handlers.php';
        require_once $includes_path . 'class-wir-woocommerce-integration.php';
        require_once $includes_path . 'class-wir-admin-settings.php';
    }
    
    /**
     * Initialize WordPress hooks
     */
    private function init_hooks() {
        // Initialize all components
        WIR_AJAX_Handlers::init();
        WIR_WooCommerce_Integration::init();
        WIR_Admin_Settings::init();
    }
    
    /**
     * Get plugin version
     * 
     * @return string
     */
    public static function get_version() {
        return self::VERSION;
    }
}

// Wrapper functions for backward compatibility
function save_encrypted_api_key($option_name, $api_key) {
    return WIR_Encryption::save_encrypted_api_key($option_name, $api_key);
}

function get_encrypted_api_key($option_name) {
    return WIR_Encryption::get_encrypted_api_key($option_name);
}

function woo_inv_to_rs_get_api_key() {
    return WIR_Encryption::get_api_key();
}

function woo_inv_to_rs_set_api_key($api_key) {
    return WIR_Encryption::set_api_key($api_key);
}

function woo_inv_to_rs_send_invoice_to_repairshopr($order_id) {
    return WIR_Invoice_Handler::send_invoice_to_repairshopr($order_id);
}

function woo_inv_to_rs_check_invoice_exists($invoice_number) {
    return WIR_Invoice_Handler::check_invoice_exists($invoice_number);
}

function woo_inv_to_rs_check_payment_exists($invoice_id, $amount, $transaction_id = '') {
    return WIR_Payment_Handler::check_payment_exists($invoice_id, $amount, $transaction_id);
}

function woo_inv_to_rs_get_repairshopr_customer($email) {
    return WIR_Customer_Handler::get_customer_id_by_email($email);
}

function woo_inv_to_rs_create_repairshopr_customer($order) {
    return WIR_Customer_Handler::create_customer_from_order($order);
}

function woo_inv_to_rs_create_repairshopr_invoice($order, $customer_id) {
    return WIR_Invoice_Handler::create_invoice($order, $customer_id);
}

function woo_inv_to_rs_send_payment_to_repairshopr($order_id) {
    return WIR_Payment_Handler::send_payment_to_repairshopr($order_id);
}

function woo_inv_to_rs_ajax_send_to_repairshopr() {
    return WIR_AJAX_Handlers::send_invoice();
}

function woo_inv_to_rs_ajax_send_payment_to_repairshopr() {
    return WIR_AJAX_Handlers::send_payment();
}

function woo_inv_to_rs_ajax_verify_invoice() {
    return WIR_AJAX_Handlers::verify_invoice();
}

function woo_inv_to_rs_ajax_verify_payment() {
    return WIR_AJAX_Handlers::verify_payment();
}

function woo_inv_to_rs_auto_send_payment_to_repairshopr($order_id) {
    return WIR_Payment_Handler::auto_send_payment_to_repairshopr($order_id);
}

function woo_inv_to_rs_add_order_repairshopr_column($columns) {
    return WIR_WooCommerce_Integration::add_order_repairshopr_column($columns);
}

function woo_inv_to_rs_order_repairshopr_column_content($column, $post_id) {
    return WIR_WooCommerce_Integration::order_repairshopr_column_content($column, $post_id);
}

function woo_inv_to_rs_hpos_add_order_repairshopr_column($columns) {
    return WIR_WooCommerce_Integration::hpos_add_order_repairshopr_column($columns);
}

function woo_inv_to_rs_hpos_order_repairshopr_column_content($column, $order) {
    return WIR_WooCommerce_Integration::hpos_order_repairshopr_column_content($column, $order);
}

function woo_inv_to_rs_enqueue_admin_scripts($hook) {
    return WIR_WooCommerce_Integration::enqueue_admin_scripts($hook);
}

function woo_invoice_to_repairshopr_menu() {
    return WIR_Admin_Settings::add_admin_menu();
}

function woo_invoice_to_repairshopr_settings_page() {
    return WIR_Admin_Settings::settings_page();
}
