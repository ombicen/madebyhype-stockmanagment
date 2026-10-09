<?php

/**
 * Toolbar of the grid tabs: search, filters button, view switch, page size and the sales period
 *
 * One GET form holds the whole state of the list. The inputs of the filter
 * panel belong to it through their form attribute, so searching keeps the
 * filters and applying filters keeps the search.
 *
 * @var \MadeByHypeStockmanagment\UI\UIManager $this
 * @var array  $request
 * @var array  $period
 * @var string $tab
 * @var string $view
 */

use MadeByHypeStockmanagment\Admin\AdminPage;
use MadeByHypeStockmanagment\Data\DataManager;

if (! defined('ABSPATH')) {
    exit;
}

$filter_count = count(array_filter($this->chips(), function ($chip) {
    return $chip['filter'];
}));
$period_key = (string) $period['key'];
$views = [
    'product' => __('By product', 'madebyhype-stockmanagment'),
    'sku' => __('By SKU', 'madebyhype-stockmanagment'),
];
?>
<form id="mbh-list-form" class="mbh-get-form mbh-toolbar" method="get" action="<?php echo esc_url(admin_url('edit.php')); ?>" role="search">
    <input type="hidden" name="post_type" value="product">
    <input type="hidden" name="page" value="<?php echo esc_attr(AdminPage::PAGE_SLUG); ?>">
    <input type="hidden" name="tab" value="<?php echo esc_attr($tab); ?>" data-default="all">
    <?php if ($tab === 'all'): ?>
        <input type="hidden" name="view" value="<?php echo esc_attr($view); ?>" data-default="product">
    <?php else: ?>
        <input type="hidden" name="attention" value="<?php echo esc_attr($request['attention']); ?>" data-default="all">
    <?php endif; ?>
    <?php if ($request['sort_by'] !== ''): ?>
        <input type="hidden" name="sort_by" value="<?php echo esc_attr($request['sort_by']); ?>">
        <input type="hidden" name="sort_order" value="<?php echo esc_attr($request['sort_order']); ?>">
    <?php endif; ?>

    <div class="mbh-toolbar-group">
        <label class="screen-reader-text" for="mbh-search"><?php esc_html_e('Search name, SKU or ID', 'madebyhype-stockmanagment'); ?></label>
        <input type="search" id="mbh-search" name="s" value="<?php echo esc_attr($request['search']); ?>" placeholder="<?php esc_attr_e('Search name, SKU or ID', 'madebyhype-stockmanagment'); ?>">
        <button type="submit" class="button"><?php esc_html_e('Search', 'madebyhype-stockmanagment'); ?></button>

        <button type="button" class="button" id="mbh-filters-toggle" aria-expanded="false" aria-controls="mbh-filters">
            <?php
            if ($filter_count) {
                /* translators: %d: number of active filters */
                echo esc_html(sprintf(__('Filters (%d)', 'madebyhype-stockmanagment'), $filter_count));
            } else {
                esc_html_e('Filters', 'madebyhype-stockmanagment');
            }
            ?>
        </button>

        <?php if ($tab === 'all'): ?>
            <span class="mbh-view-switch" role="group" aria-label="<?php esc_attr_e('View', 'madebyhype-stockmanagment'); ?>">
                <?php foreach ($views as $key => $label): ?>
                    <a href="<?php echo esc_url($this->url(['view' => $key === 'product' ? null : $key])); ?>" class="button<?php echo $key === $view ? ' mbh-view-current' : ''; ?>"<?php echo $key === $view ? ' aria-current="true"' : ''; ?>><?php echo esc_html($label); ?></a>
                <?php endforeach; ?>
            </span>
        <?php endif; ?>
    </div>

    <div class="mbh-toolbar-group mbh-toolbar-group--end">
        <label for="mbh-per-page"><?php esc_html_e('Rows per page', 'madebyhype-stockmanagment'); ?></label>
        <select id="mbh-per-page" name="per_page" data-mbh-submit data-current="<?php echo esc_attr($request['per_page']); ?>" data-default="<?php echo esc_attr(AdminPage::DEFAULT_PER_PAGE); ?>">
            <?php foreach (AdminPage::PER_PAGE_OPTIONS as $size): ?>
                <option value="<?php echo esc_attr($size); ?>"<?php selected($request['per_page'], $size); ?>><?php echo esc_html($size); ?></option>
            <?php endforeach; ?>
        </select>

        <label for="mbh-period"><?php esc_html_e('Sold in', 'madebyhype-stockmanagment'); ?></label>
        <select id="mbh-period" name="period" data-mbh-submit data-current="<?php echo esc_attr($period_key); ?>" data-default="<?php echo esc_attr(DataManager::DEFAULT_PERIOD); ?>">
            <?php foreach ($this->period_options() as $key => $label): ?>
                <option value="<?php echo esc_attr($key); ?>"<?php selected($period_key, (string) $key); ?>><?php echo esc_html($label); ?></option>
            <?php endforeach; ?>
        </select>

        <span id="mbh-period-custom" class="mbh-period-custom"<?php echo $period_key === 'custom' ? '' : ' hidden'; ?>>
            <label class="screen-reader-text" for="mbh-period-start"><?php esc_html_e('First day of the sales period', 'madebyhype-stockmanagment'); ?></label>
            <input type="date" id="mbh-period-start" name="start_date" value="<?php echo esc_attr($period_key === 'custom' ? $period['start_date'] : ''); ?>">
            <span aria-hidden="true">–</span>
            <label class="screen-reader-text" for="mbh-period-end"><?php esc_html_e('Last day of the sales period', 'madebyhype-stockmanagment'); ?></label>
            <input type="date" id="mbh-period-end" name="end_date" value="<?php echo esc_attr($period_key === 'custom' ? $period['end_date'] : ''); ?>">
            <button type="submit" class="button"><?php esc_html_e('Apply', 'madebyhype-stockmanagment'); ?></button>
        </span>
    </div>
</form>
