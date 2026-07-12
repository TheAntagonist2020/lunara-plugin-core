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
        99 => array( 'post_type' => 'review', 'post_status' => 'publish', 'title' => 'Source Review' ),
    ),
    'meta' => array(
        10 => array( 'imdb_title_id' => 'tt0000010', 'release_year' => '2024' ),
        11 => array( 'imdb_title_id' => 'tt0000011', 'release_year' => '2020' ),
        12 => array( 'imdb_title_id' => 'tt0000012', 'release_year' => '2015' ),
        13 => array( 'imdb_title_id' => 'tt0000013', 'release_year' => '2010' ),
        99 => array( '_lunara_imdb_title_id' => 'tt0000010', '_lunara_year' => '2024' ),
    ),
    'validation_errors' => array(),
);

function __( $text ) {
    return $text;
}

function apply_filters( $hook, $value ) {
    return $value;
}

function acf_add_local_field_group() {}

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

function get_post_meta( $post_id, $key ) {
    return $GLOBALS['lunara_studio_test']['meta'][ $post_id ][ $key ] ?? '';
}

function get_the_title( $post_id ) {
    return $GLOBALS['lunara_studio_test']['posts'][ $post_id ]['title'] ?? '';
}

function get_post_thumbnail_id( $post_id ) {
    return in_array( $post_id, array( 10, 11, 12, 13 ), true ) ? 500 + $post_id : 0;
}

function wp_get_attachment_image( $attachment_id, $size, $icon, $attrs ) {
    return '<img src="https://example.test/media/' . absint( $attachment_id ) . '.jpg" alt="' . esc_attr( $attrs['alt'] ?? '' ) . '">';
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

function lunara_studio_validate( $status, $movie_ids, $reasons ) {
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
        'post_ID'                => 99,
        'lunara_imdb_title_id'   => 'tt0000010',
        'acf'                    => $acf,
    );

    Lunara_Debrief_Studio::validate_submission();
    return $GLOBALS['lunara_studio_test']['validation_errors'];
}

require dirname( __DIR__ ) . '/includes/class-lunara-debrief-contract.php';
require dirname( __DIR__ ) . '/includes/class-lunara-debrief-studio.php';

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
lunara_studio_assert_same( 3, substr_count( $preview_html, '<article class="lunara-debrief-preview-card' ), 'Saved preview must render exactly three pairing cards.' );
lunara_studio_assert_true( false !== strpos( $preview_html, 'All three companion films' ), 'Complete preview must expose its readiness result.' );

$studio_source = file_get_contents( dirname( __DIR__ ) . '/includes/class-lunara-debrief-studio.php' );
lunara_studio_assert_true( false === strpos( $studio_source, 'wp_remote_get' ), 'The Studio must never perform remote HTTP.' );
lunara_studio_assert_true( false === strpos( $studio_source, 'update_post_meta' ), 'Studio validation and preview must not perform migrations.' );
lunara_studio_assert_true( file_exists( dirname( __DIR__ ) . '/assets/css/lunara-debrief-studio.css' ), 'Studio stylesheet is missing.' );

echo "Debrief Studio regression checks passed.\n";
