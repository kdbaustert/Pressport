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
		foreach ( static::settings_schema() as $field ) {
			if ( ! empty( $field['required'] ) && '' === trim( (string) $this->get( $field['key'] ) ) ) {
				/* translators: %s: field label */
				return new WP_Error( 'pressport_required', sprintf( __( '%s is required.', 'pressport' ), $field['label'] ) );
			}
		}
		return $this->settings;
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
