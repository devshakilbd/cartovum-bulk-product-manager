<?php
/**
 * Finding products: search, filters, paging, and the light-weight rows shown in the table.
 *
 * Rows are built from post meta rather than from full WC_Product objects, so listing a
 * page of products stays cheap. Writing always goes through the WooCommerce CRUD.
 */

defined( 'ABSPATH' ) || exit;

class SWBM_Query {

	/** Hard ceiling for "select all matching", so one click can never load the whole catalogue into memory. */
	const MAX_SELECT_ALL = 5000;

	/** Most attribute conditions one search may combine. Keeps the query and the screen manageable. */
	const MAX_CONDITIONS = 10;

	/** @var array Typed-attribute matches already worked out in this request, keyed by name and value. */
	private static $local_matches = array();

	/**
	 * Normalise raw request input into the filter set used by both search() and ids_for_filters().
	 *
	 * @param array $raw Raw request data.
	 * @return array
	 */
	public static function parse_filters( $raw ) {
		$skus = array();
		if ( ! empty( $raw['skus'] ) ) {
			$skus = preg_split( '/[\r\n,]+/', (string) $raw['skus'] );
			$skus = array_filter( array_map( 'trim', (array) $skus ) );
			$skus = array_map( 'wc_clean', $skus );
			$skus = array_slice( array_values( $skus ), 0, 500 );
		}

		$conditions = self::parse_conditions( $raw );
		$first      = $conditions ? $conditions[0] : array(
			'attribute' => '',
			'term'      => 0,
			'value'     => '',
		);

		return array(
			'search'       => isset( $raw['search'] ) ? wc_clean( wp_unslash( $raw['search'] ) ) : '',
			'skus'         => $skus,
			'category'     => isset( $raw['category'] ) ? absint( $raw['category'] ) : 0,
			'stock_status' => isset( $raw['stock_status'] ) && in_array( $raw['stock_status'], array( 'instock', 'outofstock', 'onbackorder' ), true ) ? $raw['stock_status'] : '',
			'post_status'  => isset( $raw['post_status'] ) && in_array( $raw['post_status'], array( 'publish', 'draft', 'pending', 'private' ), true ) ? $raw['post_status'] : '',
			'conditions'   => $conditions,
			// The single-condition keys stay, mirroring the first condition, for anything that still reads them.
			'attribute'    => $first['attribute'],
			'attr_term'    => $first['term'],
			'attr_value'   => $first['value'],
			'paged'        => isset( $raw['paged'] ) ? max( 1, absint( $raw['paged'] ) ) : 1,
			'per_page'     => isset( $raw['per_page'] ) ? min( 200, max( 10, absint( $raw['per_page'] ) ) ) : 50,
		);
	}

	/**
	 * Attribute conditions from the request. A product has to match every one of them.
	 *
	 * Reads conditions[n][attribute], conditions[n][term] (shared attributes) and conditions[n][value]
	 * (typed attributes). The original single attribute / attr_term / attr_value fields are still
	 * accepted, so an older request keeps working.
	 *
	 * @param array $raw Raw request data.
	 * @return array[] Each {attribute, term, value}.
	 */
	public static function parse_conditions( $raw ) {
		$input = array();

		if ( isset( $raw['conditions'] ) && is_array( $raw['conditions'] ) ) {
			$input = array_values( $raw['conditions'] );
		} elseif ( ! empty( $raw['attribute'] ) ) {
			$input[] = array(
				'attribute' => $raw['attribute'],
				'term'      => isset( $raw['attr_term'] ) ? $raw['attr_term'] : 0,
				'value'     => isset( $raw['attr_value'] ) ? $raw['attr_value'] : '',
			);
		}

		$conditions = array();
		foreach ( $input as $condition ) {
			if ( ! is_array( $condition ) || empty( $condition['attribute'] ) || ! is_scalar( $condition['attribute'] ) ) {
				continue;
			}

			$conditions[] = array(
				'attribute' => wc_clean( (string) $condition['attribute'] ),
				'term'      => isset( $condition['term'] ) && is_scalar( $condition['term'] ) ? absint( $condition['term'] ) : 0,
				'value'     => isset( $condition['value'] ) && is_scalar( $condition['value'] ) ? wc_clean( (string) $condition['value'] ) : '',
			);

			if ( count( $conditions ) >= self::MAX_CONDITIONS ) {
				break;
			}
		}

		return $conditions;
	}

	/**
	 * Build WP_Query arguments from parsed filters.
	 *
	 * @param array $f Parsed filters.
	 * @return array
	 */
	private static function query_args( $f ) {
		$args = array(
			'post_type'              => 'product',
			'post_status'            => $f['post_status'] ? $f['post_status'] : array( 'publish', 'draft', 'pending', 'private' ),
			'orderby'                => 'ID',
			'order'                  => 'DESC',
			'ignore_sticky_posts'    => true,
			'no_found_rows'          => false,
			'update_post_term_cache' => false,
		);

		if ( $f['search'] ) {
			$args['s'] = $f['search'];
		}

		$tax_query  = array();
		$meta_query = array();

		if ( $f['category'] ) {
			$tax_query[] = array(
				'taxonomy'         => 'product_cat',
				'field'            => 'term_id',
				'terms'            => $f['category'],
				'include_children' => true,
			);
		}

		if ( $f['stock_status'] ) {
			$meta_query[] = array(
				'key'   => '_stock_status',
				'value' => $f['stock_status'],
			);
		}

		if ( $f['skus'] ) {
			$meta_query[] = array(
				'key'     => '_sku',
				'value'   => $f['skus'],
				'compare' => 'IN',
			);
		}

		// Attribute conditions. Shared attributes become taxonomy clauses; typed attributes are resolved to
		// product IDs, intersected across conditions. null means no typed-attribute constraint yet.
		$post_in = null;

		foreach ( $f['conditions'] as $condition ) {
			$attribute = $condition['attribute'];

			if ( 0 === strpos( $attribute, 'local:' ) ) {
				$ids     = self::local_attribute_ids( substr( $attribute, 6 ), $condition['value'] );
				$post_in = null === $post_in ? $ids : array_values( array_intersect( $post_in, $ids ) );
				continue;
			}

			// An attribute that doesn't exist, or a value that isn't one of its values, can't be matched.
			if ( 0 !== strpos( $attribute, 'pa_' ) || ! taxonomy_exists( $attribute ) ) {
				$post_in = array();
				continue;
			}

			if ( $condition['term'] ) {
				$term = get_term( $condition['term'], $attribute );
				if ( ! $term || is_wp_error( $term ) ) {
					$post_in = array();
					continue;
				}

				$tax_query[] = array(
					'taxonomy' => $attribute,
					'field'    => 'term_id',
					'terms'    => $condition['term'],
				);
			} else {
				$tax_query[] = array(
					'taxonomy' => $attribute,
					'operator' => 'EXISTS',
				);
			}
		}

		if ( $tax_query ) {
			if ( count( $tax_query ) > 1 ) {
				$tax_query['relation'] = 'AND';
			}
			$args['tax_query'] = $tax_query; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
		}
		if ( $meta_query ) {
			$args['meta_query'] = $meta_query; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
		}

		if ( null !== $post_in ) {
			$args['post__in'] = $post_in ? $post_in : array( 0 );
		}

		return $args;
	}

	/**
	 * Product IDs whose serialised _product_attributes hold a typed attribute of this name,
	 * optionally with this value. One query returns the candidates together with their attribute
	 * data, the LIKE narrows them, and PHP confirms each match exactly.
	 *
	 * @param string $name  Typed attribute name.
	 * @param string $value Optional value to match.
	 * @return int[]
	 */
	private static function local_attribute_ids( $name, $value = '' ) {
		global $wpdb;

		$name  = trim( (string) $name );
		$value = trim( (string) $value );
		if ( '' === $name ) {
			return array();
		}

		$cache_key = strtolower( $name ) . "\0" . strtolower( $value );
		if ( isset( self::$local_matches[ $cache_key ] ) ) {
			return self::$local_matches[ $cache_key ];
		}

		// The object cache carries the answer between requests as well. The key holds a generation number
		// that moves on whenever this plugin edits attributes, so a stale scan is never read back.
		$object_key = 'local-ids:' . SWBM_Attributes::cache_generation() . ':' . md5( $cache_key );
		$cached     = wp_cache_get( $object_key, SWBM_Attributes::CACHE_GROUP );

		if ( is_array( $cached ) ) {
			self::$local_matches[ $cache_key ] = $cached;

			return $cached;
		}

		$sql    = "SELECT post_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key = '_product_attributes' AND meta_value LIKE %s";
		$params = array( '%' . $wpdb->esc_like( $name ) . '%' );

		if ( '' !== $value ) {
			$sql     .= ' AND meta_value LIKE %s';
			$params[] = '%' . $wpdb->esc_like( $value ) . '%';
		}

		$sql     .= ' LIMIT %d';
		$params[] = self::MAX_SELECT_ALL;

		// phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- $sql is built only from static text plus %s/%d placeholders and is passed through $wpdb->prepare() with $params before use. WordPress has no API for searching serialised meta, and the result is cached above, per request and in the object cache.
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $params ) );

		$matched = array();
		foreach ( (array) $rows as $row ) {
			$attributes = maybe_unserialize( $row->meta_value );
			if ( ! is_array( $attributes ) ) {
				continue;
			}

			foreach ( $attributes as $attribute ) {
				if ( ! is_array( $attribute ) || ! empty( $attribute['is_taxonomy'] ) || ! isset( $attribute['name'] ) ) {
					continue;
				}
				if ( 0 !== strcasecmp( trim( (string) $attribute['name'] ), $name ) ) {
					continue;
				}
				if ( '' === $value ) {
					$matched[] = (int) $row->post_id;
					break;
				}

				$options = array_map( 'trim', explode( WC_DELIMITER, isset( $attribute['value'] ) ? (string) $attribute['value'] : '' ) );
				foreach ( $options as $option ) {
					if ( 0 === strcasecmp( $option, $value ) ) {
						$matched[] = (int) $row->post_id;
						break 2;
					}
				}
			}
		}

		$matched = array_values( array_unique( $matched ) );

		self::$local_matches[ $cache_key ] = $matched;
		wp_cache_set( $object_key, $matched, SWBM_Attributes::CACHE_GROUP, HOUR_IN_SECONDS );

		return $matched;
	}

	/**
	 * One page of results.
	 *
	 * @param array $f Parsed filters.
	 * @return array {items, total, pages, paged, per_page}
	 */
	public static function search( $f ) {
		$args                   = self::query_args( $f );
		$args['posts_per_page'] = $f['per_page'];
		$args['paged']          = $f['paged'];

		$query = new WP_Query( $args );

		return array(
			'items'    => self::rows( wp_list_pluck( $query->posts, 'ID' ) ),
			'total'    => (int) $query->found_posts,
			'pages'    => (int) $query->max_num_pages,
			'paged'    => $f['paged'],
			'per_page' => $f['per_page'],
		);
	}

	/**
	 * Every product ID matching the filters, for "select all matching".
	 *
	 * @param array $f Parsed filters.
	 * @return array {ids, total, capped}
	 */
	public static function ids_for_filters( $f ) {
		$args                   = self::query_args( $f );
		$args['fields']         = 'ids';
		$args['posts_per_page'] = self::MAX_SELECT_ALL;
		$args['paged']          = 1;

		$query = new WP_Query( $args );
		$ids   = array_map( 'absint', $query->posts );

		return array(
			'ids'    => $ids,
			'total'  => (int) $query->found_posts,
			'capped' => (int) $query->found_posts > count( $ids ),
		);
	}

	/**
	 * Table rows for a set of product IDs, using batched term lookups rather than per-product queries.
	 *
	 * @param int[] $ids Product IDs, in display order.
	 * @return array[]
	 */
	public static function rows( $ids ) {
		$ids = array_values( array_map( 'absint', (array) $ids ) );
		if ( ! $ids ) {
			return array();
		}

		$attribute_taxonomies = wc_get_attribute_taxonomy_names();
		$term_map             = self::object_terms( $ids, array_merge( array( 'product_cat', 'product_type' ), $attribute_taxonomies ) );

		$rows = array();
		foreach ( $ids as $id ) {
			$terms      = isset( $term_map[ $id ] ) ? $term_map[ $id ] : array();
			$post       = get_post( $id );
			$attributes = get_post_meta( $id, '_product_attributes', true );

			$rows[] = array(
				'id'           => $id,
				'name'         => $post ? $post->post_title : '',
				'sku'          => (string) get_post_meta( $id, '_sku', true ),
				'status'       => $post ? $post->post_status : '',
				'stock_status' => (string) get_post_meta( $id, '_stock_status', true ),
				'manage_stock' => 'yes' === get_post_meta( $id, '_manage_stock', true ),
				'type'         => isset( $terms['product_type'] ) ? $terms['product_type'][0]['name'] : 'simple',
				'categories'   => isset( $terms['product_cat'] ) ? wp_list_pluck( $terms['product_cat'], 'name' ) : array(),
				'attributes'   => self::attribute_summary( is_array( $attributes ) ? $attributes : array(), $terms ),
				'edit_link'    => get_edit_post_link( $id, 'raw' ),
			);
		}

		return $rows;
	}

	/**
	 * Readable attribute list for one product: global attributes resolved to term names,
	 * local attributes read straight from the serialised value.
	 *
	 * @param array $attributes Raw _product_attributes meta.
	 * @param array $terms      This product's terms, keyed by taxonomy.
	 * @return array[]
	 */
	private static function attribute_summary( $attributes, $terms ) {
		$summary = array();

		foreach ( $attributes as $key => $attribute ) {
			$is_taxonomy = ! empty( $attribute['is_taxonomy'] );
			$name        = isset( $attribute['name'] ) ? (string) $attribute['name'] : (string) $key;

			if ( $is_taxonomy ) {
				$values = isset( $terms[ $name ] ) ? wp_list_pluck( $terms[ $name ], 'name' ) : array();
				$label  = wc_attribute_label( $name );
			} else {
				$values = '' === trim( (string) $attribute['value'] ) ? array() : array_map( 'trim', explode( WC_DELIMITER, (string) $attribute['value'] ) );
				$label  = $name;
			}

			$summary[] = array(
				'key'       => $is_taxonomy ? $name : 'local:' . $name,
				'label'     => $label,
				'type'      => $is_taxonomy ? 'global' : 'local',
				'values'    => array_values( $values ),
				'visible'   => ! empty( $attribute['is_visible'] ),
				'variation' => ! empty( $attribute['is_variation'] ),
			);
		}

		return $summary;
	}

	/**
	 * Terms for many objects across many taxonomies, in one query, keyed by object then taxonomy.
	 *
	 * @param int[]    $ids        Object IDs.
	 * @param string[] $taxonomies Taxonomies to fetch.
	 * @return array
	 */
	private static function object_terms( $ids, $taxonomies ) {
		$taxonomies = array_values( array_filter( (array) $taxonomies, 'taxonomy_exists' ) );
		if ( ! $taxonomies ) {
			return array();
		}

		$terms = wp_get_object_terms( $ids, $taxonomies, array( 'fields' => 'all_with_object_id' ) );
		if ( is_wp_error( $terms ) ) {
			return array();
		}

		$map = array();
		foreach ( $terms as $term ) {
			$map[ (int) $term->object_id ][ $term->taxonomy ][] = array(
				'id'   => (int) $term->term_id,
				'name' => $term->name,
				'slug' => $term->slug,
			);
		}

		return $map;
	}
}
