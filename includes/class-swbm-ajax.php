<?php
/**
 * The AJAX endpoints behind the screen.
 *
 * Work is always requested in small batches by the browser, so nothing here runs long enough
 * to hit a PHP time limit, and progress stays visible while it runs.
 */

defined( 'ABSPATH' ) || exit;

class SWBM_Ajax {

	/** Never process more than this in one request, whatever the browser asks for. */
	const MAX_PER_REQUEST = 25;

	/**
	 * @return void
	 */
	public static function init() {
		$actions = array( 'search', 'select_all', 'start', 'run_batch', 'jobs', 'job', 'revert_start', 'revert_batch', 'convert_config', 'convert_set', 'convert_dryrun' );

		foreach ( $actions as $action ) {
			add_action( 'wp_ajax_swbm_' . $action, array( __CLASS__, 'handle_' . $action ) );
		}
	}

	/**
	 * Shared gate for every endpoint.
	 *
	 * @return void
	 */
	private static function guard() {
		check_ajax_referer( 'swbm', 'nonce' );

		if ( ! current_user_can( SWBM_CAP ) ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to manage products.', 'cartovum-bulk-product-manager' ) ), 403 );
		}
	}

	/**
	 * Refuse anything that would write conversion data while the site is in dry-run-only mode.
	 *
	 * @return void
	 */
	private static function refuse_while_dry_run_only() {
		if ( ! SWBM_Convert::writes_enabled() ) {
			wp_send_json_error( array( 'message' => __( 'Conversion is switched off on this site (dry run only). Nothing was changed.', 'cartovum-bulk-product-manager' ) ), 403 );
		}
	}

	/**
	 * One page of products.
	 *
	 * @return void
	 */
	public static function handle_search() {
		check_ajax_referer( 'swbm', 'nonce' );
		self::guard();

		wp_send_json_success( SWBM_Query::search( SWBM_Query::parse_filters( wp_unslash( $_POST ) ) ) );
	}

	/**
	 * Every product ID matching the current filters.
	 *
	 * @return void
	 */
	public static function handle_select_all() {
		check_ajax_referer( 'swbm', 'nonce' );
		self::guard();

		wp_send_json_success( SWBM_Query::ids_for_filters( SWBM_Query::parse_filters( wp_unslash( $_POST ) ) ) );
	}

	/**
	 * Validate the requested change and open a job for it.
	 *
	 * @return void
	 */
	public static function handle_start() {
		check_ajax_referer( 'swbm', 'nonce' );
		self::guard();

		$kind  = isset( $_POST['kind'] ) ? sanitize_key( $_POST['kind'] ) : '';
		$count = isset( $_POST['count'] ) ? absint( $_POST['count'] ) : 0;

		if ( 'stock' === $kind ) {
			$status = isset( $_POST['stock_status'] ) ? sanitize_key( $_POST['stock_status'] ) : '';

			if ( ! in_array( $status, SWBM_Stock::STATUSES, true ) ) {
				wp_send_json_error( array( 'message' => __( 'Choose a stock status.', 'cartovum-bulk-product-manager' ) ) );
			}

			$job_id = SWBM_Jobs::start(
				'stock',
				array(
					'stock_status' => $status,
					'count'        => $count,
				)
			);

			wp_send_json_success( array( 'job_id' => $job_id ) );
		}

		if ( 'attributes' === $kind ) {
			$op = SWBM_Attributes::parse_op( wp_unslash( $_POST ) );

			if ( is_wp_error( $op ) ) {
				wp_send_json_error( array( 'message' => $op->get_error_message() ) );
			}

			$job_id = SWBM_Jobs::start( 'attributes', array_merge( $op, array( 'count' => $count ) ) );

			wp_send_json_success( array( 'job_id' => $job_id ) );
		}

		if ( 'convert' === $kind ) {
			self::refuse_while_dry_run_only();

			$job_id = SWBM_Jobs::start( 'convert', array( 'count' => $count ) );

			wp_send_json_success( array( 'job_id' => $job_id ) );
		}

		wp_send_json_error( array( 'message' => __( 'Unknown operation.', 'cartovum-bulk-product-manager' ) ) );
	}

	/**
	 * Process one batch of products.
	 *
	 * @return void
	 */
	public static function handle_run_batch() {
		check_ajax_referer( 'swbm', 'nonce' );
		self::guard();

		$job_id = isset( $_POST['job_id'] ) ? sanitize_text_field( wp_unslash( $_POST['job_id'] ) ) : '';
		$kind   = isset( $_POST['kind'] ) ? sanitize_key( $_POST['kind'] ) : '';
		$ids    = isset( $_POST['ids'] ) ? array_map( 'absint', (array) wp_unslash( $_POST['ids'] ) ) : array();
		$ids    = array_values( array_filter( $ids ) );

		// A batch larger than this is refused rather than trimmed: trimming would leave the extra products
		// unchanged without anyone being told.
		if ( count( $ids ) > self::MAX_PER_REQUEST ) {
			wp_send_json_error(
				array(
					'message' => sprintf(
						/* translators: 1: products sent in this batch, 2: the limit */
						__( 'Too many products in one batch: %1$d sent, %2$d is the most allowed. Nothing was changed. Reload the screen and run it again.', 'cartovum-bulk-product-manager' ),
						count( $ids ),
						self::MAX_PER_REQUEST
					),
				)
			);
		}

		if ( 'convert' === $kind ) {
			self::refuse_while_dry_run_only();
		}

		if ( ! $job_id || ! SWBM_Jobs::get( $job_id ) ) {
			wp_send_json_error( array( 'message' => __( 'That run is no longer open. Start again.', 'cartovum-bulk-product-manager' ) ) );
		}
		if ( ! $ids ) {
			wp_send_json_error( array( 'message' => __( 'No products in this batch.', 'cartovum-bulk-product-manager' ) ) );
		}

		self::prepare_for_writes();

		if ( 'stock' === $kind ) {
			$status = isset( $_POST['stock_status'] ) ? sanitize_key( $_POST['stock_status'] ) : '';

			if ( ! in_array( $status, SWBM_Stock::STATUSES, true ) ) {
				wp_send_json_error( array( 'message' => __( 'Choose a stock status.', 'cartovum-bulk-product-manager' ) ) );
			}

			$results = SWBM_Stock::apply( $ids, $status );
		} elseif ( 'attributes' === $kind ) {
			$op = SWBM_Attributes::parse_op( wp_unslash( $_POST ) );

			if ( is_wp_error( $op ) ) {
				wp_send_json_error( array( 'message' => $op->get_error_message() ) );
			}

			$results = SWBM_Attributes::apply( $ids, $op );
		} elseif ( 'convert' === $kind ) {
			// Each product carries the signature its dry-run row recorded; anything changed since is skipped.
			// Keys become integers and values are cleaned, then stripped to hex characters only.
			$expect = array();
			$raw    = isset( $_POST['expect'] ) ? (array) wp_unslash( $_POST['expect'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitised per value on the next line.
			foreach ( $raw as $id => $signature ) {
				$expect[ absint( $id ) ] = preg_replace( '/[^a-f0-9]/', '', sanitize_text_field( (string) $signature ) );
			}

			$results = SWBM_Convert::apply( $ids, $expect );
		} else {
			wp_send_json_error( array( 'message' => __( 'Unknown operation.', 'cartovum-bulk-product-manager' ) ) );
		}

		self::finish_writes();
		self::refresh_page_cache( $results );

		SWBM_Jobs::add_results( $job_id, $results );

		wp_send_json_success( array( 'results' => self::public_results( $results ) ) );
	}

	/**
	 * Recent runs.
	 *
	 * @return void
	 */
	public static function handle_jobs() {
		check_ajax_referer( 'swbm', 'nonce' );
		self::guard();

		wp_send_json_success( array( 'jobs' => SWBM_Jobs::recent( 20 ) ) );
	}

	/**
	 * One run, with its per-product results.
	 *
	 * @return void
	 */
	public static function handle_job() {
		check_ajax_referer( 'swbm', 'nonce' );
		self::guard();

		$job_id = isset( $_POST['job_id'] ) ? sanitize_text_field( wp_unslash( $_POST['job_id'] ) ) : '';
		$job    = SWBM_Jobs::get( $job_id );

		if ( ! $job ) {
			wp_send_json_error( array( 'message' => __( 'That run is not in the log.', 'cartovum-bulk-product-manager' ) ) );
		}

		wp_send_json_success(
			array(
				'job'     => $job,
				'results' => self::public_results( SWBM_Jobs::results( $job_id ) ),
			)
		);
	}

	/**
	 * Open a job that undoes an earlier one.
	 *
	 * @return void
	 */
	public static function handle_revert_start() {
		check_ajax_referer( 'swbm', 'nonce' );
		self::guard();

		$source_id = isset( $_POST['job_id'] ) ? sanitize_text_field( wp_unslash( $_POST['job_id'] ) ) : '';
		$source    = SWBM_Jobs::get( $source_id );

		if ( ! $source ) {
			wp_send_json_error( array( 'message' => __( 'That run is not in the log.', 'cartovum-bulk-product-manager' ) ) );
		}
		if ( 'revert' === $source['type'] ) {
			wp_send_json_error( array( 'message' => __( 'A revert cannot itself be reverted. Run the change again instead.', 'cartovum-bulk-product-manager' ) ) );
		}

		$rows = SWBM_Jobs::changed_results( $source_id );
		if ( ! $rows ) {
			wp_send_json_error( array( 'message' => __( 'That run changed nothing, so there is nothing to put back.', 'cartovum-bulk-product-manager' ) ) );
		}

		$job_id = SWBM_Jobs::start(
			'revert',
			array(
				'revert_of' => $source_id,
				'kind'      => $source['type'],
				'count'     => count( $rows ),
			)
		);

		wp_send_json_success(
			array(
				'job_id' => $job_id,
				'total'  => count( $rows ),
			)
		);
	}

	/**
	 * Undo one batch of an earlier run.
	 *
	 * @return void
	 */
	public static function handle_revert_batch() {
		check_ajax_referer( 'swbm', 'nonce' );
		self::guard();

		$job_id    = isset( $_POST['job_id'] ) ? sanitize_text_field( wp_unslash( $_POST['job_id'] ) ) : '';
		$source_id = isset( $_POST['source_job_id'] ) ? sanitize_text_field( wp_unslash( $_POST['source_job_id'] ) ) : '';
		$offset    = isset( $_POST['offset'] ) ? absint( $_POST['offset'] ) : 0;
		$limit     = isset( $_POST['limit'] ) ? min( self::MAX_PER_REQUEST, max( 1, absint( $_POST['limit'] ) ) ) : SWBM_BATCH_SIZE;

		$job    = SWBM_Jobs::get( $job_id );
		$source = SWBM_Jobs::get( $source_id );

		if ( ! $job || ! $source ) {
			wp_send_json_error( array( 'message' => __( 'That run is no longer open. Start again.', 'cartovum-bulk-product-manager' ) ) );
		}

		$rows = array_slice( SWBM_Jobs::changed_results( $source_id ), $offset, $limit );
		if ( ! $rows ) {
			SWBM_Jobs::mark_reverted( $source_id, $job_id );
			wp_send_json_success(
				array(
					'results' => array(),
					'done'    => true,
				)
			);
		}

		self::prepare_for_writes();

		$results = 'stock' === $source['type'] ? SWBM_Stock::revert( $rows ) : SWBM_Attributes::revert( $rows );

		self::finish_writes();
		self::refresh_page_cache( $results );

		SWBM_Jobs::add_results( $job_id, $results );

		wp_send_json_success(
			array(
				'results' => self::public_results( $results ),
				'done'    => false,
			)
		);
	}

	/**
	 * Conversion settings: attribute pairs, approved decisions, and whether conversion may write.
	 *
	 * @return void
	 */
	public static function handle_convert_config() {
		check_ajax_referer( 'swbm', 'nonce' );
		self::guard();

		wp_send_json_success( SWBM_Convert::config() );
	}

	/**
	 * Change one stored conversion setting. None of these touch a product, and all are locked while
	 * the site is in dry-run-only mode.
	 *
	 * @return void
	 */
	public static function handle_convert_set() {
		check_ajax_referer( 'swbm', 'nonce' );
		self::guard();
		self::refuse_while_dry_run_only();

		$what     = isset( $_POST['what'] ) ? sanitize_key( $_POST['what'] ) : '';
		$taxonomy = isset( $_POST['taxonomy'] ) ? sanitize_key( wp_unslash( $_POST['taxonomy'] ) ) : '';
		$value    = isset( $_POST['value'] ) ? sanitize_text_field( wp_unslash( $_POST['value'] ) ) : '';

		switch ( $what ) {
			case 'override':
				$result = SWBM_Convert::set_override( $taxonomy, $value, isset( $_POST['term_id'] ) ? absint( $_POST['term_id'] ) : 0 );
				break;
			case 'clear_override':
				$result = SWBM_Convert::clear_override( $taxonomy, $value );
				break;
			case 'hold':
				$result = SWBM_Convert::set_hold( $taxonomy, $value, isset( $_POST['reason'] ) ? sanitize_text_field( wp_unslash( $_POST['reason'] ) ) : '' );
				break;
			case 'new_terms':
				$result = SWBM_Convert::set_new_terms( isset( $_POST['term_ids'] ) ? array_map( 'absint', (array) wp_unslash( $_POST['term_ids'] ) ) : array() );
				break;
			default:
				$result = new WP_Error( 'swbm_unknown_setting', __( 'Unknown setting.', 'cartovum-bulk-product-manager' ) );
		}

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		wp_send_json_success( SWBM_Convert::config() );
	}

	/**
	 * One page of the conversion dry run. Reads only.
	 *
	 * @return void
	 */
	public static function handle_convert_dryrun() {
		check_ajax_referer( 'swbm', 'nonce' );
		self::guard();

		$paged    = isset( $_POST['paged'] ) ? max( 1, absint( $_POST['paged'] ) ) : 1;
		$per_page = isset( $_POST['per_page'] ) ? min( 100, max( 10, absint( $_POST['per_page'] ) ) ) : 50;

		wp_send_json_success( SWBM_Convert::dry_run( $paged, $per_page ) );
	}

	/**
	 * Term counting is deferred for the length of a batch, so a batch of saves recounts once
	 * rather than once per product.
	 *
	 * @return void
	 */
	private static function prepare_for_writes() {
		if ( function_exists( 'wc_set_time_limit' ) ) {
			wc_set_time_limit( 0 );
		}

		wp_defer_term_counting( true );
	}

	/**
	 * @return void
	 */
	private static function finish_writes() {
		wp_defer_term_counting( false );
	}

	/**
	 * Ask LiteSpeed Cache to refresh the pages of the products this batch wrote to. WooCommerce does not
	 * trigger that itself when only attributes change, so shoppers would keep seeing the old values.
	 * LiteSpeed applies its own "purge on update" rules, as for a save in the product editor. Does nothing
	 * when LiteSpeed Cache is not active.
	 *
	 * @param array[] $results Result rows.
	 * @return void
	 */
	private static function refresh_page_cache( $results ) {
		foreach ( (array) $results as $row ) {
			// A failed row may still have been written, so its page is refreshed too.
			if ( ! empty( $row['id'] ) && isset( $row['status'] ) && in_array( $row['status'], array( 'changed', 'failed' ), true ) ) {
				// This is LiteSpeed Cache's own action, registered in its API class; it is called here, not
				// created here, so it keeps their name rather than this plugin's prefix.
				do_action( 'litespeed_purge_post', (int) $row['id'] ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
			}
		}
	}

	/**
	 * Result rows trimmed to what the screen shows. The full before and after state stays in the log.
	 *
	 * @param array[] $results Result rows.
	 * @return array[]
	 */
	private static function public_results( $results ) {
		return array_map(
			function ( $row ) {
				return array(
					'id'      => $row['id'],
					'sku'     => $row['sku'],
					'name'    => $row['name'],
					'status'  => $row['status'],
					'message' => $row['message'],
				);
			},
			(array) $results
		);
	}
}
