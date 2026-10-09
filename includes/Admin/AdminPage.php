<?php

namespace MadeByHypeStockmanagment\Admin;

use MadeByHypeStockmanagment\Capabilities;
use MadeByHypeStockmanagment\Data\DataManager;

if (! defined('ABSPATH')) {
    exit;
}

class AdminPage
{
    const PAGE_SLUG = 'madebyhype-stockmanagment';

    const TABS = ['all', 'attention', 'history'];
    const PER_PAGE_OPTIONS = [20, 50, 100, 500];
    const DEFAULT_PER_PAGE = 50;

    private $data_manager;
    private $ui_manager;

    public function init()
    {
        // This will be called from the main Plugin class
    }

    public function set_dependencies($data_manager, $ui_manager)
    {
        $this->data_manager = $data_manager;
        $this->ui_manager = $ui_manager;
    }

    /**
     * Add custom admin menu page
     */
    public function add_admin_menu()
    {
        add_submenu_page(
            'edit.php?post_type=product', // Parent slug (WooCommerce Products)
            __('Stock Management', 'madebyhype-stockmanagment'), // Page title
            __('Stock Management', 'madebyhype-stockmanagment'), // Menu title
            Capabilities::VIEW, // Capability required to see the screen
            self::PAGE_SLUG, // Menu slug
            [$this, 'render_admin_page'] // Callback function
        );
    }

    /**
     * Reads and validates everything the screen takes from the URL
     *
     * Every 1.0.6 parameter keeps its meaning, so old bookmarks load. Anything
     * invalid falls back to its default; nothing here raises an error.
     *
     * @param array $source Normally $_GET (slashed, as WordPress hands it over)
     * @return array {
     *     tab 'all'|'attention'|'history' (default 'all'),
     *     view 'product'|'sku' (default 'product'; include_variations=1 means 'sku'; ignored on other tabs),
     *     search string (parameter s),
     *     period ''|'30'|'90'|'180'|'365'|'all', start_date, end_date ('' or Y-m-d, in order),
     *     sort_by ''|'name'|'sku'|'price'|'stock_quantity'|'total_sales'|'cover', sort_order 'ASC'|'DESC',
     *     paged int, per_page 20|50|100|500,
     *     category_filter int[], tag_filter int[], attribute_filter (pa_taxonomy => int[]),
     *     stock_filter string[] (instock, outofstock, onbackorder, lowstock, untracked),
     *     min_price float, max_price float, min_sales int, max_sales int,
     *     include_drafts bool, sold_only bool, attention 'all'|'out'|'low'|'backorder',
     *     item int, user int (History tab)
     * }
     */
    public function parse_request($source)
    {
        $source = is_array($source) ? wp_unslash($source) : [];

        $text = function ($key) use ($source) {
            return isset($source[$key]) && is_scalar($source[$key]) ? sanitize_text_field((string) $source[$key]) : '';
        };
        $flag = function ($key) use ($source) {
            return isset($source[$key]) && is_scalar($source[$key]) && (bool) $source[$key];
        };
        $ids = function ($key) use ($source) {
            $values = isset($source[$key]) && is_array($source[$key]) ? $source[$key] : [];
            $values = array_map('absint', array_filter($values, 'is_scalar'));

            return array_values(array_unique(array_filter($values)));
        };

        $request = [];

        // Tab and view. All stock grouped by product is the default.
        $tab = $text('tab');
        $request['tab'] = in_array($tab, self::TABS, true) ? $tab : 'all';

        $view = $text('view');
        if (!in_array($view, [DataManager::VIEW_PRODUCT, DataManager::VIEW_SKU], true)) {
            $view = $flag('include_variations') ? DataManager::VIEW_SKU : DataManager::VIEW_PRODUCT;
        }
        $request['view'] = $view;

        $request['search'] = trim($text('s'));

        // Sales period: a rolling period, or a fixed one from the two dates
        $period = $text('period');
        $request['period'] = in_array($period, DataManager::PERIODS, true) ? $period : '';

        $start_date = $this->valid_date($text('start_date'));
        $end_date = $this->valid_date($text('end_date'));
        if ($start_date !== '' && $end_date !== '' && $start_date > $end_date) {
            // Swap dates if in wrong order
            $swap = $start_date;
            $start_date = $end_date;
            $end_date = $swap;
        }
        $request['start_date'] = $start_date;
        $request['end_date'] = $end_date;

        // Sorting: columns and directions are whitelisted
        $sort_by = $text('sort_by');
        $request['sort_by'] = in_array($sort_by, DataManager::SORT_FIELDS, true) ? $sort_by : '';
        $request['sort_order'] = strtoupper($text('sort_order')) === 'ASC' ? 'ASC' : 'DESC';

        // Paging. An unknown page size becomes 100 when larger than 100, else the default.
        $request['paged'] = isset($source['paged']) && is_scalar($source['paged']) ? max(1, (int) $source['paged']) : 1;

        $per_page = isset($source['per_page']) && is_scalar($source['per_page']) ? (int) $source['per_page'] : self::DEFAULT_PER_PAGE;
        if (!in_array($per_page, self::PER_PAGE_OPTIONS, true)) {
            $per_page = $per_page > 100 ? 100 : self::DEFAULT_PER_PAGE;
        }
        $request['per_page'] = $per_page;

        // Filters
        $request['category_filter'] = $ids('category_filter');
        $request['tag_filter'] = $ids('tag_filter');

        // Attribute filter structure: attribute_filter[pa_color] = [term_id, ...]
        $request['attribute_filter'] = [];
        $raw_attributes = isset($source['attribute_filter']) && is_array($source['attribute_filter']) ? $source['attribute_filter'] : [];
        foreach ($raw_attributes as $taxonomy => $terms) {
            $taxonomy = sanitize_text_field((string) $taxonomy);
            // Only allow product attribute taxonomies (pa_ prefix)
            if (strpos($taxonomy, 'pa_') !== 0 || !is_array($terms)) {
                continue;
            }
            $terms = array_values(array_unique(array_filter(array_map('absint', array_filter($terms, 'is_scalar')))));
            if ($terms) {
                $request['attribute_filter'][$taxonomy] = $terms;
            }
        }

        $stock_filter = isset($source['stock_filter']) && is_array($source['stock_filter']) ? array_filter($source['stock_filter'], 'is_string') : [];
        $request['stock_filter'] = array_values(array_intersect(DataManager::STOCK_FILTERS, $stock_filter));

        $number = function ($key) use ($source) {
            return isset($source[$key]) && is_scalar($source[$key]) ? max(0, (float) $source[$key]) : 0;
        };
        $request['min_price'] = $number('min_price');
        $request['max_price'] = $number('max_price');
        $request['min_sales'] = (int) $number('min_sales');
        $request['max_sales'] = (int) $number('max_sales');

        $request['include_drafts'] = $flag('include_drafts');

        // Needs attention
        $request['sold_only'] = $flag('sold_only');
        $attention = $text('attention');
        $request['attention'] = in_array($attention, DataManager::ATTENTION_FILTERS, true) ? $attention : 'all';

        // History
        $request['item'] = isset($source['item']) && is_scalar($source['item']) ? absint($source['item']) : 0;
        $request['user'] = isset($source['user']) && is_scalar($source['user']) ? absint($source['user']) : 0;

        return $request;
    }

    /**
     * The arguments of DataManager::get_list() (and get_products()) for a parsed request
     *
     * @param array $request From parse_request()
     * @return array
     */
    public function list_args($request)
    {
        return [
            'tab' => $request['tab'] === DataManager::TAB_ATTENTION ? DataManager::TAB_ATTENTION : DataManager::TAB_ALL,
            'view' => $request['view'],
            'search' => $request['search'],
            'period' => $request['period'],
            'start_date' => $request['start_date'],
            'end_date' => $request['end_date'],
            'sort_by' => $request['sort_by'],
            'sort_order' => $request['sort_order'],
            'page' => $request['paged'],
            'per_page' => $request['per_page'],
            'category_filter' => $request['category_filter'],
            'tag_filter' => $request['tag_filter'],
            'attribute_filter' => $request['attribute_filter'],
            'stock_filter' => $request['stock_filter'],
            'min_price' => $request['min_price'],
            'max_price' => $request['max_price'],
            'min_sales' => $request['min_sales'],
            'max_sales' => $request['max_sales'],
            'include_drafts' => $request['include_drafts'],
            'sold_only' => $request['sold_only'],
            'attention' => $request['attention'],
        ];
    }

    private function valid_date($value)
    {
        // Validate date format (Y-m-d) and that the day exists
        if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m) || !checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            return '';
        }

        return $value;
    }

    /**
     * Render the admin page content
     */
    public function render_admin_page()
    {
        $request = $this->parse_request($_GET);

        // TRANSITION: the 1.0.6 templates read the old row shape, so the list
        // comes through DataManager::get_products(). Switch to get_list() with
        // the same arguments when the templates read its rows.
        $result = $this->data_manager->get_products($this->list_args($request));

        // A list that could not be read is not an empty list
        if (!empty($result['error'])) {
            echo '<div class="notice notice-error"><p>' . esc_html($result['error']) . '</p></div>';
        }

        $filter_applied = $request['start_date'] !== '' && $request['end_date'] !== '';

        // Render the page using UI manager
        $this->ui_manager->render_admin_page(
            $result['products'],
            $result['total_count'],
            $result['total_pages'],
            $result['current_page'],
            $result['per_page'],
            $request['start_date'],
            $request['end_date'],
            $filter_applied,
            $request['sort_by'],
            $request['sort_order'],
            $request['category_filter'],
            $request['tag_filter'],
            $request['attribute_filter'],
            $request['stock_filter'],
            $request['min_price'],
            $request['max_price'],
            $request['min_sales'],
            $request['max_sales'],
            $request['view'] === DataManager::VIEW_SKU
        );
    }
}
