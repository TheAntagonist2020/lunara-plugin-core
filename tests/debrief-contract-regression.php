<?php
/**
 * Dependency-free regression checks for the Debrief contract.
 *
 * Run with: php tests/debrief-contract-regression.php
 */

define( 'ABSPATH', __DIR__ . '/' );

$GLOBALS['lunara_debrief_test'] = array(
    'posts' => array(
        10 => array( 'post_type' => 'movie', 'post_status' => 'publish', 'title' => 'Source Film' ),
        11 => array( 'post_type' => 'movie', 'post_status' => 'publish', 'title' => 'Theme Film' ),
        12 => array( 'post_type' => 'movie', 'post_status' => 'publish', 'title' => 'Counter Film' ),
        13 => array( 'post_type' => 'movie', 'post_status' => 'publish', 'title' => 'Career Film' ),
        99 => array( 'post_type' => 'review', 'post_status' => 'publish', 'title' => 'Source Film Review' ),
    ),
    'meta' => array(
        10 => array( 'imdb_title_id' => 'tt0000010', 'release_year' => '2024' ),
        11 => array( 'imdb_title_id' => 'tt0000011', 'release_year' => '2020' ),
        12 => array( 'imdb_title_id' => 'tt0000012', 'release_year' => '2015' ),
        13 => array( 'imdb_title_id' => 'tt0000013', 'release_year' => '2010' ),
        99 => array(
            '_lunara_imdb_title_id'  => 'tt0000010',
            '_lunara_year'           => '2024',
            'debrief_status'         => 'ready',
            'theme_echo_movie'       => 11,
            'theme_echo_note'        => 'It carries the same moral question forward.',
            'counter_program_movie'  => 12,
            'counter_program_note'   => 'It turns the same pressure into comedy.',
            'career_context_movie'   => 13,
            'career_context_note'    => 'It reveals the director refining this visual idea.',
            '_lunara_career_context' => 'Current career value | tt0000013',
            '_lunara_craft_mirror'   => 'Legacy craft value | tt9999999',
        ),
    ),
);

function __( $text ) {
    return $text;
}

function absint( $value ) {
    return abs( (int) $value );
}

function get_post_meta( $post_id, $key ) {
    return $GLOBALS['lunara_debrief_test']['meta'][ $post_id ][ $key ] ?? '';
}

function get_the_title( $post_id ) {
    return $GLOBALS['lunara_debrief_test']['posts'][ $post_id ]['title'] ?? '';
}

function get_posts( $args ) {
    $ids = array();

    foreach ( $GLOBALS['lunara_debrief_test']['posts'] as $post_id => $post ) {
        if ( isset( $args['post_type'] ) && $post['post_type'] !== $args['post_type'] ) {
            continue;
        }

        $statuses = (array) ( $args['post_status'] ?? 'publish' );
        if ( ! in_array( $post['post_status'], $statuses, true ) ) {
            continue;
        }

        $meta_matches = true;
        if ( isset( $args['meta_query'] ) && is_array( $args['meta_query'] ) ) {
            $meta_matches = false;
            foreach ( $args['meta_query'] as $condition ) {
                if ( ! is_array( $condition ) || empty( $condition['key'] ) ) {
                    continue;
                }

                $actual = $GLOBALS['lunara_debrief_test']['meta'][ $post_id ][ $condition['key'] ] ?? '';
                if ( (string) $actual === (string) ( $condition['value'] ?? '' ) ) {
                    $meta_matches = true;
                    break;
                }
            }
        }

        if ( $meta_matches ) {
            $ids[] = $post_id;
        }
    }

    return array_slice( $ids, 0, (int) ( $args['posts_per_page'] ?? count( $ids ) ) );
}

function lunara_debrief_test_assert_same( $expected, $actual, $message ) {
    if ( $expected !== $actual ) {
        throw new RuntimeException(
            $message . "\nExpected: " . var_export( $expected, true ) . "\nActual: " . var_export( $actual, true )
        );
    }
}

function lunara_debrief_test_assert_true( $condition, $message ) {
    if ( ! $condition ) {
        throw new RuntimeException( $message );
    }
}

function lunara_debrief_test_issue_codes( $issues ) {
    return array_values( array_map( static function( $issue ) {
        return $issue['code'];
    }, $issues ) );
}

require dirname( __DIR__ ) . '/includes/class-lunara-debrief-contract.php';

$roles = Lunara_Debrief_Contract::roles();
lunara_debrief_test_assert_same(
    array( 'theme_echo', 'counter_program', 'career_context' ),
    array_keys( $roles ),
    'The contract must expose exactly three roles in editorial order.'
);
lunara_debrief_test_assert_same(
    array( '_lunara_career_context', '_lunara_craft_mirror' ),
    $roles['career_context']['legacy_meta_keys'],
    'Career Context must prefer the current key and preserve the Craft Mirror alias.'
);
lunara_debrief_test_assert_same(
    'career_context',
    Lunara_Debrief_Contract::normalize_role( 'Craft Mirror' ),
    'Craft Mirror must normalize to Career Context.'
);
lunara_debrief_test_assert_same(
    'tt1234567',
    Lunara_Debrief_Contract::normalize_imdb_title_id( 'https://www.imdb.com/title/TT1234567/' ),
    'IMDb URLs must normalize to a lower-case title ID.'
);

$complete_record = array(
    'status'        => 'ready',
    'review_id'     => 99,
    'reviewed_film' => array(
        'movie_id'      => 10,
        'review_id'     => 99,
        'imdb_title_id' => 'tt0000010',
        'title'         => 'Source Film',
        'year'          => '2024',
    ),
    'pairings' => array(
        array(
            'role'             => 'theme_echo',
            'film'             => array( 'movie_id' => 11, 'imdb_title_id' => 'tt0000011' ),
            'editorial_reason' => 'It carries the same moral question forward.',
        ),
        array(
            'role'             => 'counter_program',
            'film'             => array( 'movie_id' => 12, 'imdb_title_id' => 'tt0000012' ),
            'editorial_reason' => 'It turns the same pressure into comedy.',
        ),
        array(
            'role'             => 'career_context',
            'film'             => array( 'movie_id' => 13, 'imdb_title_id' => 'tt0000013' ),
            'editorial_reason' => 'It reveals the director refining this visual idea.',
        ),
    ),
);

$validation = Lunara_Debrief_Contract::validate( $complete_record );
lunara_debrief_test_assert_true( $validation['valid'], 'A complete ready Debrief must be valid.' );
lunara_debrief_test_assert_true( $validation['complete'], 'A complete ready Debrief must report complete.' );
lunara_debrief_test_assert_same( 3, count( $validation['record']['pairings'] ), 'The normalized record must contain exactly three pairings.' );

$duplicate_record = $complete_record;
$duplicate_record['pairings'][2]['film'] = $duplicate_record['pairings'][1]['film'];
$duplicate_result = Lunara_Debrief_Contract::validate( $duplicate_record );
lunara_debrief_test_assert_true( ! $duplicate_result['valid'], 'A ready Debrief cannot repeat a companion film.' );
lunara_debrief_test_assert_true(
    in_array( 'duplicate_companion_film', lunara_debrief_test_issue_codes( $duplicate_result['errors'] ), true ),
    'Duplicate companion validation must expose a stable issue code.'
);

$self_pair_record = $complete_record;
$self_pair_record['pairings'][0]['film'] = $complete_record['reviewed_film'];
$self_pair_result = Lunara_Debrief_Contract::validate( $self_pair_record );
lunara_debrief_test_assert_true( ! $self_pair_result['valid'], 'The reviewed film cannot pair with itself.' );
lunara_debrief_test_assert_true(
    in_array( 'reviewed_film_reused', lunara_debrief_test_issue_codes( $self_pair_result['errors'] ), true ),
    'Self-pairing validation must expose a stable issue code.'
);

$incomplete_result = Lunara_Debrief_Contract::validate( array( 'status' => 'incomplete' ) );
lunara_debrief_test_assert_true( $incomplete_result['valid'], 'An incomplete draft must remain editable.' );
lunara_debrief_test_assert_true( ! $incomplete_result['complete'], 'An empty draft must not report complete.' );
lunara_debrief_test_assert_true( count( $incomplete_result['warnings'] ) >= 7, 'Missing draft requirements must be reported as warnings.' );

$fixed_fields = Lunara_Debrief_Contract::normalize_record(
    array(
        'theme_echo_movie'      => 11,
        'theme_echo_note'       => 'Theme reason.',
        'counter_program_movie' => 12,
        'counter_program_note'  => 'Counter reason.',
        'career_context_movie'  => 13,
        'career_context_note'   => 'Career reason.',
        '_lunara_craft_mirror'  => 'Legacy Craft Mirror value.',
    )
);
lunara_debrief_test_assert_same( 11, $fixed_fields['pairings'][0]['film']['movie_id'], 'Fixed ACF fields must normalize without a repeater.' );
lunara_debrief_test_assert_same( 'Legacy Craft Mirror value.', $fixed_fields['pairings'][2]['legacy_value'], 'Legacy Craft Mirror data must map to Career Context.' );

$stored_record = Lunara_Debrief_Contract::record_from_review( 99 );
$stored_result = Lunara_Debrief_Contract::validate( $stored_record );
lunara_debrief_test_assert_same( 10, $stored_record['reviewed_film']['movie_id'], 'The Review IMDb bridge must resolve its local movie entity.' );
lunara_debrief_test_assert_same( 11, $stored_record['pairings'][0]['film']['movie_id'], 'Theme Echo must read the existing relational movie field.' );
lunara_debrief_test_assert_same( 'Current career value | tt0000013', $stored_record['pairings'][2]['legacy_value'], 'Current Career Context legacy text must win over Craft Mirror.' );
lunara_debrief_test_assert_true( $stored_result['valid'], 'A complete stored Review record must validate.' );

$contract_source = file_get_contents( dirname( __DIR__ ) . '/includes/class-lunara-debrief-contract.php' );
lunara_debrief_test_assert_true( false === strpos( $contract_source, 'wp_remote_get' ), 'The Core Debrief contract must never perform remote HTTP.' );
lunara_debrief_test_assert_true( false === strpos( $contract_source, 'lunara_get_title_poster_html' ), 'The Core contract must not depend on theme poster rendering.' );

echo "Debrief contract regression checks passed.\n";
