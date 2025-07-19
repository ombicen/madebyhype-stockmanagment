<?php

/**
 * Footer Info Template
 * 
 * @param int $total_count
 * @param int $products_count
 */
?>
<div class="footer-info-container">
    <p class="footer-info-text">
        <strong><?php _e('Total Products:', 'madebyhype-stockmanagment'); ?></strong> <?php echo number_format($total_count); ?> (<?php printf(__('showing %s on this page', 'madebyhype-stockmanagment'), $products_count); ?>)
    </p>
</div>