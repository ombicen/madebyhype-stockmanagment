<?php

/**
 * Version History Template
 * 
 * @param array $versions
 */
?>
<div class="version-history-container">
    <div class="version-history-header">
        <h3><?php _e('Version History', 'madebyhype-stockmanagment'); ?></h3>
        <span class="version-history-subtitle"><?php _e('Recent changes that can be reverted', 'madebyhype-stockmanagment'); ?></span>
    </div>

    <?php if (empty($versions)): ?>
        <div class="version-history-empty">
            <div class="version-history-empty-icon">📝</div>
            <p><?php _e('No version history yet. Changes will appear here after saving.', 'madebyhype-stockmanagment'); ?></p>
        </div>
    <?php else: ?>
        <div class="version-history-list">
            <?php foreach ($versions as $version): ?>
                <div class="version-item" data-version="<?php echo esc_attr($version['version_number']); ?>">
                    <div class="version-info">
                        <div class="version-header">
                            <span class="version-number"><?php printf(__('Version %s', 'madebyhype-stockmanagment'), esc_html($version['version_number'])); ?></span>
                            <span class="version-date"><?php echo esc_html(date('M j, Y g:i A', strtotime($version['created_at']))); ?></span>
                        </div>
                        <div class="version-summary">
                            <?php echo esc_html($version['description'] ?: __('Stock and price updates', 'madebyhype-stockmanagment')); ?>
                        </div>
                        <div class="version-details">
                            <?php
                            $summary = $version_manager_instance->get_version_summary($version);
                            echo esc_html($summary);
                            ?>
                        </div>
                    </div>
                    <div class="version-actions">
                        <button type="button" class="version-revert-btn" data-version="<?php echo esc_attr($version['version_number']); ?>">
                            <?php _e('Revert', 'madebyhype-stockmanagment'); ?>
                        </button>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>