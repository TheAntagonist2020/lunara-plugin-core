<?php
/**
 * Review Library admin workspace.
 *
 * @package Lunara_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Lunara_Review_Admin {

	/** Register Review-only admin hooks. */
	public static function init() {
		add_filter( 'manage_review_posts_columns', array( __CLASS__, 'columns' ) );
		add_action( 'manage_review_posts_custom_column', array( __CLASS__, 'column_content' ), 10, 2 );
		add_filter( 'manage_edit-review_sortable_columns', array( __CLASS__, 'sortable_columns' ) );
		add_action( 'restrict_manage_posts', array( __CLASS__, 'filters' ), 10, 2 );
		add_action( 'pre_get_posts', array( __CLASS__, 'apply_admin_query' ) );
		add_filter( 'enter_title_here', array( __CLASS__, 'title_placeholder' ), 10, 2 );
		add_filter( 'post_updated_messages', array( __CLASS__, 'updated_messages' ) );
		add_action( 'admin_head-edit.php', array( __CLASS__, 'list_styles' ) );
	}

	/**
	 * Replace the generic post table with a calm editorial inventory.
	 *
	 * @param array<string,string> $columns Existing columns.
	 * @return array<string,string>
	 */
	public static function columns( $columns ) {
		return array(
			'cb'                         => isset( $columns['cb'] ) ? $columns['cb'] : '<input type="checkbox">',
			'lunara_review_poster'       => __( 'Poster', 'lunara-core' ),
			'title'                      => __( 'Review', 'lunara-core' ),
			'lunara_review_identity'     => __( 'Film Identity', 'lunara-core' ),
			'lunara_review_score'        => __( 'Score', 'lunara-core' ),
			'lunara_review_debrief'      => __( 'Debrief', 'lunara-core' ),
			'taxonomy-lunara_director'   => __( 'Director', 'lunara-core' ),
			'taxonomy-lunara_review_year'=> __( 'Year', 'lunara-core' ),
			'date'                       => isset( $columns['date'] ) ? $columns['date'] : __( 'Date', 'lunara-core' ),
		);
	}

	/**
	 * Render one editorial inventory cell.
	 *
	 * @param string $column Column key.
	 * @param int    $post_id Review ID.
	 */
	public static function column_content( $column, $post_id ) {
		if ( 'lunara_review_poster' === $column ) {
			$image = get_the_post_thumbnail( $post_id, array( 48, 72 ), array( 'loading' => 'lazy' ) );
			echo $image ? wp_kses_post( $image ) : '<span class="lunara-review-no-poster" aria-label="' . esc_attr__( 'No poster', 'lunara-core' ) . '">&mdash;</span>';
			return;
		}

		if ( 'lunara_review_identity' === $column ) {
			$year     = trim( (string) get_post_meta( $post_id, '_lunara_year', true ) );
			$imdb_id  = self::normalize_imdb_id( get_post_meta( $post_id, '_lunara_imdb_title_id', true ) );
			$movie_id = absint( get_post_meta( $post_id, '_lunara_review_movie_id', true ) );
			if ( '' !== $year ) {
				echo '<strong>' . esc_html( $year ) . '</strong><br>';
			}
			if ( '' !== $imdb_id ) {
				echo '<a href="' . esc_url( 'https://www.imdb.com/title/' . rawurlencode( $imdb_id ) . '/' ) . '" target="_blank" rel="noopener noreferrer">' . esc_html( $imdb_id ) . '</a>';
			} else {
				echo '<span class="lunara-review-missing">' . esc_html__( 'IMDb ID needed', 'lunara-core' ) . '</span>';
			}
			if ( $movie_id && 'movie' === get_post_type( $movie_id ) ) {
				echo '<br><a href="' . esc_url( get_edit_post_link( $movie_id, 'raw' ) ) . '">' . esc_html__( 'Open Film Dossier', 'lunara-core' ) . '</a>';
			}
			return;
		}

		if ( 'lunara_review_score' === $column ) {
			$score = trim( (string) get_post_meta( $post_id, '_lunara_score', true ) );
			echo '' !== $score ? '<strong>' . esc_html( $score ) . '/5</strong>' : '&mdash;';
			return;
		}

		if ( 'lunara_review_debrief' === $column ) {
			$status = sanitize_key( (string) get_post_meta( $post_id, 'debrief_status', true ) );
			if ( ! in_array( $status, array( 'incomplete', 'ready', 'published' ), true ) ) {
				$status = 'incomplete';
			}
			$labels = array(
				'incomplete' => __( 'Incomplete', 'lunara-core' ),
				'ready'      => __( 'Ready', 'lunara-core' ),
				'published'  => __( 'Published', 'lunara-core' ),
			);
			echo '<span class="lunara-review-debrief-status is-' . esc_attr( $status ) . '">' . esc_html( $labels[ $status ] ) . '</span>';
		}
	}

	/** @param array<string,string> $columns Sortable columns. @return array<string,string> */
	public static function sortable_columns( $columns ) {
		$columns['lunara_review_score'] = 'lunara_review_score';
		$columns['taxonomy-lunara_review_year'] = 'lunara_review_year';
		return $columns;
	}

	/**
	 * Add useful Review Library filters without adding a second management UI.
	 *
	 * @param string $post_type Current post type.
	 * @param string $which Table position.
	 */
	public static function filters( $post_type, $which ) {
		if ( 'review' !== $post_type || 'top' !== $which ) {
			return;
		}

		foreach ( array( 'lunara_director', 'lunara_review_year' ) as $taxonomy ) {
			$object = get_taxonomy( $taxonomy );
			if ( ! $object ) {
				continue;
			}
			wp_dropdown_categories(
				array(
					'show_option_all' => sprintf( __( 'All %s', 'lunara-core' ), $object->labels->name ),
					'taxonomy'        => $taxonomy,
					'name'            => $taxonomy,
					'orderby'         => 'name',
					'selected'        => isset( $_GET[ $taxonomy ] ) ? sanitize_text_field( wp_unslash( $_GET[ $taxonomy ] ) ) : '',
					'hierarchical'    => false,
					'hide_empty'      => false,
					'value_field'     => 'slug',
				)
			);
		}

		$current = isset( $_GET['lunara_debrief_status'] ) ? sanitize_key( wp_unslash( $_GET['lunara_debrief_status'] ) ) : '';
		?>
		<label class="screen-reader-text" for="lunara-debrief-status-filter"><?php esc_html_e( 'Filter by Debrief readiness', 'lunara-core' ); ?></label>
		<select id="lunara-debrief-status-filter" name="lunara_debrief_status">
			<option value=""><?php esc_html_e( 'All Debrief statuses', 'lunara-core' ); ?></option>
			<option value="incomplete" <?php selected( $current, 'incomplete' ); ?>><?php esc_html_e( 'Debrief: Incomplete', 'lunara-core' ); ?></option>
			<option value="ready" <?php selected( $current, 'ready' ); ?>><?php esc_html_e( 'Debrief: Ready', 'lunara-core' ); ?></option>
			<option value="published" <?php selected( $current, 'published' ); ?>><?php esc_html_e( 'Debrief: Published', 'lunara-core' ); ?></option>
		</select>
		<?php
	}

	/**
	 * Apply Review-only sorting and Debrief filtering.
	 *
	 * @param WP_Query $query Query object.
	 */
	public static function apply_admin_query( $query ) {
		if ( ! is_admin() || ! $query->is_main_query() || 'review' !== $query->get( 'post_type' ) ) {
			return;
		}

		$orderby = $query->get( 'orderby' );
		if ( 'lunara_review_score' === $orderby ) {
			$query->set( 'meta_key', '_lunara_score' );
			$query->set( 'orderby', 'meta_value_num' );
		} elseif ( 'lunara_review_year' === $orderby ) {
			$query->set( 'meta_key', '_lunara_year' );
			$query->set( 'orderby', 'meta_value_num' );
		}

		$status = isset( $_GET['lunara_debrief_status'] ) ? sanitize_key( wp_unslash( $_GET['lunara_debrief_status'] ) ) : '';
		if ( in_array( $status, array( 'ready', 'published' ), true ) ) {
			$meta_query   = (array) $query->get( 'meta_query' );
			$meta_query[] = array( 'key' => 'debrief_status', 'value' => $status );
			$query->set( 'meta_query', $meta_query );
		} elseif ( 'incomplete' === $status ) {
			$meta_query   = (array) $query->get( 'meta_query' );
			$meta_query[] = array(
				'relation' => 'OR',
				array( 'key' => 'debrief_status', 'value' => 'incomplete' ),
				array( 'key' => 'debrief_status', 'compare' => 'NOT EXISTS' ),
				array( 'key' => 'debrief_status', 'value' => '' ),
			);
			$query->set( 'meta_query', $meta_query );
		}
	}

	/** @param string $placeholder Current placeholder. @param WP_Post $post Current post. @return string */
	public static function title_placeholder( $placeholder, $post ) {
		return $post && 'review' === $post->post_type
			? __( 'Film title — review headline', 'lunara-core' )
			: $placeholder;
	}

	/** @param array<int,array<string,string>> $messages Messages. @return array<int,array<string,string>> */
	public static function updated_messages( $messages ) {
		if ( isset( $messages['post'] ) ) {
			$messages['review'] = $messages['post'];
			$messages['review'][1] = __( 'Review updated.', 'lunara-core' );
			$messages['review'][6] = __( 'Review published.', 'lunara-core' );
			$messages['review'][7] = __( 'Review saved.', 'lunara-core' );
			$messages['review'][10] = __( 'Review draft updated.', 'lunara-core' );
		}
		return $messages;
	}

	/** Add narrow, Review-list-only visual polish. */
	public static function list_styles() {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || 'edit-review' !== $screen->id ) {
			return;
		}
		?>
		<style>
			.column-lunara_review_poster{width:58px}.column-lunara_review_poster img{display:block;width:40px;height:60px;object-fit:cover;border-radius:3px}.column-lunara_review_score{width:65px}.column-lunara_review_debrief{width:105px}.column-lunara_review_identity{width:150px}.lunara-review-debrief-status{display:inline-block;padding:3px 7px;border-radius:999px;background:#e5e7eb;color:#374151;font-size:11px;font-weight:700}.lunara-review-debrief-status.is-ready{background:#fef3c7;color:#92400e}.lunara-review-debrief-status.is-published{background:#d1fae5;color:#065f46}.lunara-review-missing{color:#b32d2e}.lunara-review-no-poster{color:#8c8f94}
		</style>
		<?php
	}

	/** @param mixed $value Raw IMDb value. @return string */
	private static function normalize_imdb_id( $value ) {
		if ( preg_match( '/\b(tt\d{5,12})\b/i', (string) $value, $match ) ) {
			return strtolower( $match[1] );
		}
		return '';
	}
}
