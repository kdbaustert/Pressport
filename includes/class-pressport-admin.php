<?php
/**
 * Admin screen + AJAX endpoints.
 *
 * @package PressPort
 */

defined( 'ABSPATH' ) || exit;

class PressPort_Admin {

	const SLUG         = 'pressport';
	const HISTORY_SLUG = 'pressport-history';
	const EXPORT_SLUG  = 'pressport-export';
	const REPLACE_SLUG = 'pressport-replace';

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( PRESSPORT_FILE ), array( __CLASS__, 'action_links' ) );

		foreach ( array( 'preview', 'duplicates', 'media_preview', 'save', 'run', 'rollback', 'delete', 'jobs', 'job', 'restart', 'export_count', 'export_start', 'export_run', 'export_download', 'exports', 'export_log', 'export_forget', 'replace_start', 'replace_run', 'replace_history', 'replace_rollback', 'replace_forget' ) as $action ) {
			add_action( 'wp_ajax_pressport_' . $action, array( __CLASS__, 'ajax_' . $action ) );
		}
	}

	/**
	 * Capability required to use the tool.
	 *
	 * @return string
	 */
	public static function capability() {
		return (string) apply_filters( 'pressport_capability', PressPort_Settings::get( 'capability' ) );
	}

	public static function menu() {
		add_menu_page(
			__( 'PressPort', 'pressport' ),
			__( 'PressPort', 'pressport' ),
			self::capability(),
			self::SLUG,
			array( __CLASS__, 'render_import' ),
			'dashicons-migrate',
			76
		);
		add_submenu_page( self::SLUG, __( 'Import', 'pressport' ), __( 'Import', 'pressport' ), self::capability(), self::SLUG, array( __CLASS__, 'render_import' ) );
		add_submenu_page( self::SLUG, __( 'Export Content', 'pressport' ), __( 'Export', 'pressport' ), self::capability(), self::EXPORT_SLUG, array( __CLASS__, 'render_export' ) );
		add_submenu_page( self::SLUG, __( 'History', 'pressport' ), __( 'History', 'pressport' ), self::capability(), self::HISTORY_SLUG, array( __CLASS__, 'render_history' ) );
		add_submenu_page( self::SLUG, __( 'Find & Replace', 'pressport' ), __( 'Find & Replace', 'pressport' ), 'manage_options', self::REPLACE_SLUG, array( __CLASS__, 'render_replace' ) );
		add_submenu_page( self::SLUG, __( 'PressPort Settings', 'pressport' ), __( 'Settings', 'pressport' ), 'manage_options', PressPort_Settings::PAGE, array( 'PressPort_Settings', 'render' ) );
	}

	/**
	 * Admin URL for one of our pages.
	 *
	 * @param string $page Page slug.
	 * @param array  $args Extra query args.
	 * @return string
	 */
	public static function url( $page = self::SLUG, $args = array() ) {
		return add_query_arg( array_merge( array( 'page' => $page ), $args ), admin_url( 'admin.php' ) );
	}

	/**
	 * @param array $links Links.
	 * @return array
	 */
	public static function action_links( $links ) {
		array_unshift(
			$links,
			'<a href="' . esc_url( self::url() ) . '">' . esc_html__( 'Import', 'pressport' ) . '</a>',
			'<a href="' . esc_url( self::url( PressPort_Settings::PAGE ) ) . '">' . esc_html__( 'Settings', 'pressport' ) . '</a>'
		);
		return $links;
	}

	/**
	 * @param string $hook Hook suffix.
	 */
	public static function assets( $hook ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- admin_enqueue_scripts passes it; the page slug is checked instead.
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		if ( ! in_array( $page, array( self::SLUG, self::HISTORY_SLUG, self::EXPORT_SLUG, self::REPLACE_SLUG, PressPort_Settings::PAGE ), true ) ) {
			return;
		}
		wp_enqueue_style( 'pressport', PRESSPORT_URL . 'assets/admin.css', array(), PRESSPORT_VERSION );
		if ( PressPort_Settings::PAGE === $page ) {
			return;
		}
		wp_enqueue_script( 'pressport', PRESSPORT_URL . 'assets/admin.js', array(), PRESSPORT_VERSION, true );
		$views = array(
			self::HISTORY_SLUG => 'history',
			self::EXPORT_SLUG  => 'export',
			self::REPLACE_SLUG => 'replace',
		);
		wp_localize_script( 'pressport', 'PRESSPORT_DATA', self::bootstrap_data( isset( $views[ $page ] ) ? $views[ $page ] : 'new' ) );
	}

	/**
	 * Data passed to the JS app.
	 *
	 * @param string $view new|history|export|replace.
	 * @return array
	 */
	protected static function bootstrap_data( $view ) {
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
			if ( in_array( $pt->name, array( 'wp_block', 'wp_navigation', 'wp_template', 'wp_template_part' ), true ) ) {
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
			'ajaxUrl'        => admin_url( 'admin-ajax.php' ),
			'nonce'          => wp_create_nonce( 'pressport_admin' ),
			'adminUrl'       => admin_url(),
			'view'           => $view,
			'importUrl'      => self::url(),
			'historyUrl'     => self::url( self::HISTORY_SLUG ),
			'exportUrl'      => self::url( self::EXPORT_SLUG ),
			'exportDefaults' => PressPort_Exporter::defaults(),
			'settingsUrl'    => current_user_can( 'manage_options' ) ? self::url( PressPort_Settings::PAGE ) : '',
			'openJob'        => isset( $_GET['job'] ) ? sanitize_key( wp_unslash( $_GET['job'] ) ) : '', // phpcs:ignore WordPress.Security.NonceVerification
			'resume'         => ! empty( $_GET['resume'] ), // phpcs:ignore WordPress.Security.NonceVerification
			'sources'        => $sources,
			'postTypes'      => $post_types,
			'users'          => $users,
			'targets'        => $targets,
			'defaults'       => PressPort_Job::default_options(),
			'maxUpload'      => size_format( wp_max_upload_size() ),
			'maxUploadBytes' => wp_max_upload_size(),
			'acf'            => PressPort_ACF::bootstrap( wp_list_pluck( $post_types, 'name' ) ),
			'woo'            => array( 'active' => PressPort_Woo::active() ),
			'maxRemote'      => size_format( max( 1, (int) PressPort_Settings::get( 'max_remote_size' ) ) * MB_IN_BYTES ),
			'tables'         => 'replace' === $view ? PressPort_Replace::tables() : array(),
		);
	}

	public static function render_import() {
		self::render_shell(
			__( 'Import Content', 'pressport' ),
			__( 'Upload a PressPort export (.json) and it imports straight away, using the field mapping saved in the file. Other JSON files work too: you map their fields first. Every import can be rolled back from History.', 'pressport' )
		);
	}

	public static function render_export() {
		self::render_shell(
			__( 'Export Content', 'pressport' ),
			__( 'Export posts, pages or custom post types with their terms, featured images, custom fields and ACF fields. Import the file on another site with PressPort and the fields map themselves.', 'pressport' )
		);
	}

	public static function render_history() {
		self::render_shell(
			__( 'History', 'pressport' ),
			__( 'Every import and export you have run. Resume an import that stopped, run it again to pick up new content, or roll it back. Open an export\'s log to see what went into the file.', 'pressport' )
		);
	}

	public static function render_replace() {
		self::render_shell(
			__( 'Find & Replace', 'pressport' ),
			__( 'Replace text across database tables, for example an old domain after a migration. Serialized data is handled safely. Start with a dry run. Real runs can be rolled back, but back up the database first anyway.', 'pressport' )
		);
	}

	/**
	 * Page wrapper; the UI itself is built by assets/admin.js.
	 *
	 * @param string $title Title.
	 * @param string $intro Intro text.
	 */
	protected static function render_shell( $title, $intro ) {
		?>
		<div class="wrap pressport-wrap">
			<h1 class="wp-heading-inline"><?php echo esc_html( $title ); ?></h1>
			<?php if ( current_user_can( 'manage_options' ) ) : ?>
				<a href="<?php echo esc_url( self::url( PressPort_Settings::PAGE ) ); ?>" class="page-title-action"><?php esc_html_e( 'Settings', 'pressport' ); ?></a>
			<?php endif; ?>
			<hr class="wp-header-end">
			<p class="pressport-intro"><?php echo esc_html( $intro ); ?></p>
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
		if ( ! self::may_use_job( $job ) ) {
			wp_send_json_error( array( 'message' => __( 'You are not allowed to do this.', 'pressport' ) ), 403 );
		}
		return $job;
	}

	/**
	 * Whether the current user may see or change a job: whoever created it, or an administrator.
	 *
	 * @param PressPort_Job $job Job.
	 * @return bool
	 */
	protected static function may_use_job( PressPort_Job $job ) {
		return get_current_user_id() === (int) $job->data['user'] || current_user_can( 'manage_options' );
	}

	/**
	 * Take the job's batch lock (reloading the job), or die while an import batch holds it.
	 *
	 * @param PressPort_Job $job Job.
	 * @return string Lock token.
	 */
	protected static function lock_job( PressPort_Job $job ) {
		$lock = PressPort_Runner::lock( $job );
		if ( ! $lock ) {
			wp_send_json_error( array( 'message' => __( 'An import batch for this job is running. Try again when it finishes.', 'pressport' ) ), 409 );
		}
		return $lock;
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
		$uploads  = array();
		foreach ( call_user_func( array( $class, 'settings_schema' ) ) as $field ) {
			$key = $field['key'];
			if ( 'file' === $field['type'] ) {
				if ( ! empty( $_FILES[ 'file_' . $key ]['name'] ) ) {
					$exts = PressPort_Source_Base::accepted_extensions( $field );
					$path = self::store_upload( $_FILES[ 'file_' . $key ], $exts ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
					if ( is_wp_error( $path ) ) {
						self::fail( $path );
					}
					$settings[ $key ] = $path;
					// The stored copy has a random name; keep the original so re-imports of the same file match.
					$settings[ $key . '_name' ] = sanitize_file_name( wp_unslash( $_FILES[ 'file_' . $key ]['name'] ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
					$uploads[]                  = $path;
				} elseif ( PressPort_Source_Base::allows_remote( $field ) && ! empty( $raw[ $key . '_url' ] ) ) {
					// Downloaded by the source in prepare().
					$url = esc_url_raw( trim( (string) $raw[ $key . '_url' ] ), array( 'http', 'https' ) );
					if ( '' === $url ) {
						self::fail( new WP_Error( 'pressport_remote_url', __( 'Enter a valid http:// or https:// URL.', 'pressport' ) ) );
					}
					$settings[ $key . '_url' ] = $url;
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
			// No job was created, so nothing else will ever clean up the upload.
			array_map( 'wp_delete_file', $uploads );
			self::fail( $preview );
		}

		// Trim long values for display.
		wp_send_json_success(
			array(
				'job'     => $preview['job']->to_public(),
				'records' => self::preview_rows( $preview['records'] ),
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

		$clean_map = self::clean_mapping( (array) $mapping );
		if ( empty( $clean_map['fields']['post_title'] ) && empty( $clean_map['fields']['post_content'] ) ) {
			self::fail( new WP_Error( 'pressport_mapping', __( 'Map at least the Title or Content field.', 'pressport' ) ) );
		}
		$clean_opt = self::clean_options( (array) $options );
		$lock      = self::lock_job( $job );

		$job->data['mapping'] = $clean_map;
		$job->data['options'] = $clean_opt;
		$job->data['status']  = 'ready';
		$job->data['cursor']  = null;
		$job->data['stats']   = array_fill_keys( array( 'processed', 'created', 'updated', 'skipped', 'failed' ), 0 );
		$job->log( 'info', $clean_opt['dry_run'] ? __( 'Dry run started, nothing will be saved.', 'pressport' ) : __( 'Import started.', 'pressport' ) );
		$job->save();
		PressPort_Runner::unlock( $job, $lock );

		wp_send_json_success( array( 'job' => $job->to_public() ) );
	}

	/**
	 * Count the records already on this site, with the mapping and options the
	 * import is about to run with (sent as for save; nothing is saved).
	 */
	public static function ajax_duplicates() {
		self::guard();
		$job = self::request_job();
		// phpcs:disable WordPress.Security.NonceVerification, WordPress.Security.ValidatedSanitizedInput
		if ( isset( $_POST['mapping'] ) ) {
			$job->data['mapping'] = self::clean_mapping( (array) json_decode( wp_unslash( $_POST['mapping'] ), true ) );
		}
		if ( isset( $_POST['options'] ) ) {
			$job->data['options'] = self::clean_options( (array) json_decode( wp_unslash( $_POST['options'] ), true ) );
		}
		// phpcs:enable
		$found = PressPort_Runner::find_duplicates( $job );
		if ( is_wp_error( $found ) ) {
			self::fail( $found );
		}
		// The browser may not be able to reach the old site (another network, a login, its own rules), so
		// the imported side of each media comparison is fetched through this site, which downloads it anyway.
		foreach ( $found['images'] as $i => $image ) {
			$found['images'][ $i ]['imported_preview'] = '' !== $image['imported'] ? add_query_arg(
				array(
					'action' => 'pressport_media_preview',
					'url'    => rawurlencode( $image['imported'] ),
					'sig'    => self::preview_signature( $image['imported'] ),
					'nonce'  => wp_create_nonce( 'pressport_admin' ),
				),
				admin_url( 'admin-ajax.php' )
			) : '';
		}
		wp_send_json_success( $found );
	}

	/**
	 * Stream an image from the import's source for the media comparison. Only URLs
	 * the duplicates check handed out (signed) are fetched, and only raster images
	 * are passed on: an SVG could carry script into the admin.
	 */
	public static function ajax_media_preview() {
		self::guard();
		// phpcs:disable WordPress.Security.NonceVerification -- verified in guard().
		$url = isset( $_GET['url'] ) ? esc_url_raw( wp_unslash( $_GET['url'] ), array( 'http', 'https' ) ) : '';
		$sig = isset( $_GET['sig'] ) ? sanitize_text_field( wp_unslash( $_GET['sig'] ) ) : '';
		// phpcs:enable
		if ( '' === $url || ! hash_equals( self::preview_signature( $url ), $sig ) ) {
			status_header( 403 );
			exit;
		}
		PressPort_Util::$in_request = true;
		$response                   = wp_safe_remote_get(
			$url,
			array(
				'timeout'             => 20,
				'limit_response_size' => 15 * MB_IN_BYTES,
			)
		);
		PressPort_Util::$in_request = false;
		$type                       = is_wp_error( $response ) ? '' : strtolower( trim( explode( ';', (string) wp_remote_retrieve_header( $response, 'content-type' ) )[0] ) );
		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) || ! in_array( $type, array( 'image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/avif' ), true ) ) {
			status_header( 404 );
			exit;
		}
		header( 'Content-Type: ' . $type );
		header( 'Cache-Control: private, max-age=600' );
		header( 'X-Content-Type-Options: nosniff' );
		echo wp_remote_retrieve_body( $response ); // phpcs:ignore WordPress.Security.EscapeOutput -- image bytes.
		exit;
	}

	/**
	 * @param string $url Image URL.
	 * @return string
	 */
	protected static function preview_signature( $url ) {
		return hash_hmac( 'sha256', 'pressport_preview|' . $url, wp_salt( 'auth' ) );
	}

	/**
	 * @param array $mapping Mapping from the request.
	 * @return array
	 */
	protected static function clean_mapping( array $mapping ) {
		$clean_map = array(
			'fields'     => array(),
			'meta'       => array(),
			'taxonomies' => array(),
			'acf'        => array(),
		);
		$targets   = PressPort_Importer::targets();
		foreach ( isset( $mapping['fields'] ) ? (array) $mapping['fields'] : array() as $target => $source ) {
			if ( isset( $targets[ $target ] ) && '' !== (string) $source ) {
				$clean_map['fields'][ $target ] = self::clean_field_name( $source );
			}
		}
		foreach ( isset( $mapping['meta'] ) ? (array) $mapping['meta'] : array() as $row ) {
			if ( ! empty( $row['key'] ) && ! empty( $row['source'] ) ) {
				$clean_map['meta'][] = array(
					'key'    => sanitize_text_field( $row['key'] ),
					'source' => self::clean_field_name( $row['source'] ),
				);
			}
		}
		foreach ( isset( $mapping['taxonomies'] ) ? (array) $mapping['taxonomies'] : array() as $row ) {
			if ( ! empty( $row['taxonomy'] ) && ! empty( $row['source'] ) ) {
				$clean_map['taxonomies'][] = array(
					'taxonomy' => sanitize_key( $row['taxonomy'] ),
					'source'   => self::clean_field_name( $row['source'] ),
				);
			}
		}
		foreach ( isset( $mapping['acf'] ) && is_array( $mapping['acf'] ) ? $mapping['acf'] : array() as $field => $source ) {
			$field = sanitize_text_field( (string) $field );
			if ( '' !== $field && '' !== (string) $source ) {
				$clean_map['acf'][ $field ] = self::clean_field_name( $source );
			}
		}
		return $clean_map;
	}

	/**
	 * A source field name, kept exactly as the source spells it (spaces, %xx, < > included)
	 * so it still matches: only invalid UTF-8 and control characters are removed.
	 *
	 * @param mixed $name Field name from the request.
	 * @return string
	 */
	protected static function clean_field_name( $name ) {
		return (string) preg_replace( '/[\x00-\x1F\x7F]/', '', wp_check_invalid_utf8( (string) $name, true ) );
	}

	/**
	 * @param array $options Import options from the request.
	 * @return array
	 */
	protected static function clean_options( array $options ) {
		$defaults  = PressPort_Job::default_options();
		$post_type = isset( $options['post_type'] ) ? sanitize_key( $options['post_type'] ) : 'post';
		$status    = isset( $options['default_status'] ) ? sanitize_key( $options['default_status'] ) : 'publish';
		$duplicate = isset( $options['duplicate'] ) ? sanitize_key( $options['duplicate'] ) : 'skip';
		$author    = isset( $options['default_author'] ) ? absint( $options['default_author'] ) : get_current_user_id();
		$post_type = post_type_exists( $post_type ) ? $post_type : 'post';
		if ( ! current_user_can( get_post_type_object( $post_type )->cap->edit_posts ) ) {
			self::fail( new WP_Error( 'pressport_import_cap', __( 'You are not allowed to import this post type.', 'pressport' ) ) );
		}
		return array(
			'post_type'             => $post_type,
			'default_status'        => in_array( $status, array( 'publish', 'draft', 'pending', 'private' ), true ) ? $status : 'draft',
			'default_author'        => get_userdata( $author ) ? $author : get_current_user_id(),
			'duplicate'             => in_array( $duplicate, array( 'skip', 'update', 'create' ), true ) ? $duplicate : 'skip',
			'skip_existing'         => empty( $options['skip_existing'] ) ? 0 : 1,
			'update_ids'            => array_values( array_filter( array_map( 'absint', isset( $options['update_ids'] ) ? (array) $options['update_ids'] : array() ) ) ),
			'replace_ids'           => array_values( array_filter( array_map( 'absint', isset( $options['replace_ids'] ) ? (array) $options['replace_ids'] : array() ) ) ),
			'copy_ids'              => array_values( array_filter( array_map( 'absint', isset( $options['copy_ids'] ) ? (array) $options['copy_ids'] : array() ) ) ),
			'batch_size'            => max( 1, min( 100, isset( $options['batch_size'] ) ? (int) $options['batch_size'] : $defaults['batch_size'] ) ),
			'import_featured_image' => empty( $options['import_featured_image'] ) ? 0 : 1,
			'import_content_images' => empty( $options['import_content_images'] ) ? 0 : 1,
			'create_terms'          => empty( $options['create_terms'] ) ? 0 : 1,
			'create_authors'        => empty( $options['create_authors'] ) ? 0 : 1,
			'rewrite_links'         => empty( $options['rewrite_links'] ) ? 0 : 1,
			'dry_run'               => empty( $options['dry_run'] ) ? 0 : 1,
		);
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
		$job    = self::request_job();
		$lock   = self::lock_job( $job );
		$source = $job->source();
		if ( ! is_wp_error( $source ) && $source instanceof PressPort_Source_Base && $source->has_remote_input() ) {
			// Re-download the remote file / feed so re-runs pick up new items.
			$prepared = $source->prepare();
			if ( is_wp_error( $prepared ) ) {
				/* translators: %s: error message */
				$job->log( 'warning', sprintf( __( 'Could not refresh the source, re-using the previous copy: %s', 'pressport' ), $prepared->get_error_message() ) );
			} else {
				$old = isset( $job->data['settings']['cache_file'] ) ? $job->data['settings']['cache_file'] : '';
				if ( $old && ( empty( $prepared['cache_file'] ) || $old !== $prepared['cache_file'] ) && file_exists( $old ) ) {
					wp_delete_file( $old );
				}
				$job->data['settings'] = $prepared;
				$job->data['total']    = isset( $prepared['total'] ) ? $prepared['total'] : null;
				$job->log( 'info', __( 'Source refreshed from its URL.', 'pressport' ) );
			}
		}
		$job->data['status'] = 'ready';
		$job->data['cursor'] = null;
		$job->data['stats']  = array_fill_keys( array( 'processed', 'created', 'updated', 'skipped', 'failed' ), 0 );
		// Changes approved last time may not be what the source holds now: the re-run follows the duplicate option.
		$job->data['options']['update_ids']  = array();
		$job->data['options']['replace_ids'] = array();
		$job->data['options']['copy_ids']    = array();
		$job->log( 'info', __( 'Job restarted.', 'pressport' ) );
		$job->save();
		PressPort_Runner::unlock( $job, $lock );
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
		$job  = self::request_job();
		$lock = self::lock_job( $job );
		$job->delete();
		PressPort_Runner::unlock( $job, $lock );
		wp_send_json_success();
	}

	/**
	 * List jobs.
	 */
	public static function ajax_jobs() {
		self::guard();
		$jobs = array();
		foreach ( PressPort_Job::all() as $job ) {
			if ( ! self::may_use_job( $job ) ) {
				continue;
			}
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
		// The source fields, so a dry run opened from History can go back to the mapping step.
		$data['fields'] = isset( $job->data['settings']['fields'] ) ? array_map( 'strval', array_values( (array) $job->data['settings']['fields'] ) ) : array();
		// A few records for the mapping preview; sample values are not kept with the job.
		$data['records'] = array();
		$source          = PressPort_Sources::create( $job->data['source'], (array) $job->data['settings'] );
		$batch           = is_wp_error( $source ) ? $source : $source->fetch( null, 5 );
		if ( ! is_wp_error( $batch ) && ! empty( $batch['records'] ) ) {
			$data['records'] = self::preview_rows( $batch['records'] );
		}
		wp_send_json_success( array( 'job' => $data ) );
	}

	/**
	 * Records as short strings for the preview table.
	 *
	 * @param array[] $records Records.
	 * @return array[]
	 */
	protected static function preview_rows( array $records ) {
		$rows = array();
		foreach ( $records as $record ) {
			$row = array();
			foreach ( $record as $k => $v ) {
				$s         = PressPort_Util::stringify( $v );
				$row[ $k ] = function_exists( 'mb_substr' ) ? mb_substr( $s, 0, 400 ) : substr( $s, 0, 400 );
			}
			$rows[] = $row;
		}
		return $rows;
	}

	/**
	 * @param string $id Source id.
	 * @return string
	 */
	protected static function source_label( $id ) {
		$all = PressPort_Sources::all();
		return isset( $all[ $id ] ) && class_exists( $all[ $id ] ) ? call_user_func( array( $all[ $id ], 'label' ) ) : $id;
	}

	// Export.

	/**
	 * Export args from the request.
	 *
	 * @return array|WP_Error
	 */
	protected static function export_args() {
		// phpcs:disable WordPress.Security.NonceVerification -- verified in guard().
		$raw = isset( $_POST['args'] ) ? json_decode( wp_unslash( $_POST['args'] ), true ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		// phpcs:enable
		$args = PressPort_Exporter::sanitize_args( is_array( $raw ) ? $raw : array() );
		if ( ! is_wp_error( $args ) && ! current_user_can( get_post_type_object( $args['post_type'] )->cap->edit_posts ) ) {
			return new WP_Error( 'pressport_export_cap', __( 'You are not allowed to export this post type.', 'pressport' ) );
		}
		return $args;
	}

	/**
	 * How many posts an export would include.
	 */
	public static function ajax_export_count() {
		self::guard();
		$args = self::export_args();
		if ( is_wp_error( $args ) ) {
			self::fail( $args );
		}
		wp_send_json_success( array( 'count' => PressPort_Exporter::count( $args ) ) );
	}

	/**
	 * Start an export.
	 */
	public static function ajax_export_start() {
		self::guard();
		$args = self::export_args();
		if ( is_wp_error( $args ) ) {
			self::fail( $args );
		}
		$state = PressPort_Exporter::start( $args );
		if ( is_wp_error( $state ) ) {
			self::fail( $state );
		}
		if ( 0 === (int) $state['total'] ) {
			PressPort_Exporter::forget( $state['id'] );
			self::fail( new WP_Error( 'pressport_export_empty', __( 'Nothing matches these filters. Widen the status or date range.', 'pressport' ) ) );
		}
		wp_send_json_success( self::export_public( $state ) );
	}

	/**
	 * Export the next batch.
	 */
	public static function ajax_export_run() {
		self::guard();
		$state = self::request_export();
		PressPort_Exporter::run_batch( $state, 50 );
		wp_send_json_success( self::export_public( $state ) );
	}

	/**
	 * Download a finished export.
	 */
	public static function ajax_export_download() {
		self::guard();
		$state = self::request_export();
		if ( empty( $state['complete'] ) || ! is_readable( $state['file'] ) ) {
			wp_die( esc_html__( 'This export is not ready or has expired. Run it again.', 'pressport' ), '', array( 'response' => 404 ) );
		}
		nocache_headers();
		header( 'Content-Type: application/json; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . sanitize_file_name( $state['filename'] ) . '"' );
		header( 'Content-Length: ' . filesize( $state['file'] ) );
		readfile( $state['file'] ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		exit;
	}

	/**
	 * Export history.
	 */
	public static function ajax_exports() {
		self::guard();
		$exports = PressPort_Exporter::history();
		foreach ( $exports as $i => $entry ) {
			$exports[ $i ]['size'] = $entry['size'] ? size_format( $entry['size'] ) : '';
			$exports[ $i ]['url']  = $entry['available'] && self::may_use_export( $entry['id'] ) ? self::export_download_url( $entry['id'] ) : '';
		}
		wp_send_json_success( array( 'exports' => $exports ) );
	}

	/**
	 * One export's log.
	 */
	public static function ajax_export_log() {
		self::guard();
		$id    = isset( $_POST['export'] ) ? sanitize_key( wp_unslash( $_POST['export'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification -- verified in guard().
		$entry = self::may_use_export( $id ) ? PressPort_Exporter::history_entry( $id ) : null;
		if ( ! $entry ) {
			wp_send_json_error( array( 'message' => __( 'This export is no longer in history.', 'pressport' ) ), 404 );
		}
		wp_send_json_success( array( 'log' => $entry['log'] ) );
	}

	/**
	 * Remove an export from history.
	 */
	public static function ajax_export_forget() {
		self::guard();
		$id = isset( $_POST['export'] ) ? sanitize_key( wp_unslash( $_POST['export'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification -- verified in guard().
		if ( ! self::may_use_export( $id ) ) {
			wp_send_json_error( array( 'message' => __( 'You are not allowed to do this.', 'pressport' ) ), 403 );
		}
		PressPort_Exporter::forget( $id );
		wp_send_json_success();
	}

	/**
	 * Whether the current user may see or remove an export: its owner, or an administrator.
	 * The history entry does not record who ran it, so once the export's state has
	 * expired only administrators pass.
	 *
	 * @param string $id Export id.
	 * @return bool
	 */
	protected static function may_use_export( $id ) {
		if ( current_user_can( 'manage_options' ) ) {
			return true;
		}
		$state = $id ? PressPort_Exporter::load_state( $id ) : null;
		return $state && get_current_user_id() === (int) $state['user'];
	}

	/**
	 * @param string $id Export id.
	 * @return string
	 */
	protected static function export_download_url( $id ) {
		return add_query_arg(
			array(
				'action' => 'pressport_export_download',
				'export' => $id,
				'nonce'  => wp_create_nonce( 'pressport_admin' ),
			),
			admin_url( 'admin-ajax.php' )
		);
	}

	// Find and replace (administrators only, whoever may run imports).

	/**
	 * Start a find and replace run.
	 */
	public static function ajax_replace_start() {
		self::guard_admin();
		// phpcs:disable WordPress.Security.NonceVerification -- verified in guard().
		$raw = isset( $_POST['args'] ) ? json_decode( wp_unslash( $_POST['args'] ), true ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- search text is used as is.
		// phpcs:enable
		$state = PressPort_Replace::start( is_array( $raw ) ? $raw : array() );
		if ( is_wp_error( $state ) ) {
			self::fail( $state );
		}
		wp_send_json_success( self::replace_public( $state ) );
	}

	/**
	 * Continue a run.
	 */
	public static function ajax_replace_run() {
		self::guard_admin();
		$state = PressPort_Replace::load_state( isset( $_POST['run'] ) ? sanitize_key( wp_unslash( $_POST['run'] ) ) : '' ); // phpcs:ignore WordPress.Security.NonceVerification -- verified in guard().
		if ( ! $state ) {
			wp_send_json_error( array( 'message' => __( 'This run has expired. Start it again.', 'pressport' ) ), 404 );
		}
		PressPort_Replace::run_batch( $state );
		$out = self::replace_public( $state );
		if ( $state['complete'] ) {
			PressPort_Replace::delete( $state['id'] );
		}
		wp_send_json_success( $out );
	}

	/**
	 * Past real runs.
	 */
	public static function ajax_replace_history() {
		self::guard_admin();
		wp_send_json_success( array( 'runs' => PressPort_Replace::history() ) );
	}

	/**
	 * Roll back the next batch of a run.
	 */
	public static function ajax_replace_rollback() {
		self::guard_admin();
		$entry = PressPort_Replace::rollback_batch( isset( $_POST['run'] ) ? sanitize_key( wp_unslash( $_POST['run'] ) ) : '' ); // phpcs:ignore WordPress.Security.NonceVerification -- verified in guard().
		if ( is_wp_error( $entry ) ) {
			self::fail( $entry );
		}
		wp_send_json_success( $entry );
	}

	/**
	 * Remove a run from history (it can then no longer be rolled back).
	 */
	public static function ajax_replace_forget() {
		self::guard_admin();
		PressPort_Replace::forget( isset( $_POST['run'] ) ? sanitize_key( wp_unslash( $_POST['run'] ) ) : '' ); // phpcs:ignore WordPress.Security.NonceVerification -- verified in guard().
		wp_send_json_success();
	}

	protected static function guard_admin() {
		self::guard();
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Only administrators can use Find & Replace.', 'pressport' ) ), 403 );
		}
	}

	/**
	 * @param array $state Run state.
	 * @return array
	 */
	protected static function replace_public( array $state ) {
		$tables = array();
		foreach ( $state['results'] as $name => $r ) {
			$tables[] = array(
				'name'    => $name,
				'rows'    => $r['rows'],
				'changes' => $r['changes'],
				'samples' => $r['samples'],
				'skipped' => $r['skipped'],
				'unsafe'  => isset( $r['unsafe'] ) ? (int) $r['unsafe'] : 0,
			);
		}
		return array(
			'id'       => $state['id'],
			'done'     => (int) $state['done'],
			'total'    => (int) $state['total'],
			'table'    => $state['complete'] ? '' : $state['tables'][ $state['index'] ],
			'complete' => (bool) $state['complete'],
			'dry_run'  => (bool) $state['args']['dry_run'],
			'error'    => isset( $state['error'] ) ? $state['error'] : '',
			'tables'   => $tables,
		);
	}

	/**
	 * Load the export named in the request (only its owner may use it).
	 *
	 * @return array
	 */
	protected static function request_export() {
		$id    = isset( $_REQUEST['export'] ) ? sanitize_key( wp_unslash( $_REQUEST['export'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		$state = $id ? PressPort_Exporter::load_state( $id ) : null;
		if ( ! $state || ( get_current_user_id() !== (int) $state['user'] && ! current_user_can( 'manage_options' ) ) ) {
			wp_send_json_error( array( 'message' => __( 'Export not found. It may have expired; run it again.', 'pressport' ) ), 404 );
		}
		return $state;
	}

	/**
	 * @param array $state State.
	 * @return array
	 */
	protected static function export_public( array $state ) {
		$out = array(
			'id'       => $state['id'],
			'total'    => (int) $state['total'],
			'done'     => (int) $state['done'],
			'complete' => ! empty( $state['complete'] ),
			'acf'      => count( $state['acf_names'] ),
			'meta'     => count( $state['meta_keys'] ),
			'taxes'    => $state['taxes'],
		);
		if ( ! empty( $state['error'] ) ) {
			$out['error'] = $state['error'];
		} elseif ( $out['complete'] ) {
			$out['filename'] = $state['filename'];
			$out['size']     = size_format( $state['size'] );
			$out['url']      = self::export_download_url( $state['id'] );
		}
		return $out;
	}
}
