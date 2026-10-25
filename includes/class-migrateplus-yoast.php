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
					$ids      = taxonomy_exists( $taxonomy ) ? MigratePlus_Importer::resolve_terms( $taxonomy, array( MigratePlus_Util::stringify( $path ) ), false ) : array();
					if ( $ids ) {
						update_post_meta( $post_id, self::META_PREFIX . 'primary_' . $taxonomy, (int) $ids[0] );
						++$saved;
					} else {
						/* translators: %s: term path */
						$warnings[] = sprintf( __( 'Yoast primary term "%s" is not on this site.', 'migrateplus' ), MigratePlus_Util::stringify( $path ) );
					}
				}
				continue;
			}
			if ( ! preg_match( '/^[a-z0-9_-]+$/i', $name ) || in_array( $name, self::COMPUTED, true ) ) {
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
	 *
	 * @param int    $term_id  Term id.
	 * @param string $taxonomy Taxonomy.
	 * @param array  $data     Exported fields.
	 */
	public static function import_term( $term_id, $taxonomy, array $data ) {
		$values = array();
		foreach ( $data as $key => $value ) {
			if ( preg_match( '/^wpseo_[a-z0-9_-]+$/i', (string) $key ) && is_scalar( $value ) ) {
				$values[ (string) $key ] = (string) $value;
			}
		}
		if ( ! $values ) {
			return;
		}
		if ( class_exists( 'WPSEO_Taxonomy_Meta' ) && method_exists( 'WPSEO_Taxonomy_Meta', 'set_values' ) ) {
			WPSEO_Taxonomy_Meta::set_values( $term_id, $taxonomy, $values );
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
	 * the rest (tokens, licences) stay as they are here.
	 *
	 * @param array $settings From export_settings().
	 * @param array $prev     Previous values, for rollback (by reference).
	 * @return int Options changed.
	 */
	public static function import_settings( array $settings, array &$prev ) {
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
			$current = get_option( $name );
			if ( ! array_key_exists( $name, $prev ) ) {
				$prev[ $name ] = false === $current ? null : $current;
			}
			update_option( $name, array_merge( is_array( $current ) ? $current : array(), $value ) );
			++$n;
		}
		return $n;
	}
}
