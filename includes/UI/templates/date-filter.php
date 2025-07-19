<?php

/**
 * Date Filter Form Template
 * 
 * @param string $start_date
 * @param string $end_date
 * @param bool $filter_applied
 */
?>
<div class="date-filter-container">
    <div class="date-filter-header">
        <div>
            <h3 class="date-filter-title">Filter Sales Data</h3>
            <p class="date-filter-subtitle">Select a date range to filter sales data</p>
        </div>
        <?php if ($filter_applied): ?>
            <div class="date-filter-badge">
                <svg fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                        d="M3 4a1 1 0 011-1h16a1 1 0 011 1v2.586a1 1 0 01-.293.707l-6.414 6.414a1 1 0 00-.293.707V17l-4 4v-6.586a1 1 0 00-.293-.707L3.293 7.293A1 1 0 013 6.586V4z">
                    </path>
                </svg>
                <span class="date-filter-badge-text">
                    <?php echo esc_html(date('M j, Y', strtotime($start_date))); ?> -
                    <?php echo esc_html(date('M j, Y', strtotime($end_date))); ?>
                </span>
            </div>
        <?php endif; ?>
    </div>

    <form method="get" action="" class="date-filter-form">
        <input type="hidden" name="page" value="omer-stockmanagment">
        <input type="hidden" id="start_date" name="start_date" value="<?php echo esc_attr($start_date); ?>" />
        <input type="hidden" id="end_date" name="end_date" value="<?php echo esc_attr($end_date); ?>" />

        <div class="date-filter-input-group">
            <div class="date-filter-input-wrapper">
                <label for="date-range" class="date-filter-label">Date Range</label>

                <!-- Preset Buttons -->
                <div class="date-filter-presets">
                    <button type="button" class="date-filter-preset-btn" data-days="30" data-label="1 Month">
                        1 Month
                    </button>
                    <button type="button" class="date-filter-preset-btn" data-days="90" data-label="3 Months">
                        3 Months
                    </button>
                    <button type="button" class="date-filter-preset-btn" data-days="180" data-label="6 Months">
                        6 Months
                    </button>
                    <button type="button" class="date-filter-preset-btn" data-days="365" data-label="1 Year">
                        1 Year
                    </button>
                </div>

                <div class="date-filter-input-row">
                    <input type="text" id="date-range" placeholder="Select date range..." class="date-filter-input" />
                    <div class="date-filter-button-group">
                        <button type="submit" name="apply_filter" class="date-filter-apply-btn">
                            Apply Filter
                        </button>
                        <?php if ($filter_applied): ?>
                            <a href="?page=omer-stockmanagment" class="date-filter-clear-btn">
                                Clear Filter
                            </a>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </form>
</div>