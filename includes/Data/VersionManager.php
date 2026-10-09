<?php

namespace MadeByHypeStockmanagment\Data;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Read-only list of the saves made before the change log existed.
 *
 * Those saves are whole snapshots without a user and without the values they
 * wrote. They are listed so the history does not start with a hole; they
 * cannot be reverted, and nothing writes to this table any more. Saves are
 * recorded by ChangeLog and undone through WriteService::undo().
 */
class VersionManager
{
    private $table_name;

    /**
     * The table is created and kept by Schema, not checked here.
     */
    public function __construct()
    {
        global $wpdb;
        $this->table_name = $wpdb->prefix . 'madebyhype_stock_versions';
    }

    /**
     * @param int|null $limit
     * @return array Newest first: ['id', 'version_number', 'created_at' (server time), 'changes_data' (decoded), 'description']
     */
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

    /**
     * @param array $version One entry of get_versions()
     * @return string Such as "2 product(s), 1 variation(s)"
     */
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
