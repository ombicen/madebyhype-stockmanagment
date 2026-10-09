<?php

namespace MadeByHypeStockmanagment\Admin;

use MadeByHypeStockmanagment\Capabilities;

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
}
