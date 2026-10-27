<?php
/**
 * ACF field groups, post types, taxonomies and options pages in MigratePlus exports.
 *
 * @package MigratePlus
 */

defined( 'ABSPATH' ) || exit;

/**
 * Exports what Advanced Custom Fields defines through its admin screens (field
 * groups with their fields, and the post types, taxonomies and options pages of
 * ACF 6.1+) in the shape ACF's own export tool writes, and imports them before
 * the records of an import, so the fields the records carry exist to save into.
 *
 * Definitions are matched by their ACF key, so importing the same file again
 * updates them. One that this site defines in code or acf-json is left alone:
 * the code is the source of truth there. Definitions the import creates are
 * posts tagged _migrateplus_job_id and go with a rollback; ones it updated
 * keep a copy of their previous shape in the job, which rollback puts back.
 */
class MigratePlus_ACF_Definitions {

	/**
	 * Internal post types, in import order: a field group may be shown on a
	 * post type or options page defined in the same file.
	 *
	 * @var string[]
	 */
	const KINDS = array( 'acf-post-type', 'acf-taxonomy', 'acf-ui-options-page', 'acf-field-group' );

	/**
	 * Location rule params whose value is a post id. The file has the old site's
	 * ids, which may name other posts here.
	 *
	 * @var string[]
	 */
	const POST_PARAMS = array( 'post', 'page', 'page_parent' );

	/**
	 * Whether ACF is active with what this needs.
	 *
	 * @return bool
	 */
	public static function active() {
		return MigratePlus_ACF::active() && function_exists( 'acf_prepare_field_group_for_export' ) && function_exists( 'acf_import_field_group' );
	}

	/**
	 * Every definition, in import order, as ACF's export tool writes it.
	 *
	 * @param array $args Export args (unused: definitions carry no content).
	 * @return array[] { kind, key, title, data }
	 */
	public static function export( array $args ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- every side section exports with the export args.
		if ( ! self::active() || ! self::user_can() ) {
			return array();
		}
		$out = array();
		foreach ( self::KINDS as $kind ) {
			foreach ( self::definitions( $kind ) as $def ) {
				$out[] = array(
					'kind'  => $kind,
					'key'   => $def['key'],
					'title' => $def['title'],
					'data'  => $def,
				);
			}
		}
		return $out;
	}

	/**
	 * How many definitions an export would carry.
	 *
	 * @param array $args Export args.
	 * @return int
	 */
	public static function count( array $args ) {
		return count( self::export( $args ) );
	}

	/**
	 * The definitions of one kind, prepared for export (ids dropped, fields nested).
	 *
	 * @param string $kind One of KINDS.
	 * @return array[]
	 */
	protected static function definitions( $kind ) {
		$out = array();
		if ( 'acf-field-group' === $kind ) {
			foreach ( (array) acf_get_field_groups() as $group ) {
				$group['fields'] = acf_get_fields( $group );
				$prepared        = acf_prepare_field_group_for_export( $group );
				if ( is_array( $prepared ) && ! empty( $prepared['key'] ) ) {
					$out[] = $prepared;
				}
			}
			return $out;
		}
		if ( ! function_exists( 'acf_get_internal_post_type_posts' ) || ! function_exists( 'acf_prepare_internal_post_type_for_export' ) || ! post_type_exists( $kind ) ) {
			return $out; // ACF before 6.1 (post types), or 6.2 (options pages), has none of this kind.
		}
		foreach ( (array) acf_get_internal_post_type_posts( $kind ) as $post ) {
			$prepared = acf_prepare_internal_post_type_for_export( $post, $kind );
			if ( is_array( $prepared ) && ! empty( $prepared['key'] ) ) {
				$out[] = $prepared;
			}
		}
		return $out;
	}

	/**
	 * Whether a job still has definitions to import. They come before its records.
	 *
	 * @param MigratePlus_Job $job Job.
	 * @return bool
	 */
	public static function pending( MigratePlus_Job $job ) {
		return ! empty( $job->data['settings']['acf_definitions_file'] ) && ! empty( $job->data['options']['import_acf_definitions'] );
	}

	/**
	 * The definitions a job's file carries, cached by the JSON source.
	 *
	 * @param MigratePlus_Job $job Job.
	 * @return array[]|WP_Error
	 */
	protected static function load( MigratePlus_Job $job ) {
		$file = isset( $job->data['settings']['acf_definitions_file'] ) ? (string) $job->data['settings']['acf_definitions_file'] : '';
		$data = '' !== $file && is_readable( $file ) ? json_decode( (string) file_get_contents( $file ), true ) : null; // phpcs:ignore WordPress.WP.AlternativeFunctions
		return is_array( $data ) ? $data : new WP_Error( 'migrateplus_acf_cache', __( 'The cached ACF definitions are missing. Start a new import.', 'migrateplus' ) );
	}

	/**
	 * Import the next batch of definitions, resuming at stage_pos.
	 *
	 * @param MigratePlus_Job $job Job (stage_pos, created_total and side_prev are updated in place).
	 * @return array { done: bool, results: array[] }
	 */
	public static function import_batch( MigratePlus_Job $job ) {
		$defs = self::load( $job );
		if ( is_wp_error( $defs ) ) {
			return array(
				'done'    => true,
				'results' => array( self::result( 'failed', 0, $defs->get_error_message() ) ),
			);
		}
		if ( ! self::active() ) {
			return array(
				'done'    => true,
				'results' => array( self::result( 'skipped', 0, __( 'ACF definitions were not imported: Advanced Custom Fields is not active on this site.', 'migrateplus' ), true ) ),
			);
		}
		if ( ! self::user_can() ) {
			return array(
				'done'    => true,
				'results' => array( self::result( 'skipped', 0, __( 'ACF definitions were not imported: you are not allowed to manage ACF on this site.', 'migrateplus' ), true ) ),
			);
		}
		$opts    = array_merge( MigratePlus_Job::default_options(), (array) $job->data['options'] );
		$limit   = max( 1, min( 100, (int) $opts['batch_size'] ) );
		$pos     = (int) $job->data['stage_pos'];
		$results = array();
		foreach ( array_slice( $defs, $pos, $limit ) as $record ) {
			if ( is_array( $record ) ) {
				$results[] = self::import_definition( $record, $job, $opts );
			}
			++$pos;
		}
		$job->data['stage_pos'] = $pos;
		return array(
			'done'    => $pos >= count( $defs ),
			'results' => $results,
		);
	}

	/**
	 * Create or update one definition.
	 *
	 * @param array           $record { kind, key, title, data }.
	 * @param MigratePlus_Job $job    Job.
	 * @param array           $opts   Job options.
	 * @return array Result.
	 */
	protected static function import_definition( array $record, MigratePlus_Job $job, array $opts ) {
		$kind  = isset( $record['kind'] ) ? sanitize_key( $record['kind'] ) : '';
		$data  = isset( $record['data'] ) && is_array( $record['data'] ) ? $record['data'] : array();
		$key   = isset( $data['key'] ) ? sanitize_text_field( MigratePlus_Util::stringify( $data['key'] ) ) : '';
		$title = isset( $data['title'] ) ? MigratePlus_Util::stringify( $data['title'] ) : $key;
		$label = self::kind_label( $kind );
		if ( '' === $key || ! in_array( $kind, self::KINDS, true ) ) {
			return self::result( 'failed', 0, __( 'An ACF definition without a kind or key was not imported.', 'migrateplus' ) );
		}
		$data['key'] = $key;
		if ( 'acf-field-group' !== $kind && ( ! function_exists( 'acf_import_internal_post_type' ) || ! post_type_exists( $kind ) ) ) {
			/* translators: 1: kind, 2: title */
			return self::result( 'skipped', 0, sprintf( __( 'ACF %1$s "%2$s" was not imported: this version of ACF has no %1$ss.', 'migrateplus' ), $label, $title ), true );
		}
		if ( self::is_local( $kind, $key ) ) {
			/* translators: 1: kind, 2: title */
			return self::result( 'skipped', 0, sprintf( __( 'ACF %1$s "%2$s" is defined in code or acf-json on this site, so the file\'s copy was not imported.', 'migrateplus' ), $label, $title ) );
		}
		$existing = self::existing( $kind, $key );
		if ( ! empty( $opts['dry_run'] ) ) {
			/* translators: 1: Would create/Would update, 2: kind, 3: title */
			return self::result( $existing ? 'updated' : 'created', $existing ? (int) $existing['ID'] : 0, sprintf( __( '%1$s ACF %2$s "%3$s".', 'migrateplus' ), $existing ? __( 'Would update', 'migrateplus' ) : __( 'Would create', 'migrateplus' ), $label, $title ) );
		}
		if ( $existing && ! isset( $job->data['side_prev']['acf_definitions'][ $key ] ) ) {
			// Its shape before the import, so rollback can put it back.
			$job->data['side_prev']['acf_definitions'][ $key ] = array(
				'kind' => $kind,
				'data' => self::prepare( $kind, $existing ),
			);
		}
		if ( 'acf-field-group' === $kind && isset( $data['location'] ) && is_array( $data['location'] ) ) {
			$missing          = array();
			$source           = $data['location'];
			$data['location'] = self::relink_location( $source, $job, $missing );
			if ( $missing ) {
				// Posts come after definitions: relink_locations() points at them once they are here.
				$job->data['acf_locations'][ $key ] = $source;
			}
		}
		$saved = self::save( $kind, $data );
		if ( ! $saved || empty( $saved['ID'] ) ) {
			/* translators: 1: kind, 2: title */
			return self::result( 'failed', 0, sprintf( __( 'ACF %1$s "%2$s" could not be saved.', 'migrateplus' ), $label, $title ) );
		}
		if ( ! $existing ) {
			update_post_meta( (int) $saved['ID'], '_migrateplus_job_id', $job->data['id'] );
			++$job->data['created_total'];
		}
		/* translators: 1: Created/Updated, 2: kind, 3: title */
		return self::result( $existing ? 'updated' : 'created', (int) $saved['ID'], sprintf( __( '%1$s ACF %2$s "%3$s".', 'migrateplus' ), $existing ? __( 'Updated', 'migrateplus' ) : __( 'Created', 'migrateplus' ), $label, $title ) );
	}

	/**
	 * Point the location rules of field groups this job imported at the posts
	 * they name, now the job's records are here. Rules whose post never came stay
	 * pointed at nothing, and are warned about.
	 *
	 * @param MigratePlus_Job $job Job (acf_locations is cleared in place).
	 */
	public static function relink_locations( MigratePlus_Job $job ) {
		if ( empty( $job->data['acf_locations'] ) || ! self::active() ) {
			return;
		}
		foreach ( (array) $job->data['acf_locations'] as $key => $source ) {
			$group = self::existing( 'acf-field-group', (string) $key );
			if ( ! $group ) {
				continue;
			}
			$missing           = array();
			$group['location'] = self::relink_location( (array) $source, $job, $missing );
			acf_update_field_group( $group );
			if ( $missing ) {
				/* translators: 1: field group title, 2: post ids on the old site */
				$job->log( 'warning', sprintf( __( 'ACF field group "%1$s" is shown on posts that are not on this site (old ids %2$s), so those location rules match nothing.', 'migrateplus' ), $group['title'], implode( ', ', $missing ) ) );
			}
		}
		$job->data['acf_locations'] = array();
	}

	/**
	 * Location rules with the old site's post ids swapped for the ones here. An id
	 * whose post is not here becomes Blocks::NOWHERE, not left as it is: it may be
	 * another post here, which the group would then be shown on.
	 *
	 * @param array           $location Groups of rules, each { param, operator, value }.
	 * @param MigratePlus_Job $job      Job.
	 * @param string[]        $missing  Old ids not found, added to.
	 * @return array
	 */
	protected static function relink_location( array $location, MigratePlus_Job $job, array &$missing ) {
		foreach ( $location as $g => $rules ) {
			foreach ( (array) $rules as $r => $rule ) {
				if ( ! is_array( $rule ) || ! isset( $rule['param'], $rule['value'] ) || ! in_array( $rule['param'], self::POST_PARAMS, true ) || ! is_numeric( $rule['value'] ) || (int) $rule['value'] <= 0 ) {
					continue;
				}
				$old = (string) (int) $rule['value'];
				$new = MigratePlus_Importer::find_by_uid( MigratePlus_Importer::uid( $job, $old ), array_keys( get_post_types() ), $job->data['id'] );
				if ( ! $new ) {
					$missing[] = $old;
				}
				$location[ $g ][ $r ]['value'] = (string) ( $new ? $new : MigratePlus_Blocks::NOWHERE );
			}
		}
		return $location;
	}

	/**
	 * Save a definition through ACF's import functions, which update by key.
	 * A post type or taxonomy is registered at once, so records of it in the
	 * same WP-CLI run can be imported.
	 *
	 * @param string $kind One of KINDS.
	 * @param array  $data Definition as exported.
	 * @return array|false The saved definition, with its ID.
	 */
	public static function save( $kind, array $data ) {
		// ACF updates by ID, not by key: without it the import would add a second copy.
		$existing = isset( $data['key'] ) ? self::existing( $kind, $data['key'] ) : false;
		if ( $existing ) {
			$data['ID'] = (int) $existing['ID'];
		} else {
			unset( $data['ID'] );
		}
		if ( 'acf-field-group' === $kind ) {
			$saved = acf_import_field_group( $data );
			return is_array( $saved ) ? $saved : false;
		}
		$saved = acf_import_internal_post_type( $data, $kind );
		if ( ! is_array( $saved ) ) {
			return false;
		}
		if ( 'acf-post-type' === $kind && function_exists( 'acf_get_instance' ) && method_exists( 'ACF_Post_Type', 'register_post_type' ) && ! empty( $saved['active'] ) ) {
			acf_get_instance( 'ACF_Post_Type' )->register_post_type( $saved );
		} elseif ( 'acf-taxonomy' === $kind && function_exists( 'acf_get_instance' ) && method_exists( 'ACF_Taxonomy', 'register_taxonomy' ) && ! empty( $saved['active'] ) ) {
			acf_get_instance( 'ACF_Taxonomy' )->register_taxonomy( $saved );
		}
		return $saved;
	}

	/**
	 * The definition on this site with a key.
	 *
	 * @param string $kind One of KINDS.
	 * @param string $key  ACF key.
	 * @return array|false
	 */
	protected static function existing( $kind, $key ) {
		$def = 'acf-field-group' === $kind ? acf_get_field_group( $key ) : ( function_exists( 'acf_get_internal_post_type' ) ? acf_get_internal_post_type( $key, $kind ) : false );
		return is_array( $def ) && ! empty( $def['ID'] ) ? $def : false;
	}

	/**
	 * Whether this site defines a key in code or acf-json rather than the database.
	 *
	 * @param string $kind One of KINDS.
	 * @param string $key  ACF key.
	 * @return bool
	 */
	protected static function is_local( $kind, $key ) {
		if ( 'acf-field-group' === $kind ) {
			return function_exists( 'acf_is_local_field_group' ) && acf_is_local_field_group( $key );
		}
		return function_exists( 'acf_is_local_internal_post_type' ) && acf_is_local_internal_post_type( $key, $kind );
	}

	/**
	 * A definition in its exported shape.
	 *
	 * @param string $kind One of KINDS.
	 * @param array  $def  Definition with its ID.
	 * @return array
	 */
	protected static function prepare( $kind, array $def ) {
		if ( 'acf-field-group' === $kind ) {
			$def['fields'] = acf_get_fields( $def );
			return (array) acf_prepare_field_group_for_export( $def );
		}
		return function_exists( 'acf_prepare_internal_post_type_for_export' ) ? (array) acf_prepare_internal_post_type_for_export( $def, $kind ) : $def;
	}

	/**
	 * Put back the definitions an import changed, from the copies in the job.
	 *
	 * @param MigratePlus_Job $job Job (side_prev cleared in place).
	 */
	public static function restore( MigratePlus_Job $job ) {
		if ( empty( $job->data['side_prev']['acf_definitions'] ) || ! self::active() ) {
			return;
		}
		foreach ( (array) $job->data['side_prev']['acf_definitions'] as $prev ) {
			if ( is_array( $prev ) && ! empty( $prev['data'] ) && is_array( $prev['data'] ) ) {
				self::save( $prev['kind'], $prev['data'] );
			}
		}
		unset( $job->data['side_prev']['acf_definitions'] );
	}

	/**
	 * Delete a definition an import created, through ACF so its fields go too.
	 *
	 * @param int $post_id Post id of an ACF internal post type.
	 * @return bool
	 */
	public static function delete( $post_id ) {
		$type = get_post_type( $post_id );
		if ( ! self::active() || ! in_array( $type, self::KINDS, true ) ) {
			return false;
		}
		if ( 'acf-field-group' === $type ) {
			return (bool) acf_delete_field_group( $post_id );
		}
		return function_exists( 'acf_delete_internal_post_type' ) && acf_delete_internal_post_type( $post_id, $type );
	}

	/**
	 * @param string $kind One of KINDS.
	 * @return string
	 */
	protected static function kind_label( $kind ) {
		$labels = array(
			'acf-field-group'     => __( 'field group', 'migrateplus' ),
			'acf-post-type'       => __( 'post type', 'migrateplus' ),
			'acf-taxonomy'        => __( 'taxonomy', 'migrateplus' ),
			'acf-ui-options-page' => __( 'options page', 'migrateplus' ),
		);
		return isset( $labels[ $kind ] ) ? $labels[ $kind ] : $kind;
	}

	/**
	 * Whether the person may manage ACF (its own capability setting, usually
	 * manage_options). WP-CLI without --user runs with the shell account's access.
	 *
	 * @return bool
	 */
	protected static function user_can() {
		return ! get_current_user_id() || current_user_can( function_exists( 'acf_get_setting' ) ? acf_get_setting( 'capability' ) : 'manage_options' );
	}

	/**
	 * @param string $action  created|updated|skipped|failed.
	 * @param int    $post_id Post id.
	 * @param string $message Message.
	 * @param bool   $warn    Warning.
	 * @return array
	 */
	protected static function result( $action, $post_id, $message, $warn = false ) {
		return array(
			'action'  => $action,
			'post_id' => (int) $post_id,
			'message' => $message,
			'warning' => $warn,
			'section' => 'acf_definitions',
		);
	}
}
