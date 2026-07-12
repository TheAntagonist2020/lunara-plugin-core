<?php
/**
 * Dependency-free regression checks for local Movie draft imports.
 *
 * Run with: php tests/movie-importer-regression.php
 */

define( 'ABSPATH', __DIR__ . '/' );

$GLOBALS['lunara_movie_repo_test'] = array(
    'posts'         => array(),
    'meta'          => array(),
    'next_id'       => 1000,
    'writes'        => array(),
    'fail_meta_key' => '',
    'error_meta_key'=> '',
    'ignore_meta_key'=> '',
    'drop_identity_meta_input' => false,
    'fail_insert'   => false,
    'locks'         => array(),
    'lock_attempts' => array(),
    'lock_releases' => array(),
    'force_lock_unavailable' => false,
);

function lunara_movie_import_assert_same( $expected, $actual, $message ) {
    if ( $expected !== $actual ) {
        fwrite( STDERR, "FAIL: {$message}\nExpected: " . var_export( $expected, true ) . "\nActual: " . var_export( $actual, true ) . "\n" );
        exit( 1 );
    }
}

function lunara_movie_import_assert_true( $condition, $message ) {
    if ( ! $condition ) {
        fwrite( STDERR, "FAIL: {$message}\n" );
        exit( 1 );
    }
}

function lunara_movie_repo_query_ids( $args ) {
    $state    = $GLOBALS['lunara_movie_repo_test'];
    $statuses = isset( $args['post_status'] ) ? (array) $args['post_status'] : array( 'publish' );
    $clauses  = isset( $args['meta_query'] ) ? $args['meta_query'] : array();
    $ids      = array();

    foreach ( $state['posts'] as $post_id => $post ) {
        if ( 'movie' !== $post['post_type'] || ! in_array( $post['post_status'], $statuses, true ) ) {
            continue;
        }

        $matched = false;
        foreach ( $clauses as $key => $clause ) {
            if ( 'relation' === $key || ! is_array( $clause ) ) {
                continue;
            }

            $stored = isset( $state['meta'][ $post_id ][ $clause['key'] ] )
                ? $state['meta'][ $post_id ][ $clause['key'] ]
                : '';
            if ( (string) $stored === (string) $clause['value'] ) {
                $matched = true;
                break;
            }
        }

        if ( $matched ) {
            $ids[] = (int) $post_id;
        }
    }

    sort( $ids, SORT_NUMERIC );
    return $ids;
}

function lunara_movie_repo_post( $post_id ) {
    return isset( $GLOBALS['lunara_movie_repo_test']['posts'][ $post_id ] )
        ? (object) $GLOBALS['lunara_movie_repo_test']['posts'][ $post_id ]
        : null;
}

function lunara_movie_repo_meta( $post_id, $key ) {
    return isset( $GLOBALS['lunara_movie_repo_test']['meta'][ $post_id ][ $key ] )
        ? $GLOBALS['lunara_movie_repo_test']['meta'][ $post_id ][ $key ]
        : '';
}

function lunara_movie_repo_statuses() {
    return array( 'publish', 'future', 'draft', 'pending', 'private', 'trash', 'auto-draft', 'inherit' );
}

function lunara_movie_repo_insert( $post_data ) {
    $state   =& $GLOBALS['lunara_movie_repo_test'];
    if ( $state['fail_insert'] ) {
        return new Lunara_Movie_Repo_Test_Error( 'insert_failed' );
    }

    $post_id = $state['next_id']++;
    $meta    = isset( $post_data['meta_input'] ) && is_array( $post_data['meta_input'] )
        ? $post_data['meta_input']
        : array();
    if ( $state['drop_identity_meta_input'] ) {
        unset( $meta['imdb_title_id'] );
    }
    unset( $post_data['meta_input'] );

    $state['posts'][ $post_id ] = array_merge(
        array(
            'ID'           => $post_id,
            'post_type'    => 'movie',
            'post_status'  => 'draft',
            'post_title'   => '',
            'post_excerpt' => '',
        ),
        $post_data
    );
    $state['meta'][ $post_id ] = $meta;
    $state['writes'][]          = array( 'insert_post', $post_id, $post_data, $meta );

    return $post_id;
}

function lunara_movie_repo_update_post( $post_data ) {
    $state   =& $GLOBALS['lunara_movie_repo_test'];
    $post_id = isset( $post_data['ID'] ) ? (int) $post_data['ID'] : 0;
    if ( ! isset( $state['posts'][ $post_id ] ) ) {
        return 0;
    }

    unset( $post_data['ID'], $post_data['post_status'], $post_data['post_type'] );
    foreach ( $post_data as $key => $value ) {
        $state['posts'][ $post_id ][ $key ] = $value;
    }
    $state['writes'][] = array( 'update_post', $post_id, $post_data );

    return $post_id;
}

function lunara_movie_repo_update_meta( $post_id, $key, $value ) {
    $state =& $GLOBALS['lunara_movie_repo_test'];
    if ( $state['error_meta_key'] === $key ) {
        return new Lunara_Movie_Repo_Test_Error( 'meta_failed' );
    }
    if ( $state['fail_meta_key'] === $key ) {
        return false;
    }
    if ( $state['ignore_meta_key'] === $key ) {
        return true;
    }

    $current = isset( $state['meta'][ $post_id ][ $key ] ) ? $state['meta'][ $post_id ][ $key ] : null;
    if ( $current === $value ) {
        return false;
    }

    $state['meta'][ $post_id ][ $key ] = $value;
    $state['writes'][]                 = array( 'update_meta', (int) $post_id, $key, $value );

    return true;
}

function lunara_movie_repo_acquire_lock( $imdb_id ) {
    $state =& $GLOBALS['lunara_movie_repo_test'];
    $state['lock_attempts'][] = $imdb_id;
    if ( $state['force_lock_unavailable'] || isset( $state['locks'][ $imdb_id ] ) ) {
        return false;
    }

    $handle = array(
        'imdb_title_id' => $imdb_id,
        'token'         => 'fixture-' . count( $state['lock_attempts'] ),
        'expires_at'    => time() + 30,
    );
    $state['locks'][ $imdb_id ] = $handle;
    return $handle;
}

function lunara_movie_repo_release_lock( $handle ) {
    $state   =& $GLOBALS['lunara_movie_repo_test'];
    $imdb_id = isset( $handle['imdb_title_id'] ) ? $handle['imdb_title_id'] : '';
    $state['lock_releases'][] = $handle;
    if ( '' === $imdb_id || ! isset( $state['locks'][ $imdb_id ] ) || $state['locks'][ $imdb_id ]['token'] !== $handle['token'] ) {
        return false;
    }

    unset( $state['locks'][ $imdb_id ] );
    return true;
}

function lunara_movie_repo_is_error( $value ) {
    return $value instanceof Lunara_Movie_Repo_Test_Error;
}

function lunara_movie_repo_error_message() {
    return 'Fixture write failed.';
}

final class Lunara_Movie_Repo_Test_Error {
    public $code;

    public function __construct( $code ) {
        $this->code = $code;
    }
}

final class Lunara_Movie_Fixture_Gateway {
    public $calls = 0;
    public $candidates = array();

    public function get_candidate_by_imdb( $imdb_id ) {
        ++$this->calls;
        return isset( $this->candidates[ $imdb_id ] ) ? $this->candidates[ $imdb_id ] : null;
    }
}

require dirname( __DIR__ ) . '/includes/class-lunara-debrief-contract.php';
require dirname( __DIR__ ) . '/includes/class-lunara-movie-import-contract.php';
require dirname( __DIR__ ) . '/includes/class-lunara-movie-repository.php';
require dirname( __DIR__ ) . '/includes/class-lunara-movie-importer.php';

$adapter = array(
    'query_ids'    => 'lunara_movie_repo_query_ids',
    'post'         => 'lunara_movie_repo_post',
    'meta'         => 'lunara_movie_repo_meta',
    'post_statuses'=> 'lunara_movie_repo_statuses',
    'insert_post'  => 'lunara_movie_repo_insert',
    'update_post'  => 'lunara_movie_repo_update_post',
    'update_meta'  => 'lunara_movie_repo_update_meta',
    'acquire_lock' => 'lunara_movie_repo_acquire_lock',
    'release_lock' => 'lunara_movie_repo_release_lock',
    'is_error'     => 'lunara_movie_repo_is_error',
    'error_message'=> 'lunara_movie_repo_error_message',
);

$repository = new Lunara_Movie_Repository( $adapter );
$gateway    = new Lunara_Movie_Fixture_Gateway();
$importer   = new Lunara_Movie_Importer( $repository, $gateway );

$candidate = array(
    'imdb_title_id' => 'tt1000001',
    'title'          => 'Imported Film',
    'original_title' => 'Imported Film Original',
    'release_year'   => 2024,
    'release_date'   => '2024-05-17',
    'runtime_minutes'=> 142,
    'overview'       => 'A factual provider overview.',
    'genres'         => array( 'Drama', 'Thriller' ),
    'countries'      => array( 'United States' ),
    'content_rating' => 'PG-13',
    'tmdb_id'        => 10101,
    'directors'      => array( 'A Director' ),
    'cast'           => array( 'An Actor' ),
    'poster_path'    => '/poster.jpg',
    'backdrop_path'  => '/backdrop.jpg',
);
$gateway->candidates['tt1000001'] = $candidate;

$preview = $importer->preview_by_imdb( 'IMDb: TT1000001' );
lunara_movie_import_assert_same( 'ready', $preview['status'], 'A missing local identity with a provider candidate must be previewable.' );
lunara_movie_import_assert_same( 'create', $preview['action'], 'A new identity must plan a draft creation.' );
lunara_movie_import_assert_same( true, $preview['gateway_used'], 'Only a missing local identity may invoke the provider gateway.' );
lunara_movie_import_assert_same( 0, $preview['writes_performed'], 'Preview must be strictly zero-write.' );
lunara_movie_import_assert_same( 0, count( $GLOBALS['lunara_movie_repo_test']['writes'] ), 'Preview must not mutate the repository.' );
lunara_movie_import_assert_true( Lunara_Movie_Import_Contract::verify_plan( $preview['plan'] ), 'Preview must return a sealed versioned plan.' );

$created = $importer->import_draft(
    $preview['candidate'],
    array( 'review_id' => '42', 'role' => 'Theme Echo', 'requested_by' => '9', 'nonce' => 'discard-me' )
);
lunara_movie_import_assert_same( 'created', $created['status'], 'Explicit import must create one Movie draft.' );
lunara_movie_import_assert_true( $created['movie_id'] > 0, 'Created imports must return their Movie ID.' );
lunara_movie_import_assert_same( 'draft', $GLOBALS['lunara_movie_repo_test']['posts'][ $created['movie_id'] ]['post_status'], 'Imports must only create drafts.' );
lunara_movie_import_assert_same( 'tt1000001', lunara_movie_repo_meta( $created['movie_id'], 'imdb_title_id' ), 'The canonical identity must be inserted with the draft.' );
lunara_movie_import_assert_same( 9, $created['plan']['context']['requested_by'], 'Sanitized operator identity must remain in the plan audit context.' );
lunara_movie_import_assert_same( 42, $created['plan']['context']['review_id'], 'Review provenance must normalize to an integer.' );
lunara_movie_import_assert_same( 'theme_echo', $created['plan']['context']['role'], 'Role provenance must be normalized and non-secret.' );
lunara_movie_import_assert_true( ! isset( $GLOBALS['lunara_movie_repo_test']['meta'][ $created['movie_id'] ]['directors'] ), 'Provider names must not overwrite curated relationships.' );
lunara_movie_import_assert_true( ! isset( $GLOBALS['lunara_movie_repo_test']['meta'][ $created['movie_id'] ]['backdrop_image'] ), 'Remote paths must not be written into local image fields.' );
lunara_movie_import_assert_same( array(), $GLOBALS['lunara_movie_repo_test']['locks'], 'Successful creation must release its IMDb lock.' );
lunara_movie_import_assert_same( 'tt1000001', end( $GLOBALS['lunara_movie_repo_test']['lock_releases'] )['imdb_title_id'], 'The released handle must belong to the imported identity.' );

$writes_after_create = count( $GLOBALS['lunara_movie_repo_test']['writes'] );
$repeat              = $importer->import_draft( $preview['candidate'], array( 'review_id' => 42, 'role' => 'Theme Echo', 'requested_by' => 9 ) );
lunara_movie_import_assert_same( 'unchanged', $repeat['status'], 'An identical repeated import must become a no-op.' );
lunara_movie_import_assert_same( 0, $repeat['writes_performed'], 'An identical repeated import must perform zero writes.' );
lunara_movie_import_assert_same( $writes_after_create, count( $GLOBALS['lunara_movie_repo_test']['writes'] ), 'No-op imports must not call a write adapter.' );

$gateway_calls = $gateway->calls;
$local_preview = $importer->preview_by_imdb( 'tt1000001' );
lunara_movie_import_assert_same( 'ready', $local_preview['status'], 'An explicit preview may enrich the one existing draft.' );
lunara_movie_import_assert_same( true, $local_preview['local'], 'Draft enrichment must retain the canonical local record.' );
lunara_movie_import_assert_same( true, $local_preview['gateway_used'], 'Draft enrichment may use the injected provider gateway.' );
lunara_movie_import_assert_same( $gateway_calls + 1, $gateway->calls, 'Draft enrichment must perform exactly one provider lookup.' );
lunara_movie_import_assert_same( $created['movie_id'], $local_preview['movie_id'], 'Draft enrichment must target the existing Movie ID.' );
lunara_movie_import_assert_same( 0, $local_preview['writes_performed'], 'Draft enrichment preview must remain zero-write.' );

$GLOBALS['lunara_movie_repo_test']['posts'][200] = array(
    'ID'           => 200,
    'post_type'    => 'movie',
    'post_status'  => 'draft',
    'post_title'   => 'Curated Local Title',
    'post_excerpt' => 'Curated local summary.',
);
$GLOBALS['lunara_movie_repo_test']['meta'][200] = array(
    'imdb_title_id' => 'tt2000001',
    'genres'         => 'Noir',
    'runtime'        => '',
    'release_year'   => '',
);
$curated_candidate = array_merge(
    $candidate,
    array(
        'imdb_title_id' => 'tt2000001',
        'title'          => 'Provider Replacement Title',
        'overview'       => 'Provider replacement summary.',
        'genres'         => array( 'Comedy' ),
        'runtime_minutes'=> 95,
        'release_year'   => 1999,
    )
);
$curated_result = $importer->import_draft( $curated_candidate );
lunara_movie_import_assert_same( 'updated', $curated_result['status'], 'A draft may fill factual blank fields.' );
lunara_movie_import_assert_same( 'Curated Local Title', $GLOBALS['lunara_movie_repo_test']['posts'][200]['post_title'], 'Existing editorial titles must be preserved.' );
lunara_movie_import_assert_same( 'Curated local summary.', $GLOBALS['lunara_movie_repo_test']['posts'][200]['post_excerpt'], 'Existing editorial summaries must be preserved.' );
lunara_movie_import_assert_same( 'Noir', lunara_movie_repo_meta( 200, 'genres' ), 'Existing curated metadata must be preserved.' );
lunara_movie_import_assert_same( '95 min', lunara_movie_repo_meta( 200, 'runtime' ), 'Blank runtime may be filled.' );
lunara_movie_import_assert_same( 1999, lunara_movie_repo_meta( 200, 'release_year' ), 'Blank release year may be filled.' );
lunara_movie_import_assert_true( in_array( 'post.post_title', $curated_result['plan']['preserved_fields'], true ), 'The plan must disclose preserved editorial fields.' );
lunara_movie_import_assert_true( in_array( 'meta.genres', $curated_result['plan']['preserved_fields'], true ), 'The plan must disclose preserved curated metadata.' );

$GLOBALS['lunara_movie_repo_test']['posts'][201] = array(
    'ID'           => 201,
    'post_type'    => 'movie',
    'post_status'  => 'publish',
    'post_title'   => '',
    'post_excerpt' => '',
);
$GLOBALS['lunara_movie_repo_test']['meta'][201] = array( 'imdb_title_id' => 'tt3000001' );
$before_published = count( $GLOBALS['lunara_movie_repo_test']['writes'] );
$published_result = $importer->import_draft( array( 'imdb_title_id' => 'tt3000001', 'title' => 'Provider Title', 'release_year' => 2001 ) );
lunara_movie_import_assert_same( 'unchanged', $published_result['status'], 'Published Movies must remain outside the importer write boundary.' );
lunara_movie_import_assert_same( 0, $published_result['writes_performed'], 'Published Movies must never receive importer writes.' );
lunara_movie_import_assert_same( $before_published, count( $GLOBALS['lunara_movie_repo_test']['writes'] ), 'Published preservation must not call a write adapter.' );

$published_gateway_calls = $gateway->calls;
$published_preview       = $importer->preview_by_imdb( 'tt3000001' );
lunara_movie_import_assert_same( 'local', $published_preview['status'], 'Non-draft local Movies must remain local-only.' );
lunara_movie_import_assert_same( false, $published_preview['gateway_used'], 'Non-draft local Movies must perform zero provider calls.' );
lunara_movie_import_assert_same( $published_gateway_calls, $gateway->calls, 'Published local preview must not change the gateway call count.' );

$GLOBALS['lunara_movie_repo_test']['posts'][202] = array( 'ID' => 202, 'post_type' => 'movie', 'post_status' => 'draft', 'post_title' => 'Duplicate A', 'post_excerpt' => '' );
$GLOBALS['lunara_movie_repo_test']['posts'][203] = array( 'ID' => 203, 'post_type' => 'movie', 'post_status' => 'trash', 'post_title' => 'Duplicate B', 'post_excerpt' => '' );
$GLOBALS['lunara_movie_repo_test']['meta'][202]  = array( 'imdb_title_id' => 'tt4000001' );
$GLOBALS['lunara_movie_repo_test']['meta'][203]  = array( '_lunara_entity_id' => 'tt4000001' );
$before_conflict = count( $GLOBALS['lunara_movie_repo_test']['writes'] );
$conflict        = $importer->import_draft( array( 'imdb_title_id' => 'tt4000001', 'title' => 'Duplicate Candidate' ) );
lunara_movie_import_assert_same( 'conflict', $conflict['status'], 'Multiple identity claimants across canonical and fallback keys must conflict.' );
lunara_movie_import_assert_same( array( 202, 203 ), $conflict['plan']['matched_movie_ids'], 'Identity conflicts must expose every matching Movie ID.' );
lunara_movie_import_assert_same( 0, $conflict['writes_performed'], 'Identity conflicts must remain zero-write.' );
lunara_movie_import_assert_same( $before_conflict, count( $GLOBALS['lunara_movie_repo_test']['writes'] ), 'Identity conflicts must not call a write adapter.' );

$GLOBALS['lunara_movie_repo_test']['posts'][204] = array( 'ID' => 204, 'post_type' => 'movie', 'post_status' => 'draft', 'post_title' => 'Alias Film', 'post_excerpt' => '' );
$GLOBALS['lunara_movie_repo_test']['meta'][204]  = array( '_lunara_entity_id' => 'tt5000001' );
$alias_result = $importer->import_draft( array( 'imdb_title_id' => 'tt5000001', 'title' => 'Alias Film', 'release_year' => 1988 ) );
lunara_movie_import_assert_same( 'updated', $alias_result['status'], 'An alias-only draft must be reused rather than duplicated.' );
lunara_movie_import_assert_same( 204, $alias_result['movie_id'], 'Fallback identity lookup must return the existing Movie.' );
lunara_movie_import_assert_same( 'tt5000001', lunara_movie_repo_meta( 204, 'imdb_title_id' ), 'Alias-only drafts may fill the canonical identity key.' );
lunara_movie_import_assert_same( 'tt5000001', lunara_movie_repo_meta( 204, '_lunara_entity_id' ), 'Historic entity identity must remain preserved.' );

$failed_enrichment = $importer->preview_by_imdb( 'tt5000001' );
lunara_movie_import_assert_same( 'local', $failed_enrichment['status'], 'Provider failure while enriching a draft must fall back to the local dossier.' );
lunara_movie_import_assert_same( true, $failed_enrichment['local'], 'Failed draft enrichment must retain local identity state.' );
lunara_movie_import_assert_same( true, $failed_enrichment['gateway_used'], 'Failed draft enrichment must disclose that the gateway was attempted.' );
lunara_movie_import_assert_same( 204, $failed_enrichment['movie_id'], 'Failed draft enrichment must retain the recoverable Movie ID.' );

$partial_candidate = array(
    'imdb_title_id' => 'tt6000001',
    'title'          => 'Recoverable Partial',
    'runtime_minutes'=> 111,
);
$GLOBALS['lunara_movie_repo_test']['fail_meta_key'] = 'runtime';
$partial = $importer->import_draft( $partial_candidate );
lunara_movie_import_assert_same( 'partial', $partial['status'], 'A metadata failure after draft creation must be reported explicitly.' );
lunara_movie_import_assert_true( $partial['movie_id'] > 0, 'Partial results must expose the recoverable draft ID.' );
lunara_movie_import_assert_same( 'tt6000001', lunara_movie_repo_meta( $partial['movie_id'], 'imdb_title_id' ), 'The identity must be embedded in draft creation before secondary metadata writes.' );
lunara_movie_import_assert_same( 1, $partial['writes_performed'], 'Failed metadata writes must not be counted as successful.' );
$post_count_after_partial = count( $GLOBALS['lunara_movie_repo_test']['posts'] );
$GLOBALS['lunara_movie_repo_test']['fail_meta_key'] = '';
$retry = $importer->import_draft( $partial_candidate );
lunara_movie_import_assert_same( 'updated', $retry['status'], 'Retry must fill the failed blank on the same draft.' );
lunara_movie_import_assert_same( $partial['movie_id'], $retry['movie_id'], 'Retry must reuse the recoverable partial draft.' );
lunara_movie_import_assert_same( $post_count_after_partial, count( $GLOBALS['lunara_movie_repo_test']['posts'] ), 'Retry must not create a second draft.' );
$retry_again = $importer->import_draft( $partial_candidate );
lunara_movie_import_assert_same( 'unchanged', $retry_again['status'], 'A completed retry must become a zero-write no-op.' );
lunara_movie_import_assert_same( 0, $retry_again['writes_performed'], 'A completed retry must perform zero writes.' );

$contended_preview = $importer->preview_candidate( array( 'imdb_title_id' => 'tt7100001', 'title' => 'Contended Film' ) );
$writes_before_contention   = count( $GLOBALS['lunara_movie_repo_test']['writes'] );
$releases_before_contention = count( $GLOBALS['lunara_movie_repo_test']['lock_releases'] );
$GLOBALS['lunara_movie_repo_test']['force_lock_unavailable'] = true;
$contended = $importer->apply_plan( $contended_preview['plan'] );
lunara_movie_import_assert_same( 'conflict', $contended['status'], 'An unavailable IMDb lock must fail closed as a conflict.' );
lunara_movie_import_assert_same( array( 'identity_lock_unavailable' ), $contended['issues'], 'Lock contention must use one stable issue code.' );
lunara_movie_import_assert_same( 0, $contended['writes_performed'], 'Lock contention must remain zero-write.' );
lunara_movie_import_assert_same( $writes_before_contention, count( $GLOBALS['lunara_movie_repo_test']['writes'] ), 'A contended import must not call a write adapter.' );
lunara_movie_import_assert_same( $releases_before_contention, count( $GLOBALS['lunara_movie_repo_test']['lock_releases'] ), 'A lock that was never acquired must not be released.' );
$contended_again = $importer->apply_plan( $contended_preview['plan'] );
lunara_movie_import_assert_same( $contended['issues'], $contended_again['issues'], 'Repeated lock contention must return the same stable issue.' );
$GLOBALS['lunara_movie_repo_test']['force_lock_unavailable'] = false;
$after_contention = $importer->apply_plan( $contended_preview['plan'] );
lunara_movie_import_assert_same( 'created', $after_contention['status'], 'The original plan may apply after the identity lock becomes available.' );
lunara_movie_import_assert_same( array(), $GLOBALS['lunara_movie_repo_test']['locks'], 'The acquired contention-retry lock must be released.' );

$insert_error_preview = $importer->preview_candidate( array( 'imdb_title_id' => 'tt7200001', 'title' => 'Insert Error Film' ) );
$release_count        = count( $GLOBALS['lunara_movie_repo_test']['lock_releases'] );
$GLOBALS['lunara_movie_repo_test']['fail_insert'] = true;
$insert_error = $importer->apply_plan( $insert_error_preview['plan'] );
lunara_movie_import_assert_same( 'error', $insert_error['status'], 'Insert errors must fail without reporting a Movie.' );
lunara_movie_import_assert_same( 0, $insert_error['writes_performed'], 'Insert errors must perform zero successful writes.' );
lunara_movie_import_assert_same( $release_count + 1, count( $GLOBALS['lunara_movie_repo_test']['lock_releases'] ), 'Insert errors must still release an acquired lock.' );
lunara_movie_import_assert_same( array(), $GLOBALS['lunara_movie_repo_test']['locks'], 'Insert error release must clear the held lock.' );
$GLOBALS['lunara_movie_repo_test']['fail_insert'] = false;

$GLOBALS['lunara_movie_repo_test']['drop_identity_meta_input'] = true;
$identity_repaired = $importer->import_draft( array( 'imdb_title_id' => 'tt7300001', 'title' => 'Identity Repair Film' ) );
lunara_movie_import_assert_same( 'created', $identity_repaired['status'], 'A missing meta_input identity must receive one explicit repair.' );
lunara_movie_import_assert_same( 'tt7300001', lunara_movie_repo_meta( $identity_repaired['movie_id'], 'imdb_title_id' ), 'Explicit identity repair must be verified after write.' );
lunara_movie_import_assert_same( 2, $identity_repaired['writes_performed'], 'Verified identity repair must count the post and explicit metadata write.' );
$post_count_after_repair = count( $GLOBALS['lunara_movie_repo_test']['posts'] );
$GLOBALS['lunara_movie_repo_test']['drop_identity_meta_input'] = false;
$identity_repeat = $importer->import_draft( array( 'imdb_title_id' => 'tt7300001', 'title' => 'Identity Repair Film' ) );
lunara_movie_import_assert_same( 'unchanged', $identity_repeat['status'], 'A repaired identity must make the next identical import a no-op.' );
lunara_movie_import_assert_same( $post_count_after_repair, count( $GLOBALS['lunara_movie_repo_test']['posts'] ), 'Identity repair must prevent a second draft.' );

$GLOBALS['lunara_movie_repo_test']['drop_identity_meta_input'] = true;
$GLOBALS['lunara_movie_repo_test']['fail_meta_key']            = 'imdb_title_id';
$identity_false = $importer->import_draft( array( 'imdb_title_id' => 'tt7400001', 'title' => 'False Identity Film' ) );
lunara_movie_import_assert_same( 'partial', $identity_false['status'], 'A false identity repair result must return a recoverable partial.' );
lunara_movie_import_assert_true( $identity_false['movie_id'] > 0, 'A failed identity repair must expose the recoverable draft ID.' );
lunara_movie_import_assert_same( 1, $identity_false['writes_performed'], 'A false identity repair must not be counted as successful.' );
lunara_movie_import_assert_same( array( 'identity_meta_write_failed' ), $identity_false['issues'], 'False identity repair must have a stable issue.' );
lunara_movie_import_assert_same( array(), $GLOBALS['lunara_movie_repo_test']['locks'], 'Identity repair failure must release its lock.' );
$GLOBALS['lunara_movie_repo_test']['fail_meta_key'] = '';

$GLOBALS['lunara_movie_repo_test']['error_meta_key'] = 'imdb_title_id';
$identity_error = $importer->import_draft( array( 'imdb_title_id' => 'tt7500001', 'title' => 'Error Identity Film' ) );
lunara_movie_import_assert_same( 'partial', $identity_error['status'], 'A WordPress error identity result must return a recoverable partial.' );
lunara_movie_import_assert_same( 1, $identity_error['writes_performed'], 'A WordPress error identity result must not be counted as successful.' );
lunara_movie_import_assert_same( array( 'identity_meta_write_failed' ), $identity_error['issues'], 'WordPress identity errors must use the stable failure issue.' );
$GLOBALS['lunara_movie_repo_test']['error_meta_key'] = '';

$GLOBALS['lunara_movie_repo_test']['ignore_meta_key'] = 'imdb_title_id';
$identity_ignored = $importer->import_draft( array( 'imdb_title_id' => 'tt7600001', 'title' => 'Ignored Identity Film' ) );
lunara_movie_import_assert_same( 'partial', $identity_ignored['status'], 'A nominal identity write that does not persist must remain partial.' );
lunara_movie_import_assert_same( 1, $identity_ignored['writes_performed'], 'An unpersisted nominal write must not be counted as successful.' );
lunara_movie_import_assert_same( array( 'identity_meta_not_persisted' ), $identity_ignored['issues'], 'Post-write identity verification must use a stable issue.' );
$GLOBALS['lunara_movie_repo_test']['ignore_meta_key']          = '';
$GLOBALS['lunara_movie_repo_test']['drop_identity_meta_input'] = false;

$stale_preview = $importer->preview_candidate( array( 'imdb_title_id' => 'tt7000001', 'title' => 'Stale Plan Film' ) );
$GLOBALS['lunara_movie_repo_test']['posts'][205] = array( 'ID' => 205, 'post_type' => 'movie', 'post_status' => 'draft', 'post_title' => 'Concurrent Film', 'post_excerpt' => '' );
$GLOBALS['lunara_movie_repo_test']['meta'][205]  = array( 'imdb_title_id' => 'tt7000001' );
$stale_result = $importer->apply_plan( $stale_preview['plan'] );
lunara_movie_import_assert_same( 'conflict', $stale_result['status'], 'Plans must be revalidated immediately before mutation.' );
lunara_movie_import_assert_true( in_array( 'stale_plan', $stale_result['issues'], true ), 'Concurrent identity changes must be explicit.' );
lunara_movie_import_assert_same( 0, $stale_result['writes_performed'], 'Stale plans must remain zero-write.' );

$tampered_preview                       = $importer->preview_candidate( array( 'imdb_title_id' => 'tt8000001', 'title' => 'Tamper Test' ) );
$tampered_preview['plan']['post_writes']['post_status'] = 'publish';
$tampered = $importer->apply_plan( $tampered_preview['plan'] );
lunara_movie_import_assert_same( 'invalid', $tampered['status'], 'Plan hash verification must reject a publish-status mutation.' );
lunara_movie_import_assert_same( 0, $tampered['writes_performed'], 'Tampered plans must remain zero-write.' );

$repository_source = file_get_contents( dirname( __DIR__ ) . '/includes/class-lunara-movie-repository.php' );
$importer_source   = file_get_contents( dirname( __DIR__ ) . '/includes/class-lunara-movie-importer.php' );
lunara_movie_import_assert_true( false === strpos( $repository_source . $importer_source, 'wp_remote_' ), 'Repository and importer must never perform remote HTTP.' );
lunara_movie_import_assert_true( false === strpos( $repository_source, "'post_status' => 'publish'" ), 'Repository plans must never publish a Movie.' );

echo "Movie importer regression checks passed.\n";
