<?php
/**
 * WP-CLI commands.
 *
 * @package PressPort
 */

defined( 'ABSPATH' ) || exit;

/**
 * Import content from CSV, JSON feeds, WordPress, Drupal or Ghost.
 *
 * ## EXAMPLES
 *
 *     # Preview a CSV and see suggested mapping
 *     wp pressport preview csv --file=./posts.csv
 *
 *     # Import a CSV as pages, skipping rows imported before
 *     wp pressport import csv --file=./pages.csv --post_type=page
 *
 *     # Import a JSON feed with a custom mapping file
 *     wp pressport import json --url=https://example.com/feed.json --map=./mapping.json
 *
 *     # Pull posts from another WordPress site, updating existing ones
 *     wp pressport import wordpress --site_url=https://old.example.com --duplicate=update
 *
 *     # Run everything from one config file
 *     wp pressport import --config=./migration.json
 *
 *     # Undo an import
 *     wp pressport rollback abc123defg --yes
 */
class PressPort_CLI {

	/**
	 * Option flags that are not source settings.
	 *
	 * @var string[]
	 */
	protected $reserved = array( 'config', 'map', 'post_type', 'status', 'author', 'duplicate', 'batch_size', 'featured-images', 'content-images', 'create-authors', 'create-terms', 'dry-run', 'limit' );

	/**
	 * Show the fields, suggested mapping and first record from a source.
	 *
	 * ## OPTIONS
	 *
	 * <source>
	 * : csv, json, wordpress, drupal, ghost (or a custom registered source).
	 *
	 * [--<field>=<value>]
	 * : Source settings, e.g. --file=posts.csv, --url=..., --site_url=..., --api_key=...
	 *
	 * @param array $args  Args.
	 * @param array $assoc Assoc args.
	 */
	public function preview( $args, $assoc ) {
		$preview = PressPort_Runner::preview( $args[0], $this->settings_from( $assoc ), 3 );
		if ( is_wp_error( $preview ) ) {
			WP_CLI::error( $preview->get_error_message() );
		}
		$job = $preview['job'];
		WP_CLI::log( sprintf( 'Records: %s', null === $job->data['total'] ? 'unknown' : $job->data['total'] ) );
		WP_CLI::log( 'Fields: ' . implode( ', ', $preview['fields'] ) );
		WP_CLI::log( "\nSuggested mapping (save as a --map file and edit):" );
		WP_CLI::log( wp_json_encode( $preview['mapping'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
		WP_CLI::log( "\nFirst record:" );
		$rows = array();
		foreach ( $preview['records'][0] as $k => $v ) {
			$rows[] = array(
				'field' => $k,
				'value' => mb_strimwidth( PressPort_Util::stringify( $v ), 0, 90, '…' ),
			);
		}
		WP_CLI\Utils\format_items( 'table', $rows, array( 'field', 'value' ) );
		$job->delete();
	}

	/**
	 * Run an import.
	 *
	 * ## OPTIONS
	 *
	 * [<source>]
	 * : csv, json, wordpress, drupal, ghost. Optional when --config is given.
	 *
	 * [--config=<file>]
	 * : JSON file: { "source": "...", "settings": {...}, "mapping": {...}, "options": {...} }
	 *
	 * [--map=<file>]
	 * : JSON mapping file: { "fields": { "post_title": "title", ... }, "meta": [{ "key": "...", "source": "..." }], "taxonomies": [{ "taxonomy": "...", "source": "..." }] }. Defaults to the suggested mapping.
	 *
	 * [--post_type=<type>]
	 * : Destination post type. Default: post.
	 *
	 * [--status=<status>]
	 * : Default status: publish, draft, pending, private.
	 *
	 * [--author=<id|login|email>]
	 * : Default author.
	 *
	 * [--duplicate=<mode>]
	 * : skip, update or create. Default: skip.
	 *
	 * [--batch_size=<n>]
	 * : Records per batch. Default: 25.
	 *
	 * [--[no-]featured-images]
	 * : Download featured images. Default: yes.
	 *
	 * [--content-images]
	 * : Download images found inside content.
	 *
	 * [--create-authors]
	 * : Create users for unknown authors.
	 *
	 * [--dry-run]
	 * : Show what would happen without saving.
	 *
	 * [--<field>=<value>]
	 * : Source settings, e.g. --file=posts.csv, --url=..., --site_url=..., --bundle=article.
	 *
	 * @param array $args  Args.
	 * @param array $assoc Assoc args.
	 */
	public function import( $args, $assoc ) {
		$config = array();
		if ( ! empty( $assoc['config'] ) ) {
			$config = $this->read_json( $assoc['config'] );
		}
		$source = isset( $args[0] ) ? $args[0] : ( isset( $config['source'] ) ? $config['source'] : '' );
		if ( '' === $source ) {
			WP_CLI::error( 'Specify a source (csv, json, wordpress, drupal, ghost) or --config.' );
		}
		$settings = array_merge( isset( $config['settings'] ) ? (array) $config['settings'] : array(), $this->settings_from( $assoc ) );

		$preview = PressPort_Runner::preview( $source, $settings, 1 );
		if ( is_wp_error( $preview ) ) {
			WP_CLI::error( $preview->get_error_message() );
		}
		$job = $preview['job'];

		$mapping = $job->data['mapping'];
		if ( ! empty( $config['mapping'] ) ) {
			$mapping = $config['mapping'];
		}
		if ( ! empty( $assoc['map'] ) ) {
			$mapping = $this->read_json( $assoc['map'] );
		}
		$job->data['mapping'] = array_merge(
			array(
				'fields'     => array(),
				'meta'       => array(),
				'taxonomies' => array(),
			),
			(array) $mapping
		);

		$options = array_merge( PressPort_Job::default_options(), array( 'batch_size' => 25 ), isset( $config['options'] ) ? (array) $config['options'] : array() );
		$flags   = array(
			'post_type'  => 'post_type',
			'status'     => 'default_status',
			'duplicate'  => 'duplicate',
			'batch_size' => 'batch_size',
		);
		foreach ( $flags as $flag => $key ) {
			if ( isset( $assoc[ $flag ] ) ) {
				$options[ $key ] = $assoc[ $flag ];
			}
		}
		if ( isset( $assoc['author'] ) ) {
			$user = is_numeric( $assoc['author'] ) ? get_userdata( (int) $assoc['author'] ) : ( get_user_by( 'login', $assoc['author'] ) ? get_user_by( 'login', $assoc['author'] ) : get_user_by( 'email', $assoc['author'] ) );
			if ( ! $user ) {
				WP_CLI::error( 'Default author not found.' );
			}
			$options['default_author'] = $user->ID;
		}
		if ( empty( $options['default_author'] ) ) {
			$admins                    = get_users(
				array(
					'role'   => 'administrator',
					'number' => 1,
					'fields' => 'ID',
				)
			);
			$options['default_author'] = $admins ? (int) $admins[0] : 0;
		}
		$options['import_featured_image'] = (int) WP_CLI\Utils\get_flag_value( $assoc, 'featured-images', ! empty( $options['import_featured_image'] ) );
		$options['import_content_images'] = (int) WP_CLI\Utils\get_flag_value( $assoc, 'content-images', ! empty( $options['import_content_images'] ) );
		$options['create_authors']        = (int) WP_CLI\Utils\get_flag_value( $assoc, 'create-authors', ! empty( $options['create_authors'] ) );
		$options['dry_run']               = (int) WP_CLI\Utils\get_flag_value( $assoc, 'dry-run', ! empty( $options['dry_run'] ) );

		if ( ! post_type_exists( $options['post_type'] ) ) {
			WP_CLI::error( sprintf( 'Post type "%s" does not exist.', $options['post_type'] ) );
		}
		$job->data['options'] = $options;
		$job->data['status']  = 'ready';
		$job->save();

		WP_CLI::log( sprintf( 'Job %s: importing into "%s"%s', $job->data['id'], $options['post_type'], $options['dry_run'] ? ' (dry run)' : '' ) );
		WP_CLI::log( 'Mapping: ' . wp_json_encode( $job->data['mapping']['fields'] ) );
		$this->run( $job );
	}

	/**
	 * Resume an interrupted job.
	 *
	 * ## OPTIONS
	 *
	 * <job>
	 * : Job id.
	 *
	 * @param array $args Args.
	 */
	public function resume( $args ) {
		$job = PressPort_Job::load( $args[0] );
		if ( ! $job ) {
			WP_CLI::error( 'Job not found.' );
		}
		$this->run( $job );
	}

	/**
	 * Delete all posts and media created by a job.
	 *
	 * ## OPTIONS
	 *
	 * <job>
	 * : Job id.
	 *
	 * [--yes]
	 * : Skip confirmation.
	 *
	 * @param array $args  Args.
	 * @param array $assoc Assoc args.
	 */
	public function rollback( $args, $assoc ) {
		$job = PressPort_Job::load( $args[0] );
		if ( ! $job ) {
			WP_CLI::error( 'Job not found.' );
		}
		$count = $job->imported_count();
		WP_CLI::confirm( sprintf( 'Permanently delete %d items created by job %s?', $count, $job->data['id'] ), $assoc );
		$deleted = 0;
		do {
			$result   = PressPort_Runner::rollback_batch( $job, 100 );
			$deleted += $result['deleted'];
		} while ( ! $result['done'] );
		WP_CLI::success( sprintf( 'Deleted %d items.', $deleted ) );
	}

	/**
	 * List import jobs.
	 *
	 * [--format=<format>]
	 * : table, json, csv. Default: table.
	 *
	 * @subcommand jobs
	 * @param array $args  Args.
	 * @param array $assoc Assoc args.
	 */
	public function jobs( $args, $assoc ) {
		$rows = array();
		foreach ( PressPort_Job::all() as $job ) {
			$d      = $job->data;
			$rows[] = array(
				'id'        => $d['id'],
				'created'   => wp_date( 'Y-m-d H:i', $d['created'] ),
				'source'    => $d['source'],
				'post_type' => $d['options']['post_type'],
				'status'    => $d['status'] . ( ! empty( $d['options']['dry_run'] ) ? ' (dry)' : '' ),
				'created_n' => $d['stats']['created'],
				'updated'   => $d['stats']['updated'],
				'skipped'   => $d['stats']['skipped'],
				'failed'    => $d['stats']['failed'],
			);
		}
		WP_CLI\Utils\format_items( isset( $assoc['format'] ) ? $assoc['format'] : 'table', $rows, array( 'id', 'created', 'source', 'post_type', 'status', 'created_n', 'updated', 'skipped', 'failed' ) );
	}

	/**
	 * @param PressPort_Job $job Job.
	 */
	protected function run( PressPort_Job $job ) {
		$total    = $job->data['total'] ? (int) $job->data['total'] : 0;
		$progress = $total ? WP_CLI\Utils\make_progress_bar( 'Importing', max( 0, $total - (int) $job->data['stats']['processed'] ) ) : null;
		do {
			$result = PressPort_Runner::run_batch( $job );
			if ( is_wp_error( $result ) ) {
				if ( $progress ) {
					$progress->finish();
				}
				WP_CLI::error( $result->get_error_message() . sprintf( ' Resume with: wp pressport resume %s', $job->data['id'] ) );
			}
			foreach ( $result['results'] as $r ) {
				if ( 'failed' === $r['action'] ) {
					WP_CLI::warning( $r['message'] );
				} elseif ( ! empty( $r['warning'] ) ) {
					WP_CLI::warning( $r['message'] );
				} elseif ( ! $progress ) {
					WP_CLI::log( $r['message'] );
				}
				if ( $progress ) {
					$progress->tick();
				}
			}
			// Free memory between batches on long runs.
			if ( function_exists( 'wp_cache_flush_runtime' ) ) {
				wp_cache_flush_runtime();
			}
		} while ( ! $result['done'] );
		if ( $progress ) {
			$progress->finish();
		}
		$s = $job->data['stats'];
		WP_CLI::success( sprintf( '%s%d created, %d updated, %d skipped, %d failed. Job id: %s', ! empty( $job->data['options']['dry_run'] ) ? '[dry run] ' : '', $s['created'], $s['updated'], $s['skipped'], $s['failed'], $job->data['id'] ) );
	}

	/**
	 * Extract source settings from assoc args.
	 *
	 * @param array $assoc Assoc args.
	 * @return array
	 */
	protected function settings_from( $assoc ) {
		$settings = array();
		foreach ( $assoc as $key => $value ) {
			if ( in_array( $key, $this->reserved, true ) ) {
				continue;
			}
			if ( 'file' === $key ) {
				$path = realpath( $value );
				if ( ! $path ) {
					WP_CLI::error( sprintf( 'File not found: %s', $value ) );
				}
				$value = $path;
			}
			$settings[ $key ] = $value;
		}
		return $settings;
	}

	/**
	 * @param string $path Path.
	 * @return array
	 */
	protected function read_json( $path ) {
		if ( ! is_readable( $path ) ) {
			WP_CLI::error( sprintf( 'Cannot read %s', $path ) );
		}
		$data = json_decode( (string) file_get_contents( $path ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		if ( ! is_array( $data ) ) {
			WP_CLI::error( sprintf( '%s is not valid JSON.', $path ) );
		}
		return $data;
	}
}
