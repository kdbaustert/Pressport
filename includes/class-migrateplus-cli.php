<?php
/**
 * WP-CLI commands.
 *
 * @package MigratePlus
 */

defined( 'ABSPATH' ) || exit;

/**
 * Import content from a JSON file, and export it as JSON again.
 *
 * ## EXAMPLES
 *
 *     # Preview a Migrate Plus export and see the mapping it carries
 *     wp migrateplus preview json --file=./migrateplus-product.json
 *
 *     # Import a Migrate Plus export, updating anything imported before
 *     wp migrateplus import json --file=./migrateplus-product.json --duplicate=update
 *
 *     # Import any JSON file with a custom mapping file
 *     wp migrateplus import json --file=./feed.json --map=./mapping.json
 *
 *     # Run everything from one config file
 *     wp migrateplus import --config=./migration.json
 *
 *     # Import another file from the same feed with a mapping saved on the mapping screen
 *     wp migrateplus import json --file=./feed.json --mapping="Books feed"
 *
 *     # Undo an import
 *     wp migrateplus rollback abc123defg --yes
 *
 *     # Export pages with ACF fields, to import on another site
 *     wp migrateplus export page --output=./pages.json
 *
 *     # Media, pages and posts in one file, with the menus
 *     wp migrateplus export attachment,page,post --menus --output=./site.json
 */
class MigratePlus_CLI {

	/**
	 * Option flags that are not source settings.
	 *
	 * @var string[]
	 */
	protected $reserved = array( 'config', 'map', 'mapping', 'post_type', 'status', 'author', 'duplicate', 'duplicates', 'batch_size', 'featured-images', 'content-images', 'create-terms', 'rewrite-links', 'force-status', 'comments', 'menus', 'term-data', 'acf-definitions', 'acf-options', 'site-settings', 'orders', 'redirects', 'dry-run', 'yes' );

	/**
	 * Import switches and the option each sets.
	 *
	 * @var string[]
	 */
	protected $switches = array(
		'featured-images' => 'import_featured_image',
		'content-images'  => 'import_content_images',
		'create-terms'    => 'create_terms',
		'rewrite-links'   => 'rewrite_links',
		'comments'        => 'import_comments',
		'menus'           => 'import_menus',
		'term-data'       => 'import_term_data',
		'acf-definitions' => 'import_acf_definitions',
		'acf-options'     => 'import_acf_options',
		'site-settings'   => 'import_site_settings',
		'orders'          => 'import_orders',
		'redirects'       => 'import_redirects',
		'dry-run'         => 'dry_run',
	);

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
		$preview = MigratePlus_Runner::preview( $args[0], $this->settings_from( $assoc, $args[0] ), 3 );
		if ( is_wp_error( $preview ) ) {
			WP_CLI::error( $preview->get_error_message() );
		}
		$job = $preview['job'];
		WP_CLI::log( sprintf( 'Records: %s', null === $job->data['total'] ? 'unknown' : $job->data['total'] ) );
		WP_CLI::log( 'Fields: ' . implode( ', ', $preview['fields'] ) );
		WP_CLI::log( "\nSuggested mapping (save as a --map file and edit):" );
		WP_CLI::log( wp_json_encode( $preview['mapping'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
		// An export of side sections alone (menus, settings...) has no records to show.
		if ( empty( $preview['records'] ) ) {
			WP_CLI::log( "\nNo records: the file holds side sections only." );
			$job->delete();
			return;
		}
		WP_CLI::log( "\nFirst record:" );
		$rows = array();
		foreach ( $preview['records'][0] as $k => $v ) {
			$value  = MigratePlus_Util::stringify( $v );
			$rows[] = array(
				'field' => $k,
				// mbstring is optional in PHP.
				'value' => function_exists( 'mb_strimwidth' ) ? mb_strimwidth( $value, 0, 90, '…' ) : ( strlen( $value ) > 90 ? substr( $value, 0, 87 ) . '...' : $value ),
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
	 * : JSON file: { "source": "...", "settings": {...}, "mapping": {...}, "options": {...} }. Its options may give "authors", an author for single records: { "0": "id|login|email", ... }, by the record's position in the file (0 for the first). Those records get it instead of --author.
	 *
	 * [--map=<file>]
	 * : JSON mapping file: { "fields": { "post_title": "title", ... }, "meta": [{ "key": "...", "source": "..." }], "taxonomies": [{ "taxonomy": "...", "source": "..." }], "acf": { "field_name_or_key": "source_field" } }. Defaults to the suggested mapping.
	 *
	 * [--mapping=<name>]
	 * : A saved mapping (see wp migrateplus mapping list): its field mapping and import options. Not for Migrate Plus exports, which carry their own. Mapping and options in --config, --map and the flags below take precedence.
	 *
	 * [--post_type=<type>]
	 * : Destination post type. Defaults to the Migrate Plus setting.
	 *
	 * [--status=<status>]
	 * : Default status: publish, draft, pending, private.
	 *
	 * [--force-status=<status>]
	 * : Give every imported post this status (publish, draft, pending or private), also posts it updates. Pass "keep" to use each record's own status. Defaults to the Migrate Plus setting.
	 *
	 * [--author=<id|login|email>]
	 * : The author of the imported posts (a record given its own in a --config file's "authors" gets that one). Without it, the posts go to the --user running the command, or to WordPress's default author without --user. User accounts differ from site to site, so the file's authors are only kept with the posts as a note.
	 *
	 * [--duplicate=<mode>]
	 * : skip, update or create. Defaults to the Migrate Plus setting.
	 *
	 * [--duplicates=<choice>]
	 * : What to do with records already on this site (imported before, or the same slug, SKU or media file name): skip, import (as copies), or update (the posts and pages whose title, content, excerpt, status, date, slug or author differ; the rest are skipped). Asks when left out. In a dry run, with --yes, or when there is no terminal to ask in, it doesn't ask: --duplicate=create imports them as copies, otherwise they are skipped.
	 *
	 * [--yes]
	 * : Don't ask about records already on this site.
	 *
	 * [--batch_size=<n>]
	 * : Records per batch. Defaults to the Migrate Plus setting.
	 *
	 * [--[no-]featured-images]
	 * : Download featured images. Defaults to the Migrate Plus setting.
	 *
	 * [--content-images]
	 * : Download images found inside content.
	 *
	 * [--[no-]create-terms]
	 * : Create missing categories and terms. Defaults to the Migrate Plus setting.
	 *
	 * [--[no-]rewrite-links]
	 * : Point links to the old site at this one (Migrate Plus exports only). Defaults to the Migrate Plus setting.
	 *
	 * [--[no-]comments]
	 * : Add the comments a Migrate Plus export carries. Defaults to the Migrate Plus setting.
	 *
	 * [--[no-]menus]
	 * : Import the navigation menus a Migrate Plus export carries, after its posts. Defaults to the Migrate Plus setting.
	 *
	 * [--[no-]term-data]
	 * : Import the terms a Migrate Plus export carries, with their data, before its posts. Defaults to the Migrate Plus setting.
	 *
	 * [--[no-]acf-definitions]
	 * : Import the ACF field groups, post types, taxonomies and options pages a Migrate Plus export carries, before its posts. Defaults to the Migrate Plus setting.
	 *
	 * [--[no-]acf-options]
	 * : Import the ACF options page values a Migrate Plus export carries, after its posts. Defaults to the Migrate Plus setting.
	 *
	 * [--[no-]site-settings]
	 * : Import the site settings a Migrate Plus export carries, after its posts. Defaults to the Migrate Plus setting.
	 *
	 * [--[no-]orders]
	 * : Import the WooCommerce orders a Migrate Plus export carries, after its posts. Defaults to the Migrate Plus setting.
	 *
	 * [--[no-]redirects]
	 * : Redirect a post's old address to its new one when they differ. Defaults to the Migrate Plus setting.
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
			if ( ! empty( $config['mapping'] ) ) {
				$this->check_mapping( $config['mapping'], $assoc['config'] );
			}
		}
		$source = isset( $args[0] ) ? $args[0] : ( isset( $config['source'] ) ? $config['source'] : '' );
		if ( '' === $source ) {
			WP_CLI::error( 'Specify a source (json) or --config.' );
		}
		$saved = null;
		if ( isset( $assoc['mapping'] ) ) {
			$saved = MigratePlus_Mappings::get( $assoc['mapping'] );
			if ( ! $saved ) {
				WP_CLI::error( sprintf( 'No saved mapping called "%s". See: wp migrateplus mapping list', $assoc['mapping'] ) );
			}
			$config += array( 'mapping' => $saved['mapping'] );
			// Option by option, so --config only overrides the ones it names.
			$config['options'] = array_merge( (array) $saved['options'], isset( $config['options'] ) ? (array) $config['options'] : array() );
		}
		// Read and checked before preview() saves a job, like the options below.
		$map = ! empty( $assoc['map'] ) ? $this->read_json( $assoc['map'] ) : null;
		if ( $map ) {
			$this->check_mapping( $map, $assoc['map'] );
		}
		$settings = array_merge( isset( $config['settings'] ) ? (array) $config['settings'] : array(), $this->settings_from( $assoc, $source ) );

		// Options from --config or a saved mapping, then the flags. Checked here: preview() saves a job that a
		// refusal after it would leave behind. The defaults preview() adds to them come from Settings, already valid.
		$given = isset( $config['options'] ) ? (array) $config['options'] : array();
		$flags = array(
			'post_type'  => 'post_type',
			'status'     => 'default_status',
			'duplicate'  => 'duplicate',
			'batch_size' => 'batch_size',
		);
		foreach ( $flags as $flag => $key ) {
			if ( isset( $assoc[ $flag ] ) ) {
				$given[ $key ] = $assoc[ $flag ];
			}
		}
		if ( isset( $given['default_status'] ) && ! array_key_exists( $given['default_status'], get_post_statuses() ) ) {
			WP_CLI::error( sprintf( '--status must be one of: %s.', implode( ', ', array_keys( get_post_statuses() ) ) ) );
		}
		if ( isset( $given['duplicate'] ) && ! in_array( $given['duplicate'], array( 'skip', 'update', 'create' ), true ) ) {
			WP_CLI::error( '--duplicate must be skip, update or create.' );
		}
		if ( isset( $assoc['force-status'] ) ) {
			$given['force_status'] = 'keep' === $assoc['force-status'] ? '' : $assoc['force-status'];
		}
		if ( ! empty( $given['force_status'] ) && ! in_array( $given['force_status'], array( 'publish', 'draft', 'pending', 'private' ), true ) ) {
			WP_CLI::error( '--force-status must be publish, draft, pending, private or keep.' );
		}
		foreach ( $this->switches as $flag => $key ) {
			if ( isset( $assoc[ $flag ] ) ) {
				$given[ $key ] = $this->switch_value( $assoc[ $flag ], $flag );
			}
		}

		$preview = MigratePlus_Runner::preview( $source, $settings, 1 );
		if ( is_wp_error( $preview ) ) {
			WP_CLI::error( $preview->get_error_message() );
		}
		$job = $preview['job'];
		if ( $saved && isset( $job->data['settings']['migrateplus_mapping'] ) ) {
			$job->delete();
			WP_CLI::error( 'This file is a Migrate Plus export: it carries its own mapping, so it is imported without --mapping.' );
		}

		$mapping = $job->data['mapping'];
		if ( ! empty( $config['mapping'] ) ) {
			$mapping = $config['mapping'];
		}
		if ( $map ) {
			$mapping = $map;
		}
		// As the mapping screen saves it: known fields only, and something to give each post a title or content.
		$job->data['mapping'] = MigratePlus_Mappings::clean_mapping( (array) $mapping );
		$sides_only           = 0 === (int) $job->data['total'] && $job->has_side_sections();
		if ( ! $sides_only && empty( $job->data['mapping']['fields']['post_title'] ) && empty( $job->data['mapping']['fields']['post_content'] ) ) {
			$this->abandon( $job, 'Map at least the Title or Content field (post_title or post_content under "fields").' );
		}

		// The previewed job's options: the defaults, plus the post type a MigratePlus export came from.
		$options = array_merge( $job->data['options'], $given );
		// Posts get the author picked here, or the one a --config file gives their record, or whoever
		// runs the command: accounts differ from site to site, so the file's authors are only kept
		// with the posts as a note. A --config file may name the author for all too.
		$pick              = isset( $assoc['author'] ) ? (string) $assoc['author'] : ( isset( $given['author'] ) && is_scalar( $given['author'] ) ? (string) $given['author'] : '' );
		$options['author'] = '' !== $pick ? $this->author_id( $job, $pick ) : 0;
		$authors           = array();
		foreach ( isset( $given['authors'] ) && is_array( $given['authors'] ) ? $given['authors'] : array() as $index => $one ) {
			if ( ! is_int( $index ) || $index < 0 ) {
				$this->abandon( $job, sprintf( 'Authors: "%s" is not a record position (0 for the first record).', $index ) );
			}
			$authors[ $index ] = $this->author_id( $job, is_scalar( $one ) ? (string) $one : '' );
		}
		// Positions past the file's records are dropped.
		$options['authors'] = MigratePlus_Mappings::clean_authors( $authors, $job->data['total'] );
		// The flags are already in $options, from $given.
		foreach ( $this->switches as $key ) {
			$options[ $key ] = empty( $options[ $key ] ) ? 0 : 1;
		}

		if ( ! post_type_exists( $options['post_type'] ) ) {
			$this->abandon( $job, sprintf( 'Post type "%s" does not exist.', $options['post_type'] ) );
		}
		$job->data['options'] = $options;
		// Without a --duplicates answer, an explicit --duplicate=create means copies; anything else skips them.
		$fallback = isset( $given['duplicate'] ) && 'create' === $given['duplicate'] ? 'import' : 'skip';
		// A dry run checks for duplicates too, so its counts match the real run's, but never asks.
		$ask                  = empty( $options['dry_run'] ) && empty( $assoc['yes'] ) && $this->interactive();
		$choice               = isset( $assoc['duplicates'] ) ? $assoc['duplicates'] : ( $ask ? '' : $fallback );
		$job->data['options'] = $this->ask_duplicates( $job, $choice, isset( $assoc['duplicates'] ) || $ask );
		$job->data['status']  = 'ready';
		$job->save();

		$types = isset( $job->data['settings']['migrateplus_post_types'] ) && false !== strpos( (string) $job->data['settings']['migrateplus_post_types'], ',' ) ? str_replace( ',', ', ', $job->data['settings']['migrateplus_post_types'] ) : $job->data['options']['post_type'];
		WP_CLI::log( sprintf( 'Job %s: importing into "%s"%s', $job->data['id'], $types, $job->data['options']['dry_run'] ? ' (dry run)' : '' ) );
		WP_CLI::log( 'Mapping: ' . wp_json_encode( $job->data['mapping']['fields'] ) );
		$this->run( $job );
	}

	/**
	 * Report records already on this site and settle whether to skip them.
	 *
	 * @param MigratePlus_Job $job      Job with the options it will run with.
	 * @param string          $choice   "skip", "import", "update", or '' to ask.
	 * @param bool            $answered Whether the choice is the user's (--duplicates or the prompt), not the default.
	 * @return array Options.
	 */
	protected function ask_duplicates( MigratePlus_Job $job, $choice, $answered ) {
		$options = $job->data['options'];
		$found   = MigratePlus_Runner::find_duplicates( $job );
		if ( is_wp_error( $found ) ) {
			$this->abandon( $job, $found->get_error_message() );
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
			$answer = $found['changed']
				? \cli\choose( 'Skip them (y), import them anyway as copies (n), or update the ones that differ (u)?', 'ynu', 'y' )
				: \cli\choose( 'Skip them? Answer n to import them anyway', 'yn', 'y' );
			$choice = array(
				'y' => 'skip',
				'n' => 'import',
				'u' => 'update',
			)[ $answer ];
		} elseif ( ! $answered ) {
			WP_CLI::log( 'import' === $choice ? 'Importing them as copies (--duplicate=create). Pass --duplicates=skip to skip them.' : 'Skipping them. Pass --duplicates=import or --duplicates=update to change that.' );
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
		$job = MigratePlus_Job::load( $args[0] );
		if ( ! $job ) {
			WP_CLI::error( 'Job not found.' );
		}
		// As in the admin: a draft was never started, so its options have not been through the duplicate check.
		if ( 'draft' === $job->data['status'] ) {
			WP_CLI::error( sprintf( 'Job %s was never started, so there is nothing to resume. Run the import again.', $job->data['id'] ) );
		}
		$this->run( $job );
	}

	/**
	 * Export content to a JSON file that Migrate Plus (on this or another site) can import.
	 *
	 * ## OPTIONS
	 *
	 * [<post_type>]
	 * : Content type to export, or several separated by commas (e.g. attachment,page,post). "orders" and "site-settings" are content types too: a file can hold them alone or with posts. Media is always written first, so the import downloads each file once. Default: post.
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
	 * [--[no-]term-data]
	 * : Include the terms of the exported types as their own section: descriptions, slugs, hierarchy, custom fields, ACF fields and empty terms. Defaults to the export defaults in Settings.
	 *
	 * [--[no-]featured-images]
	 * : Include featured image URLs. Defaults to the export defaults in Settings; --no-featured-images leaves them out.
	 *
	 * [--[no-]woo]
	 * : Include WooCommerce product data (prices, stock, attributes, variations). Defaults to the export defaults in Settings; --no-woo leaves them out.
	 *
	 * [--[no-]comments]
	 * : Include approved comments and replies. Defaults to the export defaults in Settings (off); --no-comments leaves them out.
	 *
	 * [--[no-]menus]
	 * : Include the navigation menus, their items and theme locations. Defaults to the export defaults in Settings (off). With menus, a file can hold menus alone when no content matches.
	 *
	 * [--[no-]yoast]
	 * : Include Yoast SEO fields of posts and terms, when Yoast SEO is active. Defaults to the export defaults in Settings (on).
	 *
	 * [--[no-]acf-definitions]
	 * : Include the ACF field groups, post types, taxonomies and options pages. Defaults to the export defaults in Settings (on).
	 *
	 * [--[no-]acf-options]
	 * : Include the values of ACF options pages. Defaults to the export defaults in Settings (on).
	 *
	 * [--[no-]site-settings]
	 * : Include the site settings: General, Writing, Reading, Discussion, Media and Permalinks, widgets, theme (Customizer settings, Additional CSS, global styles) and Yoast SEO settings. Defaults to the export defaults in Settings (off).
	 *
	 * [--site-sections=<sections>]
	 * : Which parts of the site settings, comma separated: general, writing, reading, discussion, media, permalinks, widgets, theme, yoast. Default: all of them.
	 *
	 * [--[no-]orders]
	 * : Include the WooCommerce orders (within --after/--before when given) with their items, refunds and notes. Defaults to the export defaults in Settings (off).
	 *
	 * [--[no-]gzip]
	 * : Compress the file (.json.gz), for a site whose upload limit is lower than the export. Needs PHP's zlib extension. Defaults to the export defaults in Settings (off).
	 *
	 * [--output=<file>]
	 * : Where to write the file. Default: migrateplus-<post_type>-<date>.json (.json.gz when compressed) in the current directory.
	 *
	 * ## EXAMPLES
	 *
	 *     wp migrateplus export post --status=publish,draft --output=posts.json
	 *     wp migrateplus export product --after=2024-01-01
	 *     wp migrateplus export attachment,page,post --menus
	 *     wp migrateplus export product,orders
	 *     wp migrateplus export site-settings --output=settings.json
	 *     wp migrateplus export product --gzip
	 *
	 * @param array $args  Args.
	 * @param array $assoc Assoc args.
	 */
	public function export( $args, $assoc ) {
		$defaults = MigratePlus_Exporter::defaults();
		// "orders" and "site-settings" in the type list are the sections of their own, not post types.
		$kinds   = array_filter( array_map( 'trim', explode( ',', isset( $args[0] ) ? (string) $args[0] : 'post' ) ) );
		$kinds   = str_replace( 'site_settings', 'site-settings', $kinds );
		$records = array_intersect( $kinds, array( 'orders', 'site-settings' ) );
		// Checked here too, for messages that name the flag.
		if ( isset( $assoc['status'] ) ) {
			$bad = array_diff( array_map( 'trim', explode( ',', (string) $assoc['status'] ) ), array_merge( array_keys( MigratePlus_Settings::export_statuses() ), array( 'any' ) ) );
			if ( $bad ) {
				WP_CLI::error( sprintf( '--status takes %s or any, separated by commas. Unknown: %s.', implode( ', ', array_keys( MigratePlus_Settings::export_statuses() ) ), implode( ', ', $bad ) ) );
			}
		}
		foreach ( array( 'after', 'before' ) as $flag ) {
			$date = isset( $assoc[ $flag ] ) ? DateTime::createFromFormat( '!Y-m-d', (string) $assoc[ $flag ] ) : null;
			if ( isset( $assoc[ $flag ] ) && ( ! $date || $date->format( 'Y-m-d' ) !== $assoc[ $flag ] ) ) {
				WP_CLI::error( sprintf( '--%s must be a date as YYYY-MM-DD.', $flag ) );
			}
		}
		$export = MigratePlus_Exporter::sanitize_args(
			array(
				'post_type'               => implode( ',', array_diff( $kinds, $records ) ),
				'statuses'                => isset( $assoc['status'] ) ? $assoc['status'] : $defaults['statuses'],
				'after'                   => isset( $assoc['after'] ) ? $assoc['after'] : '',
				'before'                  => isset( $assoc['before'] ) ? $assoc['before'] : '',
				'include_acf'             => WP_CLI\Utils\get_flag_value( $assoc, 'acf', ! empty( $defaults['include_acf'] ) ),
				'include_meta'            => WP_CLI\Utils\get_flag_value( $assoc, 'meta', ! empty( $defaults['include_meta'] ) ),
				'include_terms'           => WP_CLI\Utils\get_flag_value( $assoc, 'terms', ! empty( $defaults['include_terms'] ) ),
				'include_term_data'       => WP_CLI\Utils\get_flag_value( $assoc, 'term-data', ! empty( $defaults['include_term_data'] ) ),
				'include_image'           => WP_CLI\Utils\get_flag_value( $assoc, 'featured-images', ! empty( $defaults['include_image'] ) ),
				'include_woo'             => WP_CLI\Utils\get_flag_value( $assoc, 'woo', ! empty( $defaults['include_woo'] ) ),
				'include_comments'        => WP_CLI\Utils\get_flag_value( $assoc, 'comments', ! empty( $defaults['include_comments'] ) ),
				'include_menus'           => WP_CLI\Utils\get_flag_value( $assoc, 'menus', ! empty( $defaults['include_menus'] ) ),
				'include_yoast'           => WP_CLI\Utils\get_flag_value( $assoc, 'yoast', ! empty( $defaults['include_yoast'] ) ),
				'include_acf_definitions' => WP_CLI\Utils\get_flag_value( $assoc, 'acf-definitions', ! empty( $defaults['include_acf_definitions'] ) ),
				'include_acf_options'     => WP_CLI\Utils\get_flag_value( $assoc, 'acf-options', ! empty( $defaults['include_acf_options'] ) ),
				'include_site_settings'   => in_array( 'site-settings', $records, true ) || WP_CLI\Utils\get_flag_value( $assoc, 'site-settings', ! empty( $defaults['include_site_settings'] ) ),
				'site_sections'           => isset( $assoc['site-sections'] ) ? $assoc['site-sections'] : $defaults['site_sections'],
				'include_orders'          => in_array( 'orders', $records, true ) || WP_CLI\Utils\get_flag_value( $assoc, 'orders', ! empty( $defaults['include_orders'] ) ),
				'exclude_meta'            => isset( $assoc['exclude-meta'] ) ? $assoc['exclude-meta'] : $defaults['exclude_meta'],
				'gzip'                    => WP_CLI\Utils\get_flag_value( $assoc, 'gzip', ! empty( $defaults['gzip'] ) ),
			)
		);
		if ( is_wp_error( $export ) ) {
			WP_CLI::error( $export->get_error_message() );
		}
		// The admin only loads on admin requests; its class holds the rule the Export screen uses.
		require_once MIGRATEPLUS_DIR . 'includes/class-migrateplus-admin.php';
		foreach ( $export['post_types'] as $type ) {
			if ( ! MigratePlus_Admin::exportable_type( $type ) ) {
				WP_CLI::error( sprintf( '"%s" can\'t be exported: Migrate Plus exports content types with an admin screen, other than ACF\'s settings and WooCommerce orders.', $type ) );
			}
		}
		$state = MigratePlus_Exporter::start( $export );
		if ( is_wp_error( $state ) ) {
			WP_CLI::error( $state->get_error_message() );
		}
		if ( ! $state['total'] && ! array_sum( MigratePlus_Exporter::side_counts( $export ) ) ) {
			MigratePlus_Exporter::forget( $state['id'] );
			WP_CLI::error( 'Nothing to export with these filters.' );
		}
		$progress = WP_CLI\Utils\make_progress_bar( sprintf( 'Exporting %d %s', $state['total'], implode( ', ', $kinds ) ), max( 1, $state['total'] ) );
		while ( empty( $state['complete'] ) ) {
			$before = $state['done'];
			MigratePlus_Exporter::run_batch( $state, 100 );
			$progress->tick( $state['done'] - $before );
			if ( function_exists( 'wp_cache_flush_runtime' ) ) {
				wp_cache_flush_runtime();
			}
		}
		$progress->finish();
		if ( ! empty( $state['error'] ) ) {
			MigratePlus_Exporter::delete( $state );
			WP_CLI::error( $state['error'] );
		}

		$output = isset( $assoc['output'] ) ? $assoc['output'] : $state['filename'];
		if ( ! copy( $state['file'], $output ) ) {
			WP_CLI::error( sprintf( 'Could not write %s', $output ) );
		}
		MigratePlus_Exporter::delete( $state );
		$extras    = array();
		$providers = MigratePlus_Exporter::side_providers();
		foreach ( isset( $state['sides'] ) ? (array) $state['sides'] : array() as $key => $n ) {
			if ( $n && isset( $providers[ $key ] ) ) {
				$extras[] = 'site_settings' === $key ? 'the site settings' : sprintf( '%d %s', $n, $providers[ $key ]['label'] );
			}
		}
		WP_CLI::success( sprintf( 'Exported %d items%s (%d ACF fields, %d custom fields) to %s', $state['done'], $extras ? ' and ' . implode( ' and ', $extras ) : '', count( $state['acf_names'] ), count( $state['meta_keys'] ), $output ) );
	}

	/**
	 * List, export, import or delete saved mappings.
	 *
	 * Mappings are saved on the mapping screen of an import. An exported mapping is
	 * a --config file for wp migrateplus import (pass the JSON file with --file); import
	 * reads such a file back in as a saved mapping, for example on another site.
	 *
	 * ## OPTIONS
	 *
	 * <action>
	 * : list, export, import or delete.
	 *
	 * [<name-or-file>]
	 * : The mapping's name (export, delete) or the file to read (import).
	 *
	 * [--output=<file>]
	 * : export: where to write the file. Default: migrateplus-mapping-<name>.json in the current directory.
	 *
	 * [--name=<name>]
	 * : import: the name to save it under. Default: the name in the file, or the file name.
	 *
	 * [--format=<format>]
	 * : list: table, json, csv. Default: table.
	 *
	 * ## EXAMPLES
	 *
	 *     wp migrateplus mapping list
	 *     wp migrateplus mapping export "Books feed" --output=books.json
	 *     wp migrateplus import --config=books.json --file=./feed.json
	 *     wp migrateplus mapping import books.json
	 *     wp migrateplus mapping delete "Books feed"
	 *
	 * @param array $args  Args.
	 * @param array $assoc Assoc args.
	 */
	public function mapping( $args, $assoc ) {
		$action = $args[0];
		$target = isset( $args[1] ) ? $args[1] : '';
		if ( 'list' === $action ) {
			$rows = array();
			foreach ( MigratePlus_Mappings::all() as $entry ) {
				$rows[] = array(
					'name'      => $entry['name'],
					'post_type' => isset( $entry['options']['post_type'] ) ? $entry['options']['post_type'] : MigratePlus_Job::default_options()['post_type'],
					'mapped'    => count( $entry['mapping']['fields'] ) + count( $entry['mapping']['meta'] ) + count( $entry['mapping']['taxonomies'] ) + count( $entry['mapping']['acf'] ),
					'fields'    => count( $entry['fields'] ),
					'updated'   => wp_date( 'Y-m-d H:i', $entry['updated'] ),
				);
			}
			WP_CLI\Utils\format_items( isset( $assoc['format'] ) ? $assoc['format'] : 'table', $rows, array( 'name', 'post_type', 'mapped', 'fields', 'updated' ) );
			return;
		}
		if ( '' === $target ) {
			WP_CLI::error( 'export and delete need the mapping\'s name; import needs a file.' );
		}
		if ( 'export' === $action ) {
			$entry = MigratePlus_Mappings::get( $target );
			if ( ! $entry ) {
				WP_CLI::error( sprintf( 'No saved mapping called "%s".', $target ) );
			}
			$output = isset( $assoc['output'] ) ? $assoc['output'] : 'migrateplus-mapping-' . sanitize_title( $entry['name'] ) . '.json';
			if ( false === file_put_contents( $output, wp_json_encode( MigratePlus_Mappings::to_config( $entry ), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . "\n" ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions
				WP_CLI::error( sprintf( 'Could not write %s', $output ) );
			}
			WP_CLI::success( sprintf( 'Wrote "%s" to %s. Import with it: wp migrateplus import --config=%s --file=<file.json>', $entry['name'], $output, $output ) );
			return;
		}
		if ( 'import' === $action ) {
			$config = $this->read_json( $target );
			if ( empty( $config['mapping'] ) || ! is_array( $config['mapping'] ) ) {
				WP_CLI::error( sprintf( '%s has no "mapping".', $target ) );
			}
			$name  = isset( $assoc['name'] ) ? $assoc['name'] : ( isset( $config['name'] ) ? (string) $config['name'] : pathinfo( $target, PATHINFO_FILENAME ) );
			$saved = MigratePlus_Mappings::save( $name, $config['mapping'], isset( $config['options'] ) ? (array) $config['options'] : array(), isset( $config['fields'] ) ? (array) $config['fields'] : array() );
			if ( is_wp_error( $saved ) ) {
				WP_CLI::error( $saved->get_error_message() );
			}
			WP_CLI::success( sprintf( 'Saved mapping "%s".', $saved['name'] ) );
			return;
		}
		if ( 'delete' === $action ) {
			$deleted = MigratePlus_Mappings::delete( $target );
			if ( is_wp_error( $deleted ) ) {
				WP_CLI::error( $deleted->get_error_message() );
			}
			if ( ! $deleted ) {
				WP_CLI::error( sprintf( 'No saved mapping called "%s".', $target ) );
			}
			WP_CLI::success( sprintf( 'Deleted mapping "%s".', $target ) );
			return;
		}
		WP_CLI::error( 'The action must be list, export, import or delete.' );
	}

	/**
	 * Delete all posts, media, comments and menus created by a job.
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
		$job = MigratePlus_Job::load( $args[0] );
		if ( ! $job ) {
			WP_CLI::error( 'Job not found.' );
		}
		$count = $job->imported_count();
		// Settings and values it replaced, and redirects it added, are undone too: a job that only
		// imported site settings created nothing, and still has something to roll back.
		$undo = ! empty( $job->data['side_prev'] ) || MigratePlus_Redirects::count_for_job( $job->data['id'] );
		if ( ! empty( $job->data['options']['dry_run'] ) || 'rolled_back' === $job->data['status'] || ( ! $count && ! $undo ) ) {
			$why = 'created nothing that is still on this site';
			if ( ! empty( $job->data['options']['dry_run'] ) ) {
				$why = 'was a dry run';
			} elseif ( 'rolled_back' === $job->data['status'] ) {
				$why = 'was rolled back already';
			}
			WP_CLI::warning( sprintf( 'Nothing to roll back: job %s %s.', $job->data['id'], $why ) );
			return;
		}
		WP_CLI::confirm( $count ? sprintf( 'Permanently delete %d items created by job %s, and put back what it changed?', $count, $job->data['id'] ) : sprintf( 'Put back the settings job %s changed?', $job->data['id'] ), $assoc );
		$deleted = 0;
		do {
			$result   = MigratePlus_Runner::rollback_batch( $job, 100 );
			$deleted += $result['deleted'];
			if ( ! empty( $result['error'] ) ) {
				WP_CLI::error( sprintf( '%s (%d items deleted before it stopped.)', $result['error'], $deleted ) );
			}
		} while ( ! $result['done'] );
		WP_CLI::success( $count ? sprintf( 'Deleted %d items.', $deleted ) : sprintf( 'Rolled back job %s: what it changed was put back.', $job->data['id'] ) );
	}

	/**
	 * List import jobs.
	 *
	 * ## OPTIONS
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
		foreach ( MigratePlus_Job::all() as $job ) {
			$d      = $job->data;
			$rows[] = array(
				'id'        => $d['id'],
				'created'   => wp_date( 'Y-m-d H:i', $d['created'] ),
				'source'    => $d['source'],
				'post_type' => ! empty( $d['settings']['migrateplus_post_types'] ) && false !== strpos( (string) $d['settings']['migrateplus_post_types'], ',' ) ? $d['settings']['migrateplus_post_types'] : $d['options']['post_type'],
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
	 * @param MigratePlus_Job $job Job.
	 */
	protected function run( MigratePlus_Job $job ) {
		$total    = $job->data['total'] ? (int) $job->data['total'] : 0;
		$progress = $total ? WP_CLI\Utils\make_progress_bar( 'Importing', max( 0, $total - (int) $job->data['stats']['processed'] ) ) : null;
		do {
			$result = MigratePlus_Runner::run_batch( $job );
			if ( is_wp_error( $result ) ) {
				if ( $progress ) {
					$progress->finish();
				}
				WP_CLI::error( $result->get_error_message() . sprintf( ' Resume with: wp migrateplus resume %s', $job->data['id'] ) );
			}
			foreach ( $result['results'] as $r ) {
				if ( 'failed' === $r['action'] ) {
					WP_CLI::warning( $r['message'] );
				} elseif ( ! empty( $r['warning'] ) ) {
					WP_CLI::warning( $r['message'] );
				} elseif ( ! $progress || ! empty( $r['section'] ) ) {
					WP_CLI::log( $r['message'] );
				}
				// Side sections (terms, menus...) come apart from the records the bar counts.
				if ( $progress && empty( $r['section'] ) ) {
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
		$s       = $job->data['stats'];
		$summary = sprintf( '%s%d created, %d updated, %d skipped, %d failed. Job id: %s', ! empty( $job->data['options']['dry_run'] ) ? '[dry run] ' : '', $s['created'], $s['updated'], $s['skipped'], $s['failed'], $job->data['id'] );
		// A non-zero exit, so a script running the import notices the failed records.
		if ( $s['failed'] ) {
			WP_CLI::error( $summary );
		}
		WP_CLI::success( $summary );
	}

	/**
	 * The user an --author (or a --config file's author) names, or stop: the admin's test,
	 * someone its picker would list.
	 *
	 * @param MigratePlus_Job $job  Job, deleted when stopping.
	 * @param string          $pick Id, login or email.
	 * @return int User id.
	 */
	protected function author_id( MigratePlus_Job $job, $pick ) {
		$user = is_numeric( $pick ) ? get_userdata( (int) $pick ) : get_user_by( 'login', $pick );
		$user = $user ? $user : get_user_by( 'email', $pick );
		if ( ! $user ) {
			$this->abandon( $job, sprintf( 'Author "%s" not found.', $pick ) );
		}
		if ( ! user_can( $user, 'edit_posts' ) ) {
			$this->abandon( $job, sprintf( 'Author "%s" may not write posts.', $pick ) );
		}
		return (int) $user->ID;
	}

	/**
	 * Delete the job preview() saved, then stop with an error.
	 *
	 * @param MigratePlus_Job $job     Job.
	 * @param string          $message Error.
	 */
	protected function abandon( MigratePlus_Job $job, $message ) {
		$job->delete();
		WP_CLI::error( $message );
	}

	/**
	 * Whether there is a terminal to ask questions in.
	 *
	 * @return bool
	 */
	protected function interactive() {
		return function_exists( 'posix_isatty' ) && posix_isatty( STDIN );
	}

	/**
	 * A switch's value: true or false from --flag or --no-flag, or yes/no/true/false/1/0 given to it.
	 *
	 * @param mixed  $value Value.
	 * @param string $flag  Flag name.
	 * @return int 1 or 0.
	 */
	protected function switch_value( $value, $flag ) {
		if ( is_bool( $value ) ) {
			return (int) $value;
		}
		$value = strtolower( trim( (string) $value ) );
		if ( in_array( $value, array( 'yes', 'true', '1' ), true ) ) {
			return 1;
		}
		if ( in_array( $value, array( 'no', 'false', '0' ), true ) ) {
			return 0;
		}
		WP_CLI::error( sprintf( '--%s takes yes, no, true, false, 1 or 0.', $flag ) );
	}

	/**
	 * Stop on a mapping that is not shaped like one, rather than import with it emptied.
	 *
	 * @param mixed  $mapping Mapping from a file.
	 * @param string $from    The file, for the message.
	 */
	protected function check_mapping( $mapping, $from ) {
		$keys = array( 'fields', 'meta', 'taxonomies', 'acf' );
		if ( ! is_array( $mapping ) || ! array_intersect( $keys, array_keys( $mapping ) ) ) {
			WP_CLI::error( sprintf( '%s has no mapping: expected an object with "fields", and optionally "meta", "taxonomies" and "acf".', $from ) );
		}
		foreach ( $keys as $key ) {
			if ( isset( $mapping[ $key ] ) && ! is_array( $mapping[ $key ] ) ) {
				WP_CLI::error( sprintf( 'In %s, "%s" must be a JSON object or list.', $from, $key ) );
			}
		}
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
		$all = MigratePlus_Sources::all();
		if ( empty( $all[ $source ] ) || ! MigratePlus_Sources::usable( $all[ $source ] ) ) {
			return true; // Unknown source: preview() reports that.
		}
		foreach ( call_user_func( array( $all[ $source ], 'settings_schema' ) ) as $field ) {
			if ( 'file' === $field['key'] ) {
				return MigratePlus_Source_Base::allows_remote( $field );
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
