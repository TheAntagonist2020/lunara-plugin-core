<?php
/**
 * Dependency-free regression checks for the Review-owned Debrief Studio.
 *
 * Run with: php tests/debrief-studio-regression.php
 */

define( 'ABSPATH', __DIR__ . '/' );

$GLOBALS['lunara_studio_test'] = array(
    'posts' => array(
        10 => array( 'post_type' => 'movie', 'post_status' => 'publish', 'title' => 'Source Film' ),
        11 => array( 'post_type' => 'movie', 'post_status' => 'publish', 'title' => 'Theme Film' ),
        12 => array( 'post_type' => 'movie', 'post_status' => 'publish', 'title' => 'Counter Film' ),
        13 => array( 'post_type' => 'movie', 'post_status' => 'publish', 'title' => 'Career Film' ),
        14 => array( 'post_type' => 'movie', 'post_status' => 'draft', 'title' => 'Draft Film' ),
        15 => array( 'post_type' => 'movie', 'post_status' => 'publish', 'title' => '' ),
        16 => array( 'post_type' => 'movie', 'post_status' => 'publish', 'title' => 'Missing IMDb Film' ),
        17 => array( 'post_type' => 'movie', 'post_status' => 'publish', 'title' => 'Missing Permalink Film', 'permalink' => '' ),
        50 => array( 'post_type' => 'attachment', 'post_status' => 'inherit', 'title' => 'Poster Attachment' ),
        99 => array( 'post_type' => 'review', 'post_status' => 'publish', 'title' => 'Source Review' ),
    ),
    'meta' => array(
        10 => array( 'imdb_title_id' => 'tt0000010', 'release_year' => '2024' ),
        11 => array( 'imdb_title_id' => 'tt0000011', 'release_year' => '2020' ),
        12 => array( 'imdb_title_id' => 'tt0000012', 'release_year' => '2015' ),
        13 => array( 'imdb_title_id' => 'tt0000013', 'release_year' => '2010' ),
        14 => array( 'imdb_title_id' => 'tt0000014', 'release_year' => '2025' ),
        15 => array( 'imdb_title_id' => 'tt0000015', 'release_year' => '2023' ),
        16 => array( 'release_year' => '2022' ),
        17 => array( 'imdb_title_id' => 'tt0000017', 'release_year' => '2021' ),
        99 => array( '_lunara_imdb_title_id' => 'tt0000010', '_lunara_year' => '2024' ),
    ),
    'validation_errors' => array(),
    'entity_graph_enabled' => true,
    'hooks' => array(),
    'field_groups' => array(),
);

function __( $text ) {
    return $text;
}

function apply_filters( $hook, $value ) {
    if ( 'lunara_enable_entity_graph' === $hook ) {
        return $GLOBALS['lunara_studio_test']['entity_graph_enabled'];
    }

    return $value;
}

function add_action( $hook, $callback ) {
    $GLOBALS['lunara_studio_test']['hooks'][ $hook ][] = $callback;
}

function acf_add_local_field_group( $group ) {
    $GLOBALS['lunara_studio_test']['field_groups'][ $group['key'] ] = $group;
}

function post_type_exists( $post_type ) {
    return 'movie' === $post_type;
}

function esc_html__( $text ) {
    return htmlspecialchars( $text, ENT_QUOTES, 'UTF-8' );
}

function esc_html( $text ) {
    return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
}

function esc_attr( $text ) {
    return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
}

function esc_url( $url ) {
    return (string) $url;
}

function wp_kses_post( $html ) {
    return (string) $html;
}

function home_url( $path = '' ) {
    return 'https://example.test/' . ltrim( (string) $path, '/' );
}

function absint( $value ) {
    return abs( (int) $value );
}

function wp_unslash( $value ) {
    return $value;
}

function sanitize_key( $value ) {
    return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $value ) );
}

function sanitize_textarea_field( $value ) {
    return trim( strip_tags( (string) $value ) );
}

function get_post_type( $post_id ) {
    return $GLOBALS['lunara_studio_test']['posts'][ $post_id ]['post_type'] ?? '';
}

function get_post_status( $post_id ) {
    return $GLOBALS['lunara_studio_test']['posts'][ $post_id ]['post_status'] ?? '';
}

function get_post_meta( $post_id, $key ) {
    return $GLOBALS['lunara_studio_test']['meta'][ $post_id ][ $key ] ?? '';
}

function get_the_title( $post_id ) {
    return $GLOBALS['lunara_studio_test']['posts'][ $post_id ]['title'] ?? '';
}

function get_permalink( $post_id ) {
    if ( array_key_exists( 'permalink', $GLOBALS['lunara_studio_test']['posts'][ $post_id ] ?? array() ) ) {
        return $GLOBALS['lunara_studio_test']['posts'][ $post_id ]['permalink'];
    }

    return isset( $GLOBALS['lunara_studio_test']['posts'][ $post_id ] )
        ? 'https://example.test/movies/' . absint( $post_id ) . '/'
        : '';
}

function get_post_thumbnail_id( $post_id ) {
    return in_array( $post_id, array( 10, 11, 12, 13 ), true ) ? 500 + $post_id : 0;
}

function wp_get_attachment_image( $attachment_id, $size, $icon, $attrs ) {
    return '<img src="https://example.test/media/' . absint( $attachment_id ) . '.jpg" alt="' . esc_attr( $attrs['alt'] ?? '' ) . '">';
}

function lunara_get_oscar_ledger_counts( $imdb_id ) {
    return 'tt0000012' === $imdb_id
        ? array( 'noms' => 5, 'wins' => 2 )
        : array( 'noms' => 0, 'wins' => 0 );
}

function lunara_get_internal_title_reference_url( $imdb_id ) {
    return home_url( '/oscars/title/' . strtolower( (string) $imdb_id ) . '/' );
}

function get_edit_post_link( $post_id ) {
    return 'https://example.test/wp-admin/post.php?post=' . absint( $post_id ) . '&action=edit';
}

function get_posts( $args ) {
    $ids = array();
    foreach ( $GLOBALS['lunara_studio_test']['posts'] as $post_id => $post ) {
        if ( $post['post_type'] !== ( $args['post_type'] ?? '' ) ) {
            continue;
        }
        if ( ! in_array( $post['post_status'], (array) ( $args['post_status'] ?? 'publish' ), true ) ) {
            continue;
        }
        foreach ( $args['meta_query'] ?? array() as $condition ) {
            if ( ! is_array( $condition ) || empty( $condition['key'] ) ) {
                continue;
            }
            $actual = $GLOBALS['lunara_studio_test']['meta'][ $post_id ][ $condition['key'] ] ?? '';
            if ( (string) $actual === (string) ( $condition['value'] ?? '' ) ) {
                $ids[] = $post_id;
                break;
            }
        }
    }
    return array_slice( $ids, 0, 1 );
}

function acf_add_validation_error( $selector, $message ) {
    $GLOBALS['lunara_studio_test']['validation_errors'][] = array(
        'selector' => $selector,
        'message'  => $message,
    );
}

function lunara_studio_assert_same( $expected, $actual, $message ) {
    if ( $expected !== $actual ) {
        throw new RuntimeException(
            $message . "\nExpected: " . var_export( $expected, true ) . "\nActual: " . var_export( $actual, true )
        );
    }
}

function lunara_studio_assert_true( $condition, $message ) {
    if ( ! $condition ) {
        throw new RuntimeException( $message );
    }
}

function lunara_studio_validate( $status, $movie_ids, $reasons, $source_imdb = 'tt0000010', $submit_source = true ) {
    $GLOBALS['lunara_studio_test']['validation_errors'] = array();
    $acf = array(
        Lunara_Debrief_Contract::FIELD_STATUS_KEY => $status,
    );

    $index = 0;
    foreach ( Lunara_Debrief_Contract::roles() as $definition ) {
        $acf[ $definition['movie_field_key'] ]  = $movie_ids[ $index ] ?? 0;
        $acf[ $definition['reason_field_key'] ] = $reasons[ $index ] ?? '';
        ++$index;
    }

    $_POST = array(
        'post_ID' => 99,
        'acf'     => $acf,
    );
    if ( $submit_source ) {
        $_POST['lunara_imdb_title_id'] = $source_imdb;
    }

    Lunara_Debrief_Studio::validate_submission();
    return $GLOBALS['lunara_studio_test']['validation_errors'];
}

require dirname( __DIR__ ) . '/includes/class-lunara-debrief-contract.php';
require dirname( __DIR__ ) . '/includes/class-lunara-debrief-studio.php';

$GLOBALS['lunara_studio_test']['entity_graph_enabled'] = false;
lunara_studio_assert_true(
    Lunara_Debrief_Studio::is_available(),
    'A late entity-graph filter must not hide Debrief Studio when ACF and the Film Dossier post type are available.'
);
$GLOBALS['lunara_studio_test']['entity_graph_enabled'] = true;

Lunara_Debrief_Studio::init();
$acf_init_callbacks = $GLOBALS['lunara_studio_test']['hooks']['acf/init'] ?? array();
lunara_studio_assert_true(
    in_array( array( 'Lunara_Debrief_Studio', 'register_field_group' ), $acf_init_callbacks, true ),
    'Debrief Studio must register its own ACF group instead of relying on the broader entity module.'
);
call_user_func( array( 'Lunara_Debrief_Studio', 'register_field_group' ) );
$studio_group = $GLOBALS['lunara_studio_test']['field_groups']['group_lunara_review_trinity'] ?? array();
lunara_studio_assert_same( 'Debrief Studio', $studio_group['title'] ?? '', 'The Review-owned Studio field group was not registered.' );
lunara_studio_assert_same( 'review', $studio_group['location'][0][0]['value'] ?? '', 'Debrief Studio must remain scoped to the Review post type.' );

$fields = Lunara_Debrief_Contract::acf_fields();
lunara_studio_assert_same( 15, count( $fields ), 'The Studio must expose overview, three fixed lanes, and preview fields.' );

$field_keys = array_column( $fields, 'key' );
foreach ( array(
    Lunara_Debrief_Contract::FIELD_STATUS_KEY,
    'field_lunara_review_theme_echo_movie',
    'field_lunara_review_theme_echo_note',
    'field_lunara_review_counter_program_movie',
    'field_lunara_review_counter_program_note',
    'field_lunara_review_career_context_movie',
    'field_lunara_review_career_context_note',
    Lunara_Debrief_Contract::FIELD_PREVIEW_KEY,
) as $required_key ) {
    lunara_studio_assert_true( in_array( $required_key, $field_keys, true ), 'Missing Studio field key: ' . $required_key );
}

$movie_fields = array_values( array_filter( $fields, static function( $field ) {
    return 'post_object' === ( $field['type'] ?? '' );
} ) );
lunara_studio_assert_same( 3, count( $movie_fields ), 'The Studio must contain exactly three searchable movie selectors.' );
foreach ( $movie_fields as $field ) {
    lunara_studio_assert_same( array( 'movie' ), $field['post_type'], 'Every selector must target canonical movie entities.' );
    lunara_studio_assert_same( array( 'publish' ), $field['post_status'], 'Only published movies may be selected for a public Debrief.' );
    lunara_studio_assert_same( 1, $field['ui'], 'Every movie selector must use the searchable ACF interface.' );
}

$incomplete_errors = lunara_studio_validate( 'incomplete', array(), array() );
lunara_studio_assert_same( array(), $incomplete_errors, 'Incomplete Debriefs must remain saveable.' );

$missing_errors = lunara_studio_validate( 'ready', array(), array() );
lunara_studio_assert_same( 6, count( $missing_errors ), 'Ready status must require three films and three reasons.' );

$complete_errors = lunara_studio_validate(
    'ready',
    array( 11, 12, 13 ),
    array( 'Theme reason.', 'Counter reason.', 'Career reason.' )
);
lunara_studio_assert_same( array(), $complete_errors, 'A complete three-film Debrief must pass Studio validation.' );

$missing_source_errors = lunara_studio_validate(
    'ready',
    array( 11, 12, 13 ),
    array( 'Theme reason.', 'Counter reason.', 'Career reason.' ),
    '',
    false
);
lunara_studio_assert_same( 1, count( $missing_source_errors ), 'Ready must fail when no Review IMDb value is submitted.' );
lunara_studio_assert_true(
    false !== strpos( $missing_source_errors[0]['selector'], Lunara_Debrief_Contract::FIELD_STATUS_KEY ),
    'Missing source-film validation must attach to Debrief readiness.'
);

$draft_source_errors = lunara_studio_validate(
    'ready',
    array( 11, 12, 13 ),
    array( 'Theme reason.', 'Counter reason.', 'Career reason.' ),
    'tt0000014'
);
lunara_studio_assert_same( 1, count( $draft_source_errors ), 'A draft source Movie must block Ready once.' );
lunara_studio_assert_true(
    false !== strpos( $draft_source_errors[0]['message'], 'publicly renderable' ),
    'Draft source validation must explain the public-renderability requirement.'
);

foreach ( array( 15, 16, 17 ) as $unrenderable_movie_id ) {
    $unrenderable_errors = lunara_studio_validate(
        'ready',
        array( $unrenderable_movie_id, 12, 13 ),
        array( 'Theme reason.', 'Counter reason.', 'Career reason.' )
    );
    lunara_studio_assert_same( 1, count( $unrenderable_errors ), 'An incomplete published companion must produce one precise field error.' );
    lunara_studio_assert_true(
        false !== strpos( $unrenderable_errors[0]['selector'], 'field_lunara_review_theme_echo_movie' ),
        'Public-renderability validation must attach to the submitted companion selector.'
    );
    lunara_studio_assert_true(
        false !== strpos( $unrenderable_errors[0]['message'], 'title, IMDb ID, and public permalink' ),
        'Public-renderability validation must state the complete canonical-film requirement.'
    );
}

$incomplete_unrenderable_errors = lunara_studio_validate(
    'incomplete',
    array( 15, 12, 13 ),
    array( 'Theme reason.', 'Counter reason.', 'Career reason.' )
);
lunara_studio_assert_same( array(), $incomplete_unrenderable_errors, 'Incomplete Debriefs with renderability warnings must remain saveable.' );

$attachment_errors = lunara_studio_validate(
    'ready',
    array( 50, 12, 13 ),
    array( 'Theme reason.', 'Counter reason.', 'Career reason.' )
);
lunara_studio_assert_same( 1, count( $attachment_errors ), 'A non-movie post ID must be rejected once with a precise validation error.' );
lunara_studio_assert_true(
    false !== strpos( $attachment_errors[0]['selector'], 'field_lunara_review_theme_echo_movie' ),
    'Non-movie validation must attach to the submitted movie selector.'
);
lunara_studio_assert_true(
    false !== strpos( $attachment_errors[0]['message'], 'published Movie' ),
    'Non-movie validation must explain the shared public Movie requirement.'
);

$draft_errors = lunara_studio_validate(
    'ready',
    array( 14, 12, 13 ),
    array( 'Theme reason.', 'Counter reason.', 'Career reason.' )
);
lunara_studio_assert_same( 1, count( $draft_errors ), 'A draft Movie must be rejected once with a precise validation error.' );
lunara_studio_assert_true(
    false !== strpos( $draft_errors[0]['selector'], 'field_lunara_review_theme_echo_movie' ),
    'Draft-movie validation must attach to the submitted movie selector.'
);

$duplicate_errors = lunara_studio_validate(
    'ready',
    array( 11, 12, 12 ),
    array( 'Theme reason.', 'Counter reason.', 'Career reason.' )
);
lunara_studio_assert_true( count( $duplicate_errors ) >= 1, 'Duplicate companion films must be rejected.' );
lunara_studio_assert_true(
    false !== strpos( $duplicate_errors[0]['selector'], 'field_lunara_review_career_context_movie' ),
    'Duplicate error must attach to the conflicting movie selector.'
);

$self_pair_errors = lunara_studio_validate(
    'ready',
    array( 10, 12, 13 ),
    array( 'Theme reason.', 'Counter reason.', 'Career reason.' )
);
lunara_studio_assert_true( count( $self_pair_errors ) >= 1, 'The reviewed film cannot be selected as its own companion.' );
lunara_studio_assert_true(
    false !== strpos( $self_pair_errors[0]['selector'], 'field_lunara_review_theme_echo_movie' ),
    'Self-pairing error must attach to the source-conflicting selector.'
);

$_POST = array( 'post_ID' => 99 );
ob_start();
Lunara_Debrief_Studio::render_source_summary( array() );
$source_html = ob_get_clean();
lunara_studio_assert_true( false !== strpos( $source_html, 'Source Film' ), 'Source summary must identify the Review-owned film.' );
lunara_studio_assert_true( false !== strpos( $source_html, 'Open Film Record' ), 'Linked source summary must expose the local film record.' );
lunara_studio_assert_true( false !== strpos( $source_html, 'is-linked' ), 'A public source Movie must be visually marked as linked.' );

$GLOBALS['lunara_studio_test']['meta'][99]['_lunara_imdb_title_id'] = 'tt0000014';
ob_start();
Lunara_Debrief_Studio::render_source_summary( array() );
$draft_source_html = ob_get_clean();
lunara_studio_assert_true( false !== strpos( $draft_source_html, 'is-pending' ), 'A draft source Movie must be visually marked as pending.' );
lunara_studio_assert_true( false === strpos( $draft_source_html, 'Open Film Record' ), 'A draft source Movie must not be presented as linked.' );
$GLOBALS['lunara_studio_test']['meta'][99]['_lunara_imdb_title_id'] = 'tt0000010';

$GLOBALS['lunara_studio_test']['meta'][99] = array_merge(
    $GLOBALS['lunara_studio_test']['meta'][99],
    array(
        'debrief_status'        => 'ready',
        'theme_echo_movie'      => 11,
        'theme_echo_note'       => 'Theme reason.',
        'counter_program_movie' => 12,
        'counter_program_note'  => 'Counter reason.',
        'career_context_movie'  => 13,
        'career_context_note'   => 'Career reason.',
    )
);
ob_start();
Lunara_Debrief_Studio::render_preview( array() );
$preview_html = ob_get_clean();
lunara_studio_assert_same( 3, substr_count( $preview_html, '<article class="lunara-pair-preview-card' ), 'Saved preview must render exactly three pairing cards.' );
lunara_studio_assert_true( false !== strpos( $preview_html, 'All three companion films' ), 'Complete preview must expose its readiness result.' );
lunara_studio_assert_true( false !== strpos( $preview_html, 'Pair It With Preview' ), 'Studio must retain the established rich Pair It With preview.' );
lunara_studio_assert_true( false !== strpos( $preview_html, 'https://www.imdb.com/title/tt0000011/' ), 'Every resolved pairing must expose its direct IMDb link.' );
lunara_studio_assert_true( false !== strpos( $preview_html, 'Poster ready' ), 'Resolved local posters must be visible in the Studio preview.' );
lunara_studio_assert_true( false !== strpos( $preview_html, 'Oscar Ledger: 5 noms / 2 wins' ), 'Oscar Ledger status must remain visible in the Studio preview.' );
lunara_studio_assert_true( false !== strpos( $preview_html, 'Links to Oscar page' ), 'The preview must identify its internal destination.' );

$GLOBALS['lunara_studio_test']['meta'][99] = array(
    '_lunara_imdb_title_id'  => 'tt0000010',
    '_lunara_year'           => '2024',
    '_lunara_theme_echo'     => 'Theme Film (2020) tt0000011. Legacy theme reason.',
    '_lunara_counter_program'=> 'Counter Film (2015) https://www.imdb.com/title/tt0000012/ — Legacy counter reason.',
    '_lunara_career_context' => 'Career Film (2010) — Legacy career reason. | IMDb: tt0000013',
);
ob_start();
Lunara_Debrief_Studio::render_preview( array() );
$legacy_preview_html = ob_get_clean();
lunara_studio_assert_same( 3, substr_count( $legacy_preview_html, '<article class="lunara-pair-preview-card' ), 'Legacy-only Reviews must still render exactly three rich preview cards.' );
lunara_studio_assert_true( false !== strpos( $legacy_preview_html, 'Legacy theme reason.' ), 'Legacy editorial reasons must be projected read-only into Studio.' );
lunara_studio_assert_true( false !== strpos( $legacy_preview_html, 'tt0000013' ), 'Legacy IMDb identities must remain available to the rich preview.' );
lunara_studio_assert_true( false !== strpos( $legacy_preview_html, 'legacy pairing fields' ), 'Studio must explain when it is previewing retained legacy pairing data.' );

$studio_source = file_get_contents( dirname( __DIR__ ) . '/includes/class-lunara-debrief-studio.php' );
$core_source   = file_get_contents( dirname( __DIR__ ) . '/lunara-core.php' );
lunara_studio_assert_true( false === strpos( $studio_source, 'wp_remote_get' ), 'The Studio must never perform remote HTTP.' );
lunara_studio_assert_true( false === strpos( $studio_source, 'update_post_meta' ), 'Studio validation and preview must not perform migrations.' );
lunara_studio_assert_true( file_exists( dirname( __DIR__ ) . '/assets/css/lunara-debrief-studio.css' ), 'Studio stylesheet is missing.' );
lunara_studio_assert_true( false !== strpos( $core_source, "__( 'Review Controls', 'lunara-core' )" ), 'When Studio owns pairings, the remaining legacy box must be named Review Controls rather than a second Debrief.' );

echo "Debrief Studio regression checks passed.\n";
