<?php

namespace OmerStockhmanagment\Admin;

if (!defined('ABSPATH')) {
    exit;
}

class AjaxHandler
{
    public function init()
    {
        add_action('wp_ajax_update_product_stock', array($this, 'update_product_stock'));
        add_action('wp_ajax_nopriv_update_product_stock', array($this, 'update_product_stock'));
        add_action('wp_ajax_update_variation_stock', array($this, 'update_variation_stock'));
        add_action('wp_ajax_nopriv_update_variation_stock', array($this, 'update_variation_stock'));
        add_action('wp_ajax_revert_to_version', array($this, 'revert_to_version'));
        add_action('wp_ajax_nopriv_revert_to_version', array($this, 'revert_to_version'));
    }

    public function update_product_stock()
    {
        // Verify nonce for security
        if (!wp_verify_nonce($_POST['nonce'], 'omer_stock_update_nonce')) {
            wp_send_json_error('Security check failed');
            return;
        }

        // Check if user has permission
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error('Insufficient permissions');
            return;
        }

        $updates = isset($_POST['updates']) ? $_POST['updates'] : array();
        $results = array();
        $success_count = 0;
        $error_count = 0;

        // Capture original values for versioning before making changes
        $original_values = array();
        foreach ($updates as $update) {
            $product_id = intval($update['product_id']);
            $product = \wc_get_product($product_id);
            if ($product) {
                $original_values[$product_id] = array();
                if (isset($update['stock_quantity'])) {
                    $original_values[$product_id]['stock_quantity'] = $product->get_stock_quantity();
                }
                if (isset($update['stock_status'])) {
                    $original_values[$product_id]['stock_status'] = $product->get_stock_status();
                }
                if (isset($update['price'])) {
                    $original_values[$product_id]['price'] = $product->get_price();
                }
                if (isset($update['regular_price'])) {
                    $original_values[$product_id]['regular_price'] = $product->get_regular_price();
                }
                if (isset($update['sale_price'])) {
                    $original_values[$product_id]['sale_price'] = $product->get_sale_price();
                }
            }
        }

        foreach ($updates as $update) {
            $product_id = intval($update['product_id']);
            $stock_quantity = isset($update['stock_quantity']) ? intval($update['stock_quantity']) : null;
            $stock_status = isset($update['stock_status']) ? sanitize_text_field($update['stock_status']) : null;
            $price = isset($update['price']) ? floatval($update['price']) : null;
            $regular_price = isset($update['regular_price']) ? floatval($update['regular_price']) : null;
            $sale_price = isset($update['sale_price']) ? floatval($update['sale_price']) : null;

            // Validate product exists
            $product = \wc_get_product($product_id);
            if (!$product) {
                $results[] = array(
                    'product_id' => $product_id,
                    'success' => false,
                    'message' => 'Product not found'
                );
                $error_count++;
                continue;
            }

            $update_success = true;
            $messages = array();

            // Update stock quantity if provided
            if ($stock_quantity !== null) {
                if ($product->is_type('variable')) {
                    // For variable products, update the parent stock
                    $product->set_manage_stock(true);
                    $product->set_stock_quantity($stock_quantity);

                    // Update stock status based on quantity
                    if ($stock_quantity > 0) {
                        $product->set_stock_status('instock');
                    } else {
                        $product->set_stock_status('outofstock');
                    }
                } else {
                    // For simple products
                    $product->set_manage_stock(true);
                    $product->set_stock_quantity($stock_quantity);

                    // Update stock status based on quantity
                    if ($stock_quantity > 0) {
                        $product->set_stock_status('instock');
                    } else {
                        $product->set_stock_status('outofstock');
                    }
                }
                $messages[] = "Stock quantity updated to {$stock_quantity}";
            }

            // Update stock status if provided
            if ($stock_status !== null) {
                $product->set_stock_status($stock_status);
                $messages[] = "Stock status updated to {$stock_status}";
            }

            // Update price if provided
            if ($price !== null) {
                $product->set_price($price);
                $messages[] = "Price updated to " . \wc_price($price);
            }

            // Update regular price if provided
            if ($regular_price !== null) {
                $product->set_regular_price($regular_price);
                $messages[] = "Regular price updated to " . \wc_price($regular_price);
            }

            // Update sale price if provided
            if ($sale_price !== null) {
                $product->set_sale_price($sale_price);
                $messages[] = "Sale price updated to " . \wc_price($sale_price);
            }

            // Save the product
            $save_result = $product->save();

            if ($save_result) {
                $results[] = array(
                    'product_id' => $product_id,
                    'success' => true,
                    'message' => implode(', ', $messages)
                );
                $success_count++;
            } else {
                $results[] = array(
                    'product_id' => $product_id,
                    'success' => false,
                    'message' => 'Failed to save product'
                );
                $error_count++;
            }
        }

        // Clear any caches
        \wc_delete_product_transients();

        // Save version if there were successful updates
        if ($success_count > 0) {
            $version_manager = new \OmerStockhmanagment\Data\VersionManager();
            // Prepare changes data for versioning (store previous/original values)
            $changes_data = array('products' => array());
            foreach ($original_values as $product_id => $fields) {
                $changes_data['products'][$product_id] = $fields;
            }
            $version_manager->save_version($changes_data, "Updated $success_count product(s)");
        }

        wp_send_json_success(array(
            'results' => $results,
            'summary' => array(
                'total' => count($updates),
                'success' => $success_count,
                'errors' => $error_count
            )
        ));
    }

    public function update_variation_stock()
    {
        // Verify nonce for security
        if (!wp_verify_nonce($_POST['nonce'], 'omer_stock_update_nonce')) {
            wp_send_json_error('Security check failed');
            return;
        }

        // Check if user has permission
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error('Insufficient permissions');
            return;
        }

        $updates = isset($_POST['updates']) ? $_POST['updates'] : array();
        $results = array();
        $success_count = 0;
        $error_count = 0;

        // Capture original values for versioning before making changes
        $original_values = array();
        foreach ($updates as $update) {
            $variation_id = intval($update['variation_id']);
            $variation = \wc_get_product($variation_id);
            if ($variation && $variation->is_type('variation')) {
                $original_values[$variation_id] = array();
                if (isset($update['stock_quantity'])) {
                    $original_values[$variation_id]['stock_quantity'] = $variation->get_stock_quantity();
                }
                if (isset($update['stock_status'])) {
                    $original_values[$variation_id]['stock_status'] = $variation->get_stock_status();
                }
                if (isset($update['regular_price'])) {
                    $original_values[$variation_id]['regular_price'] = $variation->get_regular_price();
                }
            }
        }

        foreach ($updates as $update) {
            $variation_id = intval($update['variation_id']);
            $stock_quantity = isset($update['stock_quantity']) ? intval($update['stock_quantity']) : null;
            $stock_status = isset($update['stock_status']) ? sanitize_text_field($update['stock_status']) : null;
            $regular_price = isset($update['regular_price']) ? floatval($update['regular_price']) : null;
            $variation = \wc_get_product($variation_id);
            if (!$variation || !$variation->is_type('variation')) {
                $results[] = array(
                    'variation_id' => $variation_id,
                    'success' => false,
                    'message' => 'Variation not found'
                );
                $error_count++;
                continue;
            }

            $update_success = true;
            $messages = array();

            // Update stock quantity if provided
            if ($stock_quantity !== null) {
                $variation->set_manage_stock(true);
                $variation->set_stock_quantity($stock_quantity);
                $messages[] = "Stock quantity updated to {$stock_quantity}";
            }

            // Update stock status if provided
            if ($stock_status !== null) {
                $variation->set_stock_status($stock_status);
                $messages[] = "Stock status updated to {$stock_status}";
            }

            // Update regular price if provided
            if ($regular_price !== null) {
                $variation->set_regular_price($regular_price);
                $messages[] = "Regular price updated to " . \wc_price($regular_price);
            }

            // Save the variation
            $save_result = $variation->save();

            if ($save_result) {
                $results[] = array(
                    'variation_id' => $variation_id,
                    'success' => true,
                    'message' => implode(', ', $messages)
                );
                $success_count++;
            } else {
                $results[] = array(
                    'variation_id' => $variation_id,
                    'success' => false,
                    'message' => 'Failed to save variation'
                );
                $error_count++;
            }
        }

        // Clear any caches
        \wc_delete_product_transients();

        // Save version if there were successful updates
        if ($success_count > 0) {
            $version_manager = new \OmerStockhmanagment\Data\VersionManager();
            // Prepare changes data for versioning (store previous/original values)
            $changes_data = array('variations' => array());
            foreach ($original_values as $variation_id => $fields) {
                $changes_data['variations'][$variation_id] = $fields;
            }
            $version_manager->save_version($changes_data, "Updated $success_count variation(s)");
        }

        wp_send_json_success(array(
            'results' => $results,
            'summary' => array(
                'total' => count($updates),
                'success' => $success_count,
                'errors' => $error_count
            )
        ));
    }

    public function revert_to_version()
    {
        // Verify nonce for security
        if (!wp_verify_nonce($_POST['nonce'], 'omer_version_revert_nonce')) {
            wp_send_json_error('Security check failed');
            return;
        }

        // Check if user has permission
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error('Insufficient permissions');
            return;
        }

        $version_number = isset($_POST['version_number']) ? intval($_POST['version_number']) : 0;

        if ($version_number <= 0) {
            wp_send_json_error('Invalid version number');
            return;
        }

        // Get version manager and revert
        $version_manager = new \OmerStockhmanagment\Data\VersionManager();
        $success = $version_manager->revert_to_version($version_number);

        if ($success) {
            wp_send_json_success('Successfully reverted to version ' . $version_number);
        } else {
            wp_send_json_error('Failed to revert to version ' . $version_number);
        }
    }
}
