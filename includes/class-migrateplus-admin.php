<?php
/**
 * Admin screen + AJAX endpoints.
 *
 * @package MigratePlus
 */

defined( 'ABSPATH' ) || exit;

class MigratePlus_Admin {

	const SLUG         = 'migrateplus';
	const HISTORY_SLUG = 'migrateplus-history';
	const EXPORT_SLUG  = 'migrateplus-export';
	const REPLACE_SLUG = 'migrateplus-replace';

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( MIGRATEPLUS_FILE ), array( __CLASS__, 'action_links' ) );

		foreach ( array( 'preview', 'users', 'records', 'source_authors', 'duplicates', 'media_preview', 'save', 'run', 'rollback', 'delete', 'history_delete', 'jobs', 'job', 'restart', 'export_count', 'export_posts', 'export_start', 'export_run', 'export_download', 'exports', 'export_log', 'export_forget', 'pull_check', 'pull_start', 'pull_run', 'pull_fetch', 'replace_start', 'replace_run', 'replace_history', 'replace_rollback', 'replace_forget', 'mapping_save', 'mapping_rename', 'mapping_delete' ) as $action ) {
			add_action( 'wp_ajax_migrateplus_' . $action, array( __CLASS__, 'ajax_' . $action ) );
		}
	}

	/**
	 * Capability required to use the tool.
	 *
	 * @return string
	 */
	public static function capability() {
		return (string) apply_filters( 'migrateplus_capability', MigratePlus_Settings::get( 'capability' ) );
	}

	public static function menu() {
		add_menu_page(
			__( 'Migrate Plus', 'migrateplus' ),
			__( 'Migrate Plus', 'migrateplus' ),
			self::capability(),
			self::SLUG,
			array( __CLASS__, 'render_import' ),
			'dashicons-migrate',
			76
		);
		add_submenu_page( self::SLUG, __( 'Import', 'migrateplus' ), __( 'Import', 'migrateplus' ), self::capability(), self::SLUG, array( __CLASS__, 'render_import' ) );
		add_submenu_page( self::SLUG, __( 'Export Content', 'migrateplus' ), __( 'Export', 'migrateplus' ), self::capability(), self::EXPORT_SLUG, array( __CLASS__, 'render_export' ) );
		// On a network every subsite administrator has manage_options, but the tables are shared by all sites.
		add_submenu_page( self::SLUG, __( 'Find & Replace', 'migrateplus' ), __( 'Find & Replace', 'migrateplus' ), is_multisite() ? 'manage_network_options' : 'manage_options', self::REPLACE_SLUG, array( __CLASS__, 'render_replace' ) );
		add_submenu_page( self::SLUG, __( 'History', 'migrateplus' ), __( 'History', 'migrateplus' ), self::capability(), self::HISTORY_SLUG, array( __CLASS__, 'render_history' ) );
		add_submenu_page( self::SLUG, __( 'Migrate Plus Settings', 'migrateplus' ), __( 'Settings', 'migrateplus' ), 'manage_options', MigratePlus_Settings::PAGE, array( 'MigratePlus_Settings', 'render' ) );
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
			'<a href="' . esc_url( self::url() ) . '">' . esc_html__( 'Import', 'migrateplus' ) . '</a>',
			'<a href="' . esc_url( self::url( MigratePlus_Settings::PAGE ) ) . '">' . esc_html__( 'Settings', 'migrateplus' ) . '</a>'
		);
		return $links;
	}

	/**
	 * @param string $hook Hook suffix.
	 */
	public static function assets( $hook ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- admin_enqueue_scripts passes it; the page slug is checked instead.
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		if ( ! in_array( $page, array( self::SLUG, self::HISTORY_SLUG, self::EXPORT_SLUG, self::REPLACE_SLUG, MigratePlus_Settings::PAGE ), true ) ) {
			return;
		}
		// Versioned by the file, not the plugin: a changed stylesheet must not serve from the browser cache.
		wp_enqueue_style( 'migrateplus', MIGRATEPLUS_URL . 'assets/admin.css', array(), (string) filemtime( MIGRATEPLUS_DIR . 'assets/admin.css' ) );
		if ( MigratePlus_Settings::PAGE === $page ) {
			return;
		}
		// The date-range picker. Versioned by the library release, since the vendored files only change with it.
		wp_enqueue_style( 'migrateplus-calendar', MIGRATEPLUS_URL . 'assets/vendor/vanilla-calendar-pro/vanilla-calendar-pro.min.css', array(), '3.4.0' );
		wp_enqueue_script( 'migrateplus-calendar', MIGRATEPLUS_URL . 'assets/vendor/vanilla-calendar-pro/vanilla-calendar-pro.min.js', array(), '3.4.0', true );
		wp_enqueue_script( 'migrateplus', MIGRATEPLUS_URL . 'assets/admin.js', array( 'wp-element', 'wp-i18n', 'migrateplus-calendar' ), (string) filemtime( MIGRATEPLUS_DIR . 'assets/admin.js' ), true );
		wp_set_script_translations( 'migrateplus', 'migrateplus' );
		$views = array(
			self::HISTORY_SLUG => 'history',
			self::EXPORT_SLUG  => 'export',
			self::REPLACE_SLUG => 'replace',
		);
		wp_localize_script( 'migrateplus', 'MIGRATEPLUS_DATA', self::bootstrap_data( isset( $views[ $page ] ) ? $views[ $page ] : 'new' ) );
	}

	/**
	 * Data passed to the JS app.
	 *
	 * @param string $view new|history|export|replace.
	 * @return array
	 */
	protected static function bootstrap_data( $view ) {
		$sources = array();
		foreach ( MigratePlus_Sources::all() as $id => $class ) {
			if ( MigratePlus_Sources::usable( $class ) ) {
				$sources[] = array(
					'id'     => $id,
					'label'  => call_user_func( array( $class, 'label' ) ),
					'schema' => call_user_func( array( $class, 'settings_schema' ) ),
				);
			}
		}

		$post_types = array();
		$objects    = get_post_types( array( 'show_ui' => true ), 'objects' );
		foreach ( array( 'wp_template', 'wp_template_part' ) as $hidden ) {
			if ( post_type_exists( $hidden ) ) {
				$objects[ $hidden ] = get_post_type_object( $hidden ); // No admin screen, but exportable: see exportable_type().
			}
		}
		foreach ( $objects as $pt ) {
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
				'plural'     => $pt->labels->name,
				'taxonomies' => $taxes,
				'export'     => self::exportable_type( $pt->name ), // Offered on the Export screen.
				'supports'   => array( // What the Export screen can offer with this type.
					'thumbnail' => post_type_supports( $pt->name, 'thumbnail' ),
					'comments'  => post_type_supports( $pt->name, 'comments' ),
				),
			);
		}

		$targets = array();
		foreach ( MigratePlus_Importer::targets() as $key => $info ) {
			$targets[] = array(
				'key'   => $key,
				'label' => $info[0],
				'help'  => $info[1],
			);
		}

		return array(
			'ajaxUrl'        => admin_url( 'admin-ajax.php' ),
			'nonce'          => wp_create_nonce( 'migrateplus_admin' ),
			'adminUrl'       => admin_url(),
			'view'           => $view,
			'importUrl'      => self::url(),
			'exportUrl'      => self::url( self::EXPORT_SLUG ),
			'exportDefaults' => MigratePlus_Exporter::defaults(),
			'settingsUrl'    => current_user_can( 'manage_options' ) ? self::url( MigratePlus_Settings::PAGE ) : '',
			'openJob'        => isset( $_GET['job'] ) ? sanitize_key( wp_unslash( $_GET['job'] ) ) : '', // phpcs:ignore WordPress.Security.NonceVerification
			'resume'         => ! empty( $_GET['resume'] ), // phpcs:ignore WordPress.Security.NonceVerification
			'sources'        => $sources,
			'postTypes'      => $post_types,
			// Who may be picked as the posts' author. 200 means there may be more: the picker searches (ajax_users()).
			'authors'        => self::authors( '', 200 ),
			'targets'        => $targets,
			'defaults'       => MigratePlus_Job::default_options(),
			'maxUpload'      => size_format( wp_max_upload_size() ),
			'maxUploadBytes' => wp_max_upload_size(),
			'acf'            => MigratePlus_ACF::bootstrap( wp_list_pluck( $post_types, 'name' ) ),
			'woo'            => array( 'active' => MigratePlus_Woo::active() ),
			'menus'          => current_user_can( 'edit_theme_options' ), // Whether this user may export menus.
			'siteSections'   => MigratePlus_Site::sections_for_js(), // What the Site settings chip lets you pick.
			'sides'          => array( // ...and the other sections an export offers.
				'site'   => current_user_can( 'manage_options' ),
				'orders' => MigratePlus_Orders::active() && current_user_can( 'manage_woocommerce' ),
				'yoast'  => MigratePlus_Yoast::active(),
			),
			'zlib'           => MigratePlus_Util::zlib_available(), // Compressed (.json.gz) exports.
			'mappings'       => 'new' === $view ? MigratePlus_Mappings::for_js() : array(),
			'maxRemote'      => size_format( max( 1, (int) MigratePlus_Settings::get( 'max_remote_size' ) ) * MB_IN_BYTES ),
			'tables'         => 'replace' === $view ? MigratePlus_Replace::tables() : array(),
		);
	}

	public static function render_import() {
		self::render_shell(
			__( 'Import Content', 'migrateplus' ),
			__( 'Upload a Migrate Plus export (.json or .json.gz) and it imports straight away, using the field mapping saved in the file. Other JSON files work too: you map their fields first. Every import can be rolled back from History.', 'migrateplus' )
		);
	}

	public static function render_export() {
		self::render_shell(
			__( 'Export Content', 'migrateplus' ),
			__( 'Export posts, pages, media or custom post types, one type or several in one file, with their terms, featured images, custom fields and ACF fields. Import the file on another site with Migrate Plus and the fields map themselves.', 'migrateplus' )
		);
	}

	public static function render_history() {
		self::render_shell(
			__( 'History', 'migrateplus' ),
			__( 'Every import and export you have run. Resume an import that stopped, run it again to pick up new content, or roll it back. Open an export\'s log to see what went into the file.', 'migrateplus' )
		);
	}

	public static function render_replace() {
		self::render_shell(
			__( 'Find & Replace', 'migrateplus' ),
			__( 'Replace text across database tables, for example an old domain after a migration. Serialized data is handled safely. Start with a dry run. Real runs can be rolled back, but back up the database first anyway.', 'migrateplus' )
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
		<div class="wrap migrateplus-wrap">
			<h1 class="wp-heading-inline"><?php echo esc_html( $title ); ?></h1>
			<?php if ( current_user_can( 'manage_options' ) ) : ?>
				<a href="<?php echo esc_url( self::url( MigratePlus_Settings::PAGE ) ); ?>" class="page-title-action"><?php esc_html_e( 'Settings', 'migrateplus' ); ?></a>
			<?php endif; ?>
			<hr class="wp-header-end">
			<p class="migrateplus-intro"><?php echo esc_html( $intro ); ?></p>
			<div id="migrateplus-app"><p><?php esc_html_e( 'Loading…', 'migrateplus' ); ?></p></div>
			<noscript><div class="notice notice-error"><p><?php esc_html_e( 'JavaScript is required for the importer.', 'migrateplus' ); ?></p></div></noscript>
		</div>
		<?php
	}

	// AJAX handlers.

	/**
	 * Verify nonce + capability, or die with JSON error.
	 */
	protected static function guard() {
		if ( ! check_ajax_referer( 'migrateplus_admin', 'nonce', false ) ) {
			wp_send_json_error( array( 'message' => __( 'Your session expired. Reload the page.', 'migrateplus' ) ), 403 );
		}
		if ( ! current_user_can( self::capability() ) ) {
			wp_send_json_error( array( 'message' => __( 'You are not allowed to do this.', 'migrateplus' ) ), 403 );
		}
	}

	/**
	 * Load the job from the request or die.
	 *
	 * @return MigratePlus_Job
	 */
	protected static function request_job() {
		$id  = isset( $_POST['job'] ) ? sanitize_key( wp_unslash( $_POST['job'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		$job = MigratePlus_Job::load( $id );
		if ( ! $job ) {
			wp_send_json_error( array( 'message' => __( 'Import job not found.', 'migrateplus' ) ), 404 );
		}
		if ( ! self::may_use_job( $job ) ) {
			wp_send_json_error( array( 'message' => __( 'You are not allowed to do this.', 'migrateplus' ) ), 403 );
		}
		return $job;
	}

	/**
	 * Whether the current user may see or change a job: whoever created it, or an administrator.
	 *
	 * @param MigratePlus_Job $job Job.
	 * @return bool
	 */
	protected static function may_use_job( MigratePlus_Job $job ) {
		return get_current_user_id() === (int) $job->data['user'] || current_user_can( 'manage_options' );
	}

	/**
	 * Take the job's batch lock (reloading the job), or die while an import batch holds it.
	 *
	 * @param MigratePlus_Job $job Job.
	 * @return string Lock token.
	 */
	protected static function lock_job( MigratePlus_Job $job ) {
		$lock = MigratePlus_Runner::lock( $job );
		if ( ! $lock ) {
			wp_send_json_error( array( 'message' => __( 'An import batch for this job is running. Try again when it finishes.', 'migrateplus' ) ), 409 );
		}
		return $lock;
	}

	/**
	 * @param WP_Error $error Error.
	 */
	protected static function fail( WP_Error $error ) {
		// The code is for the script to match on (a busy batch is retried); the message is translated.
		wp_send_json_error(
			array(
				'message' => $error->get_error_message(),
				'code'    => $error->get_error_code(),
			),
			400
		);
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
			return new WP_Error( 'migrateplus_upload', sprintf( __( 'Upload failed (error %d). Check the file size limit.', 'migrateplus' ), (int) $file['error'] ) );
		}
		$ext = strtolower( pathinfo( (string) $file['name'], PATHINFO_EXTENSION ) );
		if ( ! in_array( $ext, $allowed, true ) ) {
			/* translators: %s: allowed extensions */
			return new WP_Error( 'migrateplus_upload_type', sprintf( __( 'Unsupported file type. Allowed: %s', 'migrateplus' ), implode( ', ', $allowed ) ) );
		}
		if ( ! is_uploaded_file( $file['tmp_name'] ) ) {
			return new WP_Error( 'migrateplus_upload', __( 'Invalid upload.', 'migrateplus' ) );
		}
		$dest = MigratePlus_Util::ensure_storage_dir() . 'upload-' . wp_generate_password( 12, false ) . '.' . $ext;
		if ( ! move_uploaded_file( $file['tmp_name'], $dest ) ) {
			return new WP_Error( 'migrateplus_upload', __( 'Could not save the uploaded file.', 'migrateplus' ) );
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
		$all       = MigratePlus_Sources::all();
		if ( empty( $all[ $source_id ] ) || ! MigratePlus_Sources::usable( $all[ $source_id ] ) ) {
			self::fail( new WP_Error( 'migrateplus_source', __( 'Choose a source.', 'migrateplus' ) ) );
		}
		$class    = $all[ $source_id ];
		$raw      = isset( $_POST['settings'] ) && is_array( $_POST['settings'] ) ? wp_unslash( $_POST['settings'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$settings = array();
		$uploads  = array();
		foreach ( call_user_func( array( $class, 'settings_schema' ) ) as $field ) {
			$key = $field['key'];
			if ( 'file' === $field['type'] ) {
				if ( ! empty( $_FILES[ 'file_' . $key ]['name'] ) ) {
					$exts = MigratePlus_Source_Base::accepted_extensions( $field );
					$path = self::store_upload( $_FILES[ 'file_' . $key ], $exts ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
					if ( is_wp_error( $path ) ) {
						self::fail( $path );
					}
					$settings[ $key ] = $path;
					// The stored copy has a random name; keep the original so re-imports of the same file match.
					$settings[ $key . '_name' ] = sanitize_file_name( wp_unslash( $_FILES[ 'file_' . $key ]['name'] ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
					$uploads[]                  = $path;
				} elseif ( MigratePlus_Source_Base::allows_remote( $field ) && ! empty( $raw[ $key . '_url' ] ) ) {
					// Downloaded by the source in prepare().
					$url = esc_url_raw( trim( (string) $raw[ $key . '_url' ] ), array( 'http', 'https' ) );
					if ( '' === $url ) {
						self::fail( new WP_Error( 'migrateplus_remote_url', __( 'Enter a valid http:// or https:// URL.', 'migrateplus' ) ) );
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

		self::send_preview( $source_id, $settings, $uploads );
	}

	/**
	 * Connect to the source and send the preview the import screen continues from,
	 * for a dropped file and a pulled one alike.
	 *
	 * @param string $source_id Source.
	 * @param array  $settings  Source settings.
	 * @param array  $uploads   Files to delete when no job is created.
	 */
	protected static function send_preview( $source_id, array $settings, array $uploads ) {
		$preview = MigratePlus_Runner::preview( $source_id, $settings );
		if ( is_wp_error( $preview ) ) {
			// No job was created, so nothing else will ever clean up the upload.
			array_map( 'wp_delete_file', $uploads );
			self::fail( $preview );
		}

		// Trim long values for display.
		wp_send_json_success(
			array(
				'job'          => $preview['job']->to_public(),
				'records'      => self::preview_rows( $preview['records'] ),
				'fields'       => $preview['fields'],
				// A saved mapping made for a file with the same fields, offered (not applied) on the mapping screen.
				'savedMapping' => self::is_export( $preview['job'] ) ? '' : MigratePlus_Mappings::match( $preview['fields'] ),
			)
		);
	}

	/**
	 * People who may write posts, for the author picker: those whose login, email,
	 * nicename or display name contains the search, or everyone.
	 *
	 * @param string $search Search, or '' for anyone.
	 * @param int    $limit  At most this many.
	 * @return array[] { id, name, login }
	 */
	protected static function authors( $search, $limit ) {
		$query = array(
			'capability' => array( 'edit_posts' ),
			'number'     => $limit,
			'orderby'    => 'display_name',
			'fields'     => array( 'ID', 'display_name', 'user_login' ),
		);
		if ( '' !== $search ) {
			$query['search']         = '*' . $search . '*';
			$query['search_columns'] = array( 'user_login', 'user_email', 'user_nicename', 'display_name' );
		}
		$out = array();
		foreach ( get_users( $query ) as $u ) {
			$out[] = array(
				'id'    => (int) $u->ID,
				'name'  => $u->display_name,
				'login' => $u->user_login,
			);
		}
		return $out;
	}

	/**
	 * Search the people who may be picked as the author (param q), for a site with
	 * more than the 200 the page lists.
	 */
	public static function ajax_users() {
		self::guard();
		$q = isset( $_POST['q'] ) ? sanitize_text_field( wp_unslash( $_POST['q'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification -- verified in guard().
		wp_send_json_success( array( 'authors' => self::authors( $q, 50 ) ) );
	}

	/**
	 * A page of a job's records, for picking an author for each: params offset, limit (at
	 * most 100), q (part of a title) and mapping (JSON, so titles follow the mapping on
	 * screen; the job's own otherwise).
	 */
	public static function ajax_records() {
		self::guard();
		$job = self::request_job();
		// phpcs:disable WordPress.Security.NonceVerification, WordPress.Security.ValidatedSanitizedInput -- verified in guard(); the mapping is cleaned below.
		$offset  = isset( $_POST['offset'] ) ? absint( $_POST['offset'] ) : 0;
		$limit   = isset( $_POST['limit'] ) ? max( 1, min( 100, absint( $_POST['limit'] ) ) ) : 50;
		$q       = isset( $_POST['q'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['q'] ) ) ) : '';
		$mapping = isset( $_POST['mapping'] ) ? json_decode( wp_unslash( $_POST['mapping'] ), true ) : null;
		// phpcs:enable
		$mapping = is_array( $mapping ) ? MigratePlus_Mappings::clean_mapping( $mapping ) : (array) $job->data['mapping'];
		$page    = MigratePlus_Runner::list_records( $job, $mapping, $offset, $limit, $q );
		if ( is_wp_error( $page ) ) {
			self::fail( $page );
		}
		wp_send_json_success( $page );
	}

	/**
	 * The authors a job's records name from the old site, for picking an author per old
	 * author: param mapping (JSON, as ajax_records()).
	 */
	public static function ajax_source_authors() {
		self::guard();
		$job = self::request_job();
		// phpcs:disable WordPress.Security.NonceVerification, WordPress.Security.ValidatedSanitizedInput -- verified in guard(); the mapping is cleaned below.
		$mapping = isset( $_POST['mapping'] ) ? json_decode( wp_unslash( $_POST['mapping'] ), true ) : null;
		// phpcs:enable
		$mapping = is_array( $mapping ) ? MigratePlus_Mappings::clean_mapping( $mapping ) : (array) $job->data['mapping'];
		$found   = MigratePlus_Runner::list_source_authors( $job, $mapping );
		if ( is_wp_error( $found ) ) {
			self::fail( $found );
		}
		wp_send_json_success( $found );
	}

	/**
	 * Whether a job imports a MigratePlus export, which carries its own mapping.
	 *
	 * @param MigratePlus_Job $job Job.
	 * @return bool
	 */
	protected static function is_export( MigratePlus_Job $job ) {
		return isset( $job->data['settings']['migrateplus_mapping'] );
	}

	/**
	 * Save the mapping and options on the mapping screen under a name.
	 */
	public static function ajax_mapping_save() {
		self::guard();
		$job = self::request_job();
		if ( self::is_export( $job ) ) {
			self::fail( new WP_Error( 'migrateplus_mapping_export', __( 'Migrate Plus exports carry their own mapping, so there is nothing to save.', 'migrateplus' ) ) );
		}
		// phpcs:disable WordPress.Security.NonceVerification, WordPress.Security.ValidatedSanitizedInput
		$name    = isset( $_POST['name'] ) ? wp_unslash( $_POST['name'] ) : '';
		$mapping = isset( $_POST['mapping'] ) ? json_decode( wp_unslash( $_POST['mapping'] ), true ) : array();
		$options = isset( $_POST['options'] ) ? json_decode( wp_unslash( $_POST['options'] ), true ) : array();
		// phpcs:enable
		$fields = isset( $job->data['settings']['fields'] ) ? (array) $job->data['settings']['fields'] : array();
		$saved  = MigratePlus_Mappings::save( $name, (array) $mapping, self::clean_options( (array) $options ), $fields );
		if ( is_wp_error( $saved ) ) {
			self::fail( $saved );
		}
		wp_send_json_success(
			array(
				'name'     => $saved['name'],
				'mappings' => MigratePlus_Mappings::for_js(),
			)
		);
	}

	/**
	 * Rename a saved mapping.
	 */
	public static function ajax_mapping_rename() {
		self::guard();
		// phpcs:disable WordPress.Security.NonceVerification, WordPress.Security.ValidatedSanitizedInput -- verified in guard(); cleaned by MigratePlus_Mappings.
		$old = isset( $_POST['name'] ) ? wp_unslash( $_POST['name'] ) : '';
		$new = isset( $_POST['new_name'] ) ? wp_unslash( $_POST['new_name'] ) : '';
		// phpcs:enable
		$done = MigratePlus_Mappings::rename( $old, $new );
		if ( is_wp_error( $done ) ) {
			self::fail( $done );
		}
		wp_send_json_success(
			array(
				'name'     => MigratePlus_Mappings::clean_name( $new ),
				'mappings' => MigratePlus_Mappings::for_js(),
			)
		);
	}

	/**
	 * Delete a saved mapping.
	 */
	public static function ajax_mapping_delete() {
		self::guard();
		$done = MigratePlus_Mappings::delete( isset( $_POST['name'] ) ? wp_unslash( $_POST['name'] ) : '' ); // phpcs:ignore WordPress.Security.NonceVerification, WordPress.Security.ValidatedSanitizedInput -- verified in guard(); cleaned by MigratePlus_Mappings.
		if ( is_wp_error( $done ) ) {
			self::fail( $done );
		}
		wp_send_json_success( array( 'mappings' => MigratePlus_Mappings::for_js() ) );
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

		$clean_map = MigratePlus_Mappings::clean_mapping( (array) $mapping );
		// An export of menus, terms or other side sections alone has no records to map.
		$sides_only = 0 === (int) $job->data['total'] && $job->has_side_sections();
		if ( ! $sides_only && empty( $clean_map['fields']['post_title'] ) && empty( $clean_map['fields']['post_content'] ) ) {
			self::fail( new WP_Error( 'migrateplus_mapping', __( 'Map at least the Title or Content field.', 'migrateplus' ) ) );
		}
		$clean_opt = self::clean_options( (array) $options, $job->data['total'] );
		$lock      = self::lock_job( $job );

		$job->data['mapping'] = $clean_map;
		$job->data['options'] = $clean_opt;
		$job->data['status']  = 'ready';
		$job->data['cursor']  = null;
		$job->data['stats']   = array_fill_keys( array( 'processed', 'created', 'updated', 'skipped', 'failed' ), 0 );
		$job->reset_sections();
		$job->log( 'info', $clean_opt['dry_run'] ? __( 'Dry run started, nothing will be saved.', 'migrateplus' ) : __( 'Import started.', 'migrateplus' ) );
		$job->save();
		MigratePlus_Runner::unlock( $job, $lock );

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
			$job->data['mapping'] = MigratePlus_Mappings::clean_mapping( (array) json_decode( wp_unslash( $_POST['mapping'] ), true ) );
		}
		if ( isset( $_POST['options'] ) ) {
			$job->data['options'] = self::clean_options( (array) json_decode( wp_unslash( $_POST['options'] ), true ), $job->data['total'] );
		}
		// phpcs:enable
		$found = MigratePlus_Runner::find_duplicates( $job );
		if ( is_wp_error( $found ) ) {
			self::fail( $found );
		}
		// The browser may not be able to reach the old site (another network, a login, its own rules), so
		// the imported side of each media comparison is fetched through this site, which downloads it anyway.
		foreach ( $found['images'] as $i => $image ) {
			$found['images'][ $i ]['imported_preview'] = self::media_preview_url( $image['imported'] );
		}
		foreach ( $found['items'] as $i => $item ) {
			if ( $item['media'] ) {
				$found['items'][ $i ]['media']['imported_preview'] = self::media_preview_url( $item['media']['imported'] );
			}
		}
		wp_send_json_success( $found );
	}

	/**
	 * Where the browser fetches an imported image through this site (ajax_media_preview()).
	 *
	 * @param string $imported The image's URL on the old site.
	 * @return string '' when it has none.
	 */
	protected static function media_preview_url( $imported ) {
		// Signed as ajax_media_preview() will read it back, escaped: a URL with a space would not match otherwise.
		$url = esc_url_raw( $imported, array( 'http', 'https' ) );
		return '' !== $url ? add_query_arg(
			array(
				'action' => 'migrateplus_media_preview',
				'url'    => rawurlencode( $url ),
				'sig'    => self::preview_signature( $url ),
				'nonce'  => wp_create_nonce( 'migrateplus_admin' ),
			),
			admin_url( 'admin-ajax.php' )
		) : '';
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
		MigratePlus_Util::$in_request = true;
		try {
			$response = wp_safe_remote_get(
				$url,
				array(
					'timeout'             => 20,
					'limit_response_size' => 15 * MB_IN_BYTES,
				)
			);
		} finally {
			// Left on, it would let every later request in this process reach local hosts.
			MigratePlus_Util::$in_request = false;
		}
		$type = is_wp_error( $response ) ? '' : strtolower( trim( explode( ';', (string) wp_remote_retrieve_header( $response, 'content-type' ) )[0] ) );
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
		return hash_hmac( 'sha256', 'migrateplus_preview|' . $url, wp_salt( 'auth' ) );
	}

	/**
	 * @param array    $options Import options from the request.
	 * @param int|null $total   Records in the job, as for MigratePlus_Mappings::clean_options().
	 * @return array
	 */
	protected static function clean_options( array $options, $total = null ) {
		$clean = MigratePlus_Mappings::clean_options( $options, null === $total ? null : (int) $total );
		if ( ! current_user_can( get_post_type_object( $clean['post_type'] )->cap->edit_posts ) ) {
			self::fail( new WP_Error( 'migrateplus_import_cap', __( 'You are not allowed to import this post type.', 'migrateplus' ) ) );
		}
		return $clean;
	}

	/**
	 * Step 3: run one batch.
	 */
	public static function ajax_run() {
		self::guard();
		$job = self::request_job();
		self::refuse_draft( $job );
		$result = MigratePlus_Runner::run_batch( $job );
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
		self::refuse_draft( $job );
		$lock   = self::lock_job( $job );
		$source = $job->source();
		// A finished import's cached copies were deleted (MigratePlus_Job::delete_caches()): prepare the file again.
		$cleared = ! empty( $job->data['caches_cleared'] );
		$remote  = ! is_wp_error( $source ) && $source instanceof MigratePlus_Source_Base && $source->has_remote_input();
		if ( ! is_wp_error( $source ) && ( $cleared || $remote ) ) {
			// Re-download the remote file / feed so re-runs pick up new items.
			$prepared = $source->prepare();
			if ( is_wp_error( $prepared ) && $cleared ) {
				MigratePlus_Runner::unlock( $job, $lock );
				/* translators: %s: error message */
				self::fail( new WP_Error( 'migrateplus_restart', sprintf( __( 'Could not read the file again: %s Start a new import.', 'migrateplus' ), $prepared->get_error_message() ) ) );
			}
			if ( is_wp_error( $prepared ) ) {
				/* translators: %s: error message */
				$job->log( 'warning', sprintf( __( 'Could not refresh the source, re-using the previous copy: %s', 'migrateplus' ), $prepared->get_error_message() ) );
			} else {
				$old = isset( $job->data['settings']['cache_file'] ) ? $job->data['settings']['cache_file'] : '';
				if ( $old && ( empty( $prepared['cache_file'] ) || $old !== $prepared['cache_file'] ) && file_exists( $old ) ) {
					wp_delete_file( $old );
				}
				$job->data['settings']       = $prepared;
				$job->data['total']          = isset( $prepared['total'] ) ? $prepared['total'] : null;
				$job->data['caches_cleared'] = 0;
				$job->log( 'info', $remote ? __( 'Source refreshed from its URL.', 'migrateplus' ) : __( 'File read again.', 'migrateplus' ) );
			}
		}
		$job->data['status'] = 'ready';
		$job->data['cursor'] = null;
		$job->data['stats']  = array_fill_keys( array( 'processed', 'created', 'updated', 'skipped', 'failed' ), 0 );
		$job->reset_sections();
		// Changes approved last time may not be what the source holds now: the re-run follows the duplicate option.
		$job->data['options']['update_ids']     = array();
		$job->data['options']['replace_ids']    = array();
		$job->data['options']['copy_ids']       = array();
		$job->data['options']['skip_ids']       = array();
		$job->data['options']['duplicate_rest'] = '';
		$job->log( 'info', __( 'Job restarted.', 'migrateplus' ) );
		$job->save();
		MigratePlus_Runner::unlock( $job, $lock );
		wp_send_json_success( array( 'job' => $job->to_public() ) );
	}

	/**
	 * Stop with an error for a job that was never started: only the mapping screen's
	 * save starts one, after the duplicate check settled its options.
	 *
	 * @param MigratePlus_Job $job Job.
	 */
	protected static function refuse_draft( MigratePlus_Job $job ) {
		if ( 'draft' === $job->data['status'] ) {
			self::fail( new WP_Error( 'migrateplus_draft', __( 'This import has not been started yet. Choose its mapping and options, then start it.', 'migrateplus' ) ) );
		}
	}

	/**
	 * Roll back one batch.
	 */
	public static function ajax_rollback() {
		self::guard();
		$job    = self::request_job();
		$result = MigratePlus_Runner::rollback_batch( $job );
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
		MigratePlus_Runner::unlock( $job, $lock );
		wp_send_json_success();
	}

	/**
	 * Delete several History entries (imports and exports) in one request.
	 */
	public static function ajax_history_delete() {
		self::guard();
		$items = isset( $_POST['items'] ) ? json_decode( wp_unslash( $_POST['items'] ), true ) : array(); // phpcs:ignore WordPress.Security.NonceVerification, WordPress.Security.ValidatedSanitizedInput -- verified in guard(); each id is sanitized in delete_entries().
		wp_send_json_success( self::delete_entries( is_array( $items ) ? $items : array() ) );
	}

	/**
	 * Delete History entries the way the single deletes do, refusing any that are still running.
	 * A job is also refused while a batch or a rollback batch holds its lock.
	 *
	 * @param array[] $items Entries, each { kind: job|export, id }.
	 * @return array { deleted: array[] { kind, id }, skipped: array[] { kind, id, reason } }
	 */
	protected static function delete_entries( array $items ) {
		$out  = array(
			'deleted' => array(),
			'skipped' => array(),
		);
		$skip = static function ( $kind, $id, $reason ) use ( &$out ) {
			$out['skipped'][] = array(
				'kind'   => $kind,
				'id'     => $id,
				'reason' => $reason,
			);
		};
		foreach ( $items as $item ) {
			$kind = is_array( $item ) && isset( $item['kind'] ) ? sanitize_key( $item['kind'] ) : '';
			$id   = is_array( $item ) && isset( $item['id'] ) ? sanitize_key( $item['id'] ) : '';
			if ( '' === $id || ! in_array( $kind, array( 'job', 'export' ), true ) ) {
				continue;
			}
			if ( 'job' === $kind ) {
				$job = MigratePlus_Job::load( $id );
				if ( ! $job ) {
					$skip( $kind, $id, __( 'no longer in history', 'migrateplus' ) );
					continue;
				}
				if ( ! self::may_use_job( $job ) ) {
					$skip( $kind, $id, __( 'not allowed', 'migrateplus' ) );
					continue;
				}
				// One whose requests died stays "running"; it has stopped once its lock has expired.
				if ( 'running' === $job->data['status'] && ! $job->is_stalled() ) {
					$skip( $kind, $id, __( 'still running', 'migrateplus' ) );
					continue;
				}
				$lock = MigratePlus_Runner::lock( $job );
				if ( ! $lock ) {
					$skip( $kind, $id, __( 'an import or rollback batch is running', 'migrateplus' ) );
					continue;
				}
				// lock() reloaded the job: it may have started running while this request waited. The lock
				// is this request's now, so only the time of the last save tells a stopped one apart.
				if ( 'running' === $job->data['status'] && (int) $job->data['updated'] > time() - MigratePlus_Runner::LOCK_TTL ) {
					MigratePlus_Runner::unlock( $job, $lock );
					$skip( $kind, $id, __( 'still running', 'migrateplus' ) );
					continue;
				}
				$job->delete();
				MigratePlus_Runner::unlock( $job, $lock );
			} else {
				$entry = MigratePlus_Exporter::history_entry( $id );
				if ( ! $entry ) {
					$skip( $kind, $id, __( 'no longer in history', 'migrateplus' ) );
					continue;
				}
				if ( ! self::may_use_export( $id ) ) {
					$skip( $kind, $id, __( 'not allowed', 'migrateplus' ) );
					continue;
				}
				// As in history(): a running export whose state has expired was abandoned, and may go.
				if ( 'running' === $entry['status'] && MigratePlus_Exporter::load_state( $id ) ) {
					$skip( $kind, $id, __( 'still running', 'migrateplus' ) );
					continue;
				}
				MigratePlus_Exporter::forget( $id );
			}
			$out['deleted'][] = array(
				'kind' => $kind,
				'id'   => $id,
			);
		}
		return $out;
	}

	/**
	 * List jobs.
	 */
	public static function ajax_jobs() {
		self::guard();
		$jobs = array();
		foreach ( MigratePlus_Job::all() as $job ) {
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
		$source          = MigratePlus_Sources::create( $job->data['source'], (array) $job->data['settings'] );
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
				$s         = MigratePlus_Util::stringify( $v );
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
		$all = MigratePlus_Sources::all();
		return isset( $all[ $id ] ) && MigratePlus_Sources::usable( $all[ $id ] ) ? call_user_func( array( $all[ $id ], 'label' ) ) : $id;
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
		return MigratePlus_Exporter::args_for_user( is_array( $raw ) ? $raw : array() );
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
		$counts = MigratePlus_Exporter::counts( $args );
		$sides  = MigratePlus_Exporter::side_counts( $args );
		wp_send_json_success(
			array(
				'count'  => array_sum( $counts ),
				'counts' => $counts,
				'sides'  => $sides,
				'menus'  => isset( $sides['menus'] ) ? $sides['menus'] : 0,
				'terms'  => isset( $sides['terms'] ) ? $sides['terms'] : 0,
			)
		);
	}

	/**
	 * Posts matching a title search (or an ID), to hand-pick for an export.
	 */
	public static function ajax_export_posts() {
		self::guard();
		// phpcs:disable WordPress.Security.NonceVerification -- verified in guard().
		$term = isset( $_POST['search'] ) ? sanitize_text_field( wp_unslash( $_POST['search'] ) ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		// phpcs:enable
		if ( '' === $term ) {
			wp_send_json_success( array( 'posts' => array() ) );
		}
		$types = array();
		foreach ( get_post_types( array(), 'objects' ) as $type => $object ) {
			if ( MigratePlus_Exporter::exportable_type( $type ) && current_user_can( $object->cap->edit_posts ) ) {
				$types[] = $type;
			}
		}
		$query = new WP_Query(
			array(
				'post_type'           => $types,
				'post_status'         => array( 'publish', 'draft', 'pending', 'private', 'future', 'inherit' ),
				's'                   => $term,
				'posts_per_page'      => 20,
				'suppress_filters'    => true,
				'ignore_sticky_posts' => true,
				'no_found_rows'       => true,
			)
		);
		$found = $query->posts;
		// A number finds that exact post too, first.
		if ( is_numeric( $term ) ) {
			$exact = get_post( absint( $term ) );
			if ( $exact && in_array( $exact->post_type, $types, true ) && ! in_array( $exact->post_status, array( 'trash', 'auto-draft' ), true ) ) {
				$found = array_merge( array( $exact ), wp_list_filter( $found, array( 'ID' => $exact->ID ), 'NOT' ) );
			}
		}
		$posts = array();
		foreach ( $found as $post ) {
			$object  = get_post_type_object( $post->post_type );
			$status  = get_post_status_object( $post->post_status );
			$posts[] = array(
				'id'     => $post->ID,
				'title'  => '' !== $post->post_title ? $post->post_title : __( '(no title)', 'migrateplus' ),
				'type'   => $post->post_type,
				'label'  => $object ? $object->labels->singular_name : $post->post_type,
				'status' => $status ? $status->label : $post->post_status,
				'date'   => get_the_date( '', $post ),
			);
		}
		wp_send_json_success( array( 'posts' => $posts ) );
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
		$state = MigratePlus_Exporter::start( $args );
		if ( is_wp_error( $state ) ) {
			self::fail( $state );
		}
		// With menus, terms or settings included, a file of those alone is still worth having.
		if ( 0 === (int) $state['total'] && ! array_sum( MigratePlus_Exporter::side_counts( $args ) ) ) {
			MigratePlus_Exporter::forget( $state['id'] );
			self::fail( new WP_Error( 'migrateplus_export_empty', __( 'Nothing matches these filters. Widen the status or date range.', 'migrateplus' ) ) );
		}
		wp_send_json_success( self::export_public( $state ) );
	}

	/**
	 * Export the next batch.
	 */
	public static function ajax_export_run() {
		self::guard();
		$state = self::request_export();
		MigratePlus_Exporter::run_batch( $state, 50 );
		wp_send_json_success( self::export_public( $state ) );
	}

	/**
	 * Download a finished export.
	 */
	public static function ajax_export_download() {
		self::guard();
		$state = self::request_export();
		if ( empty( $state['complete'] ) || ! is_readable( $state['file'] ) ) {
			wp_die( esc_html__( 'This export is not ready or has expired. Run it again.', 'migrateplus' ), '', array( 'response' => 404 ) );
		}
		nocache_headers();
		$gzip = '.gz' === substr( $state['filename'], -3 );
		header( $gzip ? 'Content-Type: application/gzip' : 'Content-Type: application/json; charset=utf-8' );
		// Sanitized without the ".gz", which sanitize_file_name() would turn ".json" in front of into ".json_".
		header( 'Content-Disposition: attachment; filename="' . sanitize_file_name( $gzip ? substr( $state['filename'], 0, -3 ) : $state['filename'] ) . ( $gzip ? '.gz' : '' ) . '"' );
		header( 'Content-Length: ' . filesize( $state['file'] ) );
		readfile( $state['file'] ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		exit;
	}

	/**
	 * Export history.
	 */
	public static function ajax_exports() {
		self::guard();
		$exports = array();
		// Like the jobs: only the user's own, or every one for an administrator.
		foreach ( MigratePlus_Exporter::history() as $entry ) {
			if ( ! self::may_use_export( $entry['id'] ) ) {
				continue;
			}
			$entry['size'] = $entry['size'] ? size_format( $entry['size'] ) : '';
			$entry['url']  = $entry['available'] ? self::export_download_url( $entry['id'] ) : '';
			$exports[]     = $entry;
		}
		wp_send_json_success( array( 'exports' => $exports ) );
	}

	/**
	 * One export's log.
	 */
	public static function ajax_export_log() {
		self::guard();
		$id    = isset( $_POST['export'] ) ? sanitize_key( wp_unslash( $_POST['export'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification -- verified in guard().
		$entry = self::may_use_export( $id ) ? MigratePlus_Exporter::history_entry( $id ) : null;
		if ( ! $entry ) {
			wp_send_json_error( array( 'message' => __( 'This export is no longer in history.', 'migrateplus' ) ), 404 );
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
			wp_send_json_error( array( 'message' => __( 'You are not allowed to do this.', 'migrateplus' ) ), 403 );
		}
		MigratePlus_Exporter::forget( $id );
		wp_send_json_success();
	}

	// Pull from another site: this site asks the old one, over its Migrate Plus REST
	// routes, to export and hand over the file, which then imports like a dropped one.

	/**
	 * The old site's address and credentials from the request: a username with an
	 * application password, or the old site's pull key. Either is sent along with
	 * every pull request and stored nowhere on this site.
	 *
	 * @return array { url, user, pass, key } Sends the error instead when something is missing.
	 */
	protected static function pull_args() {
		// phpcs:disable WordPress.Security.NonceVerification, WordPress.Security.ValidatedSanitizedInput -- verified in guard(); sanitized here.
		$url  = isset( $_POST['url'] ) ? esc_url_raw( trim( (string) wp_unslash( $_POST['url'] ) ), array( 'http', 'https' ) ) : '';
		$user = isset( $_POST['user'] ) ? sanitize_text_field( wp_unslash( $_POST['user'] ) ) : '';
		$pass = isset( $_POST['pass'] ) ? trim( (string) wp_unslash( $_POST['pass'] ) ) : '';
		$key  = isset( $_POST['key'] ) ? trim( (string) wp_unslash( $_POST['key'] ) ) : '';
		// phpcs:enable
		if ( '' === $url ) {
			self::fail( new WP_Error( 'migrateplus_pull_url', __( 'Enter the old site\'s address, starting with http:// or https://.', 'migrateplus' ) ) );
		}
		if ( '' === $key && ( '' === $user || '' === $pass ) ) {
			self::fail( new WP_Error( 'migrateplus_pull_auth', __( 'Enter a username and an application password from the old site, or its pull key.', 'migrateplus' ) ) );
		}
		return array(
			'url'  => $url,
			'user' => $user,
			'pass' => $pass,
			'key'  => $key,
		);
	}

	/**
	 * A route's URL on the old site. ?rest_route= works with and without pretty permalinks.
	 *
	 * @param array  $pull  From pull_args().
	 * @param string $route Route under the plugin's namespace.
	 * @param array  $query Query args.
	 * @return string
	 */
	protected static function pull_url( array $pull, $route, array $query = array() ) {
		return add_query_arg(
			array_merge( array( 'rest_route' => '/' . MigratePlus_Rest::ROUTE_NAMESPACE . '/' . $route ), $query ),
			trailingslashit( $pull['url'] )
		);
	}

	/**
	 * @param array $pull From pull_args().
	 * @return array Request headers.
	 */
	protected static function pull_headers( array $pull ) {
		if ( '' !== $pull['key'] ) {
			return array( 'X-MigratePlus-Key' => $pull['key'] );
		}
		return array( 'Authorization' => 'Basic ' . base64_encode( $pull['user'] . ':' . $pull['pass'] ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions -- Basic auth is base64 by definition.
	}

	/**
	 * Call one of the old site's Migrate Plus routes and return the decoded answer.
	 *
	 * @param array      $pull  From pull_args().
	 * @param string     $route Route under the plugin's namespace.
	 * @param array|null $body  POST body, or null for GET.
	 * @return array Sends the error instead when the old site cannot be used.
	 */
	protected static function pull_request( array $pull, $route, $body = null ) {
		$args = array(
			'timeout'    => max( 30, (int) MigratePlus_Settings::get( 'request_timeout' ) ),
			'headers'    => self::pull_headers( $pull ),
			'user-agent' => 'MigratePlus/' . MIGRATEPLUS_VERSION . '; ' . home_url(),
		);
		if ( null !== $body ) {
			$args['method']                  = 'POST';
			$args['body']                    = wp_json_encode( $body );
			$args['headers']['Content-Type'] = 'application/json';
		}
		// Reset in finally: left on after an exception, the next request anything makes would get the local-host allowance.
		MigratePlus_Util::$in_request = true;
		try {
			$response = wp_safe_remote_request( self::pull_url( $pull, $route ), $args );
		} finally {
			MigratePlus_Util::$in_request = false;
		}
		if ( is_wp_error( $response ) ) {
			/* translators: %s: error message */
			self::fail( new WP_Error( 'migrateplus_pull_http', sprintf( __( 'Could not reach the old site: %s', 'migrateplus' ), $response->get_error_message() ) ) );
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( $code >= 200 && $code < 300 && is_array( $data ) ) {
			return $data;
		}
		if ( is_array( $data ) && isset( $data['code'] ) && 'rest_no_route' === $data['code'] ) {
			self::fail( new WP_Error( 'migrateplus_pull_plugin', __( 'Migrate Plus is not active on the old site, or its version there is too old to pull from.', 'migrateplus' ) ) );
		}
		if ( is_array( $data ) && ! empty( $data['message'] ) ) {
			/* translators: %s: the other site's error message */
			self::fail( new WP_Error( 'migrateplus_pull_remote', sprintf( __( 'The old site said: %s', 'migrateplus' ), sanitize_text_field( (string) $data['message'] ) ) ) );
		}
		/* translators: %d: HTTP status code */
		self::fail( new WP_Error( 'migrateplus_pull_http', sprintf( __( 'The old site did not answer like a WordPress site (HTTP %d). Check the address.', 'migrateplus' ), $code ) ) );
	}

	/**
	 * Try the connection and describe the old site: its name and what it offers to export.
	 */
	public static function ajax_pull_check() {
		self::guard();
		$pull = self::pull_args();
		$site = self::pull_request( $pull, 'site' );
		wp_send_json_success(
			array(
				'name'    => sanitize_text_field( (string) ( isset( $site['name'] ) ? $site['name'] : '' ) ),
				'url'     => esc_url_raw( (string) ( isset( $site['url'] ) ? $site['url'] : '' ) ),
				'version' => sanitize_text_field( (string) ( isset( $site['version'] ) ? $site['version'] : '' ) ),
				'types'   => array_values(
					array_map(
						static function ( $type ) {
							return array(
								'type'  => sanitize_key( (string) ( isset( $type['type'] ) ? $type['type'] : '' ) ),
								'label' => sanitize_text_field( (string) ( isset( $type['label'] ) ? $type['label'] : '' ) ),
								'count' => isset( $type['count'] ) ? (int) $type['count'] : 0,
							);
						},
						isset( $site['types'] ) && is_array( $site['types'] ) ? array_filter( $site['types'], 'is_array' ) : array()
					)
				),
			)
		);
	}

	/**
	 * Start the export on the old site.
	 */
	public static function ajax_pull_start() {
		self::guard();
		$pull = self::pull_args();
		// phpcs:disable WordPress.Security.NonceVerification -- verified in guard().
		$types = isset( $_POST['post_types'] ) ? array_filter( array_map( 'sanitize_key', explode( ',', (string) wp_unslash( $_POST['post_types'] ) ) ) ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		// phpcs:enable
		if ( ! $types ) {
			self::fail( new WP_Error( 'migrateplus_pull_types', __( 'Choose at least one content type to pull.', 'migrateplus' ) ) );
		}
		// The other args keep the old site's own export defaults.
		$started = self::pull_request( $pull, 'export', array( 'args' => array( 'post_types' => array_values( $types ) ) ) );
		wp_send_json_success(
			array(
				'export' => sanitize_key( (string) ( isset( $started['id'] ) ? $started['id'] : '' ) ),
				'total'  => isset( $started['total'] ) ? (int) $started['total'] : 0,
			)
		);
	}

	/**
	 * Export the next batch on the old site and relay the progress.
	 */
	public static function ajax_pull_run() {
		self::guard();
		$pull = self::pull_args();
		$id   = isset( $_POST['export'] ) ? sanitize_key( wp_unslash( $_POST['export'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification -- verified in guard().
		$done = self::pull_request( $pull, 'export/run', array( 'export' => $id ) );
		if ( ! empty( $done['error'] ) ) {
			/* translators: %s: the other site's error message */
			self::fail( new WP_Error( 'migrateplus_pull_remote', sprintf( __( 'The old site said: %s', 'migrateplus' ), sanitize_text_field( (string) $done['error'] ) ) ) );
		}
		wp_send_json_success(
			array(
				'done'     => isset( $done['done'] ) ? (int) $done['done'] : 0,
				'total'    => isset( $done['total'] ) ? (int) $done['total'] : 0,
				'complete' => ! empty( $done['complete'] ),
				'filename' => sanitize_file_name( (string) ( isset( $done['filename'] ) ? $done['filename'] : '' ) ),
			)
		);
	}

	/**
	 * Download the finished file from the old site and preview it, like a dropped file.
	 */
	public static function ajax_pull_fetch() {
		self::guard();
		$pull = self::pull_args();
		// phpcs:disable WordPress.Security.NonceVerification -- verified in guard().
		$id   = isset( $_POST['export'] ) ? sanitize_key( wp_unslash( $_POST['export'] ) ) : '';
		$name = isset( $_POST['filename'] ) ? sanitize_file_name( wp_unslash( $_POST['filename'] ) ) : '';
		// phpcs:enable
		if ( '' === $id ) {
			self::fail( new WP_Error( 'migrateplus_pull_export', __( 'Run the export on the old site first.', 'migrateplus' ) ) );
		}
		// The URL has no file extension; gzip is detected by the file's bytes either way.
		$path = MigratePlus_Util::download_to_storage( self::pull_url( $pull, 'export/file', array( 'export' => $id ) ), array( 'json', 'gz' ), self::pull_headers( $pull ) );
		if ( is_wp_error( $path ) ) {
			self::fail( $path );
		}
		$settings = array( 'file' => $path );
		if ( '' !== $name ) {
			// Named like the export, so re-imports of the same pull match in history.
			$settings['file_name'] = $name;
		}
		self::send_preview( 'json', $settings, array( $path ) );
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
		$state = $id ? MigratePlus_Exporter::load_state( $id ) : null;
		return $state && get_current_user_id() === (int) $state['user'];
	}

	/**
	 * @param string $id Export id.
	 * @return string
	 */
	protected static function export_download_url( $id ) {
		return add_query_arg(
			array(
				'action' => 'migrateplus_export_download',
				'export' => $id,
				'nonce'  => wp_create_nonce( 'migrateplus_admin' ),
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
		$state = MigratePlus_Replace::start( is_array( $raw ) ? $raw : array() );
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
		$state = MigratePlus_Replace::load_state( isset( $_POST['run'] ) ? sanitize_key( wp_unslash( $_POST['run'] ) ) : '' ); // phpcs:ignore WordPress.Security.NonceVerification -- verified in guard().
		if ( ! $state ) {
			wp_send_json_error( array( 'message' => __( 'This run has expired. Start it again.', 'migrateplus' ) ), 404 );
		}
		MigratePlus_Replace::run_batch( $state );
		$out = self::replace_public( $state );
		if ( $state['complete'] ) {
			MigratePlus_Replace::delete( $state['id'] );
		}
		wp_send_json_success( $out );
	}

	/**
	 * Past real runs.
	 */
	public static function ajax_replace_history() {
		self::guard_admin();
		wp_send_json_success( array( 'runs' => MigratePlus_Replace::history() ) );
	}

	/**
	 * Roll back the next batch of a run.
	 */
	public static function ajax_replace_rollback() {
		self::guard_admin();
		$entry = MigratePlus_Replace::rollback_batch( isset( $_POST['run'] ) ? sanitize_key( wp_unslash( $_POST['run'] ) ) : '' ); // phpcs:ignore WordPress.Security.NonceVerification -- verified in guard().
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
		MigratePlus_Replace::forget( isset( $_POST['run'] ) ? sanitize_key( wp_unslash( $_POST['run'] ) ) : '' ); // phpcs:ignore WordPress.Security.NonceVerification -- verified in guard().
		wp_send_json_success();
	}

	protected static function guard_admin() {
		self::guard();
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Only administrators can use Find & Replace.', 'migrateplus' ) ), 403 );
		}
		// As for the menu: the tables belong to the whole network.
		if ( is_multisite() && ! is_super_admin() ) {
			wp_send_json_error( array( 'message' => __( 'Only network administrators can use Find & Replace.', 'migrateplus' ) ), 403 );
		}
	}

	/**
	 * Whether a post type may be exported. Lives on the exporter (the REST routes need it
	 * outside the admin); kept here for the callers that know this class.
	 *
	 * @param string $type Post type.
	 * @return bool
	 */
	public static function exportable_type( $type ) {
		return MigratePlus_Exporter::exportable_type( $type );
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
		$state = $id ? MigratePlus_Exporter::load_state( $id ) : null;
		if ( ! $state || ( get_current_user_id() !== (int) $state['user'] && ! current_user_can( 'manage_options' ) ) ) {
			wp_send_json_error( array( 'message' => __( 'Export not found. It may have expired; run it again.', 'migrateplus' ) ), 404 );
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
		// The post type being exported, when the file holds more than one; or the side section (orders) after them.
		$types          = $state['args']['post_types'];
		$side           = MigratePlus_Exporter::current_side( $state );
		$out['section'] = '' !== $side && ! $out['complete'] ? ucfirst( MigratePlus_Exporter::side_providers()[ $side ]['label'] ) : ( count( $types ) > 1 && ! $out['complete'] ? MigratePlus_Exporter::type_label( $types[ min( (int) $state['type_pos'], count( $types ) - 1 ) ] ) : '' );
		$out['sides']   = isset( $state['sides'] ) ? (array) $state['sides'] : array();
		$out['menus']   = (int) $state['menus'];
		$out['terms']   = isset( $state['terms'] ) ? (int) $state['terms'] : 0;
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
