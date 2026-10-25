<?php
/**
 * Plugin settings (MigratePlus → Settings).
 *
 * @package MigratePlus
 */

defined( 'ABSPATH' ) || exit;

class MigratePlus_Settings {

	const OPTION = 'migrateplus_settings';
	const GROUP  = 'migrateplus_settings_group';
	const PAGE   = 'migrateplus-settings';
	// Result of storage_public(): public, private or unknown.
	const PROBE_TRANSIENT = 'migrateplus_storage_probe';

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
			'post_type'                      => 'post',
			'default_status'                 => 'publish',
			'force_status'                   => '', // '' = keep each record's status.
			'duplicate'                      => 'skip',
			'batch_size'                     => 10,
			'import_featured_image'          => 1,
			'import_content_images'          => 0,
			'create_terms'                   => 1,
			'rewrite_links'                  => 1,
			'import_comments'                => 1,
			'import_menus'                   => 1,
			'import_term_data'               => 1,
			'import_acf_definitions'         => 1,
			'import_acf_options'             => 1,
			'import_site_settings'           => 1,
			'import_orders'                  => 1,
			'import_redirects'               => 1,
			// Export defaults.
			'export_statuses'                => array( 'publish' ),
			'export_include_terms'           => 1,
			'export_include_term_data'       => 1,
			'export_include_image'           => 1,
			'export_include_meta'            => 1,
			'export_include_acf'             => 1,
			'export_include_woo'             => 1,
			'export_include_comments'        => 0,
			'export_include_menus'           => 0,
			'export_include_acf_definitions' => 1,
			'export_include_acf_options'     => 1,
			'export_include_site_settings'   => 0,
			'export_include_orders'          => 0,
			'export_include_yoast'           => 1,
			'export_gzip'                    => 0,
			'export_exclude_meta'            => '',
			// Access.
			'capability'                     => 'manage_options',
			// Downloads.
			'request_timeout'                => 60,
			'allow_local_sources'            => 0,
			'max_remote_size'                => 100, // MB.
			'max_media_size'                 => 100, // MB, 0 = no limit.
			// Housekeeping.
			'job_retention_days'             => 0,
			'remove_meta_on_uninstall'       => 0,
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
			'manage_options'    => __( 'Administrators only', 'migrateplus' ),
			'edit_others_posts' => __( 'Editors and Administrators', 'migrateplus' ),
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

		add_settings_section( 'migrateplus_defaults', __( 'Import defaults', 'migrateplus' ), array( __CLASS__, 'section_defaults' ), self::PAGE );
		add_settings_section( 'migrateplus_export', __( 'Export defaults', 'migrateplus' ), array( __CLASS__, 'section_export' ), self::PAGE );
		add_settings_section( 'migrateplus_access', __( 'Access', 'migrateplus' ), '__return_false', self::PAGE );
		add_settings_section( 'migrateplus_connections', __( 'Downloads', 'migrateplus' ), array( __CLASS__, 'section_downloads' ), self::PAGE );
		add_settings_section( 'migrateplus_housekeeping', __( 'Housekeeping', 'migrateplus' ), '__return_false', self::PAGE );

		$post_types = array();
		foreach ( get_post_types( array( 'show_ui' => true ), 'objects' ) as $pt ) {
			if ( ! in_array( $pt->name, array( 'attachment', 'wp_block', 'wp_navigation', 'wp_template', 'wp_template_part' ), true ) ) {
				$post_types[ $pt->name ] = $pt->labels->singular_name;
			}
		}

		$fields = array(
			'migrateplus_defaults'     => array(
				'post_type'              => array( 'select', __( 'Import as', 'migrateplus' ), $post_types ),
				'default_status'         => array(
					'select',
					__( 'Default status', 'migrateplus' ),
					array(
						'publish' => __( 'Published', 'migrateplus' ),
						'draft'   => __( 'Draft', 'migrateplus' ),
						'pending' => __( 'Pending review', 'migrateplus' ),
						'private' => __( 'Private', 'migrateplus' ),
					),
					__( 'Used when a record has no status, or one Migrate Plus does not recognise.', 'migrateplus' ),
				),
				'force_status'           => array(
					'select',
					__( 'Status for every item', 'migrateplus' ),
					array(
						''        => __( 'Keep each record\'s status', 'migrateplus' ),
						'publish' => __( 'Published', 'migrateplus' ),
						'draft'   => __( 'Draft', 'migrateplus' ),
						'pending' => __( 'Pending review', 'migrateplus' ),
						'private' => __( 'Private', 'migrateplus' ),
					),
					__( 'Overrides the status in the file, also for posts an import updates. Draft is useful for reviewing an import on staging. Published posts with a future date are scheduled for that date.', 'migrateplus' ),
				),
				'duplicate'              => array(
					'select',
					__( 'Records imported before', 'migrateplus' ),
					array(
						'skip'   => __( 'Skip them', 'migrateplus' ),
						'update' => __( 'Update them', 'migrateplus' ),
						'create' => __( 'Create duplicates', 'migrateplus' ),
					),
					__( 'Matched by the Source ID field.', 'migrateplus' ),
				),
				'batch_size'             => array( 'number', __( 'Batch size', 'migrateplus' ), array( 1, 100 ), __( 'Records per request. Lower this if imports with lots of images time out.', 'migrateplus' ) ),
				'import_featured_image'  => array( 'checkbox', __( 'Featured images', 'migrateplus' ), __( 'Download featured images into the Media Library', 'migrateplus' ) ),
				'import_content_images'  => array( 'checkbox', __( 'Images in content', 'migrateplus' ), __( 'Download images found inside post content and rewrite their URLs', 'migrateplus' ) ),
				'create_terms'           => array( 'checkbox', __( 'Terms', 'migrateplus' ), __( 'Create categories, tags and terms that don\'t exist yet', 'migrateplus' ) ),
				'rewrite_links'          => array( 'checkbox', __( 'Links', 'migrateplus' ), __( 'Point links to the old site at this one', 'migrateplus' ), __( 'Migrate Plus exports only. Changes links in post content; images and files in wp-content still point at the old site unless "Images in content" copies them.', 'migrateplus' ) ),
				'import_comments'        => array( 'checkbox', __( 'Comments', 'migrateplus' ), __( 'Add the comments a Migrate Plus export carries to the imported posts', 'migrateplus' ), __( 'Importing the same file again updates the comments it added before instead of adding them twice.', 'migrateplus' ) ),
				'import_menus'           => array( 'checkbox', __( 'Menus', 'migrateplus' ), __( 'Import the navigation menus a Migrate Plus export carries', 'migrateplus' ), __( 'After all posts. A menu with the same slug is filled rather than copied, and theme locations are only set when this theme has them.', 'migrateplus' ) ),
				'import_term_data'       => array( 'checkbox', __( 'Term data', 'migrateplus' ), __( 'Import the terms a Migrate Plus export carries, with their data', 'migrateplus' ), __( 'Before the posts: descriptions, slugs, hierarchy, custom fields, ACF fields and category images, including terms no post uses. Terms already here are updated, never deleted.', 'migrateplus' ) ),
				'import_acf_definitions' => array( 'checkbox', __( 'ACF definitions', 'migrateplus' ), __( 'Import the ACF field groups, post types, taxonomies and options pages a Migrate Plus export carries', 'migrateplus' ), __( 'Before the posts. Matched by key; definitions this site has in code or acf-json are left alone.', 'migrateplus' ) ),
				'import_acf_options'     => array( 'checkbox', __( 'ACF options pages', 'migrateplus' ), __( 'Import the values of the ACF options pages a Migrate Plus export carries', 'migrateplus' ), __( 'After the posts. Rollback puts the previous values back.', 'migrateplus' ) ),
				'import_site_settings'   => array( 'checkbox', __( 'Site settings', 'migrateplus' ), __( 'Import the site settings a Migrate Plus export carries', 'migrateplus' ), __( 'After the posts: General, Reading, Discussion, Media and Permalinks settings, widgets, Customizer settings of the same theme, Additional CSS, global styles and Yoast SEO settings. Rollback puts the previous values back.', 'migrateplus' ) ),
				'import_orders'          => array( 'checkbox', __( 'Orders', 'migrateplus' ), __( 'Import the WooCommerce orders a Migrate Plus export carries', 'migrateplus' ), __( 'After the products and customers they name. No emails are sent and no stock is moved.', 'migrateplus' ) ),
				'import_redirects'       => array( 'checkbox', __( 'Redirects', 'migrateplus' ), __( 'Redirect old addresses to posts that moved', 'migrateplus' ), __( 'Migrate Plus exports only. When a post lands at another address than it had on the old site, the old one sends visitors to the new one once the domain points here.', 'migrateplus' ) ),
			),
			'migrateplus_export'       => array(
				'export_statuses'                => array( 'checkboxes', __( 'Status', 'migrateplus' ), self::export_statuses() ),
				'export_include_terms'           => array( 'checkbox', __( 'Terms', 'migrateplus' ), __( 'Categories, tags and terms', 'migrateplus' ) ),
				'export_include_term_data'       => array( 'checkbox', __( 'Term data', 'migrateplus' ), __( 'Term descriptions, custom fields, ACF fields and empty terms', 'migrateplus' ), __( 'The terms of the exported content types as their own section, so the importing site gets their data, not just their names.', 'migrateplus' ) ),
				'export_include_image'           => array( 'checkbox', __( 'Featured images', 'migrateplus' ), __( 'Featured image URLs', 'migrateplus' ) ),
				'export_include_meta'            => array( 'checkbox', __( 'Custom fields', 'migrateplus' ), __( 'Public post meta', 'migrateplus' ) ),
				'export_include_acf'             => array( 'checkbox', __( 'ACF fields', 'migrateplus' ), __( 'Advanced Custom Fields values', 'migrateplus' ) ),
				'export_include_woo'             => array( 'checkbox', __( 'WooCommerce', 'migrateplus' ), __( 'Product data (prices, stock, attributes, variations)', 'migrateplus' ) ),
				'export_include_comments'        => array( 'checkbox', __( 'Comments', 'migrateplus' ), __( 'Approved comments and replies', 'migrateplus' ), __( 'Pending, spam and trashed comments are left out.', 'migrateplus' ) ),
				'export_include_menus'           => array( 'checkbox', __( 'Menus', 'migrateplus' ), __( 'Navigation menus, their items and theme locations', 'migrateplus' ), __( 'Menu items point at posts and terms, so export the content they link to in the same file.', 'migrateplus' ) ),
				'export_include_yoast'           => array( 'checkbox', __( 'Yoast SEO', 'migrateplus' ), __( 'Yoast SEO titles, descriptions, keyphrases, social images and primary categories of posts and terms', 'migrateplus' ), __( 'Only when Yoast SEO is active.', 'migrateplus' ) ),
				'export_include_acf_definitions' => array( 'checkbox', __( 'ACF definitions', 'migrateplus' ), __( 'ACF field groups, post types, taxonomies and options pages', 'migrateplus' ), __( 'As ACF\'s own export writes them, so the importing site has the fields the content uses.', 'migrateplus' ) ),
				'export_include_acf_options'     => array( 'checkbox', __( 'ACF options pages', 'migrateplus' ), __( 'The values of ACF options pages', 'migrateplus' ) ),
				'export_include_site_settings'   => array( 'checkbox', __( 'Site settings', 'migrateplus' ), __( 'General, Reading, Discussion, Media and Permalinks settings, widgets, Customizer settings, Additional CSS, global styles and Yoast SEO settings', 'migrateplus' ), __( 'A chosen list: addresses, keys and plugins\' own data stay behind.', 'migrateplus' ) ),
				'export_include_orders'          => array( 'checkbox', __( 'Orders', 'migrateplus' ), __( 'WooCommerce orders with their items, refunds and notes', 'migrateplus' ), __( 'Orders hold customers\' names and addresses. Treat the file as confidential.', 'migrateplus' ) ),
				'export_gzip'                    => array( 'checkbox', __( 'Compression', 'migrateplus' ), __( 'Compress the file (.json.gz)', 'migrateplus' ), __( 'Much smaller, for a site whose upload limit is lower than the export. Migrate Plus imports it as it is.', 'migrateplus' ) ),
				'export_exclude_meta'            => array( 'textarea', __( 'Leave out these custom fields', 'migrateplus' ), null, __( 'One meta key per line. End a line with * to leave out every key that starts with it, e.g. rank_math_*. Keys starting with "_" are always left out.', 'migrateplus' ) ),
			),
			'migrateplus_access'       => array(
				'capability' => array( 'select', __( 'Who can run imports', 'migrateplus' ), self::access_levels(), __( 'Only administrators can change these settings.', 'migrateplus' ) ),
			),
			'migrateplus_connections'  => array(
				'request_timeout'     => array( 'number', __( 'Download timeout', 'migrateplus' ), array( 60, 300 ), __( 'Seconds to wait for each file Migrate Plus downloads.', 'migrateplus' ), __( 'seconds', 'migrateplus' ) ),
				'max_remote_size'     => array( 'number', __( 'Remote file size limit', 'migrateplus' ), array( 1, 2048 ), __( 'Only for custom sources added with the migrateplus_sources filter that read their file from a URL: the largest file they may download. Uploaded JSON files are limited by the server\'s upload size instead.', 'migrateplus' ), __( 'MB', 'migrateplus' ) ),
				'max_media_size'      => array( 'number', __( 'Image and media size limit', 'migrateplus' ), array( 0, 2048 ), __( 'Skip images and media files larger than this when importing. 0 means no limit.', 'migrateplus' ), __( 'MB', 'migrateplus' ) ),
				'allow_local_sources' => array( 'checkbox', __( 'Local sources', 'migrateplus' ), __( 'Allow downloads from localhost, private network addresses and non-standard ports', 'migrateplus' ), __( 'Turn this on to migrate from a local dev site (for example http://oldsite.test:8888). WordPress blocks these addresses by default; this only relaxes the rule for Migrate Plus\'s own requests.', 'migrateplus' ) ),
			),
			'migrateplus_housekeeping' => array(
				'job_retention_days'       => array( 'number', __( 'Keep import history', 'migrateplus' ), array( 0, 3650 ), __( 'Delete history entries older than this. 0 keeps them forever. Imported content is never touched, but a deleted entry can no longer be rolled back.', 'migrateplus' ), __( 'days', 'migrateplus' ) ),
				'remove_meta_on_uninstall' => array( 'checkbox', __( 'On uninstall', 'migrateplus' ), __( 'Also remove Migrate Plus\'s tracking data from imported posts', 'migrateplus' ), __( 'History and settings are always removed on uninstall. The tracking data is what lets a reinstall skip or update posts it already imported.', 'migrateplus' ) ),
			),
		);
		if ( ! MigratePlus_Util::zlib_available() ) {
			unset( $fields['migrateplus_export']['export_gzip'] ); // Nothing to choose: compressed exports need zlib.
		}
		if ( ! self::may_allow_local_sources() ) {
			unset( $fields['migrateplus_connections']['allow_local_sources'] );
		}

		foreach ( $fields as $section => $items ) {
			foreach ( $items as $key => $def ) {
				add_settings_field(
					'migrateplus_' . $key,
					$def[1],
					array( __CLASS__, 'field_' . $def[0] ),
					self::PAGE,
					$section,
					array(
						'key'       => $key,
						'title'     => $def[1],
						'type'      => $def[0],
						'label_for' => in_array( $def[0], array( 'checkbox', 'checkboxes' ), true ) ? null : 'migrateplus_' . $key,
						'choices'   => isset( $def[2] ) ? $def[2] : null,
						'help'      => isset( $def[3] ) ? $def[3] : '',
						'suffix'    => isset( $def[4] ) ? $def[4] : '',
					)
				);
			}
		}
	}

	public static function section_defaults() {
		echo '<p>' . esc_html__( 'Starting values for new imports. You can still change them on the mapping screen for each import.', 'migrateplus' ) . '</p>';
	}

	public static function section_downloads() {
		echo '<p>' . esc_html__( 'While importing, Migrate Plus downloads files from the old site: featured images, images in post content, Media Library files and WooCommerce download files. These limits apply to those downloads.', 'migrateplus' ) . '</p>';
	}

	public static function section_export() {
		echo '<p>' . esc_html__( 'Starting values for the Export screen and wp migrateplus export. You can still change them for each export.', 'migrateplus' ) . '</p>';
	}

	/**
	 * Statuses an export can include.
	 *
	 * @return array
	 */
	public static function export_statuses() {
		return array(
			'publish' => __( 'Published', 'migrateplus' ),
			'draft'   => __( 'Draft', 'migrateplus' ),
			'pending' => __( 'Pending review', 'migrateplus' ),
			'private' => __( 'Private', 'migrateplus' ),
			'future'  => __( 'Scheduled', 'migrateplus' ),
		);
	}

	/**
	 * @param array $args Field args.
	 */
	public static function field_select( $args ) {
		$value = self::get( $args['key'] );
		printf( '<select id="migrateplus_%1$s" name="%2$s[%1$s]">', esc_attr( $args['key'] ), esc_attr( self::OPTION ) );
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
			'<span class="migrateplus-number"><input type="number" id="migrateplus_%1$s" name="%2$s[%1$s]" value="%3$d" min="%4$d" max="%5$d"> %6$s</span>',
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
		// The switch the Import and Export screens use: the field title as its label, what it does underneath.
		$what = rtrim( (string) $args['choices'], '.' ) . '.';
		printf(
			'<div class="migrateplus-toggle"><input type="checkbox" class="migrateplus-switch" id="migrateplus_%1$s" name="%2$s[%1$s]" value="1"%3$s><label for="migrateplus_%1$s">%4$s<span class="migrateplus-help">%5$s</span></label></div>',
			esc_attr( $args['key'] ),
			esc_attr( self::OPTION ),
			checked( (int) self::get( $args['key'] ), 1, false ),
			esc_html( $args['title'] ),
			esc_html( trim( $what . ' ' . $args['help'] ) )
		);
	}

	/**
	 * @param array $args Field args.
	 */
	public static function field_checkboxes( $args ) {
		$value = (array) self::get( $args['key'] );
		printf( '<fieldset class="migrateplus-chips"><legend class="screen-reader-text">%s</legend>', esc_html( $args['title'] ) );
		foreach ( $args['choices'] as $option => $label ) {
			printf(
				'<label class="migrateplus-check-chip"><input type="checkbox" name="%2$s[%1$s][]" value="%3$s"%4$s><span>%5$s</span></label>',
				esc_attr( $args['key'] ),
				esc_attr( self::OPTION ),
				esc_attr( $option ),
				checked( in_array( $option, $value, true ), true, false ),
				esc_html( $label )
			);
		}
		echo '</fieldset>';
		self::help( $args );
	}

	/**
	 * @param array $args Field args.
	 */
	public static function field_textarea( $args ) {
		printf(
			'<textarea id="migrateplus_%1$s" name="%2$s[%1$s]" rows="4" cols="40" class="code">%3$s</textarea>',
			esc_attr( $args['key'] ),
			esc_attr( self::OPTION ),
			esc_textarea( (string) self::get( $args['key'] ) )
		);
		self::help( $args );
	}

	/**
	 * @param array $args Field args.
	 */
	protected static function help( $args ) {
		if ( $args['help'] ) {
			echo '<p class="migrateplus-field-help">' . esc_html( $args['help'] ) . '</p>';
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
		$out['force_status']   = isset( $input['force_status'] ) && in_array( $input['force_status'], array( 'publish', 'draft', 'pending', 'private' ), true ) ? $input['force_status'] : '';
		$out['duplicate']      = isset( $input['duplicate'] ) && in_array( $input['duplicate'], array( 'skip', 'update', 'create' ), true ) ? $input['duplicate'] : $defaults['duplicate'];
		// A field posted as an array would make array_key_exists() throw.
		$capability        = isset( $input['capability'] ) && is_scalar( $input['capability'] ) ? (string) $input['capability'] : '';
		$out['capability'] = array_key_exists( $capability, self::access_levels() ) ? $capability : $defaults['capability'];

		$ranges = array(
			'batch_size'         => array( 1, 100 ),
			'request_timeout'    => array( 60, 300 ),
			'max_remote_size'    => array( 1, 2048 ),
			'max_media_size'     => array( 0, 2048 ),
			'job_retention_days' => array( 0, 3650 ),
		);
		foreach ( $ranges as $key => $range ) {
			$value       = isset( $input[ $key ] ) && '' !== $input[ $key ] ? (int) $input[ $key ] : $defaults[ $key ];
			$out[ $key ] = max( $range[0], min( $range[1], $value ) );
		}

		foreach ( array( 'import_featured_image', 'import_content_images', 'create_terms', 'rewrite_links', 'import_comments', 'import_menus', 'import_term_data', 'import_acf_definitions', 'import_acf_options', 'import_site_settings', 'import_orders', 'import_redirects', 'export_include_terms', 'export_include_term_data', 'export_include_image', 'export_include_meta', 'export_include_acf', 'export_include_woo', 'export_include_comments', 'export_include_menus', 'export_include_yoast', 'export_include_acf_definitions', 'export_include_acf_options', 'export_include_site_settings', 'export_include_orders', 'export_gzip', 'allow_local_sources', 'remove_meta_on_uninstall' ) as $key ) {
			$out[ $key ] = empty( $input[ $key ] ) ? 0 : 1;
		}
		if ( ! self::may_allow_local_sources() ) {
			// Kept as it was: see may_allow_local_sources().
			$saved                      = (array) get_option( self::OPTION, array() );
			$out['allow_local_sources'] = empty( $saved['allow_local_sources'] ) ? 0 : 1;
		}

		$statuses               = isset( $input['export_statuses'] ) ? array_map( 'sanitize_key', (array) $input['export_statuses'] ) : array();
		$statuses               = array_values( array_intersect( $statuses, array_keys( self::export_statuses() ) ) );
		$out['export_statuses'] = $statuses ? $statuses : $defaults['export_statuses'];

		$out['export_exclude_meta'] = isset( $input['export_exclude_meta'] ) ? implode( "\n", MigratePlus_Exporter::meta_patterns( $input['export_exclude_meta'] ) ) : '';

		self::flush_cache();
		return $out;
	}

	/**
	 * Whether the current user may change "Local sources". On a network only a super
	 * admin may: it lets imports reach the private network the server is on, through
	 * media downloads, which a subsite's administrator must not be able to open up.
	 *
	 * @return bool
	 */
	protected static function may_allow_local_sources() {
		return ! is_multisite() || is_super_admin();
	}

	/**
	 * Render the settings page.
	 */
	public static function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Only administrators can change Migrate Plus settings.', 'migrateplus' ) );
		}
		?>
		<div class="wrap migrateplus-wrap migrateplus-settings">
			<h1 class="wp-heading-inline"><?php esc_html_e( 'Settings', 'migrateplus' ); ?></h1>
			<hr class="wp-header-end">
			<p class="migrateplus-intro"><?php esc_html_e( 'Starting values for imports and exports, who can run them, and limits for the files Migrate Plus downloads. Each import and export can still change its own options.', 'migrateplus' ); ?></p>
			<?php settings_errors(); ?>
			<div class="migrateplus-settings-layout migrateplus-app">
				<form action="options.php" method="post">
					<?php
					settings_fields( self::GROUP );
					self::render_sections();
					?>
					<div class="migrateplus-actionbar">
						<span class="migrateplus-muted"><?php esc_html_e( 'Applies to imports and exports started after you save.', 'migrateplus' ); ?></span>
						<?php submit_button( null, 'primary migrateplus-btn-lg', 'submit', false ); ?>
					</div>
				</form>
				<aside class="migrateplus-panel migrateplus-status">
					<h2><?php esc_html_e( 'Server check', 'migrateplus' ); ?></h2>
					<?php self::render_status(); ?>
				</aside>
			</div>
		</div>
		<?php
	}

	/**
	 * Each settings section as a panel, like the other screens, instead of
	 * do_settings_sections()'s one long form table. Fields keep the Settings API
	 * callbacks and names, so what is saved is unchanged.
	 */
	protected static function render_sections() {
		global $wp_settings_sections, $wp_settings_fields;
		foreach ( isset( $wp_settings_sections[ self::PAGE ] ) ? (array) $wp_settings_sections[ self::PAGE ] : array() as $section ) {
			$fields = isset( $wp_settings_fields[ self::PAGE ][ $section['id'] ] ) ? (array) $wp_settings_fields[ self::PAGE ][ $section['id'] ] : array();
			echo '<section class="migrateplus-panel migrateplus-settings-section">';
			echo '<div class="migrateplus-section-head"><h2>' . esc_html( $section['title'] ) . '</h2>';
			if ( $section['callback'] ) {
				call_user_func( $section['callback'], $section );
			}
			echo '</div><div class="migrateplus-fields">';
			foreach ( $fields as $field ) {
				// A toggle carries its own label; every other control gets the field title above it.
				if ( 'checkbox' === $field['args']['type'] ) {
					call_user_func( $field['callback'], $field['args'] );
					continue;
				}
				echo '<div class="migrateplus-field">';
				if ( ! empty( $field['args']['label_for'] ) ) {
					printf( '<label class="migrateplus-field-label" for="%s">%s</label>', esc_attr( $field['args']['label_for'] ), esc_html( $field['title'] ) );
				} else {
					printf( '<span class="migrateplus-field-label">%s</span>', esc_html( $field['title'] ) );
				}
				call_user_func( $field['callback'], $field['args'] );
				echo '</div>';
			}
			echo '</div></section>';
		}
	}

	/**
	 * A few environment facts that explain most failed imports.
	 */
	protected static function render_status() {
		$dir      = MigratePlus_Util::storage_dir();
		$writable = wp_is_writable( is_dir( $dir ) ? $dir : dirname( $dir ) );
		$time     = (int) ini_get( 'max_execution_time' );
		$memory   = ini_get( 'memory_limit' );
		$zlib     = MigratePlus_Util::zlib_available();
		$rows     = array(
			array(
				__( 'Upload folder', 'migrateplus' ),
				$writable ? __( 'Writable', 'migrateplus' ) : __( 'Not writable', 'migrateplus' ),
				$writable,
			),
			array(
				__( 'Max upload size', 'migrateplus' ),
				size_format( wp_max_upload_size() ),
				true,
				$zlib ? __( 'Too small for an export? Export it compressed (.json.gz).', 'migrateplus' ) : '',
			),
			array(
				__( 'PHP time limit', 'migrateplus' ),
				/* translators: %d: seconds */
				0 === $time ? __( 'None', 'migrateplus' ) : sprintf( __( '%d seconds', 'migrateplus' ), $time ),
				0 === $time || $time >= 30,
			),
			array(
				__( 'PHP memory limit', 'migrateplus' ),
				'-1' === (string) $memory ? __( 'Unlimited', 'migrateplus' ) : $memory,
				'-1' === (string) $memory || wp_convert_hr_to_bytes( $memory ) >= 128 * MB_IN_BYTES,
			),
			array(
				__( 'Image processing', 'migrateplus' ),
				wp_image_editor_supports() ? __( 'Available', 'migrateplus' ) : __( 'Missing (GD or Imagick)', 'migrateplus' ),
				wp_image_editor_supports(),
			),
			array(
				__( 'Compression (zlib)', 'migrateplus' ),
				$zlib ? __( 'Available', 'migrateplus' ) : __( 'Missing: no .json.gz exports or imports', 'migrateplus' ),
				$zlib,
			),
		);

		$public = self::storage_public();
		if ( null !== $public ) {
			$rows[] = array(
				__( 'Private files', 'migrateplus' ),
				$public ? __( 'Readable from the web', 'migrateplus' ) : __( 'Not readable from the web', 'migrateplus' ),
				! $public,
				/* translators: %s: folder path */
				$public ? sprintf( __( 'Exports and rollback files in %s can be downloaded by anyone who guesses their name. Apache obeys the .htaccess file there; nginx, IIS and Caddy ignore it and need a rule in the server configuration that denies access to the folder.', 'migrateplus' ), $dir ) : '',
			);
		}
		echo '<table class="migrateplus-status-table"><tbody>';
		foreach ( $rows as $row ) {
			printf(
				'<tr><th scope="row">%s</th><td><span class="migrateplus-dot %s" aria-hidden="true"></span>%s%s</td></tr>',
				esc_html( $row[0] ),
				$row[2] ? 'is-ok' : 'is-warn',
				esc_html( $row[1] ),
				empty( $row[3] ) ? '' : '<br><span class="description">' . esc_html( $row[3] ) . '</span>'
			);
		}
		echo '</tbody></table>';
		echo '<p class="description">' . esc_html__( 'Large files or slow servers? Run imports from the command line instead:', 'migrateplus' ) . '</p>';
		echo '<p><code>wp migrateplus import json --file=export.json</code></p>';
	}

	/**
	 * Whether files in the storage folder can be fetched over HTTP. It is only
	 * protected by .htaccess, which nothing but Apache reads. Checked by asking
	 * for a throwaway file, at most once an hour, as it costs a request to this site.
	 *
	 * @return bool|null Null when the check could not tell (no folder, or the site can't reach itself).
	 */
	public static function storage_public() {
		$cached = get_transient( self::PROBE_TRANSIENT );
		if ( false !== $cached ) {
			return 'unknown' === $cached ? null : 'public' === $cached;
		}
		$result = 'unknown';
		$dir    = MigratePlus_Util::storage_dir();
		$name   = 'probe-' . wp_generate_password( 12, false ) . '.txt';
		$token  = wp_generate_password( 32, false );
		// No folder yet means nothing in it to protect; it is checked once something creates it.
		if ( is_dir( $dir ) && wp_is_writable( $dir ) && false !== file_put_contents( $dir . $name, $token ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions
			$uploads  = wp_upload_dir( null, false );
			$response = wp_remote_get(
				trailingslashit( $uploads['baseurl'] ) . 'migrateplus/' . $name,
				array(
					'timeout'   => 5,
					'sslverify' => false, // Only this site, which often has a self-signed certificate on staging.
				)
			);
			wp_delete_file( $dir . $name );
			if ( ! is_wp_error( $response ) ) {
				$result = 200 === wp_remote_retrieve_response_code( $response ) && wp_remote_retrieve_body( $response ) === $token ? 'public' : 'private';
			}
		}
		set_transient( self::PROBE_TRANSIENT, $result, HOUR_IN_SECONDS );
		return 'unknown' === $result ? null : 'public' === $result;
	}

	/**
	 * Daily cleanup: imports never started, then history entries older than the retention.
	 */
	public static function cleanup() {
		foreach ( MigratePlus_Job::all() as $job ) {
			// A preview nobody went on with: its upload and caches copy the whole file.
			if ( 'draft' === $job->data['status'] && (int) $job->data['updated'] < time() - DAY_IN_SECONDS ) {
				$job->delete();
			}
		}
		$days = (int) self::get( 'job_retention_days' );
		if ( $days < 1 ) {
			return;
		}
		$cutoff = time() - $days * DAY_IN_SECONDS;
		foreach ( MigratePlus_Job::all() as $job ) {
			// Not one still running, unless its requests died (see MigratePlus_Job::is_stalled()).
			if ( ( 'running' !== $job->data['status'] || $job->is_stalled() ) && (int) $job->data['updated'] < $cutoff ) {
				$job->delete();
			}
		}
		foreach ( MigratePlus_Exporter::history() as $entry ) {
			if ( (int) $entry['created'] < $cutoff ) {
				MigratePlus_Exporter::forget( $entry['id'] );
			}
		}
		foreach ( MigratePlus_Replace::history() as $entry ) {
			if ( (int) $entry['created'] < $cutoff && 'rolling_back' !== $entry['status'] ) {
				MigratePlus_Replace::forget( $entry['id'] );
			}
		}
	}
}
