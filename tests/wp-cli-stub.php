<?php
/**
 * Just enough of WP-CLI for MigratePlus_CLI to run inside PHPUnit. Output is
 * collected instead of printed, and WP_CLI::error() throws, as the real one exits.
 *
 * @package MigratePlus
 */

// phpcs:disable Universal.Namespaces, Universal.Files.SeparateFunctionsFromOO, Generic.CodeAnalysis.UnusedFunctionParameter -- the stub spans WP-CLI's three namespaces and keeps its signatures.

namespace {
	class WP_CLI {
		public static $output = array();
		public static $items  = array();

		public static function log( $message ) {
			self::$output[] = $message;
		}

		public static function success( $message ) {
			self::$output[] = 'Success: ' . $message;
		}

		public static function warning( $message ) {
			self::$output[] = 'Warning: ' . $message;
		}

		public static function error( $message ) {
			throw new RuntimeException( $message ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
		}

		public static function confirm( $question, $assoc ) {
		}
	}
}

namespace WP_CLI\Utils {
	function get_flag_value( $assoc, $flag, $default = null ) {
		return isset( $assoc[ $flag ] ) ? $assoc[ $flag ] : $default;
	}

	function format_items( $format, $items, $fields ) {
		\WP_CLI::$items = $items;
	}

	function make_progress_bar( $message, $count ) {
		return new class() {
			public function tick( $n = 1 ) {
			}

			public function finish() {
			}
		};
	}
}

namespace cli {
	/**
	 * The duplicates prompt asks only when STDIN is a terminal, as it is when the tests run from a shell.
	 */
	function choose( $question, $choices, $default ) {
		return $default;
	}
}
