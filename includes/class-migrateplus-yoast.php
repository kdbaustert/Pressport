<?php
/**
 * Yoast SEO data in MigratePlus exports.
 *
 * @package MigratePlus
 */

defined( 'ABSPATH' ) || exit;

/**
 * Carries what Yoast SEO stores about a post (its _yoast_wpseo_* custom fields,
 * which are private and so left out of the plain custom fields), about a term
 * (the wpseo_taxonomy_meta option) and the plugin's own settings.
 *
 * A post's data travels under "yoast" with the prefix removed. The primary
 * category is a term id on the old site, so it travels as the term's
 * "Parent > Child" path and is looked up again on import. Social images travel
 * as URLs and are downloaded like featured images. Yoast's scores are left out:
 * it works them out again when the post is saved.
 */
class MigratePlus_Yoast {

	const META_PREFIX = '_yoast_wpseo_';

	/**
	 * Keys Yoast computes itself, not content to move.
	 *
	 * @var string[]
	 */
	const COMPUTED = array( 'linkdex', 'content_score', 'inclusive_language_score', 'estimated-reading-time-minutes', 'wordproof_timestamp', 'zapier_trigger_sent' );

	/**
	 * Settings options, and what is stripped out of them: tokens, keys and
	 * bookkeeping that belong to the old install.
	 *
	 * @var string[]
	 */
	const OPTIONS = array( 'wpseo', 'wpseo_titles', 'wpseo_social', 'wpseo-premium-redirects-base' );
	const SECRET  = '/token|api_key|oauth|license|secret|^version$|_version|first_activated|previous_version|dismiss|indexing|indexables|site_type|environment|_cache|_sent|_count$|myyoast|siteanalysis|ryte|wincher|semrush/i';

	/**
	 * Settings that hold an attachment id, and the setting with its URL. The id
	 * names another site's attachment, so the URL is downloaded and the id set
	 * to the copy. social-image-id-* / social-image-url-* pairs are matched by
	 * pattern (see image_pairs()).
	 *
	 * @var string[] id key => URL key
	 */
	const IMAGE_IDS = array(
		'company_logo_id'               => 'company_logo',
		'person_logo_id'                => 'person_logo',
		'og_default_image_id'           => 'og_default_image',
		'og_frontpage_image_id'         => 'og_frontpage_image',
		'open_graph_frontpage_image_id' => 'open_graph_frontpage_image',
	);

	/**
	 * Whether Yoast SEO is active.
	 *
	 * @return bool
	 */
	public static function active() {
		return defined( 'WPSEO_VERSION' );
	}

	/**
	 * A post's Yoast fields as portable data.
	 *
	 * @param WP_Post $post Post.
	 * @return array Empty when the post has none.
	 */
	public static function export_post( WP_Post $post ) {
		$out = array();
		foreach ( get_post_meta( $post->ID ) as $key => $values ) {
			if ( 0 !== strpos( $key, self::META_PREFIX ) || ! isset( $values[0] ) || '' === $values[0] ) {
				continue;
			}
			$name = substr( $key, strlen( self::META_PREFIX ) );
			if ( in_array( $name, self::COMPUTED, true ) || preg_match( '/-image-id$/', $name ) ) {
				continue; // The image's URL travels; its id means nothing elsewhere.
			}
			if ( 0 === strpos( $name, 'primary_' ) ) {
				$taxonomy = substr( $name, 8 );
				$term     = (int) $values[0] ? get_term( (int) $values[0], $taxonomy ) : null;
				if ( $term instanceof WP_Term ) {
					$out['primary'][ $taxonomy ] = MigratePlus_Exporter::term_path( $term, is_taxonomy_hierarchical( $taxonomy ) );
				}
				continue;
			}
			$out[ $name ] = maybe_unserialize( $values[0] );
		}
		return $out;
	}

	/**
	 * Save a post's Yoast fields from an export. Works without Yoast too: the
	 * fields wait in the post's meta for when it is activated.
	 *
	 * @param int             $post_id  Post id.
	 * @param array           $seo      Exported data.
	 * @param MigratePlus_Job $job      Job.
	 * @param array           $opts     Job options.
	 * @param string[]        $warnings Warnings (by reference).
	 * @return int Fields saved.
	 */
	public static function import_post( $post_id, array $seo, MigratePlus_Job $job, array $opts, array &$warnings ) {
		$base  = isset( $job->data['settings']['migrateplus_site'] ) ? (string) $job->data['settings']['migrateplus_site'] : '';
		$saved = 0;
		foreach ( $seo as $name => $value ) {
			$name = (string) $name;
			if ( 'primary' === $name ) {
				foreach ( is_array( $value ) ? $value : array() as $taxonomy => $path ) {
					$taxonomy = sanitize_key( $taxonomy );
					$term_id  = taxonomy_exists( $taxonomy ) ? MigratePlus_Terms::find_term(
						array(
							'taxonomy' => $taxonomy,
							'path'     => MigratePlus_Util::stringify( $path ),
						),
						''
					) : 0;
					if ( $term_id ) {
						update_post_meta( $post_id, self::META_PREFIX . 'primary_' . $taxonomy, $term_id );
						++$saved;
					} else {
						/* translators: %s: term path */
						$warnings[] = sprintf( __( 'Yoast primary term "%s" is not on this site.', 'migrateplus' ), MigratePlus_Util::stringify( $path ) );
					}
				}
				continue;
			}
			// An image id names the old site's attachment: the URL beside it is what travels.
			if ( ! preg_match( '/^[a-z0-9_-]+$/i', $name ) || in_array( $name, self::COMPUTED, true ) || preg_match( '/-image-id$/', $name ) ) {
				continue;
			}
			if ( preg_match( '/-image$/', $name ) && is_string( $value ) && '' !== $value && ! empty( $opts['import_featured_image'] ) ) {
				// A social image: downloaded like a featured image, so the post does not depend on the old site.
				$id = MigratePlus_Media::sideload( $value, $post_id, '', $base, $job->data['id'] );
				if ( is_wp_error( $id ) ) {
					/* translators: 1: field, 2: error */
					$warnings[] = sprintf( __( 'Yoast %1$s image: %2$s', 'migrateplus' ), $name, $id->get_error_message() );
				} else {
					$value = (string) wp_get_attachment_url( $id );
					update_post_meta( $post_id, self::META_PREFIX . $name . '-id', (int) $id );
				}
			}
			if ( in_array( $name, array( 'canonical', 'redirect' ), true ) && is_string( $value ) && ! empty( $opts['rewrite_links'] ) ) {
				$value = MigratePlus_Menus::rewrite_url( $value, $base ); // A canonical left on the old site would hand it the post's ranking.
			}
			update_post_meta( $post_id, self::META_PREFIX . $name, wp_slash( $value ) );
			++$saved;
		}
		return $saved;
	}

	/**
	 * A term's Yoast fields (title, description, focus keyphrase, canonical...).
	 *
	 * @param WP_Term $term Term.
	 * @return array Empty when the term has none.
	 */
	public static function export_term( WP_Term $term ) {
		$all  = (array) get_option( 'wpseo_taxonomy_meta', array() );
		$data = isset( $all[ $term->taxonomy ][ $term->term_id ] ) ? $all[ $term->taxonomy ][ $term->term_id ] : array();
		$out  = array();
		foreach ( is_array( $data ) ? $data : array() as $key => $value ) {
			if ( is_scalar( $value ) && '' !== (string) $value ) {
				$out[ (string) $key ] = $value;
			}
		}
		return $out;
	}

	/**
	 * Save a term's Yoast fields, keeping the ones the export does not carry.
	 * Social images are handled as a post's are: the URL is downloaded (when
	 * the import downloads featured images) and the id set to the copy; the
	 * old site's id is never saved.
	 *
	 * @param int                  $term_id  Term id.
	 * @param string               $taxonomy Taxonomy.
	 * @param array                $data     Exported fields.
	 * @param MigratePlus_Job|null $job      Job: without it, images and links are saved as they are.
	 * @param array                $opts     Job options.
	 * @param string[]             $warnings Warnings (by reference).
	 */
	public static function import_term( $term_id, $taxonomy, array $data, $job = null, array $opts = array(), array &$warnings = array() ) {
		$base   = $job && isset( $job->data['settings']['migrateplus_site'] ) ? (string) $job->data['settings']['migrateplus_site'] : '';
		$values = array();
		foreach ( $data as $key => $value ) {
			$key = (string) $key;
			if ( ! preg_match( '/^wpseo_[a-z0-9_-]+$/i', $key ) || ! is_scalar( $value ) || preg_match( '/-image-id$/', $key ) ) {
				continue;
			}
			$value = (string) $value;
			if ( $job && preg_match( '/-image$/', $key ) && '' !== $value && ! empty( $opts['import_featured_image'] ) ) {
				$id = MigratePlus_Media::sideload( $value, 0, '', $base, $job->data['id'] );
				if ( is_wp_error( $id ) ) {
					/* translators: 1: field, 2: error */
					$warnings[] = sprintf( __( 'Yoast %1$s image: %2$s', 'migrateplus' ), $key, $id->get_error_message() );
				} else {
					$value                  = (string) wp_get_attachment_url( $id );
					$values[ $key . '-id' ] = (string) $id;
				}
			}
			if ( $job && 'wpseo_canonical' === $key && ! empty( $opts['rewrite_links'] ) ) {
				$value = MigratePlus_Menus::rewrite_url( $value, $base );
			}
			$values[ $key ] = $value;
		}
		if ( ! $values ) {
			return;
		}
		if ( class_exists( 'WPSEO_Taxonomy_Meta' ) && method_exists( 'WPSEO_Taxonomy_Meta', 'set_values' ) ) {
			// set_values() keeps only the fields it is given: start from the ones the term has.
			$current = method_exists( 'WPSEO_Taxonomy_Meta', 'get_term_meta' ) ? WPSEO_Taxonomy_Meta::get_term_meta( (int) $term_id, $taxonomy ) : array();
			WPSEO_Taxonomy_Meta::set_values( $term_id, $taxonomy, array_merge( is_array( $current ) ? $current : array(), $values ) );
			return;
		}
		$all = (array) get_option( 'wpseo_taxonomy_meta', array() );
		if ( ! isset( $all[ $taxonomy ] ) || ! is_array( $all[ $taxonomy ] ) ) {
			$all[ $taxonomy ] = array();
		}
		$all[ $taxonomy ][ $term_id ] = array_merge( isset( $all[ $taxonomy ][ $term_id ] ) ? (array) $all[ $taxonomy ][ $term_id ] : array(), $values );
		update_option( 'wpseo_taxonomy_meta', $all );
	}

	/**
	 * Yoast's settings, without the tokens and bookkeeping of the old install.
	 *
	 * @return array option name => values.
	 */
	public static function export_settings() {
		$out = array();
		foreach ( self::OPTIONS as $name ) {
			$value = get_option( $name );
			if ( ! is_array( $value ) ) {
				continue;
			}
			foreach ( array_keys( $value ) as $key ) {
				if ( preg_match( self::SECRET, (string) $key ) ) {
					unset( $value[ $key ] );
				}
			}
			$out[ $name ] = $value;
		}
		return $out;
	}

	/**
	 * Apply exported settings over this site's: keys the export carries are set,
	 * the rest (tokens, licences) stay as they are here. Ids of the old site are
	 * pointed at this site's copies (logos, social images), or left out (the
	 * person the site represents, an image that does not download).
	 *
	 * @param array                $settings From export_settings().
	 * @param array                $prev     Previous values, for rollback (by reference).
	 * @param MigratePlus_Job|null $job      Job, to download images into.
	 * @param string[]             $warnings Warnings (by reference).
	 * @return int Options changed.
	 */
	public static function import_settings( array $settings, array &$prev, $job = null, array &$warnings = array() ) {
		$n = 0;
		foreach ( $settings as $name => $value ) {
			if ( ! in_array( $name, self::OPTIONS, true ) || ! is_array( $value ) ) {
				continue;
			}
			foreach ( array_keys( $value ) as $key ) {
				if ( preg_match( self::SECRET, (string) $key ) ) {
					unset( $value[ $key ] );
				}
			}
			$value   = self::localize_settings( $value, $job, $warnings );
			$current = get_option( $name );
			if ( ! array_key_exists( $name, $prev ) ) {
				$prev[ $name ] = false === $current ? null : $current;
			}
			update_option( $name, array_merge( is_array( $current ) ? $current : array(), $value ) );
			++$n;
		}
		return $n;
	}

	/**
	 * Settings with the old site's ids replaced: each image downloaded and its id
	 * set to the copy. What cannot be matched (the represented person, an image
	 * that does not download) is left out, so this site's value stays.
	 *
	 * @param array                $value    One option's settings.
	 * @param MigratePlus_Job|null $job      Job, or null (then every id is left out).
	 * @param string[]             $warnings Warnings (by reference).
	 * @return array
	 */
	protected static function localize_settings( array $value, $job, array &$warnings ) {
		$base = $job && isset( $job->data['settings']['migrateplus_site'] ) ? (string) $job->data['settings']['migrateplus_site'] : '';
		foreach ( self::image_pairs( $value ) as $id_key => $url_key ) {
			$url = isset( $value[ $url_key ] ) && is_string( $value[ $url_key ] ) ? trim( $value[ $url_key ] ) : '';
			unset( $value[ $id_key ] );
			// The logos' cached sizes and URLs ("company_logo_meta") describe the old file too.
			unset( $value[ $url_key . '_meta' ] );
			if ( '' === $url ) {
				continue;
			}
			$id = $job ? MigratePlus_Media::sideload( $url, 0, '', $base, $job->data['id'] ) : new WP_Error( 'migrateplus_yoast_image', __( 'not downloaded without an import to add it to.', 'migrateplus' ) );
			if ( is_wp_error( $id ) ) {
				unset( $value[ $url_key ] );
				/* translators: 1: setting, 2: error */
				$warnings[] = sprintf( __( 'Yoast setting "%1$s" kept this site\'s value: %2$s', 'migrateplus' ), $url_key, $id->get_error_message() );
				continue;
			}
			$value[ $url_key ] = (string) wp_get_attachment_url( $id );
			$value[ $id_key ]  = (int) $id;
		}
		if ( ! empty( $value['company_or_person_user_id'] ) ) {
			// A user id of the old site: users are not imported, so it names nobody here, or someone else.
			$warnings[] = __( 'The person Yoast SEO says the site represents is a user of the old site, so this site keeps its choice.', 'migrateplus' );
		}
		unset( $value['company_or_person_user_id'] );
		return $value;
	}

	/**
	 * The image settings in one option's values, as id key => URL key.
	 *
	 * @param array $value Settings.
	 * @return string[]
	 */
	protected static function image_pairs( array $value ) {
		$pairs = array();
		$urls  = array_flip( self::IMAGE_IDS );
		foreach ( array_keys( $value ) as $key ) {
			$key = (string) $key;
			if ( isset( self::IMAGE_IDS[ $key ] ) ) {
				$pairs[ $key ] = self::IMAGE_IDS[ $key ];
			} elseif ( isset( $urls[ $key ] ) ) {
				$pairs[ $urls[ $key ] ] = $key;
			} elseif ( preg_match( '/^social-image-(id|url)-(.+)$/', $key, $m ) ) {
				$pairs[ 'social-image-id-' . $m[2] ] = 'social-image-url-' . $m[2];
			}
		}
		return $pairs;
	}
}
