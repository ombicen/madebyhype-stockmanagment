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
        // Handle date filter and sorting parameters
        $start_date = isset($_GET['start_date']) ? sanitize_text_field($_GET['start_date']) : '';
        $end_date = isset($_GET['end_date']) ? sanitize_text_field($_GET['end_date']) : '';
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

        // Handle sidebar filter parameters
        $category_filter = isset($_GET['category_filter']) ? (array)$_GET['category_filter'] : [];
        $tag_filter = isset($_GET['tag_filter']) ? (array)$_GET['tag_filter'] : [];
        $stock_filter = isset($_GET['stock_filter']) ? (array)$_GET['stock_filter'] : [];
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
        $this->ui_manager->render_admin_page($products, $total_count, $total_pages, $current_page, $per_page, $start_date, $end_date, $filter_applied, $sort_by, $sort_order, $category_filter, $tag_filter, $stock_filter, $min_price, $max_price, $min_sales, $max_sales, $include_variations);
    }
}
