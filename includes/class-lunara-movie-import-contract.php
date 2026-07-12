<?php
/**
 * Stable data contract for local Movie import previews and draft upserts.
 *
 * The contract is intentionally provider-neutral. Remote gateways may turn
 * OMDb/TMDB responses into fixtures, but this class only normalizes arrays.
 *
 * @package Lunara_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class Lunara_Movie_Import_Contract {

    const CANDIDATE_SCHEMA = 'lunara-movie-candidate/v1';
    const PLAN_SCHEMA      = 'lunara-movie-import-plan/v1';
    const PLAN_VERSION     = '1.0.0';

    /**
     * Normalize an IMDb title ID using the same rule as Debrief.
     *
     * @param mixed $value Raw ID, URL, or text containing an ID.
     * @return string
     */
    public static function normalize_imdb_title_id( $value ) {
        if ( class_exists( 'Lunara_Debrief_Contract' ) ) {
            return Lunara_Debrief_Contract::normalize_imdb_title_id( $value );
        }

        $value = strtolower( trim( (string) $value ) );
        if ( preg_match( '/\b(tt\d{6,9})\b/', $value, $matches ) ) {
            return $matches[1];
        }

        return '';
    }

    /**
     * Normalize a fixture or provider candidate into one deterministic shape.
     *
     * Top-level canonical values win. Nested `omdb` and `tmdb` fixture values
     * are supported so normalization tests never need a network request.
     *
     * @param array<string,mixed> $fixture Provider-neutral or raw fixture.
     * @return array<string,mixed>
     */
    public static function normalize_fixture( $fixture ) {
        $fixture = is_array( $fixture ) ? $fixture : array();
        $omdb    = isset( $fixture['omdb'] ) && is_array( $fixture['omdb'] ) ? $fixture['omdb'] : array();
        $tmdb    = isset( $fixture['tmdb'] ) && is_array( $fixture['tmdb'] ) ? $fixture['tmdb'] : array();

        $imdb_id = self::first_value(
            $fixture,
            array( 'imdb_title_id', 'imdb_id', 'imdbID' ),
            self::first_value( $omdb, array( 'imdbID', 'imdb_id', 'imdb_title_id' ) )
        );

        $release_date = self::normalize_date(
            self::first_value(
                $fixture,
                array( 'release_date' ),
                self::first_value( $tmdb, array( 'release_date' ) )
            )
        );

        $release_year = self::normalize_year(
            self::first_value(
                $fixture,
                array( 'release_year', 'year' ),
                self::first_value( $omdb, array( 'Year', 'year' ), $release_date )
            )
        );

        $runtime = self::normalize_positive_integer(
            self::first_value(
                $fixture,
                array( 'runtime_minutes', 'runtime' ),
                self::first_value( $tmdb, array( 'runtime' ), self::first_value( $omdb, array( 'Runtime' ) ) )
            )
        );

        $genres = self::normalize_list(
            self::first_value(
                $fixture,
                array( 'genres' ),
                self::first_value( $tmdb, array( 'genres' ), self::first_value( $omdb, array( 'Genre' ) ) )
            )
        );

        $countries = self::normalize_list(
            self::first_value(
                $fixture,
                array( 'countries' ),
                self::first_value( $tmdb, array( 'production_countries', 'countries' ), self::first_value( $omdb, array( 'Country' ) ) )
            )
        );

        $languages = self::normalize_list(
            self::first_value(
                $fixture,
                array( 'languages' ),
                self::first_value( $tmdb, array( 'spoken_languages', 'languages' ), self::first_value( $omdb, array( 'Language' ) ) )
            )
        );

        $candidate = array(
            'schema'                  => self::CANDIDATE_SCHEMA,
            'imdb_title_id'           => self::normalize_imdb_title_id( $imdb_id ),
            'title'                   => self::normalize_text(
                self::first_value(
                    $fixture,
                    array( 'title' ),
                    self::first_value( $tmdb, array( 'title', 'name' ), self::first_value( $omdb, array( 'Title', 'title' ) ) )
                )
            ),
            'original_title'          => self::normalize_text(
                self::first_value( $fixture, array( 'original_title' ), self::first_value( $tmdb, array( 'original_title' ) ) )
            ),
            'release_year'            => $release_year,
            'release_date'            => $release_date,
            'runtime_minutes'         => $runtime,
            'plot'                    => self::normalize_long_text(
                self::first_value( $fixture, array( 'plot' ), self::first_value( $omdb, array( 'Plot', 'plot' ) ) )
            ),
            'overview'                => self::normalize_long_text(
                self::first_value( $fixture, array( 'overview' ), self::first_value( $tmdb, array( 'overview' ) ) )
            ),
            'content_rating'          => self::normalize_text(
                self::first_value( $fixture, array( 'content_rating' ), self::first_value( $omdb, array( 'Rated', 'rated' ) ) )
            ),
            'genres'                  => $genres,
            'countries'               => $countries,
            'languages'               => $languages,
            'directors'               => self::normalize_list(
                self::first_value( $fixture, array( 'directors' ), self::first_value( $omdb, array( 'Director', 'director' ) ) )
            ),
            'writers'                 => self::normalize_list(
                self::first_value( $fixture, array( 'writers' ), self::first_value( $omdb, array( 'Writer', 'writer' ) ) )
            ),
            'cast'                    => self::normalize_list(
                self::first_value( $fixture, array( 'cast' ), self::first_value( $omdb, array( 'Actors', 'actors' ) ) )
            ),
            'imdb_rating'             => self::normalize_decimal(
                self::first_value( $fixture, array( 'imdb_rating' ), self::first_value( $omdb, array( 'imdbRating' ) ) )
            ),
            'imdb_votes'              => self::normalize_positive_integer(
                self::first_value( $fixture, array( 'imdb_votes' ), self::first_value( $omdb, array( 'imdbVotes' ) ) )
            ),
            'tmdb_id'                 => self::normalize_positive_integer(
                self::first_value( $fixture, array( 'tmdb_id', 'tmdb_movie_id' ), self::first_value( $tmdb, array( 'id', 'tmdb_id' ) ) )
            ),
            'tagline'                 => self::normalize_text(
                self::first_value( $fixture, array( 'tagline' ), self::first_value( $tmdb, array( 'tagline' ) ) )
            ),
            'status'                  => self::normalize_text(
                self::first_value( $fixture, array( 'status' ), self::first_value( $tmdb, array( 'status' ) ) )
            ),
            'budget'                  => self::normalize_positive_integer(
                self::first_value( $fixture, array( 'budget' ), self::first_value( $tmdb, array( 'budget' ) ) )
            ),
            'revenue'                 => self::normalize_positive_integer(
                self::first_value( $fixture, array( 'revenue' ), self::first_value( $tmdb, array( 'revenue' ) ) )
            ),
            'poster_path'             => self::normalize_path(
                self::first_value( $fixture, array( 'poster_path' ), self::first_value( $tmdb, array( 'poster_path' ) ) )
            ),
            'backdrop_path'           => self::normalize_path(
                self::first_value( $fixture, array( 'backdrop_path' ), self::first_value( $tmdb, array( 'backdrop_path' ) ) )
            ),
            'provider_payload_hashes' => self::normalize_hashes(
                self::first_value( $fixture, array( 'provider_payload_hashes' ), array() )
            ),
        );

        if ( '' === $candidate['overview'] ) {
            $candidate['overview'] = $candidate['plot'];
        }

        return $candidate;
    }

    /**
     * Alias for callers already holding a provider-normalized candidate.
     *
     * @param array<string,mixed> $candidate Candidate data.
     * @return array<string,mixed>
     */
    public static function normalize_candidate( $candidate ) {
        return self::normalize_fixture( $candidate );
    }

    /**
     * Validate the minimum identity needed for a local Movie draft.
     *
     * @param array<string,mixed> $candidate Normalized or raw candidate.
     * @return array<int,string>
     */
    public static function validation_errors( $candidate ) {
        $candidate = self::normalize_candidate( $candidate );
        $errors    = array();

        if ( '' === $candidate['imdb_title_id'] ) {
            $errors[] = 'invalid_imdb_title_id';
        }

        if ( '' === $candidate['title'] ) {
            $errors[] = 'missing_title';
        }

        return $errors;
    }

    /**
     * Map safe factual candidate fields into WordPress fields.
     *
     * Relationship fields, local images, and editorial body content are not
     * imported here. Those remain curated in WordPress.
     *
     * @param array<string,mixed> $candidate Normalized or raw candidate.
     * @return array<string,array<string,mixed>>
     */
    public static function writable_fields( $candidate ) {
        $candidate = self::normalize_candidate( $candidate );
        $excerpt   = '' !== $candidate['overview'] ? $candidate['overview'] : $candidate['plot'];

        return array(
            'post' => array(
                'post_title'   => $candidate['title'],
                'post_excerpt' => $excerpt,
            ),
            'meta' => array(
                'imdb_title_id' => $candidate['imdb_title_id'],
                'tmdb_movie_id' => $candidate['tmdb_id'] > 0 ? $candidate['tmdb_id'] : '',
                'original_title'=> $candidate['original_title'],
                'release_year'  => $candidate['release_year'] > 0 ? $candidate['release_year'] : '',
                'release_date'  => '' !== $candidate['release_date'] ? str_replace( '-', '', $candidate['release_date'] ) : '',
                'runtime'       => $candidate['runtime_minutes'] > 0 ? $candidate['runtime_minutes'] . ' min' : '',
                'genres'        => implode( ', ', $candidate['genres'] ),
                'countries'     => implode( ', ', $candidate['countries'] ),
                'content_rating'=> $candidate['content_rating'],
            ),
        );
    }

    /**
     * Produce stable JSON by sorting associative keys recursively.
     *
     * @param mixed $value Value to encode.
     * @return string
     */
    public static function stable_json( $value ) {
        return (string) json_encode(
            self::canonicalize( $value ),
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        );
    }

    /**
     * Hash a versioned plan without its own hash field.
     *
     * @param array<string,mixed> $plan Import plan.
     * @return string Lowercase SHA-256 hex digest.
     */
    public static function plan_hash( $plan ) {
        $plan = is_array( $plan ) ? $plan : array();
        unset( $plan['plan_hash'] );

        return hash( 'sha256', self::stable_json( $plan ) );
    }

    /**
     * Verify a plan's schema, version, and digest.
     *
     * @param array<string,mixed> $plan Import plan.
     * @return bool
     */
    public static function verify_plan( $plan ) {
        return is_array( $plan )
            && self::PLAN_SCHEMA === ( isset( $plan['schema'] ) ? $plan['schema'] : '' )
            && self::PLAN_VERSION === ( isset( $plan['version'] ) ? $plan['version'] : '' )
            && isset( $plan['plan_hash'] )
            && hash_equals( self::plan_hash( $plan ), (string) $plan['plan_hash'] );
    }

    /**
     * Recursively canonicalize a value for deterministic hashing.
     *
     * @param mixed $value Value to canonicalize.
     * @return mixed
     */
    private static function canonicalize( $value ) {
        if ( ! is_array( $value ) ) {
            return $value;
        }

        foreach ( $value as $key => $item ) {
            $value[ $key ] = self::canonicalize( $item );
        }

        if ( self::is_associative( $value ) ) {
            ksort( $value, SORT_STRING );
        }

        return $value;
    }

    /**
     * Test whether an array uses non-sequential keys.
     *
     * @param array<mixed> $value Array to inspect.
     * @return bool
     */
    private static function is_associative( $value ) {
        if ( array() === $value ) {
            return false;
        }

        return array_keys( $value ) !== range( 0, count( $value ) - 1 );
    }

    /**
     * First present, non-null value from an array.
     *
     * @param array<string,mixed> $source  Source array.
     * @param array<int,string>   $keys    Candidate keys.
     * @param mixed               $default Default value.
     * @return mixed
     */
    private static function first_value( $source, $keys, $default = '' ) {
        foreach ( $keys as $key ) {
            if ( array_key_exists( $key, $source ) && null !== $source[ $key ] && '' !== $source[ $key ] ) {
                return $source[ $key ];
            }
        }

        return $default;
    }

    /**
     * Normalize a short scalar string.
     *
     * @param mixed $value Raw value.
     * @return string
     */
    private static function normalize_text( $value ) {
        if ( is_array( $value ) || is_object( $value ) ) {
            return '';
        }

        $value = html_entity_decode( (string) $value, ENT_QUOTES, 'UTF-8' );
        $value = preg_replace( '/\s+/u', ' ', strip_tags( $value ) );

        return trim( (string) $value );
    }

    /**
     * Normalize longer prose without changing its words.
     *
     * @param mixed $value Raw value.
     * @return string
     */
    private static function normalize_long_text( $value ) {
        return self::normalize_text( $value );
    }

    /**
     * Normalize a year from a year, date, or provider range.
     *
     * @param mixed $value Raw year value.
     * @return int
     */
    private static function normalize_year( $value ) {
        if ( preg_match( '/\b(18\d{2}|19\d{2}|20\d{2}|2100)\b/', (string) $value, $matches ) ) {
            return (int) $matches[1];
        }

        return 0;
    }

    /**
     * Normalize an ISO release date.
     *
     * @param mixed $value Raw date.
     * @return string
     */
    private static function normalize_date( $value ) {
        $value = trim( (string) $value );
        if ( preg_match( '/^(18\d{2}|19\d{2}|20\d{2}|2100)(0[1-9]|1[0-2])([0-2]\d|3[01])$/', $value, $matches ) ) {
            return $matches[1] . '-' . $matches[2] . '-' . $matches[3];
        }

        if ( preg_match( '/^(18\d{2}|19\d{2}|20\d{2}|2100)-(0[1-9]|1[0-2])-([0-2]\d|3[01])$/', $value ) ) {
            return $value;
        }

        return '';
    }

    /**
     * Normalize a positive integer from provider text.
     *
     * @param mixed $value Raw number.
     * @return int
     */
    private static function normalize_positive_integer( $value ) {
        if ( is_string( $value ) ) {
            $value = str_replace( ',', '', $value );
        }

        if ( preg_match( '/\d+/', (string) $value, $matches ) ) {
            return max( 0, (int) $matches[0] );
        }

        return 0;
    }

    /**
     * Normalize an optional decimal rating.
     *
     * @param mixed $value Raw rating.
     * @return float
     */
    private static function normalize_decimal( $value ) {
        return is_numeric( $value ) ? (float) $value : 0.0;
    }

    /**
     * Normalize a list from CSV strings or provider object arrays.
     *
     * @param mixed $value Raw list.
     * @return array<int,string>
     */
    private static function normalize_list( $value ) {
        if ( is_string( $value ) ) {
            $value = preg_split( '/\s*,\s*/', $value );
        }

        if ( ! is_array( $value ) ) {
            return array();
        }

        $items = array();
        foreach ( $value as $item ) {
            if ( is_array( $item ) ) {
                $item = self::first_value( $item, array( 'name', 'english_name', 'title' ) );
            }

            $item = self::normalize_text( $item );
            if ( '' !== $item && 'n/a' !== strtolower( $item ) ) {
                $items[ strtolower( $item ) ] = $item;
            }
        }

        natcasesort( $items );

        return array_values( $items );
    }

    /**
     * Normalize a provider asset path without accepting arbitrary schemes.
     *
     * @param mixed $value Raw path.
     * @return string
     */
    private static function normalize_path( $value ) {
        $value = trim( (string) $value );
        if ( '' === $value || 'n/a' === strtolower( $value ) ) {
            return '';
        }

        if ( 0 === strpos( $value, '/' ) || 0 === strpos( $value, 'https://' ) ) {
            return $value;
        }

        return '';
    }

    /**
     * Retain only named SHA-256 provider payload fingerprints.
     *
     * @param mixed $hashes Raw hash map.
     * @return array<string,string>
     */
    private static function normalize_hashes( $hashes ) {
        if ( ! is_array( $hashes ) ) {
            return array();
        }

        $normalized = array();
        foreach ( $hashes as $provider => $hash ) {
            $provider = preg_replace( '/[^a-z0-9_-]/', '', strtolower( (string) $provider ) );
            $hash     = strtolower( trim( (string) $hash ) );
            if ( '' !== $provider && ( '' === $hash || preg_match( '/^[a-f0-9]{64}$/', $hash ) ) ) {
                $normalized[ $provider ] = $hash;
            }
        }

        ksort( $normalized, SORT_STRING );

        return $normalized;
    }
}
