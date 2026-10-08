<?php

namespace MadeByHypeStockmanagment\Admin;

if (!defined('ABSPATH')) {
    exit;
}

class AjaxHandler
{
    public function init()
    {
        // Only register AJAX handlers for logged-in users with proper capabilities
        // Removed wp_ajax_nopriv_ hooks to prevent unauthenticated access attempts
        add_action('wp_ajax_madebyhype_save_stock_changes', [$this, 'save_stock_changes']);
        add_action('wp_ajax_madebyhype_revert_version', [$this, 'revert_to_version']);
    }

    public function save_stock_changes()
    {
        check_ajax_referer('madebyhype_stock_update_nonce');

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error('Insufficient permissions');
            return;
        }

        // Validate and sanitize input data
        $data = isset($_POST['data']) && is_array($_POST['data']) ? $_POST['data'] : [];
        $products = isset($data['products']) && is_array($data['products']) ? $data['products'] : [];
        $variations = isset($data['variations']) && is_array($data['variations']) ? $data['variations'] : [];

        // Validate that we have something to process
        if (empty($products) && empty($variations)) {
            wp_send_json_error('No valid data provided');
            return;
        }

        // Limit batch size to prevent abuse (max 100 items per request)
        $max_batch_size = 100;
        if (count($products) > $max_batch_size || count($variations) > $max_batch_size) {
            wp_send_json_error('Batch size exceeds maximum allowed (' . $max_batch_size . ' items)');
            return;
        }

        $results = [];
        $success_count = 0;
        $error_count = 0;

        $original_values = ['products' => [], 'variations' => []];

        foreach ($products as $product_id => $fields) {
            // Validate product_id is a positive integer
            $product_id = absint($product_id);
            if ($product_id <= 0) {
                $error_count++;
                continue;
            }

            // Validate fields is an array
            if (!is_array($fields)) {
                $results[] = ['id' => $product_id, 'success' => false, 'message' => 'Invalid field data'];
                $error_count++;
                continue;
            }

            $product = \wc_get_product($product_id);
            if (!$product) {
                $results[] = ['id' => $product_id, 'success' => false, 'message' => 'Product not found'];
                $error_count++;
                continue;
            }

            // Reject the whole item before anything is written if any field is invalid
            $validated = $this->validate_fields($product, $fields, ['stock_quantity', 'stock_status', 'regular_price', 'sale_price']);
            if (isset($validated['error'])) {
                $results[] = ['id' => $product_id, 'success' => false, 'message' => $validated['error']];
                $error_count++;
                continue;
            }

            $original = [
                'stock_quantity' => $product->get_stock_quantity(),
                'stock_status' => $product->get_stock_status(),
                'backorders' => $product->get_backorders(),
                'manage_stock' => $product->get_manage_stock(),
                'regular_price' => $product->get_regular_price(),
                'sale_price' => $product->get_sale_price(),
            ];

            try {
                $messages = $this->apply_fields($product, $validated['fields']);
                $product->save();
            } catch (\Throwable $e) {
                $results[] = ['id' => $product_id, 'success' => false, 'message' => $e->getMessage()];
                $error_count++;
                continue;
            }

            $original_values['products'][$product_id] = $original;
            $success_count++;
            $results[] = ['id' => $product_id, 'success' => true, 'message' => implode(", ", $messages)];
        }

        foreach ($variations as $variation_id => $fields) {
            // Validate variation_id is a positive integer
            $variation_id = absint($variation_id);
            if ($variation_id <= 0) {
                $error_count++;
                continue;
            }

            // Validate fields is an array
            if (!is_array($fields)) {
                $results[] = ['id' => $variation_id, 'success' => false, 'message' => 'Invalid field data'];
                $error_count++;
                continue;
            }

            $variation = \wc_get_product($variation_id);
            if (!$variation || !$variation->is_type('variation')) {
                $results[] = ['id' => $variation_id, 'success' => false, 'message' => 'Variation not found'];
                $error_count++;
                continue;
            }

            // Reject the whole item before anything is written if any field is invalid
            $validated = $this->validate_fields($variation, $fields, ['stock_quantity', 'stock_status', 'regular_price']);
            if (isset($validated['error'])) {
                $results[] = ['id' => $variation_id, 'success' => false, 'message' => $validated['error']];
                $error_count++;
                continue;
            }

            $original = [
                'stock_quantity' => $variation->get_stock_quantity(),
                'stock_status' => $variation->get_stock_status(),
                'backorders' => $variation->get_backorders(),
                'manage_stock' => $variation->get_manage_stock(),
                'regular_price' => $variation->get_regular_price(),
            ];

            try {
                $messages = $this->apply_fields($variation, $validated['fields']);
                $variation->save();
            } catch (\Throwable $e) {
                $results[] = ['id' => $variation_id, 'success' => false, 'message' => $e->getMessage()];
                $error_count++;
                continue;
            }

            $original_values['variations'][$variation_id] = $original;
            $success_count++;
            $results[] = ['id' => $variation_id, 'success' => true, 'message' => implode(", ", $messages)];
        }

        \wc_delete_product_transients();

        if ($success_count > 0) {
            $version_manager = new \MadeByHypeStockmanagment\Data\VersionManager();
            $version_manager->save_version($original_values, "Saved $success_count changes");
        }

        wp_send_json_success([
            'results' => $results,
            'summary' => [
                'total' => $success_count + $error_count,
                'success' => $success_count,
                'errors' => $error_count,
            ]
        ]);
    }

    /**
     * Validate the submitted fields for one product or variation
     *
     * Nothing is written here. An empty or non-numeric value must never be cast
     * to 0: an empty sale price ends the sale, any other empty value is an error.
     *
     * @param \WC_Product $item Product or variation the fields belong to
     * @param array $fields Raw field => value pairs from the request
     * @param array $allowed Field names this item type accepts
     * @return array ['fields' => cleaned values] or ['error' => message]
     */
    private function validate_fields($item, $fields, $allowed)
    {
        $clean = [];

        foreach ($fields as $key => $value) {
            if (!in_array($key, $allowed, true)) {
                continue;
            }

            $value = is_scalar($value) ? trim((string) wp_unslash($value)) : '';

            switch ($key) {
                case 'stock_quantity':
                    if ($value === '' || !is_numeric($value)) {
                        return ['error' => __('Stock quantity must be a number. It cannot be left empty.', 'madebyhype-stockmanagment')];
                    }
                    if ((float) $value < 0) {
                        return ['error' => __('Stock quantity cannot be negative.', 'madebyhype-stockmanagment')];
                    }
                    $clean[$key] = (int) $value;
                    break;
                case 'stock_status':
                    if (!in_array($value, ['instock', 'outofstock', 'onbackorder'], true)) {
                        return ['error' => __('Unknown stock status.', 'madebyhype-stockmanagment')];
                    }
                    $clean[$key] = $value;
                    break;
                case 'regular_price':
                    if ($value === '') {
                        return ['error' => __('Regular price cannot be left empty.', 'madebyhype-stockmanagment')];
                    }
                    if (!is_numeric($value) || (float) $value < 0) {
                        return ['error' => __('Regular price must be a number of 0 or more.', 'madebyhype-stockmanagment')];
                    }
                    $clean[$key] = \wc_format_decimal($value);
                    break;
                case 'sale_price':
                    // An empty sale price ends the sale
                    if ($value === '') {
                        $clean[$key] = '';
                        break;
                    }
                    if (!is_numeric($value) || (float) $value < 0) {
                        return ['error' => __('Sale price must be a number of 0 or more.', 'madebyhype-stockmanagment')];
                    }
                    $clean[$key] = \wc_format_decimal($value);
                    break;
            }
        }

        if (empty($clean)) {
            return ['error' => __('Nothing to save for this item.', 'madebyhype-stockmanagment')];
        }

        // WooCommerce silently drops a sale price that is not below the regular
        // price, so refuse it here and say why
        if (array_key_exists('regular_price', $clean) || array_key_exists('sale_price', $clean)) {
            $regular_price = array_key_exists('regular_price', $clean) ? $clean['regular_price'] : $item->get_regular_price();
            $sale_price = array_key_exists('sale_price', $clean) ? $clean['sale_price'] : $item->get_sale_price();

            if ($sale_price !== '' && ($regular_price === '' || (float) $sale_price >= (float) $regular_price)) {
                return ['error' => __('Sale price must be lower than the regular price.', 'madebyhype-stockmanagment')];
            }
        }

        return ['fields' => $clean];
    }

    /**
     * Apply validated fields to a product or variation (does not save)
     *
     * @param \WC_Product $item Product or variation object
     * @param array $fields Validated field => value pairs
     * @return array Messages describing what was set
     */
    private function apply_fields($item, $fields)
    {
        $messages = [];
        $item->set_manage_stock(true);

        foreach ($fields as $key => $value) {
            switch ($key) {
                case 'stock_quantity':
                    $item->set_stock_quantity($value);
                    $messages[] = "Stock set to $value";
                    break;
                case 'stock_status':
                    if ($value === 'onbackorder') {
                        $item->set_backorders('yes');
                    } elseif ($value === 'outofstock') {
                        $item->set_stock_quantity(0);
                        $item->set_backorders('no');
                    }
                    $messages[] = "Stock status set to $value";
                    break;
                case 'regular_price':
                    $item->set_regular_price($value);
                    $messages[] = "Regular price set to $value";
                    break;
                case 'sale_price':
                    $item->set_sale_price($value);
                    $messages[] = $value === '' ? 'Sale price cleared' : "Sale price set to $value";
                    break;
            }
        }

        return $messages;
    }

    public function revert_to_version()
    {
        check_ajax_referer('madebyhype_version_revert_nonce');

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error('Insufficient permissions');
            return;
        }

        $version_number = isset($_POST['version_id']) ? intval($_POST['version_id']) : 0;

        if ($version_number <= 0) {
            wp_send_json_error('Invalid version number');
            return;
        }

        $version_manager = new \MadeByHypeStockmanagment\Data\VersionManager();

        if (!$version_manager->get_version($version_number)) {
            wp_send_json_error('Version ' . $version_number . ' no longer exists. Reload the page to see the current history.');
            return;
        }

        $success = $version_manager->revert_to_version($version_number);

        if ($success) {
            wp_send_json_success('Successfully reverted to version ' . $version_number);
        } else {
            wp_send_json_error('Failed to revert to version ' . $version_number);
        }
    }
}
