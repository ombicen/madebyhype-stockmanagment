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
 */
class ChangeLog
{
    const KIND_SAVE = 'save';
    const KIND_UNDO = 'undo';

    const SOURCE_STOCK_SCREEN = 'stock_screen';
    const SOURCE_ORDER = 'order';

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
     * Values come back as stored; use decode_value() to get typed values.
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
     * Record one change row per field of one item
     *
     * A field of an item can be recorded once per batch (unique key). Record
     * the net change; a second row for the same field is refused, and then
     * none of the rows of this call are kept.
     *
     * @param int    $batch_id
     * @param array  $item    ['item_id' => int, 'product_id' => int (parent for a variation, else the item itself), 'item_type' => string]
     * @param array  $changes field => ['old' => value, 'new' => value], unencoded
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
                ],
                ['%d', '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s']
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
     * @return bool
     */
    public function set_change_status($change_id, $status, $message = '')
    {
        global $wpdb;

        return $wpdb->update(
            Schema::changes_table(),
            ['status' => $status, 'message' => self::truncate($message, 255)],
            ['id' => (int) $change_id],
            ['%s', '%s'],
            ['%d']
        ) !== false;
    }

    /**
     * Set the outcome of one change row and replace new_value with the value
     * the product really holds after the write
     *
     * @param int    $change_id
     * @param string $status    One of the STATUS_ constants
     * @param mixed  $new_value Unencoded
     * @param string $message
     * @return bool
     */
    public function set_change_result($change_id, $status, $new_value, $message = '')
    {
        global $wpdb;

        return $wpdb->update(
            Schema::changes_table(),
            ['status' => $status, 'new_value' => self::encode_value($new_value), 'message' => self::truncate($message, 255)],
            ['id' => (int) $change_id],
            ['%s', '%s', '%s'],
            ['%d']
        ) !== false;
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

    private static function truncate($text, $length)
    {
        $text = (string) $text;

        return function_exists('mb_substr') ? mb_substr($text, 0, $length) : substr($text, 0, $length);
    }
}
