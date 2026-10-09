<?php

namespace MadeByHypeStockmanagment\Data;

use MadeByHypeStockmanagment\Capabilities;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * The write path: the only code in this plugin that changes products.
 *
 * A save and an undo share one second half (commit()): apply the decided
 * changes in memory, record them as pending in the change log, write, read
 * the product back, settle the log rows against what it really holds.
 *
 * What differs is how the changes are decided:
 * - a save judges each submitted field on its own, in a fixed order
 *   (decide()): permission, where the item keeps that value, the value
 *   itself, then the value the user was looking at;
 * - an undo judges each change of one earlier save (undo_item()).
 *
 * Invariants:
 * - A product is never written unless its change rows are already stored.
 *   With the unique key on those rows, an item-field is written at most once
 *   per batch, which is what makes a resent request harmless.
 * - No save changes whether an item tracks stock, except the explicit
 *   start_tracking field. Nothing changes a backorder setting.
 * - Stock that the user saw is written as a difference, in SQL, so a sale
 *   landing at the same moment is never overwritten.
 *
 * Product writes go through WooCommerce product objects and functions only.
 */
class WriteService
{
    const MAX_ITEMS_PER_BUCKET = 100;

    // Fields a request can send, in the fixed order they are judged and applied
    const REQUEST_FIELDS = ['start_tracking', 'stock_quantity', 'stock_status', 'regular_price', 'sale_price'];

    // The change-log field that records each request field
    const LOG_FIELD = [
        'start_tracking' => 'manage_stock',
        'stock_quantity' => 'stock_quantity',
        'stock_status' => 'stock_status',
        'regular_price' => 'regular_price',
        'sale_price' => 'sale_price',
    ];

    // Need the edit-prices permission; every other field needs edit-stock
    const PRICE_FIELDS = ['regular_price', 'sale_price'];

    // Product properties that are compared, logged and undone field by field
    const TRACKED_FIELDS = ['stock_quantity', 'stock_status', 'manage_stock', 'backorders', 'regular_price', 'sale_price'];

    // Requested changes an undo can reverse, in the order it handles them
    const UNDO_FIELDS = ['manage_stock', 'stock_quantity', 'stock_status', 'regular_price', 'sale_price'];

    const STATUSES = ['instock', 'outofstock', 'onbackorder'];

    private $log;

    /**
     * @param ChangeLog $log
     */
    public function __construct($log)
    {
        $this->log = $log;
    }

    /**
     * Save a set of stock and price edits
     *
     * Request:
     *
     *   items      => [id => fields]  any product or variation id, handled by its real type
     *   products   => [id => fields]  the same; the bucket the 1.0.6 script uses
     *   variations => [id => fields]  the same; the bucket the 1.0.6 script uses
     *   note       => string          optional, stored on the batch
     *   save_token => string          optional, 8-64 chars of A-Z a-z 0-9 _ -, made up by the client
     *                                 once per press of Save and sent with every request of that save,
     *                                 including a retry after a lost response
     *
     * Fields (REQUEST_FIELDS): start_tracking (the starting quantity),
     * stock_quantity, stock_status, regular_price, sale_price ('' ends the sale).
     *
     * Each field is either the new value, or
     * ['value' => new value, 'seen' => the value the user was looking at, 'confirmed' => 1].
     * - seen on stock_quantity: the difference (value - seen) is applied to the
     *   stock stored now. Without seen the value is written as it is.
     * - seen on stock_status and prices: the field is only written if the
     *   stored value still equals seen.
     * - confirmed: needed in the items bucket to store a price of 0.
     * Values are plain, unslashed strings or numbers.
     *
     * With a save token, all requests of one save share one batch, and a field
     * that already reached the log under that token is not written again: the
     * resend gets its stored outcome. Without one, each request is its own
     * batch and nothing is recognised as a resend.
     *
     * @param array  $request
     * @param string $source  Where the edit comes from, stored on the batch
     * @return array|\WP_Error ['batch_id' => int|null, 'results' => item results (see item_result()),
     *                         'summary' => ['total', 'success', 'errors' (items), 'fields' => status => count]],
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
        $summary = [
            'total' => 0,
            'success' => 0,
            'errors' => 0,
            'fields' => ['total' => 0, 'saved' => 0, 'adjusted' => 0, 'conflict' => 0, 'refused' => 0, 'dropped' => 0],
        ];

        foreach ($entries as $entry) {
            $result = $this->save_item($entry, $run);

            $summary['total']++;
            $summary[$result['success'] ? 'success' : 'errors']++;

            foreach ($result['fields'] as $field) {
                $summary['fields']['total']++;
                $summary['fields'][$field['status']]++;
            }

            $results[] = $result;
        }

        \wc_delete_product_transients();

        return [
            'batch_id' => $run['batch_id'] ? (int) $run['batch_id'] : null,
            'results' => $results,
            'summary' => $summary,
        ];
    }

    /**
     * What undoing a save would do, without doing it. Same result as undo().
     *
     * @param int $batch_id
     * @return array|\WP_Error
     */
    public function preview_undo($batch_id)
    {
        return $this->undo($batch_id, true);
    }

    /**
     * Undo one save
     *
     * Only the changes that save requested and that are still in force are
     * touched; every one is judged again at this moment (see undo_item()).
     * The undo is recorded as a batch of its own that points at the save. A
     * save that was only partly undone can be undone again: the second run
     * handles what the first one skipped.
     *
     * The request names a batch and nothing else; what to change comes from
     * the log.
     *
     * @param int  $batch_id
     * @param bool $dry_run  Decide and report, write nothing
     * @return array|\WP_Error [
     *     'batch_id'      => int, the save,
     *     'undo_batch_id' => int|null, the batch this undo recorded (null in a dry run or when nothing was undone),
     *     'preview'       => bool,
     *     'changes'       => one entry per change still in force, see undo_item(),
     *     'summary'       => ['total' => int, 'undo' => int, 'skip' => int],
     *     'message'       => string,
     *     'values'        => item id => read_state() after the undo (empty in a dry run),
     * ]
     * WP_Error codes: forbidden, not_found, not_undoable (an undo batch), already_undone, nothing_to_undo.
     */
    public function undo($batch_id, $dry_run = false)
    {
        if (!$this->can('undo')) {
            return new \WP_Error('forbidden', __('You do not have permission to undo saves.', 'madebyhype-stockmanagment'));
        }

        $batch = $this->log->get_batch((int) $batch_id);
        if (!$batch) {
            return new \WP_Error('not_found', __('This save is no longer in History.', 'madebyhype-stockmanagment'));
        }

        if ($batch['kind'] !== ChangeLog::KIND_SAVE) {
            return new \WP_Error('not_undoable', __('An undo cannot be undone.', 'madebyhype-stockmanagment'));
        }

        $rows_by_item = [];
        $open = 0;
        $undone = 0;

        foreach ($this->log->get_changes($batch['id']) as $row) {
            $rows_by_item[(int) $row['item_id']][] = $row;

            if ($row['typed_value'] !== null && $row['status'] === ChangeLog::STATUS_APPLIED) {
                $open++;
            } elseif ($row['typed_value'] !== null && $row['status'] === ChangeLog::STATUS_UNDONE) {
                $undone++;
            }
        }

        if ($open === 0) {
            return $undone > 0
                ? new \WP_Error('already_undone', __('This save has already been undone.', 'madebyhype-stockmanagment'))
                : new \WP_Error('nothing_to_undo', __('Nothing in this save can be undone.', 'madebyhype-stockmanagment'));
        }

        $run = [
            'source' => $batch['source'],
            'kind' => ChangeLog::KIND_UNDO,
            'undoes_batch_id' => (int) $batch['id'],
            'note' => '',
            'save_token' => '',
            'batch_id' => 0,
        ];

        $changes = [];
        $values = [];

        foreach ($rows_by_item as $item_id => $rows) {
            $outcome = $this->undo_item($item_id, $rows, $run, $dry_run);
            $changes = array_merge($changes, $outcome['changes']);

            if ($outcome['values'] !== null) {
                $values[$item_id] = $outcome['values'];
            }
        }

        if (!$dry_run) {
            \wc_delete_product_transients();
        }

        $total = count($changes);
        $undo = count(array_filter($changes, function ($change) {
            return $change['outcome'] === 'undo';
        }));

        if ($undo === 0) {
            $message = __('Nothing in this save can be undone now.', 'madebyhype-stockmanagment');
        } elseif ($dry_run) {
            $message = '';
        } elseif ($undo === $total) {
            /* translators: %d: number of changes */
            $message = sprintf(_n('Undid %d change.', 'Undid %d changes.', $undo, 'madebyhype-stockmanagment'), $undo);
        } else {
            /* translators: 1: changes undone, 2: changes in the save, 3: changes skipped */
            $message = sprintf(__('Undid %1$d of %2$d changes. %3$d skipped.', 'madebyhype-stockmanagment'), $undo, $total, $total - $undo);
        }

        return [
            'batch_id' => (int) $batch['id'],
            'undo_batch_id' => $undo > 0 && $run['batch_id'] ? (int) $run['batch_id'] : null,
            'preview' => (bool) $dry_run,
            'changes' => $changes,
            'summary' => ['total' => $total, 'undo' => $undo, 'skip' => $total - $undo],
            'message' => $message,
            'values' => $values,
        ];
    }

    /**
     * Which fields the rules let anyone edit on an item of this type whose
     * stock is held this way. Permissions come on top. The screen uses the
     * same answer to decide which cells are inputs.
     *
     * @param string $type       WooCommerce product type: simple, variable, variation, external, grouped, ...
     * @param string $stock_mode 'own' (the item tracks its own stock), 'parent' (a variation using the
     *                           stock of its variable product) or 'none' (stock is not tracked)
     * @return array request field => bool, for every REQUEST_FIELDS entry
     */
    public static function editable_fields($type, $stock_mode)
    {
        $editable = [];

        foreach (self::REQUEST_FIELDS as $field) {
            $editable[$field] = self::placement($field, $type, $stock_mode) === '';
        }

        return $editable;
    }

    /**
     * Why an item of this type and stock mode does not take a field, as a
     * result code; '' when it does
     *
     * Stock lives where WooCommerce keeps it: on the item when it tracks its
     * own, on the variable product when a variation inherits. Only the holder
     * is edited. A variable product never has a price or status of its own,
     * and external and grouped products hold no stock.
     */
    private static function placement($field, $type, $stock_mode)
    {
        if (in_array($field, self::PRICE_FIELDS, true)) {
            if ($type === 'variable') {
                return 'set_on_variations';
            }

            return $type === 'grouped' ? 'no_price' : '';
        }

        if ($type === 'external' || $type === 'grouped') {
            return 'no_stock';
        }

        if ($stock_mode === 'parent') {
            return 'inherits_stock';
        }

        if ($stock_mode === 'own') {
            // Tracked: the quantity is the one thing to edit; status follows it
            if ($field === 'stock_quantity') {
                return '';
            }

            return $field === 'start_tracking' ? 'already_tracked' : 'status_follows_stock';
        }

        if ($type === 'variable') {
            return 'set_on_variations';
        }

        // Untracked: status is set directly, tracking can be started, there is no quantity
        return $field === 'stock_quantity' ? 'not_tracked' : '';
    }

    /**
     * The one place the service asks what the current user may do
     *
     * @param string $permission 'stock', 'prices' or 'undo'
     * @return bool
     */
    protected function can($permission)
    {
        switch ($permission) {
            case 'stock':
                return Capabilities::can_edit_stock();
            case 'prices':
                return Capabilities::can_edit_prices();
            case 'undo':
                return Capabilities::can_undo();
        }

        return false;
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
                /* translators: %d: maximum number of items */
                return new \WP_Error('batch_too_large', sprintf(__('Batch size exceeds maximum allowed (%d items)', 'madebyhype-stockmanagment'), self::MAX_ITEMS_PER_BUCKET));
            }

            foreach ($items as $id => $fields) {
                $entries[] = ['id' => absint($id), 'bucket' => $bucket, 'fields' => $fields];
            }
        }

        if (empty($entries)) {
            return new \WP_Error('empty_request', __('No valid data provided', 'madebyhype-stockmanagment'));
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
     * @return array|\WP_Error ['source', 'kind', 'undoes_batch_id', 'note', 'save_token', 'batch_id' (0 until created),
     *                         'stored' => item id => its change rows from earlier requests,
     *                         'handled' => item id => true for the items of this request]
     */
    private function open_run($request, $source)
    {
        $run = [
            'source' => $source,
            'kind' => ChangeLog::KIND_SAVE,
            'undoes_batch_id' => null,
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
            return new \WP_Error('invalid_save_token', __('Invalid save token', 'madebyhype-stockmanagment'));
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
            return new \WP_Error('invalid_save_token', __('Invalid save token', 'madebyhype-stockmanagment'));
        }

        return $batch;
    }

    /**
     * Create the batch of this run the first time it is needed
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
            'kind' => $run['kind'],
            'undoes_batch_id' => $run['undoes_batch_id'],
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
     * Split one item's fields into the new values, the values the user saw
     * and the confirmations
     *
     * @param array $fields field => value, or field => ['value' => x, 'seen' => y, 'confirmed' => 1]
     * @return array ['values' => field => raw new value,
     *               'seen' => field => trimmed string, or false when it is not a plain value (only where sent),
     *               'confirmed' => field => true]
     */
    private function normalise_fields($fields)
    {
        $values = [];
        $seen = [];
        $confirmed = [];

        foreach ($fields as $key => $raw) {
            if (is_array($raw) && array_key_exists('value', $raw)) {
                if (array_key_exists('seen', $raw)) {
                    $seen[$key] = is_scalar($raw['seen']) ? trim((string) $raw['seen']) : false;
                }

                if (isset($raw['confirmed']) && is_scalar($raw['confirmed']) && \wc_string_to_bool($raw['confirmed'])) {
                    $confirmed[$key] = true;
                }

                $raw = $raw['value'];
            }

            $values[$key] = $raw;
        }

        return ['values' => $values, 'seen' => $seen, 'confirmed' => $confirmed];
    }

    /**
     * Run the whole pipeline for one item of a save
     *
     * @param array $entry ['id' => int, 'bucket' => string, 'fields' => mixed]
     * @param array $run   Batch state of this request, see open_run()
     * @return array Item result, see item_result()
     */
    private function save_item($entry, &$run)
    {
        $id = $entry['id'];

        if ($id <= 0) {
            return $this->item_failure($id, 'invalid_id', __('Invalid item id.', 'madebyhype-stockmanagment'));
        }

        if (!is_array($entry['fields']) || empty($entry['fields'])) {
            return $this->item_failure($id, 'invalid_fields', __('Nothing to save for this item.', 'madebyhype-stockmanagment'));
        }

        // The same id twice in one request: the second would be judged against
        // a product the first has just changed, and could not be logged
        if (isset($run['handled'][$id])) {
            return $this->item_failure($id, 'duplicate_item', __('This item was sent twice in one request. Only the first was processed.', 'madebyhype-stockmanagment'));
        }
        $run['handled'][$id] = true;

        $input = $this->normalise_fields($entry['fields']);

        $item = \wc_get_product($id);
        if (!$item) {
            $message = self::not_saved(__('This product no longer exists.', 'madebyhype-stockmanagment'));
            $fields = [];

            foreach (array_keys($input['values']) as $field) {
                $fields[$field] = $this->field_result('dropped', 'not_found', $message);
            }

            return $this->item_result($id, null, null, [], $fields);
        }

        $type = $item->get_type();
        $before = $this->read_state($item);
        $stored = isset($run['stored'][$id]) ? $run['stored'][$id] : [];

        // An earlier request of this save died while writing this item. Whether
        // the product changed is unknown, so nothing more is written to it.
        if (in_array(ChangeLog::STATUS_PENDING, wp_list_pluck($stored, 'status'), true)) {
            $message = __('An earlier attempt to save this item did not finish, so it was not sent again. Reload the page to see its current values.', 'madebyhype-stockmanagment');
            $fields = [];

            foreach (array_keys($input['values']) as $field) {
                $fields[$field] = $this->field_result('refused', 'interrupted', $message, null, null, $this->current_value($field, $before));
            }

            return $this->item_result($id, $type, $before, [], $fields);
        }

        $decision = $this->decide($type, $before, $input, $entry['bucket'], $stored);
        $fields = $decision['fields'];
        $after = $before;
        $error = '';

        if (!empty($decision['intent'])) {
            $commit = $this->commit($item, $before, $decision['intent'], $decision['typed'], $run);
            $after = $commit['after'];
            $error = $commit['state'] === 'written' ? $commit['error'] : '';

            foreach ($decision['accepted'] as $field => $accepted) {
                $fields[$field] = $this->accepted_result($field, $accepted, $before, $commit);
            }
        }

        // Reported in the fixed field order too, whatever order they were settled in
        $ordered = [];
        foreach (array_merge(self::REQUEST_FIELDS, array_keys($fields)) as $field) {
            if (isset($fields[$field])) {
                $ordered[$field] = $fields[$field];
            }
        }

        return $this->item_result($id, $type, $after, array_keys($this->diff_state($before, $after)), $ordered, $error);
    }

    /**
     * Judge every submitted field of one item, in the fixed order
     *
     * Per field: (1) a field this save token already logged is reported, not
     * judged; (2) the user may edit that kind of field; (3) the item keeps
     * that value itself (placement()); (4) the value is valid; (5) the rule
     * of the field, including the value the user was looking at.
     *
     * A field that passes becomes part of the intent. Every other field gets
     * its result here, and the rest of the item carries on without it.
     *
     * @param string $type   Product type
     * @param array  $before read_state() of the item
     * @param array  $input  normalise_fields()
     * @param string $bucket Request bucket the item came in
     * @param array  $stored Change rows this save token already holds for the item
     * @return array ['fields' => request field => result, for the fields that are settled,
     *               'intent' => what commit() is to do (see commit()),
     *               'typed' => log field => value asked for,
     *               'accepted' => request field => ['typed' => clean value, 'seen' => string|null, ...]]
     */
    private function decide($type, $before, $input, $bucket, $stored)
    {
        $fields = [];
        $intent = [];
        $typed = [];
        $accepted = [];

        $logged = [];
        foreach ($stored as $row) {
            if ($row['typed_value'] !== null) {
                $logged[$row['field']] = $row;
            }
        }

        foreach (array_keys($input['values']) as $field) {
            if (!in_array($field, self::REQUEST_FIELDS, true)) {
                $fields[$field] = $this->field_result('refused', 'unknown_field', self::not_saved(__('This field cannot be edited here.', 'madebyhype-stockmanagment')));
            }
        }

        // Asking for a starting quantity and a stock change at once cannot be
        // read one way; neither is applied
        $ambiguous = array_key_exists('start_tracking', $input['values']) && array_key_exists('stock_quantity', $input['values']);

        foreach (self::REQUEST_FIELDS as $field) {
            if (!array_key_exists($field, $input['values'])) {
                continue;
            }

            $current = $this->current_value($field, $before);
            $seen = array_key_exists($field, $input['seen']) ? $input['seen'][$field] : null;
            $is_price = in_array($field, self::PRICE_FIELDS, true);

            if (isset($logged[self::LOG_FIELD[$field]])) {
                $fields[$field] = $this->logged_result($field, $logged[self::LOG_FIELD[$field]], $current);
                continue;
            }

            if (!$this->can($is_price ? 'prices' : 'stock')) {
                $sentence = $is_price
                    ? __('You do not have permission to change prices.', 'madebyhype-stockmanagment')
                    : __('You do not have permission to change stock.', 'madebyhype-stockmanagment');
                $fields[$field] = $this->field_result('refused', 'forbidden', self::not_saved($sentence), null, $seen, $current);
                continue;
            }

            // Tracking that starts in this save counts for the status sent with it
            $stock_mode = isset($intent['start']) ? 'own' : $before['stock_mode'];
            $code = self::placement($field, $type, $stock_mode);
            if ($code !== '') {
                $status = in_array($code, ['not_tracked', 'already_tracked'], true) ? 'dropped' : 'refused';
                $fields[$field] = $this->field_result($status, $code, self::not_saved($this->placement_sentence($code, $field, $before, $seen !== null)), null, $seen, $current);
                continue;
            }

            if ($ambiguous && !$is_price && $field !== 'stock_status') {
                $fields[$field] = $this->field_result('refused', 'invalid_value', self::not_saved(__('Send either a starting quantity or a stock change for one product, not both.', 'madebyhype-stockmanagment')), null, $seen, $current);
                continue;
            }

            $clean = $this->clean_value($field, $input['values'][$field]);
            if (isset($clean['error'])) {
                $fields[$field] = $this->field_result('refused', 'invalid_value', self::not_saved($clean['error']), null, $seen, $current);
                continue;
            }
            $value = $clean['value'];

            if ($seen === false) {
                $fields[$field] = $this->field_result('refused', 'invalid_value', self::not_saved(__('Invalid value.', 'madebyhype-stockmanagment')), $value, null, $current);
                continue;
            }

            switch ($field) {
                case 'start_tracking':
                    $verdict = ['intent' => ['start' => $value]];
                    break;
                case 'stock_quantity':
                    $verdict = $this->decide_stock($value, $seen, $before);
                    break;
                case 'stock_status':
                    $verdict = $this->decide_status($value, $seen, $before);
                    break;
                default:
                    $confirmed = $bucket !== 'items' || isset($input['confirmed'][$field]);
                    $verdict = $this->decide_price($field, $value, $seen, $before, $confirmed);
                    break;
            }

            if (isset($verdict['result'])) {
                $fields[$field] = $verdict['result'];
                continue;
            }

            $intent += $verdict['intent'];
            $typed[self::LOG_FIELD[$field]] = $value;
            $accepted[$field] = ['typed' => $value, 'seen' => $seen];
        }

        // A sale price must stay below the regular price as it will be after
        // this save. WooCommerce silently drops one that is not, so refuse it
        // here and say why. The sale price gives way first.
        foreach (['sale_price', 'regular_price'] as $field) {
            $regular = isset($intent['regular_price']) ? $intent['regular_price'] : $before['regular_price'];
            $sale = isset($intent['sale_price']) ? $intent['sale_price'] : $before['sale_price'];

            if (!isset($intent[$field]) || self::price_pair_ok($regular, $sale)) {
                continue;
            }

            /* translators: %s: regular price */
            $sentence = sprintf(__('Sale price must be lower than the regular price (%s).', 'madebyhype-stockmanagment'), self::display_value('regular_price', $regular));
            $fields[$field] = $this->field_result('refused', 'invalid_value', self::not_saved($sentence), $intent[$field], $accepted[$field]['seen'], $before[$field]);
            unset($intent[$field], $typed[$field], $accepted[$field]);
        }

        return ['fields' => $fields, 'intent' => $intent, 'typed' => $typed, 'accepted' => $accepted];
    }

    /**
     * Stock rule
     *
     * With the value the user saw: the difference between what they typed and
     * what they saw is applied to the stock stored now, so units sold in
     * between stay sold. It is a conflict only when the result would be below
     * zero. Without a seen value (the 1.0.6 script): the typed value is
     * written as it is.
     *
     * @param int|float   $value  Typed quantity
     * @param string|null $seen   Quantity the user saw ('' for an empty one), null when not sent
     * @param array       $before
     * @return array ['result' => field result] or ['intent' => ['stock_delta' => n] or ['stock_set' => n]]
     */
    private function decide_stock($value, $seen, $before)
    {
        $current = $before['stock_quantity'];
        $stored = $current === null ? 0 : $current + 0;

        if ($seen === null) {
            if ($current !== null && $stored == $value) {
                return ['result' => $this->field_result('saved', 'unchanged', __('No change.', 'madebyhype-stockmanagment'), $value, null, $current)];
            }

            return ['intent' => ['stock_set' => $value]];
        }

        if ($seen !== '' && !preg_match('/^-?\d+$/', $seen)) {
            return ['result' => $this->field_result('refused', 'invalid_value', self::not_saved(__('Invalid value.', 'madebyhype-stockmanagment')), $value, $seen, $current)];
        }

        $delta = $value - (int) $seen;
        $result = $stored + $delta;

        if ($result < 0) {
            $sentence = $delta < 0
                /* translators: 1: stock the user saw, 2: stock now, 3: the change, with its sign */
                ? __('Stock changed from %1$s to %2$s since you loaded the page. Your change of %3$s would take it below zero.', 'madebyhype-stockmanagment')
                /* translators: 1: stock the user saw, 2: stock now, 3: the change, with its sign */
                : __('Stock changed from %1$s to %2$s since you loaded the page. Your change of %3$s would still leave it below zero.', 'madebyhype-stockmanagment');

            return ['result' => $this->field_result(
                'conflict',
                'stock_conflict',
                self::not_saved(sprintf($sentence, self::display_value('stock_quantity', $seen === '' ? null : (int) $seen), self::display_value('stock_quantity', $current), self::signed($delta))),
                $value,
                $seen,
                $current
            )];
        }

        // An empty quantity cannot be adjusted in SQL (NULL + n stays NULL),
        // and nothing can sell from it in the meantime: write the result
        if ($current === null) {
            return ['intent' => ['stock_set' => $result]];
        }

        if ($delta == 0) {
            return ['result' => $this->field_result('saved', 'unchanged', __('No change.', 'madebyhype-stockmanagment'), $value, $seen, $current)];
        }

        return ['intent' => ['stock_delta' => $delta]];
    }

    /**
     * Status rule, for the one case a status is set at all: an item that does
     * not track stock (placement() refuses the others). The status is stored
     * as chosen; quantity, tracking and backorders are not touched.
     *
     * @return array ['result' => field result] or ['intent' => ['stock_status' => value]]
     */
    private function decide_status($value, $seen, $before)
    {
        $current = $before['stock_status'];

        if ($value === $current) {
            return ['result' => $this->field_result('saved', 'unchanged', __('No change.', 'madebyhype-stockmanagment'), $value, $seen, $current)];
        }

        if ($seen !== null && $seen !== $current) {
            return ['result' => $this->value_conflict('stock_status', $value, $seen, $seen, $current)];
        }

        return ['intent' => ['stock_status' => $value]];
    }

    /**
     * Price rule: written only if the stored price is still the one the user
     * saw (when sent), and a price of 0 only when confirmed
     *
     * @param bool $confirmed Whether a price of 0 may be stored
     * @return array ['result' => field result] or ['intent' => [field => value]]
     */
    private function decide_price($field, $value, $seen, $before, $confirmed)
    {
        $current = $before[$field];

        if (self::same_price($value, $current)) {
            return ['result' => $this->field_result('saved', 'unchanged', __('No change.', 'madebyhype-stockmanagment'), $value, $seen, $current)];
        }

        if ($seen !== null) {
            $saw = $this->parse_price($seen);

            if ($saw === false) {
                return ['result' => $this->field_result('refused', 'invalid_value', self::not_saved(__('Invalid value.', 'madebyhype-stockmanagment')), $value, $seen, $current)];
            }

            if (!self::same_price($saw, $current)) {
                return ['result' => $this->value_conflict($field, $value, $seen, $saw, $current)];
            }
        }

        if ($value !== '' && (float) $value == 0 && !$confirmed) {
            return ['result' => $this->field_result('refused', 'zero_price_unconfirmed', self::not_saved(__('A price of 0 must be confirmed before saving.', 'madebyhype-stockmanagment')), $value, $seen, $current)];
        }

        return ['intent' => [$field => $value]];
    }

    /**
     * A price or status that someone else changed since the page was loaded:
     * nothing is written, nothing is merged
     */
    private function value_conflict($field, $value, $seen, $saw, $current)
    {
        /* translators: 1: field name, 2: value the user saw, 3: value now */
        $sentence = sprintf(
            __('%1$s was changed from %2$s to %3$s since you loaded the page.', 'madebyhype-stockmanagment'),
            self::field_label($field),
            self::display_value($field, $saw),
            self::display_value($field, $current)
        );

        return $this->field_result('conflict', 'value_conflict', self::not_saved($sentence), $value, $seen, $current);
    }

    /**
     * Validate and clean one submitted value. Nothing is written here.
     *
     * An empty or non-numeric value is never cast to 0: an empty sale price
     * ends the sale, any other empty value is an error.
     *
     * @param string $field One of REQUEST_FIELDS
     * @param mixed  $value Raw value from the request
     * @return array ['value' => clean value] or ['error' => sentence]
     */
    private function clean_value($field, $value)
    {
        // Anything but a plain value is refused. It must never fall through
        // as an empty string, which for a sale price means "end the sale".
        if (!is_scalar($value)) {
            return ['error' => __('Invalid value.', 'madebyhype-stockmanagment')];
        }

        $value = trim((string) $value);

        switch ($field) {
            case 'start_tracking':
            case 'stock_quantity':
                if (!preg_match('/^\d+(\.0+)?$/', $value)) {
                    return ['error' => __('Enter a whole number, 0 or more.', 'madebyhype-stockmanagment')];
                }
                return ['value' => (int) $value];
            case 'stock_status':
                if (!in_array($value, self::STATUSES, true)) {
                    return ['error' => __('Unknown stock status.', 'madebyhype-stockmanagment')];
                }
                return ['value' => $value];
        }

        // regular_price, sale_price
        if ($value === '') {
            return $field === 'sale_price'
                ? ['value' => '']
                : ['error' => __('Enter a regular price.', 'madebyhype-stockmanagment')];
        }

        $price = $this->parse_price($value);
        if ($price === false || (float) $price < 0) {
            return ['error' => __('Enter a price of 0 or more.', 'madebyhype-stockmanagment')];
        }

        return ['value' => $price];
    }

    /**
     * A price as typed, with a dot or the shop's decimal separator
     *
     * @param string $text
     * @return string|false '' for an empty text, the price in WooCommerce's stored form, false when it is not a number
     */
    private function parse_price($text)
    {
        $text = trim((string) $text);
        if ($text === '') {
            return '';
        }

        $separator = \wc_get_price_decimal_separator();
        if ($separator !== '.' && $separator !== '') {
            $text = str_replace($separator, '.', $text);
        }

        // Digits with at most one decimal point: no exponent, no thousands separator
        return preg_match('/^-?(\d+(\.\d*)?|\.\d+)$/', $text) ? \wc_format_decimal($text) : false;
    }

    /**
     * @param string $regular Regular price, '' for none
     * @param string $sale    Sale price, '' for none
     * @return bool Whether the two can be stored together
     */
    private static function price_pair_ok($regular, $sale)
    {
        return (string) $sale === '' || ((string) $regular !== '' && (float) $sale < (float) $regular);
    }

    private static function same_price($a, $b)
    {
        return ChangeLog::same_value((string) $a, (string) $b);
    }

    /**
     * Write one item and record it: the shared second half of a save and of an undo
     *
     * Order: apply the intent in memory, work out everything the write will
     * change (derived changes included), store those rows as pending, write,
     * read the product back, settle the rows against what it really holds.
     *
     * @param \WC_Product   $item   Freshly loaded, nothing set on it yet
     * @param array         $before read_state() of it
     * @param array         $intent What to do. Keys, in the order they are applied:
     *                              'start' => int          start tracking at this quantity
     *                              'stop' => string|null   stop tracking; the status to restore (null: leave it)
     *                              'stock_set' => number   write this quantity
     *                              'stock_delta' => number add this to the stored quantity, in SQL
     *                              'stock_status', 'regular_price', 'sale_price' => value to store
     * @param array         $typed  log field => the value asked for; marks the rows that are requested changes
     * @param array         $run
     * @param callable|null $claim  Called once the rows are stored and before the write; returning false cancels
     * @return array ['state' => 'none' (nothing would change) | 'log_failed' | 'cancelled' | 'failed' (nothing written) | 'written',
     *               'after' => read_state() now,
     *               'rows' => log field => 'applied' | 'skipped' | 'failed' | 'reversed',
     *               'old' => log field => the value the write really started from (stock adjustments only),
     *               'error' => message of an error thrown during the write, '' for none]
     */
    private function commit($item, $before, $intent, $typed, &$run, $claim = null)
    {
        $id = $item->get_id();
        $outcome = ['state' => 'none', 'after' => $before, 'rows' => [], 'old' => [], 'error' => ''];
        $delta = isset($intent['stock_delta']) ? $intent['stock_delta'] : 0;

        // In memory only: nothing is written until the rows are stored
        try {
            $this->apply_intent($item, $intent);
            $planned = $this->plan_changes($item, $before, $intent);
        } catch (\Throwable $e) {
            $outcome['state'] = 'failed';
            $outcome['error'] = $e->getMessage();
            return $outcome;
        }

        // Nothing would change: the product is left alone and nothing is logged
        if (empty($planned)) {
            return $outcome;
        }

        foreach ($planned as $field => $change) {
            $planned[$field]['typed'] = array_key_exists($field, $typed) ? $typed[$field] : null;
        }

        $log_item = [
            'item_id' => $id,
            'product_id' => $item->get_parent_id() ? $item->get_parent_id() : $id,
            'item_type' => $item->get_type(),
        ];

        // The log comes first: no rows, no write
        $row_ids = $this->ensure_batch($run) ? $this->log->add_changes($run['batch_id'], $log_item, $planned) : false;
        if ($row_ids === false) {
            $outcome['state'] = 'log_failed';
            return $outcome;
        }

        if ($claim && !call_user_func($claim)) {
            foreach ($row_ids as $row_id) {
                $this->log->set_change_status($row_id, ChangeLog::STATUS_SKIPPED, __('Not written: this change was already undone.', 'madebyhype-stockmanagment'));
            }

            // Whoever got there first has changed the product in the meantime
            $outcome['state'] = 'cancelled';
            $outcome['after'] = $this->reload_state($id, $before);
            return $outcome;
        }

        $error = '';
        $adjustments = did_action('woocommerce_updated_product_stock');

        try {
            if ($delta) {
                $this->adjust_stock($item, $delta);
            }

            // adjust_stock() saves the item with everything else set on it;
            // when there is no adjustment, or WooCommerce declined it, save here
            if (did_action('woocommerce_updated_product_stock') === $adjustments) {
                $item->save();
            }
        } catch (\Throwable $e) {
            $error = $e->getMessage() !== '' ? $e->getMessage() : get_class($e);
        }

        $adjusted = $delta && did_action('woocommerce_updated_product_stock') > $adjustments;
        $reversed = false;

        // What the product holds now decides the outcome, not whether the
        // write returned: an error thrown by a hook after the write leaves the
        // product changed, and the log has to say so
        $after = $this->reload_state($id, $error === '' ? $this->read_state($item) : $before);

        // A sale landed between the read and the adjustment and took the stock
        // below zero with it: take the adjustment back out, the sale stays
        if ($adjusted && $after['stock_quantity'] !== null && $after['stock_quantity'] < 0) {
            try {
                $this->adjust_stock(\wc_get_product($id), -$delta);
            } catch (\Throwable $e) {
                $error = $error !== '' ? $error : $e->getMessage();
            }

            $reversed = true;
            $after = $this->reload_state($id, $after);
        }

        $actual = $this->diff_state($before, $after);
        $wrote = !empty($actual) || ($adjusted && !$reversed);

        foreach ($row_ids as $field => $row_id) {
            if ($field === 'stock_quantity' && $delta) {
                if ($reversed) {
                    $outcome['rows'][$field] = 'reversed';
                    $this->log->set_change_status($row_id, ChangeLog::STATUS_SKIPPED, __('Taken back out: the stock had dropped in the meantime and would have gone below zero.', 'madebyhype-stockmanagment'));
                } elseif ($adjusted) {
                    // The adjustment is exact even when the stock moved underneath
                    // it: record the value it landed on and the value it started from
                    $outcome['rows'][$field] = 'applied';
                    $outcome['old'][$field] = $after['stock_quantity'] === null ? $before['stock_quantity'] : $after['stock_quantity'] - $delta;
                    $this->log->set_change_status($row_id, ChangeLog::STATUS_APPLIED, $error, ['old' => $outcome['old'][$field], 'new' => $after['stock_quantity']]);
                } else {
                    $outcome['rows'][$field] = 'failed';
                    $this->log->set_change_status($row_id, ChangeLog::STATUS_FAILED, $error !== '' ? $error : __('WooCommerce did not apply the stock adjustment.', 'madebyhype-stockmanagment'));
                }
            } elseif (isset($actual[$field])) {
                $outcome['rows'][$field] = 'applied';
                $this->log->set_change_status($row_id, ChangeLog::STATUS_APPLIED, $error, ['new' => $after[$field]]);
            } elseif ($error !== '') {
                $outcome['rows'][$field] = 'failed';
                $this->log->set_change_status($row_id, ChangeLog::STATUS_FAILED, $error);
            } else {
                $outcome['rows'][$field] = 'skipped';
                $this->log->set_change_status($row_id, ChangeLog::STATUS_SKIPPED, __('The save did not change this value.', 'madebyhype-stockmanagment'));
            }
        }

        // Anything the write changed beyond the plan is logged as well
        $unplanned = array_diff_key($actual, $row_ids);
        if (!empty($unplanned)) {
            $this->log->add_changes($run['batch_id'], $log_item, $unplanned, ChangeLog::STATUS_APPLIED, __('Changed during the save, outside the planned edit.', 'madebyhype-stockmanagment'));
        }

        if ($error !== '') {
            error_log('MadeByHype Stock Management - Error while saving item ' . $id . ': ' . $error);
        }

        $outcome['state'] = $wrote || $error === '' ? 'written' : 'failed';
        $outcome['after'] = $after;
        $outcome['error'] = $error;

        return $outcome;
    }

    /**
     * Set the decided values on the product object (does not save)
     *
     * The order is fixed and is the only order: it does not depend on how the
     * request listed the fields. A stock adjustment is not set here; it is
     * made in SQL by adjust_stock().
     *
     * @param \WC_Product $item
     * @param array       $intent See commit()
     */
    private function apply_intent($item, $intent)
    {
        if (isset($intent['start'])) {
            $item->set_manage_stock(true);
            $item->set_stock_quantity($intent['start']);
        }

        if (array_key_exists('stop', $intent)) {
            $item->set_manage_stock(false);

            if ($intent['stop'] !== null) {
                $item->set_stock_status($intent['stop']);
            }
        }

        if (isset($intent['stock_set'])) {
            $item->set_stock_quantity($intent['stock_set']);
        }

        if (isset($intent['stock_status'])) {
            $item->set_stock_status($intent['stock_status']);
        }

        if (isset($intent['regular_price'])) {
            $item->set_regular_price($intent['regular_price']);
        }

        if (isset($intent['sale_price'])) {
            $item->set_sale_price($intent['sale_price']);

            // No sale price, no sale: the schedule goes with it
            if ($intent['sale_price'] === '') {
                $item->set_date_on_sale_from('');
                $item->set_date_on_sale_to('');
            }
        }
    }

    /**
     * Add to, or take from, the stored quantity in a single SQL statement
     *
     * wc_update_product_stock() runs "meta_value = meta_value + n", so the
     * result is right whatever landed since the quantity was read. It then
     * saves the product object it was given, which stores everything else set
     * on it and lets WooCommerce derive the stock status, and it fires the
     * stock hooks other plugins listen to: woocommerce_product_before_set_stock,
     * woocommerce_updated_product_stock, woocommerce_product_set_stock (the
     * variation_ forms for a variation) and, through the save, the product
     * save and stock status hooks.
     *
     * It does nothing when WooCommerce does not consider the item stock
     * managed (stock management switched off shop-wide); commit() notices by
     * woocommerce_updated_product_stock not having fired.
     *
     * @param \WC_Product $item
     * @param int|float   $delta Positive adds, negative takes
     */
    private function adjust_stock($item, $delta)
    {
        \wc_update_product_stock($item, abs($delta), $delta > 0 ? 'increase' : 'decrease');
    }

    /**
     * What writing the edited object is going to change
     *
     * WooCommerce aligns the stock properties at the start of save(): it
     * derives the stock status from the quantity, and clears quantity and
     * backorders when stock is not managed. Running that same public step on
     * a copy puts those consequences into the plan, so they are logged before
     * the write as well.
     *
     * @param \WC_Product $item   Item with the intent applied in memory
     * @param array       $before read_state() from before the edit
     * @param array       $intent
     * @return array field => ['old' => value, 'new' => value]
     */
    private function plan_changes($item, $before, $intent)
    {
        $probe = clone $item;

        if (!empty($intent['stock_delta'])) {
            $probe->set_stock_quantity(($before['stock_quantity'] === null ? 0 : $before['stock_quantity']) + $intent['stock_delta']);
        }

        $probe->validate_props();

        $planned = $this->diff_state($before, $this->read_state($probe));

        // The status found when tracking starts is always recorded, changed or
        // not: undoing the start puts it back
        if (isset($intent['start']) && !isset($planned['stock_status'])) {
            $planned['stock_status'] = ['old' => $before['stock_status'], 'new' => $before['stock_status']];
        }

        return $planned;
    }

    /**
     * read_state() of the item as stored now
     *
     * @param int   $id
     * @param array $fallback State to report when the item cannot be loaded
     * @return array
     */
    private function reload_state($id, $fallback)
    {
        $fresh = \wc_get_product($id);

        return $fresh ? $this->read_state($fresh) : $fallback;
    }

    /**
     * Result of a field that went to commit()
     *
     * @param string $field    Request field
     * @param array  $accepted ['typed' => clean value, 'seen' => string|null]
     * @param array  $before
     * @param array  $commit   What commit() returned
     * @return array Field result
     */
    private function accepted_result($field, $accepted, $before, $commit)
    {
        $log_field = self::LOG_FIELD[$field];
        $value = $accepted['typed'];
        $seen = $accepted['seen'];
        $current = $this->current_value($field, $before);
        $stored = $this->current_value($field, $commit['after']);
        $row = isset($commit['rows'][$log_field]) ? $commit['rows'][$log_field] : null;

        if ($commit['state'] === 'log_failed') {
            return $this->field_result('refused', 'log_failed', self::not_saved(__('The change history could not be written, so this product was not changed.', 'madebyhype-stockmanagment')), $value, $seen, $current);
        }

        if ($row === 'reversed') {
            /* translators: 1: stock the user saw, 2: stock now, 3: the change, with its sign */
            $sentence = sprintf(
                __('Stock changed from %1$s to %2$s since you loaded the page. Your change of %3$s would take it below zero.', 'madebyhype-stockmanagment'),
                self::display_value('stock_quantity', $seen === '' ? null : (int) $seen),
                self::display_value('stock_quantity', $stored),
                self::signed($value - (int) $seen)
            );

            return $this->field_result('conflict', 'stock_conflict', self::not_saved($sentence), $value, $seen, $stored, $stored);
        }

        // No row: the product already held the value, so there was nothing to write
        if ($row === null && $commit['state'] !== 'failed') {
            return $this->field_result('saved', 'unchanged', __('No change.', 'madebyhype-stockmanagment'), $value, $seen, $stored, $stored);
        }

        // A change that was planned and is not there afterwards was not saved
        if ($row !== 'applied') {
            return $this->field_result('refused', 'save_failed', self::not_saved(__('Something went wrong while saving this product. Reload the page to see its current values.', 'madebyhype-stockmanagment')), $value, $seen, $stored, $stored);
        }

        switch ($field) {
            case 'start_tracking':
                /* translators: %s: quantity */
                $message = sprintf(__('Stock tracking started at %s.', 'madebyhype-stockmanagment'), self::display_value('stock_quantity', $stored));
                break;
            case 'stock_quantity':
                // The value the write started from; it differs from the one the
                // user saw when stock moved while they were editing
                $from = array_key_exists($log_field, $commit['old']) ? $commit['old'][$log_field] : $current;

                if ($seen !== null && $stored != $value) {
                    /* translators: 1: stored stock, 2: typed stock, 3: stock the user saw, 4: stock found, 5: the change, with its sign, 6: stock found */
                    $message = sprintf(
                        __('Saved as %1$s, not %2$s. Stock changed from %3$s to %4$s while you were editing, so your change of %5$s was applied to %6$s.', 'madebyhype-stockmanagment'),
                        self::display_value('stock_quantity', $stored),
                        self::display_value('stock_quantity', $value),
                        self::display_value('stock_quantity', $seen === '' ? null : (int) $seen),
                        self::display_value('stock_quantity', $from),
                        self::signed($value - (int) $seen),
                        self::display_value('stock_quantity', $from)
                    );

                    return $this->field_result('adjusted', 'stock_adjusted', $message, $value, $seen, $from, $stored);
                }

                /* translators: %s: quantity */
                $message = sprintf(__('Stock set to %s.', 'madebyhype-stockmanagment'), self::display_value('stock_quantity', $stored));
                $current = $from;
                break;
            case 'sale_price':
                $message = $stored === ''
                    ? __('Sale ended.', 'madebyhype-stockmanagment')
                    /* translators: %s: price */
                    : sprintf(__('Sale price set to %s.', 'madebyhype-stockmanagment'), self::display_value($field, $stored));
                break;
            default:
                /* translators: 1: field name, 2: value */
                $message = sprintf(__('%1$s set to %2$s.', 'madebyhype-stockmanagment'), self::field_label($field), self::display_value($field, $stored));
                break;
        }

        return $this->field_result('saved', 'saved', $message, $value, $seen, $current, $stored);
    }

    /**
     * Result of a field that an earlier request with the same save token
     * already logged. Nothing is written.
     *
     * @param string $field
     * @param array  $row     Its change row in the batch
     * @param mixed  $current Value the product holds now
     * @return array Field result
     */
    private function logged_result($field, $row, $current)
    {
        $value = ChangeLog::decode_typed($row['field'], $row['typed_value']);

        // The earlier failure is repeated. Saving again from the screen (a new
        // token) is the way to retry.
        if ($row['status'] === ChangeLog::STATUS_FAILED) {
            return $this->field_result('refused', 'save_failed', self::not_saved(__('Something went wrong while saving this product. Reload the page to see its current values.', 'madebyhype-stockmanagment')), $value, null, $current);
        }

        // Stock that was stored with another value than typed: the answer the
        // lost response carried is rebuilt from the row, so the user still
        // learns that, and why, the stored quantity is not the one they typed
        $old = ChangeLog::decode_value($row['field'], $row['old_value']);
        $new = ChangeLog::decode_value($row['field'], $row['new_value']);

        if ($field === 'stock_quantity' && $new !== null && $new != $value) {
            $delta = $new - ($old === null ? 0 : $old);
            $seen = $value - $delta;

            /* translators: 1: stored stock, 2: typed stock, 3: stock the user saw, 4: stock found, 5: the change, with its sign, 6: stock found */
            $message = sprintf(
                __('Saved as %1$s, not %2$s. Stock changed from %3$s to %4$s while you were editing, so your change of %5$s was applied to %6$s.', 'madebyhype-stockmanagment'),
                self::display_value('stock_quantity', $new),
                self::display_value('stock_quantity', $value),
                self::display_value('stock_quantity', $seen),
                self::display_value('stock_quantity', $old),
                self::signed($delta),
                self::display_value('stock_quantity', $old)
            );

            return $this->field_result('adjusted', 'stock_adjusted', $message, $value, (string) $seen, $old, $new);
        }

        return $this->field_result('saved', 'already_saved', __('Already saved.', 'madebyhype-stockmanagment'), $value, null, $current);
    }

    /**
     * Result of one submitted field
     *
     * @param string $status  What became of it, in the terms the screen shows:
     *                        'saved'    stored (also: nothing to store, or stored by an earlier request)
     *                        'adjusted' stock stored with another value than typed, because it had moved
     *                        'conflict' not stored: the value changed since the user saw it; they must choose
     *                        'refused'  not stored: a rule, a permission, an invalid value, or a failure
     *                        'dropped'  not stored and it cannot apply any more; redraw the row
     * @param string $code    The reason. saved: saved, unchanged, already_saved. adjusted: stock_adjusted.
     *                        conflict: stock_conflict, value_conflict. refused: forbidden, invalid_value,
     *                        zero_price_unconfirmed, unknown_field, set_on_variations, no_price, no_stock,
     *                        inherits_stock, status_follows_stock, log_failed, save_failed, interrupted.
     *                        dropped: not_tracked, already_tracked, not_found.
     * @param string $message
     * @param mixed  $typed   The value asked for, cleaned; null when it never got that far
     * @param mixed  $seen    The value the user saw, as sent; null when not sent
     * @param mixed  $current The value the write started from (for a conflict: the value stored now)
     * @param mixed  $stored  The value stored now; defaults to $current, for a field that was not written
     * @return array
     */
    private function field_result($status, $code, $message, $typed = null, $seen = null, $current = null, $stored = null)
    {
        return [
            'status' => $status,
            'code' => $code,
            'message' => $message,
            'typed' => $typed,
            'seen' => $seen === false ? null : $seen,
            'current' => $current,
            'stored' => func_num_args() > 6 ? $stored : $current,
        ];
    }

    /**
     * Item result. The 1.0.6 script reads id, success and message.
     *
     * success is true only when every submitted field was stored: a field
     * that was not stored is never reported under a success.
     *
     * @param int         $id
     * @param string|null $type    Real WooCommerce type (simple, variable, variation, ...); null when not found
     * @param array|null  $values  What the product holds now, see read_state(); null when not found
     * @param array       $changed Tracked fields this save really changed
     * @param array       $fields  request field => field result, see field_result()
     * @param string      $error   Error thrown after the write landed, '' for none
     * @return array [
     *     'id', 'type', 'success',
     *     'code'      => success: saved, unchanged, already_saved, saved_with_error.
     *                    failure: partly_saved (some fields stored, some not), otherwise the code of the first
     *                    field that was not stored; or invalid_id, invalid_fields, duplicate_item.
     *     'message'   => the field message; with several fields, each one after its field name,
     *     'field'     => the first field that was not stored, null when all were,
     *     'values', 'changed',
     *     'conflicts' => list of ['field', 'seen', 'current'] for the fields in conflict,
     *     'fields'    => request field => field result,
     * ]
     */
    private function item_result($id, $type, $values, $changed, $fields, $error = '')
    {
        $written = 0;
        $resent = 0;
        $not_stored = [];
        $conflicts = [];
        $messages = [];

        foreach ($fields as $name => $field) {
            if ($field['status'] === 'saved' || $field['status'] === 'adjusted') {
                $written += in_array($field['code'], ['unchanged', 'already_saved'], true) ? 0 : 1;
                $resent += $field['code'] === 'already_saved' ? 1 : 0;
            } else {
                $not_stored[] = $name;
            }

            if ($field['status'] === 'conflict') {
                $conflicts[] = ['field' => $name, 'seen' => $field['seen'], 'current' => $field['current']];
            }

            $messages[] = count($fields) > 1 ? self::field_label($name) . ': ' . $field['message'] : $field['message'];
        }

        if (empty($not_stored)) {
            $code = $written ? ($error !== '' ? 'saved_with_error' : 'saved') : ($resent === count($fields) ? 'already_saved' : 'unchanged');
        } else {
            $code = $written ? 'partly_saved' : $fields[$not_stored[0]]['code'];
        }

        if ($error !== '' && $written) {
            /* translators: %s: error message */
            $messages[] = sprintf(__('Saved, but an error was reported afterwards: %s', 'madebyhype-stockmanagment'), $error);
        }

        return [
            'id' => $id,
            'type' => $type,
            'success' => empty($not_stored),
            'code' => $code,
            'message' => implode(' ', $messages),
            'field' => $not_stored ? $not_stored[0] : null,
            'values' => $values,
            'changed' => $changed,
            'conflicts' => $conflicts,
            'fields' => $fields,
        ];
    }

    /**
     * Item result for an entry that could not be read as an item at all
     */
    private function item_failure($id, $code, $message)
    {
        return [
            'id' => $id,
            'type' => null,
            'success' => false,
            'code' => $code,
            'message' => $message,
            'field' => null,
            'values' => null,
            'changed' => [],
            'conflicts' => [],
            'fields' => [],
        ];
    }

    /**
     * Undo the changes one save made to one item
     *
     * Every requested change of that save that is still in force is judged
     * now, against what the product holds now:
     * - the user needs the permission for that kind of field;
     * - stock: the difference the save made is taken back out of the current
     *   quantity, so sales since then are kept; skipped when the result would
     *   be below zero or the item no longer tracks its own stock;
     * - price and status: put back only if the product still holds the value
     *   that save wrote; a status also only while the item is still untracked;
     * - start of tracking: tracking is switched off again and the status from
     *   before is put back; skipped when the item is no longer tracked.
     *
     * @param int   $item_id
     * @param array $rows    All change rows of the save for this item
     * @param array $run     State of the undo batch
     * @param bool  $dry_run
     * @return array ['changes' => list of [
     *                   'change_id', 'item_id', 'product_id', 'item_type', 'name', 'sku', 'field', 'label',
     *                   'saved_old', 'saved_new' => what that save changed the field from and to,
     *                   'current' => value now (before this undo), 'result' => value after it (same as current when skipped),
     *                   'current_display', 'result_display',
     *                   'outcome' => 'undo' | 'skip',
     *                   'code'    => undo: stock, tracking, restore. skip: forbidden, not_found, not_tracked,
     *                                stock_conflict, changed_since, status_follows_stock, set_on_variations, no_price,
     *                                invalid_value, already_undone, log_failed, save_failed,
     *                   'message' => what the undo does, or why it is skipped],
     *               'values' => read_state() after the undo, null when nothing was written]
     */
    private function undo_item($item_id, $rows, &$run, $dry_run)
    {
        $open = [];
        $status_before = null;

        foreach ($rows as $row) {
            if ($row['typed_value'] !== null && $row['status'] === ChangeLog::STATUS_APPLIED && in_array($row['field'], self::UNDO_FIELDS, true)) {
                $open[$row['field']] = $row;
            }

            if ($row['field'] === 'stock_status') {
                $status_before = $row['old_value'];
            }
        }

        if (empty($open)) {
            return ['changes' => [], 'values' => null];
        }

        $item = \wc_get_product($item_id);
        $now = $item ? $this->read_state($item) : null;
        $type = $item ? $item->get_type() : '';

        $changes = [];
        $intent = [];
        $typed = [];

        foreach (self::UNDO_FIELDS as $field) {
            if (!isset($open[$field])) {
                continue;
            }

            $row = $open[$field];
            $old = ChangeLog::decode_value($field, $row['old_value']);
            $new = ChangeLog::decode_value($field, $row['new_value']);
            $current = $now ? $now[$field] : null;
            $is_price = in_array($field, self::PRICE_FIELDS, true);

            $change = [
                'change_id' => (int) $row['id'],
                'item_id' => (int) $item_id,
                'product_id' => (int) $row['product_id'],
                'item_type' => $row['item_type'],
                'name' => $item ? $item->get_name() : null,
                'sku' => $item ? (string) $item->get_sku() : '',
                'field' => $field,
                'label' => self::field_label($field),
                'saved_old' => $old,
                'saved_new' => $new,
                'current' => $current,
                'result' => $current,
                'outcome' => 'skip',
                'code' => '',
                'message' => '',
            ];

            if (!$item) {
                $change['code'] = 'not_found';
                $change['message'] = __('This product no longer exists.', 'madebyhype-stockmanagment');
            } elseif (!$this->can($is_price ? 'prices' : 'stock')) {
                $change['code'] = 'forbidden';
                $change['message'] = $is_price
                    ? __('You do not have permission to change prices.', 'madebyhype-stockmanagment')
                    : __('You do not have permission to change stock.', 'madebyhype-stockmanagment');
            } elseif ($field === 'manage_stock') {
                if ($now['stock_mode'] !== 'own') {
                    $change['code'] = 'not_tracked';
                    $change['message'] = __('Already not tracked.', 'madebyhype-stockmanagment');
                } else {
                    $intent['stop'] = $status_before;
                    $typed[$field] = false;
                    $change = array_merge($change, ['outcome' => 'undo', 'code' => 'tracking', 'result' => false]);
                    /* translators: %s: quantity */
                    $change['message'] = sprintf(__('Stops tracking again. The current quantity (%s) is discarded.', 'madebyhype-stockmanagment'), self::display_value('stock_quantity', $now['stock_quantity']));
                }
            } elseif ($field === 'stock_quantity') {
                $delta = ($new === null ? 0 : $new) - ($old === null ? 0 : $old);
                $stored = $current === null ? 0 : $current + 0;
                $result = $stored - $delta;

                if ($now['stock_mode'] !== 'own') {
                    $change['code'] = 'not_tracked';
                    $change['message'] = __('This product no longer tracks stock.', 'madebyhype-stockmanagment');
                } elseif ($result < 0) {
                    $change['code'] = 'stock_conflict';
                    $change['message'] = $delta > 0
                        /* translators: 1: units the save added, 2: units in stock now */
                        ? sprintf(__('This save added %1$s, but only %2$s are left.', 'madebyhype-stockmanagment'), $delta, self::display_value('stock_quantity', $stored))
                        /* translators: %s: units in stock now */
                        : sprintf(__('Stock is now %s; undoing this change would still leave it below zero.', 'madebyhype-stockmanagment'), self::display_value('stock_quantity', $stored));
                } else {
                    // An empty quantity cannot be adjusted in SQL: write the result
                    $intent += $current === null ? ['stock_set' => $result] : ['stock_delta' => -$delta];
                    $typed[$field] = $result;
                    $change = array_merge($change, ['outcome' => 'undo', 'code' => 'stock', 'result' => $result]);
                    /* translators: %s: the change the save made, with its sign */
                    $change['message'] = sprintf(__('Removes the %s from this save.', 'madebyhype-stockmanagment'), self::signed($delta));

                    $moved = $stored - ($new === null ? 0 : $new);
                    if ($moved != 0) {
                        /* translators: %s: how much the stock moved since the save, with its sign */
                        $change['message'] .= ' ' . sprintf(__('Stock has changed by %s since; that is kept.', 'madebyhype-stockmanagment'), self::signed($moved));
                    }
                }
            } else {
                $code = self::placement($field, $type, $now['stock_mode']);
                $unchanged = $is_price ? self::same_price($current, $new) : $current === $new;

                if ($code !== '') {
                    $change['code'] = $code;
                    $change['message'] = $code === 'status_follows_stock'
                        ? __('This product now tracks stock, so its status follows the quantity.', 'madebyhype-stockmanagment')
                        : $this->placement_sentence($code, $field, $now, true);
                } elseif (!$unchanged) {
                    $change['code'] = 'changed_since';
                    /* translators: 1: value the save set, 2: value now */
                    $change['message'] = sprintf(__('Changed again since this save: it set %1$s, it is now %2$s.', 'madebyhype-stockmanagment'), self::display_value($field, $new), self::display_value($field, $current));
                } else {
                    $intent[$field] = $old;
                    $typed[$field] = $old;
                    $change = array_merge($change, ['outcome' => 'undo', 'code' => 'restore', 'result' => $old]);
                    /* translators: %s: value the field goes back to */
                    $change['message'] = sprintf(__('Back to %s.', 'madebyhype-stockmanagment'), self::display_value($field, $old));
                }
            }

            $changes[$field] = $change;
        }

        // Putting a price back must not leave a sale price at or above the regular price
        foreach (['sale_price', 'regular_price'] as $field) {
            $regular = isset($intent['regular_price']) ? $intent['regular_price'] : ($now ? $now['regular_price'] : '');
            $sale = isset($intent['sale_price']) ? $intent['sale_price'] : ($now ? $now['sale_price'] : '');

            if (!isset($intent[$field]) || self::price_pair_ok($regular, $sale)) {
                continue;
            }

            unset($intent[$field], $typed[$field]);
            $changes[$field] = array_merge($changes[$field], ['outcome' => 'skip', 'code' => 'invalid_value', 'result' => $changes[$field]['current']]);
            /* translators: %s: regular price */
            $changes[$field]['message'] = sprintf(__('Sale price must be lower than the regular price (%s).', 'madebyhype-stockmanagment'), self::display_value('regular_price', $regular));
        }

        $values = null;

        if (!$dry_run && !empty($intent)) {
            $claimed = [];
            $claim = function () use ($changes, &$claimed) {
                foreach ($changes as $change) {
                    if ($change['outcome'] !== 'undo') {
                        continue;
                    }

                    if (!$this->log->claim_change($change['change_id'], ChangeLog::STATUS_APPLIED, ChangeLog::STATUS_UNDONE)) {
                        return false;
                    }

                    $claimed[] = $change['change_id'];
                }

                return true;
            };

            $commit = $this->commit($item, $now, $intent, $typed, $run, $claim);
            $stock_undone = false;

            foreach ($changes as $field => $change) {
                if ($change['outcome'] !== 'undo') {
                    continue;
                }

                $row = isset($commit['rows'][$field]) ? $commit['rows'][$field] : null;

                if ($commit['state'] === 'written' && $row === 'applied') {
                    $changes[$field]['result'] = $commit['after'][$field];
                    $stock_undone = $stock_undone || !in_array($field, self::PRICE_FIELDS, true);
                    continue;
                }

                // Not undone after all: the change of the save is in force again
                if (in_array($change['change_id'], $claimed, true)) {
                    $this->log->claim_change($change['change_id'], ChangeLog::STATUS_UNDONE, ChangeLog::STATUS_APPLIED);
                }

                $changes[$field]['outcome'] = 'skip';
                $changes[$field]['result'] = $commit['after'][$field];

                if ($commit['state'] === 'cancelled') {
                    $changes[$field]['code'] = 'already_undone';
                    $changes[$field]['message'] = __('This change has already been undone.', 'madebyhype-stockmanagment');
                } elseif ($commit['state'] === 'log_failed') {
                    $changes[$field]['code'] = 'log_failed';
                    $changes[$field]['message'] = __('The change history could not be written, so this product was not changed.', 'madebyhype-stockmanagment');
                } elseif ($row === 'reversed') {
                    $changes[$field]['code'] = 'stock_conflict';
                    /* translators: %s: units in stock now */
                    $changes[$field]['message'] = sprintf(__('Stock is now %s; undoing this change would still leave it below zero.', 'madebyhype-stockmanagment'), self::display_value('stock_quantity', $commit['after']['stock_quantity']));
                } else {
                    $changes[$field]['code'] = 'save_failed';
                    $changes[$field]['message'] = __('Something went wrong while undoing this change. Reload the page to see the current values.', 'madebyhype-stockmanagment');
                }
            }

            // What the save derived from its stock change went back with it
            if ($stock_undone) {
                $this->log->mark_derived_undone((int) $rows[0]['batch_id'], (int) $item_id);
            }

            if ($commit['state'] === 'written') {
                $values = $commit['after'];
            }
        }

        foreach ($changes as $field => $change) {
            $changes[$field]['current_display'] = self::display_value($field, $change['current']);
            $changes[$field]['result_display'] = self::display_value($field, $change['result']);
        }

        return ['changes' => array_values($changes), 'values' => $values];
    }

    /**
     * The sentence that says why an item does not take a field
     *
     * @param string $code     From placement()
     * @param string $field
     * @param array  $state    read_state() of the item
     * @param bool   $has_seen Whether the request came from a page that showed the current value
     * @return string
     */
    private function placement_sentence($code, $field, $state, $has_seen)
    {
        switch ($code) {
            case 'set_on_variations':
                // A variable product that holds the stock is edited for stock, never for prices
                return $state['stock_mode'] === 'own'
                    ? __('Prices of this product are set on its variations.', 'madebyhype-stockmanagment')
                    : __('Stock and prices of this product are set on its variations.', 'madebyhype-stockmanagment');
            case 'no_price':
                return __('This product type does not have a price of its own.', 'madebyhype-stockmanagment');
            case 'no_stock':
                return __('This product type does not hold stock.', 'madebyhype-stockmanagment');
            case 'inherits_stock':
                return __('This variation uses the stock of its product. Change the stock on the product itself.', 'madebyhype-stockmanagment');
            case 'status_follows_stock':
                return __('This product tracks stock, so its status follows the quantity. Change the quantity instead.', 'madebyhype-stockmanagment');
            case 'already_tracked':
                /* translators: %s: quantity */
                return sprintf(__('Stock tracking was already switched on for this product. Current stock is %s.', 'madebyhype-stockmanagment'), self::display_value('stock_quantity', $state['stock_quantity']));
            case 'not_tracked':
                // A page that showed a quantity is now out of date; the 1.0.6
                // screen shows a quantity field on every row
                return $has_seen
                    ? __('This product no longer tracks stock.', 'madebyhype-stockmanagment')
                    : __('This product does not track stock.', 'madebyhype-stockmanagment');
        }

        return __('This field cannot be edited here.', 'madebyhype-stockmanagment');
    }

    /**
     * The stored value a request field is about
     */
    private function current_value($field, $state)
    {
        if ($field === 'start_tracking') {
            return $state['stock_quantity'];
        }

        return isset($state[$field]) ? $state[$field] : null;
    }

    private static function not_saved($sentence)
    {
        /* translators: %s: the reason, a full sentence */
        return sprintf(__('Not saved. %s', 'madebyhype-stockmanagment'), $sentence);
    }

    /**
     * A difference with its sign, as the screen writes it: +5, −3
     */
    private static function signed($number)
    {
        return ($number < 0 ? "\u{2212}" : '+') . abs($number);
    }

    /**
     * Name of a field as the screen and History show it
     *
     * @param string $field Request field or change-log field
     * @return string
     */
    public static function field_label($field)
    {
        switch ($field) {
            case 'stock_quantity':
                return __('Stock', 'madebyhype-stockmanagment');
            case 'stock_status':
                return __('Stock status', 'madebyhype-stockmanagment');
            case 'regular_price':
                return __('Regular price', 'madebyhype-stockmanagment');
            case 'sale_price':
                return __('Sale price', 'madebyhype-stockmanagment');
            case 'start_tracking':
            case 'manage_stock':
                return __('Stock tracking', 'madebyhype-stockmanagment');
            case 'backorders':
                return __('Backorders', 'madebyhype-stockmanagment');
        }

        return (string) $field;
    }

    /**
     * A value of a field as the messages and History write it: prices in the
     * shop's number format, statuses by name, no sale price as "No sale"
     *
     * @param string $field Request field or change-log field
     * @param mixed  $value Value as read_state() or ChangeLog::decode_value() gives it
     * @return string
     */
    public static function display_value($field, $value)
    {
        switch ($field) {
            case 'manage_stock':
                return $value ? __('on', 'madebyhype-stockmanagment') : __('off', 'madebyhype-stockmanagment');
            case 'stock_status':
                $labels = [
                    'instock' => __('In stock', 'madebyhype-stockmanagment'),
                    'outofstock' => __('Out of stock', 'madebyhype-stockmanagment'),
                    'onbackorder' => __('On backorder', 'madebyhype-stockmanagment'),
                ];
                return isset($labels[$value]) ? $labels[$value] : (string) $value;
            case 'backorders':
                $labels = [
                    'no' => __('Not allowed', 'madebyhype-stockmanagment'),
                    'notify' => __('Allowed, customer is told', 'madebyhype-stockmanagment'),
                    'yes' => __('Allowed', 'madebyhype-stockmanagment'),
                ];
                return isset($labels[$value]) ? $labels[$value] : (string) $value;
            case 'regular_price':
            case 'sale_price':
                if ($value === null || $value === '') {
                    return $field === 'sale_price' ? __('No sale', 'madebyhype-stockmanagment') : __('not set', 'madebyhype-stockmanagment');
                }
                return number_format((float) $value, \wc_get_price_decimals(), \wc_get_price_decimal_separator(), \wc_get_price_thousand_separator());
        }

        // stock_quantity, start_tracking
        if ($value === null || $value === '') {
            return __('not set', 'madebyhype-stockmanagment');
        }

        return str_replace('-', "\u{2212}", (string) (is_numeric($value) ? $value + 0 : $value));
    }

    /**
     * The tracked properties of an item as stored, not as displayed, plus
     * where its stock is held and what can be edited on it
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
     *               stock_managed_by_id int|null: the product that holds the stock, null for 'none',
     *               editable: request field => bool, see editable_fields()
     */
    public function read_state($item)
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
            'editable' => self::editable_fields($item->get_type(), $stock_mode),
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
}
