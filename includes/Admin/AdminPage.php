<?php

namespace MadeByHypeStockmanagment\Admin;

if (! defined('ABSPATH')) {
    exit;
}

class AdminPage
{
    private $data_manager;
    private $ui_manager;

    public function init()
    {
        // This will be called from the main Plugin class
    }

    public function set_dependencies($data_manager, $ui_manager)
    {
        $this->data_manager = $data_manager;
        $this->ui_manager = $ui_manager;
    }

    /**
     * Add custom admin menu page
     */
    public function add_admin_menu()
    {
        add_submenu_page(
            'edit.php?post_type=product', // Parent slug (WooCommerce Products)
            __('Stock Management', 'madebyhype-stockmanagment'), // Page title
            __('Stock Management', 'madebyhype-stockmanagment'), // Menu title
            'manage_woocommerce', // Capability required (WooCommerce specific)
            'madebyhype-stockmanagment', // Menu slug
            [$this, 'render_admin_page'] // Callback function
        );
    }

    /**
     * Render the admin page content
     */
    public function render_admin_page()
    {
        // Handle date filter and sorting parameters with validation
        $start_date = isset($_GET['start_date']) ? sanitize_text_field($_GET['start_date']) : '';
        $end_date = isset($_GET['end_date']) ? sanitize_text_field($_GET['end_date']) : '';

        // Validate date format (Y-m-d)
        if (!empty($start_date) && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $start_date)) {
            $start_date = '';
        }
        if (!empty($end_date) && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $end_date)) {
            $end_date = '';
        }

        // Ensure start_date is not after end_date
        if (!empty($start_date) && !empty($end_date) && strtotime($start_date) > strtotime($end_date)) {
            // Swap dates if in wrong order
            $temp = $start_date;
            $start_date = $end_date;
            $end_date = $temp;
        }

        $filter_applied = !empty($start_date) && !empty($end_date);

        $sort_by = isset($_GET['sort_by']) ? sanitize_text_field($_GET['sort_by']) : '';
        $sort_order = isset($_GET['sort_order']) ? sanitize_text_field($_GET['sort_order']) : 'DESC';

        // Handle pagination parameters
        $current_page = isset($_GET['paged']) ? max(1, intval($_GET['paged'])) : 1;
        $per_page = isset($_GET['per_page']) ? intval($_GET['per_page']) : 50;

        // Validate per_page options
        $valid_per_page_options = [20, 50, 100, 500];
        if (!in_array($per_page, $valid_per_page_options)) {
            $per_page = 50;
        }

        // Handle sidebar filter parameters with proper validation
        $category_filter = isset($_GET['category_filter']) && is_array($_GET['category_filter'])
            ? array_map('absint', $_GET['category_filter'])
            : [];
        $category_filter = array_filter($category_filter); // Remove zeros/invalid values

        $tag_filter = isset($_GET['tag_filter']) && is_array($_GET['tag_filter'])
            ? array_map('absint', $_GET['tag_filter'])
            : [];
        $tag_filter = array_filter($tag_filter);

        // Validate stock filter values against allowed options
        $valid_stock_statuses = ['instock', 'outofstock', 'onbackorder'];
        $stock_filter = isset($_GET['stock_filter']) && is_array($_GET['stock_filter'])
            ? array_intersect($_GET['stock_filter'], $valid_stock_statuses)
            : [];
        // Attribute filter structure: attribute_filter[pa_color] = [term_id, ...]
        $raw_attribute_filter = isset($_GET['attribute_filter']) ? (array)$_GET['attribute_filter'] : [];
        $attribute_filter = [];
        foreach ($raw_attribute_filter as $tax => $terms) {
            $tax = sanitize_text_field($tax);
            // Only allow product attribute taxonomies (pa_ prefix)
            if (strpos($tax, 'pa_') !== 0) {
                continue;
            }
            $attribute_filter[$tax] = array_map('intval', (array)$terms);
        }
        $min_price = isset($_GET['min_price']) ? floatval($_GET['min_price']) : 0;
        $max_price = isset($_GET['max_price']) ? floatval($_GET['max_price']) : 0;
        $min_sales = isset($_GET['min_sales']) ? intval($_GET['min_sales']) : 0;
        $max_sales = isset($_GET['max_sales']) ? intval($_GET['max_sales']) : 0;
        $include_variations = isset($_GET['include_variations']) ? (bool)$_GET['include_variations'] : false;

        // Validate sort parameters
        if (!in_array($sort_by, ['total_sales', 'stock_quantity', ''])) {
            $sort_by = '';
        }
        if (!in_array($sort_order, ['ASC', 'DESC'])) {
            $sort_order = 'DESC';
        }

        // Get data from data manager
        $result = $this->data_manager->get_products([
            'start_date' => $start_date,
            'end_date' => $end_date,
            'sort_by' => $sort_by,
            'sort_order' => $sort_order,
            'page' => $current_page,
            'per_page' => $per_page,
            'category_filter' => $category_filter,
            'tag_filter' => $tag_filter,
            'attribute_filter' => $attribute_filter,
            'stock_filter' => $stock_filter,
            'min_price' => $min_price,
            'max_price' => $max_price,
            'min_sales' => $min_sales,
            'max_sales' => $max_sales,
            'include_variations' => $include_variations
        ]);
        $products = $result['products'];
        $total_count = $result['total_count'];
        $total_pages = $result['total_pages'];

        // Render the page using UI manager
        $this->ui_manager->render_admin_page($products, $total_count, $total_pages, $current_page, $per_page, $start_date, $end_date, $filter_applied, $sort_by, $sort_order, $category_filter, $tag_filter, $attribute_filter, $stock_filter, $min_price, $max_price, $min_sales, $max_sales, $include_variations);
    }
}
