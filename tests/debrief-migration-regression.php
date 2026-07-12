<?php
/**
 * Dependency-free regression checks for the read-only Debrief migration plan.
 *
 * Run with: php tests/debrief-migration-regression.php
 */

define( 'ABSPATH', __DIR__ . '/' );
define( 'WP_CLI', true );

$GLOBALS['lunara_migration_test'] = array(
    'posts' => array(
        10 => array( 'post_type' => 'movie', 'post_status' => 'publish', 'title' => 'Source Film', 'content' => '', 'modified_gmt' => '2026-07-01 00:00:00' ),
        11 => array( 'post_type' => 'movie', 'post_status' => 'publish', 'title' => 'Theme Film', 'content' => '', 'modified_gmt' => '2026-07-01 00:00:00' ),
        12 => array( 'post_type' => 'movie', 'post_status' => 'publish', 'title' => 'Counter Film', 'content' => '', 'modified_gmt' => '2026-07-01 00:00:00' ),
        13 => array( 'post_type' => 'movie', 'post_status' => 'publish', 'title' => 'Career Film', 'content' => '', 'modified_gmt' => '2026-07-01 00:00:00' ),
        14 => array( 'post_type' => 'movie', 'post_status' => 'draft', 'title' => 'Draft Film', 'content' => '', 'modified_gmt' => '2026-07-01 00:00:00' ),
        20 => array( 'post_type' => 'movie', 'post_status' => 'publish', 'title' => 'Duplicate Film A', 'content' => '', 'modified_gmt' => '2026-07-01 00:00:00' ),
        21 => array( 'post_type' => 'movie', 'post_status' => 'archive', 'title' => 'Duplicate Film B', 'content' => '', 'modified_gmt' => '2026-07-01 00:00:00' ),
        22 => array( 'post_type' => 'movie', 'post_status' => 'publish', 'title' => 'Divergent Film', 'content' => '', 'modified_gmt' => '2026-07-01 00:00:00' ),

        100 => array( 'post_type' => 'review', 'post_status' => 'publish', 'title' => 'Automatic', 'content' => '', 'modified_gmt' => '2026-07-02 00:00:00' ),
        101 => array( 'post_type' => 'review', 'post_status' => 'publish', 'title' => 'Already Migrated', 'content' => '', 'modified_gmt' => '2026-07-02 00:00:01' ),
        102 => array( 'post_type' => 'review', 'post_status' => 'publish', 'title' => 'Duplicate Candidate', 'content' => '', 'modified_gmt' => '2026-07-02 00:00:02' ),
        103 => array( 'post_type' => 'review', 'post_status' => 'publish', 'title' => 'Relation Conflict', 'content' => '', 'modified_gmt' => '2026-07-02 00:00:03' ),
        104 => array( 'post_type' => 'review', 'post_status' => 'publish', 'title' => 'Career Conflict', 'content' => '', 'modified_gmt' => '2026-07-02 00:00:04' ),
        105 => array( 'post_type' => 'review', 'post_status' => 'publish', 'title' => 'Missing Reason', 'content' => '', 'modified_gmt' => '2026-07-02 00:00:05' ),
        106 => array( 'post_type' => 'review', 'post_status' => 'publish', 'title' => 'Content Only', 'content' => '[lunara_debrief] <!-- wp:lunara/pair-it-with /-->', 'modified_gmt' => '2026-07-02 00:00:06' ),
        107 => array( 'post_type' => 'review', 'post_status' => 'draft', 'title' => 'No Debrief', 'content' => '', 'modified_gmt' => '2026-07-02 00:00:07' ),
        108 => array( 'post_type' => 'review', 'post_status' => 'publish', 'title' => 'Self Pair', 'content' => '', 'modified_gmt' => '2026-07-02 00:00:08' ),
        109 => array( 'post_type' => 'review', 'post_status' => 'publish', 'title' => 'Multiple IDs', 'content' => '', 'modified_gmt' => '2026-07-02 00:00:09' ),
        111 => array( 'post_type' => 'review', 'post_status' => 'publish', 'title' => 'Draft Candidate', 'content' => '', 'modified_gmt' => '2026-07-02 00:00:11' ),
        112 => array( 'post_type' => 'review', 'post_status' => 'publish', 'title' => 'Missing Movie', 'content' => '', 'modified_gmt' => '2026-07-02 00:00:12' ),
        113 => array( 'post_type' => 'review', 'post_status' => 'publish', 'title' => 'Missing IMDb', 'content' => '', 'modified_gmt' => '2026-07-02 00:00:13' ),
        114 => array( 'post_type' => 'review', 'post_status' => 'publish', 'title' => 'Reason Conflict', 'content' => '', 'modified_gmt' => '2026-07-02 00:00:14' ),
        115 => array( 'post_type' => 'review', 'post_status' => 'publish', 'title' => 'Invalid Debrief Status', 'content' => '', 'modified_gmt' => '2026-07-02 00:00:15' ),
        116 => array( 'post_type' => 'review', 'post_status' => 'publish', 'title' => 'Related With Ambiguous Legacy', 'content' => '', 'modified_gmt' => '2026-07-02 00:00:16' ),
        117 => array( 'post_type' => 'review', 'post_status' => 'publish', 'title' => 'Duplicate Companion', 'content' => '', 'modified_gmt' => '2026-07-02 00:00:17' ),
        118 => array( 'post_type' => 'review', 'post_status' => 'trash', 'title' => 'Trashed Review', 'content' => '', 'modified_gmt' => '2026-07-02 00:00:18' ),
        119 => array( 'post_type' => 'review', 'post_status' => 'archive', 'title' => 'Archived Review', 'content' => '', 'modified_gmt' => '2026-07-02 00:00:19' ),
        120 => array( 'post_type' => 'review', 'post_status' => 'auto-draft', 'title' => 'Internal Auto Draft', 'content' => '', 'modified_gmt' => '2026-07-02 00:00:20' ),
        121 => array( 'post_type' => 'review', 'post_status' => 'inherit', 'title' => 'Inherited Revision', 'content' => '', 'modified_gmt' => '2026-07-02 00:00:21' ),
        122 => array( 'post_type' => 'review', 'post_status' => 'publish', 'title' => 'Legacy Title Mismatch', 'content' => '', 'modified_gmt' => '2026-07-02 00:00:22' ),
        123 => array( 'post_type' => 'review', 'post_status' => 'publish', 'title' => 'Legacy Year Mismatch', 'content' => '', 'modified_gmt' => '2026-07-02 00:00:23' ),
        124 => array( 'post_type' => 'review', 'post_status' => 'publish', 'title' => 'Divergent Movie Identity', 'content' => '', 'modified_gmt' => '2026-07-02 00:00:24' ),
    ),
    'meta' => array(
        10 => array( 'imdb_title_id' => 'tt0000010', 'release_year' => '2024' ),
        11 => array( 'imdb_title_id' => 'tt0000011', 'release_year' => '2020' ),
        12 => array( 'imdb_title_id' => 'tt0000012', 'release_year' => '2018' ),
        13 => array( 'imdb_title_id' => 'tt0000013', 'release_year' => '2015' ),
        14 => array( 'imdb_title_id' => 'tt0000014', 'release_year' => '2010' ),
        20 => array( 'imdb_title_id' => 'tt0000020', 'release_year' => '2001' ),
        21 => array( '_lunara_entity_id' => 'tt0000020', 'release_year' => '2001' ),
        22 => array( 'imdb_title_id' => 'tt0000022', '_lunara_entity_id' => 'tt0000023', 'release_year' => '2002' ),

        100 => array(
            '_lunara_imdb_title_id'  => 'tt0000010',
            '_lunara_theme_echo'     => 'THEME-FILM (2020) tt0000011 - It carries the same question forward.',
            '_lunara_counter_program'=> 'Counter Film (2018) tt0000012 - It changes the argument and temperature.',
            '_lunara_career_context' => 'Career Film (2015) tt0000013 - It reveals the artist refining the same idea.',
            '_lunara_craft_mirror'   => 'Career Film (2015) tt0000013 - It reveals the artist refining the same idea.',
        ),
        101 => array(
            '_lunara_imdb_title_id' => 'tt0000010',
            'debrief_status'        => 'ready',
            'theme_echo_movie'      => 11,
            'theme_echo_note'       => 'Theme reason.',
            'counter_program_movie' => 12,
            'counter_program_note'  => 'Counter reason.',
            'career_context_movie'  => 13,
            'career_context_note'   => 'Career reason.',
        ),
        102 => array(
            '_lunara_imdb_title_id'   => 'tt0000010',
            '_lunara_theme_echo'      => 'Duplicate Film tt0000020 - Duplicate ambiguity.',
            '_lunara_counter_program' => 'Counter Film tt0000012 - Counter reason.',
            '_lunara_career_context'  => 'Career Film tt0000013 - Career reason.',
        ),
        103 => array(
            '_lunara_imdb_title_id' => 'tt0000010',
            'theme_echo_movie'      => 11,
            'theme_echo_note'       => 'Same reason.',
            '_lunara_theme_echo'    => 'Counter Film tt0000012 - Same reason.',
            'counter_program_movie' => 12,
            'counter_program_note'  => 'Counter reason.',
            'career_context_movie'  => 13,
            'career_context_note'   => 'Career reason.',
        ),
        104 => array(
            '_lunara_imdb_title_id'  => 'tt0000010',
            'theme_echo_movie'       => 11,
            'theme_echo_note'        => 'Theme reason.',
            'counter_program_movie'  => 12,
            'counter_program_note'   => 'Counter reason.',
            '_lunara_career_context' => 'Career Film tt0000013 - Current reason.',
            '_lunara_craft_mirror'   => 'Counter Film tt0000012 - Legacy reason.',
        ),
        105 => array(
            '_lunara_imdb_title_id'   => 'tt0000010',
            '_lunara_theme_echo'      => 'Theme Film tt0000011',
            '_lunara_counter_program' => 'Counter Film tt0000012 - Counter reason.',
            '_lunara_career_context'  => 'Career Film tt0000013 - Career reason.',
        ),
        108 => array(
            '_lunara_imdb_title_id' => 'tt0000010',
            'theme_echo_movie'      => 10,
            'theme_echo_note'       => 'Self reason.',
            'counter_program_movie' => 12,
            'counter_program_note'  => 'Counter reason.',
            'career_context_movie'  => 13,
            'career_context_note'   => 'Career reason.',
        ),
        109 => array(
            '_lunara_imdb_title_id'   => 'tt0000010',
            '_lunara_theme_echo'      => 'Theme Film tt0000011 and tt0000012 - Ambiguous reason.',
            '_lunara_counter_program' => 'Counter Film tt0000012 - Counter reason.',
            '_lunara_career_context'  => 'Career Film tt0000013 - Career reason.',
        ),
        111 => array(
            '_lunara_imdb_title_id'   => 'tt0000010',
            '_lunara_theme_echo'      => 'Draft Film tt0000014 - Draft reason.',
            '_lunara_counter_program' => 'Counter Film tt0000012 - Counter reason.',
            '_lunara_career_context'  => 'Career Film tt0000013 - Career reason.',
        ),
        112 => array(
            '_lunara_imdb_title_id'   => 'tt0000010',
            '_lunara_theme_echo'      => 'Unknown Film tt9999999 - Unknown reason.',
            '_lunara_counter_program' => 'Counter Film tt0000012 - Counter reason.',
            '_lunara_career_context'  => 'Career Film tt0000013 - Career reason.',
        ),
        113 => array(
            '_lunara_imdb_title_id'   => 'tt0000010',
            '_lunara_theme_echo'      => 'Theme Film (2020) - Theme reason.',
            '_lunara_counter_program' => 'Counter Film tt0000012 - Counter reason.',
            '_lunara_career_context'  => 'Career Film tt0000013 - Career reason.',
        ),
        114 => array(
            '_lunara_imdb_title_id' => 'tt0000010',
            'theme_echo_movie'      => 11,
            'theme_echo_note'       => 'Canonical reason.',
            '_lunara_theme_echo'    => 'Theme Film tt0000011 - Different legacy reason.',
            'counter_program_movie' => 12,
            'counter_program_note'  => 'Counter reason.',
            'career_context_movie'  => 13,
            'career_context_note'   => 'Career reason.',
        ),
        115 => array(
            '_lunara_imdb_title_id' => 'tt0000010',
            'debrief_status'        => 'awaiting-approval',
            'theme_echo_movie'      => 11,
            'theme_echo_note'       => 'Theme reason.',
            'counter_program_movie' => 12,
            'counter_program_note'  => 'Counter reason.',
            'career_context_movie'  => 13,
            'career_context_note'   => 'Career reason.',
        ),
        116 => array(
            '_lunara_imdb_title_id' => 'tt0000010',
            'theme_echo_movie'      => 11,
            'theme_echo_note'       => 'Theme reason.',
            '_lunara_theme_echo'    => 'Theme Film tt0000011 and Counter Film tt0000012 - Theme reason.',
            'counter_program_movie' => 12,
            'counter_program_note'  => 'Counter reason.',
            'career_context_movie'  => 13,
            'career_context_note'   => 'Career reason.',
        ),
        117 => array(
            '_lunara_imdb_title_id' => 'tt0000010',
            'theme_echo_movie'      => 11,
            'theme_echo_note'       => 'Theme reason.',
            'counter_program_movie' => 11,
            'counter_program_note'  => 'Counter reason.',
            'career_context_movie'  => 13,
            'career_context_note'   => 'Career reason.',
        ),
        122 => array(
            '_lunara_imdb_title_id'   => 'tt0000010',
            '_lunara_theme_echo'      => 'Wrong Film (2020) tt0000011 - Theme reason.',
            '_lunara_counter_program' => 'Counter Film tt0000012 - Counter reason.',
            '_lunara_career_context'  => 'Career Film tt0000013 - Career reason.',
        ),
        123 => array(
            '_lunara_imdb_title_id'   => 'tt0000010',
            '_lunara_theme_echo'      => 'Theme Film (1999) tt0000011 - Theme reason.',
            '_lunara_counter_program' => 'Counter Film tt0000012 - Counter reason.',
            '_lunara_career_context'  => 'Career Film tt0000013 - Career reason.',
        ),
        124 => array(
            '_lunara_imdb_title_id'   => 'tt0000010',
            '_lunara_theme_echo'      => 'Divergent Film (2002) tt0000023 - Theme reason.',
            '_lunara_counter_program' => 'Counter Film tt0000012 - Counter reason.',
            '_lunara_career_context'  => 'Career Film tt0000013 - Career reason.',
        ),
    ),
    'write_calls'    => 0,
    'remote_calls'   => 0,
    'queries'        => array(),
    'permalink_host' => 'https://example.test',
    'title_suffix'   => '',
);

function __( $text ) {
    return $text;
}

function absint( $value ) {
    return abs( (int) $value );
}

function wp_strip_all_tags( $value ) {
    return strip_tags( $value );
}

function home_url( $path = '/' ) {
    return 'https://example.test' . $path;
}

function get_post_meta( $post_id, $key ) {
    return $GLOBALS['lunara_migration_test']['meta'][ $post_id ][ $key ] ?? '';
}

function get_post_type( $post_id ) {
    return $GLOBALS['lunara_migration_test']['posts'][ $post_id ]['post_type'] ?? '';
}

function get_post_status( $post_id ) {
    return $GLOBALS['lunara_migration_test']['posts'][ $post_id ]['post_status'] ?? '';
}

function get_the_title( $post_id ) {
    $title = $GLOBALS['lunara_migration_test']['posts'][ $post_id ]['title'] ?? '';
    return $title . $GLOBALS['lunara_migration_test']['title_suffix'];
}

function get_permalink( $post_id ) {
    return isset( $GLOBALS['lunara_migration_test']['posts'][ $post_id ] )
        ? $GLOBALS['lunara_migration_test']['permalink_host'] . '/' . get_post_type( $post_id ) . 's/' . absint( $post_id ) . '/'
        : '';
}

function get_post_field( $field, $post_id, $context = 'display' ) {
    unset( $context );
    if ( 'post_content' === $field ) {
        return $GLOBALS['lunara_migration_test']['posts'][ $post_id ]['content'] ?? '';
    }
    if ( 'post_title' === $field ) {
        return $GLOBALS['lunara_migration_test']['posts'][ $post_id ]['title'] ?? '';
    }
    if ( 'post_modified_gmt' === $field ) {
        return $GLOBALS['lunara_migration_test']['posts'][ $post_id ]['modified_gmt'] ?? '';
    }
    return '';
}

function get_post_stati( $args = array(), $output = 'names' ) {
    unset( $args, $output );
    return array( 'publish', 'draft', 'pending', 'private', 'future', 'trash', 'auto-draft', 'inherit', 'archive' );
}

function get_posts( $args ) {
    $GLOBALS['lunara_migration_test']['queries'][] = $args;
    $ids = array();

    foreach ( $GLOBALS['lunara_migration_test']['posts'] as $post_id => $post ) {
        if ( isset( $args['post_type'] ) && $post['post_type'] !== $args['post_type'] ) {
            continue;
        }

        $statuses = $args['post_status'] ?? 'publish';
        if ( 'any' !== $statuses && ! in_array( $post['post_status'], (array) $statuses, true ) ) {
            continue;
        }

        if ( ! empty( $args['post__in'] ) && ! in_array( $post_id, array_map( 'intval', (array) $args['post__in'] ), true ) ) {
            continue;
        }

        if ( isset( $args['meta_query'] ) && is_array( $args['meta_query'] ) ) {
            $meta_match = false;
            foreach ( $args['meta_query'] as $condition ) {
                if ( ! is_array( $condition ) || empty( $condition['key'] ) ) {
                    continue;
                }
                $actual = $GLOBALS['lunara_migration_test']['meta'][ $post_id ][ $condition['key'] ] ?? '';
                if ( (string) $actual === (string) ( $condition['value'] ?? '' ) ) {
                    $meta_match = true;
                    break;
                }
            }
            if ( ! $meta_match ) {
                continue;
            }
        }

        $ids[] = $post_id;
    }

    sort( $ids, SORT_NUMERIC );
    $limit  = (int) ( $args['posts_per_page'] ?? -1 );
    // WordPress ignores offset when posts_per_page is -1.
    $offset = $limit > -1 ? max( 0, (int) ( $args['offset'] ?? 0 ) ) : 0;
    return array_slice( $ids, $offset, $limit > -1 ? $limit : null );
}

function update_post_meta() {
    $GLOBALS['lunara_migration_test']['write_calls']++;
    throw new RuntimeException( 'The read-only migration service attempted update_post_meta().' );
}

function update_field() {
    $GLOBALS['lunara_migration_test']['write_calls']++;
    throw new RuntimeException( 'The read-only migration service attempted update_field().' );
}

function delete_post_meta() {
    $GLOBALS['lunara_migration_test']['write_calls']++;
    throw new RuntimeException( 'The read-only migration service attempted delete_post_meta().' );
}

function wp_update_post() {
    $GLOBALS['lunara_migration_test']['write_calls']++;
    throw new RuntimeException( 'The read-only migration service attempted wp_update_post().' );
}

function wp_remote_get() {
    $GLOBALS['lunara_migration_test']['remote_calls']++;
    throw new RuntimeException( 'The read-only migration service attempted remote HTTP.' );
}

final class WP_CLI {
    public static $commands = array();
    public static $lines    = array();

    public static function add_command( $name, $callable ) {
        self::$commands[ $name ] = $callable;
    }

    public static function line( $line ) {
        self::$lines[] = (string) $line;
    }

    public static function error( $message ) {
        throw new RuntimeException( (string) $message );
    }
}

function lunara_migration_assert_same( $expected, $actual, $message ) {
    if ( $expected !== $actual ) {
        throw new RuntimeException( $message . '\nExpected: ' . var_export( $expected, true ) . '\nActual: ' . var_export( $actual, true ) );
    }
}

function lunara_migration_assert_true( $condition, $message ) {
    if ( ! $condition ) {
        throw new RuntimeException( $message );
    }
}

function lunara_migration_review( $report, $review_id ) {
    foreach ( $report['reviews'] as $review ) {
        if ( (int) $review['review_id'] === (int) $review_id ) {
            return $review;
        }
    }
    throw new RuntimeException( 'Missing Review report: ' . $review_id );
}

require dirname( __DIR__ ) . '/includes/class-lunara-debrief-contract.php';
require dirname( __DIR__ ) . '/includes/class-lunara-debrief-migration.php';
require dirname( __DIR__ ) . '/includes/class-lunara-debrief-cli.php';

$parser = Lunara_Debrief_Migration::parse_legacy_value( 'Old Film tt123456 - A reason.' );
lunara_migration_assert_same( 'tt123456', $parser['imdb_title_id'], 'Migration parsing must accept six-digit IMDb IDs.' );
lunara_migration_assert_same( 'A reason.', $parser['editorial_reason'], 'Migration parsing must preserve the explicit editorial reason.' );

$multiple_parser = Lunara_Debrief_Migration::parse_legacy_value( 'One tt1234567 and Two tt7654321 - Ambiguous.' );
lunara_migration_assert_same( array( 'tt1234567', 'tt7654321' ), $multiple_parser['imdb_ids'], 'Every explicit IMDb ID must be preserved.' );
lunara_migration_assert_true( in_array( 'multiple_imdb_ids', $multiple_parser['issues'], true ), 'Multiple IMDb IDs must be an explicit ambiguity.' );

$title_only_parser = Lunara_Debrief_Migration::parse_legacy_value( 'Title Only (1999) - A reason.' );
lunara_migration_assert_same( 'title-only', $title_only_parser['confidence'], 'Title-only legacy values must never be promoted to automatic identity matches.' );

$args = array( 'post_status' => 'any', 'generated_at_utc' => '2026-07-12T00:00:00Z' );
$census = Lunara_Debrief_Migration::census( $args );
$dry_run = Lunara_Debrief_Migration::dry_run( $args );

lunara_migration_assert_same( 'lunara-debrief-migration/v1', $census['schema'], 'Report schema must be stable and versioned.' );
lunara_migration_assert_same( 'census', $census['mode'], 'Census must identify its mode.' );
lunara_migration_assert_same( 'dry-run', $dry_run['mode'], 'Migration planning must identify dry-run mode.' );
lunara_migration_assert_same( 22, $census['summary']['reviews_scanned'], 'A complete census must include trash and registered custom Review statuses.' );
lunara_migration_assert_same( 0, $census['summary']['writes_performed'], 'Census must report zero writes.' );
lunara_migration_assert_same( 6, $census['summary']['planned_field_writes'], 'Only the fully automatic Review may advertise six planned ACF writes.' );
lunara_migration_assert_same( $census['plan_hash'], $dry_run['plan_hash'], 'Census and dry-run must produce the same canonical plan hash.' );
lunara_migration_assert_same( 0, $GLOBALS['lunara_migration_test']['write_calls'], 'Census and dry-run must make zero write calls.' );
lunara_migration_assert_same( 0, $GLOBALS['lunara_migration_test']['remote_calls'], 'Census and dry-run must make zero remote calls.' );

$expected_buckets = array(
    'already_migrated',
    'auto_migratable',
    'career_legacy_conflict',
    'content_only_candidate',
    'duplicate_companion',
    'duplicate_movie_candidates',
    'legacy_title_mismatch',
    'legacy_year_mismatch',
    'missing_imdb',
    'missing_reason',
    'movie_identity_conflict',
    'movie_not_found',
    'multiple_imdb_ids',
    'no_debrief_data',
    'reason_conflict',
    'relation_legacy_conflict',
    'self_pairing',
    'status_invalid',
    'unrenderable_movie',
);
lunara_migration_assert_same( $expected_buckets, array_keys( $census['summary']['buckets'] ), 'Every safety classification must be represented deterministically.' );
foreach ( $expected_buckets as $bucket ) {
    $expected_count = in_array( $bucket, array( 'multiple_imdb_ids', 'no_debrief_data' ), true )
        ? ( 'multiple_imdb_ids' === $bucket ? 2 : 3 )
        : 1;
    lunara_migration_assert_same( $expected_count, $census['summary']['buckets'][ $bucket ], 'Unexpected count for bucket: ' . $bucket );
}

$automatic = lunara_migration_review( $census, 100 );
lunara_migration_assert_same( 'auto_migratable', $automatic['classification'], 'A complete explicit legacy Trinity must be automatically plannable.' );
lunara_migration_assert_same( 6, count( $automatic['planned_writes'] ), 'Automatic migration must plan three movie fields and three reasons.' );
lunara_migration_assert_same( 2, count( $automatic['roles']['career_context']['legacy_sources'] ), 'Both Career Context legacy keys must remain visible in the report.' );
lunara_migration_assert_same(
    '_lunara_career_context',
    $automatic['roles']['career_context']['authoritative_legacy_key'],
    'Current Career Context must precede Craft Mirror.'
);

$duplicate = lunara_migration_review( $census, 102 );
lunara_migration_assert_same( array( 20, 21 ), $duplicate['roles']['theme_echo']['legacy_resolution']['candidate_ids'], 'All duplicate Movie candidates must be reported.' );
lunara_migration_assert_same( array(), $duplicate['planned_writes'], 'A blocked Review must advertise zero partial writes.' );

foreach ( array( 103, 104, 105, 108, 109, 111, 112, 113, 114 ) as $blocked_review_id ) {
    lunara_migration_assert_same( array(), lunara_migration_review( $census, $blocked_review_id )['planned_writes'], 'Every blocked Review must have an atomic zero-write plan.' );
}

$relation_conflict = lunara_migration_review( $census, 103 );
lunara_migration_assert_true( in_array( 'relation_legacy_conflict', $relation_conflict['roles']['theme_echo']['issue_codes'], true ), 'An existing relationship must not hide conflicting legacy identity evidence.' );

$invalid_status = lunara_migration_review( $census, 115 );
lunara_migration_assert_same( 'status_invalid', $invalid_status['classification'], 'Every contract invalid_status result must map to status_invalid.' );
lunara_migration_assert_true( in_array( 'invalid_status', $invalid_status['contract']['issue_codes'], true ), 'The original contract invalid_status code must remain visible.' );
lunara_migration_assert_same( array(), $invalid_status['planned_writes'], 'An invalid Debrief status must produce an atomic zero-write plan.' );

$related_ambiguity = lunara_migration_review( $census, 116 );
lunara_migration_assert_same( 'multiple_imdb_ids', $related_ambiguity['classification'], 'An existing relationship must not hide ambiguous legacy identity evidence.' );
lunara_migration_assert_same( 11, $related_ambiguity['roles']['theme_echo']['current']['movie_id'], 'The existing relationship must remain visible alongside legacy ambiguity.' );
lunara_migration_assert_true( in_array( 'multiple_imdb_ids', $related_ambiguity['roles']['theme_echo']['issue_codes'], true ), 'The role report must preserve relation-plus-legacy ambiguity.' );
lunara_migration_assert_same( array(), $related_ambiguity['planned_writes'], 'Relation-plus-legacy ambiguity must block the atomic plan.' );

$duplicate_companion = lunara_migration_review( $census, 117 );
lunara_migration_assert_same( 'duplicate_companion', $duplicate_companion['classification'], 'A repeated companion relationship must map to duplicate_companion.' );
lunara_migration_assert_true( in_array( 'duplicate_companion_film', $duplicate_companion['contract']['issue_codes'], true ), 'The underlying duplicate companion contract code must remain visible.' );
lunara_migration_assert_same( array(), $duplicate_companion['planned_writes'], 'Duplicate companions must produce an atomic zero-write plan.' );

$title_mismatch = lunara_migration_review( $census, 122 );
lunara_migration_assert_same( 'legacy_title_mismatch', $title_mismatch['classification'], 'A legacy title that conflicts with the resolved Movie must fail closed.' );
lunara_migration_assert_true( in_array( 'legacy_title_mismatch', $title_mismatch['roles']['theme_echo']['issue_codes'], true ), 'Title mismatch evidence must remain visible on the role.' );
lunara_migration_assert_same( array(), $title_mismatch['planned_writes'], 'Title mismatch must block every planned write for the Review.' );

$year_mismatch = lunara_migration_review( $census, 123 );
lunara_migration_assert_same( 'legacy_year_mismatch', $year_mismatch['classification'], 'A legacy year that conflicts with the resolved Movie must fail closed.' );
lunara_migration_assert_true( in_array( 'legacy_year_mismatch', $year_mismatch['roles']['theme_echo']['issue_codes'], true ), 'Year mismatch evidence must remain visible on the role.' );
lunara_migration_assert_same( array(), $year_mismatch['planned_writes'], 'Year mismatch must block every planned write for the Review.' );

$identity_conflict = lunara_migration_review( $census, 124 );
lunara_migration_assert_same( 'movie_identity_conflict', $identity_conflict['classification'], 'Divergent Movie identity fields must fail closed.' );
lunara_migration_assert_true( in_array( 'movie_identity_conflict', $identity_conflict['roles']['theme_echo']['issue_codes'], true ), 'Dual Movie identity disagreement must remain visible.' );
lunara_migration_assert_true( in_array( 'movie_identity_mismatch', $identity_conflict['roles']['theme_echo']['issue_codes'], true ), 'The effective Movie identity must equal the requested lookup identity.' );
lunara_migration_assert_same( 'tt0000022', $identity_conflict['roles']['theme_echo']['legacy_resolution']['candidates'][0]['effective_imdb_id'], 'Canonical Movie identity must be explicit in the census.' );
lunara_migration_assert_same( 'tt0000023', $identity_conflict['roles']['theme_echo']['legacy_resolution']['candidates'][0]['requested_imdb_id'], 'Requested lookup identity must be explicit in the census.' );
lunara_migration_assert_same( array(), $identity_conflict['planned_writes'], 'Divergent Movie identity fields must block every planned write.' );

$content_only = lunara_migration_review( $census, 106 );
lunara_migration_assert_same( array( 'lunara_debrief' ), $content_only['content_markers']['shortcodes'], 'Shortcode markers must be reported without becoming data authority.' );
lunara_migration_assert_same( array( 'lunara/pair-it-with' ), $content_only['content_markers']['blocks'], 'Bridge blocks must be reported without content mutation.' );

lunara_migration_assert_same( 'trash', lunara_migration_review( $census, 118 )['post_status'], 'A complete census must include trashed Reviews.' );
lunara_migration_assert_same( 'archive', lunara_migration_review( $census, 119 )['post_status'], 'A complete census must include registered custom Review statuses.' );
$reported_review_ids = array_map( static function ( $review ) {
    return $review['review_id'];
}, $census['reviews'] );
lunara_migration_assert_true( ! in_array( 120, $reported_review_ids, true ), 'Internal auto-draft records must not enter a complete Review census.' );
lunara_migration_assert_true( ! in_array( 121, $reported_review_ids, true ), 'Inherited revisions must not enter a complete Review census.' );

$review_queries = array_values( array_filter( $GLOBALS['lunara_migration_test']['queries'], static function ( $query ) {
    return isset( $query['post_type'] ) && 'review' === $query['post_type'];
} ) );
$movie_queries = array_values( array_filter( $GLOBALS['lunara_migration_test']['queries'], static function ( $query ) {
    return isset( $query['post_type'] ) && 'movie' === $query['post_type'];
} ) );
lunara_migration_assert_true( ! empty( $review_queries ), 'A complete census must issue a bounded Review-ID query.' );
lunara_migration_assert_same(
    array( 'archive', 'draft', 'future', 'pending', 'private', 'publish', 'trash' ),
    $review_queries[0]['post_status'],
    'Any-status Review census must use an explicit deterministic editorial status list.'
);
lunara_migration_assert_same( -1, $review_queries[0]['posts_per_page'], 'Review discovery must fetch all ordered IDs before PHP-side slicing.' );
lunara_migration_assert_true( ! array_key_exists( 'offset', $review_queries[0] ), 'Unlimited Review discovery must not rely on WP_Query offset.' );
lunara_migration_assert_same( false, $review_queries[0]['cache_results'], 'Review query cache behavior must be explicit.' );
lunara_migration_assert_same( false, $review_queries[0]['update_post_meta_cache'], 'Review query meta-cache behavior must be explicit.' );
lunara_migration_assert_true( in_array( 'archive', $movie_queries[0]['post_status'], true ), 'Movie discovery must include registered custom statuses.' );
lunara_migration_assert_true( in_array( 'auto-draft', $movie_queries[0]['post_status'], true ), 'All-candidate Movie discovery must retain internal statuses.' );
lunara_migration_assert_same( false, $movie_queries[0]['cache_results'], 'Movie query cache behavior must be explicit.' );

$offset_census = Lunara_Debrief_Migration::census( array(
    'post_status'      => 'any',
    'offset'           => 2,
    'generated_at_utc' => '2026-07-12T00:00:00Z',
) );
lunara_migration_assert_same( 20, $offset_census['summary']['reviews_scanned'], 'Offset without limit must slice the complete ordered Review ID set.' );
lunara_migration_assert_same( 102, $offset_census['reviews'][0]['review_id'], 'Unlimited offset must begin at the requested ordered Review.' );

$offset_limited = Lunara_Debrief_Migration::census( array(
    'post_status'      => 'any',
    'offset'           => 2,
    'limit'            => 2,
    'generated_at_utc' => '2026-07-12T00:00:00Z',
) );
lunara_migration_assert_same( array( 102, 103 ), array_column( $offset_limited['reviews'], 'review_id' ), 'Offset and limit must be applied together in PHP.' );

$encoded_once = Lunara_Debrief_Migration::stable_json( $census );
$encoded_twice = Lunara_Debrief_Migration::stable_json( $census );
lunara_migration_assert_same( $encoded_once, $encoded_twice, 'Stable report JSON must be byte-identical for the same report.' );

$baseline_source_hash = $automatic['source_hash'];
$baseline_permalink   = $automatic['roles']['theme_echo']['legacy_resolution']['candidates'][0]['permalink'];
$GLOBALS['lunara_migration_test']['permalink_host'] = 'https://display-change.example';
$GLOBALS['lunara_migration_test']['title_suffix']   = ' [filtered]';
$presentation_changed = Lunara_Debrief_Migration::census( $args );
$presentation_automatic = lunara_migration_review( $presentation_changed, 100 );
lunara_migration_assert_true( $baseline_permalink !== $presentation_automatic['roles']['theme_echo']['legacy_resolution']['candidates'][0]['permalink'], 'The hash regression must exercise a real display permalink change.' );
lunara_migration_assert_same( $baseline_source_hash, $presentation_automatic['source_hash'], 'Display permalink and filtered title changes must not alter source_hash.' );
lunara_migration_assert_same( $census['plan_hash'], $presentation_changed['plan_hash'], 'Display permalink and filtered title changes must not alter plan_hash.' );
$GLOBALS['lunara_migration_test']['permalink_host'] = 'https://example.test';
$GLOBALS['lunara_migration_test']['title_suffix']   = '';

$malformed_utf8 = "Legacy byte \xB1";
$malformed_json = Lunara_Debrief_Migration::stable_json( array( 'legacy' => $malformed_utf8 ) );
lunara_migration_assert_true( '' !== $malformed_json, 'Malformed UTF-8 must never produce blank JSON.' );
lunara_migration_assert_true( is_array( json_decode( $malformed_json, true ) ), 'Malformed UTF-8 must be deterministically substituted into valid JSON.' );
lunara_migration_assert_true( hash( 'sha256', '' ) !== hash( 'sha256', $malformed_json ), 'Malformed UTF-8 must never collapse to the empty-string hash.' );

$original_theme_legacy = $GLOBALS['lunara_migration_test']['meta'][100]['_lunara_theme_echo'];
$GLOBALS['lunara_migration_test']['meta'][100]['_lunara_theme_echo'] = $original_theme_legacy . " \xB1";
$malformed_report = Lunara_Debrief_Migration::census( array(
    'review_ids'       => array( '100' ),
    'generated_at_utc' => '2026-07-12T00:00:00Z',
) );
lunara_migration_assert_true( '' !== Lunara_Debrief_Migration::stable_json( $malformed_report ), 'Malformed stored legacy evidence must still produce a nonblank census report.' );
lunara_migration_assert_true( hash( 'sha256', '' ) !== $malformed_report['plan_hash'], 'Malformed stored legacy evidence must never produce the empty-string plan hash.' );
$GLOBALS['lunara_migration_test']['meta'][100]['_lunara_theme_echo'] = $original_theme_legacy;

lunara_migration_assert_same( 'Lunara_Debrief_CLI', WP_CLI::$commands['lunara debrief'] ?? '', 'The adapter must self-register one lunara debrief command.' );
$cli = new Lunara_Debrief_CLI();

$review_query_count_before_invalid = count( array_filter( $GLOBALS['lunara_migration_test']['queries'], static function ( $query ) {
    return isset( $query['post_type'] ) && 'review' === $query['post_type'];
} ) );
$invalid_service_filters = array(
    array(),
    array( '100', 'invalid' ),
    array( '100', '' ),
    array( 10 ),
    array( 999 ),
);
foreach ( $invalid_service_filters as $invalid_review_ids ) {
    $rejected = false;
    try {
        Lunara_Debrief_Migration::census( array( 'review_ids' => $invalid_review_ids ) );
    } catch ( InvalidArgumentException $error ) {
        $rejected = true;
    }
    lunara_migration_assert_true( $rejected, 'Invalid service Review filters must fail closed.' );
}
$review_query_count_after_invalid = count( array_filter( $GLOBALS['lunara_migration_test']['queries'], static function ( $query ) {
    return isset( $query['post_type'] ) && 'review' === $query['post_type'];
} ) );
lunara_migration_assert_same( $review_query_count_before_invalid, $review_query_count_after_invalid, 'Invalid Review filters must never broaden into a full-site query.' );

$status_mismatch_filters = array(
    array( 'review_ids' => array( '118' ), 'post_status' => 'publish' ),
    array( 'review_ids' => array( '120' ), 'post_status' => 'any' ),
);
foreach ( $status_mismatch_filters as $status_mismatch_filter ) {
    $rejected = false;
    try {
        Lunara_Debrief_Migration::census( $status_mismatch_filter );
    } catch ( InvalidArgumentException $error ) {
        $rejected = true;
    }
    lunara_migration_assert_true( $rejected, 'Explicit Review IDs and status filters must be enforced as an intersection.' );
}

$explicit_trash = Lunara_Debrief_Migration::census( array( 'review_ids' => array( '118' ), 'post_status' => 'any' ) );
lunara_migration_assert_same( array( 118 ), array_column( $explicit_trash['reviews'], 'review_id' ), 'The any-status filter must still admit an explicit trashed Review.' );

$invalid_cli_filters = array( '', '100,invalid', '100,,101', '10', '999' );
foreach ( $invalid_cli_filters as $invalid_cli_filter ) {
    $rejected = false;
    try {
        $cli->census( array(), array( 'review-id' => $invalid_cli_filter ) );
    } catch ( RuntimeException $error ) {
        $rejected = true;
    }
    lunara_migration_assert_true( $rejected, 'Invalid CLI Review filters must fail closed.' );
}
$review_query_count_after_invalid_cli = count( array_filter( $GLOBALS['lunara_migration_test']['queries'], static function ( $query ) {
    return isset( $query['post_type'] ) && 'review' === $query['post_type'];
} ) );
lunara_migration_assert_same( $review_query_count_before_invalid, $review_query_count_after_invalid_cli, 'Invalid CLI Review filters must never broaden into a full-site query.' );

$invalid_cli_scopes = array(
    array( 'review-id' => '118', 'post-status' => 'publish' ),
    array( 'review-id' => '120', 'post-status' => 'any' ),
    array( 'limit' => 'garbage' ),
    array( 'limit' => '0' ),
    array( 'limit' => '-1' ),
    array( 'offset' => 'garbage' ),
    array( 'offset' => '-1' ),
);
foreach ( $invalid_cli_scopes as $invalid_cli_scope ) {
    $rejected = false;
    try {
        $cli->census( array(), $invalid_cli_scope );
    } catch ( RuntimeException $error ) {
        $rejected = true;
    }
    lunara_migration_assert_true( $rejected, 'Invalid combined or numeric CLI filters must fail closed.' );
}

WP_CLI::$lines = array();
$cli->census( array(), array( 'review-id' => '118', 'post-status' => 'any', 'limit' => '1', 'offset' => '0', 'format' => 'summary' ) );
lunara_migration_assert_true( false !== strpos( implode( "\n", WP_CLI::$lines ), 'Reviews scanned: 1' ), 'Valid combined CLI filters must remain usable.' );

WP_CLI::$lines = array();
$cli->census( array(), array( 'review-id' => '100,101', 'format' => 'summary' ) );
lunara_migration_assert_true( false !== strpos( implode( "\n", WP_CLI::$lines ), 'Reviews scanned: 2' ), 'CLI summary output must report bounded Review counts.' );
lunara_migration_assert_true( false !== strpos( implode( "\n", WP_CLI::$lines ), 'No data changed.' ), 'CLI summary must state its read-only guarantee.' );

WP_CLI::$lines = array();
$cli->migrate( array(), array( 'dry-run' => true, 'review-id' => '100', 'format' => 'json' ) );
$cli_json = json_decode( implode( "\n", WP_CLI::$lines ), true );
lunara_migration_assert_same( 'dry-run', $cli_json['mode'] ?? '', 'CLI migrate JSON must remain dry-run only.' );
lunara_migration_assert_same( 0, $cli_json['summary']['writes_performed'] ?? -1, 'CLI dry-run must report zero writes.' );

$missing_dry_run_rejected = false;
try {
    $cli->migrate( array(), array() );
} catch ( RuntimeException $error ) {
    $missing_dry_run_rejected = false !== strpos( $error->getMessage(), '--dry-run' );
}
lunara_migration_assert_true( $missing_dry_run_rejected, 'CLI migrate must require the explicit --dry-run flag.' );

$apply_rejected = false;
try {
    $cli->migrate( array(), array( 'dry-run' => true, 'apply' => true ) );
} catch ( RuntimeException $error ) {
    $apply_rejected = false !== strpos( $error->getMessage(), 'not available' );
}
lunara_migration_assert_true( $apply_rejected, 'Release C must expose no apply path.' );

$migration_source = file_get_contents( dirname( __DIR__ ) . '/includes/class-lunara-debrief-migration.php' );
$cli_source       = file_get_contents( dirname( __DIR__ ) . '/includes/class-lunara-debrief-cli.php' );
lunara_migration_assert_true( false === strpos( $migration_source, 'posts_per_page\'         => 1' ), 'Candidate lookup must never be capped at one Movie.' );
lunara_migration_assert_true( 1 === preg_match( "/'posts_per_page'\\s*=>\\s*-1/", $migration_source ), 'Candidate lookup must request every matching Movie.' );
lunara_migration_assert_true( 0 === preg_match( '/\b(?:update_post_meta|update_field|delete_post_meta|wp_update_post|wp_remote_get)\s*\(/', $migration_source ), 'The migration service must contain no write or remote call.' );
lunara_migration_assert_true( false !== strpos( $cli_source, '* [--dry-run]' ), 'WP-CLI must advertise dry-run as a valid valueless flag.' );
lunara_migration_assert_true( false === strpos( $cli_source, '* --dry-run' ), 'WP-CLI rejects a required valueless flag in the command synopsis.' );

echo "Debrief migration regression checks passed.\n";
