<?php

namespace OmerStockhmanagment\UI;

if (! defined('ABSPATH')) {
    exit;
}

require_once dirname(__DIR__) . '/Data/VersionManager.php';

class UIManager
{
    public function init()
    {
        // Initialize UI manager
    }

    public function render_admin_page($products, $total_count, $total_pages, $current_page, $per_page, $start_date, $end_date, $filter_applied, $sort_by, $sort_order, $category_filter = [], $tag_filter = [], $stock_filter = [], $min_price = 0, $max_price = 0, $min_sales = 0, $max_sales = 0)
    {
?>
<div class="wrap">
    <h1><?php echo esc_html(get_admin_page_title()); ?></h1>

    <?php if (!class_exists('WooCommerce')): ?>
    <div class="notice notice-error">
        <p><strong>WooCommerce is not active!</strong> This plugin requires WooCommerce to be installed and activated to
            display products.</p>
    </div>
    <?php else: ?>
    <div class="">
        <h2>Product Stock Overview</h2>
        <p>Below is a table showing all products with their stock information.</p>

        <!-- Date Filter Form -->
        <?php $this->render_date_filter_form($start_date, $end_date, $filter_applied); ?>

        <!-- Items per page selector - Top right -->
        <?php $this->render_top_controls($per_page); ?>

        <!-- Main content with sidebar -->
        <div style="display: flex; gap: 20px;">
            <!-- Sidebar -->
            <?php $this->render_sidebar_filters($start_date, $end_date, $per_page, $sort_by, $sort_order, $category_filter, $tag_filter, $stock_filter, $min_price, $max_price, $min_sales, $max_sales); ?>

            <!-- Main content -->
            <div style="flex: 1; gap: 20px; display: flex; flex-direction: column; justify-content: space-between;">
                <?php $this->render_product_table($products, $start_date, $end_date, $sort_by, $sort_order); ?>

                <!-- Pagination Controls -->
                <?php if ($total_pages > 1): ?>
                <?php $this->render_pagination($total_pages, $current_page, $per_page, $start_date, $end_date, $sort_by, $sort_order, $total_count); ?>
                <?php endif; ?>
                <?php $this->render_legend(); ?>
                <?php $this->render_version_history(); ?>
                <?php $this->render_footer_info($total_count, count($products)); ?>
            </div>
        </div>
    </div>
    <?php endif; ?>
</div>
<?php
    }

    private function render_date_filter_form($start_date, $end_date, $filter_applied)
    {
        include __DIR__ . '/templates/date-filter.php';
    }

    private function render_top_controls($per_page)
    {
        include __DIR__ . '/templates/top-controls.php';
    }

    private function render_sidebar_filters($start_date, $end_date, $per_page, $sort_by, $sort_order, $category_filter, $tag_filter, $stock_filter, $min_price, $max_price, $min_sales, $max_sales)
    {
        include __DIR__ . '/templates/sidebar-filters.php';
    }

    private function render_product_table($products, $start_date, $end_date, $sort_by, $sort_order)
    {
        include __DIR__ . '/templates/product-table.php';
    }

    private function render_pagination($total_pages, $current_page, $per_page, $start_date, $end_date, $sort_by, $sort_order, $total_count)
    {
        include __DIR__ . '/templates/pagination.php';
    }

    private function render_footer_info($total_count, $products_count)
    {
        include __DIR__ . '/templates/footer-info.php';
    }

    private function render_legend()
    {
        include __DIR__ . '/templates/legend.php';
    }

    private function render_version_history()
    {
        // Get version manager instance
        $version_manager = new \OmerStockhmanagment\Data\VersionManager();
        $versions = $version_manager->get_versions(4); // Get last 4 versions

        // Pass version_manager to template
        $version_manager_instance = $version_manager;
        include __DIR__ . '/templates/version-history.php';
    }
}