<?php

namespace MadeByHypeStockmanagment\UI;

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

    public function render_admin_page($products, $total_count, $total_pages, $current_page, $per_page, $start_date, $end_date, $filter_applied, $sort_by, $sort_order, $category_filter = [], $tag_filter = [], $stock_filter = [], $min_price = 0, $max_price = 0, $min_sales = 0, $max_sales = 0, $include_variations = false)
    {
?>
        <div class="wrap">
            <h1><?php echo esc_html(get_admin_page_title()); ?></h1>

            <?php if (!class_exists('WooCommerce')): ?>
                <div class="notice notice-error">
                    <p><strong><?php _e('WooCommerce is not active!', 'madebyhype-stockmanagment'); ?></strong> <?php _e('This plugin requires WooCommerce to be installed and activated to display products.', 'madebyhype-stockmanagment'); ?></p>
                </div>
            <?php else: ?>
                <div class="">
                    <h2><?php _e('Product Stock Overview', 'madebyhype-stockmanagment'); ?></h2>
                    <p><?php _e('Below is a table showing all products with their stock information.', 'madebyhype-stockmanagment'); ?></p>

                    <!-- Date Filter Form -->
                    <?php $this->render_date_filter_form($start_date, $end_date, $filter_applied); ?>

                    <!-- Items per page selector - Top right -->
                    <?php $this->render_top_controls($per_page); ?>

                    <!-- Main content with sidebar -->
                    <div style="display: flex; gap: 20px;">
                        <!-- Sidebar -->
                        <?php $this->render_sidebar_filters($start_date, $end_date, $per_page, $sort_by, $sort_order, $category_filter, $tag_filter, $stock_filter, $min_price, $max_price, $min_sales, $max_sales, $include_variations); ?>

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

    private function render_sidebar_filters($start_date, $end_date, $per_page, $sort_by, $sort_order, $category_filter, $tag_filter, $stock_filter, $min_price, $max_price, $min_sales, $max_sales, $include_variations)
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
        $version_manager = new \MadeByHypeStockmanagment\Data\VersionManager();
        $versions = $version_manager->get_versions(6); // Get last 6 versions

        // Pass version_manager to template
        $version_manager_instance = $version_manager;
        include __DIR__ . '/templates/version-history.php';
    }

    /**
     * Build hierarchical category tree for display
     *
     * @param array $categories Flat array of category objects
     * @param int $parent_id Parent category ID (0 for top level)
     * @return array Hierarchical array of categories
     */
    private function build_category_hierarchy($categories, $parent_id = 0)
    {
        $hierarchy = [];

        foreach ($categories as $category) {
            if ($category->parent == $parent_id) {
                $children = $this->build_category_hierarchy($categories, $category->term_id);
                $category->children = $children;
                $hierarchy[] = $category;
            }
        }

        return $hierarchy;
    }

    /**
     * Render category hierarchy HTML
     *
     * @param array $categories Hierarchical array of categories
     * @param array $selected_categories Array of selected category IDs
     * @param int $level Current nesting level
     * @return string HTML output
     */
    private function render_category_hierarchy($categories, $selected_categories, $level = 0)
    {
        $html = '';

        foreach ($categories as $category) {
            $checked = in_array($category->term_id, $selected_categories) ? 'checked' : '';
            $has_children = !empty($category->children);
            $indent = $level * 20; // 20px indent per level

            $html .= '<div class="category-item" style="margin-left: ' . $indent . 'px;">';

            if ($has_children) {
                $html .= '<div class="category-header">';
                $html .= '<button type="button" class="category-toggle" data-category-id="' . esc_attr($category->term_id) . '">';
                $html .= '<img src="' . esc_url(plugins_url('assets/images/chevron.svg', dirname(__DIR__))) . '" class="chevron-icon" alt="Toggle">';
                $html .= '</button>';
                $html .= '<label class="sidebar-filter-checkbox-item category-label">';
                $html .= '<input type="checkbox" name="category_filter[]" value="' . esc_attr($category->term_id) . '" ' . $checked . '>';
                $html .= esc_html($category->name);
                $html .= '</label>';
                $html .= '</div>';
                $html .= '<div class="category-children" id="children-' . esc_attr($category->term_id) . '">';
                $html .= $this->render_category_hierarchy($category->children, $selected_categories, $level + 1);
                $html .= '</div>';
            } else {
                $html .= '<label class="sidebar-filter-checkbox-item">';
                $html .= '<input type="checkbox" name="category_filter[]" value="' . esc_attr($category->term_id) . '" ' . $checked . '>';
                $html .= esc_html($category->name);
                $html .= '</label>';
            }

            $html .= '</div>';
        }

        return $html;
    }
}
