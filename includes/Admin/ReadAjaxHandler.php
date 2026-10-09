<?php

namespace MadeByHypeStockmanagment\Admin;

use MadeByHypeStockmanagment\Capabilities;
use MadeByHypeStockmanagment\Data\DataManager;
use MadeByHypeStockmanagment\UI\UIManager;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * AJAX transport of the stock screen's reads: nonce, permission, call the
 * data manager, encode. No query logic lives here and nothing is written.
 */
class ReadAjaxHandler
{
    const NONCE_ACTION = 'madebyhype_stock_read_nonce';
    const ACTION_VARIATIONS = 'madebyhype_get_variations';
    const ACTION_ROWS = 'madebyhype_get_rows';
    const ACTION_LIST = 'madebyhype_get_list';
    const ACTION_FILTER_OPTIONS = 'madebyhype_get_filter_options';
    const ACTION_CATEGORY_COUNTS = 'madebyhype_get_category_counts';

    // Longest query string the list read takes; a URL of the screen with every filter set is far shorter
    const MAX_QUERY_LENGTH = 8000;

    private $data_manager;

    /**
     * @param \MadeByHypeStockmanagment\Data\DataManager $data_manager
     */
    public function __construct($data_manager)
    {
        $this->data_manager = $data_manager;
    }

    public function init()
    {
        // Logged-in users only: there is no wp_ajax_nopriv_ hook
        add_action('wp_ajax_' . self::ACTION_VARIATIONS, [$this, 'get_variations']);
        add_action('wp_ajax_' . self::ACTION_ROWS, [$this, 'get_rows']);
        add_action('wp_ajax_' . self::ACTION_LIST, [$this, 'get_list']);
        add_action('wp_ajax_' . self::ACTION_FILTER_OPTIONS, [$this, 'get_filter_options']);
        add_action('wp_ajax_' . self::ACTION_CATEGORY_COUNTS, [$this, 'get_category_counts']);
    }

    /**
     * One page of the list and everything the screen shows around it, for
     * sorting, paging, searching and filtering without a page reload
     *
     * Request (GET or POST): action=madebyhype_get_list, _wpnonce, and
     *   query       the query string of the screen's URL for the wanted view
     *               (tab, view, s, period or start_date and end_date, sort_by,
     *               sort_order, paged, per_page and the filters). It is read by
     *               AdminPage::parse_request(), the function that reads the URL
     *               of the page itself, so the answer is what a reload of that
     *               URL would print. Anything invalid falls back to its default.
     *   count_only  1: only count what the query would list
     *
     * Success: {success: true, data: {view, search, periodArgs, rows: [row, ...], urls, list: {...}}}
     *          list is UIManager::list_frame(): counts, labels, links, chips,
     *          headings, pager, the selected filters and the canonical URL.
     *          With count_only: {success: true, data: {total: int, label: "Show 214 products"}}
     * Failure: {success: false, data: {code, message}} with HTTP 403 (forbidden),
     *          400 (invalid_request: no query, a query that is too long, or the
     *          History tab) or 500 (db_error).
     * A missing or expired nonce is answered by WordPress itself: HTTP 403, body -1.
     */
    public function get_list()
    {
        check_ajax_referer(self::NONCE_ACTION);

        if (!Capabilities::can_view()) {
            wp_send_json_error([
                'code' => 'forbidden',
                'message' => __('You do not have permission to view stock.', 'madebyhype-stockmanagment'),
            ], 403);
            return;
        }

        $request = wp_unslash($_REQUEST);
        $query = isset($request['query']) && is_string($request['query']) ? ltrim($request['query'], '?') : null;

        if ($query === null || strlen($query) > self::MAX_QUERY_LENGTH) {
            wp_send_json_error([
                'code' => 'invalid_request',
                'message' => __('The list that was asked for could not be read.', 'madebyhype-stockmanagment'),
            ], 400);
            return;
        }

        require_once __DIR__ . '/AdminPage.php';
        require_once dirname(__DIR__) . '/UI/UIManager.php';

        $args = [];
        parse_str($query, $args);

        // The same reading as the URL of the page gets; it expects slashed input, as $_GET is
        $admin_page = new AdminPage();
        $parsed = $admin_page->parse_request(wp_slash($args));

        if ($parsed['tab'] === 'history') {
            wp_send_json_error([
                'code' => 'invalid_request',
                'message' => __('The list that was asked for could not be read.', 'madebyhype-stockmanagment'),
            ], 400);
            return;
        }

        $list_args = $admin_page->list_args($parsed);

        if (!empty($request['count_only'])) {
            $count = $this->data_manager->count_list($list_args);

            if ($count['error']) {
                wp_send_json_error($count['error'], 500);
                return;
            }

            wp_send_json_success([
                'total' => (int) $count['total_count'],
                'label' => UIManager::show_count_label((int) $count['total_count'], $count['view']),
            ]);
            return;
        }

        $result = $this->data_manager->get_list($list_args);

        if ($result['error']) {
            wp_send_json_error($result['error'], 500);
            return;
        }

        $ui_manager = new UIManager();

        wp_send_json_success($ui_manager->list_payload([
            'request' => $parsed,
            'result' => $result,
            'period' => $result['period'],
            'caps' => AdminPage::permissions(),
            // Only when this list has counted it anyway; the tab keeps its number otherwise
            'attention_count' => AdminPage::known_attention_count($parsed, $result),
        ]));
    }

    /**
     * What the filter drawer offers: categories, tags, and the attributes
     * with their values. Read when the drawer is first opened.
     *
     * Request (GET or POST): action=madebyhype_get_filter_options, _wpnonce.
     *
     * Success: {success: true, data: {
     *              categories: [{id, name, parent, count}, ...],
     *              tags: [{id, name, count}, ...],
     *              attributes: [{taxonomy, label, terms: [{id, name}, ...]}, ...]}}
     *          count is the number of published products (see DataManager::get_filter_options()).
     * Failure: {success: false, data: {code, message}} with HTTP 403 (forbidden) or 500 (db_error).
     * A missing or expired nonce is answered by WordPress itself: HTTP 403, body -1.
     */
    public function get_filter_options()
    {
        check_ajax_referer(self::NONCE_ACTION);

        if (!Capabilities::can_view()) {
            wp_send_json_error([
                'code' => 'forbidden',
                'message' => __('You do not have permission to view stock.', 'madebyhype-stockmanagment'),
            ], 403);
            return;
        }

        $options = $this->data_manager->get_filter_options();

        if ($options['error']) {
            wp_send_json_error($options['error'], 500);
            return;
        }

        wp_send_json_success([
            'categories' => $options['categories'],
            'tags' => $options['tags'],
            'attributes' => $options['attributes'],
        ]);
    }

    /**
     * The number beside each category in the filter drawer: how many rows
     * the given view would list with that category as its only category
     * filter, everything else in the query kept
     *
     * Request (GET or POST): action=madebyhype_get_category_counts, _wpnonce, and
     *   query  the query string of the screen's URL for the view, as for
     *          madebyhype_get_list. Its category_filter is ignored; sorting,
     *          page and rows per page make no difference.
     *
     * Success: {success: true, data: {counts: {"<category id>": int, ...}, view: "product"|"sku"}}
     *          A category that would list nothing is left out. The numbers are
     *          products by product and SKUs by SKU and on Needs attention; a
     *          category includes the categories below it (see
     *          DataManager::count_by_category()).
     * Failure: {success: false, data: {code, message}} with HTTP 403 (forbidden),
     *          400 (invalid_request: no query, a query that is too long, or the
     *          History tab) or 500 (db_error).
     * A missing or expired nonce is answered by WordPress itself: HTTP 403, body -1.
     */
    public function get_category_counts()
    {
        check_ajax_referer(self::NONCE_ACTION);

        if (!Capabilities::can_view()) {
            wp_send_json_error([
                'code' => 'forbidden',
                'message' => __('You do not have permission to view stock.', 'madebyhype-stockmanagment'),
            ], 403);
            return;
        }

        $request = wp_unslash($_REQUEST);
        $query = isset($request['query']) && is_string($request['query']) ? ltrim($request['query'], '?') : null;

        if ($query === null || strlen($query) > self::MAX_QUERY_LENGTH) {
            wp_send_json_error([
                'code' => 'invalid_request',
                'message' => __('The list that was asked for could not be read.', 'madebyhype-stockmanagment'),
            ], 400);
            return;
        }

        require_once __DIR__ . '/AdminPage.php';

        $args = [];
        parse_str($query, $args);

        // The same reading as the URL of the page gets; it expects slashed input, as $_GET is
        $admin_page = new AdminPage();
        $parsed = $admin_page->parse_request(wp_slash($args));

        if ($parsed['tab'] === 'history') {
            wp_send_json_error([
                'code' => 'invalid_request',
                'message' => __('The list that was asked for could not be read.', 'madebyhype-stockmanagment'),
            ], 400);
            return;
        }

        $counted = $this->data_manager->count_by_category($admin_page->list_args($parsed));

        if ($counted['error']) {
            wp_send_json_error($counted['error'], 500);
            return;
        }

        wp_send_json_success([
            // An object also when it is empty
            'counts' => (object) $counted['counts'],
            'view' => $counted['view'],
        ]);
    }

    /**
     * The variations of one variable product, loaded when its row is opened
     *
     * Request (GET or POST): action=madebyhype_get_variations, _wpnonce,
     * product_id, and the sales period of the page: period, or start_date
     * and end_date.
     *
     * Success: {success: true, data: {parent: row, variations: [row, ...], period: {...}}}
     * Failure: {success: false, data: {code, message}} with HTTP 403 (forbidden),
     *          400 (invalid_request), 404 (not_found, not_variable) or 500 (db_error).
     * A missing or expired nonce is answered by WordPress itself: HTTP 403, body -1.
     */
    public function get_variations()
    {
        check_ajax_referer(self::NONCE_ACTION);

        if (!Capabilities::can_view()) {
            wp_send_json_error([
                'code' => 'forbidden',
                'message' => __('You do not have permission to view stock.', 'madebyhype-stockmanagment'),
            ], 403);
            return;
        }

        $request = wp_unslash($_REQUEST);

        $product_id = isset($request['product_id']) && is_scalar($request['product_id']) ? absint($request['product_id']) : 0;
        if (!$product_id) {
            wp_send_json_error([
                'code' => 'invalid_request',
                'message' => __('No product was given.', 'madebyhype-stockmanagment'),
            ], 400);
            return;
        }

        $text = function ($key) use ($request) {
            return isset($request[$key]) && is_scalar($request[$key]) ? sanitize_text_field((string) $request[$key]) : '';
        };

        // The data manager validates the period and falls back to its default
        $outcome = $this->data_manager->get_variations($product_id, [
            'period' => $text('period'),
            'start_date' => $text('start_date'),
            'end_date' => $text('end_date'),
        ]);

        if ($outcome['error']) {
            wp_send_json_error($outcome['error'], $outcome['error']['code'] === 'db_error' ? 500 : 404);
            return;
        }

        wp_send_json_success([
            'parent' => $outcome['parent'],
            'variations' => $outcome['variations'],
            'period' => $outcome['period'],
        ]);
    }

    /**
     * Given products and variations as the list shows them, read again after
     * a save or an undo
     *
     * Request (GET or POST): action=madebyhype_get_rows, _wpnonce, ids[]
     * (product and variation ids, 1 to DataManager::MAX_ROW_IDS), and the
     * sales period of the page: period, or start_date and end_date.
     *
     * Success: {success: true, data: {rows: [row, ...], period: {...}}}
     *          Rows have the shape of list rows and come in the order asked
     *          for; an id that no longer exists is left out.
     * Failure: {success: false, data: {code, message}} with HTTP 403 (forbidden),
     *          400 (invalid_request: no id, or more than the limit) or 500 (db_error).
     * A missing or expired nonce is answered by WordPress itself: HTTP 403, body -1.
     */
    public function get_rows()
    {
        check_ajax_referer(self::NONCE_ACTION);

        if (!Capabilities::can_view()) {
            wp_send_json_error([
                'code' => 'forbidden',
                'message' => __('You do not have permission to view stock.', 'madebyhype-stockmanagment'),
            ], 403);
            return;
        }

        $request = wp_unslash($_REQUEST);

        $ids = isset($request['ids']) && is_array($request['ids']) ? array_filter($request['ids'], 'is_scalar') : [];
        $ids = array_values(array_unique(array_filter(array_map('absint', $ids))));

        if (!$ids || count($ids) > DataManager::MAX_ROW_IDS) {
            wp_send_json_error([
                'code' => 'invalid_request',
                'message' => !$ids
                    ? __('No product was given.', 'madebyhype-stockmanagment')
                    : __('Too many products were asked for at once.', 'madebyhype-stockmanagment'),
            ], 400);
            return;
        }

        $text = function ($key) use ($request) {
            return isset($request[$key]) && is_scalar($request[$key]) ? sanitize_text_field((string) $request[$key]) : '';
        };

        $outcome = $this->data_manager->get_rows($ids, [
            'period' => $text('period'),
            'start_date' => $text('start_date'),
            'end_date' => $text('end_date'),
        ]);

        if ($outcome['error']) {
            wp_send_json_error($outcome['error'], 500);
            return;
        }

        wp_send_json_success([
            'rows' => $outcome['rows'],
            'period' => $outcome['period'],
        ]);
    }
}
