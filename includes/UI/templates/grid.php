<?php

/**
 * The grid of All stock and Needs attention: kind links, count and paging, table, empty states
 *
 * @var \MadeByHypeStockmanagment\UI\UIManager $this
 * @var array  $request
 * @var array  $result  DataManager::get_list()
 * @var array  $caps
 * @var string $tab
 * @var string $view
 */

if (! defined('ABSPATH')) {
    exit;
}

// A list that could not be read is not an empty list: the notice above says so, and there is no table
if (!empty($result['error'])) {
    return;
}

$strings = self::strings();
$rows = $result['rows'];
$total = (int) $result['total_count'];
$has_search = $request['search'] !== '';
$has_filters = (bool) array_filter($this->chips(), function ($chip) {
    return $chip['filter'];
});
$edit_url = admin_url('post.php?action=edit&post=%d');
$history_url = $this->tab_url('history') . '&item=%d';

$none = function () {
    echo '<span class="mbh-none">—</span>';
};
$value = function ($text) {
    echo '<span class="mbh-value">' . esc_html($text) . '</span>';
};
$sub = function ($text) {
    echo '<span class="mbh-sub">' . esc_html($text) . '</span>';
};

if ($view === 'sku') {
    /* translators: %s: number of SKUs */
    $count_label = sprintf(_n('%s SKU', '%s SKUs', $total, 'madebyhype-stockmanagment'), number_format_i18n($total));
} else {
    /* translators: %s: number of products */
    $count_label = sprintf(_n('%s product', '%s products', $total, 'madebyhype-stockmanagment'), number_format_i18n($total));
}
?>

<?php if ($tab === 'attention'): ?>
    <div class="mbh-attention-bar">
        <ul class="subsubsub">
            <?php
            $attention_kinds = [
                'all' => __('All', 'madebyhype-stockmanagment'),
                'out' => __('Out of stock', 'madebyhype-stockmanagment'),
                'low' => __('Low stock', 'madebyhype-stockmanagment'),
                'backorder' => __('On backorder', 'madebyhype-stockmanagment'),
            ];
            $last = 'backorder';
            foreach ($attention_kinds as $kind => $kind_label):
                $current = $request['attention'] === $kind;
            ?>
                <li>
                    <a href="<?php echo esc_url($this->url(['attention' => $kind === 'all' ? null : $kind])); ?>"<?php echo $current ? ' class="current" aria-current="page"' : ''; ?>><?php echo esc_html($kind_label); ?> <span class="count">(<?php echo esc_html(number_format_i18n(isset($result['counts'][$kind]) ? $result['counts'][$kind] : 0)); ?>)</span></a><?php echo $kind === $last ? '' : ' |'; ?>
                </li>
            <?php endforeach; ?>
        </ul>
        <label class="mbh-sold-only">
            <input type="checkbox" form="mbh-list-form" name="sold_only" value="1" data-mbh-submit<?php checked($request['sold_only']); ?>>
            <?php esc_html_e('Only items sold in this period', 'madebyhype-stockmanagment'); ?>
        </label>
    </div>
<?php endif; ?>

<?php if ($total === 0): ?>
    <div class="mbh-empty">
        <?php if ($tab === 'attention' && $has_search): ?>
            <p>
                <?php
                /* translators: %s: search text */
                echo esc_html(sprintf(__('Nothing in Needs attention matches "%s".', 'madebyhype-stockmanagment'), $request['search']));
                ?>
                <a href="<?php echo esc_url($this->tab_url('all', ['s' => $request['search']])); ?>"><?php esc_html_e('Search all stock', 'madebyhype-stockmanagment'); ?></a>
            </p>
        <?php elseif ($tab === 'attention' && ($has_filters || $request['attention'] !== 'all')): ?>
            <p>
                <?php esc_html_e('Nothing in Needs attention matches these filters.', 'madebyhype-stockmanagment'); ?>
                <a href="<?php echo esc_url($this->clear_all_url()); ?>"><?php esc_html_e('Clear all filters', 'madebyhype-stockmanagment'); ?></a>
            </p>
        <?php elseif ($tab === 'attention'): ?>
            <p><?php esc_html_e('Nothing needs attention. No SKU is out of stock or low.', 'madebyhype-stockmanagment'); ?></p>
        <?php elseif ($has_search): ?>
            <p>
                <?php
                /* translators: %s: search text */
                echo esc_html(sprintf(__('No products or SKUs match "%s".', 'madebyhype-stockmanagment'), $request['search']));
                ?>
                <a href="<?php echo esc_url($this->url(['s' => null])); ?>"><?php esc_html_e('Clear search', 'madebyhype-stockmanagment'); ?></a>
            </p>
            <?php if ($has_filters): ?>
                <p>
                    <?php esc_html_e('Filters are also active.', 'madebyhype-stockmanagment'); ?>
                    <a href="<?php echo esc_url($this->clear_filters_url()); ?>"><?php esc_html_e('Clear all filters', 'madebyhype-stockmanagment'); ?></a>
                </p>
            <?php endif; ?>
        <?php elseif ($has_filters): ?>
            <p>
                <?php esc_html_e('No products match these filters.', 'madebyhype-stockmanagment'); ?>
                <a href="<?php echo esc_url($this->clear_filters_url()); ?>"><?php esc_html_e('Clear all filters', 'madebyhype-stockmanagment'); ?></a>
            </p>
        <?php else: ?>
            <p><?php esc_html_e('No products found.', 'madebyhype-stockmanagment'); ?></p>
        <?php endif; ?>
    </div>
    <?php return; ?>
<?php endif; ?>

<?php
$position = 'top';
include __DIR__ . '/pagination.php';
?>

<table id="mbh-grid" class="wp-list-table widefat mbh-grid">
    <caption class="screen-reader-text">
        <?php
        if ($tab === 'attention') {
            esc_html_e('Items that are out of stock or low', 'madebyhype-stockmanagment');
        } elseif ($view === 'sku') {
            esc_html_e('Stock and prices, one row per SKU', 'madebyhype-stockmanagment');
        } else {
            esc_html_e('Stock and prices, one row per product', 'madebyhype-stockmanagment');
        }
        ?>
    </caption>
    <thead>
        <tr>
            <th scope="col" class="mbh-col-id"><?php esc_html_e('ID', 'madebyhype-stockmanagment'); ?></th>
            <?php $this->sort_heading('name', esc_html__('Product', 'madebyhype-stockmanagment'), 'mbh-col-name'); ?>
            <th scope="col" class="mbh-col-type"><?php esc_html_e('Type', 'madebyhype-stockmanagment'); ?></th>
            <?php $this->sort_heading('sku', esc_html__('SKU', 'madebyhype-stockmanagment'), 'mbh-col-sku'); ?>
            <?php $this->sort_heading('stock_quantity', esc_html__('Stock', 'madebyhype-stockmanagment'), 'mbh-col-stock mbh-num'); ?>
            <th scope="col" class="mbh-col-status"><?php esc_html_e('Status', 'madebyhype-stockmanagment'); ?></th>
            <?php $this->sort_heading('price', esc_html__('Regular price', 'madebyhype-stockmanagment'), 'mbh-col-regular mbh-num', true, __('Sorts by the price customers pay now (the sale price when there is one).', 'madebyhype-stockmanagment')); ?>
            <th scope="col" class="mbh-col-sale mbh-num"><?php esc_html_e('Sale price', 'madebyhype-stockmanagment'); ?></th>
            <?php
            $this->sort_heading(
                'total_sales',
                /* translators: %s: the sales period, for example "last 30 days" */
                esc_html(sprintf(__('Sold · %s', 'madebyhype-stockmanagment'), $this->period_label())),
                'mbh-col-sold mbh-num',
                true,
                __('Units in paid orders (processing or completed) placed in this period.', 'madebyhype-stockmanagment')
            );
            $this->sort_heading(
                'cover',
                esc_html__('Cover', 'madebyhype-stockmanagment'),
                'mbh-col-cover mbh-num',
                false,
                __('Days the stock lasts at the rate it sold in this period.', 'madebyhype-stockmanagment')
            );
            ?>
        </tr>
    </thead>
    <tbody>
        <?php foreach ($rows as $row): ?>
            <?php include __DIR__ . '/row.php'; ?>
        <?php endforeach; ?>
    </tbody>
</table>

<?php
$position = 'bottom';
include __DIR__ . '/pagination.php';
