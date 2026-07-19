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

    const FIELD_GROUP_KEY = 'group_lunara_review_trinity';

    /**
     * Whether the structured Studio can replace the legacy text inputs.
     *
     * @return bool
     */
    public static function is_available() {
        return function_exists( 'acf_add_local_field_group' )
            && function_exists( 'post_type_exists' )
            && post_type_exists( 'movie' );
    }

    /**
     * Register admin-only Studio hooks.
     */
    public static function init() {
        add_action( 'acf/init', array( __CLASS__, 'register_field_group' ) );
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
     * Register the Review-owned Studio independently from the entity module.
     */
    public static function register_field_group() {
        if ( ! function_exists( 'acf_add_local_field_group' ) ) {
            return;
        }

        acf_add_local_field_group(
            array(
                'key'    => self::FIELD_GROUP_KEY,
                'title'  => 'Debrief Studio',
                'fields' => Lunara_Debrief_Contract::acf_fields(),
                'location' => array(
                    array(
                        array(
                            'param'    => 'post_type',
                            'operator' => '==',
                            'value'    => 'review',
                        ),
                    ),
                ),
                'position' => 'normal',
                'style'    => 'default',
            )
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
        $submitted_imdb = isset( $_POST['lunara_imdb_title_id'] )
            ? Lunara_Debrief_Contract::normalize_imdb_title_id( wp_unslash( $_POST['lunara_imdb_title_id'] ) )
            : '';
        $source = Lunara_Debrief_Contract::public_movie_reference_by_imdb( $submitted_imdb, $post_id );
        if ( ! $source['movie_id'] && '' !== $submitted_imdb ) {
            $source['imdb_title_id'] = $submitted_imdb;
        }

        $pairings = array();
        foreach ( Lunara_Debrief_Contract::roles() as $role => $definition ) {
            $movie_id = absint( $acf[ $definition['movie_field_key'] ] ?? 0 );
            $film     = $movie_id ? Lunara_Debrief_Contract::movie_reference( $movie_id ) : array();
            if ( $movie_id && empty( $film['movie_id'] ) ) {
                $film = array( 'movie_id' => $movie_id );
            }

            $pairings[] = array(
                'role'             => $role,
                'film'             => $film,
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
            $field_key = self::field_key_for_issue( $issue );
            $selector  = '' !== $field_key ? 'acf[' . $field_key . ']' : '';
            acf_add_validation_error( $selector, $issue['message'] );
        }
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
        $public = Lunara_Debrief_Contract::is_public_film_reference( $film );

        echo '<div class="lunara-debrief-source' . ( $public ? ' is-linked' : ' is-pending' ) . '">';
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
        if ( $public && function_exists( 'get_edit_post_link' ) ) {
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
        $uses_legacy_fallback = self::uses_legacy_pairing_fallback( $review_id );

        echo '<div class="lunara-debrief-readiness">';
        echo '<div class="lunara-debrief-readiness-head">';
        echo '<div>';
        echo '<span class="lunara-debrief-eyebrow">' . esc_html__( 'Saved Preview', 'lunara-core' ) . '</span>';
        echo '<h3>' . esc_html__( 'What should I watch now?', 'lunara-core' ) . '</h3>';
        echo '</div>';
        echo '<span class="lunara-debrief-status is-' . esc_attr( $status ) . '">' . esc_html( ucfirst( $status ) ) . '</span>';
        echo '</div>';

        if ( $validation['complete'] && ! $uses_legacy_fallback ) {
            echo '<p class="lunara-debrief-readiness-note is-complete">' . esc_html__( 'All three companion films and editorial reasons are complete.', 'lunara-core' ) . '</p>';
        } elseif ( ! $validation['complete'] ) {
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

        if ( $uses_legacy_fallback ) {
            echo '<p class="lunara-debrief-readiness-note is-legacy">';
            echo esc_html__( 'This read-only preview is using retained legacy pairing fields wherever Debrief Studio is still empty. Nothing is migrated or overwritten here.', 'lunara-core' );
            echo '</p>';
        }

        echo self::pairing_preview_html( $review_id, $record['pairings'] );
        echo '<p class="description lunara-debrief-preview-caption">' . esc_html__( 'This preview reflects saved fields. Update the Review to refresh it.', 'lunara-core' ) . '</p>';
        echo '</div>';
    }

    /**
     * Build the shared rich Pair It With preview for Studio and draft import.
     *
     * This is deliberately read-only. It may consume theme enrichment helpers
     * when available, but it always has a local Core fallback.
     *
     * @param int          $review_id Owning Review ID.
     * @param array<mixed> $pairings Canonical or importer pairing rows.
     * @return string
     */
    public static function pairing_preview_html( $review_id, $pairings ) {
        $review_id = absint( $review_id );
        $by_role   = array();

        foreach ( is_array( $pairings ) ? $pairings : array() as $key => $pairing ) {
            if ( ! is_array( $pairing ) ) {
                $pairing = array( 'legacy_value' => (string) $pairing );
            }

            $fallback_role = is_string( $key ) ? $key : '';
            $normalized    = Lunara_Debrief_Contract::normalize_pairing( $pairing, $fallback_role );
            $role          = $normalized['role'];
            if ( '' === $role || isset( $by_role[ $role ] ) ) {
                continue;
            }

            $film = $normalized['film'];
            if ( ! empty( $film['movie_id'] ) ) {
                $canonical = Lunara_Debrief_Contract::movie_reference( $film['movie_id'], $review_id );
                if ( ! empty( $canonical['movie_id'] ) ) {
                    $film = $canonical;
                }
            } elseif ( ! empty( $film['imdb_title_id'] ) ) {
                $canonical = Lunara_Debrief_Contract::public_movie_reference_by_imdb( $film['imdb_title_id'], $review_id );
                if ( ! empty( $canonical['movie_id'] ) ) {
                    $film = $canonical;
                }
            }

            $normalized['film'] = $film;
            $by_role[ $role ]    = $normalized;
        }

        foreach ( array_keys( Lunara_Debrief_Contract::roles() ) as $role ) {
            if ( ! isset( $by_role[ $role ] ) ) {
                $by_role[ $role ] = Lunara_Debrief_Contract::empty_pairing( $role );
            }
        }

        ob_start();
        echo '<div class="lunara-pair-preview">';
        echo '<div class="lunara-pair-preview-head">';
        echo '<strong>' . esc_html__( 'Pair It With Preview', 'lunara-core' ) . '</strong>';
        echo '<span>' . esc_html__( 'Read-only check for title, poster, IMDb link, and Oscar Ledger status.', 'lunara-core' ) . '</span>';
        echo '</div>';
        echo '<div class="lunara-pair-preview-grid">';
        foreach ( Lunara_Debrief_Contract::roles() as $role => $definition ) {
            self::render_rich_pairing_card( $review_id, $definition['label'], $by_role[ $role ] );
        }
        echo '</div>';
        echo '</div>';
        return (string) ob_get_clean();
    }

    /**
     * Render one rich pairing card using only local data and optional helpers.
     *
     * @param int                 $review_id Review ID.
     * @param string              $role_label Human role label.
     * @param array<string,mixed> $pairing Normalized pairing.
     */
    private static function render_rich_pairing_card( $review_id, $role_label, $pairing ) {
        $film      = $pairing['film'];
        $movie_id  = absint( $film['movie_id'] ?? 0 );
        $raw       = self::pairing_display_value( $pairing );
        $enriched  = function_exists( 'lunara_parse_pair_it_with_value' )
            ? lunara_parse_pair_it_with_value( $raw, $review_id )
            : array();
        $title     = trim( (string) ( $film['title'] ?? '' ) );
        $title     = '' !== $title ? $title : trim( (string) ( $enriched['title'] ?? '' ) );
        $year      = trim( (string) ( $film['year'] ?? '' ) );
        $year      = '' !== $year ? $year : trim( (string) ( $enriched['year'] ?? '' ) );
        $imdb_id   = Lunara_Debrief_Contract::normalize_imdb_title_id( $film['imdb_title_id'] ?? '' );
        $imdb_id   = '' !== $imdb_id ? $imdb_id : Lunara_Debrief_Contract::normalize_imdb_title_id( $enriched['tt'] ?? '' );
        $reason    = trim( (string) ( $pairing['editorial_reason'] ?? '' ) );
        $reason    = '' !== $reason ? $reason : trim( (string) ( $enriched['note'] ?? '' ) );
        $warnings  = isset( $enriched['warnings'] ) && is_array( $enriched['warnings'] ) ? $enriched['warnings'] : array();
        $poster    = trim( (string) ( $enriched['poster_html'] ?? '' ) );
        $imdb_href = '' !== $imdb_id ? 'https://www.imdb.com/title/' . $imdb_id . '/' : '';
        $internal  = trim( (string) ( $enriched['internal_href'] ?? '' ) );
        $permalink = trim( (string) ( $film['permalink'] ?? '' ) );

        if ( '' === $internal && '' !== $imdb_id && function_exists( 'lunara_get_internal_title_reference_url' ) ) {
            $internal = (string) lunara_get_internal_title_reference_url( $imdb_id, $review_id );
        }

        if ( '' === $poster && $movie_id && function_exists( 'get_post_thumbnail_id' ) && function_exists( 'wp_get_attachment_image' ) ) {
            $poster_id = absint( get_post_thumbnail_id( $movie_id ) );
            if ( $poster_id ) {
                $poster = wp_get_attachment_image(
                    $poster_id,
                    'medium',
                    false,
                    array(
                        'class'    => 'lunara-pair-preview-thumb',
                        'loading'  => 'lazy',
                        'decoding' => 'async',
                        'alt'      => '' !== $title ? $title . ' poster' : '',
                    )
                );
            }
        }

        $counts = isset( $enriched['counts'] ) && is_array( $enriched['counts'] )
            ? $enriched['counts']
            : array( 'noms' => 0, 'wins' => 0 );
        if ( ! isset( $enriched['counts'] ) && '' !== $imdb_id && function_exists( 'lunara_get_oscar_ledger_counts' ) ) {
            $counts = lunara_get_oscar_ledger_counts( $imdb_id );
        }
        $noms = absint( $counts['noms'] ?? 0 );
        $wins = absint( $counts['wins'] ?? 0 );

        $title_href = '' !== $internal ? $internal : ( '' !== $permalink ? $permalink : $imdb_href );
        $link_type  = '';
        if ( '' !== $internal ) {
            $enriched_type = trim( (string) ( $enriched['title_href_type'] ?? '' ) );
            $link_type = in_array( $enriched_type, array( 'review', 'oscar' ), true )
                ? $enriched_type
                : ( false !== strpos( $internal, '/reviews/' ) ? 'review' : 'oscar' );
        } elseif ( '' !== $permalink ) {
            $link_type = 'film';
        } elseif ( '' !== $imdb_href ) {
            $link_type = 'imdb';
        }

        $is_empty = '' === $title && '' === $imdb_id && '' === $reason;
        $classes  = array( 'lunara-pair-preview-card' );
        $classes[] = $is_empty ? 'is-empty' : ( empty( $warnings ) ? 'is-ready' : 'is-warning' );

        echo '<article class="' . esc_attr( implode( ' ', $classes ) ) . '">';
        echo '<div class="lunara-pair-preview-media">';
        echo '' !== $poster ? wp_kses_post( $poster ) : esc_html__( 'No poster', 'lunara-core' );
        echo '</div>';
        echo '<div>';
        echo '<p class="lunara-pair-preview-role">' . esc_html( $role_label ) . '</p>';
        echo '<p class="lunara-pair-preview-title">';
        $display_title = '' !== $title ? $title . ( '' !== $year ? ' (' . $year . ')' : '' ) : __( 'Not filled yet', 'lunara-core' );
        if ( '' !== $title_href && ! $is_empty ) {
            echo '<a href="' . esc_url( $title_href ) . '" target="_blank" rel="noopener noreferrer">' . esc_html( $display_title ) . '</a>';
        } else {
            echo esc_html( $display_title );
        }
        echo '</p>';
        if ( '' !== $reason ) {
            echo '<p class="lunara-pair-preview-note">' . esc_html( $reason ) . '</p>';
        }
        echo '<div class="lunara-pair-preview-chips">';
        if ( '' !== $imdb_id ) {
            echo '<a class="lunara-pair-preview-chip" href="' . esc_url( $imdb_href ) . '" target="_blank" rel="noopener noreferrer">' . esc_html( strtoupper( $imdb_id ) ) . '</a>';
        }
        if ( '' !== $poster ) {
            echo '<span class="lunara-pair-preview-chip">' . esc_html__( 'Poster ready', 'lunara-core' ) . '</span>';
        }
        if ( $noms > 0 ) {
            echo '<span class="lunara-pair-preview-chip">' . esc_html( sprintf( __( 'Oscar Ledger: %1$d noms / %2$d wins', 'lunara-core' ), $noms, $wins ) ) . '</span>';
        } elseif ( '' !== $imdb_id ) {
            echo '<span class="lunara-pair-preview-chip is-muted">' . esc_html__( 'No Oscar Ledger data', 'lunara-core' ) . '</span>';
        }
        if ( 'review' === $link_type ) {
            echo '<span class="lunara-pair-preview-chip">' . esc_html__( 'Links to Lunara review', 'lunara-core' ) . '</span>';
        } elseif ( 'oscar' === $link_type ) {
            echo '<span class="lunara-pair-preview-chip">' . esc_html__( 'Links to Oscar page', 'lunara-core' ) . '</span>';
        } elseif ( 'film' === $link_type ) {
            echo '<span class="lunara-pair-preview-chip">' . esc_html__( 'Links to Lunara film', 'lunara-core' ) . '</span>';
        } elseif ( 'imdb' === $link_type ) {
            echo '<span class="lunara-pair-preview-chip is-muted">' . esc_html__( 'IMDb fallback', 'lunara-core' ) . '</span>';
        }
        echo '</div>';
        if ( ! empty( $warnings ) ) {
            echo '<ul class="lunara-pair-preview-warnings">';
            foreach ( $warnings as $warning ) {
                echo '<li>' . esc_html( $warning ) . '</li>';
            }
            echo '</ul>';
        }
        echo '</div>';
        echo '</article>';
    }

    /** Build one theme-compatible pairing value from canonical fields. */
    private static function pairing_display_value( $pairing ) {
        $film   = $pairing['film'];
        $title  = trim( (string) ( $film['title'] ?? '' ) );
        $year   = trim( (string) ( $film['year'] ?? '' ) );
        $imdb   = Lunara_Debrief_Contract::normalize_imdb_title_id( $film['imdb_title_id'] ?? '' );
        $reason = trim( (string) ( $pairing['editorial_reason'] ?? '' ) );
        if ( ! empty( $pairing['legacy_value'] ) && ( '' === $title || '' === $imdb || '' === $reason ) ) {
            return trim( (string) $pairing['legacy_value'] );
        }

        $value  = $title . ( '' !== $year ? ' (' . $year . ')' : '' );
        $value .= '' !== $reason ? ' — ' . $reason : '';
        $value .= '' !== $imdb ? ' | IMDb: ' . $imdb : '';
        return trim( $value );
    }

    /** Whether saved canonical fields are currently falling back to legacy. */
    private static function uses_legacy_pairing_fallback( $review_id ) {
        foreach ( Lunara_Debrief_Contract::roles() as $definition ) {
            $legacy_value = '';
            foreach ( $definition['legacy_meta_keys'] as $meta_key ) {
                $legacy_value = trim( (string) get_post_meta( $review_id, $meta_key, true ) );
                if ( '' !== $legacy_value ) {
                    break;
                }
            }

            if ( '' !== $legacy_value && (
                ! absint( get_post_meta( $review_id, $definition['movie_field'], true ) )
                || '' === trim( (string) get_post_meta( $review_id, $definition['reason_field'], true ) )
            ) ) {
                return true;
            }
        }

        return false;
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
