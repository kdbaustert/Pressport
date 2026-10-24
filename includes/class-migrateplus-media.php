<?php
/**
 * Media sideloading.
 *
 * @package MigratePlus
 */

defined( 'ABSPATH' ) || exit;

/**
 * Downloads remote images into the Media Library (deduplicated by source URL)
 * and rewrites image references inside post content.
 */
class MigratePlus_Media {

	/**
	 * Load the admin includes needed for sideloading (CLI/AJAX safe).
	 */
	public static function load_deps() {
		if ( ! function_exists( 'media_handle_sideload' ) ) {
			require_once ABSPATH . 'wp-admin/includes/media.php';
			require_once ABSPATH . 'wp-admin/includes/file.php';
			require_once ABSPATH . 'wp-admin/includes/image.php';
		}
	}

	/**
	 * Download a file to a temp path, stopping at the size limit in Settings.
	 *
	 * @param string $url URL.
	 * @return string|WP_Error Temp file path.
	 */
	public static function download( $url ) {
		$max   = (int) MigratePlus_Settings::get( 'max_media_size' ) * MB_IN_BYTES;
		$limit = static function ( $args ) use ( $max ) {
			$args['limit_response_size'] = $max + 1; // One byte over tells a file at the limit from a bigger one.
			return $args;
		};
		if ( $max ) {
			add_filter( 'http_request_args', $limit );
		}
		MigratePlus_Util::$in_request = true;
		$tmp                          = download_url( $url, max( 60, (int) MigratePlus_Settings::get( 'request_timeout' ) ) );
		MigratePlus_Util::$in_request = false;
		remove_filter( 'http_request_args', $limit );

		if ( ! is_wp_error( $tmp ) && $max && filesize( $tmp ) > $max ) {
			wp_delete_file( $tmp );
			/* translators: %d: megabytes */
			return new WP_Error( 'migrateplus_media_size', sprintf( __( 'Skipped: larger than the %d MB limit in Settings.', 'migrateplus' ), $max / MB_IN_BYTES ) );
		}
		return $tmp;
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
		// Only http(s): a base or URL with another scheme (javascript:, data:) is not a file to fetch or show.
		if ( ! preg_match( '#^https?://#i', (string) $base ) || preg_match( '#^[a-z][a-z0-9+.-]*:#i', $url ) ) {
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
				'post_type'        => 'attachment',
				'post_status'      => 'any',
				'meta_key'         => '_migrateplus_source_url', // phpcs:ignore WordPress.DB.SlowDBQuery
				'meta_value'       => $url, // phpcs:ignore WordPress.DB.SlowDBQuery
				'fields'           => 'ids',
				'posts_per_page'   => 1,
				'suppress_filters' => true,
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
	 * @param bool   $reuse   Return a copy downloaded earlier instead of downloading again.
	 * @return int|WP_Error Attachment id.
	 */
	public static function sideload( $url, $post_id, $alt = '', $base = '', $job_id = '', $reuse = true ) {
		$url = self::absolutize( $url, $base );
		if ( '' === $url ) {
			return new WP_Error( 'migrateplus_media_url', __( 'Invalid image URL.', 'migrateplus' ) );
		}
		$existing = $reuse ? self::find_existing( $url ) : 0;
		if ( $existing ) {
			return $existing;
		}

		self::load_deps();
		$tmp = self::download( $url );
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
		update_post_meta( $id, '_migrateplus_source_url', $url );
		if ( $job_id ) {
			update_post_meta( $id, '_migrateplus_job_id', $job_id );
		}
		if ( '' !== (string) $alt ) {
			update_post_meta( $id, '_wp_attachment_image_alt', sanitize_text_field( $alt ) );
		}
		return (int) $id;
	}

	/**
	 * Replace an attachment's file with a downloaded one, keeping the attachment
	 * (its id, and the posts that use it). The new file takes the old one's name
	 * when the type is the same, so links to the full-size file keep working;
	 * thumbnails are regenerated and the old ones removed.
	 *
	 * @param int    $attachment_id Attachment.
	 * @param string $url           New file URL (absolute or relative to $base).
	 * @param string $base          Base URL for relative paths.
	 * @return true|WP_Error
	 */
	public static function replace_file( $attachment_id, $url, $base = '' ) {
		$url = self::absolutize( $url, $base );
		$old = (string) get_attached_file( $attachment_id );
		if ( '' === $url || '' === $old ) {
			return new WP_Error( 'migrateplus_media_replace', __( 'Nothing to replace the file with.', 'migrateplus' ) );
		}
		self::load_deps();
		$tmp = self::download( $url );
		if ( is_wp_error( $tmp ) ) {
			return $tmp;
		}
		$name  = sanitize_file_name( wp_basename( rawurldecode( (string) wp_parse_url( $url, PHP_URL_PATH ) ) ) );
		$check = wp_check_filetype_and_ext( $tmp, $name );
		if ( empty( $check['type'] ) ) {
			wp_delete_file( $tmp );
			return new WP_Error( 'migrateplus_media_replace', __( 'The imported file is not a type this site accepts.', 'migrateplus' ) );
		}

		// The new file goes over the unscaled original WordPress keeps for big images, or over the file itself.
		$dir    = dirname( $old );
		$meta   = wp_get_attachment_metadata( $attachment_id );
		$target = ! empty( $meta['original_image'] ) ? $dir . '/' . $meta['original_image'] : $old;
		$stale  = array();
		if ( $target !== $old ) {
			$stale[] = $old; // The "-scaled" copy; WordPress makes a new one if needed.
		}
		$ext = $check['ext'] ? $check['ext'] : pathinfo( $name, PATHINFO_EXTENSION );
		if ( strtolower( pathinfo( $target, PATHINFO_EXTENSION ) ) !== strtolower( $ext ) ) {
			// A different type can't keep the old name: save it beside it and drop the old file.
			$stale[] = $target;
			$target  = $dir . '/' . wp_unique_filename( $dir, pathinfo( $target, PATHINFO_FILENAME ) . '.' . $ext );
		}
		if ( ! @copy( $tmp, $target ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors
			wp_delete_file( $tmp );
			return new WP_Error( 'migrateplus_media_replace', __( 'Could not write the new file to the uploads folder.', 'migrateplus' ) );
		}
		wp_delete_file( $tmp );

		// Only now that the new file is in place: remove the old thumbnails and files it replaces.
		foreach ( isset( $meta['sizes'] ) ? (array) $meta['sizes'] : array() as $size ) {
			if ( ! empty( $size['file'] ) ) {
				$stale[] = $dir . '/' . $size['file'];
			}
		}
		foreach ( $stale as $file ) {
			if ( $file !== $target ) {
				wp_delete_file( $file );
			}
		}

		update_attached_file( $attachment_id, $target );
		wp_update_post(
			array(
				'ID'             => $attachment_id,
				'post_mime_type' => $check['type'],
			)
		);
		wp_update_attachment_metadata( $attachment_id, wp_generate_attachment_metadata( $attachment_id, $target ) );
		update_post_meta( $attachment_id, '_migrateplus_source_url', $url );
		return true;
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
		$ids  = array(); // Old attachment id => new, for the image block comments.

		$html = preg_replace_callback(
			'/<img\b[^>]*>/i',
			static function ( $m ) use ( $post_id, $base, $job_id, $home, &$errors, &$ids ) {
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
				if ( preg_match( '/\bwp-image-(\d+)\b/', $tag, $class ) ) {
					$ids[ $class[1] ] = $id;
				}
				$tag = preg_replace( '/\bwp-image-\d+\b/', 'wp-image-' . $id, $tag );
				return $tag;
			},
			$html
		);
		if ( ! $ids ) {
			return $html;
		}
		// The block comment before the <img> carries the id too ({"id":123}); the editor rejects a mismatch.
		return preg_replace_callback(
			'/(<!--\s+wp:image\s+\{[^>]*?"id":)(\d+)\b/',
			static function ( $m ) use ( $ids ) {
				return isset( $ids[ $m[2] ] ) ? $m[1] . $ids[ $m[2] ] : $m[0];
			},
			$html
		);
	}
}
