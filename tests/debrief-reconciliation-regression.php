<?php
/**
 * Dependency-free regression checks for Debrief reconciliation packs.
 *
 * Run with: php tests/debrief-reconciliation-regression.php
 */

define( 'ABSPATH', __DIR__ . '/' );

function __( $text ) {
    return $text;
}

function absint( $value ) {
    return abs( (int) $value );
}

function lunara_reconciliation_assert_same( $expected, $actual, $message ) {
    if ( $expected !== $actual ) {
        throw new RuntimeException(
            $message . "\nExpected: " . var_export( $expected, true ) . "\nActual: " . var_export( $actual, true )
        );
    }
}

function lunara_reconciliation_assert_true( $condition, $message ) {
    if ( ! $condition ) {
        throw new RuntimeException( $message );
    }
}

function lunara_reconciliation_markers( $overrides = array() ) {
    return array_merge(
        array(
            'shortcodes'      => array(),
            'blocks'          => array(),
            'structured_meta' => false,
            'pairing_labels'  => array(),
            'has_any'         => false,
        ),
        $overrides
    );
}

function lunara_reconciliation_role( $role, $issues = array(), $legacy_sources = array() ) {
    return array(
        'role'                     => $role,
        'current'                  => array(
            'movie_id' => 0,
            'reason'   => '',
        ),
        'legacy_sources'           => $legacy_sources,
        'authoritative_legacy_key' => isset( $legacy_sources[0]['meta_key'] ) ? $legacy_sources[0]['meta_key'] : '',
        'legacy_resolution'        => array(
            'resolution'    => in_array( 'movie_not_found', $issues, true ) ? 'movie-not-found' : 'empty',
            'candidate_ids' => array(),
            'issue_codes'   => $issues,
        ),
        'issue_codes'              => $issues,
    );
}

function lunara_reconciliation_missing_source( $meta_key, $value, $imdb_id, $title, $year, $reason = '' ) {
    return array(
        'meta_key' => $meta_key,
        'value'    => $value,
        'parsed'   => array(
            'imdb_title_id'    => $imdb_id,
            'title'            => $title,
            'year'             => $year,
            'editorial_reason' => $reason,
        ),
    );
}

require dirname( __DIR__ ) . '/includes/class-lunara-debrief-contract.php';
require dirname( __DIR__ ) . '/includes/class-lunara-debrief-migration.php';
require dirname( __DIR__ ) . '/includes/class-lunara-debrief-reconciliation.php';

$roles = array_keys( Lunara_Debrief_Contract::roles() );
$empty_roles = array();
foreach ( $roles as $role ) {
    $empty_roles[ $role ] = lunara_reconciliation_role( $role );
}

$auto_write = array(
    'operation'  => 'update_field',
    'field_name' => 'theme_echo_movie',
    'field_key'  => 'field_lunara_review_theme_echo_movie',
    'before'     => 0,
    'after'      => 500,
    'source'     => '_lunara_theme_echo',
);

$missing_roles = $empty_roles;
$missing_roles['theme_echo'] = lunara_reconciliation_role(
    'theme_echo',
    array( 'movie_not_found' ),
    array(
        lunara_reconciliation_missing_source(
            '_lunara_theme_echo',
            'Companion Alpha (2001) | tt0000201 - Theme reason.',
            'tt0000201',
            'Companion Alpha',
            '2001',
            'Theme reason.'
        ),
    )
);
$missing_roles['counter_program'] = lunara_reconciliation_role(
    'counter_program',
    array( 'movie_not_found' ),
    array(
        lunara_reconciliation_missing_source(
            '_lunara_counter_program',
            'Companion Alpha (2001) | tt0000201 - Counter reason.',
            'tt0000201',
            'Companion Alpha',
            '2001',
            'Counter reason.'
        ),
    )
);

$conflict_roles = $empty_roles;
$conflict_roles['career_context'] = lunara_reconciliation_role(
    'career_context',
    array( 'career_legacy_conflict' ),
    array(
        lunara_reconciliation_missing_source(
            '_lunara_career_context',
            'Current Career | tt0000301 - Current reason.',
            'tt0000301',
            'Current Career',
            '',
            'Current reason.'
        ),
        lunara_reconciliation_missing_source(
            '_lunara_craft_mirror',
            'Old Craft | tt0000302 - Old reason.',
            'tt0000302',
            'Old Craft',
            '',
            'Old reason.'
        ),
    )
);

$census = array(
    'schema' => Lunara_Debrief_Migration::SCHEMA_VERSION,
    'mode'   => 'census',
    'run'    => array(
        'run_id'           => 'debrief-fixture',
        'generated_at_utc' => '2026-07-12T00:00:00Z',
        'arguments'        => array( 'post_status' => 'any' ),
    ),
    'summary' => array(
        'reviews_scanned'      => 5,
        'buckets'              => array(),
        'issue_buckets'        => array(),
        'planned_field_writes' => 1,
        'writes_performed'     => 0,
    ),
    'reviews' => array(
        array(
            'review_id'       => 104,
            'post_status'     => 'draft',
            'post_title'      => 'No Debrief',
            'source_hash'     => hash( 'sha256', '104' ),
            'classification'  => 'no_debrief_data',
            'content_markers' => lunara_reconciliation_markers(),
            'roles'           => $empty_roles,
            'issue_codes'     => array(),
            'planned_writes'  => array(),
        ),
        array(
            'review_id'       => 101,
            'post_status'     => 'publish',
            'post_title'      => 'Missing Movies',
            'source_hash'     => hash( 'sha256', '101' ),
            'classification'  => 'movie_not_found',
            'reviewed_film'   => array(
                'raw_imdb'        => 'tt0000101',
                'normalized_imdb' => 'tt0000101',
                'resolution'      => 'movie-not-found',
                'candidate_ids'   => array(),
                'issue_codes'     => array( 'movie_not_found' ),
            ),
            'content_markers' => lunara_reconciliation_markers(),
            'roles'           => $missing_roles,
            'issue_codes'     => array( 'movie_not_found' ),
            'planned_writes'  => array(),
        ),
        array(
            'review_id'       => 100,
            'post_status'     => 'publish',
            'post_title'      => 'Automatic',
            'source_hash'     => hash( 'sha256', '100' ),
            'classification'  => 'auto_migratable',
            'content_markers' => lunara_reconciliation_markers(),
            'roles'           => $empty_roles,
            'issue_codes'     => array(),
            'planned_writes'  => array( $auto_write ),
        ),
        array(
            'review_id'       => 103,
            'post_status'     => 'publish',
            'post_title'      => 'Content Only',
            'source_hash'     => hash( 'sha256', '103' ),
            'classification'  => 'content_only_candidate',
            'content_markers' => lunara_reconciliation_markers(
                array(
                    'blocks'  => array( 'lunara/debrief' ),
                    'has_any' => true,
                )
            ),
            'roles'           => $empty_roles,
            'issue_codes'     => array(),
            'planned_writes'  => array(),
        ),
        array(
            'review_id'       => 102,
            'post_status'     => 'publish',
            'post_title'      => 'Career Conflict',
            'source_hash'     => hash( 'sha256', '102' ),
            'classification'  => 'career_legacy_conflict',
            'reviewed_film'   => array(
                'normalized_imdb' => 'tt0000102',
                'resolution'      => 'resolved',
                'candidate_ids'   => array( 12 ),
                'issue_codes'     => array(),
            ),
            'content_markers' => lunara_reconciliation_markers(
                array(
                    'shortcodes' => array( 'lunara_debrief' ),
                    'has_any'    => true,
                )
            ),
            'roles'           => $conflict_roles,
            'issue_codes'     => array( 'career_legacy_conflict' ),
            'planned_writes'  => array(),
        ),
    ),
    'plan_hash' => hash( 'sha256', 'fixture-plan' ),
);

$pack = Lunara_Debrief_Reconciliation::from_census( $census );

lunara_reconciliation_assert_same( Lunara_Debrief_Reconciliation::SCHEMA_VERSION, $pack['schema'], 'Pack schema must be explicit.' );
lunara_reconciliation_assert_same( 'reconciliation-pack', $pack['mode'], 'Pack mode must be explicit.' );
lunara_reconciliation_assert_same( 5, $pack['summary']['reviews_scanned'], 'All census Reviews must remain represented.' );
lunara_reconciliation_assert_same( 2, $pack['summary']['unique_missing_movies'], 'Repeated missing references must group by IMDb identity.' );
lunara_reconciliation_assert_same( 3, $pack['summary']['missing_reference_occurrences'], 'One Review may contribute multiple missing references.' );
lunara_reconciliation_assert_same( 1, $pack['summary']['missing_source_occurrences'], 'Source gaps need their own count.' );
lunara_reconciliation_assert_same( 2, $pack['summary']['missing_companion_occurrences'], 'Companion gaps need their own count.' );
lunara_reconciliation_assert_same( 1, $pack['summary']['auto_migratable_reviews'], 'The automatic queue must remain separate.' );
lunara_reconciliation_assert_same( 1, $pack['summary']['conflict_reviews'], 'The manual conflict queue must remain separate.' );
lunara_reconciliation_assert_same( 2, $pack['summary']['content_retirement_reviews'], 'Shortcode and block markers must both enter retirement review.' );
lunara_reconciliation_assert_same( 2, $pack['summary']['no_data_reviews'], 'Content-only and truly empty Reviews must remain visible.' );
lunara_reconciliation_assert_same( 0, $pack['summary']['writes_performed'], 'Reconciliation must report zero writes.' );

lunara_reconciliation_assert_same(
    array( 'tt0000101', 'tt0000201' ),
    array_column( $pack['queues']['missing_movies'], 'imdb_title_id' ),
    'Missing Movie rows must sort by normalized IMDb ID.'
);
lunara_reconciliation_assert_same( 2, $pack['queues']['missing_movies'][1]['occurrence_count'], 'Repeated companion identity must stay grouped.' );
lunara_reconciliation_assert_same(
    array( 'theme_echo', 'counter_program' ),
    array_column( $pack['queues']['missing_movies'][1]['contexts'], 'role' ),
    'Contexts must preserve fixed editorial role order.'
);
lunara_reconciliation_assert_same( $auto_write, $pack['queues']['auto_migratable'][0]['planned_writes'][0], 'Planned writes must remain exact descriptors.' );
lunara_reconciliation_assert_same( 2, count( $pack['queues']['conflicts'][0]['roles'][0]['legacy_sources'] ), 'Both conflicting Career legacy values must remain visible.' );

$timestamp_changed = $census;
$timestamp_changed['run']['generated_at_utc'] = '2026-07-13T00:00:00Z';
$second_pack = Lunara_Debrief_Reconciliation::from_census( $timestamp_changed );
lunara_reconciliation_assert_same( $pack['pack_hash'], $second_pack['pack_hash'], 'Generation time must not alter the reconciliation hash.' );
lunara_reconciliation_assert_same( $census['plan_hash'], $pack['source_plan_hash'], 'The verified census plan hash must remain attached.' );

$queue_changed = $census;
$queue_changed['reviews'][0]['post_title'] = 'Changed queue title';
$changed_pack = Lunara_Debrief_Reconciliation::from_census( $queue_changed );
lunara_reconciliation_assert_true( $pack['pack_hash'] !== $changed_pack['pack_hash'], 'Any queue payload change must alter the reconciliation hash.' );

$invalid_rejected = false;
try {
    Lunara_Debrief_Reconciliation::from_census( array( 'reviews' => array() ) );
} catch ( InvalidArgumentException $error ) {
    $invalid_rejected = true;
}
lunara_reconciliation_assert_true( $invalid_rejected, 'Incomplete census input must fail closed.' );

$source = file_get_contents( dirname( __DIR__ ) . '/includes/class-lunara-debrief-reconciliation.php' );
lunara_reconciliation_assert_true(
    0 === preg_match( '/\b(?:update_post_meta|add_post_meta|delete_post_meta|update_field|delete_field|wp_insert_post|wp_update_post|wp_set_object_terms|update_option|delete_option|set_transient|wp_remote_[a-z_]+)\s*\(/', $source ),
    'Reconciliation service must contain no application-data write or remote call.'
);
lunara_reconciliation_assert_true( false === strpos( $source, 'file_put_contents' ), 'Reconciliation output must stay on stdout through the CLI adapter.' );

$bootstrap = file_get_contents( dirname( __DIR__ ) . '/lunara-core.php' );
lunara_reconciliation_assert_true(
    1 === preg_match(
        "/if\s*\(\s*defined\(\s*'WP_CLI'\s*\)\s*&&\s*WP_CLI\s*\)\s*\{[^}]*class-lunara-debrief-reconciliation\.php[^}]*class-lunara-debrief-suggestions\.php[^}]*class-lunara-debrief-cli\.php/s",
        $bootstrap
    ),
    'Reconciliation and suggestion services must remain inside the WP-CLI-only bootstrap gate.'
);
$cli_source = file_get_contents( dirname( __DIR__ ) . '/includes/class-lunara-debrief-cli.php' );
lunara_reconciliation_assert_true( false !== strpos( $cli_source, 'public function reconcile' ), 'WP-CLI adapter must expose the reconcile command.' );
lunara_reconciliation_assert_true( false !== strpos( $cli_source, 'Lunara_Debrief_Reconciliation::build_pack' ), 'The reconcile command must use the canonical pack service.' );

echo "Debrief reconciliation regression checks passed.\n";
