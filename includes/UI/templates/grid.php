<?php

/**
 * The grid of All stock and Needs attention: kind links, chips and count, the table in its card with
 * rows per page and paging in the footer, and the empty state
 *
 * Everything here is printed from UIManager::list_frame(). After the script
 * has read another page of the list it redraws the same parts from the same
 * description (renderFrame() in scripts/stock-grid.js): the kinds, the chips,
 * the headings, the pager and the empty state. Change the two together;
 * MBHStock.grid.checkRender() also lists the parts on which they differ.
 *
 * The table and the empty state are both always in the page; the one that
 * does not apply is hidden.
 *
 * @var \MadeByHypeStockmanagment\UI\UIManager $this
 * @var array  $request
 * @var array  $result  DataManager::get_list()
 * @var array  $list    UIManager::list_frame()
 * @var array  $caps
 * @var string $tab
 * @var string $view
 */

if (! defined('ABSPATH')) {
    exit;
}

$strings = self::strings();
$rows = $result['rows'];
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
?>

<div class="mbh-meta" id="mbh-meta">
    <?php if ($list['kinds']): ?>
        <ul class="mbh-kinds" id="mbh-kinds" aria-label="<?php echo esc_attr($this->t('kindsLabel')); ?>">
            <?php foreach ($list['kinds'] as $kind): ?>
                <li><a href="<?php echo esc_url($kind['url']); ?>" data-nav="kind-<?php echo esc_attr($kind['key']); ?>"<?php echo $kind['current'] ? ' class="is-current" aria-current="page"' : ''; ?>><?php echo esc_html($kind['label']); ?> <span class="mbh-kind-count"><?php echo esc_html($kind['count']); ?></span></a></li>
            <?php endforeach; ?>
        </ul>
        <label class="mbh-switch mbh-sold-only">
            <input type="checkbox" role="switch" id="mbh-sold-only"<?php checked($list['soldOnly']); ?>>
            <span class="mbh-switch-track" aria-hidden="true"></span>
            <span><?php echo esc_html($this->t('soldOnly')); ?></span>
        </label>
    <?php endif; ?>

    <div class="mbh-chips" id="mbh-chips">
        <?php include __DIR__ . '/chips.php'; ?>
    </div>

    <div class="mbh-meta-end">
        <?php if ($tab === 'all'): ?>
            <button type="button" class="mbh-text-button" id="mbh-collapse-all" hidden><?php echo esc_html($this->t('collapseAll')); ?></button>
        <?php endif; ?>
        <span class="mbh-count" id="mbh-count"><?php echo esc_html($list['countLabel']); ?></span>
    </div>
</div>

<div class="mbh-card" id="mbh-card"<?php echo $list['empty'] ? ' hidden' : ''; ?>>
    <div class="mbh-progress" id="mbh-progress" hidden></div>
    <div id="mbh-grid-scroll" class="mbh-grid-scroll">
        <table id="mbh-grid" class="mbh-grid">
            <caption class="screen-reader-text" id="mbh-caption"><?php echo esc_html($list['caption']); ?></caption>
            <?php // The widths of the columns: the first row of the heading spans them, so it cannot say ?>
            <colgroup><col class="mbh-col-name"></colgroup>
            <colgroup><col class="mbh-col-stock"><col class="mbh-col-status"></colgroup>
            <colgroup><col class="mbh-col-regular"><col class="mbh-col-sale"></colgroup>
            <colgroup><col class="mbh-col-sold"><col class="mbh-col-cover"></colgroup>
            <thead>
                <?php // What the columns are about. The script redraws the row of headings below it, never this one. ?>
                <tr class="mbh-group-row">
                    <td class="mbh-col-name"></td>
                    <th scope="colgroup" colspan="2" class="mbh-group mbh-col-stock"><span><?php esc_html_e('Inventory', 'madebyhype-stockmanagment'); ?></span></th>
                    <th scope="colgroup" colspan="2" class="mbh-group mbh-col-regular"><span><?php esc_html_e('Pricing', 'madebyhype-stockmanagment'); ?></span></th>
                    <th scope="colgroup" colspan="2" class="mbh-group mbh-col-sold"><span><?php esc_html_e('Sales', 'madebyhype-stockmanagment'); ?></span></th>
                </tr>
                <tr id="mbh-head-row">
                    <?php foreach ($list['headings'] as $heading): ?>
                        <th scope="col" class="<?php echo esc_attr($heading['class'] . ($heading['sorted'] ? ' is-sorted' : '')); ?>"<?php echo $heading['ariaSort'] ? ' aria-sort="' . esc_attr($heading['ariaSort']) . '"' : ''; ?><?php echo $heading['help'] !== '' ? ' title="' . esc_attr($heading['help']) . '"' : ''; ?>>
                            <?php if ($heading['field'] !== null): ?>
                                <a href="<?php echo esc_url($heading['url']); ?>" data-nav="sort-<?php echo esc_attr($heading['field']); ?>">
                                    <span class="mbh-th-label"><?php echo esc_html($heading['label']); ?><?php if ($heading['sub'] !== ''): ?><span class="mbh-th-sub"><?php echo esc_html($heading['sub']); ?></span><?php endif; ?></span>
                                    <?php echo self::icon('arrow', 'mbh-sort-icon mbh-sort-icon--' . $heading['direction']); ?>
                                    <span class="screen-reader-text"><?php echo esc_html($heading['nextLabel']); ?></span>
                                </a>
                            <?php else: ?>
                                <span class="mbh-th-label"><?php echo esc_html($heading['label']); ?></span>
                            <?php endif; ?>
                        </th>
                    <?php endforeach; ?>
                </tr>
            </thead>
            <tbody id="mbh-rows">
                <?php foreach ($rows as $row): ?>
                    <?php include __DIR__ . '/row.php'; ?>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <?php include __DIR__ . '/pagination.php'; ?>
</div>

<div class="mbh-empty" id="mbh-empty"<?php echo $list['empty'] ? '' : ' hidden'; ?>>
    <?php foreach ($list['empty'] ? $list['empty'] : [] as $line): ?>
        <p>
            <span><?php echo esc_html($line['text']); ?></span>
            <?php if ($line['link']): ?>
                <a href="<?php echo esc_url($line['link']['url']); ?>"<?php echo $line['link']['inPage'] ? ' data-nav="empty"' : ''; ?>><?php echo esc_html($line['link']['label']); ?></a>
            <?php endif; ?>
        </p>
    <?php endforeach; ?>
</div>
