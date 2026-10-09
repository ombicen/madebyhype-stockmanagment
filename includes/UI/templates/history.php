<?php

/**
 * History tab: the frame. The entries are read and drawn by scripts/stock-history.js
 * through the madebyhype_stock_history action.
 *
 * @var \MadeByHypeStockmanagment\UI\UIManager $this
 * @var array      $request
 * @var array|null $history_item ['id', 'name', 'sku'] when narrowed to one product or variation
 */

use MadeByHypeStockmanagment\Admin\AdminPage;

if (! defined('ABSPATH')) {
    exit;
}

$filtered = $request['search'] !== '' || $request['user'];
?>
<div id="mbh-history" class="mbh-history">
    <?php if ($history_item): ?>
        <div class="mbh-history-head">
        <h2 class="mbh-history-title">
            <?php
            if ($history_item['name'] === null) {
                /* translators: %d: product id */
                echo esc_html(sprintf(__('History for item #%d', 'madebyhype-stockmanagment'), $history_item['id']));
            } elseif ($history_item['sku'] !== '') {
                /* translators: 1: product name, 2: SKU */
                echo esc_html(sprintf(__('History for %1$s (%2$s)', 'madebyhype-stockmanagment'), $history_item['name'], $history_item['sku']));
            } else {
                /* translators: %s: product name */
                echo esc_html(sprintf(__('History for %s', 'madebyhype-stockmanagment'), $history_item['name']));
            }
            ?>
        </h2>
        <a class="mbh-back-link" href="<?php echo esc_url($this->tab_url('history')); ?>"><?php echo self::icon('chevron', 'mbh-icon--flip'); ?><span><?php esc_html_e('Show all history', 'madebyhype-stockmanagment'); ?></span></a>
        </div>
    <?php else: ?>
        <form class="mbh-get-form mbh-toolbar mbh-history-filters" method="get" action="<?php echo esc_url(admin_url('edit.php')); ?>" role="search">
            <input type="hidden" name="post_type" value="product">
            <input type="hidden" name="page" value="<?php echo esc_attr(AdminPage::PAGE_SLUG); ?>">
            <input type="hidden" name="tab" value="history">

            <div class="mbh-search">
                <?php echo self::icon('search'); ?>
                <label class="screen-reader-text" for="mbh-history-search"><?php esc_html_e('Search name or SKU', 'madebyhype-stockmanagment'); ?></label>
                <input type="search" id="mbh-history-search" name="s" value="<?php echo esc_attr($request['search']); ?>" placeholder="<?php esc_attr_e('Search name or SKU', 'madebyhype-stockmanagment'); ?>" autocomplete="off">
            </div>

            <span class="mbh-period">
                <label for="mbh-history-user"><?php esc_html_e('Saved by', 'madebyhype-stockmanagment'); ?></label>
                <?php // The script adds everyone who has a save in History (the history action, view=users) ?>
                <select id="mbh-history-user" class="mbh-select" name="user">
                    <option value=""><?php esc_html_e('Everyone', 'madebyhype-stockmanagment'); ?></option>
                    <?php if ($request['user']): ?>
                        <option value="<?php echo esc_attr($request['user']); ?>" selected><?php echo esc_html($this->history_user_name($request['user'])); ?></option>
                    <?php endif; ?>
                </select>
            </span>

            <button type="submit" class="button"><?php esc_html_e('Filter', 'madebyhype-stockmanagment'); ?></button>
            <?php if ($filtered): ?>
                <a href="<?php echo esc_url($this->tab_url('history')); ?>" class="mbh-clear-all"><?php esc_html_e('Clear', 'madebyhype-stockmanagment'); ?></a>
            <?php endif; ?>
        </form>
    <?php endif; ?>

    <div id="mbh-notices" class="mbh-notices" role="status" aria-live="polite"></div>
    <div id="mbh-history-body" class="mbh-history-body" aria-live="polite"></div>
</div>
