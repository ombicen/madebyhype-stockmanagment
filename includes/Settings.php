<?php

namespace MadeByHypeStockmanagment;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * The plugin's settings: what they are, what they default to, and the page
 * they are changed on.
 *
 * The page is a section of WooCommerce's own settings, under Products beside
 * Inventory: WooCommerce prints the form, checks the permission and stores
 * each field as an option of its own. The rest of the plugin asks the
 * getters here and never reads an option itself; a getter answers with a
 * value that is safe to use whatever is stored.
 */
class Settings
{
    const SECTION = 'madebyhype_stock';

    const OPTION_PER_PAGE = 'madebyhype_stock_per_page';
    const OPTION_PERIOD = 'madebyhype_stock_period';
    const OPTION_RETENTION = 'madebyhype_stock_retention_months';
    const OPTION_BULK_MAX = 'madebyhype_stock_bulk_max_items';
    const OPTION_KEEP_DATA = 'madebyhype_stock_keep_data';

    const OPTIONS = [
        self::OPTION_PER_PAGE,
        self::OPTION_PERIOD,
        self::OPTION_RETENTION,
        self::OPTION_BULK_MAX,
        self::OPTION_KEEP_DATA,
    ];

    // What applies until something else is chosen
    const DEFAULT_PER_PAGE = 50;
    const DEFAULT_PERIOD = '30';
    const DEFAULT_RETENTION_MONTHS = 12;
    const DEFAULT_BULK_MAX = 50000;

    const PER_PAGE_CHOICES = [20, 50, 100, 500];
    const PERIOD_CHOICES = ['30', '90', '180', '365', 'all'];

    private $plugin_file;

    /**
     * @param string $plugin_file Main plugin file
     */
    public function __construct($plugin_file)
    {
        $this->plugin_file = $plugin_file;
    }

    /**
     * wp-admin only: the section, its fields, and the links that lead to it
     */
    public function init()
    {
        add_filter('woocommerce_get_sections_products', [$this, 'add_section']);
        add_filter('woocommerce_get_settings_products', [$this, 'fields'], 10, 2);
        add_filter('plugin_action_links_' . plugin_basename($this->plugin_file), [$this, 'plugin_links']);
    }

    /* ---------------------------------------------------------------------
     * What the plugin asks
     * ------------------------------------------------------------------- */

    /**
     * Rows a page of the list shows when the address does not say
     *
     * @return int One of PER_PAGE_CHOICES
     */
    public static function per_page()
    {
        $value = (int) get_option(self::OPTION_PER_PAGE, self::DEFAULT_PER_PAGE);

        return in_array($value, self::PER_PAGE_CHOICES, true) ? $value : self::DEFAULT_PER_PAGE;
    }

    /**
     * The period units sold are counted over when the address does not say
     *
     * @return string One of PERIOD_CHOICES
     */
    public static function period()
    {
        $value = (string) get_option(self::OPTION_PERIOD, self::DEFAULT_PERIOD);

        return in_array($value, self::PERIOD_CHOICES, true) ? $value : self::DEFAULT_PERIOD;
    }

    /**
     * How long History is kept
     *
     * @return int Months, 1 to 120
     */
    public static function retention_months()
    {
        $value = (int) get_option(self::OPTION_RETENTION, self::DEFAULT_RETENTION_MONTHS);

        return $value >= 1 ? min(120, $value) : self::DEFAULT_RETENTION_MONTHS;
    }

    /**
     * Most items one bulk price change takes
     *
     * @return int 100 to 500000
     */
    public static function bulk_max_items()
    {
        $value = (int) get_option(self::OPTION_BULK_MAX, self::DEFAULT_BULK_MAX);

        return $value >= 100 ? min(500000, $value) : self::DEFAULT_BULK_MAX;
    }

    /**
     * Whether History and these settings stay when the plugin is deleted
     *
     * @return bool
     */
    public static function keep_data()
    {
        return get_option(self::OPTION_KEEP_DATA, 'no') === 'yes';
    }

    /**
     * Address of the settings page
     *
     * @return string Not escaped
     */
    public static function url()
    {
        return admin_url('admin.php?page=wc-settings&tab=products&section=' . self::SECTION);
    }

    /* ---------------------------------------------------------------------
     * The page
     * ------------------------------------------------------------------- */

    /**
     * @param array $sections Section id => label, of WooCommerce > Settings > Products
     * @return array
     */
    public function add_section($sections)
    {
        $sections[self::SECTION] = __('Stock Management', 'madebyhype-stockmanagment');

        return $sections;
    }

    /**
     * @param array  $settings Fields of the section being shown
     * @param string $section
     * @return array
     */
    public function fields($settings, $section = '')
    {
        if ($section !== self::SECTION) {
            return $settings;
        }

        return [
            [
                'title' => __('The stock list', 'madebyhype-stockmanagment'),
                'type' => 'title',
                'desc' => sprintf(
                    /* translators: %s: link to the Stock Management screen */
                    __('How %s opens. Anyone can still choose otherwise on the screen itself.', 'madebyhype-stockmanagment'),
                    '<a href="' . esc_url(admin_url('edit.php?post_type=product&page=madebyhype-stockmanagment')) . '">' . esc_html__('Stock Management', 'madebyhype-stockmanagment') . '</a>'
                ),
                'id' => 'madebyhype_stock_list',
            ],
            [
                'title' => __('Rows per page', 'madebyhype-stockmanagment'),
                'id' => self::OPTION_PER_PAGE,
                'type' => 'select',
                'default' => (string) self::DEFAULT_PER_PAGE,
                'options' => array_combine(array_map('strval', self::PER_PAGE_CHOICES), array_map('strval', self::PER_PAGE_CHOICES)),
                'desc_tip' => __('More rows mean fewer pages and a slower one.', 'madebyhype-stockmanagment'),
            ],
            [
                'title' => __('Sales period', 'madebyhype-stockmanagment'),
                'id' => self::OPTION_PERIOD,
                'type' => 'select',
                'default' => self::DEFAULT_PERIOD,
                'options' => [
                    '30' => __('Last 30 days', 'madebyhype-stockmanagment'),
                    '90' => __('Last 90 days', 'madebyhype-stockmanagment'),
                    '180' => __('Last 180 days', 'madebyhype-stockmanagment'),
                    '365' => __('Last 365 days', 'madebyhype-stockmanagment'),
                    'all' => __('All time', 'madebyhype-stockmanagment'),
                ],
                'desc_tip' => __('What "Sold" and "Cover" are counted over.', 'madebyhype-stockmanagment'),
            ],
            ['type' => 'sectionend', 'id' => 'madebyhype_stock_list'],

            [
                'title' => __('History', 'madebyhype-stockmanagment'),
                'type' => 'title',
                'id' => 'madebyhype_stock_history',
            ],
            [
                'title' => __('Keep History for', 'madebyhype-stockmanagment'),
                'id' => self::OPTION_RETENTION,
                'type' => 'number',
                'default' => (string) self::DEFAULT_RETENTION_MONTHS,
                'css' => 'width: 80px;',
                'custom_attributes' => ['min' => 1, 'max' => 120, 'step' => 1],
                'desc' => __('months. Older saves are removed once a day, and can then no longer be undone.', 'madebyhype-stockmanagment'),
            ],
            ['type' => 'sectionend', 'id' => 'madebyhype_stock_history'],

            [
                'title' => __('Bulk price change', 'madebyhype-stockmanagment'),
                'type' => 'title',
                'id' => 'madebyhype_stock_bulk',
            ],
            [
                'title' => __('Largest bulk change', 'madebyhype-stockmanagment'),
                'id' => self::OPTION_BULK_MAX,
                'type' => 'number',
                'default' => (string) self::DEFAULT_BULK_MAX,
                'css' => 'width: 120px;',
                'custom_attributes' => ['min' => 100, 'max' => 500000, 'step' => 100],
                'desc' => __('items. A change that would touch more is refused, with the advice to narrow the list first. Variations count one each.', 'madebyhype-stockmanagment'),
            ],
            ['type' => 'sectionend', 'id' => 'madebyhype_stock_bulk'],

            [
                'title' => __('When the plugin is deleted', 'madebyhype-stockmanagment'),
                'type' => 'title',
                'id' => 'madebyhype_stock_delete',
            ],
            [
                'title' => __('Data', 'madebyhype-stockmanagment'),
                'id' => self::OPTION_KEEP_DATA,
                'type' => 'checkbox',
                'default' => 'no',
                'desc' => __('Keep History and these settings', 'madebyhype-stockmanagment'),
                'desc_tip' => __('Unticked, deleting the plugin from the Plugins screen removes everything it stored. Products, stock and prices are never touched either way.', 'madebyhype-stockmanagment'),
            ],
            ['type' => 'sectionend', 'id' => 'madebyhype_stock_delete'],
        ];
    }

    /**
     * "Settings" beside Deactivate on the Plugins screen
     *
     * @param array $links
     * @return array
     */
    public function plugin_links($links)
    {
        if (!class_exists('WooCommerce')) {
            return $links;
        }

        array_unshift($links, '<a href="' . esc_url(self::url()) . '">' . esc_html__('Settings', 'madebyhype-stockmanagment') . '</a>');

        return $links;
    }
}
