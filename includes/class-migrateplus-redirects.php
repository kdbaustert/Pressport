<?php
/**
 * Redirects from old addresses to imported content.
 *
 * @package MigratePlus
 */

defined( 'ABSPATH' ) || exit;

/**
 * When an imported post ends up at another path than it had on the old site
 * (a slug WordPress had to change, a different permalink structure, a parent
 * that is not here), its old path is remembered, and a visitor who lands on it
 * once the domain points at this site is sent to the post with a 301.
 *
 * Paths are kept in one option, read only when a page is not found, and
 * removed again when the import that added them is rolled back.
 */
class MigratePlus_Redirects {

	const OPTION = 'migrateplus_redirects';

	public static function init() {
		add_action( 'template_redirect', array( __CLASS__, 'maybe_redirect' ), 1 );
	}

	/**
	 * Remember an imported post's old address when it differs from its new one.
	 *
	 * @param int             $post_id Post id.
	 * @param string          $url     The post's address on the old site.
	 * @param MigratePlus_Job $job     Job.
	 * @return bool Whether a redirect was added.
	 */
	public static function record( $post_id, $url, MigratePlus_Job $job ) {
		$base = isset( $job->data['settings']['migrateplus_site'] ) ? (string) $job->data['settings']['migrateplus_site'] : '';
		$old  = self::path( $url, $base );
		$new  = self::path( (string) get_permalink( $post_id ), home_url() );
		if ( '' === $old || $old === $new ) {
			return false;
		}
		$map         = self::all();
		$map[ $old ] = array( (int) $post_id, (string) $job->data['id'] );
		update_option( self::OPTION, $map, false );
		return true;
	}

	/**
	 * Send a request for an old address to the post that moved.
	 */
	public static function maybe_redirect() {
		if ( ! is_404() || empty( $_SERVER['REQUEST_URI'] ) ) {
			return;
		}
		$map = self::all();
		if ( ! $map ) {
			return;
		}
		$path = self::path( home_url( wp_unslash( $_SERVER['REQUEST_URI'] ) ), home_url() ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- only its path is read, and compared against stored paths.
		if ( '' === $path || ! isset( $map[ $path ] ) ) {
			return;
		}
		$post = get_post( (int) $map[ $path ][0] );
		if ( ! $post || 'publish' !== $post->post_status ) {
			return;
		}
		$target = get_permalink( $post );
		if ( ! $target || self::path( $target, home_url() ) === $path ) {
			return;
		}
		if ( wp_safe_redirect( $target, 301 ) ) {
			exit;
		}
	}

	/**
	 * Drop the redirects an import added.
	 *
	 * @param string $job_id Job id.
	 * @return int How many were removed.
	 */
	public static function forget_job( $job_id ) {
		$map  = self::all();
		$kept = array_filter(
			$map,
			static function ( $entry ) use ( $job_id ) {
				return (string) $entry[1] !== (string) $job_id;
			}
		);
		if ( count( $kept ) !== count( $map ) ) {
			update_option( self::OPTION, $kept, false );
		}
		return count( $map ) - count( $kept );
	}

	/**
	 * How many redirects an import added.
	 *
	 * @param string $job_id Job id.
	 * @return int
	 */
	public static function count_for_job( $job_id ) {
		$n = 0;
		foreach ( self::all() as $entry ) {
			if ( (string) $entry[1] === (string) $job_id ) {
				++$n;
			}
		}
		return $n;
	}

	/**
	 * @return array path => [ post id, job id ]
	 */
	public static function all() {
		$map = get_option( self::OPTION, array() );
		return is_array( $map ) ? $map : array();
	}

	/**
	 * A URL's path below its site, as "a/b": no scheme, host, query or
	 * surrounding slashes, so the same page compares equal however it was typed.
	 *
	 * @param string $url  URL.
	 * @param string $base The site it is on, whose own path (a subdirectory install) is removed.
	 * @return string '' for the site's front page or an unreadable URL.
	 */
	public static function path( $url, $base = '' ) {
		$path = (string) wp_parse_url( $url, PHP_URL_PATH );
		$root = '' !== $base ? trim( (string) wp_parse_url( $base, PHP_URL_PATH ), '/' ) : '';
		$path = trim( rawurldecode( $path ), '/' );
		if ( '' !== $root && 0 === strpos( $path, $root . '/' ) ) {
			$path = substr( $path, strlen( $root ) + 1 );
		} elseif ( '' !== $root && $path === $root ) {
			$path = '';
		}
		return strtolower( $path );
	}
}
