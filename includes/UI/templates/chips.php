<?php

/**
 * Active search and filters, each removable, and Clear all
 *
 * Clear all is also offered when only the sort or the view differs from the
 * default, because it resets those too. Prints nothing when there is
 * nothing to clear. renderFrame() in scripts/stock-grid.js builds the same.
 *
 * @var \MadeByHypeStockmanagment\UI\UIManager $this
 * @var array $list UIManager::list_frame()
 */

if (! defined('ABSPATH')) {
    exit;
}
?>
<?php if ($list['chips']): ?>
    <ul class="mbh-chip-list" aria-label="<?php echo esc_attr($this->t('chipsLabel')); ?>">
        <?php foreach ($list['chips'] as $chip): ?>
            <li class="mbh-chip<?php echo $chip['exclude'] ? ' mbh-chip--not' : ''; ?>">
                <span class="mbh-chip-label"><?php echo esc_html($chip['label']); ?></span>
                <a href="<?php echo esc_url($chip['url']); ?>" class="mbh-chip-remove" data-nav="chip" aria-label="<?php echo esc_attr($chip['removeLabel']); ?>"><?php echo self::icon('close'); ?></a>
            </li>
        <?php endforeach; ?>
    </ul>
<?php endif; ?>
<?php if ($list['clearAllUrl']): ?>
    <a href="<?php echo esc_url($list['clearAllUrl']); ?>" class="mbh-clear-all" data-nav="clear"><?php echo esc_html($this->t('clearAll')); ?></a>
<?php endif; ?>
