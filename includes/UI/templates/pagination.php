<?php

/**
 * Footer of the table's card: rows per page on the left, the keyboard help, paging on the right
 *
 * The script reads the rows-per-page select and the page-number field
 * (Enter goes to that page) and loads the list without leaving the page.
 * It redraws the pager from the same description (renderFrame() in
 * scripts/stock-grid.js).
 *
 * @var \MadeByHypeStockmanagment\UI\UIManager $this
 * @var array $list UIManager::list_frame()
 * @var array $caps
 */

if (! defined('ABSPATH')) {
    exit;
}

$pager = $list['pager'];

$page_link = function ($key, $icon, $label) use ($pager) {
    $flip = $key === 'prev' ? ' mbh-icon--flip' : '';

    if ($pager[$key] === null) {
        echo '<span class="mbh-page-link is-disabled" aria-hidden="true">' . self::icon($icon, ltrim($flip)) . '</span>';
        return;
    }

    printf(
        '<a class="mbh-page-link" href="%1$s" data-nav="page-%2$s" aria-label="%3$s">%4$s</a>',
        esc_url($pager[$key]),
        esc_attr($key),
        esc_attr($label),
        self::icon($icon, ltrim($flip))
    );
};

$keys = [
    [['keyEnter', 'keyDown'], 'keysDown'],
    [['keyShiftEnter', 'keyUp'], 'keysUp'],
    [['keyEscape'], 'keysEscape'],
    [['keyCtrlS'], 'keysSave'],
];
?>
<div class="mbh-foot" id="mbh-foot">
    <label class="mbh-per-page" for="mbh-per-page">
        <span><?php echo esc_html($this->t('rowsPerPage')); ?></span>
        <select id="mbh-per-page" class="mbh-select" name="per_page">
            <?php foreach ($list['perPageOptions'] as $size): ?>
                <option value="<?php echo esc_attr($size); ?>"<?php selected($list['perPage'], $size); ?>><?php echo esc_html($size); ?></option>
            <?php endforeach; ?>
        </select>
    </label>

    <?php if (!empty($caps['stock']) || !empty($caps['prices'])): ?>
        <details class="mbh-keys" id="mbh-keys">
            <summary><?php echo self::icon('keyboard'); ?><span><?php echo esc_html($this->t('keysTitle')); ?></span></summary>
            <dl class="mbh-keys-pop">
                <?php foreach ($keys as $entry): ?>
                    <div>
                        <dt>
                            <?php foreach ($entry[0] as $index => $key): ?>
                                <?php echo $index ? '<span class="mbh-key-or">' . esc_html($this->t('keyOr')) . '</span>' : ''; ?>
                                <kbd><?php echo esc_html($this->t($key)); ?></kbd>
                            <?php endforeach; ?>
                        </dt>
                        <dd><?php echo esc_html($this->t($entry[1])); ?></dd>
                    </div>
                <?php endforeach; ?>
            </dl>
        </details>
    <?php endif; ?>

    <nav class="mbh-pager" id="mbh-pager" aria-label="<?php echo esc_attr($this->t('pages')); ?>"<?php echo $list['pages'] > 1 ? '' : ' hidden'; ?>>
        <?php
        $page_link('first', 'first', $this->t('firstPage'));
        $page_link('prev', 'chevron', $this->t('previousPage'));

        $field = sprintf(
            '<input class="mbh-page-field" id="mbh-page-field" type="text" inputmode="numeric" autocomplete="off" value="%1$d" aria-label="%2$s">',
            $list['page'],
            esc_attr($this->t('pageNumber'))
        );

        printf(
            '<span class="mbh-paging-text">%s</span>',
            sprintf(
                esc_html($this->t('pageFieldOf', '%1$s', '%2$s')),
                $field,
                '<span class="mbh-total-pages">' . esc_html($pager['pagesLabel']) . '</span>'
            )
        );

        $page_link('next', 'chevron', $this->t('nextPage'));
        $page_link('last', 'last', $this->t('lastPage'));
        ?>
    </nav>
</div>
