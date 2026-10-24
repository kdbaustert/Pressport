<?php
/**
 * Find and replace across database tables, safe for serialized data.
 *
 * @package MigratePlus
 */

defined( 'ABSPATH' ) || exit;

class MigratePlus_Replace {

	const STATE_PREFIX = 'migrateplus_replace_';
	// Not under STATE_PREFIX: cleanup() deletes everything there after a day.
	const LOG_PREFIX = 'migrateplus_replacelog_';
	const LOG_INDEX  = 'migrateplus_replacelog_ids';
	const PAGE_SIZE  = 500;
	const SAMPLES    = 20; // Example changes kept per table.
	// A run whose last batch started longer ago than this has nobody driving it: it can be resumed or rolled back.
	const IDLE = 2 * MINUTE_IN_SECONDS;
	// Rollback files hold every original value a run changed, password hashes and secrets included, so they don't stay forever.
	const BACKUP_DAYS = 30;

	/**
	 * This site's tables, with approximate row counts and sizes.
	 *
	 * @return array[] { name, rows, size }
	 */
	public static function tables() {
		global $wpdb;
		$rows = (array) $wpdb->get_results( $wpdb->prepare( 'SHOW TABLE STATUS LIKE %s', $wpdb->esc_like( $wpdb->prefix ) . '%' ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$own  = array_flip( self::own_tables( wp_list_pluck( $rows, 'Name' ) ) );
		$out  = array();
		foreach ( $rows as $row ) {
			if ( ! isset( $own[ $row['Name'] ] ) ) {
				continue;
			}
			$out[] = array(
				'name' => $row['Name'],
				'rows' => (int) $row['Rows'],
				'size' => (int) $row['Data_length'] + (int) $row['Index_length'],
			);
		}
		return $out;
	}

	/**
	 * Which of the tables starting with this site's prefix belong to this site.
	 * LIKE 'wp_%' also matches the network's tables on multisite's main site
	 * (wp_users, wp_sitemeta, every wp_2_*), and on any install the tables of
	 * another one sharing the database under a longer prefix (wp_staging_*):
	 * editing either would reach past this site, e.g. to make someone super admin.
	 *
	 * Kept: WordPress's tables for this site (plus users and usermeta on a single
	 * site, which owns them), and any other table whose name is the prefix and
	 * then a name that is neither another network site's number (wp_2_…) nor
	 * inside the prefix of another install. Another install is recognised by its
	 * own options and posts tables: {prefix}{x}_options next to {prefix}{x}_posts
	 * means {prefix}{x}_ is a WordPress prefix of its own.
	 *
	 * @param string[] $names Table names starting with $wpdb->prefix.
	 * @return string[]
	 */
	protected static function own_tables( array $names ) {
		global $wpdb;
		$core    = $wpdb->tables( 'blog' );
		$network = $wpdb->tables( 'global' );
		if ( ! is_multisite() ) {
			$core    = array_merge( $core, $network );
			$network = array();
		}
		$others = array();
		foreach ( $names as $name ) {
			if ( preg_match( '/^(.+_)options$/', $name, $m ) && $m[1] !== $wpdb->prefix && in_array( $m[1] . 'posts', $names, true ) ) {
				$others[] = $m[1];
			}
		}
		$out = array();
		foreach ( $names as $name ) {
			if ( 0 !== strpos( $name, $wpdb->prefix ) || in_array( $name, $network, true ) ) {
				continue;
			}
			if ( ! in_array( $name, $core, true ) ) {
				// Another site of a network (wp_2_posts). On a single site only a numbered copy of a
				// core table counts, left from a network, so a plugin's wp_2024_stats stays in.
				if ( preg_match( '/^\d+_(.+)$/', substr( $name, strlen( $wpdb->prefix ) ), $m ) && ( is_multisite() || in_array( $m[1], $wpdb->tables( 'blog', false ), true ) ) ) {
					continue;
				}
				foreach ( $others as $other ) {
					if ( 0 === strpos( $name, $other ) ) {
						continue 2;
					}
				}
			}
			$out[] = $name;
		}
		return $out;
	}

	/**
	 * Start a run.
	 *
	 * @param array $raw { search, replace, tables[], case_insensitive, guids, dry_run }.
	 * @return array|WP_Error State.
	 */
	public static function start( array $raw ) {
		$search  = isset( $raw['search'] ) ? (string) $raw['search'] : '';
		$replace = isset( $raw['replace'] ) ? (string) $raw['replace'] : '';
		if ( '' === $search ) {
			return new WP_Error( 'migrateplus_replace_search', __( 'Enter the text to search for.', 'migrateplus' ) );
		}
		if ( $search === $replace ) {
			return new WP_Error( 'migrateplus_replace_same', __( 'The replacement is the same as the search text.', 'migrateplus' ) );
		}
		$all    = wp_list_pluck( self::tables(), 'rows', 'name' );
		$tables = array_values( array_intersect( array_keys( $all ), isset( $raw['tables'] ) ? (array) $raw['tables'] : array() ) );
		if ( ! $tables ) {
			return new WP_Error( 'migrateplus_replace_tables', __( 'Choose at least one table.', 'migrateplus' ) );
		}

		$state = array(
			'id'       => strtolower( wp_generate_password( 12, false ) ),
			'user'     => get_current_user_id(),
			'created'  => time(),
			'args'     => array(
				'search'           => $search,
				'replace'          => $replace,
				'case_insensitive' => empty( $raw['case_insensitive'] ) ? 0 : 1,
				'whole_urls'       => ! isset( $raw['whole_urls'] ) || ! empty( $raw['whole_urls'] ) ? 1 : 0,
				'guids'            => empty( $raw['guids'] ) ? 0 : 1,
				'dry_run'          => empty( $raw['dry_run'] ) ? 0 : 1,
			),
			'tables'   => $tables,
			'index'    => 0,
			'last'     => array(), // Key of the last row read in the current table.
			'total'    => array_sum( array_intersect_key( $all, array_flip( $tables ) ) ),
			'done'     => 0,
			'failed'   => 0, // Rows whose update the database refused.
			'results'  => array(),
			'complete' => false,
			'backup'   => '',
		);
		if ( ! $state['args']['dry_run'] ) {
			// Original values of every changed cell go here, so the run can be rolled back.
			$state['backup'] = MigratePlus_Util::ensure_storage_dir() . 'replace-' . $state['id'] . '-' . wp_generate_password( 16, false ) . '.jsonl';
			if ( false === file_put_contents( $state['backup'], '' ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions
				return new WP_Error( 'migrateplus_replace_backup', __( 'Could not write the rollback file to the uploads folder, so nothing was replaced.', 'migrateplus' ) );
			}
			self::log_start( $state );
		}
		self::save_state( $state );
		return $state;
	}

	/**
	 * @param string $id Run id.
	 * @return array|null
	 */
	public static function load_state( $id ) {
		$state = get_option( self::STATE_PREFIX . sanitize_key( $id ) );
		return is_array( $state ) ? $state : null;
	}

	/**
	 * @param array $state State.
	 */
	protected static function save_state( array $state ) {
		update_option( self::STATE_PREFIX . $state['id'], $state, false );
	}

	/**
	 * Work through rows until the time budget runs out or every table is done.
	 *
	 * @param array $state   State (updated in place).
	 * @param int   $seconds Time budget.
	 * @return array State.
	 */
	public static function run_batch( array &$state, $seconds = 10 ) {
		global $wpdb;
		$until = microtime( true ) + $seconds;
		$args  = $state['args'];
		$match = self::matcher( $args['search'], $args['replace'], $args['case_insensitive'], $args['whole_urls'] );
		$undo  = '';
		// Heartbeat: while a batch is running, the run counts as in progress (see idle()).
		$state['updated'] = time();
		self::save_state( $state );

		while ( ! $state['complete'] && microtime( true ) < $until ) {
			$table = $state['tables'][ $state['index'] ];
			if ( ! isset( $state['results'][ $table ] ) ) {
				$state['results'][ $table ] = self::table_result( $table );
			}
			$result = &$state['results'][ $table ];

			if ( '' !== $result['skipped'] || $result['empty'] ) {
				self::next_table( $state );
				continue;
			}

			$quoted = '`' . str_replace( '`', '``', $table ) . '`';
			$order  = implode( ', ', array_map( array( __CLASS__, 'quote_column' ), $result['pk'] ) );
			$select = implode( ', ', array_map( array( __CLASS__, 'quote_column' ), array_unique( array_merge( $result['pk'], $result['columns'] ) ) ) );
			// Page by key rather than offset, so later pages don't rescan every row before them.
			$where  = '';
			$params = array();
			if ( ! empty( $state['last'] ) ) {
				$where  = " WHERE ({$order}) > (" . implode( ', ', array_fill( 0, count( $state['last'] ), '%s' ) ) . ')';
				$params = array_values( $state['last'] );
			}
			$params[] = self::PAGE_SIZE;
			$rows     = $wpdb->get_results( $wpdb->prepare( "SELECT {$select} FROM {$quoted}{$where} ORDER BY {$order} LIMIT %d", $params ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- one %s per key column, built above.
			if ( ! is_array( $rows ) || $wpdb->last_error ) {
				// A failed query returns no rows: don't mistake it for the end of the table.
				/* translators: %s: database error */
				$state['error']    = sprintf( __( 'Could not read the table (%s), so the run stopped.', 'migrateplus' ), $wpdb->last_error );
				$state['complete'] = true;
				break;
			}

			foreach ( $rows as $row ) {
				if ( self::protected_row( $table, $row ) ) {
					continue;
				}
				$changes = array();
				$made    = 0;
				foreach ( $result['columns'] as $column ) {
					if ( null === $row[ $column ] || ( 'guid' === $column && $table === $wpdb->posts && ! $args['guids'] ) ) {
						continue;
					}
					$count = 0;
					$new   = self::replace_value( $row[ $column ], $match, $count, $result['unsafe'] );
					if ( ! $count ) {
						continue;
					}
					$changes[ $column ] = $new;
					$made              += $count;
					if ( count( $result['samples'] ) < self::SAMPLES ) {
						$result['samples'][] = self::sample( $row, $result['pk'], $column, $match, $new );
					}
				}
				if ( ! $changes ) {
					continue;
				}
				if ( ! $args['dry_run'] ) {
					$key = array_intersect_key( $row, array_flip( $result['pk'] ) );
					// Written before the update: a row is never changed without a way back.
					$line = self::undo_line( $table, $key, array_intersect_key( $row, $changes ), $changes );
					clearstatcache( true, $state['backup'] );
					$undo_at = file_exists( $state['backup'] ) ? filesize( $state['backup'] ) : 0;
					// A key wp_json_encode can't write (invalid UTF-8) leaves a blank line, so the row would have no way back.
					if ( "\n" === $line || false === file_put_contents( $state['backup'], $line, FILE_APPEND ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions
						$state['error']    = __( 'Could not write to the rollback file, so the run stopped.', 'migrateplus' );
						$state['complete'] = true;
						break 2;
					}
					if ( false === $wpdb->update( $table, $changes, $key ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery
						++$state['failed'];
						// The row was never changed: drop its undo line, or rollback reports it as edited since.
						$fh = fopen( $state['backup'], 'r+' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
						if ( $fh ) {
							ftruncate( $fh, $undo_at );
							fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions
						}
						if ( $wpdb->last_error ) {
							/* translators: %s: database error */
							$state['error']    = sprintf( __( 'The database refused an update (%s), so the run stopped.', 'migrateplus' ), $wpdb->last_error );
							$state['complete'] = true;
							break 2;
						}
						continue;
					}
				}
				++$result['rows'];
				$result['changes'] += $made;
			}
			unset( $result );

			$count          = count( $rows );
			$state['done'] += $count;
			if ( $count ) {
				$state['last'] = array_intersect_key( end( $rows ), array_flip( $state['results'][ $table ]['pk'] ) );
			}
			if ( $count < self::PAGE_SIZE ) {
				self::next_table( $state );
			}
		}

		unset( $result );
		if ( $state['complete'] && ! empty( $state['failed'] ) && empty( $state['error'] ) ) {
			/* translators: %d: number of rows */
			$state['error'] = sprintf( _n( '%d row could not be updated.', '%d rows could not be updated.', $state['failed'], 'migrateplus' ), $state['failed'] );
		}
		if ( ! $args['dry_run'] ) {
			self::log_progress( $state );
			if ( $state['complete'] ) {
				wp_cache_flush(); // Options and posts may be cached with the old text.
			}
		}
		self::save_state( $state );
		return $state;
	}

	/**
	 * One rollback record: the row's key, its old values, and a hash of each new
	 * value so rollback can tell whether the cell was edited since.
	 *
	 * @param string $table Table.
	 * @param array  $key   Primary key values.
	 * @param array  $old   Column => old value.
	 * @param array  $new   Column => new value.
	 * @return string JSON line.
	 */
	protected static function undo_line( $table, array $key, array $old, array $new ) {
		return wp_json_encode(
			array(
				't' => $table,
				'k' => $key,
				'o' => array_map( 'base64_encode', $old ), // Binary-safe.
				'h' => array_map( 'md5', $new ),
			)
		) . "\n";
	}

	// History of real runs, and rollback.

	/**
	 * @param array $state State, just started.
	 */
	protected static function log_start( array $state ) {
		$user  = get_userdata( (int) $state['user'] );
		$entry = array(
			'id'          => $state['id'],
			'created'     => $state['created'],
			'user'        => $user ? $user->display_name : '',
			'search'      => $state['args']['search'],
			'replace'     => $state['args']['replace'],
			'tables'      => count( $state['tables'] ),
			'rows'        => 0,
			'changes'     => 0,
			'status'      => 'running',
			'backup'      => $state['backup'],
			'undo_offset' => 0,
			'restored'    => 0,
			'kept'        => 0,
			'failed'      => 0, // Values the database refused to restore.
		);
		update_option( self::LOG_PREFIX . $entry['id'], $entry, false );
		$ids   = (array) get_option( self::LOG_INDEX, array() );
		$ids[] = $entry['id'];
		update_option( self::LOG_INDEX, array_values( array_unique( $ids ) ), false );
	}

	/**
	 * @param array $state State.
	 */
	protected static function log_progress( array $state ) {
		$entry = self::history_entry( $state['id'] );
		if ( ! $entry ) {
			return;
		}
		$entry['rows']    = array_sum( wp_list_pluck( $state['results'], 'rows' ) );
		$entry['changes'] = array_sum( wp_list_pluck( $state['results'], 'changes' ) );
		if ( $state['complete'] ) {
			$entry['status'] = empty( $state['error'] ) ? 'complete' : 'failed';
		}
		update_option( self::LOG_PREFIX . $entry['id'], $entry, false );
	}

	/**
	 * @param string $id Run id.
	 * @return array|null
	 */
	public static function history_entry( $id ) {
		$entry = get_option( self::LOG_PREFIX . sanitize_key( $id ) );
		return is_array( $entry ) ? $entry : null;
	}

	/**
	 * Real runs, newest first.
	 *
	 * @return array[]
	 */
	public static function history() {
		$out = array();
		foreach ( (array) get_option( self::LOG_INDEX, array() ) as $id ) {
			$entry = self::history_entry( $id );
			if ( ! $entry ) {
				continue;
			}
			// A run whose state is gone before it finished was abandoned part way; one nobody is driving has stopped.
			$state = 'running' === $entry['status'] ? self::load_state( $id ) : null;
			if ( 'running' === $entry['status'] && ( ! $state || self::idle( $state ) ) ) {
				$entry['status'] = 'stopped';
			}
			$entry['can_resume'] = $state && empty( $state['complete'] ) && 'stopped' === $entry['status'];
			$undoable            = in_array( $entry['status'], array( 'complete', 'stopped', 'failed', 'rolling_back', 'rollback_failed' ), true ) && $entry['rows'];
			$entry['can_undo']   = $undoable && is_readable( $entry['backup'] );
			// Without this the row just loses its button, and nothing says why.
			$entry['undo_note'] = $undoable && ! $entry['can_undo'] ? sprintf(
				/* translators: %d: number of days */
				__( 'Can no longer be rolled back: its rollback file is gone. Rollback files are deleted after %d days.', 'migrateplus' ),
				self::BACKUP_DAYS
			) : '';
			unset( $entry['backup'] );
			$out[] = $entry;
		}
		usort(
			$out,
			static function ( $a, $b ) {
				return $b['created'] - $a['created'];
			}
		);
		return $out;
	}

	/**
	 * Whether no batch of a run has started for a while (the tab was closed, or the request died).
	 *
	 * @param array $state State.
	 * @return bool
	 */
	protected static function idle( array $state ) {
		return max( (int) $state['created'], isset( $state['updated'] ) ? (int) $state['updated'] : 0 ) < time() - self::IDLE;
	}

	/**
	 * Remove a run from history, with its rollback file.
	 *
	 * @param string $id Run id.
	 */
	public static function forget( $id ) {
		$id    = sanitize_key( $id );
		$entry = self::history_entry( $id );
		if ( $entry && $entry['backup'] && file_exists( $entry['backup'] ) ) {
			wp_delete_file( $entry['backup'] );
		}
		delete_option( self::LOG_PREFIX . $id );
		update_option( self::LOG_INDEX, array_values( array_diff( (array) get_option( self::LOG_INDEX, array() ), array( $id ) ) ), false );
	}

	/**
	 * Put original values back, a batch at a time. A cell is only restored when it
	 * still holds what the run wrote; anything edited since is kept as it is.
	 *
	 * @param string $id      Run id.
	 * @param int    $seconds Time budget.
	 * @return array|WP_Error Entry with progress: undo_offset, size, restored, kept, status.
	 */
	public static function rollback_batch( $id, $seconds = 10 ) {
		global $wpdb;
		$entry = self::history_entry( $id );
		if ( ! $entry || ! is_readable( $entry['backup'] ) ) {
			return new WP_Error( 'migrateplus_replace_undo', __( 'This replacement can no longer be rolled back.', 'migrateplus' ) );
		}
		$state = 'running' === $entry['status'] ? self::load_state( $id ) : null;
		if ( $state && ! self::idle( $state ) ) {
			return new WP_Error( 'migrateplus_replace_undo', __( 'This replacement is still running.', 'migrateplus' ) );
		}
		if ( $state ) {
			self::delete( $id ); // Stopped part way: once rolling back, it can't be resumed.
		}
		if ( 'rollback_failed' === $entry['status'] ) {
			// A retry reads the file again from the start: restored values are passed over, the rest recounted.
			$entry['kept']   = 0;
			$entry['failed'] = 0;
		}
		$entry['status'] = 'rolling_back';
		$until           = microtime( true ) + $seconds;
		$size            = filesize( $entry['backup'] );
		$handle          = fopen( $entry['backup'], 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		fseek( $handle, (int) $entry['undo_offset'] );
		while ( microtime( true ) < $until && false !== ( $line = fgets( $handle ) ) ) { // phpcs:ignore Generic.CodeAnalysis.AssignmentInCondition
			$entry['undo_offset'] = ftell( $handle );
			$rec                  = json_decode( $line, true );
			if ( ! is_array( $rec ) || empty( $rec['t'] ) || empty( $rec['k'] ) ) {
				continue;
			}
			$quoted = '`' . str_replace( '`', '``', $rec['t'] ) . '`';
			$where  = array();
			foreach ( array_keys( $rec['k'] ) as $col ) {
				$where[] = self::quote_column( $col ) . ' = %s';
			}
			$cols    = implode( ', ', array_map( array( __CLASS__, 'quote_column' ), array_keys( $rec['o'] ) ) );
			$current = $wpdb->get_row( $wpdb->prepare( "SELECT {$cols} FROM {$quoted} WHERE " . implode( ' AND ', $where ), array_values( $rec['k'] ) ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- one %s per key column, built above.
			if ( null === $current && $wpdb->last_error ) {
				// A failed read is not "the row is gone": count it, and keep the file so it can be retried.
				$entry['failed']  += count( $rec['o'] );
				$entry['db_error'] = $wpdb->last_error;
				continue;
			}
			$restore = array();
			foreach ( $rec['o'] as $col => $old ) {
				$old = base64_decode( $old ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions
				if ( $current && isset( $rec['h'][ $col ] ) && md5( (string) $current[ $col ] ) === $rec['h'][ $col ] ) {
					$restore[ $col ] = $old;
				} elseif ( ! $current || (string) $current[ $col ] !== $old ) {
					++$entry['kept']; // Edited since, or the row is gone. Already restored by an earlier attempt: not counted.
				}
			}
			if ( $restore ) {
				if ( false === $wpdb->update( $rec['t'], $restore, $rec['k'] ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery
					$entry['failed']  += count( $restore );
					$entry['db_error'] = $wpdb->last_error;
				} else {
					$entry['restored'] += count( $restore );
				}
			}
		}
		$done = false === $line || feof( $handle );
		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		if ( $done && $entry['failed'] ) {
			// Keep the file and start again from the top next time; values already restored are passed over.
			$entry['status']      = 'rollback_failed';
			$entry['undo_offset'] = 0;
			update_option( self::LOG_PREFIX . $entry['id'], $entry, false );
			wp_cache_flush();
			return new WP_Error(
				'migrateplus_replace_undo',
				sprintf(
					/* translators: 1: number of values, 2: database error */
					_n( '%1$d value could not be restored because the database refused it (%2$s). The rollback file is kept: roll back again to retry.', '%1$d values could not be restored because the database refused them (%2$s). The rollback file is kept: roll back again to retry.', $entry['failed'], 'migrateplus' ),
					$entry['failed'],
					$entry['db_error']
				)
			);
		}
		if ( $done ) {
			$entry['status'] = 'rolled_back';
			wp_cache_flush();
			wp_delete_file( $entry['backup'] );
		}
		update_option( self::LOG_PREFIX . $entry['id'], $entry, false );
		$entry['size'] = $size;
		unset( $entry['backup'] );
		return $entry;
	}

	/**
	 * @param array $state State.
	 */
	protected static function next_table( array &$state ) {
		++$state['index'];
		$state['last']     = array();
		$state['complete'] = $state['index'] >= count( $state['tables'] );
	}

	/**
	 * A table's primary key and text columns, with an empty tally.
	 *
	 * @param string $table Table.
	 * @return array
	 */
	protected static function table_result( $table ) {
		global $wpdb;
		$pk      = array();
		$columns = array();
		foreach ( (array) $wpdb->get_results( 'DESCRIBE `' . str_replace( '`', '``', $table ) . '`', ARRAY_A ) as $col ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
			if ( 'PRI' === $col['Key'] ) {
				$pk[] = $col['Field'];
			} elseif ( preg_match( '/char|text|blob|json/i', $col['Type'] ) ) {
				$columns[] = $col['Field'];
			}
		}
		return array(
			'pk'      => $pk,
			'columns' => $columns,
			'rows'    => 0,
			'changes' => 0,
			'samples' => array(),
			'unsafe'  => 0, // Values left as they are: they hold objects that cannot be rewritten safely.
			// A table with no text columns has nothing to replace; only a missing key is worth reporting.
			'skipped' => $pk || ! $columns ? '' : __( 'No primary key, so rows cannot be updated safely.', 'migrateplus' ),
			'empty'   => ! $columns,
		);
	}

	/**
	 * MigratePlus's own records stay as they are: its history and tracking data
	 * hold source URLs that must match the files they came from.
	 *
	 * @param string $table Table.
	 * @param array  $row   Row.
	 * @return bool
	 */
	protected static function protected_row( $table, array $row ) {
		global $wpdb;
		if ( $table === $wpdb->options && isset( $row['option_name'] ) ) {
			return 0 === strpos( $row['option_name'], 'migrateplus_' );
		}
		if ( $table === $wpdb->postmeta && isset( $row['meta_key'] ) ) {
			return 0 === strpos( $row['meta_key'], '_migrateplus_' );
		}
		return false;
	}

	/**
	 * How to match: plain text, or, for a web address or domain with "Whole URLs
	 * only" on, a pattern that stops http://old.test from matching inside
	 * http://old.test.example.com or http://old.testing.com.
	 *
	 * @param string $search           Search.
	 * @param string $replace          Replacement.
	 * @param bool   $case_insensitive Ignore case.
	 * @param bool   $whole_urls       Match whole URLs only.
	 * @return array
	 */
	public static function matcher( $search, $replace, $case_insensitive, $whole_urls = true ) {
		$pattern = null;
		if ( $whole_urls && preg_match( '~^(?:[a-z][a-z0-9+.-]*:)?//[^/\s]|^[a-z0-9-]+(?:\.[a-z0-9-]+)+(?:[/:?#]|$)~i', $search ) ) {
			// A bare domain must not be the tail of a longer host name (notold.test, sub.old.test).
			$before = preg_match( '~^[a-z0-9]~i', $search ) && ! preg_match( '~^[a-z][a-z0-9+.-]*:~i', $search ) ? '(?<![a-z0-9.-])' : '';
			// Nor may the match run on into more host or path characters (old.test.example.com, /blog → /blogging).
			$after   = preg_match( '~[a-z0-9_-]$~i', $search ) ? '(?![a-z0-9_-]|\.[a-z0-9])' : '';
			$pattern = '~' . $before . preg_quote( $search, '~' ) . $after . '~' . ( $case_insensitive ? 'i' : '' );
		}
		return array(
			'search'  => $search,
			'replace' => $replace,
			'ci'      => (bool) $case_insensitive,
			'pattern' => $pattern,
		);
	}

	/**
	 * Replace in plain text.
	 *
	 * @param string $text  Text.
	 * @param array  $match From matcher().
	 * @param int    $count Replacements made (added to).
	 * @return string
	 */
	protected static function replace_text( $text, array $match, &$count ) {
		$n = 0;
		if ( $match['pattern'] ) {
			$replace = $match['replace'];
			$out     = preg_replace_callback(
				$match['pattern'],
				static function () use ( $replace ) {
					return $replace;
				},
				$text,
				-1,
				$n
			);
		} else {
			$out = $match['ci'] ? str_ireplace( $match['search'], $match['replace'], $text, $n ) : str_replace( $match['search'], $match['replace'], $text, $n );
		}
		$count += $n;
		return null === $out ? $text : $out;
	}

	/**
	 * Replace inside a value, unserializing it first when it is serialized so
	 * string lengths stay correct. Objects other than stdClass are never created.
	 *
	 * @param mixed $value Value.
	 * @param array $match From matcher().
	 * @param int   $count  Replacements made (added to).
	 * @param int   $unsafe Values left unchanged because they hold custom-serialized objects (added to).
	 * @return mixed
	 */
	public static function replace_value( $value, array $match, &$count, &$unsafe = 0 ) {
		if ( is_string( $value ) ) {
			if ( false === ( $match['ci'] ? stripos( $value, $match['search'] ) : strpos( $value, $match['search'] ) ) ) {
				return $value;
			}
			if ( is_serialized( $value ) ) {
				// C: objects of classes left unloaded would come back empty: keep the whole value as it is.
				if ( preg_match( '/(?:^|[;{}])C:\d+:"/', $value ) ) {
					++$unsafe;
					return $value;
				}
				$data = @unserialize( $value, array( 'allowed_classes' => array( 'stdClass' ) ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.PHP.DiscouragedPHPFunctions
				if ( false === $data && 'b:0;' !== $value ) {
					return $value; // Already broken: a plain replace would only break it further.
				}
				$before = $count;
				$data   = self::replace_value( $data, $match, $count, $unsafe );
				return $count > $before ? serialize( $data ) : $value; // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions
			}
			return self::replace_text( $value, $match, $count );
		}
		if ( is_array( $value ) ) {
			foreach ( $value as $key => $item ) {
				$value[ $key ] = self::replace_value( $item, $match, $count, $unsafe );
			}
			return $value;
		}
		if ( $value instanceof stdClass ) {
			foreach ( get_object_vars( $value ) as $key => $item ) {
				$value->$key = self::replace_value( $item, $match, $count, $unsafe );
			}
		}
		return $value; // Numbers, booleans, and objects of classes left unloaded (kept byte for byte).
	}

	/**
	 * A short before/after excerpt around the first match.
	 *
	 * @param array    $row    Row.
	 * @param string[] $pk     Primary key columns.
	 * @param string   $column Column.
	 * @param array    $match  From matcher().
	 * @param string   $new    The value after replacing.
	 * @return array
	 */
	protected static function sample( array $row, array $pk, $column, array $match, $new ) {
		$old = (string) $row[ $column ];
		if ( $match['pattern'] && preg_match( $match['pattern'], $old, $m, PREG_OFFSET_CAPTURE ) ) {
			$at = $m[0][1];
		} else {
			$at = (int) ( $match['ci'] ? stripos( $old, $match['search'] ) : strpos( $old, $match['search'] ) );
		}
		$start = max( 0, $at - 40 );
		$text  = substr( $old, $start, strlen( $match['search'] ) + 80 );
		$n     = 0;
		$after = self::replace_text( $text, $match, $n );
		if ( is_serialized( $old ) ) {
			// Lengths were rewritten too, so show the saved value rather than a plain replace of the excerpt.
			$after = substr( $new, $start, strlen( $after ) );
		}
		return array(
			'row'    => implode( ', ', array_intersect_key( $row, array_flip( $pk ) ) ),
			'column' => $column,
			'before' => wp_check_invalid_utf8( $text, true ),
			'after'  => wp_check_invalid_utf8( $after, true ),
			'cut'    => array( $start > 0, $start + strlen( $text ) < strlen( $old ) ),
		);
	}

	/**
	 * @param string $column Column.
	 * @return string
	 */
	protected static function quote_column( $column ) {
		return '`' . str_replace( '`', '``', $column ) . '`';
	}

	/**
	 * Remove a run's state.
	 *
	 * @param string $id Run id.
	 */
	public static function delete( $id ) {
		delete_option( self::STATE_PREFIX . sanitize_key( $id ) );
	}

	/**
	 * Remove runs older than a day, and rollback files older than BACKUP_DAYS
	 * (runs with the daily cleanup). The history entry stays, so the run is still
	 * listed, but it can no longer be rolled back.
	 */
	public static function cleanup() {
		global $wpdb;
		$names = $wpdb->get_col( $wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( self::STATE_PREFIX ) . '%' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		foreach ( $names as $name ) {
			$state = get_option( $name );
			if ( ! is_array( $state ) || empty( $state['created'] ) || $state['created'] < time() - DAY_IN_SECONDS ) {
				delete_option( $name );
			}
		}
		foreach ( (array) get_option( self::LOG_INDEX, array() ) as $id ) {
			$entry = self::history_entry( $id );
			// A rollback in progress needs its file to finish.
			if ( ! $entry || 'rolling_back' === $entry['status'] || (int) $entry['created'] >= time() - self::BACKUP_DAYS * DAY_IN_SECONDS ) {
				continue;
			}
			if ( $entry['backup'] && file_exists( $entry['backup'] ) ) {
				wp_delete_file( $entry['backup'] );
			}
		}
	}
}
