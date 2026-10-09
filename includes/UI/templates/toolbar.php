<?php

/**
 * Toolbar of the grid tabs, one row: search, view switch, the Filters button, the bulk price change and the sales period
 *
 * The script reads the fields and loads the list without leaving the page.
 * The form is still a working GET form (search, period, and the view and
 * sort in force) for the one case where there is no grid to redraw: a list
 * that could not be read.
 *
 * @var \MadeByHypeStockmanagment\UI\UIManager $this
 * @var array      $request
 * @var array      $period
 * @var array|null $list UIManager::list_frame()
 * @var string     $tab
 * @var string     $view
 * @var array      $caps
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

    <div class="mbh-search">
        <?php echo self::icon('search'); ?>
        <label class="screen-reader-text" for="mbh-search"><?php esc_html_e('Search name, SKU or ID', 'madebyhype-stockmanagment'); ?></label>
        <input type="search" id="mbh-search" name="s" value="<?php echo esc_attr($request['search']); ?>" placeholder="<?php esc_attr_e('Search name, SKU or ID', 'madebyhype-stockmanagment'); ?>" autocomplete="off">
        <button type="submit" class="button mbh-search-button"><?php esc_html_e('Search', 'madebyhype-stockmanagment'); ?></button>
    </div>

    <?php if ($tab === 'all'): ?>
        <span class="mbh-seg mbh-view-switch" id="mbh-view-switch" role="group" aria-label="<?php esc_attr_e('View', 'madebyhype-stockmanagment'); ?>">
            <?php foreach ($views as $key => $label): ?>
                <a href="<?php echo esc_url($this->url(['view' => $key === 'product' ? null : $key])); ?>" data-nav="view-<?php echo esc_attr($key); ?>"<?php echo $key === $view ? ' class="is-current" aria-current="true"' : ''; ?>><?php echo esc_html($label); ?></a>
            <?php endforeach; ?>
        </span>
    <?php endif; ?>

    <?php if ($list): ?>
    <button type="button" class="button mbh-filters-toggle" id="mbh-filters-toggle" aria-expanded="false" aria-controls="mbh-filters" aria-haspopup="dialog">
        <?php echo self::icon('filter'); ?>
        <span><?php esc_html_e('Filters', 'madebyhype-stockmanagment'); ?></span>
        <span class="mbh-dot" id="mbh-filters-dot" aria-hidden="true"<?php echo $filter_count ? '' : ' hidden'; ?>><?php echo esc_html($filter_count ? number_format_i18n($filter_count) : ''); ?></span>
        <span class="screen-reader-text" id="mbh-filters-dot-text">
            <?php
            /* translators: %d: number of filters that are switched on */
            echo esc_html($filter_count ? sprintf(_n('%d filter is on', '%d filters are on', $filter_count, 'madebyhype-stockmanagment'), $filter_count) : '');
            ?>
        </span>
    </button>
    <?php if ($tab === 'all' && !empty($caps['prices'])): ?>
    <button type="button" class="button mbh-bulk-toggle" id="mbh-bulk-price" aria-haspopup="dialog">
        <?php echo self::icon('price'); ?>
        <span><?php echo esc_html($this->t('bulkButton')); ?></span>
    </button>
    <?php endif; ?>
    <?php endif; ?>

    <span class="mbh-period">
        <label for="mbh-period"><?php esc_html_e('Sold in', 'madebyhype-stockmanagment'); ?></label>
        <select id="mbh-period" class="mbh-select" name="period" data-current="<?php echo esc_attr($period_key); ?>" data-default="<?php echo esc_attr(\MadeByHypeStockmanagment\Settings::period()); ?>">
            <?php foreach ($this->period_options() as $key => $label): ?>
                <option value="<?php echo esc_attr($key); ?>"<?php selected($period_key, (string) $key); ?>><?php echo esc_html($label); ?></option>
            <?php endforeach; ?>
        </select>

        <span id="mbh-period-custom" class="mbh-period-custom"<?php echo $period_key === 'custom' ? '' : ' hidden'; ?>>
            <label class="screen-reader-text" for="mbh-period-start"><?php esc_html_e('First day of the sales period', 'madebyhype-stockmanagment'); ?></label>
            <input type="date" id="mbh-period-start" name="start_date" value="<?php echo esc_attr($period_key === 'custom' ? $period['start_date'] : ''); ?>">
            <span aria-hidden="true"><?php echo esc_html($this->t('rangeTo')); ?></span>
            <label class="screen-reader-text" for="mbh-period-end"><?php esc_html_e('Last day of the sales period', 'madebyhype-stockmanagment'); ?></label>
            <input type="date" id="mbh-period-end" name="end_date" value="<?php echo esc_attr($period_key === 'custom' ? $period['end_date'] : ''); ?>">
            <button type="submit" class="button" id="mbh-period-apply"><?php esc_html_e('Apply', 'madebyhype-stockmanagment'); ?></button>
        </span>
    </span>
</form>
