<?php

/**
 * Legend Template
 */
?>
<div class="legend-container">
    <div class="legend-header">
        <svg fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
        </svg>
        <h3 class="legend-title"><?php _e('Legend', 'madebyhype-stockmanagment'); ?></h3>
    </div>

    <div class="legend-grid">
        <div class="legend-item">
            <div class="legend-dot instock"></div>
            <span class="legend-text"><?php _e('In Stock', 'madebyhype-stockmanagment'); ?></span>
        </div>

        <div class="legend-item">
            <div class="legend-dot outofstock"></div>
            <span class="legend-text"><?php _e('Out of Stock', 'madebyhype-stockmanagment'); ?></span>
        </div>

        <div class="legend-item">
            <div class="legend-dot backorder"></div>
            <span class="legend-text"><?php _e('On Backorder / Low Stock (≤10)', 'madebyhype-stockmanagment'); ?></span>
        </div>

        <div class="legend-item">
            <div class="legend-dot high-sales"></div>
            <span class="legend-text"><?php _e('High Sales (≥10)', 'madebyhype-stockmanagment'); ?></span>
        </div>

        <div class="legend-item">
            <div class="legend-dot medium-sales"></div>
            <span class="legend-text"><?php _e('Medium Sales (5-9)', 'madebyhype-stockmanagment'); ?></span>
        </div>

        <div class="legend-item">
            <div class="legend-dot low-sales"></div>
            <span class="legend-text"><?php _e('Low Sales (1-4)', 'madebyhype-stockmanagment'); ?></span>
        </div>
    </div>
</div>