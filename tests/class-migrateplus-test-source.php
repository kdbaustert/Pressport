<?php
/**
 * A source that needs no upload, for the AJAX tests: the admin only takes files
 * PHP received in the request (is_uploaded_file()), which PHPUnit can't fake.
 * It serves the first "count" books of a fixed list.
 *
 * @package MigratePlus
 */
class MigratePlus_Test_Source extends MigratePlus_Source_Base {

	const BOOKS = array( 'Dune', 'Emma', 'Ulysses', 'Beloved', 'Middlemarch', 'Rebecca', 'Kindred' );

	public static function id() {
		return 'books';
	}

	public static function label() {
		return 'Book feed';
	}

	public static function settings_schema() {
		return array(
			array(
				'key'      => 'count',
				'label'    => 'Books',
				'type'     => 'text',
				'required' => true,
			),
		);
	}

	public function prepare() {
		$settings = parent::prepare();
		if ( is_wp_error( $settings ) ) {
			return $settings;
		}
		$settings['fields'] = array( 'isbn', 'title', 'blurb' );
		$settings['total']  = min( count( self::BOOKS ), (int) $settings['count'] );
		return $settings;
	}

	public function fetch( $cursor, $limit ) {
		$total   = min( count( self::BOOKS ), (int) $this->get( 'count', 0 ) );
		$offset  = (int) $cursor;
		$records = array();
		foreach ( array_slice( self::BOOKS, $offset, min( $limit, $total - $offset ) ) as $i => $title ) {
			$records[] = array(
				'isbn'  => '978-0-00-00000' . ( $offset + $i ),
				'title' => $title,
				'blurb' => 'A novel.',
			);
		}
		$next = $offset + count( $records );
		return array(
			'records' => $records,
			'next'    => $next < $total ? $next : null,
			'total'   => $total,
		);
	}

	public function suggested_mapping() {
		return array(
			'source_id'    => 'isbn',
			'post_title'   => 'title',
			'post_content' => 'blurb',
		);
	}
}
