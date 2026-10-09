# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added
- A settings page under WooCommerce → Settings → Products → Stock Management: rows per page and sales period the list opens with, how long History is kept, the largest bulk price change allowed, how many items a bulk change sends per request (10, 25, 50 or 100, each with the memory it needs), and whether History and settings are kept when the plugin is deleted. Linked from the Plugins screen and from the stock screen

## [1.2.1] - 2026-10-09

### Added
- Updates from GitHub: a new release shows on the Plugins and Updates screens like any other update, with its release notes, and can be installed in one click or automatically. The update keeps the name of the folder the plugin is installed in

## [1.2.0] - 2026-10-09

### Added
- Bulk price change: one rule applied to everything the search and filters match, on every page and including variations. Increase or decrease by a percentage or an amount, set an exact price, put items on sale at a percentage off, or remove sale prices, with optional rounding (whole number, nearest 10, ends in 9, ends in .99)
- The bulk change shows a preview first (how many prices change, what is left alone and why, before and after for the first and the largest changes), runs with a progress bar that can be stopped and continued, and is one save in History that can be undone
- Filters can leave values out: a click on a category, tag, attribute value or stock status includes it, a second click leaves it out, a third clears it. "Rings but not Wedding rings" is Rings included and Wedding rings left out inside it
- Products can be named one by one in the filters: included products are listed on top of what the other filters match, and a product that is left out is never listed. A product can also be left out from its row
- What is left out shows as its own chip above the list ("Category is not: Clearance") and counts as a filter

### Changed
- The table heading is a white band on a heavy black rule, with an arrow on every column that sorts; the sorted column is black on grey and its cells are lightly tinted
- Undo and History cope with very large saves: an undo runs in steps with its progress shown, and the changes of a save are listed 200 at a time

## [1.1.0] - 2026-10-09

### Added
- Search by name, SKU, variation SKU and ID, a by-SKU view, and a Needs attention tab
- Sorting by name, SKU, price and days of cover; low-stock, untracked and include-drafts filters
- History tab: every save with user, time and per-field before and after, per-product history, and undo of a single save with a preview
- Start tracking control for items that do not track stock; untracked items no longer show a fake 0
- Keyboard movement in the grid (Enter, arrows, Escape, Ctrl+S)
- Category filter as an expandable tree, with searchable categories, tags and attributes
- All strings are translatable

### Changed
- The stock screen is redesigned: filters in a drawer, one toolbar row, a floating save bar, sticky heading and pinned Product column
- A stock edit is applied as a difference to the current quantity, so units sold while the page was open are kept
- Each cell shows what the server did: saved, adjusted, conflict, refused or dropped
- Stock tracking never changes as a side effect of a save
- Variations load when a row is opened; sorting, paging, search and filter changes update the list without a page reload
- The product list is much faster on large catalogues
- Requires WooCommerce to be active (Requires Plugins)
- The old Version History list is read-only; its Revert is replaced by per-save undo

### Fixed
- The sales period now includes its last day, in the site's time zone
- A failed list query is reported as an error instead of an empty list
- Price and stock-status filters look at a variable product's variations
- An idle page can still save (nonces are refreshed)

## [1.0.6] - 2026-10-08

### Fixed
- Clearing a price or stock cell no longer saves 0. An empty sale price now ends the sale; an empty regular price or stock quantity is refused with a message
- Sale prices that are not below the regular price are refused instead of being silently dropped
- The save result is now read from the server: refused items stay highlighted with the reason, instead of every save reporting success
- Saves larger than the server limit (100 products or 100 variations) are sent in several requests instead of being rejected
- Reverting a version that no longer exists no longer rolls back the newer versions first
- A failed revert now shows the error and closes the dialog instead of leaving it stuck
- wp-admin no longer crashes when WooCommerce is inactive

### Changed
- The product list is no longer cached for 5 minutes, so stock and prices shown are always current
- The browser now warns before leaving the page with unsaved changes
- Save and Reset are disabled while a save is in progress
- Setting a price to 0 asks for confirmation

## [1.0.1] - 2024-01-15

### Fixed
- Fixed sorting order reset when clearing date filters
- Fixed duplicate event handlers in top controls
- Fixed save controls not being disabled on fresh load

## [1.0.0] - 2024-01-10

### Added
- Initial release of Stock Management plugin
- Product stock quantity editing
- Stock status management
- Price editing (regular and sale prices)
- Date range filtering
- Category and tag filtering
- Stock status filtering
- Price range filtering
- Sales range filtering
- Pagination support
- Variable product support with variations
- Version history and revert functionality
- Responsive design with sidebar toggle
- Export functionality
- Bulk operations 