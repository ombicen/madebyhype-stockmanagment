<?php

namespace MadeByHypeStockmanagment\Admin;

use MadeByHypeStockmanagment\Capabilities;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * AJAX transport of the stock screen: nonce, permission, decode, call the
 * write service, encode. No product logic lives here.
 */
class AjaxHandler
{
    private $write_service;

    /**
     * @param \MadeByHypeStockmanagment\Data\WriteService $write_service
     */
    public function __construct($write_service)
    {
        $this->write_service = $write_service;
    }

    public function init()
    {
        // Only register AJAX handlers for logged-in users with proper capabilities
        // Removed wp_ajax_nopriv_ hooks to prevent unauthenticated access attempts
        add_action('wp_ajax_madebyhype_save_stock_changes', [$this, 'save_stock_changes']);
        add_action('wp_ajax_madebyhype_revert_version', [$this, 'revert_to_version']);
    }

    /**
     * Save contract v2. Request and item results: see WriteService::save().
     *
     * Success: {success: true, data: {contract: 2, batch_id, results: [...], summary: {total, success, errors}}}
     * Refused as a whole: {success: false, data: "<message>"}
     */
    public function save_stock_changes()
    {
        check_ajax_referer('madebyhype_stock_update_nonce');

        // The service checks each item against the permission its fields need
        if (!Capabilities::can_edit_stock() && !Capabilities::can_edit_prices()) {
            wp_send_json_error('Insufficient permissions');
            return;
        }

        $data = isset($_POST['data']) && is_array($_POST['data']) ? wp_unslash($_POST['data']) : [];

        $outcome = $this->write_service->save($data);

        if (is_wp_error($outcome)) {
            wp_send_json_error($outcome->get_error_message());
            return;
        }

        wp_send_json_success([
            'contract' => 2,
            'batch_id' => $outcome['batch_id'],
            'results' => $outcome['results'],
            'summary' => $outcome['summary'],
        ]);
    }

    /**
     * TRANSITION: legacy revert of the Version History list
     */
    public function revert_to_version()
    {
        check_ajax_referer('madebyhype_version_revert_nonce');

        if (!Capabilities::can_undo()) {
            wp_send_json_error('Insufficient permissions');
            return;
        }

        $version_number = isset($_POST['version_id']) ? intval($_POST['version_id']) : 0;

        $outcome = $this->write_service->revert_legacy_version($version_number);

        if (is_wp_error($outcome)) {
            wp_send_json_error($outcome->get_error_message());
            return;
        }

        wp_send_json_success('Successfully reverted to version ' . $version_number);
    }
}
