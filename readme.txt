=== MadeByHype Stock Management ===
Contributors: madebyhype
Donate link: https://madebyhype.se/
Tags: woocommerce, stock, inventory, bulk edit, prices
Requires at least: 5.3
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.4.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Edit WooCommerce stock and prices in one fast grid, with bulk price changes, a full change history and undo.

== Description ==

MadeByHype Stock Management adds one screen under Products → Stock Management where the stock and prices of every product and variation are edited in place and saved together.

= The stock screen =

* One grid for all products: stock, stock status, regular price and sale price
* Variable products open to show their variations
* Two views: by product, or by SKU (every item that holds stock on its own row)
* A Needs attention tab for items that are out of stock, low, or on backorder
* Search by product name, SKU, variation SKU or ID
* Sort by name, stock, price, units sold or days of cover
* Units sold in a period you choose, and how many days the stock lasts at that rate
* Keyboard movement in the grid: Enter and arrows move down a column, Escape restores a cell, Ctrl+S saves

= Safe saving =

* Each cell shows what happened to it: saved, adjusted, conflict or refused, with the reason
* A stock edit is applied as a difference to the current quantity, so units sold while the page was open are kept
* A price is only written if it is still the price you were looking at

= Filters =

* Stock status, category, tags, attribute values, price range and units sold
* Every value can be included or left out
* Active filters show as chips above the list

= Bulk price change =

* One rule applied to everything the search and filters match, including variations
* Change by a percentage, by an amount, or to an exact price; put items on sale or remove sale prices
* Optional rounding: whole number, nearest 10, ends in 9, or ends in .99
* A preview comes first, and the run is one save that can be undone

= History and undo =

* Every save is recorded with who made it, when, and the value before and after
* Any save can be undone on its own, with a preview first
* History is kept for 12 months, or as long as the settings say

= Requirements =

* WordPress 5.3 or higher
* WooCommerce 6.0 or higher
* PHP 7.4 or higher

== Installation ==

1. Install the plugin through the Plugins screen in WordPress, or upload the plugin folder to `/wp-content/plugins/`.
2. Make sure WooCommerce is active.
3. Activate the plugin on the Plugins screen.
4. Open Products → Stock Management.

The settings are under WooCommerce → Settings → Products → Stock Management.

== Frequently Asked Questions ==

= Does it support product variations? =

Yes. A variable product opens to show its variations, and the By SKU view lists every variation on its own row.

= Can a save be undone? =

Yes. Every save is recorded in History and can be undone on its own. Only fields that still hold what the save wrote are put back.

= What happens to my data when the plugin is deleted? =

Deleting the plugin removes its history and settings, unless the plugin is set to keep them. Products are never touched.

= Who can use the screen? =

Users with the WooCommerce capability `manage_woocommerce`.

== Screenshots ==

1. The stock screen

== Changelog ==

= 1.4.0 =
* The plugin is now updated through WordPress.org. Updates from GitHub releases are removed.
* The text domain is now `madebyhype-stock-management`.
* WordPress 5.3 or higher is required.
* Icons and the page number field are escaped where they are printed.

= 1.3.2 =
* The plugin address in the plugin header is now the plugin's own page.

= 1.3.1 =
* A new plugin icon.

= 1.3.0 =
* A settings page under WooCommerce → Settings → Products → Stock Management.

= 1.2.0 =
* Bulk price change, and filters that can leave values out.

== Upgrade Notice ==

= 1.4.0 =
Updates now come from WordPress.org. WordPress 5.3 or higher is required.
