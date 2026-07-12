<?php
/**
 * Server-only OMDb and TMDb metadata gateway for the Movie importer.
 *
 * @package Lunara_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Fetch and normalize one film candidate without exposing provider secrets or
 * raw provider payloads to the rest of WordPress.
 */
final class Lunara_Movie_Provider_Gateway {

    const SCHEMA_VERSION       = 'lunara-movie-provider-candidate/v1';
    const CACHE_SCHEMA_VERSION = 'v1';
    const CACHE_TTL            = 43200;
    const MAX_RESPONSE_BYTES   = 2097152;
    const REQUEST_TIMEOUT      = 12;
    const RATE_WINDOW_SECONDS  = 1;
    const RATE_REQUEST_CAP     = 4;
    const RATE_STATE_TTL       = 5;
    const CIRCUIT_FAILURE_CAP  = 3;
    const CIRCUIT_OPEN_SECONDS = 60;
    const CIRCUIT_STATE_TTL    = 120;

    /** @var callable */
    private $transport;

    /** @var callable */
    private $cache_get;

    /** @var callable */
    private $cache_set;

    /** @var callable */
    private $clock;

    /** @var array<string,array<int,float>> */
    private $request_times = array();

    /** @var array<string,array<string,float|int>> */
    private $circuits = array();

    /**
     * Construct an injectable gateway. Production callers may omit every
     * dependency; tests and command-line tooling should inject a transport.
     *
     * The transport receives ($url, $request_args). Cache setters receive
     * ($key, $value, $ttl). No dependency ever receives a credential except
     * the transport request that must authenticate with the provider.
     *
     * @param callable|null $transport HTTP transport.
     * @param callable|null $cache_get Cache reader.
     * @param callable|null $cache_set Cache writer.
     * @param callable|null $clock Monotonic-enough wall clock returning seconds.
     */
    public function __construct( $transport = null, $cache_get = null, $cache_set = null, $clock = null ) {
        $this->transport = is_callable( $transport ) ? $transport : array( __CLASS__, 'default_transport' );
        $this->cache_get = is_callable( $cache_get ) ? $cache_get : array( __CLASS__, 'default_cache_get' );
        $this->cache_set = is_callable( $cache_set ) ? $cache_set : array( __CLASS__, 'default_cache_set' );
        $this->clock     = is_callable( $clock ) ? $clock : static function () {
            return microtime( true );
        };
    }

    /**
     * Report configuration availability without returning credential values.
     *
     * @return array<string,bool>
     */
    public function credentials_status() {
        $omdb = '' !== self::credential( 'LUNARA_OMDB_API_KEY' );
        $tmdb = '' !== self::credential( 'LUNARA_TMDB_API_TOKEN' );

        return array(
            'omdb'  => $omdb,
            'tmdb'  => $tmdb,
            'ready' => $omdb && $tmdb,
        );
    }

    /**
     * Fetch one normalized candidate by canonical IMDb title identifier.
     *
     * @param mixed $imdb_id IMDb title ID or a string containing one.
     * @return array<string,mixed>|WP_Error
     */
    public function get_candidate_by_imdb( $imdb_id ) {
        $imdb_id = self::normalize_imdb_id( $imdb_id );
        if ( '' === $imdb_id ) {
            return self::error( 'lunara_movie_invalid_imdb_id', 'Enter a valid IMDb title identifier.' );
        }

        $cache_key = self::cache_key( $imdb_id );
        $cached    = $this->read_cache( $cache_key );
        if ( self::is_valid_cached_candidate( $cached, $imdb_id ) ) {
            return $cached;
        }

        $credential_status = $this->credentials_status();
        if ( ! $credential_status['ready'] ) {
            return self::error(
                'lunara_movie_provider_credentials_missing',
                'Movie metadata providers are not fully configured.',
                '',
                array( 'credentials' => $credential_status )
            );
        }

        $omdb_key = self::credential( 'LUNARA_OMDB_API_KEY' );
        $omdb_url = self::build_url(
            'https://www.omdbapi.com/',
            array(
                'apikey' => $omdb_key,
                'i'      => $imdb_id,
                'plot'   => 'full',
                'r'      => 'json',
            )
        );
        $omdb     = $this->request_json( 'omdb', $omdb_url, self::request_args() );
        $omdb_key = '';
        if ( self::is_error_value( $omdb ) ) {
            return $omdb;
        }
        $this->record_success( 'omdb' );

        if ( isset( $omdb['data']['Response'] ) && 'false' === strtolower( (string) $omdb['data']['Response'] ) ) {
            return self::error( 'lunara_movie_provider_not_found', 'No matching movie was found.', 'omdb' );
        }

        $omdb_identity = self::normalize_imdb_id( isset( $omdb['data']['imdbID'] ) ? $omdb['data']['imdbID'] : '' );
        if ( $imdb_id !== $omdb_identity ) {
            $this->record_failure( 'omdb' );
            return self::error( 'lunara_movie_provider_identity_mismatch', 'The metadata provider returned a different film identity.', 'omdb' );
        }

        $tmdb_token = self::credential( 'LUNARA_TMDB_API_TOKEN' );
        $tmdb_args  = self::request_args(
            array(
                'Authorization' => 'Bearer ' . $tmdb_token,
            )
        );
        $find_url   = self::build_url(
            'https://api.themoviedb.org/3/find/' . rawurlencode( $imdb_id ),
            array(
                'external_source' => 'imdb_id',
                'language'        => 'en-US',
            )
        );
        $tmdb_find  = $this->request_json( 'tmdb', $find_url, $tmdb_args );
        if ( self::is_error_value( $tmdb_find ) ) {
            $tmdb_token = '';
            return $tmdb_find;
        }

        $tmdb_id      = self::first_tmdb_movie_id( $tmdb_find['data'] );
        $tmdb_details = array(
            'data'         => array(),
            'payload_hash' => '',
        );
        if ( $tmdb_id > 0 ) {
            $details_url  = self::build_url(
                'https://api.themoviedb.org/3/movie/' . $tmdb_id,
                array(
                    'append_to_response' => 'credits,external_ids',
                    'language'           => 'en-US',
                )
            );
            $tmdb_details = $this->request_json( 'tmdb', $details_url, $tmdb_args );
            if ( self::is_error_value( $tmdb_details ) ) {
                $tmdb_token = '';
                return $tmdb_details;
            }

            $tmdb_identity = self::normalize_imdb_id(
                isset( $tmdb_details['data']['external_ids']['imdb_id'] )
                    ? $tmdb_details['data']['external_ids']['imdb_id']
                    : ''
            );
            if ( '' !== $tmdb_identity && $imdb_id !== $tmdb_identity ) {
                $tmdb_token = '';
                $this->record_failure( 'tmdb' );
                return self::error( 'lunara_movie_provider_identity_mismatch', 'The metadata provider returned a different film identity.', 'tmdb' );
            }
        }
        $tmdb_token = '';
        $this->record_success( 'tmdb' );

        $candidate = self::normalize_candidate(
            $imdb_id,
            $omdb,
            $tmdb_find,
            $tmdb_details,
            $tmdb_id
        );
        if ( '' === $candidate['title'] ) {
            return self::error( 'lunara_movie_provider_invalid_response', 'The metadata provider response was incomplete.', 'omdb' );
        }

        $candidate['candidate_hash'] = hash( 'sha256', self::stable_json( $candidate ) );
        $this->write_cache( $cache_key, $candidate, self::CACHE_TTL );

        return $candidate;
    }

    /**
     * WordPress production transport.
     *
     * @param string              $url Request URL.
     * @param array<string,mixed> $args Request arguments.
     * @return array<string,mixed>|WP_Error
     */
    private static function default_transport( $url, $args ) {
        if ( ! function_exists( 'wp_safe_remote_get' ) ) {
            return self::error( 'lunara_movie_provider_transport_unavailable', 'Movie metadata transport is unavailable.' );
        }

        return wp_safe_remote_get( $url, $args );
    }

    /**
     * Default transient reader.
     *
     * @param string $key Cache key.
     * @return mixed
     */
    private static function default_cache_get( $key ) {
        return function_exists( 'get_transient' ) ? get_transient( $key ) : false;
    }

    /**
     * Default transient writer.
     *
     * @param string $key Cache key.
     * @param mixed  $value Cache value.
     * @param int    $ttl Lifetime.
     * @return bool
     */
    private static function default_cache_set( $key, $value, $ttl ) {
        return function_exists( 'set_transient' ) ? (bool) set_transient( $key, $value, $ttl ) : false;
    }

    /**
     * Perform one allowlisted request and validate its bounded JSON response.
     *
     * @param string              $provider Provider identifier.
     * @param string              $url Internally constructed URL.
     * @param array<string,mixed> $args Request arguments.
     * @return array<string,mixed>|WP_Error
     */
    private function request_json( $provider, $url, $args ) {
        $guard = $this->request_guard( $provider );
        if ( self::is_error_value( $guard ) ) {
            return $guard;
        }

        if ( ! self::is_allowed_url( $provider, $url ) ) {
            $this->record_failure( $provider );
            return self::error( 'lunara_movie_provider_url_rejected', 'The metadata provider request was rejected.', $provider );
        }

        $this->record_request( $provider );
        try {
            $response = call_user_func( $this->transport, $url, $args );
        } catch ( Throwable $exception ) {
            $this->record_failure( $provider );
            return self::error( 'lunara_movie_provider_unavailable', 'The metadata provider is temporarily unavailable.', $provider );
        }

        if ( self::is_error_value( $response ) ) {
            $this->record_failure( $provider );
            return self::error( 'lunara_movie_provider_unavailable', 'The metadata provider is temporarily unavailable.', $provider );
        }

        $status = self::response_code( $response );
        if ( 429 === $status ) {
            $this->record_failure( $provider );
            return self::error(
                'lunara_movie_provider_rate_limited',
                'The metadata provider is temporarily busy. Try again shortly.',
                $provider,
                array( 'retry_after' => self::safe_retry_after( self::response_header( $response, 'retry-after' ) ) )
            );
        }
        if ( 404 === $status ) {
            return self::error( 'lunara_movie_provider_not_found', 'No matching movie was found.', $provider );
        }
        if ( 200 !== $status ) {
            $this->record_failure( $provider );
            return self::error( 'lunara_movie_provider_unavailable', 'The metadata provider is temporarily unavailable.', $provider );
        }

        $content_length = (int) self::response_header( $response, 'content-length' );
        if ( $content_length > self::MAX_RESPONSE_BYTES ) {
            $this->record_failure( $provider );
            return self::error( 'lunara_movie_provider_response_too_large', 'The metadata provider response exceeded the allowed size.', $provider );
        }

        $content_type = strtolower( (string) self::response_header( $response, 'content-type' ) );
        if ( '' !== $content_type && false === strpos( $content_type, 'json' ) ) {
            $this->record_failure( $provider );
            return self::error( 'lunara_movie_provider_invalid_response', 'The metadata provider returned an invalid response.', $provider );
        }

        $body = self::response_body( $response );
        if ( ! is_string( $body ) || '' === $body || strlen( $body ) > self::MAX_RESPONSE_BYTES ) {
            $this->record_failure( $provider );
            $code = is_string( $body ) && strlen( $body ) > self::MAX_RESPONSE_BYTES
                ? 'lunara_movie_provider_response_too_large'
                : 'lunara_movie_provider_invalid_response';
            return self::error( $code, 'The metadata provider returned an invalid response.', $provider );
        }

        $decoded = json_decode( $body, true, 32 );
        if ( JSON_ERROR_NONE !== json_last_error() || ! is_array( $decoded ) ) {
            $this->record_failure( $provider );
            return self::error( 'lunara_movie_provider_invalid_response', 'The metadata provider returned an invalid response.', $provider );
        }

        return array(
            'data'         => $decoded,
            'payload_hash' => hash( 'sha256', $body ),
        );
    }

    /**
     * Enforce local no-sleep rate and circuit limits before a request.
     *
     * @param string $provider Provider identifier.
     * @return true|WP_Error
     */
    private function request_guard( $provider ) {
        $now     = $this->now();
        $circuit = $this->circuit_state( $provider, $now );
        $this->circuits[ $provider ] = $circuit;
        if ( isset( $circuit['open_until'] ) && (float) $circuit['open_until'] > $now ) {
            return self::error(
                'lunara_movie_provider_circuit_open',
                'The metadata provider is temporarily unavailable.',
                $provider,
                array( 'retry_after' => max( 1, (int) ceil( (float) $circuit['open_until'] - $now ) ) )
            );
        }

        $recent = array();
        foreach ( $this->rate_state( $provider, $now ) as $timestamp ) {
            if ( $timestamp > $now - self::RATE_WINDOW_SECONDS ) {
                $recent[] = $timestamp;
            }
        }
        $this->request_times[ $provider ] = $recent;
        $this->write_guard_state( $provider, 'rate', $recent, self::RATE_STATE_TTL );
        if ( count( $recent ) >= self::RATE_REQUEST_CAP ) {
            return self::error(
                'lunara_movie_provider_rate_limited',
                'The metadata provider is temporarily busy. Try again shortly.',
                $provider,
                array( 'retry_after' => 1 )
            );
        }

        return true;
    }

    /** @param string $provider Provider identifier. */
    private function record_request( $provider ) {
        $this->request_times[ $provider ][] = $this->now();
        $this->request_times[ $provider ] = array_slice(
            $this->request_times[ $provider ],
            -self::RATE_REQUEST_CAP
        );
        $this->write_guard_state(
            $provider,
            'rate',
            $this->request_times[ $provider ],
            self::RATE_STATE_TTL
        );
    }

    /** @param string $provider Provider identifier. */
    private function record_failure( $provider ) {
        $state = $this->circuit_state( $provider, $this->now() );
        $state['failures'] = min( self::CIRCUIT_FAILURE_CAP, (int) $state['failures'] + 1 );
        if ( $state['failures'] >= self::CIRCUIT_FAILURE_CAP ) {
            $state['open_until'] = $this->now() + self::CIRCUIT_OPEN_SECONDS;
        }
        $this->circuits[ $provider ] = $state;
        $this->write_guard_state( $provider, 'circuit', $state, self::CIRCUIT_STATE_TTL );
    }

    /** @param string $provider Provider identifier. */
    private function record_success( $provider ) {
        $this->circuits[ $provider ] = array(
            'failures'   => 0,
            'open_until' => 0,
        );
        $this->write_guard_state(
            $provider,
            'circuit',
            $this->circuits[ $provider ],
            self::CIRCUIT_STATE_TTL
        );
    }

    /**
     * Load a bounded rolling request history from persistent cache, falling
     * back to the current instance when no cache backend is available.
     *
     * @param string $provider Provider identifier.
     * @param float  $now Current timestamp.
     * @return array<int,float>
     */
    private function rate_state( $provider, $now ) {
        $stored = $this->read_cache( self::guard_cache_key( $provider, 'rate' ) );
        $source = is_array( $stored )
            ? $stored
            : ( isset( $this->request_times[ $provider ] ) ? $this->request_times[ $provider ] : array() );
        $times  = array();
        foreach ( $source as $timestamp ) {
            if ( ! is_numeric( $timestamp ) ) {
                continue;
            }
            $timestamp = (float) $timestamp;
            if ( $timestamp > $now - self::RATE_STATE_TTL && $timestamp <= $now + self::RATE_WINDOW_SECONDS ) {
                $times[] = $timestamp;
            }
        }
        sort( $times, SORT_NUMERIC );

        return array_slice( $times, -self::RATE_REQUEST_CAP );
    }

    /**
     * Load and clamp persistent circuit state.
     *
     * @param string $provider Provider identifier.
     * @param float  $now Current timestamp.
     * @return array<string,float|int>
     */
    private function circuit_state( $provider, $now ) {
        $stored = $this->read_cache( self::guard_cache_key( $provider, 'circuit' ) );
        $source = is_array( $stored )
            ? $stored
            : ( isset( $this->circuits[ $provider ] ) ? $this->circuits[ $provider ] : array() );

        return array(
            'failures'   => min( self::CIRCUIT_FAILURE_CAP, max( 0, (int) ( isset( $source['failures'] ) ? $source['failures'] : 0 ) ) ),
            'open_until' => min(
                $now + self::CIRCUIT_STATE_TTL,
                max( 0, (float) ( isset( $source['open_until'] ) ? $source['open_until'] : 0 ) )
            ),
        );
    }

    /**
     * Persist one non-secret provider guard state.
     *
     * @param string $provider Provider identifier.
     * @param string $kind State kind.
     * @param mixed  $state State value.
     * @param int    $ttl Bounded lifetime.
     */
    private function write_guard_state( $provider, $kind, $state, $ttl ) {
        $this->write_cache(
            self::guard_cache_key( $provider, $kind ),
            $state,
            min( self::CIRCUIT_STATE_TTL, max( 1, (int) $ttl ) )
        );
    }

    /** @return float */
    private function now() {
        return (float) call_user_func( $this->clock );
    }

    /**
     * Read cache defensively. Cache failure must not expose callback details.
     *
     * @param string $key Cache key.
     * @return mixed
     */
    private function read_cache( $key ) {
        try {
            return call_user_func( $this->cache_get, $key );
        } catch ( Throwable $exception ) {
            return false;
        }
    }

    /**
     * Write cache defensively.
     *
     * @param string              $key Cache key.
     * @param mixed  $value Cache value.
     * @param int    $ttl Cache lifetime.
     */
    private function write_cache( $key, $value, $ttl ) {
        try {
            call_user_func( $this->cache_set, $key, $value, min( self::CACHE_TTL, max( 1, (int) $ttl ) ) );
        } catch ( Throwable $exception ) {
            // A cache failure must not turn a valid provider result into an error.
        }
    }

    /**
     * Resolve a credential from the exact constant or environment variable.
     * Credential values are intentionally kept local to the calling method.
     *
     * @param string $name Exact configuration name.
     * @return string
     */
    private static function credential( $name ) {
        $value = defined( $name ) ? constant( $name ) : getenv( $name );
        if ( ! is_scalar( $value ) ) {
            return '';
        }

        $value = trim( (string) $value );
        if ( '' === $value || strlen( $value ) > 2048 || preg_match( '/[\x00-\x1F\x7F]/', $value ) ) {
            return '';
        }

        return $value;
    }

    /**
     * Normalize an IMDb title ID without accepting arbitrary provider input.
     *
     * @param mixed $value Candidate value.
     * @return string
     */
    private static function normalize_imdb_id( $value ) {
        if ( ! is_scalar( $value ) ) {
            return '';
        }

        if ( class_exists( 'Lunara_Movie_Import_Contract' ) && is_callable( array( 'Lunara_Movie_Import_Contract', 'normalize_imdb_title_id' ) ) ) {
            $normalized = Lunara_Movie_Import_Contract::normalize_imdb_title_id( $value );
            return is_string( $normalized ) && preg_match( '/^tt\d{6,9}$/', $normalized ) ? $normalized : '';
        }

        $value = strtolower( trim( (string) $value ) );
        if ( preg_match( '/\b(tt\d{6,9})\b/', $value, $matches ) ) {
            return $matches[1];
        }

        return '';
    }

    /**
     * Build a provider URL from internal bases and scalar query values.
     *
     * @param string              $base Base URL.
     * @param array<string,mixed> $query Query map.
     * @return string
     */
    private static function build_url( $base, $query ) {
        return $base . '?' . http_build_query( $query, '', '&', PHP_QUERY_RFC3986 );
    }

    /**
     * Verify exact HTTPS provider origins. Redirects are disabled separately.
     *
     * @param string $provider Provider identifier.
     * @param string $url Request URL.
     * @return bool
     */
    private static function is_allowed_url( $provider, $url ) {
        $parts = function_exists( 'wp_parse_url' ) ? wp_parse_url( $url ) : parse_url( $url );
        if ( ! is_array( $parts ) || 'https' !== strtolower( isset( $parts['scheme'] ) ? $parts['scheme'] : '' ) ) {
            return false;
        }
        if ( isset( $parts['user'] ) || isset( $parts['pass'] ) || isset( $parts['fragment'] ) ) {
            return false;
        }
        if ( isset( $parts['port'] ) && 443 !== (int) $parts['port'] ) {
            return false;
        }

        $allowed_hosts = array(
            'omdb' => 'www.omdbapi.com',
            'tmdb' => 'api.themoviedb.org',
        );
        $host = strtolower( isset( $parts['host'] ) ? $parts['host'] : '' );

        return isset( $allowed_hosts[ $provider ] ) && $allowed_hosts[ $provider ] === $host;
    }

    /**
     * Standard request arguments with hard response and redirect limits.
     *
     * @param array<string,string> $headers Additional headers.
     * @return array<string,mixed>
     */
    private static function request_args( $headers = array() ) {
        return array(
            'timeout'             => self::REQUEST_TIMEOUT,
            'redirection'         => 0,
            'reject_unsafe_urls'  => true,
            'limit_response_size' => self::MAX_RESPONSE_BYTES,
            'headers'             => array_merge(
                array(
                    'Accept'     => 'application/json',
                    'User-Agent' => 'Lunara-Core-Movie-Importer',
                ),
                $headers
            ),
        );
    }

    /**
     * Normalize the two provider documents into an importer-safe candidate.
     *
     * @param string              $imdb_id IMDb title ID.
     * @param array<string,mixed> $omdb OMDb document wrapper.
     * @param array<string,mixed> $tmdb_find TMDb find wrapper.
     * @param array<string,mixed> $tmdb_details TMDb detail wrapper.
     * @param int                 $tmdb_id TMDb movie ID.
     * @return array<string,mixed>
     */
    private static function normalize_candidate( $imdb_id, $omdb, $tmdb_find, $tmdb_details, $tmdb_id ) {
        $o = isset( $omdb['data'] ) && is_array( $omdb['data'] ) ? $omdb['data'] : array();
        $t = isset( $tmdb_details['data'] ) && is_array( $tmdb_details['data'] ) ? $tmdb_details['data'] : array();

        $title          = self::text( isset( $t['title'] ) ? $t['title'] : ( isset( $o['Title'] ) ? $o['Title'] : '' ), 300 );
        $original_title = self::text( isset( $t['original_title'] ) ? $t['original_title'] : $title, 300 );
        $release_date   = self::date( isset( $t['release_date'] ) ? $t['release_date'] : ( isset( $o['Released'] ) ? $o['Released'] : '' ) );
        $release_year   = self::year( $release_date );
        if ( ! $release_year ) {
            $release_year = self::year( isset( $o['Year'] ) ? $o['Year'] : '' );
        }

        $runtime = self::positive_integer( isset( $t['runtime'] ) ? $t['runtime'] : 0 );
        if ( ! $runtime && isset( $o['Runtime'] ) && preg_match( '/(\d{1,4})/', (string) $o['Runtime'], $matches ) ) {
            $runtime = self::positive_integer( $matches[1] );
        }

        $genres    = self::named_values( isset( $t['genres'] ) ? $t['genres'] : array() );
        $genres    = self::merge_names( $genres, self::split_names( isset( $o['Genre'] ) ? $o['Genre'] : '' ) );
        $countries = self::named_values( isset( $t['production_countries'] ) ? $t['production_countries'] : array() );
        $countries = self::merge_names( $countries, self::split_names( isset( $o['Country'] ) ? $o['Country'] : '' ) );
        $languages = self::language_values( isset( $t['spoken_languages'] ) ? $t['spoken_languages'] : array() );
        $languages = self::merge_names( $languages, self::split_names( isset( $o['Language'] ) ? $o['Language'] : '' ) );

        return array(
            'schema'                  => self::SCHEMA_VERSION,
            'imdb_title_id'           => $imdb_id,
            'title'                   => $title,
            'original_title'          => $original_title,
            'release_year'            => $release_year,
            'release_date'            => $release_date,
            'runtime_minutes'         => $runtime,
            'content_rating'          => self::text( isset( $o['Rated'] ) ? $o['Rated'] : '', 30 ),
            'plot'                    => self::text( isset( $o['Plot'] ) ? $o['Plot'] : '', 8000 ),
            'overview'                => self::text( isset( $t['overview'] ) ? $t['overview'] : '', 8000 ),
            'tagline'                 => self::text( isset( $t['tagline'] ) ? $t['tagline'] : '', 500 ),
            'genres'                  => $genres,
            'countries'               => $countries,
            'languages'               => $languages,
            'directors'               => self::crew_values( $t, 'Directing', array( 'Director' ) ),
            'writers'                 => self::crew_values( $t, 'Writing', array() ),
            'cast'                    => self::cast_values( $t ),
            'studios'                 => self::company_values( isset( $t['production_companies'] ) ? $t['production_companies'] : array() ),
            'imdb_rating'             => self::decimal( isset( $o['imdbRating'] ) ? $o['imdbRating'] : null ),
            'imdb_votes'              => self::vote_count( isset( $o['imdbVotes'] ) ? $o['imdbVotes'] : 0 ),
            'tmdb_id'                 => $tmdb_id,
            'status'                  => self::text( isset( $t['status'] ) ? $t['status'] : '', 100 ),
            'budget'                  => self::positive_integer( isset( $t['budget'] ) ? $t['budget'] : 0 ),
            'revenue'                 => self::positive_integer( isset( $t['revenue'] ) ? $t['revenue'] : 0 ),
            'poster_path'             => self::image_path( isset( $t['poster_path'] ) ? $t['poster_path'] : '' ),
            'backdrop_path'           => self::image_path( isset( $t['backdrop_path'] ) ? $t['backdrop_path'] : '' ),
            'provider_status'         => array(
                'omdb' => true,
                'tmdb' => $tmdb_id > 0 && ! empty( $t ),
            ),
            'provider_payload_hashes' => array(
                'omdb'         => isset( $omdb['payload_hash'] ) ? (string) $omdb['payload_hash'] : '',
                'tmdb_find'    => isset( $tmdb_find['payload_hash'] ) ? (string) $tmdb_find['payload_hash'] : '',
                'tmdb_details' => isset( $tmdb_details['payload_hash'] ) ? (string) $tmdb_details['payload_hash'] : '',
            ),
        );
    }

    /** @param array<string,mixed> $data TMDb find data. @return int */
    private static function first_tmdb_movie_id( $data ) {
        if ( empty( $data['movie_results'] ) || ! is_array( $data['movie_results'] ) ) {
            return 0;
        }
        foreach ( $data['movie_results'] as $movie ) {
            $id = self::positive_integer( is_array( $movie ) && isset( $movie['id'] ) ? $movie['id'] : 0 );
            if ( $id ) {
                return $id;
            }
        }
        return 0;
    }

    /** @param mixed $value Value. @param int $limit Character cap. @return string */
    private static function text( $value, $limit ) {
        if ( ! is_scalar( $value ) ) {
            return '';
        }
        $value = trim( preg_replace( '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', strip_tags( (string) $value ) ) );
        if ( 'n/a' === strtolower( $value ) ) {
            return '';
        }
        if ( function_exists( 'mb_substr' ) ) {
            return mb_substr( $value, 0, $limit, 'UTF-8' );
        }
        return substr( $value, 0, $limit );
    }

    /** @param mixed $value Value. @return int */
    private static function positive_integer( $value ) {
        if ( ! is_numeric( $value ) ) {
            return 0;
        }
        $value = (int) $value;
        return $value > 0 ? $value : 0;
    }

    /** @param mixed $value Value. @return float|null */
    private static function decimal( $value ) {
        return is_numeric( $value ) && (float) $value >= 0 ? (float) $value : null;
    }

    /** @param mixed $value Value. @return int */
    private static function vote_count( $value ) {
        return self::positive_integer( preg_replace( '/[^0-9]/', '', (string) $value ) );
    }

    /** @param mixed $value Value. @return string */
    private static function date( $value ) {
        $value = trim( (string) $value );
        if ( preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $value, $matches ) && checkdate( (int) $matches[2], (int) $matches[3], (int) $matches[1] ) ) {
            return $value;
        }
        return '';
    }

    /** @param mixed $value Value. @return int */
    private static function year( $value ) {
        return preg_match( '/\b(18|19|20|21)\d{2}\b/', (string) $value, $matches ) ? (int) $matches[0] : 0;
    }

    /** @param mixed $value Value. @return string */
    private static function image_path( $value ) {
        $value = trim( (string) $value );
        return preg_match( '#^/[A-Za-z0-9._-]{1,255}$#', $value ) ? $value : '';
    }

    /** @param mixed $value Comma-delimited names. @return array<int,string> */
    private static function split_names( $value ) {
        return self::sort_unique_names( preg_split( '/\s*,\s*/', (string) $value, -1, PREG_SPLIT_NO_EMPTY ) );
    }

    /** @param array<int,mixed> $values Provider objects. @return array<int,string> */
    private static function named_values( $values ) {
        $names = array();
        foreach ( is_array( $values ) ? $values : array() as $value ) {
            if ( is_array( $value ) && isset( $value['name'] ) ) {
                $names[] = $value['name'];
            }
        }
        return self::sort_unique_names( $names );
    }

    /** @param array<int,mixed> $values Provider objects. @return array<int,string> */
    private static function language_values( $values ) {
        $names = array();
        foreach ( is_array( $values ) ? $values : array() as $value ) {
            if ( is_array( $value ) ) {
                $names[] = isset( $value['english_name'] ) ? $value['english_name'] : ( isset( $value['name'] ) ? $value['name'] : '' );
            }
        }
        return self::sort_unique_names( $names );
    }

    /** @param array<int,string> $left Left names. @param array<int,string> $right Right names. @return array<int,string> */
    private static function merge_names( $left, $right ) {
        return self::sort_unique_names( array_merge( $left, $right ) );
    }

    /** @param array<int,mixed> $values Names. @return array<int,string> */
    private static function sort_unique_names( $values ) {
        $map = array();
        foreach ( $values as $value ) {
            $name = self::text( $value, 300 );
            if ( '' !== $name && 'n/a' !== strtolower( $name ) ) {
                $map[ strtolower( $name ) ] = $name;
            }
        }
        ksort( $map, SORT_NATURAL | SORT_FLAG_CASE );
        return array_values( $map );
    }

    /**
     * Normalize crew credits.
     *
     * @param array<string,mixed> $details TMDb details.
     * @param string              $department Department.
     * @param array<int,string>   $jobs Optional job allowlist.
     * @return array<int,array<string,mixed>>
     */
    private static function crew_values( $details, $department, $jobs ) {
        $records = array();
        $crew    = isset( $details['credits']['crew'] ) && is_array( $details['credits']['crew'] ) ? $details['credits']['crew'] : array();
        foreach ( $crew as $person ) {
            if ( ! is_array( $person ) || $department !== ( isset( $person['department'] ) ? $person['department'] : '' ) ) {
                continue;
            }
            $job = self::text( isset( $person['job'] ) ? $person['job'] : '', 100 );
            if ( ! empty( $jobs ) && ! in_array( $job, $jobs, true ) ) {
                continue;
            }
            $name = self::text( isset( $person['name'] ) ? $person['name'] : '', 300 );
            if ( '' === $name ) {
                continue;
            }
            $key = self::positive_integer( isset( $person['id'] ) ? $person['id'] : 0 ) . '|' . strtolower( $name ) . '|' . strtolower( $job );
            $records[ $key ] = array(
                'name'           => $name,
                'credit'         => $job,
                'tmdb_person_id' => self::positive_integer( isset( $person['id'] ) ? $person['id'] : 0 ),
            );
        }
        uasort( $records, static function ( $left, $right ) {
            return strcasecmp( $left['name'] . '|' . $left['credit'], $right['name'] . '|' . $right['credit'] );
        } );
        return array_slice( array_values( $records ), 0, 20 );
    }

    /** @param array<string,mixed> $details TMDb details. @return array<int,array<string,mixed>> */
    private static function cast_values( $details ) {
        $records = array();
        $cast    = isset( $details['credits']['cast'] ) && is_array( $details['credits']['cast'] ) ? $details['credits']['cast'] : array();
        foreach ( $cast as $person ) {
            if ( ! is_array( $person ) ) {
                continue;
            }
            $name = self::text( isset( $person['name'] ) ? $person['name'] : '', 300 );
            if ( '' === $name ) {
                continue;
            }
            $records[] = array(
                'name'           => $name,
                'character'      => self::text( isset( $person['character'] ) ? $person['character'] : '', 300 ),
                'order'          => max( 0, (int) ( isset( $person['order'] ) ? $person['order'] : 9999 ) ),
                'tmdb_person_id' => self::positive_integer( isset( $person['id'] ) ? $person['id'] : 0 ),
            );
        }
        usort( $records, static function ( $left, $right ) {
            if ( $left['order'] !== $right['order'] ) {
                return $left['order'] < $right['order'] ? -1 : 1;
            }
            return strcasecmp( $left['name'], $right['name'] );
        } );
        return array_slice( $records, 0, 20 );
    }

    /** @param array<int,mixed> $companies TMDb companies. @return array<int,array<string,mixed>> */
    private static function company_values( $companies ) {
        $records = array();
        foreach ( is_array( $companies ) ? $companies : array() as $company ) {
            if ( ! is_array( $company ) ) {
                continue;
            }
            $name = self::text( isset( $company['name'] ) ? $company['name'] : '', 300 );
            if ( '' === $name ) {
                continue;
            }
            $records[ strtolower( $name ) ] = array(
                'name'            => $name,
                'tmdb_company_id' => self::positive_integer( isset( $company['id'] ) ? $company['id'] : 0 ),
            );
        }
        ksort( $records, SORT_NATURAL | SORT_FLAG_CASE );
        return array_values( $records );
    }

    /** @param string $imdb_id IMDb title ID. @return string */
    private static function cache_key( $imdb_id ) {
        return 'lunara_movie_' . self::CACHE_SCHEMA_VERSION . '_' . hash( 'sha256', $imdb_id );
    }

    /**
     * Create a secret-free state key from fixed provider and state labels.
     *
     * @param string $provider Provider identifier.
     * @param string $kind State kind.
     * @return string
     */
    private static function guard_cache_key( $provider, $kind ) {
        $provider = in_array( $provider, array( 'omdb', 'tmdb' ), true ) ? $provider : 'unknown';
        $kind     = in_array( $kind, array( 'rate', 'circuit' ), true ) ? $kind : 'unknown';

        return 'lunara_movie_guard_' . self::CACHE_SCHEMA_VERSION . '_' . $provider . '_' . $kind;
    }

    /** @param mixed $candidate Candidate. @param string $imdb_id IMDb title ID. @return bool */
    private static function is_valid_cached_candidate( $candidate, $imdb_id ) {
        return is_array( $candidate )
            && self::SCHEMA_VERSION === ( isset( $candidate['schema'] ) ? $candidate['schema'] : '' )
            && $imdb_id === ( isset( $candidate['imdb_title_id'] ) ? $candidate['imdb_title_id'] : '' )
            && '' !== ( isset( $candidate['title'] ) ? trim( (string) $candidate['title'] ) : '' );
    }

    /** @param array<string,mixed> $response Response. @return int */
    private static function response_code( $response ) {
        if ( function_exists( 'wp_remote_retrieve_response_code' ) ) {
            return (int) wp_remote_retrieve_response_code( $response );
        }
        return isset( $response['response']['code'] ) ? (int) $response['response']['code'] : 0;
    }

    /** @param array<string,mixed> $response Response. @param string $name Header. @return mixed */
    private static function response_header( $response, $name ) {
        if ( function_exists( 'wp_remote_retrieve_header' ) ) {
            return wp_remote_retrieve_header( $response, $name );
        }
        if ( ! isset( $response['headers'] ) || ! is_array( $response['headers'] ) ) {
            return '';
        }
        foreach ( $response['headers'] as $header => $value ) {
            if ( strtolower( (string) $header ) === strtolower( $name ) ) {
                return $value;
            }
        }
        return '';
    }

    /** @param array<string,mixed> $response Response. @return mixed */
    private static function response_body( $response ) {
        if ( function_exists( 'wp_remote_retrieve_body' ) ) {
            return wp_remote_retrieve_body( $response );
        }
        return isset( $response['body'] ) ? $response['body'] : '';
    }

    /** @param mixed $value Retry-After header. @return int */
    private static function safe_retry_after( $value ) {
        $value = is_numeric( $value ) ? (int) $value : 1;
        return min( 300, max( 1, $value ) );
    }

    /** @param mixed $value Value. @return bool */
    private static function is_error_value( $value ) {
        return ( function_exists( 'is_wp_error' ) && is_wp_error( $value ) ) || $value instanceof WP_Error;
    }

    /**
     * Create a redacted error with a deliberately small, safe data surface.
     *
     * @param string              $code Error code.
     * @param string              $message Public message.
     * @param string              $provider Provider identifier.
     * @param array<string,mixed> $data Safe data.
     * @return WP_Error
     */
    private static function error( $code, $message, $provider = '', $data = array() ) {
        $safe = array();
        if ( in_array( $provider, array( 'omdb', 'tmdb' ), true ) ) {
            $safe['provider'] = $provider;
        }
        if ( isset( $data['retry_after'] ) ) {
            $safe['retry_after'] = min( 300, max( 1, (int) $data['retry_after'] ) );
        }
        if ( isset( $data['credentials'] ) && is_array( $data['credentials'] ) ) {
            $safe['credentials'] = array(
                'omdb'  => ! empty( $data['credentials']['omdb'] ),
                'tmdb'  => ! empty( $data['credentials']['tmdb'] ),
                'ready' => ! empty( $data['credentials']['ready'] ),
            );
        }
        return new WP_Error( $code, $message, $safe );
    }

    /** @param mixed $value Value. @return string */
    private static function stable_json( $value ) {
        return function_exists( 'wp_json_encode' )
            ? (string) wp_json_encode( $value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE )
            : (string) json_encode( $value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
    }
}
