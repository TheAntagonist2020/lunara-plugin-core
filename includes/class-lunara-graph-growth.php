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

		$tt = strtolower( trim( (string) get_post_meta( $post->ID, '_lunara_imdb_title_id', true ) ) );
		if ( ! preg_match( '/^tt\d{7,8}$/', $tt ) ) {
			return;
		}

		$existing = get_posts(
			array(
				'post_type'      => 'movie',
				'post_status'    => array( 'publish', 'draft', 'pending', 'future', 'private' ),
				'meta_key'       => 'imdb_title_id',
				'meta_value'     => $tt,
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
			),
			true
		);
		if ( is_wp_error( $movie_id ) || ! $movie_id ) {
			return;
		}

		update_post_meta( $movie_id, 'imdb_title_id', $tt );
		update_post_meta( $movie_id, '_lunara_auto_grown_from', (int) $post->ID );
	}
}
