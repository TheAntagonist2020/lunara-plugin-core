<?php
/**
 * Canonical Debrief data contract.
 *
 * This module is deliberately hook-free. It defines the Review-owned Debrief
 * shape, normalizes existing relational fields, and validates readiness while
 * the current editor and public renderer continue unchanged.
 *
 * @package Lunara_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class Lunara_Debrief_Contract {

    const VERSION = 1;

    const STATUS_INCOMPLETE = 'incomplete';
    const STATUS_READY      = 'ready';
    const STATUS_PUBLISHED  = 'published';

    const ROLE_THEME_ECHO      = 'theme_echo';
    const ROLE_COUNTER_PROGRAM = 'counter_program';
    const ROLE_CAREER_CONTEXT  = 'career_context';

    /**
     * Ordered role definitions and their existing ACF/legacy sources.
     *
     * @return array<string,array<string,mixed>>
     */
    public static function roles() {
        return array(
            self::ROLE_THEME_ECHO => array(
                'label'            => __( 'Theme Echo', 'lunara-core' ),
                'movie_field'      => 'theme_echo_movie',
                'reason_field'     => 'theme_echo_note',
                'legacy_meta_keys' => array( '_lunara_theme_echo' ),
            ),
            self::ROLE_COUNTER_PROGRAM => array(
                'label'            => __( 'Counter-Program', 'lunara-core' ),
                'movie_field'      => 'counter_program_movie',
                'reason_field'     => 'counter_program_note',
                'legacy_meta_keys' => array( '_lunara_counter_program' ),
            ),
            self::ROLE_CAREER_CONTEXT => array(
                'label'            => __( 'Career Context', 'lunara-core' ),
                'movie_field'      => 'career_context_movie',
                'reason_field'     => 'career_context_note',
                'legacy_meta_keys' => array( '_lunara_career_context', '_lunara_craft_mirror' ),
            ),
        );
    }

    /**
     * Supported Debrief readiness states.
     *
     * @return array<int,string>
     */
    public static function statuses() {
        return array(
            self::STATUS_INCOMPLETE,
            self::STATUS_READY,
            self::STATUS_PUBLISHED,
        );
    }

    /**
     * Normalize a role or legacy role label to the canonical key.
     *
     * @param mixed $role Raw role value.
     * @return string
     */
    public static function normalize_role( $role ) {
        $role = strtolower( trim( (string) $role ) );
        $role = str_replace( array( ' ', '-' ), '_', $role );
        $role = preg_replace( '/[^a-z0-9_]/', '', $role );

        $aliases = array(
            'theme'           => self::ROLE_THEME_ECHO,
            'theme_echo'      => self::ROLE_THEME_ECHO,
            'counter'         => self::ROLE_COUNTER_PROGRAM,
            'counterprogram'  => self::ROLE_COUNTER_PROGRAM,
            'counter_program' => self::ROLE_COUNTER_PROGRAM,
            'craft'           => self::ROLE_CAREER_CONTEXT,
            'craft_mirror'    => self::ROLE_CAREER_CONTEXT,
            'career'          => self::ROLE_CAREER_CONTEXT,
            'career_context'  => self::ROLE_CAREER_CONTEXT,
        );

        return isset( $aliases[ $role ] ) ? $aliases[ $role ] : '';
    }

    /**
     * Extract and normalize an IMDb title ID without making a remote request.
     *
     * @param mixed $value Raw ID, URL, or text containing an ID.
     * @return string
     */
    public static function normalize_imdb_title_id( $value ) {
        $value = strtolower( trim( (string) $value ) );

        if ( preg_match( '/\b(tt\d{6,9})\b/', $value, $matches ) ) {
            return $matches[1];
        }

        return '';
    }

    /**
     * Empty normalized film reference.
     *
     * @return array<string,mixed>
     */
    public static function empty_film_reference() {
        return array(
            'movie_id'      => 0,
            'review_id'     => 0,
            'imdb_title_id' => '',
            'title'         => '',
            'year'          => '',
        );
    }

    /**
     * Normalize a film relationship or lightweight film snapshot.
     *
     * @param mixed $reference Movie ID, post object, string, or array.
     * @return array<string,mixed>
     */
    public static function normalize_film_reference( $reference ) {
        $film = self::empty_film_reference();

        if ( is_object( $reference ) ) {
            $reference = get_object_vars( $reference );
        }

        if ( is_numeric( $reference ) ) {
            $film['movie_id'] = absint( $reference );
            return $film;
        }

        if ( is_string( $reference ) ) {
            $film['imdb_title_id'] = self::normalize_imdb_title_id( $reference );
            if ( '' === $film['imdb_title_id'] ) {
                $film['title'] = trim( $reference );
            }
            return $film;
        }

        if ( ! is_array( $reference ) ) {
            return $film;
        }

        $movie_id = self::first_value( $reference, array( 'movie_id', 'post_id', 'ID', 'id' ) );
        $review_id = self::first_value( $reference, array( 'review_id' ) );
        $imdb_id   = self::first_value( $reference, array( 'imdb_title_id', 'imdb_id', 'tt' ) );
        $title     = self::first_value( $reference, array( 'title', 'post_title' ) );
        $year      = self::first_value( $reference, array( 'year', 'release_year' ) );

        $film['movie_id']      = absint( $movie_id );
        $film['review_id']     = absint( $review_id );
        $film['imdb_title_id'] = self::normalize_imdb_title_id( $imdb_id );
        $film['title']         = trim( (string) $title );
        $film['year']          = self::normalize_year( $year );

        return $film;
    }

    /**
     * Empty normalized pairing for one fixed role.
     *
     * @param string $role Canonical role.
     * @return array<string,mixed>
     */
    public static function empty_pairing( $role ) {
        return array(
            'role'             => self::normalize_role( $role ),
            'film'             => self::empty_film_reference(),
            'editorial_reason' => '',
            'legacy_value'     => '',
        );
    }

    /**
     * Normalize one pairing row.
     *
     * @param mixed  $pairing      Raw pairing row.
     * @param string $fallback_role Role supplied by an associative array key.
     * @return array<string,mixed>
     */
    public static function normalize_pairing( $pairing, $fallback_role = '' ) {
        if ( ! is_array( $pairing ) ) {
            $pairing = array();
        }

        $role = self::normalize_role( self::first_value( $pairing, array( 'role', 'pairing_role' ) ) );
        if ( '' === $role ) {
            $role = self::normalize_role( $fallback_role );
        }

        $film = self::first_value( $pairing, array( 'film', 'companion_film' ) );
        if ( null === $film ) {
            $film = array(
                'movie_id'      => self::first_value( $pairing, array( 'movie_id' ) ),
                'review_id'     => self::first_value( $pairing, array( 'review_id' ) ),
                'imdb_title_id' => self::first_value( $pairing, array( 'imdb_title_id', 'imdb_id', 'tt' ) ),
                'title'         => self::first_value( $pairing, array( 'title' ) ),
                'year'          => self::first_value( $pairing, array( 'year' ) ),
            );
        }

        return array(
            'role'             => $role,
            'film'             => self::normalize_film_reference( $film ),
            'editorial_reason' => trim( (string) self::first_value( $pairing, array( 'editorial_reason', 'reason', 'note' ) ) ),
            'legacy_value'     => trim( (string) self::first_value( $pairing, array( 'legacy_value' ) ) ),
        );
    }

    /**
     * Normalize a complete Review-owned Debrief record.
     *
     * Fixed ACF field names may be passed directly; callers do not have to
     * construct a repeater-like pairings array.
     *
     * @param mixed $record Raw record.
     * @return array<string,mixed>
     */
    public static function normalize_record( $record ) {
        if ( ! is_array( $record ) ) {
            $record = array();
        }

        $status = strtolower( trim( (string) ( $record['status'] ?? self::STATUS_INCOMPLETE ) ) );
        if ( ! in_array( $status, self::statuses(), true ) ) {
            $status = self::STATUS_INCOMPLETE;
        }

        $by_role = array();
        foreach ( self::raw_pairings( $record ) as $key => $pairing ) {
            $normalized = self::normalize_pairing( $pairing, is_string( $key ) ? $key : '' );
            if ( '' !== $normalized['role'] && ! isset( $by_role[ $normalized['role'] ] ) ) {
                $by_role[ $normalized['role'] ] = $normalized;
            }
        }

        $pairings = array();
        foreach ( array_keys( self::roles() ) as $role ) {
            $pairings[] = isset( $by_role[ $role ] ) ? $by_role[ $role ] : self::empty_pairing( $role );
        }

        $reviewed_film = $record['reviewed_film'] ?? ( $record['debrief_reviewed_film'] ?? array() );
        if ( empty( $reviewed_film ) && ! empty( $record['review_id'] ) ) {
            $reviewed_film = array( 'review_id' => $record['review_id'] );
        }

        return array(
            'contract_version' => self::VERSION,
            'status'           => $status,
            'review_id'        => absint( $record['review_id'] ?? 0 ),
            'reviewed_film'    => self::normalize_film_reference( $reviewed_film ),
            'pairings'         => $pairings,
            'editor_note'      => trim( (string) ( $record['editor_note'] ?? ( $record['debrief_editor_note'] ?? '' ) ) ),
        );
    }

    /**
     * Validate a Debrief record without writing data or changing publication.
     *
     * Incomplete records remain editable: missing requirements are warnings.
     * Ready and published records treat the same requirements as errors.
     *
     * @param mixed     $record Raw record.
     * @param bool|null $strict Force strict validation or infer from status.
     * @return array<string,mixed>
     */
    public static function validate( $record, $strict = null ) {
        $raw        = is_array( $record ) ? $record : array();
        $normalized = self::normalize_record( $raw );
        $errors     = array();
        $warnings   = array();
        $complete   = true;

        $raw_status = strtolower( trim( (string) ( $raw['status'] ?? self::STATUS_INCOMPLETE ) ) );
        if ( '' !== $raw_status && ! in_array( $raw_status, self::statuses(), true ) ) {
            $errors[] = self::issue( 'invalid_status', 'status', __( 'Debrief status is not recognized.', 'lunara-core' ) );
        }

        if ( null === $strict ) {
            $strict = in_array( $normalized['status'], array( self::STATUS_READY, self::STATUS_PUBLISHED ), true );
        }
        $strict = (bool) $strict;

        $role_counts = array_fill_keys( array_keys( self::roles() ), 0 );
        foreach ( self::raw_pairings( $raw ) as $key => $pairing ) {
            $row_role = is_array( $pairing )
                ? self::first_value( $pairing, array( 'role', 'pairing_role' ) )
                : '';
            $role = self::normalize_role( $row_role );
            if ( '' === $role && is_string( $key ) ) {
                $role = self::normalize_role( $key );
            }

            if ( '' === $role ) {
                $complete   = false;
                $errors[]   = self::issue( 'invalid_role', 'pairings', __( 'A Debrief pairing has an invalid role.', 'lunara-core' ) );
                continue;
            }

            ++$role_counts[ $role ];
        }

        foreach ( self::roles() as $role => $definition ) {
            if ( 1 !== $role_counts[ $role ] ) {
                $complete = false;
                $issue    = self::issue(
                    $role_counts[ $role ] > 1 ? 'duplicate_role' : 'missing_role',
                    'pairings.' . $role,
                    sprintf(
                        /* translators: %s: Debrief role label. */
                        __( 'Debrief must contain exactly one %s pairing.', 'lunara-core' ),
                        $definition['label']
                    )
                );
                self::push_issue( $strict, $issue, $errors, $warnings );
            }
        }

        $source_identity = self::film_identity( $normalized['reviewed_film'] );
        if ( '' === $source_identity ) {
            $complete = false;
            self::push_issue(
                $strict,
                self::issue( 'missing_reviewed_film', 'reviewed_film', __( 'Debrief needs a reviewed film identity.', 'lunara-core' ) ),
                $errors,
                $warnings
            );
        }

        $seen_films = array();
        foreach ( $normalized['pairings'] as $index => $pairing ) {
            $identity = self::film_identity( $pairing['film'] );
            $path     = 'pairings.' . $pairing['role'];

            if ( '' === $identity ) {
                $complete = false;
                self::push_issue(
                    $strict,
                    self::issue( 'missing_companion_film', $path . '.film', __( 'Choose a companion film.', 'lunara-core' ) ),
                    $errors,
                    $warnings
                );
            } else {
                if ( isset( $seen_films[ $identity ] ) ) {
                    $complete = false;
                    self::push_issue(
                        $strict,
                        self::issue( 'duplicate_companion_film', $path . '.film', __( 'Each companion film must be unique.', 'lunara-core' ) ),
                        $errors,
                        $warnings
                    );
                }

                if ( '' !== $source_identity && $identity === $source_identity ) {
                    $complete = false;
                    self::push_issue(
                        $strict,
                        self::issue( 'reviewed_film_reused', $path . '.film', __( 'The reviewed film cannot pair with itself.', 'lunara-core' ) ),
                        $errors,
                        $warnings
                    );
                }

                $seen_films[ $identity ] = $index;
            }

            if ( '' === trim( (string) $pairing['editorial_reason'] ) ) {
                $complete = false;
                self::push_issue(
                    $strict,
                    self::issue( 'missing_editorial_reason', $path . '.editorial_reason', __( 'Every companion film needs an editorial reason.', 'lunara-core' ) ),
                    $errors,
                    $warnings
                );
            }
        }

        return array(
            'valid'    => empty( $errors ),
            'complete' => $complete && empty( $errors ),
            'strict'   => $strict,
            'record'   => $normalized,
            'errors'   => $errors,
            'warnings' => $warnings,
        );
    }

    /**
     * Read the existing fixed relational fields into the canonical contract.
     *
     * This is a read-only adapter. It does not migrate or update post meta.
     *
     * @param int $review_id Review post ID.
     * @return array<string,mixed>
     */
    public static function record_from_review( $review_id ) {
        $review_id = absint( $review_id );
        if ( ! $review_id || ! function_exists( 'get_post_meta' ) ) {
            return self::normalize_record( array() );
        }

        $reviewed_film = array(
            'review_id'     => $review_id,
            'imdb_title_id' => get_post_meta( $review_id, '_lunara_imdb_title_id', true ),
            'title'         => function_exists( 'get_the_title' ) ? get_the_title( $review_id ) : '',
            'year'          => get_post_meta( $review_id, '_lunara_year', true ),
        );

        $movie_id = self::find_movie_id_by_imdb( $reviewed_film['imdb_title_id'] );
        if ( $movie_id ) {
            $reviewed_film = self::film_reference_from_movie( $movie_id, $review_id );
        }

        $pairings = array();
        foreach ( self::roles() as $role => $definition ) {
            $relationship_id = absint( get_post_meta( $review_id, $definition['movie_field'], true ) );
            $reason          = (string) get_post_meta( $review_id, $definition['reason_field'], true );
            $legacy_value    = '';

            foreach ( $definition['legacy_meta_keys'] as $meta_key ) {
                $candidate = trim( (string) get_post_meta( $review_id, $meta_key, true ) );
                if ( '' !== $candidate ) {
                    $legacy_value = $candidate;
                    break;
                }
            }

            $film = $relationship_id
                ? self::film_reference_from_movie( $relationship_id )
                : array( 'imdb_title_id' => self::normalize_imdb_title_id( $legacy_value ) );

            $pairings[] = array(
                'role'             => $role,
                'film'             => $film,
                'editorial_reason' => $reason,
                'legacy_value'     => $legacy_value,
            );
        }

        return self::normalize_record(
            array(
                'status'        => get_post_meta( $review_id, 'debrief_status', true ),
                'review_id'     => $review_id,
                'reviewed_film' => $reviewed_film,
                'pairings'      => $pairings,
                'editor_note'   => get_post_meta( $review_id, 'debrief_editor_note', true ),
            )
        );
    }

    /**
     * Stable identity used for duplicate and self-pairing validation.
     *
     * @param mixed $film Raw or normalized film reference.
     * @return string
     */
    public static function film_identity( $film ) {
        $film = self::normalize_film_reference( $film );

        if ( $film['movie_id'] > 0 ) {
            return 'movie:' . $film['movie_id'];
        }
        if ( '' !== $film['imdb_title_id'] ) {
            return 'imdb:' . $film['imdb_title_id'];
        }
        if ( '' !== $film['title'] ) {
            return 'title:' . self::normalize_title( $film['title'] ) . '|' . $film['year'];
        }
        if ( $film['review_id'] > 0 ) {
            return 'review:' . $film['review_id'];
        }

        return '';
    }

    /**
     * Convert fixed field input into raw rows for normalization/validation.
     *
     * @param array<string,mixed> $record Raw record.
     * @return array<mixed>
     */
    private static function raw_pairings( $record ) {
        if ( isset( $record['pairings'] ) && is_array( $record['pairings'] ) ) {
            return $record['pairings'];
        }

        $pairings = array();
        foreach ( self::roles() as $role => $definition ) {
            $legacy_value = '';
            foreach ( $definition['legacy_meta_keys'] as $meta_key ) {
                if ( isset( $record[ $meta_key ] ) && '' !== trim( (string) $record[ $meta_key ] ) ) {
                    $legacy_value = $record[ $meta_key ];
                    break;
                }
            }

            $pairings[ $role ] = array(
                'role'             => $role,
                'movie_id'         => $record[ $definition['movie_field'] ] ?? 0,
                'editorial_reason' => $record[ $definition['reason_field'] ] ?? '',
                'legacy_value'     => $legacy_value,
            );
        }

        return $pairings;
    }

    /**
     * Resolve the first present array value.
     *
     * @param array<string,mixed> $values Candidate data.
     * @param array<int,string>   $keys Candidate keys.
     * @return mixed|null
     */
    private static function first_value( $values, $keys ) {
        foreach ( $keys as $key ) {
            if ( array_key_exists( $key, $values ) ) {
                return $values[ $key ];
            }
        }

        return null;
    }

    /**
     * Normalize a year to four digits.
     *
     * @param mixed $year Raw year.
     * @return string
     */
    private static function normalize_year( $year ) {
        if ( preg_match( '/\b(18|19|20|21)\d{2}\b/', (string) $year, $matches ) ) {
            return $matches[0];
        }

        return '';
    }

    /**
     * Normalize a title for fallback identity comparisons.
     *
     * @param string $title Film title.
     * @return string
     */
    private static function normalize_title( $title ) {
        $title = strtolower( trim( (string) $title ) );
        $title = preg_replace( '/[^a-z0-9]+/', ' ', $title );
        return trim( preg_replace( '/\s+/', ' ', $title ) );
    }

    /**
     * Build a validation issue.
     *
     * @param string $code Issue code.
     * @param string $path Contract path.
     * @param string $message Human-readable message.
     * @return array<string,string>
     */
    private static function issue( $code, $path, $message ) {
        return array(
            'code'    => $code,
            'path'    => $path,
            'message' => $message,
        );
    }

    /**
     * Route a completeness issue to errors or warnings.
     *
     * @param bool                   $strict Strict validation flag.
     * @param array<string,string>   $issue Issue payload.
     * @param array<int,mixed>       $errors Error list.
     * @param array<int,mixed>       $warnings Warning list.
     */
    private static function push_issue( $strict, $issue, &$errors, &$warnings ) {
        if ( $strict ) {
            $errors[] = $issue;
        } else {
            $warnings[] = $issue;
        }
    }

    /**
     * Read a canonical local movie reference.
     *
     * @param int $movie_id Movie post ID.
     * @param int $review_id Optional owning Review ID.
     * @return array<string,mixed>
     */
    private static function film_reference_from_movie( $movie_id, $review_id = 0 ) {
        $movie_id = absint( $movie_id );
        if ( ! $movie_id ) {
            return self::empty_film_reference();
        }

        return self::normalize_film_reference(
            array(
                'movie_id'      => $movie_id,
                'review_id'     => $review_id,
                'imdb_title_id' => get_post_meta( $movie_id, 'imdb_title_id', true ),
                'title'         => function_exists( 'get_the_title' ) ? get_the_title( $movie_id ) : '',
                'year'          => get_post_meta( $movie_id, 'release_year', true ),
            )
        );
    }

    /**
     * Find a local movie entity by IMDb bridge ID.
     *
     * @param mixed $imdb_id Raw IMDb ID.
     * @return int
     */
    private static function find_movie_id_by_imdb( $imdb_id ) {
        $imdb_id = self::normalize_imdb_title_id( $imdb_id );
        if ( '' === $imdb_id || ! function_exists( 'get_posts' ) ) {
            return 0;
        }

        $movie_ids = get_posts(
            array(
                'post_type'              => 'movie',
                'post_status'            => array( 'publish', 'draft', 'pending', 'private' ),
                'posts_per_page'         => 1,
                'fields'                 => 'ids',
                'no_found_rows'          => true,
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

        if ( empty( $movie_ids ) ) {
            return 0;
        }

        $movie = reset( $movie_ids );
        return is_object( $movie ) && isset( $movie->ID ) ? absint( $movie->ID ) : absint( $movie );
    }
}

if ( ! function_exists( 'lunara_debrief_contract_roles' ) ) {
    function lunara_debrief_contract_roles() {
        return Lunara_Debrief_Contract::roles();
    }
}

if ( ! function_exists( 'lunara_debrief_normalize_record' ) ) {
    function lunara_debrief_normalize_record( $record ) {
        return Lunara_Debrief_Contract::normalize_record( $record );
    }
}

if ( ! function_exists( 'lunara_debrief_validate_record' ) ) {
    function lunara_debrief_validate_record( $record, $strict = null ) {
        return Lunara_Debrief_Contract::validate( $record, $strict );
    }
}

if ( ! function_exists( 'lunara_debrief_get_review_record' ) ) {
    function lunara_debrief_get_review_record( $review_id ) {
        return Lunara_Debrief_Contract::record_from_review( $review_id );
    }
}
