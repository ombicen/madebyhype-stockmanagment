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
 */
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
            $prev_url = add_query_arg([
                'page' => 'madebyhype-stockmanagment',
                'paged' => $current_page - 1,
                'per_page' => $per_page,
                'start_date' => $start_date,
                'end_date' => $end_date,
                'sort_by' => $sort_by,
                'sort_order' => $sort_order
            ]);
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
            <a href="<?php echo esc_url(add_query_arg(['paged' => 1, 'per_page' => $per_page, 'start_date' => $start_date, 'end_date' => $end_date, 'sort_by' => $sort_by, 'sort_order' => $sort_order])); ?>"
                class="pagination-button">1</a>
            <?php if ($start_page > 2): ?>
                <span class="pagination-ellipsis">…</span>
        <?php endif;
        endif; ?>

        <?php for ($i = $start_page; $i <= $end_page; $i++): ?>
            <?php if ($i == $current_page): ?>
                <span class="pagination-button active"><?php echo $i; ?></span>
            <?php else: ?>
                <a href="<?php echo esc_url(add_query_arg(['paged' => $i, 'per_page' => $per_page, 'start_date' => $start_date, 'end_date' => $end_date, 'sort_by' => $sort_by, 'sort_order' => $sort_order])); ?>"
                    class="pagination-button"><?php echo $i; ?></a>
            <?php endif; ?>
        <?php endfor; ?>

        <?php if ($end_page < $total_pages): ?>
            <?php if ($end_page < $total_pages - 1): ?>
                <span class="pagination-ellipsis">…</span>
            <?php endif; ?>
            <a href="<?php echo esc_url(add_query_arg(['paged' => $total_pages, 'per_page' => $per_page, 'start_date' => $start_date, 'end_date' => $end_date, 'sort_by' => $sort_by, 'sort_order' => $sort_order])); ?>"
                class="pagination-button"><?php echo $total_pages; ?></a>
        <?php endif; ?>

        <?php
        // Next page
        if ($current_page < $total_pages):
            $next_url = add_query_arg([
                'page' => 'madebyhype-stockmanagment',
                'paged' => $current_page + 1,
                'per_page' => $per_page,
                'start_date' => $start_date,
                'end_date' => $end_date,
                'sort_by' => $sort_by,
                'sort_order' => $sort_order
            ]);
        ?>
            <a href="<?php echo esc_url($next_url); ?>" class="pagination-button">›</a>
        <?php else: ?>
            <span class="pagination-button disabled">›</span>
        <?php endif; ?>
    </div>
</div>