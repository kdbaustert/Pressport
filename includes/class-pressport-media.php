<?php
/**
 * Media sideloading.
 *
 * @package PressPort
 */

defined( 'ABSPATH' ) || exit;

/**
 * Downloads remote images into the Media Library (deduplicated by source URL)
 * and rewrites image references inside post content.
 */
class PressPort_Media {

	/**
	 * Load the admin includes needed for sideloading (CLI/AJAX safe).
	 */
	protected static function load_deps() {
		if ( ! function_exists( 'media_handle_sideload' ) ) {
			require_once ABSPATH . 'wp-admin/includes/media.php';
			require_once ABSPATH . 'wp-admin/includes/file.php';
			require_once ABSPATH . 'wp-admin/includes/image.php';
		}
	}

	/**
	 * Resolve a possibly relative URL against a base.
	 *
	 * @param string $url  URL.
	 * @param string $base Base URL.
	 * @return string
	 */
	public static function absolutize( $url, $base ) {
		$url = trim( html_entity_decode( (string) $url, ENT_QUOTES, 'UTF-8' ) );
		if ( '' === $url || preg_match( '#^https?://#i', $url ) ) {
			return $url;
		}
		if ( 0 === strpos( $url, '//' ) ) {
			return 'https:' . $url;
		}
		if ( '' === (string) $base || 0 === strpos( $url, 'data:' ) ) {
			return '';
		}
		$parts  = wp_parse_url( $base );
		$origin = ( isset( $parts['scheme'] ) ? $parts['scheme'] : 'https' ) . '://' . ( isset( $parts['host'] ) ? $parts['host'] : '' ) . ( isset( $parts['port'] ) ? ':' . $parts['port'] : '' );
		if ( 0 === strpos( $url, '/' ) ) {
			return $origin . $url;
		}
		return untrailingslashit( $base ) . '/' . $url;
	}

	/**
	 * Find an attachment previously imported from a URL.
	 *
	 * @param string $url Source URL.
	 * @return int
	 */
	public static function find_existing( $url ) {
		$ids = get_posts(
			array(
				'post_type'      => 'attachment',
				'post_status'    => 'any',
				'meta_key'       => '_pressport_source_url', // phpcs:ignore WordPress.DB.SlowDBQuery
				'meta_value'     => $url, // phpcs:ignore WordPress.DB.SlowDBQuery
				'fields'         => 'ids',
				'posts_per_page' => 1,
			)
		);
		return $ids ? (int) $ids[0] : 0;
	}

	/**
	 * Sideload one image.
	 *
	 * @param string $url     Image URL (absolute or relative to $base).
	 * @param int    $post_id Parent post.
	 * @param string $alt     Alt text.
	 * @param string $base    Base URL for relative paths.
	 * @param string $job_id  Job id for rollback tracking.
	 * @return int|WP_Error Attachment id.
	 */
	public static function sideload( $url, $post_id, $alt = '', $base = '', $job_id = '' ) {
		$url = self::absolutize( $url, $base );
		if ( '' === $url ) {
			return new WP_Error( 'pressport_media_url', __( 'Invalid image URL.', 'pressport' ) );
		}
		$existing = self::find_existing( $url );
		if ( $existing ) {
			return $existing;
		}

		self::load_deps();
		$tmp = download_url( $url, 60 );
		if ( is_wp_error( $tmp ) ) {
			return $tmp;
		}

		$path = (string) wp_parse_url( $url, PHP_URL_PATH );
		$name = sanitize_file_name( wp_basename( rawurldecode( $path ) ) );
		$ext  = strtolower( pathinfo( $name, PATHINFO_EXTENSION ) );
		$mime = function_exists( 'wp_get_image_mime' ) ? wp_get_image_mime( $tmp ) : false;
		$map  = array(
			'image/jpeg'    => 'jpg',
			'image/png'     => 'png',
			'image/gif'     => 'gif',
			'image/webp'    => 'webp',
			'image/avif'    => 'avif',
			'image/svg+xml' => 'svg',
		);
		if ( $mime && isset( $map[ $mime ] ) && ! in_array( $ext, array( 'jpg', 'jpeg', 'png', 'gif', 'webp', 'avif', 'svg' ), true ) ) {
			$name = ( $name ? pathinfo( $name, PATHINFO_FILENAME ) : 'image-' . md5( $url ) ) . '.' . $map[ $mime ];
		}
		if ( '' === $name ) {
			$name = 'image-' . md5( $url ) . '.jpg';
		}

		$file = array(
			'name'     => $name,
			'tmp_name' => $tmp,
		);
		$id   = media_handle_sideload( $file, $post_id );
		if ( is_wp_error( $id ) ) {
			if ( file_exists( $tmp ) ) {
				wp_delete_file( $tmp );
			}
			return $id;
		}
		update_post_meta( $id, '_pressport_source_url', $url );
		if ( $job_id ) {
			update_post_meta( $id, '_pressport_job_id', $job_id );
		}
		if ( '' !== (string) $alt ) {
			update_post_meta( $id, '_wp_attachment_image_alt', sanitize_text_field( $alt ) );
		}
		return (int) $id;
	}

	/**
	 * Import every external <img> in HTML and rewrite src to the local copy.
	 *
	 * @param string $html     Content.
	 * @param int    $post_id  Post.
	 * @param string $base     Base URL for relative paths.
	 * @param string $job_id   Job id.
	 * @param array  $errors   Collected error messages (by reference).
	 * @return string
	 */
	public static function import_content_images( $html, $post_id, $base, $job_id, &$errors = array() ) {
		if ( false === stripos( (string) $html, '<img' ) ) {
			return $html;
		}
		$home = untrailingslashit( preg_replace( '#^https?:#i', '', home_url() ) );

		return preg_replace_callback(
			'/<img\b[^>]*>/i',
			static function ( $m ) use ( $post_id, $base, $job_id, $home, &$errors ) {
				$tag = $m[0];
				if ( ! preg_match( '/\ssrc\s*=\s*(["\'])(.*?)\1/i', $tag, $src ) ) {
					return $tag;
				}
				$url = self::absolutize( $src[2], $base );
				// Skip images already hosted on this site.
				if ( ! $url || 0 === stripos( preg_replace( '#^https?:#i', '', $url ), $home . '/' ) ) {
					return $tag;
				}
				$alt = preg_match( '/\salt\s*=\s*(["\'])(.*?)\1/i', $tag, $a ) ? html_entity_decode( $a[2], ENT_QUOTES, 'UTF-8' ) : '';
				$id  = self::sideload( $url, $post_id, $alt, $base, $job_id );
				if ( is_wp_error( $id ) ) {
					$errors[] = $url . ': ' . $id->get_error_message();
					return $tag;
				}
				$new = wp_get_attachment_url( $id );
				$tag = str_replace( $src[0], ' src=' . $src[1] . esc_url( $new ) . $src[1], $tag );
				// Old responsive sources would still point at the old site.
				$tag = preg_replace( '/\s(srcset|sizes)\s*=\s*(["\']).*?\2/i', '', $tag );
				// Update block image class (wp-image-123) so the editor links it.
				$tag = preg_replace( '/\bwp-image-\d+\b/', 'wp-image-' . $id, $tag );
				return $tag;
			},
			$html
		);
	}
}
