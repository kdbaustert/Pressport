<?php
/**
 * PHPUnit bootstrap: the WordPress test suite from Composer, with WooCommerce
 * (when Composer installed it) and MigratePlus loaded.
 *
 * @package MigratePlus
 */

$root = dirname( __DIR__ );

putenv( 'WP_PHPUNIT__TESTS_CONFIG=' . __DIR__ . '/wp-tests-config.php' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.runtime_configuration_putenv -- how wp-phpunit is told where the config is.
require_once $root . '/vendor/yoast/phpunit-polyfills/phpunitpolyfills-autoload.php';

$tests_dir = getenv( 'WP_PHPUNIT__DIR' ) ? getenv( 'WP_PHPUNIT__DIR' ) : $root . '/vendor/wp-phpunit/wp-phpunit';
require_once $tests_dir . '/includes/functions.php';

// A dev dependency (wpackagist), so it is never committed or shipped. Without it the WooCommerce tests skip.
$woo = $root . '/vendor/wpackagist-plugin/woocommerce/woocommerce.php';

tests_add_filter(
	'muplugins_loaded',
	static function () use ( $root, $woo ) {
		if ( file_exists( $woo ) ) {
			require $woo;
		}
		require $root . '/migrateplus.php';
	}
);

// As WooCommerce's own test bootstrap does: create its tables, options and roles once WordPress is up.
tests_add_filter(
	'after_setup_theme',
	static function () {
		if ( ! class_exists( 'WC_Install' ) ) {
			return;
		}
		WC_Install::install();
		// install() leaves out the order tables, so WooCommerce would create them inside a test, where the suite
		// makes them temporary, and MySQL can't use a temporary table twice in one query ("Can't reopen table").
		wc_get_container()->get( Automattic\WooCommerce\Internal\DataStores\Orders\DataSynchronizer::class )->create_database_tables();
		$GLOBALS['wp_roles'] = null; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- reload the roles WooCommerce just added.
		wp_roles();
	}
);

require $tests_dir . '/includes/bootstrap.php';
