<?php

/**
 * Save bar: what is unsaved, why Save is off when it is, the last save with its undo, Discard and Save
 *
 * It floats at the bottom of the page and is hidden until the script has
 * something to show: unsaved changes, a save in progress, or the save just made.
 * The script fills in the counts; the buttons start disabled.
 *
 * @var array $caps
 */

if (! defined('ABSPATH')) {
    exit;
}
?>
<div class="mbh-save-bar" id="mbh-save-bar" role="region" aria-label="<?php esc_attr_e('Unsaved changes and saving', 'madebyhype-stockmanagment'); ?>" hidden>
    <span class="mbh-save-summary">
        <span id="mbh-save-status" class="mbh-save-status" role="status"><?php esc_html_e('No unsaved changes', 'madebyhype-stockmanagment'); ?></span>

        <?php // Why Save is off ?>
        <span id="mbh-save-invalid" class="mbh-save-reason mbh-save-invalid" hidden>
            <?php echo self::icon('warning'); ?>
            <span id="mbh-save-invalid-text"></span>
            <button type="button" class="mbh-text-button"><?php esc_html_e('Show', 'madebyhype-stockmanagment'); ?></button>
        </span>
        <span id="mbh-save-conflicts" class="mbh-save-reason mbh-save-conflicts" hidden>
            <?php echo self::icon('warning'); ?>
            <span id="mbh-save-conflicts-text"></span>
        </span>
    </span>

    <span id="mbh-last-save" class="mbh-last-save" hidden>
        <span id="mbh-last-save-text"></span>
        <span id="mbh-last-save-state"></span>
        <?php if (!empty($caps['undo'])): ?>
            <button type="button" class="mbh-text-button" id="mbh-undo-last" aria-describedby="mbh-undo-hint" hidden><?php esc_html_e('Undo…', 'madebyhype-stockmanagment'); ?></button>
            <span id="mbh-undo-hint" class="mbh-undo-hint" hidden><?php esc_html_e('Save or discard your changes first.', 'madebyhype-stockmanagment'); ?></span>
        <?php endif; ?>
    </span>

    <span class="mbh-save-actions">
        <button type="button" class="button" id="mbh-discard-button" disabled><?php esc_html_e('Discard changes', 'madebyhype-stockmanagment'); ?></button>
        <button type="button" class="button button-primary" id="mbh-save-button" aria-describedby="mbh-save-invalid-text mbh-save-conflicts-text" disabled><?php esc_html_e('Save', 'madebyhype-stockmanagment'); ?></button>
    </span>
</div>
