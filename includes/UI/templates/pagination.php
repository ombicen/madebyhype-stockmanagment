<?php

/**
 * Footer of the table's card: rows per page on the left, paging on the right
 *
 * The rows-per-page select belongs to the list form in the toolbar. The
 * page-number field is read by the script (Enter goes to that page); its
 * data-url holds the link with %d for the page.
 *
 * @var \MadeByHypeStockmanagment\UI\UIManager $this
 * @var array $request
 * @var array $result DataManager::get_list()
 */

use MadeByHypeStockmanagment\Admin\AdminPage;

if (! defined('ABSPATH')) {
    exit;
}

$current = (int) $result['current_page'];
$pages = (int) $result['total_pages'];

$page_link = function ($target, $class, $label, $symbol) use ($current, $pages) {
    if ($target < 1 || $target > $pages || $target === $current) {
        echo '<span class="button disabled ' . esc_attr($class) . '" aria-hidden="true">' . esc_html($symbol) . '</span>';
        return;
    }

    printf(
        '<a class="%1$s button" href="%2$s"><span class="screen-reader-text">%3$s</span><span aria-hidden="true">%4$s</span></a>',
        esc_attr($class),
        esc_url($this->url(['paged' => $target > 1 ? $target : null])),
        esc_html($label),
        esc_html($symbol)
    );
};
?>
<div class="mbh-foot">
    <label for="mbh-per-page">
        <?php esc_html_e('Rows per page', 'madebyhype-stockmanagment'); ?>
        <select id="mbh-per-page" form="mbh-list-form" name="per_page" data-mbh-submit data-current="<?php echo esc_attr($request['per_page']); ?>" data-default="<?php echo esc_attr(AdminPage::DEFAULT_PER_PAGE); ?>">
            <?php foreach (AdminPage::PER_PAGE_OPTIONS as $size): ?>
                <option value="<?php echo esc_attr($size); ?>"<?php selected($request['per_page'], $size); ?>><?php echo esc_html($size); ?></option>
            <?php endforeach; ?>
        </select>
    </label>

    <?php if ($pages > 1): ?>
        <nav class="mbh-pager" aria-label="<?php esc_attr_e('Pages', 'madebyhype-stockmanagment'); ?>">
            <?php
            $page_link(1, 'first-page', __('First page', 'madebyhype-stockmanagment'), '«');
            $page_link($current - 1, 'prev-page', __('Previous page', 'madebyhype-stockmanagment'), '‹');

            $field = sprintf(
                '<input class="mbh-page-field" id="mbh-page-field" type="text" inputmode="numeric" autocomplete="off" value="%1$d" aria-label="%2$s" data-page="%1$d" data-pages="%3$d" data-url="%4$s">',
                $current,
                esc_attr__('Page number', 'madebyhype-stockmanagment'),
                $pages,
                esc_url($this->url(['paged' => null]) . '&paged=%d')
            );

            printf(
                '<span class="mbh-paging-text">%s</span>',
                sprintf(
                    /* translators: 1: the field that holds the page number, 2: number of pages */
                    esc_html__('Page %1$s of %2$s', 'madebyhype-stockmanagment'),
                    $field,
                    '<span class="total-pages">' . esc_html(number_format_i18n($pages)) . '</span>'
                )
            );

            $page_link($current + 1, 'next-page', __('Next page', 'madebyhype-stockmanagment'), '›');
            $page_link($pages, 'last-page', __('Last page', 'madebyhype-stockmanagment'), '»');
            ?>
        </nav>
    <?php endif; ?>
</div>
