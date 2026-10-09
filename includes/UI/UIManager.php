<?php

namespace MadeByHypeStockmanagment\UI;

use MadeByHypeStockmanagment\Admin\AdminPage;
use MadeByHypeStockmanagment\Data\DataManager;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Renders the stock screen: tabs, toolbar, filters, the grid and the History shell.
 *
 * The rows of the list are printed here from DataManager::get_list() rows.
 * Rows that arrive later (variations) and every redraw after a save are built
 * by scripts/stock-grid.js, which must produce the same markup as
 * templates/row.php. Both take their rules from the same two places:
 * cell_kinds() / format_price() here and their twins in scripts/stock-model.js,
 * and both take their words from strings().
 */
class UIManager
{
    const COLUMNS = 7;

    /** @var array Parsed request, see AdminPage::parse_request() */
    private $request = [];

    /** @var array|null Result of DataManager::get_list(); null on the History tab */
    private $result = null;

    /** @var array Sales period, see DataManager::resolve_period() */
    private $period = [];

    /** @var array What the current user may do, see AdminPage::permissions() */
    private $caps = [];

    /** @var array Cached strings() */
    private static $strings = null;

    public function init()
    {
        // Initialize UI manager
    }

    /**
     * Print the screen
     *
     * @param array $context {
     *     request: AdminPage::parse_request(), result: DataManager::get_list() or null (History),
     *     period: DataManager::resolve_period(), caps: AdminPage::permissions(),
     *     history_item: null | ['id', 'name', 'sku'] (History narrowed to one item),
     *     attention_count: null | int (the number on the Needs attention tab)
     * }
     */
    public function render_admin_page($context)
    {
        $this->request = $context['request'];
        $this->result = $context['result'];
        $this->period = $context['period'];
        $this->caps = $context['caps'];

        $request = $this->request;
        $result = $this->result;
        $period = $this->period;
        $caps = $this->caps;
        $history_item = isset($context['history_item']) ? $context['history_item'] : null;
        $attention_count = isset($context['attention_count']) ? $context['attention_count'] : null;
        $tab = $request['tab'];
        $view = $result ? $result['view'] : $request['view'];

        include __DIR__ . '/templates/page.php';
    }

    /* ---------------------------------------------------------------------
     * Words: one list for the templates and the scripts
     * ------------------------------------------------------------------- */

    /**
     * Every string the scripts show, and the ones the row template shares
     * with them. A counted string is an _n_noop() entry: t() and tn() pick
     * its form here, script_strings() hands the scripts every form.
     *
     * @return array key => string | _n_noop() entry | map of strings
     */
    public static function strings()
    {
        if (self::$strings !== null) {
            return self::$strings;
        }


        self::$strings = [
            // Names
            'fields' => [
                'stock_quantity' => __('Stock', 'madebyhype-stockmanagment'),
                'stock_status' => __('Stock status', 'madebyhype-stockmanagment'),
                'regular_price' => __('Regular price', 'madebyhype-stockmanagment'),
                'sale_price' => __('Sale price', 'madebyhype-stockmanagment'),
                'start_tracking' => __('Stock tracking', 'madebyhype-stockmanagment'),
            ],
            'statuses' => [
                'instock' => __('In stock', 'madebyhype-stockmanagment'),
                'outofstock' => __('Out of stock', 'madebyhype-stockmanagment'),
                'onbackorder' => __('On backorder', 'madebyhype-stockmanagment'),
                'lowstock' => __('Low stock', 'madebyhype-stockmanagment'),
            ],
            'types' => [
                'simple' => __('Simple', 'madebyhype-stockmanagment'),
                'variable' => __('Variable', 'madebyhype-stockmanagment'),
                'variation' => __('Variation', 'madebyhype-stockmanagment'),
                'grouped' => __('Grouped', 'madebyhype-stockmanagment'),
                'external' => __('External', 'madebyhype-stockmanagment'),
            ],
            'postStatuses' => [
                'draft' => __('Draft', 'madebyhype-stockmanagment'),
                'pending' => __('Pending', 'madebyhype-stockmanagment'),
                'private' => __('Private', 'madebyhype-stockmanagment'),
                'future' => __('Scheduled', 'madebyhype-stockmanagment'),
            ],

            // Rows
            /* translators: 1: field name, 2: product */
            'cellLabel' => __('%1$s, %2$s', 'madebyhype-stockmanagment'),
            /* translators: 1: product name, 2: SKU */
            'itemWithSku' => __('%1$s, SKU %2$s', 'madebyhype-stockmanagment'),
            /* translators: 1: product name, 2: attribute values of a variation */
            'nameWithAttributes' => __('%1$s — %2$s', 'madebyhype-stockmanagment'),
            'usesProductStock' => __('Uses product stock', 'madebyhype-stockmanagment'),
            'inVariations' => _n_noop('in %d variation', 'in %d variations', 'madebyhype-stockmanagment'),
            'notTrackedCount' => _n_noop('%d not tracked', '%d not tracked', 'madebyhype-stockmanagment'),
            'notTracked' => __('Not tracked', 'madebyhype-stockmanagment'),
            'startTracking' => __('Start tracking', 'madebyhype-stockmanagment'),
            /* translators: %s: low-stock threshold */
            'threshold' => __('threshold %s', 'madebyhype-stockmanagment'),
            /* translators: 1: variations out of stock, 2: all variations */
            'outOf' => __('%1$s of %2$s out', 'madebyhype-stockmanagment'),
            /* translators: 1: variations on sale, 2: all variations */
            'onSaleOf' => __('%1$s of %2$s on sale', 'madebyhype-stockmanagment'),
            /* translators: 1: lowest price, 2: highest price */
            'range' => __('%1$s – %2$s', 'madebyhype-stockmanagment'),
            'noSale' => __('No sale', 'madebyhype-stockmanagment'),
            /* translators: 1: first day, 2: last day */
            'scheduled' => __('Scheduled %1$s – %2$s', 'madebyhype-stockmanagment'),
            /* translators: %s: first day */
            'scheduledFrom' => __('Scheduled from %s', 'madebyhype-stockmanagment'),
            /* translators: %s: last day */
            'scheduledUntil' => __('Scheduled until %s', 'madebyhype-stockmanagment'),
            'variationDisabled' => __('Disabled', 'madebyhype-stockmanagment'),
            /* translators: %s: product or variation id */
            'idNumber' => __('#%s', 'madebyhype-stockmanagment'),
            /* translators: 1: product type, for example "Variable", 2: number of variations */
            'typeWithCount' => __('%1$s · %2$s', 'madebyhype-stockmanagment'),
            'variationCount' => _n_noop('%d variation', '%d variations', 'madebyhype-stockmanagment'),
            'editProduct' => __('Edit product', 'madebyhype-stockmanagment'),
            /* translators: %s: product name */
            'editProductOf' => __('Edit product: %s (opens in a new tab)', 'madebyhype-stockmanagment'),
            'history' => __('History', 'madebyhype-stockmanagment'),
            /* translators: %s: product name */
            'historyOf' => __('History of %s', 'madebyhype-stockmanagment'),
            /* translators: %s: product name */
            'variationsOf' => __('Variations of %s', 'madebyhype-stockmanagment'),
            'coverDays' => _n_noop('%d day', '%d days', 'madebyhype-stockmanagment'),
            'staleFigure' => __('Recalculated when the page is reloaded', 'madebyhype-stockmanagment'),
            'match' => __('Match', 'madebyhype-stockmanagment'),
            'editedCount' => _n_noop('%d edited', '%d edited', 'madebyhype-stockmanagment'),
            'notSavedCount' => _n_noop('%d not saved', '%d not saved', 'madebyhype-stockmanagment'),

            // Cell states
            /* translators: %s: the value before the edit */
            'was' => __('was %s', 'madebyhype-stockmanagment'),
            /* translators: 1: stock before the edit, 2: the difference, with its sign */
            'wasStock' => __('was %1$s (%2$s)', 'madebyhype-stockmanagment'),
            'wasEmpty' => __('was empty', 'madebyhype-stockmanagment'),
            'wasNoSale' => __('was not on sale', 'madebyhype-stockmanagment'),
            /* translators: %s: the sale price before the edit */
            'wasSaleEnds' => __('was %s · sale ends on save', 'madebyhype-stockmanagment'),
            'trackingStartsOnSave' => __('Tracking starts on save', 'madebyhype-stockmanagment'),
            'cancelTracking' => __('Cancel tracking', 'madebyhype-stockmanagment'),
            'statusFromQuantity' => __('Set from quantity on save', 'madebyhype-stockmanagment'),
            'notSavedShort' => __('Not saved', 'madebyhype-stockmanagment'),
            'savedMark' => __('Saved', 'madebyhype-stockmanagment'),
            'adjustedMark' => __('Adjusted', 'madebyhype-stockmanagment'),
            'droppedMark' => __('Not applied', 'madebyhype-stockmanagment'),
            'errQuantity' => __('Enter a whole number, 0 or more.', 'madebyhype-stockmanagment'),
            'errPrice' => __('Enter a price of 0 or more.', 'madebyhype-stockmanagment'),
            'errRegularRequired' => __('Enter a regular price.', 'madebyhype-stockmanagment'),
            /* translators: %s: regular price */
            'errSaleNotLower' => __('Sale price must be lower than the regular price (%s).', 'madebyhype-stockmanagment'),
            'noResult' => __('Not saved. The server gave no answer for this change.', 'madebyhype-stockmanagment'),
            /* translators: %s: the value the user typed */
            'setAnyway' => __('Set to %s anyway', 'madebyhype-stockmanagment'),
            /* translators: %s: the value stored now */
            'leaveAt' => __('Leave at %s', 'madebyhype-stockmanagment'),
            'empty' => __('empty', 'madebyhype-stockmanagment'),
            'dismiss' => __('Dismiss', 'madebyhype-stockmanagment'),
            /* translators: %s: field name, before the message about that field */
            'fieldPrefix' => __('%s:', 'madebyhype-stockmanagment'),
            /* translators: 1: what a message is about, for example "Stock, Gold ring, SKU R-52", 2: the message */
            'announceAbout' => __('%1$s: %2$s', 'madebyhype-stockmanagment'),
            /* translators: 1: field and product, for example "Stock, Gold ring, SKU R-52", 2: the stored value */
            'cellRestored' => __('%1$s: restored to %2$s.', 'madebyhype-stockmanagment'),
            /* translators: %s: field and product, for example "Stock, Gold ring, SKU R-52" */
            'trackingCancelled' => __('%s: tracking will not start.', 'madebyhype-stockmanagment'),
            /* translators: %d: number of messages that are not read out */
            'moreMessages' => _n_noop('%d more message is shown under its row.', '%d more messages are shown under their rows.', 'madebyhype-stockmanagment'),
            'dismissNotice' => __('Dismiss this notice.', 'madebyhype-stockmanagment'),

            // Start tracking
            'startingQuantity' => __('Starting quantity', 'madebyhype-stockmanagment'),
            'startHelp' => __('After you save, customers can buy only this quantity online, and it goes down with each order.', 'madebyhype-stockmanagment'),
            'startZeroNote' => __('At 0 it shows as out of stock online.', 'madebyhype-stockmanagment'),
            'addToChanges' => __('Add to changes', 'madebyhype-stockmanagment'),

            // Variations
            'loadingVariations' => _n_noop('Loading %d variation…', 'Loading %d variations…', 'madebyhype-stockmanagment'),
            'variationsFailed' => __('The variations could not be loaded.', 'madebyhype-stockmanagment'),
            'variationsSession' => __('The variations could not be loaded because your session has expired. Log in again in another browser tab, then try again.', 'madebyhype-stockmanagment'),
            'tryAgain' => __('Try again', 'madebyhype-stockmanagment'),
            /* translators: %d: number of variations */
            'variationsShown' => _n_noop('%d variation shown.', '%d variations shown.', 'madebyhype-stockmanagment'),
            'allCollapsed' => __('All products collapsed.', 'madebyhype-stockmanagment'),

            // Save bar
            'noUnsaved' => __('No unsaved changes', 'madebyhype-stockmanagment'),
            /* translators: %d: number of unsaved changes, all in one row of the table */
            'unsavedInOneRow' => _n_noop('%d unsaved change in 1 row', '%d unsaved changes in 1 row', 'madebyhype-stockmanagment'),
            /* translators: 1: number of unsaved changes, 2: number of rows they are in (2 or more) */
            'unsavedInRows' => _n_noop('%1$d unsaved change in %2$d rows', '%1$d unsaved changes in %2$d rows', 'madebyhype-stockmanagment'),
            'invalidCount' => _n_noop('%d invalid; fix to save', '%d invalid; fix to save', 'madebyhype-stockmanagment'),
            'conflictCount' => _n_noop('%d waiting for your choice', '%d waiting for your choice', 'madebyhype-stockmanagment'),
            'save' => __('Save', 'madebyhype-stockmanagment'),
            'saveChanges' => _n_noop('Save %d change', 'Save %d changes', 'madebyhype-stockmanagment'),
            'saving' => __('Saving…', 'madebyhype-stockmanagment'),
            /* translators: 1: changes sent so far, 2: changes in this save */
            'savingProgress' => __('Saving… %1$s of %2$s', 'madebyhype-stockmanagment'),
            'discard' => __('Discard changes', 'madebyhype-stockmanagment'),
            'discardTitle' => _n_noop('Discard %d unsaved change?', 'Discard %d unsaved changes?', 'madebyhype-stockmanagment'),
            'discardBody' => __('The cells go back to the values that are stored now.', 'madebyhype-stockmanagment'),
            'keepEditing' => __('Keep editing', 'madebyhype-stockmanagment'),
            'discarded' => __('Changes discarded.', 'madebyhype-stockmanagment'),
            /* translators: 1: number of changes, 2: date and time */
            'lastSave' => _n_noop('Your last save: %2$s, %1$d change', 'Your last save: %2$s, %1$d changes', 'madebyhype-stockmanagment'),
            'undo' => __('Undo…', 'madebyhype-stockmanagment'),
            'undoRest' => __('Undo the rest…', 'madebyhype-stockmanagment'),

            // Save results
            'noticeSaved' => _n_noop('Saved %d change.', 'Saved %d changes.', 'madebyhype-stockmanagment'),
            'noticeAdjusted' => _n_noop('%d adjusted for stock that changed while you were editing.', '%d adjusted for stock that changed while you were editing.', 'madebyhype-stockmanagment'),
            /* translators: 1: changes saved, 2: changes in the save, 3: changes not saved */
            'noticePartial' => __('Saved %1$s of %2$s changes. %3$s not saved; they are still marked in the table.', 'madebyhype-stockmanagment'),
            'showFirst' => __('Show the first one', 'madebyhype-stockmanagment'),
            'noticeNothing' => _n_noop('Nothing was saved. %d change is still marked in the table.', 'Nothing was saved. %d changes are still marked in the table.', 'madebyhype-stockmanagment'),
            'noticeLogFailed' => __('Nothing was saved. The change history could not be written, so no product was changed.', 'madebyhype-stockmanagment'),
            /* translators: 1: changes saved, 2: changes in the save, 3: changes not sent */
            'noticeConnection' => __('Saved %1$s of %2$s changes. The connection was lost; the other %3$s are still marked in the table. Press Save to try again.', 'madebyhype-stockmanagment'),
            'noticeConnectionNone' => __('The connection was lost before the save was confirmed. Your changes are still marked in the table. Press Save to try again; no change is applied twice.', 'madebyhype-stockmanagment'),
            'noticeSession' => __('Your session has expired. Your changes are still on this page. Log in again in another browser tab, then press Save here.', 'madebyhype-stockmanagment'),
            /* translators: %s: the server's reason */
            'noticeRefused' => __('The save was refused: %s Your changes are still marked in the table.', 'madebyhype-stockmanagment'),
            'noticeRefusedGeneric' => __('The save was refused by the server. Your changes are still marked in the table.', 'madebyhype-stockmanagment'),

            // Check before saving
            'checkTitle' => __('Check before saving', 'madebyhype-stockmanagment'),
            'checkIntro' => __('This save contains changes that are easy to make by mistake:', 'madebyhype-stockmanagment'),
            /* translators: 1: product name, 2: SKU, 3: field name */
            'checkZero' => __('Price set to 0 (the product becomes free): %1$s (%2$s), %3$s', 'madebyhype-stockmanagment'),
            /* translators: 1: product name, 2: SKU, 3: field name, 4: old price, 5: new price, 6: change in percent with its sign, for example +150; %% prints a percent sign */
            'checkLarge' => __('Large price change: %1$s (%2$s), %3$s %4$s → %5$s (%6$s%%)', 'madebyhype-stockmanagment'),
            /* translators: 1: product name, 2: SKU, 3: starting quantity */
            'checkTracking' => __('Start tracking stock: %1$s (%2$s) at %3$s', 'madebyhype-stockmanagment'),
            'goBack' => __('Go back', 'madebyhype-stockmanagment'),

            // Leaving
            'leaveTitle' => _n_noop('You have %d unsaved change', 'You have %d unsaved changes', 'madebyhype-stockmanagment'),
            'leaveBody' => __('They are lost if you continue without saving.', 'madebyhype-stockmanagment'),
            'leaveSave' => __('Save and continue', 'madebyhype-stockmanagment'),
            'cancel' => __('Cancel', 'madebyhype-stockmanagment'),
            'close' => __('Close', 'madebyhype-stockmanagment'),

            // Undo
            /* translators: %s: save number */
            'undoTitle' => __('Undo save #%s?', 'madebyhype-stockmanagment'),
            'undoChecking' => __('Checking what can be undone…', 'madebyhype-stockmanagment'),
            /* translators: 1: user, 2: date and time */
            'undoSavedBy' => __('Saved by %1$s on %2$s.', 'madebyhype-stockmanagment'),
            /* translators: %s: number of changes */
            'willBeUndone' => __('Will be undone (%s)', 'madebyhype-stockmanagment'),
            /* translators: %s: number of changes */
            'willBeSkipped' => __('Will be skipped (%s)', 'madebyhype-stockmanagment'),
            /* translators: %s: number of changes */
            'skippedHeading' => __('Skipped (%s)', 'madebyhype-stockmanagment'),
            /* translators: 1: value now, 2: value after the undo */
            'nowAfter' => __('%1$s → %2$s', 'madebyhype-stockmanagment'),
            'undoFooter' => __('Only the fields this save changed are touched. The undo is recorded in History.', 'madebyhype-stockmanagment'),
            'undoConfirm' => _n_noop('Undo %d change', 'Undo %d changes', 'madebyhype-stockmanagment'),
            'undoing' => __('Undoing…', 'madebyhype-stockmanagment'),
            'undoNothing' => __('Nothing in this save can be undone now.', 'madebyhype-stockmanagment'),
            'undoCheckFailed' => __('Could not check what can be undone. Nothing was changed.', 'madebyhype-stockmanagment'),
            'undoUncertain' => __('The undo could not be completed. Open History to see what was undone before it stopped.', 'madebyhype-stockmanagment'),
            'undoSession' => __('Your session has expired. Log in again in another browser tab, then try again.', 'madebyhype-stockmanagment'),
            'unknownUser' => __('(deleted user)', 'madebyhype-stockmanagment'),

            // History
            'historyLoading' => __('Loading…', 'madebyhype-stockmanagment'),
            'historyFailed' => __('History could not be loaded.', 'madebyhype-stockmanagment'),
            'historySession' => __('History could not be loaded because your session has expired. Log in again in another browser tab, then try again.', 'madebyhype-stockmanagment'),
            'historyEmpty' => __('No saves yet. Every save made in this tool is listed here for 12 months.', 'madebyhype-stockmanagment'),
            'historyNoMatch' => __('No saves match this search.', 'madebyhype-stockmanagment'),
            'historyItemEmpty' => __('No changes to this item have been made in this tool in the last 12 months.', 'madebyhype-stockmanagment'),
            'colSave' => __('Save', 'madebyhype-stockmanagment'),
            'colWhen' => __('When', 'madebyhype-stockmanagment'),
            'colWho' => __('Who', 'madebyhype-stockmanagment'),
            'colSummary' => __('Summary', 'madebyhype-stockmanagment'),
            'colState' => __('State', 'madebyhype-stockmanagment'),
            'colActions' => __('Actions', 'madebyhype-stockmanagment'),
            'colProduct' => __('Product', 'madebyhype-stockmanagment'),
            'colSku' => __('SKU', 'madebyhype-stockmanagment'),
            'colField' => __('Field', 'madebyhype-stockmanagment'),
            'colBefore' => __('Before', 'madebyhype-stockmanagment'),
            'colAfter' => __('After', 'madebyhype-stockmanagment'),
            'colNote' => __('Note', 'madebyhype-stockmanagment'),
            'colReason' => __('Reason', 'madebyhype-stockmanagment'),
            'colNowAfter' => __('Now → After undo', 'madebyhype-stockmanagment'),
            /* translators: %d: number of changes, all to one SKU */
            'changesOnOneSku' => _n_noop('%d change on 1 SKU', '%d changes on 1 SKU', 'madebyhype-stockmanagment'),
            /* translators: 1: number of changes, 2: number of SKUs they were made to (2 or more) */
            'changesOnSkus' => _n_noop('%1$d change on %2$d SKUs', '%1$d changes on %2$d SKUs', 'madebyhype-stockmanagment'),
            /* translators: 1: a count of changes, for example "3 changes on 2 SKUs", 2: what kinds they were, for example "2 stock, 1 price" */
            'summaryWithKinds' => __('%1$s: %2$s', 'madebyhype-stockmanagment'),
            /* translators: between the items of a list, for example "2 stock, 1 price" */
            'listSeparator' => __(', ', 'madebyhype-stockmanagment'),
            /* translators: %d: number of stock changes, in a list such as "2 stock, 1 price" */
            'countStock' => _n_noop('%d stock', '%d stock', 'madebyhype-stockmanagment'),
            /* translators: %d: number of price changes, in a list such as "2 stock, 1 price" */
            'countPrice' => _n_noop('%d price', '%d price', 'madebyhype-stockmanagment'),
            /* translators: %d: number of other changes, in a list such as "2 stock, 1 other" */
            'countOther' => _n_noop('%d other', '%d other', 'madebyhype-stockmanagment'),
            'noChangesStored' => __('No changes were stored', 'madebyhype-stockmanagment'),
            /* translators: 1: save number, 2: a count of changes, for example "3 changes on 2 SKUs: 2 stock, 1 price" */
            'undoOfSummary' => __('Undo of #%1$s · %2$s', 'madebyhype-stockmanagment'),
            /* translators: 1: user, 2: save number */
            'whoUndo' => __('%1$s (undo of #%2$s)', 'madebyhype-stockmanagment'),
            /* translators: %d: user id of an account that no longer exists */
            'userNumber' => __('User #%d', 'madebyhype-stockmanagment'),
            /* translators: 1: user, 2: date and time */
            'undoneBy' => __('Undone by %1$s, %2$s', 'madebyhype-stockmanagment'),
            'undone' => __('Undone', 'madebyhype-stockmanagment'),
            /* translators: 1: changes undone, 2: changes in the save */
            'partlyUndone' => __('Partly undone: %1$s of %2$s', 'madebyhype-stockmanagment'),
            'viewChanges' => __('View changes', 'madebyhype-stockmanagment'),
            /* translators: %s: save number */
            'viewChangesOf' => __('View changes of save #%s', 'madebyhype-stockmanagment'),
            /* translators: %s: save number */
            'undoSaveLabel' => __('Undo save #%s…', 'madebyhype-stockmanagment'),
            'undoneSince' => __('Undone since', 'madebyhype-stockmanagment'),
            'changeFailed' => __('Could not be written.', 'madebyhype-stockmanagment'),
            'changeSkipped' => __('Had no effect.', 'madebyhype-stockmanagment'),
            'didNotFinish' => __('Did not finish', 'madebyhype-stockmanagment'),
            'failedCount' => _n_noop('%d failed', '%d failed', 'madebyhype-stockmanagment'),
            'productGone' => __('(deleted product)', 'madebyhype-stockmanagment'),
            /* translators: 1: "on" 2: starting quantity */
            'trackingQuantity' => __('%1$s, quantity %2$s', 'madebyhype-stockmanagment'),
            /* translators: 1: value stored, 2: value typed */
            'storedTyped' => __('%1$s (typed %2$s)', 'madebyhype-stockmanagment'),
            'automatic' => __('follows from another change', 'madebyhype-stockmanagment'),
            /* translators: 1: field name, 2: value before, 3: value after */
            'gapLine' => __('%1$s changed from %2$s to %3$s outside this tool (for example online orders or the product editor).', 'madebyhype-stockmanagment'),
            /* translators: %s: version number */
            'legacyVersion' => __('Version %s', 'madebyhype-stockmanagment'),
            'legacySummary' => __('Saved before this update. Details and undo are not available.', 'madebyhype-stockmanagment'),
            /* translators: %s: save number */
            'saveHeading' => __('Save #%s', 'madebyhype-stockmanagment'),
            'allSavesHeading' => __('All saves', 'madebyhype-stockmanagment'),
            'itemCount' => _n_noop('%d item', '%d items', 'madebyhype-stockmanagment'),
            /* translators: 1: current page, 2: number of pages */
            'pageOf' => __('%1$s of %2$s', 'madebyhype-stockmanagment'),
            'firstPage' => __('First page', 'madebyhype-stockmanagment'),
            'previousPage' => __('Previous page', 'madebyhype-stockmanagment'),
            'nextPage' => __('Next page', 'madebyhype-stockmanagment'),
            'lastPage' => __('Last page', 'madebyhype-stockmanagment'),
            /* translators: 1: field name, 2: value now, 3: value after the undo */
            'undoResult' => __('%1$s %2$s → %3$s', 'madebyhype-stockmanagment'),
        ];

        return self::$strings;
    }

    /**
     * A string of strings() with its placeholders filled, as the scripts do it
     *
     * @param string $key
     * @param mixed  ...$args
     * @return string Not escaped
     */
    public function t($key, ...$args)
    {
        $strings = self::strings();
        $text = isset($strings[$key]) ? $strings[$key] : $key;

        if (is_array($text)) {
            // A counted string asked for without a count: its form for "many"
            $text = translate_nooped_plural($text, 2, 'madebyhype-stockmanagment');
        }

        // Always through vsprintf, as the scripts always fill: "%%" is a percent sign either way
        return vsprintf($text, $args);
    }

    /**
     * A string with a singular and a plural form, chosen by $count (which is also its first placeholder)
     *
     * @return string Not escaped
     */
    public function tn($key, $count, ...$args)
    {
        $strings = self::strings();
        $text = isset($strings[$key]) ? $strings[$key] : $key;

        if (is_array($text)) {
            $text = translate_nooped_plural($text, (int) $count, 'madebyhype-stockmanagment');
        }

        return vsprintf($text, array_merge([$count], $args));
    }

    /**
     * Which plural form a count takes in the language of the page, as a
     * table the scripts can read without running the language's formula.
     *
     * Plural formulas look at the count itself for small numbers and at its
     * last two digits beyond that, so 200 entries cover every count: 0 to 99
     * as they are, larger ones as 100 + (count mod 100).
     *
     * @return array forms: number of plural forms, table: form index for each of the 200 entries
     */
    public static function plural_rule()
    {
        $forms = 2;
        $formula = 'n != 1';

        $translations = get_translations_for_domain('madebyhype-stockmanagment');
        $headers = is_object($translations) ? $translations->headers : [];
        $header = is_array($headers) && isset($headers['Plural-Forms']) ? (string) $headers['Plural-Forms'] : '';

        if (preg_match('/nplurals\s*=\s*(\d+)\s*;\s*plural\s*=\s*([^;]+)/', $header, $match)) {
            $forms = max(1, (int) $match[1]);
            $formula = trim($match[2]);
        }

        $table = [];

        try {
            $rule = new \Plural_Forms($formula);

            for ($n = 0; $n < 200; $n++) {
                $table[] = max(0, min($forms - 1, (int) $rule->get($n)));
            }
        } catch (\Exception $e) {
            // A formula that cannot be read: one and many
            $forms = 2;
            $table = [];

            for ($n = 0; $n < 200; $n++) {
                $table[] = $n === 1 ? 0 : 1;
            }
        }

        return ['forms' => $forms, 'table' => $table];
    }

    /**
     * strings() for the scripts: a counted string becomes the list of its
     * plural forms in the language of the page, in the order plural_rule() counts them
     *
     * @return array key => string | list of forms | map of strings
     */
    public static function script_strings()
    {
        $rule = self::plural_rule();
        $strings = [];

        // A count that takes each form, to ask the translation for that form
        $sample = [];
        foreach ($rule['table'] as $n => $form) {
            if (!isset($sample[$form])) {
                $sample[$form] = $n;
            }
        }

        foreach (self::strings() as $key => $text) {
            if (is_array($text) && isset($text['singular'])) {
                $forms = [];

                for ($form = 0; $form < $rule['forms']; $form++) {
                    $forms[] = translate_nooped_plural($text, isset($sample[$form]) ? $sample[$form] : 2, 'madebyhype-stockmanagment');
                }

                $text = $forms;
            }

            $strings[$key] = $text;
        }

        return $strings;
    }

    /* ---------------------------------------------------------------------
     * Rules shared with scripts/stock-model.js
     * ------------------------------------------------------------------- */

    /**
     * The kind of each cell of a row, from its editable flags, its stock mode
     * and whether tracking can be started, plus the user's permissions. Never
     * from the product type. Twin of cellKinds() in stock-model.js.
     *
     * @param array $row  A DataManager::get_list() row
     * @param array $caps AdminPage::permissions()
     * @return array stock: input|text|inherits|total|untracked|none, status: select|text|badge|summary|none,
     *               regular: input|text|range|none, sale: input|text|summary|none, start: bool
     */
    public static function cell_kinds($row, $caps)
    {
        $editable = isset($row['editable']) && is_array($row['editable']) ? $row['editable'] : [];
        $summary = isset($row['variation_summary']) ? $row['variation_summary'] : null;
        $mode = isset($row['stock_mode']) ? $row['stock_mode'] : 'none';
        $kinds = ['stock' => 'none', 'status' => 'none', 'regular' => 'none', 'sale' => 'none', 'start' => false];

        if (!empty($editable['stock_quantity'])) {
            $kinds['stock'] = !empty($caps['stock']) ? 'input' : 'text';
        } elseif ($mode === 'parent') {
            $kinds['stock'] = 'inherits';
        } elseif ($summary) {
            $kinds['stock'] = 'total';
        } elseif (!empty($row['can_start_tracking'])) {
            $kinds['stock'] = 'untracked';
            $kinds['start'] = !empty($caps['stock']);
        }

        if (!empty($editable['stock_status'])) {
            $kinds['status'] = !empty($caps['stock']) ? 'select' : 'text';
        } elseif ($mode === 'own' || $mode === 'parent') {
            $kinds['status'] = 'badge';
        } elseif ($summary) {
            $kinds['status'] = 'summary';
        }

        if (!empty($editable['regular_price'])) {
            $kinds['regular'] = !empty($caps['prices']) ? 'input' : 'text';
        } elseif ($summary) {
            $kinds['regular'] = 'range';
        }

        if (!empty($editable['sale_price'])) {
            $kinds['sale'] = !empty($caps['prices']) ? 'input' : 'text';
        } elseif ($summary) {
            $kinds['sale'] = 'summary';
        }

        return $kinds;
    }

    /**
     * A stored price for display: at least the shop's number of decimals,
     * never rounded, the shop's separators. Twin of formatPrice() in stock-model.js.
     *
     * @param string $value   Stored price ('' for none), dot as decimal point
     * @param bool   $grouped Whether to add the thousands separator
     * @return string Not escaped
     */
    public static function format_price($value, $grouped = false)
    {
        $text = trim((string) $value);

        if ($text === '' || !preg_match('/^(-?)(\d*)(?:\.(\d*))?$/', $text, $match)) {
            return $text;
        }

        $format = self::price_format();
        $whole = $match[2] === '' ? '0' : $match[2];
        $fraction = str_pad(isset($match[3]) ? $match[3] : '', $format['decimals'], '0');

        if ($grouped && $format['thousandSep'] !== '') {
            $whole = preg_replace('/\B(?=(\d{3})+(?!\d))/', $format['thousandSep'], $whole);
        }

        return $match[1] . $whole . ($fraction !== '' ? $format['decimalSep'] . $fraction : '');
    }

    /**
     * The shop's price format, as the scripts get it
     *
     * @return array decimals int, decimalSep string, thousandSep string
     */
    public static function price_format()
    {
        return [
            'decimals' => function_exists('wc_get_price_decimals') ? (int) wc_get_price_decimals() : 2,
            'decimalSep' => function_exists('wc_get_price_decimal_separator') ? (string) wc_get_price_decimal_separator() : '.',
            'thousandSep' => function_exists('wc_get_price_thousand_separator') ? (string) wc_get_price_thousand_separator() : ',',
        ];
    }

    /* ---------------------------------------------------------------------
     * Links
     * ------------------------------------------------------------------- */

    /**
     * The query arguments that say what the screen shows now. Defaults are
     * left out, so a link never pins one into a bookmark.
     *
     * @return array
     */
    private function current_args()
    {
        $request = $this->request;
        $args = ['post_type' => 'product', 'page' => AdminPage::PAGE_SLUG];

        if ($request['tab'] !== 'all') {
            $args['tab'] = $request['tab'];
        }

        if ($request['tab'] === 'history') {
            foreach (['item' => 'item', 'user' => 'user'] as $key => $name) {
                if ($request[$key]) {
                    $args[$name] = $request[$key];
                }
            }
            if ($request['search'] !== '') {
                $args['s'] = $request['search'];
            }
            if ($request['paged'] > 1) {
                $args['paged'] = $request['paged'];
            }

            return $args + $this->period_args();
        }

        if ($request['tab'] === 'all' && $request['view'] === DataManager::VIEW_SKU) {
            $args['view'] = DataManager::VIEW_SKU;
        }

        if ($request['search'] !== '') {
            $args['s'] = $request['search'];
        }

        $args += $this->period_args();

        if ($request['sort_by'] !== '') {
            $args['sort_by'] = $request['sort_by'];
            $args['sort_order'] = $request['sort_order'];
        }

        if ($request['per_page'] !== AdminPage::DEFAULT_PER_PAGE) {
            $args['per_page'] = $request['per_page'];
        }

        foreach (['category_filter', 'tag_filter', 'attribute_filter', 'stock_filter'] as $key) {
            if ($request[$key]) {
                $args[$key] = $request[$key];
            }
        }

        foreach (['min_price', 'max_price', 'min_sales', 'max_sales'] as $key) {
            if ($request[$key] > 0) {
                $args[$key] = $request[$key];
            }
        }

        if ($request['include_drafts'] && $request['tab'] === 'all') {
            $args['include_drafts'] = 1;
        }

        if ($request['tab'] === 'attention') {
            if ($request['sold_only']) {
                $args['sold_only'] = 1;
            }
            if ($request['attention'] !== 'all') {
                $args['attention'] = $request['attention'];
            }
        }

        $page = $this->result ? $this->result['current_page'] : $request['paged'];
        if ($page > 1) {
            $args['paged'] = $page;
        }

        return $args;
    }

    /**
     * The sales period as query arguments; nothing for the default period
     *
     * @return array
     */
    public function period_args()
    {
        $period = $this->period;

        if (empty($period) || !empty($period['is_default']) || ($period['key'] === DataManager::DEFAULT_PERIOD && $period['mode'] === 'rolling')) {
            return [];
        }

        if ($period['key'] === 'custom') {
            return ['start_date' => $period['start_date'], 'end_date' => $period['end_date']];
        }

        return ['period' => $period['key']];
    }

    /**
     * The sales period as the variations call takes it: always stated, also the default
     *
     * @return array
     */
    private function period_request()
    {
        $period = $this->period;

        if ($period['key'] === 'custom') {
            return ['start_date' => $period['start_date'], 'end_date' => $period['end_date']];
        }

        return ['period' => $period['key']];
    }

    /**
     * A link to this screen: what it shows now, with some arguments changed
     *
     * @param array $changes Argument => new value; null removes it. The page number is dropped unless given.
     * @return string Not escaped
     */
    public function url($changes = [])
    {
        $args = $this->current_args();

        if (!array_key_exists('paged', $changes)) {
            unset($args['paged']);
        }

        return $this->build_url(array_merge($args, $changes));
    }

    /**
     * A link to another tab: the sales period and page size go along, nothing else
     *
     * @param string $tab     all, attention or history
     * @param array  $changes More arguments
     * @return string Not escaped
     */
    public function tab_url($tab, $changes = [])
    {
        $args = ['post_type' => 'product', 'page' => AdminPage::PAGE_SLUG];

        if ($tab !== 'all') {
            $args['tab'] = $tab;
        }

        $args += $this->period_args();

        if ($tab !== 'history' && $this->request['per_page'] !== AdminPage::DEFAULT_PER_PAGE) {
            $args['per_page'] = $this->request['per_page'];
        }

        return $this->build_url(array_merge($args, $changes));
    }

    private function build_url($args)
    {
        $args = array_filter($args, function ($value) {
            return $value !== null && $value !== '' && $value !== [];
        });

        return admin_url('edit.php') . '?' . http_build_query($args, '', '&', PHP_QUERY_RFC3986);
    }

    /* ---------------------------------------------------------------------
     * What the templates ask
     * ------------------------------------------------------------------- */

    /**
     * Active search and filters, each with the link that removes it
     *
     * @return array List of ['label' => string, 'url' => string, 'filter' => bool (false for the search)]
     */
    private function chips()
    {
        $request = $this->request;
        $chips = [];

        if ($request['search'] !== '') {
            /* translators: %s: search text */
            $chips[] = ['label' => sprintf(__('Search: "%s"', 'madebyhype-stockmanagment'), $request['search']), 'url' => $this->url(['s' => null]), 'filter' => false];
        }

        $term_chips = function ($key, $taxonomy, $ids, $label, $nested = null) use (&$chips, $request) {
            foreach ($ids as $id) {
                $term = get_term($id, $taxonomy);
                if (!$term || is_wp_error($term)) {
                    continue;
                }

                $rest = array_values(array_diff($ids, [$id]));
                if ($nested !== null) {
                    $value = $request[$key];
                    $value[$nested] = $rest;
                    $value = array_filter($value);
                } else {
                    $value = $rest;
                }

                $chips[] = [
                    /* translators: 1: what is filtered on, for example "Category" or "Size", 2: the value */
                    'label' => sprintf(__('%1$s: %2$s', 'madebyhype-stockmanagment'), $label, html_entity_decode($term->name, ENT_QUOTES | ENT_HTML5, 'UTF-8')),
                    'url' => $this->url([$key => $value ? $value : null]),
                    'filter' => true,
                ];
            }
        };

        $term_chips('category_filter', 'product_cat', $request['category_filter'], __('Category', 'madebyhype-stockmanagment'));
        $term_chips('tag_filter', 'product_tag', $request['tag_filter'], __('Tag', 'madebyhype-stockmanagment'));

        foreach ($request['attribute_filter'] as $taxonomy => $ids) {
            $term_chips('attribute_filter', $taxonomy, $ids, function_exists('wc_attribute_label') ? wc_attribute_label($taxonomy) : $taxonomy, $taxonomy);
        }

        if ($request['tab'] === 'all') {
            $labels = $this->stock_filter_labels();
            foreach ($request['stock_filter'] as $status) {
                $rest = array_values(array_diff($request['stock_filter'], [$status]));
                $chips[] = [
                    /* translators: %s: stock status */
                    'label' => sprintf(__('Status: %s', 'madebyhype-stockmanagment'), $labels[$status]),
                    'url' => $this->url(['stock_filter' => $rest ? $rest : null]),
                    'filter' => true,
                ];
            }
        }

        $range = function ($min, $max, $between, $from, $up_to, $format) {
            if ($min > 0 && $max > 0) {
                return sprintf($between, $format($min), $format($max));
            }

            return $min > 0 ? sprintf($from, $format($min)) : sprintf($up_to, $format($max));
        };

        if ($request['min_price'] > 0 || $request['max_price'] > 0) {
            $chips[] = [
                'label' => $range(
                    $request['min_price'],
                    $request['max_price'],
                    /* translators: 1: lowest price, 2: highest price */
                    __('Price: %1$s – %2$s', 'madebyhype-stockmanagment'),
                    /* translators: %s: lowest price */
                    __('Price: %s or more', 'madebyhype-stockmanagment'),
                    /* translators: %s: highest price */
                    __('Price: up to %s', 'madebyhype-stockmanagment'),
                    function ($number) {
                        return self::format_price((string) (float) $number, true);
                    }
                ),
                'url' => $this->url(['min_price' => null, 'max_price' => null]),
                'filter' => true,
            ];
        }

        if ($request['min_sales'] > 0 || $request['max_sales'] > 0) {
            $chips[] = [
                'label' => $range(
                    $request['min_sales'],
                    $request['max_sales'],
                    /* translators: 1: fewest units sold, 2: most units sold */
                    __('Sold: %1$s – %2$s', 'madebyhype-stockmanagment'),
                    /* translators: %s: fewest units sold */
                    __('Sold: %s or more', 'madebyhype-stockmanagment'),
                    /* translators: %s: most units sold */
                    __('Sold: up to %s', 'madebyhype-stockmanagment'),
                    'strval'
                ),
                'url' => $this->url(['min_sales' => null, 'max_sales' => null]),
                'filter' => true,
            ];
        }

        if ($request['include_drafts'] && $request['tab'] === 'all') {
            $chips[] = ['label' => __('Drafts included', 'madebyhype-stockmanagment'), 'url' => $this->url(['include_drafts' => null]), 'filter' => true];
        }

        if ($request['sold_only'] && $request['tab'] === 'attention') {
            $chips[] = ['label' => __('Only items sold in this period', 'madebyhype-stockmanagment'), 'url' => $this->url(['sold_only' => null]), 'filter' => true];
        }

        return $chips;
    }

    /**
     * The link that clears the search, every filter, the sort and the view
     *
     * @return string Not escaped
     */
    private function clear_all_url()
    {
        return $this->tab_url($this->request['tab'] === 'attention' ? 'attention' : 'all');
    }

    /**
     * The link that removes every filter and keeps the search, the sort and the view
     *
     * @return string Not escaped
     */
    private function clear_filters_url()
    {
        return $this->url(array_fill_keys(
            ['category_filter', 'tag_filter', 'attribute_filter', 'stock_filter', 'min_price', 'max_price', 'min_sales', 'max_sales', 'include_drafts', 'sold_only', 'attention'],
            null
        ));
    }

    /**
     * @return array Stock filter value => label
     */
    private function stock_filter_labels()
    {

        return [
            'instock' => __('In stock', 'madebyhype-stockmanagment'),
            'lowstock' => __('Low stock', 'madebyhype-stockmanagment'),
            'outofstock' => __('Out of stock', 'madebyhype-stockmanagment'),
            'onbackorder' => __('On backorder', 'madebyhype-stockmanagment'),
            'untracked' => __('Not tracked', 'madebyhype-stockmanagment'),
        ];
    }

    /**
     * @return array Period key => label of the "Sold in" selector
     */
    private function period_options()
    {
        $labels = [
            '30' => __('Last 30 days', 'madebyhype-stockmanagment'),
            '90' => __('Last 90 days', 'madebyhype-stockmanagment'),
            '180' => __('Last 6 months', 'madebyhype-stockmanagment'),
            '365' => __('Last 12 months', 'madebyhype-stockmanagment'),
            'all' => __('All time', 'madebyhype-stockmanagment'),
        ];
        $options = [];

        // The periods the server offers, in its order
        foreach (DataManager::PERIODS as $key) {
            /* translators: %s: number of days */
            $options[$key] = isset($labels[$key]) ? $labels[$key] : sprintf(__('Last %s days', 'madebyhype-stockmanagment'), $key);
        }

        $options['custom'] = __('Custom range…', 'madebyhype-stockmanagment');

        return $options;
    }

    /**
     * The sales period in words: the second line of the Sold column's heading
     *
     * @return string Not escaped
     */
    private function period_label()
    {
        $period = $this->period;
        $labels = [
            '30' => __('last 30 days', 'madebyhype-stockmanagment'),
            '90' => __('last 90 days', 'madebyhype-stockmanagment'),
            '180' => __('last 6 months', 'madebyhype-stockmanagment'),
            '365' => __('last 12 months', 'madebyhype-stockmanagment'),
            'all' => __('all time', 'madebyhype-stockmanagment'),
        ];

        if (isset($labels[$period['key']])) {
            return $labels[$period['key']];
        }

        if ($period['key'] !== 'custom') {
            /* translators: %s: number of days */
            return sprintf(__('last %s days', 'madebyhype-stockmanagment'), $period['key']);
        }

        return sprintf(
            /* translators: 1: first day of a period, 2: its last day */
            __('%1$s – %2$s', 'madebyhype-stockmanagment'),
            date_i18n('j M Y', strtotime($period['start_date'])),
            date_i18n('j M Y', strtotime($period['end_date']))
        );
    }

    /**
     * A column heading that sorts the list
     *
     * @param string $field      One of DataManager::SORT_FIELDS
     * @param string $label      Already escaped
     * @param string $class      Column classes
     * @param bool   $desc_first Whether the first click sorts from high to low
     * @param string $help       Plain text shown as the heading's tooltip
     */
    private function sort_heading($field, $label, $class, $desc_first = false, $help = '')
    {
        $sorted = $this->request['sort_by'] === $field;
        $current = strtolower($this->request['sort_order']);

        // The class says which way the arrow points: the direction in force, or the one a click gives
        if ($sorted) {
            $next = $current === 'asc' ? 'desc' : 'asc';
            $classes = 'sorted ' . $current;
        } else {
            $next = $desc_first ? 'desc' : 'asc';
            $classes = 'sortable ' . $next;
        }

        printf(
            '<th scope="col" class="%1$s %2$s"%3$s%4$s><a href="%5$s"><span>%6$s</span><span class="mbh-sort-arrow" aria-hidden="true"></span> <span class="screen-reader-text">%7$s</span></a></th>',
            esc_attr($class),
            esc_attr($classes),
            $sorted ? ' aria-sort="' . ($current === 'asc' ? 'ascending' : 'descending') . '"' : '',
            $help !== '' ? ' title="' . esc_attr($help) . '"' : '',
            esc_url($this->url(['sort_by' => $field, 'sort_order' => strtoupper($next)])),
            $label,
            $next === 'asc' ? esc_html__('Sort ascending.', 'madebyhype-stockmanagment') : esc_html__('Sort descending.', 'madebyhype-stockmanagment')
        );
    }

    /**
     * The name of the user History's "Saved by" filter is set to. The other
     * choices are everyone who has a save in History; the script reads them
     * through the history action (view=users).
     *
     * @param int $user_id
     * @return string Not escaped
     */
    private function history_user_name($user_id)
    {
        $user = get_userdata($user_id);

        return $user ? $user->display_name : $this->t('userNumber', $user_id);
    }

    /**
     * What the scripts need to know about this page view, printed as JSON
     *
     * @return array
     */
    private function page_data($history_item)
    {
        $request = $this->request;
        $result = $this->result;
        $rows = [];

        // The rows as the server gave them, minus what no script reads
        foreach ($result ? $result['rows'] : [] as $row) {
            unset($row['image_id'], $row['attributes'], $row['price'], $row['is_stock_holder']);
            $rows[] = $row;
        }

        $data = [
            'tab' => $request['tab'],
            'view' => $result ? $result['view'] : $request['view'],
            'search' => $request['search'],
            'periodArgs' => $this->period_request(),
            'rows' => $rows,
            'urls' => [
                'edit' => admin_url('post.php?action=edit&post=%d'),
                'history' => $this->tab_url('history') . '&item=%d',
            ],
        ];

        if ($request['tab'] === 'history') {
            $data['history'] = [
                'item' => $history_item ? $history_item['id'] : 0,
                'user' => $request['user'],
                'search' => $history_item ? '' : $request['search'],
                'paged' => $request['paged'],
                'pageUrl' => $this->url(['paged' => null]) . '&paged=%d',
                'listUrl' => $this->tab_url('history'),
            ];
        }

        return $data;
    }
}
