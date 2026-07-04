<?php
/**
 * Lunara Entity Graph — Phase 1 (Design Spec 2.0 §4).
 *
 * Treats movies, people, and award results as distinct entities so the site
 * can grow a real knowledge graph: movie profiles, talent pages with
 * auto-built filmographies, and ledger entries wiring films into award
 * categories, years, and win states.
 *
 * Phase 1 registers the content models and their ACF field groups only —
 * nothing renders on the front end until entities are populated (Phase 2
 * bridges the Academy Awards tables into these models; Phase 3 upgrades the
 * Debrief Trinity to relational fields).
 *
 * Reversible: add_filter( 'lunara_enable_entity_graph', '__return_false' )
 * hides the whole graph again without touching stored content.
 *
 * @package Lunara_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class Lunara_Entities {

    /**
     * Wire registrations. Called once from the Lunara_Core bootstrap.
     */
    public static function init() {
        if ( ! apply_filters( 'lunara_enable_entity_graph', true ) ) {
            return;
        }

        add_action( 'init', array( __CLASS__, 'register_post_types' ), 5 );
        add_action( 'init', array( __CLASS__, 'register_taxonomies' ), 15 );
        add_action( 'init', array( __CLASS__, 'maybe_flush_rewrites' ), 99 );
        add_action( 'acf/init', array( __CLASS__, 'register_field_groups' ) );
    }

    /**
     * Register the three entity post types.
     *
     * Note: `movie` adopts the orphaned rows already present in the database
     * (the type existed in an earlier build) — registering the same slug
     * makes that content editable again instead of stranding it.
     */
    public static function register_post_types() {
        register_post_type(
            'movie',
            array(
                'labels' => array(
                    'name'          => __( 'Movies', 'lunara-core' ),
                    'singular_name' => __( 'Movie', 'lunara-core' ),
                    'add_new_item'  => __( 'Add New Movie', 'lunara-core' ),
                    'edit_item'     => __( 'Edit Movie', 'lunara-core' ),
                    'menu_name'     => __( 'Movies', 'lunara-core' ),
                ),
                'description'  => __( 'Core film entities: metadata, relationships, and award history.', 'lunara-core' ),
                'public'       => true,
                'has_archive'  => true,
                'rewrite'      => array( 'slug' => 'film' ),
                'menu_icon'    => 'dashicons-video-alt2',
                'menu_position' => 21,
                'supports'     => array( 'title', 'editor', 'thumbnail', 'excerpt' ),
                'show_in_rest' => true,
            )
        );

        register_post_type(
            'person',
            array(
                'labels' => array(
                    'name'          => __( 'People', 'lunara-core' ),
                    'singular_name' => __( 'Person', 'lunara-core' ),
                    'add_new_item'  => __( 'Add New Person', 'lunara-core' ),
                    'edit_item'     => __( 'Edit Person', 'lunara-core' ),
                    'menu_name'     => __( 'People', 'lunara-core' ),
                ),
                'description'  => __( 'Talent entities: directors, actors, and craftspeople.', 'lunara-core' ),
                'public'       => true,
                'has_archive'  => true,
                'rewrite'      => array( 'slug' => 'talent' ),
                'menu_icon'    => 'dashicons-groups',
                'menu_position' => 22,
                'supports'     => array( 'title', 'editor', 'thumbnail', 'excerpt' ),
                'show_in_rest' => true,
            )
        );

        // Relational join node: movie × category × ceremony × win state.
        // Not public — it renders THROUGH movie/person surfaces, never alone.
        register_post_type(
            'ledger_entry',
            array(
                'labels' => array(
                    'name'          => __( 'Ledger Entries', 'lunara-core' ),
                    'singular_name' => __( 'Ledger Entry', 'lunara-core' ),
                    'add_new_item'  => __( 'Add New Ledger Entry', 'lunara-core' ),
                    'edit_item'     => __( 'Edit Ledger Entry', 'lunara-core' ),
                    'menu_name'     => __( 'Oscar Ledger Entries', 'lunara-core' ),
                ),
                'description'  => __( 'Join nodes wiring movies into award categories, years, and win states.', 'lunara-core' ),
                'public'       => false,
                'show_ui'      => true,
                'menu_icon'    => 'dashicons-awards',
                'menu_position' => 23,
                'supports'     => array( 'title' ),
                'show_in_rest' => true,
            )
        );
    }

    /**
     * Studio / distributor taxonomy (spec §4A) — press contacts, campaign
     * priorities, and studio index collections hang off these terms later.
     */
    public static function register_taxonomies() {
        register_taxonomy(
            'lunara_studio',
            array( 'movie' ),
            array(
                'labels' => array(
                    'name'          => __( 'Studios', 'lunara-core' ),
                    'singular_name' => __( 'Studio', 'lunara-core' ),
                ),
                'public'       => true,
                'hierarchical' => false,
                'show_in_rest' => true,
                'rewrite'      => array( 'slug' => 'studio' ),
            )
        );
    }

    /**
     * One-time rewrite flush per plugin version, so new permalinks work
     * after a GitHub deploy (where the activation hook never re-runs).
     */
    public static function maybe_flush_rewrites() {
        if ( get_option( 'lunara_core_rewrite_version' ) !== LUNARA_CORE_VERSION ) {
            flush_rewrite_rules();
            update_option( 'lunara_core_rewrite_version', LUNARA_CORE_VERSION );
        }
    }

    /**
     * ACF field groups (spec §4A), registered in PHP so the schema is
     * versioned with the code. Requires ACF Pro for the repeater; the whole
     * block is skipped gracefully when ACF is absent.
     */
    public static function register_field_groups() {
        if ( ! function_exists( 'acf_add_local_field_group' ) ) {
            return;
        }

        acf_add_local_field_group(
            array(
                'key'    => 'group_lunara_movie',
                'title'  => 'Movie Profile',
                'fields' => array(
                    array(
                        'key'   => 'field_lunara_movie_release_year',
                        'label' => 'Release Year',
                        'name'  => 'release_year',
                        'type'  => 'number',
                        'min'   => 1888,
                        'max'   => 2100,
                        'instructions' => 'Standardized sorting across the Oscar Ledger and search filters.',
                    ),
                    array(
                        'key'          => 'field_lunara_movie_directors',
                        'label'        => 'Director(s)',
                        'name'         => 'directors',
                        'type'         => 'relationship',
                        'post_type'    => array( 'person' ),
                        'filters'      => array( 'search' ),
                        'return_format' => 'id',
                        'instructions' => 'Bridges the Career Context slot in reviews and the director index loops.',
                    ),
                    array(
                        'key'          => 'field_lunara_movie_principal_cast',
                        'label'        => 'Principal Cast',
                        'name'         => 'principal_cast',
                        'type'         => 'relationship',
                        'post_type'    => array( 'person' ),
                        'filters'      => array( 'search' ),
                        'return_format' => 'id',
                        'instructions' => 'Powers the bidirectional graph: talent pages build their filmographies from these links.',
                    ),
                    array(
                        'key'   => 'field_lunara_movie_runtime',
                        'label' => 'Runtime',
                        'name'  => 'runtime',
                        'type'  => 'text',
                        'placeholder' => '142 min',
                    ),
                    array(
                        'key'   => 'field_lunara_movie_imdb_title_id',
                        'label' => 'IMDb Title ID',
                        'name'  => 'imdb_title_id',
                        'type'  => 'text',
                        'placeholder' => 'tt0068646',
                        'instructions' => 'Bridge key into the existing Academy Awards tables, poster library, and IMDb Guard.',
                    ),
                    array(
                        'key'   => 'field_lunara_movie_tmdb_backdrop_url',
                        'label' => 'TMDB Backdrop URL',
                        'name'  => 'tmdb_backdrop_url',
                        'type'  => 'url',
                        'instructions' => 'High-res landscape still behind expanded detail panels.',
                    ),
                    array(
                        'key'          => 'field_lunara_movie_where_to_watch',
                        'label'        => 'Where to Watch Matrix',
                        'name'         => 'where_to_watch',
                        'type'         => 'repeater',
                        'layout'       => 'table',
                        'button_label' => 'Add Provider',
                        'sub_fields'   => array(
                            array(
                                'key'   => 'field_lunara_movie_wtw_provider',
                                'label' => 'Provider Name',
                                'name'  => 'provider_name',
                                'type'  => 'text',
                            ),
                            array(
                                'key'     => 'field_lunara_movie_wtw_access',
                                'label'   => 'Access Type',
                                'name'    => 'access_type',
                                'type'    => 'select',
                                'choices' => array(
                                    'stream' => 'Stream',
                                    'rent'   => 'Rent',
                                    'buy'    => 'Buy',
                                ),
                            ),
                            array(
                                'key'   => 'field_lunara_movie_wtw_url',
                                'label' => 'Link URL',
                                'name'  => 'url',
                                'type'  => 'url',
                            ),
                        ),
                    ),
                ),
                'location' => array(
                    array(
                        array(
                            'param'    => 'post_type',
                            'operator' => '==',
                            'value'    => 'movie',
                        ),
                    ),
                ),
            )
        );

        acf_add_local_field_group(
            array(
                'key'    => 'group_lunara_person',
                'title'  => 'Person Profile',
                'fields' => array(
                    array(
                        'key'     => 'field_lunara_person_roles',
                        'label'   => 'Primary Roles',
                        'name'    => 'roles',
                        'type'    => 'checkbox',
                        'choices' => array(
                            'director' => 'Director',
                            'actor'    => 'Actor',
                            'writer'   => 'Writer',
                            'craft'    => 'Craft / Below-the-line',
                        ),
                    ),
                    array(
                        'key'   => 'field_lunara_person_tmdb_id',
                        'label' => 'TMDB Person ID',
                        'name'  => 'tmdb_person_id',
                        'type'  => 'text',
                        'instructions' => 'Bridge key into the portrait queue and TMDB imagery sync.',
                    ),
                ),
                'location' => array(
                    array(
                        array(
                            'param'    => 'post_type',
                            'operator' => '==',
                            'value'    => 'person',
                        ),
                    ),
                ),
            )
        );

        acf_add_local_field_group(
            array(
                'key'    => 'group_lunara_ledger_entry',
                'title'  => 'Ledger Entry',
                'fields' => array(
                    array(
                        'key'          => 'field_lunara_ledger_movie',
                        'label'        => 'Movie',
                        'name'         => 'movie',
                        'type'         => 'post_object',
                        'post_type'    => array( 'movie' ),
                        'return_format' => 'id',
                        'required'     => 1,
                    ),
                    array(
                        'key'          => 'field_lunara_ledger_person',
                        'label'        => 'Person (for talent categories)',
                        'name'         => 'person',
                        'type'         => 'post_object',
                        'post_type'    => array( 'person' ),
                        'return_format' => 'id',
                        'allow_null'   => 1,
                    ),
                    array(
                        'key'   => 'field_lunara_ledger_category',
                        'label' => 'Award Category',
                        'name'  => 'category',
                        'type'  => 'text',
                        'placeholder' => 'Best Picture',
                        'required' => 1,
                    ),
                    array(
                        'key'   => 'field_lunara_ledger_ceremony',
                        'label' => 'Ceremony Number',
                        'name'  => 'ceremony_number',
                        'type'  => 'number',
                        'min'   => 1,
                    ),
                    array(
                        'key'   => 'field_lunara_ledger_year',
                        'label' => 'Ceremony Year',
                        'name'  => 'ceremony_year',
                        'type'  => 'number',
                        'min'   => 1929,
                        'max'   => 2100,
                    ),
                    array(
                        'key'   => 'field_lunara_ledger_won',
                        'label' => 'Won',
                        'name'  => 'won',
                        'type'  => 'true_false',
                        'ui'    => 1,
                    ),
                ),
                'location' => array(
                    array(
                        array(
                            'param'    => 'post_type',
                            'operator' => '==',
                            'value'    => 'ledger_entry',
                        ),
                    ),
                ),
            )
        );
    }
}
