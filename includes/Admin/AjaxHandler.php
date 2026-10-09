<?php

namespace MadeByHypeStockmanagment\Admin;

use MadeByHypeStockmanagment\Capabilities;
use MadeByHypeStockmanagment\Data\VersionManager;
use MadeByHypeStockmanagment\Data\WriteService;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * AJAX transport of the stock screen: nonce, permission, decode, call the
 * write service or the change log, encode. No product logic lives here.
 *
 * A missing or expired nonce ends the request with HTTP 403 and the body -1
 * (WordPress's check_ajax_referer), which a page can tell apart from every
 * answer below: those are HTTP 200 with {success: true|false, data: ...}.
 */
class AjaxHandler
{
    // Nonce action names. NONCE_SAVE keeps its 1.0.6 name.
    const NONCE_SAVE = 'madebyhype_stock_update_nonce';
    const NONCE_UNDO = 'madebyhype_stock_undo_nonce';
    const NONCE_HISTORY = 'madebyhype_stock_history_nonce';
    const NONCE_LEGACY_REVERT = 'madebyhype_version_revert_nonce';

    // Key a page sends with a Heartbeat tick to get fresh nonces back under the same key
    const HEARTBEAT_KEY = 'madebyhype_stock_nonces';

    private $write_service;
    private $change_log;

    /**
     * @param \MadeByHypeStockmanagment\Data\WriteService $write_service
     * @param \MadeByHypeStockmanagment\Data\ChangeLog    $change_log
     */
    public function __construct($write_service, $change_log)
    {
        $this->write_service = $write_service;
        $this->change_log = $change_log;
    }

    public function init()
    {
        // Only register AJAX handlers for logged-in users with proper capabilities
        // Removed wp_ajax_nopriv_ hooks to prevent unauthenticated access attempts
        add_action('wp_ajax_madebyhype_save_stock_changes', [$this, 'save_stock_changes']);
        add_action('wp_ajax_madebyhype_stock_undo_preview', [$this, 'undo_preview']);
        add_action('wp_ajax_madebyhype_stock_undo', [$this, 'undo']);
        add_action('wp_ajax_madebyhype_stock_history', [$this, 'history']);
        add_action('wp_ajax_madebyhype_revert_version', [$this, 'revert_to_version']);

        // Fresh nonces for a page that stayed open, through WordPress's own
        // Heartbeat: on a normal tick, and on the tick after the session was
        // renewed (when the Heartbeat nonce itself is stale)
        add_filter('heartbeat_received', [$this, 'refresh_nonces'], 10, 2);
        add_filter('wp_refresh_nonces', [$this, 'refresh_nonces'], 10, 2);
    }

    /**
     * The nonces the current user can use, for the page to start with and to
     * refresh later
     *
     * @return array Any of 'save', 'undo', 'history' => nonce
     */
    public static function nonces()
    {
        $nonces = [];

        if (Capabilities::can_edit_stock() || Capabilities::can_edit_prices()) {
            $nonces['save'] = wp_create_nonce(self::NONCE_SAVE);
        }

        if (Capabilities::can_undo()) {
            $nonces['undo'] = wp_create_nonce(self::NONCE_UNDO);
        }

        if (Capabilities::can_view()) {
            $nonces['history'] = wp_create_nonce(self::NONCE_HISTORY);
        }

        return $nonces;
    }

    /**
     * Heartbeat: answer a tick that carries HEARTBEAT_KEY with fresh nonces
     *
     * @param array $response
     * @param array $data     What the page sent with the tick
     * @return array
     */
    public function refresh_nonces($response, $data = [])
    {
        if (is_array($data) && !empty($data[self::HEARTBEAT_KEY]) && Capabilities::can_view()) {
            $response[self::HEARTBEAT_KEY] = self::nonces();
        }

        return $response;
    }

    /**
     * Save. Request and item results: see WriteService::save().
     *
     * Success: {success: true, data: {contract: 3, batch_id, results: [...], summary: {...}, save: {...}|null}}
     *          save: the history entry of this save as it stands after this request (see present_save()),
     *          null when nothing was recorded
     * Refused as a whole: {success: false, data: "<message>"}
     */
    public function save_stock_changes()
    {
        check_ajax_referer(self::NONCE_SAVE);

        // The service checks each field against the permission it needs
        if (!Capabilities::can_edit_stock() && !Capabilities::can_edit_prices()) {
            wp_send_json_error(__('You do not have permission to change stock or prices.', 'madebyhype-stockmanagment'));
            return;
        }

        $data = isset($_POST['data']) && is_array($_POST['data']) ? wp_unslash($_POST['data']) : [];

        $outcome = $this->write_service->save($data);

        if (is_wp_error($outcome)) {
            wp_send_json_error($outcome->get_error_message());
            return;
        }

        wp_send_json_success([
            'contract' => 3,
            'batch_id' => $outcome['batch_id'],
            'results' => $outcome['results'],
            'summary' => $outcome['summary'],
            'save' => $this->saved_entry($outcome['batch_id']),
        ]);
    }

    /**
     * What undoing a save would do. Request: batch_id. Writes nothing.
     * Answer: see send_undo().
     */
    public function undo_preview()
    {
        $this->send_undo(true);
    }

    /**
     * Undo a save. Request: batch_id, and nothing else: what to change comes
     * from the change log. Answer: see send_undo().
     */
    public function undo()
    {
        $this->send_undo(false);
    }

    /**
     * Success: {success: true, data: <WriteService::undo() result> + save: the history entry of the
     *          save being undone, as it stands after this request (see present_save())}
     * Refused: {success: false, data: {code, message}}
     */
    private function send_undo($dry_run)
    {
        check_ajax_referer(self::NONCE_UNDO);

        if (!Capabilities::can_undo()) {
            wp_send_json_error(['code' => 'forbidden', 'message' => __('You do not have permission to undo saves.', 'madebyhype-stockmanagment')]);
            return;
        }

        $batch_id = isset($_POST['batch_id']) ? absint($_POST['batch_id']) : 0;

        $outcome = $this->write_service->undo($batch_id, $dry_run);

        if (is_wp_error($outcome)) {
            wp_send_json_error(['code' => $outcome->get_error_code(), 'message' => $outcome->get_error_message()]);
            return;
        }

        $outcome['save'] = $this->saved_entry($outcome['batch_id']);

        wp_send_json_success($outcome);
    }

    /**
     * Read the history. Request: view, plus what that view takes.
     *
     *   view=saves (default)  paged, per_page, user (id), s (name or SKU)
     *                         -> {saves: [...], total, page, per_page, pages}
     *   view=save             batch_id -> {save: {...}, changes: [...]}
     *   view=item             item_id (product or variation), paged, per_page
     *                         -> {rows: [...], total, page, per_page, pages}
     *   view=last             -> {save: {...}|null}: the current user's most recent save
     *   view=legacy           -> {versions: [...]}: saves made before the change log, list only
     *
     * Refused: {success: false, data: {code, message}}
     */
    public function history()
    {
        check_ajax_referer(self::NONCE_HISTORY);

        if (!Capabilities::can_view()) {
            wp_send_json_error(['code' => 'forbidden', 'message' => __('You do not have permission to view stock history.', 'madebyhype-stockmanagment')]);
            return;
        }

        $view = isset($_REQUEST['view']) ? sanitize_key(wp_unslash($_REQUEST['view'])) : 'saves';
        $paging = [
            'page' => isset($_REQUEST['paged']) ? absint($_REQUEST['paged']) : 1,
            'per_page' => isset($_REQUEST['per_page']) ? absint($_REQUEST['per_page']) : 0,
        ];

        switch ($view) {
            case 'save':
                $save = $this->change_log->get_batch_summary(isset($_REQUEST['batch_id']) ? absint($_REQUEST['batch_id']) : 0);

                if (!$save) {
                    wp_send_json_error(['code' => 'not_found', 'message' => __('This save is no longer in History.', 'madebyhype-stockmanagment')]);
                    return;
                }

                wp_send_json_success([
                    'save' => $this->present_save($save),
                    'changes' => array_map([$this, 'present_change'], $this->change_log->get_batch_changes($save['id'])),
                ]);
                return;

            case 'item':
                $history = $this->change_log->get_item_history(
                    isset($_REQUEST['item_id']) ? absint($_REQUEST['item_id']) : 0,
                    ['page' => $paging['page'], 'per_page' => $paging['per_page'] ? $paging['per_page'] : 50]
                );
                $history['rows'] = array_map([$this, 'present_change'], $history['rows']);

                wp_send_json_success($history);
                return;

            case 'last':
                $save = $this->change_log->get_last_save(get_current_user_id());

                wp_send_json_success(['save' => $save ? $this->present_save($save) : null]);
                return;

            case 'legacy':
                wp_send_json_success(['versions' => $this->legacy_versions()]);
                return;
        }

        $found = $this->change_log->get_batches([
            'page' => $paging['page'],
            'per_page' => $paging['per_page'] ? $paging['per_page'] : 20,
            'user_id' => isset($_REQUEST['user']) ? absint($_REQUEST['user']) : 0,
            'search' => isset($_REQUEST['s']) ? sanitize_text_field(wp_unslash($_REQUEST['s'])) : '',
        ]);

        wp_send_json_success([
            'saves' => array_map([$this, 'present_save'], $found['batches']),
            'total' => $found['total'],
            'page' => $found['page'],
            'per_page' => $found['per_page'],
            'pages' => $found['pages'],
        ]);
    }

    /**
     * The Revert button of the old Version History list. Saves made before
     * the change log are listed only; this always refuses.
     */
    public function revert_to_version()
    {
        check_ajax_referer(self::NONCE_LEGACY_REVERT);

        wp_send_json_error(__('Saves made before this update can no longer be reverted. Saves made from now on can be undone one by one.', 'madebyhype-stockmanagment'));
    }

    /**
     * The history entry of one batch, ready to show; null when there is none
     */
    private function saved_entry($batch_id)
    {
        $save = $batch_id ? $this->change_log->get_batch_summary($batch_id) : null;

        return $save ? $this->present_save($save) : null;
    }

    /**
     * A batch summary (ChangeLog::get_batches()) plus 'when': its time in the
     * site's time zone and format; the same inside 'undone_by'
     */
    private function present_save($save)
    {
        $save['when'] = $this->local_time($save['created_at_gmt']);

        if ($save['undone_by']) {
            $save['undone_by']['when'] = $this->local_time($save['undone_by']['created_at_gmt']);
        }

        return $save;
    }

    /**
     * A change row with the field name and values as History writes them
     */
    private function present_change($change)
    {
        $field = $change['field'];

        $change['label'] = WriteService::field_label($field);
        $change['old_display'] = WriteService::display_value($field, $change['old']);
        $change['new_display'] = WriteService::display_value($field, $change['new']);
        // For a start of tracking the user typed the starting quantity
        $change['typed_display'] = $change['typed'] === null ? null : WriteService::display_value($field === 'manage_stock' && !is_bool($change['typed']) ? 'stock_quantity' : $field, $change['typed']);

        if (isset($change['created_at_gmt'])) {
            $change['when'] = $this->local_time($change['created_at_gmt']);
        }

        if (!empty($change['gap'])) {
            $change['gap']['from_display'] = WriteService::display_value($field, $change['gap']['from']);
            $change['gap']['to_display'] = WriteService::display_value($field, $change['gap']['to']);
        }

        return $change;
    }

    /**
     * @param string $gmt UTC, Y-m-d H:i:s
     * @return string In the site's time zone, date and time format
     */
    private function local_time($gmt)
    {
        return wp_date(get_option('date_format') . ' ' . get_option('time_format'), strtotime($gmt . ' UTC'));
    }

    /**
     * Saves made before the change log: when and how many items, nothing more
     */
    private function legacy_versions()
    {
        require_once dirname(__DIR__) . '/Data/VersionManager.php';

        $manager = new VersionManager();
        $versions = [];

        foreach ($manager->get_versions() as $version) {
            $versions[] = [
                'version_number' => (int) $version['version_number'],
                // Server time, as the old list has always shown it
                'created_at' => $version['created_at'],
                'summary' => $manager->get_version_summary($version),
            ];
        }

        return $versions;
    }
}
