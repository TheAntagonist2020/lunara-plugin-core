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
        14 => array( 'post_type' => 'movie', 'post_status' => 'draft', 'title' => 'Draft Source Film' ),
        15 => array( 'post_type' => 'movie', 'post_status' => 'publish', 'title' => '' ),
        16 => array( 'post_type' => 'movie', 'post_status' => 'publish', 'title' => 'Missing IMDb Film' ),
        17 => array( 'post_type' => 'movie', 'post_status' => 'publish', 'title' => 'Missing Permalink Film', 'permalink' => '' ),
        18 => array( 'post_type' => 'movie', 'post_status' => 'publish', 'title' => 'Fallback IMDb Film' ),
        50 => array( 'post_type' => 'attachment', 'post_status' => 'inherit', 'title' => 'Poster Attachment' ),
        99 => array( 'post_type' => 'review', 'post_status' => 'publish', 'title' => 'Source Film Review' ),
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
        18 => array( '_lunara_entity_id' => 'tt0000018', 'release_year' => '2019' ),
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

function get_post_type( $post_id ) {
    return $GLOBALS['lunara_debrief_test']['posts'][ $post_id ]['post_type'] ?? '';
}

function get_post_status( $post_id ) {
    return $GLOBALS['lunara_debrief_test']['posts'][ $post_id ]['post_status'] ?? '';
}

function get_permalink( $post_id ) {
    if ( array_key_exists( 'permalink', $GLOBALS['lunara_debrief_test']['posts'][ $post_id ] ?? array() ) ) {
        return $GLOBALS['lunara_debrief_test']['posts'][ $post_id ]['permalink'];
    }

    return isset( $GLOBALS['lunara_debrief_test']['posts'][ $post_id ] )
        ? 'https://example.test/movies/' . absint( $post_id ) . '/'
        : '';
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

$public_source = Lunara_Debrief_Contract::public_movie_reference( 10, 99 );
lunara_debrief_test_assert_true(
    Lunara_Debrief_Contract::is_public_film_reference( $public_source ),
    'A published canonical Movie with title, IMDb ID, and permalink must be publicly renderable.'
);
lunara_debrief_test_assert_same( 'https://example.test/movies/10/', $public_source['permalink'], 'Public references must carry their local permalink.' );

$fallback_imdb = Lunara_Debrief_Contract::public_movie_reference_by_imdb( 'tt0000018' );
lunara_debrief_test_assert_same( 18, $fallback_imdb['movie_id'], 'Published IMDb lookup must honor the _lunara_entity_id fallback.' );
lunara_debrief_test_assert_same( 'tt0000018', $fallback_imdb['imdb_title_id'], 'Fallback entity IDs must normalize into the public reference.' );

$draft_lookup = Lunara_Debrief_Contract::public_movie_reference_by_imdb( 'tt0000014' );
lunara_debrief_test_assert_same( 0, $draft_lookup['movie_id'], 'Published IMDb lookup must never return a draft Movie.' );

$draft_source_record = $complete_record;
$draft_source_record['reviewed_film'] = Lunara_Debrief_Contract::movie_reference( 14, 99 );
$draft_source_result = Lunara_Debrief_Contract::validate( $draft_source_record );
lunara_debrief_test_assert_true( ! $draft_source_result['valid'], 'A draft source Movie cannot make a Debrief ready.' );
lunara_debrief_test_assert_true(
    in_array( 'unrenderable_reviewed_film', lunara_debrief_test_issue_codes( $draft_source_result['errors'] ), true ),
    'Draft source validation must expose the shared public-renderability issue.'
);

foreach ( array( 15, 16, 17 ) as $unrenderable_movie_id ) {
    $unrenderable_record = $complete_record;
    $unrenderable_record['pairings'][0]['film'] = Lunara_Debrief_Contract::movie_reference( $unrenderable_movie_id );
    $unrenderable_result = Lunara_Debrief_Contract::validate( $unrenderable_record );
    lunara_debrief_test_assert_true( ! $unrenderable_result['valid'], 'An incomplete public Movie record cannot make a Debrief ready.' );
    lunara_debrief_test_assert_true(
        in_array( 'unrenderable_companion_film', lunara_debrief_test_issue_codes( $unrenderable_result['errors'] ), true ),
        'Missing title, IMDb ID, or permalink must use the shared companion-renderability issue.'
    );
}

$incomplete_unrenderable = $complete_record;
$incomplete_unrenderable['status'] = 'incomplete';
$incomplete_unrenderable['pairings'][0]['film'] = Lunara_Debrief_Contract::movie_reference( 15 );
$incomplete_unrenderable_result = Lunara_Debrief_Contract::validate( $incomplete_unrenderable );
lunara_debrief_test_assert_true( $incomplete_unrenderable_result['valid'], 'Incomplete Debriefs must remain saveable with unrenderable Movie warnings.' );
lunara_debrief_test_assert_true(
    in_array( 'unrenderable_companion_film', lunara_debrief_test_issue_codes( $incomplete_unrenderable_result['warnings'] ), true ),
    'Incomplete Debriefs must report the shared renderability warning.'
);

$same_title_record = $complete_record;
$same_title_record['reviewed_film']['title'] = 'Shared Title';
$same_title_record['reviewed_film']['year']  = '2024';
$same_title_record['pairings'][0]['film']['title'] = 'Shared Title';
$same_title_record['pairings'][0]['film']['year']  = '2024';
$same_title_record['pairings'][1]['film']['title'] = 'Shared Title';
$same_title_record['pairings'][1]['film']['year']  = '2024';
$same_title_result = Lunara_Debrief_Contract::validate( $same_title_record );
$same_title_codes  = lunara_debrief_test_issue_codes( $same_title_result['errors'] );
lunara_debrief_test_assert_true( $same_title_result['valid'], 'Distinct canonical IDs must remain valid when title and year match.' );
lunara_debrief_test_assert_true(
    ! in_array( 'duplicate_companion_film', $same_title_codes, true ),
    'Title/year must not override distinct canonical companion identities.'
);
lunara_debrief_test_assert_true(
    ! in_array( 'reviewed_film_reused', $same_title_codes, true ),
    'Title/year must not create a false self-pair when canonical identities differ.'
);
lunara_debrief_test_assert_same(
    array( 'movie:11', 'imdb:tt0000011' ),
    Lunara_Debrief_Contract::film_identity_keys(
        array(
            'movie_id'      => 11,
            'imdb_title_id' => 'tt0000011',
            'title'         => 'Shared Title',
            'year'          => '2024',
        )
    ),
    'Title/year identity must be omitted when canonical IDs are available.'
);

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

$attachment_reference = Lunara_Debrief_Contract::movie_reference( 50 );
lunara_debrief_test_assert_same( 0, $attachment_reference['movie_id'], 'Non-movie post IDs must not become canonical film references.' );

$contract_source = file_get_contents( dirname( __DIR__ ) . '/includes/class-lunara-debrief-contract.php' );
lunara_debrief_test_assert_true( false === strpos( $contract_source, 'wp_remote_get' ), 'The Core Debrief contract must never perform remote HTTP.' );
lunara_debrief_test_assert_true( false === strpos( $contract_source, 'lunara_get_title_poster_html' ), 'The Core contract must not depend on theme poster rendering.' );

echo "Debrief contract regression checks passed.\n";
