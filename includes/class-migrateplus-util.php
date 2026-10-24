<?php
/**
 * Shared helpers.
 *
 * @package MigratePlus
 */

defined( 'ABSPATH' ) || exit;

/**
 * Static utility helpers used across sources, importer and UI.
 */
class MigratePlus_Util {

	/**
	 * True while MigratePlus is making an outbound request.
	 *
	 * @var bool
	 */
	public static $in_request = false;

	/**
	 * @param bool   $external Whether WordPress considers the host external.
	 * @param string $host     Host.
	 * @param string $url      URL.
	 * @return bool
	 */
	public static function allow_local_host( $external, $host, $url ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- filter signature.
		if ( self::$in_request && MigratePlus_Settings::get( 'allow_local_sources' ) ) {
			return true;
		}
		return $external;
	}

	/**
	 * @param int[]  $ports Allowed ports.
	 * @param string $host  Host.
	 * @param string $url   URL.
	 * @return int[]
	 */
	public static function allow_local_port( $ports, $host = '', $url = '' ) {
		if ( self::$in_request && MigratePlus_Settings::get( 'allow_local_sources' ) && $url ) {
			$port = wp_parse_url( $url, PHP_URL_PORT );
			if ( $port ) {
				$ports[] = (int) $port;
			}
		}
		return $ports;
	}

	/**
	 * Private storage directory for uploaded / cached source files.
	 *
	 * @return string Absolute path with trailing slash.
	 */
	public static function storage_dir() {
		$uploads = wp_upload_dir( null, false );
		return trailingslashit( $uploads['basedir'] ) . 'migrateplus/';
	}

	/**
	 * Create the storage dir and lock it down from direct web access.
	 *
	 * @return string
	 */
	public static function ensure_storage_dir() {
		$dir = self::storage_dir();
		if ( ! is_dir( $dir ) ) {
			wp_mkdir_p( $dir );
		}
		if ( ! file_exists( $dir . 'index.php' ) ) {
			file_put_contents( $dir . 'index.php', "<?php\n// Silence is golden.\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		}
		if ( ! file_exists( $dir . '.htaccess' ) ) {
			file_put_contents( $dir . '.htaccess', "Require all denied\nDeny from all\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		}
		return $dir;
	}

	/**
	 * Whether PHP can write and read gzip files (.json.gz exports and imports).
	 *
	 * @return bool
	 */
	public static function zlib_available() {
		return function_exists( 'gzopen' ) && function_exists( 'gzread' ) && function_exists( 'gzwrite' );
	}

	/**
	 * Flatten a nested array into dot-notation keys. Lists of scalars are kept
	 * as arrays (useful for tags/categories); lists of objects are flattened
	 * with numeric segments (e.g. "authors.0.name").
	 *
	 * @param array  $data   Data.
	 * @param string $prefix Key prefix.
	 * @param int    $depth  Current depth.
	 * @return array
	 */
	public static function flatten( $data, $prefix = '', $depth = 0 ) {
		$out = array();
		if ( ! is_array( $data ) ) {
			return $out;
		}
		foreach ( $data as $key => $value ) {
			$full = '' === $prefix ? (string) $key : $prefix . '.' . $key;
			if ( is_array( $value ) && $depth < 6 ) {
				if ( self::is_scalar_list( $value ) ) {
					$out[ $full ] = array_values( $value );
				} else {
					$out += self::flatten( $value, $full, $depth + 1 );
				}
			} elseif ( is_array( $value ) ) {
				$out[ $full ] = wp_json_encode( $value );
			} elseif ( is_bool( $value ) ) {
				$out[ $full ] = $value ? '1' : '0';
			} elseif ( null === $value ) {
				$out[ $full ] = '';
			} else {
				$out[ $full ] = $value;
			}
		}
		return $out;
	}

	/**
	 * Whether an array is a zero-indexed list containing only scalars.
	 *
	 * @param array $value Value.
	 * @return bool
	 */
	public static function is_scalar_list( $value ) {
		if ( array() === $value ) {
			return true;
		}
		if ( array_keys( $value ) !== range( 0, count( $value ) - 1 ) ) {
			return false;
		}
		foreach ( $value as $v ) {
			if ( ! is_scalar( $v ) && null !== $v ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Normalize a value into a list of trimmed, non-empty strings.
	 * Strings are split on "|", ";" or "," (first separator found wins).
	 *
	 * @param mixed $value Value.
	 * @return string[]
	 */
	public static function to_list( $value ) {
		if ( is_array( $value ) ) {
			$items = $value;
		} elseif ( is_string( $value ) || is_numeric( $value ) ) {
			$value = (string) $value;
			$sep   = null;
			foreach ( array( '|', ';', ',' ) as $candidate ) {
				if ( false !== strpos( $value, $candidate ) ) {
					$sep = $candidate;
					break;
				}
			}
			$items = $sep ? explode( $sep, $value ) : array( $value );
		} else {
			return array();
		}
		$items = array_map(
			static function ( $item ) {
				return is_scalar( $item ) ? trim( (string) $item ) : '';
			},
			$items
		);
		return array_values( array_filter( $items, 'strlen' ) );
	}

	/**
	 * Turn common "share" links into direct download links.
	 *
	 * Handles Google Sheets, Google Drive, Dropbox and GitHub blob URLs. Other
	 * URLs are returned unchanged. Filter with "migrateplus_remote_file_url".
	 *
	 * @param string $url URL.
	 * @param string $ext Preferred extension (used for Google Sheets exports).
	 * @return string
	 */
	public static function direct_download_url( $url, $ext = '' ) {
		$url    = trim( (string) $url );
		$parts  = wp_parse_url( $url );
		$host   = isset( $parts['host'] ) ? strtolower( $parts['host'] ) : '';
		$path   = isset( $parts['path'] ) ? $parts['path'] : '';
		$direct = $url;

		if ( 'docs.google.com' === $host && preg_match( '#^/spreadsheets/d/([A-Za-z0-9_-]+)#', $path, $m ) && false === strpos( $path, '/export' ) && false === strpos( $path, '/pub' ) ) {
			// Google Sheets: export the sheet (keeping the selected tab) as CSV.
			$direct = 'https://docs.google.com/spreadsheets/d/' . $m[1] . '/export?format=' . ( 'tsv' === $ext ? 'tsv' : 'csv' );
			if ( preg_match( '/[#&?]gid=(\d+)/', $url, $gid ) ) {
				$direct .= '&gid=' . $gid[1];
			}
		} elseif ( 'drive.google.com' === $host && preg_match( '#^/file/d/([A-Za-z0-9_-]+)#', $path, $m ) ) {
			$direct = 'https://drive.google.com/uc?export=download&id=' . $m[1];
		} elseif ( in_array( $host, array( 'www.dropbox.com', 'dropbox.com' ), true ) ) {
			$direct = add_query_arg( 'dl', '1', remove_query_arg( array( 'dl', 'raw' ), $url ) );
		} elseif ( 'github.com' === $host && preg_match( '#^/([^/]+)/([^/]+)/blob/(.+)$#', $path, $m ) ) {
			$direct = 'https://raw.githubusercontent.com/' . $m[1] . '/' . $m[2] . '/' . $m[3];
		}

		return (string) apply_filters( 'migrateplus_remote_file_url', $direct, $url );
	}

	/**
	 * Download a remote file into the private storage dir.
	 *
	 * @param string   $url     URL (http or https).
	 * @param string[] $allowed Allowed extensions (the first is used when the URL has none).
	 * @param array    $headers Extra request headers.
	 * @return string|WP_Error Absolute path of the downloaded file.
	 */
	public static function download_to_storage( $url, array $allowed, $headers = array() ) {
		$url    = esc_url_raw( trim( (string) $url ), array( 'http', 'https' ) );
		$scheme = $url ? wp_parse_url( $url, PHP_URL_SCHEME ) : '';
		if ( ! $url || ! in_array( $scheme, array( 'http', 'https' ), true ) ) {
			return new WP_Error( 'migrateplus_remote_url', __( 'Enter a valid http:// or https:// URL.', 'migrateplus' ) );
		}

		$allowed = array_values( array_filter( array_map( 'strtolower', $allowed ) ) );
		$ext     = strtolower( pathinfo( (string) wp_parse_url( $url, PHP_URL_PATH ), PATHINFO_EXTENSION ) );
		if ( ! in_array( $ext, $allowed, true ) ) {
			$ext = $allowed ? $allowed[0] : 'txt';
		}
		$fetch = self::direct_download_url( $url, $ext );

		$max_mb = max( 1, (int) MigratePlus_Settings::get( 'max_remote_size' ) );
		$limit  = $max_mb * MB_IN_BYTES;
		$dest   = self::ensure_storage_dir() . 'remote-' . wp_generate_password( 12, false ) . '.' . $ext;

		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 600 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		}

		self::$in_request = true;
		$response         = wp_safe_remote_get(
			$fetch,
			array(
				'timeout'             => max( 60, (int) MigratePlus_Settings::get( 'request_timeout' ) ),
				'redirection'         => 5,
				'stream'              => true,
				'filename'            => $dest,
				'limit_response_size' => $limit + 1,
				'headers'             => $headers,
				'user-agent'          => 'MigratePlus/' . MIGRATEPLUS_VERSION . '; ' . home_url(),
			)
		);
		self::$in_request = false;

		$fail = static function ( WP_Error $error ) use ( $dest ) {
			if ( file_exists( $dest ) ) {
				wp_delete_file( $dest );
			}
			return $error;
		};

		if ( is_wp_error( $response ) ) {
			/* translators: %s: error message */
			return $fail( new WP_Error( 'migrateplus_remote_http', sprintf( __( 'Could not download the file: %s', 'migrateplus' ), $response->get_error_message() ) ) );
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( $code < 200 || $code >= 300 ) {
			/* translators: 1: HTTP status code, 2: URL */
			return $fail( new WP_Error( 'migrateplus_remote_http', sprintf( __( 'Download returned HTTP %1$d for %2$s', 'migrateplus' ), $code, $url ) ) );
		}
		clearstatcache( true, $dest );
		$size = file_exists( $dest ) ? (int) filesize( $dest ) : 0;
		if ( $size > $limit ) {
			/* translators: %d: size limit in MB */
			return $fail( new WP_Error( 'migrateplus_remote_size', sprintf( __( 'The remote file is larger than the %d MB limit. Raise "Remote file size limit" in Settings, or use WP-CLI.', 'migrateplus' ), $max_mb ) ) );
		}
		if ( 0 === $size ) {
			return $fail( new WP_Error( 'migrateplus_remote_empty', __( 'The remote file is empty.', 'migrateplus' ) ) );
		}

		// A web page instead of a file usually means a login wall or a "view" link.
		$type = wp_remote_retrieve_header( $response, 'content-type' );
		$type = strtolower( is_array( $type ) ? (string) end( $type ) : (string) $type );
		if ( false !== strpos( $type, 'text/html' ) ) {
			$head = (string) file_get_contents( $dest, false, null, 0, 512 ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			if ( preg_match( '/^\s*(<!doctype html|<html|<head|<body)/i', $head ) ) {
				return $fail( new WP_Error( 'migrateplus_remote_html', __( 'The URL returned a web page instead of a file. Use a direct download link and make sure the file is publicly shared.', 'migrateplus' ) ) );
			}
		}

		return $dest;
	}

	/**
	 * Turn an arbitrary value into a string for display / storage.
	 *
	 * @param mixed $value Value.
	 * @return string
	 */
	public static function stringify( $value ) {
		if ( is_array( $value ) ) {
			return implode( ', ', array_map( array( __CLASS__, 'stringify' ), $value ) );
		}
		return null === $value ? '' : (string) $value;
	}
}
