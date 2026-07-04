<?php
/**
 * Plugin Name: Lunara Core
 * Plugin URI: https://lunarafilm.com
 * Description: Core content models and editorial tools for Lunara Film.
 * Version: 0.2.0
 * Author: Lunara Film (Dalton Johnson)
 * Author URI: https://lunarafilm.com
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: lunara-core
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'LUNARA_CORE_VERSION', '0.2.0' );
define( 'LUNARA_CORE_FILE', __FILE__ );
define( 'LUNARA_CORE_DIR', plugin_dir_path( __FILE__ ) );
define( 'LUNARA_CORE_URL', plugin_dir_url( __FILE__ ) );

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

        // Entity graph (Design Spec 2.0 §4): movie / person / ledger_entry
        // content models + ACF schema. Phase 1 registers models only — no
        // front-end output until the graph is populated.
        require_once LUNARA_CORE_DIR . 'includes/class-lunara-entities.php';
        Lunara_Entities::init();

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
     * Plugin activation callback.
     */
    public static function activate() {
        self::register_reviews_cpt();
        self::register_review_taxonomies();
        self::register_slide_set_taxonomy();
        flush_rewrite_rules();
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
                    'edit_item'     => __( 'Edit Review', 'lunara-core' ),
                    'menu_name'     => __( 'Reviews', 'lunara-core' ),
                ),
                'public'       => true,
                'has_archive'  => true,
                'rewrite'      => array( 'slug' => 'reviews' ),
                'menu_icon'    => 'dashicons-star-filled',
                'supports'     => array( 'title', 'editor', 'thumbnail', 'excerpt' ),
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
        add_meta_box(
            'lunara_debrief_meta',
            __( 'Lunara Debrief', 'lunara-core' ),
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
            .lunara-pair-preview { margin-top: 18px; border: 1px solid #d8c38a; border-radius: 10px; background: #071523; overflow: hidden; color: #f7f1dd; }
            .lunara-pair-preview-head { display: flex; justify-content: space-between; gap: 12px; padding: 12px 14px; border-bottom: 1px solid rgba(216, 195, 138, 0.28); background: rgba(216, 195, 138, 0.08); }
            .lunara-pair-preview-head strong { color: #e4c875; text-transform: uppercase; letter-spacing: .08em; }
            .lunara-pair-preview-head span { color: #b8c3cf; font-size: 12px; }
            .lunara-pair-preview-grid { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 12px; padding: 14px; }
            .lunara-pair-preview-card { display: grid; grid-template-columns: 74px minmax(0, 1fr); gap: 12px; min-height: 122px; padding: 10px; border: 1px solid rgba(255,255,255,.12); border-radius: 8px; background: rgba(255,255,255,.045); }
            .lunara-pair-preview-card.is-warning { border-color: rgba(214, 126, 70, .85); }
            .lunara-pair-preview-card.is-empty { opacity: .72; }
            .lunara-pair-preview-media { width: 74px; aspect-ratio: 2 / 3; border-radius: 6px; overflow: hidden; background: rgba(255,255,255,.07); display: flex; align-items: center; justify-content: center; color: #8f9aa7; font-size: 11px; text-align: center; }
            .lunara-pair-preview-thumb { display: block; width: 100%; height: 100%; object-fit: cover; }
            .lunara-pair-preview-role { margin: 0 0 4px; color: #e4c875; font-size: 11px; font-weight: 700; letter-spacing: .09em; text-transform: uppercase; }
            .lunara-pair-preview-title { margin: 0 0 5px; color: #fff; font-size: 14px; line-height: 1.25; }
            .lunara-pair-preview-note { margin: 0 0 8px; color: #c8d0d8; font-size: 12px; line-height: 1.35; }
            .lunara-pair-preview-chips { display: flex; flex-wrap: wrap; gap: 5px; margin-top: 7px; }
            .lunara-pair-preview-chip { display: inline-flex; align-items: center; min-height: 20px; padding: 2px 7px; border: 1px solid rgba(228, 200, 117, .42); border-radius: 999px; color: #f2d986; font-size: 11px; line-height: 1.2; text-decoration: none; }
            .lunara-pair-preview-chip.is-muted { border-color: rgba(255,255,255,.18); color: #aeb8c4; }
            .lunara-pair-preview-warnings { margin: 8px 0 0; padding-left: 16px; color: #ffb07b; font-size: 12px; }
            .lunara-pair-preview-warnings li { margin: 0 0 4px; }
            @media (max-width: 1100px) { .lunara-pair-preview-grid { grid-template-columns: 1fr; } }
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

        if ( '' !== $director ) {
            wp_set_object_terms( $post_id, array( $director ), 'lunara_director', false );
        }

        if ( '' !== $year ) {
            wp_set_object_terms( $post_id, array( $year ), 'lunara_review_year', false );
        }
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

        $order = isset( $_POST['order'] ) ? (array) $_POST['order'] : array();
        $order = array_values( array_filter( array_map( 'intval', $order ) ) );

        if ( empty( $order ) ) {
            wp_send_json_error( array( 'message' => __( 'No order received.', 'lunara-core' ) ) );
        }

        foreach ( $order as $menu_order => $id ) {
            wp_update_post(
                array(
                    'ID'         => $id,
                    'menu_order' => $menu_order,
                )
            );
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
