<?php

namespace MadeByHypeStockmanagment\Assets;

use MadeByHypeStockmanagment\Admin\AdminPage;
use MadeByHypeStockmanagment\Admin\AjaxHandler;
use MadeByHypeStockmanagment\UI\UIManager;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Styles and scripts of the stock screen.
 *
 * Loaded on that screen only, and versioned by each file's modification time,
 * so a browser never keeps running an older copy after an update.
 */
class AssetsManager
{
    // The object the scripts read their settings, permissions and strings from
    const SCRIPT_DATA = 'madebyhypeStockData';

    public function init()
    {
        // Initialize assets manager
    }

    /**
     * Enqueue admin scripts and styles
     *
     * @param string $hook        Hook suffix of the admin page being loaded
     * @param string $plugin_file Main plugin file
     */
    public function enqueue_admin_scripts($hook, $plugin_file)
    {
        // The stock screen is recognised by the hook WordPress gave it when the menu was registered
        $screen = AdminPage::hook_suffix();

        if ($screen === '' || $hook !== $screen) {
            return;
        }

        $this->style('madebyhype-stock-screen', 'includes/UI/styles/stock-screen.css', [], $plugin_file);

        // The rules (no DOM), then what every tab shares, then History, the grid, its filter drawer and the bulk price change
        $this->script('madebyhype-stock-model', 'includes/UI/scripts/stock-model.js', [], $plugin_file);
        $this->script('madebyhype-stock-core', 'includes/UI/scripts/stock-core.js', ['jquery', 'heartbeat', 'madebyhype-stock-model'], $plugin_file);
        $this->script('madebyhype-stock-history', 'includes/UI/scripts/stock-history.js', ['madebyhype-stock-core'], $plugin_file);
        $this->script('madebyhype-stock-grid', 'includes/UI/scripts/stock-grid.js', ['madebyhype-stock-core', 'madebyhype-stock-history'], $plugin_file);
        $this->script('madebyhype-stock-filters', 'includes/UI/scripts/stock-filters.js', ['madebyhype-stock-core', 'madebyhype-stock-grid'], $plugin_file);
        $this->script('madebyhype-stock-bulk', 'includes/UI/scripts/stock-bulk.js', ['madebyhype-stock-core', 'madebyhype-stock-history', 'madebyhype-stock-grid'], $plugin_file);

        wp_localize_script('madebyhype-stock-core', self::SCRIPT_DATA, $this->script_data());
    }

    /**
     * Settings, nonces, permissions and strings for the scripts
     *
     * @return array
     */
    private function script_data()
    {
        // Each nonce only for a user who may use it; refreshed later through Heartbeat
        return [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonces' => AjaxHandler::nonces(),
            'heartbeatKey' => AjaxHandler::HEARTBEAT_KEY,
            'caps' => AdminPage::permissions(),
            'format' => UIManager::price_format(),
            // Counted strings in every plural form of the page's language, and which form a count takes
            'strings' => UIManager::script_strings(),
            // The paths of the icons, so the scripts draw the ones the templates print
            'icons' => UIManager::ICONS,
            'plural' => UIManager::plural_rule()['table'],
        ];
    }

    private function style($handle, $path, $deps, $plugin_file)
    {
        wp_enqueue_style($handle, plugins_url($path, $plugin_file), $deps, $this->version($path, $plugin_file));
    }

    private function script($handle, $path, $deps, $plugin_file)
    {
        wp_enqueue_script($handle, plugins_url($path, $plugin_file), $deps, $this->version($path, $plugin_file), true);
    }

    /**
     * The version every enqueued file carries: when it was last changed
     *
     * @return string
     */
    private function version($path, $plugin_file)
    {
        $modified = @filemtime(plugin_dir_path($plugin_file) . $path);

        return $modified ? (string) $modified : '0';
    }
}
