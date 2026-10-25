<?php
/**
 * Users in MigratePlus exports.
 *
 * @package MigratePlus
 */

defined( 'ABSPATH' ) || exit;

/**
 * Exports the site's users with their profile, roles, custom fields (WooCommerce
 * addresses among them) and ACF fields, and creates them before the records of
 * an import, so posts land with their authors and orders with their customers.
 *
 * A user is matched by the uid they were imported with, then by email, then by
 * login. One already here is updated (profile and fields), but keeps their
 * roles, login, email and password: an import must not change who may do what.
 * A new user gets the roles the file names that exist here and that the person
 * importing may give. Their password hash travels only when the person exporting
 * may edit users, and is set on a new account only, so the same password works
 * on the new site. Users the import creates carry _migrateplus_job_id for
 * rollback.
 */
class MigratePlus_Users {

	/**
	 * Custom fields that belong to this install, not to the person.
	 *
	 * @var string[] Regular expressions.
	 */
	const SKIP_META = array(
		'/^_/',
		'/^session_tokens$/',
		'/^(closedpostboxes|metaboxhidden|meta-box-order|screen_layout|edit_.*_per_page|manage.*columnshidden|nav_menu_recently_edited|default_password_nag|community-events-location|dismissed_wp_pointers|show_welcome_panel|wp_persisted_preferences|wc_last_active|last_update|_order_count|_money_spent|wc_money_spent|wc_order_count)/',
	);

	/**
	 * Every user as portable data, oldest first.
	 *
	 * @param array $args Export args (exclude_meta, include_acf).
	 * @return array[]
	 */
	public static function export( array $args ) {
		if ( ! self::user_can( 'list_users' ) ) {
			return array();
		}
		$out   = array();
		$query = new WP_User_Query(
			array(
				'orderby' => 'ID',
				'order'   => 'ASC',
				'number'  => -1,
				'fields'  => 'all',
			)
		);
		foreach ( $query->get_results() as $user ) {
			$out[] = self::export_user( $user, $args );
		}
		return $out;
	}

	/**
	 * How many users an export would carry.
	 *
	 * @param array $args Export args.
	 * @return int
	 */
	public static function count( array $args ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- every side section counts with the export args.
		if ( ! self::user_can( 'list_users' ) ) {
			return 0;
		}
		$counts = count_users();
		return (int) $counts['total_users'];
	}

	/**
	 * One user as a portable record.
	 *
	 * @param WP_User $user User.
	 * @param array   $args Export args.
	 * @return array
	 */
	protected static function export_user( WP_User $user, array $args ) {
		$item = array(
			'id'           => (int) $user->ID,
			'login'        => $user->user_login,
			'email'        => $user->user_email,
			'display_name' => $user->display_name,
			'first_name'   => $user->first_name,
			'last_name'    => $user->last_name,
			'nickname'     => $user->nickname,
			'url'          => $user->user_url,
			'description'  => $user->description,
			'registered'   => gmdate( 'c', strtotime( $user->user_registered . ' UTC' ) ),
			'roles'        => array_values( (array) $user->roles ),
			'locale'       => (string) get_user_meta( $user->ID, 'locale', true ),
			'meta'         => array(),
			'acf'          => array(),
		);
		if ( self::user_can( 'edit_users' ) ) {
			$item['pass'] = $user->user_pass; // The hash. Only for those who may edit users, as the file then opens accounts.
		}

		$acf_names = array();
		if ( ! empty( $args['include_acf'] ) && MigratePlus_ACF::active() ) {
			foreach ( MigratePlus_ACF::fields_for_user() as $f ) {
				$field = function_exists( 'acf_get_field' ) ? acf_get_field( $f['key'] ) : null;
				if ( ! $field ) {
					continue;
				}
				$raw = get_field( $field['key'], 'user_' . $user->ID, false );
				if ( null === $raw || '' === $raw || ( false === $raw && 'true_false' !== $field['type'] ) ) {
					continue;
				}
				$item['acf'][ $field['name'] ] = MigratePlus_Exporter::export_acf_value( $field, $raw );
				$acf_names[ $field['name'] ]   = true;
			}
		}

		$core = array( 'first_name', 'last_name', 'nickname', 'description', 'locale' );
		foreach ( get_user_meta( $user->ID ) as $key => $values ) {
			if ( in_array( $key, $core, true ) || isset( $acf_names[ $key ] ) || self::skip_meta( $key ) ) {
				continue;
			}
			if ( ! empty( $args['exclude_meta'] ) && MigratePlus_Exporter::meta_excluded( $key, $args['exclude_meta'] ) ) {
				continue;
			}
			if ( MigratePlus_ACF::active() && 0 === strpos( (string) get_user_meta( $user->ID, '_' . $key, true ), 'field_' ) ) {
				continue; // Sub-field rows of an ACF repeater/group on the user.
			}
			$rows                 = array_map( 'maybe_unserialize', $values );
			$item['meta'][ $key ] = count( $rows ) > 1 ? array( '_migrateplus_rows' => $rows ) : $rows[0];
		}

		return apply_filters( 'migrateplus_export_user', $item, $user, $args );
	}

	/**
	 * Whether a user meta key is this install's bookkeeping (capabilities, screen
	 * settings, sessions) rather than data about the person.
	 *
	 * @param string $key Meta key.
	 * @return bool
	 */
	protected static function skip_meta( $key ) {
		global $wpdb;
		if ( 0 === strpos( $key, $wpdb->prefix ) || 0 === strpos( $key, $wpdb->base_prefix ) ) {
			return true; // wp_capabilities, wp_user_level, wp_user-settings, wp_dashboard_quick_press_last_post_id...
		}
		foreach ( self::SKIP_META as $pattern ) {
			if ( preg_match( $pattern, $key ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Whether a job still has users to import. They come before its records.
	 *
	 * @param MigratePlus_Job $job Job.
	 * @return bool
	 */
	public static function pending( MigratePlus_Job $job ) {
		return ! empty( $job->data['settings']['users_file'] ) && ! empty( $job->data['options']['import_users'] );
	}

	/**
	 * The users a job's file carries, cached by the JSON source.
	 *
	 * @param MigratePlus_Job $job Job.
	 * @return array[]|WP_Error
	 */
	protected static function load( MigratePlus_Job $job ) {
		$file = isset( $job->data['settings']['users_file'] ) ? (string) $job->data['settings']['users_file'] : '';
		$data = '' !== $file && is_readable( $file ) ? json_decode( (string) file_get_contents( $file ), true ) : null; // phpcs:ignore WordPress.WP.AlternativeFunctions
		return is_array( $data ) ? $data : new WP_Error( 'migrateplus_users_cache', __( 'The cached users are missing. Start a new import.', 'migrateplus' ) );
	}

	/**
	 * Import the next batch of the job's users, resuming at stage_pos.
	 *
	 * @param MigratePlus_Job $job Job (stage_pos and created_total are updated in place).
	 * @return array { done: bool, results: array[] }
	 */
	public static function import_batch( MigratePlus_Job $job ) {
		$users = self::load( $job );
		if ( is_wp_error( $users ) ) {
			return array(
				'done'    => true,
				'results' => array( self::result( 'failed', 0, $users->get_error_message() ) ),
			);
		}
		$opts    = array_merge( MigratePlus_Job::default_options(), (array) $job->data['options'] );
		$limit   = max( 1, min( 100, (int) $opts['batch_size'] ) );
		$pos     = (int) $job->data['stage_pos'];
		$results = array();
		foreach ( array_slice( $users, $pos, $limit ) as $record ) {
			if ( is_array( $record ) ) {
				$results[] = self::import_user( $record, $job, $opts );
			}
			++$pos;
		}
		$job->data['stage_pos'] = $pos;
		return array(
			'done'    => $pos >= count( $users ),
			'results' => $results,
		);
	}

	/**
	 * Create or update one user from their exported record.
	 *
	 * @param array           $record User as exported.
	 * @param MigratePlus_Job $job    Job.
	 * @param array           $opts   Job options.
	 * @return array Result.
	 */
	protected static function import_user( array $record, MigratePlus_Job $job, array $opts ) {
		$dry   = ! empty( $opts['dry_run'] );
		$cli   = defined( 'WP_CLI' ) && WP_CLI && ! get_current_user_id();
		$read  = static function ( $key ) use ( $record ) {
			return isset( $record[ $key ] ) ? trim( MigratePlus_Util::stringify( $record[ $key ] ) ) : '';
		};
		$login = sanitize_user( $read( 'login' ), true );
		$email = sanitize_email( $read( 'email' ) );
		$name  = $read( 'display_name' );
		$label = '' !== $name ? $name : ( '' !== $login ? $login : $email );
		if ( '' === $login && '' === $email ) {
			return self::result( 'failed', 0, __( 'User without a login or email was not imported.', 'migrateplus' ) );
		}

		$uid      = self::uid( $job, $record );
		$existing = self::find_user( $uid, $email, $login );
		if ( ! $cli && ! current_user_can( $existing ? 'edit_users' : 'create_users' ) ) {
			/* translators: %s: user name */
			return self::result( 'skipped', 0, sprintf( __( 'User "%1$s" was not imported: you are not allowed to %2$s users.', 'migrateplus' ), $label, $existing ? __( 'edit', 'migrateplus' ) : __( 'create', 'migrateplus' ) ), true );
		}
		$warnings = array();
		$roles    = self::roles_for( isset( $record['roles'] ) ? (array) $record['roles'] : array(), $cli, $warnings, $label );

		if ( $dry ) {
			/* translators: 1: Would create/Would update, 2: user name */
			return self::result( $existing ? 'updated' : 'created', $existing, self::with_warnings( sprintf( __( '%1$s user "%2$s".', 'migrateplus' ), $existing ? __( 'Would update', 'migrateplus' ) : __( 'Would create', 'migrateplus' ), $label ), $warnings ), ! empty( $warnings ) );
		}

		$profile = array(
			'first_name'   => $read( 'first_name' ),
			'last_name'    => $read( 'last_name' ),
			'nickname'     => '' !== $read( 'nickname' ) ? $read( 'nickname' ) : $label,
			'display_name' => $label,
			'user_url'     => esc_url_raw( $read( 'url' ) ),
			'description'  => $read( 'description' ),
		);
		$created = false;
		if ( $existing ) {
			$saved = wp_update_user( wp_slash( array( 'ID' => $existing ) + $profile ) );
			if ( is_wp_error( $saved ) ) {
				/* translators: 1: user name, 2: error */
				return self::result( 'failed', 0, sprintf( __( 'User "%1$s" could not be updated: %2$s', 'migrateplus' ), $label, $saved->get_error_message() ) );
			}
			$user_id = $existing;
		} else {
			$base = '' !== $login ? $login : ( is_email( $email ) ? sanitize_user( strstr( $email, '@', true ), true ) : sanitize_title( $name ) );
			$base = '' !== $base ? $base : 'user';
			$try  = $base;
			$i    = 2;
			while ( username_exists( $try ) ) {
				$try = $base . ( $i++ );
			}
			if ( $try !== $login && '' !== $login ) {
				/* translators: 1: login on the old site, 2: login here */
				$warnings[] = sprintf( __( 'The login "%1$s" is taken, so they are "%2$s" here.', 'migrateplus' ), $login, $try );
			}
			$data       = $profile + array(
				'user_login' => $try,
				'user_pass'  => wp_generate_password( 24 ),
				'user_email' => is_email( $email ) ? $email : '',
				'role'       => $roles ? array_shift( $roles ) : get_option( 'default_role', 'subscriber' ),
			);
			$registered = MigratePlus_Importer::parse_date( $read( 'registered' ) );
			if ( $registered ) {
				$data['user_registered'] = $registered->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' );
			}
			if ( '' !== $read( 'locale' ) ) {
				$data['locale'] = sanitize_text_field( $read( 'locale' ) );
			}
			$user_id = wp_insert_user( wp_slash( $data ) );
			if ( is_wp_error( $user_id ) ) {
				/* translators: 1: user name, 2: error */
				return self::result( 'failed', 0, sprintf( __( 'User "%1$s" could not be created: %2$s', 'migrateplus' ), $label, $user_id->get_error_message() ) );
			}
			$created = true;
			$user    = new WP_User( $user_id );
			foreach ( $roles as $role ) {
				$user->add_role( $role );
			}
			self::set_password_hash( $user_id, $read( 'pass' ), $cli );
			update_user_meta( $user_id, '_migrateplus_job_id', $job->data['id'] );
			++$job->data['created_total'];
		}
		if ( '' !== $uid && ! get_user_meta( $user_id, '_migrateplus_source_uid', true ) ) {
			update_user_meta( $user_id, '_migrateplus_source_uid', $uid );
		}

		self::import_meta( $user_id, isset( $record['meta'] ) && is_array( $record['meta'] ) ? $record['meta'] : array() );
		$acf = isset( $record['acf'] ) && is_array( $record['acf'] ) ? $record['acf'] : array();
		if ( $acf && MigratePlus_ACF::active() ) {
			MigratePlus_ACF::import_user_fields( $user_id, $acf, $job, $opts, $warnings );
		} elseif ( $acf ) {
			$warnings[] = __( 'The user carries ACF fields but Advanced Custom Fields is not active.', 'migrateplus' );
		}

		/* translators: 1: Created/Updated, 2: user name */
		$message = sprintf( __( '%1$s user "%2$s".', 'migrateplus' ), $created ? __( 'Created', 'migrateplus' ) : __( 'Updated', 'migrateplus' ), $label );
		return self::result( $created ? 'created' : 'updated', $user_id, self::with_warnings( $message, $warnings ), ! empty( $warnings ) );
	}

	/**
	 * The roles a new user gets: those the file names that exist here and that
	 * the person importing may give. Administrator only from an administrator.
	 *
	 * @param array    $roles    Roles on the old site.
	 * @param bool     $cli      WP-CLI without a user: everything is allowed.
	 * @param string[] $warnings Warnings (by reference).
	 * @param string   $label    User name, for the warnings.
	 * @return string[]
	 */
	protected static function roles_for( array $roles, $cli, array &$warnings, $label ) {
		$out = array();
		foreach ( $roles as $role ) {
			$role = sanitize_key( MigratePlus_Util::stringify( $role ) );
			if ( '' === $role ) {
				continue;
			}
			if ( ! wp_roles()->is_role( $role ) ) {
				/* translators: 1: role, 2: user name */
				$warnings[] = sprintf( __( 'Role "%1$s" does not exist on this site, so "%2$s" does not get it.', 'migrateplus' ), $role, $label );
				continue;
			}
			if ( ! $cli && ( ! current_user_can( 'promote_users' ) || ( 'administrator' === $role && ! current_user_can( 'administrator' ) ) ) ) { // phpcs:ignore WordPress.WP.Capabilities.RoleFound -- only an administrator may make one.
				/* translators: 1: role, 2: user name */
				$warnings[] = sprintf( __( 'You may not give the "%1$s" role, so "%2$s" does not get it.', 'migrateplus' ), $role, $label );
				continue;
			}
			$out[] = $role;
		}
		return array_values( array_unique( $out ) );
	}

	/**
	 * Give a new account the password it had on the old site, when the hash
	 * travelled and the person importing may edit users.
	 *
	 * @param int    $user_id User id.
	 * @param string $hash    Password hash from the export.
	 * @param bool   $cli     WP-CLI without a user.
	 */
	protected static function set_password_hash( $user_id, $hash, $cli ) {
		if ( '' === $hash || ! preg_match( '/^\$(P|H|2[axy]|argon2|wp)\$/', $hash ) || ( ! $cli && ! current_user_can( 'edit_users' ) ) ) {
			return;
		}
		global $wpdb;
		// wp_update_user() would hash the hash: written as it is, like WordPress reads it.
		$wpdb->update( $wpdb->users, array( 'user_pass' => $hash ), array( 'ID' => (int) $user_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		clean_user_cache( $user_id );
	}

	/**
	 * A user record's "imported before" key.
	 *
	 * @param MigratePlus_Job $job    Job.
	 * @param array           $record User record.
	 * @return string '' without an id.
	 */
	protected static function uid( MigratePlus_Job $job, array $record ) {
		return isset( $record['id'] ) ? MigratePlus_Importer::uid( $job, 'user:' . MigratePlus_Util::stringify( $record['id'] ) ) : '';
	}

	/**
	 * The user a record names on this site.
	 *
	 * @param string $uid   From uid().
	 * @param string $email Email.
	 * @param string $login Login.
	 * @return int 0 when they are not here.
	 */
	protected static function find_user( $uid, $email, $login ) {
		if ( '' !== $uid ) {
			$ids = get_users(
				array(
					'meta_key'   => '_migrateplus_source_uid', // phpcs:ignore WordPress.DB.SlowDBQuery
					'meta_value' => $uid, // phpcs:ignore WordPress.DB.SlowDBQuery
					'number'     => 1,
					'fields'     => 'ID',
				)
			);
			if ( $ids ) {
				return (int) $ids[0];
			}
		}
		$user = is_email( $email ) ? get_user_by( 'email', $email ) : false;
		if ( ! $user && '' !== $login ) {
			$user = get_user_by( 'login', $login );
		}
		return $user ? (int) $user->ID : 0;
	}

	/**
	 * Replace a user's custom fields with the record's.
	 *
	 * @param int   $user_id User id.
	 * @param array $meta    Exported meta.
	 */
	protected static function import_meta( $user_id, array $meta ) {
		foreach ( $meta as $key => $value ) {
			$key = sanitize_text_field( MigratePlus_Util::stringify( $key ) );
			if ( '' === $key || self::skip_meta( $key ) ) {
				continue;
			}
			$rows = is_array( $value ) && isset( $value['_migrateplus_rows'] ) && is_array( $value['_migrateplus_rows'] ) ? $value['_migrateplus_rows'] : array( $value );
			delete_user_meta( $user_id, $key );
			foreach ( $rows as $one ) {
				add_user_meta( $user_id, $key, wp_slash( $one ) );
			}
		}
	}

	/**
	 * IDs of the users a job created, for rollback.
	 *
	 * @param string $job_id Job id.
	 * @return int[]
	 */
	public static function imported( $job_id ) {
		$ids = get_users(
			array(
				'meta_key'   => '_migrateplus_job_id', // phpcs:ignore WordPress.DB.SlowDBQuery
				'meta_value' => (string) $job_id, // phpcs:ignore WordPress.DB.SlowDBQuery
				'number'     => -1,
				'fields'     => 'ID',
			)
		);
		return array_map( 'intval', (array) $ids );
	}

	/**
	 * Delete a user the import created. Content they own is handed to the person
	 * rolling back, so a post added by hand since is not lost with them.
	 *
	 * @param int $user_id User id.
	 * @return bool
	 */
	public static function delete( $user_id ) {
		if ( get_current_user_id() === (int) $user_id ) {
			return false;
		}
		if ( ! function_exists( 'wp_delete_user' ) ) {
			require_once ABSPATH . 'wp-admin/includes/user.php';
		}
		$reassign = get_current_user_id() ? get_current_user_id() : null;
		if ( is_multisite() ) {
			if ( ! function_exists( 'wpmu_delete_user' ) ) {
				require_once ABSPATH . 'wp-admin/includes/ms.php';
			}
			return (bool) wpmu_delete_user( $user_id );
		}
		return (bool) wp_delete_user( $user_id, $reassign );
	}

	/**
	 * @param string   $message  Message.
	 * @param string[] $warnings Warnings.
	 * @return string
	 */
	protected static function with_warnings( $message, array $warnings ) {
		return $warnings ? $message . ' ' . __( 'Warnings:', 'migrateplus' ) . ' ' . implode( ' ', array_unique( $warnings ) ) : $message;
	}

	/**
	 * Whether the person exporting may do something; WP-CLI without --user runs
	 * with the shell account's access.
	 *
	 * @param string $capability Capability.
	 * @return bool
	 */
	protected static function user_can( $capability ) {
		return ! get_current_user_id() || current_user_can( $capability );
	}

	/**
	 * @param string $action  created|updated|skipped|failed.
	 * @param int    $user_id User id.
	 * @param string $message Message.
	 * @param bool   $warn    Warning.
	 * @return array
	 */
	protected static function result( $action, $user_id, $message, $warn = false ) {
		return array(
			'action'  => $action,
			'post_id' => (int) $user_id,
			'message' => $message,
			'warning' => $warn,
			'section' => 'users',
		);
	}
}
