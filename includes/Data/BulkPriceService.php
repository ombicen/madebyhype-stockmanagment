<?php

namespace MadeByHypeStockmanagment\Data;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * A bulk price change: one rule applied to every priced item a list matches.
 *
 * Two steps, driven by the screen:
 * - preview() finds the items, works out what the rule does to each from the
 *   prices stored now, and writes nothing;
 * - apply() takes a slice of those items, works the rule out again from the
 *   prices stored at that moment, and hands the new prices to WriteService.
 *
 * So everything a save does holds here too: the change log comes first, a
 * price is only written over the value it was calculated from, a sale price
 * stays below the regular price, and the whole run is one save in History
 * that can be undone. The arithmetic is BulkPriceRule's.
 */
class BulkPriceService
{
    // Most items one apply() call takes
    const MAX_APPLY = 50;

    // Rows the preview shows, before the two largest changes are added
    const SAMPLE_ROWS = 10;

    // A price that moves by more than this share is called out in the preview
    const LARGE_CHANGE = 0.5;

    private $data_manager;
    private $write_service;

    /**
     * @param DataManager  $data_manager
     * @param WriteService $write_service
     */
    public function __construct($data_manager, $write_service)
    {
        $this->data_manager = $data_manager;
        $this->write_service = $write_service;
    }

    /**
     * What a rule would do to everything a list matches
     *
     * @param array $list_args As DataManager::get_list()
     * @param mixed $raw_rule  See BulkPriceRule::normalise()
     * @return array|\WP_Error [
     *     'rule'     => the rule in its one form,
     *     'sentence' => the rule in words, as History will show it,
     *     'targets'  => priced items the list matches, 'products' => products they belong to,
     *     'change'   => items the rule changes, 'ids' => their ids, in list order,
     *     'skip'     => BulkPriceRule skip code => items,
     *     'up', 'down' => items whose price rises or falls, 'large' => items that move by more than half,
     *     'sample'   => rows, see sample_row(): the first changes, then the largest fall and the largest rise,
     * ]
     * WP_Error codes: invalid_rule, db_error, too_many.
     */
    public function preview($list_args, $raw_rule)
    {
        $rule = $this->rule($raw_rule);
        if (is_wp_error($rule)) {
            return $rule;
        }

        $targets = $this->data_manager->price_target_ids($list_args);
        if ($targets['error']) {
            return new \WP_Error('db_error', $targets['error']['message']);
        }

        $max = (int) apply_filters('madebyhype_stock_bulk_price_max_items', \MadeByHypeStockmanagment\Settings::bulk_max_items());
        if (count($targets['ids']) > $max) {
            return new \WP_Error('too_many', sprintf(
                /* translators: 1: number of items matched, 2: most items one bulk change takes */
                __('This would change %1$s items; one bulk change takes at most %2$s. Narrow the list with the search or the filters.', 'madebyhype-stockmanagment'),
                number_format_i18n(count($targets['ids'])),
                number_format_i18n($max)
            ));
        }

        $prices = $this->stored_prices($targets['ids']);

        $result = [
            'rule' => $rule,
            'sentence' => $this->describe($rule),
            'targets' => count($targets['ids']),
            'products' => $targets['products'],
            'change' => 0,
            'ids' => [],
            'skip' => [],
            'up' => 0,
            'down' => 0,
            'large' => 0,
            'sample' => [],
        ];

        $first = [];
        $lowest = null;
        $highest = null;

        foreach ($targets['ids'] as $id) {
            $now = isset($prices[$id]) ? $prices[$id] : ['regular' => '', 'sale' => ''];
            $then = BulkPriceRule::apply($rule, $now['regular'], $now['sale']);

            if (isset($then['skip'])) {
                $result['skip'][$then['skip']] = (isset($result['skip'][$then['skip']]) ? $result['skip'][$then['skip']] : 0) + 1;
                continue;
            }

            $result['change']++;
            $result['ids'][] = $id;

            if (count($first) < self::SAMPLE_ROWS) {
                $first[$id] = [$now, $then];
            }

            $move = $this->movement($now, $then);
            if ($move === null) {
                continue;
            }

            $result[$move > 0 ? 'up' : 'down']++;
            if (abs($move) > self::LARGE_CHANGE) {
                $result['large']++;
            }

            if ($move < 0 && ($lowest === null || $move < $lowest[0])) {
                $lowest = [$move, $id, $now, $then];
            }
            if ($move > 0 && ($highest === null || $move > $highest[0])) {
                $highest = [$move, $id, $now, $then];
            }
        }

        // The two extremes are what a wrong rule shows up in first
        foreach ([$lowest, $highest] as $extreme) {
            if ($extreme && !isset($first[$extreme[1]])) {
                $first[$extreme[1]] = [$extreme[2], $extreme[3]];
            }
        }

        foreach ($first as $id => $pair) {
            $result['sample'][] = $this->sample_row($id, $pair[0], $pair[1]);
        }

        return $result;
    }

    /**
     * Apply a rule to a slice of the items a preview named
     *
     * Each new price is worked out from what the item holds now, not from
     * what the preview saw. The requests of one bulk change share a save
     * token, so they are one save, and a request sent twice changes nothing
     * the second time.
     *
     * @param array  $ids        Product and variation ids, at most MAX_APPLY
     * @param mixed  $raw_rule
     * @param string $save_token See WriteService::save()
     * @return array|\WP_Error [
     *     'batch_id'  => int|null, the save,
     *     'processed' => items handled, 'changed' => items whose price was changed,
     *     'skip'      => BulkPriceRule skip code => items the rule left alone this time,
     *     'failed'    => list of ['id', 'name', 'sku', 'message'] for items the write refused or could not make,
     * ]
     * WP_Error codes: invalid_rule, invalid_request, and those of WriteService::save().
     */
    public function apply($ids, $raw_rule, $save_token)
    {
        $rule = $this->rule($raw_rule);
        if (is_wp_error($rule)) {
            return $rule;
        }

        $ids = array_values(array_unique(array_filter(array_map('absint', array_filter((array) $ids, 'is_scalar')))));
        if (empty($ids) || count($ids) > self::MAX_APPLY || !is_string($save_token) || $save_token === '') {
            return new \WP_Error('invalid_request', __('The bulk price change could not be read.', 'madebyhype-stockmanagment'));
        }

        $result = ['batch_id' => null, 'processed' => count($ids), 'changed' => 0, 'skip' => [], 'failed' => []];
        $items = [];
        $labels = [];

        foreach ($ids as $id) {
            $item = \wc_get_product($id);

            if (!$item) {
                $result['failed'][] = ['id' => $id, 'name' => null, 'sku' => '', 'message' => __('This product no longer exists.', 'madebyhype-stockmanagment')];
                continue;
            }

            $regular = $item->get_regular_price('edit');
            $sale = $item->get_sale_price('edit');
            $then = BulkPriceRule::apply($rule, $regular, $sale);

            if (isset($then['skip'])) {
                $result['skip'][$then['skip']] = (isset($result['skip'][$then['skip']]) ? $result['skip'][$then['skip']] : 0) + 1;
                continue;
            }

            $labels[$id] = ['name' => $this->item_name($item), 'sku' => (string) $item->get_sku()];

            // With the price it was calculated from: someone else's change in between is a conflict, not overwritten
            if ($then['regular'] !== null) {
                $items[$id]['regular_price'] = ['value' => $then['regular'], 'seen' => (string) $regular];
            }
            if ($then['sale'] !== null) {
                $items[$id]['sale_price'] = ['value' => $then['sale'], 'seen' => (string) $sale];
            }
        }

        if (empty($items)) {
            return $result;
        }

        $saved = $this->write_service->save(
            ['items' => $items, 'save_token' => $save_token, 'note' => $this->describe($rule)],
            ChangeLog::SOURCE_BULK_PRICE
        );

        if (is_wp_error($saved)) {
            return $saved;
        }

        $result['batch_id'] = $saved['batch_id'];

        foreach ($saved['results'] as $item) {
            // A resend finds its own earlier write: that item was changed by this bulk change
            if ($item['success'] && in_array($item['code'], ['saved', 'saved_with_error', 'already_saved'], true)) {
                $result['changed']++;
                continue;
            }

            if ($item['success']) {
                $result['skip'][BulkPriceRule::SKIP_UNCHANGED] = (isset($result['skip'][BulkPriceRule::SKIP_UNCHANGED]) ? $result['skip'][BulkPriceRule::SKIP_UNCHANGED] : 0) + 1;
                continue;
            }

            // One of two prices may have been stored; the item still needs a look
            if ($item['code'] === 'partly_saved') {
                $result['changed']++;
            }

            $label = isset($labels[$item['id']]) ? $labels[$item['id']] : ['name' => null, 'sku' => ''];
            $result['failed'][] = ['id' => (int) $item['id'], 'name' => $label['name'], 'sku' => $label['sku'], 'message' => $item['message']];
        }

        return $result;
    }

    /**
     * The rule in words, for the preview and for History
     *
     * @param array $rule From BulkPriceRule::normalise()
     * @return string
     */
    public function describe($rule)
    {
        $amount = WriteService::display_value('regular_price', $rule['value']);
        $percent = str_replace('.', \wc_get_price_decimal_separator(), (string) ($rule['value'] + 0));

        switch ($rule['change']) {
            case BulkPriceRule::CHANGE_CLEAR_SALE:
                return __('Sale prices removed', 'madebyhype-stockmanagment');

            case BulkPriceRule::CHANGE_SALE_FROM_REGULAR:
                $sentence = sprintf(
                    /* translators: %s: a percentage, without the sign */
                    __('On sale at %s%% off the regular price', 'madebyhype-stockmanagment'),
                    $percent
                );

                if ($rule['skip_on_sale']) {
                    $sentence .= __(', items already on sale left alone', 'madebyhype-stockmanagment');
                }
                break;

            default:
                $what = [
                    BulkPriceRule::CHANGE_REGULAR => __('Regular price', 'madebyhype-stockmanagment'),
                    BulkPriceRule::CHANGE_SALE => __('Sale price', 'madebyhype-stockmanagment'),
                    BulkPriceRule::CHANGE_BOTH => __('Regular and sale price', 'madebyhype-stockmanagment'),
                ][$rule['change']];

                $how = [
                    /* translators: 1: which price, 2: a percentage, without the sign */
                    'increase_percent' => __('%1$s increased by %2$s%%', 'madebyhype-stockmanagment'),
                    /* translators: 1: which price, 2: a percentage, without the sign */
                    'decrease_percent' => __('%1$s decreased by %2$s%%', 'madebyhype-stockmanagment'),
                    /* translators: 1: which price, 2: an amount */
                    'increase_amount' => __('%1$s increased by %2$s', 'madebyhype-stockmanagment'),
                    /* translators: 1: which price, 2: an amount */
                    'decrease_amount' => __('%1$s decreased by %2$s', 'madebyhype-stockmanagment'),
                    /* translators: 1: which price, 2: an amount */
                    'set' => __('%1$s set to %2$s', 'madebyhype-stockmanagment'),
                ][$rule['method']];

                $sentence = sprintf($how, $what, strpos($rule['method'], 'percent') !== false ? $percent : $amount);
        }

        $rounding = [
            'whole' => __(', rounded to a whole number', 'madebyhype-stockmanagment'),
            'ten' => __(', rounded to the nearest 10', 'madebyhype-stockmanagment'),
            'nine' => __(', rounded to end in 9', 'madebyhype-stockmanagment'),
            /* translators: %s: the decimal separator */
            'ninety_nine' => sprintf(__(', rounded to end in %s99', 'madebyhype-stockmanagment'), \wc_get_price_decimal_separator()),
        ];

        return $sentence . (isset($rounding[$rule['rounding']]) ? $rounding[$rule['rounding']] : '');
    }

    /**
     * @param mixed $raw_rule
     * @return array|\WP_Error The rule, or invalid_rule with a sentence for the field that is wrong
     */
    private function rule($raw_rule)
    {
        $rule = BulkPriceRule::normalise($raw_rule, \wc_get_price_decimals(), \wc_get_price_decimal_separator());

        if (!isset($rule['error'])) {
            return $rule;
        }

        $messages = [
            'value' => __('Enter a number above 0.', 'madebyhype-stockmanagment'),
            'value_percent' => __('Enter a percentage that leaves a price: below 100 to take off, at most 1000 to add.', 'madebyhype-stockmanagment'),
        ];

        return new \WP_Error(
            'invalid_rule',
            isset($messages[$rule['error']]) ? $messages[$rule['error']] : __('The bulk price change could not be read.', 'madebyhype-stockmanagment'),
            ['field' => $rule['error']]
        );
    }

    /**
     * Regular and sale price of many items as stored, read without loading the products
     *
     * @param int[] $ids
     * @return array id => ['regular' => string, 'sale' => string]; '' for a price that is not set
     */
    private function stored_prices($ids)
    {
        global $wpdb;

        $prices = [];

        foreach (array_chunk($ids, 2000) as $chunk) {
            $in = implode(',', array_map('intval', $chunk));

            $rows = $wpdb->get_results(
                "SELECT post_id, meta_key, meta_value FROM {$wpdb->postmeta} WHERE post_id IN ({$in}) AND meta_key IN ('_regular_price', '_sale_price')"
            );

            foreach ((array) $rows as $row) {
                $id = (int) $row->post_id;

                if (!isset($prices[$id])) {
                    $prices[$id] = ['regular' => '', 'sale' => ''];
                }

                $prices[$id][$row->meta_key === '_sale_price' ? 'sale' : 'regular'] = (string) $row->meta_value;
            }
        }

        return $prices;
    }

    /**
     * How far the rule moves an item's price, as a share of what it was:
     * the regular price when that changes, else the sale price
     *
     * @return float|null Null when there is no earlier price to compare with, or the sale simply ends
     */
    private function movement($now, $then)
    {
        $field = $then['regular'] !== null ? 'regular' : 'sale';

        if ($then[$field] === '' || !is_numeric($now[$field]) || (float) $now[$field] <= 0) {
            return null;
        }

        return ((float) $then[$field] - (float) $now[$field]) / (float) $now[$field];
    }

    /**
     * The name that tells one item from the next: a variation is its product
     * and what it is a variation in ("Ring - Size: 7, Metal: Rose gold")
     *
     * @param \WC_Product $item
     * @return string
     */
    private function item_name($item)
    {
        $name = $item->get_name();

        if ($item->is_type('variation')) {
            $attributes = \wc_get_formatted_variation($item, true, true, false);

            if ($attributes !== '') {
                $name .= ' - ' . $attributes;
            }
        }

        return wp_strip_all_tags($name);
    }

    /**
     * One row of the preview's sample
     *
     * @return array ['id', 'name', 'sku', 'regular' => ['old', 'new' (as the screen shows them; new is null when
     *               it stays), 'direction' => 'up' | 'down' | ''], 'sale' => the same]
     */
    private function sample_row($id, $now, $then)
    {
        $item = \wc_get_product($id);
        $row = [
            'id' => (int) $id,
            'name' => $item ? $this->item_name($item) : null,
            'sku' => $item ? (string) $item->get_sku() : '',
        ];

        foreach (['regular' => 'regular_price', 'sale' => 'sale_price'] as $key => $field) {
            $old = is_numeric($now[$key]) ? $now[$key] : '';
            $new = $then[$key];
            $direction = '';

            if ($new !== null && $new !== '' && $old !== '') {
                $direction = (float) $new > (float) $old ? 'up' : 'down';
            }

            $row[$key] = [
                'old' => WriteService::display_value($field, $old),
                'new' => $new === null ? null : WriteService::display_value($field, $new),
                'direction' => $direction,
            ];
        }

        return $row;
    }
}
