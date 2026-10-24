<?php
/**
 * Plugin Name:       Migrate Plus
 * Description:       Move content between WordPress sites as JSON. Export posts, pages, custom post types and WooCommerce products, then import the file on another site with field mapping (including ACF), dry runs, batched processing, media sideloading and one-click rollback.
 * Version:           0.1.0-beta.1
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            CNC Resource
 * License:           GPL-3.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-3.0.html
 * Text Domain:       migrateplus
 *
 * @package MigratePlus
 */

defined( 'ABSPATH' ) || exit;

define( 'MIGRATEPLUS_VERSION', '0.1.0-beta.1' );
define( 'MIGRATEPLUS_FILE', __FILE__ );
define( 'MIGRATEPLUS_DIR', plugin_dir_path( __FILE__ ) );
define( 'MIGRATEPLUS_URL', plugin_dir_url( __FILE__ ) );

require_once MIGRATEPLUS_DIR . 'includes/class-migrateplus-util.php';
require_once MIGRATEPLUS_DIR . 'includes/class-migrateplus-settings.php';
require_once MIGRATEPLUS_DIR . 'includes/sources/interface-migrateplus-source.php';
require_once MIGRATEPLUS_DIR . 'includes/sources/class-migrateplus-source-json.php';
require_once MIGRATEPLUS_DIR . 'includes/class-migrateplus-sources.php';
require_once MIGRATEPLUS_DIR . 'includes/class-migrateplus-job.php';
require_once MIGRATEPLUS_DIR . 'includes/class-migrateplus-mappings.php';
require_once MIGRATEPLUS_DIR . 'includes/class-migrateplus-media.php';
require_once MIGRATEPLUS_DIR . 'includes/class-migrateplus-acf.php';
require_once MIGRATEPLUS_DIR . 'includes/class-migrateplus-woo.php';
require_once MIGRATEPLUS_DIR . 'includes/class-migrateplus-menus.php';
require_once MIGRATEPLUS_DIR . 'includes/class-migrateplus-exporter.php';
require_once MIGRATEPLUS_DIR . 'includes/class-migrateplus-importer.php';
require_once MIGRATEPLUS_DIR . 'includes/class-migrateplus-runner.php';
require_once MIGRATEPLUS_DIR . 'includes/class-migrateplus-replace.php';
require_once MIGRATEPLUS_DIR . 'includes/class-migrateplus-upgrade.php';

// Before anything reads settings: moves the data of an install from before the rename, once.
add_action( 'plugins_loaded', array( 'MigratePlus_Upgrade', 'maybe_run' ), 0 );

if ( is_admin() ) {
	require_once MIGRATEPLUS_DIR . 'includes/class-migrateplus-admin.php';
	add_action( 'plugins_loaded', array( 'MigratePlus_Admin', 'init' ) );
	add_action( 'plugins_loaded', array( 'MigratePlus_Settings', 'init' ) );
}

// Let MigratePlus's own requests reach local/dev sites when the setting is on.
add_filter( 'http_request_host_is_external', array( 'MigratePlus_Util', 'allow_local_host' ), 10, 3 );
add_filter( 'http_allowed_safe_ports', array( 'MigratePlus_Util', 'allow_local_port' ), 10, 3 );

// Signed links for downloadable product files in an export; the importing site fetches them logged out.
add_action( 'wp_ajax_migrateplus_file', array( 'MigratePlus_Woo', 'serve_file' ) );
add_action( 'wp_ajax_nopriv_migrateplus_file', array( 'MigratePlus_Woo', 'serve_file' ) );

add_action( 'migrateplus_cleanup', array( 'MigratePlus_Settings', 'cleanup' ) );
add_action( 'migrateplus_cleanup', array( 'MigratePlus_Exporter', 'cleanup' ) );
add_action( 'migrateplus_cleanup', array( 'MigratePlus_Replace', 'cleanup' ) );
add_action(
	'init',
	static function () {
		if ( ! wp_next_scheduled( 'migrateplus_cleanup' ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'migrateplus_cleanup' );
		}
	}
);

if ( defined( 'WP_CLI' ) && WP_CLI ) {
	require_once MIGRATEPLUS_DIR . 'includes/class-migrateplus-cli.php';
	WP_CLI::add_command( 'migrateplus', 'MigratePlus_CLI' );
}

register_activation_hook( __FILE__, array( 'MigratePlus_Upgrade', 'activate' ) );
register_deactivation_hook(
	__FILE__,
	static function () {
		wp_clear_scheduled_hook( 'migrateplus_cleanup' );
	}
);
