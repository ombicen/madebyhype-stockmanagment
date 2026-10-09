<?php

/**
 * Filter panel of the grid tabs
 *
 * Its inputs belong to the list form in the toolbar (form attribute), so
 * Apply submits them together with the search, view, sort and period.
 *
 * @var \MadeByHypeStockmanagment\UI\UIManager $this
 * @var array  $request
 * @var string $tab
 */

if (! defined('ABSPATH')) {
    exit;
}

$form = 'mbh-list-form';

$terms_of = function ($taxonomy) {
    $terms = get_terms(['taxonomy' => $taxonomy, 'hide_empty' => true]);

    return is_array($terms) ? $terms : [];
};

$checkbox = function ($name, $value, $label, $checked) use ($form) {
    printf(
        '<label class="mbh-filter-option"><input type="checkbox" form="%1$s" name="%2$s" value="%3$s"%4$s> %5$s</label>',
        esc_attr($form),
        esc_attr($name),
        esc_attr($value),
        checked($checked, true, false),
        esc_html($label)
    );
};

// Categories as a tree: parent id => its categories
$categories = [];
foreach ($terms_of('product_cat') as $category) {
    $categories[(int) $category->parent][] = $category;
}

$has_selected_below = function ($parent_id) use (&$has_selected_below, $categories, $request) {
    foreach (isset($categories[$parent_id]) ? $categories[$parent_id] : [] as $category) {
        if (in_array((int) $category->term_id, $request['category_filter'], true) || $has_selected_below((int) $category->term_id)) {
            return true;
        }
    }

    return false;
};

$category_tree = function ($parent_id) use (&$category_tree, $categories, $request, $checkbox, $has_selected_below) {
    foreach (isset($categories[$parent_id]) ? $categories[$parent_id] : [] as $category) {
        $id = (int) $category->term_id;
        $name = html_entity_decode($category->name, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $has_children = !empty($categories[$id]);
        $open = $has_children && $has_selected_below($id);

        echo '<div class="mbh-filter-node">';

        if ($has_children) {
            printf(
                '<button type="button" class="mbh-fold-toggle" aria-expanded="%1$s" aria-controls="mbh-cat-%2$d" aria-label="%3$s"><span class="mbh-chevron" aria-hidden="true"></span></button>',
                $open ? 'true' : 'false',
                $id,
                /* translators: %s: category name */
                esc_attr(sprintf(__('Subcategories of %s', 'madebyhype-stockmanagment'), $name))
            );
        } else {
            echo '<span class="mbh-fold-spacer" aria-hidden="true"></span>';
        }

        $checkbox('category_filter[]', $id, $name, in_array($id, $request['category_filter'], true));

        if ($has_children) {
            printf('<div class="mbh-fold" id="mbh-cat-%d"%s>', $id, $open ? '' : ' hidden');
            $category_tree($id);
            echo '</div>';
        }

        echo '</div>';
    }
};

$number = function ($name, $value, $label, $step) use ($form) {
    printf(
        '<label class="mbh-filter-number"><span class="screen-reader-text">%1$s</span><input type="number" form="%2$s" name="%3$s" value="%4$s" min="0" step="%5$s" placeholder="%6$s"></label>',
        esc_html($label),
        esc_attr($form),
        esc_attr($name),
        esc_attr($value > 0 ? $value + 0 : ''),
        esc_attr($step),
        esc_attr(strpos($name, 'min_') === 0 ? __('Min', 'madebyhype-stockmanagment') : __('Max', 'madebyhype-stockmanagment'))
    );
};

$tags = $terms_of('product_tag');
$attributes = function_exists('wc_get_attribute_taxonomies') ? wc_get_attribute_taxonomies() : [];
?>
<aside id="mbh-filters" class="mbh-filters" aria-label="<?php esc_attr_e('Filters', 'madebyhype-stockmanagment'); ?>" hidden>
    <h2 class="mbh-filters-title"><?php esc_html_e('Filters', 'madebyhype-stockmanagment'); ?></h2>

    <?php if ($tab === 'all'): ?>
        <fieldset class="mbh-filter-section">
            <legend><?php esc_html_e('Stock status', 'madebyhype-stockmanagment'); ?></legend>
            <?php foreach ($this->stock_filter_labels() as $status => $label): ?>
                <?php $checkbox('stock_filter[]', $status, $label, in_array($status, $request['stock_filter'], true)); ?>
            <?php endforeach; ?>
        </fieldset>

        <fieldset class="mbh-filter-section">
            <legend><?php esc_html_e('Drafts', 'madebyhype-stockmanagment'); ?></legend>
            <?php $checkbox('include_drafts', 1, __('Include drafts', 'madebyhype-stockmanagment'), $request['include_drafts']); ?>
        </fieldset>
    <?php endif; ?>

    <?php if ($categories): ?>
        <fieldset class="mbh-filter-section">
            <legend><?php esc_html_e('Categories', 'madebyhype-stockmanagment'); ?></legend>
            <div class="mbh-filter-scroll">
                <?php $category_tree(0); ?>
            </div>
        </fieldset>
    <?php endif; ?>

    <?php if ($tags): ?>
        <fieldset class="mbh-filter-section">
            <legend><?php esc_html_e('Tags', 'madebyhype-stockmanagment'); ?></legend>
            <div class="mbh-filter-scroll">
                <?php foreach ($tags as $tag): ?>
                    <?php $checkbox('tag_filter[]', (int) $tag->term_id, html_entity_decode($tag->name, ENT_QUOTES | ENT_HTML5, 'UTF-8'), in_array((int) $tag->term_id, $request['tag_filter'], true)); ?>
                <?php endforeach; ?>
            </div>
        </fieldset>
    <?php endif; ?>

    <?php if ($attributes): ?>
        <fieldset class="mbh-filter-section">
            <legend><?php esc_html_e('Attributes', 'madebyhype-stockmanagment'); ?></legend>
            <label class="screen-reader-text" for="mbh-attribute-search"><?php esc_html_e('Search attribute values', 'madebyhype-stockmanagment'); ?></label>
            <input type="search" id="mbh-attribute-search" class="mbh-attribute-search" placeholder="<?php esc_attr_e('Search attribute values', 'madebyhype-stockmanagment'); ?>">
            <div class="mbh-filter-scroll">
                <?php foreach ($attributes as $attribute): ?>
                    <?php
                    $taxonomy = 'pa_' . $attribute->attribute_name;
                    $terms = $terms_of($taxonomy);
                    if (!$terms) {
                        continue;
                    }
                    $selected = isset($request['attribute_filter'][$taxonomy]) ? $request['attribute_filter'][$taxonomy] : [];
                    $fold_id = 'mbh-attr-' . sanitize_html_class($taxonomy);
                    ?>
                    <div class="mbh-attribute-group">
                        <button type="button" class="mbh-fold-toggle mbh-fold-toggle--label" aria-expanded="<?php echo $selected ? 'true' : 'false'; ?>" aria-controls="<?php echo esc_attr($fold_id); ?>">
                            <span class="mbh-chevron" aria-hidden="true"></span>
                            <?php echo esc_html($attribute->attribute_label); ?>
                            <?php if ($selected): ?>
                                <span class="mbh-filter-count">(<?php echo esc_html(count($selected)); ?>)</span>
                            <?php endif; ?>
                        </button>
                        <div class="mbh-fold" id="<?php echo esc_attr($fold_id); ?>"<?php echo $selected ? '' : ' hidden'; ?>>
                            <?php foreach ($terms as $term): ?>
                                <?php $checkbox('attribute_filter[' . $taxonomy . '][]', (int) $term->term_id, html_entity_decode($term->name, ENT_QUOTES | ENT_HTML5, 'UTF-8'), in_array((int) $term->term_id, $selected, true)); ?>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </fieldset>
    <?php endif; ?>

    <fieldset class="mbh-filter-section">
        <legend><?php esc_html_e('Price', 'madebyhype-stockmanagment'); ?></legend>
        <div class="mbh-filter-range">
            <?php $number('min_price', $request['min_price'], __('Lowest price', 'madebyhype-stockmanagment'), 'any'); ?>
            <?php $number('max_price', $request['max_price'], __('Highest price', 'madebyhype-stockmanagment'), 'any'); ?>
        </div>
    </fieldset>

    <fieldset class="mbh-filter-section">
        <legend><?php esc_html_e('Units sold in the period', 'madebyhype-stockmanagment'); ?></legend>
        <div class="mbh-filter-range">
            <?php $number('min_sales', $request['min_sales'], __('Fewest units sold', 'madebyhype-stockmanagment'), '1'); ?>
            <?php $number('max_sales', $request['max_sales'], __('Most units sold', 'madebyhype-stockmanagment'), '1'); ?>
        </div>
    </fieldset>

    <div class="mbh-filter-buttons">
        <button type="submit" form="<?php echo esc_attr($form); ?>" class="button button-primary"><?php esc_html_e('Apply filters', 'madebyhype-stockmanagment'); ?></button>
        <a href="<?php echo esc_url($this->clear_all_url()); ?>" class="button"><?php esc_html_e('Clear all', 'madebyhype-stockmanagment'); ?></a>
    </div>
</aside>
