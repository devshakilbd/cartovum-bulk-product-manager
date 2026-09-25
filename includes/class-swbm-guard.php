<?php
/**
 * The safety net.
 *
 * Before each write the product is fingerprinted, and after the write the fingerprint is taken
 * again. Anything that changed but was not meant to change is reported instead of passing silently.
 */

defined( 'ABSPATH' ) || exit;

class SWBM_Guard {

	/**
	 * Everything worth protecting on a product, flattened for comparison.
	 *
	 * @param WC_Product $product Product.
	 * @return array
	 */
	public static function fingerprint( $product ) {
		return array(
			'name'              => $product->get_name(),
			'slug'              => $product->get_slug(),
			'status'            => $product->get_status(),
			'type'              => $product->get_type(),
			'sku'               => (string) $product->get_sku(),
			'regular_price'     => (string) $product->get_regular_price(),
			'sale_price'        => (string) $product->get_sale_price(),
			'description'       => md5( (string) $product->get_description() ),
			'short_description' => md5( (string) $product->get_short_description() ),
			'image_id'          => (int) $product->get_image_id(),
			'gallery'           => implode( ',', (array) $product->get_gallery_image_ids() ),
			'categories'        => implode( ',', (array) $product->get_category_ids() ),
			'tags'              => implode( ',', (array) $product->get_tag_ids() ),
			'tax_status'        => (string) $product->get_tax_status(),
			'tax_class'         => (string) $product->get_tax_class(),
			'shipping_class_id' => (int) $product->get_shipping_class_id(),
			'weight'            => (string) $product->get_weight(),
			'dimensions'        => implode( 'x', array( $product->get_length(), $product->get_width(), $product->get_height() ) ),
			'manage_stock'      => $product->get_manage_stock() ? 'yes' : 'no',
			'stock_quantity'    => (string) $product->get_stock_quantity(),
			'backorders'        => (string) $product->get_backorders(),
			'menu_order'        => (int) $product->get_menu_order(),
			'catalog_visible'   => (string) $product->get_catalog_visibility(),
			'children'          => implode( ',', (array) $product->get_children() ),
			'stock_status'      => (string) $product->get_stock_status(),
			'attributes'        => SWBM_Attributes::signature( $product ),
		);
	}

	/**
	 * Fields that changed, ignoring the ones this operation was allowed to change.
	 *
	 * @param array    $before  Fingerprint before the write.
	 * @param array    $after   Fingerprint after the write.
	 * @param string[] $allowed Keys this operation is permitted to change.
	 * @return string[]
	 */
	public static function unexpected_changes( $before, $after, $allowed ) {
		$changed = array();

		foreach ( $before as $key => $value ) {
			if ( in_array( $key, $allowed, true ) ) {
				continue;
			}
			if ( ! array_key_exists( $key, $after ) || $after[ $key ] !== $value ) {
				$changed[] = $key;
			}
		}

		return $changed;
	}

	/**
	 * Read a product straight from the database, bypassing the caches warmed by the save.
	 *
	 * @param int $id Product ID.
	 * @return WC_Product|false
	 */
	public static function reread( $id ) {
		$id = (int) $id;

		clean_post_cache( $id );
		wp_cache_delete( $id, 'post_meta' );
		if ( function_exists( 'wc_delete_product_transients' ) ) {
			wc_delete_product_transients( $id );
		}

		return wc_get_product( $id );
	}
}
