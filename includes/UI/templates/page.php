<?php

/**
 * The stock screen: heading, tabs, then the grid tabs or History
 *
 * @var \MadeByHypeStockmanagment\UI\UIManager $this
 * @var array      $request      AdminPage::parse_request()
 * @var array|null $result       DataManager::get_list(), null on History
 * @var array      $period       DataManager::resolve_period()
 * @var array      $caps         AdminPage::permissions()
 * @var array|null $history_item ['id', 'name', 'sku'] when History is narrowed to one item
 * @var string     $tab          all, attention or history
 * @var string     $view         product or sku
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
    <h1 class="wp-heading-inline"><?php echo esc_html(get_admin_page_title()); ?></h1>
    <hr class="wp-header-end">

    <?php if (!class_exists('WooCommerce')): ?>
        <div class="notice notice-error inline">
            <p><strong><?php esc_html_e('WooCommerce is not active!', 'madebyhype-stockmanagment'); ?></strong> <?php esc_html_e('This plugin requires WooCommerce to be installed and activated to display products.', 'madebyhype-stockmanagment'); ?></p>
        </div>
    <?php else: ?>
        <nav class="nav-tab-wrapper wp-clearfix" aria-label="<?php esc_attr_e('Stock Management sections', 'madebyhype-stockmanagment'); ?>">
            <?php foreach ($tabs as $key => $label): ?>
                <a href="<?php echo esc_url($this->tab_url($key)); ?>" class="nav-tab<?php echo $key === $tab ? ' nav-tab-active' : ''; ?>"<?php echo $key === $tab ? ' aria-current="page"' : ''; ?>><?php echo esc_html($label); ?></a>
            <?php endforeach; ?>
        </nav>

        <script type="application/json" id="mbh-stock-page"><?php echo wp_json_encode($this->page_data($history_item), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?></script>

        <noscript>
            <div class="notice notice-warning inline"><p><?php esc_html_e('This screen needs JavaScript to save changes, open variations and show History.', 'madebyhype-stockmanagment'); ?></p></div>
        </noscript>

        <?php if ($tab === 'history'): ?>
            <?php include __DIR__ . '/history.php'; ?>
        <?php else: ?>
            <?php include __DIR__ . '/toolbar.php'; ?>
            <?php include __DIR__ . '/chips.php'; ?>

            <?php if (!$can_edit): ?>
                <p class="mbh-permission-note"><?php esc_html_e('You can view stock here but not change it.', 'madebyhype-stockmanagment'); ?></p>
            <?php elseif (empty($caps['prices'])): ?>
                <p class="mbh-permission-note"><?php esc_html_e('You can change stock here. Prices are read-only for your account.', 'madebyhype-stockmanagment'); ?></p>
            <?php elseif (empty($caps['stock'])): ?>
                <p class="mbh-permission-note"><?php esc_html_e('You can change prices here. Stock is read-only for your account.', 'madebyhype-stockmanagment'); ?></p>
            <?php endif; ?>

            <div id="mbh-notices" class="mbh-notices" role="status" aria-live="polite">
                <?php if (!empty($result['error'])): ?>
                    <div class="notice notice-error inline"><p><?php echo esc_html($result['error']['message']); ?></p></div>
                <?php endif; ?>
            </div>

            <?php if ($can_edit && empty($result['error'])): ?>
                <?php include __DIR__ . '/save-bar.php'; ?>
            <?php endif; ?>

            <div class="mbh-layout">
                <?php include __DIR__ . '/filters.php'; ?>
                <div class="mbh-main">
                    <?php include __DIR__ . '/grid.php'; ?>
                </div>
            </div>
        <?php endif; ?>
    <?php endif; ?>
</div>
