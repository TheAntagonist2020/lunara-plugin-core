<?php
/**
 * Dependency-free regression checks for private Debrief suggestions.
 *
 * Run with: php tests/debrief-suggestions-regression.php
 */

define( 'ABSPATH', __DIR__ . '/' );

$GLOBALS['lunara_suggestion_test'] = array(
    'posts' => array(
        10 => array( 'post_type' => 'movie', 'post_status' => 'publish', 'title' => 'Source Film' ),
        11 => array( 'post_type' => 'movie', 'post_status' => 'publish', 'title' => 'Director Film' ),
        12 => array( 'post_type' => 'movie', 'post_status' => 'publish', 'title' => 'Cast Film' ),
        13 => array( 'post_type' => 'movie', 'post_status' => 'publish', 'title' => 'Studio Only Film' ),
        14 => array( 'post_type' => 'movie', 'post_status' => 'draft', 'title' => 'Draft Director Film' ),
        15 => array( 'post_type' => 'movie', 'post_status' => 'publish', 'title' => 'No Permalink Film', 'permalink' => '' ),
        16 => array( 'post_type' => 'movie', 'post_status' => 'publish', 'title' => 'Already Selected Film' ),
        17 => array( 'post_type' => 'movie', 'post_status' => 'publish', 'title' => 'Director and Cast Film' ),
        18 => array( 'post_type' => 'movie', 'post_status' => 'publish', 'title' => 'Sparse Source Film' ),
        19 => array( 'post_type' => 'movie', 'post_status' => 'publish', 'title' => 'Duplicate Source A' ),
        20 => array( 'post_type' => 'movie', 'post_status' => 'publish', 'title' => 'Duplicate Source B' ),
        21 => array( 'post_type' => 'movie', 'post_status' => 'publish', 'title' => 'Conflicted Candidate' ),
        97 => array( 'post_type' => 'review', 'post_status' => 'draft', 'title' => 'Ambiguous Review' ),
        98 => array( 'post_type' => 'review', 'post_status' => 'draft', 'title' => 'Sparse Review' ),
        99 => array( 'post_type' => 'review', 'post_status' => 'draft', 'title' => 'Source Review' ),
        100 => array( 'post_type' => 'person', 'post_status' => 'publish', 'title' => 'Director One' ),
        200 => array( 'post_type' => 'person', 'post_status' => 'publish', 'title' => 'Performer One' ),
    ),
    'meta' => array(
        10 => array(
            'imdb_title_id' => 'tt0000010',
            'release_year'  => '2024',
            'directors'     => array( 100 ),
            'principal_cast' => array( 200 ),
        ),
        11 => array(
            'imdb_title_id' => 'tt0000011',
            'release_year'  => '2020',
            'directors'     => array( 100 ),
        ),
        12 => array(
            'imdb_title_id' => 'tt0000012',
            'release_year'  => '2019',
            'principal_cast' => array( 200 ),
        ),
        13 => array( 'imdb_title_id' => 'tt0000013', 'release_year' => '2018' ),
        14 => array( 'imdb_title_id' => 'tt0000014', 'release_year' => '2017', 'directors' => array( 100 ) ),
        15 => array( 'imdb_title_id' => 'tt0000015', 'release_year' => '2016', 'directors' => array( 100 ) ),
        16 => array( 'imdb_title_id' => 'tt0000016', 'release_year' => '2015', 'directors' => array( 100 ) ),
        17 => array(
            'imdb_title_id' => 'tt0000017',
            'release_year'  => '2014',
            'directors'     => array( 100 ),
            'principal_cast' => array( 200 ),
        ),
        18 => array( 'imdb_title_id' => 'tt0000018', 'release_year' => '2013' ),
        19 => array( 'imdb_title_id' => 'tt0000019', 'release_year' => '2012', 'directors' => array( 100 ) ),
        20 => array( 'imdb_title_id' => 'tt0000019', 'release_year' => '2011', 'directors' => array( 100 ) ),
        21 => array(
            'imdb_title_id'    => 'tt0000021',
            '_lunara_entity_id' => 'tt0000022',
            'release_year'     => '2010',
            'directors'        => array( 100 ),
        ),
        97 => array( '_lunara_imdb_title_id' => 'tt0000019' ),
        98 => array( '_lunara_imdb_title_id' => 'tt0000018' ),
        99 => array(
            '_lunara_imdb_title_id' => 'tt0000010',
            'theme_echo_movie'      => 16,
            'theme_echo_note'       => 'Already selected.',
        ),
    ),
    'studios' => array(
        10 => array( 300 ),
        11 => array( 300 ),
        12 => array( 300 ),
        13 => array( 300 ),
        15 => array( 300 ),
        16 => array( 300 ),
        17 => array( 300 ),
    ),
    'terms' => array(
        300 => 'Studio One',
    ),
    'queries' => array(),
);

for ( $movie_id = 1000; $movie_id < 1205; ++$movie_id ) {
    $GLOBALS['lunara_suggestion_test']['posts'][ $movie_id ] = array(
        'post_type'   => 'movie',
        'post_status' => 'publish',
        'title'       => 'Pool Film ' . $movie_id,
    );
    $GLOBALS['lunara_suggestion_test']['meta'][ $movie_id ] = array(
        'imdb_title_id' => 'tt' . str_pad( (string) $movie_id, 7, '0', STR_PAD_LEFT ),
        'release_year'  => '2000',
        'directors'     => array( 100 ),
    );
}

function __( $text ) {
    return $text;
}

function absint( $value ) {
    return abs( (int) $value );
}

function get_post_type( $post_id ) {
    return $GLOBALS['lunara_suggestion_test']['posts'][ $post_id ]['post_type'] ?? '';
}

function get_post_status( $post_id ) {
    return $GLOBALS['lunara_suggestion_test']['posts'][ $post_id ]['post_status'] ?? '';
}

function get_post_meta( $post_id, $key ) {
    return $GLOBALS['lunara_suggestion_test']['meta'][ $post_id ][ $key ] ?? '';
}

function get_the_title( $post_id ) {
    return $GLOBALS['lunara_suggestion_test']['posts'][ $post_id ]['title'] ?? '';
}

function get_permalink( $post_id ) {
    if ( array_key_exists( 'permalink', $GLOBALS['lunara_suggestion_test']['posts'][ $post_id ] ?? array() ) ) {
        return $GLOBALS['lunara_suggestion_test']['posts'][ $post_id ]['permalink'];
    }
    return isset( $GLOBALS['lunara_suggestion_test']['posts'][ $post_id ] )
        ? 'https://example.test/film/' . absint( $post_id ) . '/'
        : '';
}

function get_posts( $args ) {
    $GLOBALS['lunara_suggestion_test']['queries'][] = $args;
    $ids = array();
    $statuses = (array) ( $args['post_status'] ?? 'publish' );

    foreach ( $GLOBALS['lunara_suggestion_test']['posts'] as $post_id => $post ) {
        if ( $post['post_type'] !== ( $args['post_type'] ?? '' ) || ! in_array( $post['post_status'], $statuses, true ) ) {
            continue;
        }

        if ( ! empty( $args['meta_query'] ) ) {
            $matched = false;
            foreach ( $args['meta_query'] as $condition ) {
                if ( ! is_array( $condition ) || empty( $condition['key'] ) ) {
                    continue;
                }
                $actual = $GLOBALS['lunara_suggestion_test']['meta'][ $post_id ][ $condition['key'] ] ?? '';
                if ( 'LIKE' === ( $condition['compare'] ?? '' ) ) {
                    $needle = trim( (string) ( $condition['value'] ?? '' ), '"' );
                    $values = is_array( $actual ) ? array_map( 'strval', $actual ) : array( (string) $actual );
                    if ( in_array( $needle, $values, true ) ) {
                        $matched = true;
                        break;
                    }
                } elseif ( (string) $actual === (string) ( $condition['value'] ?? '' ) ) {
                    $matched = true;
                    break;
                }
            }
            if ( ! $matched ) {
                continue;
            }
        }

        $ids[] = $post_id;
    }

    sort( $ids, SORT_NUMERIC );
    $limit = (int) ( $args['posts_per_page'] ?? count( $ids ) );
    return $limit > -1 ? array_slice( $ids, 0, $limit ) : $ids;
}

function wp_get_object_terms( $post_id, $taxonomy, $args ) {
    unset( $taxonomy, $args );
    return $GLOBALS['lunara_suggestion_test']['studios'][ $post_id ] ?? array();
}

function is_wp_error() {
    return false;
}

function get_term( $term_id, $taxonomy ) {
    unset( $taxonomy );
    if ( ! isset( $GLOBALS['lunara_suggestion_test']['terms'][ $term_id ] ) ) {
        return null;
    }
    return (object) array(
        'term_id' => $term_id,
        'name'    => $GLOBALS['lunara_suggestion_test']['terms'][ $term_id ],
    );
}

function lunara_suggestion_assert_same( $expected, $actual, $message ) {
    if ( $expected !== $actual ) {
        throw new RuntimeException(
            $message . "\nExpected: " . var_export( $expected, true ) . "\nActual: " . var_export( $actual, true )
        );
    }
}

function lunara_suggestion_assert_true( $condition, $message ) {
    if ( ! $condition ) {
        throw new RuntimeException( $message );
    }
}

class WP_CLI {
    public static $lines = array();

    public static function line( $line ) {
        self::$lines[] = (string) $line;
    }

    public static function error( $message ) {
        throw new RuntimeException( (string) $message );
    }

    public static function add_command() {}
}

class Lunara_Suggestion_Fixture_Gateway {
    public $calls = 0;

    private $result;

    private $throws;

    public function __construct( $result = array(), $throws = false ) {
        $this->result = $result;
        $this->throws = $throws;
    }

    public function get_candidate_by_imdb( $imdb_id ) {
        ++$this->calls;
        if ( $this->throws ) {
            throw new RuntimeException( 'fixture provider failure for ' . $imdb_id );
        }

        return $this->result;
    }
}

require dirname( __DIR__ ) . '/includes/class-lunara-debrief-contract.php';
require dirname( __DIR__ ) . '/includes/class-lunara-debrief-suggestions.php';
require dirname( __DIR__ ) . '/includes/class-lunara-debrief-cli.php';

$report = Lunara_Debrief_Suggestions::for_review(
    99,
    array(
        'role'  => 'career_context',
        'limit' => 2,
    )
);

lunara_suggestion_assert_same( Lunara_Debrief_Suggestions::SCHEMA_VERSION, $report['schema'], 'Suggestion schema must be explicit.' );
lunara_suggestion_assert_same( 0, $report['writes_performed'], 'Suggestion reports must advertise zero writes.' );
lunara_suggestion_assert_same( 'ready', $report['source_status'], 'A unique public source Movie must pass strict source resolution.' );
lunara_suggestion_assert_same( 200, $report['candidate_pool']['scanned'], 'Candidate scans must stop at the hard pool cap.' );
lunara_suggestion_assert_true( $report['candidate_pool']['truncated'], 'A capped pool must disclose truncation.' );
lunara_suggestion_assert_same( array( 16 ), $report['selected_movie_ids'], 'Saved companion relationships must be excluded.' );
lunara_suggestion_assert_same(
    array( 17, 11 ),
    array_column( array_column( $report['roles']['career_context']['candidates'], 'film' ), 'movie_id' ),
    'Career candidates must sort by structured score and then stable Movie ID.'
);
lunara_suggestion_assert_same( 135, $report['roles']['career_context']['candidates'][0]['score'], 'Director, cast, and studio evidence must use documented weights.' );
lunara_suggestion_assert_same(
    array( 'shared_director', 'shared_principal_cast', 'shared_studio' ),
    array_column( $report['roles']['career_context']['candidates'][0]['evidence'], 'code' ),
    'Every score contribution must remain explainable.'
);
lunara_suggestion_assert_true(
    false !== strpos( $report['roles']['career_context']['candidates'][0]['explanation'], 'Director One' ),
    'Local relationship labels must appear in the operator explanation.'
);
lunara_suggestion_assert_true(
    ! in_array( 10, array_column( array_column( $report['roles']['career_context']['candidates'], 'film' ), 'movie_id' ), true ),
    'The reviewed film must never be suggested as its own companion.'
);
lunara_suggestion_assert_true(
    ! in_array( 16, array_column( array_column( $report['roles']['career_context']['candidates'], 'film' ), 'movie_id' ), true ),
    'An already selected companion must never be suggested again.'
);

$all_roles = Lunara_Debrief_Suggestions::for_review( 99 );
lunara_suggestion_assert_same( 'insufficient_evidence', $all_roles['roles']['theme_echo']['status'], 'Theme Echo must abstain without controlled theme metadata.' );
lunara_suggestion_assert_same( array(), $all_roles['roles']['theme_echo']['candidates'], 'Theme Echo abstention must return no invented candidates.' );
lunara_suggestion_assert_same( 'insufficient_evidence', $all_roles['roles']['counter_program']['status'], 'Counter-Program must abstain without controlled theme and tone metadata.' );
lunara_suggestion_assert_same( array(), $all_roles['roles']['counter_program']['candidates'], 'Counter-Program abstention must return no invented candidates.' );
lunara_suggestion_assert_same( 'ready', $all_roles['roles']['career_context']['status'], 'Career Context may use existing structured graph evidence.' );
lunara_suggestion_assert_true(
    ! in_array( 21, array_column( array_column( $all_roles['roles']['career_context']['candidates'], 'film' ), 'movie_id' ), true ),
    'A Movie with conflicting canonical identity fields must never become a suggestion.'
);

$repeated = Lunara_Debrief_Suggestions::for_review( 99 );
lunara_suggestion_assert_same( $all_roles['suggestion_hash'], $repeated['suggestion_hash'], 'Identical local evidence must produce an identical suggestion hash.' );

$pool_query_count_before_sparse = count( array_filter( $GLOBALS['lunara_suggestion_test']['queries'], static function ( $query ) {
    return 201 === (int) ( $query['posts_per_page'] ?? 0 );
} ) );
$sparse = Lunara_Debrief_Suggestions::for_review( 98, array( 'role' => 'career_context' ) );
lunara_suggestion_assert_same( 'insufficient_evidence', $sparse['roles']['career_context']['status'], 'Career Context must abstain when source relationships are missing.' );
lunara_suggestion_assert_same( array( 'source_career_relationships_missing' ), $sparse['roles']['career_context']['reason_codes'], 'Career abstention must explain the missing signal.' );
$pool_query_count_after_sparse = count( array_filter( $GLOBALS['lunara_suggestion_test']['queries'], static function ( $query ) {
    return 201 === (int) ( $query['posts_per_page'] ?? 0 );
} ) );
lunara_suggestion_assert_same( $pool_query_count_before_sparse, $pool_query_count_after_sparse, 'Sparse sources must abstain before a candidate-pool query.' );

$enrichment_gateway = new Lunara_Suggestion_Fixture_Gateway(
    array( 'directors' => array( ' Director One ', 'DIRECTOR ONE', '', 17, null ) )
);
$enriched = Lunara_Debrief_Suggestions::for_review(
    98,
    array( 'role' => 'career_context' ),
    $enrichment_gateway
);
lunara_suggestion_assert_same( 'ready', $enriched['roles']['career_context']['status'], 'A matching provider director must unlock sparse Career Context.' );
lunara_suggestion_assert_same( 1, $enrichment_gateway->calls, 'Sparse Career Context must call the injected provider exactly once.' );
lunara_suggestion_assert_same( array( 100 ), $enriched['source_signals']['directors']['ids'], 'Provider names must resolve only to an existing local Person ID.' );
lunara_suggestion_assert_same( array( 'Director One' ), $enriched['source_signals']['directors']['labels'], 'Resolved provider directors must use canonical local Person labels.' );
lunara_suggestion_assert_true(
    in_array( 11, array_column( array_column( $enriched['roles']['career_context']['candidates'], 'film' ), 'movie_id' ), true ),
    'The existing bounded candidate query and scoring path must use the matched local Person ID.'
);
lunara_suggestion_assert_same( 0, $enriched['writes_performed'], 'Provider enrichment must remain zero-write.' );

$enriched_repeat = Lunara_Debrief_Suggestions::for_review(
    98,
    array( 'role' => 'career_context' ),
    $enrichment_gateway
);
lunara_suggestion_assert_same( $enriched['suggestion_hash'], $enriched_repeat['suggestion_hash'], 'Repeated enriched requests must produce the same suggestion hash.' );
lunara_suggestion_assert_same( 2, $enrichment_gateway->calls, 'Each sparse enriched request may perform only one provider call.' );

$local_gateway = new Lunara_Suggestion_Fixture_Gateway( array( 'directors' => array( 'Director One' ) ) );
Lunara_Debrief_Suggestions::for_review( 99, array( 'role' => 'career_context' ), $local_gateway );
lunara_suggestion_assert_same( 0, $local_gateway->calls, 'Sources with local career signals must not call the provider.' );

foreach ( array(
    new Lunara_Suggestion_Fixture_Gateway( 'invalid provider result' ),
    new Lunara_Suggestion_Fixture_Gateway( array( 'directors' => array() ) ),
    new Lunara_Suggestion_Fixture_Gateway( array(), true ),
) as $failed_gateway ) {
    $failed_enrichment = Lunara_Debrief_Suggestions::for_review(
        98,
        array( 'role' => 'career_context' ),
        $failed_gateway
    );
    lunara_suggestion_assert_same( 'insufficient_evidence', $failed_enrichment['roles']['career_context']['status'], 'Missing, invalid, error, or director-less provider data must fail closed.' );
    lunara_suggestion_assert_same( array( 'source_career_relationships_missing' ), $failed_enrichment['roles']['career_context']['reason_codes'], 'Failed provider enrichment must preserve the existing abstention reason.' );
    lunara_suggestion_assert_same( 1, $failed_gateway->calls, 'A sparse failed enrichment attempt must still be bounded to one provider call.' );
}

$ambiguous = Lunara_Debrief_Suggestions::for_review( 97, array( 'role' => 'career_context' ) );
lunara_suggestion_assert_same( 'unavailable', $ambiguous['source_status'], 'Duplicate source Movie identities must fail closed.' );
lunara_suggestion_assert_same( array( 'source_movie_ambiguous' ), $ambiguous['source_reason_codes'], 'Ambiguous source resolution must be explicit.' );
lunara_suggestion_assert_same( array(), $ambiguous['roles']['career_context']['candidates'], 'Ambiguous source identities must never produce candidates.' );

foreach ( array(
    array( 'review_id' => '99,98', 'args' => array() ),
    array( 'review_id' => 999, 'args' => array() ),
    array( 'review_id' => 99, 'args' => array( 'role' => 'theme' ) ),
    array( 'review_id' => 99, 'args' => array( 'limit' => 0 ) ),
    array( 'review_id' => 99, 'args' => array( 'limit' => 13 ) ),
) as $invalid ) {
    $rejected = false;
    try {
        Lunara_Debrief_Suggestions::for_review( $invalid['review_id'], $invalid['args'] );
    } catch ( InvalidArgumentException $error ) {
        $rejected = true;
    }
    lunara_suggestion_assert_true( $rejected, 'Invalid suggestion scope must fail closed.' );
}

$pool_queries = array_values( array_filter( $GLOBALS['lunara_suggestion_test']['queries'], static function ( $query ) {
    return 201 === (int) ( $query['posts_per_page'] ?? 0 );
} ) );
$source_queries = array_values( array_filter( $GLOBALS['lunara_suggestion_test']['queries'], static function ( $query ) {
    return 2 === (int) ( $query['posts_per_page'] ?? 0 );
} ) );
lunara_suggestion_assert_true( ! empty( $source_queries ), 'Source resolution must use a bounded ambiguity check.' );
lunara_suggestion_assert_same( false, $source_queries[0]['cache_results'], 'Source identity checks must not rely on warmed query state.' );
lunara_suggestion_assert_true( ! empty( $pool_queries ), 'Suggestions must issue a bounded candidate query.' );
lunara_suggestion_assert_same( 'publish', $pool_queries[0]['post_status'], 'Only published Movies may enter the candidate pool.' );
lunara_suggestion_assert_same( true, $pool_queries[0]['no_found_rows'], 'Candidate discovery must avoid pagination counts.' );
lunara_suggestion_assert_same( 'OR', $pool_queries[0]['meta_query']['relation'], 'Candidate discovery must target source career relationships.' );

WP_CLI::$lines = array();
$cli = new Lunara_Debrief_CLI();
$cli->suggest(
    array(),
    array(
        'review-id' => '99',
        'role'      => 'career_context',
        'limit'     => '2',
        'format'    => 'summary',
    )
);
$cli_summary = implode( "\n", WP_CLI::$lines );
lunara_suggestion_assert_true( false !== strpos( $cli_summary, 'Review ID: 99' ), 'Suggestion CLI must report its exact bounded Review.' );
lunara_suggestion_assert_true( false !== strpos( $cli_summary, 'Writes performed: 0' ), 'Suggestion CLI must state its zero-write guarantee.' );

$apply_rejected = false;
try {
    $cli->suggest( array(), array( 'review-id' => '99', 'apply' => true ) );
} catch ( RuntimeException $error ) {
    $apply_rejected = false !== strpos( $error->getMessage(), 'not available' );
}
lunara_suggestion_assert_true( $apply_rejected, 'Suggestion CLI must reject every apply attempt.' );

$source = file_get_contents( dirname( __DIR__ ) . '/includes/class-lunara-debrief-suggestions.php' );
lunara_suggestion_assert_true(
    0 === preg_match( '/\b(?:update_post_meta|add_post_meta|delete_post_meta|update_field|delete_field|wp_insert_post|wp_update_post|wp_set_object_terms|update_option|delete_option|set_transient|wp_remote_[a-z_]+)\s*\(/', $source ),
    'Suggestion service must contain no application-data write or remote call.'
);
lunara_suggestion_assert_true( false === strpos( $source, 'file_put_contents' ), 'Suggestion output must stay on stdout through the CLI adapter.' );
lunara_suggestion_assert_true( false === strpos( $source, 'editorial_reason' ), 'Suggestions must never author the editor-owned pairing reason.' );
$cli_source = file_get_contents( dirname( __DIR__ ) . '/includes/class-lunara-debrief-cli.php' );
lunara_suggestion_assert_true( false !== strpos( $cli_source, '* --review-id=<id>' ), 'WP-CLI must require one explicit Review ID for suggestions.' );

echo "Debrief suggestion regression checks passed.\n";
