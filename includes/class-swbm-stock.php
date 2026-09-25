<?php
/**
 * Setting stock status in bulk.
 *
 * Only the stock status is touched. Products whose stock is quantity-managed are skipped, because
 * WooCommerce recalculates their status from the quantity and any status written here would not
 * survive. Variable parents are skipped too: their status comes from their variations.
 */

defined( 'ABSPATH' ) || exit;

class SWBM_Stock {

	/** Stock statuses this screen may set. */
	const STATUSES = array( 'instock', 'outofstock', 'onbackorder' );

	/**
	 * Apply a stock status to one batch of products.
	 *
	 * @param int[]  $ids    Product IDs.
	 * @param string $status Target stock status.
	 * @return array[] Result rows.
	 */
	public static function apply( $ids, $status ) {
		$results = array();

		if ( ! in_array( $status, self::STATUSES, true ) ) {
			return $results;
		}

		foreach ( array_map( 'absint', (array) $ids ) as $id ) {
			$results[] = self::apply_one( $id, $status );
		}

		return $results;
	}

	/**
	 * @param int    $id     Product ID.
	 * @param string $status Target stock status.
	 * @return array Result row.
	 */
	private static function apply_one( $id, $status ) {
		$product = wc_get_product( $id );

		if ( ! $product ) {
			return self::row( $id, '', '', 'failed', __( 'Product not found.', 'cartovum-bulk-product-manager' ) );
		}

		$sku  = (string) $product->get_sku();
		$name = $product->get_name();

		if ( $product->is_type( 'variable' ) ) {
			return self::row( $id, $sku, $name, 'skipped', __( 'Variable product: its stock status is derived from its variations, so it was left alone.', 'cartovum-bulk-product-manager' ) );
		}

		if ( $product->get_manage_stock() ) {
			return self::row(
				$id,
				$sku,
				$name,
				'skipped',
				__( 'Stock quantity is managed for this product, so WooCommerce sets the status from the quantity. Change the quantity instead.', 'cartovum-bulk-product-manager' )
			);
		}

		$before = $product->get_stock_status();
		if ( $before === $status ) {
			return self::row( $id, $sku, $name, 'skipped', __( 'Already set to this stock status.', 'cartovum-bulk-product-manager' ) );
		}

		$fingerprint_before = SWBM_Guard::fingerprint( $product );

		try {
			$product->set_stock_status( $status );
			$product->save();
		} catch ( Exception $e ) {
			return self::row( $id, $sku, $name, 'failed', sprintf( /* translators: %s: error message */ __( 'WooCommerce rejected the change: %s', 'cartovum-bulk-product-manager' ), $e->getMessage() ) );
		}

		$fresh = SWBM_Guard::reread( $id );
		if ( ! $fresh ) {
			return self::row( $id, $sku, $name, 'failed', __( 'Saved, but the product could not be read back to confirm it.', 'cartovum-bulk-product-manager' ) );
		}

		if ( $fresh->get_stock_status() !== $status ) {
			return self::row(
				$id,
				$sku,
				$name,
				'failed',
				sprintf( /* translators: %s: stock status found after saving */ __( 'The save did not stick: the product still reads as %s.', 'cartovum-bulk-product-manager' ), $fresh->get_stock_status() )
			);
		}

		$unexpected = SWBM_Guard::unexpected_changes( $fingerprint_before, SWBM_Guard::fingerprint( $fresh ), array( 'stock_status' ) );
		if ( $unexpected ) {
			return self::row(
				$id,
				$sku,
				$name,
				'failed',
				sprintf( /* translators: %s: list of product fields */ __( 'Stock status changed, but these fields changed too and should not have: %s', 'cartovum-bulk-product-manager' ), implode( ', ', $unexpected ) ),
				array( 'stock_status' => $before ),
				array( 'stock_status' => $status )
			);
		}

		return self::row(
			$id,
			$sku,
			$name,
			'changed',
			sprintf( /* translators: 1: previous stock status, 2: new stock status */ __( '%1$s to %2$s', 'cartovum-bulk-product-manager' ), $before, $status ),
			array( 'stock_status' => $before ),
			array( 'stock_status' => $status )
		);
	}

	/**
	 * Put products back to the stock status they had before a run, but only where nothing
	 * has changed since.
	 *
	 * @param array[] $rows Recorded result rows to undo.
	 * @return array[] Result rows for the revert.
	 */
	public static function revert( $rows ) {
		$results = array();

		foreach ( (array) $rows as $row ) {
			$id      = isset( $row['id'] ) ? absint( $row['id'] ) : 0;
			$before  = isset( $row['before']['stock_status'] ) ? $row['before']['stock_status'] : '';
			$after   = isset( $row['after']['stock_status'] ) ? $row['after']['stock_status'] : '';
			$product = $id ? wc_get_product( $id ) : false;

			if ( ! $product || ! in_array( $before, self::STATUSES, true ) ) {
				$results[] = self::row( $id, '', '', 'failed', __( 'Nothing to put back for this product.', 'cartovum-bulk-product-manager' ) );
				continue;
			}

			if ( $product->get_stock_status() !== $after ) {
				$results[] = self::row(
					$id,
					(string) $product->get_sku(),
					$product->get_name(),
					'skipped',
					__( 'Changed since that run, so it was left as it is.', 'cartovum-bulk-product-manager' )
				);
				continue;
			}

			$results[] = self::apply_one( $id, $before );
		}

		return $results;
	}

	/**
	 * @param int    $id      Product ID.
	 * @param string $sku     SKU.
	 * @param string $name    Product name.
	 * @param string $status  changed, skipped, or failed.
	 * @param string $message Explanation shown in the results list.
	 * @param array  $before  State before the change.
	 * @param array  $after   State after the change.
	 * @return array
	 */
	private static function row( $id, $sku, $name, $status, $message, $before = array(), $after = array() ) {
		return array(
			'id'      => (int) $id,
			'sku'     => $sku,
			'name'    => $name,
			'status'  => $status,
			'message' => $message,
			'before'  => $before,
			'after'   => $after,
		);
	}
}
