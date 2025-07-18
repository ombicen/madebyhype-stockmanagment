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
        <strong>Total Products:</strong> <?php echo number_format($total_count); ?> (showing
        <?php echo $products_count; ?> on this page)
    </p>
</div>