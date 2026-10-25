<?php
/**
 * Site settings in MigratePlus exports.
 *
 * @package MigratePlus
 */

defined( 'ABSPATH' ) || exit;

/**
 * Exports the settings a site is set up with (General, Reading, Discussion,
 * Media and Permalinks, the widgets, the theme's Customizer settings, Additional
 * CSS and the block editor's global styles, plus Yoast SEO's settings when it is
 * active) and applies them after the records of an import, since the front
 * page, the posts page and the logo point at content the file carries.
 *
 * It is a chosen list, not the whole options table: addresses, keys, salts and
 * every plugin's own bookkeeping stay where they are. Developers add options
 * with the migrateplus_site_options filter. Every value the import replaces is
 * kept in the job and put back by a rollback.
 */
class MigratePlus_Site {

	/**
	 * The sections a site's settings fall into, as the Settings menu divides them,
	 * each with the options it holds. The widgets, theme and Yoast sections hold
	 * no plain options: they are gathered by their own methods.
	 *
	 * @var array[] key => { label, help, options }
	 */
	const SECTIONS = array(
		'general'    => array(
			'label'   => 'General',
			'help'    => 'Site title, tagline, language, time zone, date and time formats, site icon, privacy page',
			'options' => array( 'blogname', 'blogdescription', 'timezone_string', 'gmt_offset', 'date_format', 'time_format', 'start_of_week', 'WPLANG', 'site_icon', 'wp_page_for_privacy_policy' ),
		),
		'writing'    => array(
			'label'   => 'Writing',
			'help'    => 'Default category and post format',
			'options' => array( 'default_category', 'default_post_format' ),
		),
		'reading'    => array(
			'label'   => 'Reading',
			'help'    => 'Front page and posts page, posts per page and per feed, search engine visibility',
			'options' => array( 'show_on_front', 'page_on_front', 'page_for_posts', 'posts_per_page', 'posts_per_rss', 'rss_use_excerpt', 'blog_public' ),
		),
		'discussion' => array(
			'label'   => 'Discussion',
			'help'    => 'Comment and pingback defaults, moderation rules and keys, threading, avatars',
			'options' => array( 'default_comment_status', 'default_ping_status', 'default_pingback_flag', 'comment_registration', 'require_name_email', 'comments_notify', 'moderation_notify', 'comment_moderation', 'comment_previously_approved', 'comment_max_links', 'moderation_keys', 'disallowed_keys', 'thread_comments', 'thread_comments_depth', 'page_comments', 'comments_per_page', 'default_comments_page', 'comment_order', 'close_comments_for_old_posts', 'close_comments_days_old', 'show_comments_cookies_opt_in', 'show_avatars', 'avatar_rating', 'avatar_default', 'use_smilies' ),
		),
		'media'      => array(
			'label'   => 'Media',
			'help'    => 'Image sizes and the uploads folder layout',
			'options' => array( 'thumbnail_size_w', 'thumbnail_size_h', 'thumbnail_crop', 'medium_size_w', 'medium_size_h', 'medium_large_size_w', 'medium_large_size_h', 'large_size_w', 'large_size_h', 'image_default_link_type', 'image_default_size', 'image_default_align', 'uploads_use_yearmonth_folders' ),
		),
		'permalinks' => array(
			'label'   => 'Permalinks',
			'help'    => 'Permalink structure and the category and tag bases',
			'options' => array( 'permalink_structure', 'category_base', 'tag_base' ),
		),
		'widgets'    => array(
			'label'   => 'Widgets',
			'help'    => 'Every widget area and the widgets in it',
			'options' => array(),
		),
		'theme'      => array(
			'label'   => 'Theme',
			'help'    => 'Customizer settings (applied to the same theme only), Additional CSS and the block editor\'s global styles',
			'options' => array(),
		),
		'yoast'      => array(
			'label'   => 'Yoast SEO',
			'help'    => 'Yoast SEO\'s settings, without the old site\'s keys and licences',
			'options' => array(),
		),
	);

	/**
	 * Options that name a page, exported as a reference and looked up on import.
	 *
	 * @var string[]
	 */
	const PAGE_OPTIONS = array( 'page_on_front', 'page_for_posts', 'wp_page_for_privacy_policy' );

	/**
	 * Theme mods that belong to this install rather than the design, or that
	 * other sections carry.
	 *
	 * @var string[]
	 */
	const SKIP_MODS = array( 'nav_menu_locations', 'sidebars_widgets', 'custom_css_post_id', 'header_image_data' );

	/**
	 * The sections this site can export: SECTIONS, with Yoast SEO only when it is
	 * active, and the options the migrateplus_site_options filter adds under General.
	 *
	 * @return array[] key => { label, help, options }
	 */
	public static function sections() {
		$sections = self::SECTIONS;
		foreach ( $sections as $key => $section ) {
			$sections[ $key ]['label'] = __( $section['label'], 'migrateplus' ); // phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralText -- the labels are literal in SECTIONS.
			$sections[ $key ]['help']  = __( $section['help'], 'migrateplus' ); // phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralText
		}
		if ( ! MigratePlus_Yoast::active() ) {
			unset( $sections['yoast'] );
		}
		$extra                          = apply_filters( 'migrateplus_site_options', array() );
		$sections['general']['options'] = array_values( array_unique( array_merge( $sections['general']['options'], array_filter( array_map( 'strval', (array) $extra ) ) ) ) );
		return $sections;
	}

	/**
	 * The section keys the args name, or every section when they do not say.
	 * A list that names none picks none: unticking every section must not
	 * export them all.
	 *
	 * @param array $args Export args (site_sections).
	 * @return string[]
	 */
	public static function picked_sections( array $args ) {
		$all = array_keys( self::sections() );
		if ( ! isset( $args['site_sections'] ) ) {
			return $all;
		}
		return array_values( array_intersect( $all, (array) $args['site_sections'] ) );
	}

	/**
	 * The sections for the Export screen's list.
	 *
	 * @return array[] { key, label, help }
	 */
	public static function sections_for_js() {
		$out = array();
		foreach ( self::sections() as $key => $section ) {
			$out[] = array(
				'key'   => $key,
				'label' => $section['label'],
				'help'  => $section['help'],
			);
		}
		return $out;
	}

	/**
	 * The site's settings as one portable record: the sections the args pick.
	 *
	 * @param array $args Export args (site_sections).
	 * @return array { sections, options, widgets, theme, plugins }, empty when the person may not read them or no section is picked.
	 */
	public static function export( array $args ) {
		$picked = self::picked_sections( $args );
		if ( ! self::user_can() || ! $picked ) {
			return array();
		}
		$sections = self::sections();
		$options  = array();
		foreach ( $picked as $key ) {
			foreach ( $sections[ $key ]['options'] as $name ) {
				if ( in_array( $name, self::PAGE_OPTIONS, true ) ) {
					$id               = (int) get_option( $name );
					$options[ $name ] = $id && get_post( $id ) ? MigratePlus_Exporter::post_ref( $id ) : null;
				} elseif ( 'default_category' === $name ) {
					$default = get_term( (int) get_option( 'default_category' ), 'category' );
					if ( $default instanceof WP_Term ) {
						$options[ $name ] = MigratePlus_Exporter::term_path( $default, true );
					}
				} elseif ( 'site_icon' === $name ) {
					$icon             = (int) get_option( 'site_icon' );
					$options[ $name ] = $icon ? (string) wp_get_attachment_url( $icon ) : '';
				} else {
					$value = get_option( $name );
					if ( false !== $value ) {
						$options[ $name ] = $value;
					}
				}
			}
		}

		$theme = array();
		if ( in_array( 'theme', $picked, true ) ) {
			$mods = array();
			foreach ( (array) get_theme_mods() as $key => $value ) {
				if ( ! in_array( $key, self::SKIP_MODS, true ) && is_string( $key ) && 0 !== strpos( $key, '_' ) ) {
					$mods[ $key ] = $value;
				}
			}
			if ( ! empty( $mods['custom_logo'] ) ) {
				$mods['custom_logo'] = (string) wp_get_attachment_url( (int) $mods['custom_logo'] ); // Downloaded again on import.
			}
			$theme = array(
				'stylesheet'    => get_stylesheet(),
				'name'          => wp_get_theme()->get( 'Name' ),
				'mods'          => $mods,
				'custom_css'    => (string) wp_get_custom_css(),
				'global_styles' => self::global_styles(),
			);
		}

		$plugins = array();
		if ( in_array( 'yoast', $picked, true ) && MigratePlus_Yoast::active() ) {
			$plugins['yoast'] = MigratePlus_Yoast::export_settings();
		}

		$record = array(
			'sections' => $picked,
			'options'  => $options,
			'widgets'  => in_array( 'widgets', $picked, true ) ? self::widgets() : array(),
			'theme'    => $theme,
			'plugins'  => $plugins,
		);
		return apply_filters( 'migrateplus_export_site', $record, $args );
	}

	/**
	 * One record, when the person may read it and the args pick a section.
	 *
	 * @param array $args Export args.
	 * @return int
	 */
	public static function count( array $args ) {
		return self::user_can() && self::picked_sections( $args ) ? 1 : 0;
	}

	/**
	 * Every option name that travels as it is, across all sections.
	 *
	 * @return string[]
	 */
	public static function option_names() {
		$names = array();
		foreach ( self::sections() as $section ) {
			$names = array_merge( $names, $section['options'] );
		}
		return array_values( array_unique( $names ) );
	}

	/**
	 * The widget areas and the widgets in them, with the slug of each menu a
	 * Navigation Menu widget shows: its id here means nothing on the new site.
	 *
	 * @return array { sidebars: { area => [ widget ids ] }, instances: { widget_xxx option => value }, menus: { menu id => slug } }
	 */
	protected static function widgets() {
		$sidebars  = array();
		$instances = array();
		foreach ( (array) get_option( 'sidebars_widgets', array() ) as $area => $ids ) {
			if ( 'array_version' === $area || ! is_array( $ids ) ) {
				continue;
			}
			$sidebars[ $area ] = array_values( array_map( 'strval', $ids ) );
			foreach ( $ids as $id ) {
				$base = preg_replace( '/-\d+$/', '', (string) $id );
				if ( '' !== $base && ! isset( $instances[ 'widget_' . $base ] ) && false !== get_option( 'widget_' . $base ) ) {
					$instances[ 'widget_' . $base ] = get_option( 'widget_' . $base );
				}
			}
		}
		$menus = array();
		foreach ( isset( $instances['widget_nav_menu'] ) && is_array( $instances['widget_nav_menu'] ) ? $instances['widget_nav_menu'] : array() as $instance ) {
			$menu = is_array( $instance ) && ! empty( $instance['nav_menu'] ) ? get_term( (int) $instance['nav_menu'], 'nav_menu' ) : null;
			if ( $menu instanceof WP_Term ) {
				$menus[ (string) $menu->term_id ] = $menu->slug;
			}
		}
		return array(
			'sidebars'  => $sidebars,
			'instances' => $instances,
			'menus'     => $menus,
		);
	}

	/**
	 * The user's global styles for the active theme (Styles in the Site Editor).
	 *
	 * @return string JSON, or '' when nothing was customised.
	 */
	protected static function global_styles() {
		if ( ! class_exists( 'WP_Theme_JSON_Resolver' ) || ! method_exists( 'WP_Theme_JSON_Resolver', 'get_user_global_styles_post_id' ) ) {
			return '';
		}
		$id = (int) WP_Theme_JSON_Resolver::get_user_global_styles_post_id();
		return $id ? (string) get_post_field( 'post_content', $id ) : '';
	}

	/**
	 * Whether a job still has settings to import. They come after its records.
	 *
	 * @param MigratePlus_Job $job Job.
	 * @return bool
	 */
	public static function pending( MigratePlus_Job $job ) {
		return ! empty( $job->data['settings']['site_settings_file'] ) && ! empty( $job->data['options']['import_site_settings'] );
	}

	/**
	 * The settings a job's file carries, cached by the JSON source as a list of one.
	 *
	 * @param MigratePlus_Job $job Job.
	 * @return array|WP_Error
	 */
	protected static function load( MigratePlus_Job $job ) {
		$file = isset( $job->data['settings']['site_settings_file'] ) ? (string) $job->data['settings']['site_settings_file'] : '';
		$data = '' !== $file && is_readable( $file ) ? json_decode( (string) file_get_contents( $file ), true ) : null; // phpcs:ignore WordPress.WP.AlternativeFunctions
		if ( is_array( $data ) && isset( $data[0] ) && is_array( $data[0] ) ) {
			return $data[0];
		}
		return new WP_Error( 'migrateplus_site_cache', __( 'The cached site settings are missing. Start a new import.', 'migrateplus' ) );
	}

	/**
	 * The settings stage: everything in one request.
	 *
	 * @param MigratePlus_Job $job Job (side_prev updated in place).
	 * @return array { done: true, results: array[] }
	 */
	public static function import_batch( MigratePlus_Job $job ) {
		$record = self::load( $job );
		if ( is_wp_error( $record ) ) {
			return array(
				'done'    => true,
				'results' => array( self::result( 'failed', $record->get_error_message() ) ),
			);
		}
		if ( ! self::user_can() ) {
			return array(
				'done'    => true,
				'results' => array( self::result( 'skipped', __( 'Site settings were not imported: you are not allowed to manage options on this site.', 'migrateplus' ), true ) ),
			);
		}
		$opts = array_merge( MigratePlus_Job::default_options(), (array) $job->data['options'] );
		return array(
			'done'    => true,
			'results' => array( self::import( $record, $job, $opts ) ),
		);
	}

	/**
	 * Apply exported settings to this site.
	 *
	 * @param array           $record Settings as exported.
	 * @param MigratePlus_Job $job    Job.
	 * @param array           $opts   Job options.
	 * @return array Result.
	 */
	protected static function import( array $record, MigratePlus_Job $job, array $opts ) {
		$dry      = ! empty( $opts['dry_run'] );
		$warnings = array();
		$counts   = array(
			'options' => 0,
			'widgets' => 0,
			'theme'   => 0,
			'plugins' => 0,
		);
		$options  = isset( $record['options'] ) && is_array( $record['options'] ) ? $record['options'] : array();
		$allowed  = self::option_names();
		foreach ( $options as $name => $value ) {
			$name = (string) $name;
			if ( in_array( $name, self::PAGE_OPTIONS, true ) ) {
				$value = self::page_id( $value, $job, $warnings );
			} elseif ( 'default_category' === $name ) {
				$ids   = MigratePlus_Importer::resolve_terms( 'category', array( MigratePlus_Util::stringify( $value ) ), ! empty( $opts['create_terms'] ), $warnings, $job );
				$value = $ids ? (int) $ids[0] : null;
			} elseif ( 'site_icon' === $name ) {
				$value = self::settings_image( $value, $job, $opts, $dry, $warnings, __( 'The site icon', 'migrateplus' ) );
			} elseif ( ! in_array( $name, $allowed, true ) ) {
				continue;
			}
			if ( null === $value ) {
				continue;
			}
			// A staging site's export says "discourage search engines": imported unnoticed, it hides the live site.
			if ( 'blog_public' === $name && (string) (int) get_option( 'blog_public' ) !== (string) (int) $value ) {
				$warnings[] = (int) $value
					? __( 'Search engine visibility changes: search engines are now asked to index this site.', 'migrateplus' )
					: __( 'Search engine visibility changes: search engines are now asked not to index this site. Check Settings > Reading if this is the live site.', 'migrateplus' );
			}
			++$counts['options'];
			if ( ! $dry ) {
				self::set_option( $name, $value, $job );
			}
		}

		$widgets = isset( $record['widgets'] ) && is_array( $record['widgets'] ) ? $record['widgets'] : array();
		if ( ! empty( $widgets['sidebars'] ) && is_array( $widgets['sidebars'] ) ) {
			$counts['widgets'] = self::import_widgets( $widgets, $job, $dry, $warnings );
		}

		$theme = isset( $record['theme'] ) && is_array( $record['theme'] ) ? $record['theme'] : array();
		if ( $theme ) {
			$counts['theme'] = self::import_theme( $theme, $job, $dry, $opts, $warnings );
		}

		$plugins = isset( $record['plugins'] ) && is_array( $record['plugins'] ) ? $record['plugins'] : array();
		if ( ! empty( $plugins['yoast'] ) && is_array( $plugins['yoast'] ) ) {
			if ( ! MigratePlus_Yoast::active() ) {
				$warnings[] = __( 'The file carries Yoast SEO settings, but Yoast SEO is not active here, so they were not imported.', 'migrateplus' );
			} elseif ( $dry ) {
				$counts['plugins'] = count( $plugins['yoast'] );
			} else {
				if ( ! isset( $job->data['side_prev']['yoast'] ) ) {
					$job->data['side_prev']['yoast'] = array();
				}
				// The job and warnings let it download the logo and social images.
				$counts['plugins'] = MigratePlus_Yoast::import_settings( $plugins['yoast'], $job->data['side_prev']['yoast'], $job, $warnings );
			}
		}

		$parts = array_filter(
			array(
				/* translators: %d: number of settings */
				$counts['options'] ? sprintf( _n( '%d setting', '%d settings', $counts['options'], 'migrateplus' ), $counts['options'] ) : '',
				/* translators: %d: number of widget areas */
				$counts['widgets'] ? sprintf( _n( '%d widget area', '%d widget areas', $counts['widgets'], 'migrateplus' ), $counts['widgets'] ) : '',
				/* translators: %d: number of theme settings */
				$counts['theme'] ? sprintf( _n( '%d theme setting', '%d theme settings', $counts['theme'], 'migrateplus' ), $counts['theme'] ) : '',
				/* translators: %d: number of Yoast options */
				$counts['plugins'] ? sprintf( _n( '%d Yoast SEO option', '%d Yoast SEO options', $counts['plugins'], 'migrateplus' ), $counts['plugins'] ) : '',
			)
		);
		/* translators: 1: Applied/Would apply, 2: what */
		$message = sprintf( __( '%1$s %2$s.', 'migrateplus' ), $dry ? __( 'Would apply', 'migrateplus' ) : __( 'Applied', 'migrateplus' ), $parts ? implode( ', ', $parts ) : __( 'no site settings', 'migrateplus' ) );
		if ( $warnings ) {
			$message .= ' ' . __( 'Warnings:', 'migrateplus' ) . ' ' . implode( ' ', array_unique( $warnings ) );
		}
		return self::result( 'updated', $message, ! empty( $warnings ) );
	}

	/**
	 * Set an option, keeping what it was for rollback.
	 *
	 * @param string          $name  Option.
	 * @param mixed           $value Value.
	 * @param MigratePlus_Job $job   Job (side_prev updated in place).
	 */
	protected static function set_option( $name, $value, MigratePlus_Job $job ) {
		if ( ! isset( $job->data['side_prev']['options'] ) || ! array_key_exists( $name, (array) $job->data['side_prev']['options'] ) ) {
			$current                                    = get_option( $name );
			$job->data['side_prev']['options'][ $name ] = false === $current ? array( 'none' => true ) : array( 'value' => $current );
		}
		self::write_option( $name, $value );
	}

	/**
	 * Save an option. The permalink ones go through WP_Rewrite, which saves the
	 * option and rebuilds its own state: written with update_option(), rules
	 * flushed later in the same request would still be built for the old
	 * structure.
	 *
	 * @param string $name  Option.
	 * @param mixed  $value Value.
	 * @return bool Whether it was a permalink option.
	 */
	protected static function write_option( $name, $value ) {
		global $wp_rewrite;
		$setters = array(
			'permalink_structure' => 'set_permalink_structure',
			'category_base'       => 'set_category_base',
			'tag_base'            => 'set_tag_base',
		);
		if ( isset( $setters[ $name ] ) && $wp_rewrite instanceof WP_Rewrite ) {
			$wp_rewrite->{$setters[ $name ]}( (string) $value );
			self::refresh_permastructs();
			return true;
		}
		update_option( $name, $value );
		return false;
	}

	/**
	 * Register the taxonomies' and post types' permalink shapes again, as init
	 * did with the old settings: the category and tag bases are read once, and
	 * a site that had no permalink structure registered none at all. Adding
	 * replaces what is there; their remove_rewrite_rules() is not called, as a
	 * post type's also drops its meta capabilities for the rest of the request.
	 */
	protected static function refresh_permastructs() {
		global $wp_rewrite;
		$bases = array(
			'category' => array( 'category_base', 'category' ),
			'post_tag' => array( 'tag_base', 'tag' ),
		);
		foreach ( get_taxonomies( array(), 'objects' ) as $taxonomy ) {
			if ( false === $taxonomy->rewrite ) {
				continue;
			}
			// Registered under plain links, the rewrite args never got their defaults: as WP_Taxonomy::set_props() gives them.
			$taxonomy->rewrite = wp_parse_args(
				is_array( $taxonomy->rewrite ) ? $taxonomy->rewrite : array(),
				array(
					'with_front'   => true,
					'hierarchical' => false,
					'ep_mask'      => EP_NONE,
				)
			);
			if ( empty( $taxonomy->rewrite['slug'] ) ) {
				$taxonomy->rewrite['slug'] = sanitize_title_with_dashes( $taxonomy->name );
			}
			if ( isset( $bases[ $taxonomy->name ] ) ) {
				// As create_initial_taxonomies() works them out.
				$base                            = (string) get_option( $bases[ $taxonomy->name ][0] );
				$taxonomy->rewrite['slug']       = '' !== $base ? $base : $bases[ $taxonomy->name ][1];
				$taxonomy->rewrite['with_front'] = '' === $base || $wp_rewrite->using_index_permalinks();
			}
			$taxonomy->add_rewrite_rules();
		}
		foreach ( get_post_types( array(), 'objects' ) as $type ) {
			if ( false === $type->rewrite ) {
				continue;
			}
			// As WP_Post_Type::set_props() fills them in when permalinks are on.
			$rewrite  = is_array( $type->rewrite ) ? $type->rewrite : array();
			$rewrite += array(
				'slug'       => $type->name,
				'with_front' => true,
				'pages'      => true,
				'ep_mask'    => EP_PERMALINK,
			);
			if ( empty( $rewrite['slug'] ) ) {
				$rewrite['slug'] = $type->name;
			}
			if ( ! isset( $rewrite['feeds'] ) || ! $type->has_archive ) {
				$rewrite['feeds'] = (bool) $type->has_archive;
			}
			$type->rewrite = $rewrite;
			$type->add_rewrite_rules();
		}
	}

	/**
	 * This site's id for a page the old site's settings named.
	 *
	 * @param mixed           $ref      { id, slug, title, type } or null.
	 * @param MigratePlus_Job $job      Job.
	 * @param string[]        $warnings Warnings (by reference).
	 * @return int|null 0 to clear the setting, null to leave it alone.
	 */
	protected static function page_id( $ref, MigratePlus_Job $job, array &$warnings ) {
		if ( null === $ref || '' === $ref ) {
			return 0;
		}
		if ( ! is_array( $ref ) ) {
			return null;
		}
		$id = isset( $ref['id'] ) ? MigratePlus_Importer::find_by_uid( MigratePlus_Importer::uid( $job, MigratePlus_Util::stringify( $ref['id'] ) ), array_keys( get_post_types() ), $job->data['id'] ) : 0;
		if ( ! $id && ! empty( $ref['slug'] ) ) {
			$type = isset( $ref['type'] ) && post_type_exists( sanitize_key( $ref['type'] ) ) ? sanitize_key( $ref['type'] ) : 'page';
			$hit  = get_posts(
				array(
					'name'             => sanitize_title( MigratePlus_Util::stringify( $ref['slug'] ) ),
					'post_type'        => $type,
					'post_status'      => 'any',
					'posts_per_page'   => 1,
					'fields'           => 'ids',
					'suppress_filters' => true,
				)
			);
			$id   = $hit ? (int) $hit[0] : 0;
		}
		if ( ! $id ) {
			/* translators: %s: page title */
			$warnings[] = sprintf( __( 'Page "%s" is not on this site, so the setting that points at it was left alone.', 'migrateplus' ), isset( $ref['title'] ) ? MigratePlus_Util::stringify( $ref['title'] ) : '' );
			return null;
		}
		return $id;
	}

	/**
	 * Download an image the settings name (site icon, logo).
	 *
	 * @param mixed           $url      URL.
	 * @param MigratePlus_Job $job      Job.
	 * @param string[]        $warnings Warnings (by reference).
	 * @param string          $what     What it is, for the warning.
	 * @return int|null Attachment id, 0 to clear, null when the download failed.
	 */
	protected static function sideload( $url, MigratePlus_Job $job, array &$warnings, $what ) {
		$url = MigratePlus_Util::stringify( $url );
		if ( '' === $url ) {
			return 0;
		}
		$base = isset( $job->data['settings']['migrateplus_site'] ) ? (string) $job->data['settings']['migrateplus_site'] : '';
		$id   = MigratePlus_Media::sideload( $url, 0, '', $base, $job->data['id'] );
		if ( is_wp_error( $id ) ) {
			$warnings[] = $what . ': ' . $id->get_error_message();
			return null;
		}
		return (int) $id;
	}

	/**
	 * The attachment for an image the settings name (site icon, logo, header
	 * image). Downloaded under the rule every image of an import follows: only
	 * when "Featured images" is on.
	 *
	 * @param mixed           $url      URL.
	 * @param MigratePlus_Job $job      Job.
	 * @param array           $opts     Job options.
	 * @param bool            $dry      Dry run.
	 * @param string[]        $warnings Warnings (by reference).
	 * @param string          $what     What it is, for the warnings ("The site icon").
	 * @return int|null Attachment id (0 in a dry run, or to clear), null to leave the setting alone.
	 */
	protected static function settings_image( $url, MigratePlus_Job $job, array $opts, $dry, array &$warnings, $what ) {
		if ( '' === MigratePlus_Util::stringify( $url ) ) {
			return 0;
		}
		if ( empty( $opts['import_featured_image'] ) ) {
			/* translators: %s: The site icon, The logo... */
			$warnings[] = sprintf( __( '%s was not downloaded: images are downloaded only when "Featured images" is on.', 'migrateplus' ), $what );
			return null;
		}
		return $dry ? 0 : self::sideload( $url, $job, $warnings, $what );
	}

	/**
	 * Fill this theme's widget areas from the export's. An area this theme does
	 * not have goes to Inactive Widgets, where its widgets can be moved by hand.
	 *
	 * The file's widgets join this site's rather than replace them: each is added
	 * to its widget_* option, under a new number when this site already uses its
	 * own, and what an area the file fills held before goes to Inactive Widgets.
	 * Text, HTML and block widgets need unfiltered_html, as Additional CSS needs
	 * edit_css: on a network a site's administrator has neither, and could
	 * otherwise add script through a file.
	 *
	 * @param array           $widgets  { sidebars, instances, menus }.
	 * @param MigratePlus_Job $job      Job (side_prev and widget_menus updated in place).
	 * @param bool            $dry      Dry run.
	 * @param string[]        $warnings Warnings (by reference).
	 * @return int Areas filled.
	 */
	protected static function import_widgets( array $widgets, MigratePlus_Job $job, $dry, array &$warnings ) {
		global $wp_registered_sidebars;
		$registered = is_array( $wp_registered_sidebars ) ? array_keys( $wp_registered_sidebars ) : array();
		$html_ok    = self::user_can( 'unfiltered_html' );
		$plan       = array();
		$left_out   = 0;
		foreach ( $widgets['sidebars'] as $area => $ids ) {
			$area = sanitize_key( $area );
			$ids  = array_values( array_filter( array_map( 'sanitize_key', (array) $ids ) ) );
			if ( 'wp_inactive_widgets' === $area ) {
				continue;
			}
			if ( ! $html_ok ) {
				$kept      = array_values( array_filter( $ids, array( __CLASS__, 'widget_is_plain' ) ) );
				$left_out += count( $ids ) - count( $kept );
				$ids       = $kept;
			}
			if ( $ids ) {
				$plan[ $area ] = $ids;
			}
		}
		if ( $left_out ) {
			/* translators: %d: number of widgets */
			$warnings[] = sprintf( _n( 'You may not add unfiltered HTML on this site, so %d text, HTML or block widget was left out.', 'You may not add unfiltered HTML on this site, so %d text, HTML or block widgets were left out.', $left_out, 'migrateplus' ), $left_out );
		}
		$filled = count( array_intersect( array_keys( $plan ), $registered ) );
		foreach ( array_diff( array_keys( $plan ), $registered ) as $area ) {
			/* translators: %s: widget area */
			$warnings[] = sprintf( __( 'This theme has no "%s" widget area; its widgets were put with the inactive widgets.', 'migrateplus' ), $area );
		}
		if ( $dry ) {
			return $filled;
		}

		$current   = (array) get_option( 'sidebars_widgets', array() );
		$inactive  = isset( $current['wp_inactive_widgets'] ) && is_array( $current['wp_inactive_widgets'] ) ? $current['wp_inactive_widgets'] : array();
		$sources   = isset( $widgets['instances'] ) && is_array( $widgets['instances'] ) ? $widgets['instances'] : array();
		$options   = array();
		$new       = array();
		$new_menus = array();
		foreach ( $plan as $area => $ids ) {
			$placed = array();
			foreach ( $ids as $id ) {
				if ( ! preg_match( '/^([a-z0-9_-]+)-(\d+)$/', $id, $m ) ) {
					$placed[] = $id; // A widget of the old single-instance kind: nothing to copy.
					continue;
				}
				$name     = 'widget_' . $m[1];
				$instance = isset( $sources[ $name ][ $m[2] ] ) && is_array( $sources[ $name ][ $m[2] ] ) ? $sources[ $name ][ $m[2] ] : null;
				if ( null === $instance ) {
					continue;
				}
				if ( ! isset( $options[ $name ] ) ) {
					$options[ $name ] = get_option( $name, array() );
					$options[ $name ] = is_array( $options[ $name ] ) ? $options[ $name ] : array();
				}
				$menu     = '';
				$instance = self::remap_widget( $m[1], $instance, $widgets, $job, $warnings, $menu );
				$number   = (int) $m[2];
				// The same number holding another widget here: a new number, and the area names it by that.
				if ( isset( $options[ $name ][ $number ] ) && $options[ $name ][ $number ] !== $instance ) {
					$number = max( array_merge( array( 1 ), array_filter( array_keys( $options[ $name ] ), 'is_int' ) ) ) + 1;
				}
				$options[ $name ][ $number ] = $instance;
				$placed[]                    = $m[1] . '-' . $number;
				if ( '' !== $menu ) {
					$new_menus[ $number ] = $menu;
				}
			}
			if ( in_array( $area, $registered, true ) ) {
				$new[ $area ] = $placed;
			} else {
				$inactive = array_merge( $inactive, $placed );
			}
		}
		foreach ( $options as $name => $value ) {
			self::set_option( $name, $value + array( '_multiwidget' => 1 ), $job );
		}
		if ( $new_menus ) {
			$job->data['widget_menus'] = $new_menus + ( isset( $job->data['widget_menus'] ) ? (array) $job->data['widget_menus'] : array() );
		}
		// Areas the file does not mention keep what they have; one it fills gets the file's widgets, and the
		// widgets it had before wait in Inactive Widgets.
		foreach ( $current as $area => $ids ) {
			if ( 'array_version' === $area || 'wp_inactive_widgets' === $area || ! is_array( $ids ) ) {
				continue;
			}
			if ( isset( $new[ $area ] ) ) {
				$inactive = array_merge( $inactive, array_diff( $ids, $new[ $area ] ) );
			} else {
				$new[ $area ] = $ids;
			}
		}
		$new = array( 'wp_inactive_widgets' => array_values( array_unique( $inactive ) ) ) + $new;
		if ( isset( $current['array_version'] ) ) {
			$new['array_version'] = $current['array_version'];
		}
		self::set_option( 'sidebars_widgets', $new, $job );
		return $filled;
	}

	/**
	 * Whether a widget carries no HTML of its own: not a text, custom HTML or
	 * block widget.
	 *
	 * @param string $id Widget id (block-2).
	 * @return bool
	 */
	protected static function widget_is_plain( $id ) {
		return ! preg_match( '/^(text|custom_html|block)-\d+$/', (string) $id );
	}

	/**
	 * Point a widget's references at this site: a Navigation Menu widget's menu
	 * by its slug, a media widget's attachments by the uid they were imported
	 * with. What cannot be found is cleared with a warning, so it does not point
	 * at whatever has that id here.
	 *
	 * @param string          $base     Widget base (nav_menu, media_image...).
	 * @param array           $instance Instance.
	 * @param array           $widgets  The file's widgets (menus: { id => slug }).
	 * @param MigratePlus_Job $job      Job.
	 * @param string[]        $warnings Warnings (by reference).
	 * @param string          $menu     Set to the menu's slug when it is not here yet (by reference).
	 * @return array
	 */
	protected static function remap_widget( $base, array $instance, array $widgets, MigratePlus_Job $job, array &$warnings, &$menu ) {
		if ( 'nav_menu' === $base && ! empty( $instance['nav_menu'] ) ) {
			$slug = isset( $widgets['menus'][ (string) $instance['nav_menu'] ] ) ? sanitize_title( MigratePlus_Util::stringify( $widgets['menus'][ (string) $instance['nav_menu'] ] ) ) : '';
			$term = '' !== $slug ? get_term_by( 'slug', $slug, 'nav_menu' ) : false;
			// Menus are imported after the settings: one not here yet is set by resolve_widget_menus() then.
			$instance['nav_menu'] = $term instanceof WP_Term ? (int) $term->term_id : 0;
			if ( ! $term && '' !== $slug ) {
				$menu = $slug;
				/* translators: %s: menu slug */
				$warnings[] = sprintf( __( 'A Navigation Menu widget shows the "%s" menu, which is not on this site yet: it gets it if the file\'s menus are imported, otherwise choose one in the widget.', 'migrateplus' ), $slug );
			} elseif ( ! $term ) {
				$warnings[] = __( 'A Navigation Menu widget names a menu the file does not, so it shows no menu until one is chosen.', 'migrateplus' );
			}
		}
		if ( 0 !== strpos( $base, 'media_' ) ) {
			return $instance;
		}
		$find = static function ( $old ) use ( $job ) {
			return MigratePlus_Importer::find_by_uid( MigratePlus_Importer::uid( $job, MigratePlus_Util::stringify( $old ) ), 'attachment', $job->data['id'] );
		};
		if ( ! empty( $instance['attachment_id'] ) ) {
			$id                        = $find( $instance['attachment_id'] );
			$instance['attachment_id'] = $id;
			if ( $id && isset( $instance['url'] ) ) {
				$instance['url'] = (string) wp_get_attachment_url( $id );
			} elseif ( ! $id ) {
				$warnings[] = __( 'A media widget\'s file is not in the file\'s media, so the widget loads it from the old site\'s address.', 'migrateplus' );
			}
		}
		if ( ! empty( $instance['ids'] ) && is_array( $instance['ids'] ) ) {
			$old             = array_filter( $instance['ids'] );
			$instance['ids'] = array_values( array_filter( array_map( $find, $old ) ) );
			if ( count( $instance['ids'] ) < count( $old ) ) {
				$warnings[] = __( 'A gallery widget shows images that are not in the file\'s media; they were left out of it.', 'migrateplus' );
			}
		}
		return $instance;
	}

	/**
	 * Point Navigation Menu widgets at their menus once the import has made them.
	 * Menus come after the settings (they link to the records), so a widget whose
	 * menu was not here yet was left empty and its menu's slug kept in the job.
	 *
	 * @param MigratePlus_Job $job Job (widget_menus cleared in place).
	 * @return int Widgets given their menu.
	 */
	public static function resolve_widget_menus( MigratePlus_Job $job ) {
		$pending = isset( $job->data['widget_menus'] ) ? (array) $job->data['widget_menus'] : array();
		unset( $job->data['widget_menus'] );
		$option = get_option( 'widget_nav_menu', array() );
		$fixed  = 0;
		foreach ( $pending as $number => $slug ) {
			$term = get_term_by( 'slug', (string) $slug, 'nav_menu' );
			if ( $term instanceof WP_Term && isset( $option[ $number ] ) && is_array( $option[ $number ] ) ) {
				$option[ $number ]['nav_menu'] = (int) $term->term_id;
				++$fixed;
			}
		}
		if ( $fixed ) {
			update_option( 'widget_nav_menu', $option ); // side_prev already holds what it was before the import.
		}
		return $fixed;
	}

	/**
	 * Apply the theme's settings: Customizer settings only to the same theme
	 * (another theme reads them differently), Additional CSS and global styles to
	 * whichever is active.
	 *
	 * @param array           $theme    { stylesheet, name, mods, custom_css, global_styles }.
	 * @param MigratePlus_Job $job      Job.
	 * @param bool            $dry      Dry run.
	 * @param array           $opts     Job options.
	 * @param string[]        $warnings Warnings (by reference).
	 * @return int Settings applied.
	 */
	protected static function import_theme( array $theme, MigratePlus_Job $job, $dry, array $opts, array &$warnings ) {
		$n    = 0;
		$mods = isset( $theme['mods'] ) && is_array( $theme['mods'] ) ? $theme['mods'] : array();
		if ( $mods && isset( $theme['stylesheet'] ) && get_stylesheet() !== (string) $theme['stylesheet'] ) {
			/* translators: 1: theme on the old site, 2: theme here */
			$warnings[] = sprintf( __( 'The Customizer settings are for the "%1$s" theme; this site runs "%2$s", so they were not applied.', 'migrateplus' ), isset( $theme['name'] ) ? MigratePlus_Util::stringify( $theme['name'] ) : MigratePlus_Util::stringify( $theme['stylesheet'] ), wp_get_theme()->get( 'Name' ) );
			$mods       = array();
		}
		foreach ( $mods as $key => $value ) {
			$key = sanitize_key( $key );
			if ( '' === $key || in_array( $key, self::SKIP_MODS, true ) ) {
				continue;
			}
			if ( 'custom_logo' === $key ) {
				$value = self::settings_image( $value, $job, $opts, $dry, $warnings, __( 'The logo', 'migrateplus' ) );
				if ( null === $value ) {
					continue;
				}
			}
			$header = null;
			// A header image is a URL on the old site; downloaded like the logo, so it does not keep loading from there.
			if ( 'header_image' === $key && is_string( $value ) && preg_match( '#^https?://#i', $value ) ) {
				$header = self::settings_image( $value, $job, $opts, $dry, $warnings, __( 'The header image', 'migrateplus' ) );
				if ( null === $header ) {
					continue;
				}
				$value = $header ? (string) wp_get_attachment_url( $header ) : $value;
			}
			++$n;
			if ( $dry ) {
				continue;
			}
			self::set_mod( $key, $value, $job );
			if ( $header ) {
				$meta = wp_get_attachment_metadata( $header );
				self::set_mod(
					'header_image_data',
					(object) array(
						'attachment_id' => $header,
						'url'           => $value,
						'thumbnail_url' => $value,
						'width'         => isset( $meta['width'] ) ? (int) $meta['width'] : 0,
						'height'        => isset( $meta['height'] ) ? (int) $meta['height'] : 0,
					),
					$job
				);
			}
		}
		if ( isset( $theme['custom_css'] ) && is_string( $theme['custom_css'] ) && '' !== $theme['custom_css'] && ! self::user_can( 'edit_css' ) ) {
			$warnings[] = __( 'You may not edit CSS on this site, so the Additional CSS was not applied.', 'migrateplus' );
		} elseif ( isset( $theme['custom_css'] ) && is_string( $theme['custom_css'] ) && '' !== $theme['custom_css'] ) {
			++$n;
			if ( ! $dry ) {
				if ( ! isset( $job->data['side_prev']['custom_css'] ) ) {
					$job->data['side_prev']['custom_css'] = (string) wp_get_custom_css();
				}
				wp_update_custom_css_post( $theme['custom_css'] );
			}
		}
		if ( isset( $theme['global_styles'] ) && is_string( $theme['global_styles'] ) && '' !== $theme['global_styles'] ) {
			++$n;
			if ( ! $dry ) {
				self::set_global_styles( $theme['global_styles'], $job, $warnings );
			}
		}
		return $n;
	}

	/**
	 * Set a theme mod, keeping what it was for rollback.
	 *
	 * @param string          $key   Theme mod.
	 * @param mixed           $value Value.
	 * @param MigratePlus_Job $job   Job (side_prev updated in place).
	 */
	protected static function set_mod( $key, $value, MigratePlus_Job $job ) {
		if ( ! isset( $job->data['side_prev']['theme_mods'] ) || ! array_key_exists( $key, (array) $job->data['side_prev']['theme_mods'] ) ) {
			$prev = get_theme_mod( $key, null );
			$job->data['side_prev']['theme_mods'][ $key ] = null === $prev ? array( 'none' => true ) : array( 'value' => $prev );
		}
		set_theme_mod( $key, $value );
	}

	/**
	 * Replace the active theme's user global styles.
	 *
	 * @param string          $json     theme.json-shaped JSON.
	 * @param MigratePlus_Job $job      Job.
	 * @param string[]        $warnings Warnings (by reference).
	 */
	protected static function set_global_styles( $json, MigratePlus_Job $job, array &$warnings ) {
		if ( ! class_exists( 'WP_Theme_JSON_Resolver' ) || ! method_exists( 'WP_Theme_JSON_Resolver', 'get_user_global_styles_post_id' ) ) {
			$warnings[] = __( 'This WordPress version has no global styles, so the file\'s were not applied.', 'migrateplus' );
			return;
		}
		if ( null === json_decode( $json, true ) ) {
			$warnings[] = __( 'The global styles in the file are not valid JSON, so they were not applied.', 'migrateplus' );
			return;
		}
		$id = (int) WP_Theme_JSON_Resolver::get_user_global_styles_post_id();
		if ( ! $id ) {
			return;
		}
		if ( ! isset( $job->data['side_prev']['global_styles'] ) ) {
			$job->data['side_prev']['global_styles'] = (string) get_post_field( 'post_content', $id );
		}
		wp_update_post(
			wp_slash(
				array(
					'ID'           => $id,
					'post_content' => $json,
				)
			)
		);
	}

	/**
	 * Put back every setting the import replaced.
	 *
	 * @param MigratePlus_Job $job Job (side_prev cleared in place).
	 */
	public static function restore( MigratePlus_Job $job ) {
		$prev       = isset( $job->data['side_prev'] ) ? (array) $job->data['side_prev'] : array();
		$permalinks = false;
		foreach ( isset( $prev['options'] ) ? (array) $prev['options'] : array() as $name => $was ) {
			if ( isset( $was['none'] ) ) {
				// A permalink option that was never saved reads as empty: emptied through WP_Rewrite first, so its
				// state follows, then deleted as it was.
				$permalinks = ( in_array( $name, self::SECTIONS['permalinks']['options'], true ) && self::write_option( $name, '' ) ) || $permalinks;
				delete_option( $name );
			} elseif ( is_array( $was ) && array_key_exists( 'value', $was ) ) {
				$permalinks = self::write_option( $name, $was['value'] ) || $permalinks;
			}
		}
		// The import flushed rules for its structure; without a flush they would outlive the rollback.
		if ( $permalinks ) {
			flush_rewrite_rules( false );
		}
		foreach ( isset( $prev['theme_mods'] ) ? (array) $prev['theme_mods'] : array() as $key => $was ) {
			if ( isset( $was['none'] ) ) {
				remove_theme_mod( $key );
			} elseif ( is_array( $was ) && array_key_exists( 'value', $was ) ) {
				set_theme_mod( $key, $was['value'] );
			}
		}
		if ( isset( $prev['custom_css'] ) ) {
			wp_update_custom_css_post( (string) $prev['custom_css'] );
		}
		if ( isset( $prev['global_styles'] ) && class_exists( 'WP_Theme_JSON_Resolver' ) && method_exists( 'WP_Theme_JSON_Resolver', 'get_user_global_styles_post_id' ) ) {
			$id = (int) WP_Theme_JSON_Resolver::get_user_global_styles_post_id();
			if ( $id ) {
				wp_update_post(
					wp_slash(
						array(
							'ID'           => $id,
							'post_content' => (string) $prev['global_styles'],
						)
					)
				);
			}
		}
		foreach ( isset( $prev['yoast'] ) ? (array) $prev['yoast'] : array() as $name => $was ) {
			if ( null === $was ) {
				delete_option( $name );
			} else {
				update_option( $name, $was );
			}
		}
		unset( $prev['options'], $prev['theme_mods'], $prev['custom_css'], $prev['global_styles'], $prev['yoast'] );
		$job->data['side_prev'] = $prev;
	}

	/**
	 * Whether the person may read and change the site's settings, or do what
	 * another capability says. WP-CLI without --user runs with the shell
	 * account's access.
	 *
	 * @param string $capability Capability.
	 * @return bool
	 */
	protected static function user_can( $capability = 'manage_options' ) {
		return ! get_current_user_id() || current_user_can( $capability );
	}

	/**
	 * @param string $action  updated|skipped|failed.
	 * @param string $message Message.
	 * @param bool   $warn    Warning.
	 * @return array
	 */
	protected static function result( $action, $message, $warn = false ) {
		return array(
			'action'  => $action,
			'post_id' => 0,
			'message' => $message,
			'warning' => $warn,
			'section' => 'site_settings',
		);
	}
}
