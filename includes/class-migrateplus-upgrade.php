<?php
/**
 * One-time move of an install's data from before the plugin was renamed.
 *
 * @package MigratePlus
 */

defined( 'ABSPATH' ) || exit;

/**
 * MigratePlus was called PressPort, and stored everything under that name:
 * options and cron hook prefixed "pressport_", post, comment and term meta
 * prefixed "_pressport_", and files in uploads/pressport/. The first time the
 * renamed plugin loads, this moves all of it to the new names, so settings,
 * history, rollback and "imported before" matching carry on as they were.
 * A flag records that it ran, so after that each load costs one autoloaded option.
 */
class MigratePlus_Upgrade {

	// Set once the move has run (on a new install, when there was nothing to move).
	const FLAG = 'migrateplus_renamed';

	// The names before the rename.
	const OLD_OPTION_PREFIX = 'pressport_';
	const OLD_META_PREFIX   = '_pressport_';
	const OLD_CRON_HOOK     = 'pressport_cleanup';
	const OLD_DIR           = 'pressport';

	public static function maybe_run() {
		if ( get_option( self::FLAG ) ) {
			return;
		}
		// Only once everything moved: what failed is tried again on the next load.
		if ( self::run() ) {
			update_option( self::FLAG, 1 );
		}
	}

	/**
	 * Activation: activating the renamed plugin happens after plugins_loaded, so the
	 * move runs here first, before the storage folder is created under its new name.
	 */
	public static function activate() {
		self::maybe_run();
		MigratePlus_Util::ensure_storage_dir();
	}

	/**
	 * Move everything. Safe to run again: what was moved is no longer under the old names.
	 *
	 * @return bool Whether every option and meta key was moved.
	 */
	public static function run() {
		$uploads = wp_upload_dir( null, false );
		$old_dir = wp_normalize_path( trailingslashit( $uploads['basedir'] ) . self::OLD_DIR . '/' );
		$new_dir = wp_normalize_path( MigratePlus_Util::storage_dir() );
		self::move_dir( $old_dir, $new_dir );
		list( $options, $options_ok ) = self::rename_options( $old_dir, $new_dir );
		list( $meta, $meta_ok )       = self::rename_meta();
		wp_clear_scheduled_hook( self::OLD_CRON_HOOK ); // The new hook is scheduled on init.
		MigratePlus_Settings::flush_cache();
		// Meta was renamed in SQL, behind any persistent object cache, which may also hold "no such
		// option" for the new names. Only when something moved: not on a new install, and not on
		// every load while a write keeps failing, which would lose every cached value each time.
		if ( $options || $meta ) {
			wp_cache_flush();
		}
		return $options_ok && $meta_ok;
	}

	/**
	 * Move the old storage folder's files to the new one. Activating the renamed
	 * plugin may have created the new folder already, so files move one by one
	 * when the folder can't be renamed whole.
	 *
	 * @param string $old Old folder, with trailing slash.
	 * @param string $new New folder, with trailing slash.
	 */
	protected static function move_dir( $old, $new ) {
		if ( ! is_dir( $old ) ) {
			return;
		}
		if ( ! is_dir( $new ) && @rename( untrailingslashit( $old ), untrailingslashit( $new ) ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.WP.AlternativeFunctions
			return;
		}
		wp_mkdir_p( $new );
		$guards = array( 'index.php', '.htaccess' );
		$left   = false;
		foreach ( array_diff( (array) scandir( $old ), array( '.', '..' ), $guards ) as $name ) {
			// The rest have random names, so none is in both folders; a file that can't be moved keeps its old path.
			if ( is_file( $old . $name ) && ( file_exists( $new . $name ) || ! @rename( $old . $name, $new . $name ) ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.WP.AlternativeFunctions
				$left = true;
			}
		}
		// The folder's index.php and .htaccess last, and only once nothing is left for them to guard.
		foreach ( $left ? array() : $guards as $name ) {
			if ( ! is_file( $old . $name ) ) {
				continue;
			}
			if ( file_exists( $new . $name ) ) {
				wp_delete_file( $old . $name );
				continue;
			}
			@rename( $old . $name, $new . $name ); // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.WP.AlternativeFunctions
		}
		@rmdir( $old ); // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.WP.AlternativeFunctions
	}

	/**
	 * Rename every "pressport_" option: settings, saved mappings, import jobs and
	 * their index, export and replace states, their histories and batch locks.
	 * Paths into the old storage folder saved inside them (a job's uploaded and
	 * cached files, an export's file, a replace run's rollback file) follow the
	 * files, and a job's source settings drop their old "pressport_" key prefix.
	 *
	 * @param string $old_dir Old storage folder.
	 * @param string $new_dir New storage folder.
	 * @return array { 0: int old options moved, 1: bool whether every one was moved }
	 */
	protected static function rename_options( $old_dir, $new_dir ) {
		global $wpdb;
		$ok    = true;
		$moved = 0;
		$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT option_name, autoload FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( self::OLD_OPTION_PREFIX ) . '%' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		foreach ( (array) $rows as $row ) {
			$new = 'migrateplus_' . substr( $row->option_name, strlen( self::OLD_OPTION_PREFIX ) );
			$old = get_option( $row->option_name );
			// Two first page loads can run this at once. The other one may have moved this option and deleted
			// it since the list above was read: false is then not a value to copy. And add_option() upserts,
			// trusting a cached "no such option", so it could write over what the other one moved: ask the table.
			$exists = (bool) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name = %s", $new ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			if ( false !== $old && ! $exists ) {
				$value = self::move_paths( $old, $old_dir, $new_dir );
				if ( 0 === strpos( $new, 'migrateplus_job_' ) && isset( $value['settings'] ) && is_array( $value['settings'] ) ) {
					$value['settings'] = self::rename_keys( $value['settings'] );
				}
				// WordPress 6.6 stores "on"/"off"/"auto…" instead of "yes"/"no"; add_option() takes both of the old ones.
				add_option( $new, $value, '', in_array( $row->autoload, array( 'yes', 'on', 'auto', 'auto-on' ), true ) ? 'yes' : 'no' );
				$exists = (bool) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name = %s", $new ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			}
			// The old one only goes once the new one is there: a failed write must not lose the value.
			if ( false !== $old && ! $exists ) {
				$ok = false;
				continue;
			}
			$moved += (int) delete_option( $row->option_name );
		}
		return array( $moved, $ok );
	}

	/**
	 * Rename every "_pressport_" meta key: one UPDATE per meta table, covering the
	 * fixed keys (job id, source uid/id/url, parent uid, ACF pending, comment uid, …)
	 * and the per-product "_pressport_pending_<hash>" keys alike. A prefix LIKE uses
	 * the meta_key index, so this stays fast however many rows there are.
	 *
	 * @return array { 0: int rows renamed, 1: bool whether every UPDATE worked }
	 */
	protected static function rename_meta() {
		global $wpdb;
		$rows = 0;
		foreach ( array( $wpdb->postmeta, $wpdb->commentmeta, $wpdb->termmeta ) as $table ) {
			$done = $wpdb->query( $wpdb->prepare( "UPDATE {$table} SET meta_key = CONCAT( %s, SUBSTRING( meta_key, %d ) ) WHERE meta_key LIKE %s", '_migrateplus_', strlen( self::OLD_META_PREFIX ) + 1, $wpdb->esc_like( self::OLD_META_PREFIX ) . '%' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- core table names.
			if ( false === $done ) {
				return array( $rows, false );
			}
			$rows += (int) $done;
		}
		return array( $rows, true );
	}

	/**
	 * Point saved paths into the old storage folder at the new one, where the file was moved.
	 *
	 * @param mixed  $value   Option value.
	 * @param string $old_dir Old storage folder.
	 * @param string $new_dir New storage folder.
	 * @return mixed
	 */
	protected static function move_paths( $value, $old_dir, $new_dir ) {
		if ( is_array( $value ) ) {
			foreach ( $value as $key => $item ) {
				$value[ $key ] = self::move_paths( $item, $old_dir, $new_dir );
			}
			return $value;
		}
		if ( ! is_string( $value ) || 0 !== strpos( wp_normalize_path( $value ), $old_dir ) ) {
			return $value;
		}
		// A file that could not be moved keeps its old path, which still works.
		return file_exists( $value ) ? $value : $new_dir . substr( wp_normalize_path( $value ), strlen( $old_dir ) );
	}

	/**
	 * @param array $settings A job's source settings.
	 * @return array
	 */
	protected static function rename_keys( array $settings ) {
		$out = array();
		foreach ( $settings as $key => $item ) {
			$old         = 0 === strpos( (string) $key, self::OLD_OPTION_PREFIX );
			$new         = $old ? 'migrateplus_' . substr( $key, strlen( self::OLD_OPTION_PREFIX ) ) : $key;
			$out[ $new ] = $item;
		}
		return $out;
	}
}
