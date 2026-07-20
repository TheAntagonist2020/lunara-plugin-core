<?php
/**
 * Plugin Name: Lunara Core
 * Plugin URI: https://lunarafilm.com
 * Description: Core content models and editorial tools for Lunara Film.
 * Version: 0.8.2
 * Author: Lunara Film (Dalton Johnson)
 * Author URI: https://lunarafilm.com
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: lunara-core
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'LUNARA_CORE_VERSION', '0.8.2' );
define( 'LUNARA_CORE_FILE', __FILE__ );
define( 'LUNARA_CORE_DIR', plugin_dir_path( __FILE__ ) );
define( 'LUNARA_CORE_URL', plugin_dir_url( __FILE__ ) );

require_once LUNARA_CORE_DIR . 'includes/class-lunara-debrief-contract.php';

// Debrief reconciliation and suggestions are operator-only, read-only WP-CLI
// surfaces. Keep them out of normal public and editor WordPress requests.
if ( defined( 'WP_CLI' ) && WP_CLI ) {
    require_once LUNARA_CORE_DIR . 'includes/class-lunara-debrief-migration.php';
    require_once LUNARA_CORE_DIR . 'includes/class-lunara-debrief-reconciliation.php';
    require_once LUNARA_CORE_DIR . 'includes/class-lunara-debrief-suggestions.php';
    require_once LUNARA_CORE_DIR . 'includes/class-lunara-debrief-cli.php';
}

final class Lunara_Core {

    /**
     * Singleton instance.
     *
     * @var Lunara_Core|null
     */
    private static $instance = null;

    /**
     * Bootstrap the plugin.
     *
     * @return Lunara_Core
     */
    public static function instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    /**
     * Register runtime hooks.
     */
    private function __construct() {
        add_action( 'init', array( __CLASS__, 'register_reviews_cpt' ), 0 );
        add_action( 'init', array( __CLASS__, 'register_review_taxonomies' ), 20 );
        add_action( 'init', array( __CLASS__, 'register_slide_set_taxonomy' ), 20 );

        // The Movie importer is an operator surface. Public HTML and unrelated
        // REST requests keep it unloaded; only its exact private REST prefix
        // is bootstrapped before WordPress dispatches the request.
        add_action( 'parse_request', array( __CLASS__, 'maybe_bootstrap_movie_import_rest' ), 5 );
        add_action( 'parse_request', array( __CLASS__, 'maybe_bootstrap_review_draft_import_rest' ), 5 );
        if ( function_exists( 'is_admin' ) && is_admin() ) {
            self::load_movie_import_admin();
            Lunara_Movie_Import_Admin::init();
            self::load_review_draft_import_admin();
            Lunara_Review_Draft_Import_Admin::init();
        }

        // Entity graph (Design Spec 2.0 §4): movie / person / ledger_entry
        // content models + ACF schema. Phase 1 registers models only — no
        // front-end output until the graph is populated.
        require_once LUNARA_CORE_DIR . 'includes/class-lunara-entities.php';
        Lunara_Entities::init();

        // Review-owned Debrief Studio. The Studio is admin-only; the active
        // theme remains responsible for public presentation.
        require_once LUNARA_CORE_DIR . 'includes/class-lunara-debrief-studio.php';
        Lunara_Debrief_Studio::init();

        // Modular Essay Builder (Design Spec 2.0 §12): flexible-content
        // module palette on journal entries and posts. Theme-side renderer
        // lives in the child theme (inc/essay-builder.php).
        require_once LUNARA_CORE_DIR . 'includes/class-lunara-essays.php';
        Lunara_Essays::init();

        // Graph growth: a review published with an unknown IMDb id spawns
        // its movie entity as a draft — the graph is self-expanding.
        require_once LUNARA_CORE_DIR . 'includes/class-lunara-graph-growth.php';
        Lunara_Graph_Growth::init();

        // Guardian: the availability safety net. Runs from the plugin so it
        // survives a theme knockout — auto-restores the Lunara theme if the
        // site ever falls back to a WordPress default.
        require_once LUNARA_CORE_DIR . 'includes/class-lunara-guardian.php';
        Lunara_Guardian::init();

        add_action( 'add_meta_boxes', array( $this, 'add_debrief_meta_box' ) );
        add_action( 'add_meta_boxes', array( $this, 'add_review_details_meta_box' ) );

        add_action( 'save_post_review', array( $this, 'save_debrief_meta' ) );
        add_action( 'save_post_review', array( $this, 'save_review_details_meta' ) );
        add_action( 'save_post_review', array( $this, 'sync_review_archive_terms' ), 30 );

        add_filter( 'attachment_fields_to_edit', array( $this, 'attachment_fields_to_edit' ), 10, 2 );
        add_filter( 'attachment_fields_to_save', array( $this, 'attachment_fields_to_save' ), 10, 2 );

        add_action( 'admin_menu', array( $this, 'register_carousel_manager_page' ) );
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_carousel_admin_assets' ) );
        add_action( 'wp_ajax_lunara_save_carousel_order', array( $this, 'ajax_save_carousel_order' ) );
    }

    /**
     * Load only the private editor shell for normal wp-admin requests.
     */
    public static function load_movie_import_admin() {
        if ( class_exists( 'Lunara_Movie_Import_Admin', false ) ) {
            return;
        }

        require_once LUNARA_CORE_DIR . 'includes/class-lunara-movie-import-admin.php';
    }

    /**
     * Load the private Review draft parser and editor controller.
     */
    public static function load_review_draft_import_admin() {
        if ( class_exists( 'Lunara_Review_Draft_Import_Admin', false ) ) {
            return;
        }

        require_once LUNARA_CORE_DIR . 'includes/class-lunara-review-draft-parser.php';
        require_once LUNARA_CORE_DIR . 'includes/class-lunara-review-draft-document.php';
        require_once LUNARA_CORE_DIR . 'includes/class-lunara-review-draft-import-admin.php';
    }

    /**
     * Load private Movie importer services in dependency order.
     */
    public static function load_movie_importer() {
        if ( class_exists( 'Lunara_Movie_Importer', false ) ) {
            return;
        }

        require_once LUNARA_CORE_DIR . 'includes/class-lunara-movie-identity-lock.php';
        require_once LUNARA_CORE_DIR . 'includes/class-lunara-movie-import-contract.php';
        require_once LUNARA_CORE_DIR . 'includes/class-lunara-movie-repository.php';
        require_once LUNARA_CORE_DIR . 'includes/class-lunara-movie-provider-gateway.php';
        require_once LUNARA_CORE_DIR . 'includes/class-lunara-movie-importer.php';
        self::load_movie_import_admin();
    }

    /**
     * Load and register importer routes only for their exact private prefix.
     *
     * WordPress invokes parse_request before rest_api_init, so the route can
     * be registered normally without adding importer weight to other REST
     * traffic or exposing the route in the public REST index.
     *
     * @param WP $wp Current WordPress environment.
     */
    public static function maybe_bootstrap_movie_import_rest( $wp ) {
        $route = '';
        if ( is_object( $wp ) && isset( $wp->query_vars['rest_route'] ) ) {
            $route = (string) $wp->query_vars['rest_route'];
        } elseif ( isset( $_GET['rest_route'] ) ) {
            $route = function_exists( 'wp_unslash' )
                ? (string) wp_unslash( $_GET['rest_route'] )
                : (string) $_GET['rest_route'];
        }

        $route = '/' . ltrim( $route, '/' );
        if ( 0 !== strpos( $route, '/lunara/v1/movie-import/' ) ) {
            return;
        }

        self::load_movie_importer();
        add_action( 'rest_api_init', array( 'Lunara_Movie_Import_Admin', 'register_rest_routes' ), 5 );
    }

    /**
     * Register Review draft importer routes only for their private prefix.
     *
     * @param WP $wp Current WordPress environment.
     */
    public static function maybe_bootstrap_review_draft_import_rest( $wp ) {
        $route = '';
        if ( is_object( $wp ) && isset( $wp->query_vars['rest_route'] ) ) {
            $route = (string) $wp->query_vars['rest_route'];
        } elseif ( isset( $_GET['rest_route'] ) ) {
            $route = function_exists( 'wp_unslash' )
                ? (string) wp_unslash( $_GET['rest_route'] )
                : (string) $_GET['rest_route'];
        }

        $route = '/' . ltrim( $route, '/' );
        if ( 0 !== strpos( $route, '/lunara/v1/review-draft-import/' ) ) {
            return;
        }

        self::load_review_draft_import_admin();
        add_action( 'rest_api_init', array( 'Lunara_Review_Draft_Import_Admin', 'register_rest_routes' ), 5 );
    }

    /**
     * Plugin activation callback.
     */
    public static function activate() {
        self::register_reviews_cpt();
        self::register_review_taxonomies();
        self::register_slide_set_taxonomy();

        $entity_graph_enabled = (bool) apply_filters( 'lunara_enable_entity_graph', true );
        if ( $entity_graph_enabled ) {
            Lunara_Entities::register_post_types();
            Lunara_Entities::register_taxonomies();
        }

        flush_rewrite_rules();

        if ( $entity_graph_enabled ) {
            update_option( 'lunara_core_rewrite_version', LUNARA_CORE_VERSION );
        }
    }

    /**
     * Plugin deactivation callback.
     */
    public static function deactivate() {
        flush_rewrite_rules();
    }

    /**
     * Register the Reviews post type.
     */
    public static function register_reviews_cpt() {
        register_post_type(
            'review',
            array(
                'labels' => array(
                    'name'          => __( 'Reviews', 'lunara-core' ),
                    'singular_name' => __( 'Review', 'lunara-core' ),
                    'add_new'       => __( 'Add New Review', 'lunara-core' ),
                    'add_new_item'  => __( 'Add New Review', 'lunara-core' ),
                    'all_items'     => __( 'All Reviews', 'lunara-core' ),
                    'edit_item'     => __( 'Edit Review', 'lunara-core' ),
                    'name_admin_bar' => __( 'Review', 'lunara-core' ),
                    'menu_name'      => __( 'Review', 'lunara-core' ),
                ),
                'public'       => true,
                'has_archive'  => true,
                'rewrite'      => array( 'slug' => 'reviews' ),
                'menu_icon'    => 'dashicons-star-filled',
                'menu_position' => 20,
                'supports'     => array( 'title', 'editor', 'thumbnail', 'excerpt', 'revisions' ),
                'taxonomies'   => array( 'category', 'post_tag' ),
                'show_in_rest' => true,
            )
        );
    }

    /**
     * Register archive taxonomies for review metadata.
     */
    public static function register_review_taxonomies() {
        register_taxonomy(
            'lunara_director',
            array( 'review' ),
            array(
                'labels' => array(
                    'name'          => __( 'Directors', 'lunara-core' ),
                    'singular_name' => __( 'Director', 'lunara-core' ),
                ),
                'public'       => true,
                'hierarchical' => false,
                'show_in_rest' => true,
                'rewrite'      => array( 'slug' => 'director' ),
            )
        );

        register_taxonomy(
            'lunara_review_year',
            array( 'review' ),
            array(
                'labels' => array(
                    'name'          => __( 'Review Years', 'lunara-core' ),
                    'singular_name' => __( 'Review Year', 'lunara-core' ),
                ),
                'public'       => true,
                'hierarchical' => false,
                'show_in_rest' => true,
                'rewrite'      => array( 'slug' => 'review-year' ),
            )
        );
    }

    /**
     * Register slide sets taxonomy for attachments.
     */
    public static function register_slide_set_taxonomy() {
        register_taxonomy(
            'lunara_slide_set',
            array( 'attachment' ),
            array(
                'labels' => array(
                    'name'          => __( 'Slide Sets', 'lunara-core' ),
                    'singular_name' => __( 'Slide Set', 'lunara-core' ),
                    'search_items'  => __( 'Search Slide Sets', 'lunara-core' ),
                    'all_items'     => __( 'All Slide Sets', 'lunara-core' ),
                    'edit_item'     => __( 'Edit Slide Set', 'lunara-core' ),
                    'update_item'   => __( 'Update Slide Set', 'lunara-core' ),
                    'add_new_item'  => __( 'Add New Slide Set', 'lunara-core' ),
                    'new_item_name' => __( 'New Slide Set Name', 'lunara-core' ),
                    'menu_name'     => __( 'Slide Sets', 'lunara-core' ),
                ),
                'public'             => false,
                'show_ui'            => true,
                'show_admin_column'  => true,
                'show_in_quick_edit' => true,
                'show_in_rest'       => true,
                'hierarchical'       => false,
                'rewrite'            => false,
                'query_var'          => false,
            )
        );
    }

    /**
     * Add the main review debrief meta box.
     */
    public function add_debrief_meta_box() {
        $title = class_exists( 'Lunara_Debrief_Studio' ) && Lunara_Debrief_Studio::is_available()
            ? __( 'Review Controls', 'lunara-core' )
            : __( 'Lunara Debrief', 'lunara-core' );

        add_meta_box(
            'lunara_debrief_meta',
            $title,
            array( $this, 'render_debrief_meta_box' ),
            'review',
            'normal',
            'high'
        );
    }

    /**
     * Render the review debrief meta box.
     *
     * @param WP_Post $post Current post object.
     */
    public function render_debrief_meta_box( $post ) {
        wp_nonce_field( 'lunara_debrief_nonce', 'lunara_debrief_nonce' );

        $score          = get_post_meta( $post->ID, '_lunara_score', true );
        $year           = get_post_meta( $post->ID, '_lunara_year', true );
        $imdb_review_id = get_post_meta( $post->ID, '_lunara_imdb_title_id', true );
        $where          = get_post_meta( $post->ID, '_lunara_where', true );
        $theme_echo            = get_post_meta( $post->ID, '_lunara_theme_echo', true );
        $counter               = get_post_meta( $post->ID, '_lunara_counter_program', true );
        $craft                 = get_post_meta( $post->ID, '_lunara_career_context', true );
        $spoiler_review_url    = get_post_meta( $post->ID, '_lunara_spoiler_review_url', true );
        $spoiler_review_label  = get_post_meta( $post->ID, '_lunara_spoiler_review_label', true );
        $spoiler_review_mode   = get_post_meta( $post->ID, '_lunara_review_spoiler_mode', true );
        if ( '' === trim( (string) $craft ) ) {
            $craft = get_post_meta( $post->ID, '_lunara_craft_mirror', true );
        }
        if ( ! in_array( $spoiler_review_mode, array( 'spoiler_free', 'full_spoiler' ), true ) ) {
            $spoiler_review_mode = 'spoiler_free';
        }
        ?>
        <style>
            .lunara-meta-field { margin-bottom: 15px; }
            .lunara-meta-field label { display: block; font-weight: 600; margin-bottom: 5px; }
            .lunara-meta-field input, .lunara-meta-field select, .lunara-meta-field textarea { width: 100%; }
            .lunara-meta-field .description { font-style: italic; color: #666; font-size: 12px; margin-top: 4px; }
            .lunara-meta-section { margin-top: 20px; padding-top: 15px; border-top: 1px solid #ddd; }
            .lunara-meta-section h4 { margin: 0 0 15px; color: #c9a961; }
            .lunara-meta-row { display: flex; gap: 20px; }
            .lunara-meta-row .lunara-meta-field { flex: 1; }
        </style>

        <div class="lunara-meta-row">
            <div class="lunara-meta-field">
                <label for="lunara_score"><?php esc_html_e( 'Score (0-5, use .5 for half stars)', 'lunara-core' ); ?></label>
                <input type="text" id="lunara_score" name="lunara_score" value="<?php echo esc_attr( $score ); ?>" placeholder="4.5">
                <p class="description"><?php esc_html_e( 'Examples: 4, 4.5, 5 -> four, four-and-a-half, and five stars.', 'lunara-core' ); ?></p>
            </div>

            <div class="lunara-meta-field">
                <label for="lunara_year"><?php esc_html_e( 'Year Released', 'lunara-core' ); ?></label>
                <select id="lunara_year" name="lunara_year">
                    <option value=""><?php esc_html_e( '— Select Year —', 'lunara-core' ); ?></option>
                    <?php
                    $current_year = (int) gmdate( 'Y' ) + 2;
                    for ( $y = $current_year; $y >= 1920; $y-- ) :
                        ?>
                        <option value="<?php echo esc_attr( $y ); ?>" <?php selected( (string) $year, (string) $y ); ?>><?php echo esc_html( $y ); ?></option>
                    <?php endfor; ?>
                </select>
            </div>
        </div>

        <div class="lunara-meta-field">
            <label for="lunara_imdb_title_id"><?php esc_html_e( 'IMDb Title ID (for this review)', 'lunara-core' ); ?></label>
            <input type="text" id="lunara_imdb_title_id" name="lunara_imdb_title_id" value="<?php echo esc_attr( $imdb_review_id ); ?>" placeholder="tt1234567">
            <p class="description"><?php esc_html_e( 'Connects this review to the Oscars database film page.', 'lunara-core' ); ?></p>
        </div>

        <div class="lunara-meta-field">
            <label for="lunara_where"><?php esc_html_e( 'Where to Watch', 'lunara-core' ); ?></label>
            <input type="text" id="lunara_where" name="lunara_where" value="<?php echo esc_attr( $where ); ?>" placeholder="Netflix, Max, Theaters">
        </div>

        <?php if ( ! class_exists( 'Lunara_Debrief_Studio' ) || ! Lunara_Debrief_Studio::is_available() ) : ?>
        <div class="lunara-meta-section">
            <h4><?php esc_html_e( 'PAIR IT WITH', 'lunara-core' ); ?></h4>

            <div class="lunara-meta-field">
                <label for="lunara_theme_echo"><?php esc_html_e( 'Theme Echo', 'lunara-core' ); ?></label>
                <input type="text" id="lunara_theme_echo" name="lunara_theme_echo" value="<?php echo esc_attr( $theme_echo ); ?>" placeholder="Film that shares thematic DNA">
                <p class="description"><?php esc_html_e( 'Optionally append a tt-id or IMDb URL for direct links.', 'lunara-core' ); ?></p>
            </div>

            <div class="lunara-meta-field">
                <label for="lunara_counter_program"><?php esc_html_e( 'Counter-Program', 'lunara-core' ); ?></label>
                <input type="text" id="lunara_counter_program" name="lunara_counter_program" value="<?php echo esc_attr( $counter ); ?>" placeholder="Film that offers opposing perspective">
                <p class="description"><?php esc_html_e( 'Optionally append a tt-id or IMDb URL for direct links.', 'lunara-core' ); ?></p>
            </div>

            <div class="lunara-meta-field">
                <label for="lunara_career_context"><?php esc_html_e( 'Career Context (Optional)', 'lunara-core' ); ?></label>
                <input type="text" id="lunara_career_context" name="lunara_career_context" value="<?php echo esc_attr( $craft ); ?>" placeholder="Film that clarifies this artist's career or creative trajectory">
                <p class="description"><?php esc_html_e( 'Optionally append a tt-id or IMDb URL for direct links.', 'lunara-core' ); ?></p>
            </div>

            <?php
            if ( function_exists( 'lunara_render_pair_it_with_admin_preview' ) ) {
                echo lunara_render_pair_it_with_admin_preview(
                    $post->ID,
                    array(
                        __( 'Theme Echo', 'lunara-core' )      => $theme_echo,
                        __( 'Counter-Program', 'lunara-core' ) => $counter,
                        __( 'Career Context', 'lunara-core' )  => $craft,
                    )
                );
            }
            ?>
        </div>
        <?php endif; ?>

        <div class="lunara-meta-section">
            <h4><?php esc_html_e( 'SPOILER REVIEW BRIDGE', 'lunara-core' ); ?></h4>

            <div class="lunara-meta-field">
                <label for="lunara_review_spoiler_mode"><?php esc_html_e( 'Review Spoiler Mode', 'lunara-core' ); ?></label>
                <select id="lunara_review_spoiler_mode" name="lunara_review_spoiler_mode">
                    <option value="spoiler_free" <?php selected( $spoiler_review_mode, 'spoiler_free' ); ?>><?php esc_html_e( 'Spoiler-free review', 'lunara-core' ); ?></option>
                    <option value="full_spoiler" <?php selected( $spoiler_review_mode, 'full_spoiler' ); ?>><?php esc_html_e( 'Full spoiler review', 'lunara-core' ); ?></option>
                </select>
                <p class="description"><?php esc_html_e( 'Use Full spoiler review for companion pieces that openly discuss endings, reveals, deaths, twists, and final images.', 'lunara-core' ); ?></p>
            </div>

            <div class="lunara-meta-field">
                <label for="lunara_spoiler_review_url"><?php esc_html_e( 'Full Spoiler Review URL', 'lunara-core' ); ?></label>
                <input type="url" id="lunara_spoiler_review_url" name="lunara_spoiler_review_url" value="<?php echo esc_url( $spoiler_review_url ); ?>" placeholder="<?php echo esc_attr( home_url( '/reviews/example-spoiler-review/' ) ); ?>">
                <p class="description"><?php esc_html_e( 'Optional. When this spoiler-free review has a full spoiler companion, paste the companion review URL here.', 'lunara-core' ); ?></p>
            </div>

            <div class="lunara-meta-field">
                <label for="lunara_spoiler_review_label"><?php esc_html_e( 'Spoiler Link Label', 'lunara-core' ); ?></label>
                <input type="text" id="lunara_spoiler_review_label" name="lunara_spoiler_review_label" value="<?php echo esc_attr( $spoiler_review_label ); ?>" placeholder="<?php esc_attr_e( 'Read the full spoiler review', 'lunara-core' ); ?>">
                <p class="description"><?php esc_html_e( 'Leave blank for the default CTA. Manual URL wins; otherwise the theme may auto-detect a spoiler-category Review with the same IMDb ID.', 'lunara-core' ); ?></p>
            </div>
        </div>
        <?php
    }

    /**
     * Persist review debrief fields.
     *
     * @param int $post_id Review post ID.
     */
    public function save_debrief_meta( $post_id ) {
        if ( ! isset( $_POST['lunara_debrief_nonce'] ) ) {
            return;
        }

        if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['lunara_debrief_nonce'] ) ), 'lunara_debrief_nonce' ) ) {
            return;
        }

        if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
            return;
        }

        if ( ! current_user_can( 'edit_post', $post_id ) ) {
            return;
        }

        foreach ( array( 'lunara_score', 'lunara_year', 'lunara_imdb_title_id', 'lunara_where', 'lunara_theme_echo', 'lunara_counter_program' ) as $field ) {
            if ( isset( $_POST[ $field ] ) ) {
                update_post_meta( $post_id, '_' . $field, sanitize_text_field( wp_unslash( $_POST[ $field ] ) ) );
            }
        }

        if ( isset( $_POST['lunara_review_spoiler_mode'] ) ) {
            $spoiler_review_mode = sanitize_key( wp_unslash( $_POST['lunara_review_spoiler_mode'] ) );
            if ( 'full_spoiler' === $spoiler_review_mode ) {
                update_post_meta( $post_id, '_lunara_review_spoiler_mode', 'full_spoiler' );
            } else {
                delete_post_meta( $post_id, '_lunara_review_spoiler_mode' );
            }
        }

        if ( isset( $_POST['lunara_spoiler_review_url'] ) ) {
            $spoiler_review_url = esc_url_raw( wp_unslash( $_POST['lunara_spoiler_review_url'] ) );
            if ( '' === trim( (string) $spoiler_review_url ) ) {
                delete_post_meta( $post_id, '_lunara_spoiler_review_url' );
            } else {
                update_post_meta( $post_id, '_lunara_spoiler_review_url', $spoiler_review_url );
            }
        }

        if ( isset( $_POST['lunara_spoiler_review_label'] ) ) {
            $spoiler_review_label = sanitize_text_field( wp_unslash( $_POST['lunara_spoiler_review_label'] ) );
            if ( '' === trim( (string) $spoiler_review_label ) ) {
                delete_post_meta( $post_id, '_lunara_spoiler_review_label' );
            } else {
                update_post_meta( $post_id, '_lunara_spoiler_review_label', $spoiler_review_label );
            }
        }

        if ( isset( $_POST['lunara_career_context'] ) ) {
            $career_context = sanitize_text_field( wp_unslash( $_POST['lunara_career_context'] ) );
            update_post_meta( $post_id, '_lunara_career_context', $career_context );
            delete_post_meta( $post_id, '_lunara_craft_mirror' );
        } elseif ( isset( $_POST['lunara_craft_mirror'] ) ) {
            update_post_meta( $post_id, '_lunara_craft_mirror', sanitize_text_field( wp_unslash( $_POST['lunara_craft_mirror'] ) ) );
        }
    }

    /**
     * Add the supplemental review details meta box.
     */
    public function add_review_details_meta_box() {
        add_meta_box(
            'lunara_review_details_meta',
            __( 'Review Details', 'lunara-core' ),
            array( $this, 'render_review_details_meta_box' ),
            'review',
            'side',
            'default'
        );
    }

    /**
     * Render the review details meta box.
     *
     * @param WP_Post $post Current post object.
     */
    public function render_review_details_meta_box( $post ) {
        wp_nonce_field( 'lunara_review_details_nonce', 'lunara_review_details_nonce' );

        $director = get_post_meta( $post->ID, '_lunara_director', true );
        $runtime  = get_post_meta( $post->ID, '_lunara_runtime', true );
        $studio   = get_post_meta( $post->ID, '_lunara_studio', true );
        ?>
        <p><label for="lunara_director"><strong><?php esc_html_e( 'Director', 'lunara-core' ); ?></strong></label><br>
        <input type="text" name="lunara_director" id="lunara_director" value="<?php echo esc_attr( $director ); ?>" style="width:100%;"></p>

        <p><label for="lunara_runtime"><strong><?php esc_html_e( 'Runtime', 'lunara-core' ); ?></strong></label><br>
        <input type="text" name="lunara_runtime" id="lunara_runtime" value="<?php echo esc_attr( $runtime ); ?>" placeholder="142 min" style="width:100%;"></p>

        <p><label for="lunara_studio"><strong><?php esc_html_e( 'Studio / Distributor', 'lunara-core' ); ?></strong></label><br>
        <input type="text" name="lunara_studio" id="lunara_studio" value="<?php echo esc_attr( $studio ); ?>" style="width:100%;"></p>
        <?php
    }

    /**
     * Persist the supplemental review detail fields.
     *
     * @param int $post_id Review post ID.
     */
    public function save_review_details_meta( $post_id ) {
        if ( ! isset( $_POST['lunara_review_details_nonce'] ) ) {
            return;
        }

        if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['lunara_review_details_nonce'] ) ), 'lunara_review_details_nonce' ) ) {
            return;
        }

        if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
            return;
        }

        if ( ! current_user_can( 'edit_post', $post_id ) ) {
            return;
        }

        foreach ( array( 'lunara_director', 'lunara_runtime', 'lunara_studio' ) as $field ) {
            if ( isset( $_POST[ $field ] ) ) {
                update_post_meta( $post_id, '_' . $field, sanitize_text_field( wp_unslash( $_POST[ $field ] ) ) );
            }
        }
    }

    /**
     * Keep director/year taxonomies aligned with stored review metadata.
     *
     * @param int $post_id Review post ID.
     */
    public function sync_review_archive_terms( $post_id ) {
        if ( wp_is_post_revision( $post_id ) || 'review' !== get_post_type( $post_id ) ) {
            return;
        }

        $director = trim( (string) get_post_meta( $post_id, '_lunara_director', true ) );
        $year     = trim( (string) get_post_meta( $post_id, '_lunara_year', true ) );

        wp_set_object_terms( $post_id, '' !== $director ? array( $director ) : array(), 'lunara_director', false );
        wp_set_object_terms( $post_id, '' !== $year ? array( $year ) : array(), 'lunara_review_year', false );
    }

    /**
     * Add the slide-link field to attachment edit forms.
     *
     * @param array   $form_fields Existing attachment fields.
     * @param WP_Post $post        Attachment post object.
     * @return array
     */
    public function attachment_fields_to_edit( $form_fields, $post ) {
        $form_fields['lunara_slide_link'] = array(
            'label' => __( 'Carousel Link URL', 'lunara-core' ),
            'input' => 'text',
            'value' => get_post_meta( $post->ID, '_lunara_slide_link', true ),
            'helps' => __( 'Optional. If set, the carousel slide will link here.', 'lunara-core' ),
        );

        return $form_fields;
    }

    /**
     * Save the slide-link attachment field.
     *
     * @param array $post       Attachment post array.
     * @param array $attachment Submitted attachment data.
     * @return array
     */
    public function attachment_fields_to_save( $post, $attachment ) {
        if ( isset( $attachment['lunara_slide_link'] ) ) {
            $url = trim( (string) $attachment['lunara_slide_link'] );

            if ( '' === $url ) {
                delete_post_meta( $post['ID'], '_lunara_slide_link' );
            } else {
                update_post_meta( $post['ID'], '_lunara_slide_link', esc_url_raw( $url ) );
            }
        }

        return $post;
    }

    /**
     * Add the carousel manager screen under Appearance.
     */
    public function register_carousel_manager_page() {
        add_theme_page(
            __( 'Lunara Carousel', 'lunara-core' ),
            __( 'Lunara Carousel', 'lunara-core' ),
            'manage_options',
            'lunara-carousel-manager',
            array( $this, 'render_carousel_manager_page' )
        );
    }

    /**
     * Enqueue carousel admin assets only on the manager screen.
     *
     * @param string $hook Admin page hook suffix.
     */
    public function enqueue_carousel_admin_assets( $hook ) {
        if ( 'appearance_page_lunara-carousel-manager' !== $hook ) {
            return;
        }

        wp_enqueue_style(
            'lunara-core-carousel-admin',
            LUNARA_CORE_URL . 'assets/css/lunara-carousel-admin.css',
            array(),
            $this->asset_version( 'assets/css/lunara-carousel-admin.css' )
        );

        wp_enqueue_script( 'jquery-ui-sortable' );

        wp_enqueue_script(
            'lunara-core-carousel-admin',
            LUNARA_CORE_URL . 'assets/js/lunara-carousel-admin.js',
            array( 'jquery', 'jquery-ui-sortable' ),
            $this->asset_version( 'assets/js/lunara-carousel-admin.js' ),
            true
        );

        wp_localize_script(
            'lunara-core-carousel-admin',
            'LUNARA_CAROUSEL_ADMIN',
            array(
                'ajaxUrl' => admin_url( 'admin-ajax.php' ),
                'nonce'   => wp_create_nonce( 'lunara_carousel_admin' ),
            )
        );
    }

    /**
     * Render the carousel manager UI.
     */
    public function render_carousel_manager_page() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'You do not have permission to access this page.', 'lunara-core' ) );
        }

        $terms = get_terms(
            array(
                'taxonomy'   => 'lunara_slide_set',
                'hide_empty' => false,
            )
        );

        $selected = isset( $_GET['set'] ) ? sanitize_text_field( wp_unslash( $_GET['set'] ) ) : '';
        if ( '' === $selected && ! empty( $terms ) && ! is_wp_error( $terms ) ) {
            $selected = $terms[0]->slug;
        }

        echo '<div class="wrap">';
        echo '<h1>' . esc_html__( 'Lunara Carousel', 'lunara-core' ) . '</h1>';
        echo '<p><strong>' . esc_html__( 'How to update the carousel:', 'lunara-core' ) . '</strong> ' . esc_html__( 'Upload or select images in Media Library, assign them to a Slide Set, then reorder them here.', 'lunara-core' ) . '</p>';

        echo '<form method="get" action="">';
        echo '<input type="hidden" name="page" value="lunara-carousel-manager" />';
        echo '<label for="lunara-slide-set"><strong>' . esc_html__( 'Slide Set:', 'lunara-core' ) . '</strong></label> ';
        echo '<select id="lunara-slide-set" name="set">';
        if ( ! empty( $terms ) && ! is_wp_error( $terms ) ) {
            foreach ( $terms as $term ) {
                echo '<option value="' . esc_attr( $term->slug ) . '" ' . selected( $selected, $term->slug, false ) . '>' . esc_html( $term->name ) . '</option>';
            }
        }
        echo '</select> ';
        submit_button( __( 'Load', 'lunara-core' ), 'secondary', '', false );
        echo '</form>';

        if ( '' !== $selected ) {
            $attachments = get_posts(
                array(
                    'post_type'      => 'attachment',
                    'post_status'    => 'inherit',
                    'posts_per_page' => -1,
                    'orderby'        => array(
                        'menu_order' => 'ASC',
                        'date'       => 'DESC',
                    ),
                    'tax_query'      => array(
                        array(
                            'taxonomy' => 'lunara_slide_set',
                            'field'    => 'slug',
                            'terms'    => $selected,
                        ),
                    ),
                )
            );

            echo '<hr />';
            echo '<h2>' . esc_html__( 'Slides in:', 'lunara-core' ) . ' ' . esc_html( $selected ) . '</h2>';

            if ( empty( $attachments ) ) {
                echo '<p>' . esc_html__( 'No slides found in this set yet.', 'lunara-core' ) . '</p>';
            } else {
                echo '<p class="description">' . esc_html__( 'Drag and drop to reorder, then click Save Order.', 'lunara-core' ) . '</p>';
                echo '<ul id="lunara-carousel-sortable" class="lunara-carousel-sortable" data-slide-set="' . esc_attr( $selected ) . '">';
                foreach ( $attachments as $attachment ) {
                    $thumb = wp_get_attachment_image( $attachment->ID, array( 120, 120 ), true );
                    $link  = get_post_meta( $attachment->ID, '_lunara_slide_link', true );

                    echo '<li class="lunara-carousel-item" data-id="' . esc_attr( $attachment->ID ) . '">';
                    echo '<div class="lunara-carousel-thumb">' . $thumb . '</div>';
                    echo '<div class="lunara-carousel-meta">';
                    echo '<div class="lunara-carousel-title"><strong>' . esc_html( get_the_title( $attachment->ID ) ) . '</strong></div>';
                    if ( $link ) {
                        echo '<div class="lunara-carousel-link"><code>' . esc_html( $link ) . '</code></div>';
                    }
                    echo '<div class="lunara-carousel-actions"><a href="' . esc_url( get_edit_post_link( $attachment->ID ) ) . '">' . esc_html__( 'Edit', 'lunara-core' ) . '</a></div>';
                    echo '</div>';
                    echo '</li>';
                }
                echo '</ul>';
                echo '<button type="button" class="button button-primary" id="lunara-carousel-save-order">' . esc_html__( 'Save Order', 'lunara-core' ) . '</button> ';
                echo '<span id="lunara-carousel-save-status" style="margin-left:10px;"></span>';
            }
        }

        echo '</div>';
    }

    /**
     * Save the attachment ordering for a carousel set.
     */
    public function ajax_save_carousel_order() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => __( 'Permission denied.', 'lunara-core' ) ) );
        }

        check_ajax_referer( 'lunara_carousel_admin', 'nonce' );

        $slide_set = isset( $_POST['slide_set'] ) ? sanitize_title( wp_unslash( $_POST['slide_set'] ) ) : '';
        $term      = '' !== $slide_set ? get_term_by( 'slug', $slide_set, 'lunara_slide_set' ) : false;

        if ( ! ( $term instanceof WP_Term ) ) {
            wp_send_json_error( array( 'message' => __( 'Invalid slide set.', 'lunara-core' ) ) );
        }

        $order = isset( $_POST['order'] ) ? (array) wp_unslash( $_POST['order'] ) : array();
        $order = array_values(
            array_unique(
                array_filter( array_map( 'absint', $order ) )
            )
        );

        if ( empty( $order ) ) {
            wp_send_json_error( array( 'message' => __( 'No order received.', 'lunara-core' ) ) );
        }

        foreach ( $order as $id ) {
            $attachment = get_post( $id );
            if (
                ! ( $attachment instanceof WP_Post )
                || 'attachment' !== $attachment->post_type
                || ! has_term( $term->term_id, 'lunara_slide_set', $id )
            ) {
                wp_send_json_error( array( 'message' => __( 'The order contains a slide outside this set.', 'lunara-core' ) ) );
            }
        }

        foreach ( $order as $menu_order => $id ) {
            $updated = wp_update_post(
                array(
                    'ID'         => $id,
                    'menu_order' => $menu_order,
                ),
                true
            );

            if ( is_wp_error( $updated ) ) {
                wp_send_json_error( array( 'message' => __( 'A slide could not be reordered.', 'lunara-core' ) ) );
            }
        }

        wp_send_json_success(
            array(
                'message' => __( 'Order saved.', 'lunara-core' ),
                'count'   => count( $order ),
            )
        );
    }

    /**
     * Resolve plugin asset versions from file modification times when available.
     *
     * @param string $relative_path Relative path inside the plugin.
     * @return string
     */
    private function asset_version( $relative_path ) {
        $path = LUNARA_CORE_DIR . ltrim( $relative_path, '/\\' );

        if ( file_exists( $path ) ) {
            return (string) filemtime( $path );
        }

        return LUNARA_CORE_VERSION;
    }
}

register_activation_hook( LUNARA_CORE_FILE, array( 'Lunara_Core', 'activate' ) );
register_deactivation_hook( LUNARA_CORE_FILE, array( 'Lunara_Core', 'deactivate' ) );

Lunara_Core::instance();
