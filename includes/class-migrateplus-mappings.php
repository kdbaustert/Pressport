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
 * Mappings are site-wide: anyone who may run imports can save and load them, but
 * only the person who saved one (or an administrator) may overwrite, rename or
 * delete it. They live in one option that is not autoloaded.
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
	 * @return array[] name => { name, fields, mapping, options, updated, user }
	 */
	public static function all() {
		$all = get_option( self::OPTION, array() );
		$all = is_array( $all ) ? $all : array();
		foreach ( $all as $name => $entry ) {
			// Saved before authors stopped travelling: authors are picked for each import, as ids differ per site.
			if ( isset( $entry['options'] ) && is_array( $entry['options'] ) ) {
				$all[ $name ]['options'] = array_diff_key( $entry['options'], array_flip( array( 'author', 'authors', 'default_author', 'create_authors' ) ) );
			}
		}
		return $all;
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
		$entry = array(
			'name'    => $name,
			'fields'  => array_values( array_unique( array_map( array( __CLASS__, 'clean_field_name' ), $fields ) ) ),
			'mapping' => self::clean_mapping( $mapping ),
			// What to do with this file's records, not how one run went: the per-run choices stay behind.
			// Only the options given are kept: one left out takes the MigratePlus setting when the mapping is used, as in a --config file.
			// Nor the authors: user ids differ from site to site.
			'options' => array_diff_key( array_intersect_key( self::clean_options( $options ), $options ), array_flip( array( 'skip_existing', 'update_ids', 'replace_ids', 'copy_ids', 'skip_ids', 'duplicate_rest', 'dry_run', 'author', 'authors' ) ) ),
			'updated' => time(),
			'user'    => get_current_user_id(),
		);
		$done = self::change(
			static function ( array $all ) use ( $name, $entry ) {
				if ( isset( $all[ $name ] ) && ! self::may_change( $all[ $name ] ) ) {
					return self::not_yours();
				}
				if ( ! isset( $all[ $name ] ) && count( $all ) >= self::MAX ) {
					/* translators: %d: maximum number of saved mappings */
					return new WP_Error( 'migrateplus_mapping_limit', sprintf( __( 'There are already %d saved mappings, the most Migrate Plus keeps. Delete one you no longer use, or save over an existing one.', 'migrateplus' ), self::MAX ) );
				}
				$all[ $name ] = $entry;
				return $all;
			}
		);
		return is_wp_error( $done ) ? $done : $entry;
	}

	/**
	 * @param string $old Current name.
	 * @param string $new New name.
	 * @return true|WP_Error
	 */
	public static function rename( $old, $new ) {
		$old = self::clean_name( $old );
		$new = self::clean_name( $new );
		return self::change(
			static function ( array $all ) use ( $old, $new ) {
				if ( ! isset( $all[ $old ] ) ) {
					return new WP_Error( 'migrateplus_mapping_missing', __( 'This saved mapping no longer exists.', 'migrateplus' ) );
				}
				if ( ! self::may_change( $all[ $old ] ) ) {
					return self::not_yours();
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
				return $all;
			}
		);
	}

	/**
	 * @param string $name Name.
	 * @return bool|WP_Error Whether it existed, or why it may not be deleted.
	 */
	public static function delete( $name ) {
		$name = self::clean_name( $name );
		$done = self::change(
			static function ( array $all ) use ( $name ) {
				if ( ! isset( $all[ $name ] ) ) {
					return false;
				}
				if ( ! self::may_change( $all[ $name ] ) ) {
					return self::not_yours();
				}
				unset( $all[ $name ] );
				return $all;
			}
		);
		return false === $done || is_wp_error( $done ) ? $done : true;
	}

	/**
	 * Whether the current user may overwrite, rename or delete a saved mapping: whoever
	 * saved it, or an administrator. WP-CLI without --user runs with the shell account's
	 * access. Mappings saved before they had an owner are an administrator's alone.
	 *
	 * @param array $entry Saved mapping.
	 * @return bool
	 */
	public static function may_change( array $entry ) {
		if ( ( defined( 'WP_CLI' ) && WP_CLI && ! get_current_user_id() ) || current_user_can( 'manage_options' ) ) {
			return true;
		}
		return isset( $entry['user'] ) && get_current_user_id() === (int) $entry['user'];
	}

	/**
	 * @return WP_Error
	 */
	protected static function not_yours() {
		return new WP_Error( 'migrateplus_mapping_owner', __( 'Only the person who saved this mapping, or an administrator, can change or delete it.', 'migrateplus' ) );
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
	 * Change the saved mappings under a lock, on a fresh copy, so two people saving at
	 * once can't each write their own copy and lose the other's mapping.
	 *
	 * @param callable $change Gets every mapping by name; returns them changed, false to change
	 *                         nothing, or a WP_Error to refuse.
	 * @return true|false|WP_Error What $change returned, true when it saved.
	 */
	protected static function change( callable $change ) {
		$out = true;
		MigratePlus_Util::update_option_locked(
			self::OPTION,
			static function ( $all ) use ( $change, &$out ) {
				$all = $change( is_array( $all ) ? $all : array() );
				if ( ! is_array( $all ) ) {
					$out = $all;
					return null;
				}
				uksort( $all, 'strcasecmp' );
				return $all;
			}
		);
		return $out;
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
	 * @param array    $options Import options.
	 * @param int|null $total   Records in the job, which the per-record authors may not go past; null when unknown.
	 * @return array
	 */
	public static function clean_options( array $options, $total = null ) {
		$defaults  = MigratePlus_Job::default_options();
		$post_type = isset( $options['post_type'] ) ? sanitize_key( $options['post_type'] ) : 'post';
		$status    = isset( $options['default_status'] ) ? sanitize_key( $options['default_status'] ) : 'publish';
		$force     = isset( $options['force_status'] ) ? sanitize_key( $options['force_status'] ) : '';
		$duplicate = isset( $options['duplicate'] ) ? sanitize_key( $options['duplicate'] ) : 'skip';
		$rest      = isset( $options['duplicate_rest'] ) && is_scalar( $options['duplicate_rest'] ) ? sanitize_key( $options['duplicate_rest'] ) : '';
		$post_type = post_type_exists( $post_type ) ? $post_type : 'post';
		$author    = isset( $options['author'] ) && is_scalar( $options['author'] ) ? absint( $options['author'] ) : 0;
		// The picker lists whoever may write posts, so the same test here (a file can hold several types),
		// or 0 for none: the posts then go to whoever runs the import.
		$author = self::may_write_posts( $author ) ? $author : 0;
		return array(
			'post_type'              => $post_type,
			'default_status'         => in_array( $status, array( 'publish', 'draft', 'pending', 'private' ), true ) ? $status : 'draft',
			'force_status'           => in_array( $force, array( 'publish', 'draft', 'pending', 'private' ), true ) ? $force : '',
			'author'                 => $author,
			'authors'                => self::clean_authors( isset( $options['authors'] ) ? $options['authors'] : array(), $total ),
			'duplicate'              => in_array( $duplicate, array( 'skip', 'update', 'create' ), true ) ? $duplicate : 'skip',
			'skip_existing'          => empty( $options['skip_existing'] ) ? 0 : 1,
			'update_ids'             => array_values( array_filter( array_map( 'absint', isset( $options['update_ids'] ) ? (array) $options['update_ids'] : array() ) ) ),
			'replace_ids'            => array_values( array_filter( array_map( 'absint', isset( $options['replace_ids'] ) ? (array) $options['replace_ids'] : array() ) ) ),
			'copy_ids'               => array_values( array_filter( array_map( 'absint', isset( $options['copy_ids'] ) ? (array) $options['copy_ids'] : array() ) ) ),
			'skip_ids'               => array_values( array_filter( array_map( 'absint', isset( $options['skip_ids'] ) ? (array) $options['skip_ids'] : array() ) ) ),
			'duplicate_rest'         => in_array( $rest, array( 'skip', 'update', 'copy' ), true ) ? $rest : '',
			'batch_size'             => max( 1, min( 100, isset( $options['batch_size'] ) ? (int) $options['batch_size'] : $defaults['batch_size'] ) ),
			'import_featured_image'  => empty( $options['import_featured_image'] ) ? 0 : 1,
			'import_content_images'  => empty( $options['import_content_images'] ) ? 0 : 1,
			'create_terms'           => empty( $options['create_terms'] ) ? 0 : 1,
			'rewrite_links'          => empty( $options['rewrite_links'] ) ? 0 : 1,
			'import_comments'        => empty( $options['import_comments'] ) ? 0 : 1,
			'import_menus'           => empty( $options['import_menus'] ) ? 0 : 1,
			'import_term_data'       => empty( $options['import_term_data'] ) ? 0 : 1,
			// Each side section reads its own switch straight from the job's options.
			'import_acf_definitions' => empty( $options['import_acf_definitions'] ) ? 0 : 1,
			'import_acf_options'     => empty( $options['import_acf_options'] ) ? 0 : 1,
			'import_site_settings'   => empty( $options['import_site_settings'] ) ? 0 : 1,
			'import_orders'          => empty( $options['import_orders'] ) ? 0 : 1,
			'import_redirects'       => empty( $options['import_redirects'] ) ? 0 : 1,
			'dry_run'                => empty( $options['dry_run'] ) ? 0 : 1,
		);
	}

	/**
	 * The authors picked for single records, by the record's position in the file (0 for the
	 * first): each must be someone who may write posts, as for the author of all of them.
	 * Anything else is dropped, as are positions that are not whole numbers or that go past
	 * the job's records.
	 *
	 * @param mixed    $authors Position => user id, from a request or file.
	 * @param int|null $total   Records in the job, or null when unknown.
	 * @return int[] Position => user id.
	 */
	public static function clean_authors( $authors, $total = null ) {
		$clean = array();
		foreach ( is_array( $authors ) ? $authors : array() as $index => $user ) {
			if ( ! is_int( $index ) || $index < 0 || ( null !== $total && $index >= (int) $total ) || ! is_scalar( $user ) ) {
				continue;
			}
			$user = absint( $user );
			if ( self::may_write_posts( $user ) ) {
				$clean[ $index ] = $user;
			}
		}
		ksort( $clean );
		return $clean;
	}

	/**
	 * Whether a user may be picked as an author: an account here that may write posts.
	 *
	 * @param int $user User id.
	 * @return bool
	 */
	public static function may_write_posts( $user ) {
		return (int) $user > 0 && get_userdata( $user ) && user_can( $user, 'edit_posts' );
	}
}
