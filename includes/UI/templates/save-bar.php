<?php

/**
 * Save bar: what is unsaved, the last save with its undo, Discard and Save
 *
 * The script fills in the counts; the buttons start disabled.
 *
 * @var array $caps
 */

if (! defined('ABSPATH')) {
    exit;
}
?>
<div class="mbh-save-bar" id="mbh-save-bar" role="region" aria-label="<?php esc_attr_e('Unsaved changes and saving', 'madebyhype-stockmanagment'); ?>">
    <div class="mbh-save-info">
        <span id="mbh-save-status" class="mbh-save-status" role="status"><?php esc_html_e('No unsaved changes', 'madebyhype-stockmanagment'); ?></span>
        <span id="mbh-save-invalid" class="mbh-save-invalid" hidden>
            <span id="mbh-save-invalid-text"></span>
            <button type="button" class="button-link"><?php esc_html_e('Show', 'madebyhype-stockmanagment'); ?></button>
        </span>
        <span id="mbh-save-conflicts" class="mbh-save-conflicts" hidden></span>
        <span id="mbh-last-save" class="mbh-last-save" hidden>
            <span id="mbh-last-save-text"></span>
            <?php if (!empty($caps['undo'])): ?>
                <button type="button" class="button-link" id="mbh-undo-last" aria-describedby="mbh-undo-hint" hidden><?php esc_html_e('Undo…', 'madebyhype-stockmanagment'); ?></button>
                <span id="mbh-undo-hint" class="mbh-undo-hint" hidden><?php esc_html_e('Save or discard your changes first.', 'madebyhype-stockmanagment'); ?></span>
            <?php endif; ?>
        </span>
    </div>
    <div class="mbh-save-actions">
        <button type="button" class="button" id="mbh-discard-button" disabled><?php esc_html_e('Discard changes', 'madebyhype-stockmanagment'); ?></button>
        <button type="button" class="button button-primary" id="mbh-save-button" disabled><?php esc_html_e('Save', 'madebyhype-stockmanagment'); ?></button>
    </div>
</div>
