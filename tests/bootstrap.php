<?php
/**
 * PHPUnit bootstrap: the WordPress test suite from Composer, with PressPort loaded.
 *
 * @package PressPort
 */

$root = dirname( __DIR__ );

putenv( 'WP_PHPUNIT__TESTS_CONFIG=' . __DIR__ . '/wp-tests-config.php' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.runtime_configuration_putenv -- how wp-phpunit is told where the config is.
require_once $root . '/vendor/yoast/phpunit-polyfills/phpunitpolyfills-autoload.php';

$tests_dir = getenv( 'WP_PHPUNIT__DIR' ) ? getenv( 'WP_PHPUNIT__DIR' ) : $root . '/vendor/wp-phpunit/wp-phpunit';
require_once $tests_dir . '/includes/functions.php';

tests_add_filter(
	'muplugins_loaded',
	static function () use ( $root ) {
		require $root . '/pressport.php';
	}
);

require $tests_dir . '/includes/bootstrap.php';
