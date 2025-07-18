<?php

namespace OmerStockhmanagment;

if (! defined('ABSPATH')) {
    exit;
}

class Plugin
{
    private $admin_page;
    private $data_manager;
    private $ui_manager;
    private $assets_manager;
    private $ajax_handler;

    public function __construct()
    {
        $this->load_dependencies();
    }

    public function run()
    {
        add_action('init', [$this, 'init_plugin']);
        add_action('admin_menu', [$this, 'add_admin_menu']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_scripts']);
    }

    private function load_dependencies()
    {
        // Load all modular components
        require_once plugin_dir_path(__FILE__) . 'Admin/AdminPage.php';
        require_once plugin_dir_path(__FILE__) . 'Admin/AjaxHandler.php';
        require_once plugin_dir_path(__FILE__) . 'Data/DataManager.php';
        require_once plugin_dir_path(__FILE__) . 'UI/UIManager.php';
        require_once plugin_dir_path(__FILE__) . 'Assets/AssetsManager.php';

        $this->data_manager = new Data\DataManager();
        $this->ui_manager = new UI\UIManager();
        $this->assets_manager = new Assets\AssetsManager();
        $this->admin_page = new Admin\AdminPage();
        $this->ajax_handler = new Admin\AjaxHandler();

        // Set dependencies
        $this->admin_page->set_dependencies($this->data_manager, $this->ui_manager);
    }

    public function init_plugin()
    {
        // Initialize components
        $this->admin_page->init();
        $this->data_manager->init();
        $this->ui_manager->init();
        $this->assets_manager->init();
        $this->ajax_handler->init();
    }

    /**
     * Add custom admin menu page
     */
    public function add_admin_menu()
    {
        $this->admin_page->add_admin_menu();
    }

    /**
     * Enqueue admin scripts and styles
     */
    public function enqueue_admin_scripts($hook)
    {
        $this->assets_manager->enqueue_admin_scripts($hook);
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
}