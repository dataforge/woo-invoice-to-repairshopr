# Woo Invoice to RepairShopr

**Woo Invoice to RepairShopr** is a WordPress plugin that automatically sends paid WooCommerce order (invoice) details to your RepairShopr account. When a WooCommerce order is marked as paid, the plugin will:

- Check if the customer exists in RepairShopr (by email) and create them if needed.
- Create a corresponding invoice in RepairShopr, including line items and fees from the WooCommerce order.
- Sync the invoice as paid in RepairShopr, ensuring your sales and customer records are up to date across both platforms.
- Provide a manual "Send to RepairShopr" button in the WooCommerce admin orders list for on-demand syncing.

This plugin is ideal for businesses that use WooCommerce for online sales and RepairShopr for service management, allowing seamless integration and reducing manual data entry.

## Why use this plugin?

- **Save time:** No more manually copying order details from WooCommerce to RepairShopr.
- **Reduce errors:** Customer and invoice data is transferred automatically and accurately.
- **Keep systems in sync:** Ensure your sales, customer, and invoice records are always up to date in both WooCommerce and RepairShopr.
- **Secure API key storage:** Your RepairShopr API key is encrypted in the WordPress database (see below).

---
## API Key Migration and Backward Compatibility

**What if I already had an API key saved before this plugin added encryption?**

If you previously saved your RepairShopr API key before the encryption feature was introduced, the plugin will continue to work without interruption. The plugin will detect and use your existing (plaintext) API key as usual. However, your key will not be automatically re-encrypted until you update it via the plugin's settings page. For best security, it is recommended to re-save your API key in the settings after updating the plugin, so it will be stored encrypted.

---
## API Key Security and Encryption

This plugin encrypts your RepairShopr API key before storing it in the WordPress database to help protect it from casual snooping or direct database access.

### How Encryption Works

- The API key is encrypted using the AES-256-CBC algorithm via PHP's `openssl_encrypt` and decrypted with `openssl_decrypt`.
- By default, the plugin uses the WordPress `AUTH_KEY` constant (defined in your `wp-config.php`) as the encryption secret.
- If you define a custom secret constant `REPAIRSHOPR_SYNC_SECRET` in your `wp-config.php`, it will be used instead of `AUTH_KEY`.

**Example (optional, only if you want to override AUTH_KEY):**
```php
define('REPAIRSHOPR_SYNC_SECRET', 'your-strong-random-string');
```

### How It Works in Practice

- When you save your API key in the plugin settings, it is encrypted before being stored in the database.
- When the plugin needs to use the API key (for API requests or to display the masked value in the settings), it is decrypted in memory using the secret.
- The settings UI only ever shows the last 4 characters of the stored key for security.

### Security Considerations

- Using `AUTH_KEY` as the encryption secret means the key is unique per site and not stored in the database.
- If an attacker has access to both your files (including `wp-config.php`) and your database, they can decrypt the key. This is a limitation of all in-app encryption.
- This approach prevents casual snooping in the database and is a practical improvement over plaintext storage, but is not a substitute for full server security.
- For maximum security, consider using environment variables or external secrets management if your infrastructure supports it.

### References

- [WordPress Security Keys Documentation](https://wordpress.org/support/article/editing-wp-config-php/#security-keys)
- [PHP openssl_encrypt Manual](https://www.php.net/manual/en/function.openssl-encrypt.php)
- [Discussion: Storing Confidential Data in WordPress](https://felix-arntz.me/blog/storing-confidential-data-in-wordpress/)

---

## Example: Securely Storing and Retrieving an API Key in WordPress Plugins

You can use the following code pattern in your own plugins to securely store and retrieve API keys (or other secrets) in the WordPress database, using AES-256-CBC encryption and the site's `AUTH_KEY` (or a custom secret if you prefer).

```php
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
        $encrypted = openssl_encrypt($api_key, 'AES-256-CBC', $secret, 0, substr(hash('sha256', $secret), 0, 16));
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
        $decrypted = openssl_decrypt($encrypted, 'AES-256-CBC', $secret, 0, substr(hash('sha256', $secret), 0, 16));
        return $decrypted !== false ? $decrypted : false;
    }
    return $encrypted; // fallback: plaintext (not recommended)
}

/**
 * Example usage:
 */
// Save a new API key
save_encrypted_api_key('my_plugin_api_key', 'your-api-key-here');

// Retrieve the API key for use
$api_key = get_encrypted_api_key('my_plugin_api_key');
if ($api_key) {
    // Use $api_key as needed
}
```

**Notes:**
- By default, this uses the WordPress `AUTH_KEY` as the encryption secret. You can define your own secret in `wp-config.php` if you want to isolate secrets between plugins:
  ```php
  define('REPAIRSHOPR_SYNC_SECRET', 'your-strong-random-string');
  ```
- This approach is suitable for most plugin-level secrets, but if you need maximum security, consider using environment variables or an external secrets manager.
- Always mask the API key in your plugin's UI (e.g., show only the last 4 characters).

Feel free to copy and adapt this code for your other RepairShopr-related plugins or any other WordPress plugin that needs to securely store secrets.
Synchronize your WooCommerce store's product data with RepairShopr, keeping quantities and retail prices in sync automatically.
