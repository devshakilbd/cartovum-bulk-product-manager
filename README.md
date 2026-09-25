=== Cartovum Bulk Product Manager ===
Contributors: sanjubgd2024
Tags: woocommerce, bulk edit, stock, inventory, product attributes
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.4.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html
WC requires at least: 6.0
WC tested up to: 11.1

Find WooCommerce products by attribute, then bulk edit stock and attributes in small batches. Every run is logged and can be put back.

== Description ==

Cartovum Bulk Product Manager gives WooCommerce store administrators one screen for finding products
and changing them in bulk. WooCommerce's own bulk edit shows twenty products at a time and has no
attribute fields at all, so anything involving attributes means opening products one by one.

This plugin replaces that with a single workflow: filter the catalogue down to the products you mean,
select them across pages, then apply one change to all of them. The change runs in small batches with
live progress, every product gets its own result line, and the whole run can be put back afterwards.

= Finding products =

Search and filter by any combination of:

* product name
* a pasted list of SKUs
* product category
* stock status
* published state (published, draft, private and so on)
* one or more attribute conditions

Attribute conditions are the reason the plugin exists. Add as many as ten with the Add Condition
button, choosing an attribute and, if you want, one of its values. Conditions combine with AND: a
product has to match every condition you have added to appear in the results. Both kinds of attribute
are supported, the shared (global) attributes WooCommerce keeps as taxonomies and the typed (custom)
attributes stored on the product itself, and each value list only offers values that already exist in
your catalogue.

= Changing them =

Select products page by page, or select every matching product in one click, then either:

* set the stock status of everything selected, or
* run one attribute operation across everything selected:
  * add values to an attribute
  * remove values from an attribute
  * replace the values of an attribute
  * remove the attribute
  * set the value of a typed attribute

The browser sends the selection to the server in batches of ten, so no single request has to do all
the work. Progress is shown while it runs, and every product gets its own line in the results:
changed, skipped or failed, each with the reason.

= Stock status on products that track a quantity =

WooCommerce stores the stock status of a quantity-tracked product as a result rather than a setting:
on every save it works the status out again from the quantity and the backorder setting. Writing the
status on its own is therefore undone the moment it is written, which is why setting such products to
out of stock can appear to do nothing at all.

So for those products the quantity is set instead, and the status follows from it. Out of stock writes
a quantity of zero; in stock writes the quantity given on the screen. Products that do not track a
quantity have their status written directly. Untick "Also set the stock quantity" to leave
quantity-tracked products alone, and they are listed as skipped with the reason.

Two cases are refused rather than half done, each explained on the product's own line: a product that
allows backorders cannot be emptied into out of stock, because WooCommerce would call it on backorder;
and a quantity at or below the store's out-of-stock threshold would not count as in stock.

= What it will not do =

* It never creates a shared attribute or a new attribute value. Only values that already exist can be
  chosen, so a typo cannot quietly add a new term.
* It never converts a typed attribute into a shared one, or the other way round, as part of an
  ordinary bulk edit. If a product already carries a typed attribute of the same name, adding the
  shared one is skipped and said so, rather than leaving the product with two attributes of one name.
* It never turns stock quantity tracking on or off. A product that tracks its quantity keeps tracking
  it; a product that does not is never made to.
* It leaves variable products alone, and leaves any attribute marked "used for variations" alone.
* It never deletes a product, a variation, a category, an attribute or an attribute term.

= How it protects the rest of the product =

Before each write the product is fingerprinted: name, SKU, prices, description, images, gallery,
categories, tags, tax, shipping class, weight, dimensions, stock quantity, backorders, menu order,
catalogue visibility and attributes. After the write the fingerprint is taken again. Anything that
changed but was not part of the requested operation is reported as a failure rather than passing
silently.

Every change goes through the WooCommerce product API, so lookup tables, caches, feeds and other
integrations stay in step exactly as they do when a product is saved by hand.

= Putting a run back =

Each run is recorded with the state of every product before and after the change. In Recent runs, Put
back restores the products that run changed, including the stock quantity where one was set. A product
that has changed since is skipped rather than overwritten. The last 25 runs are kept.

= Converting typed attributes to shared attributes =

Catalogues that grew over time often hold the same information twice: the same attribute typed
straight onto some products and set as a shared attribute on others. The Convert typed attributes
panel moves a typed value onto the shared attribute of the same name, for example a typed Size of
`18` onto the shared Size value `18"` (18 inches).

* A typed value only moves when it matches exactly one shared value once units and quotation marks are
  set aside (`18` matches `18"`, `112` matches `112mm`), or when an administrator has approved that
  specific mapping.
* Values on hold, values with no match, and values matching more than one shared value block the whole
  product. It is left exactly as it is and listed under Needs attention.
* If the product already has the shared attribute with the same value, only the typed copy is removed.
  If the values differ, the product is blocked.
* Each product converts in a single save and keeps every attribute's position and visibility. After the
  save it is read back: converted attributes must hold exactly the planned values, other attributes
  must be untouched, and nothing outside the attributes may have changed.
* Run the dry run first, and download its CSV report if you want to review it away from the screen.
  The dry run records a signature for every product, and a product edited after the dry run is skipped
  rather than overwritten. A conversion run stops at the first product that fails its check.
* Conversion runs appear in Recent runs and can be put back like any other run.

Because conversion is a one-way change, a site can stage it. Define `SWBM_CONVERT_DRY_RUN_ONLY`,
`SWBM_CONVERT_ALLOWED_STATUSES` or `SWBM_CONVERT_ALLOWED_IDS` in `wp-config.php` to allow the dry run
only, to restrict conversion to certain product statuses, or to approve individual products while the
statuses stay restricted.

= Who it is for =

Store administrators and shop managers looking after catalogues too large to edit by hand, especially
catalogues where the same attribute is typed on some products and shared on others.

= Privacy =

The plugin sends nothing anywhere. It has no external services, no tracking and no analytics, and it
reads and writes only your own WooCommerce products and its own run log, which is stored in your
site's options table.

== Installation ==

1. In WordPress go to Plugins, then Add New.
2. Search for Cartovum Bulk Product Manager.
3. Click Install Now, then Activate.
4. Go to Products, then Bulk Stock & Attributes.
5. Set your filter conditions, press Search, and select the products you want to change.
6. Apply a stock status or an attribute operation to the selection.

WooCommerce has to be installed and active. Every screen and every request needs the
`manage_woocommerce` capability, which administrators and shop managers have by default.

To install manually instead, upload the plugin folder to `wp-content/plugins/` and activate it from
the Plugins screen.

== Frequently Asked Questions ==

= Does this plugin require WooCommerce? =

Yes. It manages WooCommerce products through the WooCommerce product API, so it does nothing on its
own. If WooCommerce is not active the plugin says so and stays out of the way.

= Can I filter products using more than one attribute? =

Yes. Use the Add Condition button to add up to ten attribute conditions. Each condition is an
attribute and, optionally, one of that attribute's values. Both shared (global) and typed (custom)
attributes can be used, and the value lists only ever offer values that exist in your catalogue.

= Does it use AND or OR logic? =

AND. A product has to match every condition you have added, together with the other filters such as
category and stock status, before it appears in the results. There is no OR option: to cover
alternatives, run one search per alternative.

= Can I manage stock in bulk? =

Yes. Select products and set them to in stock, out of stock or on backorder. Products that track a
stock quantity are handled by setting the quantity, because WooCommerce derives their status from it,
and you can untick that behaviour to leave those products alone instead. Stock quantity tracking
itself is never switched on or off for you.

= Does the plugin modify product data? =

Yes, that is its purpose, but only the field you asked it to change. Every product is fingerprinted
before the write and read back afterwards, and if anything outside the requested change moved, the
product is reported as failed instead of passing quietly. Each run is recorded and can be put back
from the Recent runs list.

= Can I undo a bulk change? =

Yes. Every run is recorded with each product's state before and after, and Put back restores them. Any
product that has been changed since the run is skipped rather than overwritten. The last 25 runs are
kept.

= Why was a product skipped instead of changed? =

The result line always gives the reason. The usual ones are that the product already had the value you
asked for, that it is a variable product, that the attribute is used for variations, or that
WooCommerce could not reach the status you asked for, such as out of stock on a product that allows
backorders.

= Will it create new attribute values? =

No. Only values that already exist in your catalogue can be chosen, so a mistyped value cannot quietly
add a new term to your attributes.

= Does it work with large catalogues? =

Yes. Searches are paginated and selections are processed in batches of ten per request, so no single
request has to carry the whole job. Attribute and value lists are cached, and you can stop a run after
the current batch.

= Does it send any data to an external service? =

No. There are no external requests, no tracking and no analytics.

== Screenshots ==

1. The bulk management screen: filters at the top, matching products below, with selection carried across pages.
2. Building an attribute filter. Add Condition adds another attribute and value pair.
3. Two attribute conditions combined with AND, and the products matching both.
4. Setting stock status across a selection, including the quantity used for products that track one.
5. View details: the installed version, feature list and compatibility, read from the plugin itself.

== Changelog ==

= 1.4.0 =
* Stock status now works on products that track a stock quantity. WooCommerce derives their status from
  the quantity, so the quantity is set instead: zero for out of stock, and a quantity you choose for in
  stock. Previously these products were always skipped.
* Added an "Also set the stock quantity" option, on by default. Unticked, quantity-tracked products are
  skipped as before, with the reason given.
* Put back now restores the stock quantity as well as the stock status.
* Two impossible requests are now refused with an explanation instead of being half applied: emptying a
  product that allows backorders into out of stock, and setting a quantity at or below the store's
  out-of-stock threshold as in stock.
* Converting typed attributes is no longer limited to particular product statuses or products. The
  staging switches moved to `wp-config.php`, so a site that wants to rehearse a conversion can still
  restrict it.
* The plugin now declares WooCommerce as a required plugin, so WordPress 6.5 and later handles the
  dependency itself.
* Rewrote readme.txt in full, with installation steps, frequently asked questions, screenshots and this
  changelog.

= 1.3.9 =
* First release on the WordPress.org plugin directory.
* Find products by name, a list of SKUs, category, stock status and published state.
* Up to ten attribute conditions combined with AND, across shared and typed attributes.
* Bulk stock status, and bulk attribute operations: add, remove, replace, remove attribute, set a typed
  value.
* Every product fingerprinted before the write and read back afterwards.
* A run log of the last 25 runs, each one able to be put back.
* Convert typed attributes to shared attributes, with a dry run and a CSV report.

== Upgrade Notice ==

= 1.4.0 =
Setting stock status now works on products that track a stock quantity, which earlier versions always
skipped. Put back restores the quantity too. Conversion of typed attributes is no longer restricted to
particular products.
