<?php

namespace MadeByHypeStockmanagment\Assets;

if (! defined('ABSPATH')) {
    exit;
}

class AssetsManager
{
    public function init()
    {
        // Initialize assets manager
    }

    /**
     * Enqueue admin scripts and styles
     */
    public function enqueue_admin_scripts($hook, $plugin_file)
    {
        if ($hook !== 'toplevel_page_madebyhype-stockmanagment') {
            return;
        }

        $plugin_url = plugin_dir_url($plugin_file);


        wp_enqueue_script('jquery');

        // Enqueue bundled Flatpickr and Toastify
        wp_enqueue_style('flatpickr', $plugin_url . 'assets/styles/flatpickr.min.css', [], '1.0.0');
        wp_enqueue_script('flatpickr', $plugin_url . 'assets/scripts/flatpickr.min.js', [], '1.0.0', true);
        wp_enqueue_script('toastify', $plugin_url . 'assets/scripts/toastify.min.js', [], '1.12.0', true);
        wp_enqueue_style('toastify', $plugin_url . 'assets/styles/toastify.min.css', [], '1.12.0');

        // Enqueue component styles
        $this->enqueue_component_styles();

        // Enqueue admin JS from file
        wp_enqueue_script('madebyhype-stockmanagment-js', $plugin_url . 'includes/UI/scripts/admin-ui.js', ['jquery'], '1.0.0', true);

        // Localize script with nonces and data
        wp_localize_script('madebyhype-stockmanagment-js', 'madebyhypeStockData', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'updateNonce' => wp_create_nonce('madebyhype_stock_update_nonce'),
            'revertNonce' => wp_create_nonce('madebyhype_version_revert_nonce'),
        ]);

        // Enqueue inline styles
        wp_add_inline_style('wp-admin', $this->get_admin_css());
    }

    private function enqueue_component_styles()
    {
        $plugin_url = plugin_dir_url(__DIR__ . '/../../');

        wp_enqueue_style('madebyhype-date-filter', $plugin_url . 'UI/styles/date-filter.css', [], '1.0.0');
        wp_enqueue_style('madebyhype-top-controls', $plugin_url . 'UI/styles/top-controls.css', [], '1.0.0');
        wp_enqueue_style('madebyhype-sidebar-filters', $plugin_url . 'UI/styles/sidebar-filters.css', [], '1.0.0');
        wp_enqueue_style('madebyhype-product-table', $plugin_url . 'UI/styles/product-table.css', [], '1.0.0');
        wp_enqueue_style('madebyhype-variation-table', $plugin_url . 'UI/styles/variation-table.css', [], '1.0.0');
        wp_enqueue_style('madebyhype-pagination', $plugin_url . 'UI/styles/pagination.css', [], '1.0.0');
        wp_enqueue_style('madebyhype-footer-info', $plugin_url . 'UI/styles/footer-info.css', [], '1.0.0');
        wp_enqueue_style('madebyhype-legend', $plugin_url . 'UI/styles/legend.css', [], '1.0.0');
        wp_enqueue_style('madebyhype-version-history', $plugin_url . 'UI/styles/version-history.css', [], '1.0.0');
    }



    private function get_admin_css()
    {
        return '
            /* Flatpickr customization for minimal black/white theme */
            .flatpickr-calendar {
                background: white;
                border: 1px solid #e5e5e5;
                border-radius: 8px;
                box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
                font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            }
            .flatpickr-day {
                color: #000;
                border-radius: 4px;
            }
            .flatpickr-day:hover {
                background: #f5f5f5;
                color: #000;
            }
            .flatpickr-day.selected {
                background: #000;
                color: white;
                border-color: #000;
            }
            .flatpickr-day.inRange {
                background: #f5f5f5;
                color: #000;
            }
            .flatpickr-day.startRange {
                background: #000;
                color: white;
            }
            .flatpickr-day.endRange {
                background: #000;
                color: white;
            }
            .flatpickr-current-month {
                color: #000;
                font-weight: 600;
            }
            .flatpickr-monthDropdown-months {
                color: #000;
            }
            .flatpickr-weekday {
                color: #666;
                font-weight: 500;
            }
            .flatpickr-prev-month, .flatpickr-next-month {
                color: #000;
            }
            .flatpickr-prev-month:hover, .flatpickr-next-month:hover {
                color: #000;
            }
            .flatpickr-day.selected, .flatpickr-day.startRange, .flatpickr-day.endRange, .flatpickr-day.selected.inRange, .flatpickr-day.startRange.inRange, .flatpickr-day.endRange.inRange, .flatpickr-day.selected:focus, .flatpickr-day.startRange:focus, .flatpickr-day.endRange:focus, .flatpickr-day.selected:hover, .flatpickr-day.startRange:hover, .flatpickr-day.endRange:hover, .flatpickr-day.selected.prevMonthDay, .flatpickr-day.startRange.prevMonthDay, .flatpickr-day.endRange.prevMonthDay, .flatpickr-day.selected.nextMonthDay, .flatpickr-day.startRange.nextMonthDay, .flatpickr-day.endRange.nextMonthDay {
                background: #000 !important;
                border-color: #000 !important;
            }

            /* Toastify customization for Shadcn-like appearance */
            .toastify {
                font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif !important;
                font-size: 14px !important;
                font-weight: 500 !important;
                border-radius: 6px !important;
                box-shadow: 0 1px 3px 0 rgba(0, 0, 0, 0.1), 0 1px 2px 0 rgba(0, 0, 0, 0.06) !important;
                padding: 12px 16px !important;
                margin: 8px !important;
                min-width: 300px !important;
                max-width: 400px !important;
                line-height: 1.5 !important;
            }
            
            /* Shadcn-style black and white base */
            .toastify-shadcn {
                background: white !important;
                color: #000 !important;
                border: 1px solid #e5e5e5 !important;
            }
            
            /* Toast content layout */
            .toast-content {
                display: flex !important;
                align-items: center !important;
                gap: 12px !important;
            }
            
            /* Icon wrapper styling */
            .toast-icon-wrapper {
                width: 20px !important;
                height: 20px !important;
                border-radius: 50% !important;
                display: flex !important;
                align-items: center !important;
                justify-content: center !important;
                flex-shrink: 0 !important;
            }
            
            .toast-icon {
                font-size: 12px !important;
                font-weight: 600 !important;
                color: white !important;
            }
            
            /* Icon wrapper colors */
            .toast-icon-success {
                background: #22c55e !important;
            }
            
            .toast-icon-error {
                background: #ef4444 !important;
            }
            
            .toast-icon-info {
                background: #3b82f6 !important;
            }
            
            /* Message styling */
            .toast-message {
                flex: 1 !important;
                color: #000 !important;
            }
            
            /* Remove old notification styles */
            .stock-notification {
                display: none !important;
            }
        ';
    }
}
