<?php
/**
 * Graph growth — the entity graph expands itself on publish.
 *
 * When a review goes live carrying an IMDb title id that no movie
 * entity claims yet, a draft Film Dossier is created for it, seeded
 * with the review's title and the id. Draft status is deliberate:
 * the editor remains the only publisher, and drafts never render on
 * the front end (the relational card path requires publish status).
 *
 * @package Lunara_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Lunara_Graph_Growth {

	public static function init() {
		if ( ! apply_filters( 'lunara_enable_entity_graph', true ) ) {
			return;
		}
		add_action( 'transition_post_status', array( __CLASS__, 'maybe_grow_on_publish' ), 10, 3 );
	}

	/**
	 * On first publish of a review, spawn its movie entity if unknown.
	 *
	 * @param string  $new_status New status.
	 * @param string  $old_status Old status.
	 * @param WP_Post $post       Post object.
	 */
	public static function maybe_grow_on_publish( $new_status, $old_status, $post ) {
		if ( 'publish' !== $new_status || 'publish' === $old_status ) {
			return;
		}
		if ( ! $post instanceof WP_Post || 'review' !== $post->post_type || ! post_type_exists( 'movie' ) ) {
			return;
		}

		$tt = Lunara_Debrief_Contract::normalize_imdb_title_id(
			get_post_meta( $post->ID, '_lunara_imdb_title_id', true )
		);
		if ( '' === $tt ) {
			return;
		}

		if ( ! class_exists( 'Lunara_Movie_Identity_Lock', false ) ) {
			require_once __DIR__ . '/class-lunara-movie-identity-lock.php';
		}

		$lock = Lunara_Movie_Identity_Lock::acquire( $tt );
		if ( false === $lock ) {
			return;
		}

		try {
			$existing = get_posts(
				array(
					'post_type'      => 'movie',
					'post_status'    => array_keys( get_post_stati() ),
					'meta_query'     => array(
						'relation' => 'OR',
						array(
							'key'   => 'imdb_title_id',
							'value' => $tt,
						),
						array(
							'key'   => '_lunara_entity_id',
							'value' => $tt,
						),
					),
					'fields'         => 'ids',
					'posts_per_page' => 1,
					'no_found_rows'  => true,
				)
			);
			if ( $existing ) {
				return;
			}

			$movie_id = wp_insert_post(
				array(
					'post_type'   => 'movie',
					'post_status' => 'draft',
					'post_title'  => sanitize_text_field( get_the_title( $post ) ),
					'meta_input'  => array( 'imdb_title_id' => $tt ),
				),
				true
			);
			if ( is_wp_error( $movie_id ) || ! $movie_id ) {
				return;
			}

			$stored_identity = Lunara_Debrief_Contract::normalize_imdb_title_id(
				get_post_meta( $movie_id, 'imdb_title_id', true )
			);
			if ( $tt !== $stored_identity ) {
				update_post_meta( $movie_id, 'imdb_title_id', $tt );
				$stored_identity = Lunara_Debrief_Contract::normalize_imdb_title_id(
					get_post_meta( $movie_id, 'imdb_title_id', true )
				);
			}
			if ( $tt !== $stored_identity ) {
				update_post_meta( $movie_id, '_lunara_entity_id', $tt );
				$stored_identity = Lunara_Debrief_Contract::normalize_imdb_title_id(
					get_post_meta( $movie_id, '_lunara_entity_id', true )
				);
			}
			if ( $tt !== $stored_identity && self::force_identity_reservation( $movie_id, $tt ) ) {
				$stored_identity = $tt;
			}
			if ( $tt !== $stored_identity ) {
				// The draft is new and unusable without a queryable identity.
				// Roll it back, and surface the exceptional double failure.
				$deleted = wp_delete_post( $movie_id, true );
				if ( false === $deleted || null === $deleted ) {
					do_action( 'lunara_graph_growth_identity_rollback_failed', $movie_id, $tt, (int) $post->ID );
				}
				return;
			}

			update_post_meta( $movie_id, '_lunara_auto_grown_from', (int) $post->ID );
		} finally {
			Lunara_Movie_Identity_Lock::release( $lock );
		}
	}

	/**
	 * Reserve identity directly when normal post-meta APIs are filtered.
	 *
	 * This is a last recovery path for a brand-new draft. It uses the legacy
	 * identity key already included in every duplicate lookup.
	 *
	 * @param int    $movie_id New Movie draft ID.
	 * @param string $imdb_id  Canonical IMDb title ID.
	 * @return bool
	 */
	private static function force_identity_reservation( $movie_id, $imdb_id ) {
		global $wpdb;

		if (
			! is_object( $wpdb )
			|| empty( $wpdb->postmeta )
			|| ! is_callable( array( $wpdb, 'insert' ) )
		) {
			return false;
		}

		$inserted = $wpdb->insert(
			$wpdb->postmeta,
			array(
				'post_id'    => (int) $movie_id,
				'meta_key'   => '_lunara_entity_id',
				'meta_value' => $imdb_id,
			),
			array( '%d', '%s', '%s' )
		);
		if ( 1 !== (int) $inserted ) {
			return false;
		}

		if ( function_exists( 'wp_cache_delete' ) ) {
			wp_cache_delete( (int) $movie_id, 'post_meta' );
		}

		return $imdb_id === Lunara_Debrief_Contract::normalize_imdb_title_id(
			get_post_meta( $movie_id, '_lunara_entity_id', true )
		);
	}
}
