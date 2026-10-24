<?php
/**
 * Advanced Custom Fields integration.
 *
 * @package PressPort
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
class PressPort_ACF {

	/**
	 * Field types that hold no data.
	 *
	 * @var string[]
	 */
	const LAYOUT_TYPES = array( 'tab', 'message', 'accordion', 'clone' );

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
	 * @return array[] { key, name, label, type, group, required, sub }
	 */
	public static function fields_for_post_type( $post_type ) {
		if ( ! self::active() ) {
			return array();
		}
		$out = array();
		foreach ( (array) acf_get_field_groups( array( 'post_type' => $post_type ) ) as $group ) {
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
	 * Load a field by key or name.
	 *
	 * @param string $selector Field key or name.
	 * @param int    $post_id  Post id (helps resolve names).
	 * @return array|false
	 */
	public static function get_field_object( $selector, $post_id = 0 ) {
		if ( 0 === strpos( $selector, 'field_' ) && function_exists( 'acf_get_field' ) ) {
			$field = acf_get_field( $selector );
			if ( $field ) {
				return $field;
			}
		}
		if ( function_exists( 'acf_maybe_get_field' ) ) {
			$field = acf_maybe_get_field( $selector, $post_id, false );
			if ( $field ) {
				return $field;
			}
		}
		return function_exists( 'acf_get_field' ) ? acf_get_field( $selector ) : false;
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
			return $record[ $source ];
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
			$node  = &$tree;
			foreach ( explode( '.', substr( $key, $len ) ) as $segment ) {
				if ( ! isset( $node[ $segment ] ) || ! is_array( $node[ $segment ] ) ) {
					$node[ $segment ] = array();
				}
				$node = &$node[ $segment ];
			}
			$node = $value;
			unset( $node );
		}
		return $found ? self::listify( $tree ) : null;
	}

	/**
	 * Turn arrays with 0..n keys into lists, recursively.
	 *
	 * @param mixed $data Data.
	 * @return mixed
	 */
	protected static function listify( $data ) {
		if ( ! is_array( $data ) ) {
			return $data;
		}
		foreach ( $data as $k => $v ) {
			$data[ $k ] = self::listify( $v );
		}
		$keys = array_keys( $data );
		if ( $keys && array_filter( $keys, 'is_numeric' ) === $keys ) {
			ksort( $data, SORT_NUMERIC );
			$data = array_values( $data );
		}
		return $data;
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
			// A single object (e.g. { url: ... }) is one item, not a list of fields.
			return array_keys( $value ) === range( 0, count( $value ) - 1 ) ? $value : array( $value );
		}
		return PressPort_Util::to_list( $value );
	}

	/**
	 * Save all mapped ACF fields for a post.
	 *
	 * @param int           $post_id  Post id.
	 * @param array         $record   Flat record.
	 * @param array         $map      field key/name => source field.
	 * @param PressPort_Job $job      Job.
	 * @param array         $opts     Job options.
	 * @param string        $base     Base URL for relative media.
	 * @param string[]      $warnings Warnings (by reference).
	 * @return int Number of fields saved.
	 */
	public static function import( $post_id, array $record, array $map, PressPort_Job $job, array $opts, $base, array &$warnings ) {
		if ( ! $map ) {
			return 0;
		}
		if ( ! self::active() ) {
			$warnings[] = __( 'ACF fields are mapped but Advanced Custom Fields is not active.', 'pressport' );
			return 0;
		}
		$ctx   = array(
			'post_id'      => (int) $post_id,
			'job_id'       => $job->data['id'],
			'base'         => (string) $base,
			'create_terms' => ! empty( $opts['create_terms'] ),
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
				$warnings[] = sprintf( __( 'ACF field "%s" was not found.', 'pressport' ), $selector );
				continue;
			}
			$value = self::convert( $field, $raw, $ctx );
			$value = apply_filters( 'pressport_acf_value', $value, $field, $raw, $post_id, $job );
			if ( update_field( $field['key'], $value, $post_id ) || null !== $value ) {
				++$saved;
			}
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
				$s = trim( PressPort_Util::stringify( $value ) );
				$s = str_replace( array( ',', ' ' ), array( '', '' ), $s );
				return is_numeric( $s ) ? $s + 0 : '';

			case 'true_false':
				$s = strtolower( trim( PressPort_Util::stringify( $value ) ) );
				return in_array( $s, array( '1', 'true', 'yes', 'y', 'on', 'checked' ), true ) ? 1 : 0;

			case 'select':
			case 'checkbox':
			case 'radio':
			case 'button_group':
				$choices = isset( $field['choices'] ) ? (array) $field['choices'] : array();
				$picked  = array();
				foreach ( self::items( $value ) as $item ) {
					$picked[] = self::match_choice( PressPort_Util::stringify( $item ), $choices );
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
				foreach ( self::items( $value ) as $item ) {
					$id = self::resolve_post( $item, $types );
					if ( $id ) {
						$ids[] = $id;
					} elseif ( 'page_link' === $type && is_string( $item ) && preg_match( '#^https?://#', $item ) ) {
						$ids[] = $item; // Page links may store plain URLs.
					} else {
						/* translators: 1: field label, 2: value */
						$ctx['warnings'][] = sprintf( __( 'ACF "%1$s": no post found for "%2$s".', 'pressport' ), $label, PressPort_Util::stringify( $item ) );
					}
				}
				$ids = array_values( array_unique( $ids ) );
				if ( 'relationship' === $type || $multi ) {
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
				foreach ( self::items( $value ) as $item ) {
					$item = is_array( $item ) ? ( isset( $item['name'] ) ? $item['name'] : ( isset( $item['term_id'] ) ? $item['term_id'] : '' ) ) : $item;
					if ( is_numeric( $item ) && get_term( (int) $item, $tax ) instanceof WP_Term ) {
						$ids[] = (int) $item;
					} elseif ( '' !== trim( (string) $item ) ) {
						$names[] = (string) $item;
					}
				}
				if ( $names ) {
					$create = $ctx['create_terms'] && ! empty( $field['add_term'] );
					$ids    = array_merge( $ids, PressPort_Importer::resolve_terms( $tax, $names, $create ) );
				}
				$ids = array_values( array_unique( $ids ) );
				$one = isset( $field['field_type'] ) && in_array( $field['field_type'], array( 'radio', 'select' ), true ) && empty( $field['multiple'] );
				return $one ? ( $ids ? $ids[0] : '' ) : $ids;

			case 'user':
				$ids = array();
				foreach ( self::items( $value ) as $item ) {
					$item = is_array( $item ) ? ( isset( $item['user_email'] ) ? $item['user_email'] : ( isset( $item['ID'] ) ? $item['ID'] : '' ) ) : $item;
					$id   = is_numeric( $item ) && get_userdata( (int) $item ) ? (int) $item : PressPort_Importer::resolve_author( (string) $item, false );
					if ( $id ) {
						$ids[] = (int) $id;
					}
				}
				$ids = array_values( array_unique( $ids ) );
				return $multi ? $ids : ( $ids ? $ids[0] : '' );

			case 'link':
				$value = self::maybe_json( $value );
				if ( is_array( $value ) ) {
					return array(
						'url'    => isset( $value['url'] ) ? esc_url_raw( $value['url'] ) : '',
						'title'  => isset( $value['title'] ) ? (string) $value['title'] : '',
						'target' => isset( $value['target'] ) ? (string) $value['target'] : '',
					);
				}
				return array(
					'url'    => esc_url_raw( trim( (string) $value ) ),
					'title'  => '',
					'target' => '',
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

			case 'group':
				$row = self::maybe_json( $value );
				return is_array( $row ) ? self::convert_row( (array) $field['sub_fields'], $row, $ctx ) : array();

			case 'repeater':
				$rows = self::maybe_json( $value );
				if ( ! is_array( $rows ) ) {
					return array();
				}
				if ( array_keys( $rows ) !== range( 0, count( $rows ) - 1 ) ) {
					$rows = array( $rows ); // A single row given as an object.
				}
				$out = array();
				foreach ( $rows as $row ) {
					if ( is_array( $row ) ) {
						$out[] = self::convert_row( (array) $field['sub_fields'], $row, $ctx );
					}
				}
				return $out;

			case 'flexible_content':
				$rows    = self::maybe_json( $value );
				$layouts = array();
				foreach ( isset( $field['layouts'] ) ? (array) $field['layouts'] : array() as $layout ) {
					$layouts[ $layout['name'] ] = $layout;
				}
				$out = array();
				foreach ( is_array( $rows ) ? $rows : array() as $row ) {
					$name = is_array( $row ) ? ( isset( $row['acf_fc_layout'] ) ? $row['acf_fc_layout'] : ( isset( $row['layout'] ) ? $row['layout'] : '' ) ) : '';
					if ( ! isset( $layouts[ $name ] ) ) {
						/* translators: 1: field label, 2: layout name */
						$ctx['warnings'][] = sprintf( __( 'ACF "%1$s": unknown layout "%2$s".', 'pressport' ), $label, $name );
						continue;
					}
					$out[] = array( 'acf_fc_layout' => $name ) + self::convert_row( (array) $layouts[ $name ]['sub_fields'], $row, $ctx );
				}
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
				return PressPort_Util::stringify( $value );
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
				$out[ $sub['key'] ] = self::convert( $sub, $found, $ctx );
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
		$s = trim( PressPort_Util::stringify( $value ) );
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
		$date = PressPort_Importer::parse_date( $s );
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
			$ctx['warnings'][] = sprintf( __( 'ACF "%1$s": attachment #%2$s does not exist on this site. Map a file URL instead.', 'pressport' ), $label, $value );
			return 0;
		}
		$id = PressPort_Media::sideload( $value, $ctx['post_id'], '', $ctx['base'], $ctx['job_id'] );
		if ( is_wp_error( $id ) ) {
			/* translators: 1: field label, 2: error */
			$ctx['warnings'][] = sprintf( __( 'ACF "%1$s": %2$s', 'pressport' ), $label, $id->get_error_message() );
			return 0;
		}
		return (int) $id;
	}

	/**
	 * Resolve a related post: a source ID from an earlier PressPort import, a local
	 * post id, a slug or a title.
	 *
	 * @param mixed    $value Value.
	 * @param string[] $types Allowed post types (empty = any).
	 * @return int
	 */
	public static function resolve_post( $value, array $types ) {
		$post_types = $types ? $types : 'any';
		if ( is_array( $value ) ) {
			// An exported reference { id, slug, title } from another site: match the
			// earlier import by source id, then slug, then title. Never treat the
			// foreign id as a local post id.
			$ref = array_change_key_case( $value, CASE_LOWER );
			if ( ! empty( $ref['id'] ) ) {
				$ids = get_posts(
					array(
						'post_type'        => $post_types,
						'post_status'      => 'any',
						'posts_per_page'   => 1,
						'fields'           => 'ids',
						'no_found_rows'    => true,
						'suppress_filters' => true,
						'meta_key'         => '_pressport_source_id', // phpcs:ignore WordPress.DB.SlowDBQuery
						'meta_value'       => (string) $ref['id'], // phpcs:ignore WordPress.DB.SlowDBQuery
					)
				);
				if ( $ids ) {
					return (int) $ids[0];
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
		$common     = array(
			'post_type'        => $post_types,
			'post_status'      => 'any',
			'posts_per_page'   => 1,
			'fields'           => 'ids',
			'no_found_rows'    => true,
			'suppress_filters' => true,
		);

		// 1. Imported earlier by PressPort (source IDs survive the migration).
		$ids = get_posts(
			$common + array(
				'meta_key'   => '_pressport_source_id', // phpcs:ignore WordPress.DB.SlowDBQuery
				'meta_value' => $value, // phpcs:ignore WordPress.DB.SlowDBQuery
			)
		);
		if ( $ids ) {
			return (int) $ids[0];
		}
		// 2. A local post id.
		if ( ctype_digit( $value ) ) {
			$post = get_post( (int) $value );
			if ( $post && ( ! $types || in_array( $post->post_type, $types, true ) ) ) {
				return (int) $post->ID;
			}
		}
		// 3. A URL or path: use its last segment as a slug.
		if ( false !== strpos( $value, '/' ) ) {
			$segments = array_values( array_filter( explode( '/', (string) wp_parse_url( $value, PHP_URL_PATH ) ), 'strlen' ) );
			$value    = $segments ? end( $segments ) : $value;
		}
		// 4. Slug, then title.
		$ids = get_posts( $common + array( 'name' => sanitize_title( $value ) ) );
		if ( $ids ) {
			return (int) $ids[0];
		}
		$ids = get_posts( $common + array( 'title' => $value ) );
		return $ids ? (int) $ids[0] : 0;
	}
}
