<?php
/**
 * Plugin Name:       PressPort
 * Description:       Port your content into WordPress. Import posts, pages and custom post types from CSV files, JSON feeds, or another CMS (WordPress, Drupal, Ghost) with field mapping, dry runs, batched processing, media sideloading and one-click rollback.
 * Version:           1.0.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            CNC Resource
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       pressport
 *
 * @package PressPort
 */

defined( 'ABSPATH' ) || exit;

define( 'PRESSPORT_VERSION', '1.0.0' );
define( 'PRESSPORT_FILE', __FILE__ );
define( 'PRESSPORT_DIR', plugin_dir_path( __FILE__ ) );
define( 'PRESSPORT_URL', plugin_dir_url( __FILE__ ) );

require_once PRESSPORT_DIR . 'includes/class-pressport-util.php';
require_once PRESSPORT_DIR . 'includes/class-pressport-settings.php';
require_once PRESSPORT_DIR . 'includes/sources/interface-pressport-source.php';
require_once PRESSPORT_DIR . 'includes/sources/class-pressport-source-csv.php';
require_once PRESSPORT_DIR . 'includes/sources/class-pressport-source-json.php';
require_once PRESSPORT_DIR . 'includes/sources/class-pressport-source-wordpress.php';
require_once PRESSPORT_DIR . 'includes/sources/class-pressport-source-drupal.php';
require_once PRESSPORT_DIR . 'includes/sources/class-pressport-source-ghost.php';
require_once PRESSPORT_DIR . 'includes/class-pressport-sources.php';
require_once PRESSPORT_DIR . 'includes/class-pressport-job.php';
require_once PRESSPORT_DIR . 'includes/class-pressport-media.php';
require_once PRESSPORT_DIR . 'includes/class-pressport-importer.php';
require_once PRESSPORT_DIR . 'includes/class-pressport-runner.php';

if ( is_admin() ) {
	require_once PRESSPORT_DIR . 'includes/class-pressport-admin.php';
	add_action( 'plugins_loaded', array( 'PressPort_Admin', 'init' ) );
	add_action( 'plugins_loaded', array( 'PressPort_Settings', 'init' ) );
}

// Let PressPort's own requests reach local/dev sites when the setting is on.
add_filter( 'http_request_host_is_external', array( 'PressPort_Util', 'allow_local_host' ), 10, 3 );
add_filter( 'http_allowed_safe_ports', array( 'PressPort_Util', 'allow_local_port' ), 10, 3 );

add_action( 'pressport_cleanup', array( 'PressPort_Settings', 'cleanup' ) );
add_action(
	'init',
	static function () {
		if ( ! wp_next_scheduled( 'pressport_cleanup' ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'pressport_cleanup' );
		}
	}
);

if ( defined( 'WP_CLI' ) && WP_CLI ) {
	require_once PRESSPORT_DIR . 'includes/class-pressport-cli.php';
	WP_CLI::add_command( 'pressport', 'PressPort_CLI' );
}

register_activation_hook( __FILE__, array( 'PressPort_Util', 'ensure_storage_dir' ) );
register_deactivation_hook(
	__FILE__,
	static function () {
		wp_clear_scheduled_hook( 'pressport_cleanup' );
	}
);
