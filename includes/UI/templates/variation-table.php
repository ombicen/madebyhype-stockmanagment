<?php

/**
 * Variation Table Template
 * 
 * This template is included within the product table for variable products
 */
?>
<table class="variation-table">
    <thead>
        <tr>
            <th>Variation ID</th>
            <th>Variation Name</th>
            <th>SKU</th>
            <th>Stock</th>
            <th>Status</th>
            <th>Price</th>
            <th>Total Sales</th>
        </tr>
    </thead>
    <tbody>
        <?php foreach ($product['variations'] as $variation): ?>
        <tr>
            <td><?php echo esc_html($variation['id']); ?></td>
            <td>
                <div class="product-name">
                    <a href="<?php echo esc_url(get_edit_post_link($variation['id'])); ?>" target="_blank">
                        <?php echo esc_html($variation['name']); ?>
                    </a>
                </div>
                <?php if (!empty($variation['attributes'])): ?>
                <div class="variation-attributes">
                    <?php
                            $attr_parts = [];
                            foreach ($variation['attributes'] as $attr_name => $attr_value) {
                                $attr_parts[] = ucfirst(str_replace('attribute_', '', $attr_name)) . ': ' . $attr_value;
                            }
                            echo esc_html(implode(', ', $attr_parts));
                            ?>
                </div>
                <?php endif; ?>
            </td>
            <td class="variation-sku"><?php echo esc_html($variation['sku']); ?></td>
            <td>
                <?php
                    $stock_qty = $variation['stock_quantity'];
                    if ($stock_qty === null) {
                        echo '<span style="color: #999;">N/A</span>';
                    } else {
                        echo '<input type="number" 
                            class="variation-stock-quantity-input" 
                            data-variation-id="' . esc_attr($variation['id']) . '" 
                            data-original-value="' . esc_attr($stock_qty) . '"
                            value="' . esc_attr($stock_qty) . '" 
                            min="0" 
                            step="1" />';
                    }
                    ?>
            </td>
            <td>
                <?php
                    $status = $variation['stock_status'];
                    echo '<select class="variation-stock-status-select" data-variation-id="' . esc_attr($variation['id']) . '" data-original-value="' . esc_attr($status) . '">';
                    echo '<option value="instock" ' . selected($status, 'instock', false) . '>In Stock</option>';
                    echo '<option value="outofstock" ' . selected($status, 'outofstock', false) . '>Out of Stock</option>';
                    echo '<option value="onbackorder" ' . selected($status, 'onbackorder', false) . '>On Backorder</option>';
                    echo '</select>';
                    ?>
            </td>
            <td class="variation-price">
                <?php
                    $variation_price = $variation['regular_price'];
                    if ($variation_price !== null && $variation_price !== '') {
                        echo '<input type="number" 
                            class="variation-price-input" 
                            data-variation-id="' . esc_attr($variation['id']) . '" 
                            data-original-value="' . esc_attr($variation_price) . '"
                            value="' . esc_attr($variation_price) . '" 
                            min="0" 
                            step="0.01" 
                            placeholder="0.00" />';
                    } else {
                        echo '<input type="number" 
                            class="variation-price-input" 
                            data-variation-id="' . esc_attr($variation['id']) . '" 
                            data-original-value=""
                            value="" 
                            min="0" 
                            step="0.01" 
                            placeholder="0.00" />';
                    }
                    ?>
            </td>
            <td class="variation-sales">
                <?php
                    $total_sales = $variation['total_sales'];
                    if ($total_sales > 0) {
                        $class = $total_sales >= 10 ? 'high' : ($total_sales >= 5 ? 'medium' : 'low');
                        echo '<span class="' . $class . '">' . esc_html($total_sales) . '</span>';
                    } else {
                        echo '<span style="color: #999;">0</span>';
                    }
                    ?>
            </td>
        </tr>
        <?php endforeach; ?>
    </tbody>
</table>