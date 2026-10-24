<?php
/**
 * JSON feed / file source.
 *
 * @package PressPort
 */

defined( 'ABSPATH' ) || exit;

/**
 * Imports an array of objects from a JSON URL or uploaded JSON file.
 * The feed is downloaded once and cached locally so batches are consistent.
 */
class PressPort_Source_JSON extends PressPort_Source_Base {

	/** @inheritDoc */
	public static function id() {
		return 'json';
	}

	/** @inheritDoc */
	public static function label() {
		return __( 'JSON feed / file', 'pressport' );
	}

	/** @inheritDoc */
	public static function settings_schema() {
		return array(
			array(
				'key'   => 'url',
				'label' => __( 'Feed URL', 'pressport' ),
				'type'  => 'url',
				'help'  => __( 'Any URL returning JSON (JSON Feed, REST API, export endpoint). Leave empty to upload a file instead.', 'pressport' ),
			),
			array(
				'key'    => 'file',
				'label'  => __( 'Or upload a JSON file', 'pressport' ),
				'type'   => 'file',
				'accept' => '.json,application/json',
				'remote' => false, // The feed URL field above already covers remote JSON.
			),
			array(
				'key'   => 'records_path',
				'label' => __( 'Records path', 'pressport' ),
				'type'  => 'text',
				'help'  => __( 'Dot path to the array of items, e.g. "data.posts". Leave empty to auto-detect.', 'pressport' ),
			),
			array(
				'key'   => 'headers',
				'label' => __( 'Request headers', 'pressport' ),
				'type'  => 'textarea',
				'help'  => __( 'Optional, one per line, e.g. "Authorization: Bearer abc123".', 'pressport' ),
			),
		);
	}

	/**
	 * Locate the records list inside a decoded document.
	 *
	 * @param mixed  $doc  Decoded JSON.
	 * @param string $path Optional explicit path.
	 * @return array|WP_Error
	 */
	public static function find_records( $doc, $path = '' ) {
		if ( '' !== $path ) {
			$found = PressPort_Util::get_path( $doc, $path );
			if ( ! is_array( $found ) ) {
				/* translators: %s: records path */
				return new WP_Error( 'pressport_json_path', sprintf( __( 'No array found at path "%s".', 'pressport' ), $path ) );
			}
			return array_values( $found );
		}
		if ( is_array( $doc ) && isset( $doc[0] ) && is_array( $doc[0] ) && array_keys( $doc ) === range( 0, count( $doc ) - 1 ) ) {
			return $doc; // Top-level list of objects.
		}
		if ( is_array( $doc ) ) {
			foreach ( array( 'items', 'data', 'posts', 'results', 'records', 'entries', 'articles', 'nodes', 'rows' ) as $key ) {
				if ( isset( $doc[ $key ] ) && is_array( $doc[ $key ] ) && ! empty( $doc[ $key ] ) && isset( $doc[ $key ][0] ) && is_array( $doc[ $key ][0] ) ) {
					return $doc[ $key ];
				}
			}
			// Fallback: first list of objects anywhere at depth 1-2.
			foreach ( $doc as $value ) {
				if ( is_array( $value ) && isset( $value[0] ) && is_array( $value[0] ) ) {
					return array_values( $value );
				}
				if ( is_array( $value ) ) {
					foreach ( $value as $inner ) {
						if ( is_array( $inner ) && isset( $inner[0] ) && is_array( $inner[0] ) ) {
							return array_values( $inner );
						}
					}
				}
			}
		}
		return new WP_Error( 'pressport_json_records', __( 'Could not find a list of records in the JSON. Set the records path.', 'pressport' ) );
	}

	/** @inheritDoc */
	public function prepare() {
		$url  = trim( (string) $this->get( 'url' ) );
		$file = (string) $this->get( 'file' );

		if ( '' !== $url ) {
			$result = PressPort_Util::get_json( $url, PressPort_Util::parse_header_lines( $this->get( 'headers' ) ) );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
			$doc = $result['body'];
		} elseif ( '' !== $file && is_readable( $file ) ) {
			$doc = json_decode( (string) file_get_contents( $file ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			if ( null === $doc ) {
				return new WP_Error( 'pressport_json', __( 'Uploaded file is not valid JSON: ', 'pressport' ) . json_last_error_msg() );
			}
		} else {
			return new WP_Error( 'pressport_json_input', __( 'Provide a feed URL or upload a JSON file.', 'pressport' ) );
		}

		// A PressPort export: remember its mapping and use its item list.
		if ( is_array( $doc ) && isset( $doc['pressport']['mapping'], $doc['items'] ) && is_array( $doc['items'] ) ) {
			$this->settings['pressport_mapping'] = $doc['pressport']['mapping'];
			$this->settings['pressport_site']    = isset( $doc['pressport']['site'] ) ? (string) $doc['pressport']['site'] : '';
		}

		$records = self::find_records( $doc, trim( (string) $this->get( 'records_path' ) ) );
		if ( is_wp_error( $records ) ) {
			return $records;
		}
		$records = array_values( array_filter( $records, 'is_array' ) );

		$cache = PressPort_Util::ensure_storage_dir() . 'json-' . wp_generate_password( 12, false ) . '.json';
		file_put_contents( $cache, wp_json_encode( $records ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions

		$this->settings['cache_file'] = $cache;
		$this->settings['total']      = count( $records );
		// Collect field names from the first few records; optional fields may be missing from the first one.
		$fields = array();
		foreach ( array_slice( $records, 0, 25 ) as $record ) {
			$fields += array_flip( array_keys( PressPort_Util::flatten( $record ) ) );
		}
		$this->settings['fields'] = array_keys( $fields );
		return $this->settings;
	}

	/** @inheritDoc */
	public function fetch( $cursor, $limit ) {
		$cache = (string) $this->get( 'cache_file' );
		if ( ! $cache || ! is_readable( $cache ) ) {
			return new WP_Error( 'pressport_json_cache', __( 'Cached feed is missing. Start a new import.', 'pressport' ) );
		}
		$all    = json_decode( (string) file_get_contents( $cache ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		$offset = null === $cursor ? 0 : (int) $cursor;
		$slice  = array_slice( (array) $all, $offset, $limit );
		$next   = $offset + count( $slice );

		return array(
			'records' => array_map( array( 'PressPort_Util', 'flatten' ), $slice ),
			'next'    => $next < count( $all ) ? $next : null,
			'total'   => count( $all ),
		);
	}

	/** @inheritDoc */
	public function has_remote_input() {
		return '' !== trim( (string) $this->get( 'url' ) );
	}

	/** @inheritDoc */
	public function suggested_mapping() {
		return PressPort_Sources::guess_mapping( (array) $this->get( 'fields', array() ) );
	}
}
