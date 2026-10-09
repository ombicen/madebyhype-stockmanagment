<?php

/**
 * Plugin Name: MadeByHype Stock Management
 * Plugin URI: https://madebyhype.se/
 * Description: Advanced WooCommerce stock management plugin with version control, bulk editing, and comprehensive reporting. Features include real-time stock tracking, price management, variation support, and detailed sales analytics with customizable date filters.
 * Version: 1.2.0
 * Requires at least: 5.0
 * Tested up to: 6.4
 * Requires PHP: 7.4
 * Requires Plugins: woocommerce
 * Author: MadebyHype
 * Author URI: https://madebyhype.se/
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: madebyhype-stockmanagment
 * Domain Path: /languages
 * Network: false
 * Update URI: https://github.com/ombicen/madebyhype-stockmanagment
 * 
 * WC requires at least: 6.0
 * WC tested up to: 9.8.5
 * 
 * @package MadeByHypeStockmanagment
 * @author MadebyHype
 * @version 1.0.0
 */

if (! defined('ABSPATH')) {
    exit; // Stop direct access
}

require_once plugin_dir_path(__FILE__) . 'includes/Plugin.php';

// Table lifecycle. Removal of all data on delete is in uninstall.php
register_activation_hook(__FILE__, ['\MadeByHypeStockmanagment\Plugin', 'activate']);
register_deactivation_hook(__FILE__, ['\MadeByHypeStockmanagment\Plugin', 'deactivate']);

// Initialize the plugin
function madebyhype_stockmanagment_init()
{
    $plugin = new \MadeByHypeStockmanagment\Plugin(__FILE__);
    $plugin->run();
}
add_action('plugins_loaded', 'madebyhype_stockmanagment_init');