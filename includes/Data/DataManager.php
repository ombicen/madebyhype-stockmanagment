<?php

namespace MadeByHypeStockmanagment\Data;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Read path of the stock screen: which rows a list holds, in which order,
 * and what each row says about its stock and prices.
 *
 * Selection, filtering, sorting and counting run on WooCommerce's product
 * lookup table (wc_product_meta_lookup, one indexed row per product and per
 * variation). The query returns one page of ids; everything shown for those
 * ids is then read from WooCommerce product objects in the 'edit' context,
 * the same context WriteService reports after a save. Nothing here is cached
 * and nothing here writes.
 *
 * Public entry points:
 *   get_list()        the list: All stock (by product or by SKU) and Needs attention
 *   get_variations()  the variations of one variable product, on demand
 *   get_products()    TRANSITION: the 1.0.6 call and row shape, for the old templates
 */
class DataManager
{
    const VIEW_PRODUCT = 'product';
    const VIEW_SKU = 'sku';

    const TAB_ALL = 'all';
    const TAB_ATTENTION = 'attention';

    const MAX_PER_PAGE = 500;
    const DEFAULT_PERIOD = '30';

    const SORT_FIELDS = ['name', 'sku', 'price', 'stock_quantity', 'total_sales', 'cover'];
    const STOCK_FILTERS = ['instock', 'outofstock', 'onbackorder', 'lowstock', 'untracked'];
    const ATTENTION_FILTERS = ['all', 'out', 'low', 'backorder'];
    const PERIODS = ['30', '90', '180', '365', 'all'];

    // Product types whose stock sits on variations, and types that hold no stock at all
    const VARIABLE_TYPES = ['variable', 'variable-subscription'];
    const NO_STOCK_TYPES = ['grouped', 'external'];

    // Post statuses listed besides 'publish' when drafts are included
    const DRAFT_STATUSES = ['draft', 'pending', 'private', 'future'];

    // A disabled variation is a private post; it still holds stock
    const VARIATION_STATUSES_SQL = "'publish','private'";

    // Orders that count as sold
    const PAID_STATUSES_SQL = "'wc-completed','wc-processing'";

    private $type_tt_ids = [];

    public function init()
    {
        // Nothing to register: the read path has no hooks
    }

    /* ---------------------------------------------------------------------
     * Public API
     * ------------------------------------------------------------------- */

    /**
     * One page of the list
     *
     * @param array $args {
     *     @type string       $tab              'all' (default) or 'attention'. Attention is always by SKU, published only.
     *     @type string|null  $view             'product' (default) or 'sku'. Null: 'sku' when include_variations is set.
     *     @type bool         $include_variations Legacy synonym for view=sku.
     *     @type string       $search           Matches product name, product SKU, variation SKU (contains) and id (exact).
     *     @type string       $sort_by          '' (default order), 'name', 'sku', 'price', 'stock_quantity', 'total_sales', 'cover'.
     *     @type string       $sort_order       'ASC' or 'DESC' (default 'DESC'; ignored when sort_by is '').
     *     @type int          $page             Default 1. Beyond the last page: the last page.
     *     @type int          $per_page         Default 50, 1 to MAX_PER_PAGE.
     *     @type int[]        $category_filter  product_cat term ids; descendants included.
     *     @type int[]        $tag_filter       product_tag term ids.
     *     @type array        $attribute_filter pa_taxonomy => term ids.
     *     @type string[]     $stock_filter     Any of STOCK_FILTERS; several are OR-ed.
     *     @type float        $min_price        0 = no bound. Current price (sale applied).
     *     @type float        $max_price        0 = no bound.
     *     @type int          $min_sales        0 = no bound. Units sold in the period.
     *     @type int          $max_sales        0 = no bound.
     *     @type bool         $sold_only        Only rows with at least one unit sold in the period.
     *     @type string       $attention        Needs attention only: 'all', 'out', 'low', 'backorder'.
     *     @type bool         $include_drafts   Also list draft, pending, private and scheduled products. Ignored on Needs attention.
     *     @type string       $period           '30', '90', '180', '365', 'all'. Wins over the dates.
     *     @type string       $start_date       Y-m-d, with end_date: a fixed period.
     *     @type string       $end_date         Y-m-d. The whole day is included.
     *     @type bool         $with_images      Default true: resolve thumbnail URLs.
     * }
     * @return array {
     *     rows, total_count, total_pages, current_page, per_page, view, tab, sort_by, sort_order,
     *     search, period (see resolve_period()), counts (Needs attention: all/out/low/backorder, else null),
     *     error (null, or ['code' => 'db_error', 'message' => string])
     * }
     */
    public function get_list($args = [])
    {
        $q = $this->normalise_list_args($args);

        $result = [
            'rows' => [],
            'total_count' => 0,
            'total_pages' => 0,
            'current_page' => 1,
            'per_page' => $q['per_page'],
            'view' => $q['view'],
            'tab' => $q['tab'],
            'sort_by' => $q['sort_by'],
            'sort_order' => $q['sort_order'],
            'search' => $q['search'],
            'period' => $q['period'],
            'counts' => null,
            'error' => null,
        ];

        if (!class_exists('WooCommerce')) {
            return $result;
        }

        try {
            if ($q['search'] !== '') {
                $q['exact_ids'] = $this->exact_sku_ids($q['search']);
            }

            $branches = $this->build_branches($q);

            // 1. How many rows match
            $counts = $this->count_rows($q, $branches);
            if ($q['tab'] === self::TAB_ATTENTION) {
                $result['counts'] = $counts;
            }
            $total = $q['tab'] === self::TAB_ATTENTION ? $counts[$q['attention']] : $counts['all'];

            $result['total_count'] = $total;
            $result['total_pages'] = (int) ceil($total / $q['per_page']);
            $result['current_page'] = max(1, min($q['page'], $result['total_pages']));

            if ($total === 0) {
                return $result;
            }

            // 2. The ids of one page, in order
            $ids = $this->page_ids($q, $branches, $result['current_page']);

            // 3. Everything shown, for those ids only
            $result['rows'] = $this->hydrate_rows($ids, $q['period'], [
                'images' => $q['with_images'],
                'search' => $q['search'],
            ]);
        } catch (\RuntimeException $e) {
            return $this->failed($result, $e);
        }

        return $result;
    }

    /**
     * The variations of one variable product, in the product's own order
     *
     * Every variation is returned, also the ones that use the parent's stock
     * (stock_mode 'parent'). Rows have the shape of get_list() rows.
     *
     * @param int   $parent_id
     * @param array $args period, start_date, end_date, with_images: as in get_list()
     * @return array {
     *     parent (row|null), variations (rows), period,
     *     error (null, or ['code' => 'not_found'|'not_variable'|'db_error', 'message' => string])
     * }
     */
    public function get_variations($parent_id, $args = [])
    {
        $args = wp_parse_args($args, ['period' => '', 'start_date' => '', 'end_date' => '', 'with_images' => true]);
        $parent_id = is_scalar($parent_id) ? absint($parent_id) : 0;

        $result = [
            'parent' => null,
            'variations' => [],
            'period' => $this->resolve_period($args['period'], $args['start_date'], $args['end_date']),
            'error' => null,
        ];

        if (!class_exists('WooCommerce')) {
            return $result;
        }

        $post = $parent_id ? get_post($parent_id) : null;
        if (!$post || $post->post_type !== 'product' || $post->post_status === 'trash') {
            $result['error'] = ['code' => 'not_found', 'message' => __('This product no longer exists.', 'madebyhype-stockmanagment')];
            return $result;
        }

        try {
            $opts = ['images' => !empty($args['with_images']), 'search' => ''];

            $parents = $this->hydrate_rows([$parent_id], $result['period'], $opts);
            if (!$parents) {
                $result['error'] = ['code' => 'not_found', 'message' => __('This product no longer exists.', 'madebyhype-stockmanagment')];
                return $result;
            }
            $result['parent'] = $parents[0];

            if (!in_array($parents[0]['type'], self::VARIABLE_TYPES, true)) {
                $result['error'] = ['code' => 'not_variable', 'message' => __('This product has no variations.', 'madebyhype-stockmanagment')];
                return $result;
            }

            $children = $this->child_ids([$parent_id]);
            if (!empty($children[$parent_id])) {
                $result['variations'] = $this->hydrate_rows($children[$parent_id], $result['period'], $opts);
            }
        } catch (\RuntimeException $e) {
            return $this->failed($result, $e);
        }

        return $result;
    }

    /**
     * The sales period a request means
     *
     * Days are whole days in the site's time zone, the last one included.
     * A rolling period of N days is the N days that end today.
     *
     * @param string $period     '30', '90', '180', '365', 'all', or '' to use the dates
     * @param string $start_date Y-m-d
     * @param string $end_date   Y-m-d
     * @return array {
     *     mode 'rolling'|'fixed'|'all', key '30'|'90'|'180'|'365'|'all'|'custom',
     *     start_date Y-m-d|null, end_date Y-m-d|null, days int|null,
     *     from 'Y-m-d 00:00:00'|null (inclusive), to 'Y-m-d 00:00:00'|null (exclusive: the day after end_date),
     *     timezone string, is_default bool
     * }
     */
    public function resolve_period($period = '', $start_date = '', $end_date = '')
    {
        $timezone = wp_timezone();
        $today = new \DateTimeImmutable('today', $timezone);
        $period = is_scalar($period) ? (string) $period : '';
        $is_default = false;

        $start = null;
        $end = null;

        if ($period === 'all') {
            return [
                'mode' => 'all',
                'key' => 'all',
                'start_date' => null,
                'end_date' => null,
                'days' => null,
                'from' => null,
                'to' => null,
                'timezone' => wp_timezone_string(),
                'is_default' => false,
            ];
        }

        if (!in_array($period, self::PERIODS, true)) {
            $period = '';
            $start = $this->parse_day($start_date, $timezone);
            $end = $this->parse_day($end_date, $timezone);

            if ($start && $end && $start > $end) {
                $swap = $start;
                $start = $end;
                $end = $swap;
            }

            if (!$start || !$end) {
                $period = self::DEFAULT_PERIOD;
                $is_default = true;
            }
        }

        if ($period !== '') {
            $end = $today;
            $start = $today->modify('-' . ((int) $period - 1) . ' days');
        }

        return [
            'mode' => $period !== '' ? 'rolling' : 'fixed',
            'key' => $period !== '' ? $period : 'custom',
            'start_date' => $start->format('Y-m-d'),
            'end_date' => $end->format('Y-m-d'),
            'days' => (int) $start->diff($end)->days + 1,
            'from' => $start->format('Y-m-d 00:00:00'),
            'to' => $end->modify('+1 day')->format('Y-m-d 00:00:00'),
            'timezone' => wp_timezone_string(),
            'is_default' => $is_default,
        ];
    }

    /**
     * TRANSITION: the list as the 1.0.6 templates read it
     *
     * Same arguments and row shape as before, variations of the grouped view
     * loaded inline, now produced by get_list(). The arguments of get_list()
     * (search, view, tab, drafts, the new sorts and stock filters, period)
     * are accepted too and passed on. Delete this method, legacy_row() and
     * legacy_variation() once the templates read get_list() rows.
     *
     * @param array $args start_date, end_date, sort_by, sort_order, page, per_page, category_filter,
     *                    tag_filter, attribute_filter, stock_filter, min_price, max_price, min_sales,
     *                    max_sales, include_variations, plus anything get_list() takes
     * @return array products, total_count, total_pages, current_page, per_page, period, counts,
     *               and 'error' (string) only when the list could not be read
     */
    public function get_products($args = [])
    {
        $args = is_array($args) ? $args : [];
        $args['with_images'] = false;

        $list = $this->get_list($args);

        $result = [
            'products' => [],
            'total_count' => $list['total_count'],
            'total_pages' => $list['total_pages'],
            'current_page' => $list['current_page'],
            'per_page' => $list['per_page'],
            'period' => $list['period'],
            'counts' => $list['counts'],
        ];

        if ($list['error']) {
            $result['error'] = $list['error']['message'];
            return $result;
        }

        // The old grouped view carries every variation of every variable product on the page
        $inline = [];
        if ($list['view'] === self::VIEW_PRODUCT && $list['rows']) {
            $parent_ids = [];
            foreach ($list['rows'] as $row) {
                if (in_array($row['type'], self::VARIABLE_TYPES, true)) {
                    $parent_ids[] = $row['id'];
                }
            }

            try {
                $children = $this->child_ids($parent_ids);
                $all = $children ? array_merge(...array_values($children)) : [];
                $by_id = [];
                foreach ($this->hydrate_rows($all, $list['period'], ['images' => false, 'search' => '']) as $variation) {
                    $by_id[$variation['id']] = $variation;
                }
                foreach ($children as $parent_id => $child_ids) {
                    foreach ($child_ids as $child_id) {
                        if (isset($by_id[$child_id])) {
                            $inline[$parent_id][] = $this->legacy_variation($by_id[$child_id]);
                        }
                    }
                }
            } catch (\RuntimeException $e) {
                $failed = $this->failed($list, $e);
                $result['error'] = $failed['error']['message'];
                return $result;
            }
        }

        foreach ($list['rows'] as $row) {
            $legacy = $this->legacy_row($row);
            if (isset($inline[$row['id']])) {
                $legacy['variations'] = $inline[$row['id']];
            }
            $result['products'][] = $legacy;
        }

        return $result;
    }

    /* ---------------------------------------------------------------------
     * Arguments
     * ------------------------------------------------------------------- */

    private function normalise_list_args($args)
    {
        $args = wp_parse_args(is_array($args) ? $args : [], [
            'tab' => self::TAB_ALL,
            'view' => null,
            'include_variations' => false,
            'search' => '',
            'sort_by' => '',
            'sort_order' => 'DESC',
            'page' => 1,
            'per_page' => 50,
            'category_filter' => [],
            'tag_filter' => [],
            'attribute_filter' => [],
            'stock_filter' => [],
            'min_price' => 0,
            'max_price' => 0,
            'min_sales' => 0,
            'max_sales' => 0,
            'sold_only' => false,
            'attention' => 'all',
            'include_drafts' => false,
            'period' => '',
            'start_date' => '',
            'end_date' => '',
            'with_images' => true,
        ]);

        $q = [];

        $q['tab'] = $args['tab'] === self::TAB_ATTENTION ? self::TAB_ATTENTION : self::TAB_ALL;

        if ($q['tab'] === self::TAB_ATTENTION || $args['view'] === self::VIEW_SKU) {
            $q['view'] = self::VIEW_SKU;
        } elseif ($args['view'] === self::VIEW_PRODUCT) {
            $q['view'] = self::VIEW_PRODUCT;
        } else {
            $q['view'] = !empty($args['include_variations']) ? self::VIEW_SKU : self::VIEW_PRODUCT;
        }

        $search = is_scalar($args['search']) ? trim(preg_replace('/\s+/u', ' ', (string) $args['search'])) : '';
        $q['search'] = function_exists('mb_substr') ? mb_substr($search, 0, 200) : substr($search, 0, 200);
        $q['exact_ids'] = [];

        $sort_by = is_string($args['sort_by']) ? $args['sort_by'] : '';
        $q['sort_by'] = in_array($sort_by, self::SORT_FIELDS, true) ? $sort_by : '';
        $q['sort_order'] = is_string($args['sort_order']) && strtoupper($args['sort_order']) === 'ASC' ? 'ASC' : 'DESC';

        $number = function ($value) {
            return is_scalar($value) ? (float) $value : 0;
        };

        $q['page'] = (int) max(1, min(PHP_INT_MAX >> 16, $number($args['page'])));
        $q['per_page'] = (int) max(1, min(self::MAX_PER_PAGE, $number($args['per_page'])));

        $q['category_filter'] = $this->clean_ids($args['category_filter']);
        $q['tag_filter'] = $this->clean_ids($args['tag_filter']);

        $q['attribute_filter'] = [];
        if (is_array($args['attribute_filter'])) {
            foreach ($args['attribute_filter'] as $taxonomy => $term_ids) {
                $taxonomy = is_string($taxonomy) ? $taxonomy : '';
                $term_ids = $this->clean_ids($term_ids);
                if ($term_ids && strpos($taxonomy, 'pa_') === 0 && taxonomy_exists($taxonomy)) {
                    $q['attribute_filter'][$taxonomy] = $term_ids;
                }
            }
        }

        $q['stock_filter'] = is_array($args['stock_filter'])
            ? array_values(array_intersect(self::STOCK_FILTERS, array_filter($args['stock_filter'], 'is_string')))
            : [];

        $q['min_price'] = max(0, $number($args['min_price']));
        $q['max_price'] = max(0, $number($args['max_price']));
        $q['min_sales'] = (int) max(0, min(PHP_INT_MAX >> 16, $number($args['min_sales'])));
        $q['max_sales'] = (int) max(0, min(PHP_INT_MAX >> 16, $number($args['max_sales'])));
        $q['sold_only'] = !empty($args['sold_only']);

        $q['attention'] = in_array($args['attention'], self::ATTENTION_FILTERS, true) ? $args['attention'] : 'all';

        // Needs attention never lists drafts
        $q['include_drafts'] = $q['tab'] === self::TAB_ALL && !empty($args['include_drafts']);
        $q['statuses'] = $q['include_drafts'] ? array_merge(['publish'], self::DRAFT_STATUSES) : ['publish'];

        $q['period'] = $this->resolve_period($args['period'], $args['start_date'], $args['end_date']);

        $q['with_images'] = !empty($args['with_images']);

        $q['low_stock_amount'] = (int) get_option('woocommerce_notify_low_stock_amount', 2);

        // Sales are totalled for the whole catalogue only when the order or the selection depends on them
        $q['needs_sales'] = in_array($q['sort_by'], ['total_sales', 'cover'], true)
            || $q['min_sales'] > 0
            || $q['max_sales'] > 0
            || $q['sold_only']
            || ($q['tab'] === self::TAB_ATTENTION && $q['sort_by'] === '');

        return $q;
    }

    private function clean_ids($ids)
    {
        if (!is_array($ids)) {
            return [];
        }

        $clean = [];
        foreach ($ids as $id) {
            if (is_scalar($id) && absint($id) > 0) {
                $clean[] = absint($id);
            }
        }

        return array_values(array_unique($clean));
    }

    private function parse_day($value, $timezone)
    {
        if (!is_string($value) || !preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m)) {
            return null;
        }

        if (!checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            return null;
        }

        return new \DateTimeImmutable($value . ' 00:00:00', $timezone);
    }

    /* ---------------------------------------------------------------------
     * The id query
     *
     * A list is one or two branches with the same columns:
     *   product    rows that are products       (p = the product,   l = its lookup row)
     *   variation  rows that are variations     (p = the variation, l = its lookup row,
     *                                            pp = its product,  pl = the product's lookup row)
     * By product: the product branch, every product.
     * By SKU:     products that hold stock or could, plus variations that do.
     * ------------------------------------------------------------------- */

    private function build_branches($q)
    {
        global $wpdb;

        $lookup = $this->lookup_table();
        $statuses = $this->prepare_in($q['statuses'], '%s');
        $by_sku = $q['view'] === self::VIEW_SKU;
        $variable = $this->type_tt_ids(self::VARIABLE_TYPES);
        $sales = $q['needs_sales'] ? ' LEFT JOIN (' . $this->sales_subquery($q['period']) . ') s ON s.id = p.ID' : '';

        $branches = [];

        // Products
        $from = "FROM {$wpdb->posts} p LEFT JOIN {$lookup} l ON l.product_id = p.ID" . $sales;
        if (!$by_sku && in_array($q['sort_by'], ['stock_quantity', 'cover', 'price'], true)) {
            // By product these keys are totals over a product's variations
            $from .= ' LEFT JOIN (' . $this->variation_totals_subquery() . ') agg ON agg.parent_id = p.ID';
        }

        $where = ["p.post_type = 'product'", "p.post_status IN ({$statuses})"];
        if ($by_sku) {
            // A product is a stock row when it is not variable (and not a type without stock),
            // or when it is variable, tracks stock, and at least one variation draws on that stock
            $not_stock_item = array_merge($variable, $this->type_tt_ids(self::NO_STOCK_TYPES));
            $simple = $not_stock_item ? 'NOT ' . $this->has_type_sql('p.ID', $not_stock_item) : '1=1';
            $holder = $variable
                ? '(' . $this->has_type_sql('p.ID', $variable) . ' AND l.stock_quantity IS NOT NULL AND ' . $this->child_exists('vl.stock_quantity IS NULL') . ')'
                : '1=0';
            $where[] = "({$simple} OR {$holder})";
        }

        $branches[] = $this->finish_branch('product', $from, $where, $q);

        // Variations that do not use their product's stock
        if ($by_sku && $variable) {
            $from = "FROM {$wpdb->posts} pp"
                . " INNER JOIN {$wpdb->posts} p ON p.post_parent = pp.ID AND p.post_type = 'product_variation' AND p.post_status IN (" . self::VARIATION_STATUSES_SQL . ')'
                . " LEFT JOIN {$lookup} l ON l.product_id = p.ID"
                . " LEFT JOIN {$lookup} pl ON pl.product_id = pp.ID"
                . $sales;

            $where = [
                "pp.post_type = 'product'",
                "pp.post_status IN ({$statuses})",
                $this->has_type_sql('pp.ID', $variable),
                '(l.stock_quantity IS NOT NULL OR pl.stock_quantity IS NULL)',
            ];

            $branches[] = $this->finish_branch('variation', $from, $where, $q);
        }

        return $branches;
    }

    /**
     * Adds the filters and the columns of one branch
     *
     * @return array from (sql), where (sql), cols (name => sql)
     */
    private function finish_branch($kind, $from, $where, $q)
    {
        global $wpdb;

        $is_variation = $kind === 'variation';
        $by_sku = $q['view'] === self::VIEW_SKU;
        $owner = $is_variation ? 'pp' : 'p';
        $threshold = $this->threshold_sql($q['low_stock_amount'], 'p.ID', $is_variation ? 'pp.ID' : null);

        // Category (with descendants) and tag apply through the product
        if ($q['category_filter']) {
            $term_ids = $q['category_filter'];
            foreach ($q['category_filter'] as $term_id) {
                $children = get_term_children($term_id, 'product_cat');
                if (is_array($children)) {
                    $term_ids = array_merge($term_ids, array_map('intval', $children));
                }
            }
            $where[] = $this->has_term_sql("{$owner}.ID", 'product_cat', array_values(array_unique($term_ids)));
        }

        if ($q['tag_filter']) {
            $where[] = $this->has_term_sql("{$owner}.ID", 'product_tag', $q['tag_filter']);
        }

        // Attribute: a variation's own value, else its product's; a product by its terms
        foreach ($q['attribute_filter'] as $taxonomy => $term_ids) {
            $on_owner = $this->has_term_sql("{$owner}.ID", $taxonomy, $term_ids);

            if (!$is_variation) {
                $where[] = $on_owner;
                continue;
            }

            $slugs = get_terms(['taxonomy' => $taxonomy, 'include' => $term_ids, 'hide_empty' => false, 'fields' => 'id=>slug']);
            $slugs = is_array($slugs) ? array_values($slugs) : [];
            $meta_key = 'attribute_' . $taxonomy;

            $own_value = $slugs
                ? $wpdb->prepare(
                    "EXISTS (SELECT 1 FROM {$wpdb->postmeta} am WHERE am.post_id = p.ID AND am.meta_key = %s AND am.meta_value IN (" . $this->prepare_in($slugs, '%s') . '))',
                    $meta_key
                )
                : '1=0';
            $no_own_value = $wpdb->prepare(
                "NOT EXISTS (SELECT 1 FROM {$wpdb->postmeta} am WHERE am.post_id = p.ID AND am.meta_key = %s AND am.meta_value <> '')",
                $meta_key
            );

            $where[] = "({$own_value} OR ({$no_own_value} AND {$on_owner}))";
        }

        // Search
        if ($q['search'] !== '') {
            $where[] = $this->search_sql($q['search'], $is_variation);
        }

        // Stock status
        if ($q['stock_filter']) {
            $where[] = $this->stock_filter_sql($q['stock_filter'], $by_sku, $threshold, $q['low_stock_amount']);
        }

        // Current price (sale applied). An item answers with its own price; a variable
        // product matches when at least one of its variations is in range.
        if ($q['min_price'] > 0 || $q['max_price'] > 0) {
            $own = $this->price_range_sql('l', 'p.ID', $q['min_price'], $q['max_price']);
            if ($is_variation) {
                $where[] = "({$own})";
            } else {
                $variable = $this->type_tt_ids(self::VARIABLE_TYPES);
                $not_variable = $variable ? 'NOT ' . $this->has_type_sql('p.ID', $variable) : '1=1';
                $any_variation = $this->child_exists($this->price_range_sql('vl', 'v.ID', $q['min_price'], $q['max_price']));
                $where[] = "(({$not_variable} AND {$own}) OR {$any_variation})";
            }
        }

        // Units sold in the period
        if ($q['min_sales'] > 0) {
            $where[] = $wpdb->prepare('COALESCE(s.sold, 0) >= %d', $q['min_sales']);
        }
        if ($q['max_sales'] > 0) {
            $where[] = $wpdb->prepare('COALESCE(s.sold, 0) <= %d', $q['max_sales']);
        }
        if ($q['sold_only']) {
            $where[] = 's.sold > 0';
        }

        $cols = [
            'id' => 'p.ID',
            'k_name' => "{$owner}.post_title",
            'k_owner' => "{$owner}.ID",
            'k_order' => $is_variation ? 'p.menu_order' : '0',
            'k_exact' => $q['exact_ids'] ? '(p.ID IN (' . $this->prepare_in($q['exact_ids'], '%d') . '))' : '0',
        ];

        // Needs attention: tracked, and out of stock or at or below the threshold in force
        if ($q['tab'] === self::TAB_ATTENTION) {
            $where[] = "(l.stock_quantity IS NOT NULL AND (l.stock_status = 'outofstock' OR l.stock_quantity <= {$threshold}))";
            $cols['cls'] = "(CASE WHEN l.stock_status = 'outofstock' THEN 'out' WHEN l.stock_status = 'onbackorder' THEN 'backorder' ELSE 'low' END)";
        }

        // Sort keys
        $stock = 'l.stock_quantity';
        $held = 'l.stock_quantity';
        // 0 in the lookup table is almost always "no price"; those rows go last
        $price = 'NULLIF(l.min_price, 0)';
        if (!$by_sku && in_array($q['sort_by'], ['stock_quantity', 'cover', 'price'], true)) {
            // The lowest variation price, from the variations themselves: the range kept
            // on the product's own lookup row is not always in step with them
            $variable = $this->type_tt_ids(self::VARIABLE_TYPES);
            $no_variations = $variable ? ' WHEN ' . $this->has_type_sql('p.ID', $variable) . ' THEN NULL' : '';
            $price = "(CASE WHEN agg.parent_id IS NOT NULL THEN agg.price_min{$no_variations} ELSE NULLIF(l.min_price, 0) END)";
            // By product: what the product has in total, wherever it is held
            $stock = '(CASE WHEN agg.parent_id IS NULL THEN l.stock_quantity'
                . ' WHEN l.stock_quantity IS NOT NULL AND agg.inherit_n > 0 THEN l.stock_quantity + agg.own_sum'
                . ' WHEN agg.own_n > 0 THEN agg.own_sum ELSE NULL END)';
            $held = '(CASE WHEN agg.parent_id IS NULL OR agg.inherit_n > 0 THEN l.stock_quantity ELSE NULL END)';
        }

        switch ($q['sort_by']) {
            case 'sku':
                $cols['k_sort'] = "NULLIF(l.sku, '')";
                break;
            case 'price':
                $cols['k_sort'] = $price;
                break;
            case 'stock_quantity':
                $cols['k_sort'] = $stock;
                break;
            case 'total_sales':
                $cols['k_sort'] = 'COALESCE(s.sold, 0)';
                break;
            case 'cover':
                $cols['k_sort'] = $q['period']['days']
                    ? $wpdb->prepare("(CASE WHEN s.sold > 0 AND {$held} IS NOT NULL THEN GREATEST({$held}, 0) * %d / s.sold ELSE NULL END)", $q['period']['days'])
                    : 'NULL';
                break;
            default:
                if ($q['tab'] === self::TAB_ATTENTION) {
                    $cols['k_sort'] = 'l.stock_quantity';
                    $cols['k_sort2'] = 'COALESCE(s.sold, 0)';
                }
        }

        return ['from' => $from, 'where' => implode(' AND ', $where), 'cols' => $cols];
    }

    private function select_sql($branches, $col_names)
    {
        $selects = [];
        foreach ($branches as $branch) {
            $cols = [];
            foreach ($col_names as $name) {
                $cols[] = $branch['cols'][$name] . ' AS ' . $name;
            }
            $selects[] = 'SELECT ' . implode(', ', $cols) . ' ' . $branch['from'] . ' WHERE ' . $branch['where'];
        }

        return implode(' UNION ALL ', $selects);
    }

    /**
     * @return array all, and for Needs attention also out, low, backorder
     */
    private function count_rows($q, $branches)
    {
        if ($q['tab'] !== self::TAB_ATTENTION) {
            $sql = 'SELECT COUNT(*) FROM (' . $this->select_sql($branches, ['id']) . ') r';

            return ['all' => (int) $this->run('get_var', $sql)];
        }

        $sql = "SELECT COUNT(*) AS n_all, SUM(r.cls = 'out') AS n_out, SUM(r.cls = 'low') AS n_low, SUM(r.cls = 'backorder') AS n_backorder"
            . ' FROM (' . $this->select_sql($branches, ['id', 'cls']) . ') r';
        $row = $this->run('get_row', $sql);

        return [
            'all' => $row ? (int) $row->n_all : 0,
            'out' => $row ? (int) $row->n_out : 0,
            'low' => $row ? (int) $row->n_low : 0,
            'backorder' => $row ? (int) $row->n_backorder : 0,
        ];
    }

    private function page_ids($q, $branches, $page)
    {
        global $wpdb;

        $col_names = array_keys($branches[0]['cols']);
        $direction = $q['sort_order'] === 'ASC' ? 'ASC' : 'DESC';

        // Name, then a product's variations together in the product's own order
        $by_name = 'r.k_name ASC, r.k_owner ASC, r.k_order ASC, r.id ASC';

        $order = [];
        if ($q['exact_ids']) {
            // An exact SKU match is listed first
            $order[] = 'r.k_exact DESC';
        }

        switch ($q['sort_by']) {
            case 'name':
                $order[] = "r.k_name {$direction}, r.k_owner ASC, r.k_order ASC, r.id ASC";
                break;
            case 'sku':
            case 'price':
            case 'stock_quantity':
            case 'cover':
                // Rows without a value come last in either direction
                $order[] = "(r.k_sort IS NULL) ASC, r.k_sort {$direction}, {$by_name}";
                break;
            case 'total_sales':
                $order[] = "r.k_sort {$direction}, {$by_name}";
                break;
            default:
                $order[] = $q['tab'] === self::TAB_ATTENTION
                    ? "r.k_sort ASC, r.k_sort2 DESC, {$by_name}"
                    : $by_name;
        }

        $sql = 'SELECT r.id FROM (' . $this->select_sql($branches, $col_names) . ') r';
        if ($q['tab'] === self::TAB_ATTENTION && $q['attention'] !== 'all') {
            $sql .= $wpdb->prepare(' WHERE r.cls = %s', $q['attention']);
        }
        $sql .= ' ORDER BY ' . implode(', ', $order);
        $sql .= $wpdb->prepare(' LIMIT %d OFFSET %d', $q['per_page'], ($page - 1) * $q['per_page']);

        return array_map('intval', $this->run('get_col', $sql));
    }

    /* ---------------------------------------------------------------------
     * SQL fragments
     * ------------------------------------------------------------------- */

    private function lookup_table()
    {
        global $wpdb;

        return isset($wpdb->wc_product_meta_lookup) ? $wpdb->wc_product_meta_lookup : $wpdb->prefix . 'wc_product_meta_lookup';
    }

    /**
     * A prepared, comma-separated list for IN (...)
     */
    private function prepare_in($values, $format)
    {
        global $wpdb;

        $values = array_values($values);

        return $wpdb->prepare(implode(',', array_fill(0, count($values), $format)), ...$values);
    }

    /**
     * term_taxonomy ids of the given product types that exist on this site
     */
    private function type_tt_ids($slugs)
    {
        $key = implode(',', $slugs);

        if (!isset($this->type_tt_ids[$key])) {
            $terms = get_terms(['taxonomy' => 'product_type', 'slug' => $slugs, 'hide_empty' => false]);
            $ids = [];
            if (is_array($terms)) {
                foreach ($terms as $term) {
                    $ids[] = (int) $term->term_taxonomy_id;
                }
            }
            $this->type_tt_ids[$key] = $ids;
        }

        return $this->type_tt_ids[$key];
    }

    private function has_type_sql($id_sql, $tt_ids)
    {
        global $wpdb;

        return "EXISTS (SELECT 1 FROM {$wpdb->term_relationships} ty WHERE ty.object_id = {$id_sql} AND ty.term_taxonomy_id IN (" . $this->prepare_in($tt_ids, '%d') . '))';
    }

    private function has_term_sql($id_sql, $taxonomy, $term_ids)
    {
        global $wpdb;

        return $wpdb->prepare(
            "EXISTS (SELECT 1 FROM {$wpdb->term_relationships} tr"
            . " INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id"
            . " WHERE tr.object_id = {$id_sql} AND tt.taxonomy = %s AND tt.term_id IN (" . $this->prepare_in($term_ids, '%d') . '))',
            $taxonomy
        );
    }

    /**
     * True when the product p has a variation for which $condition holds
     * (v = the variation, vl = its lookup row)
     */
    private function child_exists($condition)
    {
        global $wpdb;

        return "EXISTS (SELECT 1 FROM {$wpdb->posts} v LEFT JOIN {$this->lookup_table()} vl ON vl.product_id = v.ID"
            . " WHERE v.post_parent = p.ID AND v.post_type = 'product_variation' AND v.post_status IN (" . self::VARIATION_STATUSES_SQL . ')'
            . " AND ({$condition}))";
    }

    /**
     * Price bounds on a lookup row's own current price; 0 means no bound
     *
     * The lookup table keeps 0, not NULL, for many items that have no price
     * at all. With only an upper bound those would match, so a 0 counts only
     * when a price is really stored.
     */
    private function price_range_sql($alias, $id_sql, $min, $max)
    {
        global $wpdb;

        $parts = [];
        if ($min > 0) {
            $parts[] = $wpdb->prepare("{$alias}.min_price >= %f", $min);
        } else {
            $parts[] = "({$alias}.min_price > 0 OR EXISTS (SELECT 1 FROM {$wpdb->postmeta} pr WHERE pr.post_id = {$id_sql} AND pr.meta_key = '_price' AND pr.meta_value <> ''))";
        }
        if ($max > 0) {
            $parts[] = $wpdb->prepare("{$alias}.min_price <= %f", $max);
        }

        return implode(' AND ', $parts);
    }

    /**
     * The low-stock threshold in force: the item's own, else its product's,
     * else the store-wide setting. The same rule as wc_get_low_stock_amount().
     */
    private function threshold_sql($store_amount, $id_sql, $parent_id_sql = null)
    {
        global $wpdb;

        $parts = ["NULLIF((SELECT t1.meta_value FROM {$wpdb->postmeta} t1 WHERE t1.post_id = {$id_sql} AND t1.meta_key = '_low_stock_amount' LIMIT 1), '')"];
        if ($parent_id_sql) {
            $parts[] = "NULLIF((SELECT t2.meta_value FROM {$wpdb->postmeta} t2 WHERE t2.post_id = {$parent_id_sql} AND t2.meta_key = '_low_stock_amount' LIMIT 1), '')";
        }

        return $wpdb->prepare('CAST(COALESCE(' . implode(', ', $parts) . ', %d) AS SIGNED)', $store_amount);
    }

    /**
     * Units sold per id in the period: a product's total under its id (all
     * its variations included), a variation's own under the variation id
     */
    private function sales_subquery($period)
    {
        global $wpdb;

        $lines = $wpdb->prefix . 'wc_order_product_lookup';
        $orders = $wpdb->prefix . 'wc_order_stats';
        $paid = 'st.status IN (' . self::PAID_STATUSES_SQL . ')' . $this->period_sql($period);

        return 'SELECT x.id, SUM(x.qty) AS sold FROM ('
            . "SELECT o.product_id AS id, o.product_qty AS qty FROM {$lines} o INNER JOIN {$orders} st ON st.order_id = o.order_id WHERE {$paid}"
            . ' UNION ALL '
            . "SELECT o.variation_id AS id, o.product_qty AS qty FROM {$lines} o INNER JOIN {$orders} st ON st.order_id = o.order_id WHERE o.variation_id > 0 AND {$paid}"
            . ') x GROUP BY x.id';
    }

    /**
     * Order dates are stored in site time, so the bounds are site-time days
     */
    private function period_sql($period)
    {
        global $wpdb;

        if (!$period['from'] || !$period['to']) {
            return '';
        }

        return $wpdb->prepare(' AND st.date_created >= %s AND st.date_created < %s', $period['from'], $period['to']);
    }

    /**
     * Per variable product: how many variations it has, how many use the
     * product's stock, what the others hold themselves, and the lowest price
     */
    private function variation_totals_subquery()
    {
        global $wpdb;

        return 'SELECT v.post_parent AS parent_id, COUNT(*) AS n,'
            . ' SUM(vl.stock_quantity IS NULL) AS inherit_n,'
            . ' SUM(vl.stock_quantity IS NOT NULL) AS own_n,'
            . ' SUM(COALESCE(vl.stock_quantity, 0)) AS own_sum,'
            . ' MIN(NULLIF(vl.min_price, 0)) AS price_min'
            . " FROM {$wpdb->posts} v LEFT JOIN {$this->lookup_table()} vl ON vl.product_id = v.ID"
            . " WHERE v.post_type = 'product_variation' AND v.post_status IN (" . self::VARIATION_STATUSES_SQL . ')'
            . ' GROUP BY v.post_parent';
    }

    private function search_sql($term, $is_variation)
    {
        global $wpdb;

        $like = '%' . $wpdb->esc_like($term) . '%';
        $id = ctype_digit($term) && strlen($term) <= 18 ? (int) $term : 0;

        if ($is_variation) {
            $parts = [
                $wpdb->prepare('pp.post_title LIKE %s', $like),
                $wpdb->prepare('l.sku LIKE %s', $like),
                $wpdb->prepare('pl.sku LIKE %s', $like),
            ];
            if ($id) {
                $parts[] = $wpdb->prepare('p.ID = %d OR pp.ID = %d', $id, $id);
            }

            return '(' . implode(' OR ', $parts) . ')';
        }

        // A product is found by itself or by any of its variations
        $variation = $wpdb->prepare('vl.sku LIKE %s', $like);
        if ($id) {
            $variation .= $wpdb->prepare(' OR v.ID = %d', $id);
        }

        $parts = [
            $wpdb->prepare('p.post_title LIKE %s', $like),
            $wpdb->prepare('l.sku LIKE %s', $like),
            "p.ID IN (SELECT v.post_parent FROM {$wpdb->posts} v LEFT JOIN {$this->lookup_table()} vl ON vl.product_id = v.ID"
                . " WHERE v.post_type = 'product_variation' AND v.post_status IN (" . self::VARIATION_STATUSES_SQL . ") AND ({$variation}))",
        ];
        if ($id) {
            $parts[] = $wpdb->prepare('p.ID = %d', $id);
        }

        return '(' . implode(' OR ', $parts) . ')';
    }

    /**
     * Stock status filter; several values are OR-ed.
     *
     * By SKU each row answers for itself. By product a variable product
     * matches when it or at least one of its variations does.
     */
    private function stock_filter_sql($values, $by_sku, $threshold, $store_amount)
    {
        $statuses = array_values(array_intersect($values, ['instock', 'outofstock', 'onbackorder']));
        $variable = $this->type_tt_ids(self::VARIABLE_TYPES);
        $is_variable = $variable ? $this->has_type_sql('p.ID', $variable) : '1=0';
        $parts = [];

        if ($statuses) {
            $in = $this->prepare_in($statuses, '%s');
            $parts[] = "l.stock_status IN ({$in})";
            if (!$by_sku) {
                $parts[] = $this->child_exists("vl.stock_status IN ({$in})");
            }
        }

        // Low: tracked, in stock, at or below the threshold in force
        if (in_array('lowstock', $values, true)) {
            $low = "l.stock_quantity IS NOT NULL AND l.stock_status = 'instock' AND l.stock_quantity <= {$threshold}";
            if ($by_sku) {
                $parts[] = "({$low})";
            } else {
                // The product's own stock counts only when something draws on it
                $parts[] = "({$low} AND (NOT {$is_variable} OR " . $this->child_exists('vl.stock_quantity IS NULL') . '))';
                $parts[] = $this->child_exists(
                    "vl.stock_quantity IS NOT NULL AND vl.stock_status = 'instock' AND vl.stock_quantity <= " . $this->threshold_sql($store_amount, 'v.ID', 'p.ID')
                );
            }
        }

        // Not tracked: no stock of its own and none to draw on
        if (in_array('untracked', $values, true)) {
            $parts[] = $by_sku
                ? 'l.stock_quantity IS NULL'
                : "(l.stock_quantity IS NULL AND (NOT {$is_variable} OR " . $this->child_exists('vl.stock_quantity IS NULL') . '))';
        }

        return $parts ? '(' . implode(' OR ', $parts) . ')' : '1=1';
    }

    /* ---------------------------------------------------------------------
     * Small lookups around the id query
     * ------------------------------------------------------------------- */

    /**
     * Ids to list first for a search: rows whose SKU is exactly the term,
     * and the products of variations whose SKU is
     */
    private function exact_sku_ids($term)
    {
        global $wpdb;

        $rows = $this->run('get_results', $wpdb->prepare(
            "SELECT x.ID AS id, x.post_type, x.post_parent FROM {$this->lookup_table()} l"
            . " INNER JOIN {$wpdb->posts} x ON x.ID = l.product_id"
            . ' WHERE l.sku = %s LIMIT 100',
            $term
        ));

        $ids = [];
        foreach ($rows as $row) {
            $ids[] = (int) $row->id;
            if ($row->post_type === 'product_variation' && $row->post_parent) {
                $ids[] = (int) $row->post_parent;
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * Which variations of the given products the search term matches
     *
     * @return array product id => variation ids
     */
    private function matched_variation_ids($parent_ids, $term)
    {
        global $wpdb;

        if (!$parent_ids || $term === '') {
            return [];
        }

        $match = $wpdb->prepare('vl.sku LIKE %s', '%' . $wpdb->esc_like($term) . '%');
        if (ctype_digit($term) && strlen($term) <= 18) {
            $match .= $wpdb->prepare(' OR v.ID = %d', (int) $term);
        }

        $rows = $this->run('get_results',
            "SELECT v.post_parent AS parent_id, v.ID AS id FROM {$wpdb->posts} v"
            . " LEFT JOIN {$this->lookup_table()} vl ON vl.product_id = v.ID"
            . ' WHERE v.post_parent IN (' . $this->prepare_in($parent_ids, '%d') . ')'
            . " AND v.post_type = 'product_variation' AND v.post_status IN (" . self::VARIATION_STATUSES_SQL . ')'
            . " AND ({$match}) ORDER BY v.menu_order ASC, v.ID ASC"
        );

        $matched = [];
        foreach ($rows as $row) {
            $matched[(int) $row->parent_id][] = (int) $row->id;
        }

        return $matched;
    }

    /**
     * Variation ids per product, in the product's own order. Read directly:
     * WC_Product_Variable::get_children() would store a transient.
     *
     * @return array product id => variation ids
     */
    private function child_ids($parent_ids)
    {
        global $wpdb;

        if (!$parent_ids) {
            return [];
        }

        $rows = $this->run('get_results',
            "SELECT v.post_parent AS parent_id, v.ID AS id FROM {$wpdb->posts} v"
            . ' WHERE v.post_parent IN (' . $this->prepare_in($parent_ids, '%d') . ')'
            . " AND v.post_type = 'product_variation' AND v.post_status IN (" . self::VARIATION_STATUSES_SQL . ')'
            . ' ORDER BY v.post_parent ASC, v.menu_order ASC, v.ID ASC'
        );

        $children = [];
        foreach ($rows as $row) {
            $children[(int) $row->parent_id][] = (int) $row->id;
        }

        return $children;
    }

    /**
     * Totals over the variations of the given products
     *
     * Counts, statuses, stock and current prices come from the lookup table;
     * the regular price range and the sale price count from the stored prices.
     *
     * @return array product id => raw totals
     */
    private function variation_summaries($parent_ids)
    {
        global $wpdb;

        if (!$parent_ids) {
            return [];
        }

        $in = $this->prepare_in($parent_ids, '%d');
        $variations = "v.post_parent IN ({$in}) AND v.post_type = 'product_variation' AND v.post_status IN (" . self::VARIATION_STATUSES_SQL . ')';
        $summaries = [];

        $rows = $this->run('get_results',
            'SELECT v.post_parent AS parent_id, COUNT(*) AS n,'
            . ' SUM(vl.stock_quantity IS NOT NULL) AS own_n,'
            . ' SUM(COALESCE(vl.stock_quantity, 0)) AS own_sum,'
            . " SUM(vl.stock_status = 'instock') AS in_n,"
            . " SUM(vl.stock_status = 'outofstock') AS out_n,"
            . " SUM(vl.stock_status = 'onbackorder') AS backorder_n,"
            . ' SUM(vl.onsale = 1) AS on_sale_n,'
            . ' MIN(vl.min_price) AS price_min, MAX(vl.max_price) AS price_max'
            . " FROM {$wpdb->posts} v LEFT JOIN {$this->lookup_table()} vl ON vl.product_id = v.ID"
            . " WHERE {$variations} GROUP BY v.post_parent"
        );
        foreach ($rows as $row) {
            $summaries[(int) $row->parent_id] = (array) $row;
        }

        $rows = $this->run('get_results',
            'SELECT v.post_parent AS parent_id,'
            . " MIN(CAST(NULLIF(rp.meta_value, '') AS DECIMAL(19,4))) AS regular_min,"
            . " MAX(CAST(NULLIF(rp.meta_value, '') AS DECIMAL(19,4))) AS regular_max,"
            . " COUNT(DISTINCT CASE WHEN sp.meta_value <> '' THEN v.ID END) AS sale_price_n"
            . " FROM {$wpdb->posts} v"
            . " LEFT JOIN {$wpdb->postmeta} rp ON rp.post_id = v.ID AND rp.meta_key = '_regular_price'"
            . " LEFT JOIN {$wpdb->postmeta} sp ON sp.post_id = v.ID AND sp.meta_key = '_sale_price'"
            . " WHERE {$variations} GROUP BY v.post_parent"
        );
        foreach ($rows as $row) {
            if (isset($summaries[(int) $row->parent_id])) {
                $summaries[(int) $row->parent_id] += (array) $row;
            }
        }

        return $summaries;
    }

    /**
     * Units sold in the period for the rows of one page
     *
     * @param int[] $product_ids products on the page and the products of the variations on it
     * @return array [product id => units (all its variations included), variation id => units]
     */
    private function sales_for($product_ids, $period)
    {
        global $wpdb;

        if (!$product_ids) {
            return [[], []];
        }

        $rows = $this->run('get_results',
            'SELECT o.product_id, o.variation_id, SUM(o.product_qty) AS sold'
            . " FROM {$wpdb->prefix}wc_order_product_lookup o"
            . " INNER JOIN {$wpdb->prefix}wc_order_stats st ON st.order_id = o.order_id"
            . ' WHERE o.product_id IN (' . $this->prepare_in($product_ids, '%d') . ')'
            . ' AND st.status IN (' . self::PAID_STATUSES_SQL . ')' . $this->period_sql($period)
            . ' GROUP BY o.product_id, o.variation_id'
        );

        $by_product = [];
        $by_variation = [];
        foreach ($rows as $row) {
            $product_id = (int) $row->product_id;
            $by_product[$product_id] = (isset($by_product[$product_id]) ? $by_product[$product_id] : 0) + (int) $row->sold;
            if ((int) $row->variation_id > 0) {
                $by_variation[(int) $row->variation_id] = (int) $row->sold;
            }
        }

        return [$by_product, $by_variation];
    }

    /* ---------------------------------------------------------------------
     * Rows
     * ------------------------------------------------------------------- */

    /**
     * Builds the rows for the given ids, in the given order
     *
     * Stock, status, tracking and prices are read from WooCommerce product
     * objects in the 'edit' context, as WriteService does; a product that can
     * no longer be loaded is left out.
     *
     * Row keys:
     *   id, parent_id (0 unless a variation), type (the real WooCommerce type),
     *   post_status, parent_status (variation: its product's status, else null),
     *   name (the product's name; for a variation its product's name), full_name (WooCommerce's
     *   own name of the item), attributes (variation: list of key, label, value, raw), attribute_summary
     *   (all plain text, entities decoded: escape on output),
     *   sku (own), parent_sku (variation: its product's SKU, else ''),
     *   image_id, thumbnail_url,
     *   stock_mode 'own'|'parent'|'none', manage_stock (bool, own setting), stock_managed_by_id (int|null),
     *   stock_quantity (own; null unless stock_mode is 'own'), holder_stock_quantity (what the item
     *   can draw on: own, or the product's for stock_mode 'parent'; null when untracked),
     *   stock_status, backorders, is_stock_holder (bool), can_start_tracking (bool),
     *   editable (field => bool from WriteService::editable_fields(), or null),
     *   low_stock_threshold (int|null), attention (null|'out'|'low'|'backorder'),
     *   regular_price, sale_price ('' = none), price (current), sale_from, sale_to (Y-m-d|null),
     *   units_sold (int), cover_days (int|null),
     *   variation_count (int; 0 unless variable), variation_summary (array|null),
     *   matched_self (bool), matched_variation_ids (int[])
     */
    private function hydrate_rows($ids, $period, $opts)
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        if (!$ids) {
            return [];
        }

        // Posts, meta and terms of the page in three queries, then the products of its variations
        _prime_post_caches($ids, true, true);

        $parent_ids = [];
        foreach ($ids as $id) {
            $post = get_post($id);
            if ($post && $post->post_type === 'product_variation' && $post->post_parent) {
                $parent_ids[] = (int) $post->post_parent;
            }
        }
        $parent_ids = array_values(array_unique($parent_ids));
        if ($parent_ids) {
            _prime_post_caches($parent_ids, true, true);
        }

        $this->prime_variable_product_transients($ids);

        $products = [];
        $variable_ids = [];
        foreach ($ids as $id) {
            $product = wc_get_product($id);
            if (!$product) {
                continue;
            }
            $products[$id] = $product;
            if (in_array($product->get_type(), self::VARIABLE_TYPES, true)) {
                $variable_ids[] = $id;
            }
        }

        if (!$products) {
            return [];
        }

        $summaries = $this->variation_summaries($variable_ids);
        $matched = $this->matched_variation_ids($variable_ids, $opts['search']);

        $sales_ids = $parent_ids;
        foreach ($products as $id => $product) {
            if (!($product instanceof \WC_Product_Variation)) {
                $sales_ids[] = $id;
            }
        }
        list($sold_by_product, $sold_by_variation) = $this->sales_for(array_values(array_unique($sales_ids)), $period);

        $term_names = $this->attribute_term_names($products);
        $store_threshold = (int) get_option('woocommerce_notify_low_stock_amount', 2);

        $rows = [];
        foreach ($products as $id => $product) {
            $row = $this->build_row($product, $store_threshold, $term_names);

            if (isset($summaries[$id])) {
                $row['variation_summary'] = $this->format_summary($summaries[$id], $row);
                $row['variation_count'] = $row['variation_summary']['count'];
            }

            // A variable product is the stock row only when a variation draws on its stock
            if (in_array($row['type'], self::VARIABLE_TYPES, true)) {
                $row['is_stock_holder'] = $row['stock_mode'] === 'own'
                    && $row['variation_summary'] !== null
                    && $row['variation_summary']['inheriting_count'] > 0;
            }

            if ($row['parent_id']) {
                $row['units_sold'] = isset($sold_by_variation[$id]) ? $sold_by_variation[$id] : 0;
            } else {
                $row['units_sold'] = isset($sold_by_product[$id]) ? $sold_by_product[$id] : 0;
            }

            $this->add_derived($row, $period);

            if ($opts['search'] !== '') {
                $row['matched_self'] = $this->matches_self($row, $opts['search']);
                $row['matched_variation_ids'] = isset($matched[$id]) ? $matched[$id] : [];
            }

            $rows[] = $row;
        }

        if (!empty($opts['images'])) {
            $this->add_thumbnails($rows);
        }

        return $rows;
    }

    /**
     * WooCommerce looks up five transients each time it loads a variable
     * product, one query per product. Reading them for the whole page first
     * turns that into one query. Only a read; if WooCommerce renames them this
     * simply stops helping.
     */
    private function prime_variable_product_transients($ids)
    {
        if (!function_exists('wp_prime_option_caches') || wp_using_ext_object_cache()) {
            return;
        }

        $names = [];
        foreach ($ids as $id) {
            if (!in_array(\WC_Product_Factory::get_product_type($id), self::VARIABLE_TYPES, true)) {
                continue;
            }
            foreach (['wc_var_prices_', 'wc_product_children_', 'wc_child_has_weight_', 'wc_child_has_dimensions_', 'wc_related_'] as $name) {
                $names[] = '_transient_' . $name . $id;
                $names[] = '_transient_timeout_' . $name . $id;
            }
        }

        if ($names) {
            wp_prime_option_caches($names);
        }
    }

    private function build_row($product, $store_threshold, $term_names)
    {
        $is_variation = $product instanceof \WC_Product_Variation;
        $type = $product->get_type();
        $parent_id = $is_variation ? (int) $product->get_parent_id() : 0;
        $parent_data = $is_variation && method_exists($product, 'get_parent_data') ? (array) $product->get_parent_data() : [];

        // The 'view' context is what resolves a variation to its product (as in WriteService::read_state)
        $managed = $product->get_manage_stock();
        $stock_mode = $managed === 'parent' ? 'parent' : ($managed ? 'own' : 'none');
        $holds_no_stock = in_array($type, self::NO_STOCK_TYPES, true);
        $is_variable = in_array($type, self::VARIABLE_TYPES, true);

        $own_quantity = $stock_mode === 'own' ? $product->get_stock_quantity('edit') : null;
        $holder_quantity = $own_quantity;
        if ($stock_mode === 'parent') {
            $holder_quantity = $product->get_stock_quantity();
        }

        // Threshold in force for the stock this item sells from
        $threshold = null;
        if ($stock_mode !== 'none') {
            $amount = $stock_mode === 'own' ? $product->get_low_stock_amount('edit') : '';
            if ($amount === '' && $parent_id) {
                $amount = get_post_meta($parent_id, '_low_stock_amount', true);
            }
            $threshold = $amount === '' || $amount === null ? $store_threshold : (int) $amount;
        }

        $attributes = [];
        $values = [];
        if ($is_variation) {
            foreach ((array) $product->get_attributes() as $key => $raw) {
                $raw = (string) $raw;
                $value = $raw;
                if ($raw !== '' && isset($term_names[$key][$raw])) {
                    $value = $term_names[$key][$raw];
                }
                $value = $this->plain($value);
                $attributes[] = [
                    'key' => (string) $key,
                    'label' => $this->plain($this->attribute_label($key, $parent_id)),
                    'value' => $value,
                    'raw' => $raw,
                ];
                if ($value !== '') {
                    $values[] = $value;
                }
            }
        }

        $from = $product->get_date_on_sale_from('edit');
        $to = $product->get_date_on_sale_to('edit');

        return [
            'id' => (int) $product->get_id(),
            'parent_id' => $parent_id,
            'type' => $type,
            'post_status' => $product->get_status('edit'),
            'parent_status' => $is_variation ? (isset($parent_data['status']) ? $parent_data['status'] : get_post_status($parent_id)) : null,
            'name' => $this->plain($is_variation ? (isset($parent_data['title']) ? $parent_data['title'] : get_post_field('post_title', $parent_id)) : $product->get_name('edit')),
            'full_name' => $this->plain($product->get_name('edit')),
            'attributes' => $attributes,
            'attribute_summary' => implode(', ', $values),
            'sku' => (string) $product->get_sku('edit'),
            'parent_sku' => $is_variation ? (string) (isset($parent_data['sku']) ? $parent_data['sku'] : get_post_meta($parent_id, '_sku', true)) : '',
            'image_id' => (int) $product->get_image_id(),
            'thumbnail_url' => '',
            'stock_mode' => $stock_mode,
            'manage_stock' => (bool) $product->get_manage_stock('edit'),
            'stock_managed_by_id' => $stock_mode === 'none' ? null : (int) $product->get_stock_managed_by_id(),
            'stock_quantity' => $own_quantity,
            'holder_stock_quantity' => $holder_quantity,
            'stock_status' => $product->get_stock_status('edit'),
            'backorders' => $product->get_backorders('edit'),
            'is_stock_holder' => $stock_mode === 'own' && !$holds_no_stock,
            'can_start_tracking' => $stock_mode === 'none' && !$holds_no_stock && !$is_variable,
            'editable' => $this->editable_fields($type, $stock_mode),
            'low_stock_threshold' => $threshold,
            'attention' => null,
            'regular_price' => (string) $product->get_regular_price('edit'),
            'sale_price' => (string) $product->get_sale_price('edit'),
            'price' => (string) $product->get_price('edit'),
            'sale_from' => $from ? $from->date('Y-m-d') : null,
            'sale_to' => $to ? $to->date('Y-m-d') : null,
            'units_sold' => 0,
            'cover_days' => null,
            'variation_count' => 0,
            'variation_summary' => null,
            'matched_self' => false,
            'matched_variation_ids' => [],
        ];
    }

    /**
     * Names as plain text. WordPress stores "&" in a title or term name as
     * "&amp;"; rows carry the text itself, to be escaped where it is printed.
     */
    private function plain($text)
    {
        return html_entity_decode((string) $text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    /**
     * Which fields a save accepts on an item of this type and stock mode
     *
     * The rule is WriteService's, asked here so list and save cannot
     * disagree. Null when the write service is not loaded.
     *
     * @return array|null field => bool
     */
    private function editable_fields($type, $stock_mode)
    {
        $rules = [__NAMESPACE__ . '\WriteService', 'editable_fields'];

        return is_callable($rules) ? call_user_func($rules, $type, $stock_mode) : null;
    }

    /**
     * What follows from quantity, threshold and sales: attention and cover
     */
    private function add_derived(&$row, $period)
    {
        if (!$row['is_stock_holder']) {
            return;
        }

        // A tracked item without a stored quantity sells as 0
        $quantity = $row['stock_quantity'] === null ? 0 : $row['stock_quantity'];

        // The same three classes as the Needs attention query
        if ($row['stock_status'] === 'outofstock') {
            $row['attention'] = 'out';
        } elseif ($quantity <= $row['low_stock_threshold']) {
            $row['attention'] = $row['stock_status'] === 'onbackorder' ? 'backorder' : 'low';
        }

        // Days the stock lasts at the period's rate of sale
        if ($period['days'] && $row['units_sold'] > 0) {
            $row['cover_days'] = (int) floor(max(0, $quantity) * $period['days'] / $row['units_sold']);
        }
    }

    private function format_summary($raw, $row)
    {
        $count = (int) $raw['n'];
        $own = (int) $raw['own_n'];
        $parent_tracks = $row['stock_mode'] === 'own';
        $inheriting = $parent_tracks ? $count - $own : 0;

        // Stock on hand: the product's own when a variation draws on it, plus what variations hold themselves
        $total = null;
        if ($own > 0 || $inheriting > 0) {
            $total = wc_stock_amount($raw['own_sum']);
            if ($inheriting > 0 && $row['stock_quantity'] !== null) {
                $total += $row['stock_quantity'];
            }
        }

        return [
            'count' => $count,
            'own_stock_count' => $own,
            'inheriting_count' => $inheriting,
            'untracked_count' => $parent_tracks ? 0 : $count - $own,
            'in_stock_count' => (int) $raw['in_n'],
            'out_of_stock_count' => (int) $raw['out_n'],
            'on_backorder_count' => (int) $raw['backorder_n'],
            'stock_total' => $total,
            'price_min' => $this->decimal_or_null($raw['price_min']),
            'price_max' => $this->decimal_or_null($raw['price_max']),
            'regular_price_min' => $this->decimal_or_null(isset($raw['regular_min']) ? $raw['regular_min'] : null),
            'regular_price_max' => $this->decimal_or_null(isset($raw['regular_max']) ? $raw['regular_max'] : null),
            'on_sale_count' => (int) $raw['on_sale_n'],
            'sale_price_count' => isset($raw['sale_price_n']) ? (int) $raw['sale_price_n'] : 0,
        ];
    }

    private function decimal_or_null($value)
    {
        return $value === null || $value === '' ? null : wc_format_decimal($value, false, true);
    }

    private function matches_self($row, $term)
    {
        if (ctype_digit($term) && ((int) $term === $row['id'] || (int) $term === $row['parent_id'])) {
            return true;
        }

        foreach ([$row['name'], $row['sku'], $row['parent_sku']] as $text) {
            if ($text !== '' && (function_exists('mb_stripos') ? mb_stripos($text, $term) : stripos($text, $term)) !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * Names of the attribute terms used by the variations of a page, in one query
     *
     * @return array taxonomy => slug => name
     */
    private function attribute_term_names($products)
    {
        $slugs = [];
        foreach ($products as $product) {
            if (!($product instanceof \WC_Product_Variation)) {
                continue;
            }
            foreach ((array) $product->get_attributes() as $key => $raw) {
                if ($raw !== '' && strpos((string) $key, 'pa_') === 0 && taxonomy_exists($key)) {
                    $slugs[$key][(string) $raw] = true;
                }
            }
        }

        if (!$slugs) {
            return [];
        }

        $wanted = [];
        foreach ($slugs as $taxonomy_slugs) {
            $wanted = array_merge($wanted, array_map('strval', array_keys($taxonomy_slugs)));
        }

        $terms = get_terms([
            'taxonomy' => array_keys($slugs),
            'slug' => array_values(array_unique($wanted)),
            'hide_empty' => false,
            'update_term_meta_cache' => false,
        ]);

        $names = [];
        if (is_array($terms)) {
            foreach ($terms as $term) {
                $names[$term->taxonomy][$term->slug] = $term->name;
            }
        }

        return $names;
    }

    private $attribute_labels = [];

    private function attribute_label($key, $parent_id)
    {
        $key = (string) $key;

        if (strpos($key, 'pa_') === 0) {
            return wc_attribute_label($key);
        }

        // A custom attribute is named on the product
        $cache_key = $parent_id . ':' . $key;
        if (!isset($this->attribute_labels[$cache_key])) {
            $stored = get_post_meta($parent_id, '_product_attributes', true);
            $this->attribute_labels[$cache_key] = is_array($stored) && !empty($stored[$key]['name'])
                ? (string) $stored[$key]['name']
                : ucfirst(str_replace('-', ' ', $key));
        }

        return $this->attribute_labels[$cache_key];
    }

    private function add_thumbnails(&$rows)
    {
        $image_ids = [];
        foreach ($rows as $row) {
            if ($row['image_id']) {
                $image_ids[] = $row['image_id'];
            }
        }

        if (!$image_ids) {
            return;
        }

        _prime_post_caches(array_values(array_unique($image_ids)), false, true);

        foreach ($rows as &$row) {
            if ($row['image_id']) {
                $url = wp_get_attachment_image_url($row['image_id'], 'thumbnail');
                $row['thumbnail_url'] = $url ? $url : '';
            }
        }
        unset($row);
    }

    /* ---------------------------------------------------------------------
     * TRANSITION: the 1.0.6 row shapes
     * ------------------------------------------------------------------- */

    private function legacy_row($row)
    {
        // The old list printed 0 for a tracked item without a stored quantity
        $quantity = $row['stock_quantity'];
        if ($quantity === null && $row['stock_mode'] === 'own') {
            $quantity = 0;
        }

        return [
            'id' => $row['id'],
            'name' => $row['full_name'],
            'sku' => $row['sku'],
            'stock_quantity' => $quantity,
            'stock_status' => $row['stock_status'],
            'regular_price' => $row['regular_price'] === '' ? null : number_format((float) $row['regular_price'], 2, '.', ''),
            'sale_price' => $row['sale_price'] === '' ? null : number_format((float) $row['sale_price'], 2, '.', ''),
            'type' => $row['type'],
            'status' => $row['post_status'],
            'total_sales' => $row['units_sold'],
            'variations' => [],
        ];
    }

    private function legacy_variation($row)
    {
        $attributes = [];
        foreach ($row['attributes'] as $attribute) {
            $attributes[$attribute['key']] = $attribute['raw'];
        }

        return [
            'id' => $row['id'],
            'name' => $row['full_name'],
            'sku' => $row['sku'] !== '' ? $row['sku'] : $row['parent_sku'],
            'stock_quantity' => $row['holder_stock_quantity'],
            'stock_status' => $row['stock_status'],
            'regular_price' => $row['regular_price'],
            'sale_price' => $row['sale_price'],
            'type' => 'variation',
            'status' => $row['post_status'],
            'total_sales' => $row['units_sold'],
            'attributes' => $attributes,
        ];
    }

    /* ---------------------------------------------------------------------
     * Database access
     * ------------------------------------------------------------------- */

    /**
     * Runs one read query; a failure is thrown, never returned as "no rows"
     *
     * @param string $method get_var, get_row, get_col or get_results
     * @throws \RuntimeException
     */
    private function run($method, $sql)
    {
        global $wpdb;

        $suppressed = $wpdb->suppress_errors(true);
        $value = $wpdb->$method($sql);
        $error = $wpdb->last_error;
        $wpdb->suppress_errors($suppressed);

        if ($error !== '') {
            throw new \RuntimeException($error);
        }

        if ($method === 'get_col' || $method === 'get_results') {
            return is_array($value) ? $value : [];
        }

        return $value;
    }

    private function failed($result, $e)
    {
        error_log('MadeByHype Stock Management - list query error: ' . $e->getMessage());

        $result['error'] = [
            'code' => 'db_error',
            'message' => __('The product list could not be loaded. Reload the page. If this keeps happening, tell your site administrator.', 'madebyhype-stockmanagment'),
        ];

        return $result;
    }
}
