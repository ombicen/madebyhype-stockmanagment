<?php

namespace OmerStockhmanagment\Data;

if (!defined('ABSPATH')) {
    exit;
}

class VersionManager
{
    private $table_name;
    private $max_versions = 6;

    public function __construct()
    {
        global $wpdb;
        $this->table_name = $wpdb->prefix . 'omer_stock_versions';
        $this->create_table();
    }

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

        $limit_clause = $limit ? "LIMIT $limit" : '';

        $results = $wpdb->get_results(
            "SELECT * FROM {$this->table_name} ORDER BY version_number DESC $limit_clause",
            ARRAY_A
        );

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

        // Apply product changes
        if (isset($changes_data['products'])) {
            foreach ($changes_data['products'] as $product_id => $changes) {
                $product = \wc_get_product($product_id);
                if ($product) {
                    foreach ($changes as $field => $value) {
                        // In revert mode, we need to apply the opposite of what was saved
                        $value_to_apply = $is_revert ? $this->get_opposite_value($field, $value) : $value;

                        switch ($field) {
                            case 'stock_quantity':
                                $product->set_manage_stock(true);
                                $product->set_stock_quantity($value_to_apply);
                                break;
                            case 'stock_status':
                                // Restore stock status using WooCommerce's logic
                                if ($value_to_apply === 'onbackorder') {
                                    $product->set_manage_stock(true);
                                    $product->set_backorders('yes');
                                } elseif ($value_to_apply === 'outofstock') {
                                    $product->set_manage_stock(true);
                                    $product->set_stock_quantity(0);
                                    $product->set_backorders('no');
                                }
                                break;
                            case 'backorders':
                                $product->set_backorders($value_to_apply);
                                break;
                            case 'manage_stock':
                                $product->set_manage_stock($value_to_apply);
                                break;
                            case 'price':
                                $product->set_price($value_to_apply);
                                break;
                            case 'regular_price':
                                $product->set_regular_price($value_to_apply);
                                break;
                            case 'sale_price':
                                $product->set_sale_price($value_to_apply);
                                break;
                        }
                    }
                    $save_result = $product->save();
                    if (!$save_result) {
                        $success = false;
                    }
                }
            }
        }

        // Apply variation changes
        if (isset($changes_data['variations'])) {
            foreach ($changes_data['variations'] as $variation_id => $changes) {
                $variation = \wc_get_product($variation_id);
                if ($variation && $variation->is_type('variation')) {
                    foreach ($changes as $field => $value) {
                        // In revert mode, we need to apply the opposite of what was saved
                        $value_to_apply = $is_revert ? $this->get_opposite_value($field, $value) : $value;

                        switch ($field) {
                            case 'stock_quantity':
                                $variation->set_manage_stock(true);
                                $variation->set_stock_quantity($value_to_apply);
                                break;
                            case 'stock_status':
                                // Restore stock status using WooCommerce's logic
                                if ($value_to_apply === 'onbackorder') {
                                    $variation->set_manage_stock(true);
                                    $variation->set_backorders('yes');
                                } elseif ($value_to_apply === 'outofstock') {
                                    $variation->set_manage_stock(true);
                                    $variation->set_stock_quantity(0);
                                    $variation->set_backorders('no');
                                }
                                break;
                            case 'backorders':
                                $variation->set_backorders($value_to_apply);
                                break;
                            case 'manage_stock':
                                $variation->set_manage_stock($value_to_apply);
                                break;
                            case 'regular_price':
                                $variation->set_regular_price($value_to_apply);
                                break;
                        }
                    }
                    $save_result = $variation->save();
                    if (!$save_result) {
                        $success = false;
                    }
                }
            }
        }

        return $success;
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

        $wpdb->query(
            "DELETE FROM {$this->table_name} 
             WHERE id NOT IN (
                 SELECT id FROM (
                     SELECT id FROM {$this->table_name} 
                     ORDER BY version_number DESC 
                     LIMIT {$this->max_versions}
                 ) as temp
             )"
        );
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