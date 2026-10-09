# MadeByHype Stock Management

<a href="https://buymeacoffee.com/okanat"><img src="https://cdn.buymeacoffee.com/buttons/v2/default-yellow.png" alt="Buy Me a Coffee" height="48"></a>

If this plugin saves you time, you can [buy me a coffee](https://buymeacoffee.com/okanat).

A WooCommerce plugin for editing stock and prices in one screen: a fast, searchable grid of every product and variation, with bulk price changes, a full change history and undo.

## Features

### The stock screen
- One grid for all products, under **Products → Stock Management**: stock, stock status, regular price and sale price are edited in place and saved together.
- Variable products open to show their variations; variations load when a row is opened.
- Two views: **By product**, or **By SKU** (every item that holds stock on its own row).
- A **Needs attention** tab for items that are out of stock, low, or on backorder.
- Search by product name, SKU, variation SKU or ID.
- Sort by name, stock, price, units sold or days of cover.
- Units sold in a period you choose (30, 90, 180 or 365 days, all time, or custom dates), and how many days the stock lasts at that rate.
- 20, 50, 100 or 500 rows per page; sorting, paging and filtering update the list without a page reload.
- Keyboard movement in the grid: Enter and arrows move down a column, Escape restores a cell, Ctrl+S saves.

### Safe saving
- Each cell shows what the server did with it: saved, adjusted, conflict, refused or dropped, with the reason.
- A stock edit is applied as a difference to the current quantity, so units sold while the page was open are kept.
- A price is only written if it is still the price you were looking at; otherwise it is reported as a conflict.
- A sale price must stay below the regular price, and a price of 0 has to be confirmed.
- Stock tracking never changes as a side effect: items that do not track stock get a **Start tracking** control.

### Filters
- Stock status, category (a searchable tree), tags, attribute values, price range and units sold.
- Every value can be **included or left out**: one click includes it, a second leaves it out, a third clears it. "Rings but not Wedding rings" is Rings included and Wedding rings left out inside it.
- **Products named one by one**: an included product is listed on top of what the other filters match, a product that is left out is never listed. A product can also be left out from its own row.
- Active filters show as chips above the list; exclusions read "Category is not: …".

### Bulk price change
- One rule applied to everything the search and filters match, on every page and including variations.
- Change the regular price, the sale price or both: by a percentage, by an amount, or to an exact price. Put items on sale at a percentage off, or remove sale prices.
- Rounding: whole number, nearest 10, ends in 9, or ends in .99.
- A preview comes first: how many prices change, what is left alone and why, and before and after for the first and the largest changes.
- The run shows its progress and can be stopped and continued. It is recorded as one save and can be undone.

### History and undo
- Every save is recorded with who made it, when, and the value before and after for each field.
- History per save and per product, with a note where a value was changed outside this tool.
- Any save can be undone on its own, with a preview first. Only fields that still hold what the save wrote are put back.
- History is kept for 12 months, or as long as the settings say.

## Requirements

- WordPress 5.0 or higher
- WooCommerce (must be active)
- PHP 7.4 or higher

## Installation

1. Upload the plugin folder to `/wp-content/plugins/`, or install the zip from a [release](https://github.com/ombicen/madebyhype-stockmanagment/releases) through **Plugins → Add New → Upload Plugin**.
2. Make sure WooCommerce is active.
3. Activate the plugin on the **Plugins** screen.
4. Open **Products → Stock Management**.

## Updates

From 1.2.1 on the plugin updates itself from the [releases](https://github.com/ombicen/madebyhype-stockmanagment/releases) of this repository: a new release shows under **Dashboard → Updates** and on the **Plugins** screen, with its release notes under "View details", and automatic updates can be switched on there. WordPress looks for updates about twice a day; **Check again** on the Updates screen looks at once.

An installation that is a git working copy (it has a `.git` folder) is never offered an update: update it with git.

When updating by copying files instead, replace the whole plugin folder rather than copying over it, and clear the server's PHP cache if the screen does not change.

## Settings

**WooCommerce → Settings → Products → Stock Management** (also linked from the Plugins screen and from the tabs of the stock screen):

- Rows per page and the sales period the list opens with.
- How long History is kept (12 months unless changed).
- The largest bulk price change allowed, in items.
- Items per request of a bulk price change: 50 (default), 100, 250 or 500, each listed with what it takes per request and the PHP memory recommended for it. The page also shows the memory this server allows.
- Whether History and these settings are kept when the plugin is deleted. Unticked, deleting the plugin removes what it stored; products are never touched.

## Permissions

The screen and everything on it need the WooCommerce capability `manage_woocommerce`. Viewing, editing stock, editing prices and undoing are separate permissions in the code (`includes/Capabilities.php`) and all map to that capability for now.

## Plugin structure

```
madebyhype-stockmanagment/
├── madebyhype-stockmanagment.php   # Bootstrap
├── uninstall.php                   # Removes the plugin's tables and options on delete
├── includes/
│   ├── Plugin.php                  # Wires the parts together
│   ├── Updater.php                 # Updates from the releases on GitHub
│   ├── Settings.php                # The settings and their page under WooCommerce > Settings > Products
│   ├── Capabilities.php            # What the current user may do
│   ├── Admin/
│   │   ├── AdminPage.php           # Menu, and reading the request
│   │   ├── AjaxHandler.php         # Save, undo, history
│   │   ├── ReadAjaxHandler.php     # List, variations, filter options, product search
│   │   └── BulkAjaxHandler.php     # Bulk price change: preview and apply
│   ├── Data/
│   │   ├── DataManager.php         # The list query: search, filters, views
│   │   ├── WriteService.php        # The only code that changes products
│   │   ├── ChangeLog.php           # Saves and undos, field by field
│   │   ├── BulkPriceRule.php       # The arithmetic of a bulk price change
│   │   ├── BulkPriceService.php    # Preview and apply of a bulk price change
│   │   ├── Schema.php              # The plugin's tables
│   │   └── VersionManager.php      # Read-only list of saves made before 1.1.0
│   ├── UI/
│   │   ├── UIManager.php           # What the templates and scripts show, and all strings
│   │   ├── templates/              # Page, toolbar, grid, row, chips, pager, history
│   │   ├── scripts/                # stock-model (rules), core, grid, filters, history, bulk
│   │   └── styles/stock-screen.css
│   └── Assets/AssetsManager.php    # Loads the scripts and the stylesheet on this screen only
├── languages/                      # Translation template
└── assets/images/
```

Products are written through WooCommerce product objects only, and every write is recorded in the change log before it is made.

## Translations

All strings are translatable (text domain `madebyhype-stockmanagment`). The template in `languages/` is not yet regenerated for 1.2.0.

## Changelog

See [CHANGELOG.md](CHANGELOG.md).

## License

GPL v2 or later.
