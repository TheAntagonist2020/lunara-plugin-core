<?php
/**
 * Dependency-free security checks for the Movie provider gateway.
 *
 * Run with: php tests/movie-importer-security-regression.php
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

final class Lunara_Movie_Import_Contract {
    public static $normalization_calls = 0;

    public static function normalize_imdb_title_id( $value ) {
        ++self::$normalization_calls;
        $value = strtolower( trim( (string) $value ) );
        return preg_match( '/\b(tt\d{6,9})\b/', $value, $matches ) ? $matches[1] : '';
    }
}

$GLOBALS['lunara_security_live_calls'] = 0;
function wp_safe_remote_get() {
    ++$GLOBALS['lunara_security_live_calls'];
    throw new RuntimeException( 'A regression test attempted a live provider call.' );
}

function lunara_security_assert_same( $expected, $actual, $message ) {
    if ( $expected !== $actual ) {
        throw new RuntimeException(
            $message . "\nExpected: " . var_export( $expected, true ) . "\nActual: " . var_export( $actual, true )
        );
    }
}

function lunara_security_assert_true( $condition, $message ) {
    if ( ! $condition ) {
        throw new RuntimeException( $message );
    }
}

function lunara_security_response( $data ) {
    $body = json_encode( $data );
    return array(
        'response' => array( 'code' => 200 ),
        'headers'  => array(
            'content-type'   => 'application/json',
            'content-length' => strlen( $body ),
        ),
        'body'     => $body,
    );
}

function lunara_security_success_transport( $url, $args ) {
    $parts = parse_url( $url );
    if ( 'www.omdbapi.com' === $parts['host'] ) {
        parse_str( $parts['query'], $query );
        return lunara_security_response(
            array(
                'Title'    => 'Mock Film ' . $query['i'],
                'Year'     => '2024',
                'imdbID'   => $query['i'],
                'Response' => 'True',
            )
        );
    }
    return lunara_security_response( array( 'movie_results' => array() ) );
}

$omdb_secret = 'SECURITY-OMDB-SECRET-9fd71';
$tmdb_secret = 'SECURITY-TMDB-SECRET-48ac2';
putenv( 'LUNARA_OMDB_API_KEY=' . $omdb_secret );
putenv( 'LUNARA_TMDB_API_TOKEN=' . $tmdb_secret );

require dirname( __DIR__ ) . '/includes/class-lunara-movie-provider-gateway.php';

$mock_calls = 0;
$gateway    = new Lunara_Movie_Provider_Gateway(
    static function ( $url, $args ) use ( &$mock_calls ) {
        ++$mock_calls;
        return lunara_security_success_transport( $url, $args );
    },
    static function () { return false; },
    static function () { return true; },
    static function () { return 100.0; }
);

$status = $gateway->credentials_status();
lunara_security_assert_same( array( 'omdb' => true, 'tmdb' => true, 'ready' => true ), $status, 'Credential status must expose booleans only.' );
foreach ( $status as $configured ) {
    lunara_security_assert_true( is_bool( $configured ), 'Every credential status value must be a boolean.' );
}
lunara_security_assert_true( false === strpos( serialize( $status ), $omdb_secret ), 'Credential status must never reveal the OMDb key.' );
lunara_security_assert_true( false === strpos( serialize( $status ), $tmdb_secret ), 'Credential status must never reveal the TMDb token.' );

foreach ( array( '', 'Dune', '1234567', 'tt12345', 'tt1234567890', 'https://evil.test/?id=tt1234567890', array( 'tt0111161' ) ) as $invalid_id ) {
    $invalid = $gateway->get_candidate_by_imdb( $invalid_id );
    lunara_security_assert_true( is_wp_error( $invalid ), 'Invalid IMDb input must fail before transport.' );
    lunara_security_assert_same( 'lunara_movie_invalid_imdb_id', $invalid->get_error_code(), 'Invalid IMDb input must use the stable validation code.' );
}
lunara_security_assert_same( 0, $mock_calls, 'Invalid input, including a ten-digit IMDb value, must perform zero provider calls.' );
lunara_security_assert_true( Lunara_Movie_Import_Contract::$normalization_calls > 0, 'The gateway must delegate IMDb normalization to the shared import contract when it is loaded.' );

$candidate = $gateway->get_candidate_by_imdb( 'prefix TT0111161 suffix' );
lunara_security_assert_true( is_array( $candidate ), 'A valid embedded IMDb ID must normalize and use the mocked transport.' );
lunara_security_assert_same( 'tt0111161', $candidate['imdb_title_id'], 'Normalized IMDb identity must be lowercase and bounded to six through nine digits.' );
$public_candidate = serialize( $candidate );
lunara_security_assert_true( false === strpos( $public_candidate, $omdb_secret ), 'Normalized candidates must never reveal the OMDb key.' );
lunara_security_assert_true( false === strpos( $public_candidate, $tmdb_secret ), 'Normalized candidates must never reveal the TMDb token.' );

$redaction_calls = 0;
$redaction_gateway = new Lunara_Movie_Provider_Gateway(
    static function ( $url, $args ) use ( &$redaction_calls, $omdb_secret, $tmdb_secret ) {
        ++$redaction_calls;
        return new WP_Error(
            'transport_leak',
            'Failed URL ' . $url . ' authorization=' . $tmdb_secret . ' key=' . $omdb_secret,
            array( 'request' => $args )
        );
    },
    static function () { return false; },
    static function () { return true; },
    static function () { return 500.0; }
);
$redacted = $redaction_gateway->get_candidate_by_imdb( 'tt0111161' );
lunara_security_assert_true( is_wp_error( $redacted ), 'Transport errors must be converted to a gateway error.' );
lunara_security_assert_same( 'lunara_movie_provider_unavailable', $redacted->get_error_code(), 'Transport errors must use the redacted stable code.' );
$redacted_surface = serialize(
    array(
        $redacted->get_error_code(),
        $redacted->get_error_message(),
        $redacted->get_error_data(),
    )
);
lunara_security_assert_true( false === strpos( $redacted_surface, $omdb_secret ), 'Gateway errors must redact an OMDb key leaked by a lower transport.' );
lunara_security_assert_true( false === strpos( $redacted_surface, $tmdb_secret ), 'Gateway errors must redact a TMDb token leaked by a lower transport.' );
lunara_security_assert_true( false === strpos( $redacted_surface, 'apikey=' ), 'Gateway errors must not reveal provider URLs or query names.' );

$oversize_gateway = new Lunara_Movie_Provider_Gateway(
    static function () {
        return array(
            'response' => array( 'code' => 200 ),
            'headers'  => array( 'content-type' => 'application/json' ),
            'body'     => str_repeat( 'x', Lunara_Movie_Provider_Gateway::MAX_RESPONSE_BYTES + 1 ),
        );
    },
    static function () { return false; },
    static function () { return true; }
);
$oversize = $oversize_gateway->get_candidate_by_imdb( 'tt0111161' );
lunara_security_assert_true( is_wp_error( $oversize ), 'Bodies larger than two megabytes must fail closed.' );
lunara_security_assert_same( 'lunara_movie_provider_response_too_large', $oversize->get_error_code(), 'Oversized bodies must use the stable size error code.' );

$clock = 1000.0;
$circuit_calls = 0;
$circuit_cache = array();
$circuit_transport = static function () use ( &$circuit_calls, $omdb_secret ) {
    ++$circuit_calls;
    return new WP_Error( 'low_level', 'Provider unavailable ' . $omdb_secret );
};
$circuit_cache_get = static function ( $key ) use ( &$circuit_cache ) {
    return array_key_exists( $key, $circuit_cache ) ? $circuit_cache[ $key ] : false;
};
$circuit_cache_set = static function ( $key, $value, $ttl ) use ( &$circuit_cache ) {
    $circuit_cache[ $key ] = $value;
    return true;
};
$circuit_gateway = static function () use ( $circuit_transport, $circuit_cache_get, $circuit_cache_set, &$clock ) {
    return new Lunara_Movie_Provider_Gateway(
        $circuit_transport,
        $circuit_cache_get,
        $circuit_cache_set,
        static function () use ( &$clock ) { return $clock; }
    );
};
for ( $attempt = 0; $attempt < Lunara_Movie_Provider_Gateway::CIRCUIT_FAILURE_CAP; ++$attempt ) {
    $failed = $circuit_gateway()->get_candidate_by_imdb( 'tt0111161' );
    lunara_security_assert_same( 'lunara_movie_provider_unavailable', $failed->get_error_code(), 'Initial provider failures must remain redacted.' );
    $clock += 2;
}
$blocked = $circuit_gateway()->get_candidate_by_imdb( 'tt0111161' );
lunara_security_assert_same( 'lunara_movie_provider_circuit_open', $blocked->get_error_code(), 'Three failures across fresh gateway instances must open the shared provider circuit.' );
lunara_security_assert_same( Lunara_Movie_Provider_Gateway::CIRCUIT_FAILURE_CAP, $circuit_calls, 'A persistent open circuit must block transport calls without sleeping.' );
lunara_security_assert_true( $blocked->get_error_data()['retry_after'] > 0, 'Circuit errors must provide a bounded retry interval.' );
$clock += Lunara_Movie_Provider_Gateway::CIRCUIT_OPEN_SECONDS + 1;
$after_circuit = $circuit_gateway()->get_candidate_by_imdb( 'tt0111161' );
lunara_security_assert_same( 'lunara_movie_provider_unavailable', $after_circuit->get_error_code(), 'The circuit must permit a new probe after its open interval.' );
lunara_security_assert_same( Lunara_Movie_Provider_Gateway::CIRCUIT_FAILURE_CAP + 1, $circuit_calls, 'A post-interval probe must reach the mocked transport once.' );

$detail_clock = 2000.0;
$detail_calls = 0;
$detail_cache = array();
$detail_transport = static function ( $url, $args ) use ( &$detail_calls ) {
    ++$detail_calls;
    $parts = parse_url( $url );
    if ( 'www.omdbapi.com' === $parts['host'] ) {
        parse_str( $parts['query'], $query );
        return lunara_security_response(
            array(
                'Title'    => 'Detail Circuit Film',
                'Year'     => '2020',
                'imdbID'   => $query['i'],
                'Response' => 'True',
            )
        );
    }
    if ( 0 === strpos( $parts['path'], '/3/find/' ) ) {
        return lunara_security_response(
            array( 'movie_results' => array( array( 'id' => 424242 ) ) )
        );
    }

    return new WP_Error( 'detail_unavailable', 'TMDb detail unavailable.' );
};
$detail_cache_get = static function ( $key ) use ( &$detail_cache ) {
    return array_key_exists( $key, $detail_cache ) ? $detail_cache[ $key ] : false;
};
$detail_cache_set = static function ( $key, $value, $ttl ) use ( &$detail_cache ) {
    $detail_cache[ $key ] = $value;
    return true;
};
$detail_gateway = static function () use ( $detail_transport, $detail_cache_get, $detail_cache_set, &$detail_clock ) {
    return new Lunara_Movie_Provider_Gateway(
        $detail_transport,
        $detail_cache_get,
        $detail_cache_set,
        static function () use ( &$detail_clock ) { return $detail_clock; }
    );
};
for ( $attempt = 0; $attempt < Lunara_Movie_Provider_Gateway::CIRCUIT_FAILURE_CAP; ++$attempt ) {
    $detail_failed = $detail_gateway()->get_candidate_by_imdb( 'tt7654321' );
    lunara_security_assert_same( 'lunara_movie_provider_unavailable', $detail_failed->get_error_code(), 'A repeated TMDb detail failure must remain a provider failure.' );
    $detail_clock += 2;
}
$detail_blocked = $detail_gateway()->get_candidate_by_imdb( 'tt7654321' );
lunara_security_assert_same( 'lunara_movie_provider_circuit_open', $detail_blocked->get_error_code(), 'A successful TMDb find must not erase a later detail failure from the provider circuit.' );
lunara_security_assert_same( 10, $detail_calls, 'The open TMDb circuit must stop the fourth attempt before another find or detail transport call.' );

$rate_calls = 0;
$rate_cache = array();
$rate_transport = static function ( $url, $args ) use ( &$rate_calls ) {
    ++$rate_calls;
    return lunara_security_success_transport( $url, $args );
};
$rate_cache_get = static function ( $key ) use ( &$rate_cache ) {
    return array_key_exists( $key, $rate_cache ) ? $rate_cache[ $key ] : false;
};
$rate_cache_set = static function ( $key, $value, $ttl ) use ( &$rate_cache, $omdb_secret, $tmdb_secret ) {
    lunara_security_assert_true( false === strpos( $key, $omdb_secret ), 'Persistent rate keys must exclude the OMDb secret.' );
    lunara_security_assert_true( false === strpos( $key, $tmdb_secret ), 'Persistent rate keys must exclude the TMDb secret.' );
    if ( 0 === strpos( $key, 'lunara_movie_guard_v1_' ) ) {
        lunara_security_assert_true( $ttl <= Lunara_Movie_Provider_Gateway::CIRCUIT_STATE_TTL, 'Guard state TTLs must remain bounded.' );
    }
    $rate_cache[ $key ] = $value;
    return true;
};
$rate_gateway = static function () use ( $rate_transport, $rate_cache_get, $rate_cache_set ) {
    return new Lunara_Movie_Provider_Gateway(
        $rate_transport,
        $rate_cache_get,
        $rate_cache_set,
        static function () { return 3000.0; }
    );
};
for ( $index = 0; $index < Lunara_Movie_Provider_Gateway::RATE_REQUEST_CAP; ++$index ) {
    $id = 'tt' . str_pad( (string) ( 111111 + $index ), 6, '0', STR_PAD_LEFT );
    $rate_candidate = $rate_gateway()->get_candidate_by_imdb( $id );
    lunara_security_assert_true( is_array( $rate_candidate ), 'Requests inside the shared rate cap must use the mocked transport.' );
}
$rate_limited = $rate_gateway()->get_candidate_by_imdb( 'tt222222' );
lunara_security_assert_true( is_wp_error( $rate_limited ), 'The next request in the same rate window must fail without sleeping.' );
lunara_security_assert_same( 'lunara_movie_provider_rate_limited', $rate_limited->get_error_code(), 'Cross-instance rate limiting must use the stable busy code.' );
lunara_security_assert_same( Lunara_Movie_Provider_Gateway::RATE_REQUEST_CAP * 2, $rate_calls, 'Persistent rate limiting must block before the next mocked OMDb call.' );

putenv( 'LUNARA_OMDB_API_KEY' );
putenv( 'LUNARA_TMDB_API_TOKEN' );
$missing_gateway = new Lunara_Movie_Provider_Gateway(
    static function () { throw new RuntimeException( 'Missing credentials reached transport.' ); },
    static function () { return false; },
    static function () { return true; }
);
$missing_status = $missing_gateway->credentials_status();
lunara_security_assert_same( array( 'omdb' => false, 'tmdb' => false, 'ready' => false ), $missing_status, 'Missing credentials must remain boolean configuration state.' );
$missing = $missing_gateway->get_candidate_by_imdb( 'tt0111161' );
lunara_security_assert_same( 'lunara_movie_provider_credentials_missing', $missing->get_error_code(), 'Missing credentials must fail before transport.' );
lunara_security_assert_same( $missing_status, $missing->get_error_data()['credentials'], 'Credential errors may expose only the same boolean status map.' );

$source = file_get_contents( dirname( __DIR__ ) . '/includes/class-lunara-movie-provider-gateway.php' );
lunara_security_assert_true( 0 === preg_match( '/\b(?:sleep|usleep|time_nanosleep)\s*\(/', $source ), 'The provider gateway must never block a request by sleeping.' );
lunara_security_assert_true( 0 === preg_match( '/\b(?:error_log|trigger_error|var_dump|print_r)\s*\(/', $source ), 'The provider gateway must never log credentials or raw provider responses.' );
lunara_security_assert_true( false !== strpos( $source, "'https://www.omdbapi.com/'" ), 'The OMDb HTTPS origin must be hard-coded internally.' );
lunara_security_assert_true( false !== strpos( $source, "'https://api.themoviedb.org/3/find/'" ), 'The TMDb HTTPS origin must be hard-coded internally.' );
lunara_security_assert_true( false === strpos( $source, 'CURLOPT_FOLLOWLOCATION' ), 'The gateway must not introduce redirect-following transport behavior.' );
lunara_security_assert_same( 0, $GLOBALS['lunara_security_live_calls'], 'All regression checks must use injected mocks and perform zero live HTTP calls.' );

echo "Movie importer security regression checks passed.\n";
