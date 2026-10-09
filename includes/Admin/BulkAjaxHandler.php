<?php

namespace MadeByHypeStockmanagment\Admin;

use MadeByHypeStockmanagment\Capabilities;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * AJAX transport of the bulk price change: nonce, permission, decode, call
 * the bulk price service, encode. No price logic lives here.
 *
 * Both actions use the nonce of a save and need the permission to edit
 * prices. Answers are HTTP 200 with {success: true|false, data: ...}; a
 * missing or expired nonce is answered by WordPress itself: HTTP 403, body -1.
 */
class BulkAjaxHandler
{
    const ACTION_PREVIEW = 'madebyhype_bulk_price_preview';
    const ACTION_APPLY = 'madebyhype_bulk_price_apply';

    private $bulk_price_service;

    /**
     * @param \MadeByHypeStockmanagment\Data\BulkPriceService $bulk_price_service
     */
    public function __construct($bulk_price_service)
    {
        $this->bulk_price_service = $bulk_price_service;
    }

    public function init()
    {
        // Logged-in users only: there is no wp_ajax_nopriv_ hook
        add_action('wp_ajax_' . self::ACTION_PREVIEW, [$this, 'preview']);
        add_action('wp_ajax_' . self::ACTION_APPLY, [$this, 'apply']);
    }

    /**
     * What a rule would change. Writes nothing.
     *
     * Request (POST): _wpnonce, and
     *   query  the query string of the screen's URL, as madebyhype_get_list takes it:
     *          the search and the filters decide which items the rule applies to
     *   rule   {change, method, value, rounding, skip_on_sale}, see BulkPriceRule::normalise()
     *
     * Success: {success: true, data: <BulkPriceService::preview() result>}
     * Refused: {success: false, data: {code, message, field}}; field names the part of the rule that is wrong
     */
    public function preview()
    {
        if (!$this->allowed()) {
            return;
        }

        $request = wp_unslash($_POST);
        $query = isset($request['query']) && is_string($request['query']) ? ltrim($request['query'], '?') : null;

        if ($query === null || strlen($query) > ReadAjaxHandler::MAX_QUERY_LENGTH) {
            $this->refuse(new \WP_Error('invalid_request', __('The list that was asked for could not be read.', 'madebyhype-stockmanagment')));
            return;
        }

        require_once __DIR__ . '/AdminPage.php';

        $args = [];
        parse_str($query, $args);

        // The same reading as the URL of the page gets; it expects slashed input, as $_GET is
        $admin_page = new AdminPage();
        $parsed = $admin_page->parse_request(wp_slash($args));

        if ($parsed['tab'] !== 'all') {
            $this->refuse(new \WP_Error('invalid_request', __('Prices are changed in bulk from the All stock tab.', 'madebyhype-stockmanagment')));
            return;
        }

        $outcome = $this->bulk_price_service->preview($admin_page->list_args($parsed), isset($request['rule']) ? $request['rule'] : null);

        if (is_wp_error($outcome)) {
            $this->refuse($outcome);
            return;
        }

        wp_send_json_success($outcome);
    }

    /**
     * Apply a rule to a slice of the items its preview named
     *
     * Request (POST): _wpnonce, and
     *   ids         product and variation ids, at most BulkPriceService::MAX_APPLY
     *   rule        as for the preview
     *   save_token  made up once per bulk change and sent with every slice of it,
     *               including a slice that is sent again after a lost answer
     *
     * Success: {success: true, data: <BulkPriceService::apply() result>}
     * Refused: {success: false, data: {code, message, field}}; nothing of this slice was changed
     */
    public function apply()
    {
        if (!$this->allowed()) {
            return;
        }

        $request = wp_unslash($_POST);

        $outcome = $this->bulk_price_service->apply(
            isset($request['ids']) && is_array($request['ids']) ? $request['ids'] : [],
            isset($request['rule']) ? $request['rule'] : null,
            isset($request['save_token']) && is_string($request['save_token']) ? $request['save_token'] : ''
        );

        if (is_wp_error($outcome)) {
            $this->refuse($outcome);
            return;
        }

        wp_send_json_success($outcome);
    }

    /**
     * Nonce and permission. Answers the request itself when it is not allowed.
     *
     * @return bool
     */
    private function allowed()
    {
        check_ajax_referer(AjaxHandler::NONCE_SAVE);

        if (!Capabilities::can_edit_prices()) {
            wp_send_json_error(['code' => 'forbidden', 'message' => __('You do not have permission to change prices.', 'madebyhype-stockmanagment')]);
            return false;
        }

        return true;
    }

    private function refuse($error)
    {
        $data = $error->get_error_data();

        wp_send_json_error([
            'code' => $error->get_error_code(),
            'message' => $error->get_error_message(),
            'field' => is_array($data) && isset($data['field']) ? $data['field'] : null,
        ]);
    }
}
