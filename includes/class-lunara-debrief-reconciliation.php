<?php
/**
 * Deterministic operator reconciliation packs for Debrief migration evidence.
 *
 * @package Lunara_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Project the Release C census into bounded operator queues without writes.
 */
final class Lunara_Debrief_Reconciliation {

    const SCHEMA_VERSION = 'lunara-debrief-reconciliation/v1';

    /**
     * Run a census and build its reconciliation pack.
     *
     * @param array<string,mixed> $args Census scope.
     * @return array<string,mixed>
     */
    public static function build_pack( $args = array() ) {
        if ( ! class_exists( 'Lunara_Debrief_Migration' ) ) {
            throw new RuntimeException( 'Lunara Debrief migration service is required.' );
        }

        return self::from_census( Lunara_Debrief_Migration::census( $args ) );
    }

    /**
     * Convert an existing census into deterministic operator queues.
     *
     * @param array<string,mixed> $census Census report.
     * @return array<string,mixed>
     */
    public static function from_census( $census ) {
        if ( ! is_array( $census )
            || empty( $census['plan_hash'] )
            || ! isset( $census['reviews'] )
            || ! is_array( $census['reviews'] )
        ) {
            throw new InvalidArgumentException( 'A complete Debrief census report is required.' );
        }

        $reviews = array_values( $census['reviews'] );
        usort( $reviews, array( __CLASS__, 'sort_reviews' ) );

        $missing_by_imdb  = array();
        $auto_migratable  = array();
        $conflicts        = array();
        $content_retirement = array();
        $no_data          = array();

        foreach ( $reviews as $review ) {
            if ( ! is_array( $review ) ) {
                continue;
            }

            $base = self::review_identity( $review );
            $classification = (string) ( $review['classification'] ?? '' );

            if ( 'auto_migratable' === $classification ) {
                $item                   = $base;
                $item['planned_writes'] = array_values( (array) ( $review['planned_writes'] ?? array() ) );
                $auto_migratable[]      = $item;
            }

            if ( in_array( $classification, array( 'no_debrief_data', 'content_only_candidate' ), true ) ) {
                $no_data[] = $base;
            }

            $markers = isset( $review['content_markers'] ) && is_array( $review['content_markers'] )
                ? $review['content_markers']
                : array();
            if ( ! empty( $markers['has_any'] ) ) {
                $item                    = $base;
                $item['content_markers'] = array(
                    'shortcodes'      => array_values( (array) ( $markers['shortcodes'] ?? array() ) ),
                    'blocks'          => array_values( (array) ( $markers['blocks'] ?? array() ) ),
                    'structured_meta' => ! empty( $markers['structured_meta'] ),
                    'pairing_labels'  => array_values( (array) ( $markers['pairing_labels'] ?? array() ) ),
                );
                $content_retirement[] = $item;
            }

            self::collect_source_missing_movie( $missing_by_imdb, $review );
            self::collect_role_missing_movies( $missing_by_imdb, $review );

            $conflict = self::conflict_item( $review );
            if ( ! empty( $conflict ) ) {
                $conflicts[] = $conflict;
            }
        }

        $missing_movies = self::finalize_missing_movies( $missing_by_imdb );
        usort( $auto_migratable, array( __CLASS__, 'sort_reviews' ) );
        usort( $conflicts, array( __CLASS__, 'sort_reviews' ) );
        usort( $content_retirement, array( __CLASS__, 'sort_reviews' ) );
        usort( $no_data, array( __CLASS__, 'sort_reviews' ) );

        $source_missing    = 0;
        $companion_missing = 0;
        foreach ( $missing_movies as $missing_movie ) {
            foreach ( $missing_movie['contexts'] as $context ) {
                if ( 'reviewed_film' === $context['lane'] ) {
                    ++$source_missing;
                } else {
                    ++$companion_missing;
                }
            }
        }

        $summary = array(
            'reviews_scanned'              => count( $reviews ),
            'auto_migratable_reviews'      => count( $auto_migratable ),
            'conflict_reviews'             => count( $conflicts ),
            'content_retirement_reviews'   => count( $content_retirement ),
            'no_data_reviews'              => count( $no_data ),
            'unique_missing_movies'        => count( $missing_movies ),
            'missing_reference_occurrences' => $source_missing + $companion_missing,
            'missing_source_occurrences'   => $source_missing,
            'missing_companion_occurrences' => $companion_missing,
            'planned_field_writes'         => (int) ( $census['summary']['planned_field_writes'] ?? 0 ),
            'writes_performed'             => 0,
        );

        $queues = array(
            'missing_movies'     => $missing_movies,
            'conflicts'          => $conflicts,
            'auto_migratable'    => $auto_migratable,
            'content_retirement' => $content_retirement,
            'no_data'            => $no_data,
        );

        $source_plan_hash = (string) $census['plan_hash'];
        $pack_hash = hash(
            'sha256',
            Lunara_Debrief_Migration::stable_json(
                array(
                    'schema'           => self::SCHEMA_VERSION,
                    'source_plan_hash' => $source_plan_hash,
                    'summary'          => $summary,
                    'queues'           => $queues,
                )
            )
        );

        return array(
            'schema'           => self::SCHEMA_VERSION,
            'mode'             => 'reconciliation-pack',
            'run'              => isset( $census['run'] ) && is_array( $census['run'] ) ? $census['run'] : array(),
            'source_plan_hash' => $source_plan_hash,
            'summary'          => $summary,
            'queues'           => $queues,
            'pack_hash'        => $pack_hash,
        );
    }

    /**
     * Add a source-film Movie gap to the grouped queue.
     *
     * @param array<string,mixed> $missing Queue accumulator.
     * @param array<string,mixed> $review Review report.
     */
    private static function collect_source_missing_movie( &$missing, $review ) {
        $source = isset( $review['reviewed_film'] ) && is_array( $review['reviewed_film'] )
            ? $review['reviewed_film']
            : array();
        if ( ! in_array( 'movie_not_found', (array) ( $source['issue_codes'] ?? array() ), true ) ) {
            return;
        }

        self::add_missing_context(
            $missing,
            (string) ( $source['normalized_imdb'] ?? '' ),
            array(
                'review_id'   => absint( $review['review_id'] ?? 0 ),
                'lane'        => 'reviewed_film',
                'role'        => '',
                'source_hash' => (string) ( $review['source_hash'] ?? '' ),
                'meta_key'    => '_lunara_imdb_title_id',
                'raw_value'   => (string) ( $source['raw_imdb'] ?? '' ),
                'parsed_title' => '',
                'parsed_year' => '',
            )
        );
    }

    /**
     * Add companion-film Movie gaps to the grouped queue.
     *
     * @param array<string,mixed> $missing Queue accumulator.
     * @param array<string,mixed> $review Review report.
     */
    private static function collect_role_missing_movies( &$missing, $review ) {
        $roles = isset( $review['roles'] ) && is_array( $review['roles'] ) ? $review['roles'] : array();
        foreach ( array_keys( Lunara_Debrief_Contract::roles() ) as $role ) {
            $role_report = isset( $roles[ $role ] ) && is_array( $roles[ $role ] ) ? $roles[ $role ] : array();
            if ( ! in_array( 'movie_not_found', (array) ( $role_report['issue_codes'] ?? array() ), true ) ) {
                continue;
            }

            $source = self::authoritative_legacy_source( $role_report );
            $parsed = isset( $source['parsed'] ) && is_array( $source['parsed'] ) ? $source['parsed'] : array();
            self::add_missing_context(
                $missing,
                (string) ( $parsed['imdb_title_id'] ?? '' ),
                array(
                    'review_id'    => absint( $review['review_id'] ?? 0 ),
                    'lane'         => 'companion',
                    'role'         => $role,
                    'source_hash'  => (string) ( $review['source_hash'] ?? '' ),
                    'meta_key'     => (string) ( $source['meta_key'] ?? '' ),
                    'raw_value'    => (string) ( $source['value'] ?? '' ),
                    'parsed_title' => (string) ( $parsed['title'] ?? '' ),
                    'parsed_year'  => (string) ( $parsed['year'] ?? '' ),
                )
            );
        }
    }

    /**
     * Add one context to a normalized IMDb queue entry.
     *
     * @param array<string,mixed> $missing Queue accumulator.
     * @param string              $imdb_id Normalized IMDb title ID.
     * @param array<string,mixed> $context Context evidence.
     */
    private static function add_missing_context( &$missing, $imdb_id, $context ) {
        $imdb_id = Lunara_Debrief_Contract::normalize_imdb_title_id( $imdb_id );
        if ( '' === $imdb_id ) {
            return;
        }

        if ( ! isset( $missing[ $imdb_id ] ) ) {
            $missing[ $imdb_id ] = array(
                'imdb_title_id' => $imdb_id,
                'contexts'      => array(),
            );
        }
        $missing[ $imdb_id ]['contexts'][] = $context;
    }

    /**
     * Sort and complete grouped missing-Movie rows.
     *
     * @param array<string,mixed> $missing Grouped rows.
     * @return array<int,array<string,mixed>>
     */
    private static function finalize_missing_movies( $missing ) {
        ksort( $missing, SORT_STRING );
        $items = array();
        foreach ( $missing as $item ) {
            usort( $item['contexts'], array( __CLASS__, 'sort_contexts' ) );
            $review_ids = array_values( array_unique( array_map( static function ( $context ) {
                return absint( $context['review_id'] ?? 0 );
            }, $item['contexts'] ) ) );
            sort( $review_ids, SORT_NUMERIC );
            $item['occurrence_count'] = count( $item['contexts'] );
            $item['review_ids']       = $review_ids;
            $items[]                  = $item;
        }

        return $items;
    }

    /**
     * Build a conflict row when manual editorial reconciliation is required.
     *
     * @param array<string,mixed> $review Review report.
     * @return array<string,mixed>
     */
    private static function conflict_item( $review ) {
        $conflict_codes = array(
            'career_legacy_conflict',
            'relation_legacy_conflict',
            'reason_conflict',
            'multiple_imdb_ids',
            'duplicate_movie_candidates',
            'movie_identity_conflict',
            'movie_identity_mismatch',
            'legacy_title_mismatch',
            'legacy_year_mismatch',
            'unrenderable_movie',
            'missing_imdb',
            'missing_reason',
            'duplicate_companion',
            'self_pairing',
            'status_invalid',
        );
        $issue_codes = array_values( array_unique( (array) ( $review['issue_codes'] ?? array() ) ) );
        sort( $issue_codes, SORT_STRING );
        if ( empty( array_intersect( $issue_codes, $conflict_codes ) ) ) {
            return array();
        }

        $item                = self::review_identity( $review );
        $item['issue_codes'] = $issue_codes;
        $item['source']      = self::resolution_evidence( $review['reviewed_film'] ?? array() );
        $item['roles']       = array();

        $roles = isset( $review['roles'] ) && is_array( $review['roles'] ) ? $review['roles'] : array();
        foreach ( array_keys( Lunara_Debrief_Contract::roles() ) as $role ) {
            $role_report = isset( $roles[ $role ] ) && is_array( $roles[ $role ] ) ? $roles[ $role ] : array();
            if ( empty( $role_report['issue_codes'] ) ) {
                continue;
            }

            $item['roles'][] = array(
                'role'                     => $role,
                'issue_codes'              => array_values( (array) $role_report['issue_codes'] ),
                'current_movie_id'         => absint( $role_report['current']['movie_id'] ?? 0 ),
                'current_reason'           => (string) ( $role_report['current']['reason'] ?? '' ),
                'authoritative_legacy_key' => (string) ( $role_report['authoritative_legacy_key'] ?? '' ),
                'legacy_sources'           => array_values( (array) ( $role_report['legacy_sources'] ?? array() ) ),
                'resolution'               => self::resolution_evidence( $role_report['legacy_resolution'] ?? array() ),
            );
        }

        return $item;
    }

    /**
     * Keep the operator-facing portion of one resolution.
     *
     * @param mixed $resolution Resolution report.
     * @return array<string,mixed>
     */
    private static function resolution_evidence( $resolution ) {
        $resolution = is_array( $resolution ) ? $resolution : array();
        return array(
            'resolution'     => (string) ( $resolution['resolution'] ?? '' ),
            'normalized_imdb' => (string) ( $resolution['normalized_imdb'] ?? '' ),
            'candidate_ids'  => array_values( array_map( 'absint', (array) ( $resolution['candidate_ids'] ?? array() ) ) ),
            'issue_codes'    => array_values( (array) ( $resolution['issue_codes'] ?? array() ) ),
        );
    }

    /**
     * Locate the source selected by the migration planner.
     *
     * @param array<string,mixed> $role_report Role report.
     * @return array<string,mixed>
     */
    private static function authoritative_legacy_source( $role_report ) {
        $key = (string) ( $role_report['authoritative_legacy_key'] ?? '' );
        foreach ( (array) ( $role_report['legacy_sources'] ?? array() ) as $source ) {
            if ( is_array( $source ) && $key === (string) ( $source['meta_key'] ?? '' ) ) {
                return $source;
            }
        }

        return array();
    }

    /**
     * Common Review identity fields for every queue.
     *
     * @param array<string,mixed> $review Review report.
     * @return array<string,mixed>
     */
    private static function review_identity( $review ) {
        return array(
            'review_id'      => absint( $review['review_id'] ?? 0 ),
            'post_status'    => (string) ( $review['post_status'] ?? '' ),
            'post_title'     => (string) ( $review['post_title'] ?? '' ),
            'source_hash'    => (string) ( $review['source_hash'] ?? '' ),
            'classification' => (string) ( $review['classification'] ?? '' ),
        );
    }

    /**
     * Sort Review-shaped rows by stable numeric ID.
     *
     * @param array<string,mixed> $left Left row.
     * @param array<string,mixed> $right Right row.
     * @return int
     */
    private static function sort_reviews( $left, $right ) {
        return absint( $left['review_id'] ?? 0 ) <=> absint( $right['review_id'] ?? 0 );
    }

    /**
     * Sort missing-reference contexts by Review and fixed lane order.
     *
     * @param array<string,mixed> $left Left context.
     * @param array<string,mixed> $right Right context.
     * @return int
     */
    private static function sort_contexts( $left, $right ) {
        $review_order = absint( $left['review_id'] ?? 0 ) <=> absint( $right['review_id'] ?? 0 );
        if ( 0 !== $review_order ) {
            return $review_order;
        }

        $order = array(
            'reviewed_film'  => 0,
            'theme_echo'     => 1,
            'counter_program' => 2,
            'career_context' => 3,
        );
        $left_key  = 'reviewed_film' === ( $left['lane'] ?? '' ) ? 'reviewed_film' : (string) ( $left['role'] ?? '' );
        $right_key = 'reviewed_film' === ( $right['lane'] ?? '' ) ? 'reviewed_film' : (string) ( $right['role'] ?? '' );

        return ( $order[ $left_key ] ?? 99 ) <=> ( $order[ $right_key ] ?? 99 );
    }
}
