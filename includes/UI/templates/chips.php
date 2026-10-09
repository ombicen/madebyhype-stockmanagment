<?php

/**
 * Active search and filters, each removable, and Clear all
 *
 * Clear all is also offered when only the sort or the view differs from the
 * default, because it resets those too.
 *
 * @var \MadeByHypeStockmanagment\UI\UIManager $this
 * @var array  $request
 * @var string $tab
 * @var string $view
 */

if (! defined('ABSPATH')) {
    exit;
}

$chips = $this->chips();
$sorted_or_switched = $request['sort_by'] !== ''
    || ($tab === 'all' && $view !== 'product')
    || ($tab === 'attention' && $request['attention'] !== 'all');

if (!$chips && !$sorted_or_switched) {
    return;
}
?>
<div class="mbh-chips">
    <?php if ($chips): ?>
        <ul class="mbh-chip-list" aria-label="<?php esc_attr_e('Active search and filters', 'madebyhype-stockmanagment'); ?>">
            <?php foreach ($chips as $chip): ?>
                <li class="mbh-chip">
                    <span class="mbh-chip-label"><?php echo esc_html($chip['label']); ?></span>
                    <a href="<?php echo esc_url($chip['url']); ?>" class="mbh-chip-remove">
                        <span aria-hidden="true">&times;</span>
                        <span class="screen-reader-text">
                            <?php
                            /* translators: %s: the filter, for example "Category: Rings" */
                            echo esc_html(sprintf(__('Remove %s', 'madebyhype-stockmanagment'), $chip['label']));
                            ?>
                        </span>
                    </a>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
    <a href="<?php echo esc_url($this->clear_all_url()); ?>" class="mbh-clear-all"><?php esc_html_e('Clear all', 'madebyhype-stockmanagment'); ?></a>
</div>
