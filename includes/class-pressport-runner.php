<?php
/**
 * Job orchestration shared by the admin (AJAX) and WP-CLI.
 *
 * @package PressPort
 */

defined( 'ABSPATH' ) || exit;

/**
 * Preview, batch execution and rollback.
 */
class PressPort_Runner {

	/**
	 * Prepare a source, create a job and return a preview.
	 *
	 * @param string $source_id Source id.
	 * @param array  $settings  Raw settings.
	 * @param int    $sample    Number of sample records.
	 * @return array|WP_Error { job: PressPort_Job, records: array, fields: string[], mapping: array }
	 */
	public static function preview( $source_id, array $settings, $sample = 5 ) {
		$source = PressPort_Sources::create( $source_id, $settings );
		if ( is_wp_error( $source ) ) {
			return $source;
		}
		$prepared = $source->prepare();
		if ( is_wp_error( $prepared ) ) {
			return $prepared;
		}
		// Re-instantiate with prepared settings (detected headers, totals, cache files).
		$source = PressPort_Sources::create( $source_id, $prepared );
		$batch  = $source->fetch( null, $sample );
		if ( is_wp_error( $batch ) ) {
			return $batch;
		}
		if ( empty( $batch['records'] ) ) {
			return new WP_Error( 'pressport_empty', __( 'The source returned no records.', 'pressport' ) );
		}

		$fields = array();
		foreach ( $batch['records'] as $record ) {
			foreach ( array_keys( $record ) as $key ) {
				$fields[ $key ] = true;
			}
		}

		$job = PressPort_Job::create( $source_id, $prepared );
		if ( null === $job->data['total'] && isset( $batch['total'] ) ) {
			$job->data['total'] = $batch['total'];
		}
		$job->data['mapping']['fields'] = array_intersect( $source->suggested_mapping(), array_keys( $fields ) );
		$job->log( 'info', sprintf( /* translators: %s: source label */ __( 'Connected to source: %s', 'pressport' ), call_user_func( array( get_class( $source ), 'label' ) ) ) );
		$job->save();

		return array(
			'job'     => $job,
			'records' => $batch['records'],
			'fields'  => array_keys( $fields ),
			'mapping' => $job->data['mapping'],
		);
	}

	/**
	 * Process the next batch of a job.
	 *
	 * @param PressPort_Job $job Job.
	 * @return array|WP_Error { done: bool, results: array[] }
	 */
	public static function run_batch( PressPort_Job $job ) {
		if ( in_array( $job->data['status'], array( 'complete', 'rolled_back', 'cancelled' ), true ) ) {
			return array(
				'done'    => true,
				'results' => array(),
			);
		}

		$lock = 'pressport_lock_' . $job->data['id'];
		if ( get_transient( $lock ) ) {
			return new WP_Error( 'pressport_locked', __( 'A batch for this job is already running.', 'pressport' ) );
		}
		set_transient( $lock, 1, 5 * MINUTE_IN_SECONDS );

		$source = $job->source();
		if ( is_wp_error( $source ) ) {
			delete_transient( $lock );
			return $source;
		}

		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 300 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		}
		wp_defer_term_counting( true );
		wp_defer_comment_counting( true );

		$job->data['status'] = 'running';
		$limit               = max( 1, min( 100, (int) $job->data['options']['batch_size'] ) );
		$batch               = $source->fetch( $job->data['cursor'], $limit );

		if ( is_wp_error( $batch ) ) {
			$job->log( 'error', $batch->get_error_message() );
			$job->data['status'] = 'failed';
			$job->save();
			delete_transient( $lock );
			return $batch;
		}

		if ( null === $job->data['total'] && null !== $batch['total'] ) {
			$job->data['total'] = (int) $batch['total'];
		}

		$results = array();
		foreach ( $batch['records'] as $record ) {
			try {
				$result = PressPort_Importer::import( $record, $job );
			} catch ( Throwable $e ) {
				$result = array(
					'action'  => 'failed',
					'post_id' => 0,
					'message' => $e->getMessage(),
					'warning' => false,
				);
			}
			++$job->data['stats']['processed'];
			++$job->data['stats'][ $result['action'] ];
			$level = 'failed' === $result['action'] ? 'error' : ( ! empty( $result['warning'] ) ? 'warning' : ( 'skipped' === $result['action'] ? 'info' : 'success' ) );
			$job->log( $level, $result['message'] );
			$results[] = $result;
		}

		$job->data['cursor'] = $batch['next'];
		$done                = null === $batch['next'] || empty( $batch['records'] );
		if ( $done ) {
			$job->data['status'] = 'complete';
			$s                   = $job->data['stats'];
			$job->log(
				'info',
				sprintf(
					/* translators: 1: created, 2: updated, 3: skipped, 4: failed */
					__( 'Import finished: %1$d created, %2$d updated, %3$d skipped, %4$d failed.', 'pressport' ),
					$s['created'],
					$s['updated'],
					$s['skipped'],
					$s['failed']
				)
			);
		}
		$job->save();

		wp_defer_term_counting( false );
		wp_defer_comment_counting( false );
		delete_transient( $lock );

		return array(
			'done'    => $done,
			'results' => $results,
		);
	}

	/**
	 * Delete a batch of posts (and their imported media) created by a job.
	 *
	 * @param PressPort_Job $job   Job.
	 * @param int    $limit Posts per batch.
	 * @return array { deleted: int, remaining: int, done: bool }
	 */
	public static function rollback_batch( PressPort_Job $job, $limit = 25 ) {
		$ids = get_posts(
			array(
				'post_type'        => 'any',
				'post_status'      => 'any',
				'meta_key'         => '_pressport_job_id', // phpcs:ignore WordPress.DB.SlowDBQuery
				'meta_value'       => $job->data['id'], // phpcs:ignore WordPress.DB.SlowDBQuery
				'fields'           => 'ids',
				'posts_per_page'   => $limit,
				'suppress_filters' => true,
			)
		);
		// Attachments are not part of 'any' when their status is 'inherit' under some setups; query explicitly.
		$attachments = get_posts(
			array(
				'post_type'      => 'attachment',
				'post_status'    => 'inherit',
				'meta_key'       => '_pressport_job_id', // phpcs:ignore WordPress.DB.SlowDBQuery
				'meta_value'     => $job->data['id'], // phpcs:ignore WordPress.DB.SlowDBQuery
				'fields'         => 'ids',
				'posts_per_page' => $limit,
			)
		);
		$ids     = array_unique( array_merge( $ids, $attachments ) );
		$deleted = 0;
		foreach ( $ids as $id ) {
			$ok = 'attachment' === get_post_type( $id ) ? wp_delete_attachment( $id, true ) : wp_delete_post( $id, true );
			if ( $ok ) {
				++$deleted;
			}
		}
		$remaining = $job->imported_count();
		$done      = 0 === $remaining || 0 === $deleted;
		if ( $done ) {
			$job->data['status'] = 'rolled_back';
			$job->data['cursor'] = null;
			$job->log( 'info', __( 'Rollback complete: imported content was deleted.', 'pressport' ) );
		}
		$job->save();
		return array(
			'deleted'   => $deleted,
			'remaining' => $remaining,
			'done'      => $done,
		);
	}
}
