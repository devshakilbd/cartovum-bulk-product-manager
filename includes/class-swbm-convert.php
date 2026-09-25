<?php
/**
 * Converting typed (local) attributes into the shared (global) attribute of the same name.
 *
 * Nothing here guesses. A typed value moves to a shared value only when:
 *
 * - it matches exactly one shared value once units and quotes are set aside
 *   (18 matches 18", 112 matches 112mm, 57.1 matches 57.1 but never 57.10 or 57.2), or
 * - an approved decision maps that specific value (2024-ONWARDS to 2024-Present), or
 * - it matches a new shared value approved for creation (shown as "to be created" until it exists).
 *
 * A value on hold, a value with no match, or a value matching more than one shared value blocks the
 * whole product. That product is left exactly as it is and reported.
 *
 * While SWBM_CONVERT_DRY_RUN_ONLY is true, nothing in this class writes: apply() refuses every product.
 *
 * When conversion is enabled, each product converts in one save. Every attribute keeps its position and
 * visibility, and after the save the product is read back: the converted attributes must hold exactly the
 * planned values, every other attribute must be untouched, and nothing outside the attributes may change.
 */

defined( 'ABSPATH' ) || exit;

class SWBM_Convert {

	/** Approved non-exact mappings stored in the database: [ taxonomy => [ typed value => term_id ] ]. */
	const OVERRIDES_OPTION = 'swbm_convert_overrides';

	/** Values held back in the database: [ taxonomy => [ typed value => reason ] ]. */
	const HOLDS_OPTION = 'swbm_convert_holds';

	/** Term IDs created for this migration, so reports can tell new values from existing ones. */
	const NEW_TERMS_OPTION = 'swbm_convert_new_terms';

	/** @var array Normalised term lookups, per taxonomy, for the life of the request. */
	private static $term_index = array();

	/** @var array|null Decisions file, loaded once. */
	private static $decisions = null;

	/**
	 * Whether conversion may write to products. False while the site is in dry-run-only mode.
	 *
	 * @return bool
	 */
	public static function writes_enabled() {
		return ! ( defined( 'SWBM_CONVERT_DRY_RUN_ONLY' ) && SWBM_CONVERT_DRY_RUN_ONLY );
	}

	/**
	 * Product statuses that may be converted at the current stage, from SWBM_CONVERT_ALLOWED_STATUSES
	 * (comma separated). Empty means no status restriction.
	 *
	 * @return string[]
	 */
	public static function allowed_statuses() {
		if ( ! defined( 'SWBM_CONVERT_ALLOWED_STATUSES' ) ) {
			return array();
		}

		return array_values( array_filter( array_map( 'trim', explode( ',', (string) SWBM_CONVERT_ALLOWED_STATUSES ) ), 'strlen' ) );
	}

	/**
	 * Individual products that may also be converted at the current stage whatever their status, from
	 * SWBM_CONVERT_ALLOWED_IDS (comma separated). Only matters while statuses are restricted.
	 *
	 * @return int[]
	 */
	public static function allowed_ids() {
		if ( ! defined( 'SWBM_CONVERT_ALLOWED_IDS' ) ) {
			return array();
		}

		return array_values( array_filter( array_map( 'absint', explode( ',', (string) SWBM_CONVERT_ALLOWED_IDS ) ) ) );
	}

	/**
	 * Approved decisions from includes/convert-decisions.php.
	 *
	 * @return array {planned_terms, overrides, holds}
	 */
	public static function decisions() {
		if ( null === self::$decisions ) {
			$file            = SWBM_PATH . 'includes/convert-decisions.php';
			$loaded          = file_exists( $file ) ? include $file : array();
			self::$decisions = wp_parse_args(
				is_array( $loaded ) ? $loaded : array(),
				array(
					'planned_terms' => array(),
					'overrides'     => array(),
					'holds'         => array(),
				)
			);
		}

		return self::$decisions;
	}

	/**
	 * Typed attribute name and shared attribute that belong together: pairs share a label.
	 *
	 * @return array[]
	 */
	public static function pairs() {
		$pairs = array();

		foreach ( wc_get_attribute_taxonomies() as $taxonomy ) {
			$label = $taxonomy->attribute_label ? $taxonomy->attribute_label : $taxonomy->attribute_name;

			$pairs[] = array(
				'local'        => $label,
				'taxonomy'     => wc_attribute_taxonomy_name( $taxonomy->attribute_name ),
				'attribute_id' => (int) $taxonomy->attribute_id,
				'label'        => $label,
			);
		}

		return $pairs;
	}

	/**
	 * Comparable form of a value. Units are only set aside when what remains is a plain number,
	 * so text values are compared as text.
	 *
	 * @param string $value Raw value.
	 * @return string
	 */
	public static function normalise( $value ) {
		$value = wp_specialchars_decode( (string) $value, ENT_QUOTES );
		$value = strtolower( trim( $value ) );
		$value = str_replace( array( '"', "\u{201D}", "\u{201C}", "\u{2033}" ), '', $value );
		$value = preg_replace( '/\s+/u', '', $value );

		if ( preg_match( '/^(\d+(?:\.\d+)?)(mm|inches|inch|in)?$/', $value, $match ) ) {
			return $match[1];
		}

		return $value;
	}

	/**
	 * Where one typed value would go.
	 *
	 * @param string $taxonomy Target taxonomy.
	 * @param string $value    Typed value.
	 * @return array {kind, ok, term_id, term, new_term, note}
	 */
	public static function resolve( $taxonomy, $value ) {
		$decisions = self::decisions();
		$holds     = self::option( self::HOLDS_OPTION );
		$overrides = self::option( self::OVERRIDES_OPTION );

		if ( isset( $decisions['holds'][ $taxonomy ][ $value ] ) ) {
			return self::resolution( 'held', null, (string) $decisions['holds'][ $taxonomy ][ $value ], $taxonomy );
		}
		if ( isset( $holds[ $taxonomy ][ $value ] ) ) {
			return self::resolution( 'held', null, (string) $holds[ $taxonomy ][ $value ], $taxonomy );
		}

		if ( isset( $decisions['overrides'][ $taxonomy ][ $value ] ) ) {
			$target = $decisions['overrides'][ $taxonomy ][ $value ];
			$term   = get_term( (int) $target['term_id'], $taxonomy );

			if ( $term && ! is_wp_error( $term ) && $term->name === $target['name'] ) {
				return self::resolution( 'override', $term, __( 'Approved mapping.', 'cartovum-bulk-product-manager' ), $taxonomy );
			}

			return self::resolution( 'unmapped', null, __( 'The approved mapping no longer points at the approved value.', 'cartovum-bulk-product-manager' ), $taxonomy );
		}

		if ( isset( $overrides[ $taxonomy ][ $value ] ) ) {
			$term = get_term( (int) $overrides[ $taxonomy ][ $value ], $taxonomy );

			return ( $term && ! is_wp_error( $term ) )
				? self::resolution( 'override', $term, __( 'Approved mapping.', 'cartovum-bulk-product-manager' ), $taxonomy )
				: self::resolution( 'unmapped', null, __( 'The approved mapping points at a value that no longer exists.', 'cartovum-bulk-product-manager' ), $taxonomy );
		}

		$index   = self::term_index( $taxonomy );
		$key     = self::normalise( $value );
		$matches = isset( $index[ $key ] ) ? $index[ $key ] : array();

		if ( 1 === count( $matches ) ) {
			return self::resolution( 'exact', $matches[0], '', $taxonomy );
		}

		if ( count( $matches ) > 1 ) {
			return self::resolution(
				'ambiguous',
				null,
				sprintf( /* translators: %s: list of values */ __( 'Matches more than one shared value: %s', 'cartovum-bulk-product-manager' ), implode( ', ', wp_list_pluck( $matches, 'name' ) ) ),
				$taxonomy
			);
		}

		$planned = isset( $decisions['planned_terms'][ $taxonomy ] ) ? (array) $decisions['planned_terms'][ $taxonomy ] : array();
		foreach ( $planned as $name ) {
			if ( self::normalise( $name ) === $key ) {
				return array(
					'kind'     => 'planned',
					'ok'       => true,
					'term_id'  => 0,
					'term'     => (string) $name,
					'new_term' => true,
					'note'     => __( 'Approved new value; it has not been created yet.', 'cartovum-bulk-product-manager' ),
				);
			}
		}

		return self::resolution( 'unmapped', null, __( 'No shared value matches.', 'cartovum-bulk-product-manager' ), $taxonomy );
	}

	/**
	 * What converting this product would do, without doing it.
	 *
	 * @param WC_Product $product Product.
	 * @return array {state, rows, note, signature}
	 */
	public static function plan( $product ) {
		$attributes = $product->get_attributes();
		$rows       = array();

		foreach ( self::pairs() as $pair ) {
			$local_key = '';
			foreach ( $attributes as $key => $attribute ) {
				if ( ! $attribute->is_taxonomy() && 0 === strcasecmp( trim( $attribute->get_name() ), $pair['local'] ) ) {
					$local_key = $key;
					break;
				}
			}

			if ( '' === $local_key ) {
				continue;
			}

			$rows[] = self::plan_row( $attributes, $local_key, $pair );
		}

		$plan = array(
			'state'     => 'none',
			'rows'      => $rows,
			'note'      => '',
			'signature' => md5( SWBM_Attributes::signature( $product ) ),
		);

		if ( ! $rows ) {
			return $plan;
		}

		if ( $product->is_type( 'variable' ) || $product->is_type( 'variation' ) ) {
			$plan['state'] = 'skipped';
			$plan['note']  = __( 'Variable products and their variations are left alone.', 'cartovum-bulk-product-manager' );

			return $plan;
		}

		$states = wp_list_pluck( $rows, 'state' );

		if ( in_array( 'blocked', $states, true ) ) {
			$plan['state'] = 'blocked';
		} elseif ( in_array( 'needs-approval', $states, true ) ) {
			$plan['state'] = 'needs-approval';
		} else {
			$plan['state'] = 'ready';
		}

		return $plan;
	}

	/**
	 * One typed attribute's part of the plan.
	 *
	 * @param WC_Product_Attribute[] $attributes Product attributes.
	 * @param string                 $local_key  Key of the typed attribute.
	 * @param array                  $pair       Pair definition.
	 * @return array
	 */
	private static function plan_row( $attributes, $local_key, $pair ) {
		$local  = $attributes[ $local_key ];
		$values = array_values( array_filter( array_map( 'trim', array_map( 'strval', $local->get_options() ) ), 'strlen' ) );

		$row = array(
			'attribute'      => $pair['label'],
			'taxonomy'       => $pair['taxonomy'],
			'local_key'      => $local_key,
			'current'        => implode( ' | ', $values ),
			'new'            => '',
			'term_ids'       => array(),
			'mapping'        => '',
			'new_term'       => false,
			'needs_new_term' => false,
			'action'         => 'convert',
			'state'          => 'ready',
			'note'           => '',
		);

		if ( $local->get_variation() ) {
			$row['state'] = 'blocked';
			$row['note']  = __( 'Used for variations, so it is left alone.', 'cartovum-bulk-product-manager' );

			return $row;
		}

		if ( ! $values ) {
			$row['state'] = 'blocked';
			$row['note']  = __( 'The typed attribute has no value.', 'cartovum-bulk-product-manager' );

			return $row;
		}

		$targets = array();
		$kinds   = array();
		$notes   = array();

		foreach ( $values as $value ) {
			$resolution = self::resolve( $pair['taxonomy'], $value );
			$kinds[]    = $resolution['kind'];

			if ( $resolution['ok'] ) {
				$targets[] = $resolution;
			} else {
				/* translators: 1: typed value, 2: reason */
				$notes[] = sprintf( __( '%1$s: %2$s', 'cartovum-bulk-product-manager' ), $value, $resolution['note'] );
			}
		}

		$row['new']            = implode( ' | ', wp_list_pluck( $targets, 'term' ) );
		$row['term_ids']       = array_map( 'intval', wp_list_pluck( $targets, 'term_id' ) );
		$row['new_term']       = in_array( true, wp_list_pluck( $targets, 'new_term' ), true );
		$row['needs_new_term'] = in_array( 'planned', $kinds, true );

		if ( $notes ) {
			$row['state']   = in_array( 'held', $kinds, true ) && ! array_intersect( array( 'unmapped', 'ambiguous' ), $kinds ) ? 'needs-approval' : 'blocked';
			$row['mapping'] = in_array( 'held', $kinds, true ) ? 'awaiting approval' : ( in_array( 'ambiguous', $kinds, true ) ? 'ambiguous' : 'no match' );
			$row['note']    = implode( ' ', $notes );

			return $row;
		}

		if ( in_array( 'override', $kinds, true ) ) {
			$row['mapping'] = 'approved mapping';
		} elseif ( $row['needs_new_term'] ) {
			$row['mapping'] = 'new value (to be created on approval)';
		} elseif ( $row['new_term'] ) {
			$row['mapping'] = 'newly created value';
		} else {
			$row['mapping'] = 'exact';
		}

		if ( isset( $attributes[ $pair['taxonomy'] ] ) ) {
			$shared = array_map( 'intval', (array) $attributes[ $pair['taxonomy'] ]->get_options() );
			$wanted = $row['term_ids'];
			sort( $shared );
			sort( $wanted );

			if ( ! $row['needs_new_term'] && $shared === $wanted ) {
				$row['action'] = 'drop-duplicate';
				$row['note']   = __( 'Already shared with the same value, so only the typed copy is removed.', 'cartovum-bulk-product-manager' );
			} else {
				$row['state'] = 'blocked';
				$row['note']  = __( 'Already has the shared attribute with a different value.', 'cartovum-bulk-product-manager' );
			}
		}

		return $row;
	}

	/**
	 * The attribute list to save for a ready plan.
	 *
	 * @param WC_Product $product Product.
	 * @param array      $plan    Plan from plan().
	 * @return WC_Product_Attribute[]
	 */
	private static function build( $product, $plan ) {
		$list = array();
		foreach ( $product->get_attributes() as $key => $attribute ) {
			$list[ $key ] = clone $attribute;
		}

		foreach ( $plan['rows'] as $row ) {
			$local    = $list[ $row['local_key'] ];
			$position = (int) $local->get_position();
			$visible  = (bool) $local->get_visible();

			unset( $list[ $row['local_key'] ] );

			if ( 'drop-duplicate' === $row['action'] ) {
				continue;
			}

			$shared = new WC_Product_Attribute();
			$shared->set_id( wc_attribute_taxonomy_id_by_name( $row['taxonomy'] ) );
			$shared->set_name( $row['taxonomy'] );
			$shared->set_options( $row['term_ids'] );
			$shared->set_position( $position );
			$shared->set_visible( $visible );
			$shared->set_variation( false );

			$list[ $row['taxonomy'] ] = $shared;
		}

		return array_values( $list );
	}

	/**
	 * Convert a batch. Refuses everything while the site is in dry-run-only mode. Otherwise each product
	 * must carry the signature recorded by the dry run; a product changed since then is skipped.
	 *
	 * @param int[]    $ids    Product IDs.
	 * @param string[] $expect Dry-run signatures keyed by product ID.
	 * @return array[] Result rows.
	 */
	public static function apply( $ids, $expect ) {
		$results = array();

		if ( ! self::writes_enabled() ) {
			foreach ( array_map( 'absint', (array) $ids ) as $id ) {
				$results[] = self::row( $id, '', '', 'skipped', __( 'Conversion is switched off on this site (dry run only). Nothing was changed.', 'cartovum-bulk-product-manager' ) );
			}

			return $results;
		}

		foreach ( array_map( 'absint', (array) $ids ) as $id ) {
			$results[] = self::apply_one( $id, isset( $expect[ $id ] ) ? (string) $expect[ $id ] : '' );
		}

		SWBM_Attributes::flush_local_attribute_cache();

		return $results;
	}

	/**
	 * @param int    $id        Product ID.
	 * @param string $signature Dry-run signature.
	 * @return array Result row.
	 */
	private static function apply_one( $id, $signature ) {
		$product = wc_get_product( $id );

		if ( ! $product ) {
			return self::row( $id, '', '', 'failed', __( 'Product not found.', 'cartovum-bulk-product-manager' ) );
		}

		$sku     = (string) $product->get_sku();
		$name    = $product->get_name();
		$allowed = self::allowed_statuses();

		if ( $allowed && ! in_array( $product->get_status(), $allowed, true ) && ! in_array( (int) $id, self::allowed_ids(), true ) ) {
			return self::row(
				$id,
				$sku,
				$name,
				'skipped',
				/* translators: 1: allowed statuses, 2: this product's status */
				sprintf( __( 'Only %1$s products, and products approved individually, may be converted at this stage; this one is %2$s and not on the list, so it was left unchanged.', 'cartovum-bulk-product-manager' ), implode( ', ', $allowed ), $product->get_status() )
			);
		}

		$plan = self::plan( $product );

		if ( 'none' === $plan['state'] ) {
			return self::row( $id, $sku, $name, 'skipped', __( 'No typed attributes to convert.', 'cartovum-bulk-product-manager' ) );
		}

		if ( 'ready' !== $plan['state'] ) {
			return self::row( $id, $sku, $name, 'skipped', self::blocking_note( $plan ) );
		}

		if ( in_array( true, wp_list_pluck( $plan['rows'], 'needs_new_term' ), true ) ) {
			return self::row( $id, $sku, $name, 'skipped', __( 'Needs an approved new value that has not been created yet.', 'cartovum-bulk-product-manager' ) );
		}

		if ( '' === $signature ) {
			return self::row( $id, $sku, $name, 'skipped', __( 'Not in the dry run. Run the dry run first.', 'cartovum-bulk-product-manager' ) );
		}

		if ( $signature !== $plan['signature'] ) {
			return self::row( $id, $sku, $name, 'skipped', __( 'Attributes changed since the dry run, so this product was left alone. Run the dry run again.', 'cartovum-bulk-product-manager' ) );
		}

		$before             = SWBM_Attributes::snapshot( $product );
		$fingerprint_before = SWBM_Guard::fingerprint( $product );

		try {
			$product->set_attributes( self::build( $product, $plan ) );
			$product->save();
		} catch ( Exception $e ) {
			return self::row( $id, $sku, $name, 'failed', sprintf( /* translators: %s: error message */ __( 'WooCommerce rejected the change: %s', 'cartovum-bulk-product-manager' ), $e->getMessage() ) );
		}

		$fresh = SWBM_Guard::reread( $id );
		if ( ! $fresh ) {
			return self::row( $id, $sku, $name, 'failed', __( 'Saved, but the product could not be read back to confirm it.', 'cartovum-bulk-product-manager' ), $before, $before );
		}

		$after    = SWBM_Attributes::snapshot( $fresh );
		$problems = array_merge(
			self::verify( $before, $after, $plan ),
			array_map(
				function ( $field ) {
					/* translators: %s: product field */
					return sprintf( __( '%s changed and should not have.', 'cartovum-bulk-product-manager' ), $field );
				},
				SWBM_Guard::unexpected_changes( $fingerprint_before, SWBM_Guard::fingerprint( $fresh ), array( 'attributes' ) )
			)
		);

		if ( $problems ) {
			return self::row( $id, $sku, $name, 'failed', implode( ' ', $problems ), $before, $after );
		}

		return self::row( $id, $sku, $name, 'changed', self::describe( $plan ), $before, $after );
	}

	/**
	 * Read-back checks for one converted product.
	 *
	 * @param array[] $before Snapshot before.
	 * @param array[] $after  Snapshot after.
	 * @param array   $plan   The plan that was applied.
	 * @return string[] Problems found; empty when the product is exactly as planned.
	 */
	private static function verify( $before, $after, $plan ) {
		$problems = array();
		$by_name  = function ( $snapshot ) {
			$out = array();
			foreach ( $snapshot as $row ) {
				$out[ $row['taxonomy'] ? $row['name'] : 'local:' . strtolower( trim( $row['name'] ) ) ] = $row;
			}
			return $out;
		};

		$was = $by_name( $before );
		$now = $by_name( $after );

		$involved = array();

		foreach ( $plan['rows'] as $row ) {
			$local_id   = 'local:' . strtolower( trim( $row['attribute'] ) );
			$involved[] = $local_id;
			$involved[] = $row['taxonomy'];

			if ( isset( $now[ $local_id ] ) ) {
				/* translators: %s: attribute name */
				$problems[] = sprintf( __( 'The typed %s is still there.', 'cartovum-bulk-product-manager' ), $row['attribute'] );
			}

			if ( ! isset( $now[ $row['taxonomy'] ] ) ) {
				/* translators: %s: attribute name */
				$problems[] = sprintf( __( 'The shared %s is missing.', 'cartovum-bulk-product-manager' ), $row['attribute'] );
				continue;
			}

			$shared = $now[ $row['taxonomy'] ];
			$got    = array_map( 'intval', $shared['options'] );
			$want   = array_map( 'intval', $row['term_ids'] );
			sort( $got );
			sort( $want );

			if ( $got !== $want ) {
				/* translators: %s: attribute name */
				$problems[] = sprintf( __( 'The shared %s holds different values than planned.', 'cartovum-bulk-product-manager' ), $row['attribute'] );
			}

			if ( 'drop-duplicate' === $row['action'] ) {
				if ( ! isset( $was[ $row['taxonomy'] ] ) || wp_json_encode( $was[ $row['taxonomy'] ] ) !== wp_json_encode( $shared ) ) {
					/* translators: %s: attribute name */
					$problems[] = sprintf( __( 'The existing shared %s was altered.', 'cartovum-bulk-product-manager' ), $row['attribute'] );
				}
			} elseif ( isset( $was[ $local_id ] ) ) {
				if ( (int) $shared['position'] !== (int) $was[ $local_id ]['position'] || (bool) $shared['visible'] !== (bool) $was[ $local_id ]['visible'] ) {
					/* translators: %s: attribute name */
					$problems[] = sprintf( __( 'The shared %s did not keep its position or visibility.', 'cartovum-bulk-product-manager' ), $row['attribute'] );
				}
			}
		}

		foreach ( $was as $id => $row ) {
			if ( in_array( $id, $involved, true ) ) {
				continue;
			}
			if ( ! isset( $now[ $id ] ) || wp_json_encode( $now[ $id ] ) !== wp_json_encode( $row ) ) {
				/* translators: %s: attribute name */
				$problems[] = sprintf( __( 'An attribute outside the conversion changed: %s.', 'cartovum-bulk-product-manager' ), $row['label'] );
			}
		}

		foreach ( $now as $id => $row ) {
			if ( ! isset( $was[ $id ] ) && ! in_array( $id, $involved, true ) ) {
				/* translators: %s: attribute name */
				$problems[] = sprintf( __( 'An unexpected attribute appeared: %s.', 'cartovum-bulk-product-manager' ), $row['label'] );
			}
		}

		return $problems;
	}

	/**
	 * Dry run over one page of the catalogue. Reads only.
	 *
	 * @param int $paged    Page number.
	 * @param int $per_page Products per page.
	 * @return array {products, total, pages, scanned}
	 */
	public static function dry_run( $paged, $per_page ) {
		$query = new WP_Query(
			array(
				'post_type'      => 'product',
				'post_status'    => 'any',
				'fields'         => 'ids',
				'orderby'        => 'ID',
				'order'          => 'ASC',
				'posts_per_page' => $per_page,
				'paged'          => $paged,
				'no_found_rows'  => false,
			)
		);

		$products = array();
		$errors   = array();

		foreach ( $query->posts as $id ) {
			$product = wc_get_product( $id );
			if ( ! $product ) {
				/* translators: %d: product id */
				$errors[] = sprintf( __( 'Product %d could not be loaded.', 'cartovum-bulk-product-manager' ), $id );
				continue;
			}

			$plan = self::plan( $product );
			if ( 'none' === $plan['state'] ) {
				continue;
			}

			$rows = array_map(
				function ( $row ) {
					unset( $row['local_key'] );
					return $row;
				},
				$plan['rows']
			);

			$products[] = array(
				'id'        => (int) $id,
				'name'      => $product->get_name(),
				'sku'       => (string) $product->get_sku(),
				'status'    => $product->get_status(),
				'type'      => $product->get_type(),
				'state'     => $plan['state'],
				'note'      => 'ready' === $plan['state'] ? '' : self::blocking_note( $plan ),
				'signature' => $plan['signature'],
				'rows'      => $rows,
			);
		}

		return array(
			'products' => $products,
			'errors'   => $errors,
			'scanned'  => count( $query->posts ),
			'total'    => (int) $query->found_posts,
			'pages'    => (int) $query->max_num_pages,
		);
	}

	/**
	 * Current conversion settings, for the screen and for reports.
	 *
	 * @return array
	 */
	public static function config() {
		return array(
			'writes_enabled' => self::writes_enabled(),
			'pairs'          => self::pairs(),
			'decisions'      => self::decisions(),
			'overrides'      => self::option( self::OVERRIDES_OPTION ),
			'holds'          => self::option( self::HOLDS_OPTION ),
			'new_terms'      => array_map( 'intval', (array) get_option( self::NEW_TERMS_OPTION, array() ) ),
		);
	}

	/**
	 * Approve a mapping for one typed value. The target must be an existing value of that attribute.
	 *
	 * @param string $taxonomy Target taxonomy.
	 * @param string $value    Typed value.
	 * @param int    $term_id  Existing term.
	 * @return true|WP_Error
	 */
	public static function set_override( $taxonomy, $value, $term_id ) {
		$check = self::check_target( $taxonomy, $value );
		if ( is_wp_error( $check ) ) {
			return $check;
		}

		$term = get_term( (int) $term_id, $taxonomy );
		if ( ! $term || is_wp_error( $term ) ) {
			return new WP_Error( 'swbm_unknown_term', __( 'That shared value does not exist.', 'cartovum-bulk-product-manager' ) );
		}

		$overrides                        = self::option( self::OVERRIDES_OPTION );
		$overrides[ $taxonomy ][ $value ] = (int) $term->term_id;
		update_option( self::OVERRIDES_OPTION, $overrides, false );

		return true;
	}

	/**
	 * Withdraw an approved mapping, so the value falls back to exact matching.
	 *
	 * @param string $taxonomy Target taxonomy.
	 * @param string $value    Typed value.
	 * @return true|WP_Error
	 */
	public static function clear_override( $taxonomy, $value ) {
		$check = self::check_target( $taxonomy, $value );
		if ( is_wp_error( $check ) ) {
			return $check;
		}

		$overrides = self::option( self::OVERRIDES_OPTION );
		unset( $overrides[ $taxonomy ][ $value ] );
		update_option( self::OVERRIDES_OPTION, array_filter( $overrides ), false );

		return true;
	}

	/**
	 * Hold a typed value back until a decision is made, or release it.
	 *
	 * @param string $taxonomy Target taxonomy.
	 * @param string $value    Typed value.
	 * @param string $reason   Why it is held; empty releases the hold.
	 * @return true|WP_Error
	 */
	public static function set_hold( $taxonomy, $value, $reason ) {
		$check = self::check_target( $taxonomy, $value );
		if ( is_wp_error( $check ) ) {
			return $check;
		}

		$holds = self::option( self::HOLDS_OPTION );

		if ( '' === trim( (string) $reason ) ) {
			unset( $holds[ $taxonomy ][ $value ] );
		} else {
			$holds[ $taxonomy ][ $value ] = sanitize_text_field( $reason );
		}

		update_option( self::HOLDS_OPTION, array_filter( $holds ), false );

		return true;
	}

	/**
	 * Record which values were created for this migration.
	 *
	 * @param int[] $term_ids Term IDs; each must belong to a shared attribute.
	 * @return true|WP_Error
	 */
	public static function set_new_terms( $term_ids ) {
		$taxonomies = wc_get_attribute_taxonomy_names();
		$ids        = array();

		foreach ( array_map( 'absint', (array) $term_ids ) as $term_id ) {
			$term = get_term( $term_id );
			if ( ! $term || is_wp_error( $term ) || ! in_array( $term->taxonomy, $taxonomies, true ) ) {
				/* translators: %d: term id */
				return new WP_Error( 'swbm_unknown_term', sprintf( __( 'Value %d is not part of a shared attribute.', 'cartovum-bulk-product-manager' ), $term_id ) );
			}
			$ids[] = $term_id;
		}

		update_option( self::NEW_TERMS_OPTION, array_values( array_unique( array_merge( self::config()['new_terms'], $ids ) ) ), false );

		return true;
	}

	/**
	 * @param string $taxonomy Target taxonomy.
	 * @param string $value    Typed value.
	 * @return true|WP_Error
	 */
	private static function check_target( $taxonomy, $value ) {
		if ( ! in_array( $taxonomy, wp_list_pluck( self::pairs(), 'taxonomy' ), true ) ) {
			return new WP_Error( 'swbm_unknown_attribute', __( 'That shared attribute does not exist.', 'cartovum-bulk-product-manager' ) );
		}
		if ( '' === trim( (string) $value ) ) {
			return new WP_Error( 'swbm_no_value', __( 'Say which typed value this applies to.', 'cartovum-bulk-product-manager' ) );
		}

		return true;
	}

	/**
	 * @param string       $kind     Resolution kind.
	 * @param WP_Term|null $term     Target term.
	 * @param string       $note     Explanation.
	 * @param string       $taxonomy Taxonomy, used to recognise values created from the approved list.
	 * @return array
	 */
	private static function resolution( $kind, $term, $note, $taxonomy ) {
		$new_terms = array_map( 'intval', (array) get_option( self::NEW_TERMS_OPTION, array() ) );
		$planned   = isset( self::decisions()['planned_terms'][ $taxonomy ] ) ? (array) self::decisions()['planned_terms'][ $taxonomy ] : array();

		return array(
			'kind'     => $kind,
			'ok'       => in_array( $kind, array( 'exact', 'override' ), true ),
			'term_id'  => $term ? (int) $term->term_id : 0,
			'term'     => $term ? $term->name : '',
			'new_term' => $term ? ( in_array( (int) $term->term_id, $new_terms, true ) || in_array( $term->name, $planned, true ) ) : false,
			'note'     => $note,
		);
	}

	/**
	 * @param string $taxonomy Taxonomy.
	 * @return array Normalised name => WP_Term[]
	 */
	private static function term_index( $taxonomy ) {
		if ( ! isset( self::$term_index[ $taxonomy ] ) ) {
			$index = array();
			$terms = get_terms(
				array(
					'taxonomy'   => $taxonomy,
					'hide_empty' => false,
				)
			);

			foreach ( is_wp_error( $terms ) ? array() : $terms as $term ) {
				$index[ self::normalise( $term->name ) ][] = $term;
			}

			self::$term_index[ $taxonomy ] = $index;
		}

		return self::$term_index[ $taxonomy ];
	}

	/**
	 * @param string $name Option name.
	 * @return array
	 */
	private static function option( $name ) {
		$value = get_option( $name, array() );

		return is_array( $value ) ? $value : array();
	}

	/**
	 * Why a plan is not ready, in one line.
	 *
	 * @param array $plan Plan.
	 * @return string
	 */
	private static function blocking_note( $plan ) {
		if ( $plan['note'] ) {
			return $plan['note'];
		}

		$notes = array();
		foreach ( $plan['rows'] as $row ) {
			if ( 'ready' !== $row['state'] ) {
				/* translators: 1: attribute name, 2: reason */
				$notes[] = sprintf( __( '%1$s: %2$s', 'cartovum-bulk-product-manager' ), $row['attribute'], $row['note'] );
			}
		}

		return implode( ' ', $notes );
	}

	/**
	 * @param array $plan Applied plan.
	 * @return string
	 */
	private static function describe( $plan ) {
		return implode(
			'; ',
			array_map(
				function ( $row ) {
					return 'drop-duplicate' === $row['action']
						/* translators: %s: attribute name */
						? sprintf( __( '%s: typed copy removed (already shared)', 'cartovum-bulk-product-manager' ), $row['attribute'] )
						/* translators: 1: attribute name, 2: typed value, 3: shared value */
						: sprintf( __( '%1$s: %2$s to %3$s', 'cartovum-bulk-product-manager' ), $row['attribute'], $row['current'], $row['new'] );
				},
				$plan['rows']
			)
		);
	}

	/**
	 * @param int    $id      Product ID.
	 * @param string $sku     SKU.
	 * @param string $name    Name.
	 * @param string $status  changed, skipped, or failed.
	 * @param string $message Explanation.
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
