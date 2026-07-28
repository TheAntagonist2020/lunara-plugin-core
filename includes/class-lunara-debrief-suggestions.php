<?php
/**
 * Private, explainable Debrief suggestion support for operators.
 *
 * @package Lunara_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Build local-data-only candidate reports without changing Review content.
 */
final class Lunara_Debrief_Suggestions {

    const SCHEMA_VERSION     = 'lunara-debrief-suggestions/v1';
    const CANDIDATE_POOL_CAP = 200;
    const MAX_RESULTS        = 12;
    const DEFAULT_RESULTS    = 6;

    const WEIGHT_DIRECTOR = 100;
    const WEIGHT_CAST     = 30;
    const WEIGHT_STUDIO   = 5;

    /**
     * Suggest candidates for one explicit Review.
     *
     * Theme Echo and Counter-Program deliberately abstain until controlled
     * editorial theme/tone metadata exists. Career Context uses only local,
     * structured Movie relationships and always explains its score.
     *
     * @param mixed               $review_id Review ID.
     * @param array<string,mixed> $args Optional role and result limit.
     * @param object|null          $gateway Optional injected provider gateway.
     * @return array<string,mixed>
     */
    public static function for_review( $review_id, $args = array(), $gateway = null ) {
        $review_id = self::positive_integer( $review_id, 'Review ID' );
        if ( ! function_exists( 'get_post_type' ) || 'review' !== get_post_type( $review_id ) ) {
            throw new InvalidArgumentException( 'Review ID ' . $review_id . ' does not identify an existing Review.' );
        }

        $args = is_array( $args ) ? $args : array();
        $limit = array_key_exists( 'limit', $args )
            ? self::positive_integer( $args['limit'], 'Suggestion limit' )
            : self::DEFAULT_RESULTS;
        if ( $limit > self::MAX_RESULTS ) {
            throw new InvalidArgumentException( 'Suggestion limit cannot exceed ' . self::MAX_RESULTS . '.' );
        }

        $role_filter = isset( $args['role'] ) ? trim( (string) $args['role'] ) : '';
        $roles       = array_keys( Lunara_Debrief_Contract::roles() );
        if ( '' !== $role_filter ) {
            if ( ! in_array( $role_filter, $roles, true ) ) {
                throw new InvalidArgumentException( 'Invalid Debrief role: ' . $role_filter . '.' );
            }
            $roles = array( $role_filter );
        }

        $source_resolution = self::source_resolution( $review_id );
        $source_film       = $source_resolution['film'];
        $source_snapshot   = $source_resolution['snapshot'];
        $source_id         = absint( $source_film['movie_id'] ?? 0 );

        $selected_ids = array();
        foreach ( Lunara_Debrief_Contract::roles() as $definition ) {
            $movie_id = function_exists( 'get_post_meta' )
                ? absint( get_post_meta( $review_id, $definition['movie_field'], true ) )
                : 0;
            if ( $movie_id ) {
                $selected_ids[] = $movie_id;
            }
        }
        $selected_ids = array_values( array_unique( $selected_ids ) );
        sort( $selected_ids, SORT_NUMERIC );

        $source_public   = 'ready' === $source_resolution['status'];
        $source_signals  = isset( $source_snapshot['signals'] ) ? $source_snapshot['signals'] : self::empty_signals();
        $has_career_signals = ! empty( $source_signals['directors']['ids'] )
            || ! empty( $source_signals['principal_cast']['ids'] );

        // Provider enrichment is deliberately injected, sparse-only, and in-memory.
        // Existing local relationships always remain authoritative and avoid a call.
        $career_requested = in_array( Lunara_Debrief_Contract::ROLE_CAREER_CONTEXT, $roles, true );
        $source_imdb_id   = Lunara_Debrief_Contract::normalize_imdb_title_id( $source_film['imdb_title_id'] ?? '' );
        if ( $source_public
            && $career_requested
            && ! $has_career_signals
            && '' !== $source_imdb_id
            && is_object( $gateway )
            && method_exists( $gateway, 'get_candidate_by_imdb' ) ) {
            $provider_result = null;
            try {
                $provider_result = $gateway->get_candidate_by_imdb( $source_imdb_id );
            } catch ( Throwable $error ) {
                $provider_result = null;
            }

            if ( is_array( $provider_result ) ) {
                $provider_directors = self::normalize_provider_directors( $provider_result['directors'] ?? array() );
                if ( ! empty( $provider_directors ) ) {
                    $resolved_director_ids = self::resolve_existing_person_ids( $provider_directors );
                    if ( ! empty( $resolved_director_ids ) ) {
                        $source_signals['directors']['ids']    = $resolved_director_ids;
                        $source_signals['directors']['labels'] = self::post_labels( $resolved_director_ids );
                        $source_snapshot['signals']            = $source_signals;
                    }
                }
            }
        }

        $has_career_signals = ! empty( $source_signals['directors']['ids'] )
            || ! empty( $source_signals['principal_cast']['ids'] );
        $needs_pool = $source_public
            && $has_career_signals
            && in_array( Lunara_Debrief_Contract::ROLE_CAREER_CONTEXT, $roles, true );
        $pool = $needs_pool ? self::candidate_pool( $source_signals ) : array(
            'ids'       => array(),
            'scanned'   => 0,
            'truncated' => false,
        );

        $role_reports = array();
        foreach ( $roles as $role ) {
            if ( ! $source_public ) {
                $role_reports[ $role ] = self::empty_role_report(
                    $role,
                    'unavailable',
                    $source_resolution['reason_codes']
                );
                continue;
            }

            if ( Lunara_Debrief_Contract::ROLE_THEME_ECHO === $role ) {
                $role_reports[ $role ] = self::empty_role_report(
                    $role,
                    'insufficient_evidence',
                    array( 'controlled_theme_metadata_unavailable' )
                );
                continue;
            }

            if ( Lunara_Debrief_Contract::ROLE_COUNTER_PROGRAM === $role ) {
                $role_reports[ $role ] = self::empty_role_report(
                    $role,
                    'insufficient_evidence',
                    array( 'controlled_theme_and_tone_metadata_unavailable' )
                );
                continue;
            }

            $role_reports[ $role ] = self::career_context_report(
                $source_snapshot,
                $pool['ids'],
                array_values( array_unique( array_merge( array( $source_id ), $selected_ids ) ) ),
                $limit
            );
        }

        $hash_roles = array();
        foreach ( $role_reports as $role => $role_report ) {
            $hash_roles[ $role ] = array(
                'status'       => $role_report['status'],
                'reason_codes' => $role_report['reason_codes'],
                'candidates'   => array_map( static function ( $candidate ) {
                    return array(
                        'movie_id' => absint( $candidate['film']['movie_id'] ?? 0 ),
                        'score'    => (int) ( $candidate['score'] ?? 0 ),
                        'evidence' => array_map( static function ( $evidence ) {
                            return array(
                                'code'       => (string) ( $evidence['code'] ?? '' ),
                                'entity_ids' => array_values( array_map( 'absint', (array) ( $evidence['entity_ids'] ?? array() ) ) ),
                                'score'      => (int) ( $evidence['score'] ?? 0 ),
                            );
                        }, (array) ( $candidate['evidence'] ?? array() ) ),
                    );
                }, (array) $role_report['candidates'] ),
            );
        }

        $hash_material = array(
            'schema'             => self::SCHEMA_VERSION,
            'review_id'          => $review_id,
            'source_movie_id'    => $source_id,
            'source_status'      => $source_resolution['status'],
            'source_reason_codes' => $source_resolution['reason_codes'],
            'selected_movie_ids' => $selected_ids,
            'candidate_pool'     => array(
                'scanned'   => $pool['scanned'],
                'truncated' => $pool['truncated'],
            ),
            'roles'              => $hash_roles,
        );

        return array(
            'schema'             => self::SCHEMA_VERSION,
            'mode'               => 'suggestions',
            'review_id'          => $review_id,
            'source_film'        => $source_film,
            'source_status'      => $source_resolution['status'],
            'source_reason_codes' => $source_resolution['reason_codes'],
            'source_signals'     => $source_signals,
            'selected_movie_ids' => $selected_ids,
            'candidate_pool'     => array(
                'scanned'   => $pool['scanned'],
                'cap'       => self::CANDIDATE_POOL_CAP,
                'truncated' => $pool['truncated'],
            ),
            'roles'              => $role_reports,
            'writes_performed'   => 0,
            'suggestion_hash'    => hash( 'sha256', self::stable_json( $hash_material ) ),
        );
    }

    /**
     * Resolve exactly one public source Movie without accepting ambiguity.
     *
     * @param int $review_id Review ID.
     * @return array<string,mixed>
     */
    private static function source_resolution( $review_id ) {
        $raw_imdb = function_exists( 'get_post_meta' )
            ? get_post_meta( $review_id, '_lunara_imdb_title_id', true )
            : '';
        $imdb_id  = Lunara_Debrief_Contract::normalize_imdb_title_id( $raw_imdb );
        $fallback = Lunara_Debrief_Contract::empty_film_reference();
        $fallback['review_id']     = $review_id;
        $fallback['imdb_title_id'] = $imdb_id;

        if ( '' === $imdb_id ) {
            return self::source_result( 'unavailable', array( 'source_imdb_missing' ), $fallback, array() );
        }
        if ( ! function_exists( 'get_posts' ) ) {
            return self::source_result( 'unavailable', array( 'source_lookup_unavailable' ), $fallback, array() );
        }

        $raw_ids = get_posts(
            array(
                'post_type'              => 'movie',
                'post_status'            => array( 'publish' ),
                'posts_per_page'         => 2,
                'fields'                 => 'ids',
                'orderby'                => 'ID',
                'order'                  => 'ASC',
                'no_found_rows'          => true,
                'cache_results'          => false,
                'update_post_meta_cache' => false,
                'update_post_term_cache' => false,
                'meta_query'             => array(
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
            )
        );
        $ids = array_values( array_unique( array_filter( array_map( 'absint', is_array( $raw_ids ) ? $raw_ids : array() ) ) ) );
        sort( $ids, SORT_NUMERIC );

        if ( empty( $ids ) ) {
            return self::source_result( 'unavailable', array( 'source_movie_not_found' ), $fallback, array() );
        }
        if ( count( $ids ) > 1 ) {
            return self::source_result( 'unavailable', array( 'source_movie_ambiguous' ), $fallback, array() );
        }

        $snapshot = self::movie_snapshot( $ids[0] );
        if ( empty( $snapshot ) ) {
            return self::source_result( 'unavailable', array( 'source_movie_unrenderable_or_conflicted' ), $fallback, array() );
        }
        if ( $imdb_id !== (string) ( $snapshot['film']['imdb_title_id'] ?? '' ) ) {
            return self::source_result( 'unavailable', array( 'source_movie_identity_mismatch' ), $fallback, array() );
        }

        return self::source_result( 'ready', array(), $snapshot['film'], $snapshot );
    }

    /**
     * Standard source resolution response.
     *
     * @param string              $status Resolution status.
     * @param array<int,string>   $reason_codes Explainable reason codes.
     * @param array<string,mixed> $film Film reference.
     * @param array<string,mixed> $snapshot Structured snapshot.
     * @return array<string,mixed>
     */
    private static function source_result( $status, $reason_codes, $film, $snapshot ) {
        return array(
            'status'       => $status,
            'reason_codes' => array_values( $reason_codes ),
            'film'         => $film,
            'snapshot'     => $snapshot,
        );
    }

    /**
     * Build the Career Context ranking.
     *
     * @param array<string,mixed> $source Source snapshot.
     * @param array<int,int>      $candidate_ids Candidate IDs.
     * @param array<int,int>      $excluded_ids Source and selected IDs.
     * @param int                 $limit Maximum results.
     * @return array<string,mixed>
     */
    private static function career_context_report( $source, $candidate_ids, $excluded_ids, $limit ) {
        $signals = isset( $source['signals'] ) && is_array( $source['signals'] )
            ? $source['signals']
            : self::empty_signals();
        if ( empty( $signals['directors']['ids'] ) && empty( $signals['principal_cast']['ids'] ) ) {
            return self::empty_role_report(
                Lunara_Debrief_Contract::ROLE_CAREER_CONTEXT,
                'insufficient_evidence',
                array( 'source_career_relationships_missing' )
            );
        }

        $excluded = array_fill_keys( array_map( 'absint', $excluded_ids ), true );
        $candidates = array();
        foreach ( $candidate_ids as $candidate_id ) {
            $candidate_id = absint( $candidate_id );
            if ( ! $candidate_id || isset( $excluded[ $candidate_id ] ) ) {
                continue;
            }

            $candidate = self::movie_snapshot( $candidate_id );
            if ( empty( $candidate ) ) {
                continue;
            }

            $evidence = self::career_evidence( $signals, $candidate['signals'] );
            $qualifying_codes = array_column( $evidence, 'code' );
            if ( ! in_array( 'shared_director', $qualifying_codes, true )
                && ! in_array( 'shared_principal_cast', $qualifying_codes, true )
            ) {
                continue;
            }

            $score = array_sum( array_map( static function ( $item ) {
                return (int) $item['score'];
            }, $evidence ) );
            $candidates[] = array(
                'film'        => $candidate['film'],
                'score'       => $score,
                'evidence'    => $evidence,
                'explanation' => self::explanation( $evidence ),
            );
        }

        usort( $candidates, static function ( $left, $right ) {
            $score_order = (int) $right['score'] <=> (int) $left['score'];
            if ( 0 !== $score_order ) {
                return $score_order;
            }

            return absint( $left['film']['movie_id'] ?? 0 ) <=> absint( $right['film']['movie_id'] ?? 0 );
        } );
        $candidates = array_slice( $candidates, 0, $limit );

        return array(
            'role'         => Lunara_Debrief_Contract::ROLE_CAREER_CONTEXT,
            'status'       => empty( $candidates ) ? 'no_qualified_candidates' : 'ready',
            'reason_codes' => empty( $candidates ) ? array( 'no_shared_career_relationships' ) : array(),
            'candidates'   => $candidates,
        );
    }

    /**
     * Collect a bounded deterministic pool sharing source career entities.
     *
     * @param array<string,mixed> $source_signals Source Movie signals.
     * @return array<string,mixed>
     */
    private static function candidate_pool( $source_signals ) {
        if ( ! function_exists( 'get_posts' ) ) {
            return array(
                'ids'       => array(),
                'scanned'   => 0,
                'truncated' => false,
            );
        }

        $meta_query = array( 'relation' => 'OR' );
        foreach ( array( 'directors', 'principal_cast' ) as $meta_key ) {
            $ids = array_values( array_unique( array_filter( array_map(
                'absint',
                (array) ( $source_signals[ $meta_key ]['ids'] ?? array() )
            ) ) ) );
            sort( $ids, SORT_NUMERIC );
            foreach ( $ids as $entity_id ) {
                $meta_query[] = array(
                    'key'     => $meta_key,
                    'value'   => '"' . $entity_id . '"',
                    'compare' => 'LIKE',
                );
            }
        }

        if ( 1 === count( $meta_query ) ) {
            return array(
                'ids'       => array(),
                'scanned'   => 0,
                'truncated' => false,
            );
        }

        $raw_ids = get_posts(
            array(
                'post_type'              => 'movie',
                'post_status'            => 'publish',
                'posts_per_page'         => self::CANDIDATE_POOL_CAP + 1,
                'fields'                 => 'ids',
                'orderby'                => 'ID',
                'order'                  => 'ASC',
                'no_found_rows'          => true,
                'cache_results'          => true,
                'update_post_meta_cache' => true,
                'update_post_term_cache' => true,
                'meta_query'             => $meta_query,
            )
        );
        $ids = array();
        foreach ( is_array( $raw_ids ) ? $raw_ids : array() as $raw_id ) {
            if ( is_scalar( $raw_id ) && absint( $raw_id ) ) {
                $ids[] = absint( $raw_id );
            }
        }
        $ids = array_values( array_unique( $ids ) );
        sort( $ids, SORT_NUMERIC );
        $truncated = count( $ids ) > self::CANDIDATE_POOL_CAP;
        $ids       = array_slice( $ids, 0, self::CANDIDATE_POOL_CAP );

        return array(
            'ids'       => $ids,
            'scanned'   => count( $ids ),
            'truncated' => $truncated,
        );
    }

    /**
     * Read one public Movie and the structured career signals used for score.
     *
     * @param int $movie_id Movie ID.
     * @return array<string,mixed>
     */
    private static function movie_snapshot( $movie_id ) {
        $film = Lunara_Debrief_Contract::public_movie_reference( $movie_id );
        if ( empty( $film['movie_id'] ) ) {
            return array();
        }

        $imdb_meta = Lunara_Debrief_Contract::normalize_imdb_title_id(
            function_exists( 'get_post_meta' ) ? get_post_meta( $movie_id, 'imdb_title_id', true ) : ''
        );
        $entity_id = Lunara_Debrief_Contract::normalize_imdb_title_id(
            function_exists( 'get_post_meta' ) ? get_post_meta( $movie_id, '_lunara_entity_id', true ) : ''
        );
        if ( '' !== $imdb_meta && '' !== $entity_id && $imdb_meta !== $entity_id ) {
            return array();
        }

        $director_ids = self::post_meta_ids( $movie_id, 'directors', 'person' );
        $cast_ids     = self::post_meta_ids( $movie_id, 'principal_cast', 'person' );
        $studio_ids   = self::studio_ids( $movie_id );

        return array(
            'film'    => $film,
            'signals' => array(
                'directors' => array(
                    'ids'    => $director_ids,
                    'labels' => self::post_labels( $director_ids ),
                ),
                'principal_cast' => array(
                    'ids'    => $cast_ids,
                    'labels' => self::post_labels( $cast_ids ),
                ),
                'studios' => array(
                    'ids'    => $studio_ids,
                    'labels' => self::term_labels( $studio_ids, 'lunara_studio' ),
                ),
            ),
        );
    }

    /**
     * Produce ordered evidence shared by two Movie signal snapshots.
     *
     * @param array<string,mixed> $source Source signals.
     * @param array<string,mixed> $candidate Candidate signals.
     * @return array<int,array<string,mixed>>
     */
    private static function career_evidence( $source, $candidate ) {
        $evidence = array();
        $definitions = array(
            'directors' => array(
                'code'   => 'shared_director',
                'label'  => 'Shared director',
                'weight' => self::WEIGHT_DIRECTOR,
            ),
            'principal_cast' => array(
                'code'   => 'shared_principal_cast',
                'label'  => 'Shared principal cast',
                'weight' => self::WEIGHT_CAST,
            ),
            'studios' => array(
                'code'   => 'shared_studio',
                'label'  => 'Shared studio',
                'weight' => self::WEIGHT_STUDIO,
            ),
        );

        foreach ( $definitions as $signal => $definition ) {
            $source_ids    = array_values( array_map( 'absint', (array) ( $source[ $signal ]['ids'] ?? array() ) ) );
            $candidate_ids = array_values( array_map( 'absint', (array) ( $candidate[ $signal ]['ids'] ?? array() ) ) );
            $shared_ids    = array_values( array_intersect( $source_ids, $candidate_ids ) );
            $shared_ids    = array_values( array_unique( array_filter( $shared_ids ) ) );
            sort( $shared_ids, SORT_NUMERIC );
            if ( empty( $shared_ids ) ) {
                continue;
            }

            $labels_by_id = array();
            foreach ( $source[ $signal ]['ids'] ?? array() as $index => $entity_id ) {
                $labels_by_id[ absint( $entity_id ) ] = (string) ( $source[ $signal ]['labels'][ $index ] ?? '' );
            }
            $labels = array_map( static function ( $entity_id ) use ( $labels_by_id ) {
                return (string) ( $labels_by_id[ $entity_id ] ?? '' );
            }, $shared_ids );

            $evidence[] = array(
                'code'        => $definition['code'],
                'label'       => $definition['label'],
                'entity_ids'  => $shared_ids,
                'labels'      => $labels,
                'unit_weight' => $definition['weight'],
                'score'       => count( $shared_ids ) * $definition['weight'],
            );
        }

        return $evidence;
    }

    /**
     * Human-readable explanation assembled only from structured evidence.
     *
     * @param array<int,array<string,mixed>> $evidence Evidence rows.
     * @return string
     */
    private static function explanation( $evidence ) {
        $parts = array();
        foreach ( $evidence as $item ) {
            $labels = array_values( array_filter( array_map( 'strval', (array) ( $item['labels'] ?? array() ) ), 'strlen' ) );
            $parts[] = (string) $item['label'] . ( empty( $labels ) ? '' : ': ' . implode( ', ', $labels ) );
        }

        return implode( '; ', $parts ) . ( empty( $parts ) ? '' : '.' );
    }

    /**
     * Normalize one ACF relationship field to ordered positive IDs.
     *
     * @param int    $post_id Post ID.
     * @param string $meta_key Meta key.
     * @param string $expected_post_type Required related post type.
     * @return array<int,int>
     */
    private static function post_meta_ids( $post_id, $meta_key, $expected_post_type ) {
        $raw = function_exists( 'get_post_meta' ) ? get_post_meta( $post_id, $meta_key, true ) : array();
        $raw = is_array( $raw ) ? $raw : array( $raw );
        $ids = array_values( array_unique( array_filter( array_map( 'absint', $raw ) ) ) );
        if ( function_exists( 'get_post_type' ) ) {
            $ids = array_values( array_filter( $ids, static function ( $related_id ) use ( $expected_post_type ) {
                if ( $expected_post_type !== get_post_type( $related_id ) ) {
                    return false;
                }
                return ! function_exists( 'get_post_status' ) || 'publish' === get_post_status( $related_id );
            } ) );
        }
        sort( $ids, SORT_NUMERIC );
        return $ids;
    }

    /**
     * Read local Studio taxonomy term IDs.
     *
     * @param int $movie_id Movie ID.
     * @return array<int,int>
     */
    private static function studio_ids( $movie_id ) {
        if ( ! function_exists( 'wp_get_object_terms' ) ) {
            return array();
        }

        $terms = wp_get_object_terms( $movie_id, 'lunara_studio', array( 'fields' => 'ids' ) );
        if ( ! is_array( $terms ) || ( function_exists( 'is_wp_error' ) && is_wp_error( $terms ) ) ) {
            return array();
        }

        $ids = array_values( array_unique( array_filter( array_map( 'absint', $terms ) ) ) );
        sort( $ids, SORT_NUMERIC );
        return $ids;
    }

    /**
     * Resolve local post labels without using them as score identity.
     *
     * @param array<int,int> $ids Post IDs.
     * @return array<int,string>
     */
    private static function post_labels( $ids ) {
        return array_map( static function ( $post_id ) {
            return function_exists( 'get_the_title' ) ? (string) get_the_title( $post_id ) : '';
        }, $ids );
    }

    /**
     * Normalize provider director names for exact local matching.
     *
     * @param mixed $raw_names Provider directors value.
     * @return array<int,string>
     */
    private static function normalize_provider_directors( $raw_names ) {
        if ( ! is_array( $raw_names ) ) {
            return array();
        }

        $names = array();
        foreach ( $raw_names as $raw_name ) {
            // The provider gateway returns structured crew records, while
            // older integrations may still return scalar director labels.
            if ( is_array( $raw_name ) ) {
                if ( ! array_key_exists( 'name', $raw_name ) ) {
                    continue;
                }
                $raw_name = $raw_name['name'];
            }
            if ( ! is_scalar( $raw_name ) || is_bool( $raw_name ) ) {
                continue;
            }
            $name = self::normalize_person_label( (string) $raw_name );
            if ( '' !== $name ) {
                $names[ $name ] = true;
            }
        }

        return array_keys( $names );
    }

    /**
     * Match provider names against a bounded, published local Person query.
     *
     * @param array<int,string> $provider_names Normalized provider names.
     * @return array<int,int>
     */
    private static function resolve_existing_person_ids( $provider_names ) {
        if ( empty( $provider_names ) || ! function_exists( 'get_posts' ) ) {
            return array();
        }

        $wanted = array_fill_keys( $provider_names, true );
        $raw_ids = get_posts(
            array(
                'post_type'              => 'person',
                'post_status'            => array( 'publish' ),
                'posts_per_page'         => self::CANDIDATE_POOL_CAP,
                'fields'                 => 'ids',
                'orderby'                => 'ID',
                'order'                  => 'ASC',
                'no_found_rows'          => true,
                'cache_results'          => false,
                'update_post_meta_cache' => false,
                'update_post_term_cache' => false,
            )
        );

        $matched = array();
        foreach ( is_array( $raw_ids ) ? $raw_ids : array() as $raw_id ) {
            $person_id = absint( $raw_id );
            if ( ! $person_id || ( function_exists( 'get_post_type' ) && 'person' !== get_post_type( $person_id ) ) ) {
                continue;
            }
            if ( function_exists( 'get_post_status' ) && 'publish' !== get_post_status( $person_id ) ) {
                continue;
            }
            $label = function_exists( 'get_the_title' ) ? self::normalize_person_label( get_the_title( $person_id ) ) : '';
            if ( '' !== $label && isset( $wanted[ $label ] ) ) {
                $matched[] = $person_id;
            }
        }

        $matched = array_values( array_unique( $matched ) );
        sort( $matched, SORT_NUMERIC );
        return $matched;
    }

    /**
     * Normalize labels for exact, case-insensitive comparisons.
     *
     * @param mixed $label Label value.
     * @return string
     */
    private static function normalize_person_label( $label ) {
        $label = trim( (string) $label );
        $label = preg_replace( '/\s+/u', ' ', $label );
        if ( function_exists( 'mb_strtolower' ) ) {
            return mb_strtolower( $label, 'UTF-8' );
        }

        return strtolower( $label );
    }

    /**
     * Resolve local taxonomy labels without using them as score identity.
     *
     * @param array<int,int> $ids Term IDs.
     * @param string         $taxonomy Taxonomy name.
     * @return array<int,string>
     */
    private static function term_labels( $ids, $taxonomy ) {
        return array_map( static function ( $term_id ) use ( $taxonomy ) {
            if ( ! function_exists( 'get_term' ) ) {
                return '';
            }
            $term = get_term( $term_id, $taxonomy );
            if ( ! is_object( $term ) || ( function_exists( 'is_wp_error' ) && is_wp_error( $term ) ) ) {
                return '';
            }
            return isset( $term->name ) ? (string) $term->name : '';
        }, $ids );
    }

    /**
     * Standard no-candidate role response.
     *
     * @param string            $role Role key.
     * @param string            $status Result status.
     * @param array<int,string> $reason_codes Explainable reason codes.
     * @return array<string,mixed>
     */
    private static function empty_role_report( $role, $status, $reason_codes ) {
        return array(
            'role'         => $role,
            'status'       => $status,
            'reason_codes' => array_values( $reason_codes ),
            'candidates'   => array(),
        );
    }

    /**
     * Empty structured signal shape.
     *
     * @return array<string,mixed>
     */
    private static function empty_signals() {
        return array(
            'directors'      => array( 'ids' => array(), 'labels' => array() ),
            'principal_cast' => array( 'ids' => array(), 'labels' => array() ),
            'studios'        => array( 'ids' => array(), 'labels' => array() ),
        );
    }

    /**
     * Strict positive-integer parsing for bounded operator input.
     *
     * @param mixed  $value Raw value.
     * @param string $label Error label.
     * @return int
     */
    private static function positive_integer( $value, $label ) {
        if ( ! is_scalar( $value ) || ! preg_match( '/^[1-9]\d*$/', trim( (string) $value ) ) ) {
            throw new InvalidArgumentException( $label . ' must be a positive integer.' );
        }

        return (int) $value;
    }

    /**
     * Deterministic JSON used only for suggestion report hashes.
     *
     * @param mixed $value Value to encode.
     * @return string
     */
    private static function stable_json( $value ) {
        $flags = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;
        if ( defined( 'JSON_INVALID_UTF8_SUBSTITUTE' ) ) {
            $flags |= JSON_INVALID_UTF8_SUBSTITUTE;
        }
        $json = json_encode( self::canonicalize( $value ), $flags );
        if ( false === $json ) {
            throw new RuntimeException( 'Unable to encode the Debrief suggestion report.' );
        }
        return $json;
    }

    /**
     * Recursively sort associative arrays while preserving list order.
     *
     * @param mixed $value Value to normalize.
     * @return mixed
     */
    private static function canonicalize( $value ) {
        if ( ! is_array( $value ) ) {
            return $value;
        }

        $is_list = array_keys( $value ) === range( 0, count( $value ) - 1 );
        if ( ! $is_list ) {
            ksort( $value, SORT_STRING );
        }
        foreach ( $value as $key => $item ) {
            $value[ $key ] = self::canonicalize( $item );
        }

        return $value;
    }
}
