<?php
/**
 * Plugin Name:       PressPort
 * Description:       Move content between WordPress sites as JSON. Export posts, pages, custom post types and WooCommerce products, then import the file on another site with field mapping (including ACF), dry runs, batched processing, media sideloading and one-click rollback.
 * Version:           0.1.0-beta.1
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            CNC Resource
 * License:           GPL-3.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-3.0.html
 * Text Domain:       pressport
 *
 * @package PressPort
 */

defined( 'ABSPATH' ) || exit;

define( 'PRESSPORT_VERSION', '0.1.0-beta.1' );
define( 'PRESSPORT_FILE', __FILE__ );
define( 'PRESSPORT_DIR', plugin_dir_path( __FILE__ ) );
define( 'PRESSPORT_URL', plugin_dir_url( __FILE__ ) );

require_once PRESSPORT_DIR . 'includes/class-pressport-util.php';
require_once PRESSPORT_DIR . 'includes/class-pressport-settings.php';
require_once PRESSPORT_DIR . 'includes/sources/interface-pressport-source.php';
require_once PRESSPORT_DIR . 'includes/sources/class-pressport-source-json.php';
require_once PRESSPORT_DIR . 'includes/class-pressport-sources.php';
require_once PRESSPORT_DIR . 'includes/class-pressport-job.php';
require_once PRESSPORT_DIR . 'includes/class-pressport-media.php';
require_once PRESSPORT_DIR . 'includes/class-pressport-acf.php';
require_once PRESSPORT_DIR . 'includes/class-pressport-woo.php';
require_once PRESSPORT_DIR . 'includes/class-pressport-exporter.php';
require_once PRESSPORT_DIR . 'includes/class-pressport-importer.php';
require_once PRESSPORT_DIR . 'includes/class-pressport-runner.php';
require_once PRESSPORT_DIR . 'includes/class-pressport-replace.php';

if ( is_admin() ) {
	require_once PRESSPORT_DIR . 'includes/class-pressport-admin.php';
	add_action( 'plugins_loaded', array( 'PressPort_Admin', 'init' ) );
	add_action( 'plugins_loaded', array( 'PressPort_Settings', 'init' ) );
}

// Let PressPort's own requests reach local/dev sites when the setting is on.
add_filter( 'http_request_host_is_external', array( 'PressPort_Util', 'allow_local_host' ), 10, 3 );
add_filter( 'http_allowed_safe_ports', array( 'PressPort_Util', 'allow_local_port' ), 10, 3 );

// Signed links for downloadable product files in an export; the importing site fetches them logged out.
add_action( 'wp_ajax_pressport_file', array( 'PressPort_Woo', 'serve_file' ) );
add_action( 'wp_ajax_nopriv_pressport_file', array( 'PressPort_Woo', 'serve_file' ) );

add_action( 'pressport_cleanup', array( 'PressPort_Settings', 'cleanup' ) );
add_action( 'pressport_cleanup', array( 'PressPort_Exporter', 'cleanup' ) );
add_action( 'pressport_cleanup', array( 'PressPort_Replace', 'cleanup' ) );
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
