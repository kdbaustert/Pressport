<?php
/**
 * Shared helpers.
 *
 * @package PressPort
 */

defined( 'ABSPATH' ) || exit;

/**
 * Static utility helpers used across sources, importer and UI.
 */
class PressPort_Util {

	/**
	 * True while PressPort is making an outbound request.
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
	public static function allow_local_host( $external, $host, $url ) {
		if ( self::$in_request && PressPort_Settings::get( 'allow_local_sources' ) ) {
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
		if ( self::$in_request && PressPort_Settings::get( 'allow_local_sources' ) && $url ) {
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
		return trailingslashit( $uploads['basedir'] ) . 'pressport/';
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
	 * Read a value from a nested array by dot path ("data.items").
	 *
	 * @param array  $data Data.
	 * @param string $path Path.
	 * @return mixed|null
	 */
	public static function get_path( $data, $path ) {
		if ( '' === $path || null === $path ) {
			return $data;
		}
		foreach ( explode( '.', $path ) as $segment ) {
			if ( is_array( $data ) && array_key_exists( $segment, $data ) ) {
				$data = $data[ $segment ];
			} else {
				return null;
			}
		}
		return $data;
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
	 * GET a URL and decode its JSON body.
	 *
	 * @param string $url     URL.
	 * @param array  $headers Extra headers.
	 * @return array|WP_Error { body: mixed, headers: array }
	 */
	public static function get_json( $url, $headers = array() ) {
		self::$in_request = true;
		$response         = wp_safe_remote_get(
			$url,
			array(
				'timeout'     => (int) PressPort_Settings::get( 'request_timeout' ),
				'redirection' => 3,
				'headers'     => array_merge( array( 'Accept' => 'application/json, application/vnd.api+json, application/feed+json' ), $headers ),
				'user-agent'  => 'PressPort/' . PRESSPORT_VERSION . '; ' . home_url(),
			)
		);
		self::$in_request = false;
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = wp_remote_retrieve_body( $response );
		if ( $code < 200 || $code >= 300 ) {
			/* translators: 1: HTTP status code, 2: URL */
			return new WP_Error( 'pressport_http', sprintf( __( 'Request returned HTTP %1$d for %2$s', 'pressport' ), $code, $url ) );
		}
		$decoded = json_decode( $body, true );
		if ( null === $decoded && JSON_ERROR_NONE !== json_last_error() ) {
			return new WP_Error( 'pressport_json', __( 'Response was not valid JSON: ', 'pressport' ) . json_last_error_msg() );
		}
		return array(
			'body'    => $decoded,
			'headers' => wp_remote_retrieve_headers( $response ),
		);
	}

	/**
	 * Parse "Name: value" lines into a header array.
	 *
	 * @param string $raw Raw lines.
	 * @return array
	 */
	public static function parse_header_lines( $raw ) {
		$headers = array();
		foreach ( preg_split( '/\r\n|\r|\n/', (string) $raw ) as $line ) {
			if ( false === strpos( $line, ':' ) ) {
				continue;
			}
			list( $name, $value ) = array_map( 'trim', explode( ':', $line, 2 ) );
			if ( '' !== $name ) {
				$headers[ $name ] = $value;
			}
		}
		return $headers;
	}

	/**
	 * Basic-auth header if credentials are present.
	 *
	 * @param string $user User.
	 * @param string $pass Password.
	 * @return array
	 */
	public static function basic_auth( $user, $pass ) {
		if ( '' === (string) $user ) {
			return array();
		}
		return array( 'Authorization' => 'Basic ' . base64_encode( $user . ':' . $pass ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions
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
