<?php
/**
 * WooCommerce Integration for Woo Invoice to RepairShopr
 * 
 * Handles WooCommerce hooks, columns, and script enqueuing
 */

if (!defined('ABSPATH')) {
    exit;
}

class WIR_WooCommerce_Integration {
    
    /**
     * Initialize WooCommerce integration
     */
    public static function init() {
        // Hook into WooCommerce order payment completed for invoice sync
        add_action('woocommerce_payment_complete', array(__CLASS__, 'handle_invoice_sync'));
        
        // Hook into WooCommerce order payment completed for payment sync  
        add_action('woocommerce_payment_complete', array(__CLASS__, 'handle_payment_sync'));
        
        // Add RepairShopr column to WooCommerce Orders table (legacy and HPOS)
        add_filter('manage_edit-shop_order_columns', array(__CLASS__, 'add_order_repairshopr_column'));
        add_action('manage_shop_order_posts_custom_column', array(__CLASS__, 'order_repairshopr_column_content'), 20, 2);
        
        // HPOS orders table column
        add_filter('woocommerce_shop_order_list_table_columns', array(__CLASS__, 'hpos_add_order_repairshopr_column'), 20);
        add_action('woocommerce_shop_order_list_table_custom_column', array(__CLASS__, 'hpos_order_repairshopr_column_content'), 20, 2);
        
        // Enqueue scripts
        add_action('admin_enqueue_scripts', array(__CLASS__, 'enqueue_admin_scripts'));
    }

    /**
     * Handle WooCommerce payment complete hook
     * 
     * @param int $order_id Order ID
     */
    public static function handle_payment_complete($order_id) {
        // Handle invoice sync
        self::handle_invoice_sync($order_id);
        
        // Handle payment sync
        WIR_Payment_Handler::auto_send_payment_to_repairshopr($order_id);
    }

    /**
     * Handle automatic invoice sync
     * 
     * @param int $order_id Order ID
     */
    public static function handle_invoice_sync($order_id) {
        // Check if auto-sync invoice is enabled
        if (get_option('woo_inv_to_rs_auto_sync_invoice', '') !== '1') {
            error_log("woo_inv_to_rs: Auto-sync invoice is disabled. Skipping invoice sync for order $order_id.");
            return;
        }

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
        $existing_invoice = WIR_Invoice_Handler::check_invoice_exists($invoice_number);
        
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
        $invoice_created = WIR_Invoice_Handler::create_invoice($order, $customer_id);
        if (!$invoice_created) {
            error_log('woo_inv_to_rs: Failed to create invoice in RepairShopr');
            return false;
        }

        error_log("woo_inv_to_rs: Invoice successfully created in RepairShopr for order $order_id");
        return true;
    }

    /**
     * Handle automatic payment sync
     * 
     * @param int $order_id Order ID
     */
    public static function handle_payment_sync($order_id) {
        // Check if auto-sync payment is enabled
        if (get_option('woo_inv_to_rs_auto_sync_payment', '') !== '1') {
            error_log("woo_inv_to_rs: Auto-sync payment is disabled. Skipping payment sync for order $order_id.");
            return;
        }

        error_log("woo_inv_to_rs: Auto-sync payment is enabled. Starting payment sync for order $order_id.");
        
        // Call the payment handler
        $result = WIR_Payment_Handler::send_payment_to_repairshopr($order_id);
        
        if ($result['success']) {
            error_log("woo_inv_to_rs: Auto payment sync successful for order $order_id: " . $result['message']);
        } else {
            error_log("woo_inv_to_rs: Auto payment sync failed for order $order_id: " . $result['message']);
        }
    }

    /**
     * Add RepairShopr column to legacy orders table
     * 
     * @param array $columns Existing columns
     * @return array Modified columns
     */
    public static function add_order_repairshopr_column($columns) {
        $new_columns = array();
        foreach ($columns as $key => $column) {
            $new_columns[$key] = $column;
            if ($key === 'order_number') {
                $new_columns['repairshopr'] = __('RepairShopr', 'woocommerce-invoice-to-repairshopr');
            }
        }
        return $new_columns;
    }

    /**
     * Legacy orders table content
     * 
     * @param string $column Column name
     * @param int $post_id Post ID
     */
    public static function order_repairshopr_column_content($column, $post_id) {
        error_log('woo_inv_to_rs: custom column content called. $column=' . $column . ', $post_id=' . $post_id);
        if ($column == 'repairshopr') {
            error_log('woo_inv_to_rs: rendering Send Invoice and Send Payment buttons for order ' . $post_id);
            echo '<button type="button" class="button woo_inv_to_rs-send-to-repairshopr" data-order-id="' . esc_attr($post_id) . '">Send Invoice</button> ';
            echo '<button type="button" class="button woo_inv_to_rs-send-payment" data-order-id="' . esc_attr($post_id) . '">Send Payment</button> ';
            echo '<button type="button" class="button woo_inv_to_rs-verify-invoice" data-order-id="' . esc_attr($post_id) . '">RS Verify Invoice</button> ';
            echo '<button type="button" class="button woo_inv_to_rs-verify-payment" data-order-id="' . esc_attr($post_id) . '">RS Verify Payment</button>';
        }
    }

    /**
     * HPOS orders table column
     * 
     * @param array $columns Existing columns
     * @return array Modified columns
     */
    public static function hpos_add_order_repairshopr_column($columns) {
        $new_columns = array();
        foreach ($columns as $key => $column) {
            $new_columns[$key] = $column;
            if ($key === 'order_number') {
                $new_columns['repairshopr'] = __('RepairShopr', 'woocommerce-invoice-to-repairshopr');
            }
        }
        return $new_columns;
    }

    /**
     * HPOS orders table content
     * 
     * @param string $column Column name
     * @param WC_Order|int $order Order object or ID
     */
    public static function hpos_order_repairshopr_column_content($column, $order) {
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
     * 
     * @param string $hook Current admin page hook
     */
    public static function enqueue_admin_scripts($hook) {
        error_log('woo_inv_to_rs: admin_enqueue_scripts called. $hook=' . $hook . ', $_GET[page]=' . (isset($_GET['page']) ? $_GET['page'] : 'NOT SET'));
        
        // Legacy orders page (edit.php?post_type=shop_order) or legacy table on wc-orders page
        if (
            ('edit.php' === $hook && isset($_GET['post_type']) && $_GET['post_type'] === 'shop_order') ||
            ($hook === 'woocommerce_page_wc-orders')
        ) {
            error_log('woo_inv_to_rs: Attempting to enqueue admin script (legacy orders page or legacy table on wc-orders)');
            wp_enqueue_script('wir-admin-script', plugin_dir_url(dirname(__FILE__)) . 'assets/js/admin-script.js', array('jquery'), time(), true);
            wp_enqueue_style('wir-admin-styles', plugin_dir_url(dirname(__FILE__)) . 'assets/css/admin-styles.css', array(), time());
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
                plugin_dir_url(dirname(__FILE__)) . 'assets/js/admin-wc-orders.js',
                array(), // No dependencies, uses global wp/wc
                time(), // Use time() for cache busting
                true
            );
            wp_enqueue_style('wir-admin-styles', plugin_dir_url(dirname(__FILE__)) . 'assets/css/admin-styles.css', array(), time());
            // Localize nonce for AJAX
            wp_localize_script('wir-admin-wc-orders', 'woo_inv_to_rs_ajax', array(
                'ajax_url' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('woo_inv_to_rs_nonce')
            ));
            error_log('woo_inv_to_rs: Admin script enqueued (wc-orders)');
        }

        // Settings page
        if ($hook === 'woocommerce_page_woo-invoice-to-repairshopr') {
            wp_enqueue_style('wir-admin-styles', plugin_dir_url(dirname(__FILE__)) . 'assets/css/admin-styles.css', array(), time());
        }
    }
}
