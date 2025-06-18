<?php
/*
Plugin Name: Woo Invoice to RepairShopr
Plugin URI: https://github.com/dataforge/woo-invoice-to-repairshopr
Description: Sends invoice details to RepairShopr when an invoice is paid in WooCommerce.
Version: 1.12
Author: Dataforge
GitHub Plugin URI: https://github.com/dataforge/woo-invoice-to-repairshopr
*/

if (!defined('ABSPATH')) {
    exit;
}

// Define plugin constants
define('WIR_PLUGIN_FILE', __FILE__);
define('WIR_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('WIR_PLUGIN_URL', plugin_dir_url(__FILE__));

/**
 * Main plugin initialization
 */
function woo_invoice_to_repairshopr_init() {
    // Load the main plugin class
    require_once WIR_PLUGIN_DIR . 'includes/class-wir-plugin.php';
    
    // Initialize the plugin
    WIR_Plugin::get_instance();
}

// Initialize the plugin
add_action('plugins_loaded', 'woo_invoice_to_repairshopr_init');

/**
 * Plugin activation hook
 */
function woo_invoice_to_repairshopr_activate() {
    // Add any activation tasks here if needed
    error_log('woo_inv_to_rs: Plugin activated');
}
register_activation_hook(__FILE__, 'woo_invoice_to_repairshopr_activate');

/**
 * Plugin deactivation hook
 */
function woo_invoice_to_repairshopr_deactivate() {
    // Add any deactivation tasks here if needed
    error_log('woo_inv_to_rs: Plugin deactivated');
}
register_deactivation_hook(__FILE__, 'woo_invoice_to_repairshopr_deactivate');
