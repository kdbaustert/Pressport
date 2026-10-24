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
		// Reset in finally: left on after an exception, the next request anything makes would get the local-host allowance.
		MigratePlus_Util::$in_request = true;
		try {
			$tmp = download_url( $url, max( 60, (int) MigratePlus_Settings::get( 'request_timeout' ) ) );
		} finally {
			MigratePlus_Util::$in_request = false;
			remove_filter( 'http_request_args', $limit );
		}

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
			// Slashed: update_post_meta() unslashes, and "C:\Photos" would lose its backslash.
			update_post_meta( $id, '_wp_attachment_image_alt', wp_slash( sanitize_text_field( $alt ) ) );
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
		$scaled = '';
		if ( $target !== $old ) {
			$stale[] = $old; // The "-scaled" copy; WordPress makes a new one if needed.
			$scaled  = $old;
		}
		$ext = $check['ext'] ? $check['ext'] : pathinfo( $name, PATHINFO_EXTENSION );
		if ( strtolower( pathinfo( $target, PATHINFO_EXTENSION ) ) !== strtolower( $ext ) ) {
			// A different type can't keep the old name: save it beside it and drop the old file.
			$stale[] = $target;
			$target  = $dir . '/' . wp_unique_filename( $dir, pathinfo( $target, PATHINFO_FILENAME ) . '.' . $ext );
			$scaled  = ''; // Nor the old "-scaled" name.
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
		$new_meta = wp_generate_attachment_metadata( $attachment_id, $target );
		if ( '' !== $scaled && ! file_exists( $scaled ) && @copy( $target, $scaled ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors
			// The old image was scaled down and links point at its "-scaled" file, but the new one is under
			// the size threshold, so WordPress made none. A copy under that name keeps the links working,
			// recorded as WordPress records its own scaled copy (the file, with original_image naming the
			// one it came from), so deleting the attachment deletes both.
			update_attached_file( $attachment_id, $scaled );
			$new_meta['file']           = _wp_relative_upload_path( $scaled );
			$new_meta['original_image'] = wp_basename( $target );
		}
		wp_update_attachment_metadata( $attachment_id, $new_meta );
		update_post_meta( $attachment_id, '_migrateplus_source_url', $url );
		return true;
	}

	/**
	 * The attachment an earlier record of this import brought an image in as: by the
	 * old attachment id in its wp-image-N class, then by its URL, then by the URL of
	 * the full-size file a size variant (photo-1024x683.jpg, photo-scaled.jpg) was
	 * made from, so a variant is not downloaded again as an image of its own.
	 *
	 * @param string               $url    Image URL on the old site.
	 * @param string               $old_id Old attachment id, or ''.
	 * @param MigratePlus_Job|null $job    The import.
	 * @return int 0 when none.
	 */
	protected static function find_imported( $url, $old_id, $job ) {
		if ( '' !== $old_id && $job ) {
			$id = MigratePlus_Importer::find_by_uid( MigratePlus_Importer::uid( $job, $old_id ), 'attachment' );
			if ( $id ) {
				return $id;
			}
		}
		foreach ( array_unique( array_merge( array( $url ), self::full_size_urls( $url ) ) ) as $candidate ) {
			$id = self::find_existing( $candidate );
			if ( $id ) {
				return $id;
			}
		}
		return 0;
	}

	/**
	 * Where the full-size file of a size variant may be: the URL without "-WxH" or
	 * "-scaled", and that with "-scaled" (what an export of a big image carries).
	 *
	 * @param string $url Image URL.
	 * @return string[]
	 */
	protected static function full_size_urls( $url ) {
		$plain = preg_replace( '/-(?:\d+x\d+|scaled)(\.[a-z0-9]+)$/i', '$1', $url );
		return array_unique( array( $plain, preg_replace( '/(\.[a-z0-9]+)$/i', '-scaled$1', $plain ) ) );
	}

	/**
	 * Import every external <img> in HTML and rewrite src to the local copy, along
	 * with links to the old file and the ids and URLs in the image, cover and
	 * media & text block comments.
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
		$ids  = array(); // Old attachment id => new, for the block comments.
		$urls = array(); // Old file URL => new, for links to the image and the cover block's url.
		$job  = $job_id ? MigratePlus_Job::load( $job_id ) : null;

		$html = preg_replace_callback(
			'/<img\b[^>]*>/i',
			static function ( $m ) use ( $post_id, $base, $job_id, $home, $job, &$errors, &$ids, &$urls ) {
				$tag = $m[0];
				if ( ! preg_match( '/\ssrc\s*=\s*(["\'])(.*?)\1/i', $tag, $src ) ) {
					return $tag;
				}
				$url = self::absolutize( $src[2], $base );
				// Skip images already hosted on this site.
				if ( ! $url || 0 === stripos( preg_replace( '#^https?:#i', '', $url ), $home . '/' ) ) {
					return $tag;
				}
				$old_id = preg_match( '/\bwp-image-(\d+)\b/', $tag, $class ) ? $class[1] : '';
				$id     = self::find_imported( $url, $old_id, $job );
				if ( ! $id ) {
					$alt = preg_match( '/\salt\s*=\s*(["\'])(.*?)\1/i', $tag, $a ) ? html_entity_decode( $a[2], ENT_QUOTES, 'UTF-8' ) : '';
					$id  = self::sideload( $url, $post_id, $alt, $base, $job_id );
				}
				if ( is_wp_error( $id ) ) {
					$errors[] = $url . ': ' . $id->get_error_message();
					return $tag;
				}
				$full = wp_get_attachment_url( $id );
				$new  = $full;
				// A size variant matched to its original: show the same size of the local copy.
				if ( get_post_meta( $id, '_migrateplus_source_url', true ) !== $url && preg_match( '/-(\d+)x(\d+)\.[a-z0-9]+$/i', $url, $size ) ) {
					$sized = wp_get_attachment_image_url( $id, array( (int) $size[1], (int) $size[2] ) );
					$new   = $sized ? $sized : $full;
				}
				foreach ( self::full_size_urls( $url ) as $old ) {
					$urls[ $old ] = $full;
				}
				$urls[ $url ] = $new;
				$tag          = str_replace( $src[0], ' src=' . $src[1] . esc_url( $new ) . $src[1], $tag );
				// Old responsive sources would still point at the old site.
				$tag = preg_replace( '/\s(srcset|sizes)\s*=\s*(["\']).*?\2/i', '', $tag );
				// Update block image class (wp-image-123) so the editor links it.
				if ( '' !== $old_id ) {
					$ids[ $old_id ] = $id;
				}
				$tag = preg_replace( '/\bwp-image-\d+\b/', 'wp-image-' . $id, $tag );
				return $tag;
			},
			$html
		);
		if ( ! $urls ) {
			return $html;
		}
		// A link around the image (to the file, or "Link to: Media file") would still open the old site's copy.
		$html = preg_replace_callback(
			'/(<a\b[^>]*?\shref\s*=\s*)(["\'])(.*?)\2/i',
			static function ( $m ) use ( $base, $urls ) {
				$href = self::absolutize( $m[3], $base );
				return isset( $urls[ $href ] ) ? $m[1] . $m[2] . esc_url( $urls[ $href ] ) . $m[2] : $m[0];
			},
			$html
		);
		// The block comments carry the id and URL too ({"id":123}, a cover's "url", media & text's
		// "mediaId" and "mediaLink"); the editor marks a block invalid when they no longer match its HTML.
		return preg_replace_callback(
			'/<!--\s+wp:(?:image|cover|media-text)\s+(\{.*?\})\s+\/?-->/s',
			static function ( $m ) use ( $base, $ids, $urls ) {
				$attrs = preg_replace_callback(
					'/"(id|mediaId)":(\d+)\b/',
					static function ( $a ) use ( $ids ) {
						return isset( $ids[ $a[2] ] ) ? '"' . $a[1] . '":' . $ids[ $a[2] ] : $a[0];
					},
					$m[1]
				);
				$media = preg_match( '/"mediaId":(\d+)\b/', $m[1], $mid ) && isset( $ids[ $mid[1] ] ) ? $ids[ $mid[1] ] : 0;
				$attrs = preg_replace_callback(
					'/"(url|mediaLink)":"((?:[^"\\\\]|\\\\.)*)"/',
					static function ( $a ) use ( $base, $urls, $media ) {
						$new = '';
						if ( 'url' === $a[1] ) {
							$old = self::absolutize( (string) json_decode( '"' . $a[2] . '"' ), $base );
							$new = isset( $urls[ $old ] ) ? $urls[ $old ] : '';
						} elseif ( $media ) {
							$new = (string) get_attachment_link( $media );
						}
						return '' !== $new ? '"' . $a[1] . '":' . wp_json_encode( $new, JSON_UNESCAPED_SLASHES ) : $a[0];
					},
					$attrs
				);
				return str_replace( $m[1], $attrs, $m[0] );
			},
			$html
		);
	}
}
