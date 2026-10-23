<?php
/**
 * Admin screen + AJAX endpoints.
 *
 * @package PressPort
 */

defined( 'ABSPATH' ) || exit;

/**
 * Tools → PressPort.
 */
class PressPort_Admin {

	const SLUG = 'pressport';

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( PRESSPORT_FILE ), array( __CLASS__, 'action_links' ) );

		foreach ( array( 'preview', 'save', 'run', 'rollback', 'delete', 'jobs', 'job', 'restart' ) as $action ) {
			add_action( 'wp_ajax_pressport_' . $action, array( __CLASS__, 'ajax_' . $action ) );
		}
	}

	/**
	 * Capability required to use the tool.
	 *
	 * @return string
	 */
	public static function capability() {
		return (string) apply_filters( 'pressport_capability', 'manage_options' );
	}

	public static function menu() {
		add_management_page(
			__( 'PressPort', 'pressport' ),
			__( 'PressPort', 'pressport' ),
			self::capability(),
			self::SLUG,
			array( __CLASS__, 'render' )
		);
	}

	/**
	 * @param array $links Links.
	 * @return array
	 */
	public static function action_links( $links ) {
		array_unshift( $links, '<a href="' . esc_url( admin_url( 'tools.php?page=' . self::SLUG ) ) . '">' . esc_html__( 'Import content', 'pressport' ) . '</a>' );
		return $links;
	}

	/**
	 * @param string $hook Hook suffix.
	 */
	public static function assets( $hook ) {
		if ( 'tools_page_' . self::SLUG !== $hook ) {
			return;
		}
		wp_enqueue_style( 'pressport', PRESSPORT_URL . 'assets/admin.css', array(), PRESSPORT_VERSION );
		wp_enqueue_script( 'pressport', PRESSPORT_URL . 'assets/admin.js', array(), PRESSPORT_VERSION, true );
		wp_localize_script( 'pressport', 'PRESSPORT_DATA', self::bootstrap_data() );
	}

	/**
	 * Data passed to the JS app.
	 *
	 * @return array
	 */
	protected static function bootstrap_data() {
		$sources = array();
		foreach ( PressPort_Sources::all() as $id => $class ) {
			if ( class_exists( $class ) ) {
				$sources[] = array(
					'id'     => $id,
					'label'  => call_user_func( array( $class, 'label' ) ),
					'schema' => call_user_func( array( $class, 'settings_schema' ) ),
				);
			}
		}

		$post_types = array();
		foreach ( get_post_types( array( 'show_ui' => true ), 'objects' ) as $pt ) {
			if ( in_array( $pt->name, array( 'attachment', 'wp_block', 'wp_navigation', 'wp_template', 'wp_template_part' ), true ) ) {
				continue;
			}
			$taxes = array();
			foreach ( get_object_taxonomies( $pt->name, 'objects' ) as $tax ) {
				if ( $tax->show_ui && 'post_format' !== $tax->name ) {
					$taxes[] = array(
						'name'  => $tax->name,
						'label' => $tax->labels->singular_name,
					);
				}
			}
			$post_types[] = array(
				'name'       => $pt->name,
				'label'      => $pt->labels->singular_name,
				'taxonomies' => $taxes,
			);
		}

		$users = array();
		foreach ( get_users(
			array(
				'capability' => array( 'edit_posts' ),
				'number'     => 200,
				'fields'     => array( 'ID', 'display_name' ),
			)
		) as $u ) {
			$users[] = array(
				'id'   => (int) $u->ID,
				'name' => $u->display_name,
			);
		}

		$targets = array();
		foreach ( PressPort_Importer::targets() as $key => $info ) {
			$targets[] = array(
				'key'   => $key,
				'label' => $info[0],
				'help'  => $info[1],
			);
		}

		return array(
			'ajaxUrl'   => admin_url( 'admin-ajax.php' ),
			'nonce'     => wp_create_nonce( 'pressport_admin' ),
			'adminUrl'  => admin_url(),
			'sources'   => $sources,
			'postTypes' => $post_types,
			'users'     => $users,
			'targets'   => $targets,
			'defaults'  => PressPort_Job::default_options(),
			'maxUpload' => size_format( wp_max_upload_size() ),
		);
	}

	/**
	 * Render the page shell; the UI is built by assets/admin.js.
	 */
	public static function render() {
		?>
		<div class="wrap pressport-wrap">
			<h1><?php esc_html_e( 'PressPort', 'pressport' ); ?></h1>
			<p class="pressport-intro"><?php esc_html_e( 'Import content from a CSV file, a JSON feed, or another CMS. Map fields, preview, run in safe batches, and roll back if needed.', 'pressport' ); ?></p>
			<nav class="nav-tab-wrapper pressport-tabs">
				<a href="#new" class="nav-tab nav-tab-active" data-tab="new"><?php esc_html_e( 'New import', 'pressport' ); ?></a>
				<a href="#history" class="nav-tab" data-tab="history"><?php esc_html_e( 'History', 'pressport' ); ?></a>
			</nav>
			<div id="pressport-app"><p><?php esc_html_e( 'Loading…', 'pressport' ); ?></p></div>
			<noscript><div class="notice notice-error"><p><?php esc_html_e( 'JavaScript is required for the importer.', 'pressport' ); ?></p></div></noscript>
		</div>
		<?php
	}

	// AJAX handlers.

	/**
	 * Verify nonce + capability, or die with JSON error.
	 */
	protected static function guard() {
		if ( ! check_ajax_referer( 'pressport_admin', 'nonce', false ) ) {
			wp_send_json_error( array( 'message' => __( 'Your session expired. Reload the page.', 'pressport' ) ), 403 );
		}
		if ( ! current_user_can( self::capability() ) ) {
			wp_send_json_error( array( 'message' => __( 'You are not allowed to do this.', 'pressport' ) ), 403 );
		}
	}

	/**
	 * Load the job from the request or die.
	 *
	 * @return PressPort_Job
	 */
	protected static function request_job() {
		$id  = isset( $_POST['job'] ) ? sanitize_key( wp_unslash( $_POST['job'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		$job = PressPort_Job::load( $id );
		if ( ! $job ) {
			wp_send_json_error( array( 'message' => __( 'Import job not found.', 'pressport' ) ), 404 );
		}
		return $job;
	}

	/**
	 * @param WP_Error $error Error.
	 */
	protected static function fail( WP_Error $error ) {
		wp_send_json_error( array( 'message' => $error->get_error_message() ), 400 );
	}

	/**
	 * Store an uploaded file in the private storage dir.
	 *
	 * @param array    $file    $_FILES entry.
	 * @param string[] $allowed Allowed extensions.
	 * @return string|WP_Error Path.
	 */
	protected static function store_upload( $file, $allowed ) {
		if ( ! empty( $file['error'] ) ) {
			/* translators: %d: PHP upload error code */
			return new WP_Error( 'pressport_upload', sprintf( __( 'Upload failed (error %d). Check the file size limit.', 'pressport' ), (int) $file['error'] ) );
		}
		$ext = strtolower( pathinfo( (string) $file['name'], PATHINFO_EXTENSION ) );
		if ( ! in_array( $ext, $allowed, true ) ) {
			/* translators: %s: allowed extensions */
			return new WP_Error( 'pressport_upload_type', sprintf( __( 'Unsupported file type. Allowed: %s', 'pressport' ), implode( ', ', $allowed ) ) );
		}
		if ( ! is_uploaded_file( $file['tmp_name'] ) ) {
			return new WP_Error( 'pressport_upload', __( 'Invalid upload.', 'pressport' ) );
		}
		$dest = PressPort_Util::ensure_storage_dir() . 'upload-' . wp_generate_password( 12, false ) . '.' . $ext;
		if ( ! move_uploaded_file( $file['tmp_name'], $dest ) ) {
			return new WP_Error( 'pressport_upload', __( 'Could not save the uploaded file.', 'pressport' ) );
		}
		return $dest;
	}

	/**
	 * Step 1: connect to the source and preview records.
	 */
	public static function ajax_preview() {
		self::guard();
		// phpcs:disable WordPress.Security.NonceVerification -- verified in guard().
		$source_id = isset( $_POST['source'] ) ? sanitize_key( wp_unslash( $_POST['source'] ) ) : '';
		$all       = PressPort_Sources::all();
		if ( empty( $all[ $source_id ] ) ) {
			self::fail( new WP_Error( 'pressport_source', __( 'Choose a source.', 'pressport' ) ) );
		}
		$class    = $all[ $source_id ];
		$raw      = isset( $_POST['settings'] ) && is_array( $_POST['settings'] ) ? wp_unslash( $_POST['settings'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$settings = array();
		foreach ( call_user_func( array( $class, 'settings_schema' ) ) as $field ) {
			$key = $field['key'];
			if ( 'file' === $field['type'] ) {
				if ( ! empty( $_FILES[ 'file_' . $key ]['name'] ) ) {
					$accept = isset( $field['accept'] ) ? $field['accept'] : '';
					$exts   = array();
					foreach ( explode( ',', $accept ) as $a ) {
						if ( 0 === strpos( trim( $a ), '.' ) ) {
							$exts[] = ltrim( trim( $a ), '.' );
						}
					}
					$exts[] = 'txt';
					$path   = self::store_upload( $_FILES[ 'file_' . $key ], $exts ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
					if ( is_wp_error( $path ) ) {
						self::fail( $path );
					}
					$settings[ $key ] = $path;
				}
				continue;
			}
			$value = isset( $raw[ $key ] ) ? $raw[ $key ] : '';
			switch ( $field['type'] ) {
				case 'url':
					$settings[ $key ] = esc_url_raw( trim( (string) $value ) );
					break;
				case 'textarea':
					$settings[ $key ] = sanitize_textarea_field( (string) $value );
					break;
				case 'password':
					$settings[ $key ] = trim( (string) $value );
					break;
				default:
					$settings[ $key ] = sanitize_text_field( (string) $value );
			}
		}
		// phpcs:enable

		$preview = PressPort_Runner::preview( $source_id, $settings );
		if ( is_wp_error( $preview ) ) {
			self::fail( $preview );
		}

		// Trim long values for display.
		$records = array();
		foreach ( $preview['records'] as $record ) {
			$row = array();
			foreach ( $record as $k => $v ) {
				$s         = PressPort_Util::stringify( $v );
				$row[ $k ] = function_exists( 'mb_substr' ) ? mb_substr( $s, 0, 400 ) : substr( $s, 0, 400 );
			}
			$records[] = $row;
		}

		wp_send_json_success(
			array(
				'job'     => $preview['job']->to_public(),
				'records' => $records,
				'fields'  => $preview['fields'],
			)
		);
	}

	/**
	 * Step 2: save mapping + options.
	 */
	public static function ajax_save() {
		self::guard();
		$job = self::request_job();
		// phpcs:disable WordPress.Security.NonceVerification, WordPress.Security.ValidatedSanitizedInput
		$mapping = isset( $_POST['mapping'] ) ? json_decode( wp_unslash( $_POST['mapping'] ), true ) : array();
		$options = isset( $_POST['options'] ) ? json_decode( wp_unslash( $_POST['options'] ), true ) : array();
		// phpcs:enable

		$clean_map = array(
			'fields'     => array(),
			'meta'       => array(),
			'taxonomies' => array(),
		);
		$targets   = PressPort_Importer::targets();
		foreach ( isset( $mapping['fields'] ) ? (array) $mapping['fields'] : array() as $target => $source ) {
			if ( isset( $targets[ $target ] ) && '' !== (string) $source ) {
				$clean_map['fields'][ $target ] = sanitize_text_field( (string) $source );
			}
		}
		foreach ( isset( $mapping['meta'] ) ? (array) $mapping['meta'] : array() as $row ) {
			if ( ! empty( $row['key'] ) && ! empty( $row['source'] ) ) {
				$clean_map['meta'][] = array(
					'key'    => sanitize_text_field( $row['key'] ),
					'source' => sanitize_text_field( $row['source'] ),
				);
			}
		}
		foreach ( isset( $mapping['taxonomies'] ) ? (array) $mapping['taxonomies'] : array() as $row ) {
			if ( ! empty( $row['taxonomy'] ) && ! empty( $row['source'] ) ) {
				$clean_map['taxonomies'][] = array(
					'taxonomy' => sanitize_key( $row['taxonomy'] ),
					'source'   => sanitize_text_field( $row['source'] ),
				);
			}
		}
		if ( empty( $clean_map['fields']['post_title'] ) && empty( $clean_map['fields']['post_content'] ) ) {
			self::fail( new WP_Error( 'pressport_mapping', __( 'Map at least the Title or Content field.', 'pressport' ) ) );
		}

		$defaults  = PressPort_Job::default_options();
		$post_type = isset( $options['post_type'] ) ? sanitize_key( $options['post_type'] ) : 'post';
		$status    = isset( $options['default_status'] ) ? sanitize_key( $options['default_status'] ) : 'publish';
		$duplicate = isset( $options['duplicate'] ) ? sanitize_key( $options['duplicate'] ) : 'skip';
		$author    = isset( $options['default_author'] ) ? absint( $options['default_author'] ) : get_current_user_id();
		$clean_opt = array(
			'post_type'             => post_type_exists( $post_type ) ? $post_type : 'post',
			'default_status'        => in_array( $status, array( 'publish', 'draft', 'pending', 'private' ), true ) ? $status : 'draft',
			'default_author'        => get_userdata( $author ) ? $author : get_current_user_id(),
			'duplicate'             => in_array( $duplicate, array( 'skip', 'update', 'create' ), true ) ? $duplicate : 'skip',
			'batch_size'            => max( 1, min( 100, isset( $options['batch_size'] ) ? (int) $options['batch_size'] : $defaults['batch_size'] ) ),
			'import_featured_image' => empty( $options['import_featured_image'] ) ? 0 : 1,
			'import_content_images' => empty( $options['import_content_images'] ) ? 0 : 1,
			'create_terms'          => empty( $options['create_terms'] ) ? 0 : 1,
			'create_authors'        => empty( $options['create_authors'] ) ? 0 : 1,
			'dry_run'               => empty( $options['dry_run'] ) ? 0 : 1,
		);

		$job->data['mapping'] = $clean_map;
		$job->data['options'] = $clean_opt;
		$job->data['status']  = 'ready';
		$job->data['cursor']  = null;
		$job->data['stats']   = array_fill_keys( array( 'processed', 'created', 'updated', 'skipped', 'failed' ), 0 );
		$job->log( 'info', $clean_opt['dry_run'] ? __( 'Dry run started, nothing will be saved.', 'pressport' ) : __( 'Import started.', 'pressport' ) );
		$job->save();

		wp_send_json_success( array( 'job' => $job->to_public() ) );
	}

	/**
	 * Step 3: run one batch.
	 */
	public static function ajax_run() {
		self::guard();
		$job    = self::request_job();
		$result = PressPort_Runner::run_batch( $job );
		if ( is_wp_error( $result ) ) {
			self::fail( $result );
		}
		wp_send_json_success(
			array(
				'job'     => $job->to_public(),
				'done'    => $result['done'],
				'results' => $result['results'],
			)
		);
	}

	/**
	 * Re-run a finished job from the start with the same settings.
	 */
	public static function ajax_restart() {
		self::guard();
		$job = self::request_job();
		if ( 'json' === $job->data['source'] && ! empty( $job->data['settings']['url'] ) ) {
			// Refresh the cached feed so re-runs pick up new items.
			$source = $job->source();
			if ( ! is_wp_error( $source ) ) {
				$prepared = $source->prepare();
				if ( ! is_wp_error( $prepared ) ) {
					$old = isset( $job->data['settings']['cache_file'] ) ? $job->data['settings']['cache_file'] : '';
					if ( $old && $old !== $prepared['cache_file'] && file_exists( $old ) ) {
						wp_delete_file( $old );
					}
					$job->data['settings'] = $prepared;
					$job->data['total']    = $prepared['total'];
				}
			}
		}
		$job->data['status'] = 'ready';
		$job->data['cursor'] = null;
		$job->data['stats']  = array_fill_keys( array( 'processed', 'created', 'updated', 'skipped', 'failed' ), 0 );
		$job->log( 'info', __( 'Job restarted.', 'pressport' ) );
		$job->save();
		wp_send_json_success( array( 'job' => $job->to_public() ) );
	}

	/**
	 * Roll back one batch.
	 */
	public static function ajax_rollback() {
		self::guard();
		$job    = self::request_job();
		$result = PressPort_Runner::rollback_batch( $job );
		wp_send_json_success( array_merge( $result, array( 'job' => $job->to_public() ) ) );
	}

	/**
	 * Delete a job record (not its content).
	 */
	public static function ajax_delete() {
		self::guard();
		$job = self::request_job();
		$job->delete();
		wp_send_json_success();
	}

	/**
	 * List jobs.
	 */
	public static function ajax_jobs() {
		self::guard();
		$jobs = array();
		foreach ( PressPort_Job::all() as $job ) {
			$data          = $job->to_public();
			$data['log']   = array();
			$data['label'] = self::source_label( $job->data['source'] );
			$jobs[]        = $data;
		}
		wp_send_json_success( array( 'jobs' => $jobs ) );
	}

	/**
	 * Single job (with log).
	 */
	public static function ajax_job() {
		self::guard();
		$job           = self::request_job();
		$data          = $job->to_public();
		$data['label'] = self::source_label( $job->data['source'] );
		wp_send_json_success( array( 'job' => $data ) );
	}

	/**
	 * @param string $id Source id.
	 * @return string
	 */
	protected static function source_label( $id ) {
		$all = PressPort_Sources::all();
		return isset( $all[ $id ] ) && class_exists( $all[ $id ] ) ? call_user_func( array( $all[ $id ], 'label' ) ) : $id;
	}
}
