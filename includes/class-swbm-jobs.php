<?php
/**
 * The change log.
 *
 * Every run records, per product, the state before and after the change. That is what makes a
 * run reversible and what lets you prove afterwards exactly what was touched.
 *
 * Entries are written in parts, one per batch, so a long run never rewrites a growing option.
 */

defined( 'ABSPATH' ) || exit;

class SWBM_Jobs {

	const INDEX_OPTION = 'swbm_jobs_index';
	const KEEP_JOBS    = 25;

	/**
	 * Start a run and return its id.
	 *
	 * @param string $type   'stock', 'attributes', or 'revert'.
	 * @param array  $params Human-readable description of what was asked for.
	 * @return string
	 */
	public static function start( $type, $params = array() ) {
		$user = wp_get_current_user();
		$id   = gmdate( 'Ymd-His' ) . '-' . wp_generate_password( 6, false, false );

		$index = self::index();
		array_unshift(
			$index,
			array(
				'id'          => $id,
				'type'        => (string) $type,
				'params'      => $params,
				'created_gmt' => gmdate( 'Y-m-d H:i:s' ),
				'user_id'     => (int) get_current_user_id(),
				'user_login'  => $user ? $user->user_login : '',
				'parts'       => 0,
				'counts'      => array(
					'changed' => 0,
					'skipped' => 0,
					'failed'  => 0,
				),
				'reverted_at' => '',
				'revert_of'   => isset( $params['revert_of'] ) ? (string) $params['revert_of'] : '',
			)
		);

		self::save_index( $index );
		self::prune( $index );

		return $id;
	}

	/**
	 * Store one batch of results and update the running totals.
	 *
	 * @param string $job_id  Job id.
	 * @param array  $results Result rows for this batch.
	 * @return void
	 */
	public static function add_results( $job_id, $results ) {
		$index = self::index();
		$found = false;

		foreach ( $index as $i => $job ) {
			if ( $job['id'] !== $job_id ) {
				continue;
			}

			$found = true;
			$part  = (int) $job['parts'] + 1;

			update_option( self::part_option( $job_id, $part ), $results, false );

			foreach ( $results as $result ) {
				$status = isset( $result['status'] ) ? $result['status'] : 'failed';
				if ( ! isset( $index[ $i ]['counts'][ $status ] ) ) {
					$index[ $i ]['counts'][ $status ] = 0;
				}
				++$index[ $i ]['counts'][ $status ];
			}

			$index[ $i ]['parts'] = $part;
			break;
		}

		if ( $found ) {
			self::save_index( $index );
		}
	}

	/**
	 * A job's index entry.
	 *
	 * @param string $job_id Job id.
	 * @return array|null
	 */
	public static function get( $job_id ) {
		foreach ( self::index() as $job ) {
			if ( $job['id'] === $job_id ) {
				return $job;
			}
		}

		return null;
	}

	/**
	 * Every recorded result row for a job, in the order it was processed.
	 *
	 * @param string $job_id Job id.
	 * @return array[]
	 */
	public static function results( $job_id ) {
		$job = self::get( $job_id );
		if ( ! $job ) {
			return array();
		}

		$results = array();
		for ( $part = 1; $part <= (int) $job['parts']; $part++ ) {
			$rows = get_option( self::part_option( $job_id, $part ), array() );
			if ( is_array( $rows ) ) {
				$results = array_merge( $results, $rows );
			}
		}

		return $results;
	}

	/**
	 * Result rows that wrote to a product, so a revert has a precise target list. That includes
	 * products that were saved but then failed their read-back check: they were still written.
	 *
	 * @param string $job_id Job id.
	 * @return array[]
	 */
	public static function changed_results( $job_id ) {
		return array_values(
			array_filter(
				self::results( $job_id ),
				function ( $row ) {
					if ( ! isset( $row['status'] ) ) {
						return false;
					}

					return 'changed' === $row['status'] || ( 'failed' === $row['status'] && ! empty( $row['after'] ) );
				}
			)
		);
	}

	/**
	 * Mark a job as reverted.
	 *
	 * @param string $job_id        Job that was undone.
	 * @param string $revert_job_id Job that undid it.
	 * @return void
	 */
	public static function mark_reverted( $job_id, $revert_job_id ) {
		$index = self::index();

		foreach ( $index as $i => $job ) {
			if ( $job['id'] === $job_id ) {
				$index[ $i ]['reverted_at'] = gmdate( 'Y-m-d H:i:s' );
				$index[ $i ]['reverted_by'] = $revert_job_id;
				break;
			}
		}

		self::save_index( $index );
	}

	/**
	 * Recent jobs, newest first.
	 *
	 * @param int $limit How many.
	 * @return array[]
	 */
	public static function recent( $limit = 20 ) {
		return array_slice( self::index(), 0, max( 1, (int) $limit ) );
	}

	/**
	 * The job index.
	 *
	 * @return array[]
	 */
	private static function index() {
		$index = get_option( self::INDEX_OPTION, array() );

		return is_array( $index ) ? $index : array();
	}

	/**
	 * @param array[] $index Job index.
	 * @return void
	 */
	private static function save_index( $index ) {
		update_option( self::INDEX_OPTION, $index, false );
	}

	/**
	 * @param string $job_id Job id.
	 * @param int    $part   Part number.
	 * @return string
	 */
	private static function part_option( $job_id, $part ) {
		return 'swbm_job_' . $job_id . '_p' . (int) $part;
	}

	/**
	 * Drop the oldest jobs, with their stored parts. Only this plugin's own log is removed.
	 *
	 * @param array[] $index Current index.
	 * @return void
	 */
	private static function prune( $index ) {
		if ( count( $index ) <= self::KEEP_JOBS ) {
			return;
		}

		$keep = array_slice( $index, 0, self::KEEP_JOBS );
		$drop = array_slice( $index, self::KEEP_JOBS );

		foreach ( $drop as $job ) {
			for ( $part = 1; $part <= (int) $job['parts']; $part++ ) {
				delete_option( self::part_option( $job['id'], $part ) );
			}
		}

		self::save_index( $keep );
	}
}
