<?php
/**
 * CSV file source.
 *
 * @package PressPort
 */

defined( 'ABSPATH' ) || exit;

/**
 * Reads an uploaded CSV file in byte-offset batches (handles multi-line cells).
 */
class PressPort_Source_CSV extends PressPort_Source_Base {

	/** @inheritDoc */
	public static function id() {
		return 'csv';
	}

	/** @inheritDoc */
	public static function label() {
		return __( 'CSV file', 'pressport' );
	}

	/** @inheritDoc */
	public static function settings_schema() {
		return array(
			array(
				'key'      => 'file',
				'label'    => __( 'CSV file', 'pressport' ),
				'type'     => 'file',
				'accept'   => '.csv,text/csv',
				'required' => true,
				'help'     => __( 'First row must contain column headers. UTF-8 recommended.', 'pressport' ),
			),
			array(
				'key'     => 'delimiter',
				'label'   => __( 'Delimiter', 'pressport' ),
				'type'    => 'select',
				'default' => 'auto',
				'options' => array(
					'auto' => __( 'Auto-detect', 'pressport' ),
					','    => __( 'Comma (,)', 'pressport' ),
					';'    => __( 'Semicolon (;)', 'pressport' ),
					'tab'  => __( 'Tab', 'pressport' ),
					'|'    => __( 'Pipe (|)', 'pressport' ),
				),
			),
		);
	}

	/**
	 * Resolve the delimiter character.
	 *
	 * @return string
	 */
	protected function delimiter() {
		$d = $this->get( 'delimiter', 'auto' );
		if ( 'tab' === $d ) {
			return "\t";
		}
		if ( 'auto' !== $d ) {
			return $d;
		}
		return isset( $this->settings['detected_delimiter'] ) ? $this->settings['detected_delimiter'] : ',';
	}

	/**
	 * Open the file and skip a UTF-8 BOM.
	 *
	 * @return resource|WP_Error
	 */
	protected function open() {
		$file = $this->get( 'file' );
		if ( ! $file || ! is_readable( $file ) ) {
			return new WP_Error( 'pressport_csv_missing', __( 'CSV file could not be read.', 'pressport' ) );
		}
		$fh = fopen( $file, 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		if ( ! $fh ) {
			return new WP_Error( 'pressport_csv_open', __( 'CSV file could not be opened.', 'pressport' ) );
		}
		if ( "\xEF\xBB\xBF" !== fread( $fh, 3 ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions
			rewind( $fh );
		}
		return $fh;
	}

	/** @inheritDoc */
	public function prepare() {
		$settings = parent::prepare();
		if ( is_wp_error( $settings ) ) {
			return $settings;
		}
		$fh = $this->open();
		if ( is_wp_error( $fh ) ) {
			return $fh;
		}

		// Detect delimiter from the header line.
		$first = (string) fgets( $fh );
		$best  = ',';
		$max   = 0;
		foreach ( array( ',', ';', "\t", '|' ) as $candidate ) {
			$count = substr_count( $first, $candidate );
			if ( $count > $max ) {
				$max  = $count;
				$best = $candidate;
			}
		}
		$this->settings['detected_delimiter'] = $best;
		$delimiter                            = $this->delimiter();

		// Re-read header properly with fgetcsv.
		rewind( $fh );
		if ( "\xEF\xBB\xBF" !== fread( $fh, 3 ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions
			rewind( $fh );
		}
		$header = fgetcsv( $fh, 0, $delimiter, '"', '\\' );
		if ( ! $header || array( null ) === $header ) {
			fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			return new WP_Error( 'pressport_csv_header', __( 'CSV file has no header row.', 'pressport' ) );
		}
		$headers = array();
		foreach ( $header as $i => $name ) {
			$name = trim( (string) $name );
			if ( '' === $name || isset( $headers[ $name ] ) ) {
				$name = 'column_' . ( $i + 1 );
			}
			$headers[ $name ] = true;
		}
		$this->settings['headers']     = array_keys( $headers );
		$this->settings['data_offset'] = ftell( $fh );

		$rows = 0;
		while ( false !== ( $row = fgetcsv( $fh, 0, $delimiter, '"', '\\' ) ) ) { // phpcs:ignore WordPress.CodeAnalysis.AssignmentInCondition
			if ( array( null ) !== $row ) {
				++$rows;
			}
		}
		fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		$this->settings['total'] = $rows;

		return $this->settings;
	}

	/** @inheritDoc */
	public function fetch( $cursor, $limit ) {
		$fh = $this->open();
		if ( is_wp_error( $fh ) ) {
			return $fh;
		}
		$offset = null === $cursor ? (int) $this->get( 'data_offset', 0 ) : (int) $cursor;
		fseek( $fh, $offset );

		$headers   = (array) $this->get( 'headers', array() );
		$delimiter = $this->delimiter();
		$records   = array();

		while ( count( $records ) < $limit && false !== ( $row = fgetcsv( $fh, 0, $delimiter, '"', '\\' ) ) ) { // phpcs:ignore WordPress.CodeAnalysis.AssignmentInCondition
			if ( array( null ) === $row ) {
				continue; // Blank line.
			}
			$record = array();
			foreach ( $headers as $i => $name ) {
				$record[ $name ] = isset( $row[ $i ] ) ? $row[ $i ] : '';
			}
			$records[] = $record;
		}
		$next = feof( $fh ) ? null : ftell( $fh );
		fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions

		return array(
			'records' => $records,
			'next'    => ( null !== $next && count( $records ) === $limit ) ? $next : null,
			'total'   => isset( $this->settings['total'] ) ? (int) $this->settings['total'] : null,
		);
	}

	/** @inheritDoc */
	public function suggested_mapping() {
		return PressPort_Sources::guess_mapping( (array) $this->get( 'headers', array() ) );
	}
}
