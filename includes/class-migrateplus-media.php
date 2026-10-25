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
	 * Block types whose attachment ids import_content_images() points at the
	 * copies it downloads; MigratePlus_Blocks leaves those ids alone.
	 *
	 * @var string[]
	 */
	const BLOCK_TYPES = array( 'image', 'cover', 'media-text', 'video', 'audio', 'file' );

	/**
	 * Per post, the old attachment ids import_content_images() replaced in its
	 * block markup, and the ids it put there, until MigratePlus_Blocks takes them.
	 *
	 * @var array[] post id => [ old id => new id ]
	 */
	protected static $block_ids = array();

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

		$file = array(
			'name'     => self::file_name( $url, $tmp ),
			'tmp_name' => $tmp,
		);
		// Tagged the moment the attachment exists, before its sizes are made: a request that dies
		// while resizing would otherwise leave an attachment rollback does not know about.
		$tag = static function ( $attachment_id ) use ( $job_id ) {
			update_post_meta( $attachment_id, '_migrateplus_job_id', $job_id );
		};
		if ( $job_id ) {
			add_action( 'add_attachment', $tag );
		}
		try {
			$id = media_handle_sideload( $file, $post_id );
		} finally {
			remove_action( 'add_attachment', $tag );
		}
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
	 * The name a downloaded file is saved under: the URL's file name, with the
	 * extension of the type the file turned out to be when the URL has none this
	 * site knows ("download?id=5", "photo.php").
	 *
	 * @param string $url URL it came from.
	 * @param string $tmp The downloaded file.
	 * @return string
	 */
	protected static function file_name( $url, $tmp ) {
		$name  = sanitize_file_name( wp_basename( rawurldecode( (string) wp_parse_url( $url, PHP_URL_PATH ) ) ) );
		$known = '' !== $name && false !== wp_check_filetype( $name )['ext'];
		$ext   = $known ? '' : self::extension_for( self::detect_mime( $tmp ) );
		if ( '' !== $ext ) {
			$name = ( $name ? pathinfo( $name, PATHINFO_FILENAME ) : 'image-' . md5( $url ) ) . '.' . $ext;
		}
		return '' === $name ? 'image-' . md5( $url ) . '.jpg' : $name;
	}

	/**
	 * A file's type from its content.
	 *
	 * @param string $file Path.
	 * @return string '' when it can't be told.
	 */
	protected static function detect_mime( $file ) {
		$mime = function_exists( 'wp_get_image_mime' ) ? (string) wp_get_image_mime( $file ) : '';
		if ( '' !== $mime ) {
			return $mime;
		}
		// wp_get_image_mime() reads binary image headers only, and finfo names SVG differently from
		// one libmagic to the next (image/svg, text/xml, text/plain): look for the tag.
		$head = (string) file_get_contents( $file, false, null, 0, 1024 ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		if ( preg_match( '/<svg[\s>]/i', $head ) ) {
			return 'image/svg+xml';
		}
		$mime = class_exists( 'finfo' ) ? (string) ( new finfo( FILEINFO_MIME_TYPE ) )->file( $file ) : '';
		return 'application/octet-stream' === $mime ? '' : $mime;
	}

	/**
	 * The usual file extension of a type.
	 *
	 * @param string $mime Type.
	 * @return string '' for an unknown type.
	 */
	protected static function extension_for( $mime ) {
		if ( '' === $mime ) {
			return '';
		}
		foreach ( wp_get_mime_types() as $exts => $type ) {
			if ( $type === $mime ) {
				return strtok( $exts, '|' );
			}
		}
		return 'image/svg+xml' === $mime ? 'svg' : ''; // Not in WordPress's list unless a plugin allows SVG.
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
		$name  = self::file_name( $url, $tmp );
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
	 * Import the images and files content shows from the old site, and point the
	 * content at the local copies: every <img> (its src, the link around it, the
	 * wp-image-N class), and the cover, video, audio and file blocks (a cover
	 * with only a background image, a video's or audio's src, a file's link),
	 * with the ids and URLs in the block comments of image, cover, media & text,
	 * video, audio and file blocks.
	 *
	 * Only files on the old site's host(s) or under a /wp-content/uploads/ path
	 * are downloaded: images hotlinked from elsewhere and tracking pixels are
	 * left as they are. The hosts can be changed with the
	 * migrateplus_content_image_hosts filter.
	 *
	 * @param string $html     Content.
	 * @param int    $post_id  Post.
	 * @param string $base     Base URL for relative paths.
	 * @param string $job_id   Job id.
	 * @param array  $errors   Collected error messages (by reference).
	 * @return string
	 */
	public static function import_content_images( $html, $post_id, $base, $job_id, &$errors = array() ) {
		$html = (string) $html;
		if ( false === stripos( $html, '<img' ) && ! preg_match( '/<!--\s+wp:(?:cover|video|audio|file)\s/', $html ) ) {
			return $html;
		}
		$job    = $job_id ? MigratePlus_Job::load( $job_id ) : null;
		$ctx    = array(
			'post_id' => $post_id,
			'base'    => $base,
			'job_id'  => $job_id,
			'job'     => $job,
			'home'    => untrailingslashit( preg_replace( '#^https?:#i', '', home_url() ) ),
			'hosts'   => self::content_image_hosts( $base, $job, $post_id ),
			'ids'     => array(), // Old attachment id => new, for the block comments.
			'urls'    => array(), // Old file URL => new, for links to the file and the URLs in block comments.
			'errors'  => array(),
		);
		$blocks = has_blocks( $html );

		$html = preg_replace_callback(
			'/<img\b[^>]*>/i',
			static function ( $m ) use ( &$ctx ) {
				$tag = $m[0];
				if ( ! preg_match( '/\ssrc\s*=\s*(["\'])(.*?)\1/i', $tag, $src ) ) {
					return $tag;
				}
				$old_id = preg_match( '/\bwp-image-(\d+)\b/', $tag, $class ) ? $class[1] : '';
				$alt    = preg_match( '/\salt\s*=\s*(["\'])(.*?)\1/i', $tag, $a ) ? html_entity_decode( $a[2], ENT_QUOTES, 'UTF-8' ) : '';
				$url    = self::absolutize( $src[2], $ctx['base'] );
				$id     = self::import_content_file( $url, $old_id, $alt, $ctx );
				if ( ! $id ) {
					return $tag;
				}
				$new = $ctx['urls'][ $url ];
				$tag = str_replace( $src[0], ' src=' . $src[1] . esc_url( $new ) . $src[1], $tag );
				// Old responsive sources would still point at the old site.
				$tag = preg_replace( '/\s(srcset|sizes)\s*=\s*(["\']).*?\2/i', '', $tag );
				// Update block image class (wp-image-123) so the editor links it.
				return preg_replace( '/\bwp-image-\d+\b/', 'wp-image-' . $id, $tag );
			},
			$html
		);

		// Files the blocks name outside an <img>: a cover's background and a file's link (in the
		// block comment), a video's or audio's src (in its HTML; neither block has inner blocks).
		$named = array();
		preg_match_all( '/<!--\s+wp:(cover|file)\s+(\{.*?\})\s+\/?-->/s', $html, $found, PREG_SET_ORDER );
		foreach ( $found as $block ) {
			$attrs   = json_decode( $block[2], true );
			$key     = 'cover' === $block[1] ? 'url' : 'href';
			$named[] = is_array( $attrs ) && isset( $attrs[ $key ] ) && is_string( $attrs[ $key ] ) ? array( $attrs[ $key ], $attrs ) : null;
		}
		preg_match_all( '/<!--\s+wp:(video|audio)(?:\s+(\{.*?\}))?\s+-->(.*?)<!--\s+\/wp:\1\s+-->/s', $html, $found, PREG_SET_ORDER );
		foreach ( $found as $block ) {
			$attrs   = '' !== $block[2] ? json_decode( $block[2], true ) : array();
			$named[] = preg_match( '/<(?:video|audio)\b[^>]*?\ssrc\s*=\s*(["\'])(.*?)\1/i', $block[3], $src ) ? array( html_entity_decode( $src[2], ENT_QUOTES, 'UTF-8' ), is_array( $attrs ) ? $attrs : array() ) : null;
		}
		foreach ( array_filter( $named ) as $one ) {
			list( $file, $attrs ) = $one;
			$url                  = self::absolutize( $file, $ctx['base'] );
			$old_id               = isset( $attrs['id'] ) && is_numeric( $attrs['id'] ) ? (string) (int) $attrs['id'] : '';
			if ( '' === $url ) {
				continue;
			}
			if ( ! isset( $ctx['urls'][ $url ] ) ) {
				self::import_content_file( $url, $old_id, '', $ctx );
			} elseif ( '' !== $old_id && ! isset( $ctx['ids'][ $old_id ] ) ) {
				$id = self::find_existing( $url ); // The same file shown twice: the copy made for the first.
				if ( $id ) {
					$ctx['ids'][ $old_id ] = $id;
				}
			}
		}
		foreach ( $ctx['errors'] as $error ) {
			$errors[] = $error;
		}
		if ( $blocks && $post_id && $ctx['ids'] ) {
			self::$block_ids[ (int) $post_id ] = array_map( 'intval', $ctx['ids'] );
		}
		if ( ! $ctx['urls'] ) {
			return $html;
		}
		$urls = $ctx['urls'];
		$ids  = $ctx['ids'];
		$base = $ctx['base'];
		// A link around the image (to the file, or "Link to: Media file") would still open the old site's copy.
		$html = preg_replace_callback(
			'/(<a\b[^>]*?\shref\s*=\s*)(["\'])(.*?)\2/i',
			static function ( $m ) use ( $base, $urls ) {
				$href = self::absolutize( $m[3], $base );
				return isset( $urls[ $href ] ) ? $m[1] . $m[2] . esc_url( $urls[ $href ] ) . $m[2] : $m[0];
			},
			$html
		);
		// A video's, audio's or source's src, and a cover's background image.
		$html = preg_replace_callback(
			'/(<(?:video|audio|source)\b[^>]*?\ssrc\s*=\s*)(["\'])(.*?)\2/i',
			static function ( $m ) use ( $base, $urls ) {
				$src = self::absolutize( $m[3], $base );
				return isset( $urls[ $src ] ) ? $m[1] . $m[2] . esc_url( $urls[ $src ] ) . $m[2] : $m[0];
			},
			$html
		);
		$html = preg_replace_callback(
			'/(background-image\s*:\s*url\(\s*)(["\']?)([^"\')]+)\2(\s*\))/i',
			static function ( $m ) use ( $base, $urls ) {
				$src = self::absolutize( $m[3], $base );
				return isset( $urls[ $src ] ) ? $m[1] . $m[2] . esc_url( $urls[ $src ] ) . $m[2] . $m[4] : $m[0];
			},
			$html
		);
		// The block comments carry the id and URL too ({"id":123}, a cover's "url", a file's "href",
		// media & text's "mediaId" and "mediaLink"); the editor marks a block invalid when they no longer match its HTML.
		return preg_replace_callback(
			'/<!--\s+wp:(?:' . implode( '|', array_map( 'preg_quote', self::BLOCK_TYPES ) ) . ')\s+(\{.*?\})\s+\/?-->/s',
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
					'/"(url|href|textLinkHref|mediaLink)":"((?:[^"\\\\]|\\\\.)*)"/',
					static function ( $a ) use ( $base, $urls, $media ) {
						$new = '';
						if ( 'mediaLink' !== $a[1] ) {
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

	/**
	 * Import one file content shows, unless it is already here or not to be
	 * downloaded, and remember where the content's old URL and id now point.
	 *
	 * @param string $url    Absolute URL on the old site.
	 * @param string $old_id Old attachment id, or ''.
	 * @param string $alt    Alt text.
	 * @param array  $ctx    import_content_images() context (by reference: ids, urls and errors are added to).
	 * @return int The attachment, 0 when the content keeps the old URL.
	 */
	protected static function import_content_file( $url, $old_id, $alt, array &$ctx ) {
		// Skip files already hosted on this site, and hosts the import does not download from.
		if ( ! $url || 0 === stripos( preg_replace( '#^https?:#i', '', $url ), $ctx['home'] . '/' ) || ! self::may_download( $url, $ctx['hosts'] ) ) {
			return 0;
		}
		$id = self::find_imported( $url, $old_id, $ctx['job'] );
		if ( ! $id ) {
			$id = self::sideload( $url, $ctx['post_id'], $alt, $ctx['base'], $ctx['job_id'] );
		}
		if ( is_wp_error( $id ) ) {
			$ctx['errors'][] = $url . ': ' . $id->get_error_message();
			return 0;
		}
		$full = wp_get_attachment_url( $id );
		$new  = $full;
		// A size variant matched to its original: show the same size of the local copy.
		if ( get_post_meta( $id, '_migrateplus_source_url', true ) !== $url && preg_match( '/-(\d+)x(\d+)\.[a-z0-9]+$/i', $url, $size ) ) {
			$sized = wp_get_attachment_image_url( $id, array( (int) $size[1], (int) $size[2] ) );
			$new   = $sized ? $sized : $full;
		}
		foreach ( self::full_size_urls( $url ) as $old ) {
			$ctx['urls'][ $old ] = $full;
		}
		$ctx['urls'][ $url ] = $new;
		if ( '' !== $old_id ) {
			$ctx['ids'][ $old_id ] = (int) $id;
		}
		return (int) $id;
	}

	/**
	 * The hosts content files are downloaded from: the old site's.
	 *
	 * @param string               $base    Base URL of the records.
	 * @param MigratePlus_Job|null $job     The import.
	 * @param int                  $post_id Post.
	 * @return string[] Lowercase host names.
	 */
	protected static function content_image_hosts( $base, $job, $post_id ) {
		$sites = array( $base );
		foreach ( array( 'migrateplus_site', 'site_url', 'url' ) as $key ) {
			if ( $job && ! empty( $job->data['settings'][ $key ] ) && is_string( $job->data['settings'][ $key ] ) ) {
				$sites[] = $job->data['settings'][ $key ];
			}
		}
		$hosts = array();
		foreach ( $sites as $site ) {
			$host = strtolower( (string) wp_parse_url( (string) $site, PHP_URL_HOST ) );
			if ( '' !== $host ) {
				$hosts[] = $host;
			}
		}
		/**
		 * The hosts whose images and files in post content are downloaded. Files
		 * under /wp-content/uploads/ are downloaded from any host.
		 *
		 * @param string[]             $hosts   Host names, the old site's by default.
		 * @param int                  $post_id The post being imported.
		 * @param MigratePlus_Job|null $job     The import.
		 */
		return array_values( array_unique( array_map( 'strtolower', array_filter( (array) apply_filters( 'migrateplus_content_image_hosts', $hosts, $post_id, $job ), 'is_string' ) ) ) );
	}

	/**
	 * Whether a content file is one to download: on one of the hosts (with or
	 * without www.), or in a WordPress uploads folder.
	 *
	 * @param string   $url   URL.
	 * @param string[] $hosts From content_image_hosts().
	 * @return bool
	 */
	protected static function may_download( $url, array $hosts ) {
		if ( false !== strpos( (string) wp_parse_url( $url, PHP_URL_PATH ), '/wp-content/uploads/' ) ) {
			return true;
		}
		$bare = static function ( $host ) {
			return preg_replace( '/^www\./', '', strtolower( (string) $host ) );
		};
		return in_array( $bare( wp_parse_url( $url, PHP_URL_HOST ) ), array_map( $bare, $hosts ), true );
	}

	/**
	 * The old attachment ids import_content_images() replaced in a post's block
	 * markup, mapped to the ids it put there, handed over once: MigratePlus_Blocks
	 * takes them when it relinks the post, so it does not map those ids again.
	 *
	 * @param int $post_id Post.
	 * @return int[] old id => new id.
	 */
	public static function take_block_ids( $post_id ) {
		$ids = isset( self::$block_ids[ (int) $post_id ] ) ? self::$block_ids[ (int) $post_id ] : array();
		unset( self::$block_ids[ (int) $post_id ] );
		return $ids;
	}
}
