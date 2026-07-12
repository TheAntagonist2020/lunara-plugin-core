<?php
/**
 * Dependency-free regression checks for the Movie import contract.
 *
 * Run with: php tests/movie-import-contract-regression.php
 */

define( 'ABSPATH', __DIR__ . '/' );

function lunara_movie_contract_assert_same( $expected, $actual, $message ) {
    if ( $expected !== $actual ) {
        fwrite( STDERR, "FAIL: {$message}\nExpected: " . var_export( $expected, true ) . "\nActual: " . var_export( $actual, true ) . "\n" );
        exit( 1 );
    }
}

function lunara_movie_contract_assert_true( $condition, $message ) {
    if ( ! $condition ) {
        fwrite( STDERR, "FAIL: {$message}\n" );
        exit( 1 );
    }
}

require dirname( __DIR__ ) . '/includes/class-lunara-debrief-contract.php';
require dirname( __DIR__ ) . '/includes/class-lunara-movie-import-contract.php';

$imdb_inputs = array(
    'tt123456'                              => 'tt123456',
    'HTTPS://WWW.IMDB.COM/TITLE/TT1234567/' => 'tt1234567',
    'Film identity: tt123456789'            => 'tt123456789',
    'tt12345'                               => '',
    'not-an-id'                             => '',
);

foreach ( $imdb_inputs as $input => $expected ) {
    lunara_movie_contract_assert_same(
        Lunara_Debrief_Contract::normalize_imdb_title_id( $input ),
        Lunara_Movie_Import_Contract::normalize_imdb_title_id( $input ),
        'Movie imports must use exactly the Debrief IMDb normalization rule.'
    );
    lunara_movie_contract_assert_same(
        $expected,
        Lunara_Movie_Import_Contract::normalize_imdb_title_id( $input ),
        'IMDb normalization must retain the supported six-to-nine digit range.'
    );
}

$fixture = array(
    'omdb' => array(
        'imdbID'    => 'TT7654321',
        'Title'     => 'Fixture Title',
        'Year'      => '2024-2025',
        'Runtime'   => '142 min',
        'Plot'      => 'A fixture-driven plot.',
        'Rated'     => 'PG-13',
        'Genre'     => 'Drama, Thriller, drama',
        'Country'   => 'United States, Canada',
        'Language'  => 'English, French',
        'Director'  => 'Director B, Director A',
        'Writer'    => 'Writer A',
        'Actors'    => 'Actor B, Actor A',
        'imdbRating'=> '7.8',
        'imdbVotes' => '12,345',
    ),
    'tmdb' => array(
        'id'                   => 98765,
        'title'                => 'Fixture Title',
        'original_title'       => 'Titre original',
        'release_date'         => '2024-05-17',
        'runtime'              => 143,
        'overview'             => 'A preferred TMDB overview.',
        'genres'               => array( array( 'name' => 'Thriller' ), array( 'name' => 'Drama' ) ),
        'production_countries' => array( array( 'name' => 'Canada' ), array( 'name' => 'United States' ) ),
        'spoken_languages'     => array( array( 'english_name' => 'French' ), array( 'english_name' => 'English' ) ),
        'tagline'              => 'The fixture is the source.',
        'status'               => 'Released',
        'budget'               => 25000000,
        'revenue'              => 75000000,
        'poster_path'          => '/poster.jpg',
        'backdrop_path'        => '/backdrop.jpg',
    ),
    'provider_payload_hashes' => array(
        'tmdb_details' => str_repeat( 'b', 64 ),
        'omdb'         => str_repeat( 'a', 64 ),
        'invalid'      => 'not-a-hash',
    ),
);

$candidate = Lunara_Movie_Import_Contract::normalize_fixture( $fixture );
lunara_movie_contract_assert_same( Lunara_Movie_Import_Contract::CANDIDATE_SCHEMA, $candidate['schema'], 'Candidate schemas must be explicit and versioned.' );
lunara_movie_contract_assert_same( 'tt7654321', $candidate['imdb_title_id'], 'Nested fixture identities must normalize.' );
lunara_movie_contract_assert_same( 'Fixture Title', $candidate['title'], 'TMDB and OMDb fixtures must normalize to one title.' );
lunara_movie_contract_assert_same( 'Titre original', $candidate['original_title'], 'Original titles must remain available for the local Movie record.' );
lunara_movie_contract_assert_same( 2024, $candidate['release_year'], 'Provider year ranges must reduce to their first valid year.' );
lunara_movie_contract_assert_same( '2024-05-17', $candidate['release_date'], 'Valid provider dates must use ISO format.' );
lunara_movie_contract_assert_same( 143, $candidate['runtime_minutes'], 'TMDB numeric runtime must win over the OMDb display string.' );
lunara_movie_contract_assert_same( 'A preferred TMDB overview.', $candidate['overview'], 'TMDB overview must remain distinct and preferred for the excerpt.' );
lunara_movie_contract_assert_same( array( 'Drama', 'Thriller' ), $candidate['genres'], 'Lists must be de-duplicated and deterministically sorted.' );
lunara_movie_contract_assert_same( array( 'Canada', 'United States' ), $candidate['countries'], 'Provider object lists must normalize by name.' );
lunara_movie_contract_assert_same( array( 'English', 'French' ), $candidate['languages'], 'Language lists must be deterministic.' );
lunara_movie_contract_assert_same( array( 'Director A', 'Director B' ), $candidate['directors'], 'CSV director fixtures must normalize without creating relationships.' );
lunara_movie_contract_assert_same( 12345, $candidate['imdb_votes'], 'Formatted vote totals must normalize to integers.' );
lunara_movie_contract_assert_same( 98765, $candidate['tmdb_id'], 'TMDB identity must normalize to a positive integer.' );
lunara_movie_contract_assert_same(
    array( 'omdb' => str_repeat( 'a', 64 ), 'tmdb_details' => str_repeat( 'b', 64 ) ),
    $candidate['provider_payload_hashes'],
    'Only valid named SHA-256 provider fingerprints may enter the candidate.'
);

$writable = Lunara_Movie_Import_Contract::writable_fields( $candidate );
lunara_movie_contract_assert_same( 'Fixture Title', $writable['post']['post_title'], 'The normalized title must feed draft creation.' );
lunara_movie_contract_assert_same( 'A preferred TMDB overview.', $writable['post']['post_excerpt'], 'Only a blank excerpt may receive the factual overview.' );
lunara_movie_contract_assert_same( '143 min', $writable['meta']['runtime'], 'Runtime must match the existing editable ACF display format.' );
lunara_movie_contract_assert_same( '20240517', $writable['meta']['release_date'], 'ACF date-picker metadata must use its raw Ymd storage format.' );
lunara_movie_contract_assert_true( ! isset( $writable['meta']['directors'] ), 'Imported names must not overwrite curated person relationships.' );
lunara_movie_contract_assert_true( ! isset( $writable['meta']['principal_cast'] ), 'Imported cast must not overwrite curated person relationships.' );
lunara_movie_contract_assert_true( ! isset( $writable['meta']['backdrop_image'] ), 'Provider paths must not masquerade as local Media Library IDs.' );

$stored_date_candidate = Lunara_Movie_Import_Contract::normalize_candidate(
    array( 'imdb_title_id' => 'tt7654321', 'title' => 'Stored Date', 'release_date' => '20240517' )
);
lunara_movie_contract_assert_same( '2024-05-17', $stored_date_candidate['release_date'], 'Raw ACF date-picker values must normalize back to ISO candidates.' );

$plan_a = array(
    'schema'    => Lunara_Movie_Import_Contract::PLAN_SCHEMA,
    'version'   => Lunara_Movie_Import_Contract::PLAN_VERSION,
    'candidate' => array( 'title' => 'A', 'identity' => array( 'imdb' => 'tt7654321', 'tmdb' => 98765 ) ),
    'context'   => array( 'role' => 'theme_echo', 'review_id' => 42 ),
);
$plan_b = array(
    'context'   => array( 'review_id' => 42, 'role' => 'theme_echo' ),
    'candidate' => array( 'identity' => array( 'tmdb' => 98765, 'imdb' => 'tt7654321' ), 'title' => 'A' ),
    'version'   => Lunara_Movie_Import_Contract::PLAN_VERSION,
    'schema'    => Lunara_Movie_Import_Contract::PLAN_SCHEMA,
);

lunara_movie_contract_assert_same(
    Lunara_Movie_Import_Contract::plan_hash( $plan_a ),
    Lunara_Movie_Import_Contract::plan_hash( $plan_b ),
    'Associative key order must not alter the stable plan hash.'
);

$sealed              = $plan_a;
$sealed['plan_hash'] = Lunara_Movie_Import_Contract::plan_hash( $sealed );
lunara_movie_contract_assert_true( Lunara_Movie_Import_Contract::verify_plan( $sealed ), 'A correctly sealed versioned plan must verify.' );
$sealed['candidate']['title'] = 'Tampered';
lunara_movie_contract_assert_true( ! Lunara_Movie_Import_Contract::verify_plan( $sealed ), 'Any plan mutation must invalidate the digest.' );

$invalid = Lunara_Movie_Import_Contract::validation_errors( array( 'title' => '', 'imdb_title_id' => 'invalid' ) );
lunara_movie_contract_assert_same( array( 'invalid_imdb_title_id', 'missing_title' ), $invalid, 'Draft creation requires both canonical identity and title.' );

$source = file_get_contents( dirname( __DIR__ ) . '/includes/class-lunara-movie-import-contract.php' );
lunara_movie_contract_assert_true( false === strpos( $source, 'wp_remote_' ), 'The import contract must never perform remote HTTP.' );

echo "Movie import contract regression checks passed.\n";
