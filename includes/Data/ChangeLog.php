<?php

namespace MadeByHypeStockmanagment\Data;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * The change log: the only code that reads or writes the batches and changes
 * tables (definitions in Schema).
 *
 * A batch is one save or one undo. A change is one field of one product or
 * variation inside a batch, with the value before and the value after.
 *
 * A change is either requested (typed_value holds what the user asked for)
 * or derived (typed_value is NULL: a consequence, such as the stock status
 * WooCommerce set from a new quantity). Counts, undo and resend detection
 * work on requested changes; derived ones follow the change that caused them.
 */
class ChangeLog
{
    const KIND_SAVE = 'save';
    const KIND_UNDO = 'undo';

    const SOURCE_STOCK_SCREEN = 'stock_screen';
    const SOURCE_ORDER = 'order';
    const SOURCE_BULK_PRICE = 'bulk_price';

    // Recorded before the product is written; still pending afterwards means the request died mid-write
    const STATUS_PENDING = 'pending';
    // The product holds new_value because of this change
    const STATUS_APPLIED = 'applied';
    // The write threw; the product may or may not hold new_value
    const STATUS_FAILED = 'failed';
    // Deliberately not written, or written without effect; see the message
    const STATUS_SKIPPED = 'skipped';
    // Was applied, then reversed by a later undo batch
    const STATUS_UNDONE = 'undone';

    const DEFAULT_RETENTION_MONTHS = 12;

    /**
     * Open a batch
     *
     * @param array $args {
     *     @type int    $user_id         Default: the current user (0 for none).
     *     @type string $source          Where the change came from. Default 'stock_screen'.
     *     @type int    $source_id       Id within the source, such as an order id. Default 0.
     *     @type string $kind            'save' or 'undo'. Default 'save'.
     *     @type int    $undoes_batch_id For an undo: the batch it reverses. Default none.
     *     @type string $note            Optional free text. Default ''.
     *     @type string $save_token      Client-generated identity of one press of Save. A user can
     *                                   hold one batch per token; see find_batch_by_token(). Default none.
     * }
     * @return int Batch id, 0 on failure (including a token this user already used)
     */
    public function create_batch($args = [])
    {
        global $wpdb;

        $args = array_merge([
            'user_id' => get_current_user_id(),
            'source' => self::SOURCE_STOCK_SCREEN,
            'source_id' => 0,
            'kind' => self::KIND_SAVE,
            'undoes_batch_id' => null,
            'note' => '',
            'save_token' => '',
        ], $args);

        // A refused insert (a token already in use) is an expected outcome, not
        // something for wpdb to print into an AJAX response
        $suppress = $wpdb->suppress_errors(true);
        $inserted = $wpdb->insert(
            Schema::batches_table(),
            [
                'created_at_gmt' => gmdate('Y-m-d H:i:s'),
                'user_id' => (int) $args['user_id'],
                'source' => substr((string) $args['source'], 0, 32),
                'source_id' => (int) $args['source_id'],
                'kind' => $args['kind'] === self::KIND_UNDO ? self::KIND_UNDO : self::KIND_SAVE,
                'undoes_batch_id' => $args['undoes_batch_id'] ? (int) $args['undoes_batch_id'] : null,
                'note' => self::truncate($args['note'], 255),
                'save_token' => (string) $args['save_token'] !== '' ? (string) $args['save_token'] : null,
            ],
            ['%s', '%d', '%s', '%d', '%s', '%d', '%s', '%s']
        );
        $wpdb->suppress_errors($suppress);

        return $inserted ? (int) $wpdb->insert_id : 0;
    }

    /**
     * @param int $batch_id
     * @return array|null Batch row, null when it does not exist
     */
    public function get_batch($batch_id)
    {
        global $wpdb;

        $table = Schema::batches_table();

        return $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM {$table} WHERE id = %d", $batch_id),
            ARRAY_A
        );
    }

    /**
     * The batch a user opened with a save token, if any
     *
     * @param int    $user_id
     * @param string $save_token
     * @return array|null
     */
    public function find_batch_by_token($user_id, $save_token)
    {
        global $wpdb;

        $table = Schema::batches_table();

        return $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM {$table} WHERE user_id = %d AND save_token = %s", $user_id, $save_token),
            ARRAY_A
        );
    }

    /**
     * Change rows of one batch, in the order they were recorded.
     * Values come back as stored; use decode_value() to get typed values,
     * or get_batch_changes() for rows ready to show.
     *
     * @param int $batch_id
     * @return array
     */
    public function get_changes($batch_id)
    {
        global $wpdb;

        $table = Schema::changes_table();

        return $wpdb->get_results(
            $wpdb->prepare("SELECT * FROM {$table} WHERE batch_id = %d ORDER BY id ASC", $batch_id),
            ARRAY_A
        );
    }

    /**
     * Change rows of one batch for its next items, in item order: how a save
     * too large for one request is walked
     *
     * @param int $batch_id
     * @param int $after_item Only items with a higher id; 0 to start
     * @param int $limit      Number of items (not rows)
     * @return array ['rows' => change rows as get_changes() gives them, 'next_after' => the last item id read
     *               ($after_item when there was none), 'done' => bool: no item is left after these]
     */
    public function get_changes_page($batch_id, $after_item, $limit)
    {
        global $wpdb;

        $table = Schema::changes_table();
        $limit = max(1, (int) $limit);

        // One more than asked for says whether anything is left
        $item_ids = array_map('intval', (array) $wpdb->get_col(
            $wpdb->prepare(
                "SELECT DISTINCT item_id FROM {$table} WHERE batch_id = %d AND item_id > %d ORDER BY item_id ASC LIMIT %d",
                $batch_id,
                $after_item,
                $limit + 1
            )
        ));

        $done = count($item_ids) <= $limit;
        $item_ids = array_slice($item_ids, 0, $limit);

        if (empty($item_ids)) {
            return ['rows' => [], 'next_after' => (int) $after_item, 'done' => true];
        }

        $in = implode(',', $item_ids);

        return [
            'rows' => $wpdb->get_results(
                $wpdb->prepare("SELECT * FROM {$table} WHERE batch_id = %d AND item_id IN ({$in}) ORDER BY item_id ASC, id ASC", $batch_id),
                ARRAY_A
            ),
            'next_after' => (int) end($item_ids),
            'done' => $done,
        ];
    }

    /**
     * How many requested changes of a batch are in force and how many were undone
     *
     * @param int $batch_id
     * @return array ['open' => int, 'undone' => int]
     */
    public function count_requested($batch_id)
    {
        global $wpdb;

        $table = Schema::changes_table();
        $counts = ['open' => 0, 'undone' => 0];

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT status, COUNT(*) AS n FROM {$table} WHERE batch_id = %d AND typed_value IS NOT NULL AND status IN (%s, %s) GROUP BY status",
                $batch_id,
                self::STATUS_APPLIED,
                self::STATUS_UNDONE
            ),
            ARRAY_A
        );

        foreach ($rows as $row) {
            $counts[$row['status'] === self::STATUS_UNDONE ? 'undone' : 'open'] = (int) $row['n'];
        }

        return $counts;
    }

    /**
     * Record one change row per field of one item
     *
     * A field of an item can be recorded once per batch (unique key). Record
     * the net change; a second row for the same field is refused, and then
     * none of the rows of this call are kept.
     *
     * @param int    $batch_id
     * @param array  $item    ['item_id' => int, 'product_id' => int (parent for a variation, else the item itself), 'item_type' => string]
     * @param array  $changes field => ['old' => value, 'new' => value, 'typed' => value the user asked for (optional;
     *                        leave out or null for a derived change)], unencoded
     * @param string $status  One of the STATUS_ constants
     * @param string $message
     * @return array|false field => change row id; false when any row could not be written
     */
    public function add_changes($batch_id, $item, $changes, $status = self::STATUS_PENDING, $message = '')
    {
        global $wpdb;

        $row_ids = [];

        foreach ($changes as $field => $change) {
            // A refused insert (the field is already recorded in this batch) is
            // an expected outcome; it is reported through the return value
            $suppress = $wpdb->suppress_errors(true);
            $inserted = $wpdb->insert(
                Schema::changes_table(),
                [
                    'batch_id' => (int) $batch_id,
                    'item_id' => (int) $item['item_id'],
                    'product_id' => (int) $item['product_id'],
                    'item_type' => (string) $item['item_type'],
                    'field' => (string) $field,
                    'old_value' => self::encode_value($change['old']),
                    'new_value' => self::encode_value($change['new']),
                    'status' => $status,
                    'message' => self::truncate($message, 255),
                    'typed_value' => self::encode_value(isset($change['typed']) ? $change['typed'] : null),
                ],
                ['%d', '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s']
            );
            $last_error = $wpdb->last_error;
            $wpdb->suppress_errors($suppress);

            if (!$inserted) {
                error_log('MadeByHype Stock Management - Could not record change: ' . $last_error);

                foreach ($row_ids as $row_id) {
                    $wpdb->delete(Schema::changes_table(), ['id' => $row_id], ['%d']);
                }

                return false;
            }

            $row_ids[$field] = (int) $wpdb->insert_id;
        }

        return $row_ids;
    }

    /**
     * Set the outcome of one change row
     *
     * @param int    $change_id
     * @param string $status    One of the STATUS_ constants
     * @param string $message
     * @param array  $values    Optional 'old' and/or 'new' (unencoded): replace the recorded values with
     *                          what the product really held before and holds after the write
     * @return bool
     */
    public function set_change_status($change_id, $status, $message = '', $values = [])
    {
        global $wpdb;

        $data = ['status' => $status, 'message' => self::truncate($message, 255)];

        foreach (['old' => 'old_value', 'new' => 'new_value'] as $key => $column) {
            if (array_key_exists($key, $values)) {
                $data[$column] = self::encode_value($values[$key]);
            }
        }

        return $wpdb->update(
            Schema::changes_table(),
            $data,
            ['id' => (int) $change_id],
            array_fill(0, count($data), '%s'),
            ['%d']
        ) !== false;
    }

    /**
     * Move a change row from one status to another, only if it still has the
     * first one. One statement, so of two requests that try the same move
     * exactly one succeeds: this is what keeps a change from being undone twice.
     *
     * @param int    $change_id
     * @param string $from
     * @param string $to
     * @return bool Whether this call made the move
     */
    public function claim_change($change_id, $from, $to)
    {
        global $wpdb;

        $table = Schema::changes_table();

        return $wpdb->query(
            $wpdb->prepare("UPDATE {$table} SET status = %s WHERE id = %d AND status = %s", $to, $change_id, $from)
        ) === 1;
    }

    /**
     * Mark the derived changes of one item in a batch as undone, after the
     * requested change that caused them was undone
     *
     * @param int $batch_id
     * @param int $item_id
     */
    public function mark_derived_undone($batch_id, $item_id)
    {
        global $wpdb;

        $table = Schema::changes_table();

        $wpdb->query(
            $wpdb->prepare(
                "UPDATE {$table} SET status = %s WHERE batch_id = %d AND item_id = %d AND typed_value IS NULL AND status = %s",
                self::STATUS_UNDONE,
                $batch_id,
                $item_id,
                self::STATUS_APPLIED
            )
        );
    }

    /**
     * Saves and undos, newest first, one summary each (see summarise())
     *
     * @param array $args {
     *     @type int    $page     Default 1.
     *     @type int    $per_page Default 20, at most 100.
     *     @type int    $user_id  Only batches of this user. Default all.
     *     @type string $kind     'save' or 'undo'. Default both.
     *     @type string $search   Only batches that touched a product or variation whose name or SKU
     *                            contains this text. Default none.
     * }
     * @return array ['batches' => summaries, 'total' => int, 'page' => int, 'per_page' => int, 'pages' => int]
     */
    public function get_batches($args = [])
    {
        global $wpdb;

        $args = array_merge(['page' => 1, 'per_page' => 20, 'user_id' => 0, 'kind' => '', 'search' => ''], $args);
        $per_page = max(1, min(100, (int) $args['per_page']));
        $page = max(1, (int) $args['page']);
        $batches = Schema::batches_table();
        $changes = Schema::changes_table();
        $result = ['batches' => [], 'total' => 0, 'page' => $page, 'per_page' => $per_page, 'pages' => 0];

        $where = ['1 = 1'];

        if ((int) $args['user_id'] > 0) {
            $where[] = 'b.user_id = ' . (int) $args['user_id'];
        }

        if (in_array($args['kind'], [self::KIND_SAVE, self::KIND_UNDO], true)) {
            $where[] = "b.kind = '" . $args['kind'] . "'";
        }

        if (trim((string) $args['search']) !== '') {
            $item_ids = $this->find_item_ids($args['search']);
            if (empty($item_ids)) {
                return $result;
            }

            $in = implode(',', $item_ids);
            $where[] = "b.id IN (SELECT c.batch_id FROM {$changes} c WHERE c.item_id IN ({$in}) OR c.product_id IN ({$in}))";
        }

        $where = implode(' AND ', $where);
        $offset = ($page - 1) * $per_page;

        $result['total'] = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$batches} b WHERE {$where}");
        $result['pages'] = (int) ceil($result['total'] / $per_page);
        $result['batches'] = $this->summarise(
            $wpdb->get_results("SELECT b.* FROM {$batches} b WHERE {$where} ORDER BY b.id DESC LIMIT {$per_page} OFFSET {$offset}", ARRAY_A)
        );

        return $result;
    }

    /**
     * @param int $batch_id
     * @return array|null Summary of one batch (see summarise()), null when it does not exist
     */
    public function get_batch_summary($batch_id)
    {
        $batch = $this->get_batch($batch_id);
        $summaries = $batch ? $this->summarise([$batch]) : [];

        return $summaries ? $summaries[0] : null;
    }

    /**
     * The most recent save of one user, for "Your last save"
     *
     * @param int $user_id
     * @return array|null Summary, see summarise()
     */
    public function get_last_save($user_id)
    {
        $found = $this->get_batches(['user_id' => (int) $user_id, 'kind' => self::KIND_SAVE, 'per_page' => 1]);

        return $found['batches'] ? $found['batches'][0] : null;
    }

    /**
     * Everyone who has a batch (a save or an undo) in the log, for History's
     * "Saved by" filter
     *
     * @return array List of ['id' => int, 'name' => string|null], by name; name is null
     *               for an account that no longer exists (those come last)
     */
    public function get_batch_users()
    {
        global $wpdb;

        $batches = Schema::batches_table();
        $ids = array_map('intval', (array) $wpdb->get_col("SELECT DISTINCT user_id FROM {$batches} WHERE user_id > 0"));
        $names = $this->user_names($ids);
        $users = [];

        foreach ($ids as $id) {
            $users[] = ['id' => $id, 'name' => isset($names[$id]) ? $names[$id] : null];
        }

        usort($users, function ($a, $b) {
            if (($a['name'] === null) !== ($b['name'] === null)) {
                return $a['name'] === null ? 1 : -1;
            }

            $order = strcasecmp((string) $a['name'], (string) $b['name']);

            return $order !== 0 ? $order : $a['id'] - $b['id'];
        });

        return $users;
    }

    /**
     * The changes of one batch, ready to show (see describe_changes())
     *
     * @param int $batch_id
     * @return array
     */
    public function get_batch_changes($batch_id)
    {
        return $this->describe_changes($this->get_changes($batch_id));
    }

    /**
     * The requested changes of one batch, a page at a time, ready to show
     *
     * @param int   $batch_id
     * @param array $args 'page' (default 1), 'per_page' (default 200, at most 500)
     * @return array ['rows' => describe_changes() rows, 'total' => int, 'page' => int, 'per_page' => int, 'pages' => int]
     */
    public function get_requested_changes($batch_id, $args = [])
    {
        global $wpdb;

        $args = array_merge(['page' => 1, 'per_page' => 200], $args);
        $per_page = max(1, min(500, (int) $args['per_page']));
        $page = max(1, (int) $args['page']);
        $table = Schema::changes_table();

        $total = (int) $wpdb->get_var(
            $wpdb->prepare("SELECT COUNT(*) FROM {$table} WHERE batch_id = %d AND typed_value IS NOT NULL", $batch_id)
        );

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$table} WHERE batch_id = %d AND typed_value IS NOT NULL ORDER BY id ASC LIMIT %d OFFSET %d",
                $batch_id,
                $per_page,
                ($page - 1) * $per_page
            ),
            ARRAY_A
        );

        return [
            'rows' => $this->describe_changes($rows),
            'total' => $total,
            'page' => $page,
            'per_page' => $per_page,
            'pages' => (int) ceil($total / $per_page),
        ];
    }

    /**
     * Every change that landed on one item, newest first, across all batches
     *
     * For a product the list covers the product itself and all its
     * variations; for a variation, that variation only.
     *
     * Each row also says whether the history joins up: 'gap' is set when the
     * value this change found (old) is not the value the previous change to
     * the same field of the same item left behind. Something outside this
     * tool changed it in between: an order, the product editor, an import.
     *
     * @param int   $id   Product or variation id
     * @param array $args 'page' (default 1), 'per_page' (default 50, at most 200)
     * @return array ['rows' => describe_changes() rows plus 'kind', 'undoes_batch_id', 'user_id', 'user_name',
     *               'created_at_gmt' of the batch and 'gap' => null | ['from' => value, 'to' => value],
     *               'total' => int, 'page' => int, 'per_page' => int, 'pages' => int]
     */
    public function get_item_history($id, $args = [])
    {
        global $wpdb;

        $id = (int) $id;
        $args = array_merge(['page' => 1, 'per_page' => 50], $args);
        $per_page = max(1, min(200, (int) $args['per_page']));
        $page = max(1, (int) $args['page']);
        $offset = ($page - 1) * $per_page;
        $batches = Schema::batches_table();
        $changes = Schema::changes_table();
        $landed = "('" . self::STATUS_APPLIED . "','" . self::STATUS_UNDONE . "')";

        // product_id is the parent for a variation, else the item itself. One
        // equality on one index, whose entries are already in id order, so a
        // page is read without sorting the whole history of the product. The
        // index is named because "ORDER BY id LIMIT n" otherwise tempts the
        // optimiser into walking the whole table by its primary key.
        $post_type = get_post_type($id);
        if ($post_type === 'product_variation') {
            $from = "{$changes} c FORCE INDEX (item_id) WHERE c.item_id = {$id}";
        } elseif ($post_type) {
            $from = "{$changes} c FORCE INDEX (product_id) WHERE c.product_id = {$id}";
        } else {
            // The item is gone and its type with it: look both ways
            $from = "{$changes} c WHERE (c.product_id = {$id} OR c.item_id = {$id})";
        }
        $from .= " AND c.status IN {$landed}";

        $total = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$from}");
        $rows = $wpdb->get_results("SELECT c.* FROM {$from} ORDER BY c.id DESC LIMIT {$per_page} OFFSET {$offset}", ARRAY_A);

        $batch_rows = [];
        $previous = [];

        if ($rows) {
            $in = implode(',', array_unique(array_map('intval', wp_list_pluck($rows, 'batch_id'))));
            foreach ($wpdb->get_results("SELECT id, kind, undoes_batch_id, user_id, created_at_gmt FROM {$batches} WHERE id IN ({$in})", ARRAY_A) as $batch) {
                $batch_rows[(int) $batch['id']] = $batch;
            }

            // What the change before each row, on the same field of the same
            // item, left behind. Within the page that is the next row of the
            // same item and field (the rows are newest first)...
            $oldest = [];
            foreach ($rows as $row) {
                $key = $row['item_id'] . ':' . $row['field'];
                if (isset($oldest[$key])) {
                    $previous[(int) $oldest[$key]['id']] = $row['new_value'];
                }
                $oldest[$key] = $row;
            }

            // ...and for the oldest row of each item and field on the page it
            // is looked up: one short walk down the item_id index each
            $lookups = [];
            foreach ($oldest as $row) {
                $lookups[] = $wpdb->prepare(
                    "(SELECT %d AS change_id, p.new_value FROM {$changes} p FORCE INDEX (item_id)
                       WHERE p.item_id = %d AND p.field = %s AND p.id < %d AND p.status IN {$landed}
                       ORDER BY p.id DESC LIMIT 1)",
                    $row['id'],
                    $row['item_id'],
                    $row['field'],
                    $row['id']
                );
            }
            foreach ($wpdb->get_results(implode(' UNION ALL ', $lookups), ARRAY_A) as $found) {
                $previous[(int) $found['change_id']] = $found['new_value'];
            }
        }

        $names = $this->user_names(wp_list_pluck($batch_rows, 'user_id'));
        $described = $this->describe_changes($rows);

        foreach ($rows as $index => $row) {
            $batch = isset($batch_rows[(int) $row['batch_id']]) ? $batch_rows[(int) $row['batch_id']] : null;
            $user_id = $batch ? (int) $batch['user_id'] : 0;
            $gap = null;

            if (array_key_exists((int) $row['id'], $previous) && !self::same_value($previous[(int) $row['id']], $row['old_value'])) {
                $gap = [
                    'from' => self::decode_value($row['field'], $previous[(int) $row['id']]),
                    'to' => self::decode_value($row['field'], $row['old_value']),
                ];
            }

            $described[$index] += [
                'kind' => $batch ? $batch['kind'] : '',
                'undoes_batch_id' => $batch && $batch['undoes_batch_id'] !== null ? (int) $batch['undoes_batch_id'] : null,
                'user_id' => $user_id,
                'user_name' => isset($names[$user_id]) ? $names[$user_id] : '',
                'created_at_gmt' => $batch ? $batch['created_at_gmt'] : '',
                'gap' => $gap,
            ];
        }

        return [
            'rows' => $described,
            'total' => $total,
            'page' => $page,
            'per_page' => $per_page,
            'pages' => (int) ceil($total / $per_page),
        ];
    }

    /**
     * One summary per batch row, in the order given
     *
     * @param array $rows Batch rows
     * @return array List of [
     *     'id', 'kind' ('save'|'undo'), 'undoes_batch_id' (int|null), 'user_id', 'user_name' (display name
     *     only, '' when the user is gone), 'created_at_gmt' (UTC, Y-m-d H:i:s), 'source', 'note',
     *     'changes'     => requested changes that landed (applied or since undone),
     *     'items'       => products and variations those changes are on,
     *     'by_field'    => field => count of those changes,
     *     'open'        => how many are still in force, 'undone' => how many were undone since,
     *     'failed'      => rows whose write failed, 'pending' => rows never settled,
     *     'interrupted' => bool: a request died while writing (pending > 0),
     *     'state'       => '' | 'partly_undone' | 'undone' (always '' for an undo),
     *     'undoable'    => bool: a save with at least one change still in force,
     *     'undone_by'   => null | ['batch_id', 'user_id', 'user_name', 'created_at_gmt'] of the latest undo of it
     * ]
     */
    private function summarise($rows)
    {
        global $wpdb;

        if (empty($rows)) {
            return [];
        }

        $batches = Schema::batches_table();
        $changes = Schema::changes_table();
        $summaries = [];

        foreach ($rows as $row) {
            $summaries[(int) $row['id']] = [
                'id' => (int) $row['id'],
                'kind' => $row['kind'],
                'undoes_batch_id' => $row['undoes_batch_id'] === null ? null : (int) $row['undoes_batch_id'],
                'user_id' => (int) $row['user_id'],
                'user_name' => '',
                'created_at_gmt' => $row['created_at_gmt'],
                'source' => $row['source'],
                'note' => $row['note'],
                'changes' => 0,
                'items' => 0,
                'by_field' => [],
                'open' => 0,
                'undone' => 0,
                'failed' => 0,
                'pending' => 0,
                'interrupted' => false,
                'state' => '',
                'undoable' => false,
                'undone_by' => null,
            ];
        }

        $in = implode(',', array_keys($summaries));
        $landed = [self::STATUS_APPLIED, self::STATUS_UNDONE];

        $counts = $wpdb->get_results(
            "SELECT batch_id, field, status, (typed_value IS NOT NULL) AS requested, COUNT(*) AS n
               FROM {$changes} WHERE batch_id IN ({$in})
              GROUP BY batch_id, field, status, requested",
            ARRAY_A
        );

        foreach ($counts as $count) {
            $id = (int) $count['batch_id'];
            $n = (int) $count['n'];

            if ($count['status'] === self::STATUS_PENDING) {
                $summaries[$id]['pending'] += $n;
            } elseif ($count['status'] === self::STATUS_FAILED) {
                $summaries[$id]['failed'] += $n;
            }

            if (!(int) $count['requested'] || !in_array($count['status'], $landed, true)) {
                continue;
            }

            $field = $count['field'];
            $summaries[$id]['changes'] += $n;
            $summaries[$id]['by_field'][$field] = (isset($summaries[$id]['by_field'][$field]) ? $summaries[$id]['by_field'][$field] : 0) + $n;
            $summaries[$id][$count['status'] === self::STATUS_UNDONE ? 'undone' : 'open'] += $n;
        }

        $items = $wpdb->get_results(
            "SELECT batch_id, COUNT(DISTINCT item_id) AS n
               FROM {$changes}
              WHERE batch_id IN ({$in}) AND typed_value IS NOT NULL AND status IN ('" . implode("','", $landed) . "')
              GROUP BY batch_id",
            ARRAY_A
        );

        foreach ($items as $row) {
            $summaries[(int) $row['batch_id']]['items'] = (int) $row['n'];
        }

        // Undo batches that undid something (one that only recorded skipped rows
        // does not count). Oldest first, so the latest undo of a batch stays.
        $undos = $wpdb->get_results(
            "SELECT b.id, b.undoes_batch_id, b.user_id, b.created_at_gmt
               FROM {$batches} b
              WHERE b.undoes_batch_id IN ({$in})
                AND EXISTS (SELECT 1 FROM {$changes} c WHERE c.batch_id = b.id AND c.status = '" . self::STATUS_APPLIED . "')
              ORDER BY b.id ASC",
            ARRAY_A
        );

        $user_ids = wp_list_pluck($rows, 'user_id');

        foreach ($undos as $undo) {
            $user_ids[] = $undo['user_id'];
            $summaries[(int) $undo['undoes_batch_id']]['undone_by'] = [
                'batch_id' => (int) $undo['id'],
                'user_id' => (int) $undo['user_id'],
                'user_name' => '',
                'created_at_gmt' => $undo['created_at_gmt'],
            ];
        }

        $names = $this->user_names($user_ids);

        foreach ($summaries as $id => $summary) {
            $is_save = $summary['kind'] === self::KIND_SAVE;

            $summaries[$id]['user_name'] = isset($names[$summary['user_id']]) ? $names[$summary['user_id']] : '';
            $summaries[$id]['interrupted'] = $summary['pending'] > 0;
            $summaries[$id]['undoable'] = $is_save && $summary['open'] > 0;

            if ($is_save && $summary['undone'] > 0) {
                $summaries[$id]['state'] = $summary['open'] > 0 ? 'partly_undone' : 'undone';
            }

            if ($summary['undone_by']) {
                $by = $summary['undone_by']['user_id'];
                $summaries[$id]['undone_by']['user_name'] = isset($names[$by]) ? $names[$by] : '';
            }
        }

        return array_values($summaries);
    }

    /**
     * Change rows with decoded values and the name and SKU of their item
     *
     * @param array $rows Change rows as stored
     * @return array List, same order, of ['id', 'batch_id', 'item_id', 'product_id', 'item_type', 'field',
     *               'old', 'new' (decoded), 'typed' (what the user asked for; null on a derived change),
     *               'requested' (bool), 'status', 'message',
     *               'name' (null when the item no longer exists), 'sku', 'exists' (bool)]
     */
    private function describe_changes($rows)
    {
        $labels = $this->item_labels(wp_list_pluck($rows, 'item_id'));
        $described = [];

        foreach ($rows as $row) {
            $item_id = (int) $row['item_id'];
            $label = isset($labels[$item_id]) ? $labels[$item_id] : null;

            $described[] = [
                'id' => (int) $row['id'],
                'batch_id' => (int) $row['batch_id'],
                'item_id' => $item_id,
                'product_id' => (int) $row['product_id'],
                'item_type' => $row['item_type'],
                'field' => $row['field'],
                'old' => self::decode_value($row['field'], $row['old_value']),
                'new' => self::decode_value($row['field'], $row['new_value']),
                'typed' => self::decode_typed($row['field'], $row['typed_value']),
                'requested' => $row['typed_value'] !== null,
                'status' => $row['status'],
                'message' => $row['message'],
                'name' => $label ? $label['name'] : null,
                'sku' => $label ? $label['sku'] : '',
                'exists' => (bool) $label,
            ];
        }

        return $described;
    }

    /**
     * Name and SKU of products and variations, read in one query
     *
     * A variation's name is its stored title ("Product - Size"); one without
     * a SKU of its own shows its product's, as WooCommerce does.
     *
     * @param array $ids
     * @return array id => ['name' => string, 'sku' => string]; ids that no longer exist are left out
     */
    private function item_labels($ids)
    {
        global $wpdb;

        $ids = array_values(array_unique(array_filter(array_map('intval', (array) $ids))));
        if (empty($ids)) {
            return [];
        }

        $sql = "SELECT p.ID, p.post_title, p.post_parent, p.post_type, m.meta_value AS sku
                  FROM {$wpdb->posts} p
                  LEFT JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = '_sku'
                 WHERE p.ID IN (%s)";

        $labels = [];
        $inherit = [];

        foreach ($wpdb->get_results(sprintf($sql, implode(',', $ids)), ARRAY_A) as $row) {
            $labels[(int) $row['ID']] = ['name' => $row['post_title'], 'sku' => (string) $row['sku']];

            if ($row['post_type'] === 'product_variation' && (string) $row['sku'] === '' && (int) $row['post_parent']) {
                $inherit[(int) $row['ID']] = (int) $row['post_parent'];
            }
        }

        if ($inherit) {
            $parents = [];
            foreach ($wpdb->get_results(sprintf($sql, implode(',', array_unique($inherit))), ARRAY_A) as $row) {
                $parents[(int) $row['ID']] = (string) $row['sku'];
            }

            foreach ($inherit as $id => $parent_id) {
                $labels[$id]['sku'] = isset($parents[$parent_id]) ? $parents[$parent_id] : '';
            }
        }

        return $labels;
    }

    /**
     * Products and variations whose name or SKU contains a text
     *
     * @param string $text
     * @return array Ids, at most 1000
     */
    private function find_item_ids($text)
    {
        global $wpdb;

        $like = '%' . $wpdb->esc_like(trim((string) $text)) . '%';

        return array_map('intval', $wpdb->get_col(
            $wpdb->prepare(
                "SELECT ID FROM {$wpdb->posts} WHERE post_type IN ('product', 'product_variation') AND post_title LIKE %s
                  UNION
                 SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_sku' AND meta_value LIKE %s
                  LIMIT 1000",
                $like,
                $like
            )
        ));
    }

    /**
     * Display names, the only thing History shows of a user
     *
     * @param array $user_ids
     * @return array user id => display name; users that no longer exist are left out
     */
    private function user_names($user_ids)
    {
        $user_ids = array_values(array_unique(array_filter(array_map('intval', (array) $user_ids))));
        $names = [];

        if ($user_ids && function_exists('cache_users')) {
            cache_users($user_ids);
        }

        foreach ($user_ids as $user_id) {
            $user = get_userdata($user_id);
            if ($user) {
                $names[$user_id] = $user->display_name;
            }
        }

        return $names;
    }

    /**
     * Delete batches older than the retention period, with their changes
     *
     * @param int $months Batches created more than this many months ago are removed
     * @return int Number of batches deleted
     */
    public function prune($months = self::DEFAULT_RETENTION_MONTHS)
    {
        global $wpdb;

        $months = max(1, (int) $months);
        $cutoff = gmdate('Y-m-d H:i:s', strtotime('-' . $months . ' months', time()));
        $batches = Schema::batches_table();
        $changes = Schema::changes_table();

        $wpdb->query(
            $wpdb->prepare(
                "DELETE c FROM {$changes} c INNER JOIN {$batches} b ON b.id = c.batch_id WHERE b.created_at_gmt < %s",
                $cutoff
            )
        );

        return (int) $wpdb->query(
            $wpdb->prepare("DELETE FROM {$batches} WHERE created_at_gmt < %s", $cutoff)
        );
    }

    /**
     * Product value to its stored form
     *
     * null stays NULL (no stock quantity), booleans become 'yes'/'no' (the
     * way WooCommerce stores manage_stock), everything else is its string:
     * stock quantity '7', status 'instock', price '129.50', no price ''.
     *
     * @param mixed $value
     * @return string|null
     */
    public static function encode_value($value)
    {
        if ($value === null) {
            return null;
        }

        if (is_bool($value)) {
            return $value ? 'yes' : 'no';
        }

        return self::truncate($value, 191);
    }

    /**
     * Stored value back to what the product setters and getters use
     *
     * @param string      $field
     * @param string|null $stored
     * @return mixed
     */
    public static function decode_value($field, $stored)
    {
        if ($stored === null) {
            return null;
        }

        switch ($field) {
            case 'manage_stock':
                return $stored === 'yes';
            case 'stock_quantity':
                return is_numeric($stored) ? $stored + 0 : null;
            default:
                return $stored;
        }
    }

    /**
     * Stored typed_value back to a value. Same as decode_value(), except that
     * for manage_stock the user typed a starting quantity, not yes or no.
     *
     * @param string      $field
     * @param string|null $stored
     * @return mixed
     */
    public static function decode_typed($field, $stored)
    {
        if ($field === 'manage_stock' && is_numeric($stored)) {
            return $stored + 0;
        }

        return self::decode_value($field, $stored);
    }

    /**
     * Whether two stored values are the same value. Numbers are compared as
     * numbers, so '100' and '100.00' do not read as a change.
     *
     * @param string|null $a
     * @param string|null $b
     * @return bool
     */
    public static function same_value($a, $b)
    {
        if ($a === null || $b === null) {
            return $a === $b;
        }

        if (is_numeric($a) && is_numeric($b)) {
            return abs((float) $a - (float) $b) < 0.000001;
        }

        return (string) $a === (string) $b;
    }

    private static function truncate($text, $length)
    {
        $text = (string) $text;

        return function_exists('mb_substr') ? mb_substr($text, 0, $length) : substr($text, 0, $length);
    }
}
