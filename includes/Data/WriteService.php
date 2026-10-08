<?php

namespace MadeByHypeStockmanagment\Data;

use MadeByHypeStockmanagment\Capabilities;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * The write path: the only code in this plugin that changes products.
 *
 * Per item: resolve by real type, authorise, validate, read the current
 * state, apply the edit in memory, record the planned changes as pending in
 * the change log, save, read the product back, settle the log rows, report.
 *
 * Invariant: a product is never written unless its change rows are already
 * stored. If they cannot be stored, the item is refused. Together with the
 * unique key on the change rows this also means an item is written at most
 * once per batch, which is what makes a resent request harmless.
 *
 * Product writes go through WooCommerce product objects and save() only.
 */
class WriteService
{
    const MAX_ITEMS_PER_BUCKET = 100;

    // Fields a request can edit, grouped by the permission they need
    const STOCK_FIELDS = ['stock_quantity', 'stock_status'];
    const PRICE_FIELDS = ['regular_price', 'sale_price'];

    // Product properties that are compared, logged and undone field by field
    const TRACKED_FIELDS = ['stock_quantity', 'stock_status', 'manage_stock', 'backorders', 'regular_price', 'sale_price'];

    private $log;
    private $legacy_versions;

    /**
     * @param ChangeLog           $log
     * @param VersionManager|null $legacy_versions TRANSITION: when given, every save also writes the
     *                                             old snapshot so the legacy Version History and its
     *                                             Revert keep working. Remove with the legacy revert.
     */
    public function __construct($log, $legacy_versions = null)
    {
        $this->log = $log;
        $this->legacy_versions = $legacy_versions;
    }

    /**
     * Save a set of stock and price edits
     *
     * Request (contract v2, still accepts what the 1.0.6 script sends):
     *
     *   products   => [id => fields]  legacy bucket: any product type, variations included
     *   variations => [id => fields]  legacy bucket: variations only, no sale_price
     *   items      => [id => fields]  v2 bucket: any id, handled by its real type
     *   note       => string          optional, stored on the batch
     *   save_token => string          optional, 8-64 chars of A-Z a-z 0-9 _ -, made up by the client
     *                                 once per press of Save and sent with every request of that save
     *
     * Each field is either the new value (legacy) or
     * ['value' => new value, 'seen' => the value the user was looking at].
     * Values are plain, unslashed strings or numbers.
     *
     * With a save token, all requests of one save share one batch, and an
     * item that already reached the log under that token is not written
     * again: the resend gets its stored outcome (see stored_result()).
     * Without one, each request is its own batch and nothing is recognised
     * as a resend.
     *
     * @param array  $request
     * @param string $source  Where the edit comes from, stored on the batch
     * @return array|\WP_Error ['batch_id' => int|null, 'results' => array, 'summary' => array],
     *                         or a WP_Error when the request as a whole is refused (nothing is processed)
     */
    public function save($request, $source = ChangeLog::SOURCE_STOCK_SCREEN)
    {
        $request = is_array($request) ? $request : [];

        $entries = $this->normalise_request($request);
        if (is_wp_error($entries)) {
            return $entries;
        }

        $run = $this->open_run($request, $source);
        if (is_wp_error($run)) {
            return $run;
        }

        $results = [];
        $success_count = 0;
        $error_count = 0;
        $legacy_snapshot = ['products' => [], 'variations' => []];

        foreach ($entries as $entry) {
            $result = $this->save_item($entry, $run, $legacy_snapshot);

            if ($result['success']) {
                $success_count++;
            } else {
                $error_count++;
            }

            $results[] = $result;
        }

        \wc_delete_product_transients();

        // TRANSITION: old snapshot for the legacy Version History. Unlike the
        // change log it is only written when the whole request completes.
        $snapshot_count = count($legacy_snapshot['products']) + count($legacy_snapshot['variations']);
        if ($snapshot_count > 0 && $this->legacy_versions) {
            $this->legacy_versions->save_version($legacy_snapshot, "Saved $snapshot_count changes");
        }

        return [
            'batch_id' => $run['batch_id'] ? (int) $run['batch_id'] : null,
            'results' => $results,
            'summary' => [
                'total' => $success_count + $error_count,
                'success' => $success_count,
                'errors' => $error_count,
            ],
        ];
    }

    /**
     * TRANSITION: the legacy "revert to version" of the Version History list
     *
     * This is the one product write that does not go through save_item(): it
     * delegates to VersionManager, which writes the products itself and logs
     * nothing in the change log. Replace it with a per-save undo built on the
     * change log, then delete this method and VersionManager.
     *
     * @param int $version_number
     * @return true|\WP_Error
     */
    public function revert_legacy_version($version_number)
    {
        $version_number = (int) $version_number;

        if (!Capabilities::can_undo() || !$this->legacy_versions) {
            return new \WP_Error('forbidden', 'Insufficient permissions');
        }

        if ($version_number <= 0) {
            return new \WP_Error('invalid_version', 'Invalid version number');
        }

        if (!$this->legacy_versions->get_version($version_number)) {
            return new \WP_Error('version_missing', 'Version ' . $version_number . ' no longer exists. Reload the page to see the current history.');
        }

        if (!$this->legacy_versions->revert_to_version($version_number)) {
            return new \WP_Error('revert_failed', 'Failed to revert to version ' . $version_number);
        }

        return true;
    }

    /**
     * Flatten the request buckets into one list of entries
     *
     * @return array|\WP_Error List of ['id' => int, 'bucket' => string, 'fields' => mixed]
     */
    private function normalise_request($request)
    {
        $entries = [];

        foreach (['products', 'variations', 'items'] as $bucket) {
            $items = isset($request[$bucket]) && is_array($request[$bucket]) ? $request[$bucket] : [];

            // Limit batch size to prevent abuse and timeouts
            if (count($items) > self::MAX_ITEMS_PER_BUCKET) {
                return new \WP_Error('batch_too_large', 'Batch size exceeds maximum allowed (' . self::MAX_ITEMS_PER_BUCKET . ' items)');
            }

            foreach ($items as $id => $fields) {
                $entries[] = ['id' => absint($id), 'bucket' => $bucket, 'fields' => $fields];
            }
        }

        if (empty($entries)) {
            return new \WP_Error('empty_request', 'No valid data provided');
        }

        return $entries;
    }

    /**
     * Batch state of one request
     *
     * The batch row itself is created with the first change that has to be
     * logged (ensure_batch()), so a request that changes nothing leaves no
     * batch. With a save token that an earlier request already used, the run
     * continues that batch and knows which items it already holds.
     *
     * @return array|\WP_Error ['source', 'note', 'save_token', 'batch_id' (0 until created),
     *                         'stored' => item id => its change rows from earlier requests,
     *                         'handled' => item id => true for the items of this request]
     */
    private function open_run($request, $source)
    {
        $run = [
            'source' => $source,
            'note' => isset($request['note']) && is_scalar($request['note']) ? sanitize_text_field((string) $request['note']) : '',
            'save_token' => '',
            'batch_id' => 0,
            'stored' => [],
            'handled' => [],
        ];

        if (!isset($request['save_token']) || $request['save_token'] === '') {
            return $run;
        }

        if (!is_string($request['save_token']) || !preg_match('/^[A-Za-z0-9_-]{8,64}$/', $request['save_token'])) {
            return new \WP_Error('invalid_save_token', 'Invalid save token');
        }

        $run['save_token'] = $request['save_token'];

        $batch = $this->find_token_batch($run);
        if (is_wp_error($batch)) {
            return $batch;
        }

        if ($batch) {
            $run['batch_id'] = (int) $batch['id'];

            foreach ($this->log->get_changes($run['batch_id']) as $row) {
                $run['stored'][(int) $row['item_id']][] = $row;
            }
        }

        return $run;
    }

    /**
     * The batch the current user already opened with this run's save token
     *
     * @return array|null|\WP_Error Batch row, null for none, WP_Error when the token belongs to
     *                              something this run cannot continue (an undo, another source)
     */
    private function find_token_batch($run)
    {
        $batch = $this->log->find_batch_by_token(get_current_user_id(), $run['save_token']);

        if ($batch && ($batch['kind'] !== ChangeLog::KIND_SAVE || $batch['source'] !== $run['source'])) {
            return new \WP_Error('invalid_save_token', 'Invalid save token');
        }

        return $batch;
    }

    /**
     * Create the batch of this request the first time it is needed
     *
     * @return int Batch id, 0 when it could not be created
     */
    private function ensure_batch(&$run)
    {
        if ($run['batch_id']) {
            return $run['batch_id'];
        }

        $run['batch_id'] = $this->log->create_batch([
            'source' => $run['source'],
            'kind' => ChangeLog::KIND_SAVE,
            'note' => $run['note'],
            'save_token' => $run['save_token'],
        ]);

        // Two requests of one save can race to open its batch; the loser joins the winner's
        if (!$run['batch_id'] && $run['save_token'] !== '') {
            $batch = $this->find_token_batch($run);
            $run['batch_id'] = $batch && !is_wp_error($batch) ? (int) $batch['id'] : 0;
        }

        return $run['batch_id'];
    }

    /**
     * Split one item's fields into the new values and the values the user saw
     *
     * @param array $fields field => value, or field => ['value' => x, 'seen' => y]
     * @return array ['values' => field => raw new value, 'seen' => field => string (only where sent)]
     */
    private function normalise_fields($fields)
    {
        $values = [];
        $seen = [];

        foreach ($fields as $key => $raw) {
            if (is_array($raw) && array_key_exists('value', $raw)) {
                if (isset($raw['seen']) && is_scalar($raw['seen'])) {
                    $seen[$key] = trim((string) $raw['seen']);
                }
                $raw = $raw['value'];
            }

            $values[$key] = $raw;
        }

        return ['values' => $values, 'seen' => $seen];
    }

    /**
     * Run the whole pipeline for one item
     *
     * @param array $entry           ['id' => int, 'bucket' => string, 'fields' => mixed]
     * @param array $run             Batch state of this request, see open_run()
     * @param array $legacy_snapshot TRANSITION: collects the old-style snapshot of saved items
     * @return array Item result, see result()
     */
    private function save_item($entry, &$run, &$legacy_snapshot)
    {
        $id = $entry['id'];
        $bucket = $entry['bucket'];

        if ($id <= 0) {
            return $this->result($id, false, 'invalid_id', 'Invalid item id');
        }

        if (!is_array($entry['fields'])) {
            return $this->result($id, false, 'invalid_fields', 'Invalid field data');
        }

        // The same id twice in one request: the second would be judged against
        // a product the first has just changed, and could not be logged
        if (isset($run['handled'][$id])) {
            return $this->result($id, false, 'duplicate_item', __('This item was sent twice in one request. Only the first was processed.', 'madebyhype-stockmanagment'));
        }
        $run['handled'][$id] = true;

        $item = $this->resolve_item($id, $bucket);
        if (!$item) {
            return $this->result($id, false, 'not_found', $bucket === 'variations' ? 'Variation not found' : 'Product not found');
        }

        $type = $item->get_type();
        $before = $this->read_state($item);

        // A resend of an item this save already logged: report, do not write
        if (isset($run['stored'][$id])) {
            return $this->stored_result($id, $type, $before, $run['stored'][$id]);
        }

        $input = $this->normalise_fields($entry['fields']);
        $allowed = $this->editable_fields($item, $bucket);

        $denied = $this->authorise(array_intersect(array_keys($input['values']), $allowed));
        if ($denied !== '') {
            return $this->result($id, false, 'forbidden', $denied, ['type' => $type, 'values' => $before]);
        }

        // Reject the whole item before anything is written if any field is invalid
        $validated = $this->validate_fields($item, $input['values'], $allowed);
        if (isset($validated['error'])) {
            return $this->result($id, false, 'invalid_value', $validated['error'], [
                'type' => $type,
                'values' => $before,
                'field' => isset($validated['field']) ? $validated['field'] : null,
            ]);
        }

        $conflicts = $this->detect_conflicts($validated['fields'], $input['seen'], $before);
        $snapshot = $this->legacy_snapshot($item, $bucket);

        // In memory only: nothing is written until save()
        try {
            $messages = $this->apply_fields($item, $validated['fields']);
            $planned = $this->plan_changes($item, $before);
        } catch (\Throwable $e) {
            return $this->result($id, false, 'save_failed', $e->getMessage(), ['type' => $type, 'values' => $before]);
        }

        $after = $before;
        $code = 'unchanged';
        $message = implode(", ", $messages);

        // Nothing would change: the product is left alone and nothing is logged
        if (!empty($planned)) {
            $log_item = [
                'item_id' => $id,
                'product_id' => $item->get_parent_id() ? $item->get_parent_id() : $id,
                'item_type' => $type,
            ];

            // The log comes first: no rows, no write
            $row_ids = $this->ensure_batch($run) ? $this->log->add_changes($run['batch_id'], $log_item, $planned) : false;
            if ($row_ids === false) {
                return $this->result($id, false, 'log_failed', __('The change could not be recorded in the history, so it was not saved.', 'madebyhype-stockmanagment'), ['type' => $type, 'values' => $before]);
            }

            $error = '';
            try {
                $item->save();
            } catch (\Throwable $e) {
                $error = $e->getMessage() !== '' ? $e->getMessage() : get_class($e);
            }

            // What the product holds now decides the outcome, not whether save()
            // returned: an error thrown by a hook after the write leaves the
            // product changed, and the log has to say so
            $fresh = \wc_get_product($id);
            if ($fresh) {
                $after = $this->read_state($fresh);
            } elseif ($error === '') {
                $after = $this->read_state($item);
            }

            if ($error !== '' && empty($this->diff_state($before, $after))) {
                foreach ($row_ids as $row_id) {
                    $this->log->set_change_status($row_id, ChangeLog::STATUS_FAILED, $error);
                }

                return $this->result($id, false, 'save_failed', $error, ['type' => $type, 'values' => $after]);
            }

            $this->settle_changes($run['batch_id'], $log_item, $row_ids, $before, $after, $error);

            $code = 'saved';
            if ($error !== '') {
                $code = 'saved_with_error';
                /* translators: %s: error message */
                $message .= '. ' . sprintf(__('Saved, but an error was reported afterwards: %s', 'madebyhype-stockmanagment'), $error);
            }
        }

        $changed = array_keys($this->diff_state($before, $after));
        if (empty($changed)) {
            $code = 'unchanged';
        }

        $legacy_snapshot[$this->legacy_snapshot_bucket($item, $bucket)][$id] = $snapshot;

        return $this->result($id, true, $code, $message, [
            'type' => $type,
            'values' => $after,
            'changed' => $changed,
            'conflicts' => $conflicts,
        ]);
    }

    /**
     * Outcome of an item that an earlier request with the same save token
     * already logged. Nothing is written.
     *
     * - a row still pending: that request died while writing the item, so
     *   whether the product changed is unknown. Reported as "interrupted"
     *   rather than written a second time.
     * - rows failed and none applied: the earlier failure is repeated. Saving
     *   again from the screen (a new token) is the way to retry.
     * - otherwise it was saved: reported as "already_saved".
     *
     * @param int    $id
     * @param string $type
     * @param array  $current read_state() of the item now
     * @param array  $rows    Its change rows in the batch
     * @return array Item result
     */
    private function stored_result($id, $type, $current, $rows)
    {
        $extra = ['type' => $type, 'values' => $current, 'changed' => []];
        $statuses = [];
        $failure = '';

        foreach ($rows as $row) {
            $statuses[] = $row['status'];

            if ($row['status'] === ChangeLog::STATUS_FAILED && $failure === '') {
                $failure = $row['message'];
            }

            if ($row['status'] === ChangeLog::STATUS_APPLIED) {
                $extra['changed'][] = $row['field'];
            }
        }

        if (in_array(ChangeLog::STATUS_PENDING, $statuses, true)) {
            return $this->result($id, false, 'interrupted', __('An earlier attempt to save this item did not finish, so it was not sent again. Reload the page to see its current values.', 'madebyhype-stockmanagment'), $extra);
        }

        if (in_array(ChangeLog::STATUS_FAILED, $statuses, true) && !array_intersect($statuses, [ChangeLog::STATUS_APPLIED, ChangeLog::STATUS_UNDONE])) {
            return $this->result($id, false, 'save_failed', $failure, $extra);
        }

        return $this->result($id, true, 'already_saved', __('Already saved.', 'madebyhype-stockmanagment'), $extra);
    }

    /**
     * Item result of contract v2. The 1.0.6 script reads id, success and message.
     *
     * @param int    $id
     * @param bool   $success
     * @param string $code    Success: saved, unchanged, saved_with_error, already_saved.
     *                        Failure: invalid_id, invalid_fields, duplicate_item, not_found, forbidden,
     *                        invalid_value, log_failed, save_failed, interrupted.
     * @param string $message
     * @param array  $extra   Overrides for type, field, values, changed, conflicts
     * @return array
     */
    private function result($id, $success, $code, $message, $extra = [])
    {
        return array_merge([
            'id' => $id,
            // Real WooCommerce type (simple, variable, variation, ...); null when the item was not found
            'type' => null,
            'success' => $success,
            'code' => $code,
            'message' => $message,
            // For invalid_value: the field that was refused, when it is one field
            'field' => null,
            // What the product holds now, see read_state(); null when the item was not found
            'values' => null,
            // Tracked fields this save really changed
            'changed' => [],
            // List of ['field' => string, 'seen' => mixed, 'current' => mixed], see detect_conflicts()
            'conflicts' => [],
        ], $extra);
    }

    /**
     * Load the item an id really refers to
     *
     * The products bucket takes any product type: with "include variations"
     * the screen sends variation rows there. The variations bucket keeps its
     * 1.0.6 check and only takes real variations.
     *
     * @return \WC_Product|null
     */
    private function resolve_item($id, $bucket)
    {
        $item = \wc_get_product($id);

        if (!$item) {
            return null;
        }

        if ($bucket === 'variations' && !$item->is_type('variation')) {
            return null;
        }

        return $item;
    }

    /**
     * Fields a request may edit on this item
     *
     * LEGACY RULE: the variations bucket does not accept sale_price, as in
     * 1.0.6. Everywhere else all four fields are accepted for every type.
     *
     * @param \WC_Product $item
     * @param string      $bucket
     * @return array
     */
    private function editable_fields($item, $bucket)
    {
        if ($bucket === 'variations') {
            return ['stock_quantity', 'stock_status', 'regular_price'];
        }

        return ['stock_quantity', 'stock_status', 'regular_price', 'sale_price'];
    }

    /**
     * @param array $fields Field names the request wants to edit
     * @return string Empty when allowed, otherwise the refusal message
     */
    private function authorise($fields)
    {
        if (array_intersect($fields, self::STOCK_FIELDS) && !Capabilities::can_edit_stock()) {
            return __('You are not allowed to edit stock.', 'madebyhype-stockmanagment');
        }

        if (array_intersect($fields, self::PRICE_FIELDS) && !Capabilities::can_edit_prices()) {
            return __('You are not allowed to edit prices.', 'madebyhype-stockmanagment');
        }

        return '';
    }

    /**
     * Validate the submitted fields for one product or variation
     *
     * Nothing is written here. An empty or non-numeric value must never be cast
     * to 0: an empty sale price ends the sale, any other empty value is an error.
     *
     * @param \WC_Product $item Product or variation the fields belong to
     * @param array $fields Raw field => value pairs from the request
     * @param array $allowed Field names this item accepts
     * @return array ['fields' => cleaned values] or ['error' => message, 'field' => name or null]
     */
    private function validate_fields($item, $fields, $allowed)
    {
        $clean = [];

        foreach ($fields as $key => $value) {
            if (!in_array($key, $allowed, true)) {
                continue;
            }

            // Anything but a plain value is refused. It must never fall through
            // as an empty string, which for a sale price means "end the sale".
            if (!is_scalar($value)) {
                return ['error' => __('Invalid value.', 'madebyhype-stockmanagment'), 'field' => $key];
            }

            $value = trim((string) $value);

            switch ($key) {
                case 'stock_quantity':
                    if ($value === '' || !is_numeric($value)) {
                        return ['error' => __('Stock quantity must be a number. It cannot be left empty.', 'madebyhype-stockmanagment'), 'field' => $key];
                    }
                    if ((float) $value < 0) {
                        return ['error' => __('Stock quantity cannot be negative.', 'madebyhype-stockmanagment'), 'field' => $key];
                    }
                    $clean[$key] = (int) $value;
                    break;
                case 'stock_status':
                    if (!in_array($value, ['instock', 'outofstock', 'onbackorder'], true)) {
                        return ['error' => __('Unknown stock status.', 'madebyhype-stockmanagment'), 'field' => $key];
                    }
                    $clean[$key] = $value;
                    break;
                case 'regular_price':
                    if ($value === '') {
                        return ['error' => __('Regular price cannot be left empty.', 'madebyhype-stockmanagment'), 'field' => $key];
                    }
                    if (!is_numeric($value) || (float) $value < 0) {
                        return ['error' => __('Regular price must be a number of 0 or more.', 'madebyhype-stockmanagment'), 'field' => $key];
                    }
                    $clean[$key] = \wc_format_decimal($value);
                    break;
                case 'sale_price':
                    // An empty sale price ends the sale
                    if ($value === '') {
                        $clean[$key] = '';
                        break;
                    }
                    if (!is_numeric($value) || (float) $value < 0) {
                        return ['error' => __('Sale price must be a number of 0 or more.', 'madebyhype-stockmanagment'), 'field' => $key];
                    }
                    $clean[$key] = \wc_format_decimal($value);
                    break;
            }
        }

        if (empty($clean)) {
            return ['error' => __('Nothing to save for this item.', 'madebyhype-stockmanagment'), 'field' => null];
        }

        // WooCommerce silently drops a sale price that is not below the regular
        // price, so refuse it here and say why
        if (array_key_exists('regular_price', $clean) || array_key_exists('sale_price', $clean)) {
            $regular_price = array_key_exists('regular_price', $clean) ? $clean['regular_price'] : $item->get_regular_price();
            $sale_price = array_key_exists('sale_price', $clean) ? $clean['sale_price'] : $item->get_sale_price();

            if ($sale_price !== '' && ($regular_price === '' || (float) $sale_price >= (float) $regular_price)) {
                return ['error' => __('Sale price must be lower than the regular price.', 'madebyhype-stockmanagment'), 'field' => 'sale_price'];
            }
        }

        return ['fields' => $clean];
    }

    /**
     * SEAM, not implemented: compare what the user was looking at with what
     * the product holds now
     *
     * Today a save overwrites whatever is there, so nothing is compared and
     * nothing is reported. The rule that decides what counts as a conflict,
     * and whether it blocks the write, belongs here.
     *
     * @param array $fields Validated field => new value
     * @param array $seen   field => the value the user saw, as a trimmed string; only fields the client sent it for
     * @param array $before read_state() of the item before the edit
     * @return array List of ['field' => string, 'seen' => mixed, 'current' => mixed], returned to the client as "conflicts"
     */
    private function detect_conflicts($fields, $seen, $before)
    {
        return [];
    }

    /**
     * Apply validated fields to a product or variation (does not save)
     *
     * Each rule is its own method so it can be replaced on its own.
     *
     * @param \WC_Product $item Product or variation object
     * @param array $fields Validated field => value pairs
     * @return array Messages describing what was set
     */
    private function apply_fields($item, $fields)
    {
        $messages = [];
        $this->force_stock_tracking($item);

        foreach ($fields as $key => $value) {
            switch ($key) {
                case 'stock_quantity':
                    $this->set_stock_absolute($item, $value);
                    $messages[] = "Stock set to $value";
                    break;
                case 'stock_status':
                    $this->apply_stock_status($item, $value);
                    $messages[] = "Stock status set to $value";
                    break;
                case 'regular_price':
                    $item->set_regular_price($value);
                    $messages[] = "Regular price set to $value";
                    break;
                case 'sale_price':
                    $item->set_sale_price($value);
                    $messages[] = $value === '' ? 'Sale price cleared' : "Sale price set to $value";
                    break;
            }
        }

        return $messages;
    }

    /**
     * LEGACY RULE, kept as it was in 1.0.6: every save turns stock tracking on
     * for the item itself, whichever field was edited, a price included. A
     * variation that was using its parent's stock stops doing so.
     */
    private function force_stock_tracking($item)
    {
        $item->set_manage_stock(true);
    }

    /**
     * LEGACY RULE, kept as it was in 1.0.6: the quantity is written as an
     * absolute value, on the item itself. Stock sold between loading the
     * screen and saving is overwritten.
     */
    private function set_stock_absolute($item, $quantity)
    {
        $item->set_stock_quantity($quantity);
    }

    /**
     * LEGACY RULE, kept as it was in 1.0.6: the status is never set directly.
     * "On backorder" allows backorders, "out of stock" zeroes the quantity and
     * disallows backorders, "in stock" does nothing; WooCommerce then derives
     * the real status from quantity and backorders when it saves.
     */
    private function apply_stock_status($item, $status)
    {
        if ($status === 'onbackorder') {
            $item->set_backorders('yes');
        } elseif ($status === 'outofstock') {
            $item->set_stock_quantity(0);
            $item->set_backorders('no');
        }
    }

    /**
     * The tracked properties of an item as stored, not as displayed, plus
     * where its stock is held
     *
     * The six tracked fields are read in WooCommerce's 'edit' context: no
     * display filters, and a variation reports its own settings, never its
     * parent's. So for a variation that uses its parent's stock, manage_stock
     * is false and stock_quantity is null here; stock_mode and
     * stock_managed_by_id say where the stock really is.
     *
     * @param \WC_Product $item
     * @return array stock_quantity int|float|null, stock_status string, manage_stock bool,
     *               backorders 'no'|'notify'|'yes', regular_price string, sale_price string ('' for none),
     *               stock_mode 'own' (tracks its own stock), 'parent' (a variation using the stock of
     *               its variable product) or 'none' (stock is not tracked),
     *               stock_managed_by_id int|null: the product that holds the stock, null for 'none'
     */
    private function read_state($item)
    {
        // The 'view' context is what resolves a variation to its parent
        $managed = $item->get_manage_stock();
        $stock_mode = $managed === 'parent' ? 'parent' : ($managed ? 'own' : 'none');

        return [
            'stock_quantity' => $item->get_stock_quantity('edit'),
            'stock_status' => $item->get_stock_status('edit'),
            'manage_stock' => $item->get_manage_stock('edit'),
            'backorders' => $item->get_backorders('edit'),
            'regular_price' => $item->get_regular_price('edit'),
            'sale_price' => $item->get_sale_price('edit'),
            'stock_mode' => $stock_mode,
            'stock_managed_by_id' => $stock_mode === 'none' ? null : (int) $item->get_stock_managed_by_id(),
        ];
    }

    /**
     * Tracked fields whose stored form differs between two states
     *
     * @return array field => ['old' => value, 'new' => value]
     */
    private function diff_state($before, $after)
    {
        $changes = [];

        foreach (self::TRACKED_FIELDS as $field) {
            if (ChangeLog::encode_value($before[$field]) !== ChangeLog::encode_value($after[$field])) {
                $changes[$field] = ['old' => $before[$field], 'new' => $after[$field]];
            }
        }

        return $changes;
    }

    /**
     * What saving the edited object is going to change
     *
     * WooCommerce aligns the stock properties at the start of save(): it
     * derives the stock status, and clears quantity and backorders when stock
     * is not managed. Running that same public step now puts those side
     * effects into the plan, so they are logged before the write as well.
     *
     * @param \WC_Product $item   Item with the edit applied in memory
     * @param array       $before read_state() from before the edit
     * @return array field => ['old' => value, 'new' => value]
     */
    private function plan_changes($item, $before)
    {
        $item->validate_props();

        return $this->diff_state($before, $this->read_state($item));
    }

    /**
     * After the write: settle the pending rows against what the product
     * really holds, and log anything the save changed beyond the plan
     *
     * @param string $error Message of an error thrown after the write landed, '' for none
     */
    private function settle_changes($batch_id, $log_item, $row_ids, $before, $after, $error = '')
    {
        $actual = $this->diff_state($before, $after);

        foreach ($row_ids as $field => $row_id) {
            if (isset($actual[$field])) {
                $this->log->set_change_result($row_id, ChangeLog::STATUS_APPLIED, $after[$field], $error);
            } else {
                $this->log->set_change_status($row_id, ChangeLog::STATUS_SKIPPED, __('The save did not change this value.', 'madebyhype-stockmanagment'));
            }
        }

        $unplanned = array_diff_key($actual, $row_ids);
        if (!empty($unplanned)) {
            $this->log->add_changes($batch_id, $log_item, $unplanned, ChangeLog::STATUS_APPLIED, __('Changed during the save, outside the planned edit.', 'madebyhype-stockmanagment'));
        }
    }

    /**
     * TRANSITION: the item's values in the shape and read context the legacy
     * snapshot has always used. Must be taken before the edit is applied.
     */
    private function legacy_snapshot($item, $bucket)
    {
        $snapshot = [
            'stock_quantity' => $item->get_stock_quantity(),
            'stock_status' => $item->get_stock_status(),
            'backorders' => $item->get_backorders(),
            'manage_stock' => $item->get_manage_stock(),
            'regular_price' => $item->get_regular_price(),
        ];

        if ($bucket !== 'variations') {
            $snapshot['sale_price'] = $item->get_sale_price();
        }

        return $snapshot;
    }

    /**
     * TRANSITION: the legacy snapshot files an item under the bucket it
     * arrived in, exactly as 1.0.6 did. Only the new items bucket, which has
     * no legacy counterpart, is filed by real type.
     */
    private function legacy_snapshot_bucket($item, $bucket)
    {
        if ($bucket === 'items') {
            return $item->is_type('variation') ? 'variations' : 'products';
        }

        return $bucket;
    }
}
