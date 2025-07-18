<?php

/**
 * Plugin Name: Omer Simple stock managment
 * Description: A basic WordPress plugin structure using OOP.
 * Version: 1.0
 * Author: Ömer
 */

if (! defined('ABSPATH')) {
    exit; // Stop direct access
}

require_once plugin_dir_path(__FILE__) . 'includes/Plugin.php';

// Initialize the plugin
function omer_stockmanagment_init()
{
    $plugin = new \OmerStockhmanagment\Plugin();
    $plugin->run();
}
add_action('plugins_loaded', 'omer_stockmanagment_init');