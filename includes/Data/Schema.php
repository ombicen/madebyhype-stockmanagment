<?php

namespace MadeByHypeStockmanagment\Data;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Lifecycle of the plugin's custom tables: create, upgrade, remove.
 *
 * The schema version is stored in an autoloaded option, so the per-request
 * check is one in-memory comparison. Bump DB_VERSION whenever a definition
 * below changes; maybe_upgrade() then runs install() once on the next request.
 */
class Schema
{
    const DB_VERSION = '2';
    const VERSION_OPTION = 'madebyhype_stock_db_version';
    const RETRY_TRANSIENT = 'madebyhype_stock_db_retry';
    const PRUNE_HOOK = 'madebyhype_stock_prune_log';

    /**
     * One row per save or undo
     */
    public static function batches_table()
    {
        global $wpdb;
        return $wpdb->prefix . 'madebyhype_stock_batches';
    }

    /**
     * One row per changed field of one product or variation
     */
    public static function changes_table()
    {
        global $wpdb;
        return $wpdb->prefix . 'madebyhype_stock_changes';
    }

    /**
     * Snapshot table of the old Version History. Read and written by
     * VersionManager only; kept until the legacy revert is retired.
     */
    public static function legacy_versions_table()
    {
        global $wpdb;
        return $wpdb->prefix . 'madebyhype_stock_versions';
    }

    /**
     * Run install() when the stored schema version is behind
     *
     * Called on every request, in every context. Activation does not fire for
     * a plugin that is updated in place, so this is what creates the tables on
     * an existing site.
     */
    public static function maybe_upgrade()
    {
        if (version_compare((string) get_option(self::VERSION_OPTION, '0'), self::DB_VERSION, '>=')) {
            return;
        }

        // A failed attempt is not repeated on every request
        if (get_transient(self::RETRY_TRANSIENT)) {
            return;
        }

        self::install();
    }

    /**
     * Create or update the tables and record the schema version
     *
     * Safe to run repeatedly: dbDelta only applies differences.
     *
     * @return bool False when a table is still missing afterwards
     */
    public static function install()
    {
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        dbDelta(self::get_schema());

        // The legacy table is created on a fresh install only. An existing one
        // is never altered: its data stays exactly as it is.
        if (!self::table_exists(self::legacy_versions_table())) {
            dbDelta(self::get_legacy_schema());
        }

        foreach ([self::batches_table(), self::changes_table(), self::legacy_versions_table()] as $table) {
            if (!self::table_exists($table)) {
                error_log('MadeByHype Stock Management - Could not create table ' . $table);
                set_transient(self::RETRY_TRANSIENT, 1, HOUR_IN_SECONDS);
                return false;
            }
        }

        update_option(self::VERSION_OPTION, self::DB_VERSION, true);
        delete_transient(self::RETRY_TRANSIENT);

        return true;
    }

    /**
     * Remove everything this plugin stored. Called from uninstall.php only.
     */
    public static function uninstall()
    {
        global $wpdb;

        foreach ([self::changes_table(), self::batches_table(), self::legacy_versions_table()] as $table) {
            $wpdb->query("DROP TABLE IF EXISTS {$table}");
        }

        delete_option(self::VERSION_OPTION);
        delete_transient(self::RETRY_TRANSIENT);
        wp_clear_scheduled_hook(self::PRUNE_HOOK);

        // Category lookups cached by DataManager
        $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
                $wpdb->esc_like('_transient_mbh_stock_') . '%',
                $wpdb->esc_like('_transient_timeout_mbh_stock_') . '%'
            )
        );
    }

    /**
     * @param string $table Full table name
     * @return bool
     */
    public static function table_exists($table)
    {
        global $wpdb;

        return $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($table))) === $table;
    }

    /**
     * Change-log tables, in dbDelta format (one column per line, two spaces
     * after PRIMARY KEY, KEY rather than INDEX)
     *
     * All timestamps are UTC and set by PHP, never by the database.
     * Encoding of old_value/new_value: see ChangeLog::encode_value().
     *
     * The two unique keys are what makes a save safe to resend:
     * user_save_token gives one batch per press of Save (NULL, for a request
     * without a token, never collides), and batch_item_field allows a field
     * of an item to be recorded, and therefore written, once per batch.
     */
    private static function get_schema()
    {
        global $wpdb;

        $batches = self::batches_table();
        $changes = self::changes_table();
        $charset_collate = $wpdb->get_charset_collate();

        return "CREATE TABLE {$batches} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  created_at_gmt datetime NOT NULL,
  user_id bigint(20) unsigned NOT NULL DEFAULT 0,
  source varchar(32) NOT NULL DEFAULT 'stock_screen',
  source_id bigint(20) unsigned NOT NULL DEFAULT 0,
  kind varchar(10) NOT NULL DEFAULT 'save',
  undoes_batch_id bigint(20) unsigned DEFAULT NULL,
  note varchar(255) NOT NULL DEFAULT '',
  save_token varchar(64) DEFAULT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY user_save_token (user_id,save_token),
  KEY created_at_gmt (created_at_gmt),
  KEY undoes_batch_id (undoes_batch_id)
) {$charset_collate};
CREATE TABLE {$changes} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  batch_id bigint(20) unsigned NOT NULL,
  item_id bigint(20) unsigned NOT NULL,
  product_id bigint(20) unsigned NOT NULL,
  item_type varchar(20) NOT NULL DEFAULT '',
  field varchar(32) NOT NULL,
  old_value varchar(191) DEFAULT NULL,
  new_value varchar(191) DEFAULT NULL,
  status varchar(10) NOT NULL DEFAULT 'pending',
  message varchar(255) NOT NULL DEFAULT '',
  PRIMARY KEY  (id),
  UNIQUE KEY batch_item_field (batch_id,item_id,field),
  KEY item_id (item_id),
  KEY product_id (product_id)
) {$charset_collate};";
    }

    /**
     * Definition of the legacy snapshot table, unchanged from 1.0.6
     */
    private static function get_legacy_schema()
    {
        global $wpdb;

        $table = self::legacy_versions_table();
        $charset_collate = $wpdb->get_charset_collate();

        return "CREATE TABLE {$table} (
  id bigint(20) NOT NULL AUTO_INCREMENT,
  version_number int(11) NOT NULL,
  created_at datetime DEFAULT CURRENT_TIMESTAMP,
  changes_data longtext NOT NULL,
  description varchar(255) DEFAULT '',
  PRIMARY KEY  (id),
  KEY version_number (version_number)
) {$charset_collate};";
    }
}
