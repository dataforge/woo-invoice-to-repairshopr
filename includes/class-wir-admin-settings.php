<?php
/**
 * Admin Settings for Woo Invoice to RepairShopr
 * 
 * Handles the admin settings page and configuration
 */

if (!defined('ABSPATH')) {
    exit;
}

class WIR_Admin_Settings {
    
    /**
     * Initialize admin settings
     */
    public static function init() {
        add_action('admin_menu', array(__CLASS__, 'add_admin_menu'));
    }

    /**
     * Add admin menu
     */
    public static function add_admin_menu() {
        $plugin_name = 'Woo Invoice to RepairShopr'; // Keep in sync with Plugin Name in header
        add_submenu_page(
            'woocommerce',
            $plugin_name,
            $plugin_name,
            'manage_options',
            'woo-invoice-to-repairshopr',
            array(__CLASS__, 'settings_page')
        );
    }

    /**
     * Render settings page
     */
    public static function settings_page() {
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
            self::render_main_tab();
        } elseif ($tab === 'settings') {
            self::render_settings_tab();
        }
        
        echo '</div>';
    }

    /**
     * Render main tab
     */
    private static function render_main_tab() {
        echo '<div style="margin-top:2em;">';
        echo '<h3>Woo Invoice to RepairShopr</h3>';
        echo '<p>This plugin sends invoice details to RepairShopr when an invoice is paid in WooCommerce.</p>';
        echo '<p>More features and status information will appear here in the future.</p>';
        echo '</div>';
    }

    /**
     * Render settings tab
     */
    private static function render_settings_tab() {
        // Get current API key (masked)
        $api_key = WIR_Encryption::get_api_key();
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
            self::save_settings($masked_key);
            
            // Refresh values after save
            $api_key = WIR_Encryption::get_api_key();
            if (!empty($api_key) && strlen($api_key) > 4) {
                $masked_key = str_repeat('*', max(0, strlen($api_key) - 4)) . substr($api_key, -4);
            } elseif (!empty($api_key)) {
                $masked_key = str_repeat('*', strlen($api_key));
            }
            
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
            self::check_for_updates();
        }

        self::render_settings_form($masked_key, $api_url, $customer_url, $invoice_url, $tax_rate_id, $epf_product_id, $epf_name, $rounding_correction_product_id, $notes, $invoice_note, $get_sms, $opt_out, $no_email, $get_billing, $get_marketing, $get_reports, $taxable, $verified_paid, $tech_marked_paid, $is_paid);
    }

    /**
     * Save settings
     * 
     * @param string $current_masked_key Current masked API key
     */
    private static function save_settings($current_masked_key) {
        // API Key
        if (isset($_POST['woo_inv_to_rs_api_key'])) {
            $submitted_key = sanitize_text_field($_POST['woo_inv_to_rs_api_key']);
            if ($submitted_key !== $current_masked_key && $submitted_key !== '') {
                WIR_Encryption::set_api_key($submitted_key);
                echo '<div class="updated"><p>API Key updated.</p></div>';
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
        
        // Checkboxes
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
        
        // Save automatic sync settings
        update_option('woo_inv_to_rs_auto_sync_invoice', isset($_POST['woo_inv_to_rs_auto_sync_invoice']) ? '1' : '');
        update_option('woo_inv_to_rs_auto_sync_payment', isset($_POST['woo_inv_to_rs_auto_sync_payment']) ? '1' : '');
        
        echo '<div class="updated"><p>Settings updated.</p></div>';
    }

    /**
     * Check for plugin updates
     */
    private static function check_for_updates() {
        do_action('wp_update_plugins');
        if (function_exists('wp_clean_plugins_cache')) {
            wp_clean_plugins_cache(true);
        }
        delete_site_transient('update_plugins');
        if (function_exists('wp_update_plugins')) {
            wp_update_plugins();
        }
        $plugin_file = plugin_basename(dirname(dirname(__FILE__)) . '/woo-invoice-to-repairshopr.php');
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

    /**
     * Render settings form
     */
    private static function render_settings_form($masked_key, $api_url, $customer_url, $invoice_url, $tax_rate_id, $epf_product_id, $epf_name, $rounding_correction_product_id, $notes, $invoice_note, $get_sms, $opt_out, $no_email, $get_billing, $get_marketing, $get_reports, $taxable, $verified_paid, $tech_marked_paid, $is_paid) {
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
                </table>

                <h2 style="margin-top:2em;">Automatic Sync Settings</h2>
                <table class="form-table">
                    <tr>
                        <th>Automatic Sync Options</th>
                        <td>
                            <label>
                                <input type="checkbox" name="woo_inv_to_rs_auto_sync_invoice" <?php checked(get_option('woo_inv_to_rs_auto_sync_invoice', ''), '1'); ?>>
                                Automatically sync invoice upon order creation
                            </label><br>
                            <label>
                                <input type="checkbox" name="woo_inv_to_rs_auto_sync_payment" <?php checked(get_option('woo_inv_to_rs_auto_sync_payment', ''), '1'); ?>>
                                Automatically sync payment upon order payment
                            </label><br>
                            <p class="description">Note: Manual sync buttons will always work regardless of these settings.</p>
                        </td>
                    </tr>
                </table>

                <table class="form-table">
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
                            <?php self::render_payment_mapping(); ?>
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

    /**
     * Render payment method mapping
     */
    private static function render_payment_mapping() {
        // Fetch WooCommerce payment gateways
        if (class_exists('WC_Payment_Gateways')) {
            $gateways = WC_Payment_Gateways::instance()->get_available_payment_gateways();
        } else {
            $gateways = array();
        }

        // Fetch RepairShopr payment methods via API using complex URL building like original
        $repairshopr_methods = array();
        $api_key = WIR_Encryption::get_api_key();
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
    }
}
