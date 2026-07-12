<?php
/**
 * Dependency-free provider normalization checks for the Movie importer.
 *
 * Run with: php tests/movie-importer-provider-regression.php
 */

define( 'ABSPATH', __DIR__ . '/' );

final class WP_Error {
    private $code;
    private $message;
    private $data;

    public function __construct( $code = '', $message = '', $data = array() ) {
        $this->code    = (string) $code;
        $this->message = (string) $message;
        $this->data    = $data;
    }

    public function get_error_code() {
        return $this->code;
    }

    public function get_error_message() {
        return $this->message;
    }

    public function get_error_data() {
        return $this->data;
    }
}

function is_wp_error( $value ) {
    return $value instanceof WP_Error;
}

function lunara_provider_assert_same( $expected, $actual, $message ) {
    if ( $expected !== $actual ) {
        throw new RuntimeException(
            $message . "\nExpected: " . var_export( $expected, true ) . "\nActual: " . var_export( $actual, true )
        );
    }
}

function lunara_provider_assert_true( $condition, $message ) {
    if ( ! $condition ) {
        throw new RuntimeException( $message );
    }
}

function lunara_provider_response( $data ) {
    $body = json_encode( $data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
    return array(
        'response' => array( 'code' => 200 ),
        'headers'  => array(
            'content-type'   => 'application/json; charset=utf-8',
            'content-length' => strlen( $body ),
        ),
        'body'     => $body,
    );
}

putenv( 'LUNARA_OMDB_API_KEY=provider-test-omdb-secret' );
putenv( 'LUNARA_TMDB_API_TOKEN=provider-test-tmdb-secret' );

require dirname( __DIR__ ) . '/includes/class-lunara-movie-provider-gateway.php';

$calls       = array();
$cache       = array();
$cache_writes = array();
$transport   = static function ( $url, $args ) use ( &$calls ) {
    $calls[] = array(
        'url'  => $url,
        'args' => $args,
    );

    $parts = parse_url( $url );
    parse_str( isset( $parts['query'] ) ? $parts['query'] : '', $query );

    if ( 'www.omdbapi.com' === $parts['host'] ) {
        return lunara_provider_response(
            array(
                'Title'      => 'Dune: Part Two',
                'Year'       => '2024',
                'Rated'      => 'PG-13',
                'Released'   => '01 Mar 2024',
                'Runtime'    => '166 min',
                'Genre'      => 'Action, Adventure, Drama',
                'Country'    => 'United States, Canada',
                'Language'   => 'English',
                'Plot'       => 'Paul Atreides unites with Chani and the Fremen.',
                'imdbRating' => '8.5',
                'imdbVotes'  => '612,345',
                'imdbID'     => isset( $query['i'] ) ? $query['i'] : '',
                'Response'   => 'True',
            )
        );
    }

    if ( preg_match( '#^/3/find/(tt\d+)$#', $parts['path'], $matches ) ) {
        return lunara_provider_response(
            array(
                'movie_results' => array(
                    array(
                        'id'           => 693134,
                        'title'        => 'Dune: Part Two',
                        'release_date' => '2024-02-27',
                    ),
                ),
            )
        );
    }

    if ( '/3/movie/693134' === $parts['path'] ) {
        return lunara_provider_response(
            array(
                'id'                   => 693134,
                'title'                => 'Dune: Part Two',
                'original_title'       => 'Dune: Part Two',
                'release_date'         => '2024-02-27',
                'runtime'              => 166,
                'overview'             => 'Paul faces a choice between love and the fate of the universe.',
                'tagline'              => 'Long live the fighters.',
                'status'               => 'Released',
                'budget'               => 190000000,
                'revenue'              => 714844358,
                'poster_path'          => '/1pdfLvkbY9ohJlCjQH2CZjjYVvJ.jpg',
                'backdrop_path'        => '/xOMo8BRK7PfcJv9JCnx7s5hj0PX.jpg',
                'genres'               => array(
                    array( 'id' => 878, 'name' => 'Science Fiction' ),
                    array( 'id' => 12, 'name' => 'Adventure' ),
                ),
                'production_countries' => array(
                    array( 'iso_3166_1' => 'US', 'name' => 'United States of America' ),
                    array( 'iso_3166_1' => 'CA', 'name' => 'Canada' ),
                ),
                'spoken_languages'     => array(
                    array( 'iso_639_1' => 'en', 'english_name' => 'English' ),
                    array( 'iso_639_1' => 'fr', 'english_name' => 'French' ),
                ),
                'production_companies' => array(
                    array( 'id' => 923, 'name' => 'Legendary Pictures' ),
                    array( 'id' => 174, 'name' => 'Warner Bros. Pictures' ),
                ),
                'credits'              => array(
                    'crew' => array(
                        array( 'id' => 137427, 'name' => 'Denis Villeneuve', 'department' => 'Directing', 'job' => 'Director' ),
                        array( 'id' => 137427, 'name' => 'Denis Villeneuve', 'department' => 'Writing', 'job' => 'Screenplay' ),
                        array( 'id' => 234673, 'name' => 'Jon Spaihts', 'department' => 'Writing', 'job' => 'Screenplay' ),
                    ),
                    'cast' => array(
                        array( 'id' => 1190668, 'name' => 'Timothee Chalamet', 'character' => 'Paul Atreides', 'order' => 0 ),
                        array( 'id' => 505710, 'name' => 'Zendaya', 'character' => 'Chani', 'order' => 1 ),
                    ),
                ),
                'external_ids'         => array( 'imdb_id' => 'tt15239678' ),
            )
        );
    }

    return new WP_Error( 'unexpected_request', 'Unexpected mocked request.' );
};

$cache_get = static function ( $key ) use ( &$cache ) {
    return array_key_exists( $key, $cache ) ? $cache[ $key ] : false;
};
$cache_set = static function ( $key, $value, $ttl ) use ( &$cache, &$cache_writes ) {
    $cache[ $key ] = $value;
    $cache_writes[] = array( $key, $ttl );
    return true;
};

$gateway   = new Lunara_Movie_Provider_Gateway( $transport, $cache_get, $cache_set, static function () { return 1000.0; } );
$candidate = $gateway->get_candidate_by_imdb( 'https://www.imdb.com/title/TT15239678/?ref_=fn_al_tt_1' );

lunara_provider_assert_true( is_array( $candidate ), 'A valid mocked provider exchange must return a candidate array.' );
lunara_provider_assert_same( Lunara_Movie_Provider_Gateway::SCHEMA_VERSION, $candidate['schema'], 'Candidate schema must be explicit.' );
lunara_provider_assert_same( 'tt15239678', $candidate['imdb_title_id'], 'IMDb URL input must normalize to the canonical lowercase ID.' );
lunara_provider_assert_same( 'Dune: Part Two', $candidate['title'], 'The normalized candidate must expose a canonical title.' );
lunara_provider_assert_same( 'Dune: Part Two', $candidate['original_title'], 'Original title must survive normalization.' );
lunara_provider_assert_same( 2024, $candidate['release_year'], 'Release year must be derived from the validated TMDb date.' );
lunara_provider_assert_same( '2024-02-27', $candidate['release_date'], 'Release date must use ISO format.' );
lunara_provider_assert_same( 166, $candidate['runtime_minutes'], 'Runtime must be a positive integer.' );
lunara_provider_assert_same( 693134, $candidate['tmdb_id'], 'TMDb identity must be retained as an integer.' );
lunara_provider_assert_same( 612345, $candidate['imdb_votes'], 'IMDb vote counts must be normalized to an integer.' );
lunara_provider_assert_same( 8.5, $candidate['imdb_rating'], 'IMDb ratings must be normalized to a number.' );
lunara_provider_assert_same(
    array( 'Action', 'Adventure', 'Drama', 'Science Fiction' ),
    $candidate['genres'],
    'Genre names from both providers must be unique and stably sorted.'
);
lunara_provider_assert_same( 'Denis Villeneuve', $candidate['directors'][0]['name'], 'Director credits must be normalized into structured records.' );
lunara_provider_assert_same( 'Timothee Chalamet', $candidate['cast'][0]['name'], 'Cast must retain provider billing order.' );
lunara_provider_assert_same( '/1pdfLvkbY9ohJlCjQH2CZjjYVvJ.jpg', $candidate['poster_path'], 'Only a normalized TMDb image path may leave the gateway.' );
lunara_provider_assert_same( array( 'omdb' => true, 'tmdb' => true ), $candidate['provider_status'], 'Provider availability must contain booleans only.' );

foreach ( $candidate['provider_payload_hashes'] as $hash ) {
    lunara_provider_assert_true( 1 === preg_match( '/^[a-f0-9]{64}$/', $hash ), 'Provider payloads must leave the gateway only as SHA-256 hashes.' );
}
lunara_provider_assert_true( 1 === preg_match( '/^[a-f0-9]{64}$/', $candidate['candidate_hash'] ), 'The normalized candidate must carry a stable SHA-256 hash.' );
lunara_provider_assert_same( 3, count( $calls ), 'A fully enriched candidate must use exactly OMDb, TMDb find, and TMDb detail calls.' );

foreach ( $calls as $index => $call ) {
    $parts = parse_url( $call['url'] );
    lunara_provider_assert_same( 'https', $parts['scheme'], 'Every provider call must use HTTPS.' );
    lunara_provider_assert_true(
        in_array( $parts['host'], array( 'www.omdbapi.com', 'api.themoviedb.org' ), true ),
        'Every provider call must use an exact allowlisted hostname.'
    );
    lunara_provider_assert_same( 0, $call['args']['redirection'], 'Provider redirects must be disabled.' );
    lunara_provider_assert_same( Lunara_Movie_Provider_Gateway::MAX_RESPONSE_BYTES, $call['args']['limit_response_size'], 'Transport must enforce the two-megabyte response limit.' );
}

$candidate_writes = array_values( array_filter( $cache_writes, static function ( $write ) {
    return 0 === strpos( $write[0], 'lunara_movie_v1_' );
} ) );
$guard_writes = array_values( array_filter( $cache_writes, static function ( $write ) {
    return 0 === strpos( $write[0], 'lunara_movie_guard_v1_' );
} ) );
lunara_provider_assert_same( 1, count( $candidate_writes ), 'A valid candidate must be cached once.' );
lunara_provider_assert_same( Lunara_Movie_Provider_Gateway::CACHE_TTL, $candidate_writes[0][1], 'Candidate cache TTL must remain explicit.' );
lunara_provider_assert_true( ! empty( $guard_writes ), 'Provider guard state must use the injected cache boundary.' );
foreach ( $cache_writes as $write ) {
    lunara_provider_assert_true( false === strpos( $write[0], 'provider-test-omdb-secret' ), 'Every cache key must exclude the OMDb secret.' );
    lunara_provider_assert_true( false === strpos( $write[0], 'provider-test-tmdb-secret' ), 'Every cache key must exclude the TMDb secret.' );
    if ( 0 === strpos( $write[0], 'lunara_movie_guard_v1_' ) ) {
        lunara_provider_assert_true( $write[1] <= Lunara_Movie_Provider_Gateway::CIRCUIT_STATE_TTL, 'Guard cache entries must use a bounded short TTL.' );
    }
}

$cached_gateway = new Lunara_Movie_Provider_Gateway( $transport, $cache_get, $cache_set, static function () { return 1000.0; } );
$cached = $cached_gateway->get_candidate_by_imdb( 'tt15239678' );
lunara_provider_assert_same( $candidate, $cached, 'A fresh gateway instance must reuse the exact normalized candidate cache entry.' );
lunara_provider_assert_same( 3, count( $calls ), 'A cross-instance candidate cache hit must perform zero provider calls.' );

$partial_calls = 0;
$partial_gateway = new Lunara_Movie_Provider_Gateway(
    static function ( $url, $args ) use ( &$partial_calls ) {
        ++$partial_calls;
        $parts = parse_url( $url );
        if ( 'www.omdbapi.com' === $parts['host'] ) {
            parse_str( $parts['query'], $query );
            return lunara_provider_response(
                array(
                    'Title'    => 'The Shawshank Redemption',
                    'Year'     => '1994',
                    'Runtime'  => '142 min',
                    'imdbID'   => $query['i'],
                    'Response' => 'True',
                )
            );
        }
        return lunara_provider_response( array( 'movie_results' => array() ) );
    },
    static function () { return false; },
    static function () { return true; },
    static function () { return 2000.0; }
);
$partial = $partial_gateway->get_candidate_by_imdb( 'tt0111161' );
lunara_provider_assert_true( is_array( $partial ), 'A valid OMDb candidate must remain usable when TMDb has no matching movie.' );
lunara_provider_assert_same( 0, $partial['tmdb_id'], 'No TMDb match must be represented as zero, never a fabricated identifier.' );
lunara_provider_assert_same( array( 'omdb' => true, 'tmdb' => false ), $partial['provider_status'], 'Partial provider coverage must be explicit booleans.' );
lunara_provider_assert_same( 2, $partial_calls, 'A no-match TMDb result must not trigger a detail request.' );

$invalid_json_gateway = new Lunara_Movie_Provider_Gateway(
    static function () {
        return array(
            'response' => array( 'code' => 200 ),
            'headers'  => array( 'content-type' => 'application/json' ),
            'body'     => '{invalid-json',
        );
    },
    static function () { return false; },
    static function () { return true; }
);
$invalid_json = $invalid_json_gateway->get_candidate_by_imdb( 'tt0111161' );
lunara_provider_assert_true( is_wp_error( $invalid_json ), 'Invalid provider JSON must fail closed.' );
lunara_provider_assert_same( 'lunara_movie_provider_invalid_response', $invalid_json->get_error_code(), 'Invalid JSON must return the stable redacted error code.' );

putenv( 'LUNARA_OMDB_API_KEY' );
putenv( 'LUNARA_TMDB_API_TOKEN' );

echo "Movie importer provider regression checks passed.\n";
