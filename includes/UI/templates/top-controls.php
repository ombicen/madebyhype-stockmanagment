<?php

/**
 * Top Controls Template
 * 
 * @param int $per_page
 */
?>
<div class="top-controls-container">
    <div class="top-controls-left">
        <button id="toggle-sidebar" class="top-controls-toggle-btn">
            <span id="sidebar-toggle-text">☰ <?php _e('Filters', 'madebyhype-stockmanagment'); ?></span>
        </button>
        <span class="top-controls-help-text"><?php _e('Use filters to narrow down products', 'madebyhype-stockmanagment'); ?></span>
    </div>
    <div class="top-controls-right">
        <label for="per_page" class="top-controls-label"><?php _e('Items per page:', 'madebyhype-stockmanagment'); ?></label>
        <select id="per_page" onchange="changePerPage(this.value)" class="top-controls-select">
            <option value="20" <?php echo ($per_page == 20) ? 'selected' : ''; ?>>20</option>
            <option value="50" <?php echo ($per_page == 50) ? 'selected' : ''; ?>>50</option>
            <option value="100" <?php echo ($per_page == 100) ? 'selected' : ''; ?>>100</option>
            <option value="500" <?php echo ($per_page == 500) ? 'selected' : ''; ?>>500</option>
        </select>
    </div>
</div>