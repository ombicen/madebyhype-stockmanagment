<?php

/**
 * Product Table Template
 * 
 * @param array $products
 * @param string $start_date
 * @param string $end_date
 * @param string $sort_by
 * @param string $sort_order
 */
?>
<div class="product-table-container">
    <!-- Save Controls Row -->
    <div class="save-controls-row">
        <div class="save-controls-content">
            <div class="save-controls-left">
                <span class="changes-indicator" id="changes-indicator">
                    <span class="changes-count">0</span> changes pending
                </span>
            </div>
            <div class="save-controls-right">
                <button type="button" class="reset-changes-btn" id="reset-changes-btn">
                    Reset Changes
                </button>
                <button type="button" class="save-changes-btn" id="save-changes-btn">
                    Save All Changes
                </button>
            </div>
        </div>
    </div>

    <table class="product-table">
        <thead>
            <tr>
                <th style="width: 60px;">ID</th>
                <th style="width: 300px;">Product Name</th>
                <th>SKU</th>
                <th
                    class="sortable <?php echo ($sort_by === 'stock_quantity') ? 'sort-' . strtolower($sort_order) : ''; ?>">
                    <?php
                    $stock_sort_url = add_query_arg([
                        'page' => 'omer-stockmanagment',
                        'start_date' => $start_date,
                        'end_date' => $end_date,
                        'sort_by' => 'stock_quantity',
                        'sort_order' => ($sort_by === 'stock_quantity' && $sort_order === 'ASC') ? 'DESC' : 'ASC'
                    ]);
                    ?>
                    <a href="<?php echo esc_url($stock_sort_url); ?>" style="color: inherit; text-decoration: none;">
                        Stock Quantity
                    </a>
                </th>
                <th>Stock Status</th>

                <th>Regular Price</th>
                <th>Sale Price</th>
                <th
                    class="sortable <?php echo ($sort_by === 'total_sales') ? 'sort-' . strtolower($sort_order) : ''; ?>">
                    <?php
                    $sales_sort_url = add_query_arg([
                        'page' => 'omer-stockmanagment',
                        'start_date' => $start_date,
                        'end_date' => $end_date,
                        'sort_by' => 'total_sales',
                        'sort_order' => ($sort_by === 'total_sales' && $sort_order === 'ASC') ? 'DESC' : 'ASC'
                    ]);
                    ?>
                    <a href="<?php echo esc_url($sales_sort_url); ?>" style="color: inherit; text-decoration: none;">
                        Total Sales
                    </a>
                </th>
                <th>Status</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($products)): ?>
            <tr>
                <td colspan="10" class="empty-state">
                    <div class="empty-state-icon">📦</div>
                    No products found with stock information.
                </td>
            </tr>
            <?php else: ?>
            <?php foreach ($products as $product): ?>
            <tr>
                <td><?php echo esc_html($product['id']); ?></td>
                <td>
                    <div style="display: flex; align-items: center; gap: 5px;">
                        <?php if (!empty($product['variations'])): ?>
                        <button class="expand-variations" data-product-id="<?php echo esc_attr($product['id']); ?>">

                            <img src="<?php echo plugin_dir_url(__DIR__) . '../../assets/images/chevron.svg'; ?>"
                                class="chevron" alt="Toggle variations" />
                        </button>
                        <a href="<?php echo esc_url(get_edit_post_link($product['id'])); ?>" target="_blank"
                            class="product-name">
                            <?php echo esc_html($product['name']); ?>
                        </a>
                        <span class="variation-count">(<?php echo count($product['variations']); ?>)</span>
                        <?php else: ?>
                        <a href="<?php echo esc_url(get_edit_post_link($product['id'])); ?>" target="_blank"
                            class="product-name">
                            <?php echo esc_html($product['name']); ?>
                        </a>
                        <?php endif; ?>
                    </div>
                </td>
                <td class="product-sku"><?php echo esc_html($product['sku']); ?></td>
                <td>
                    <?php
                            $stock_qty = $product['stock_quantity'];
                            if ($stock_qty === null) {
                                echo '<span style="color: #999;">N/A</span>';
                            } else {
                                echo '<input type="number" 
                                    class="stock-quantity-input" 
                                    data-product-id="' . esc_attr($product['id']) . '" 
                                    data-original-value="' . esc_attr($stock_qty) . '"
                                    value="' . esc_attr($stock_qty) . '" 
                                    min="0" 
                                    step="1" />';
                            }
                            ?>
                </td>
                <td>
                    <?php
                            $status = $product['stock_status'];
                            echo '<select class="stock-status-select" data-product-id="' . esc_attr($product['id']) . '" data-original-value="' . esc_attr($status) . '">';
                            echo '<option value="instock" ' . selected($status, 'instock', false) . '>In Stock</option>';
                            echo '<option value="outofstock" ' . selected($status, 'outofstock', false) . '>Out of Stock</option>';
                            echo '<option value="onbackorder" ' . selected($status, 'onbackorder', false) . '>On Backorder</option>';
                            echo '</select>';
                            ?>
                </td>

                <td>
                    <?php
                            $regular_price = $product['regular_price'];
                            if ($regular_price !== null && $regular_price !== '') {
                                echo '<input type="number" 
                                    class="regular-price-input" 
                                    data-product-id="' . esc_attr($product['id']) . '" 
                                    data-original-value="' . esc_attr($regular_price) . '"
                                    value="' . esc_attr($regular_price) . '" 
                                    min="0" 
                                    step="0.01" 
                                    placeholder="0.00" />';
                            } else {
                                echo '<input type="number" 
                                    class="regular-price-input" 
                                    data-product-id="' . esc_attr($product['id']) . '" 
                                    data-original-value=""
                                    value="" 
                                    min="0" 
                                    step="0.01" 
                                    placeholder="0.00" />';
                            }
                            ?>
                </td>
                <td>
                    <?php
                            $sale_price = $product['sale_price'];
                            if ($sale_price !== null && $sale_price !== '') {
                                echo '<input type="number" 
                                    class="sale-price-input" 
                                    data-product-id="' . esc_attr($product['id']) . '" 
                                    data-original-value="' . esc_attr($sale_price) . '"
                                    value="' . esc_attr($sale_price) . '" 
                                    min="0" 
                                    step="0.01" 
                                    placeholder="0.00" />';
                            } else {
                                echo '<input type="number" 
                                    class="sale-price-input" 
                                    data-product-id="' . esc_attr($product['id']) . '" 
                                    data-original-value=""
                                    value="" 
                                    min="0" 
                                    step="0.01" 
                                    placeholder="0.00" />';
                            }
                            ?>
                </td>
                <td class="sales-data">
                    <?php
                            $total_sales = $product['total_sales'];
                            if ($total_sales > 0) {
                                $class = $total_sales >= 10 ? 'high' : ($total_sales >= 5 ? 'medium' : 'low');
                                echo '<div class="sales-total ' . $class . '">' . esc_html($total_sales) . '</div>';
                            } else {
                                echo '<div class="sales-total">0</div>';
                            }
                            ?>
                </td>
                <td><?php echo esc_html(ucfirst($product['status'])); ?></td>
            </tr>

            <!-- Variation rows -->
            <?php if (!empty($product['variations'])): ?>
            <tr id="variations-<?php echo esc_attr($product['id']); ?>" class="variations-row" style="display: none;">
                <td colspan="10">
                    <div class="variations-container">
                        <?php include __DIR__ . '/variation-table.php'; ?>
                    </div>
                </td>
            </tr>
            <?php endif; ?>
            <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
</div>