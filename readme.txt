=== Cartovum Bulk Product Manager ===
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 7.4
WC requires at least: 6.0
WC tested up to: 11.1
Stable tag: 1.3.9
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Bulk-edit WooCommerce stock status and product attributes in small batches, with a change log and put-back.

== Description ==

Developed by Cartovum Agency (https://cartovumagency.com). "View details" at the top of the screen shows
the installed version, features, and compatibility.

== Where it lives ==

Products -> Bulk Stock & Attributes
/wp-admin/edit.php?post_type=product&page=swbm-bulk-manager

Requires the "manage_woocommerce" capability (shop managers and administrators).

== What it does ==

Find products by name, by a list of SKUs, by category, by stock status, by published state, or by
an attribute and one of its values. Select what you find, across pages, then either:

* set the stock status of everything selected, or
* run one attribute operation across everything selected:
  - add values to an attribute
  - remove values from an attribute
  - replace the values of an attribute
  - remove the attribute
  - set the value of a local attribute

The browser sends the selection to the server in batches of ten, so no single request has to do
all the work. Progress is shown while it runs and every product gets its own line in the results:
changed, skipped, or failed, each with the reason.

== What it will not do ==

* It never creates a global attribute or an attribute value. Only values that already exist can be
  chosen, so a typo cannot quietly add a new term.
* It never converts a local (custom) attribute into a global one, or the other way round. If a
  product already carries a local attribute of the same name, adding the global one is skipped and
  said so, rather than leaving the product with two attributes of one name.
* It leaves variable products alone, and leaves any attribute marked "used for variations" alone.
* It skips products whose stock is quantity-managed, because WooCommerce works their stock status
  out from the quantity.
* It never deletes a product, a variation, a category, an attribute, or an attribute term.

== How it protects the rest of the product ==

Before each write the product is fingerprinted: name, SKU, prices, description, images, gallery,
categories, tags, tax, shipping class, weight, dimensions, stock quantity, backorders, menu order,
catalogue visibility, and attributes. After the write the fingerprint is taken again. Anything that
changed but was not part of the requested operation is reported as a failure rather than passing
silently.

Every change goes through the WooCommerce product API, so lookup tables, caches, feeds and other
integrations stay in step exactly as they do when a product is saved by hand.

== Converting typed attributes to shared attributes ==

The "Convert typed attributes" panel moves a typed (local) attribute onto the shared attribute with the
same name, for example the typed Rim Size value `18` onto the shared Rim Size value `18"` (18 inches).

* A typed value only moves when it matches exactly one shared value once units and quotation marks are
  set aside (`18` matches `18"`, `112` matches `112mm`), or when an administrator has approved that
  specific mapping.
* Values on hold, values with no match, and values matching more than one shared value block the whole
  product. It is left exactly as it is and listed under "Needs attention".
* If the product already has the shared attribute with the same value, only the typed copy is removed.
  If the values differ, the product is blocked.
* Each product converts in a single save and keeps every attribute's position and visibility. After the
  save it is read back: converted attributes must hold exactly the planned values, other attributes must
  be untouched, and nothing outside the attributes may have changed.
* Run the dry run first. It records a signature for every product; a product edited after the dry run is
  skipped rather than overwritten. A conversion run stops at the first product that fails its check.
* Conversion runs appear in Recent runs and can be put back like any other run.

Approved mappings, held values and the list of values created for the migration are stored in the
options swbm_convert_overrides, swbm_convert_holds and swbm_convert_new_terms.

== Putting a run back ==

Each run is recorded with the state of every product before and after the change. In "Recent runs",
"Put back" restores the products that run changed. A product that has changed since is skipped
rather than overwritten. The last 25 runs are kept.
