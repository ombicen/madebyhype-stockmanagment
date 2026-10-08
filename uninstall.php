<?php

/**
 * Runs when the plugin is deleted from the Plugins screen (not on
 * deactivation). Removes everything the plugin stored: the change log, the
 * legacy version history, its options, its cached lookups and its scheduled
 * clean-up. Products are not touched.
 *
 * @package MadeByHypeStockmanagment
 */

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

require_once __DIR__ . '/includes/Data/Schema.php';

\MadeByHypeStockmanagment\Data\Schema::uninstall();
