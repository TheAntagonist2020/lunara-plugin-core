<?php
/**
 * Local WordPress repository for deterministic Movie draft upserts.
 *
 * @package Lunara_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class Lunara_Movie_Repository {

    /** @var array<string,callable> */
    private $adapter;

    /**
     * Constructor.
     *
     * WordPress calls are behind an adapter so previews and regressions can
     * run against an in-memory repository without HTTP or database writes.
     *
     * @param array<string,callable> $adapter Optional function overrides.
     */
    public function __construct( $adapter = array() ) {
        $defaults = array(
            'query_ids' => static function ( $args ) {
                return get_posts( $args );
            },
            'post' => static function ( $post_id ) {
                return get_post( $post_id );
            },
            'meta' => static function ( $post_id, $key ) {
                return get_post_meta( $post_id, $key, true );
            },
            'post_statuses' => static function () {
                return array_keys( get_post_stati() );
            },
            'insert_post' => static function ( $post_data ) {
                return wp_insert_post( $post_data, true );
            },
            'update_post' => static function ( $post_data ) {
                return wp_update_post( $post_data, true );
            },
            'update_meta' => static function ( $post_id, $key, $value ) {
                return update_post_meta( $post_id, $key, $value );
            },
            'acquire_lock' => static function ( $imdb_id ) {
                if ( ! class_exists( 'Lunara_Movie_Identity_Lock' ) ) {
                    return false;
                }

                return Lunara_Movie_Identity_Lock::acquire( $imdb_id, 30 );
            },
            'release_lock' => static function ( $handle ) {
                if ( ! class_exists( 'Lunara_Movie_Identity_Lock' ) ) {
                    return false;
                }

                return Lunara_Movie_Identity_Lock::release( $handle );
            },
            'is_error' => static function ( $value ) {
                return is_wp_error( $value );
            },
            'error_message' => static function ( $value ) {
                return is_object( $value ) && method_exists( $value, 'get_error_message' )
                    ? $value->get_error_message()
                    : 'WordPress write failed.';
            },
        );

        $adapter       = is_array( $adapter ) ? $adapter : array();
        $this->adapter = array_merge( $defaults, $adapter );
    }

    /**
     * Find every Movie claiming an IMDb identity, regardless of post status.
     *
     * Both the canonical ACF key and the historic entity-id fallback are
     * queried in one OR clause. Matching IDs are de-duplicated and sorted.
     *
     * @param mixed $imdb_id IMDb title identity.
     * @return array<int,int>
     */
    public function find_identity_matches( $imdb_id ) {
        $imdb_id = Lunara_Movie_Import_Contract::normalize_imdb_title_id( $imdb_id );
        if ( '' === $imdb_id ) {
            return array();
        }

        $statuses = call_user_func( $this->adapter['post_statuses'] );
        $statuses = is_array( $statuses ) ? array_values( array_unique( $statuses ) ) : array( 'any' );

        $ids = call_user_func(
            $this->adapter['query_ids'],
            array(
                'post_type'      => 'movie',
                'post_status'    => $statuses,
                'meta_query'     => array(
                    'relation' => 'OR',
                    array(
                        'key'   => 'imdb_title_id',
                        'value' => $imdb_id,
                    ),
                    array(
                        'key'   => '_lunara_entity_id',
                        'value' => $imdb_id,
                    ),
                ),
                'fields'         => 'ids',
                'posts_per_page' => -1,
                'no_found_rows'  => true,
                'orderby'        => 'ID',
                'order'          => 'ASC',
            )
        );

        $ids = is_array( $ids ) ? array_map( 'intval', $ids ) : array();
        $ids = array_values( array_unique( array_filter( $ids ) ) );
        sort( $ids, SORT_NUMERIC );

        return $ids;
    }

    /**
     * Return the local-first state for an IMDb identity.
     *
     * @param mixed $imdb_id IMDb title identity.
     * @return array<string,mixed>
     */
    public function local_identity_state( $imdb_id ) {
        $imdb_id = Lunara_Movie_Import_Contract::normalize_imdb_title_id( $imdb_id );
        $matches = $this->find_identity_matches( $imdb_id );

        if ( count( $matches ) > 1 ) {
            $candidate = $this->candidate_for_movie( $matches[0] );
            if ( '' === $candidate['title'] ) {
                $candidate['title'] = 'IMDb ' . $imdb_id;
            }

            return array(
                'status'    => 'conflict',
                'movie_ids' => $matches,
                'candidate' => $candidate,
            );
        }

        if ( empty( $matches ) ) {
            return array(
                'status'    => 'missing',
                'movie_ids' => array(),
                'candidate' => array(),
            );
        }

        return array(
            'status'    => 'found',
            'movie_ids' => $matches,
            'candidate' => $this->candidate_for_movie( $matches[0] ),
        );
    }

    /**
     * Build a normalized candidate from an existing local Movie.
     *
     * @param int $movie_id Movie post ID.
     * @return array<string,mixed>
     */
    public function candidate_for_movie( $movie_id ) {
        $movie_id = (int) $movie_id;
        $post     = call_user_func( $this->adapter['post'], $movie_id );

        if ( ! $post ) {
            return Lunara_Movie_Import_Contract::normalize_candidate( array() );
        }

        return Lunara_Movie_Import_Contract::normalize_candidate(
            array(
                'imdb_title_id' => $this->meta( $movie_id, 'imdb_title_id' )
                    ? $this->meta( $movie_id, 'imdb_title_id' )
                    : $this->meta( $movie_id, '_lunara_entity_id' ),
                'title'          => $this->post_value( $post, 'post_title' ),
                'overview'       => $this->post_value( $post, 'post_excerpt' ),
                'tmdb_movie_id'  => $this->meta( $movie_id, 'tmdb_movie_id' ),
                'original_title' => $this->meta( $movie_id, 'original_title' ),
                'release_year'   => $this->meta( $movie_id, 'release_year' ),
                'release_date'   => $this->meta( $movie_id, 'release_date' ),
                'runtime'        => $this->meta( $movie_id, 'runtime' ),
                'genres'         => $this->meta( $movie_id, 'genres' ),
                'countries'      => $this->meta( $movie_id, 'countries' ),
                'content_rating' => $this->meta( $movie_id, 'content_rating' ),
            )
        );
    }

    /**
     * Create a zero-write, deterministic draft-upsert plan.
     *
     * @param array<string,mixed> $candidate Candidate or fixture.
     * @param array<string,mixed> $context   Optional review/role provenance.
     * @return array<string,mixed>
     */
    public function plan_upsert( $candidate, $context = array() ) {
        $candidate = Lunara_Movie_Import_Contract::normalize_candidate( $candidate );
        $errors    = Lunara_Movie_Import_Contract::validation_errors( $candidate );
        $context   = $this->normalize_context( $context );

        $plan = array(
            'schema'           => Lunara_Movie_Import_Contract::PLAN_SCHEMA,
            'version'          => Lunara_Movie_Import_Contract::PLAN_VERSION,
            'operation'        => 'draft_upsert',
            'action'           => 'invalid',
            'identity'         => array( 'imdb_title_id' => $candidate['imdb_title_id'] ),
            'candidate'        => $candidate,
            'context'          => $context,
            'movie_id'         => 0,
            'matched_movie_ids'=> array(),
            'post_status'      => '',
            'post_writes'      => array(),
            'meta_writes'      => array(),
            'preserved_fields' => array(),
            'issues'           => $errors,
            'write_count'      => 0,
        );

        if ( ! empty( $errors ) ) {
            return $this->seal_plan( $plan );
        }

        $matches                   = $this->find_identity_matches( $candidate['imdb_title_id'] );
        $plan['matched_movie_ids'] = $matches;

        if ( count( $matches ) > 1 ) {
            $plan['action']   = 'conflict';
            $plan['issues'][] = 'duplicate_identity';
            return $this->seal_plan( $plan );
        }

        $fields = Lunara_Movie_Import_Contract::writable_fields( $candidate );
        if ( empty( $matches ) ) {
            $plan['action']      = 'create';
            $plan['post_status'] = 'draft';
            $plan['post_writes'] = array(
                'post_type'   => 'movie',
                'post_status' => 'draft',
                'post_title'  => $fields['post']['post_title'],
                'meta_input'  => array( 'imdb_title_id' => $candidate['imdb_title_id'] ),
            );

            if ( ! $this->is_blank( $fields['post']['post_excerpt'] ) ) {
                $plan['post_writes']['post_excerpt'] = $fields['post']['post_excerpt'];
            }

            foreach ( $fields['meta'] as $key => $value ) {
                if ( 'imdb_title_id' === $key ) {
                    continue;
                }

                if ( ! $this->is_blank( $value ) ) {
                    $plan['meta_writes'][ $key ] = $value;
                }
            }

            $plan['write_count'] = 1 + count( $plan['meta_writes'] );
            return $this->seal_plan( $plan );
        }

        $movie_id           = $matches[0];
        $post               = call_user_func( $this->adapter['post'], $movie_id );
        $plan['movie_id']    = $movie_id;
        $plan['post_status'] = $this->post_value( $post, 'post_status' );

        if ( 'draft' !== $plan['post_status'] ) {
            $plan['action']   = 'unchanged';
            $plan['issues'][] = 'non_draft_preserved';
            return $this->seal_plan( $plan );
        }

        foreach ( $fields['post'] as $key => $desired ) {
            if ( $this->is_blank( $desired ) ) {
                continue;
            }

            $current = $this->post_value( $post, $key );
            if ( $this->is_blank( $current ) ) {
                $plan['post_writes'][ $key ] = $desired;
            } elseif ( (string) $current !== (string) $desired ) {
                $plan['preserved_fields'][] = 'post.' . $key;
            }
        }

        foreach ( $fields['meta'] as $key => $desired ) {
            if ( $this->is_blank( $desired ) ) {
                continue;
            }

            $current = $this->meta( $movie_id, $key );
            if ( $this->is_blank( $current ) ) {
                $plan['meta_writes'][ $key ] = $desired;
            } elseif ( (string) $current !== (string) $desired ) {
                $plan['preserved_fields'][] = 'meta.' . $key;
            }
        }

        sort( $plan['preserved_fields'], SORT_STRING );
        $plan['write_count'] = ( empty( $plan['post_writes'] ) ? 0 : 1 ) + count( $plan['meta_writes'] );
        $plan['action']      = $plan['write_count'] > 0 ? 'update' : 'unchanged';

        return $this->seal_plan( $plan );
    }

    /**
     * Apply an already previewed plan after verifying it is still current.
     *
     * @param array<string,mixed> $plan Sealed import plan.
     * @return array<string,mixed>
     */
    public function apply_plan( $plan ) {
        if ( ! Lunara_Movie_Import_Contract::verify_plan( $plan ) ) {
            return $this->result( 'invalid', 0, 0, $plan, array( 'invalid_plan_hash' ) );
        }

        if ( in_array( $plan['action'], array( 'invalid', 'conflict' ), true ) ) {
            return $this->result( $plan['action'], 0, 0, $plan, $plan['issues'] );
        }

        $imdb_id = Lunara_Movie_Import_Contract::normalize_imdb_title_id(
            isset( $plan['identity']['imdb_title_id'] ) ? $plan['identity']['imdb_title_id'] : ''
        );
        if ( '' === $imdb_id ) {
            return $this->result( 'invalid', 0, 0, $plan, array( 'invalid_plan_identity' ) );
        }

        $lock = call_user_func( $this->adapter['acquire_lock'], $imdb_id );
        if ( ! $lock || $this->adapter_error( $lock ) ) {
            return $this->result(
                'conflict',
                isset( $plan['movie_id'] ) ? (int) $plan['movie_id'] : 0,
                0,
                $plan,
                array( 'identity_lock_unavailable' )
            );
        }

        try {
            return $this->apply_locked_plan( $plan );
        } finally {
            call_user_func( $this->adapter['release_lock'], $lock );
        }
    }

    /**
     * Revalidate and apply one plan while its IMDb identity lock is held.
     *
     * @param array<string,mixed> $plan Verified import plan.
     * @return array<string,mixed>
     */
    private function apply_locked_plan( $plan ) {

        $fresh = $this->plan_upsert( $plan['candidate'], $plan['context'] );
        if ( $fresh['plan_hash'] !== $plan['plan_hash'] ) {
            return $this->result( 'conflict', 0, 0, $fresh, array( 'stale_plan' ) );
        }

        if ( 'unchanged' === $fresh['action'] ) {
            return $this->result( 'unchanged', $fresh['movie_id'], 0, $fresh, $fresh['issues'] );
        }

        if ( 'create' === $fresh['action'] ) {
            $movie_id = call_user_func( $this->adapter['insert_post'], $fresh['post_writes'] );
            if ( call_user_func( $this->adapter['is_error'], $movie_id ) || ! $movie_id ) {
                return $this->result(
                    'error',
                    0,
                    0,
                    $fresh,
                    array( call_user_func( $this->adapter['error_message'], $movie_id ) )
                );
            }

            $writes = 1;
            $identity = $this->meta( (int) $movie_id, 'imdb_title_id' );
            if ( $this->adapter_error( $identity ) || $fresh['identity']['imdb_title_id'] !== Lunara_Movie_Import_Contract::normalize_imdb_title_id( $identity ) ) {
                $updated = call_user_func(
                    $this->adapter['update_meta'],
                    (int) $movie_id,
                    'imdb_title_id',
                    $fresh['identity']['imdb_title_id']
                );
                if ( $this->write_failed( $updated ) ) {
                    return $this->result(
                        'partial',
                        (int) $movie_id,
                        $writes,
                        $fresh,
                        array( 'identity_meta_write_failed' )
                    );
                }

                $identity = $this->meta( (int) $movie_id, 'imdb_title_id' );
                if ( $this->adapter_error( $identity ) || $fresh['identity']['imdb_title_id'] !== Lunara_Movie_Import_Contract::normalize_imdb_title_id( $identity ) ) {
                    return $this->result(
                        'partial',
                        (int) $movie_id,
                        $writes,
                        $fresh,
                        array( 'identity_meta_not_persisted' )
                    );
                }
                ++$writes;
            }

            foreach ( $fresh['meta_writes'] as $key => $value ) {
                $updated = call_user_func( $this->adapter['update_meta'], (int) $movie_id, $key, $value );
                if ( $this->write_failed( $updated ) ) {
                    return $this->result(
                        'partial',
                        (int) $movie_id,
                        $writes,
                        $fresh,
                        array( 'meta_write_failed:' . $key )
                    );
                }
                ++$writes;
            }

            return $this->result( 'created', (int) $movie_id, $writes, $fresh, array() );
        }

        $movie_id = (int) $fresh['movie_id'];
        $writes   = 0;
        if ( ! empty( $fresh['post_writes'] ) ) {
            $post_data       = $fresh['post_writes'];
            $post_data['ID'] = $movie_id;
            $updated         = call_user_func( $this->adapter['update_post'], $post_data );
            if ( call_user_func( $this->adapter['is_error'], $updated ) || ! $updated ) {
                return $this->result(
                    'error',
                    $movie_id,
                    0,
                    $fresh,
                    array( call_user_func( $this->adapter['error_message'], $updated ) )
                );
            }
            ++$writes;
        }

        foreach ( $fresh['meta_writes'] as $key => $value ) {
            $updated = call_user_func( $this->adapter['update_meta'], $movie_id, $key, $value );
            if ( $this->write_failed( $updated ) ) {
                return $this->result(
                    $writes > 0 ? 'partial' : 'error',
                    $movie_id,
                    $writes,
                    $fresh,
                    array( 'meta_write_failed:' . $key )
                );
            }
            ++$writes;
        }

        return $this->result( 'updated', $movie_id, $writes, $fresh, array() );
    }

    /**
     * Test an adapter return value for a WordPress or explicit write failure.
     *
     * @param mixed $value Adapter return value.
     * @return bool
     */
    private function write_failed( $value ) {
        return false === $value || $this->adapter_error( $value );
    }

    /**
     * Test an adapter return value for a WordPress error object.
     *
     * @param mixed $value Adapter return value.
     * @return bool
     */
    private function adapter_error( $value ) {
        return (bool) call_user_func( $this->adapter['is_error'], $value );
    }

    /**
     * Seal a plan with a stable versioned hash.
     *
     * @param array<string,mixed> $plan Plan payload.
     * @return array<string,mixed>
     */
    private function seal_plan( $plan ) {
        $plan['issues']    = array_values( array_unique( $plan['issues'] ) );
        $plan['plan_hash'] = Lunara_Movie_Import_Contract::plan_hash( $plan );

        return $plan;
    }

    /**
     * Normalize stable, non-secret provenance for a plan.
     *
     * @param array<string,mixed> $context Raw context.
     * @return array<string,mixed>
     */
    private function normalize_context( $context ) {
        $context = is_array( $context ) ? $context : array();
        $clean   = array();

        foreach ( array( 'requested_by', 'review_id' ) as $key ) {
            if ( isset( $context[ $key ] ) && (int) $context[ $key ] > 0 ) {
                $clean[ $key ] = (int) $context[ $key ];
            }
        }

        foreach ( array( 'role', 'source' ) as $key ) {
            if ( ! isset( $context[ $key ] ) ) {
                continue;
            }

            $value = preg_replace( '/[^a-z0-9]+/', '_', strtolower( trim( (string) $context[ $key ] ) ) );
            $value = trim( $value, '_' );
            if ( '' !== $value ) {
                $clean[ $key ] = $value;
            }
        }

        ksort( $clean, SORT_STRING );

        return $clean;
    }

    /**
     * Read a post value from either an object or array fixture.
     *
     * @param mixed  $post Post record.
     * @param string $key  Field key.
     * @return mixed
     */
    private function post_value( $post, $key ) {
        if ( is_object( $post ) && isset( $post->{$key} ) ) {
            return $post->{$key};
        }

        return is_array( $post ) && isset( $post[ $key ] ) ? $post[ $key ] : '';
    }

    /**
     * Read one metadata value through the injected adapter.
     *
     * @param int    $post_id Post ID.
     * @param string $key     Metadata key.
     * @return mixed
     */
    private function meta( $post_id, $key ) {
        return call_user_func( $this->adapter['meta'], (int) $post_id, $key );
    }

    /**
     * Test whether a stored field is safe to fill.
     *
     * @param mixed $value Stored value.
     * @return bool
     */
    private function is_blank( $value ) {
        return null === $value
            || '' === $value
            || ( is_string( $value ) && '' === trim( $value ) )
            || ( is_array( $value ) && empty( $value ) );
    }

    /**
     * Standard mutation result envelope.
     *
     * @param string              $status Status code.
     * @param int                 $movie_id Movie post ID.
     * @param int                 $writes Number of writes performed.
     * @param array<string,mixed> $plan Applied or current plan.
     * @param array<int,string>   $issues Issues or errors.
     * @return array<string,mixed>
     */
    private function result( $status, $movie_id, $writes, $plan, $issues ) {
        return array(
            'status'           => $status,
            'movie_id'         => (int) $movie_id,
            'writes_performed' => (int) $writes,
            'plan_hash'        => isset( $plan['plan_hash'] ) ? $plan['plan_hash'] : '',
            'plan'             => $plan,
            'issues'           => array_values( array_unique( $issues ) ),
        );
    }
}
