<?php

namespace OmerStockhmanagment\Data;

if (! defined('ABSPATH')) {
    exit;
}

class DataManager
{
    public function init()
    {
        // Initialize data manager
    }

    public function get_products($start_date = null, $end_date = null, $sort_by = null, $sort_order = 'DESC', $page = 1, $per_page = 50, $category_filter = [], $tag_filter = [], $stock_filter = [], $min_price = 0, $max_price = 0, $min_sales = 0, $max_sales = 0)
    {
        global $wpdb;

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

        // SQL för att hämta produkter med metadata + total försäljning
        $sql = "
            SELECT 
                p.ID as product_id,
                p.post_title as product_name,
                p.post_status as status,
                pm_sku.meta_value as sku,
                CAST(pm_stock.meta_value AS SIGNED) as stock_quantity,
                pm_stock_status.meta_value as stock_status,
               
                CAST(pm_regular_price.meta_value AS DECIMAL(10,2)) as regular_price,
                CAST(pm_sale_price.meta_value AS DECIMAL(10,2)) as sale_price,
                pm_type.meta_value as product_type,
                COALESCE(sales.total_sales, 0) as total_sales
            FROM $posts_table p
            LEFT JOIN $postmeta_table pm_sku ON p.ID = pm_sku.post_id AND pm_sku.meta_key = '_sku'
            LEFT JOIN $postmeta_table pm_stock ON p.ID = pm_stock.post_id AND pm_stock.meta_key = '_stock'
            LEFT JOIN $postmeta_table pm_stock_status ON p.ID = pm_stock_status.post_id AND pm_stock_status.meta_key = '_stock_status'
            
            LEFT JOIN $postmeta_table pm_regular_price ON p.ID = pm_regular_price.post_id AND pm_regular_price.meta_key = '_regular_price'
            LEFT JOIN $postmeta_table pm_sale_price ON p.ID = pm_sale_price.post_id AND pm_sale_price.meta_key = '_sale_price'
            LEFT JOIN $postmeta_table pm_type ON p.ID = pm_type.post_id AND pm_type.meta_key = '_product_type'
            LEFT JOIN (
                SELECT 
                    lookup.product_id,
                    SUM(lookup.product_qty) as total_sales
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

        $sql .= "
                GROUP BY lookup.product_id
            ) sales ON p.ID = sales.product_id
            WHERE p.post_type = 'product' 
              AND p.post_status = 'publish'
        ";

        // Apply filters
        if (!empty($category_filter)) {
            $category_placeholders = implode(',', array_fill(0, count($category_filter), '%d'));
            $sql .= " AND p.ID IN (
                SELECT object_id FROM {$wpdb->prefix}term_relationships tr
                INNER JOIN {$wpdb->prefix}term_taxonomy tt ON tr.term_taxonomy_id = tt.term_taxonomy_id
                WHERE tt.taxonomy = 'product_cat' AND tt.term_id IN ($category_placeholders)
            )";
            $params = array_merge($params, $category_filter);
        }

        if (!empty($tag_filter)) {
            $tag_placeholders = implode(',', array_fill(0, count($tag_filter), '%d'));
            $sql .= " AND p.ID IN (
                SELECT object_id FROM {$wpdb->prefix}term_relationships tr
                INNER JOIN {$wpdb->prefix}term_taxonomy tt ON tr.term_taxonomy_id = tt.term_taxonomy_id
                WHERE tt.taxonomy = 'product_tag' AND tt.term_id IN ($tag_placeholders)
            )";
            $params = array_merge($params, $tag_filter);
        }

        if (!empty($stock_filter)) {
            $stock_placeholders = implode(',', array_fill(0, count($stock_filter), '%s'));
            $sql .= " AND pm_stock_status.meta_value IN ($stock_placeholders)";
            $params = array_merge($params, $stock_filter);
        }

        if ($min_price > 0) {
            $sql .= " AND CAST(pm_price.meta_value AS DECIMAL(10,2)) >= %f";
            $params[] = $min_price;
        }

        if ($max_price > 0) {
            $sql .= " AND CAST(pm_price.meta_value AS DECIMAL(10,2)) <= %f";
            $params[] = $max_price;
        }



        if ($min_sales > 0) {
            $sql .= " AND COALESCE(sales.total_sales, 0) >= %d";
            $params[] = $min_sales;
        }

        if ($max_sales > 0) {
            $sql .= " AND COALESCE(sales.total_sales, 0) <= %d";
            $params[] = $max_sales;
        }

        // Sortering
        if ($sort_column === 'total_sales') {
            $sql .= " ORDER BY total_sales $sort_direction, p.post_title ASC";
        } elseif ($sort_column === 'stock_quantity') {
            $sql .= " ORDER BY stock_quantity $sort_direction, p.post_title ASC";
        } else {
            $sql .= " ORDER BY p.post_title ASC";
        }

        // Get total count for pagination
        $count_sql = "SELECT COUNT(*) FROM ($sql) as count_table";
        $count_prepared_sql = $wpdb->prepare($count_sql, ...$params);
        $total_count = $wpdb->get_var($count_prepared_sql);

        // Add pagination
        $offset = ($page - 1) * $per_page;
        $sql .= " LIMIT $per_page OFFSET $offset";

        // Hämta alla produkter
        $prepared_sql = $wpdb->prepare($sql, ...$params);
        $results = $wpdb->get_results($prepared_sql);

        if (empty($results)) {
            return ['products' => [], 'total_count' => $total_count, 'total_pages' => 0];
        }

        // Steg 1: Hämta alla variationer
        $variation_ids_all = [];
        $variable_product_map = [];

        foreach ($results as $row) {
            // Check both the meta value and the actual product type
            $product = wc_get_product($row->product_id);
            if ($product) {
                $actual_type = $product->get_type();

                if ($actual_type === 'variable') {
                    $children = $product->get_children();
                    if (!empty($children)) {
                        $variation_ids_all = array_merge($variation_ids_all, $children);
                        $variable_product_map[$row->product_id] = $children;
                    }
                }
            }
        }

        // Steg 2: Hämta försäljning i bulk för alla variationer
        $variation_sales_lookup = [];
        if (!empty($variation_ids_all)) {
            $variation_sales_lookup = $this->get_bulk_sales_data($variation_ids_all, true, $start_date, $end_date);
        }

        // Steg 3: Sammanställ slutlig produktlista
        foreach ($results as $row) {
            // Get the actual product type using WooCommerce product object
            $product = wc_get_product($row->product_id);
            $actual_type = $product ? $product->get_type() : 'simple';

            $product_data = [
                'id' => $row->product_id,
                'name' => $row->product_name,
                'sku' => $row->sku,
                'stock_quantity' => is_numeric($row->stock_quantity) ? (int)$row->stock_quantity : null,
                'stock_status' => $row->stock_status,
                'regular_price' => $row->regular_price,
                'sale_price' => $row->sale_price,
                'type' => $actual_type,
                'status' => $row->status,
                'total_sales' => (int)$row->total_sales,
                'variations' => []
            ];

            // Lägg till variationer
            if (isset($variable_product_map[$row->product_id])) {
                foreach ($variable_product_map[$row->product_id] as $variation_id) {
                    $variation = wc_get_product($variation_id);
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
                        'status' => get_post_status($variation_id),
                        'total_sales' => $variation_sales_lookup[$variation_id] ?? 0,
                        'attributes' => $variation->get_attributes()
                    ];
                }
            }

            $products[] = $product_data;
        }



        $total_pages = ceil($total_count / $per_page);

        return [
            'products' => $products,
            'total_count' => $total_count,
            'total_pages' => $total_pages,
            'current_page' => $page,
            'per_page' => $per_page
        ];
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
     * Get sales data for a specific product
     */
    public function get_product_sales_by_date($product_id, $start_date = null, $end_date = null, $is_variation = false)
    {
        global $wpdb;

        // Set default 1-month interval if no dates provided
        if (empty($start_date) || empty($end_date)) {
            $end_date = date('Y-m-d'); // Today
            $start_date = date('Y-m-d', strtotime('-1 month')); // 1 month ago
        }

        $lookup_table = "{$wpdb->prefix}wc_order_product_lookup";
        $stats_table  = "{$wpdb->prefix}wc_order_stats";

        // Välj rätt kolumn: product_id eller variation_id
        $column = $is_variation ? 'variation_id' : 'product_id';

        $sql = "
            SELECT SUM(lookup.product_qty)
            FROM $lookup_table AS lookup
            INNER JOIN $stats_table AS stats
                ON lookup.order_id = stats.order_id
            WHERE lookup.$column = %d
              AND stats.status IN ('wc-completed', 'wc-processing')
        ";

        $params = [$product_id];

        // Always apply date filter since we now have default dates
        $sql .= " AND stats.date_created BETWEEN %s AND %s";
        $params[] = date('Y-m-d H:i:s', strtotime($start_date));
        $params[] = date('Y-m-d H:i:s', strtotime($end_date));

        $prepared_sql = $wpdb->prepare($sql, ...$params);
        $result = $wpdb->get_var($prepared_sql);

        return intval($result);
    }
}