<?php
/**
 * Review-owned Debrief Studio administration.
 *
 * @package Lunara_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class Lunara_Debrief_Studio {

    /**
     * Whether the structured Studio can replace the legacy text inputs.
     *
     * @return bool
     */
    public static function is_available() {
        return function_exists( 'acf_add_local_field_group' )
            && (bool) apply_filters( 'lunara_enable_entity_graph', true );
    }

    /**
     * Register admin-only Studio hooks.
     */
    public static function init() {
        add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
        add_action( 'acf/validate_save_post', array( __CLASS__, 'validate_submission' ) );
        add_action(
            'acf/render_field/key=' . Lunara_Debrief_Contract::FIELD_SOURCE_SUMMARY_KEY,
            array( __CLASS__, 'render_source_summary' )
        );
        add_action(
            'acf/render_field/key=' . Lunara_Debrief_Contract::FIELD_PREVIEW_KEY,
            array( __CLASS__, 'render_preview' )
        );
    }

    /**
     * Load Studio styling only on Review edit screens.
     *
     * @param string $hook Admin hook suffix.
     */
    public static function enqueue_assets( $hook ) {
        if ( ! in_array( $hook, array( 'post.php', 'post-new.php' ), true ) || ! function_exists( 'get_current_screen' ) ) {
            return;
        }

        $screen = get_current_screen();
        if ( ! $screen || 'review' !== $screen->post_type ) {
            return;
        }

        $path    = LUNARA_CORE_DIR . 'assets/css/lunara-debrief-studio.css';
        $version = file_exists( $path ) ? (string) filemtime( $path ) : LUNARA_CORE_VERSION;

        wp_enqueue_style(
            'lunara-core-debrief-studio',
            LUNARA_CORE_URL . 'assets/css/lunara-debrief-studio.css',
            array(),
            $version
        );
    }

    /**
     * Enforce the exactly-three contract only when an editor marks Debrief
     * ready or published. Incomplete work remains saveable.
     */
    public static function validate_submission() {
        if ( ! self::is_available() || ! function_exists( 'acf_add_validation_error' ) || empty( $_POST['acf'] ) || ! is_array( $_POST['acf'] ) ) {
            return;
        }

        $post_id = isset( $_POST['post_ID'] ) ? absint( wp_unslash( $_POST['post_ID'] ) ) : 0;
        if ( ! $post_id || 'review' !== get_post_type( $post_id ) ) {
            return;
        }

        $acf    = wp_unslash( $_POST['acf'] );
        $status = sanitize_key( $acf[ Lunara_Debrief_Contract::FIELD_STATUS_KEY ] ?? Lunara_Debrief_Contract::STATUS_INCOMPLETE );
        $stored = Lunara_Debrief_Contract::record_from_review( $post_id );
        $source = $stored['reviewed_film'];

        if ( isset( $_POST['lunara_imdb_title_id'] ) ) {
            $submitted_imdb = Lunara_Debrief_Contract::normalize_imdb_title_id( wp_unslash( $_POST['lunara_imdb_title_id'] ) );
            if ( $submitted_imdb !== $source['imdb_title_id'] ) {
                $source['movie_id'] = 0;
            }
            $source['imdb_title_id'] = $submitted_imdb;
        }

        $pairings            = array();
        $invalid_movie_paths = array();
        foreach ( Lunara_Debrief_Contract::roles() as $role => $definition ) {
            $movie_id = absint( $acf[ $definition['movie_field_key'] ] ?? 0 );
            if ( $movie_id && ! self::is_published_movie( $movie_id ) ) {
                $invalid_movie_paths[ 'pairings.' . $role . '.film' ] = true;
                acf_add_validation_error(
                    'acf[' . $definition['movie_field_key'] . ']',
                    __( 'Choose a published film from the canonical Movie library.', 'lunara-core' )
                );
                $movie_id = 0;
            }

            $pairings[] = array(
                'role'             => $role,
                'film'             => $movie_id ? Lunara_Debrief_Contract::movie_reference( $movie_id ) : array(),
                'editorial_reason' => sanitize_textarea_field( $acf[ $definition['reason_field_key'] ] ?? '' ),
            );
        }

        $result = Lunara_Debrief_Contract::validate(
            array(
                'status'        => $status,
                'review_id'     => $post_id,
                'reviewed_film' => $source,
                'pairings'      => $pairings,
            )
        );

        foreach ( $result['errors'] as $issue ) {
            $is_replaced_missing_error = 'missing_companion_film' === ( $issue['code'] ?? '' )
                && isset( $invalid_movie_paths[ $issue['path'] ?? '' ] );
            if ( $is_replaced_missing_error ) {
                continue;
            }

            $field_key = self::field_key_for_issue( $issue );
            $selector  = '' !== $field_key ? 'acf[' . $field_key . ']' : '';
            acf_add_validation_error( $selector, $issue['message'] );
        }
    }

    /**
     * Confirm a submitted relationship points to a public canonical film.
     *
     * @param int $movie_id Submitted post ID.
     * @return bool
     */
    private static function is_published_movie( $movie_id ) {
        return $movie_id > 0
            && function_exists( 'get_post_type' )
            && function_exists( 'get_post_status' )
            && 'movie' === get_post_type( $movie_id )
            && 'publish' === get_post_status( $movie_id );
    }

    /**
     * Render the source-film summary on the Studio overview tab.
     *
     * @param array<string,mixed> $field ACF field definition.
     */
    public static function render_source_summary( $field ) {
        $review_id = self::current_review_id();
        if ( ! $review_id ) {
            echo '<div class="lunara-debrief-source is-pending">';
            echo '<strong>' . esc_html__( 'Source film pending', 'lunara-core' ) . '</strong>';
            echo '<span>' . esc_html__( 'Save the Review with its IMDb title ID to connect the canonical film entity.', 'lunara-core' ) . '</span>';
            echo '</div>';
            return;
        }

        $record = Lunara_Debrief_Contract::record_from_review( $review_id );
        $film   = $record['reviewed_film'];
        $title  = trim( (string) $film['title'] );
        $year   = trim( (string) $film['year'] );
        $imdb   = trim( (string) $film['imdb_title_id'] );

        echo '<div class="lunara-debrief-source' . ( $film['movie_id'] ? ' is-linked' : ' is-pending' ) . '">';
        echo '<span class="lunara-debrief-source-mark" aria-hidden="true">D</span>';
        echo '<div class="lunara-debrief-source-copy">';
        echo '<strong>' . esc_html( '' !== $title ? $title : __( 'Canonical film not linked yet', 'lunara-core' ) ) . '</strong>';
        echo '<span>';
        if ( '' !== $year ) {
            echo esc_html( $year ) . ' · ';
        }
        echo esc_html( '' !== $imdb ? $imdb : __( 'Add a valid IMDb title ID in Review metadata', 'lunara-core' ) );
        echo '</span>';
        echo '</div>';
        if ( $film['movie_id'] && function_exists( 'get_edit_post_link' ) ) {
            $edit_link = get_edit_post_link( $film['movie_id'] );
            if ( $edit_link ) {
                echo '<a class="button button-secondary" href="' . esc_url( $edit_link ) . '">' . esc_html__( 'Open Film Record', 'lunara-core' ) . '</a>';
            }
        }
        echo '</div>';
    }

    /**
     * Render the saved Debrief preview and readiness report.
     *
     * @param array<string,mixed> $field ACF field definition.
     */
    public static function render_preview( $field ) {
        $review_id = self::current_review_id();
        if ( ! $review_id ) {
            echo '<p class="description">' . esc_html__( 'Save the Review once to activate the Debrief preview.', 'lunara-core' ) . '</p>';
            return;
        }

        $record     = Lunara_Debrief_Contract::record_from_review( $review_id );
        $validation = Lunara_Debrief_Contract::validate( $record );
        $status     = $record['status'];

        echo '<div class="lunara-debrief-readiness">';
        echo '<div class="lunara-debrief-readiness-head">';
        echo '<div>';
        echo '<span class="lunara-debrief-eyebrow">' . esc_html__( 'Saved Preview', 'lunara-core' ) . '</span>';
        echo '<h3>' . esc_html__( 'What should I watch now?', 'lunara-core' ) . '</h3>';
        echo '</div>';
        echo '<span class="lunara-debrief-status is-' . esc_attr( $status ) . '">' . esc_html( ucfirst( $status ) ) . '</span>';
        echo '</div>';

        if ( $validation['complete'] ) {
            echo '<p class="lunara-debrief-readiness-note is-complete">' . esc_html__( 'All three companion films and editorial reasons are complete.', 'lunara-core' ) . '</p>';
        } else {
            $issues = array_merge( $validation['errors'], $validation['warnings'] );
            echo '<div class="lunara-debrief-readiness-note is-incomplete">';
            echo '<strong>' . esc_html__( 'Still needed before Ready:', 'lunara-core' ) . '</strong>';
            echo '<ul>';
            foreach ( $issues as $issue ) {
                echo '<li>' . esc_html( $issue['message'] ) . '</li>';
            }
            echo '</ul>';
            echo '</div>';
        }

        echo '<div class="lunara-debrief-preview-grid">';
        foreach ( $record['pairings'] as $pairing ) {
            self::render_pairing_card( $pairing );
        }
        echo '</div>';
        echo '<p class="description lunara-debrief-preview-caption">' . esc_html__( 'This preview reflects saved fields. Update the Review to refresh it.', 'lunara-core' ) . '</p>';
        echo '</div>';
    }

    /**
     * Render one local-data-only pairing card.
     *
     * @param array<string,mixed> $pairing Normalized pairing.
     */
    private static function render_pairing_card( $pairing ) {
        $roles      = Lunara_Debrief_Contract::roles();
        $role       = $pairing['role'];
        $film       = $pairing['film'];
        $movie_id   = absint( $film['movie_id'] ?? 0 );
        $title      = trim( (string) ( $film['title'] ?? '' ) );
        $year       = trim( (string) ( $film['year'] ?? '' ) );
        $reason     = trim( (string) $pairing['editorial_reason'] );
        $poster_id  = $movie_id ? absint( get_post_thumbnail_id( $movie_id ) ) : 0;
        $role_label = $roles[ $role ]['label'] ?? $role;

        echo '<article class="lunara-debrief-preview-card' . ( $movie_id ? ' has-film' : ' is-empty' ) . '">';
        echo '<div class="lunara-debrief-preview-media">';
        if ( $poster_id && function_exists( 'wp_get_attachment_image' ) ) {
            echo wp_get_attachment_image(
                $poster_id,
                'medium',
                false,
                array(
                    'loading'  => 'lazy',
                    'decoding' => 'async',
                    'alt'      => '' !== $title ? $title . ' poster' : '',
                )
            );
        } else {
            echo '<span aria-hidden="true">2:3</span>';
        }
        echo '</div>';
        echo '<div class="lunara-debrief-preview-copy">';
        echo '<span class="lunara-debrief-preview-role">' . esc_html( $role_label ) . '</span>';
        echo '<h4>' . esc_html( '' !== $title ? $title : __( 'Film not selected', 'lunara-core' ) ) . '</h4>';
        if ( '' !== $year ) {
            echo '<span class="lunara-debrief-preview-year">' . esc_html( $year ) . '</span>';
        }
        echo '<p>' . esc_html( '' !== $reason ? $reason : __( 'Editorial reason not written yet.', 'lunara-core' ) ) . '</p>';
        echo '</div>';
        echo '</article>';
    }

    /**
     * Map a validation issue to the most useful ACF field.
     *
     * @param array<string,string> $issue Validation issue.
     * @return string
     */
    private static function field_key_for_issue( $issue ) {
        $path = (string) ( $issue['path'] ?? '' );
        if ( 'status' === $path || 'reviewed_film' === $path || 0 === strpos( $path, 'pairings' ) && false === strpos( $path, '.' ) ) {
            return Lunara_Debrief_Contract::FIELD_STATUS_KEY;
        }

        foreach ( Lunara_Debrief_Contract::roles() as $role => $definition ) {
            if ( false === strpos( $path, 'pairings.' . $role ) ) {
                continue;
            }

            return false !== strpos( $path, 'editorial_reason' )
                ? $definition['reason_field_key']
                : $definition['movie_field_key'];
        }

        return Lunara_Debrief_Contract::FIELD_STATUS_KEY;
    }

    /**
     * Current Review ID across ACF render and validation contexts.
     *
     * @return int
     */
    private static function current_review_id() {
        if ( isset( $_POST['post_ID'] ) ) {
            return absint( wp_unslash( $_POST['post_ID'] ) );
        }
        if ( isset( $_GET['post'] ) ) {
            return absint( wp_unslash( $_GET['post'] ) );
        }

        global $post;
        return is_object( $post ) && isset( $post->ID ) ? absint( $post->ID ) : 0;
    }
}
