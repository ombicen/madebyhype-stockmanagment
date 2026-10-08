<?php

namespace MadeByHypeStockmanagment\Data;

if (!defined('ABSPATH')) {
    exit;
}

class VersionManager
{
    private $table_name;
    private $max_versions = 6;

    /**
     * Flag to track if table has been verified this request
     */
    private static $table_verified = false;

    public function __construct()
    {
        global $wpdb;
        $this->table_name = $wpdb->prefix . 'madebyhype_stock_versions';

        // Only check/create table once per request, not on every instantiation
        if (!self::$table_verified) {
            $this->maybe_create_table();
            self::$table_verified = true;
        }
    }

    /**
     * Create table only if it doesn't exist
     * Uses a lightweight check before running expensive dbDelta
     */
    private function maybe_create_table()
    {
        global $wpdb;

        // Quick check if table exists (much faster than running dbDelta every time)
        $table_exists = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(1) FROM information_schema.tables WHERE table_schema = %s AND table_name = %s",
                DB_NAME,
                $this->table_name
            )
        );

        if (!$table_exists) {
            $this->create_table();
        }
    }

    /**
     * Create the versions table using dbDelta
     * Only called when table doesn't exist
     */
    private function create_table()
    {
        global $wpdb;

        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE IF NOT EXISTS {$this->table_name} (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            version_number int(11) NOT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            changes_data longtext NOT NULL,
            description varchar(255) DEFAULT '',
            PRIMARY KEY (id),
            KEY version_number (version_number)
        ) $charset_collate;";

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
    }

    public function save_version($changes_data, $description = '')
    {
        global $wpdb;

        // Get current version number
        $current_version = $this->get_current_version_number();
        $new_version = $current_version + 1;

        // Insert new version
        $result = $wpdb->insert(
            $this->table_name,
            array(
                'version_number' => $new_version,
                'changes_data' => json_encode($changes_data),
                'description' => $description
            ),
            array('%d', '%s', '%s')
        );

        if ($result) {
            // Clean up old versions (keep only max_versions)
            $this->cleanup_old_versions();
            return $new_version;
        }

        return false;
    }

    public function get_versions($limit = null)
    {
        global $wpdb;

        // Use prepared statement for limit to prevent SQL injection
        if ($limit) {
            $sql = $wpdb->prepare(
                "SELECT * FROM {$this->table_name} ORDER BY version_number DESC LIMIT %d",
                (int) $limit
            );
        } else {
            $sql = "SELECT * FROM {$this->table_name} ORDER BY version_number DESC";
        }

        $results = $wpdb->get_results($sql, ARRAY_A);

        // Check for database errors
        if ($wpdb->last_error) {
            error_log('MadeByHype Stock Management - Get versions error: ' . $wpdb->last_error);
            return [];
        }

        if (empty($results)) {
            return [];
        }

        foreach ($results as &$version) {
            $version['changes_data'] = json_decode($version['changes_data'], true);
        }

        return $results;
    }

    public function get_version($version_number)
    {
        global $wpdb;

        $result = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$this->table_name} WHERE version_number = %d",
                $version_number
            ),
            ARRAY_A
        );

        if ($result) {
            $result['changes_data'] = json_decode($result['changes_data'], true);
        }

        return $result;
    }

    public function revert_to_version($version_number)
    {
        global $wpdb;

        // Get all versions after the target version (newest first)
        $later_versions = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$this->table_name} 
                 WHERE version_number > %d 
                 ORDER BY version_number DESC",
                $version_number
            ),
            ARRAY_A
        );

        // Decode changes data for later versions
        foreach ($later_versions as &$version) {
            $version['changes_data'] = json_decode($version['changes_data'], true);
        }

        // Apply all later versions in reverse order (newest first) to undo their changes
        foreach ($later_versions as $version) {
            $this->apply_version_changes($version['changes_data'], true); // true = revert mode
        }

        // Now apply the target version to restore the desired state
        $target_version = $this->get_version($version_number);
        if (!$target_version) {
            return false;
        }

        $revert_success = $this->apply_version_changes($target_version['changes_data'], false); // false = normal mode

        if ($revert_success) {
            \wc_delete_product_transients();
        }

        return $revert_success;
    }

    private function apply_version_changes($changes_data, $is_revert = false)
    {
        $success = true;
        $errors = [];

        // Batch load all products at once to avoid N+1 queries
        $product_ids = isset($changes_data['products']) ? array_keys($changes_data['products']) : [];
        $variation_ids = isset($changes_data['variations']) ? array_keys($changes_data['variations']) : [];

        // Pre-load products in batch
        $product_cache = [];
        if (!empty($product_ids)) {
            $loaded_products = \wc_get_products([
                'include' => array_map('intval', $product_ids),
                'limit' => -1,
                'return' => 'objects'
            ]);
            foreach ($loaded_products as $prod) {
                $product_cache[$prod->get_id()] = $prod;
            }
        }

        // Pre-load variations in batch
        $variation_cache = [];
        if (!empty($variation_ids)) {
            $loaded_variations = \wc_get_products([
                'include' => array_map('intval', $variation_ids),
                'type' => 'variation',
                'limit' => -1,
                'return' => 'objects'
            ]);
            foreach ($loaded_variations as $var) {
                $variation_cache[$var->get_id()] = $var;
            }
        }

        // Apply product changes using cached products
        if (isset($changes_data['products'])) {
            foreach ($changes_data['products'] as $product_id => $changes) {
                $product = $product_cache[(int) $product_id] ?? null;
                if ($product) {
                    $this->apply_field_changes($product, $changes, $is_revert);
                    $save_result = $product->save();
                    if (!$save_result) {
                        $success = false;
                        $errors[] = "Failed to save product $product_id";
                    }
                } else {
                    $errors[] = "Product $product_id not found";
                }
            }
        }

        // Apply variation changes using cached variations
        if (isset($changes_data['variations'])) {
            foreach ($changes_data['variations'] as $variation_id => $changes) {
                $variation = $variation_cache[(int) $variation_id] ?? null;
                if ($variation && $variation->is_type('variation')) {
                    $this->apply_field_changes($variation, $changes, $is_revert);
                    $save_result = $variation->save();
                    if (!$save_result) {
                        $success = false;
                        $errors[] = "Failed to save variation $variation_id";
                    }
                } else {
                    $errors[] = "Variation $variation_id not found";
                }
            }
        }

        // Log any errors that occurred
        if (!empty($errors)) {
            error_log('MadeByHype Stock Management - Version revert errors: ' . implode(', ', $errors));
        }

        return $success;
    }

    /**
     * Apply field changes to a product or variation
     * Extracted to reduce code duplication (DRY)
     *
     * @param \WC_Product $item Product or variation object
     * @param array $changes Array of field => value pairs
     * @param bool $is_revert Whether this is a revert operation
     */
    private function apply_field_changes($item, $changes, $is_revert)
    {
        foreach ($changes as $field => $value) {
            $value_to_apply = $is_revert ? $this->get_opposite_value($field, $value) : $value;

            switch ($field) {
                case 'stock_quantity':
                    $item->set_manage_stock(true);
                    $item->set_stock_quantity($value_to_apply);
                    break;
                case 'stock_status':
                    if ($value_to_apply === 'onbackorder') {
                        $item->set_manage_stock(true);
                        $item->set_backorders('yes');
                    } elseif ($value_to_apply === 'outofstock') {
                        $item->set_manage_stock(true);
                        $item->set_stock_quantity(0);
                        $item->set_backorders('no');
                    }
                    break;
                case 'backorders':
                    $item->set_backorders($value_to_apply);
                    break;
                case 'manage_stock':
                    $item->set_manage_stock($value_to_apply);
                    break;
                case 'price':
                    $item->set_price($value_to_apply);
                    break;
                case 'regular_price':
                    $item->set_regular_price($value_to_apply);
                    break;
                case 'sale_price':
                    if (method_exists($item, 'set_sale_price')) {
                        $item->set_sale_price($value_to_apply);
                    }
                    break;
            }
        }
    }

    private function get_opposite_value($field, $value)
    {
        // This method should return the opposite value that was stored
        // Since we store the "previous" value in our versioning system,
        // the opposite would be the "current" value that was changed to
        // For now, we'll return the same value since our versioning stores
        // the previous state, not the new state
        return $value;
    }

    private function get_current_version_number()
    {
        global $wpdb;

        $result = $wpdb->get_var("SELECT MAX(version_number) FROM {$this->table_name}");
        return $result ? intval($result) : 0;
    }

    private function cleanup_old_versions()
    {
        global $wpdb;

        // Count total versions first - only cleanup if we exceed max
        $total_versions = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$this->table_name}");

        if ($total_versions <= $this->max_versions) {
            return; // Nothing to clean up
        }

        // More efficient approach: find the minimum version_number to keep, then delete older
        $min_version_to_keep = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT version_number FROM {$this->table_name}
                 ORDER BY version_number DESC
                 LIMIT 1 OFFSET %d",
                $this->max_versions - 1
            )
        );

        if ($min_version_to_keep) {
            $result = $wpdb->query(
                $wpdb->prepare(
                    "DELETE FROM {$this->table_name} WHERE version_number < %d",
                    (int) $min_version_to_keep
                )
            );

            // Log any errors
            if ($wpdb->last_error) {
                error_log('MadeByHype Stock Management - Cleanup versions error: ' . $wpdb->last_error);
            }
        }
    }

    public function get_version_summary($version)
    {
        $summary = array();

        if (isset($version['changes_data']['products'])) {
            $product_count = count($version['changes_data']['products']);
            $summary[] = "$product_count product(s)";
        }

        if (isset($version['changes_data']['variations'])) {
            $variation_count = count($version['changes_data']['variations']);
            $summary[] = "$variation_count variation(s)";
        }

        return implode(', ', $summary);
    }
}
