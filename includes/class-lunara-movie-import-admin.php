<?php
/**
 * Private Movie importer controls for the Review-owned Debrief Studio.
 *
 * The controller owns authorization, request validation, and the editor UI.
 * Provider transport and WordPress entity writes remain isolated in the
 * provider gateway and importer services.
 *
 * @package Lunara_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class Lunara_Movie_Import_Admin {

    const REST_NAMESPACE = 'lunara/v1';
    const REST_BASE      = '/movie-import';

    /**
     * Register admin-only hooks.
     */
    public static function init() {
        add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );

        foreach ( self::role_fields() as $role => $field_key ) {
            add_action(
                'acf/render_field/key=' . $field_key,
                array( __CLASS__, 'render_field_launcher' )
            );
        }
    }

    /**
     * Enqueue importer assets only on persisted Review edit screens.
     *
     * @param string $hook Admin hook suffix.
     */
    public static function enqueue_assets( $hook ) {
        if ( 'post.php' !== $hook || ! function_exists( 'get_current_screen' ) ) {
            return;
        }

        $screen = get_current_screen();
        if ( ! $screen || 'review' !== $screen->post_type ) {
            return;
        }

        $review_id = self::current_review_id();
        if (
            ! self::is_saved_review( $review_id )
            || ! current_user_can( 'edit_post', $review_id )
            || ! current_user_can( 'manage_options' )
        ) {
            return;
        }

        $css_path = LUNARA_CORE_DIR . 'assets/css/lunara-movie-import-admin.css';
        $js_path  = LUNARA_CORE_DIR . 'assets/js/lunara-movie-import-admin.js';

        wp_enqueue_style(
            'lunara-core-movie-import-admin',
            LUNARA_CORE_URL . 'assets/css/lunara-movie-import-admin.css',
            array(),
            file_exists( $css_path ) ? (string) filemtime( $css_path ) : LUNARA_CORE_VERSION
        );
        wp_enqueue_script(
            'lunara-core-movie-import-admin',
            LUNARA_CORE_URL . 'assets/js/lunara-movie-import-admin.js',
            array(),
            file_exists( $js_path ) ? (string) filemtime( $js_path ) : LUNARA_CORE_VERSION,
            true
        );

        wp_localize_script(
            'lunara-core-movie-import-admin',
            'LunaraMovieImportAdmin',
            array(
                'restBase' => esc_url_raw( rest_url( self::REST_NAMESPACE . self::REST_BASE . '/' ) ),
                'nonce'    => wp_create_nonce( 'wp_rest' ),
                'reviewId' => $review_id,
                'strings'  => array(
                    'invalidImdb' => __( 'Enter a valid IMDb title ID such as tt0068646.', 'lunara-core' ),
                    'lookupBusy'  => __( 'Looking up the film...', 'lunara-core' ),
                    'importBusy'  => __( 'Creating the draft Film Dossier...', 'lunara-core' ),
                    'lookupReady' => __( 'Film found. Review the identity before importing.', 'lunara-core' ),
                    'localFound'  => __( 'This film is already in the local library. Open its dossier or close this window and select the published record above.', 'lunara-core' ),
                    'draftReady'  => __( 'An existing draft can be enriched. Review the provider identity before continuing.', 'lunara-core' ),
                    'createDraft' => __( 'Create draft dossier', 'lunara-core' ),
                    'enrichDraft' => __( 'Enrich existing draft', 'lunara-core' ),
                    'enrichBusy'  => __( 'Enriching the existing draft Film Dossier...', 'lunara-core' ),
                    'imported'    => __( 'Draft Film Dossier saved. Review and publish it before selecting it here.', 'lunara-core' ),
                    'requestFail' => __( 'The request could not be completed. Try again or contact an administrator.', 'lunara-core' ),
                ),
            )
        );
    }

    /**
     * Add one local-first launcher beneath each canonical Movie selector.
     *
     * @param array<string,mixed> $field ACF field definition.
     */
    public static function render_field_launcher( $field ) {
        $field_key = sanitize_key( $field['key'] ?? '' );
        $role      = array_search( $field_key, self::role_fields(), true );
        if ( false === $role ) {
            return;
        }

        $review_id = self::current_review_id();
        $roles     = Lunara_Debrief_Contract::roles();
        $label     = $roles[ $role ]['label'] ?? ucwords( str_replace( '_', ' ', $role ) );

        echo '<div class="lunara-movie-import-launcher" data-lunara-movie-import-launcher data-state="local-first" data-role="' . esc_attr( $role ) . '" data-field-key="' . esc_attr( $field_key ) . '">';
        echo '<p class="description"><strong>' . esc_html__( 'Local library first.', 'lunara-core' ) . '</strong> ';
        echo esc_html__( 'Search the published Film Dossiers above. Import only when the film is genuinely missing.', 'lunara-core' );
        echo '</p>';

        if ( ! self::is_saved_review( $review_id ) ) {
            echo '<p class="lunara-movie-import-guard">' . esc_html__( 'Save this Review once to enable the private importer.', 'lunara-core' ) . '</p>';
            echo '</div>';
            return;
        }

        if ( ! current_user_can( 'edit_post', $review_id ) ) {
            echo '</div>';
            return;
        }

        if ( ! current_user_can( 'manage_options' ) ) {
            echo '<p class="lunara-movie-import-guard">' . esc_html__( 'Remote importing is currently limited to administrators.', 'lunara-core' ) . '</p>';
            echo '</div>';
            return;
        }

        $dialog_id = 'lunara-movie-import-' . sanitize_html_class( $role );
        $title_id  = $dialog_id . '-title';

        echo '<button type="button" class="button button-secondary lunara-movie-import-open" data-lunara-movie-import-open aria-haspopup="dialog" aria-controls="' . esc_attr( $dialog_id ) . '">';
        echo esc_html__( 'Import a missing film', 'lunara-core' );
        echo '</button>';
        echo '<dialog id="' . esc_attr( $dialog_id ) . '" class="lunara-movie-import-dialog" data-lunara-movie-import-dialog aria-labelledby="' . esc_attr( $title_id ) . '">';
        echo '<div class="lunara-movie-import-dialog-head">';
        echo '<div><span class="lunara-movie-import-eyebrow">' . esc_html( $label ) . '</span>';
        echo '<h2 id="' . esc_attr( $title_id ) . '">' . esc_html__( 'Import Film Dossier', 'lunara-core' ) . '</h2></div>';
        echo '<button type="button" class="button-link lunara-movie-import-close" data-lunara-movie-import-close aria-label="' . esc_attr__( 'Close film importer', 'lunara-core' ) . '"><span aria-hidden="true">&times;</span></button>';
        echo '</div>';
        echo '<div class="lunara-movie-import-dialog-body">';
        echo '<p>' . esc_html__( 'Use the canonical IMDb title ID. Provider credentials and remote URLs never enter this form.', 'lunara-core' ) . '</p>';
        echo '<form data-lunara-movie-lookup-form novalidate>';
        echo '<label for="' . esc_attr( $dialog_id . '-imdb' ) . '">' . esc_html__( 'IMDb title ID', 'lunara-core' ) . '</label>';
        echo '<div class="lunara-movie-import-query">';
        echo '<input id="' . esc_attr( $dialog_id . '-imdb' ) . '" name="imdb_id" type="text" inputmode="text" autocomplete="off" spellcheck="false" pattern="tt[0-9]{6,9}" placeholder="tt0068646" required data-lunara-imdb-input>';
        echo '<button type="submit" class="button button-primary">' . esc_html__( 'Look up film', 'lunara-core' ) . '</button>';
        echo '</div>';
        echo '</form>';
        echo '<p class="lunara-movie-import-status" role="status" aria-live="polite" aria-atomic="true" data-lunara-movie-import-status></p>';
        echo '<p class="lunara-movie-import-alert" role="alert" aria-live="assertive" aria-atomic="true" hidden data-lunara-movie-import-alert></p>';
        echo '<section class="lunara-movie-import-result" hidden data-lunara-movie-import-result aria-label="' . esc_attr__( 'Remote film result', 'lunara-core' ) . '">';
        echo '<span class="lunara-movie-import-result-label">' . esc_html__( 'Candidate identity', 'lunara-core' ) . '</span>';
        echo '<h3 data-lunara-candidate-title tabindex="-1"></h3>';
        echo '<p data-lunara-candidate-meta></p>';
        echo '<p data-lunara-candidate-overview></p>';
        echo '<div class="lunara-movie-import-actions">';
        echo '<button type="button" class="button button-primary" data-lunara-movie-import-draft>' . esc_html__( 'Create draft dossier', 'lunara-core' ) . '</button>';
        echo '<a class="button button-secondary" href="#" target="_blank" rel="noopener noreferrer" aria-label="' . esc_attr__( 'Open Film Dossier in a new tab', 'lunara-core' ) . '" hidden data-lunara-movie-edit-link>' . esc_html__( 'Open Film Dossier', 'lunara-core' ) . '</a>';
        echo '</div>';
        echo '</section>';
        echo '</div>';
        echo '</dialog>';
        echo '</div>';
    }

    /**
     * Register the private lookup and draft-import endpoints.
     */
    public static function register_rest_routes() {
        $common = array(
            'methods'             => 'POST',
            'permission_callback' => array( __CLASS__, 'rest_permission' ),
            'args'                => array(
                'review_id' => array(
                    'required'          => true,
                    'sanitize_callback' => 'absint',
                ),
                'role'      => array(
                    'required'          => true,
                    'sanitize_callback' => 'sanitize_key',
                ),
                'imdb_id'   => array(
                    'required'          => true,
                    'sanitize_callback' => array( __CLASS__, 'sanitize_imdb_id' ),
                ),
            ),
        );

        register_rest_route(
            self::REST_NAMESPACE,
            self::REST_BASE . '/lookup',
            array_merge(
                $common,
                array( 'callback' => array( __CLASS__, 'rest_lookup' ) )
            )
        );
        register_rest_route(
            self::REST_NAMESPACE,
            self::REST_BASE . '/import',
            array_merge(
                $common,
                array( 'callback' => array( __CLASS__, 'rest_import' ) )
            )
        );
    }

    /**
     * Require REST nonce, administrator authority, and an editable saved Review.
     *
     * @param WP_REST_Request $request REST request.
     * @return true|WP_Error
     */
    public static function rest_permission( $request ) {
        $nonce = (string) $request->get_header( 'X-WP-Nonce' );
        if ( '' === $nonce || ! wp_verify_nonce( $nonce, 'wp_rest' ) ) {
            return new WP_Error( 'lunara_movie_import_bad_nonce', __( 'The importer session expired. Refresh the editor and try again.', 'lunara-core' ), array( 'status' => 403 ) );
        }

        if ( ! current_user_can( 'manage_options' ) ) {
            return new WP_Error( 'lunara_movie_import_forbidden', __( 'Remote film importing is currently limited to administrators.', 'lunara-core' ), array( 'status' => 403 ) );
        }

        $review_id = absint( $request->get_param( 'review_id' ) );
        if ( ! self::is_saved_review( $review_id ) ) {
            return new WP_Error( 'lunara_movie_import_review_required', __( 'Save a valid Review before using the importer.', 'lunara-core' ), array( 'status' => 409 ) );
        }

        if ( ! current_user_can( 'edit_post', $review_id ) ) {
            return new WP_Error( 'lunara_movie_import_review_forbidden', __( 'You cannot edit this Review.', 'lunara-core' ), array( 'status' => 403 ) );
        }

        $role = sanitize_key( $request->get_param( 'role' ) );
        if ( ! array_key_exists( $role, self::role_fields() ) ) {
            return new WP_Error( 'lunara_movie_import_bad_role', __( 'Choose a valid Debrief role.', 'lunara-core' ), array( 'status' => 400 ) );
        }

        return true;
    }

    /**
     * Look up a normalized candidate without changing WordPress content.
     *
     * @param WP_REST_Request $request REST request.
     * @return WP_REST_Response|WP_Error
     */
    public static function rest_lookup( $request ) {
        $imdb_id = self::sanitize_imdb_id( $request->get_param( 'imdb_id' ) );
        if ( '' === $imdb_id ) {
            return new WP_Error( 'lunara_movie_import_bad_imdb', __( 'Enter a valid IMDb title ID.', 'lunara-core' ), array( 'status' => 400 ) );
        }

        $importer = self::importer_service();
        if ( is_wp_error( $importer ) ) {
            return self::public_service_error( $importer, 'lookup' );
        }
        $preview = self::preview_by_imdb( $imdb_id, $importer );
        if ( is_wp_error( $preview ) ) {
            return self::public_service_error( $preview, 'lookup' );
        }

        $preview_error = self::preview_state_error( $preview );
        if ( is_wp_error( $preview_error ) ) {
            return $preview_error;
        }

        $candidate = self::candidate_from_preview( $preview );
        $public    = self::public_candidate( $candidate, $imdb_id );
        if ( '' === $public['title'] ) {
            return new WP_Error( 'lunara_movie_import_not_found', __( 'No usable film record was returned for that IMDb ID.', 'lunara-core' ), array( 'status' => 404 ) );
        }

        $movie_id = absint( $preview['movie_id'] ?? 0 );
        $public['local']      = ! empty( $preview['local'] );
        $public['can_import'] = 'ready' === ( $preview['status'] ?? '' );
        $public['edit_url']   = '';
        if ( $movie_id && current_user_can( 'edit_post', $movie_id ) && function_exists( 'get_edit_post_link' ) ) {
            $edit_url = get_edit_post_link( $movie_id, 'raw' );
            $public['edit_url'] = $edit_url ? esc_url_raw( $edit_url ) : '';
        }

        return new WP_REST_Response( array( 'candidate' => $public ), 200 );
    }

    /**
     * Resolve the candidate again server-side and create a draft Movie only.
     *
     * @param WP_REST_Request $request REST request.
     * @return WP_REST_Response|WP_Error
     */
    public static function rest_import( $request ) {
        $imdb_id = self::sanitize_imdb_id( $request->get_param( 'imdb_id' ) );
        if ( '' === $imdb_id ) {
            return new WP_Error( 'lunara_movie_import_bad_imdb', __( 'Enter a valid IMDb title ID.', 'lunara-core' ), array( 'status' => 400 ) );
        }

        $importer = self::importer_service();
        if ( is_wp_error( $importer ) ) {
            return self::public_service_error( $importer, 'lookup' );
        }
        $preview = self::preview_by_imdb( $imdb_id, $importer );
        if ( is_wp_error( $preview ) ) {
            return self::public_service_error( $preview, 'lookup' );
        }
        $preview_error = self::preview_state_error( $preview );
        if ( is_wp_error( $preview_error ) ) {
            return $preview_error;
        }
        $candidate = self::candidate_from_preview( $preview );
        if ( empty( $candidate ) ) {
            return new WP_Error( 'lunara_movie_import_invalid_preview', __( 'The provider did not return an importable film candidate.', 'lunara-core' ), array( 'status' => 422 ) );
        }

        $context = array(
            'review_id'   => absint( $request->get_param( 'review_id' ) ),
            'role'        => sanitize_key( $request->get_param( 'role' ) ),
            'requested_by' => function_exists( 'get_current_user_id' ) ? get_current_user_id() : 0,
            'post_status' => 'draft',
        );
        $result = self::import_candidate( $candidate, $context, $importer );
        if ( is_wp_error( $result ) ) {
            return self::public_service_error( $result, 'import' );
        }

        $result_status = sanitize_key( is_array( $result ) ? ( $result['status'] ?? '' ) : '' );
        if ( in_array( $result_status, array( 'invalid', 'conflict', 'error', 'partial' ), true ) ) {
            $http_status = 'conflict' === $result_status ? 409 : ( 'invalid' === $result_status ? 422 : 500 );
            $error_data  = array( 'status' => $http_status );
            $movie       = self::local_movie_payload( absint( is_array( $result ) ? ( $result['movie_id'] ?? 0 ) : 0 ) );
            if ( ! empty( $movie ) ) {
                $error_data['movie'] = $movie;
            }

            return new WP_Error( 'lunara_movie_import_' . $result_status, __( 'The draft Film Dossier could not be completed safely. Open the saved draft to review it.', 'lunara-core' ), $error_data );
        }

        $movie_id = is_array( $result )
            ? absint( $result['movie_id'] ?? $result['post_id'] ?? 0 )
            : absint( $result );
        if ( ! $movie_id || 'movie' !== get_post_type( $movie_id ) || 'draft' !== get_post_status( $movie_id ) ) {
            return new WP_Error( 'lunara_movie_import_not_draft', __( 'The importer did not produce a valid draft Film Dossier.', 'lunara-core' ), array( 'status' => 500 ) );
        }

        $edit_url = function_exists( 'get_edit_post_link' ) ? get_edit_post_link( $movie_id, 'raw' ) : '';
        return new WP_REST_Response(
            array(
                'movie' => array(
                    'id'            => $movie_id,
                    'title'         => sanitize_text_field( get_the_title( $movie_id ) ),
                    'imdb_title_id' => $imdb_id,
                    'status'        => 'draft',
                    'edit_url'      => $edit_url ? esc_url_raw( $edit_url ) : '',
                ),
                'role'  => $context['role'],
            ),
            'created' === $result_status ? 201 : 200
        );
    }

    /**
     * Normalize one canonical IMDb title ID, including pasted IMDb URLs.
     *
     * @param mixed $value Raw request value.
     * @return string
     */
    public static function sanitize_imdb_id( $value ) {
        return Lunara_Debrief_Contract::normalize_imdb_title_id(
            sanitize_text_field( (string) $value )
        );
    }

    /**
     * Resolve an importer instance through one filterable service boundary.
     *
     * Integrations may inject a prebuilt instance. The default construction is
     * intentionally isolated here so the REST controller never owns provider
     * credentials or repository logic.
     *
     * @return Lunara_Movie_Importer|WP_Error
     */
    private static function importer_service() {
        $service = apply_filters( 'lunara_movie_importer_service', null );
        if ( is_object( $service ) ) {
            return $service;
        }

        if ( ! class_exists( 'Lunara_Movie_Importer' ) || ! class_exists( 'Lunara_Movie_Repository' ) ) {
            return new WP_Error( 'lunara_movie_importer_unavailable', __( 'The private Movie importer is not available.', 'lunara-core' ), array( 'status' => 503 ) );
        }

        try {
            $repository = new Lunara_Movie_Repository();
            $gateway    = apply_filters( 'lunara_movie_provider_gateway_service', null );
            if ( null === $gateway && class_exists( 'Lunara_Movie_Provider_Gateway' ) ) {
                $gateway = new Lunara_Movie_Provider_Gateway();
            }
            return new Lunara_Movie_Importer( $repository, $gateway );
        } catch ( Throwable $error ) {
            return new WP_Error( 'lunara_movie_importer_unavailable', __( 'The private Movie importer could not be initialized.', 'lunara-core' ), array( 'status' => 503 ) );
        }
    }

    /**
     * Request a zero-write provider preview through the importer service.
     *
     * @param string      $imdb_id Normalized IMDb title ID.
     * @param object|null $importer Resolved importer service.
     * @return array<string,mixed>|WP_Error
     */
    private static function preview_by_imdb( $imdb_id, $importer = null ) {
        $importer = is_object( $importer ) ? $importer : self::importer_service();
        if ( is_wp_error( $importer ) ) {
            return $importer;
        }
        if ( ! method_exists( $importer, 'preview_by_imdb' ) ) {
            return new WP_Error( 'lunara_movie_importer_incompatible', __( 'The private Movie importer cannot preview IMDb records.', 'lunara-core' ), array( 'status' => 503 ) );
        }

        return $importer->preview_by_imdb( $imdb_id );
    }

    /**
     * Invoke the draft-only apply boundary on the importer instance.
     *
     * @param array<string,mixed> $candidate Provider-normalized candidate.
     * @param array<string,mixed> $context Import audit context.
     * @param object|null         $importer Resolved importer service.
     * @return int|array<string,mixed>|WP_Error
     */
    private static function import_candidate( $candidate, $context, $importer = null ) {
        $importer = is_object( $importer ) ? $importer : self::importer_service();
        if ( is_wp_error( $importer ) ) {
            return $importer;
        }
        if ( ! method_exists( $importer, 'import_draft' ) ) {
            return new WP_Error( 'lunara_movie_importer_incompatible', __( 'The private Movie importer cannot create draft records.', 'lunara-core' ), array( 'status' => 503 ) );
        }

        return $importer->import_draft( $candidate, $context );
    }

    /**
     * Extract a normalized candidate from the versioned preview envelope.
     *
     * @param mixed $preview Importer preview.
     * @return array<string,mixed>
     */
    private static function candidate_from_preview( $preview ) {
        if ( ! is_array( $preview ) ) {
            return array();
        }
        if ( isset( $preview['candidate'] ) && is_array( $preview['candidate'] ) ) {
            return $preview['candidate'];
        }
        if ( isset( $preview['record'] ) && is_array( $preview['record'] ) ) {
            return $preview['record'];
        }

        return isset( $preview['title'] ) ? $preview : array();
    }

    /**
     * Fail closed for importer preview states that cannot be applied.
     *
     * @param mixed $preview Importer preview envelope.
     * @return true|WP_Error
     */
    private static function preview_state_error( $preview ) {
        if ( ! is_array( $preview ) ) {
            return new WP_Error( 'lunara_movie_import_invalid_preview', __( 'The importer returned an invalid preview.', 'lunara-core' ), array( 'status' => 502 ) );
        }

        $status = sanitize_key( $preview['status'] ?? '' );
        if ( 'ready' === $status ) {
            return true;
        }

        if ( 'local' === $status ) {
            $movie      = self::local_movie_payload( absint( $preview['movie_id'] ?? 0 ) );
            $post_state = sanitize_key( $movie['status'] ?? '' );
            $message    = 'draft' === $post_state
                ? __( 'A draft Film Dossier already owns this IMDb ID. Open the draft to finish and publish it.', 'lunara-core' )
                : __( 'This film already exists in the local Movie library. Select its published dossier above.', 'lunara-core' );
            $data       = array( 'status' => 409 );
            if ( ! empty( $movie ) ) {
                $data['movie'] = $movie;
            }

            return new WP_Error( 'lunara_movie_import_already_local', $message, $data );
        }

        $http_status = 'conflict' === $status ? 409 : ( 'invalid' === $status ? 400 : 503 );
        return new WP_Error( 'lunara_movie_import_preview_' . ( $status ? $status : 'invalid' ), __( 'The film cannot be imported from this preview.', 'lunara-core' ), array( 'status' => $http_status ) );
    }

    /**
     * Build a small, capability-checked recovery payload for an existing Movie.
     *
     * @param int $movie_id Movie post ID.
     * @return array<string,mixed>
     */
    private static function local_movie_payload( $movie_id ) {
        $movie_id = absint( $movie_id );
        if ( ! $movie_id || 'movie' !== get_post_type( $movie_id ) || ! current_user_can( 'edit_post', $movie_id ) ) {
            return array();
        }

        $edit_url = function_exists( 'get_edit_post_link' ) ? get_edit_post_link( $movie_id, 'raw' ) : '';

        return array(
            'id'       => $movie_id,
            'title'    => sanitize_text_field( get_the_title( $movie_id ) ),
            'status'   => sanitize_key( get_post_status( $movie_id ) ),
            'edit_url' => $edit_url ? esc_url_raw( $edit_url ) : '',
        );
    }

    /**
     * Whitelist the small candidate surface allowed back into wp-admin.
     *
     * @param mixed  $candidate Provider-normalized candidate.
     * @param string $imdb_id Requested canonical ID.
     * @return array<string,mixed>
     */
    private static function public_candidate( $candidate, $imdb_id ) {
        $candidate = is_array( $candidate ) ? $candidate : array();
        $directors = $candidate['directors'] ?? $candidate['director'] ?? '';
        if ( is_array( $directors ) ) {
            $director_names = array();
            foreach ( $directors as $director ) {
                if ( is_array( $director ) ) {
                    $director = $director['name'] ?? '';
                }
                $director = sanitize_text_field( $director );
                if ( '' !== $director ) {
                    $director_names[] = $director;
                }
            }
            $directors = implode( ', ', array_values( array_unique( $director_names ) ) );
        }

        return array(
            'imdb_title_id' => $imdb_id,
            'title'         => sanitize_text_field( $candidate['title'] ?? '' ),
            'year'          => sanitize_text_field( $candidate['year'] ?? $candidate['release_year'] ?? '' ),
            'runtime'       => sanitize_text_field(
                $candidate['runtime'] ?? ( ! empty( $candidate['runtime_minutes'] ) ? absint( $candidate['runtime_minutes'] ) . ' min' : '' )
            ),
            'directors'     => sanitize_text_field( $directors ),
            'overview'      => sanitize_textarea_field( $candidate['overview'] ?? $candidate['plot'] ?? '' ),
            'has_poster'    => ! empty( $candidate['poster_url'] ) || ! empty( $candidate['poster'] ) || ! empty( $candidate['poster_path'] ),
            'has_backdrop'  => ! empty( $candidate['backdrop_url'] ) || ! empty( $candidate['backdrop'] ) || ! empty( $candidate['backdrop_path'] ),
        );
    }

    /**
     * Return an opaque public error without leaking provider internals.
     *
     * @param WP_Error $error Service error.
     * @param string   $operation Operation label.
     * @return WP_Error
     */
    private static function public_service_error( $error, $operation ) {
        $data   = $error->get_error_data();
        $status = is_array( $data ) ? absint( $data['status'] ?? 0 ) : 0;
        if ( $status < 400 || $status > 599 ) {
            $status = 'lookup' === $operation ? 502 : 500;
        }

        $message = 'lookup' === $operation
            ? __( 'The film lookup could not be completed.', 'lunara-core' )
            : __( 'The draft Film Dossier could not be created.', 'lunara-core' );
        return new WP_Error( 'lunara_movie_' . $operation . '_failed', $message, array( 'status' => $status ) );
    }

    /**
     * Canonical Debrief role-to-field map.
     *
     * @return array<string,string>
     */
    private static function role_fields() {
        $fields = array();
        foreach ( Lunara_Debrief_Contract::roles() as $role => $definition ) {
            if ( ! empty( $definition['movie_field_key'] ) ) {
                $fields[ $role ] = sanitize_key( $definition['movie_field_key'] );
            }
        }
        return $fields;
    }

    /**
     * Whether the ID is a persisted, non-auto-draft Review.
     *
     * @param int $review_id Review post ID.
     * @return bool
     */
    private static function is_saved_review( $review_id ) {
        $status = get_post_status( $review_id );
        return $review_id > 0
            && 'review' === get_post_type( $review_id )
            && ! in_array( $status, array( false, '', 'auto-draft', 'trash' ), true );
    }

    /**
     * Resolve current Review ID across ACF and post.php contexts.
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
