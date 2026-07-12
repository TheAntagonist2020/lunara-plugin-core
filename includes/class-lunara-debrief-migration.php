<?php
/**
 * Read-only Debrief migration census and planning service.
 *
 * @package Lunara_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Build deterministic, read-only Debrief migration reports.
 */
final class Lunara_Debrief_Migration {

    const SCHEMA_VERSION = 'lunara-debrief-migration/v1';
    const MODE_CENSUS    = 'census';
    const MODE_DRY_RUN   = 'dry-run';

    /**
     * Run the read-only census.
     *
     * @param array<string,mixed> $args Optional scan arguments.
     * @return array<string,mixed>
     */
    public static function census( $args = array() ) {
        return self::build_report( self::MODE_CENSUS, $args );
    }

    /**
     * Build an idempotent migration plan without writing data.
     *
     * @param array<string,mixed> $args Optional scan arguments.
     * @return array<string,mixed>
     */
    public static function dry_run( $args = array() ) {
        return self::build_report( self::MODE_DRY_RUN, $args );
    }

    /**
     * Parse one legacy pairing value using the current theme's conventions.
     *
     * @param mixed $value Raw legacy value.
     * @return array<string,mixed>
     */
    public static function parse_legacy_value( $value ) {
        $raw    = trim( (string) $value );
        $issues = array();
        $out    = array(
            'raw'              => $raw,
            'imdb_ids'         => array(),
            'imdb_title_id'    => '',
            'title'            => '',
            'year'             => '',
            'editorial_reason' => '',
            'letterboxd_url'   => '',
            'confidence'       => 'empty',
            'issues'           => array(),
        );

        if ( '' === $raw ) {
            return $out;
        }

        preg_match_all( '/\btt\d{6,9}\b/i', $raw, $matches );
        $imdb_ids = array();
        foreach ( isset( $matches[0] ) ? $matches[0] : array() as $match ) {
            $normalized = self::normalize_imdb_title_id( $match );
            if ( '' !== $normalized ) {
                $imdb_ids[] = $normalized;
            }
        }
        $imdb_ids = array_values( array_unique( $imdb_ids ) );
        sort( $imdb_ids, SORT_STRING );

        if ( count( $imdb_ids ) > 1 ) {
            $issues[] = 'multiple_imdb_ids';
        }

        $imdb_id = 1 === count( $imdb_ids ) ? $imdb_ids[0] : '';

        $letterboxd_url = '';
        if ( preg_match( '#(?:https?://)?(?:www\.)?letterboxd\.com/film/[^\s\|\)\]]+/?#i', $raw, $letterboxd_match ) ) {
            $letterboxd_url = $letterboxd_match[0];
            if ( 0 !== stripos( $letterboxd_url, 'http' ) ) {
                $letterboxd_url = 'https://' . ltrim( $letterboxd_url, '/' );
            }
        }

        $clean = $raw;
        foreach ( $imdb_ids as $candidate_id ) {
            $quoted = preg_quote( $candidate_id, '/' );
            $clean  = preg_replace( '/\[\s*' . $quoted . '\s*\]/i', '', $clean );
            $clean  = preg_replace( '/\(\s*' . $quoted . '\s*\)/i', '', $clean );
            $clean  = preg_replace( '#https?://(?:www\.)?imdb\.com/title/' . $quoted . '/?#i', ' ', $clean );
            $clean  = preg_replace( '/\b' . $quoted . '\b/i', ' ', $clean );
        }

        if ( '' !== $letterboxd_url ) {
            $clean = preg_replace( '#' . preg_quote( $letterboxd_url, '#' ) . '#i', ' ', $clean );
        }

        $clean = preg_replace( '/\s*\|\s*(?:imdb|imdb id|imdb title id|lb)\s*:?\s*$/i', '', (string) $clean );
        $clean = preg_replace( '/\s*\|\s*$/', '', (string) $clean );
        $clean = preg_replace( '/\s+([,.;:!?])/', '$1', (string) $clean );
        $clean = trim( preg_replace( '/\s{2,}/', ' ', (string) $clean ) );

        $parts  = preg_split( '/\s+(?:-|\x{2013}|\x{2014})\s+/u', $clean, 2 );
        $title  = trim( isset( $parts[0] ) ? $parts[0] : '' );
        $reason = trim( isset( $parts[1] ) ? $parts[1] : '' );

        if ( '' === $reason && '' !== $imdb_id ) {
            $tail_pattern = '/^(.*?)\b' . preg_quote( $imdb_id, '/' ) . '\b(?:\s*[.:;\-\x{2013}\x{2014}]\s*|\s+)(.+)$/iu';
            if ( preg_match( $tail_pattern, $raw, $tail_match ) ) {
                $title  = self::strip_text( $tail_match[1] );
                $title  = trim( preg_replace( '/\s*(?:\||:|-|\x{2013}|\x{2014})\s*$/u', '', $title ) );
                $reason = self::strip_text( $tail_match[2] );
            }
        }

        if ( '' === $reason && preg_match( '/^(.*?\(\d{4}\))\s*[.:;\-\x{2013}\x{2014}]+\s*(.+)$/u', $title, $year_reason_match ) ) {
            $title  = trim( $year_reason_match[1] );
            $reason = trim( $year_reason_match[2] );
        }

        $title = self::strip_text( $title );
        $year  = '';
        if ( preg_match( '/^(.*?)(?:\s*\((\d{4})\))\s*$/', $title, $title_match ) ) {
            $title = trim( $title_match[1] );
            $year  = $title_match[2];
        }

        if ( '' === $imdb_id ) {
            $issues[] = 'missing_imdb';
        }
        if ( '' === $reason ) {
            $issues[] = 'missing_reason';
        }

        $out['imdb_ids']         = $imdb_ids;
        $out['imdb_title_id']    = $imdb_id;
        $out['title']            = $title;
        $out['year']             = $year;
        $out['editorial_reason'] = $reason;
        $out['letterboxd_url']   = $letterboxd_url;
        $out['issues']           = array_values( array_unique( $issues ) );

        if ( '' !== $imdb_id && '' !== $reason ) {
            $out['confidence'] = 'explicit-imdb-and-reason';
        } elseif ( '' !== $imdb_id ) {
            $out['confidence'] = 'explicit-imdb-only';
        } elseif ( '' !== $title ) {
            $out['confidence'] = 'title-only';
        } else {
            $out['confidence'] = 'unparsed';
        }

        return $out;
    }

    /**
     * Encode a value with deterministic key ordering.
     *
     * @param mixed $value Value to encode.
     * @param bool  $pretty Pretty-print JSON.
     * @return string
     */
    public static function stable_json( $value, $pretty = false ) {
        $flags = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;
        if ( defined( 'JSON_INVALID_UTF8_SUBSTITUTE' ) ) {
            $flags |= JSON_INVALID_UTF8_SUBSTITUTE;
        }
        if ( $pretty ) {
            $flags |= JSON_PRETTY_PRINT;
        }

        $encoded = json_encode( self::canonicalize( $value ), $flags );
        if ( false === $encoded || '' === $encoded ) {
            throw new RuntimeException( 'Unable to encode the Debrief migration report as JSON.' );
        }

        return $encoded;
    }

    /**
     * Build a complete report.
     *
     * @param string              $mode Census or dry-run.
     * @param array<string,mixed> $args Scan arguments.
     * @return array<string,mixed>
     */
    private static function build_report( $mode, $args ) {
        if ( ! class_exists( 'Lunara_Debrief_Contract' ) ) {
            throw new RuntimeException( 'Lunara Debrief contract is required.' );
        }

        $arguments  = self::normalize_arguments( $args );
        $review_ids = self::review_ids( $arguments );
        $reviews    = array();

        foreach ( $review_ids as $review_id ) {
            $reviews[] = self::review_report( $review_id );
        }

        usort(
            $reviews,
            static function ( $left, $right ) {
                return (int) $left['review_id'] <=> (int) $right['review_id'];
            }
        );

        $buckets             = array();
        $issue_buckets       = array();
        $planned_field_writes = 0;

        foreach ( $reviews as $review ) {
            $classification = $review['classification'];
            $buckets[ $classification ] = isset( $buckets[ $classification ] ) ? $buckets[ $classification ] + 1 : 1;
            $planned_field_writes += count( $review['planned_writes'] );

            foreach ( array_values( array_unique( $review['issue_codes'] ) ) as $issue_code ) {
                $issue_buckets[ $issue_code ] = isset( $issue_buckets[ $issue_code ] ) ? $issue_buckets[ $issue_code ] + 1 : 1;
            }
        }
        ksort( $buckets, SORT_STRING );
        ksort( $issue_buckets, SORT_STRING );

        $plan_material = array(
            'schema'  => self::SCHEMA_VERSION,
            'reviews' => array_map(
                static function ( $review ) {
                    return array(
                        'review_id'      => $review['review_id'],
                        'source_hash'    => $review['source_hash'],
                        'classification' => $review['classification'],
                        'planned_writes' => $review['planned_writes'],
                    );
                },
                $reviews
            ),
        );
        $plan_hash = hash( 'sha256', self::stable_json( $plan_material ) );

        return array(
            'schema' => self::SCHEMA_VERSION,
            'mode'   => $mode,
            'run'    => array(
                'run_id'           => 'debrief-' . substr( $plan_hash, 0, 16 ),
                'site_url'         => function_exists( 'home_url' ) ? (string) home_url( '/' ) : '',
                'generated_at_utc' => ! empty( $arguments['generated_at_utc'] ) ? $arguments['generated_at_utc'] : gmdate( 'c' ),
                'arguments'        => array(
                    'post_status'         => $arguments['post_status'],
                    'review_ids'          => $arguments['review_ids'],
                    'review_ids_supplied' => $arguments['review_ids_supplied'],
                    'limit'               => $arguments['limit'],
                    'offset'              => $arguments['offset'],
                ),
            ),
            'summary' => array(
                'reviews_scanned'      => count( $reviews ),
                'buckets'              => $buckets,
                'issue_buckets'        => $issue_buckets,
                'planned_field_writes' => $planned_field_writes,
                'writes_performed'     => 0,
            ),
            'reviews'   => $reviews,
            'plan_hash' => $plan_hash,
        );
    }

    /**
     * Build one Review report.
     *
     * @param int $review_id Review post ID.
     * @return array<string,mixed>
     */
    private static function review_report( $review_id ) {
        $review_id = absint( $review_id );
        $roles       = Lunara_Debrief_Contract::roles();
        $content     = self::post_field( 'post_content', $review_id );
        $markers     = self::content_markers( $content );
        $status      = trim( (string) get_post_meta( $review_id, 'debrief_status', true ) );
        $post_status = function_exists( 'get_post_status' ) ? (string) get_post_status( $review_id ) : '';
        $editor_note = trim( (string) get_post_meta( $review_id, 'debrief_editor_note', true ) );

        $raw_role_data   = array();
        $has_debrief_data = '' !== $editor_note;

        foreach ( $roles as $role => $definition ) {
            $legacy = array();
            foreach ( $definition['legacy_meta_keys'] as $legacy_key ) {
                $legacy[ $legacy_key ] = trim( (string) get_post_meta( $review_id, $legacy_key, true ) );
            }
            $relation_id = absint( get_post_meta( $review_id, $definition['movie_field'], true ) );
            $reason      = trim( (string) get_post_meta( $review_id, $definition['reason_field'], true ) );

            if ( $relation_id || '' !== $reason || array_filter( $legacy, 'strlen' ) ) {
                $has_debrief_data = true;
            }

            $raw_role_data[ $role ] = array(
                'definition'  => $definition,
                'relation_id' => $relation_id,
                'reason'      => $reason,
                'legacy'      => $legacy,
            );
        }

        if ( '' !== $status && Lunara_Debrief_Contract::STATUS_INCOMPLETE !== $status ) {
            $has_debrief_data = true;
        }

        $enforce       = $has_debrief_data;
        $issue_codes   = array();
        $planned_writes = array();
        $source_raw    = trim( (string) get_post_meta( $review_id, '_lunara_imdb_title_id', true ) );
        $source        = self::resolve_raw_imdb( $source_raw, 'source', $enforce );

        if ( $enforce ) {
            $issue_codes = array_merge( $issue_codes, $source['issue_codes'] );
        }

        $role_reports       = array();
        $projected_pairings = array();

        foreach ( $raw_role_data as $role => $raw ) {
            $role_report = self::role_report( $review_id, $role, $raw, $enforce );
            $role_reports[ $role ] = $role_report;
            $issue_codes           = array_merge( $issue_codes, $role_report['issue_codes'] );
            $planned_writes        = array_merge( $planned_writes, $role_report['planned_writes'] );
            $projected_pairings[]  = array(
                'role'             => $role,
                'film'             => $role_report['projected_film'],
                'editorial_reason' => $role_report['projected_reason'],
                'legacy_value'     => $role_report['authoritative_legacy_value'],
            );
        }

        $projected_record = array(
            'status'        => '' !== $status ? $status : Lunara_Debrief_Contract::STATUS_INCOMPLETE,
            'review_id'     => $review_id,
            'reviewed_film' => $source['resolved_film'],
            'pairings'      => $projected_pairings,
            'editor_note'   => '',
        );
        $contract = Lunara_Debrief_Contract::validate( $projected_record, true );
        $contract_codes = array();
        foreach ( array_merge( $contract['errors'], $contract['warnings'] ) as $contract_issue ) {
            if ( isset( $contract_issue['code'] ) ) {
                $contract_codes[] = $contract_issue['code'];
            }
        }

        if ( $enforce ) {
            if ( in_array( 'duplicate_companion_film', $contract_codes, true ) ) {
                $issue_codes[] = 'duplicate_companion';
            }
            if ( in_array( 'reviewed_film_reused', $contract_codes, true ) ) {
                $issue_codes[] = 'self_pairing';
            }
            if (
                in_array( 'invalid_status', $contract_codes, true )
                || ( in_array( $status, array( Lunara_Debrief_Contract::STATUS_READY, Lunara_Debrief_Contract::STATUS_PUBLISHED ), true ) && ! $contract['valid'] )
            ) {
                $issue_codes[] = 'status_invalid';
            }
        }

        $issue_codes = array_values( array_unique( array_filter( $issue_codes ) ) );
        sort( $issue_codes, SORT_STRING );

        // A plan is atomic at the Review level. Never advertise partial writes
        // when any source, identity, reason, or contract ambiguity remains.
        if ( ! empty( $issue_codes ) ) {
            $planned_writes = array();
        }
        usort(
            $planned_writes,
            static function ( $left, $right ) {
                return strcmp( $left['field_name'], $right['field_name'] );
            }
        );

        $classification = self::classification(
            $has_debrief_data,
            $markers,
            $issue_codes,
            $planned_writes,
            $contract
        );

        $hash_roles = array();
        foreach ( $raw_role_data as $role => $raw ) {
            $hash_roles[ $role ] = array(
                'relation_id'       => absint( $raw['relation_id'] ),
                'reason'            => (string) $raw['reason'],
                'legacy'            => $raw['legacy'],
                'current_film'      => self::film_hash_evidence( $role_reports[ $role ]['current']['film'] ),
                'legacy_resolution' => self::resolution_hash_evidence( $role_reports[ $role ]['legacy_resolution'] ),
            );
        }

        $source_material = array(
            'review_id'         => $review_id,
            'post_status'       => $post_status,
            'debrief_status'    => $status,
            'editor_note'       => $editor_note,
            'source_imdb_raw'   => $source_raw,
            'source_resolution' => self::resolution_hash_evidence( $source ),
            'roles'             => $hash_roles,
            'content_markers'   => $markers,
            'issue_codes'       => $issue_codes,
            'planned_writes'    => $planned_writes,
        );

        return array(
            'review_id'      => $review_id,
            'post_status'    => $post_status,
            'post_title'     => function_exists( 'get_the_title' ) ? (string) get_the_title( $review_id ) : '',
            'modified_gmt'   => self::post_field( 'post_modified_gmt', $review_id ),
            'source_hash'    => hash( 'sha256', self::stable_json( $source_material ) ),
            'classification' => $classification,
            'has_debrief_data' => $has_debrief_data,
            'reviewed_film'  => $source,
            'content_markers' => $markers,
            'roles'          => $role_reports,
            'contract'       => array(
                'stored_status' => $status,
                'valid'         => (bool) $contract['valid'],
                'complete'      => (bool) $contract['complete'],
                'issue_codes'   => array_values( array_unique( $contract_codes ) ),
            ),
            'issue_codes'   => $issue_codes,
            'planned_writes' => $planned_writes,
        );
    }

    /**
     * Build one role report.
     *
     * @param int                 $review_id Review ID.
     * @param string              $role Role key.
     * @param array<string,mixed> $raw Raw role sources.
     * @param bool                $enforce Whether the Review contains Debrief data.
     * @return array<string,mixed>
     */
    private static function role_report( $review_id, $role, $raw, $enforce ) {
        $definition = $raw['definition'];
        $legacy     = array();
        $nonempty   = array();

        foreach ( $raw['legacy'] as $meta_key => $value ) {
            $parsed = self::parse_legacy_value( $value );
            $legacy[] = array(
                'meta_key' => $meta_key,
                'value'    => $value,
                'parsed'   => $parsed,
            );
            if ( '' !== $value ) {
                $nonempty[] = array(
                    'meta_key' => $meta_key,
                    'value'    => $value,
                    'parsed'   => $parsed,
                );
            }
        }

        $authoritative = ! empty( $nonempty ) ? $nonempty[0] : array(
            'meta_key' => '',
            'value'    => '',
            'parsed'   => self::parse_legacy_value( '' ),
        );
        $issues = array();

        if ( count( $nonempty ) > 1 ) {
            $distinct_values = array_values( array_unique( array_map( static function ( $item ) {
                return $item['value'];
            }, $nonempty ) ) );
            if ( count( $distinct_values ) > 1 ) {
                $issues[] = 'career_legacy_conflict';
            }
        }

        $relation_id   = absint( $raw['relation_id'] );
        $current_reason = trim( (string) $raw['reason'] );
        $current_film  = $relation_id ? self::movie_snapshot_by_id( $relation_id, $review_id ) : self::empty_film();
        $legacy_resolution = self::resolve_parsed_legacy( $authoritative['parsed'] );

        if ( $relation_id && ! $current_film['public_renderable'] ) {
            $issues[] = 'unrenderable_movie';
        }
        if ( $relation_id && ! empty( $current_film['identity_issue_codes'] ) ) {
            $issues = array_merge( $issues, $current_film['identity_issue_codes'] );
        }

        if ( $relation_id && '' !== $authoritative['value'] && '' !== $authoritative['parsed']['imdb_title_id'] ) {
            $current_imdb = isset( $current_film['effective_imdb_id'] ) ? $current_film['effective_imdb_id'] : '';
            if ( '' === $current_imdb || $current_imdb !== $authoritative['parsed']['imdb_title_id'] ) {
                $issues[] = 'relation_legacy_conflict';
            }
        }

        if ( '' !== $current_reason && '' !== $authoritative['parsed']['editorial_reason'] && $current_reason !== $authoritative['parsed']['editorial_reason'] ) {
            $issues[] = 'reason_conflict';
        }

        if ( $enforce && ( ! $relation_id || '' !== $authoritative['value'] ) ) {
            $issues = array_merge( $issues, $legacy_resolution['issue_codes'] );
        }

        if ( '' === $current_reason && $enforce && '' === $authoritative['parsed']['editorial_reason'] ) {
            $issues[] = 'missing_reason';
        }

        $issues = array_values( array_unique( $issues ) );
        sort( $issues, SORT_STRING );

        $blocking = ! empty( $issues );
        $projected_film = $relation_id && $current_film['public_renderable']
            ? Lunara_Debrief_Contract::movie_reference( $relation_id, $review_id )
            : $legacy_resolution['resolved_film'];
        $projected_reason = '' !== $current_reason ? $current_reason : $authoritative['parsed']['editorial_reason'];
        $writes = array();

        if ( $enforce && ! $blocking ) {
            if ( ! $relation_id && ! empty( $projected_film['movie_id'] ) ) {
                $writes[] = self::planned_write(
                    $definition['movie_field'],
                    $definition['movie_field_key'],
                    0,
                    absint( $projected_film['movie_id'] ),
                    $authoritative['meta_key']
                );
            }
            if ( '' === $current_reason && '' !== $projected_reason ) {
                $writes[] = self::planned_write(
                    $definition['reason_field'],
                    $definition['reason_field_key'],
                    '',
                    $projected_reason,
                    $authoritative['meta_key']
                );
            }
        }

        return array(
            'role'                       => $role,
            'current'                    => array(
                'movie_id' => $relation_id,
                'film'     => $current_film,
                'reason'   => $current_reason,
            ),
            'legacy_sources'             => $legacy,
            'authoritative_legacy_key'   => $authoritative['meta_key'],
            'authoritative_legacy_value' => $authoritative['value'],
            'legacy_resolution'          => $legacy_resolution,
            'projected_film'             => $projected_film,
            'projected_reason'           => $projected_reason,
            'issue_codes'                => $issues,
            'planned_writes'             => $writes,
        );
    }

    /**
     * Resolve one parsed legacy value against every matching local Movie.
     *
     * @param array<string,mixed> $parsed Parsed value.
     * @return array<string,mixed>
     */
    private static function resolve_parsed_legacy( $parsed ) {
        $imdb_id = $parsed['imdb_title_id'];
        if ( count( $parsed['imdb_ids'] ) > 1 ) {
            return self::resolution( 'multiple-imdb-ids', array(), self::empty_film(), array( 'multiple_imdb_ids' ) );
        }
        if ( '' === $imdb_id ) {
            return self::resolution( 'missing-imdb', array(), self::empty_film(), array( 'missing_imdb' ) );
        }

        $candidates = self::movie_candidates( $imdb_id );
        if ( empty( $candidates ) ) {
            return self::resolution( 'movie-not-found', array(), self::empty_film(), array( 'movie_not_found' ) );
        }

        $candidate_issues = array();
        foreach ( $candidates as $candidate ) {
            $candidate_issues = array_merge( $candidate_issues, $candidate['identity_issue_codes'] );
        }
        $candidate_issues = array_values( array_unique( $candidate_issues ) );
        sort( $candidate_issues, SORT_STRING );

        if ( count( $candidates ) > 1 ) {
            $candidate_issues[] = 'duplicate_movie_candidates';
            $candidate_issues   = array_values( array_unique( $candidate_issues ) );
            sort( $candidate_issues, SORT_STRING );
            return self::resolution( 'duplicate-movie-candidates', $candidates, self::empty_film(), $candidate_issues );
        }

        $candidate = $candidates[0];
        $candidate_issues = array_merge( $candidate_issues, self::legacy_candidate_issues( $parsed, $candidate ) );
        if ( empty( $candidate['public_renderable'] ) ) {
            $candidate_issues[] = 'unrenderable_movie';
        }
        $candidate_issues = array_values( array_unique( $candidate_issues ) );
        sort( $candidate_issues, SORT_STRING );

        if ( ! empty( $candidate_issues ) ) {
            return self::resolution( 'candidate-validation-failed', $candidates, self::empty_film(), $candidate_issues );
        }

        return self::resolution(
            'resolved',
            $candidates,
            Lunara_Debrief_Contract::movie_reference( $candidate['movie_id'] ),
            array()
        );
    }

    /**
     * Cross-check parsed legacy presentation evidence against one Movie.
     *
     * @param array<string,mixed> $parsed Parsed legacy value.
     * @param array<string,mixed> $candidate Candidate Movie snapshot.
     * @return array<int,string> Stable blocking issue codes.
     */
    private static function legacy_candidate_issues( $parsed, $candidate ) {
        $issues = array();
        $legacy_title = isset( $parsed['title'] ) ? trim( (string) $parsed['title'] ) : '';
        if ( '' !== $legacy_title ) {
            $legacy_normalized = self::normalize_title_for_comparison( $legacy_title );
            $movie_title       = isset( $candidate['raw_title'] ) ? $candidate['raw_title'] : '';
            $movie_normalized  = self::normalize_title_for_comparison( $movie_title );
            if ( '' === $legacy_normalized || '' === $movie_normalized || $legacy_normalized !== $movie_normalized ) {
                $issues[] = 'legacy_title_mismatch';
            }
        }

        $legacy_year = isset( $parsed['year'] ) ? self::normalize_year_for_comparison( $parsed['year'] ) : '';
        if ( '' !== $legacy_year ) {
            $movie_year = self::normalize_year_for_comparison( isset( $candidate['release_year_raw'] ) ? $candidate['release_year_raw'] : '' );
            if ( $legacy_year !== $movie_year ) {
                $issues[] = 'legacy_year_mismatch';
            }
        }

        return $issues;
    }

    /**
     * Resolve the Review's source-film IMDb bridge.
     *
     * @param string $raw Raw Review IMDb value.
     * @param string $context Resolution context.
     * @param bool   $enforce Whether missing data blocks planning.
     * @return array<string,mixed>
     */
    private static function resolve_raw_imdb( $raw, $context, $enforce ) {
        $parsed     = self::parse_legacy_value( $raw );
        $resolution = self::resolve_parsed_legacy( $parsed );
        $issues     = $enforce ? $resolution['issue_codes'] : array();

        return array(
            'context'          => $context,
            'raw_imdb'         => $raw,
            'normalized_imdb'  => $parsed['imdb_title_id'],
            'imdb_ids'         => $parsed['imdb_ids'],
            'resolution'       => $resolution['resolution'],
            'candidate_ids'    => array_values( array_map( static function ( $candidate ) {
                return $candidate['movie_id'];
            }, $resolution['candidates'] ) ),
            'candidates'       => $resolution['candidates'],
            'resolved_film'    => $resolution['resolved_film'],
            'issue_codes'      => $issues,
        );
    }

    /**
     * Return every Movie carrying an IMDb identifier.
     *
     * @param string $imdb_id Normalized IMDb ID.
     * @return array<int,array<string,mixed>>
     */
    private static function movie_candidates( $imdb_id ) {
        if ( '' === $imdb_id || ! function_exists( 'get_posts' ) ) {
            return array();
        }

        $ids = get_posts(
            array(
                'post_type'              => 'movie',
                'post_status'            => self::all_post_statuses(),
                'posts_per_page'         => -1,
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

        $ids = array_values( array_unique( array_map( 'absint', is_array( $ids ) ? $ids : array() ) ) );
        sort( $ids, SORT_NUMERIC );

        return array_values( array_map( static function ( $movie_id ) use ( $imdb_id ) {
            return self::movie_snapshot_by_id( $movie_id, 0, $imdb_id );
        }, $ids ) );
    }

    /**
     * Describe one local Movie without remote calls.
     *
     * @param int    $movie_id Movie ID.
     * @param int    $review_id Optional owning Review.
     * @param string $requested_imdb_id Optional lookup identity to cross-check.
     * @return array<string,mixed>
     */
    private static function movie_snapshot_by_id( $movie_id, $review_id = 0, $requested_imdb_id = '' ) {
        $movie_id = absint( $movie_id );
        if ( ! $movie_id ) {
            return self::empty_film();
        }

        $reference          = Lunara_Debrief_Contract::movie_reference( $movie_id, $review_id );
        $imdb_meta_raw      = (string) get_post_meta( $movie_id, 'imdb_title_id', true );
        $entity_id_raw      = (string) get_post_meta( $movie_id, '_lunara_entity_id', true );
        $release_year_raw   = (string) get_post_meta( $movie_id, 'release_year', true );
        $imdb_meta_id       = self::normalize_imdb_title_id( $imdb_meta_raw );
        $entity_id          = self::normalize_imdb_title_id( $entity_id_raw );
        $requested_imdb_id  = self::normalize_imdb_title_id( $requested_imdb_id );
        $effective_imdb_id  = '' !== $imdb_meta_id ? $imdb_meta_id : $entity_id;
        $identity_issues    = array();

        if ( '' !== $imdb_meta_id && '' !== $entity_id && $imdb_meta_id !== $entity_id ) {
            $identity_issues[] = 'movie_identity_conflict';
        }
        if ( '' !== $requested_imdb_id && $effective_imdb_id !== $requested_imdb_id ) {
            $identity_issues[] = 'movie_identity_mismatch';
        }
        $identity_issues = array_values( array_unique( $identity_issues ) );
        sort( $identity_issues, SORT_STRING );

        return array(
            'movie_id'             => $movie_id,
            'post_type'            => function_exists( 'get_post_type' ) ? (string) get_post_type( $movie_id ) : '',
            'post_status'          => function_exists( 'get_post_status' ) ? (string) get_post_status( $movie_id ) : '',
            'title'                => isset( $reference['title'] ) ? $reference['title'] : '',
            'raw_title'            => self::raw_post_field( 'post_title', $movie_id ),
            'year'                 => isset( $reference['year'] ) ? $reference['year'] : '',
            'imdb_title_id'        => isset( $reference['imdb_title_id'] ) ? $reference['imdb_title_id'] : '',
            'imdb_meta_raw'        => $imdb_meta_raw,
            'imdb_meta_id'         => $imdb_meta_id,
            'entity_id_raw'        => $entity_id_raw,
            'entity_id'            => $entity_id,
            'effective_imdb_id'    => $effective_imdb_id,
            'requested_imdb_id'    => $requested_imdb_id,
            'release_year_raw'     => $release_year_raw,
            'identity_issue_codes' => $identity_issues,
            'permalink'            => isset( $reference['permalink'] ) ? $reference['permalink'] : '',
            'public_renderable'    => Lunara_Debrief_Contract::is_public_film_reference( $reference ),
        );
    }

    /**
     * Include every registered Movie status in candidate discovery.
     *
     * @return array<int,string>|string Registered statuses, or WordPress's any-status fallback.
     */
    private static function all_post_statuses() {
        if ( ! function_exists( 'get_post_stati' ) ) {
            return array( 'auto-draft', 'draft', 'future', 'inherit', 'pending', 'private', 'publish', 'trash' );
        }

        $statuses = get_post_stati( array(), 'names' );
        $statuses = is_array( $statuses ) ? array_values( array_unique( array_filter( array_map( 'strval', $statuses ) ) ) ) : array();
        sort( $statuses, SORT_STRING );

        return ! empty( $statuses ) ? $statuses : 'any';
    }

    /**
     * Include every editorial Review status in a complete census.
     *
     * Auto-drafts and inherited revisions are internal records, not editorial
     * Reviews. Trash and registered custom statuses remain census evidence.
     *
     * @return array<int,string> Registered Review statuses.
     */
    private static function review_post_statuses() {
        $statuses = self::all_post_statuses();
        $statuses = is_array( $statuses ) ? $statuses : array( 'draft', 'future', 'pending', 'private', 'publish', 'trash' );
        $statuses = array_values( array_diff( $statuses, array( 'auto-draft', 'inherit' ) ) );
        sort( $statuses, SORT_STRING );

        return $statuses;
    }

    /**
     * Detect content sources without treating them as migration authority.
     *
     * @param string $content Review content.
     * @return array<string,mixed>
     */
    private static function content_markers( $content ) {
        $shortcodes = array();
        foreach ( array( 'lunara_debrief', 'lunara_pair_it_with' ) as $shortcode ) {
            if ( preg_match( '/\[' . preg_quote( $shortcode, '/' ) . '\b/i', $content ) ) {
                $shortcodes[] = $shortcode;
            }
        }

        $blocks = array();
        foreach ( array( 'lunara/debrief', 'lunara/pair-it-with' ) as $block ) {
            if ( false !== stripos( $content, '<!-- wp:' . $block ) ) {
                $blocks[] = $block;
            }
        }

        $labels = array();
        $label_patterns = array(
            'theme_echo'      => '/Theme\s+Echo\s*:/i',
            'counter_program' => '/Counter(?:-|\s)Program\s*:/i',
            'career_context'  => '/(?:Career\s+Context|Craft\s+Mirror)\s*:/i',
        );
        foreach ( $label_patterns as $role => $pattern ) {
            if ( preg_match( $pattern, $content ) ) {
                $labels[] = $role;
            }
        }

        return array(
            'shortcodes'       => $shortcodes,
            'blocks'           => $blocks,
            'structured_meta'  => (bool) preg_match( '/<!--\s*LUNARA_REVIEW_META\b/i', $content ),
            'pairing_labels'   => $labels,
            'has_any'          => ! empty( $shortcodes ) || ! empty( $blocks ) || ! empty( $labels ) || (bool) preg_match( '/<!--\s*LUNARA_REVIEW_META\b/i', $content ),
        );
    }

    /**
     * Select one stable primary classification.
     *
     * @param bool                $has_data Whether canonical/legacy data exists.
     * @param array<string,mixed> $markers Content markers.
     * @param array<int,string>   $issues Stable issue codes.
     * @param array<int,mixed>    $writes Proposed writes.
     * @param array<string,mixed> $contract Contract validation result.
     * @return string
     */
    private static function classification( $has_data, $markers, $issues, $writes, $contract ) {
        if ( ! $has_data ) {
            return ! empty( $markers['has_any'] ) ? 'content_only_candidate' : 'no_debrief_data';
        }

        $priority = array(
            'career_legacy_conflict',
            'relation_legacy_conflict',
            'reason_conflict',
            'multiple_imdb_ids',
            'duplicate_movie_candidates',
            'movie_identity_conflict',
            'movie_identity_mismatch',
            'legacy_title_mismatch',
            'legacy_year_mismatch',
            'movie_not_found',
            'unrenderable_movie',
            'missing_imdb',
            'missing_reason',
            'duplicate_companion',
            'self_pairing',
            'status_invalid',
        );
        foreach ( $priority as $classification ) {
            if ( in_array( $classification, $issues, true ) ) {
                return $classification;
            }
        }

        if ( ! empty( $writes ) ) {
            return 'auto_migratable';
        }
        if ( ! empty( $contract['valid'] ) && ! empty( $contract['complete'] ) ) {
            return 'already_migrated';
        }

        return 'manual_review';
    }

    /**
     * Normalize scan arguments.
     *
     * @param array<string,mixed> $args Raw arguments.
     * @return array<string,mixed>
     */
    private static function normalize_arguments( $args ) {
        $args = is_array( $args ) ? $args : array();
        $post_status = isset( $args['post_status'] ) && '' !== trim( (string) $args['post_status'] )
            ? strtolower( trim( (string) $args['post_status'] ) )
            : 'any';
        $review_ids_supplied = array_key_exists( 'review_ids_supplied', $args )
            ? (bool) $args['review_ids_supplied']
            : array_key_exists( 'review_ids', $args );
        $review_ids = array();

        if ( $review_ids_supplied ) {
            $raw_review_ids = isset( $args['review_ids'] ) ? (array) $args['review_ids'] : array();
            if ( empty( $raw_review_ids ) ) {
                throw new InvalidArgumentException( 'The Review ID filter was supplied but contained no IDs.' );
            }

            foreach ( $raw_review_ids as $raw_review_id ) {
                if ( ! is_scalar( $raw_review_id ) ) {
                    throw new InvalidArgumentException( 'Every Review ID must be a positive integer.' );
                }

                $token = trim( (string) $raw_review_id );
                if ( '' === $token || ! preg_match( '/^[1-9]\d*$/', $token ) ) {
                    throw new InvalidArgumentException( 'Invalid Review ID token: ' . ( '' === $token ? '(empty)' : $token ) . '.' );
                }

                $review_ids[] = (int) $token;
            }

            $review_ids = array_values( array_unique( $review_ids ) );
            sort( $review_ids, SORT_NUMERIC );

            if ( ! function_exists( 'get_post_type' ) || ! function_exists( 'get_post_status' ) ) {
                throw new InvalidArgumentException( 'Review IDs cannot be validated in this runtime.' );
            }
            $allowed_statuses = 'any' === $post_status ? self::review_post_statuses() : array( $post_status );
            foreach ( $review_ids as $review_id ) {
                if ( 'review' !== get_post_type( $review_id ) ) {
                    throw new InvalidArgumentException( 'Review ID ' . $review_id . ' does not identify an existing Review.' );
                }
                $actual_status = (string) get_post_status( $review_id );
                if ( ! in_array( $actual_status, $allowed_statuses, true ) ) {
                    throw new InvalidArgumentException(
                        'Review ID ' . $review_id . ' has status ' . $actual_status . ', which does not match --post-status=' . $post_status . '.'
                    );
                }
            }
        }

        return array(
            'post_status'         => $post_status,
            'review_ids'          => $review_ids,
            'review_ids_supplied' => $review_ids_supplied,
            'limit'               => isset( $args['limit'] ) ? max( 0, (int) $args['limit'] ) : 0,
            'offset'              => isset( $args['offset'] ) ? max( 0, (int) $args['offset'] ) : 0,
            'generated_at_utc'    => isset( $args['generated_at_utc'] ) ? trim( (string) $args['generated_at_utc'] ) : '',
        );
    }

    /**
     * Find Review IDs for a scan.
     *
     * @param array<string,mixed> $arguments Normalized arguments.
     * @return array<int,int>
     */
    private static function review_ids( $arguments ) {
        if ( $arguments['review_ids_supplied'] ) {
            $ids = $arguments['review_ids'];
        } elseif ( function_exists( 'get_posts' ) ) {
            $post_status = 'any' === $arguments['post_status']
                ? self::review_post_statuses()
                : $arguments['post_status'];
            $ids = get_posts(
                array(
                    'post_type'              => 'review',
                    'post_status'            => $post_status,
                    'posts_per_page'         => -1,
                    'fields'                 => 'ids',
                    'orderby'                => 'ID',
                    'order'                  => 'ASC',
                    'no_found_rows'          => true,
                    'cache_results'          => false,
                    'update_post_meta_cache' => false,
                    'update_post_term_cache' => false,
                )
            );
        } else {
            $ids = array();
        }

        $ids = array_values( array_unique( array_filter( array_map( 'absint', is_array( $ids ) ? $ids : array() ) ) ) );
        sort( $ids, SORT_NUMERIC );

        $ids = array_slice( $ids, $arguments['offset'], $arguments['limit'] > 0 ? $arguments['limit'] : null );

        return $ids;
    }

    /**
     * Create a planned ACF write descriptor without performing it.
     *
     * @param string $field_name Field name.
     * @param string $field_key ACF field key.
     * @param mixed  $before Existing value.
     * @param mixed  $after Proposed value.
     * @param string $source Legacy source key.
     * @return array<string,mixed>
     */
    private static function planned_write( $field_name, $field_key, $before, $after, $source ) {
        return array(
            'operation'  => 'update_field',
            'field_name' => $field_name,
            'field_key'  => $field_key,
            'before'     => $before,
            'after'      => $after,
            'source'     => $source,
        );
    }

    /**
     * Standard resolution payload.
     *
     * @param string                   $state Resolution state.
     * @param array<int,array<string,mixed>> $candidates Candidate snapshots.
     * @param array<string,mixed>      $film Resolved film.
     * @param array<int,string>        $issues Issue codes.
     * @return array<string,mixed>
     */
    private static function resolution( $state, $candidates, $film, $issues ) {
        return array(
            'resolution'    => $state,
            'candidate_ids' => array_values( array_map( static function ( $candidate ) {
                return $candidate['movie_id'];
            }, $candidates ) ),
            'candidates'    => $candidates,
            'resolved_film' => $film,
            'issue_codes'   => $issues,
        );
    }

    /**
     * Reduce one resolution to raw reconciliation evidence for hashing.
     *
     * @param array<string,mixed> $resolution Resolution report.
     * @return array<string,mixed>
     */
    private static function resolution_hash_evidence( $resolution ) {
        $candidates = isset( $resolution['candidates'] ) && is_array( $resolution['candidates'] )
            ? $resolution['candidates']
            : array();
        $resolved = isset( $resolution['resolved_film'] ) && is_array( $resolution['resolved_film'] )
            ? $resolution['resolved_film']
            : array();

        return array(
            'resolution'        => isset( $resolution['resolution'] ) ? (string) $resolution['resolution'] : '',
            'candidates'        => array_values( array_map( array( __CLASS__, 'film_hash_evidence' ), $candidates ) ),
            'resolved_movie_id' => isset( $resolved['movie_id'] ) ? absint( $resolved['movie_id'] ) : 0,
            'issue_codes'       => isset( $resolution['issue_codes'] ) ? array_values( (array) $resolution['issue_codes'] ) : array(),
        );
    }

    /**
     * Keep only raw Movie reconciliation inputs in report hashes.
     *
     * @param array<string,mixed> $film Movie snapshot.
     * @return array<string,mixed>
     */
    private static function film_hash_evidence( $film ) {
        return array(
            'movie_id'             => isset( $film['movie_id'] ) ? absint( $film['movie_id'] ) : 0,
            'post_type'            => isset( $film['post_type'] ) ? (string) $film['post_type'] : '',
            'post_status'          => isset( $film['post_status'] ) ? (string) $film['post_status'] : '',
            'raw_title'            => isset( $film['raw_title'] ) ? (string) $film['raw_title'] : '',
            'release_year_raw'     => isset( $film['release_year_raw'] ) ? (string) $film['release_year_raw'] : '',
            'imdb_meta_raw'        => isset( $film['imdb_meta_raw'] ) ? (string) $film['imdb_meta_raw'] : '',
            'entity_id_raw'        => isset( $film['entity_id_raw'] ) ? (string) $film['entity_id_raw'] : '',
            'effective_imdb_id'    => isset( $film['effective_imdb_id'] ) ? (string) $film['effective_imdb_id'] : '',
            'requested_imdb_id'    => isset( $film['requested_imdb_id'] ) ? (string) $film['requested_imdb_id'] : '',
            'identity_issue_codes' => isset( $film['identity_issue_codes'] ) ? array_values( (array) $film['identity_issue_codes'] ) : array(),
        );
    }

    /**
     * Empty film shape used by the report.
     *
     * @return array<string,mixed>
     */
    private static function empty_film() {
        return array(
            'movie_id'             => 0,
            'post_type'            => '',
            'post_status'          => '',
            'title'                => '',
            'raw_title'            => '',
            'year'                 => '',
            'imdb_title_id'        => '',
            'imdb_meta_raw'        => '',
            'imdb_meta_id'         => '',
            'entity_id_raw'        => '',
            'entity_id'            => '',
            'effective_imdb_id'    => '',
            'requested_imdb_id'    => '',
            'release_year_raw'     => '',
            'identity_issue_codes' => array(),
            'permalink'            => '',
            'public_renderable'    => false,
        );
    }

    /**
     * Normalize an IMDb ID through the Core contract.
     *
     * @param mixed $value Raw value.
     * @return string
     */
    private static function normalize_imdb_title_id( $value ) {
        return class_exists( 'Lunara_Debrief_Contract' )
            ? Lunara_Debrief_Contract::normalize_imdb_title_id( $value )
            : ( preg_match( '/\b(tt\d{6,9})\b/i', (string) $value, $match ) ? strtolower( $match[1] ) : '' );
    }

    /**
     * Safely read a post field.
     *
     * @param string $field Field name.
     * @param int    $post_id Post ID.
     * @return string
     */
    private static function post_field( $field, $post_id ) {
        return function_exists( 'get_post_field' ) ? (string) get_post_field( $field, $post_id ) : '';
    }

    /**
     * Read an unfiltered post field for migration reconciliation.
     *
     * @param string $field Field name.
     * @param int    $post_id Post ID.
     * @return string
     */
    private static function raw_post_field( $field, $post_id ) {
        return function_exists( 'get_post_field' ) ? (string) get_post_field( $field, $post_id, 'raw' ) : '';
    }

    /**
     * Normalize a title without fuzzy matching or editorial assumptions.
     *
     * Case, whitespace, and punctuation are presentation differences. Words
     * and numbers must otherwise match exactly.
     *
     * @param mixed $title Raw title.
     * @return string
     */
    private static function normalize_title_for_comparison( $title ) {
        $title = self::strip_text( $title );
        if ( '' === $title ) {
            return '';
        }

        $title = function_exists( 'mb_strtolower' ) ? mb_strtolower( $title, 'UTF-8' ) : strtolower( $title );
        $normalized = preg_replace( '/[^\p{L}\p{N}]+/u', ' ', $title );
        if ( ! is_string( $normalized ) ) {
            return '';
        }

        return trim( preg_replace( '/\s+/', ' ', $normalized ) );
    }

    /**
     * Normalize a four-digit year for exact comparison.
     *
     * @param mixed $year Raw year.
     * @return string
     */
    private static function normalize_year_for_comparison( $year ) {
        return preg_match( '/\b(18|19|20|21)\d{2}\b/', (string) $year, $match ) ? $match[0] : '';
    }

    /**
     * Strip HTML and normalize whitespace.
     *
     * @param mixed $value Raw value.
     * @return string
     */
    private static function strip_text( $value ) {
        $value = html_entity_decode( (string) $value, ENT_QUOTES, 'UTF-8' );
        $value = function_exists( 'wp_strip_all_tags' ) ? wp_strip_all_tags( $value ) : strip_tags( $value );
        return trim( preg_replace( '/\s{2,}/', ' ', $value ) );
    }

    /**
     * Canonically sort associative arrays before hashing or JSON output.
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
