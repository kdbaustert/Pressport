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
 * Paths are kept in one option (not autoloaded), read only when a page is not
 * found, and removed again when the import that added them is rolled back.
 * An old "?p=123" address has no path, so it is kept as "?p=123". A batch adds
 * its paths to a buffer that is written once, at the end of the request:
 * rewriting the whole option for every post would make an import quadratic.
 */
class MigratePlus_Redirects {

	const OPTION = 'migrateplus_redirects';

	/**
	 * Redirects recorded in this request and not written yet.
	 *
	 * @var array path => entry, as all() returns them.
	 */
	protected static $buffer = array();

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
		$old  = self::key( $url, $base );
		$new  = self::path( (string) get_permalink( $post_id ), home_url() );
		if ( '' === $old || $old === $new ) {
			return false;
		}
		$job_id = (string) $job->data['id'];
		$entry  = array( (int) $post_id, $job_id );
		$map    = self::all();
		if ( isset( $map[ $old ] ) && (string) $map[ $old ][1] !== $job_id ) {
			// Another import's redirect for this path comes back if this import is rolled back.
			$entry[] = $map[ $old ];
		} elseif ( isset( $map[ $old ][2] ) ) {
			$entry[] = $map[ $old ][2];
		}
		if ( ! self::$buffer ) {
			add_action( 'shutdown', array( __CLASS__, 'flush' ) );
		}
		self::$buffer[ $old ] = $entry;
		return true;
	}

	/**
	 * Write the redirects recorded in this request to the option.
	 */
	public static function flush() {
		if ( ! self::$buffer ) {
			return;
		}
		$map          = self::$buffer + self::stored();
		self::$buffer = array();
		update_option( self::OPTION, $map, false );
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
		// REQUEST_URI already carries the subdirectory WordPress may live in: path() removes home's.
		$path = self::key( wp_unslash( $_SERVER['REQUEST_URI'] ), home_url() ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- only its path and post id are read, and compared against stored keys.
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
		self::flush();
		$map     = self::stored();
		$kept    = array();
		$removed = 0;
		foreach ( $map as $path => $entry ) {
			$left = self::without_job( $entry, (string) $job_id );
			if ( (string) $entry[1] === (string) $job_id ) {
				++$removed;
			}
			if ( null !== $left ) {
				$kept[ $path ] = $left;
			}
		}
		if ( $kept !== $map ) {
			update_option( self::OPTION, $kept, false );
		}
		return $removed;
	}

	/**
	 * An entry with a job's part taken out: the earlier import's redirect it
	 * replaced takes its place again.
	 *
	 * @param array  $entry  [ post id, job id, entry it replaced? ].
	 * @param string $job_id Job id.
	 * @return array|null Null when nothing is left.
	 */
	protected static function without_job( array $entry, $job_id ) {
		$previous = isset( $entry[2] ) && is_array( $entry[2] ) ? self::without_job( $entry[2], $job_id ) : null;
		if ( (string) $entry[1] === $job_id ) {
			return $previous;
		}
		$out = array( (int) $entry[0], (string) $entry[1] );
		if ( null !== $previous ) {
			$out[] = $previous;
		}
		return $out;
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
	 * Every redirect, those not written yet included.
	 *
	 * @return array path => [ post id, job id, the entry of an earlier import it replaced? ]
	 */
	public static function all() {
		return self::$buffer + self::stored();
	}

	/**
	 * @return array The redirects in the option.
	 */
	protected static function stored() {
		$map = get_option( self::OPTION, array() );
		return is_array( $map ) ? $map : array();
	}

	/**
	 * What a redirect is kept under: the URL's path (see path()), or for an
	 * address with none, the post id it asks for, as "?p=123".
	 *
	 * @param string $url  URL or request path.
	 * @param string $base The site it is on.
	 * @return string '' for the front page or an unreadable URL.
	 */
	public static function key( $url, $base = '' ) {
		$path = self::path( $url, $base );
		if ( '' !== $path ) {
			return $path;
		}
		wp_parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $query );
		foreach ( array( 'p', 'page_id', 'attachment_id' ) as $var ) {
			if ( isset( $query[ $var ] ) && is_string( $query[ $var ] ) && ctype_digit( $query[ $var ] ) && (int) $query[ $var ] > 0 ) {
				return '?p=' . (int) $query[ $var ];
			}
		}
		return '';
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
