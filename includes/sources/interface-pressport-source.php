<?php
/**
 * Source contract + shared base class.
 *
 * @package PressPort
 */

defined( 'ABSPATH' ) || exit;

/**
 * A content source yields pages of flat records (dot-notation keys).
 */
interface PressPort_Source {

	/**
	 * @return string
	 */
	public static function id();

	/**
	 * @return string
	 */
	public static function label();

	/**
	 * Settings schema rendered by the admin UI and validated on save.
	 * Each item: key, label, type (text|url|password|textarea|select|number|file), options, help, required, default.
	 * File fields also accept "accept" (".csv,text/csv") and "remote" (default true): when remote is on,
	 * the user can give a URL instead of uploading; it is stored as "{key}_url" and downloaded in prepare().
	 *
	 * @return array[]
	 */
	public static function settings_schema();

	/**
	 * Validate settings and perform one-time setup (download, count rows...).
	 * Returns the (possibly enriched) settings to persist on the job.
	 *
	 * @return array|WP_Error
	 */
	public function prepare();

	/**
	 * Fetch a batch of records.
	 *
	 * @param mixed $cursor Opaque cursor; null for the first batch.
	 * @param int   $limit  Batch size.
	 * @return array|WP_Error { records: array[], next: mixed|null, total: int|null }
	 */
	public function fetch( $cursor, $limit );

	/**
	 * Suggested default field mapping: target field => source field.
	 *
	 * @return array
	 */
	public function suggested_mapping();
}

abstract class PressPort_Source_Base implements PressPort_Source {

	protected $settings;

	/**
	 * @param array $settings Settings.
	 */
	public function __construct( array $settings ) {
		$defaults = array();
		foreach ( static::settings_schema() as $field ) {
			$defaults[ $field['key'] ] = isset( $field['default'] ) ? $field['default'] : '';
		}
		$this->settings = array_merge( $defaults, $settings );
	}

	/**
	 * @param string $key     Key.
	 * @param mixed  $default Default.
	 * @return mixed
	 */
	protected function get( $key, $default = '' ) {
		return isset( $this->settings[ $key ] ) && '' !== $this->settings[ $key ] ? $this->settings[ $key ] : $default;
	}

	/**
	 * Default prepare: check required settings.
	 *
	 * @return array|WP_Error
	 */
	public function prepare() {
		$remote = $this->download_remote_files();
		if ( is_wp_error( $remote ) ) {
			return $remote;
		}
		foreach ( static::settings_schema() as $field ) {
			if ( ! empty( $field['required'] ) && '' === trim( (string) $this->get( $field['key'] ) ) ) {
				if ( 'file' === $field['type'] && self::allows_remote( $field ) ) {
					/* translators: %s: field label */
					return new WP_Error( 'pressport_required', sprintf( __( '%s is required: upload a file or enter its URL.', 'pressport' ), $field['label'] ) );
				}
				/* translators: %s: field label */
				return new WP_Error( 'pressport_required', sprintf( __( '%s is required.', 'pressport' ), $field['label'] ) );
			}
		}
		return $this->settings;
	}

	/**
	 * Whether a file field may be filled from a remote URL.
	 *
	 * @param array $field Schema field.
	 * @return bool
	 */
	public static function allows_remote( array $field ) {
		return 'file' === $field['type'] && ( ! isset( $field['remote'] ) || $field['remote'] );
	}

	/**
	 * Extensions a file field accepts, from its "accept" attribute.
	 *
	 * @param array $field Schema field.
	 * @return string[]
	 */
	public static function accepted_extensions( array $field ) {
		$exts = array();
		foreach ( explode( ',', isset( $field['accept'] ) ? (string) $field['accept'] : '' ) as $item ) {
			$item = trim( $item );
			if ( 0 === strpos( $item, '.' ) ) {
				$exts[] = strtolower( ltrim( $item, '.' ) );
			}
		}
		$exts[] = 'txt';
		return array_values( array_unique( $exts ) );
	}

	/**
	 * Download every file field that was given as a URL ("{key}_url").
	 * Runs on every prepare(), so re-running a job picks up a fresh copy.
	 *
	 * @return true|WP_Error
	 */
	protected function download_remote_files() {
		foreach ( static::settings_schema() as $field ) {
			if ( ! self::allows_remote( $field ) ) {
				continue;
			}
			$key = $field['key'];
			// A URL given directly as the file value (e.g. in a WP-CLI config file) counts too.
			if ( preg_match( '#^https?://#i', (string) $this->get( $key ) ) && '' === trim( (string) $this->get( $key . '_url' ) ) ) {
				$this->settings[ $key . '_url' ] = $this->settings[ $key ];
				$this->settings[ $key ]          = '';
			}
			$url = trim( (string) $this->get( $key . '_url' ) );
			if ( '' === $url ) {
				continue;
			}
			$old  = (string) $this->get( $key );
			$path = PressPort_Util::download_to_storage( $url, self::accepted_extensions( $field ), $this->remote_headers() );
			if ( is_wp_error( $path ) ) {
				return $path;
			}
			// Remove the previous download (only ever inside our storage dir).
			if ( $old && $old !== $path && 0 === strpos( wp_normalize_path( $old ), wp_normalize_path( PressPort_Util::storage_dir() ) ) && file_exists( $old ) ) {
				wp_delete_file( $old );
			}
			$this->settings[ $key ]          = $path;
			$this->settings[ $key . '_url' ] = $url;
		}
		return true;
	}

	/**
	 * Extra HTTP headers for remote file downloads (e.g. an Authorization header).
	 *
	 * @return array
	 */
	protected function remote_headers() {
		return array();
	}

	/**
	 * Whether this source reads anything remote that should be refreshed on re-run.
	 *
	 * @return bool
	 */
	public function has_remote_input() {
		foreach ( static::settings_schema() as $field ) {
			if ( self::allows_remote( $field ) && '' !== trim( (string) $this->get( $field['key'] . '_url' ) ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * @return array
	 */
	public function suggested_mapping() {
		return array();
	}

	/**
	 * Normalize a base URL (no trailing slash).
	 *
	 * @param string $url URL.
	 * @return string
	 */
	protected function base_url( $url ) {
		return untrailingslashit( esc_url_raw( trim( (string) $url ) ) );
	}
}
