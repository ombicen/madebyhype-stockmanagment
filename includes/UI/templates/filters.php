<?php

/**
 * Filters of the grid tabs: the frame of a drawer the Filters button opens over the page
 *
 * Only the frame is printed. What can be chosen (categories, tags,
 * attributes) is read when the drawer is first opened, through the
 * madebyhype_get_filter_options action, and the sections are built by
 * scripts/stock-filters.js from that and from what is selected now
 * (the "filters" of UIManager::list_frame(), in the page data).
 *
 * @var \MadeByHypeStockmanagment\UI\UIManager $this
 * @var string $tab
 */

if (! defined('ABSPATH')) {
    exit;
}
?>
<aside id="mbh-filters" class="mbh-drawer" role="dialog" aria-modal="true" aria-labelledby="mbh-filters-title" tabindex="-1" hidden>
    <header class="mbh-drawer-head">
        <h2 class="mbh-drawer-title" id="mbh-filters-title"><?php esc_html_e('Filters', 'madebyhype-stockmanagment'); ?></h2>
        <span class="mbh-drawer-count" id="mbh-filters-active" hidden></span>
        <button type="button" class="mbh-icon-button" id="mbh-filters-close" aria-label="<?php esc_attr_e('Close filters', 'madebyhype-stockmanagment'); ?>"><?php echo self::icon('close'); ?></button>
    </header>

    <div class="mbh-drawer-body" id="mbh-filters-body"></div>

    <footer class="mbh-drawer-foot">
        <button type="button" class="button" id="mbh-filters-clear"><?php echo esc_html($this->t('clearAll')); ?></button>
        <button type="button" class="button button-primary" id="mbh-filters-apply"><?php echo esc_html($this->t('showResults')); ?></button>
    </footer>
</aside>
