<?php

/**
 * Pagination Template
 *
 * @param int $total_pages
 * @param int $current_page
 * @param int $per_page
 * @param string $start_date
 * @param string $end_date
 * @param string $sort_by
 * @param string $sort_order
 * @param int $total_count
 *
 * Note: Filter parameters are accessed via $_GET in helper functions
 */

// Helper function to build complete query args
function build_pagination_args($overrides = [])
{
    // Get current URL parameters
    $current_params = $_GET;

    // Default required parameters
    $defaults = [
        'post_type' => 'product',
        'page' => 'madebyhype-stockmanagment'
    ];

    // Merge current params with defaults, then apply overrides
    $args = wp_parse_args($overrides, wp_parse_args($current_params, $defaults));

    // Clean up empty values - remove empty strings, null, empty arrays, and zero values for numeric filters
    $args = array_filter($args, function ($value, $key) {
        // Always keep required parameters (even if empty)
        if (in_array($key, ['post_type', 'page'])) {
            return true;
        }

        // Remove various forms of empty values
        if ($value === '' || $value === null || $value === [] || $value === 0 || $value === '0' || $value === false) {
            return false;
        }

        // For arrays, check if they contain only empty values
        if (is_array($value)) {
            $filtered = array_filter($value, function ($item) {
                return $item !== '' && $item !== null && $item !== 0 && $item !== '0' && $item !== false;
            });
            return !empty($filtered);
        }

        // For strings, trim and check if still has content
        if (is_string($value)) {
            return trim($value) !== '';
        }

        return true;
    }, ARRAY_FILTER_USE_BOTH);

    return $args;
}
?>
<div class="pagination-container">
    <div class="pagination-info">
        <?php
        printf(
            __('Showing %1$s to %2$s of %3$s results', 'madebyhype-stockmanagment'),
            number_format(($current_page - 1) * $per_page + 1),
            number_format(min($current_page * $per_page, $total_count)),
            number_format($total_count)
        );
        ?>
    </div>

    <div class="pagination-controls">
        <?php
        // Previous page
        if ($current_page > 1):
            $prev_args = build_pagination_args(['paged' => $current_page - 1]);
            $prev_url = add_query_arg($prev_args, admin_url('edit.php'));
        ?>
            <a href="<?php echo esc_url($prev_url); ?>" class="pagination-button">‹</a>
        <?php else: ?>
            <span class="pagination-button disabled">‹</span>
        <?php endif; ?>

        <?php
        // Page numbers
        $start_page = max(1, $current_page - 2);
        $end_page = min($total_pages, $current_page + 2);

        if ($start_page > 1): ?>
            <?php $first_args = build_pagination_args(['paged' => 1]); ?>
            <a href="<?php echo esc_url(add_query_arg($first_args, admin_url('edit.php'))); ?>"
                class="pagination-button">1</a>
            <?php if ($start_page > 2): ?>
                <span class="pagination-ellipsis">…</span>
        <?php endif;
        endif; ?>

        <?php for ($i = $start_page; $i <= $end_page; $i++): ?>
            <?php if ($i == $current_page): ?>
                <span class="pagination-button active"><?php echo $i; ?></span>
            <?php else: ?>
                <?php $page_args = build_pagination_args(['paged' => $i]); ?>
                <a href="<?php echo esc_url(add_query_arg($page_args, admin_url('edit.php'))); ?>"
                    class="pagination-button"><?php echo $i; ?></a>
            <?php endif; ?>
        <?php endfor; ?>

        <?php if ($end_page < $total_pages): ?>
            <?php if ($end_page < $total_pages - 1): ?>
                <span class="pagination-ellipsis">…</span>
            <?php endif; ?>
            <?php $last_args = build_pagination_args(['paged' => $total_pages]); ?>
            <a href="<?php echo esc_url(add_query_arg($last_args, admin_url('edit.php'))); ?>"
                class="pagination-button"><?php echo $total_pages; ?></a>
        <?php endif; ?>

        <?php
        // Next page
        if ($current_page < $total_pages):
            $next_args = build_pagination_args(['paged' => $current_page + 1]);
            $next_url = add_query_arg($next_args, admin_url('edit.php'));
        ?>
            <a href="<?php echo esc_url($next_url); ?>" class="pagination-button">›</a>
        <?php else: ?>
            <span class="pagination-button disabled">›</span>
        <?php endif; ?>
    </div>
</div>