<?php
/**
 * Encryption utilities for Woo Invoice to RepairShopr
 * 
 * Handles secure storage and retrieval of API keys
 */

if (!defined('ABSPATH')) {
    exit;
}

class WIR_Encryption {
    
    /**
     * Securely store an API key in the WordPress options table.
     * Uses AES-256-CBC encryption with AUTH_KEY or a custom secret.
     *
     * @param string $option_name The option key to store the encrypted value under.
     * @param string $api_key The plaintext API key to store.
     */
    public static function save_encrypted_api_key($option_name, $api_key) {
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
    public static function get_encrypted_api_key($option_name) {
        $secret = defined('REPAIRSHOPR_SYNC_SECRET') ? REPAIRSHOPR_SYNC_SECRET : (defined('AUTH_KEY') ? AUTH_KEY : '');
        $encrypted = get_option($option_name);
        if (!empty($secret) && !empty($encrypted)) {
            $iv = substr(hash('sha256', $secret), 0, 16);
            $decrypted = openssl_decrypt($encrypted, 'AES-256-CBC', $secret, 0, $iv);
            return $decrypted !== false ? $decrypted : false;
        }
        return $encrypted; // fallback: plaintext (not recommended)
    }

    /**
     * Get the API key for this plugin
     * 
     * @return string The decrypted API key
     */
    public static function get_api_key() {
        $api_key = self::get_encrypted_api_key('woo_inv_to_rs_api_key');
        return $api_key ? $api_key : '';
    }

    /**
     * Set the API key for this plugin
     * 
     * @param string $api_key The API key to store
     */
    public static function set_api_key($api_key) {
        self::save_encrypted_api_key('woo_inv_to_rs_api_key', $api_key);
    }
}
