<?php
/**
 * Saved field mappings for JSON files that are not MigratePlus exports.
 *
 * @package MigratePlus
 */

defined( 'ABSPATH' ) || exit;

/**
 * A saved mapping is the field mapping plus the import options of one import,
 * under a name, so the next file from the same place needs no mapping by hand.
 * Mappings are site-wide: anyone who may run imports can save, load and delete
 * them. They live in one option that is not autoloaded.
 *
 * Each also keeps the source fields of the file it was saved from. A new file
 * with the same top-level fields (in any order) is taken to come from the same
 * place, and the mapping is offered for it. Only the top level is compared:
 * nested lists flatten to keys such as "authors.2.name", which vary from file
 * to file with the data.
 *
 * The sanitizing of mappings and options lives here, shared by the admin
 * (which also checks that the user may edit the post type) and WP-CLI.
 */
class MigratePlus_Mappings {

	const OPTION = 'migrateplus_mappings';
	// The option is read whole on every import screen, so it is kept small.
	const MAX = 50;

	/**
	 * Every saved mapping, by name, in name order.
	 *
	 * @return array[] name => { name, fields, mapping, options, updated }
	 */
	public static function all() {
		$all = get_option( self::OPTION, array() );
		return is_array( $all ) ? $all : array();
	}

	/**
	 * @param string $name Name.
	 * @return array|null
	 */
	public static function get( $name ) {
		$all  = self::all();
		$name = self::clean_name( $name );
		return isset( $all[ $name ] ) ? $all[ $name ] : null;
	}

	/**
	 * Save (or overwrite) a mapping.
	 *
	 * @param string   $name    Name.
	 * @param array    $mapping Mapping, as for an import.
	 * @param array    $options Import options.
	 * @param string[] $fields  Source fields of the file it was made for.
	 * @return array|WP_Error The saved entry.
	 */
	public static function save( $name, array $mapping, array $options, array $fields ) {
		$name = self::clean_name( $name );
		if ( '' === $name ) {
			return new WP_Error( 'migrateplus_mapping_name', __( 'Give the mapping a name.', 'migrateplus' ) );
		}
		$all = self::all();
		if ( ! isset( $all[ $name ] ) && count( $all ) >= self::MAX ) {
			/* translators: %d: maximum number of saved mappings */
			return new WP_Error( 'migrateplus_mapping_limit', sprintf( __( 'There are already %d saved mappings, the most Migrate Plus keeps. Delete one you no longer use, or save over an existing one.', 'migrateplus' ), self::MAX ) );
		}
		$entry = array(
			'name'    => $name,
			'fields'  => array_values( array_unique( array_map( array( __CLASS__, 'clean_field_name' ), $fields ) ) ),
			'mapping' => self::clean_mapping( $mapping ),
			// What to do with this file's records, not how one run went: the per-run choices stay behind.
			// Only the options given are kept: one left out takes the MigratePlus setting when the mapping is used, as in a --config file.
			'options' => array_diff_key( array_intersect_key( self::clean_options( $options ), $options ), array_flip( array( 'skip_existing', 'update_ids', 'replace_ids', 'copy_ids', 'dry_run' ) ) ),
			'updated' => time(),
		);
		$all[ $name ] = $entry;
		self::store( $all );
		return $entry;
	}

	/**
	 * @param string $old Current name.
	 * @param string $new New name.
	 * @return true|WP_Error
	 */
	public static function rename( $old, $new ) {
		$all = self::all();
		$old = self::clean_name( $old );
		$new = self::clean_name( $new );
		if ( ! isset( $all[ $old ] ) ) {
			return new WP_Error( 'migrateplus_mapping_missing', __( 'This saved mapping no longer exists.', 'migrateplus' ) );
		}
		if ( '' === $new ) {
			return new WP_Error( 'migrateplus_mapping_name', __( 'Give the mapping a name.', 'migrateplus' ) );
		}
		if ( $new !== $old && isset( $all[ $new ] ) ) {
			/* translators: %s: mapping name */
			return new WP_Error( 'migrateplus_mapping_name', sprintf( __( 'A saved mapping called "%s" already exists.', 'migrateplus' ), $new ) );
		}
		$entry         = $all[ $old ];
		$entry['name'] = $new;
		unset( $all[ $old ] );
		$all[ $new ] = $entry;
		self::store( $all );
		return true;
	}

	/**
	 * @param string $name Name.
	 * @return bool Whether it existed.
	 */
	public static function delete( $name ) {
		$all  = self::all();
		$name = self::clean_name( $name );
		if ( ! isset( $all[ $name ] ) ) {
			return false;
		}
		unset( $all[ $name ] );
		self::store( $all );
		return true;
	}

	/**
	 * The saved mapping made for a file with the same top-level fields, the most
	 * recently saved when several are.
	 *
	 * @param string[] $fields Source fields of a file.
	 * @return string Its name, or '' for none.
	 */
	public static function match( array $fields ) {
		$top  = self::top_fields( $fields );
		$best = null;
		foreach ( self::all() as $entry ) {
			if ( $top && self::top_fields( (array) $entry['fields'] ) === $top && ( ! $best || $entry['updated'] > $best['updated'] ) ) {
				$best = $entry;
			}
		}
		return $best ? $best['name'] : '';
	}

	/**
	 * @param string[] $fields Flattened field names.
	 * @return string[] The part before the first dot of each, sorted.
	 */
	protected static function top_fields( array $fields ) {
		$top = array();
		foreach ( $fields as $field ) {
			$top[ explode( '.', (string) $field, 2 )[0] ] = true;
		}
		$top = array_map( 'strval', array_keys( $top ) );
		sort( $top, SORT_STRING );
		return $top;
	}

	/**
	 * A saved mapping as a WP-CLI config file (the shape of examples/migration-config.json),
	 * plus its name and fields so `wp migrateplus mapping import` can restore it whole.
	 *
	 * @param array $entry Saved mapping.
	 * @return array
	 */
	public static function to_config( array $entry ) {
		return array(
			'name'     => $entry['name'],
			'source'   => 'json',
			'settings' => new stdClass(), // Pass the file with --file.
			'mapping'  => $entry['mapping'],
			'options'  => $entry['options'],
			'fields'   => $entry['fields'],
		);
	}

	/**
	 * Saved mappings for the browser.
	 *
	 * @return array[]
	 */
	public static function for_js() {
		return array_values( self::all() );
	}

	/**
	 * @param array $all Mappings by name.
	 */
	protected static function store( array $all ) {
		uksort( $all, 'strcasecmp' );
		update_option( self::OPTION, $all, false );
	}

	/**
	 * @param mixed $name Name from a request or the command line.
	 * @return string
	 */
	public static function clean_name( $name ) {
		$name = sanitize_text_field( (string) $name );
		return function_exists( 'mb_substr' ) ? mb_substr( $name, 0, 100 ) : substr( $name, 0, 100 );
	}

	/**
	 * @param array $mapping Mapping from a request or file.
	 * @return array
	 */
	public static function clean_mapping( array $mapping ) {
		$clean_map = array(
			'fields'     => array(),
			'meta'       => array(),
			'taxonomies' => array(),
			'acf'        => array(),
		);
		$targets   = MigratePlus_Importer::targets();
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
	public static function clean_field_name( $name ) {
		return (string) preg_replace( '/[\x00-\x1F\x7F]/', '', wp_check_invalid_utf8( (string) $name, true ) );
	}

	/**
	 * Import options from a request or file. Whether the user may import into the
	 * post type is for the caller to check.
	 *
	 * @param array $options Import options.
	 * @return array
	 */
	public static function clean_options( array $options ) {
		$defaults  = MigratePlus_Job::default_options();
		$post_type = isset( $options['post_type'] ) ? sanitize_key( $options['post_type'] ) : 'post';
		$status    = isset( $options['default_status'] ) ? sanitize_key( $options['default_status'] ) : 'publish';
		$force     = isset( $options['force_status'] ) ? sanitize_key( $options['force_status'] ) : '';
		$duplicate = isset( $options['duplicate'] ) ? sanitize_key( $options['duplicate'] ) : 'skip';
		$author    = isset( $options['default_author'] ) ? absint( $options['default_author'] ) : get_current_user_id();
		return array(
			'post_type'             => post_type_exists( $post_type ) ? $post_type : 'post',
			'default_status'        => in_array( $status, array( 'publish', 'draft', 'pending', 'private' ), true ) ? $status : 'draft',
			'force_status'          => in_array( $force, array( 'publish', 'draft', 'pending', 'private' ), true ) ? $force : '',
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
			'import_comments'       => empty( $options['import_comments'] ) ? 0 : 1,
			'import_menus'          => empty( $options['import_menus'] ) ? 0 : 1,
			'import_term_data'      => empty( $options['import_term_data'] ) ? 0 : 1,
			'dry_run'               => empty( $options['dry_run'] ) ? 0 : 1,
		);
	}
}
