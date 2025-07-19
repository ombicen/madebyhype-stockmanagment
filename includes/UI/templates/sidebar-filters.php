<?php

/**
 * Sidebar Filters Template
 * 
 * @param string $start_date
 * @param string $end_date
 * @param int $per_page
 * @param string $sort_by
 * @param string $sort_order
 * @param array $category_filter
 * @param array $tag_filter
 * @param array $stock_filter
 * @param float $min_price
 * @param float $max_price
 * @param int $min_sales
 * @param int $max_sales
 */
?>
<div id="filters-sidebar" class="sidebar-filters-container">
    <h3 class="sidebar-filters-title"><?php _e('Filters', 'madebyhype-stockmanagment'); ?></h3>

    <form id="filters-form" method="get" action="" class="sidebar-filters-form">
        <input type="hidden" name="page" value="madebyhype-stockmanagment">
        <input type="hidden" name="start_date" value="<?php echo esc_attr($start_date); ?>">
        <input type="hidden" name="end_date" value="<?php echo esc_attr($end_date); ?>">
        <input type="hidden" name="per_page" value="<?php echo esc_attr($per_page); ?>">
        <input type="hidden" name="sort_by" value="<?php echo esc_attr($sort_by); ?>">
        <input type="hidden" name="sort_order" value="<?php echo esc_attr($sort_order); ?>">

        <!-- Categories Filter -->
        <div class="sidebar-filter-section">
            <label class="sidebar-filter-label"><?php _e('Categories', 'madebyhype-stockmanagment'); ?></label>
            <div class="sidebar-filter-checkbox-container">
                <?php
                $categories = get_terms(['taxonomy' => 'product_cat', 'hide_empty' => true]);
                foreach ($categories as $category) {
                    $checked = in_array($category->term_id, $category_filter) ? 'checked' : '';
                    echo '<label class="sidebar-filter-checkbox-item">
                            <input type="checkbox" name="category_filter[]" value="' . esc_attr($category->term_id) . '" ' . $checked . '>
                            ' . esc_html($category->name) . '
                          </label>';
                }
                ?>
            </div>
        </div>

        <!-- Tags Filter -->
        <div class="sidebar-filter-section">
            <label class="sidebar-filter-label"><?php _e('Tags', 'madebyhype-stockmanagment'); ?></label>
            <div class="sidebar-filter-checkbox-container">
                <?php
                $tags = get_terms(['taxonomy' => 'product_tag', 'hide_empty' => true]);
                foreach ($tags as $tag) {
                    $checked = in_array($tag->term_id, $tag_filter) ? 'checked' : '';
                    echo '<label class="sidebar-filter-checkbox-item">
                            <input type="checkbox" name="tag_filter[]" value="' . esc_attr($tag->term_id) . '" ' . $checked . '>
                            ' . esc_html($tag->name) . '
                          </label>';
                }
                ?>
            </div>
        </div>

        <!-- Stock Status Filter -->
        <div class="sidebar-filter-section">
            <label class="sidebar-filter-label"><?php _e('Stock Status', 'madebyhype-stockmanagment'); ?></label>
            <div class="sidebar-filter-checkbox-list">
                <?php
                $stock_statuses = [
                    'instock' => __('In Stock', 'madebyhype-stockmanagment'),
                    'outofstock' => __('Out of Stock', 'madebyhype-stockmanagment'),
                    'onbackorder' => __('On Backorder', 'madebyhype-stockmanagment')
                ];
                foreach ($stock_statuses as $status => $label) {
                    $checked = in_array($status, $stock_filter) ? 'checked' : '';
                    echo '<label class="sidebar-filter-checkbox-item">
                            <input type="checkbox" name="stock_filter[]" value="' . esc_attr($status) . '" ' . $checked . '>
                            ' . esc_html($label) . '
                          </label>';
                }
                ?>
            </div>
        </div>

        <!-- Price Range Filter -->
        <div class="sidebar-filter-section">
            <label class="sidebar-filter-label"><?php _e('Price Range', 'madebyhype-stockmanagment'); ?></label>
            <div class="sidebar-filter-input-group">
                <input type="number" name="min_price" placeholder="<?php esc_attr_e('Min', 'madebyhype-stockmanagment'); ?>"
                    value="<?php echo esc_attr($min_price > 0 ? $min_price : ''); ?>" class="sidebar-filter-input">
                <input type="number" name="max_price" placeholder="<?php esc_attr_e('Max', 'madebyhype-stockmanagment'); ?>"
                    value="<?php echo esc_attr($max_price > 0 ? $max_price : ''); ?>" class="sidebar-filter-input">
            </div>
        </div>

        <!-- Sales Range Filter -->
        <div class="sidebar-filter-section">
            <label class="sidebar-filter-label"><?php _e('Sales Range', 'madebyhype-stockmanagment'); ?></label>
            <div class="sidebar-filter-input-group">
                <input type="number" name="min_sales" placeholder="<?php esc_attr_e('Min', 'madebyhype-stockmanagment'); ?>"
                    value="<?php echo esc_attr($min_sales > 0 ? $min_sales : ''); ?>" class="sidebar-filter-input">
                <input type="number" name="max_sales" placeholder="<?php esc_attr_e('Max', 'madebyhype-stockmanagment'); ?>"
                    value="<?php echo esc_attr($max_sales > 0 ? $max_sales : ''); ?>" class="sidebar-filter-input">
            </div>
        </div>

        <!-- Filter Buttons -->
        <div class="sidebar-filter-buttons">
            <button type="submit" class="sidebar-filter-apply-btn">
                <?php _e('Apply Filters', 'madebyhype-stockmanagment'); ?>
            </button>
            <a href="?page=madebyhype-stockmanagment" class="sidebar-filter-clear-btn">
                <?php _e('Clear All', 'madebyhype-stockmanagment'); ?>
            </a>
        </div>
    </form>
</div>