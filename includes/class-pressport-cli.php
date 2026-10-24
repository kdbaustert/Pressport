<?php
/**
 * WP-CLI commands.
 *
 * @package PressPort
 */

defined( 'ABSPATH' ) || exit;

/**
 * Import content from a JSON file, and export it as JSON again.
 *
 * ## EXAMPLES
 *
 *     # Preview a PressPort export and see the mapping it carries
 *     wp pressport preview json --file=./pressport-product.json
 *
 *     # Import a PressPort export, updating anything imported before
 *     wp pressport import json --file=./pressport-product.json --duplicate=update
 *
 *     # Import any JSON file with a custom mapping file
 *     wp pressport import json --file=./feed.json --map=./mapping.json
 *
 *     # Run everything from one config file
 *     wp pressport import --config=./migration.json
 *
 *     # Undo an import
 *     wp pressport rollback abc123defg --yes
 *
 *     # Export pages with ACF fields, to import on another site
 *     wp pressport export page --output=./pages.json
 */
class PressPort_CLI {

	/**
	 * Option flags that are not source settings.
	 *
	 * @var string[]
	 */
	protected $reserved = array( 'config', 'map', 'post_type', 'status', 'author', 'duplicate', 'batch_size', 'featured-images', 'content-images', 'create-authors', 'create-terms', 'rewrite-links', 'dry-run' );

	/**
	 * Show the fields, suggested mapping and first record from a source.
	 *
	 * ## OPTIONS
	 *
	 * <source>
	 * : json (or a custom registered source).
	 *
	 * [--<field>=<value>]
	 * : Source settings, e.g. --file=export.json.
	 *
	 * @param array $args  Args.
	 * @param array $assoc Assoc args.
	 */
	public function preview( $args, $assoc ) {
		$preview = PressPort_Runner::preview( $args[0], $this->settings_from( $assoc, $args[0] ), 3 );
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
	 * : json (or a custom registered source). Optional when --config is given.
	 *
	 * [--config=<file>]
	 * : JSON file: { "source": "...", "settings": {...}, "mapping": {...}, "options": {...} }
	 *
	 * [--map=<file>]
	 * : JSON mapping file: { "fields": { "post_title": "title", ... }, "meta": [{ "key": "...", "source": "..." }], "taxonomies": [{ "taxonomy": "...", "source": "..." }], "acf": { "field_name_or_key": "source_field" } }. Defaults to the suggested mapping.
	 *
	 * [--post_type=<type>]
	 * : Destination post type. Defaults to the PressPort setting.
	 *
	 * [--status=<status>]
	 * : Default status: publish, draft, pending, private.
	 *
	 * [--author=<id|login|email>]
	 * : Default author.
	 *
	 * [--duplicate=<mode>]
	 * : skip, update or create. Defaults to the PressPort setting.
	 *
	 * [--duplicates=<choice>]
	 * : What to do with records already on this site (imported before, or the same slug, SKU or media file name): skip, import (as copies), or update (the posts and pages whose title, content, excerpt, status, date, slug or author differ; the rest are skipped). Asks when left out; skips without asking when there is no terminal to ask in.
	 *
	 * [--batch_size=<n>]
	 * : Records per batch. Defaults to the PressPort setting.
	 *
	 * [--[no-]featured-images]
	 * : Download featured images. Defaults to the PressPort setting.
	 *
	 * [--content-images]
	 * : Download images found inside content.
	 *
	 * [--create-authors]
	 * : Create users for unknown authors.
	 *
	 * [--[no-]create-terms]
	 * : Create missing categories and terms. Defaults to the PressPort setting.
	 *
	 * [--[no-]rewrite-links]
	 * : Point links to the old site at this one (PressPort exports only). Defaults to the PressPort setting.
	 *
	 * [--dry-run]
	 * : Show what would happen without saving.
	 *
	 * [--<field>=<value>]
	 * : Source settings, e.g. --file=export.json.
	 *
	 * @param array $args  Args.
	 * @param array $assoc Assoc args.
	 */
	public function import( $args, $assoc ) {
		if ( isset( $assoc['duplicates'] ) && ! in_array( $assoc['duplicates'], array( 'skip', 'import', 'update' ), true ) ) {
			WP_CLI::error( '--duplicates must be skip, import or update.' );
		}
		$config = array();
		if ( ! empty( $assoc['config'] ) ) {
			$config = $this->read_json( $assoc['config'] );
		}
		$source = isset( $args[0] ) ? $args[0] : ( isset( $config['source'] ) ? $config['source'] : '' );
		if ( '' === $source ) {
			WP_CLI::error( 'Specify a source (json) or --config.' );
		}
		$settings = array_merge( isset( $config['settings'] ) ? (array) $config['settings'] : array(), $this->settings_from( $assoc, $source ) );

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
				'acf'        => array(),
			),
			(array) $mapping
		);

		// The previewed job's options: the defaults, plus the post type a PressPort export came from.
		$options = array_merge( $job->data['options'], isset( $config['options'] ) ? (array) $config['options'] : array() );
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
		if ( ! array_key_exists( $options['default_status'], get_post_statuses() ) ) {
			WP_CLI::error( sprintf( '--status must be one of: %s.', implode( ', ', array_keys( get_post_statuses() ) ) ) );
		}
		if ( ! in_array( $options['duplicate'], array( 'skip', 'update', 'create' ), true ) ) {
			WP_CLI::error( '--duplicate must be skip, update or create.' );
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
		$options['create_terms']          = (int) WP_CLI\Utils\get_flag_value( $assoc, 'create-terms', ! empty( $options['create_terms'] ) );
		$options['rewrite_links']         = (int) WP_CLI\Utils\get_flag_value( $assoc, 'rewrite-links', ! empty( $options['rewrite_links'] ) );
		$options['dry_run']               = (int) WP_CLI\Utils\get_flag_value( $assoc, 'dry-run', ! empty( $options['dry_run'] ) );

		if ( ! post_type_exists( $options['post_type'] ) ) {
			WP_CLI::error( sprintf( 'Post type "%s" does not exist.', $options['post_type'] ) );
		}
		$job->data['options'] = $options;
		if ( empty( $options['dry_run'] ) ) {
			$job->data['options'] = $this->ask_duplicates( $job, isset( $assoc['duplicates'] ) ? $assoc['duplicates'] : '' );
		}
		$job->data['status'] = 'ready';
		$job->save();

		WP_CLI::log( sprintf( 'Job %s: importing into "%s"%s', $job->data['id'], $job->data['options']['post_type'], $job->data['options']['dry_run'] ? ' (dry run)' : '' ) );
		WP_CLI::log( 'Mapping: ' . wp_json_encode( $job->data['mapping']['fields'] ) );
		$this->run( $job );
	}

	/**
	 * Report records already on this site and settle whether to skip them.
	 *
	 * @param PressPort_Job $job    Job with the options it will run with.
	 * @param string        $choice "skip", "import", "update", or '' to ask.
	 * @return array Options.
	 */
	protected function ask_duplicates( PressPort_Job $job, $choice ) {
		$options = $job->data['options'];
		$found   = PressPort_Runner::find_duplicates( $job );
		if ( is_wp_error( $found ) ) {
			WP_CLI::error( $found->get_error_message() );
		}
		if ( ! $found['count'] ) {
			return $options;
		}
		WP_CLI::log( sprintf( '%d of %d records are already on this site: %d imported before, %d with the same slug, SKU or file name.', $found['count'], $found['total'], $found['before'], $found['existing'] ) );
		foreach ( $found['examples'] as $x ) {
			WP_CLI::log( sprintf( '  "%s" (#%d)', $x['title'], $x['post_id'] ) );
		}
		if ( $found['changed'] ) {
			WP_CLI::log( sprintf( '%d of them differ from what is on this site:', count( $found['changed'] ) ) );
			foreach ( $found['changed'] as $c ) {
				WP_CLI::log( sprintf( '  "%s" (#%d): %s', $c['title'], $c['post_id'], implode( ', ', wp_list_pluck( $c['changes'], 'label' ) ) ) );
			}
		}
		if ( '' === $choice ) {
			if ( function_exists( 'posix_isatty' ) && posix_isatty( STDIN ) ) {
				$answer = $found['changed']
					? \cli\choose( 'Skip them (y), import them anyway as copies (n), or update the ones that differ (u)?', 'ynu', 'y' )
					: \cli\choose( 'Skip them? Answer n to import them anyway', 'yn', 'y' );
				$choice = array(
					'y' => 'skip',
					'n' => 'import',
					'u' => 'update',
				)[ $answer ];
			} else {
				$choice = 'skip';
				WP_CLI::log( 'Skipping them. Pass --duplicates=import or --duplicates=update to change that.' );
			}
		}
		// As in the admin: records imported before keep "update" when asked for; otherwise skip or copy.
		// "update" updates the ones that differ and skips the rest.
		$update                   = 'update' === $options['duplicate'];
		$options['skip_existing'] = 'import' === $choice ? 0 : 1;
		$options['duplicate']     = $update ? 'update' : ( 'import' === $choice ? 'create' : 'skip' );
		$options['update_ids']    = 'update' === $choice ? wp_list_pluck( $found['changed'], 'post_id' ) : array();
		return $options;
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
	 * Export content to a JSON file that PressPort (on this or another site) can import.
	 *
	 * ## OPTIONS
	 *
	 * [<post_type>]
	 * : Post type to export. Default: post.
	 *
	 * [--status=<statuses>]
	 * : Comma separated statuses, or "any". Default: the export defaults in Settings (Published).
	 *
	 * [--after=<date>]
	 * : Only content published on or after this date (YYYY-MM-DD).
	 *
	 * [--before=<date>]
	 * : Only content published on or before this date (YYYY-MM-DD).
	 *
	 * [--[no-]acf]
	 * : Include ACF fields. Defaults to the export defaults in Settings; --no-acf leaves them out.
	 *
	 * [--[no-]meta]
	 * : Include other custom fields. Defaults to the export defaults in Settings; --no-meta leaves them out.
	 *
	 * [--exclude-meta=<keys>]
	 * : Comma separated custom field keys to leave out. End a key with * to match a prefix. Default: the list in Settings.
	 *
	 * [--[no-]terms]
	 * : Include categories, tags and other terms. Defaults to the export defaults in Settings; --no-terms leaves them out.
	 *
	 * [--[no-]featured-images]
	 * : Include featured image URLs. Defaults to the export defaults in Settings; --no-featured-images leaves them out.
	 *
	 * [--[no-]woo]
	 * : Include WooCommerce product data (prices, stock, attributes, variations). Defaults to the export defaults in Settings; --no-woo leaves them out.
	 *
	 * [--output=<file>]
	 * : Where to write the file. Default: pressport-<post_type>-<date>.json in the current directory.
	 *
	 * ## EXAMPLES
	 *
	 *     wp pressport export post --status=publish,draft --output=posts.json
	 *     wp pressport export product --after=2024-01-01
	 *
	 * @param array $args  Args.
	 * @param array $assoc Assoc args.
	 */
	public function export( $args, $assoc ) {
		$defaults = PressPort_Exporter::defaults();
		$export   = PressPort_Exporter::sanitize_args(
			array(
				'post_type'     => isset( $args[0] ) ? $args[0] : 'post',
				'statuses'      => isset( $assoc['status'] ) ? $assoc['status'] : $defaults['statuses'],
				'after'         => isset( $assoc['after'] ) ? $assoc['after'] : '',
				'before'        => isset( $assoc['before'] ) ? $assoc['before'] : '',
				'include_acf'   => WP_CLI\Utils\get_flag_value( $assoc, 'acf', ! empty( $defaults['include_acf'] ) ),
				'include_meta'  => WP_CLI\Utils\get_flag_value( $assoc, 'meta', ! empty( $defaults['include_meta'] ) ),
				'include_terms' => WP_CLI\Utils\get_flag_value( $assoc, 'terms', ! empty( $defaults['include_terms'] ) ),
				'include_image' => WP_CLI\Utils\get_flag_value( $assoc, 'featured-images', ! empty( $defaults['include_image'] ) ),
				'include_woo'   => WP_CLI\Utils\get_flag_value( $assoc, 'woo', ! empty( $defaults['include_woo'] ) ),
				'exclude_meta'  => isset( $assoc['exclude-meta'] ) ? $assoc['exclude-meta'] : $defaults['exclude_meta'],
			)
		);
		if ( is_wp_error( $export ) ) {
			WP_CLI::error( $export->get_error_message() );
		}
		$state = PressPort_Exporter::start( $export );
		if ( is_wp_error( $state ) ) {
			WP_CLI::error( $state->get_error_message() );
		}
		if ( ! $state['total'] ) {
			PressPort_Exporter::forget( $state['id'] );
			WP_CLI::error( 'Nothing to export with these filters.' );
		}
		$progress = WP_CLI\Utils\make_progress_bar( sprintf( 'Exporting %d %s', $state['total'], $export['post_type'] ), $state['total'] );
		while ( empty( $state['complete'] ) ) {
			$before = $state['done'];
			PressPort_Exporter::run_batch( $state, 100 );
			$progress->tick( $state['done'] - $before );
			if ( function_exists( 'wp_cache_flush_runtime' ) ) {
				wp_cache_flush_runtime();
			}
		}
		$progress->finish();

		$output = isset( $assoc['output'] ) ? $assoc['output'] : $state['filename'];
		if ( ! copy( $state['file'], $output ) ) {
			WP_CLI::error( sprintf( 'Could not write %s', $output ) );
		}
		PressPort_Exporter::delete( $state );
		WP_CLI::success( sprintf( 'Exported %d items (%d ACF fields, %d custom fields) to %s', $state['done'], count( $state['acf_names'] ), count( $state['meta_keys'] ), $output ) );
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
			if ( ! empty( $result['error'] ) ) {
				WP_CLI::error( sprintf( '%s (%d items deleted before it stopped.)', $result['error'], $deleted ) );
			}
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
	 * @param array  $assoc  Assoc args.
	 * @param string $source Source id.
	 * @return array
	 */
	protected function settings_from( $assoc, $source ) {
		$settings = array();
		foreach ( $assoc as $key => $value ) {
			if ( in_array( $key, $this->reserved, true ) ) {
				continue;
			}
			if ( 'file' === $key && preg_match( '#^https?://#i', (string) $value ) ) {
				if ( ! $this->remote_file_allowed( $source ) ) {
					WP_CLI::error( sprintf( 'The %s source needs a local file: download it first and pass its path to --file.', $source ) );
				}
				// Remote file: downloaded by the source when it is prepared.
				$settings['file_url'] = $value;
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
	 * Whether a source's "file" setting may be a URL (the JSON source takes uploads only).
	 *
	 * @param string $source Source id.
	 * @return bool
	 */
	protected function remote_file_allowed( $source ) {
		$all = PressPort_Sources::all();
		if ( empty( $all[ $source ] ) || ! class_exists( $all[ $source ] ) ) {
			return true; // Unknown source: preview() reports that.
		}
		foreach ( call_user_func( array( $all[ $source ], 'settings_schema' ) ) as $field ) {
			if ( 'file' === $field['key'] ) {
				return PressPort_Source_Base::allows_remote( $field );
			}
		}
		return true;
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
