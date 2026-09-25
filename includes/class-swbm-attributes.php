<?php
/**
 * Bulk attribute edits.
 *
 * Rules that hold for every operation here:
 *
 * - Global attributes and their terms are only ever selected, never created. A value that does not
 *   already exist as a term is refused rather than added.
 * - A local (custom) attribute is never turned into a global one, and a global one is never turned
 *   into a local one. If a product already carries a local attribute of the same name, adding the
 *   global one is skipped instead of leaving the product with two attributes of one name.
 * - Attributes the operation does not name are rebuilt exactly as they were, including their
 *   position, visibility and "used for variations" flag.
 * - Attributes used for variations, and variable products as a whole, are left alone.
 */

defined( 'ABSPATH' ) || exit;

class SWBM_Attributes {

	/** Operations this screen can run. */
	const OPS = array( 'add_terms', 'remove_terms', 'set_terms', 'remove_attribute', 'set_local_value' );

	const LOCAL_NAMES_TRANSIENT = 'swbm_local_attribute_names';

	/** Cache of typed-attribute values in use, per attribute name, for the filter's value dropdowns. */
	const LOCAL_VALUES_TRANSIENT = 'swbm_local_attribute_values';

	/** Object-cache group for this plugin's catalogue scans. */
	const CACHE_GROUP = 'swbm_attributes';

	/**
	 * A marker that changes whenever this plugin edits attributes. Cached scans carry it in their key, so
	 * anything cached before an edit is never read again. An object cache that only lasts one request is
	 * fine: the scans simply run once per request instead.
	 *
	 * @return string
	 */
	public static function cache_generation() {
		$generation = wp_cache_get( 'generation', self::CACHE_GROUP );

		if ( ! is_string( $generation ) || '' === $generation ) {
			$generation = uniqid( '', true );
			wp_cache_set( 'generation', $generation, self::CACHE_GROUP );
		}

		return $generation;
	}

	/**
	 * Validate an operation described by the request.
	 *
	 * @param array $raw Raw request data.
	 * @return array|WP_Error {op, attribute, taxonomy, label, term_ids, value}
	 */
	public static function parse_op( $raw ) {
		$op        = isset( $raw['op'] ) ? sanitize_key( $raw['op'] ) : '';
		$attribute = isset( $raw['attribute'] ) ? wc_clean( wp_unslash( $raw['attribute'] ) ) : '';

		if ( ! in_array( $op, self::OPS, true ) ) {
			return new WP_Error( 'swbm_bad_op', __( 'Unknown attribute operation.', 'cartovum-bulk-product-manager' ) );
		}
		if ( '' === $attribute ) {
			return new WP_Error( 'swbm_no_attribute', __( 'Choose an attribute first.', 'cartovum-bulk-product-manager' ) );
		}

		$is_local = 0 === strpos( $attribute, 'local:' );
		$taxonomy = $is_local ? '' : $attribute;
		$label    = $is_local ? substr( $attribute, 6 ) : wc_attribute_label( $attribute );

		if ( ! $is_local && ! in_array( $taxonomy, wc_get_attribute_taxonomy_names(), true ) ) {
			return new WP_Error( 'swbm_unknown_attribute', __( 'That global attribute does not exist. Attributes are never created by this tool.', 'cartovum-bulk-product-manager' ) );
		}
		if ( $is_local && ! in_array( $op, array( 'remove_attribute', 'set_local_value' ), true ) ) {
			return new WP_Error( 'swbm_local_op', __( 'For a local attribute you can change its value or remove it. Term operations apply to global attributes only.', 'cartovum-bulk-product-manager' ) );
		}
		if ( ! $is_local && 'set_local_value' === $op ) {
			return new WP_Error( 'swbm_global_op', __( 'A global attribute takes terms, not free text.', 'cartovum-bulk-product-manager' ) );
		}

		$term_ids = array();
		if ( in_array( $op, array( 'add_terms', 'remove_terms', 'set_terms' ), true ) ) {
			foreach ( (array) ( isset( $raw['terms'] ) ? $raw['terms'] : array() ) as $term_id ) {
				$term_id = absint( $term_id );
				$term    = $term_id ? get_term( $term_id, $taxonomy ) : null;

				if ( ! $term || is_wp_error( $term ) ) {
					return new WP_Error(
						'swbm_unknown_term',
						sprintf( /* translators: %d: term id */ __( 'Value %d does not exist for this attribute. Existing values only: this tool never creates new ones.', 'cartovum-bulk-product-manager' ), $term_id )
					);
				}

				$term_ids[] = $term_id;
			}

			if ( ! $term_ids && 'set_terms' !== $op ) {
				return new WP_Error( 'swbm_no_terms', __( 'Choose at least one value.', 'cartovum-bulk-product-manager' ) );
			}
		}

		$value = isset( $raw['value'] ) ? wc_clean( wp_unslash( $raw['value'] ) ) : '';
		if ( 'set_local_value' === $op && '' === trim( $value ) ) {
			return new WP_Error( 'swbm_no_value', __( 'Enter a value. To clear the attribute instead, use "remove attribute".', 'cartovum-bulk-product-manager' ) );
		}

		return array(
			'op'        => $op,
			'attribute' => $attribute,
			'is_local'  => $is_local,
			'taxonomy'  => $taxonomy,
			'label'     => $label,
			'term_ids'  => array_values( array_unique( $term_ids ) ),
			'value'     => $value,
		);
	}

	/**
	 * Run one operation over a batch of products.
	 *
	 * @param int[] $ids Product IDs.
	 * @param array $op  Parsed operation.
	 * @return array[] Result rows.
	 */
	public static function apply( $ids, $op ) {
		$results = array();

		foreach ( array_map( 'absint', (array) $ids ) as $id ) {
			$results[] = self::apply_one( $id, $op );
		}

		self::flush_local_attribute_cache();

		return $results;
	}

	/**
	 * @param int   $id Product ID.
	 * @param array $op Parsed operation.
	 * @return array Result row.
	 */
	private static function apply_one( $id, $op ) {
		$product = wc_get_product( $id );

		if ( ! $product ) {
			return self::row( $id, '', '', 'failed', __( 'Product not found.', 'cartovum-bulk-product-manager' ) );
		}

		$sku  = (string) $product->get_sku();
		$name = $product->get_name();

		if ( $product->is_type( 'variable' ) || $product->is_type( 'variation' ) ) {
			return self::row( $id, $sku, $name, 'skipped', __( 'Variable product: its attributes drive its variations, so this tool leaves them alone.', 'cartovum-bulk-product-manager' ) );
		}

		$existing = $product->get_attributes();
		$before   = self::snapshot( $product );
		$key      = $op['is_local'] ? sanitize_title( substr( $op['attribute'], 6 ) ) : sanitize_title( $op['taxonomy'] );

		if ( isset( $existing[ $key ] ) && $existing[ $key ]->get_variation() ) {
			return self::row( $id, $sku, $name, 'skipped', __( 'This attribute is marked "used for variations" on this product, so it was left alone.', 'cartovum-bulk-product-manager' ) );
		}

		$plan = self::plan( $existing, $key, $op );
		if ( is_wp_error( $plan ) ) {
			return self::row( $id, $sku, $name, 'skipped', $plan->get_error_message() );
		}
		if ( null === $plan ) {
			return self::row( $id, $sku, $name, 'skipped', __( 'Already as requested; nothing to change.', 'cartovum-bulk-product-manager' ) );
		}

		$fingerprint_before = SWBM_Guard::fingerprint( $product );

		try {
			$product->set_attributes( $plan );
			$product->save();
		} catch ( Exception $e ) {
			return self::row( $id, $sku, $name, 'failed', sprintf( /* translators: %s: error message */ __( 'WooCommerce rejected the change: %s', 'cartovum-bulk-product-manager' ), $e->getMessage() ) );
		}

		$fresh = SWBM_Guard::reread( $id );
		if ( ! $fresh ) {
			return self::row( $id, $sku, $name, 'failed', __( 'Saved, but the product could not be read back to confirm it.', 'cartovum-bulk-product-manager' ) );
		}

		$after      = self::snapshot( $fresh );
		$unexpected = SWBM_Guard::unexpected_changes( $fingerprint_before, SWBM_Guard::fingerprint( $fresh ), array( 'attributes' ) );

		if ( $unexpected ) {
			return self::row(
				$id,
				$sku,
				$name,
				'failed',
				sprintf( /* translators: %s: list of product fields */ __( 'Attributes changed, but these fields changed too and should not have: %s', 'cartovum-bulk-product-manager' ), implode( ', ', $unexpected ) ),
				$before,
				$after
			);
		}

		return self::row( $id, $sku, $name, 'changed', self::describe( $before, $after, $op ), $before, $after );
	}

	/**
	 * Work out the full attribute list to save, or a reason not to.
	 *
	 * The existing attributes are cloned first. WooCommerce compares the new attribute list
	 * against the one it is holding, so editing those objects in place would leave it seeing
	 * no change at all, and the save would write nothing.
	 *
	 * @param WC_Product_Attribute[] $existing Current attributes, keyed as WooCommerce keys them.
	 * @param string                 $key      Key of the attribute this operation targets.
	 * @param array                  $op       Parsed operation.
	 * @return WC_Product_Attribute[]|WP_Error|null
	 */
	private static function plan( $existing, $key, $op ) {
		$list = array();
		foreach ( $existing as $existing_key => $attribute ) {
			$list[ $existing_key ] = clone $attribute;
		}

		$current = isset( $list[ $key ] ) ? $list[ $key ] : null;

		switch ( $op['op'] ) {
			case 'remove_attribute':
				if ( ! $current ) {
					return null;
				}

				unset( $list[ $key ] );

				return array_values( $list );

			case 'set_local_value':
				if ( ! $current ) {
					return new WP_Error( 'swbm_missing', __( 'This product does not have that local attribute, and this tool does not add local attributes.', 'cartovum-bulk-product-manager' ) );
				}

				$options = array_values( array_filter( array_map( 'trim', explode( '|', $op['value'] ) ), 'strlen' ) );
				if ( $options === array_map( 'strval', $current->get_options() ) ) {
					return null;
				}

				$current->set_options( $options );

				return array_values( $list );

			case 'add_terms':
			case 'remove_terms':
			case 'set_terms':
				return self::plan_terms( $list, $key, $op );
		}

		return new WP_Error( 'swbm_bad_op', __( 'Unknown attribute operation.', 'cartovum-bulk-product-manager' ) );
	}

	/**
	 * Term changes for a global attribute.
	 *
	 * @param WC_Product_Attribute[] $list Cloned attributes, keyed as WooCommerce keys them.
	 * @param string                 $key  Key of the targeted attribute.
	 * @param array                  $op   Parsed operation.
	 * @return WC_Product_Attribute[]|WP_Error|null
	 */
	private static function plan_terms( $list, $key, $op ) {
		$wanted  = $op['term_ids'];
		$current = isset( $list[ $key ] ) ? $list[ $key ] : null;

		if ( ! $current ) {
			if ( in_array( $op['op'], array( 'remove_terms' ), true ) ) {
				return null;
			}

			$clash = self::local_clash( $list, $op['label'] );
			if ( $clash ) {
				return new WP_Error(
					'swbm_local_clash',
					sprintf(
						/* translators: %s: attribute name */
						__( 'This product already has "%s" as a local attribute. Adding the global one would leave two attributes of the same name, so it was skipped.', 'cartovum-bulk-product-manager' ),
						$clash
					)
				);
			}

			if ( ! $wanted ) {
				return null;
			}

			$new = new WC_Product_Attribute();
			$new->set_id( wc_attribute_taxonomy_id_by_name( $op['taxonomy'] ) );
			$new->set_name( $op['taxonomy'] );
			$new->set_options( $wanted );
			$new->set_position( self::next_position( $list ) );
			$new->set_visible( true );
			$new->set_variation( false );

			$list[ $key ] = $new;

			return array_values( $list );
		}

		$have = array_map( 'absint', (array) $current->get_options() );

		switch ( $op['op'] ) {
			case 'add_terms':
				$result = array_values( array_unique( array_merge( $have, $wanted ) ) );
				break;
			case 'remove_terms':
				$result = array_values( array_diff( $have, $wanted ) );
				break;
			default:
				$result = $wanted;
				break;
		}

		sort( $have );
		$sorted_result = $result;
		sort( $sorted_result );

		if ( $have === $sorted_result ) {
			return null;
		}

		if ( ! $result ) {
			// An attribute with no values would be meaningless, so the attribute itself goes.
			unset( $list[ $key ] );

			return array_values( $list );
		}

		$current->set_options( $result );

		return array_values( $list );
	}

	/**
	 * The name of a local attribute on this product that clashes with the given label.
	 *
	 * @param WC_Product_Attribute[] $list  Attributes.
	 * @param string                 $label Global attribute label.
	 * @return string Empty when there is no clash.
	 */
	private static function local_clash( $list, $label ) {
		foreach ( $list as $attribute ) {
			if ( $attribute->is_taxonomy() ) {
				continue;
			}
			if ( 0 === strcasecmp( trim( $attribute->get_name() ), trim( $label ) ) ) {
				return $attribute->get_name();
			}
		}

		return '';
	}

	/**
	 * @param WC_Product_Attribute[] $list Attributes.
	 * @return int
	 */
	private static function next_position( $list ) {
		$positions = array( -1 );
		foreach ( $list as $attribute ) {
			$positions[] = (int) $attribute->get_position();
		}

		return max( $positions ) + 1;
	}

	/**
	 * A product's attributes, flattened for the change log and for reverting.
	 *
	 * @param WC_Product $product Product.
	 * @return array[]
	 */
	public static function snapshot( $product ) {
		$snapshot = array();

		foreach ( $product->get_attributes() as $attribute ) {
			$snapshot[] = array(
				'id'        => (int) $attribute->get_id(),
				'name'      => $attribute->get_name(),
				'taxonomy'  => $attribute->is_taxonomy(),
				'label'     => $attribute->is_taxonomy() ? wc_attribute_label( $attribute->get_name() ) : $attribute->get_name(),
				'options'   => array_values( $attribute->get_options() ),
				'position'  => (int) $attribute->get_position(),
				'visible'   => (bool) $attribute->get_visible(),
				'variation' => (bool) $attribute->get_variation(),
			);
		}

		return $snapshot;
	}

	/**
	 * A stable string for comparing a product's attributes before and after a write.
	 *
	 * @param WC_Product $product Product.
	 * @return string
	 */
	public static function signature( $product ) {
		$snapshot = self::snapshot( $product );

		usort(
			$snapshot,
			function ( $a, $b ) {
				return strcmp( $a['name'], $b['name'] );
			}
		);

		foreach ( $snapshot as $i => $row ) {
			$options = array_map( 'strval', $row['options'] );
			sort( $options );
			$snapshot[ $i ]['options'] = $options;
		}

		return wp_json_encode( $snapshot );
	}

	/**
	 * Rebuild a product's attributes from a snapshot. Used by revert.
	 *
	 * @param WC_Product $product  Product.
	 * @param array[]    $snapshot Snapshot rows.
	 * @return void
	 */
	public static function restore( $product, $snapshot ) {
		$list = array();

		foreach ( (array) $snapshot as $row ) {
			$attribute = new WC_Product_Attribute();
			$attribute->set_id( (int) $row['id'] );
			$attribute->set_name( $row['name'] );
			$attribute->set_options( $row['options'] );
			$attribute->set_position( (int) $row['position'] );
			$attribute->set_visible( ! empty( $row['visible'] ) );
			$attribute->set_variation( ! empty( $row['variation'] ) );

			$list[] = $attribute;
		}

		$product->set_attributes( $list );
	}

	/**
	 * Put attributes back as they were before a run, where nothing has changed since.
	 *
	 * @param array[] $rows Recorded result rows to undo.
	 * @return array[] Result rows for the revert.
	 */
	public static function revert( $rows ) {
		$results = array();

		foreach ( (array) $rows as $row ) {
			$id      = isset( $row['id'] ) ? absint( $row['id'] ) : 0;
			$product = $id ? wc_get_product( $id ) : false;

			if ( ! $product || empty( $row['after'] ) ) {
				$results[] = self::row( $id, '', '', 'failed', __( 'Nothing to put back for this product.', 'cartovum-bulk-product-manager' ) );
				continue;
			}

			$sku  = (string) $product->get_sku();
			$name = $product->get_name();

			if ( self::signature( $product ) !== self::signature_of( $row['after'] ) ) {
				$results[] = self::row( $id, $sku, $name, 'skipped', __( 'Attributes changed since that run, so they were left as they are.', 'cartovum-bulk-product-manager' ) );
				continue;
			}

			$fingerprint_before = SWBM_Guard::fingerprint( $product );

			try {
				self::restore( $product, $row['before'] );
				$product->save();
			} catch ( Exception $e ) {
				$results[] = self::row( $id, $sku, $name, 'failed', sprintf( /* translators: %s: error message */ __( 'WooCommerce rejected the change: %s', 'cartovum-bulk-product-manager' ), $e->getMessage() ) );
				continue;
			}

			$fresh = SWBM_Guard::reread( $id );
			if ( ! $fresh ) {
				$results[] = self::row( $id, $sku, $name, 'failed', __( 'Saved, but the product could not be read back to confirm it.', 'cartovum-bulk-product-manager' ) );
				continue;
			}

			$unexpected = SWBM_Guard::unexpected_changes( $fingerprint_before, SWBM_Guard::fingerprint( $fresh ), array( 'attributes' ) );
			$status     = $unexpected ? 'failed' : 'changed';
			$message    = $unexpected
				? sprintf( /* translators: %s: list of product fields */ __( 'Put back, but these fields changed too: %s', 'cartovum-bulk-product-manager' ), implode( ', ', $unexpected ) )
				: __( 'Attributes put back as they were.', 'cartovum-bulk-product-manager' );

			$results[] = self::row( $id, $sku, $name, $status, $message, $row['after'], self::snapshot( $fresh ) );
		}

		self::flush_local_attribute_cache();

		return $results;
	}

	/**
	 * Signature of a stored snapshot, comparable with signature().
	 *
	 * @param array[] $snapshot Snapshot rows.
	 * @return string
	 */
	private static function signature_of( $snapshot ) {
		$snapshot = (array) $snapshot;

		usort(
			$snapshot,
			function ( $a, $b ) {
				return strcmp( $a['name'], $b['name'] );
			}
		);

		foreach ( $snapshot as $i => $row ) {
			$options = array_map( 'strval', (array) $row['options'] );
			sort( $options );
			$snapshot[ $i ]['options'] = $options;
		}

		return wp_json_encode( array_values( $snapshot ) );
	}

	/**
	 * Plain-language description of what changed on one product.
	 *
	 * @param array[] $before Snapshot before.
	 * @param array[] $after  Snapshot after.
	 * @param array   $op     Parsed operation.
	 * @return string
	 */
	private static function describe( $before, $after, $op ) {
		$find = function ( $snapshot ) use ( $op ) {
			foreach ( (array) $snapshot as $row ) {
				$match = $op['is_local'] ? ( 0 === strcasecmp( $row['name'], substr( $op['attribute'], 6 ) ) ) : ( $row['name'] === $op['taxonomy'] );
				if ( $match ) {
					return $row;
				}
			}

			return null;
		};

		$was = $find( $before );
		$now = $find( $after );

		$values = function ( $row ) use ( $op ) {
			if ( ! $row ) {
				return __( 'not set', 'cartovum-bulk-product-manager' );
			}

			$options = $row['options'];
			if ( ! empty( $row['taxonomy'] ) ) {
				$names = array();
				foreach ( $options as $term_id ) {
					$term = get_term( (int) $term_id, $op['taxonomy'] );
					if ( $term && ! is_wp_error( $term ) ) {
						$names[] = $term->name;
					}
				}
				$options = $names;
			}

			return $options ? implode( ', ', $options ) : __( 'empty', 'cartovum-bulk-product-manager' );
		};

		return sprintf(
			/* translators: 1: attribute name, 2: previous values, 3: new values */
			__( '%1$s: %2$s to %3$s', 'cartovum-bulk-product-manager' ),
			$op['label'],
			$values( $was ),
			$values( $now )
		);
	}

	/**
	 * Global attributes and their terms, for the operation form.
	 *
	 * @return array[]
	 */
	public static function global_attributes() {
		$out = array();

		foreach ( wc_get_attribute_taxonomies() as $taxonomy ) {
			$name  = wc_attribute_taxonomy_name( $taxonomy->attribute_name );
			$terms = get_terms(
				array(
					'taxonomy'   => $name,
					'hide_empty' => false,
				)
			);

			$out[] = array(
				'key'   => $name,
				'label' => $taxonomy->attribute_label ? $taxonomy->attribute_label : $taxonomy->attribute_name,
				'terms' => is_wp_error( $terms ) ? array() : array_map(
					function ( $term ) {
						return array(
							'id'    => (int) $term->term_id,
							'name'  => $term->name,
							'count' => (int) $term->count,
						);
					},
					$terms
				),
			);
		}

		return $out;
	}

	/**
	 * Local attribute names in use across the catalogue, for the operation form and the filter.
	 *
	 * @return string[]
	 */
	public static function local_attribute_names() {
		$names = array_map( 'strval', array_keys( self::local_attribute_values() ) );
		sort( $names );

		return $names;
	}

	/**
	 * Local attribute values in use, per attribute name, with how many products carry each value.
	 * One scan of the catalogue, cached for 12 hours and cleared whenever this plugin changes attributes.
	 *
	 * @return array [ name => [ [ value, count ], ... ] ], values in natural order.
	 */
	public static function local_attribute_values() {
		$cached = get_transient( self::LOCAL_VALUES_TRANSIENT );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		global $wpdb;

		$counts = array();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- fixed query with no user input; WordPress has no API for reading serialised meta in bulk, and the result is held for 12 hours in the transient read above and cleared whenever this plugin changes attributes.
		$rows = $wpdb->get_col( "SELECT meta_value FROM {$wpdb->postmeta} WHERE meta_key = '_product_attributes' LIMIT 10000" );

		foreach ( (array) $rows as $row ) {
			$attributes = maybe_unserialize( $row );
			if ( ! is_array( $attributes ) ) {
				continue;
			}

			foreach ( $attributes as $attribute ) {
				if ( ! is_array( $attribute ) || ! empty( $attribute['is_taxonomy'] ) || empty( $attribute['name'] ) ) {
					continue;
				}

				$name   = (string) $attribute['name'];
				$values = array_unique( array_filter( array_map( 'trim', explode( WC_DELIMITER, isset( $attribute['value'] ) ? (string) $attribute['value'] : '' ) ), 'strlen' ) );

				if ( ! isset( $counts[ $name ] ) ) {
					$counts[ $name ] = array();
				}
				foreach ( $values as $value ) {
					$counts[ $name ][ $value ] = isset( $counts[ $name ][ $value ] ) ? $counts[ $name ][ $value ] + 1 : 1;
				}
			}
		}

		// A dropdown stays usable: at most this many values per attribute are offered.
		$limit = 300;
		$out   = array();

		foreach ( $counts as $name => $values ) {
			uksort( $values, 'strnatcasecmp' );

			$out[ $name ] = array();
			foreach ( array_slice( $values, 0, $limit, true ) as $value => $count ) {
				$out[ $name ][] = array(
					'value' => (string) $value,
					'count' => (int) $count,
				);
			}
		}

		set_transient( self::LOCAL_VALUES_TRANSIENT, $out, 12 * HOUR_IN_SECONDS );

		return $out;
	}

	/**
	 * Forget the cached local attribute names and values, after anything changes attributes.
	 *
	 * @return void
	 */
	public static function flush_local_attribute_cache() {
		delete_transient( self::LOCAL_NAMES_TRANSIENT );
		delete_transient( self::LOCAL_VALUES_TRANSIENT );

		// Moves the marker on, which retires every scan held in the object cache.
		wp_cache_set( 'generation', uniqid( '', true ), self::CACHE_GROUP );
	}

	/**
	 * @param int    $id      Product ID.
	 * @param string $sku     SKU.
	 * @param string $name    Product name.
	 * @param string $status  changed, skipped, or failed.
	 * @param string $message Explanation shown in the results list.
	 * @param array  $before  Attribute snapshot before.
	 * @param array  $after   Attribute snapshot after.
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
