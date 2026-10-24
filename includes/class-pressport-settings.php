<?php
/**
 * Plugin settings (PressPort → Settings).
 *
 * @package PressPort
 */

defined( 'ABSPATH' ) || exit;

class PressPort_Settings {

	const OPTION = 'pressport_settings';
	const GROUP  = 'pressport_settings_group';
	const PAGE   = 'pressport-settings';

	/**
	 * Cached merged settings for the request.
	 *
	 * @var array|null
	 */
	protected static $cache = null;

	/**
	 * @return array
	 */
	public static function defaults() {
		return array(
			// Import defaults.
			'post_type'                => 'post',
			'default_status'           => 'publish',
			'default_author'           => 0, // 0 = whoever runs the import.
			'duplicate'                => 'skip',
			'batch_size'               => 10,
			'import_featured_image'    => 1,
			'import_content_images'    => 0,
			'create_terms'             => 1,
			'create_authors'           => 0,
			// Access.
			'capability'               => 'manage_options',
			// Connections.
			'request_timeout'          => 30,
			'allow_local_sources'      => 0,
			'max_remote_size'          => 100, // MB.
			// Housekeeping.
			'job_retention_days'       => 0,
			'remove_meta_on_uninstall' => 0,
		);
	}

	/**
	 * All settings, or one key.
	 *
	 * @param string|null $key Key.
	 * @return mixed
	 */
	public static function get( $key = null ) {
		if ( null === self::$cache ) {
			self::$cache = array_merge( self::defaults(), (array) get_option( self::OPTION, array() ) );
		}
		if ( null === $key ) {
			return self::$cache;
		}
		return isset( self::$cache[ $key ] ) ? self::$cache[ $key ] : null;
	}

	public static function flush_cache() {
		self::$cache = null;
	}

	/**
	 * Roles that can be given access, keyed by capability.
	 *
	 * @return array
	 */
	public static function access_levels() {
		return array(
			'manage_options'    => __( 'Administrators only', 'pressport' ),
			'edit_others_posts' => __( 'Editors and Administrators', 'pressport' ),
		);
	}

	public static function init() {
		add_action( 'admin_init', array( __CLASS__, 'register' ) );
		add_action( 'update_option_' . self::OPTION, array( __CLASS__, 'flush_cache' ) );
		add_filter( 'option_page_capability_' . self::GROUP, array( __CLASS__, 'settings_capability' ) );
	}

	/**
	 * Only admins can change who has access, whatever the access level is set to.
	 *
	 * @return string
	 */
	public static function settings_capability() {
		return 'manage_options';
	}

	public static function register() {
		register_setting(
			self::GROUP,
			self::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( __CLASS__, 'sanitize' ),
				'default'           => self::defaults(),
			)
		);

		add_settings_section( 'pressport_defaults', __( 'Import defaults', 'pressport' ), array( __CLASS__, 'section_defaults' ), self::PAGE );
		add_settings_section( 'pressport_access', __( 'Access', 'pressport' ), '__return_false', self::PAGE );
		add_settings_section( 'pressport_connections', __( 'Connections', 'pressport' ), '__return_false', self::PAGE );
		add_settings_section( 'pressport_housekeeping', __( 'Housekeeping', 'pressport' ), '__return_false', self::PAGE );

		$post_types = array();
		foreach ( get_post_types( array( 'show_ui' => true ), 'objects' ) as $pt ) {
			if ( ! in_array( $pt->name, array( 'attachment', 'wp_block', 'wp_navigation', 'wp_template', 'wp_template_part' ), true ) ) {
				$post_types[ $pt->name ] = $pt->labels->singular_name;
			}
		}

		$authors = array( 0 => __( 'The person running the import', 'pressport' ) );
		foreach ( get_users(
			array(
				'capability' => array( 'edit_posts' ),
				'number'     => 200,
				'fields'     => array( 'ID', 'display_name' ),
			)
		) as $user ) {
			$authors[ $user->ID ] = $user->display_name;
		}

		$fields = array(
			'pressport_defaults'     => array(
				'post_type'             => array( 'select', __( 'Import as', 'pressport' ), $post_types ),
				'default_status'        => array(
					'select',
					__( 'Default status', 'pressport' ),
					array(
						'publish' => __( 'Published', 'pressport' ),
						'draft'   => __( 'Draft', 'pressport' ),
						'pending' => __( 'Pending review', 'pressport' ),
						'private' => __( 'Private', 'pressport' ),
					),
					__( 'Used when a record has no status, or one PressPort does not recognise.', 'pressport' ),
				),
				'default_author'        => array( 'select', __( 'Default author', 'pressport' ), $authors ),
				'duplicate'             => array(
					'select',
					__( 'Records imported before', 'pressport' ),
					array(
						'skip'   => __( 'Skip them', 'pressport' ),
						'update' => __( 'Update them', 'pressport' ),
						'create' => __( 'Create duplicates', 'pressport' ),
					),
					__( 'Matched by the Source ID field.', 'pressport' ),
				),
				'batch_size'            => array( 'number', __( 'Batch size', 'pressport' ), array( 1, 100 ), __( 'Records per request. Lower this if imports with lots of images time out.', 'pressport' ) ),
				'import_featured_image' => array( 'checkbox', __( 'Featured images', 'pressport' ), __( 'Download featured images into the Media Library', 'pressport' ) ),
				'import_content_images' => array( 'checkbox', __( 'Images in content', 'pressport' ), __( 'Download images found inside post content and rewrite their URLs', 'pressport' ) ),
				'create_terms'          => array( 'checkbox', __( 'Terms', 'pressport' ), __( 'Create categories, tags and terms that don\'t exist yet', 'pressport' ) ),
				'create_authors'        => array( 'checkbox', __( 'Authors', 'pressport' ), __( 'Create users for authors that don\'t exist yet (Author role, random password)', 'pressport' ) ),
			),
			'pressport_access'       => array(
				'capability' => array( 'select', __( 'Who can run imports', 'pressport' ), self::access_levels(), __( 'Only administrators can change these settings.', 'pressport' ) ),
			),
			'pressport_connections'  => array(
				'request_timeout'     => array( 'number', __( 'Request timeout', 'pressport' ), array( 5, 300 ), __( 'Seconds to wait for a remote site or feed. Image downloads get at least 60 seconds.', 'pressport' ), __( 'seconds', 'pressport' ) ),
				'max_remote_size'     => array( 'number', __( 'Remote file size limit', 'pressport' ), array( 1, 2048 ), __( 'Largest file PressPort will download when a CSV is imported from a URL.', 'pressport' ), __( 'MB', 'pressport' ) ),
				'allow_local_sources' => array( 'checkbox', __( 'Local sources', 'pressport' ), __( 'Allow importing from localhost, private network addresses and non-standard ports', 'pressport' ), __( 'Turn this on to migrate from a local dev site (for example http://oldsite.test:8888). WordPress blocks these addresses by default; this only relaxes the rule for PressPort\'s own requests.', 'pressport' ) ),
			),
			'pressport_housekeeping' => array(
				'job_retention_days'       => array( 'number', __( 'Keep import history', 'pressport' ), array( 0, 3650 ), __( 'Delete history entries older than this. 0 keeps them forever. Imported content is never touched, but a deleted entry can no longer be rolled back.', 'pressport' ), __( 'days', 'pressport' ) ),
				'remove_meta_on_uninstall' => array( 'checkbox', __( 'On uninstall', 'pressport' ), __( 'Also remove PressPort\'s tracking data from imported posts', 'pressport' ), __( 'History and settings are always removed on uninstall. The tracking data is what lets a reinstall skip or update posts it already imported.', 'pressport' ) ),
			),
		);

		foreach ( $fields as $section => $items ) {
			foreach ( $items as $key => $def ) {
				add_settings_field(
					'pressport_' . $key,
					$def[1],
					array( __CLASS__, 'field_' . $def[0] ),
					self::PAGE,
					$section,
					array(
						'key'       => $key,
						'label_for' => 'checkbox' === $def[0] ? null : 'pressport_' . $key,
						'choices'   => isset( $def[2] ) ? $def[2] : null,
						'help'      => isset( $def[3] ) ? $def[3] : '',
						'suffix'    => isset( $def[4] ) ? $def[4] : '',
					)
				);
			}
		}
	}

	public static function section_defaults() {
		echo '<p>' . esc_html__( 'Starting values for new imports. You can still change them on the mapping screen for each import.', 'pressport' ) . '</p>';
	}

	/**
	 * @param array $args Field args.
	 */
	public static function field_select( $args ) {
		$value = self::get( $args['key'] );
		printf( '<select id="pressport_%1$s" name="%2$s[%1$s]">', esc_attr( $args['key'] ), esc_attr( self::OPTION ) );
		foreach ( $args['choices'] as $option => $label ) {
			printf( '<option value="%s"%s>%s</option>', esc_attr( $option ), selected( (string) $value, (string) $option, false ), esc_html( $label ) );
		}
		echo '</select>';
		self::help( $args );
	}

	/**
	 * @param array $args Field args.
	 */
	public static function field_number( $args ) {
		printf(
			'<input type="number" class="small-text" id="pressport_%1$s" name="%2$s[%1$s]" value="%3$d" min="%4$d" max="%5$d"> %6$s',
			esc_attr( $args['key'] ),
			esc_attr( self::OPTION ),
			(int) self::get( $args['key'] ),
			(int) $args['choices'][0],
			(int) $args['choices'][1],
			esc_html( $args['suffix'] )
		);
		self::help( $args );
	}

	/**
	 * @param array $args Field args.
	 */
	public static function field_checkbox( $args ) {
		printf(
			'<label><input type="checkbox" name="%2$s[%1$s]" value="1"%3$s> %4$s</label>',
			esc_attr( $args['key'] ),
			esc_attr( self::OPTION ),
			checked( (int) self::get( $args['key'] ), 1, false ),
			esc_html( $args['choices'] )
		);
		self::help( $args );
	}

	/**
	 * @param array $args Field args.
	 */
	protected static function help( $args ) {
		if ( $args['help'] ) {
			echo '<p class="description">' . esc_html( $args['help'] ) . '</p>';
		}
	}

	/**
	 * @param mixed $input Submitted values.
	 * @return array
	 */
	public static function sanitize( $input ) {
		$input    = is_array( $input ) ? $input : array();
		$defaults = self::defaults();
		$out      = array();

		$post_type        = isset( $input['post_type'] ) ? sanitize_key( $input['post_type'] ) : $defaults['post_type'];
		$out['post_type'] = post_type_exists( $post_type ) ? $post_type : $defaults['post_type'];

		$out['default_status'] = isset( $input['default_status'] ) && in_array( $input['default_status'], array( 'publish', 'draft', 'pending', 'private' ), true ) ? $input['default_status'] : $defaults['default_status'];
		$out['duplicate']      = isset( $input['duplicate'] ) && in_array( $input['duplicate'], array( 'skip', 'update', 'create' ), true ) ? $input['duplicate'] : $defaults['duplicate'];
		$out['capability']     = isset( $input['capability'] ) && array_key_exists( $input['capability'], self::access_levels() ) ? $input['capability'] : $defaults['capability'];

		$author                = isset( $input['default_author'] ) ? absint( $input['default_author'] ) : 0;
		$out['default_author'] = $author && get_userdata( $author ) ? $author : 0;

		$ranges = array(
			'batch_size'         => array( 1, 100 ),
			'request_timeout'    => array( 5, 300 ),
			'max_remote_size'    => array( 1, 2048 ),
			'job_retention_days' => array( 0, 3650 ),
		);
		foreach ( $ranges as $key => $range ) {
			$value       = isset( $input[ $key ] ) ? (int) $input[ $key ] : $defaults[ $key ];
			$out[ $key ] = max( $range[0], min( $range[1], $value ) );
		}

		foreach ( array( 'import_featured_image', 'import_content_images', 'create_terms', 'create_authors', 'allow_local_sources', 'remove_meta_on_uninstall' ) as $key ) {
			$out[ $key ] = empty( $input[ $key ] ) ? 0 : 1;
		}

		self::flush_cache();
		return $out;
	}

	/**
	 * Render the settings page.
	 */
	public static function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Only administrators can change PressPort settings.', 'pressport' ) );
		}
		?>
		<div class="wrap pressport-wrap pressport-settings">
			<h1><?php esc_html_e( 'PressPort Settings', 'pressport' ); ?></h1>
			<?php settings_errors(); ?>
			<div class="pressport-settings-layout">
				<form action="options.php" method="post" class="pressport-card">
					<?php
					settings_fields( self::GROUP );
					do_settings_sections( self::PAGE );
					submit_button();
					?>
				</form>
				<aside class="pressport-card pressport-status">
					<h2><?php esc_html_e( 'Server check', 'pressport' ); ?></h2>
					<?php self::render_status(); ?>
				</aside>
			</div>
		</div>
		<?php
	}

	/**
	 * A few environment facts that explain most failed imports.
	 */
	protected static function render_status() {
		$dir      = PressPort_Util::storage_dir();
		$writable = wp_is_writable( is_dir( $dir ) ? $dir : dirname( $dir ) );
		$time     = (int) ini_get( 'max_execution_time' );
		$memory   = ini_get( 'memory_limit' );
		$rows     = array(
			array(
				__( 'Upload folder', 'pressport' ),
				$writable ? __( 'Writable', 'pressport' ) : __( 'Not writable', 'pressport' ),
				$writable,
			),
			array(
				__( 'Max upload size', 'pressport' ),
				size_format( wp_max_upload_size() ),
				true,
			),
			array(
				__( 'PHP time limit', 'pressport' ),
				/* translators: %d: seconds */
				0 === $time ? __( 'None', 'pressport' ) : sprintf( __( '%d seconds', 'pressport' ), $time ),
				0 === $time || $time >= 30,
			),
			array(
				__( 'PHP memory limit', 'pressport' ),
				'-1' === (string) $memory ? __( 'Unlimited', 'pressport' ) : $memory,
				'-1' === (string) $memory || wp_convert_hr_to_bytes( $memory ) >= 128 * MB_IN_BYTES,
			),
			array(
				__( 'Image processing', 'pressport' ),
				wp_image_editor_supports() ? __( 'Available', 'pressport' ) : __( 'Missing (GD or Imagick)', 'pressport' ),
				wp_image_editor_supports(),
			),
		);
		echo '<table class="pressport-status-table"><tbody>';
		foreach ( $rows as $row ) {
			printf(
				'<tr><th scope="row">%s</th><td><span class="pressport-dot %s" aria-hidden="true"></span>%s</td></tr>',
				esc_html( $row[0] ),
				$row[2] ? 'is-ok' : 'is-warn',
				esc_html( $row[1] )
			);
		}
		echo '</tbody></table>';
		echo '<p class="description">' . esc_html__( 'Large files or slow servers? Run imports from the command line instead:', 'pressport' ) . '</p>';
		echo '<p><code>wp pressport import csv --file=posts.csv</code></p>';
	}

	/**
	 * Daily cleanup of old history entries.
	 */
	public static function cleanup() {
		$days = (int) self::get( 'job_retention_days' );
		if ( $days < 1 ) {
			return;
		}
		$cutoff = time() - $days * DAY_IN_SECONDS;
		foreach ( PressPort_Job::all() as $job ) {
			if ( 'running' !== $job->data['status'] && (int) $job->data['updated'] < $cutoff ) {
				$job->delete();
			}
		}
	}
}
