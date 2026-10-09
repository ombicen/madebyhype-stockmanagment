<?php

/**
 * The stock screen: heading and tabs, then the grid tabs or History
 *
 * @var \MadeByHypeStockmanagment\UI\UIManager $this
 * @var array      $request         AdminPage::parse_request()
 * @var array|null $result          DataManager::get_list(), null on History
 * @var array      $period          DataManager::resolve_period()
 * @var array      $caps            AdminPage::permissions()
 * @var array|null $list            UIManager::list_frame(): what the grid tabs show around the rows
 * @var array|null $history_item    ['id', 'name', 'sku'] when History is narrowed to one item
 * @var int|null   $attention_count Items in Needs attention with no search or filter; null when not known
 * @var string     $tab             all, attention or history
 * @var string     $view            product or sku
 */

if (! defined('ABSPATH')) {
    exit;
}

$tabs = [
    'all' => __('All stock', 'madebyhype-stockmanagment'),
    'attention' => __('Needs attention', 'madebyhype-stockmanagment'),
    'history' => __('History', 'madebyhype-stockmanagment'),
];
$can_edit = !empty($caps['stock']) || !empty($caps['prices']);
?>
<div class="wrap mbh-stock mbh-stock--<?php echo esc_attr($tab); ?>">
    <h1 class="mbh-title"><?php echo esc_html(get_admin_page_title()); ?></h1>
    <hr class="wp-header-end">

    <?php if (!class_exists('WooCommerce')): ?>
        <div class="mbh-notice mbh-notice--error notice notice-error inline">
            <?php echo self::icon('warning'); ?>
            <p><strong><?php esc_html_e('WooCommerce is not active!', 'madebyhype-stockmanagment'); ?></strong> <?php esc_html_e('This plugin requires WooCommerce to be installed and activated to display products.', 'madebyhype-stockmanagment'); ?></p>
        </div>
    <?php else: ?>
        <nav class="mbh-tabs" aria-label="<?php esc_attr_e('Stock Management sections', 'madebyhype-stockmanagment'); ?>">
            <?php foreach ($tabs as $key => $label): ?>
                <a href="<?php echo esc_url($this->tab_url($key)); ?>" class="mbh-tab<?php echo $key === $tab ? ' is-current' : ''; ?>" data-tab="<?php echo esc_attr($key); ?>"<?php echo $key === $tab ? ' aria-current="page"' : ''; ?>>
                    <?php echo esc_html($label); ?>
                    <?php if ($key === 'attention'): ?>
                        <span class="mbh-tab-count" id="mbh-attention-count"<?php echo $attention_count === null ? ' hidden' : ''; ?>>
                            <span aria-hidden="true"><?php echo esc_html($attention_count === null ? '' : number_format_i18n($attention_count)); ?></span>
                            <span class="screen-reader-text">
                                <?php
                                /* translators: %s: number of items */
                                echo esc_html($attention_count === null ? '' : sprintf(_n('%s item', '%s items', $attention_count, 'madebyhype-stockmanagment'), number_format_i18n($attention_count)));
                                ?>
                            </span>
                        </span>
                    <?php endif; ?>
                </a>
            <?php endforeach; ?>
        </nav>

        <script type="application/json" id="mbh-stock-page"><?php echo wp_json_encode($this->page_data($history_item), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?></script>

        <noscript>
            <div class="mbh-notice mbh-notice--warning notice notice-warning inline">
                <?php echo self::icon('warning'); ?>
                <p><?php esc_html_e('This screen needs JavaScript to save changes, open variations and show History.', 'madebyhype-stockmanagment'); ?></p>
            </div>
        </noscript>

        <?php // What happens in the table is said here for screen readers: one line per event, put in by the script ?>
        <div id="mbh-live" class="screen-reader-text" role="status" aria-live="polite" aria-atomic="false"></div>

        <?php if ($tab === 'history'): ?>
            <?php include __DIR__ . '/history.php'; ?>
        <?php else: ?>
            <?php include __DIR__ . '/toolbar.php'; ?>

            <?php if (!$can_edit): ?>
                <p class="mbh-permission-note"><?php echo self::icon('info'); ?><span><?php esc_html_e('You can view stock here but not change it.', 'madebyhype-stockmanagment'); ?></span></p>
            <?php elseif (empty($caps['prices'])): ?>
                <p class="mbh-permission-note"><?php echo self::icon('info'); ?><span><?php esc_html_e('You can change stock here. Prices are read-only for your account.', 'madebyhype-stockmanagment'); ?></span></p>
            <?php elseif (empty($caps['stock'])): ?>
                <p class="mbh-permission-note"><?php echo self::icon('info'); ?><span><?php esc_html_e('You can change prices here. Stock is read-only for your account.', 'madebyhype-stockmanagment'); ?></span></p>
            <?php endif; ?>

            <div id="mbh-notices" class="mbh-notices" role="status" aria-live="polite">
                <?php if (!empty($result['error'])): ?>
                    <div class="mbh-notice mbh-notice--error notice notice-error inline">
                        <?php echo self::icon('warning'); ?>
                        <p><?php echo esc_html($result['error']['message']); ?></p>
                    </div>
                <?php endif; ?>
            </div>

            <?php if ($list): ?>
                <div class="mbh-main" id="mbh-list">
                    <?php include __DIR__ . '/grid.php'; ?>
                </div>
            <?php endif; ?>

            <?php if ($can_edit && $list): ?>
                <?php include __DIR__ . '/save-bar.php'; ?>
            <?php endif; ?>

            <?php if ($list): ?>
                <?php include __DIR__ . '/filters.php'; ?>
            <?php endif; ?>
        <?php endif; ?>
    <?php endif; ?>
</div>
