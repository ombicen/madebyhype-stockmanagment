<?php

namespace MadeByHypeStockmanagment\Data;

if (! defined('ABSPATH')) {
    exit;
}

class DataManager
{
    /**
     * Cache expiration time in seconds (5 minutes)
     */
    private const CACHE_EXPIRATION = 5 * MINUTE_IN_SECONDS;

    /**
     * Cache key prefix
     */
    private const CACHE_PREFIX = 'mbh_stock_';

    public function init()
    {
        // Initialize data manager
    }

    /**
     * Clear all product cache transients
     */
    public function clear_cache()
    {
        global $wpdb;

        // Delete all transients with our prefix
        $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
                '_transient_' . self::CACHE_PREFIX . '%',
                '_transient_timeout_' . self::CACHE_PREFIX . '%'
            )
        );
    }

    /**
     * Generate cache key from arguments
     */
    private function get_cache_key($args)
    {
        return self::CACHE_PREFIX . md5(serialize($args));
    }

    /**
     * Get products with filtering, sorting, and pagination
     *
     * @param array $args {
     *     Optional. Array of arguments.
     *
     *     @type string|null $start_date        Start date for sales data (Y-m-d format). Default null (1 month ago).
     *     @type string|null $end_date          End date for sales data (Y-m-d format). Default null (today).
     *     @type string|null $sort_by           Sort field ('total_sales', 'stock_quantity'). Default null.
     *     @type string      $sort_order        Sort order ('ASC', 'DESC'). Default 'DESC'.
     *     @type int         $page              Page number for pagination. Default 1.
     *     @type int         $per_page          Items per page. Default 50.
     *     @type array       $category_filter   Array of category IDs to filter by. Default [].
     *     @type array       $tag_filter        Array of tag IDs to filter by. Default [].
     *     @type array       $stock_filter      Array of stock statuses to filter by. Default [].
     *     @type float       $min_price         Minimum price filter. Default 0.
     *     @type float       $max_price         Maximum price filter. Default 0.
     *     @type int         $min_sales         Minimum sales filter. Default 0.
     *     @type int         $max_sales         Maximum sales filter. Default 0.
     *     @type bool        $include_variations Include variations as top-level items. Default false.
     * }
     *
     * @return array {
     *     @type array $products      Array of product data.
     *     @type int   $total_count   Total number of products (before pagination).
     *     @type int   $total_pages   Total number of pages.
     *     @type int   $current_page  Current page number.
     *     @type int   $per_page      Items per page.
     * }
     *
     * @example
     * // Basic usage
     * $result = $data_manager->get_products();
     *
     * // With filters and sorting
     * $result = $data_manager->get_products([
     *     'start_date' => '2024-01-01',
     *     'end_date' => '2024-01-31',
     *     'sort_by' => 'total_sales',
     *     'sort_order' => 'DESC',
     *     'page' => 1,
     *     'per_page' => 20,
     *     'category_filter' => [1, 2, 3],
     *     'stock_filter' => ['instock'],
     *     'min_price' => 10.00,
     *     'include_variations' => true
     * ]);
     */
    public function get_products($args = [])
    {
        global $wpdb;

        // Parse arguments with defaults
        $defaults = [
            'start_date' => null,
            'end_date' => null,
            'sort_by' => null,
            'sort_order' => 'DESC',
            'page' => 1,
            'per_page' => 50,
            'category_filter' => [],
            'tag_filter' => [],
            'attribute_filter' => [],
            'stock_filter' => [],
            'min_price' => 0,
            'max_price' => 0,
            'min_sales' => 0,
            'max_sales' => 0,
            'include_variations' => false
        ];

        $args = wp_parse_args($args, $defaults);

        // Check cache first
        $cache_key = $this->get_cache_key($args);
        $cached_result = get_transient($cache_key);
        if ($cached_result !== false) {
            return $cached_result;
        }

        // Extract variables explicitly (avoiding extract() for security)
        $start_date = $args['start_date'];
        $end_date = $args['end_date'];
        $sort_by = $args['sort_by'];
        $sort_order = $args['sort_order'];
        $page = (int) $args['page'];
        $per_page = (int) $args['per_page'];
        $category_filter = $args['category_filter'];
        $tag_filter = $args['tag_filter'];
        $attribute_filter = $args['attribute_filter'];
        $stock_filter = $args['stock_filter'];
        $min_price = (float) $args['min_price'];
        $max_price = (float) $args['max_price'];
        $min_sales = (int) $args['min_sales'];
        $max_sales = (int) $args['max_sales'];
        $include_variations = (bool) $args['include_variations'];

        $products = [];

        if (!class_exists('WooCommerce')) {
            return $products;
        }

        // Set default 1-month interval if no dates provided
        if (empty($start_date) || empty($end_date)) {
            $end_date = date('Y-m-d'); // Today
            $start_date = date('Y-m-d', strtotime('-1 month')); // 1 month ago
        }

        $sort_column = '';
        $sort_direction = strtoupper($sort_order) === 'ASC' ? 'ASC' : 'DESC';

        if ($sort_by === 'total_sales') {
            $sort_column = 'total_sales';
        } elseif ($sort_by === 'stock_quantity') {
            $sort_column = 'stock_quantity';
        }

        $lookup_table = "{$wpdb->prefix}wc_order_product_lookup";
        $stats_table = "{$wpdb->prefix}wc_order_stats";
        $posts_table = "{$wpdb->prefix}posts";
        $postmeta_table = "{$wpdb->prefix}postmeta";

        /*
         * PERFORMANCE NOTE: This query benefits from the following indexes:
         * - wp_posts: PRIMARY (ID), type_status_date (post_type, post_status, post_date, ID)
         * - wp_postmeta: post_id, meta_key (composite index on post_id + meta_key is ideal)
         * - wp_wc_order_product_lookup: product_id, variation_id, order_id
         * - wp_wc_order_stats: order_id, status, date_created
         * - wp_term_relationships: object_id, term_taxonomy_id
         *
         * If queries are slow, consider adding:
         * ALTER TABLE wp_postmeta ADD INDEX idx_meta_lookup (post_id, meta_key(32));
         *
         * OPTIMIZATION: Using conditional aggregation (MAX/CASE) instead of multiple LEFT JOINs
         * on postmeta. This reduces the number of table scans from 7 to 1 for metadata retrieval.
         * Also removed DISTINCT as it's not needed with this query structure.
         */

        // SQL to fetch products with metadata + total sales
        // Using conditional aggregation to pivot postmeta rows into columns (single table scan)
        $sql = "
            SELECT
                p.ID as product_id,
                p.post_type as post_type,
                p.post_title as product_name,
                p.post_status as status,
                MAX(CASE WHEN pm.meta_key = '_sku' THEN pm.meta_value END) as sku,
                CAST(MAX(CASE WHEN pm.meta_key = '_stock' THEN pm.meta_value END) AS SIGNED) as stock_quantity,
                MAX(CASE WHEN pm.meta_key = '_stock_status' THEN pm.meta_value END) as stock_status,
                CAST(MAX(CASE WHEN pm.meta_key = '_regular_price' THEN pm.meta_value END) AS DECIMAL(10,2)) as regular_price,
                CAST(MAX(CASE WHEN pm.meta_key = '_sale_price' THEN pm.meta_value END) AS DECIMAL(10,2)) as sale_price,
                MAX(CASE WHEN pm.meta_key = '_product_type' THEN pm.meta_value END) as product_type,
                MAX(CASE WHEN pm.meta_key = '_price' THEN pm.meta_value END) as price_raw,
                COALESCE(sales.total_sales, 0) as total_sales
            FROM $posts_table p
            LEFT JOIN $postmeta_table pm ON p.ID = pm.post_id
                AND pm.meta_key IN ('_sku', '_stock', '_stock_status', '_regular_price', '_sale_price', '_product_type', '_price')
            LEFT JOIN (
                SELECT
                    sales_inner.id,
                    SUM(sales_inner.qty) as total_sales
                FROM (
                    SELECT
                        lookup.product_id as id,
                        lookup.product_qty as qty
                    FROM $lookup_table lookup
                    INNER JOIN $stats_table stats ON lookup.order_id = stats.order_id
                    WHERE stats.status IN ('wc-completed', 'wc-processing')
        ";

        $params = [];

        if (!empty($start_date) && !empty($end_date)) {
            $sql .= " AND stats.date_created BETWEEN %s AND %s";
            $params[] = date('Y-m-d H:i:s', strtotime($start_date));
            $params[] = date('Y-m-d H:i:s', strtotime($end_date));
        }

        // If include_variations is true, also get variation sales with UNION ALL
        if ($include_variations) {
            $sql .= "
                    UNION ALL
                    SELECT
                        lookup.variation_id as id,
                        lookup.product_qty as qty
                    FROM $lookup_table lookup
                    INNER JOIN $stats_table stats ON lookup.order_id = stats.order_id
                    WHERE stats.status IN ('wc-completed', 'wc-processing')
                    AND lookup.variation_id > 0
            ";

            if (!empty($start_date) && !empty($end_date)) {
                $sql .= " AND stats.date_created BETWEEN %s AND %s";
                $params[] = date('Y-m-d H:i:s', strtotime($start_date));
                $params[] = date('Y-m-d H:i:s', strtotime($end_date));
            }
        }

        $sql .= "
                ) sales_inner
                GROUP BY sales_inner.id
            ) sales ON p.ID = sales.id
            WHERE (p.post_type = 'product'" . ($include_variations ? " OR p.post_type = 'product_variation'" : "") . ")
              AND p.post_status = 'publish'
        ";

        // Apply filters using EXISTS (more efficient than IN for large datasets)
        if (!empty($category_filter)) {
            // Get all child category IDs for the selected categories
            $all_category_ids = $this->get_all_child_category_ids($category_filter);
            $category_placeholders = implode(',', array_fill(0, count($all_category_ids), '%d'));
            // EXISTS is generally faster than IN for correlated subqueries
            $sql .= " AND EXISTS (
                SELECT 1 FROM {$wpdb->prefix}term_relationships tr
                INNER JOIN {$wpdb->prefix}term_taxonomy tt ON tr.term_taxonomy_id = tt.term_taxonomy_id
                WHERE tr.object_id = p.ID AND tt.taxonomy = 'product_cat' AND tt.term_id IN ($category_placeholders)
            )";
            $params = [...$params, ...$all_category_ids];
        }

        if (!empty($tag_filter)) {
            $tag_placeholders = implode(',', array_fill(0, count($tag_filter), '%d'));
            $sql .= " AND EXISTS (
                SELECT 1 FROM {$wpdb->prefix}term_relationships tr
                INNER JOIN {$wpdb->prefix}term_taxonomy tt ON tr.term_taxonomy_id = tt.term_taxonomy_id
                WHERE tr.object_id = p.ID AND tt.taxonomy = 'product_tag' AND tt.term_id IN ($tag_placeholders)
            )";
            $params = [...$params, ...$tag_filter];
        }

        if (!empty($attribute_filter) && is_array($attribute_filter)) {
            // attribute_filter is expected to be an assoc array: taxonomy => [term_ids]
            foreach ($attribute_filter as $taxonomy => $term_ids) {
                if (empty($term_ids) || !is_array($term_ids)) continue;
                // taxonomy should be a string like 'pa_color'
                $placeholders = implode(',', array_fill(0, count($term_ids), '%d'));
                // Match either the product itself or products that have variations with the attribute term
                // Using EXISTS for better performance with large datasets
                $sql .= " AND (
                    EXISTS (
                        SELECT 1 FROM {$wpdb->prefix}term_relationships tr
                        INNER JOIN {$wpdb->prefix}term_taxonomy tt ON tr.term_taxonomy_id = tt.term_taxonomy_id
                        WHERE tr.object_id = p.ID AND tt.taxonomy = %s AND tt.term_id IN ($placeholders)
                    )
                    OR EXISTS (
                        SELECT 1 FROM {$wpdb->prefix}posts pv
                        INNER JOIN {$wpdb->prefix}term_relationships tr2 ON pv.ID = tr2.object_id
                        INNER JOIN {$wpdb->prefix}term_taxonomy tt2 ON tr2.term_taxonomy_id = tt2.term_taxonomy_id
                        WHERE pv.post_parent = p.ID AND pv.post_type = 'product_variation'
                        AND tt2.taxonomy = %s AND tt2.term_id IN ($placeholders)
                    )
                )";
                // add taxonomy and term ids twice (for product and variation checks)
                $params[] = $taxonomy;
                $params = [...$params, ...$term_ids];
                $params[] = $taxonomy;
                $params = [...$params, ...$term_ids];
            }
        }

        // GROUP BY is required since we're using aggregate functions (MAX)
        $sql .= " GROUP BY p.ID, p.post_type, p.post_title, p.post_status, sales.total_sales";

        // HAVING clause for filters that need aggregated values
        $having_conditions = [];
        $having_params = [];

        if (!empty($stock_filter)) {
            $stock_placeholders = implode(',', array_fill(0, count($stock_filter), '%s'));
            $having_conditions[] = "MAX(CASE WHEN pm.meta_key = '_stock_status' THEN pm.meta_value END) IN ($stock_placeholders)";
            $having_params = array_merge($having_params, $stock_filter);
        }

        if ($min_price > 0) {
            $having_conditions[] = "CAST(MAX(CASE WHEN pm.meta_key = '_price' THEN pm.meta_value END) AS DECIMAL(10,2)) >= %f";
            $having_params[] = $min_price;
        }

        if ($max_price > 0) {
            $having_conditions[] = "CAST(MAX(CASE WHEN pm.meta_key = '_price' THEN pm.meta_value END) AS DECIMAL(10,2)) <= %f";
            $having_params[] = $max_price;
        }

        if ($min_sales > 0) {
            $having_conditions[] = "COALESCE(sales.total_sales, 0) >= %d";
            $having_params[] = $min_sales;
        }

        if ($max_sales > 0) {
            $having_conditions[] = "COALESCE(sales.total_sales, 0) <= %d";
            $having_params[] = $max_sales;
        }

        if (!empty($having_conditions)) {
            $sql .= " HAVING " . implode(' AND ', $having_conditions);
            $params = array_merge($params, $having_params);
        }

        // Sorting
        if ($sort_column === 'total_sales') {
            $sql .= " ORDER BY total_sales $sort_direction, p.post_title ASC";
        } elseif ($sort_column === 'stock_quantity') {
            $sql .= " ORDER BY stock_quantity $sort_direction, p.post_title ASC";
        } else {
            $sql .= " ORDER BY p.post_title ASC";
        }

        // Use SQL_CALC_FOUND_ROWS to get total count in single query (avoids running query twice)
        // This is more efficient than a separate COUNT query for complex queries
        // Only replace the first SELECT (not the one in the subquery)
        $sql_with_calc = preg_replace('/^\s*SELECT\b/i', 'SELECT SQL_CALC_FOUND_ROWS', $sql, 1);

        // Add pagination with parameterized values to prevent SQL injection
        $offset = ($page - 1) * $per_page;
        $sql_with_calc .= " LIMIT %d OFFSET %d";
        $params[] = (int) $per_page;
        $params[] = (int) $offset;

        // Fetch all products with SQL_CALC_FOUND_ROWS
        $prepared_sql = $wpdb->prepare($sql_with_calc, ...$params);
        $results = $wpdb->get_results($prepared_sql);

        // Get total count from FOUND_ROWS() - much faster than separate COUNT query
        $total_count = (int) $wpdb->get_var("SELECT FOUND_ROWS()");

        // Check for database errors on main query
        if ($wpdb->last_error) {
            error_log('MadeByHype Stock Management - Products query error: ' . $wpdb->last_error);
            return [
                'products' => [],
                'total_count' => 0,
                'total_pages' => 0,
                'current_page' => $page,
                'per_page' => $per_page,
                'error' => 'Database error occurred'
            ];
        }

        if (empty($results)) {
            return ['products' => [], 'total_count' => $total_count, 'total_pages' => 0];
        }

        // Reset products array to ensure no duplicates
        $products = [];

        // Step 1: Batch load all products at once to avoid N+1 queries
        $product_ids = array_map(function ($row) {
            return $row->product_id;
        }, $results);

        // Pre-warm the WooCommerce product cache by loading all products in one query
        $product_cache = [];
        $loaded_products = \wc_get_products([
            'include' => $product_ids,
            'limit' => -1,
            'return' => 'objects'
        ]);
        foreach ($loaded_products as $prod) {
            $product_cache[$prod->get_id()] = $prod;
        }

        // Step 2: Collect all variation IDs from variable products
        $variation_ids_all = [];
        $variable_product_map = [];

        foreach ($results as $row) {
            $product = $product_cache[$row->product_id] ?? null;
            if ($product) {
                $actual_type = $product->get_type();

                if ($actual_type === 'variable') {
                    $children = $product->get_children();
                    if (!empty($children)) {
                        // Use spread operator instead of array_merge (more memory efficient in loops)
                        $variation_ids_all = [...$variation_ids_all, ...$children];
                        $variable_product_map[$row->product_id] = $children;
                    }
                }
            }
        }

        // Step 3: Batch load all variations at once
        $variation_cache = [];
        $variation_status_cache = [];
        if (!empty($variation_ids_all)) {
            $loaded_variations = \wc_get_products([
                'include' => $variation_ids_all,
                'type' => 'variation',
                'limit' => -1,
                'return' => 'objects'
            ]);
            foreach ($loaded_variations as $var) {
                $variation_cache[$var->get_id()] = $var;
            }

            // Batch fetch all post statuses in one query instead of individual get_post_status() calls
            $variation_status_cache = $this->get_bulk_post_statuses($variation_ids_all);
        }

        // Step 4: Get bulk sales data for all variations
        $variation_sales_lookup = [];
        if (!empty($variation_ids_all)) {
            $variation_sales_lookup = $this->get_bulk_sales_data($variation_ids_all, true, $start_date, $end_date);
        }

        // Step 5: Build final product list using cached objects
        foreach ($results as $row) {
            $product = $product_cache[$row->product_id] ?? null;
            $actual_type = $product ? $product->get_type() : 'simple';

            $product_data = [
                'id' => $row->product_id,
                'name' => $row->product_name,
                'sku' => $row->sku,
                'stock_quantity' => is_numeric($row->stock_quantity) ? (int)$row->stock_quantity : 0,
                'stock_status' => $row->stock_status,
                'regular_price' => $row->regular_price,
                'sale_price' => $row->sale_price,
                'type' => $actual_type,
                'status' => $row->status,
                'total_sales' =>  (int)$row->total_sales,
                'variations' => []
            ];

            // Add variations from cache
            if (isset($variable_product_map[$row->product_id]) && !$include_variations) {
                foreach ($variable_product_map[$row->product_id] as $variation_id) {
                    $variation = $variation_cache[$variation_id] ?? null;
                    if (!$variation) continue;

                    $product_data['variations'][] = [
                        'id' => $variation_id,
                        'name' => $variation->get_name(),
                        'sku' => $variation->get_sku(),
                        'stock_quantity' => $variation->get_stock_quantity(),
                        'stock_status' => $variation->get_stock_status(),
                        'regular_price' => $variation->get_regular_price(),
                        'sale_price' => $variation->get_sale_price(),
                        'type' => 'variation',
                        'status' => $variation_status_cache[$variation_id] ?? 'publish',
                        'total_sales' => $variation_sales_lookup[$variation_id] ?? 0,
                        'attributes' => $variation->get_attributes()
                    ];
                }
            }

            $products[] = $product_data;
        }



        $total_pages = ceil($total_count / $per_page);

        $result = [
            'products' => $products,
            'total_count' => $total_count,
            'total_pages' => $total_pages,
            'current_page' => $page,
            'per_page' => $per_page
        ];

        // Store result in cache
        set_transient($cache_key, $result, self::CACHE_EXPIRATION);

        return $result;
    }



    /**
     * Batch fetch post statuses for multiple post IDs in one query
     * Avoids N+1 queries when getting statuses for variations
     *
     * @param array $post_ids Array of post IDs
     * @return array Associative array of post_id => post_status
     */
    private function get_bulk_post_statuses($post_ids)
    {
        global $wpdb;

        if (empty($post_ids)) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($post_ids), '%d'));
        $sql = $wpdb->prepare(
            "SELECT ID, post_status FROM {$wpdb->posts} WHERE ID IN ($placeholders)",
            ...$post_ids
        );

        $results = $wpdb->get_results($sql);

        $statuses = [];
        foreach ($results as $row) {
            $statuses[(int) $row->ID] = $row->post_status;
        }

        return $statuses;
    }

    private function get_bulk_sales_data($product_ids, $is_variation = false, $start_date = null, $end_date = null)
    {
        global $wpdb;

        if (empty($product_ids)) return [];

        // Set default 1-month interval if no dates provided
        if (empty($start_date) || empty($end_date)) {
            $end_date = date('Y-m-d'); // Today
            $start_date = date('Y-m-d', strtotime('-1 month')); // 1 month ago
        }

        $column = $is_variation ? 'variation_id' : 'product_id';
        $lookup_table = "{$wpdb->prefix}wc_order_product_lookup";
        $stats_table  = "{$wpdb->prefix}wc_order_stats";

        $placeholders = implode(',', array_fill(0, count($product_ids), '%d'));
        $params = $product_ids;

        $sql = "
            SELECT lookup.$column as id, SUM(lookup.product_qty) as total_sales
            FROM $lookup_table AS lookup
            INNER JOIN $stats_table AS stats ON lookup.order_id = stats.order_id
            WHERE lookup.$column IN ($placeholders)
              AND stats.status IN ('wc-completed', 'wc-processing')
        ";

        // Always apply date filter since we now have default dates
        $sql .= " AND stats.date_created BETWEEN %s AND %s";
        $params[] = date('Y-m-d H:i:s', strtotime($start_date));
        $params[] = date('Y-m-d H:i:s', strtotime($end_date));

        $sql .= " GROUP BY lookup.$column";

        $prepared_sql = $wpdb->prepare($sql, ...$params);
        $results = $wpdb->get_results($prepared_sql, OBJECT_K);

        $sales = [];
        foreach ($results as $row) {
            $sales[intval($row->id)] = intval($row->total_sales);
        }

        return $sales;
    }

    /**
     * Get all child category IDs recursively for given parent category IDs
     * Uses caching to avoid repeated database queries
     *
     * @param array $parent_ids Array of parent category IDs
     * @return array Array of all category IDs including children
     */
    private function get_all_child_category_ids($parent_ids)
    {
        if (empty($parent_ids)) {
            return [];
        }

        // Sort parent_ids to ensure consistent cache key
        sort($parent_ids);
        $cache_key = self::CACHE_PREFIX . 'cats_' . md5(implode(',', $parent_ids));

        // Check cache first
        $cached = get_transient($cache_key);
        if ($cached !== false) {
            return $cached;
        }

        // Use a single optimized query to get all descendant categories
        $all_category_ids = $this->get_category_descendants_optimized($parent_ids);

        // Cache for 1 hour (categories change less frequently than products)
        set_transient($cache_key, $all_category_ids, HOUR_IN_SECONDS);

        return $all_category_ids;
    }

    /**
     * Get all descendant category IDs using an optimized approach
     * Fetches all product categories once and builds the tree in PHP
     *
     * @param array $parent_ids Array of parent category IDs
     * @return array Array of all category IDs including children
     */
    private function get_category_descendants_optimized($parent_ids)
    {
        // Get all product categories in one query
        $all_terms = get_terms([
            'taxonomy' => 'product_cat',
            'hide_empty' => false,
            'fields' => 'all'
        ]);

        if (empty($all_terms) || is_wp_error($all_terms)) {
            return $parent_ids;
        }

        // Build parent-child relationship map
        $children_map = [];
        foreach ($all_terms as $term) {
            if (!isset($children_map[$term->parent])) {
                $children_map[$term->parent] = [];
            }
            $children_map[$term->parent][] = $term->term_id;
        }

        // Collect all descendants iteratively (avoids deep recursion)
        $all_category_ids = $parent_ids;
        $to_process = $parent_ids;

        while (!empty($to_process)) {
            $current = array_shift($to_process);
            if (isset($children_map[$current])) {
                foreach ($children_map[$current] as $child_id) {
                    if (!in_array($child_id, $all_category_ids)) {
                        $all_category_ids[] = $child_id;
                        $to_process[] = $child_id;
                    }
                }
            }
        }

        return array_unique($all_category_ids);
    }
}