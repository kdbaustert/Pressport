<?php
/**
 * JSON file source.
 *
 * @package MigratePlus
 */

defined( 'ABSPATH' ) || exit;

/**
 * Imports an array of objects from an uploaded JSON file (or one compressed as .json.gz), normally a MigratePlus export.
 * The records are cached locally so batches are consistent.
 */
class MigratePlus_Source_JSON extends MigratePlus_Source_Base {

	/** @inheritDoc */
	public static function id() {
		return 'json';
	}

	/** @inheritDoc */
	public static function label() {
		return __( 'JSON file', 'migrateplus' );
	}

	/** @inheritDoc */
	public static function settings_schema() {
		return array(
			array(
				'key'      => 'file',
				'label'    => __( 'JSON file', 'migrateplus' ),
				'type'     => 'file',
				'accept'   => '.json,.gz,application/json,application/gzip',
				'remote'   => false,
				'required' => true,
			),
		);
	}

	/**
	 * Locate the records list inside a decoded document.
	 *
	 * @param mixed $doc Decoded JSON.
	 * @return array|WP_Error
	 */
	public static function find_records( $doc ) {
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
		return new WP_Error( 'migrateplus_json_records', __( 'Could not find a list of records in this JSON file.', 'migrateplus' ) );
	}

	/**
	 * The export header of a decoded file: "migrateplus", or "pressport" in files
	 * exported before the plugin was renamed (same shape).
	 *
	 * @param array $doc Decoded JSON.
	 * @return array|null
	 */
	public static function export_header( array $doc ) {
		foreach ( array( 'migrateplus', 'pressport' ) as $key ) {
			if ( isset( $doc[ $key ] ) && is_array( $doc[ $key ] ) ) {
				return $doc[ $key ];
			}
		}
		return null;
	}

	/**
	 * Whether a file is gzip-compressed (starts with the gzip magic bytes 1f 8b).
	 *
	 * @param string $file Path.
	 * @return bool
	 */
	public static function is_gzip( $file ) {
		return "\x1f\x8b" === (string) file_get_contents( $file, false, null, 0, 2 ); // phpcs:ignore WordPress.WP.AlternativeFunctions
	}

	/**
	 * The largest JSON a .json.gz upload may expand to: an eighth of the memory limit.
	 * prepare() holds the file as a string, the decoded records and their encoded copy
	 * at once, several times the file's size, so larger JSON would run out of memory
	 * part way; stopping first also keeps a gzip bomb from filling the disk. 1 GB when
	 * memory is unlimited. Filter: migrateplus_max_json_size.
	 *
	 * @return int Bytes.
	 */
	public static function max_json_size() {
		$memory = wp_convert_hr_to_bytes( (string) ini_get( 'memory_limit' ) );
		return (int) apply_filters( 'migrateplus_max_json_size', $memory > 0 ? intdiv( $memory, 8 ) : GB_IN_BYTES );
	}

	/**
	 * Decompress a .json.gz into a temporary file in the storage dir, a chunk at a time.
	 *
	 * @param string $file Gzip file.
	 * @return string|WP_Error Path of the decompressed copy (the caller deletes it; so does shutdown, should a fatal error stop the caller first).
	 */
	protected static function gunzip( $file ) {
		if ( ! MigratePlus_Util::zlib_available() ) {
			return new WP_Error( 'migrateplus_gzip', __( 'This file is compressed (.json.gz), and this server cannot read it: PHP\'s zlib extension is missing. Decompress the file first, or export it again uncompressed.', 'migrateplus' ) );
		}
		$limit = self::max_json_size();
		$dest  = MigratePlus_Util::ensure_storage_dir() . 'gunzip-' . wp_generate_password( 12, false ) . '.json';
		// Running out of memory or time while this is decompressed or decoded skips every delete below.
		register_shutdown_function(
			static function () use ( $dest ) {
				if ( file_exists( $dest ) ) {
					wp_delete_file( $dest );
				}
			}
		);
		$in    = gzopen( $file, 'rb' );
		$out   = fopen( $dest, 'wb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		$error = null;
		$size  = 0;
		if ( ! $in || ! $out ) {
			$error = new WP_Error( 'migrateplus_json_cache', __( 'Could not write the import cache to the uploads folder.', 'migrateplus' ) );
		}
		while ( ! $error && ! gzeof( $in ) ) {
			$chunk = gzread( $in, MB_IN_BYTES );
			if ( false === $chunk ) {
				$error = new WP_Error( 'migrateplus_gzip', __( 'This compressed file is damaged and cannot be read.', 'migrateplus' ) );
				break;
			}
			if ( '' === $chunk ) {
				break; // A cut-off file: what is there is decoded, and the JSON check reports the rest.
			}
			$size += strlen( $chunk );
			if ( $size > $limit ) {
				/* translators: %s: size limit */
				$error = new WP_Error( 'migrateplus_gzip_size', sprintf( __( 'This compressed file holds more than %s of JSON, more than this server can import. Use a smaller export, or raise PHP\'s memory limit.', 'migrateplus' ), size_format( $limit ) ) );
				break;
			}
			if ( strlen( $chunk ) !== fwrite( $out, $chunk ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions
				$error = new WP_Error( 'migrateplus_json_cache', __( 'Could not write the import cache to the uploads folder.', 'migrateplus' ) );
			}
		}
		if ( $in ) {
			gzclose( $in );
		}
		if ( $out ) {
			fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		}
		if ( $error ) {
			wp_delete_file( $dest );
			return $error;
		}
		return $dest;
	}

	/** @inheritDoc */
	public function prepare() {
		$file = (string) $this->get( 'file' );
		if ( '' === $file || ! is_readable( $file ) ) {
			return new WP_Error( 'migrateplus_json_input', __( 'Upload a JSON file.', 'migrateplus' ) );
		}
		// Recognised by its first bytes, not its name: a renamed or re-downloaded file may have lost the .gz.
		if ( self::is_gzip( $file ) ) {
			$plain = self::gunzip( $file );
			if ( is_wp_error( $plain ) ) {
				return $plain;
			}
			$doc = json_decode( (string) file_get_contents( $plain ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			wp_delete_file( $plain );
		} else {
			$doc = json_decode( (string) file_get_contents( $file ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		}
		if ( null === $doc ) {
			return new WP_Error( 'migrateplus_json', __( 'Uploaded file is not valid JSON: ', 'migrateplus' ) . json_last_error_msg() );
		}

		// A MigratePlus export: remember its mapping and use its item list. Files exported before the
		// plugin was renamed carry the same header under "pressport".
		$header = is_array( $doc ) ? self::export_header( $doc ) : null;
		$export = null !== $header && isset( $header['mapping'], $doc['items'] ) && is_array( $doc['items'] );
		if ( $export ) {
			// Its site is the base for relative media URLs: only an http(s) address, never javascript: or the like.
			$this->settings['migrateplus_mapping']   = $header['mapping'];
			$this->settings['migrateplus_site']      = isset( $header['site'] ) ? esc_url_raw( (string) $header['site'], array( 'http', 'https' ) ) : '';
			$this->settings['migrateplus_post_type'] = isset( $header['post_type'] ) ? sanitize_key( $header['post_type'] ) : '';
			// Exports with several post types list them; kept as text so the browser sees it (job settings must be scalar there).
			$types                                    = isset( $header['post_types'] ) ? array_filter( array_map( 'sanitize_key', array_map( 'strval', (array) $header['post_types'] ) ) ) : array();
			$this->settings['migrateplus_post_types'] = implode( ',', $types );
		}

		// Not find_records() for an export: with no items it would take the menus for records.
		$records = $export ? $doc['items'] : self::find_records( $doc );
		if ( is_wp_error( $records ) ) {
			return $records;
		}
		$records = array_values( array_filter( $records, 'is_array' ) );
		if ( $export ) {
			// Media first, whatever order the file has, so later items reuse the files it downloads.
			$media   = array_filter(
				$records,
				static function ( $record ) {
					return isset( $record['post_type'] ) && 'attachment' === $record['post_type'];
				}
			);
			$records = array_merge( array_values( $media ), array_values( array_diff_key( $records, $media ) ) );
			$counts  = array();
			foreach ( $records as $record ) {
				$type            = isset( $record['post_type'] ) ? sanitize_key( MigratePlus_Util::stringify( $record['post_type'] ) ) : '';
				$counts[ $type ] = ( isset( $counts[ $type ] ) ? $counts[ $type ] : 0 ) + 1;
			}
			$this->settings['migrateplus_counts'] = $counts;
		}

		$cache = MigratePlus_Util::ensure_storage_dir() . 'json-' . wp_generate_password( 12, false ) . '.json';
		$json  = wp_json_encode( $records );
		if ( false === $json ) {
			// Values PHP cannot write back out, such as a number too large to hold (1e400).
			return new WP_Error( 'migrateplus_json', __( 'This JSON file holds values that cannot be read: ', 'migrateplus' ) . json_last_error_msg() );
		}
		if ( false === file_put_contents( $cache, $json ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions
			return new WP_Error( 'migrateplus_json_cache', __( 'Could not write the import cache to the uploads folder.', 'migrateplus' ) );
		}

		$this->settings['cache_file'] = $cache;
		$this->settings['total']      = count( $records );
		// Menus are imported after every item, from a cache of their own.
		$menus = $export && ! empty( $doc['menus'] ) && is_array( $doc['menus'] ) ? array_values( array_filter( $doc['menus'], 'is_array' ) ) : array();
		if ( $menus ) {
			$menus_file = substr( $cache, 0, -5 ) . '-menus.json';
			if ( false === file_put_contents( $menus_file, wp_json_encode( $menus ) ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions
				// No job will be made to delete the records cache, or what part of this one was written.
				wp_delete_file( $cache );
				wp_delete_file( $menus_file );
				return new WP_Error( 'migrateplus_json_cache', __( 'Could not write the import cache to the uploads folder.', 'migrateplus' ) );
			}
			$this->settings['menus_file']        = $menus_file;
			$this->settings['migrateplus_menus'] = count( $menus );
		}
		// Collect field names from the first few records; optional fields may be missing from the first one.
		// A MigratePlus export's mapping was built from every item, so check them all or later-only fields drop out of it.
		$fields = array();
		foreach ( isset( $this->settings['migrateplus_mapping'] ) ? $records : array_slice( $records, 0, 25 ) as $record ) {
			$fields += array_flip( array_keys( MigratePlus_Util::flatten( $record ) ) );
		}
		$this->settings['fields'] = array_keys( $fields );
		return $this->settings;
	}

	/** @inheritDoc */
	public function fetch( $cursor, $limit ) {
		$cache = (string) $this->get( 'cache_file' );
		if ( ! $cache || ! is_readable( $cache ) ) {
			return new WP_Error( 'migrateplus_json_cache', __( 'Cached feed is missing. Start a new import.', 'migrateplus' ) );
		}
		// Decoded once per request: duplicate checks call this every 200 records.
		static $decoded = array();
		$key            = $cache . ':' . filemtime( $cache );
		if ( ! isset( $decoded[ $key ] ) ) {
			$decoded = array( $key => json_decode( (string) file_get_contents( $cache ), true ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		}
		$all = $decoded[ $key ];
		if ( ! is_array( $all ) ) {
			return new WP_Error( 'migrateplus_json_cache', __( 'Cached feed is unreadable. Start a new import.', 'migrateplus' ) );
		}
		$offset = null === $cursor ? 0 : (int) $cursor;
		$slice  = array_slice( $all, $offset, $limit );
		$next   = $offset + count( $slice );

		return array(
			'records' => array_map( array( 'MigratePlus_Util', 'flatten' ), $slice ),
			'next'    => $next < count( $all ) ? $next : null,
			'total'   => count( $all ),
		);
	}

	/** @inheritDoc */
	public function suggested_mapping() {
		return MigratePlus_Sources::guess_mapping( (array) $this->get( 'fields', array() ) );
	}
}
