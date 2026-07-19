<?php
/**
 * Dependency-free regression checks for canonical Movie graph growth.
 *
 * Run with: php tests/graph-growth-identity-regression.php
 */

define( 'ABSPATH', __DIR__ . '/' );

class WP_Post {
	public $ID;
	public $post_type;

	public function __construct( $id, $post_type ) {
		$this->ID        = (int) $id;
		$this->post_type = (string) $post_type;
	}
}

final class Lunara_Movie_Identity_Lock {
	public static $busy = false;
	public static $acquired = array();
	public static $released = array();

	public static function acquire( $imdb_id, $ttl = 30 ) {
		self::$acquired[] = $imdb_id;
		return self::$busy ? false : array( 'key' => $imdb_id, 'token' => 'test-token' );
	}

	public static function release( $handle ) {
		self::$released[] = $handle;
		return true;
	}
}

final class Lunara_Graph_Test_WPDB {
	public $postmeta = 'wp_postmeta';

	public function insert( $table, $data, $format ) {
		if ( ! $GLOBALS['lunara_graph_test']['direct_identity_reservation_result'] ) {
			return false;
		}

		$GLOBALS['lunara_graph_test']['meta'][ $data['post_id'] ][ $data['meta_key'] ] = $data['meta_value'];
		return 1;
	}
}

$wpdb = new Lunara_Graph_Test_WPDB();

$GLOBALS['lunara_graph_test'] = array(
	'posts'       => array(
		10 => array( 'post_type' => 'movie', 'post_status' => 'draft', 'post_title' => 'Six Digit Owner' ),
		11 => array( 'post_type' => 'movie', 'post_status' => 'trash', 'post_title' => 'Legacy Owner' ),
	),
	'meta'        => array(
		10 => array( 'imdb_title_id' => 'tt123456' ),
		11 => array( '_lunara_entity_id' => 'tt1234567' ),
	),
	'review_meta' => array(),
	'titles'      => array(),
	'insertions'  => array(),
	'deletions'   => array(),
	'last_query'  => array(),
	'next_id'     => 100,
	'persist_meta_input'     => true,
	'identity_repair_result' => true,
	'legacy_identity_repair_result'     => true,
	'direct_identity_reservation_result'=> true,
	'delete_result'                     => true,
	'rollback_failures'                 => array(),
);

function apply_filters( $hook, $value ) {
	return $value;
}

function add_action() {
}

function do_action( $hook, ...$args ) {
	if ( 'lunara_graph_growth_identity_rollback_failed' === $hook ) {
		$GLOBALS['lunara_graph_test']['rollback_failures'][] = $args;
	}
}

function absint( $value ) {
	return abs( (int) $value );
}

function post_type_exists( $post_type ) {
	return 'movie' === $post_type;
}

function get_post_meta( $post_id, $key, $single = false ) {
	if ( isset( $GLOBALS['lunara_graph_test']['review_meta'][ $post_id ][ $key ] ) ) {
		return $GLOBALS['lunara_graph_test']['review_meta'][ $post_id ][ $key ];
	}

	return $GLOBALS['lunara_graph_test']['meta'][ $post_id ][ $key ] ?? '';
}

function get_post_stati() {
	return array(
		'publish' => 'publish',
		'draft'   => 'draft',
		'pending' => 'pending',
		'private' => 'private',
		'trash'   => 'trash',
	);
}

function get_posts( $args ) {
	$GLOBALS['lunara_graph_test']['last_query'] = $args;
	$matches = array();

	foreach ( $GLOBALS['lunara_graph_test']['posts'] as $post_id => $post ) {
		if ( ( $args['post_type'] ?? '' ) !== $post['post_type'] ) {
			continue;
		}

		if ( ! in_array( $post['post_status'], (array) ( $args['post_status'] ?? 'publish' ), true ) ) {
			continue;
		}

		$meta_match = false;
		foreach ( (array) ( $args['meta_query'] ?? array() ) as $condition ) {
			if ( ! is_array( $condition ) || empty( $condition['key'] ) ) {
				continue;
			}

			$actual = $GLOBALS['lunara_graph_test']['meta'][ $post_id ][ $condition['key'] ] ?? '';
			if ( (string) $actual === (string) ( $condition['value'] ?? '' ) ) {
				$meta_match = true;
				break;
			}
		}

		if ( $meta_match ) {
			$matches[] = $post_id;
		}
	}

	return array_slice( $matches, 0, (int) ( $args['posts_per_page'] ?? count( $matches ) ) );
}

function get_the_title( $post ) {
	$post_id = $post instanceof WP_Post ? $post->ID : (int) $post;
	return $GLOBALS['lunara_graph_test']['titles'][ $post_id ] ?? '';
}

function sanitize_text_field( $value ) {
	return trim( strip_tags( (string) $value ) );
}

function wp_insert_post( $postarr, $wp_error = false ) {
	$post_id = $GLOBALS['lunara_graph_test']['next_id']++;
	$GLOBALS['lunara_graph_test']['posts'][ $post_id ] = array(
		'post_type'   => $postarr['post_type'],
		'post_status' => $postarr['post_status'],
		'post_title'  => $postarr['post_title'],
	);
	if ( $GLOBALS['lunara_graph_test']['persist_meta_input'] ) {
		foreach ( (array) ( $postarr['meta_input'] ?? array() ) as $key => $value ) {
			$GLOBALS['lunara_graph_test']['meta'][ $post_id ][ $key ] = $value;
		}
	}
	$GLOBALS['lunara_graph_test']['insertions'][] = $post_id;
	return $post_id;
}

function is_wp_error( $value ) {
	return false;
}

function update_post_meta( $post_id, $key, $value ) {
	if ( 'imdb_title_id' === $key && ! $GLOBALS['lunara_graph_test']['identity_repair_result'] ) {
		return false;
	}
	if ( '_lunara_entity_id' === $key && ! $GLOBALS['lunara_graph_test']['legacy_identity_repair_result'] ) {
		return false;
	}
	$GLOBALS['lunara_graph_test']['meta'][ $post_id ][ $key ] = $value;
	return true;
}

function wp_delete_post( $post_id, $force_delete = false ) {
	$GLOBALS['lunara_graph_test']['deletions'][] = array( $post_id, $force_delete );
	if ( ! $GLOBALS['lunara_graph_test']['delete_result'] ) {
		return false;
	}
	unset(
		$GLOBALS['lunara_graph_test']['posts'][ $post_id ],
		$GLOBALS['lunara_graph_test']['meta'][ $post_id ]
	);
	return true;
}

function wp_cache_delete() {
	return true;
}

function lunara_graph_assert_same( $expected, $actual, $message ) {
	if ( $expected !== $actual ) {
		throw new RuntimeException(
			$message . "\nExpected: " . var_export( $expected, true ) . "\nActual: " . var_export( $actual, true )
		);
	}
}

function lunara_graph_assert_true( $condition, $message ) {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

require dirname( __DIR__ ) . '/includes/class-lunara-debrief-contract.php';
require dirname( __DIR__ ) . '/includes/class-lunara-graph-growth.php';

$GLOBALS['lunara_graph_test']['review_meta'][20]['_lunara_imdb_title_id'] = 'not-an-imdb-id';
$GLOBALS['lunara_graph_test']['titles'][20] = 'Invalid Review';
Lunara_Graph_Growth::maybe_grow_on_publish( 'publish', 'draft', new WP_Post( 20, 'review' ) );
lunara_graph_assert_same( array(), $GLOBALS['lunara_graph_test']['insertions'], 'Invalid IMDb input must never create a Movie.' );

$GLOBALS['lunara_graph_test']['review_meta'][21]['_lunara_imdb_title_id'] = 'https://www.imdb.com/title/tt123456/';
$GLOBALS['lunara_graph_test']['titles'][21] = 'Existing Six Digit Film';
Lunara_Graph_Growth::maybe_grow_on_publish( 'publish', 'draft', new WP_Post( 21, 'review' ) );
lunara_graph_assert_same( array(), $GLOBALS['lunara_graph_test']['insertions'], 'A six-digit canonical IMDb owner must block duplicate graph growth.' );

$GLOBALS['lunara_graph_test']['review_meta'][22]['_lunara_imdb_title_id'] = 'tt1234567';
$GLOBALS['lunara_graph_test']['titles'][22] = 'Legacy Film';
Lunara_Graph_Growth::maybe_grow_on_publish( 'publish', 'draft', new WP_Post( 22, 'review' ) );
lunara_graph_assert_same( array(), $GLOBALS['lunara_graph_test']['insertions'], 'A trashed legacy IMDb owner must block duplicate graph growth.' );
lunara_graph_assert_true(
	in_array( 'trash', $GLOBALS['lunara_graph_test']['last_query']['post_status'], true ),
	'Identity lookup must include trash so soft-deleted Movies remain reserved.'
);

$GLOBALS['lunara_graph_test']['review_meta'][23]['_lunara_imdb_title_id'] = 'Candidate tt123456789';
$GLOBALS['lunara_graph_test']['titles'][23] = '  <b>New Film</b> — The Review Headline Argument  ';
Lunara_Graph_Growth::maybe_grow_on_publish( 'publish', 'draft', new WP_Post( 23, 'review' ) );
lunara_graph_assert_same( 1, count( $GLOBALS['lunara_graph_test']['insertions'] ), 'An unknown nine-digit IMDb ID must create one draft Movie.' );

$created_id = $GLOBALS['lunara_graph_test']['insertions'][0];
lunara_graph_assert_same( 'draft', $GLOBALS['lunara_graph_test']['posts'][ $created_id ]['post_status'], 'Graph-grown Movies must remain drafts.' );
lunara_graph_assert_same( 'New Film', $GLOBALS['lunara_graph_test']['posts'][ $created_id ]['post_title'], 'Graph growth must keep only the film title, not the Review headline argument.' );
lunara_graph_assert_same( 'tt123456789', $GLOBALS['lunara_graph_test']['meta'][ $created_id ]['imdb_title_id'], 'Graph growth must store the canonical IMDb ID.' );
lunara_graph_assert_same( 23, $GLOBALS['lunara_graph_test']['meta'][ $created_id ]['_lunara_auto_grown_from'], 'Graph growth must retain the source Review ID.' );

Lunara_Graph_Growth::maybe_grow_on_publish( 'publish', 'draft', new WP_Post( 23, 'review' ) );
lunara_graph_assert_same( 1, count( $GLOBALS['lunara_graph_test']['insertions'] ), 'Repeated execution must not create a second Movie.' );
lunara_graph_assert_same( count( Lunara_Movie_Identity_Lock::$acquired ), count( Lunara_Movie_Identity_Lock::$released ), 'Every acquired graph-growth claim must be released, including early exits.' );

Lunara_Graph_Growth::maybe_grow_on_publish( 'publish', 'publish', new WP_Post( 23, 'review' ) );
Lunara_Graph_Growth::maybe_grow_on_publish( 'publish', 'draft', new WP_Post( 23, 'post' ) );
lunara_graph_assert_same( 1, count( $GLOBALS['lunara_graph_test']['insertions'] ), 'Republishes and non-Review posts must not grow the graph.' );

$GLOBALS['lunara_graph_test']['review_meta'][24]['_lunara_imdb_title_id'] = 'tt9876543';
$GLOBALS['lunara_graph_test']['titles'][24] = 'Concurrent Film';
Lunara_Movie_Identity_Lock::$busy = true;
Lunara_Graph_Growth::maybe_grow_on_publish( 'publish', 'draft', new WP_Post( 24, 'review' ) );
lunara_graph_assert_same( 1, count( $GLOBALS['lunara_graph_test']['insertions'] ), 'A busy canonical identity claim must block a concurrent graph-grown draft.' );
Lunara_Movie_Identity_Lock::$busy = false;
$releases_before_identity_repair = count( Lunara_Movie_Identity_Lock::$released );

$GLOBALS['lunara_graph_test']['persist_meta_input'] = false;
$GLOBALS['lunara_graph_test']['identity_repair_result'] = true;
$GLOBALS['lunara_graph_test']['review_meta'][25]['_lunara_imdb_title_id'] = 'tt9876544';
$GLOBALS['lunara_graph_test']['titles'][25] = 'Repairable Identity';
Lunara_Graph_Growth::maybe_grow_on_publish( 'publish', 'draft', new WP_Post( 25, 'review' ) );
$repaired_id = end( $GLOBALS['lunara_graph_test']['insertions'] );
lunara_graph_assert_same( 'tt9876544', $GLOBALS['lunara_graph_test']['meta'][ $repaired_id ]['imdb_title_id'], 'Graph growth must repair a missing meta_input identity before retaining the draft.' );

$GLOBALS['lunara_graph_test']['identity_repair_result'] = false;
$GLOBALS['lunara_graph_test']['legacy_identity_repair_result'] = false;
$GLOBALS['lunara_graph_test']['direct_identity_reservation_result'] = false;
$GLOBALS['lunara_graph_test']['review_meta'][26]['_lunara_imdb_title_id'] = 'tt9876545';
$GLOBALS['lunara_graph_test']['titles'][26] = 'Irreparable Identity';
Lunara_Graph_Growth::maybe_grow_on_publish( 'publish', 'draft', new WP_Post( 26, 'review' ) );
$failed_id = end( $GLOBALS['lunara_graph_test']['insertions'] );
lunara_graph_assert_true( ! isset( $GLOBALS['lunara_graph_test']['posts'][ $failed_id ] ), 'A new draft without a persistent canonical identity must be rolled back.' );
lunara_graph_assert_same( array( $failed_id, true ), end( $GLOBALS['lunara_graph_test']['deletions'] ), 'Identity rollback must permanently remove only the unusable new draft.' );

$GLOBALS['lunara_graph_test']['direct_identity_reservation_result'] = true;
$GLOBALS['lunara_graph_test']['delete_result'] = false;
$GLOBALS['lunara_graph_test']['review_meta'][27]['_lunara_imdb_title_id'] = 'tt9876546';
$GLOBALS['lunara_graph_test']['titles'][27] = 'Direct Reservation';
Lunara_Graph_Growth::maybe_grow_on_publish( 'publish', 'draft', new WP_Post( 27, 'review' ) );
$reserved_id = end( $GLOBALS['lunara_graph_test']['insertions'] );
lunara_graph_assert_same( 'tt9876546', $GLOBALS['lunara_graph_test']['meta'][ $reserved_id ]['_lunara_entity_id'], 'A filtered identity must fall back to a queryable direct legacy reservation.' );
lunara_graph_assert_true( isset( $GLOBALS['lunara_graph_test']['posts'][ $reserved_id ] ), 'A directly reserved draft must remain available for later editorial repair.' );

$GLOBALS['lunara_graph_test']['direct_identity_reservation_result'] = false;
$GLOBALS['lunara_graph_test']['review_meta'][28]['_lunara_imdb_title_id'] = 'tt9876547';
$GLOBALS['lunara_graph_test']['titles'][28] = 'Rollback Failure';
Lunara_Graph_Growth::maybe_grow_on_publish( 'publish', 'draft', new WP_Post( 28, 'review' ) );
$rollback_failed_id = end( $GLOBALS['lunara_graph_test']['insertions'] );
lunara_graph_assert_same( array( $rollback_failed_id, 'tt9876547', 28 ), end( $GLOBALS['lunara_graph_test']['rollback_failures'] ), 'A failed identity reservation and failed rollback must emit an observable failure action.' );
lunara_graph_assert_same( $releases_before_identity_repair + 4, count( Lunara_Movie_Identity_Lock::$released ), 'Identity repair, reservation, rollback, and failure paths must release every acquired graph-growth claim.' );

echo "Graph growth identity regression checks passed.\n";
