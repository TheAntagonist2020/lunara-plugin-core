<?php
/**
 * Private preview/apply workflow for editorial Review HTML drafts.
 *
 * @package Lunara_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class Lunara_Review_Draft_Import_Admin {

    const REST_NAMESPACE = 'lunara/v1';
    const REST_BASE      = '/review-draft-import';
    const SOURCE_META    = '_lunara_review_import_record';
    const HASH_META      = '_lunara_review_import_source_hash';

    /** Register private editor hooks. */
    public static function init() {
        add_action( 'add_meta_boxes_review', array( __CLASS__, 'add_meta_box' ) );
        add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
    }

    /** Add the importer beneath the normal Review editor. */
    public static function add_meta_box() {
        add_meta_box(
            'lunara_review_draft_import',
            __( 'Import Review Draft', 'lunara-core' ),
            array( __CLASS__, 'render_meta_box' ),
            'review',
            'normal',
            'high'
        );
    }

    /**
     * Render an editor-neutral file/paste surface.
     *
     * @param WP_Post $post Current Review.
     */
    public static function render_meta_box( $post ) {
        $review_id = isset( $post->ID ) ? absint( $post->ID ) : 0;
        $importability = self::review_importability( $review_id );
        ?>
        <div class="lunara-review-import" data-lunara-review-import data-review-id="<?php echo esc_attr( $review_id ); ?>">
            <p class="lunara-review-import-intro">
                <?php esc_html_e( 'Turn HTML, Word, or Google Docs draft exports into native WordPress blocks and editable Lunara fields. Preview first; nothing is published or overwritten.', 'lunara-core' ); ?>
            </p>

            <?php if ( 'save_first' === $importability['reason'] ) : ?>
                <p class="notice notice-info inline"><strong><?php esc_html_e( 'Save this Review as a draft once to enable importing.', 'lunara-core' ); ?></strong></p>
            <?php elseif ( ! $importability['importable'] ) : ?>
                <p class="notice notice-warning inline"><strong><?php esc_html_e( 'Reference HTML can be imported only while this Review is saved with Draft status.', 'lunara-core' ); ?></strong></p>
            <?php else : ?>
                <div class="lunara-review-import-grid">
                    <div>
                        <label for="lunara-review-import-file"><strong><?php esc_html_e( 'Choose HTML, Word, or Google Docs export', 'lunara-core' ); ?></strong></label>
                        <input id="lunara-review-import-file" type="file" accept=".html,.htm,.docx,.zip,text/html,application/vnd.openxmlformats-officedocument.wordprocessingml.document,application/zip" data-lunara-review-import-file>
                    </div>
                    <div>
                        <label for="lunara-review-import-html"><strong><?php esc_html_e( 'Or paste HTML/rich text from Word or Google Docs', 'lunara-core' ); ?></strong></label>
                        <textarea id="lunara-review-import-html" rows="8" data-lunara-review-import-html spellcheck="false"></textarea>
                        <p class="description"><?php esc_html_e( 'Rich clipboard formatting is captured as HTML when the source application provides it.', 'lunara-core' ); ?></p>
                    </div>
                </div>
                <div class="lunara-review-import-actions">
                    <button type="button" class="button button-secondary" data-lunara-review-import-preview><?php esc_html_e( 'Preview import', 'lunara-core' ); ?></button>
                    <button type="button" class="button button-primary" data-lunara-review-import-apply disabled><?php esc_html_e( 'Apply to this draft', 'lunara-core' ); ?></button>
                    <span class="spinner" data-lunara-review-import-spinner></span>
                </div>
                <p class="lunara-review-import-status" role="status" aria-live="polite" aria-atomic="true" data-lunara-review-import-status></p>
                <p class="lunara-review-import-alert" role="alert" aria-live="assertive" aria-atomic="true" hidden data-lunara-review-import-alert></p>
                <section class="lunara-review-import-preview" hidden data-lunara-review-import-result aria-label="<?php esc_attr_e( 'Review import preview', 'lunara-core' ); ?>">
                    <div class="lunara-review-import-summary" data-lunara-review-import-summary></div>
                    <div class="lunara-review-import-pairings" data-lunara-review-import-pairings></div>
                    <div class="lunara-review-import-warnings" data-lunara-review-import-warnings></div>
                </section>
            <?php endif; ?>
        </div>
        <?php
    }

    /** Enqueue assets only on a persisted Review edit screen. */
    public static function enqueue_assets( $hook ) {
        if ( 'post.php' !== $hook || ! function_exists( 'get_current_screen' ) ) {
            return;
        }

        $screen = get_current_screen();
        if ( ! $screen || 'review' !== $screen->post_type ) {
            return;
        }

        $review_id = self::current_review_id();
        if ( ! self::review_importability( $review_id )['importable'] || ! current_user_can( 'edit_post', $review_id ) ) {
            return;
        }

        $css_path = LUNARA_CORE_DIR . 'assets/css/lunara-review-draft-import-admin.css';
        $js_path  = LUNARA_CORE_DIR . 'assets/js/lunara-review-draft-import-admin.js';

        wp_enqueue_style(
            'lunara-core-review-draft-import',
            LUNARA_CORE_URL . 'assets/css/lunara-review-draft-import-admin.css',
            array(),
            file_exists( $css_path ) ? (string) filemtime( $css_path ) : LUNARA_CORE_VERSION
        );
        wp_enqueue_script(
            'lunara-core-review-draft-import',
            LUNARA_CORE_URL . 'assets/js/lunara-review-draft-import-admin.js',
            array( 'wp-data' ),
            file_exists( $js_path ) ? (string) filemtime( $js_path ) : LUNARA_CORE_VERSION,
            true
        );
        wp_localize_script(
            'lunara-core-review-draft-import',
            'LunaraReviewDraftImport',
            array(
                'restBase' => esc_url_raw( rest_url( self::REST_NAMESPACE . self::REST_BASE . '/' ) ),
                'nonce'    => wp_create_nonce( 'wp_rest' ),
                'reviewId' => $review_id,
                'maxBytes'         => Lunara_Review_Draft_Parser::MAX_INPUT_BYTES,
                'maxDocumentBytes' => Lunara_Review_Draft_Document::MAX_SOURCE_BYTES,
                'strings'  => array(
                    'choose'          => __( 'Choose or paste an HTML draft, or choose a Word/Google export first.', 'lunara-core' ),
                    'tooLarge'        => __( 'That HTML draft is larger than the one-megabyte import limit.', 'lunara-core' ),
                    'documentTooLarge' => __( 'That Word/Google document is larger than the five-megabyte import limit.', 'lunara-core' ),
                    'unsupportedFile' => __( 'Choose an .html, .htm, .docx, or Google HTML export (.zip) file.', 'lunara-core' ),
                    'reading'      => __( 'Reading the draft...', 'lunara-core' ),
                    'saveFirst'    => __( 'Save the Review draft before importing so no unsaved editor changes can be lost.', 'lunara-core' ),
                    'previewing'   => __( 'Checking structure and field mappings...', 'lunara-core' ),
                    'ready'        => __( 'Preview ready. Review the mappings, then apply them to this draft.', 'lunara-core' ),
                    'applying'     => __( 'Creating native blocks and filling empty Lunara fields...', 'lunara-core' ),
                    'applied'      => __( 'Import complete. Reloading the Review editor...', 'lunara-core' ),
                    'failed'       => __( 'The import could not be completed. Review the warning and try again.', 'lunara-core' ),
                    'already'      => __( 'This exact source file has already been imported into this Review.', 'lunara-core' ),
                    'unresolved'   => __( 'Missing local Movie records remain editable in Debrief Studio and can be added with the private Movie importer.', 'lunara-core' ),
                ),
            )
        );
    }

    /** Register preview and apply endpoints behind the exact private prefix. */
    public static function register_rest_routes() {
        foreach ( array( 'preview' => 'rest_preview', 'apply' => 'rest_apply' ) as $route => $callback ) {
            register_rest_route(
                self::REST_NAMESPACE,
                self::REST_BASE . '/' . $route,
                array(
                    'methods'             => 'POST',
                    'callback'            => array( __CLASS__, $callback ),
                    'permission_callback' => array( __CLASS__, 'rest_permission' ),
                    'args'                => array(
                        'review_id' => array(
                            'required'          => true,
                            'sanitize_callback' => 'absint',
                        ),
                        'html'          => array( 'required' => false, 'type' => 'string' ),
                        'document'      => array( 'required' => false, 'type' => 'string' ),
                        'source_format' => array( 'required' => false, 'type' => 'string', 'sanitize_callback' => 'sanitize_key' ),
                        'source_name'   => array( 'required' => false, 'type' => 'string' ),
                    ),
                )
            );
        }
    }

    /** Require an editable, unpublished Review. */
    public static function rest_permission( $request ) {
        $review_id = absint( $request->get_param( 'review_id' ) );
        return self::review_importability( $review_id )['importable']
            && current_user_can( 'edit_post', $review_id );
    }

    /** Return a read-only mapping preview. */
    public static function rest_preview( $request ) {
        $review_id = absint( $request->get_param( 'review_id' ) );
        $importable = self::importability_error( $review_id );
        if ( is_wp_error( $importable ) ) {
            return $importable;
        }

        $parsed    = self::parse_request_source( $request );
        if ( is_wp_error( $parsed ) ) {
            return $parsed;
        }

        $source_hash = self::source_hash( $request );
        $resolutions = self::pairing_resolutions( $parsed['pairings'] );

        return rest_ensure_response(
            array(
                'valid'       => true,
                'sourceHash'  => $source_hash,
                'sourceFormat' => isset( $parsed['source_format'] ) ? $parsed['source_format'] : 'html',
                'summary'     => self::preview_summary( $parsed ),
                'pairings'    => $parsed['pairings'],
                'resolutions' => $resolutions,
                'warnings'    => $parsed['warnings'],
                'existing'    => self::existing_state( $review_id, $parsed, $source_hash ),
            )
        );
    }

    /** Apply one valid source to an empty Review draft. */
    public static function rest_apply( $request ) {
        $review_id = absint( $request->get_param( 'review_id' ) );
        $hash      = self::source_hash( $request );
        $importable = self::importability_error( $review_id );
        if ( is_wp_error( $importable ) ) {
            return $importable;
        }

        if ( ! function_exists( 'get_field' ) || ! function_exists( 'update_field' ) ) {
            return new WP_Error(
                'acf_unavailable',
                __( 'ACF Pro must be active before a Review draft can be imported safely.', 'lunara-core' ),
                array( 'status' => 503 )
            );
        }

        if ( hash_equals( (string) get_post_meta( $review_id, self::HASH_META, true ), $hash ) ) {
            return rest_ensure_response( array( 'valid' => true, 'alreadyImported' => true ) );
        }

        $parsed = self::parse_request_source( $request );
        if ( is_wp_error( $parsed ) ) {
            return $parsed;
        }

        $post          = get_post( $review_id );
        $source_record = get_post_meta( $review_id, self::SOURCE_META, true );
        $same_source   = is_array( $source_record )
            && isset( $source_record['source_hash'] )
            && hash_equals( (string) $source_record['source_hash'], $hash );
        $pending_source = is_array( $source_record )
            && isset( $source_record['status'], $source_record['source_hash'] )
            && 'pending' === $source_record['status']
            && '' !== (string) $source_record['source_hash'];
        $has_content   = '' !== trim( (string) $post->post_content );
        $recovery_body = $same_source && (string) $post->post_content === (string) $parsed['content'];

        if ( $pending_source && ! $same_source ) {
            return new WP_Error(
                'review_import_pending_source_mismatch',
                __( 'This Review has a pending import from a different source file. Retry that exact source or start a fresh Review draft so partial fields cannot be mixed.', 'lunara-core' ),
                array( 'status' => 409 )
            );
        }

        if ( $has_content && ! $recovery_body ) {
            return new WP_Error(
                'review_not_empty',
                __( 'This Review already has body content. Start a fresh draft or move the existing prose before importing so nothing can be overwritten.', 'lunara-core' ),
                array( 'status' => 409 )
            );
        }

        $started_at = $same_source && ! empty( $source_record['started_at'] )
            ? (string) $source_record['started_at']
            : current_time( 'mysql', true );
        $pending_record = array(
            'version'     => 1,
            'status'      => 'pending',
            'source_hash' => $hash,
            'started_at'  => $started_at,
            'imported_by' => get_current_user_id(),
            'metadata'    => $parsed['metadata'],
            'warnings'    => $parsed['warnings'],
        );
        $pending_write = self::update_meta_verified( $review_id, self::SOURCE_META, $pending_record );
        if ( is_wp_error( $pending_write ) ) {
            return $pending_write;
        }

        $review_fields = self::fill_review_fields( $review_id, $parsed );
        if ( is_wp_error( $review_fields ) ) {
            return $review_fields;
        }

        $resolutions = self::apply_debrief_fields( $review_id, $parsed['pairings'] );
        if ( is_wp_error( $resolutions ) ) {
            return $resolutions;
        }

        $post_update = array(
            'ID' => $review_id,
        );
        if ( ! $has_content ) {
            $post_update['post_content'] = $parsed['content'];
        }
        if ( '' === trim( (string) $post->post_title ) ) {
            $post_update['post_title'] = $parsed['title'];
        }
        if ( '' === trim( (string) $post->post_excerpt ) && '' !== $parsed['excerpt'] ) {
            $post_update['post_excerpt'] = $parsed['excerpt'];
        }

        if ( count( $post_update ) > 1 ) {
            $updated = wp_update_post( wp_slash( $post_update ), true );
            if ( is_wp_error( $updated ) ) {
                return $updated;
            }

            $verified_post = get_post( $review_id );
            foreach ( $post_update as $field => $value ) {
                if ( 'ID' !== $field && ( ! ( $verified_post instanceof WP_Post ) || (string) $verified_post->{$field} !== (string) $value ) ) {
                    return self::persistence_error( 'post:' . $field );
                }
            }
        }

        $record = array(
            'version'      => 1,
            'status'       => 'complete',
            'source_hash'  => $hash,
            'started_at'   => $started_at,
            'completed_at' => current_time( 'mysql', true ),
            'imported_by'  => get_current_user_id(),
            'metadata'     => $parsed['metadata'],
            'warnings'     => $parsed['warnings'],
            'resolutions'  => $resolutions,
        );
        $record_write = self::update_meta_verified( $review_id, self::SOURCE_META, $record );
        if ( is_wp_error( $record_write ) ) {
            return $record_write;
        }

        $hash_write = self::update_meta_verified( $review_id, self::HASH_META, $hash );
        if ( is_wp_error( $hash_write ) ) {
            return $hash_write;
        }

        if ( method_exists( 'Lunara_Core', 'instance' ) ) {
            $core = Lunara_Core::instance();
            if ( is_object( $core ) && method_exists( $core, 'sync_review_archive_terms' ) ) {
                $core->sync_review_archive_terms( $review_id );
            }
        }

        return rest_ensure_response(
            array(
                'valid'           => true,
                'alreadyImported' => false,
                'reviewId'        => $review_id,
                'editUrl'         => get_edit_post_link( $review_id, 'raw' ),
                'resolutions'     => $resolutions,
            )
        );
    }

    /** Parse and validate a request HTML or local document export. */
    private static function parse_request_source( $request ) {
        $format   = sanitize_key( (string) $request->get_param( 'source_format' ) );
        $warnings = array();

        if ( '' === $format ) {
            $format = 'html';
        }
        if ( ! in_array( $format, array( 'html', 'docx', 'zip' ), true ) ) {
            return new WP_Error(
                'unsupported_source_format',
                __( 'Choose an HTML, DOCX, or Google HTML export source.', 'lunara-core' ),
                array( 'status' => 400 )
            );
        }

        if ( in_array( $format, array( 'docx', 'zip' ), true ) ) {
            $converted = Lunara_Review_Draft_Document::convert( $request->get_param( 'document' ), $format );
            if ( ! empty( $converted['errors'] ) ) {
                return new WP_Error(
                    'invalid_review_document',
                    __( 'The Word/Google document could not be converted safely.', 'lunara-core' ),
                    array( 'status' => 422, 'errors' => $converted['errors'], 'warnings' => $converted['warnings'] )
                );
            }
            $html     = (string) $converted['html'];
            $warnings = $converted['warnings'];
        } else {
            $html   = $request->get_param( 'html' );
        }

        if ( ! is_string( $html ) || strlen( $html ) > Lunara_Review_Draft_Parser::MAX_INPUT_BYTES ) {
            return new WP_Error( 'invalid_source', __( 'The HTML source is missing or too large.', 'lunara-core' ), array( 'status' => 400 ) );
        }

        $parsed = Lunara_Review_Draft_Parser::parse( $html );
        $parsed['source_format'] = $format;
        $parsed['warnings']      = array_values( array_unique( array_merge( $warnings, $parsed['warnings'] ) ) );
        if ( empty( $parsed['valid'] ) ) {
            return new WP_Error(
                'invalid_review_draft',
                __( 'The draft structure could not be imported safely.', 'lunara-core' ),
                array( 'status' => 422, 'errors' => $parsed['errors'], 'warnings' => $parsed['warnings'] )
            );
        }

        return $parsed;
    }

    /** Hash the exact source representation so retries remain idempotent. */
    private static function source_hash( $request ) {
        $format = sanitize_key( (string) $request->get_param( 'source_format' ) );
        $is_document = in_array( $format, array( 'docx', 'zip' ), true );
        $source = $is_document
            ? (string) $request->get_param( 'document' )
            : (string) $request->get_param( 'html' );

        // Preserve the 0.7.1 HTML hash so existing import records remain
        // idempotent after this format-expansion release.
        return $is_document ? hash( 'sha256', $format . "\0" . $source ) : hash( 'sha256', $source );
    }

    /** Build a bounded preview safe for the editor UI. */
    private static function preview_summary( $parsed ) {
        return array(
            'title'          => $parsed['title'],
            'year'           => $parsed['year'],
            'imdbId'         => $parsed['imdb_id'],
            'standfirst'     => $parsed['standfirst'],
            'excerpt'        => $parsed['excerpt'],
            'score'          => $parsed['score'],
            'whereToWatch'   => $parsed['where_to_watch'],
            'paragraphCount' => substr_count( $parsed['content'], '<!-- wp:paragraph -->' ),
            'metadata'       => $parsed['metadata'],
        );
    }

    /** Report values that would prevent a safe empty-draft import. */
    private static function existing_state( $review_id, $parsed = null, $source_hash = '' ) {
        $post          = get_post( $review_id );
        $source_record = get_post_meta( $review_id, self::SOURCE_META, true );
        $stored_hash   = (string) get_post_meta( $review_id, self::HASH_META, true );
        $same_source   = '' !== $source_hash
            && is_array( $source_record )
            && isset( $source_record['source_hash'] )
            && hash_equals( (string) $source_record['source_hash'], $source_hash );
        $body_matches  = is_array( $parsed )
            && $post instanceof WP_Post
            && '' !== trim( (string) $post->post_content )
            && (string) $post->post_content === (string) $parsed['content'];
        $already_imported = '' !== $source_hash && hash_equals( $stored_hash, $source_hash );
        $debrief       = array();

        foreach ( Lunara_Debrief_Contract::roles() as $role => $definition ) {
            $debrief[ $role ] = array(
                'movie'  => function_exists( 'get_field' ) && ! empty( get_field( $definition['movie_field'], $review_id, false ) ),
                'reason' => function_exists( 'get_field' ) && '' !== trim( (string) get_field( $definition['reason_field'], $review_id, false ) ),
            );
        }

        return array(
            'title'         => $post instanceof WP_Post && '' !== trim( (string) $post->post_title ),
            'content'       => $post instanceof WP_Post && '' !== trim( (string) $post->post_content ),
            'excerpt'       => $post instanceof WP_Post && '' !== trim( (string) $post->post_excerpt ),
            'previousHash'  => (string) get_post_meta( $review_id, self::HASH_META, true ),
            'recoverable'   => $same_source && $body_matches && ! $already_imported,
            'alreadyImported' => $already_imported,
            'debrief'       => $debrief,
            'fields'        => array(
                'score'        => self::has_meta_value( $review_id, '_lunara_score' ),
                'year'         => self::has_meta_value( $review_id, '_lunara_year' ),
                'imdbId'       => self::has_meta_value( $review_id, '_lunara_imdb_title_id' ),
                'whereToWatch' => self::has_meta_value( $review_id, '_lunara_where' ),
                'standfirst'   => self::has_meta_value( $review_id, '_lunara_review_standfirst' ),
                'director'     => self::has_meta_value( $review_id, '_lunara_director' ),
                'runtime'      => self::has_meta_value( $review_id, '_lunara_runtime' ),
                'studio'       => self::has_meta_value( $review_id, '_lunara_studio' ),
            ),
        );
    }

    /** Fill only currently empty Review fields. */
    private static function fill_review_fields( $review_id, $parsed ) {
        $score = '';
        if ( preg_match( '/^(\d+(?:\.\d+)?)/', (string) $parsed['score'], $match ) ) {
            $score = $match[1];
        }

        $director = self::metadata_value( $parsed['metadata'], array( 'director', 'director_writer' ) );
        if ( '' !== $director ) {
            $director = trim( preg_replace( '/\s*\(.*$/u', '', $director ) );
        }

        $fields = array(
            '_lunara_score'             => $score,
            '_lunara_year'              => (string) $parsed['year'],
            '_lunara_imdb_title_id'     => $parsed['imdb_id'],
            '_lunara_where'             => $parsed['where_to_watch'],
            '_lunara_review_standfirst' => $parsed['standfirst'],
            '_lunara_director'          => $director,
            '_lunara_runtime'           => self::metadata_value( $parsed['metadata'], array( 'runtime' ) ),
            '_lunara_studio'            => self::metadata_value( $parsed['metadata'], array( 'studio_distributor', 'studio' ) ),
        );

        foreach ( $fields as $key => $value ) {
            if ( '' !== trim( (string) $value ) && '' === trim( (string) get_post_meta( $review_id, $key, true ) ) ) {
                $written = self::update_meta_verified( $review_id, $key, sanitize_textarea_field( $value ) );
                if ( is_wp_error( $written ) ) {
                    return $written;
                }
            }
        }

        return true;
    }

    /** Fill canonical Debrief fields when local Movies exist, preserving legacy text. */
    private static function apply_debrief_fields( $review_id, $pairings ) {
        $resolutions = self::pairing_resolutions( $pairings );
        $roles       = Lunara_Debrief_Contract::roles();

        foreach ( $roles as $role => $definition ) {
            $pairing = isset( $pairings[ $role ] ) ? $pairings[ $role ] : array();
            $legacy  = self::legacy_pairing_value( $pairing );
            $legacy_key = isset( $definition['legacy_meta_keys'][0] ) ? $definition['legacy_meta_keys'][0] : '';
            if ( '' !== $legacy_key && '' === trim( (string) get_post_meta( $review_id, $legacy_key, true ) ) ) {
                $legacy_write = self::update_meta_verified( $review_id, $legacy_key, $legacy );
                if ( is_wp_error( $legacy_write ) ) {
                    return $legacy_write;
                }
            }

            $current_reason = get_field( $definition['reason_field'], $review_id, false );
            if ( '' === trim( (string) $current_reason ) && ! empty( $pairing['reason'] ) ) {
                $reason_write = self::update_acf_verified(
                    $review_id,
                    $definition['reason_field_key'],
                    $definition['reason_field'],
                    $pairing['reason']
                );
                if ( is_wp_error( $reason_write ) ) {
                    return $reason_write;
                }
            }

            $movie_id = isset( $resolutions[ $role ]['movieId'] ) ? absint( $resolutions[ $role ]['movieId'] ) : 0;
            $current_movie = get_field( $definition['movie_field'], $review_id, false );
            if ( $movie_id > 0 && empty( $current_movie ) ) {
                $movie_write = self::update_acf_verified(
                    $review_id,
                    $definition['movie_field_key'],
                    $definition['movie_field'],
                    $movie_id
                );
                if ( is_wp_error( $movie_write ) ) {
                    return $movie_write;
                }
            }
        }

        $status = get_field( 'debrief_status', $review_id, false );
        if ( '' === trim( (string) $status ) ) {
            $status_write = self::update_acf_verified(
                $review_id,
                Lunara_Debrief_Contract::FIELD_STATUS_KEY,
                'debrief_status',
                Lunara_Debrief_Contract::STATUS_INCOMPLETE
            );
            if ( is_wp_error( $status_write ) ) {
                return $status_write;
            }
        }

        return $resolutions;
    }

    /** Resolve pairings only against published local Movie records. */
    private static function pairing_resolutions( $pairings ) {
        $resolved = array();
        foreach ( $pairings as $role => $pairing ) {
            $imdb_id = isset( $pairing['imdb_id'] ) ? strtolower( trim( (string) $pairing['imdb_id'] ) ) : '';
            $ids = array();
            if ( preg_match( '/^tt\d{6,9}$/', $imdb_id ) ) {
                $ids = get_posts(
                    array(
                        'post_type'      => 'movie',
                        'post_status'    => 'publish',
                        'fields'         => 'ids',
                        'posts_per_page' => 2,
                        'no_found_rows'  => true,
                        'meta_query'     => array(
                            'relation' => 'OR',
                            array( 'key' => 'imdb_title_id', 'value' => $imdb_id ),
                            array( 'key' => '_lunara_entity_id', 'value' => $imdb_id ),
                        ),
                    )
                );
            }
            $ids = is_array( $ids ) ? array_values( array_unique( array_map( 'absint', $ids ) ) ) : array();
            $resolved[ $role ] = array(
                'status'  => 1 === count( $ids ) ? 'published' : ( count( $ids ) > 1 ? 'conflict' : 'missing' ),
                'movieId' => 1 === count( $ids ) ? $ids[0] : 0,
                'imdbId'  => $imdb_id,
            );
        }
        return $resolved;
    }

    /** Build the legacy editable pairing text without losing the reason. */
    private static function legacy_pairing_value( $pairing ) {
        $title  = isset( $pairing['title'] ) ? trim( (string) $pairing['title'] ) : '';
        $year   = isset( $pairing['year'] ) ? absint( $pairing['year'] ) : 0;
        $imdb   = isset( $pairing['imdb_id'] ) ? trim( (string) $pairing['imdb_id'] ) : '';
        $reason = isset( $pairing['reason'] ) ? trim( (string) $pairing['reason'] ) : '';

        return trim( $title . ( $year ? ' (' . $year . ')' : '' ) . ( $imdb ? ' | IMDb: ' . $imdb : '' ) . ( $reason ? ' — ' . $reason : '' ) );
    }

    /** Find the first normalized metadata value. */
    private static function metadata_value( $metadata, $keys ) {
        foreach ( $keys as $key ) {
            if ( isset( $metadata[ $key ] ) && '' !== trim( (string) $metadata[ $key ] ) ) {
                return trim( (string) $metadata[ $key ] );
            }
        }
        return '';
    }

    /** Resolve the Review ID from an editor request. */
    private static function current_review_id() {
        if ( isset( $_GET['post'] ) ) {
            return absint( $_GET['post'] );
        }
        return 0;
    }

    /** Central draft-only importability decision for UI, assets, and REST. */
    private static function review_importability( $review_id ) {
        $post = $review_id > 0 ? get_post( $review_id ) : null;
        if ( ! ( $post instanceof WP_Post ) || 'review' !== $post->post_type || 'auto-draft' === $post->post_status ) {
            return array( 'importable' => false, 'reason' => 'save_first', 'post' => $post );
        }

        if ( 'draft' !== $post->post_status ) {
            return array( 'importable' => false, 'reason' => 'drafts_only', 'post' => $post );
        }

        return array( 'importable' => true, 'reason' => '', 'post' => $post );
    }

    /** Return a REST error for any target outside the saved-draft boundary. */
    private static function importability_error( $review_id ) {
        $state = self::review_importability( $review_id );
        if ( $state['importable'] ) {
            return true;
        }

        if ( 'save_first' === $state['reason'] ) {
            return new WP_Error(
                'review_not_saved',
                __( 'Save this Review with Draft status before importing reference HTML.', 'lunara-core' ),
                array( 'status' => 409 )
            );
        }

        return new WP_Error(
            'review_not_draft',
            __( 'Reference HTML can be imported only into a saved Review draft.', 'lunara-core' ),
            array( 'status' => 409 )
        );
    }

    /** Check whether canonical metadata already contains an editable value. */
    private static function has_meta_value( $review_id, $key ) {
        return '' !== trim( (string) get_post_meta( $review_id, $key, true ) );
    }

    /** Persist and re-read post metadata so silent failures cannot pass. */
    private static function update_meta_verified( $review_id, $key, $value ) {
        $current = get_post_meta( $review_id, $key, true );
        if ( self::stored_value_matches( $current, $value ) ) {
            return true;
        }

        $updated = update_post_meta( $review_id, $key, wp_slash( $value ) );
        $stored  = get_post_meta( $review_id, $key, true );
        if ( false === $updated || ! self::stored_value_matches( $stored, $value ) ) {
            return self::persistence_error( 'meta:' . $key );
        }

        return true;
    }

    /** Persist and re-read an ACF field using its stable key and field name. */
    private static function update_acf_verified( $review_id, $field_key, $field_name, $value ) {
        $current = get_field( $field_name, $review_id, false );
        if ( self::stored_value_matches( $current, $value ) ) {
            return true;
        }

        $updated = update_field( $field_key, $value, $review_id );
        $stored  = get_field( $field_name, $review_id, false );
        if ( false === $updated || ! self::stored_value_matches( $stored, $value ) ) {
            return self::persistence_error( 'acf:' . $field_name );
        }

        return true;
    }

    /** Compare WordPress/ACF scalar coercions while preserving array shape. */
    private static function stored_value_matches( $stored, $expected ) {
        if ( is_array( $expected ) || is_object( $expected ) ) {
            return $stored == $expected;
        }

        return (string) $stored === (string) $expected;
    }

    /** Build one stable write-failure response with the failed target. */
    private static function persistence_error( $target ) {
        return new WP_Error(
            'review_import_write_failed',
            __( 'The Review import stopped because a field could not be saved and verified. The pending import can be retried safely.', 'lunara-core' ),
            array( 'status' => 500, 'target' => $target )
        );
    }
}
