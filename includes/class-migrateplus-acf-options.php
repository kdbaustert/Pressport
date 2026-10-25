<?php
/**
 * ACF options page values in MigratePlus exports.
 *
 * @package MigratePlus
 */

defined( 'ABSPATH' ) || exit;

/**
 * Exports the values of every ACF options page (site-wide fields such as a
 * footer address or a default banner) and saves them after the records of an
 * import, since they often point at posts and images the file carries.
 *
 * Values are converted like a post's ACF fields: images as URLs that are
 * downloaded, related posts as references that are looked up. The value each
 * field had before is kept in the job, so a rollback puts it back.
 */
class MigratePlus_ACF_Options {

	/**
	 * Every options page with its field values.
	 *
	 * @param array $args Export args (include_acf is implied by asking for this section).
	 * @return array[] { page, title, post_id, fields: { name => value } }
	 */
	public static function export( array $args ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- every side section exports with the export args.
		$out = array();
		if ( ! MigratePlus_ACF::active() || ! function_exists( 'acf_get_options_pages' ) || ! self::user_can() ) {
			return $out;
		}
		foreach ( (array) acf_get_options_pages() as $page ) {
			$fields = array();
			foreach ( self::fields_for( $page ) as $f ) {
				$field = acf_get_field( $f['key'] );
				if ( ! $field ) {
					continue;
				}
				$raw = self::stored_value( $field, $page['post_id'] );
				if ( null === $raw || '' === $raw || ( false === $raw && 'true_false' !== $field['type'] ) ) {
					continue;
				}
				$fields[ $field['name'] ] = MigratePlus_Exporter::export_acf_value( $field, $raw );
			}
			if ( ! $fields ) {
				continue;
			}
			$out[] = array(
				'page'    => (string) $page['menu_slug'],
				'title'   => (string) $page['page_title'],
				'post_id' => (string) $page['post_id'],
				'fields'  => $fields,
			);
		}
		return $out;
	}

	/**
	 * How many options pages with values an export would carry.
	 *
	 * @param array $args Export args.
	 * @return int
	 */
	public static function count( array $args ) {
		return count( self::export( $args ) );
	}

	/**
	 * The top-level fields shown on an options page.
	 *
	 * @param array $page Options page as acf_get_options_pages() lists it.
	 * @return array[] { key, name }
	 */
	protected static function fields_for( array $page ) {
		$out = array();
		foreach ( (array) acf_get_field_groups( array( 'options_page' => $page['menu_slug'] ) ) as $group ) {
			if ( isset( $group['active'] ) && ! $group['active'] ) {
				continue;
			}
			foreach ( (array) acf_get_fields( $group ) as $field ) {
				if ( ! empty( $field['name'] ) && ! in_array( $field['type'], MigratePlus_ACF::LAYOUT_TYPES, true ) ) {
					$out[] = array(
						'key'  => $field['key'],
						'name' => $field['name'],
					);
				}
			}
		}
		return $out;
	}

	/**
	 * Whether a job still has options to import. They come after its records.
	 *
	 * @param MigratePlus_Job $job Job.
	 * @return bool
	 */
	public static function pending( MigratePlus_Job $job ) {
		return ! empty( $job->data['settings']['acf_options_file'] ) && ! empty( $job->data['options']['import_acf_options'] );
	}

	/**
	 * The options pages a job's file carries, cached by the JSON source.
	 *
	 * @param MigratePlus_Job $job Job.
	 * @return array[]|WP_Error
	 */
	protected static function load( MigratePlus_Job $job ) {
		$file = isset( $job->data['settings']['acf_options_file'] ) ? (string) $job->data['settings']['acf_options_file'] : '';
		$data = '' !== $file && is_readable( $file ) ? json_decode( (string) file_get_contents( $file ), true ) : null; // phpcs:ignore WordPress.WP.AlternativeFunctions
		return is_array( $data ) ? $data : new WP_Error( 'migrateplus_acf_options_cache', __( 'The cached ACF options are missing. Start a new import.', 'migrateplus' ) );
	}

	/**
	 * The options stage: every page in one request.
	 *
	 * @param MigratePlus_Job $job Job (side_prev updated in place).
	 * @return array { done: true, results: array[] }
	 */
	public static function import_batch( MigratePlus_Job $job ) {
		$pages = self::load( $job );
		if ( is_wp_error( $pages ) ) {
			return array(
				'done'    => true,
				'results' => array( self::result( 'failed', $pages->get_error_message() ) ),
			);
		}
		if ( ! MigratePlus_ACF::active() || ! function_exists( 'acf_get_options_page' ) ) {
			return array(
				'done'    => true,
				'results' => array( self::result( 'skipped', __( 'ACF options were not imported: Advanced Custom Fields is not active on this site.', 'migrateplus' ), true ) ),
			);
		}
		if ( ! self::user_can() ) {
			return array(
				'done'    => true,
				'results' => array( self::result( 'skipped', __( 'ACF options were not imported: you are not allowed to manage ACF on this site.', 'migrateplus' ), true ) ),
			);
		}
		$opts    = array_merge( MigratePlus_Job::default_options(), (array) $job->data['options'] );
		$results = array();
		foreach ( $pages as $page ) {
			if ( is_array( $page ) ) {
				$results[] = self::import_page( $page, $job, $opts );
			}
		}
		return array(
			'done'    => true,
			'results' => $results,
		);
	}

	/**
	 * Save one options page's values.
	 *
	 * @param array           $record Page as exported.
	 * @param MigratePlus_Job $job    Job.
	 * @param array           $opts   Job options.
	 * @return array Result.
	 */
	protected static function import_page( array $record, MigratePlus_Job $job, array $opts ) {
		$slug   = isset( $record['page'] ) ? self::page_slug( MigratePlus_Util::stringify( $record['page'] ) ) : '';
		$title  = isset( $record['title'] ) ? MigratePlus_Util::stringify( $record['title'] ) : $slug;
		$values = isset( $record['fields'] ) && is_array( $record['fields'] ) ? $record['fields'] : array();
		$page   = '' !== $slug ? acf_get_options_page( $slug ) : false;
		if ( ! $page ) {
			/* translators: %s: options page title */
			return self::result( 'skipped', sprintf( __( 'ACF options page "%s" does not exist on this site, so its values were not imported.', 'migrateplus' ), $title ), true );
		}
		$by_name = array();
		foreach ( self::fields_for( $page ) as $f ) {
			$by_name[ $f['name'] ] = $f['key'];
		}
		if ( ! empty( $opts['dry_run'] ) ) {
			/* translators: 1: number of fields, 2: options page title */
			return self::result( 'updated', sprintf( __( 'Would set %1$d fields on ACF options page "%2$s".', 'migrateplus' ), count( array_intersect_key( $values, $by_name ) ), $title ) );
		}
		$post_id  = (string) $page['post_id'];
		$ctx      = array(
			'post_id'      => $post_id,
			'job_id'       => $job->data['id'],
			'job'          => $job,
			'base'         => isset( $job->data['settings']['migrateplus_site'] ) ? (string) $job->data['settings']['migrateplus_site'] : '',
			'create_terms' => ! empty( $opts['create_terms'] ),
			'rewrite'      => ! empty( $opts['rewrite_links'] ),
			'defer'        => false,
			'pending'      => array(),
			'warnings'     => array(),
		);
		$saved    = 0;
		$warnings = array();
		foreach ( $values as $name => $raw ) {
			$field = isset( $by_name[ $name ] ) ? acf_get_field( $by_name[ $name ] ) : false;
			if ( ! $field ) {
				/* translators: 1: field name, 2: options page title */
				$warnings[] = sprintf( __( 'ACF field "%1$s" is not on options page "%2$s".', 'migrateplus' ), $name, $title );
				continue;
			}
			if ( ! isset( $job->data['side_prev']['acf_options'][ $post_id ][ $field['key'] ] ) ) {
				$prev = self::stored_value( $field, $post_id );
				$job->data['side_prev']['acf_options'][ $post_id ][ $field['key'] ] = null === $prev ? array( 'none' => true ) : array( 'value' => $prev );
			}
			$value = MigratePlus_ACF::convert( $field, $raw, $ctx );
			// Slashed as from a form post, like a post's fields (ACF unslashes option values itself).
			update_field( $field['key'], wp_slash( $value ), $post_id );
			++$saved;
		}
		foreach ( array_unique( $ctx['warnings'] ) as $w ) {
			$warnings[] = $w;
		}
		/* translators: 1: number of fields, 2: options page title */
		$message = sprintf( __( 'Set %1$d fields on ACF options page "%2$s".', 'migrateplus' ), $saved, $title );
		return self::result( 'updated', $warnings ? $message . ' ' . __( 'Warnings:', 'migrateplus' ) . ' ' . implode( ' ', $warnings ) : $message, ! empty( $warnings ) );
	}

	/**
	 * Put back the values the import replaced.
	 *
	 * @param MigratePlus_Job $job Job (side_prev cleared in place).
	 */
	public static function restore( MigratePlus_Job $job ) {
		if ( empty( $job->data['side_prev']['acf_options'] ) || ! MigratePlus_ACF::active() ) {
			return;
		}
		foreach ( (array) $job->data['side_prev']['acf_options'] as $post_id => $fields ) {
			foreach ( (array) $fields as $key => $prev ) {
				if ( ! acf_get_field( $key ) ) {
					continue;
				}
				if ( isset( $prev['none'] ) ) {
					delete_field( $key, $post_id );
				} elseif ( is_array( $prev ) && array_key_exists( 'value', $prev ) ) {
					update_field( $key, wp_slash( $prev['value'] ), $post_id );
				}
			}
		}
		unset( $job->data['side_prev']['acf_options'] );
	}

	/**
	 * A field's saved value, unformatted, or null when none is saved. Unlike
	 * get_field(), never the field's default: a rollback would save the default
	 * where there was nothing, and an export would carry it as a value.
	 *
	 * @param array      $field   Field.
	 * @param int|string $post_id Where it is saved ("options", "options_page").
	 * @return mixed
	 */
	protected static function stored_value( array $field, $post_id ) {
		if ( ! function_exists( 'acf_get_metadata_by_field' ) ) {
			return get_field( $field['key'], $post_id, false ); // ACF before 6.4.
		}
		if ( null === acf_get_metadata_by_field( $post_id, $field ) ) {
			return null;
		}
		return get_field( $field['key'], $post_id, false );
	}

	/**
	 * The options page an export names, as this site registers it: options pages
	 * registered in code keep their slug's case ("Site-Options"), which
	 * sanitize_key() would lower.
	 *
	 * @param string $slug Slug from the file.
	 * @return string
	 */
	protected static function page_slug( $slug ) {
		$slug  = trim( $slug );
		$pages = function_exists( 'acf_get_options_pages' ) ? acf_get_options_pages() : array();
		if ( '' !== $slug && is_array( $pages ) && array_key_exists( $slug, $pages ) ) {
			return $slug;
		}
		return sanitize_key( $slug ); // As older versions read it, and lowercase slugs are the norm.
	}

	/**
	 * Whether the person may manage ACF options (ACF's capability setting).
	 * WP-CLI without --user runs with the shell account's access.
	 *
	 * @return bool
	 */
	protected static function user_can() {
		return ! get_current_user_id() || current_user_can( function_exists( 'acf_get_setting' ) ? acf_get_setting( 'capability' ) : 'manage_options' );
	}

	/**
	 * @param string $action  updated|skipped|failed.
	 * @param string $message Message.
	 * @param bool   $warn    Warning.
	 * @return array
	 */
	protected static function result( $action, $message, $warn = false ) {
		return array(
			'action'  => $action,
			'post_id' => 0,
			'message' => $message,
			'warning' => $warn,
			'section' => 'acf_options',
		);
	}
}
