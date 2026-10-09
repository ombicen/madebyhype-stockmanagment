<?php

/**
 * Runs when the plugin is deleted from the Plugins screen (not on
 * deactivation). Unless the plugin is set to keep its data, removes
 * everything the plugin stored: the change log, the
 * legacy version history, its options, its cached lookups and its scheduled
 * clean-up. Products are not touched.
 *
 * @package MadeByHypeStockmanagment
 */

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

require_once __DIR__ . '/includes/Data/Schema.php';
require_once __DIR__ . '/includes/Settings.php';

// Set to keep its data: History and the settings stay for a later reinstall
if (\MadeByHypeStockmanagment\Settings::keep_data()) {
    wp_clear_scheduled_hook(\MadeByHypeStockmanagment\Data\Schema::PRUNE_HOOK);
    return;
}

\MadeByHypeStockmanagment\Data\Schema::uninstall();

foreach (\MadeByHypeStockmanagment\Settings::OPTIONS as $option) {
    delete_option($option);
}
