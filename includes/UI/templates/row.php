<?php

/**
 * One row of the grid, as the server last read it: no edits, no save marks
 *
 * buildRow() in scripts/stock-grid.js builds the same markup for rows that
 * arrive later and for every redraw. Change the two together;
 * MBHStock.grid.checkRender() in the browser console lists rows on which
 * they differ.
 *
 * Which cells are inputs comes from UIManager::cell_kinds() only.
 *
 * The row-action links are printed out of the tab order (tabindex -1), so Tab
 * goes from cell to cell. The script puts the links of the row that holds
 * the focus back into it; they are one Shift+Tab before that row's first cell.
 *
 * @var \MadeByHypeStockmanagment\UI\UIManager $this
 * @var array    $row      A DataManager::get_list() row
 * @var array    $caps
 * @var string   $view     product or sku
 * @var array    $strings  UIManager::strings()
 * @var string   $edit_url    Link to the product editor with %d for the id
 * @var string   $history_url Link to History with %d for the id
 * @var callable $none  Prints the dash of an empty cell
 * @var callable $value Prints a read-only value
 * @var callable $sub   Prints a line of small print under a value
 */

if (! defined('ABSPATH')) {
    exit;
}

$kinds = self::cell_kinds($row, $caps);
$summary = $row['variation_summary'];
$sku = $row['sku'] !== '' ? $row['sku'] : $row['parent_sku'];
$label = $sku !== '' ? $this->t('itemWithSku', $row['full_name'], $sku) : $row['full_name'];
$expandable = $view === 'product' && $row['variation_count'] > 0;

if ($row['parent_id'] && $row['attribute_summary'] !== '') {
    $name = $this->t('nameWithAttributes', $row['name'], $row['attribute_summary']);
} else {
    $name = $row['parent_id'] ? $row['full_name'] : $row['name'];
}

$product_status = $row['parent_id'] ? $row['parent_status'] : $row['post_status'];
$type_label = isset($strings['types'][$row['type']]) ? $strings['types'][$row['type']] : $row['type'];

$input = function ($field, $kind, $shown, $placeholder = '') use ($label, $strings) {
    printf(
        '<input type="text" class="mbh-input mbh-input--%1$s" inputmode="%2$s" autocomplete="off" data-field="%3$s" value="%4$s"%5$s aria-label="%6$s">',
        esc_attr($kind),
        $kind === 'qty' ? 'numeric' : 'decimal',
        esc_attr($field),
        esc_attr($shown),
        $placeholder !== '' ? ' placeholder="' . esc_attr($placeholder) . '"' : '',
        esc_attr($this->t('cellLabel', $strings['fields'][$field], $label))
    );
};

$badge = function ($key, $title = '') use ($strings) {
    printf(
        '<span class="mbh-status mbh-status--%1$s"%3$s>%2$s</span>',
        esc_attr($key),
        esc_html(isset($strings['statuses'][$key]) ? $strings['statuses'][$key] : $key),
        $title !== '' ? ' title="' . esc_attr($title) . '"' : ''
    );
};
?>
<tr id="mbh-row-<?php echo esc_attr($row['id']); ?>" class="mbh-row<?php echo $expandable ? ' mbh-row--parent' : ''; ?>" data-id="<?php echo esc_attr($row['id']); ?>">
    <td class="mbh-col-name">
        <div class="mbh-prod">
            <?php if ($expandable): ?>
                <button type="button" class="mbh-expand" aria-expanded="false" aria-label="<?php echo esc_attr($this->t('variationsOf', $row['full_name'])); ?>"><?php echo self::icon('chevron'); ?></button>
            <?php else: ?>
                <span class="mbh-expand-space" aria-hidden="true"></span>
            <?php endif; ?>
            <?php if ($row['thumbnail_url'] !== ''): ?>
                <img class="mbh-thumb" src="<?php echo esc_url($row['thumbnail_url']); ?>" alt="" width="36" height="36" loading="lazy" decoding="async">
            <?php else: ?>
                <span class="mbh-thumb mbh-thumb--none" aria-hidden="true"></span>
            <?php endif; ?>
            <div class="mbh-prod-text">
                <strong class="mbh-name" title="<?php echo esc_attr($name); ?>"><?php echo esc_html($name); ?></strong>
                <div class="mbh-sub-line">
                    <?php if ($sku !== ''): ?>
                        <span class="mbh-sku"><?php echo esc_html($sku); ?></span>
                    <?php endif; ?>
                    <span class="mbh-id"><?php echo esc_html($this->t('idNumber', $row['id'])); ?></span>
                    <span class="mbh-type"<?php echo $row['variation_count'] > 0 ? ' title="' . esc_attr($this->tn('variationCount', $row['variation_count'])) . '"' : ''; ?>><?php echo esc_html($row['variation_count'] > 0 ? $this->t('typeWithCount', $type_label, $row['variation_count']) : $type_label); ?></span>
                    <?php if ($product_status && $product_status !== 'publish'): ?>
                        <span class="mbh-tag mbh-tag--draft"><?php echo esc_html(isset($strings['postStatuses'][$product_status]) ? $strings['postStatuses'][$product_status] : $product_status); ?></span>
                    <?php endif; ?>
                    <?php if ($row['parent_id'] && $row['post_status'] === 'private'): ?>
                        <span class="mbh-tag mbh-tag--draft"><?php echo esc_html($this->t('variationDisabled')); ?></span>
                    <?php endif; ?>
                    <span class="mbh-row-links">
                        <?php if (!empty($caps['editProducts'])): ?>
                            <a class="mbh-link-edit" href="<?php echo esc_url(str_replace('%d', (string) ($row['parent_id'] ? $row['parent_id'] : $row['id']), $edit_url)); ?>" target="_blank" rel="noopener" tabindex="-1" aria-label="<?php echo esc_attr($this->t('editProductOf', $row['full_name'])); ?>"><?php echo esc_html($this->t('editProduct')); ?></a>
                        <?php endif; ?>
                        <a class="mbh-link-history" href="<?php echo esc_url(str_replace('%d', (string) $row['id'], $history_url)); ?>" tabindex="-1" aria-label="<?php echo esc_attr($this->t('historyOf', $row['full_name'])); ?>"><?php echo esc_html($this->t('history')); ?></a>
                    </span>
                </div>
            </div>
        </div>
    </td>
    <td class="mbh-col-stock mbh-num" data-field="stock_quantity">
        <?php
        switch ($kinds['stock']) {
            case 'input':
                $input('stock_quantity', 'qty', $row['stock_quantity'] === null ? '' : (string) $row['stock_quantity']);
                break;

            case 'text':
                $row['stock_quantity'] === null ? $none() : $value((string) $row['stock_quantity']);
                break;

            case 'inherits':
                $row['holder_stock_quantity'] === null ? $none() : $value((string) $row['holder_stock_quantity']);
                $sub($this->t('usesProductStock'));
                break;

            case 'total':
                // One value and one line under it, so the row is as high as every other
                if ($summary['stock_total'] === null) {
                    echo '<span class="mbh-note">' . esc_html($this->t('notTracked')) . '</span>';
                    $sub($this->tn('variationCount', $summary['count']));
                } else {
                    $value((string) $summary['stock_total']);
                    if ($summary['own_stock_count'] > 0 && $summary['untracked_count'] > 0) {
                        $sub($this->t('stockParts', $this->tn('inVariations', $summary['own_stock_count']), $this->tn('notTrackedCount', $summary['untracked_count'])));
                    } elseif ($summary['own_stock_count'] > 0) {
                        $sub($this->tn('inVariations', $summary['own_stock_count']));
                    } elseif ($summary['untracked_count'] > 0) {
                        $sub($this->tn('notTrackedCount', $summary['untracked_count']));
                    }
                }
                break;

            case 'untracked':
                echo '<span class="mbh-note">' . esc_html($this->t('notTracked')) . '</span>';
                if ($kinds['start']) {
                    printf(
                        '<button type="button" class="mbh-text-button mbh-start" aria-label="%1$s">%2$s</button>',
                        esc_attr($this->t('cellLabel', $this->t('startTracking'), $label)),
                        esc_html($this->t('startTracking'))
                    );
                }
                break;

            default:
                $none();
        }
        ?>
    </td>
    <td class="mbh-col-status" data-field="stock_status">
        <?php
        switch ($kinds['status']) {
            case 'select':
                printf(
                    '<select class="mbh-input mbh-input--status" data-field="stock_status" aria-label="%s">',
                    esc_attr($this->t('cellLabel', $strings['fields']['stock_status'], $label))
                );
                foreach (['instock', 'outofstock', 'onbackorder'] as $status) {
                    printf(
                        '<option value="%1$s"%2$s>%3$s</option>',
                        esc_attr($status),
                        $row['stock_status'] === $status ? ' selected' : '',
                        esc_html($strings['statuses'][$status])
                    );
                }
                echo '</select>';
                break;

            case 'text':
                $badge($row['stock_status']);
                break;

            case 'badge':
                // The threshold a low item is measured against is in the badge's tooltip
                $key = $row['attention'] === 'low' ? 'lowstock' : $row['stock_status'];
                $badge($key, $key === 'lowstock' && $row['low_stock_threshold'] !== null ? $this->t('thresholdTitle', $row['low_stock_threshold']) : '');
                break;

            case 'summary':
                $badge($row['stock_status']);
                if ($summary['out_of_stock_count'] > 0 && $summary['out_of_stock_count'] < $summary['count']) {
                    $sub($this->t('outOf', $summary['out_of_stock_count'], $summary['count']));
                }
                break;

            default:
                $none();
        }
        ?>
    </td>
    <?php foreach (['regular_price' => 'regular', 'sale_price' => 'sale'] as $field => $column): ?>
        <td class="mbh-col-<?php echo esc_attr($column); ?> mbh-num" data-field="<?php echo esc_attr($field); ?>">
            <?php
            $stored = (string) $row[$field];

            switch ($kinds[$column]) {
                case 'input':
                    $input($field, 'price', self::format_price($stored, false), $field === 'sale_price' ? $this->t('noSale') : '');

                    if ($field === 'sale_price' && $stored !== '') {
                        if ($row['sale_from'] && $row['sale_to']) {
                            $sub($this->t('scheduled', $row['sale_from'], $row['sale_to']));
                        } elseif ($row['sale_from']) {
                            $sub($this->t('scheduledFrom', $row['sale_from']));
                        } elseif ($row['sale_to']) {
                            $sub($this->t('scheduledUntil', $row['sale_to']));
                        }
                    }
                    break;

                case 'text':
                    $stored === '' ? $none() : $value(self::format_price($stored, true));
                    break;

                case 'range':
                    if ($summary['regular_price_min'] === null) {
                        $none();
                    } elseif ((float) $summary['regular_price_min'] === (float) $summary['regular_price_max']) {
                        $value(self::format_price($summary['regular_price_min'], true));
                    } else {
                        $value($this->t('range', self::format_price($summary['regular_price_min'], true), self::format_price($summary['regular_price_max'], true)));
                    }
                    break;

                case 'summary':
                    if ($summary['on_sale_count'] > 0) {
                        echo '<span class="mbh-note">' . esc_html($this->t('onSaleOf', $summary['on_sale_count'], $summary['count'])) . '</span>';
                    } else {
                        $none();
                    }
                    break;

                default:
                    $none();
            }
            ?>
        </td>
    <?php endforeach; ?>
    <td class="mbh-col-sold mbh-num"><?php echo esc_html($row['units_sold']); ?></td>
    <td class="mbh-col-cover mbh-num"><?php $row['cover_days'] === null ? $none() : print(esc_html($this->tn('coverDays', $row['cover_days']))); ?></td>
</tr>
