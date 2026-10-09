<?php

/**
 * Result count and paging, in wp-admin's list-table markup
 *
 * @var \MadeByHypeStockmanagment\UI\UIManager $this
 * @var array  $result      DataManager::get_list()
 * @var string $count_label "3,923 products" or "42 SKUs"
 * @var string $position    top or bottom
 */

if (! defined('ABSPATH')) {
    exit;
}

$current = (int) $result['current_page'];
$pages = (int) $result['total_pages'];

$page_link = function ($target, $class, $label, $symbol) use ($current, $pages) {
    if ($target < 1 || $target > $pages || $target === $current) {
        echo '<span class="tablenav-pages-navspan button disabled" aria-hidden="true">' . esc_html($symbol) . '</span> ';
        return;
    }

    printf(
        '<a class="%1$s button" href="%2$s"><span class="screen-reader-text">%3$s</span><span aria-hidden="true">%4$s</span></a> ',
        esc_attr($class),
        esc_url($this->url(['paged' => $target > 1 ? $target : null])),
        esc_html($label),
        esc_html($symbol)
    );
};
?>
<div class="tablenav <?php echo esc_attr($position); ?>">
    <div class="tablenav-pages<?php echo $pages <= 1 ? ' one-page' : ''; ?>">
        <span class="displaying-num"><?php echo esc_html($count_label); ?></span>
        <?php if ($pages > 1): ?>
            <span class="pagination-links">
                <?php
                $page_link(1, 'first-page', __('First page', 'madebyhype-stockmanagment'), '«');
                $page_link($current - 1, 'prev-page', __('Previous page', 'madebyhype-stockmanagment'), '‹');
                ?>
                <span class="tablenav-paging-text">
                    <?php
                    /* translators: 1: current page, 2: number of pages */
                    echo esc_html(sprintf(__('%1$s of %2$s', 'madebyhype-stockmanagment'), number_format_i18n($current), number_format_i18n($pages)));
                    ?>
                </span>
                <?php
                $page_link($current + 1, 'next-page', __('Next page', 'madebyhype-stockmanagment'), '›');
                $page_link($pages, 'last-page', __('Last page', 'madebyhype-stockmanagment'), '»');
                ?>
            </span>
        <?php endif; ?>
    </div>
    <br class="clear">
</div>
