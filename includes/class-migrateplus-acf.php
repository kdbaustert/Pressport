<?php
/**
 * Advanced Custom Fields integration.
 *
 * @package MigratePlus
 */

defined( 'ABSPATH' ) || exit;

/**
 * Maps source data onto ACF fields and saves it with update_field(), converting
 * each value to what the field type stores: attachment ids for images, files and
 * galleries; post ids for relationships; term ids; user ids; Ymd dates; choice
 * keys; and nested rows for repeaters, groups and flexible content.
 *
 * Mapping format (job mapping "acf"): { "<field key or name>": "<source field>" }.
 */
class MigratePlus_ACF {

	/**
	 * Post meta holding relationship links that wait for a later post.
	 *
	 * @var string
	 */
	const PENDING_META = '_migrateplus_acf_pending';

	/**
	 * Field types that hold no data.
	 *
	 * @var string[]
	 */
	const LAYOUT_TYPES = array( 'tab', 'message', 'accordion' );

	/**
	 * Field types whose post references wait for a post later in the file: the
	 * reference fields, and the rows that can hold one (a clone's storage is
	 * the cloned fields', so its references are not followed).
	 *
	 * @var string[]
	 */
	const DEFER_TYPES = array( 'post_object', 'relationship', 'page_link', 'group', 'repeater', 'flexible_content' );

	/**
	 * Post types a reference never resolves to.
	 *
	 * @var string[]
	 */
	const NOT_REFERENCED = array( 'revision', 'nav_menu_item' );

	/**
	 * Whether ACF (free or Pro) is active.
	 *
	 * @return bool
	 */
	public static function active() {
		return function_exists( 'acf_get_field_groups' ) && function_exists( 'acf_get_fields' ) && function_exists( 'update_field' );
	}

	/**
	 * Top-level ACF fields shown for a post type, grouped by field group.
	 *
	 * @param string $post_type Post type.
	 * @param int    $post_id   Optional post: groups shown by its terms, template or ID only match with it.
	 * @return array[] { key, name, label, type, group, required, sub }
	 */
	public static function fields_for_post_type( $post_type, $post_id = 0 ) {
		if ( ! self::active() ) {
			return array();
		}
		$out  = array();
		$args = array( 'post_type' => $post_type );
		if ( $post_id ) {
			$args['post_id'] = (int) $post_id;
		}
		foreach ( (array) acf_get_field_groups( $args ) as $group ) {
			if ( isset( $group['active'] ) && ! $group['active'] ) {
				continue;
			}
			foreach ( (array) acf_get_fields( $group ) as $field ) {
				if ( empty( $field['name'] ) || in_array( $field['type'], self::LAYOUT_TYPES, true ) ) {
					continue;
				}
				$sub = array();
				foreach ( self::sub_fields( $field ) as $s ) {
					$sub[] = $s['name'];
				}
				$out[] = array(
					'key'      => $field['key'],
					'name'     => $field['name'],
					'label'    => $field['label'] ? $field['label'] : $field['name'],
					'type'     => $field['type'],
					'group'    => $group['title'],
					'required' => ! empty( $field['required'] ),
					'sub'      => array_values( array_unique( $sub ) ),
				);
			}
		}
		return $out;
	}

	/**
	 * Top-level ACF fields shown for a taxonomy's terms, grouped by field group.
	 *
	 * @param string $taxonomy Taxonomy.
	 * @return array[] { key, name, label, type, group, required, sub }
	 */
	public static function fields_for_taxonomy( $taxonomy ) {
		if ( ! self::active() ) {
			return array();
		}
		$out = array();
		foreach ( (array) acf_get_field_groups( array( 'taxonomy' => $taxonomy ) ) as $group ) {
			if ( isset( $group['active'] ) && ! $group['active'] ) {
				continue;
			}
			foreach ( (array) acf_get_fields( $group ) as $field ) {
				if ( empty( $field['name'] ) || in_array( $field['type'], self::LAYOUT_TYPES, true ) ) {
					continue;
				}
				$out[] = array(
					'key'   => $field['key'],
					'name'  => $field['name'],
					'label' => $field['label'] ? $field['label'] : $field['name'],
					'type'  => $field['type'],
					'group' => $group['title'],
				);
			}
		}
		return $out;
	}

	/**
	 * Save a term's exported ACF values ({ field name => portable value }).
	 * Post references that are not on the site yet are linked when the post they
	 * name arrives (see link_pending()).
	 *
	 * @param int             $term_id  Term id.
	 * @param string          $taxonomy Taxonomy.
	 * @param array           $values   Exported values.
	 * @param MigratePlus_Job $job      Job.
	 * @param array           $opts     Job options.
	 * @param string[]        $warnings Warnings (by reference).
	 * @return int Fields saved.
	 */
	public static function import_term_fields( $term_id, $taxonomy, array $values, MigratePlus_Job $job, array $opts, array &$warnings ) {
		if ( ! self::active() ) {
			return 0;
		}
		$by_name = array();
		foreach ( self::fields_for_taxonomy( $taxonomy ) as $f ) {
			$by_name[ $f['name'] ] = $f['key'];
		}
		$ctx   = array(
			'post_id'      => 'term_' . (int) $term_id,
			'job_id'       => $job->data['id'],
			'job'          => $job,
			'base'         => isset( $job->data['settings']['migrateplus_site'] ) ? (string) $job->data['settings']['migrateplus_site'] : '',
			'create_terms' => ! empty( $opts['create_terms'] ),
			'rewrite'      => ! empty( $opts['rewrite_links'] ),
			'warnings'     => array(),
		);
		$saved = 0;
		foreach ( $values as $name => $raw ) {
			if ( null === $raw || '' === $raw || array() === $raw ) {
				continue;
			}
			$field = isset( $by_name[ $name ] ) ? acf_get_field( $by_name[ $name ] ) : false;
			if ( ! $field ) {
				/* translators: %s: field name */
				$warnings[] = sprintf( __( 'ACF field "%s" is not on this taxonomy.', 'migrateplus' ), $name );
				continue;
			}
			$ctx['defer']   = in_array( $field['type'], self::DEFER_TYPES, true );
			$ctx['pending'] = array();
			$ctx['waits']   = array();
			$ctx['path']    = array();
			$value          = self::convert( $field, $raw, $ctx );
			// Slashed as from a form post, like a post's fields (see import()).
			if ( update_field( $field['key'], wp_slash( $value ), 'term_' . (int) $term_id ) || null !== $value ) {
				++$saved;
			}
			self::set_term_pending( (int) $term_id, $field['key'], $ctx['waits'] );
		}
		foreach ( array_unique( $ctx['warnings'] ) as $w ) {
			$warnings[] = $w;
		}
		return $saved;
	}

	/**
	 * ACF data for the admin screen.
	 *
	 * @param string[] $post_types Post type names.
	 * @return array
	 */
	public static function bootstrap( array $post_types ) {
		$fields = array();
		if ( self::active() ) {
			foreach ( $post_types as $pt ) {
				$fields[ $pt ] = self::fields_for_post_type( $pt );
			}
		}
		return array(
			'active' => self::active(),
			'pro'    => defined( 'ACF_PRO' ) && ACF_PRO,
			'fields' => $fields,
		);
	}

	/**
	 * All sub fields of a repeater/group, or of every flexible content layout.
	 *
	 * @param array $field Field.
	 * @return array[]
	 */
	protected static function sub_fields( array $field ) {
		$subs = array();
		if ( ! empty( $field['sub_fields'] ) ) {
			$subs = (array) $field['sub_fields'];
		}
		if ( ! empty( $field['layouts'] ) ) {
			foreach ( (array) $field['layouts'] as $layout ) {
				$subs = array_merge( $subs, isset( $layout['sub_fields'] ) ? (array) $layout['sub_fields'] : array() );
			}
		}
		return array_filter(
			$subs,
			static function ( $s ) {
				return ! empty( $s['name'] ) && ! in_array( $s['type'], self::LAYOUT_TYPES, true );
			}
		);
	}

	/**
	 * Load a field by key, or by name among the top-level fields of the groups
	 * shown on the post. ACF's own name lookup falls back to the first field with
	 * that name anywhere on the site, sub fields included.
	 *
	 * @param string $selector Field key or name.
	 * @param int    $post_id  Post id (names resolve against its field groups).
	 * @return array|false
	 */
	public static function get_field_object( $selector, $post_id = 0 ) {
		if ( 0 === strpos( $selector, 'field_' ) && function_exists( 'acf_get_field' ) ) {
			$field = acf_get_field( $selector );
			if ( $field ) {
				return $field;
			}
		}
		$type = $post_id ? get_post_type( $post_id ) : '';
		foreach ( $type ? self::fields_for_post_type( $type, $post_id ) : array() as $f ) {
			if ( $f['name'] === $selector ) {
				return acf_get_field( $f['key'] );
			}
		}
		return false;
	}

	/**
	 * Read a source value. When the exact key is missing, rebuild nested data from
	 * flattened keys ("items.0.title", "items.1.title" => [ [ title ], [ title ] ]).
	 *
	 * @param array  $record Flat record.
	 * @param string $source Source field.
	 * @return mixed|null
	 */
	public static function read( array $record, $source ) {
		if ( array_key_exists( $source, $record ) ) {
			$value = $record[ $source ];
			// flatten() keeps true, false and null so they survive inside nested values; a field read
			// on its own is stored as it was before, "1", "0" and "" (update_post_meta( false ) would store "").
			if ( is_bool( $value ) ) {
				return $value ? '1' : '0';
			}
			return null === $value ? '' : $value;
		}
		$prefix = $source . '.';
		$len    = strlen( $prefix );
		$tree   = array();
		$found  = false;
		foreach ( $record as $key => $value ) {
			$key = (string) $key;
			if ( 0 !== strpos( $key, $prefix ) ) {
				continue;
			}
			$found = true;
			// flatten() stops at MAX_DEPTH and writes what is deeper as JSON text: decode it back.
			if ( is_string( $value ) && MigratePlus_Util::MAX_DEPTH === substr_count( $key, '.' ) ) {
				$decoded = json_decode( $value, true );
				$value   = is_array( $decoded ) ? $decoded : $value;
			}
			$node = &$tree;
			foreach ( explode( '.', substr( $key, $len ) ) as $segment ) {
				if ( ! isset( $node[ $segment ] ) || ! is_array( $node[ $segment ] ) ) {
					$node[ $segment ] = array();
				}
				$node = &$node[ $segment ];
			}
			$node = $value;
			unset( $node );
		}
		// Keys come back in the order flatten() wrote them, so a list is already 0..n-1 and
		// a map keeps its keys, numeric ones too ({ "5": 10, "4": 3 } is not a list).
		return $found ? $tree : null;
	}

	/**
	 * Decode JSON strings ("[...]" / "{...}") into arrays.
	 *
	 * @param mixed $value Value.
	 * @return mixed
	 */
	protected static function maybe_json( $value ) {
		if ( is_string( $value ) ) {
			$trim = trim( $value );
			if ( '' !== $trim && ( '[' === $trim[0] || '{' === $trim[0] ) ) {
				$decoded = json_decode( $trim, true );
				if ( is_array( $decoded ) ) {
					return $decoded;
				}
			}
		}
		return $value;
	}

	/**
	 * Normalize a value into a list of items (arrays are kept, strings are split).
	 *
	 * @param mixed $value Value.
	 * @return array
	 */
	protected static function items( $value ) {
		$value = self::maybe_json( $value );
		if ( is_array( $value ) ) {
			// An empty list is no items; range( 0, -1 ) is [ 0, -1 ], so the test below would wrap it as one.
			if ( ! $value ) {
				return array();
			}
			// A single object (e.g. { url: ... }) is one item, not a list of fields.
			return array_keys( $value ) === range( 0, count( $value ) - 1 ) ? $value : array( $value );
		}
		return MigratePlus_Util::to_list( $value );
	}

	/**
	 * Save all mapped ACF fields for a post.
	 *
	 * @param int             $post_id  Post id.
	 * @param array           $record   Flat record.
	 * @param array           $map      field key/name => source field.
	 * @param MigratePlus_Job $job      Job.
	 * @param array           $opts     Job options.
	 * @param string          $base     Base URL for relative media.
	 * @param string[]        $warnings Warnings (by reference).
	 * @return int Number of fields saved.
	 */
	public static function import( $post_id, array $record, array $map, MigratePlus_Job $job, array $opts, $base, array &$warnings ) {
		if ( ! $map ) {
			return 0;
		}
		if ( ! self::active() ) {
			$warnings[] = __( 'ACF fields are mapped but Advanced Custom Fields is not active.', 'migrateplus' );
			return 0;
		}
		$ctx   = array(
			'post_id'      => (int) $post_id,
			'job_id'       => $job->data['id'],
			'job'          => $job,
			'base'         => (string) $base,
			'create_terms' => ! empty( $opts['create_terms'] ),
			'rewrite'      => ! empty( $opts['rewrite_links'] ),
			'warnings'     => array(),
		);
		$saved = 0;
		foreach ( $map as $selector => $source ) {
			if ( '' === (string) $selector || '' === (string) $source ) {
				continue;
			}
			$raw = self::read( $record, (string) $source );
			if ( null === $raw || '' === $raw || array() === $raw ) {
				continue;
			}
			$field = self::get_field_object( (string) $selector, $post_id );
			if ( ! $field ) {
				/* translators: %s: field key or name */
				$warnings[] = sprintf( __( 'ACF field "%s" was not found.', 'migrateplus' ), $selector );
				continue;
			}
			// Post references wait for posts later in the file, inside repeater, group and flexible
			// content rows too: the pending row records the way down to the sub field (see set_pending()).
			$ctx['defer']   = in_array( $field['type'], self::DEFER_TYPES, true );
			$ctx['pending'] = array();
			$ctx['waits']   = array();
			$ctx['path']    = array();
			$value          = self::convert( $field, $raw, $ctx );
			$value          = apply_filters( 'migrateplus_acf_value', $value, $field, $raw, $post_id, $job );
			// ACF expects slashed data, as from a form post: post meta goes to update_metadata(),
			// which unslashes, and options are unslashed by ACF itself. Unslashed, "C:\Users" loses its backslashes.
			if ( update_field( $field['key'], wp_slash( $value ), $post_id ) || null !== $value ) {
				++$saved;
			}
			self::set_pending( (int) $post_id, $field['key'], $ctx['waits'] );
		}
		foreach ( array_unique( $ctx['warnings'] ) as $w ) {
			$warnings[] = $w;
		}
		return $saved;
	}

	/**
	 * Convert a raw source value to what an ACF field type stores.
	 *
	 * @param array $field Field.
	 * @param mixed $value Raw value.
	 * @param array $ctx   Context (by reference, collects warnings).
	 * @return mixed
	 */
	public static function convert( array $field, $value, array &$ctx ) {
		$type  = isset( $field['type'] ) ? $field['type'] : 'text';
		$label = isset( $field['label'] ) && $field['label'] ? $field['label'] : ( isset( $field['name'] ) ? $field['name'] : '' );
		$multi = ! empty( $field['multiple'] );

		switch ( $type ) {
			case 'number':
			case 'range':
				// Spaces may group thousands; a comma is left alone, since "1,5" may be one and a half or fifteen.
				$s = str_replace( ' ', '', trim( MigratePlus_Util::stringify( $value ) ) );
				if ( '' !== $s && ! is_numeric( $s ) ) {
					/* translators: 1: field label, 2: value */
					$ctx['warnings'][] = sprintf( __( 'ACF "%1$s": "%2$s" is not a number, so it was left empty.', 'migrateplus' ), $label, $s );
				}
				return is_numeric( $s ) ? $s + 0 : '';

			case 'true_false':
				$s = strtolower( trim( MigratePlus_Util::stringify( $value ) ) );
				return in_array( $s, array( '1', 'true', 'yes', 'y', 'on', 'checked' ), true ) ? 1 : 0;

			case 'select':
			case 'checkbox':
			case 'radio':
			case 'button_group':
				$choices = isset( $field['choices'] ) ? (array) $field['choices'] : array();
				$picked  = array();
				foreach ( self::items( $value ) as $item ) {
					$picked[] = self::match_choice( MigratePlus_Util::stringify( $item ), $choices );
				}
				$picked = array_values( array_unique( array_filter( $picked, 'strlen' ) ) );
				if ( 'checkbox' === $type || ( 'select' === $type && $multi ) ) {
					return $picked;
				}
				return $picked ? $picked[0] : '';

			case 'date_picker':
				return self::format_date( $value, 'Ymd' );
			case 'date_time_picker':
				return self::format_date( $value, 'Y-m-d H:i:s' );
			case 'time_picker':
				return self::format_date( $value, 'H:i:s' );

			case 'image':
			case 'file':
				$id = self::attachment( $value, $ctx, $label );
				return $id ? $id : '';

			case 'gallery':
				$ids = array();
				foreach ( self::items( $value ) as $item ) {
					$id = self::attachment( $item, $ctx, $label );
					if ( $id ) {
						$ids[] = $id;
					}
				}
				return array_values( array_unique( $ids ) );

			case 'post_object':
			case 'relationship':
			case 'page_link':
				$types = isset( $field['post_type'] ) ? array_filter( (array) $field['post_type'] ) : array();
				$ids   = array();
				$list  = 'relationship' === $type || $multi;
				$slot  = 0; // Position among the posts kept, so one that arrives later takes its place in the order.
				foreach ( self::items( $value ) as $item ) {
					$id = self::resolve_post( $item, $types, isset( $ctx['job'] ) ? $ctx['job'] : null );
					if ( $id ) {
						$ids[] = $id;
						++$slot;
					} elseif ( 'page_link' === $type && is_string( $item ) && preg_match( '#^https?://#', $item ) ) {
						$ids[] = ! empty( $ctx['rewrite'] ) ? MigratePlus_Menus::rewrite_url( $item, $ctx['base'] ) : $item; // Page links may store plain URLs.
						++$slot;
					} elseif ( ! empty( $ctx['defer'] ) && is_array( $item ) && ! empty( $item['id'] ) ) {
						// An exported post later in the file: linked when it is imported.
						$uid              = MigratePlus_Importer::uid( $ctx['job'], $item['id'] );
						$ctx['pending'][] = $uid;
						$ctx['waits'][]   = array(
							'uid'  => $uid,
							'path' => isset( $ctx['path'] ) ? $ctx['path'] : array(),
							'pos'  => $list ? $slot : null,
						);
						++$slot;
					} else {
						/* translators: 1: field label, 2: value */
						$ctx['warnings'][] = sprintf( __( 'ACF "%1$s": no post found for "%2$s".', 'migrateplus' ), $label, MigratePlus_Util::stringify( $item ) );
					}
				}
				$ids = array_values( array_unique( $ids ) );
				if ( $list ) {
					return $ids;
				}
				return $ids ? $ids[0] : '';

			case 'taxonomy':
				$tax = isset( $field['taxonomy'] ) ? $field['taxonomy'] : 'category';
				if ( ! taxonomy_exists( $tax ) ) {
					return array();
				}
				$ids   = array();
				$names = array();
				// Exports carry term names, so "2019" there is a term called 2019, never term #2019.
				$export = isset( $ctx['job'] ) && isset( $ctx['job']->data['settings']['migrateplus_mapping'] );
				foreach ( self::items( $value ) as $item ) {
					$item = is_array( $item ) ? ( isset( $item['name'] ) ? $item['name'] : ( isset( $item['term_id'] ) ? $item['term_id'] : '' ) ) : $item;
					// Other data may use ids, but a term named like the number still wins.
					if ( ! $export && is_numeric( $item ) && ! get_term_by( 'name', (string) $item, $tax ) && ! get_term_by( 'slug', (string) $item, $tax ) && get_term( (int) $item, $tax ) instanceof WP_Term ) {
						$ids[] = (int) $item;
					} elseif ( '' !== trim( (string) $item ) ) {
						$names[] = (string) $item;
					}
				}
				if ( $names ) {
					$create = $ctx['create_terms'] && ! empty( $field['add_term'] );
					$ids    = array_merge( $ids, MigratePlus_Importer::resolve_terms( $tax, $names, $create, $ctx['warnings'], isset( $ctx['job'] ) ? $ctx['job'] : null ) );
				}
				$ids = array_values( array_unique( $ids ) );
				$one = isset( $field['field_type'] ) && in_array( $field['field_type'], array( 'radio', 'select' ), true ) && empty( $field['multiple'] );
				return $one ? ( $ids ? $ids[0] : '' ) : $ids;

			case 'user':
				$ids = array();
				foreach ( self::items( $value ) as $item ) {
					$id = self::resolve_user( $item );
					if ( $id ) {
						$ids[] = $id;
					} else {
						/* translators: 1: field label, 2: user */
						$ctx['warnings'][] = sprintf( __( 'ACF "%1$s": no user found for "%2$s".', 'migrateplus' ), $label, is_array( $item ) ? implode( ', ', array_filter( array_map( 'strval', $item ) ) ) : MigratePlus_Util::stringify( $item ) );
					}
				}
				$ids = array_values( array_unique( $ids ) );
				return $multi ? $ids : ( $ids ? $ids[0] : '' );

			case 'link':
				$value = self::maybe_json( $value );
				$url   = is_array( $value ) ? ( isset( $value['url'] ) ? (string) $value['url'] : '' ) : trim( (string) $value );
				if ( ! empty( $ctx['rewrite'] ) ) {
					$url = MigratePlus_Menus::rewrite_url( $url, $ctx['base'] );
				}
				return array(
					'url'    => esc_url_raw( $url ),
					'title'  => is_array( $value ) && isset( $value['title'] ) ? (string) $value['title'] : '',
					'target' => is_array( $value ) && isset( $value['target'] ) ? (string) $value['target'] : '',
				);

			case 'google_map':
				$value = self::maybe_json( $value );
				if ( is_string( $value ) && preg_match( '/^\s*(-?\d+(?:\.\d+)?)\s*,\s*(-?\d+(?:\.\d+)?)\s*$/', $value, $m ) ) {
					return array(
						'address' => '',
						'lat'     => (float) $m[1],
						'lng'     => (float) $m[2],
						'zoom'    => 14,
					);
				}
				return is_array( $value ) ? $value : array( 'address' => (string) $value );

			case 'clone': // Stores its cloned fields' values, read and written like a group's.
				$defer        = ! empty( $ctx['defer'] );
				$ctx['defer'] = false; // Not a value of its own to find the sub field in later.
				$row          = self::maybe_json( $value );
				$out          = is_array( $row ) ? self::convert_row( (array) $field['sub_fields'], $row, $ctx ) : array();
				$ctx['defer'] = $defer;
				return $out;

			case 'group':
				$row = self::maybe_json( $value );
				return is_array( $row ) ? self::convert_row( (array) $field['sub_fields'], $row, $ctx ) : array();

			case 'repeater':
				$rows = self::maybe_json( $value );
				if ( ! is_array( $rows ) ) {
					return array();
				}
				// An empty repeater is no rows, not one blank row: range( 0, -1 ) is [ 0, -1 ].
				if ( $rows && array_keys( $rows ) !== range( 0, count( $rows ) - 1 ) ) {
					$rows = array( $rows ); // A single row given as an object.
				}
				$out  = array();
				$path = isset( $ctx['path'] ) ? $ctx['path'] : array();
				foreach ( $rows as $row ) {
					if ( is_array( $row ) ) {
						$ctx['path'] = array_merge( $path, array( count( $out ) ) );
						$out[]       = self::convert_row( (array) $field['sub_fields'], $row, $ctx );
					}
				}
				$ctx['path'] = $path;
				return $out;

			case 'flexible_content':
				$rows    = self::maybe_json( $value );
				$layouts = array();
				foreach ( isset( $field['layouts'] ) ? (array) $field['layouts'] : array() as $layout ) {
					$layouts[ $layout['name'] ] = $layout;
				}
				$out  = array();
				$path = isset( $ctx['path'] ) ? $ctx['path'] : array();
				foreach ( is_array( $rows ) ? $rows : array() as $row ) {
					$name = is_array( $row ) ? ( isset( $row['acf_fc_layout'] ) ? $row['acf_fc_layout'] : ( isset( $row['layout'] ) ? $row['layout'] : '' ) ) : '';
					if ( ! isset( $layouts[ $name ] ) ) {
						/* translators: 1: field label, 2: layout name */
						$ctx['warnings'][] = sprintf( __( 'ACF "%1$s": unknown layout "%2$s".', 'migrateplus' ), $label, $name );
						continue;
					}
					$ctx['path'] = array_merge( $path, array( count( $out ) ) );
					$out[]       = array( 'acf_fc_layout' => $name ) + self::convert_row( (array) $layouts[ $name ]['sub_fields'], $row, $ctx );
				}
				$ctx['path'] = $path;
				return $out;

			case 'wysiwyg':
			case 'textarea':
			case 'text':
			case 'email':
			case 'url':
			case 'password':
			case 'oembed':
			case 'color_picker':
			default:
				if ( is_array( $value ) && ! in_array( $type, array( 'text', 'textarea', 'wysiwyg', 'email', 'url', 'password', 'oembed', 'color_picker' ), true ) ) {
					return $value; // Unknown / third-party field type: pass structured data through.
				}
				$value = MigratePlus_Util::stringify( $value );
				if ( ! empty( $ctx['rewrite'] ) && 'url' === $type ) {
					return MigratePlus_Menus::rewrite_url( trim( $value ), $ctx['base'] );
				}
				// Links in an editor field, by the rules links in post content follow.
				return ! empty( $ctx['rewrite'] ) && 'wysiwyg' === $type ? MigratePlus_Importer::rewrite_links( $value, $ctx['base'] ) : $value;
		}
	}

	/**
	 * Convert one repeater / group / layout row. Source rows may use sub field names,
	 * keys or labels.
	 *
	 * @param array[] $subs Sub fields.
	 * @param array   $row  Source row.
	 * @param array   $ctx  Context.
	 * @return array sub field key => value
	 */
	protected static function convert_row( array $subs, array $row, array &$ctx ) {
		$lower = array();
		foreach ( $row as $k => $v ) {
			$lower[ strtolower( (string) $k ) ] = $v;
		}
		$out = array();
		foreach ( $subs as $sub ) {
			if ( empty( $sub['name'] ) || in_array( $sub['type'], self::LAYOUT_TYPES, true ) ) {
				continue;
			}
			$candidates = array( $sub['name'], $sub['key'], strtolower( $sub['name'] ), strtolower( (string) $sub['label'] ), sanitize_title( (string) $sub['label'] ) );
			$found      = null;
			foreach ( $candidates as $c ) {
				if ( array_key_exists( $c, $row ) ) {
					$found = $row[ $c ];
					break;
				}
				if ( array_key_exists( $c, $lower ) ) {
					$found = $lower[ $c ];
					break;
				}
			}
			if ( null !== $found ) {
				$path               = isset( $ctx['path'] ) ? $ctx['path'] : array();
				$ctx['path']        = array_merge( $path, array( $sub['key'] ) );
				$out[ $sub['key'] ] = self::convert( $sub, $found, $ctx );
				$ctx['path']        = $path;
			}
		}
		return $out;
	}

	/**
	 * Map a value to a choice key, matching keys first and then labels (case-insensitive).
	 *
	 * @param string $value   Value.
	 * @param array  $choices key => label.
	 * @return string
	 */
	protected static function match_choice( $value, array $choices ) {
		$value = trim( $value );
		if ( '' === $value || ! $choices || isset( $choices[ $value ] ) ) {
			return $value;
		}
		foreach ( $choices as $key => $label ) {
			if ( 0 === strcasecmp( (string) $key, $value ) || 0 === strcasecmp( wp_strip_all_tags( (string) $label ), $value ) ) {
				return (string) $key;
			}
		}
		return $value;
	}

	/**
	 * @param mixed  $value  Date-ish value.
	 * @param string $format Output format.
	 * @return string
	 */
	protected static function format_date( $value, $format ) {
		$s = trim( MigratePlus_Util::stringify( $value ) );
		if ( '' === $s ) {
			return '';
		}
		if ( 'Ymd' === $format && preg_match( '/^\d{8}$/', $s ) ) {
			return $s; // Already in ACF's storage format.
		}
		if ( 'H:i:s' === $format && preg_match( '/^\d{1,2}:\d{2}(:\d{2})?\s*(am|pm)?$/i', $s ) ) {
			$t = strtotime( '1970-01-01 ' . $s . ' UTC' );
			return false === $t ? $s : gmdate( 'H:i:s', $t );
		}
		$date = MigratePlus_Importer::parse_date( $s );
		return $date ? $date->setTimezone( wp_timezone() )->format( $format ) : '';
	}

	/**
	 * Resolve an image/file value to a local attachment id, downloading URLs.
	 *
	 * @param mixed  $value Value: URL, attachment id, or { url | id | ID }.
	 * @param array  $ctx   Context.
	 * @param string $label Field label for warnings.
	 * @return int
	 */
	protected static function attachment( $value, array &$ctx, $label ) {
		$value = self::maybe_json( $value );
		if ( is_array( $value ) ) {
			if ( ! empty( $value['url'] ) ) {
				$value = $value['url'];
			} elseif ( ! empty( $value['ID'] ) || ! empty( $value['id'] ) ) {
				$value = ! empty( $value['ID'] ) ? $value['ID'] : $value['id'];
			} elseif ( ! empty( $value['source_url'] ) ) {
				$value = $value['source_url'];
			} else {
				return 0;
			}
		}
		$value = trim( (string) $value );
		if ( '' === $value ) {
			return 0;
		}
		if ( ctype_digit( $value ) ) {
			if ( 'attachment' === get_post_type( (int) $value ) ) {
				return (int) $value;
			}
			/* translators: 1: field label, 2: id */
			$ctx['warnings'][] = sprintf( __( 'ACF "%1$s": attachment #%2$s does not exist on this site. Map a file URL instead.', 'migrateplus' ), $label, $value );
			return 0;
		}
		$id = MigratePlus_Media::sideload( $value, $ctx['post_id'], '', $ctx['base'], $ctx['job_id'] );
		if ( is_wp_error( $id ) ) {
			/* translators: 1: field label, 2: error */
			$ctx['warnings'][] = sprintf( __( 'ACF "%1$s": %2$s', 'migrateplus' ), $label, $id->get_error_message() );
			return 0;
		}
		return (int) $id;
	}

	/**
	 * Remember the posts a field still waits for, one row each:
	 * "<field key>[/<row>/<sub field key>...][#<position>]|<source uid>". The
	 * path leads from the field's value down to a sub field in its rows; the
	 * position is where the post goes among those of a list. A re-import
	 * replaces the field's old rows.
	 *
	 * @param int     $post_id   Post id.
	 * @param string  $field_key ACF field key.
	 * @param array[] $waits     { uid, path, pos } of each post not imported yet (convert() collects them).
	 */
	protected static function set_pending( $post_id, $field_key, array $waits ) {
		foreach ( (array) get_post_meta( $post_id, self::PENDING_META, false ) as $row ) {
			if ( self::pending_field( (string) $row ) === $field_key ) {
				delete_post_meta( $post_id, self::PENDING_META, $row );
			}
		}
		foreach ( self::pending_rows( $field_key, $waits ) as $row ) {
			add_post_meta( $post_id, self::PENDING_META, $row );
		}
	}

	/**
	 * Like set_pending(), for a term's fields (term meta).
	 *
	 * @param int     $term_id   Term id.
	 * @param string  $field_key ACF field key.
	 * @param array[] $waits     { uid, path, pos } of each post not imported yet.
	 */
	protected static function set_term_pending( $term_id, $field_key, array $waits ) {
		foreach ( (array) get_term_meta( $term_id, self::PENDING_META, false ) as $row ) {
			if ( self::pending_field( (string) $row ) === $field_key ) {
				delete_term_meta( $term_id, self::PENDING_META, $row );
			}
		}
		foreach ( self::pending_rows( $field_key, $waits ) as $row ) {
			add_term_meta( $term_id, self::PENDING_META, $row );
		}
	}

	/**
	 * The PENDING_META rows for a field's waiting posts.
	 *
	 * @param string  $field_key ACF field key.
	 * @param array[] $waits     { uid, path, pos }.
	 * @return string[]
	 */
	protected static function pending_rows( $field_key, array $waits ) {
		$rows = array();
		foreach ( $waits as $wait ) {
			$path   = $wait['path'] ? '/' . implode( '/', $wait['path'] ) : '';
			$pos    = null !== $wait['pos'] ? '#' . (int) $wait['pos'] : '';
			$rows[] = $field_key . $path . $pos . '|' . $wait['uid'];
		}
		return array_values( array_unique( $rows ) );
	}

	/**
	 * A PENDING_META row taken apart. Rows written before paths and positions are "<field key>|<uid>".
	 *
	 * @param string $row Row.
	 * @return array { field: string, path: string[], pos: int|null, place: string (the row without its position and uid) }
	 */
	protected static function parse_pending( $row ) {
		$head = substr( $row, 0, (int) strrpos( $row, '|' ) );
		$pos  = null;
		if ( preg_match( '/^(.*)#(\d+)$/', $head, $m ) ) {
			$head = $m[1];
			$pos  = (int) $m[2];
		}
		$parts = explode( '/', $head );
		return array(
			'field' => array_shift( $parts ),
			'path'  => $parts,
			'pos'   => $pos,
			'place' => $head,
		);
	}

	/**
	 * @param string $row A PENDING_META row.
	 * @return string The field key it belongs to.
	 */
	protected static function pending_field( $row ) {
		return self::parse_pending( $row )['field'];
	}

	/**
	 * A post has just been imported: add it to the fields, on posts and on
	 * terms, that were waiting for it.
	 *
	 * @param int    $post_id   The imported post.
	 * @param string $source_id Its source uid (MigratePlus_Importer::uid()), so only references from the same source match.
	 */
	public static function link_pending( $post_id, $source_id ) {
		global $wpdb;
		if ( '' === (string) $source_id || ! self::active() ) {
			return;
		}
		$like = '%|' . $wpdb->esc_like( (string) $source_id );
		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare( "SELECT post_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value LIKE %s", self::PENDING_META, $like )
		);
		foreach ( (array) $rows as $row ) {
			$all = (array) get_post_meta( (int) $row->post_id, self::PENDING_META, false );
			if ( self::link_waiting_field( $row->meta_value, (int) $post_id, (int) $row->post_id, $all ) ) {
				delete_post_meta( (int) $row->post_id, self::PENDING_META, $row->meta_value );
			}
		}
		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare( "SELECT term_id, meta_value FROM {$wpdb->termmeta} WHERE meta_key = %s AND meta_value LIKE %s", self::PENDING_META, $like )
		);
		foreach ( (array) $rows as $row ) {
			$all = (array) get_term_meta( (int) $row->term_id, self::PENDING_META, false );
			if ( self::link_waiting_field( $row->meta_value, (int) $post_id, 'term_' . (int) $row->term_id, $all ) ) {
				delete_term_meta( (int) $row->term_id, self::PENDING_META, $row->meta_value );
			}
		}
	}

	/**
	 * Add a just-imported post to one waiting field: at its place in the order
	 * of a list, and down the row path of a sub field.
	 *
	 * @param string     $pending  A PENDING_META value (see set_pending()).
	 * @param int        $post_id  The imported post.
	 * @param int|string $selector Where the field lives: a post id, or "term_<id>".
	 * @param string[]   $all      Every PENDING_META row of that post or term.
	 * @return bool Whether the pending row is settled and can go.
	 */
	protected static function link_waiting_field( $pending, $post_id, $selector, array $all = array() ) {
		$wait  = self::parse_pending( $pending );
		$field = acf_get_field( $wait['field'] );
		if ( ! $field ) {
			return true;
		}
		// Posts listed before this one that are still to come are not in the list yet.
		$index = $wait['pos'];
		if ( null !== $index ) {
			foreach ( $all as $row ) {
				$other = self::parse_pending( (string) $row );
				if ( (string) $row !== $pending && $other['place'] === $wait['place'] && null !== $other['pos'] && $other['pos'] < $wait['pos'] ) {
					--$index;
				}
			}
		}
		$current = get_field( $wait['field'], $selector, false );
		if ( ! $wait['path'] ) {
			update_field( $wait['field'], wp_slash( self::place_post( $field, $current, $post_id, $index ) ), $selector );
			return true;
		}
		$sub = self::sub_field_at( $field, $wait['path'] );
		if ( ! $sub || ! is_array( $current ) ) {
			return true; // The field or its rows are not what they were: nothing to put the post into.
		}
		$node = &$current;
		foreach ( $wait['path'] as $segment ) {
			if ( ! is_array( $node ) ) {
				return true;
			}
			if ( ! array_key_exists( $segment, $node ) ) {
				$node[ $segment ] = null;
			}
			$node = &$node[ $segment ];
		}
		$node = self::place_post( $sub, $node, $post_id, $index );
		unset( $node );
		update_field( $wait['field'], wp_slash( $current ), $selector );
		return true;
	}

	/**
	 * A reference field's raw value with a post added: the post for a single
	 * one, inserted at its place for a list.
	 *
	 * @param array    $field   The reference field.
	 * @param mixed    $current Its raw value.
	 * @param int      $post_id Post to add.
	 * @param int|null $index   Where in a list, or null for the end.
	 * @return int|array
	 */
	protected static function place_post( array $field, $current, $post_id, $index ) {
		if ( 'relationship' !== $field['type'] && empty( $field['multiple'] ) ) {
			return (int) $post_id;
		}
		$list = is_array( $current ) ? array_values( $current ) : ( null === $current || false === $current || '' === $current ? array() : array( $current ) );
		$list = array_map(
			static function ( $item ) {
				return is_numeric( $item ) ? (int) $item : $item; // A page link may hold a URL.
			},
			$list
		);
		if ( in_array( (int) $post_id, $list, true ) ) {
			return $list;
		}
		array_splice( $list, null === $index ? count( $list ) : max( 0, min( $index, count( $list ) ) ), 0, array( (int) $post_id ) );
		return $list;
	}

	/**
	 * The sub field a pending row's path leads to: row numbers are passed over, field keys followed.
	 *
	 * @param array    $field Top-level field.
	 * @param string[] $path  Path.
	 * @return array|null
	 */
	protected static function sub_field_at( array $field, array $path ) {
		foreach ( $path as $segment ) {
			if ( ctype_digit( (string) $segment ) ) {
				continue;
			}
			$next = null;
			foreach ( self::sub_fields( $field ) as $sub ) {
				if ( $sub['key'] === $segment ) {
					$next = $sub;
					break;
				}
			}
			if ( ! $next ) {
				return null;
			}
			$field = $next;
		}
		return $field;
	}

	/**
	 * Resolve a user: an exported { email, login }, ACF's user array, a local id, or
	 * a plain email/login/slug/display name (older exports). Email wins over login.
	 *
	 * @param mixed $item Value.
	 * @return int 0 when no user matches.
	 */
	public static function resolve_user( $item ) {
		if ( is_array( $item ) ) {
			$item = array_change_key_case( $item, CASE_LOWER );
			$keys = array(
				'email'      => 'email',
				'user_email' => 'email',
				'login'      => 'login',
				'user_login' => 'login',
			);
			foreach ( $keys as $key => $by ) {
				if ( ! empty( $item[ $key ] ) ) {
					$user = get_user_by( $by, (string) $item[ $key ] );
					if ( $user ) {
						return (int) $user->ID;
					}
				}
			}
			return 0;
		}
		if ( is_numeric( $item ) && get_userdata( (int) $item ) ) {
			return (int) $item;
		}
		// Authors are no longer matched by name, so a plain value names a user by email or login only.
		$user = is_email( (string) $item ) ? get_user_by( 'email', (string) $item ) : get_user_by( 'login', (string) $item );
		return $user ? (int) $user->ID : 0;
	}

	/**
	 * Resolve a related post: a source ID from an earlier MigratePlus import, a local
	 * post id, a slug or a title.
	 *
	 * @param mixed                $value Value.
	 * @param string[]             $types Allowed post types (empty = any).
	 * @param MigratePlus_Job|null $job   The import: source ids match the posts it imported from the same source.
	 * @return int
	 */
	public static function resolve_post( $value, array $types, $job = null ) {
		$common = array(
			// Not 'any': that leaves out post types hidden from search. Nor revisions or menu items,
			// which share their post's slug and title but are never what a reference means.
			'post_type'        => $types ? $types : array_values( array_diff( array_keys( get_post_types() ), self::NOT_REFERENCED ) ),
			'post_status'      => 'any',
			'posts_per_page'   => 1,
			'fields'           => 'ids',
			'no_found_rows'    => true,
			'suppress_filters' => true,
		);
		if ( is_array( $value ) ) {
			// An exported reference { id, slug, title } from another site: match the
			// earlier import by source id, then (not for our own exports) slug, then title. Never treat the
			// foreign id as a local post id, nor as an id from another source.
			$ref = array_change_key_case( $value, CASE_LOWER );
			if ( ! empty( $ref['id'] ) ) {
				$ids = get_posts(
					$common + ( $job ? array(
						'meta_key'   => '_migrateplus_source_uid', // phpcs:ignore WordPress.DB.SlowDBQuery
						'meta_value' => MigratePlus_Importer::uid( $job, $ref['id'] ), // phpcs:ignore WordPress.DB.SlowDBQuery
					) : array(
						'meta_key'   => '_migrateplus_source_id', // phpcs:ignore WordPress.DB.SlowDBQuery
						'meta_value' => (string) $ref['id'], // phpcs:ignore WordPress.DB.SlowDBQuery
					) )
				);
				if ( $ids ) {
					return (int) $ids[0];
				}
				// From a Migrate Plus export: the post is later in the file, in a file still to come,
				// or already here without having been imported (staging and live both have About).
				// The same slug of the same type is that last case; a title alone is too loose, and
				// once linked to the wrong post the field would never be corrected, so otherwise wait.
				if ( $job && isset( $job->data['settings']['migrateplus_mapping'] ) ) {
					foreach ( array( 'slug', 'post_name' ) as $k ) {
						$found = ! empty( $ref[ $k ] ) ? self::resolve_post( '/' . $ref[ $k ], $types ) : 0;
						if ( $found ) {
							return $found;
						}
					}
					return 0;
				}
			}
			foreach ( array( 'slug', 'post_name', 'title', 'post_title' ) as $k ) {
				if ( ! empty( $ref[ $k ] ) ) {
					$found = self::resolve_post( 'title' === $k || 'post_title' === $k ? $ref[ $k ] : '/' . $ref[ $k ], $types );
					if ( $found ) {
						return $found;
					}
				}
			}
			return 0;
		}
		$value = trim( (string) $value );
		if ( '' === $value ) {
			return 0;
		}

		// 1. Imported earlier by MigratePlus (source IDs survive the migration): from this
		// import's source first, then from any source, before this site's own ids. Data
		// that names related posts by id almost always uses the old system's ids, and a
		// local post with the same number nearly always exists.
		if ( $job ) {
			$ids = get_posts(
				$common + array(
					'meta_key'   => '_migrateplus_source_uid', // phpcs:ignore WordPress.DB.SlowDBQuery
					'meta_value' => MigratePlus_Importer::uid( $job, $value ), // phpcs:ignore WordPress.DB.SlowDBQuery
				)
			);
			if ( $ids ) {
				return (int) $ids[0];
			}
		}
		$ids = get_posts(
			$common + array(
				'meta_key'   => '_migrateplus_source_id', // phpcs:ignore WordPress.DB.SlowDBQuery
				'meta_value' => $value, // phpcs:ignore WordPress.DB.SlowDBQuery
			)
		);
		if ( $ids ) {
			return (int) $ids[0];
		}
		// 2. A local post id.
		if ( ctype_digit( $value ) ) {
			$post = get_post( (int) $value );
			if ( $post && ( $types ? in_array( $post->post_type, $types, true ) : ! in_array( $post->post_type, self::NOT_REFERENCED, true ) ) ) {
				return (int) $post->ID;
			}
		}
		// 3. Slug (a URL or path uses its last segment), then title.
		$slug = MigratePlus_Importer::slug_from( $value );
		$ids  = '' !== $slug ? get_posts( $common + array( 'name' => $slug ) ) : array();
		if ( $ids ) {
			return (int) $ids[0];
		}
		$ids = get_posts( $common + array( 'title' => $value ) );
		return $ids ? (int) $ids[0] : 0;
	}
}
