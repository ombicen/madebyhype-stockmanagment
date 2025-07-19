<?php

namespace MadeByHypeStockmanagment\Admin;

if (!defined('ABSPATH')) {
    exit;
}

class AjaxHandler
{
    public function init()
    {
        add_action('wp_ajax_madebyhype_save_stock_changes', [$this, 'save_stock_changes']);
        add_action('wp_ajax_nopriv_madebyhype_save_stock_changes', [$this, 'save_stock_changes']);
        add_action('wp_ajax_madebyhype_revert_version', [$this, 'revert_to_version']);
        add_action('wp_ajax_nopriv_madebyhype_revert_version', [$this, 'revert_to_version']);
    }

    public function save_stock_changes()
    {
        check_ajax_referer('madebyhype_stock_update_nonce');

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error('Insufficient permissions');
            return;
        }

        $data = isset($_POST['data']) ? $_POST['data'] : [];
        $products = isset($data['products']) ? $data['products'] : [];
        $variations = isset($data['variations']) ? $data['variations'] : [];

        $results = [];
        $success_count = 0;
        $error_count = 0;

        $original_values = ['products' => [], 'variations' => []];

        foreach ($products as $product_id => $fields) {
            $product = \wc_get_product($product_id);
            if (!$product) {
                $results[] = ['id' => $product_id, 'success' => false, 'message' => 'Product not found'];
                $error_count++;
                continue;
            }

            $original_values['products'][$product_id] = [
                'stock_quantity' => $product->get_stock_quantity(),
                'stock_status' => $product->get_stock_status(),
                'backorders' => $product->get_backorders(),
                'manage_stock' => $product->get_manage_stock(),
                'regular_price' => $product->get_regular_price(),
                'sale_price' => $product->get_sale_price(),
            ];

            $messages = [];
            $product->set_manage_stock(true);

            foreach ($fields as $key => $value) {
                switch ($key) {
                    case 'stock_quantity':
                        $product->set_stock_quantity((int) $value);
                        $messages[] = "Stock set to $value";
                        break;
                    case 'stock_status':
                        if ($value === 'onbackorder') {
                            $product->set_backorders('yes');
                        } elseif ($value === 'outofstock') {
                            $product->set_stock_quantity(0);
                            $product->set_backorders('no');
                        }
                        $messages[] = "Stock status set to $value";
                        break;
                    case 'regular_price':
                        $product->set_regular_price((float) $value);
                        $messages[] = "Regular price set to $value";
                        break;
                    case 'sale_price':
                        $product->set_sale_price((float) $value);
                        $messages[] = "Sale price set to $value";
                        break;
                }
            }

            $product->save();
            $success_count++;
            $results[] = ['id' => $product_id, 'success' => true, 'message' => implode(", ", $messages)];
        }

        foreach ($variations as $variation_id => $fields) {
            $variation = \wc_get_product($variation_id);
            if (!$variation || !$variation->is_type('variation')) {
                $results[] = ['id' => $variation_id, 'success' => false, 'message' => 'Variation not found'];
                $error_count++;
                continue;
            }

            $original_values['variations'][$variation_id] = [
                'stock_quantity' => $variation->get_stock_quantity(),
                'stock_status' => $variation->get_stock_status(),
                'backorders' => $variation->get_backorders(),
                'manage_stock' => $variation->get_manage_stock(),
                'regular_price' => $variation->get_regular_price(),
            ];

            $messages = [];
            $variation->set_manage_stock(true);

            foreach ($fields as $key => $value) {
                switch ($key) {
                    case 'stock_quantity':
                        $variation->set_stock_quantity((int) $value);
                        $messages[] = "Stock set to $value";
                        break;
                    case 'stock_status':
                        if ($value === 'onbackorder') {
                            $variation->set_backorders('yes');
                        } elseif ($value === 'outofstock') {
                            $variation->set_stock_quantity(0);
                            $variation->set_backorders('no');
                        }
                        $messages[] = "Stock status set to $value";
                        break;
                    case 'regular_price':
                        $variation->set_regular_price((float) $value);
                        $messages[] = "Regular price set to $value";
                        break;
                }
            }

            $variation->save();
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
        $success = $version_manager->revert_to_version($version_number);

        if ($success) {
            wp_send_json_success('Successfully reverted to version ' . $version_number);
        } else {
            wp_send_json_error('Failed to revert to version ' . $version_number);
        }
    }
}
