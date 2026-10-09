<?php

namespace MadeByHypeStockmanagment;

if (! defined('ABSPATH')) {
    exit;
}

// Needed before WooCommerce is known to be there: activation and schema upgrades
require_once plugin_dir_path(__FILE__) . 'Data/Schema.php';

class Plugin
{
    private $admin_page;
    private $data_manager;
    private $ui_manager;
    private $assets_manager;
    private $ajax_handler;
    private $read_ajax_handler;
    private $bulk_ajax_handler;
    private $change_log;
    private $write_service;
    private $plugin_file;

    public function __construct($plugin_file)
    {
        $this->plugin_file = $plugin_file;
    }

    /**
     * Plugin activation: create the tables. An update of an already active
     * plugin does not come through here; Schema::maybe_upgrade() covers that.
     */
    public static function activate()
    {
        Data\Schema::install();
    }

    /**
     * Plugin deactivation: stop the scheduled clean-up. Data is kept.
     */
    public static function deactivate()
    {
        wp_clear_scheduled_hook(Data\Schema::PRUNE_HOOK);
    }

    /**
     * Runs on plugins_loaded, in every context.
     *
     * On a storefront request (a page view, the cart, checkout, REST) this
     * plugin does two things and nothing else: the HPOS declaration and the
     * schema version comparison. Everything else is decided in init_plugin().
     */
    public function run()
    {
        // Declare HPOS compatibility (needs to run always)
        add_action('before_woocommerce_init', [$this, 'declare_hpos_compatibility']);

        // One comparison against an autoloaded option; does work only when the
        // schema is behind. Before init_plugin, so the tables exist when it runs.
        add_action('init', [Data\Schema::class, 'maybe_upgrade'], 5);

        add_action('init', [$this, 'init_plugin']);

        // The menu and the assets are wp-admin only
        if (is_admin()) {
            add_action('admin_menu', [$this, 'add_admin_menu']);
            add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_scripts']);
        }
    }

    public function init_plugin()
    {
        // Translations, where this plugin shows words: wp-admin (with admin-ajax) and cron
        if ($this->core_runs_here()) {
            load_plugin_textdomain('madebyhype-stockmanagment', false, dirname(plugin_basename($this->plugin_file)) . '/languages');
        }

        // Check if WooCommerce is active
        if (!class_exists('WooCommerce')) {
            if (is_admin()) {
                add_action('admin_notices', [$this, 'woocommerce_missing_notice']);
            }
            return;
        }

        if (!$this->core_runs_here()) {
            return;
        }

        $this->load_core();
        $this->register_core_hooks();

        // wp-admin and admin-ajax only
        if (is_admin()) {
            $this->load_admin();
            $this->admin_page->init();
            $this->data_manager->init();
            $this->ui_manager->init();
            $this->assets_manager->init();
            $this->ajax_handler->init();
            $this->read_ajax_handler->init();
            $this->bulk_ajax_handler->init();
        }
    }

    /**
     * Where the core (change log, write service, scheduled clean-up) is loaded
     *
     * wp-admin, which includes admin-ajax, and cron runs, so the daily prune
     * fires. Not the storefront: changes made outside this tool are not
     * recorded, so nothing of ours has a reason to run there. Returning true
     * here is all it takes to load the core in every context again.
     *
     * @return bool
     */
    private function core_runs_here()
    {
        return is_admin() || wp_doing_cron();
    }

    /**
     * The permission helper, the change log and the write service. Nothing in
     * here depends on wp-admin.
     */
    private function load_core()
    {
        require_once plugin_dir_path(__FILE__) . 'Capabilities.php';
        require_once plugin_dir_path(__FILE__) . 'Data/ChangeLog.php';
        require_once plugin_dir_path(__FILE__) . 'Data/WriteService.php';

        $this->change_log = new Data\ChangeLog();
        $this->write_service = new Data\WriteService($this->change_log);
    }

    /**
     * Hooks of the core that are not tied to the admin screen: the daily
     * clean-up of the change log, and keeping it scheduled
     */
    private function register_core_hooks()
    {
        add_action(Data\Schema::PRUNE_HOOK, [$this, 'prune_change_log']);

        if (!wp_next_scheduled(Data\Schema::PRUNE_HOOK)) {
            wp_schedule_event(time() + HOUR_IN_SECONDS, 'daily', Data\Schema::PRUNE_HOOK);
        }
    }

    /**
     * The admin screen and its AJAX transport
     */
    private function load_admin()
    {
        require_once plugin_dir_path(__FILE__) . 'Admin/AdminPage.php';
        require_once plugin_dir_path(__FILE__) . 'Admin/AjaxHandler.php';
        require_once plugin_dir_path(__FILE__) . 'Admin/ReadAjaxHandler.php';
        require_once plugin_dir_path(__FILE__) . 'Admin/BulkAjaxHandler.php';
        require_once plugin_dir_path(__FILE__) . 'Data/DataManager.php';
        require_once plugin_dir_path(__FILE__) . 'Data/BulkPriceRule.php';
        require_once plugin_dir_path(__FILE__) . 'Data/BulkPriceService.php';
        require_once plugin_dir_path(__FILE__) . 'UI/UIManager.php';
        require_once plugin_dir_path(__FILE__) . 'Assets/AssetsManager.php';

        $this->data_manager = new Data\DataManager();
        $this->ui_manager = new UI\UIManager();
        $this->assets_manager = new Assets\AssetsManager();
        $this->admin_page = new Admin\AdminPage();
        $this->ajax_handler = new Admin\AjaxHandler($this->write_service, $this->change_log);
        $this->read_ajax_handler = new Admin\ReadAjaxHandler($this->data_manager);
        $this->bulk_ajax_handler = new Admin\BulkAjaxHandler(new Data\BulkPriceService($this->data_manager, $this->write_service));

        // Set dependencies
        $this->admin_page->set_dependencies($this->data_manager, $this->ui_manager);
    }

    /**
     * Daily clean-up of change-log entries past the retention period
     */
    public function prune_change_log()
    {
        $months = (int) apply_filters('madebyhype_stock_log_retention_months', Data\ChangeLog::DEFAULT_RETENTION_MONTHS);

        $this->change_log->prune($months);
    }

    /**
     * Add custom admin menu page
     */
    public function add_admin_menu()
    {
        // Components are only created once WooCommerce is confirmed active
        if (!$this->admin_page) {
            return;
        }

        $this->admin_page->add_admin_menu();
    }

    /**
     * Enqueue admin scripts and styles
     */
    public function enqueue_admin_scripts($hook)
    {
        if (!$this->assets_manager) {
            return;
        }

        $this->assets_manager->enqueue_admin_scripts($hook, $this->plugin_file);
    }

    /**
     * Get data manager instance
     */
    public function get_data_manager()
    {
        return $this->data_manager;
    }

    /**
     * Get UI manager instance
     */
    public function get_ui_manager()
    {
        return $this->ui_manager;
    }

    /**
     * Get the write service: the one way to change products from this plugin
     */
    public function get_write_service()
    {
        return $this->write_service;
    }

    /**
     * Get the change log
     */
    public function get_change_log()
    {
        return $this->change_log;
    }

    /**
     * Declare HPOS (High-Performance Order Storage) compatibility
     */
    public function declare_hpos_compatibility()
    {
        if (class_exists('\Automattic\WooCommerce\Utilities\FeaturesUtil')) {
            \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
                'custom_order_tables',
                $this->plugin_file,
                true
            );
        }
    }

    /**
     * Display notice if WooCommerce is not active
     */
    public function woocommerce_missing_notice()
    {
?>
        <div class="notice notice-error">
            <p><?php _e('MadeByHype Stock Management requires WooCommerce to be installed and activated.', 'madebyhype-stockmanagment'); ?>
            </p>
        </div>
<?php
    }
}
