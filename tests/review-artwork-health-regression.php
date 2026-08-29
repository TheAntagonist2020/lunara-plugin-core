<?php
/**
 * Runtime contracts for the owner-side compact artwork health workflow.
 *
 * Run with: php tests/review-artwork-health-regression.php
 */

define( 'ABSPATH', __DIR__ . '/' );
define( 'MINUTE_IN_SECONDS', 60 );

$root     = dirname( __DIR__ );
$backfill = $root . '/includes/class-lunara-review-artwork-backfill.php';

$GLOBALS['lunara_artwork_health_state'] = array(
	'actions'             => array(),
	'capability'          => true,
	'capability_calls'    => 0,
	'nonce_valid'         => true,
	'nonce_calls'         => 0,
	'options'             => array(),
	'option_writes'       => array(),
	'census_allowed'      => false,
	'census_calls'        => 0,
	'credential_loads'    => 0,
	'snapshot_writes'     => array(),
	'projection_writes'   => array(),
	'throw_snapshot'      => false,
	'snapshot_write_ok'   => true,
	'redirects'           => array(),
	'scheduled'           => array(),
	'transient'           => false,
	'image_refresh_calls' => 0,
);

function __( $text ) { return $text; }
function esc_html__( $text ) { return $text; }
function esc_html_e( $text ) { echo $text; }
function esc_html( $value ) { return htmlspecialchars( (string) $value, ENT_QUOTES, 'UTF-8' ); }
function esc_url( $value ) { return (string) $value; }
function esc_attr( $value ) { return (string) $value; }
function submit_button( $text ) { echo '<button>' . esc_html( $text ) . '</button>'; }
function wp_nonce_field( $action ) { echo '<input type="hidden" name="_wpnonce" value="fixture">'; }
function wp_parse_args( $args, $defaults ) { return array_merge( $defaults, is_array( $args ) ? $args : array() ); }
function absint( $value ) { return abs( (int) $value ); }
function sanitize_key( $value ) { return strtolower( preg_replace( '/[^a-z0-9_\-]/', '', (string) $value ) ); }
function sanitize_text_field( $value ) { return trim( strip_tags( (string) $value ) ); }
function is_admin() { return true; }

function add_action( $hook, $callback, $priority = 10, $accepted_args = 1 ) {
	$GLOBALS['lunara_artwork_health_state']['actions'][] = array( $hook, $callback, $priority, $accepted_args );
}

function add_submenu_page() {}

function current_user_can( $capability ) {
	$GLOBALS['lunara_artwork_health_state']['capability_calls']++;
	return $GLOBALS['lunara_artwork_health_state']['capability'] && 'edit_others_posts' === $capability;
}

function check_admin_referer( $action ) {
	$GLOBALS['lunara_artwork_health_state']['nonce_calls']++;
	if ( ! $GLOBALS['lunara_artwork_health_state']['nonce_valid'] ) {
		throw new RuntimeException( 'invalid nonce' );
	}
	return true;
}

function wp_die( $message ) {
	throw new RuntimeException( (string) $message );
}

function admin_url( $path = '' ) {
	return 'https://example.test/wp-admin/' . ltrim( (string) $path, '/' );
}

function add_query_arg( $key, $value, $url ) {
	return $url . ( false === strpos( $url, '?' ) ? '?' : '&' ) . rawurlencode( $key ) . '=' . rawurlencode( $value );
}

function wp_safe_redirect( $url ) {
	$GLOBALS['lunara_artwork_health_state']['redirects'][] = $url;
	return true;
}

function get_option( $key, $default = false ) {
	return array_key_exists( $key, $GLOBALS['lunara_artwork_health_state']['options'] )
		? $GLOBALS['lunara_artwork_health_state']['options'][ $key ]
		: $default;
}

function update_option( $key, $value, $autoload = null ) {
	$GLOBALS['lunara_artwork_health_state']['option_writes'][] = array( $key, $value, $autoload );
	$GLOBALS['lunara_artwork_health_state']['options'][ $key ] = $value;
	return true;
}

function get_posts() {
	$GLOBALS['lunara_artwork_health_state']['census_calls']++;
	if ( ! $GLOBALS['lunara_artwork_health_state']['census_allowed'] ) {
		throw new RuntimeException( 'A census was attempted outside an explicit refresh/start/completion seam.' );
	}
	return array( 17, 18 );
}

function get_post_meta( $review_id, $key ) {
	return 17 === $review_id ? 'tt1234567' : '';
}

function get_edit_post_link( $review_id ) { return 'https://example.test/wp-admin/post.php?post=' . (int) $review_id; }
function get_the_title( $review_id ) { return 'Fixture ' . (int) $review_id; }

function get_transient() {
	return $GLOBALS['lunara_artwork_health_state']['transient'];
}

function set_transient() {
	$GLOBALS['lunara_artwork_health_state']['transient'] = true;
	return true;
}

function delete_transient() {
	$GLOBALS['lunara_artwork_health_state']['transient'] = false;
	return true;
}

function wp_clear_scheduled_hook() { return true; }
function wp_next_scheduled() { return false; }
function wp_schedule_single_event( $timestamp, $hook ) {
	$GLOBALS['lunara_artwork_health_state']['scheduled'][] = array( $timestamp, $hook );
	return true;
}

final class Lunara_Core_Site_Studio_Bridge {
	public static function review_identity_artwork_status() {
		return array(
			'coverage'    => array( 'total' => null, 'identity_ready' => null, 'missing_identity' => null, 'missing_poster' => null, 'missing_backdrop' => null, 'custom_protected' => null ),
			'credentials' => array( 'known' => false, 'omdb' => false, 'tmdb' => false, 'ready' => false ),
			'snapshot'    => array( 'state' => 'missing', 'generated_at' => null, 'expires_at' => null ),
		);
	}

	public static function write_artwork_health_snapshot( $coverage, $job, $credentials ) {
		$GLOBALS['lunara_artwork_health_state']['snapshot_writes'][] = array( $coverage, $job, $credentials );
		if ( $GLOBALS['lunara_artwork_health_state']['throw_snapshot'] ) {
			throw new RuntimeException( 'snapshot failure' );
		}
		return $GLOBALS['lunara_artwork_health_state']['snapshot_write_ok'];
	}

	public static function update_artwork_health_job_projection( $job ) {
		$GLOBALS['lunara_artwork_health_state']['projection_writes'][] = $job;
		if ( $GLOBALS['lunara_artwork_health_state']['throw_snapshot'] ) {
			throw new RuntimeException( 'snapshot projection failure' );
		}
		return true;
	}
}

final class Lunara_Core {
	public static function load_movie_provider_gateway() {
		$GLOBALS['lunara_artwork_health_state']['credential_loads']++;
		if ( ! class_exists( 'Lunara_Movie_Provider_Gateway', false ) ) {
			eval( 'final class Lunara_Movie_Provider_Gateway { public function credentials_status() { return array( "omdb" => true, "tmdb" => true, "ready" => true ); } }' );
		}
	}

	public static function load_movie_importer() {
		throw new RuntimeException( 'Artwork health must not load the broad importer/admin stack.' );
	}
}

final class Lunara_Review_Image_Studio {
	public static function resolve_slot( $review_id, $slot ) {
		if ( 17 === $review_id && 'card' === $slot ) {
			return array( 'url' => 'poster', 'mode' => 'custom' );
		}
		return array( 'url' => '', 'mode' => 'auto' );
	}

	public static function refresh_review_artwork( $review_id ) {
		$GLOBALS['lunara_artwork_health_state']['image_refresh_calls']++;
		return array( 'status' => 'ready', 'poster' => 'ready', 'backdrop' => 'ready', 'conflicts' => array() );
	}
}

function lunara_artwork_health_assert( $condition, $message ) {
	if ( ! $condition ) {
		fwrite( STDERR, "Assertion failed: {$message}\n" );
		exit( 1 );
	}
}

function lunara_artwork_health_assert_same( $expected, $actual, $message ) {
	if ( $expected !== $actual ) {
		fwrite( STDERR, "Assertion failed: {$message}\nExpected: " . var_export( $expected, true ) . "\nActual: " . var_export( $actual, true ) . "\n" );
		exit( 1 );
	}
}

require $backfill;

Lunara_Review_Artwork_Backfill::init();
lunara_artwork_health_assert(
	in_array( array( 'admin_post_lunara_review_artwork_health_refresh', array( 'Lunara_Review_Artwork_Backfill', 'handle_refresh_health' ), 10, 1 ), $GLOBALS['lunara_artwork_health_state']['actions'], true ),
	'Artwork Audit must register the explicit compact health refresh action.'
);

$GLOBALS['lunara_artwork_health_state']['census_calls']     = 0;
$GLOBALS['lunara_artwork_health_state']['credential_loads'] = 0;
$GLOBALS['lunara_artwork_health_state']['snapshot_writes']  = array();
ob_start();
Lunara_Review_Artwork_Backfill::render_page();
$rendered = ob_get_clean();
lunara_artwork_health_assert( false !== strpos( $rendered, 'Refresh health snapshot' ), 'Artwork Audit must show the explicit refresh control.' );
lunara_artwork_health_assert_same( 0, $GLOBALS['lunara_artwork_health_state']['census_calls'], 'Viewing Artwork Audit must not run a full census.' );
lunara_artwork_health_assert_same( 0, $GLOBALS['lunara_artwork_health_state']['credential_loads'], 'Viewing Artwork Audit must not inspect credentials.' );
lunara_artwork_health_assert_same( array(), $GLOBALS['lunara_artwork_health_state']['snapshot_writes'], 'Viewing Artwork Audit must not mutate the compact snapshot.' );

$GLOBALS['lunara_artwork_health_state']['capability']       = false;
$GLOBALS['lunara_artwork_health_state']['nonce_calls']      = 0;
$GLOBALS['lunara_artwork_health_state']['census_calls']     = 0;
$GLOBALS['lunara_artwork_health_state']['snapshot_writes']  = array();
try {
	Lunara_Review_Artwork_Backfill::handle_refresh_health();
	lunara_artwork_health_assert( false, 'Denied refresh must stop.' );
} catch ( RuntimeException $error ) {
	lunara_artwork_health_assert_same( 'You do not have permission to manage Review artwork.', $error->getMessage(), 'Denied refresh must fail with the fixed owner message.' );
}
lunara_artwork_health_assert_same( 0, $GLOBALS['lunara_artwork_health_state']['nonce_calls'], 'Refresh capability must be enforced before nonce validation.' );
lunara_artwork_health_assert_same( 0, $GLOBALS['lunara_artwork_health_state']['census_calls'], 'Denied refresh must not run a census.' );
lunara_artwork_health_assert_same( array(), $GLOBALS['lunara_artwork_health_state']['snapshot_writes'], 'Denied refresh must not write a snapshot.' );

$GLOBALS['lunara_artwork_health_state']['capability']  = true;
$GLOBALS['lunara_artwork_health_state']['nonce_valid'] = false;
try {
	Lunara_Review_Artwork_Backfill::handle_refresh_health();
	lunara_artwork_health_assert( false, 'Invalid nonce refresh must stop.' );
} catch ( RuntimeException $error ) {
	lunara_artwork_health_assert_same( 'invalid nonce', $error->getMessage(), 'Refresh must use the owner nonce guard.' );
}
lunara_artwork_health_assert_same( 0, $GLOBALS['lunara_artwork_health_state']['census_calls'], 'Invalid nonce refresh must not run a census.' );

$GLOBALS['lunara_artwork_health_state']['nonce_valid']      = true;
$GLOBALS['lunara_artwork_health_state']['census_allowed']   = true;
$GLOBALS['lunara_artwork_health_state']['snapshot_write_ok'] = true;
$GLOBALS['lunara_artwork_health_state']['redirects']        = array();
Lunara_Review_Artwork_Backfill::handle_refresh_health();
lunara_artwork_health_assert_same( 1, $GLOBALS['lunara_artwork_health_state']['census_calls'], 'Explicit refresh must run exactly one owner-side census.' );
lunara_artwork_health_assert_same( 1, $GLOBALS['lunara_artwork_health_state']['credential_loads'], 'Explicit refresh must load only the narrow gateway once.' );
lunara_artwork_health_assert_same( 1, count( $GLOBALS['lunara_artwork_health_state']['snapshot_writes'] ), 'Explicit refresh must write one compact snapshot.' );
lunara_artwork_health_assert( false !== strpos( end( $GLOBALS['lunara_artwork_health_state']['redirects'] ), 'health_snapshot=refreshed' ), 'Verified refresh must redirect with the redacted refreshed result.' );

$GLOBALS['lunara_artwork_health_state']['snapshot_write_ok'] = false;
$GLOBALS['lunara_artwork_health_state']['redirects']         = array();
Lunara_Review_Artwork_Backfill::handle_refresh_health();
lunara_artwork_health_assert( false !== strpos( end( $GLOBALS['lunara_artwork_health_state']['redirects'] ), 'health_snapshot=failed' ), 'Failed refresh writes must never report fresh.' );
$GLOBALS['lunara_artwork_health_state']['snapshot_write_ok'] = true;

$running_job = array(
	'status'       => 'running',
	'ids'          => array( 17, 18 ),
	'cursor'       => 0,
	'processed'    => 0,
	'total'        => 2,
	'started_at'   => time() - 20,
	'completed_at' => 0,
	'last_error'   => '',
	'counts'       => array( 'ready' => 0, 'partial' => 0, 'conflicts' => 0, 'errors' => 0 ),
	'recent'       => array(),
);
$GLOBALS['lunara_artwork_health_state']['options']['lunara_review_artwork_backfill_job'] = $running_job;
$GLOBALS['lunara_artwork_health_state']['option_writes']      = array();
$GLOBALS['lunara_artwork_health_state']['projection_writes']  = array();
$GLOBALS['lunara_artwork_health_state']['snapshot_writes']    = array();
$GLOBALS['lunara_artwork_health_state']['census_allowed']     = false;
$GLOBALS['lunara_artwork_health_state']['census_calls']       = 0;
$GLOBALS['lunara_artwork_health_state']['credential_loads']   = 0;
$GLOBALS['lunara_artwork_health_state']['throw_snapshot']     = true;
Lunara_Review_Artwork_Backfill::process_next();
lunara_artwork_health_assert_same( 1, count( $GLOBALS['lunara_artwork_health_state']['option_writes'] ), 'Intermediate ticks must preserve the canonical full-job write.' );
$saved_job = $GLOBALS['lunara_artwork_health_state']['option_writes'][0][1];
lunara_artwork_health_assert_same( 1, $saved_job['cursor'], 'Snapshot failure must not interrupt canonical job progress.' );
lunara_artwork_health_assert_same( 1, count( $GLOBALS['lunara_artwork_health_state']['projection_writes'] ), 'Intermediate ticks must attempt only the compact in-memory job projection.' );
lunara_artwork_health_assert_same( 0, $GLOBALS['lunara_artwork_health_state']['census_calls'], 'Intermediate ticks must never run a census.' );
lunara_artwork_health_assert_same( 0, $GLOBALS['lunara_artwork_health_state']['credential_loads'], 'Intermediate ticks must never inspect credentials.' );

$GLOBALS['lunara_artwork_health_state']['throw_snapshot'] = false;
$complete_job = $running_job;
$complete_job['ids']   = array( 17 );
$complete_job['total'] = 1;
$GLOBALS['lunara_artwork_health_state']['options']['lunara_review_artwork_backfill_job'] = $complete_job;
$GLOBALS['lunara_artwork_health_state']['option_writes']     = array();
$GLOBALS['lunara_artwork_health_state']['snapshot_writes']   = array();
$GLOBALS['lunara_artwork_health_state']['census_allowed']    = true;
$GLOBALS['lunara_artwork_health_state']['census_calls']      = 0;
Lunara_Review_Artwork_Backfill::process_next();
lunara_artwork_health_assert_same( 'complete', $GLOBALS['lunara_artwork_health_state']['option_writes'][0][1]['status'], 'Completion seam must persist the canonical complete job first.' );
lunara_artwork_health_assert_same( 1, $GLOBALS['lunara_artwork_health_state']['census_calls'], 'Completion seam may refresh full coverage exactly once.' );
lunara_artwork_health_assert_same( 1, count( $GLOBALS['lunara_artwork_health_state']['snapshot_writes'] ), 'Completion seam must refresh one compact full projection.' );

$GLOBALS['lunara_artwork_health_state']['options']['lunara_review_artwork_backfill_job'] = $complete_job;
$GLOBALS['lunara_artwork_health_state']['option_writes']   = array();
$GLOBALS['lunara_artwork_health_state']['snapshot_writes'] = array();
$GLOBALS['lunara_artwork_health_state']['census_calls']    = 0;
$GLOBALS['lunara_artwork_health_state']['throw_snapshot']  = true;
Lunara_Review_Artwork_Backfill::process_next();
lunara_artwork_health_assert_same( 'complete', $GLOBALS['lunara_artwork_health_state']['option_writes'][0][1]['status'], 'Full snapshot failure must not interrupt the canonical completion job write.' );
lunara_artwork_health_assert_same( 1, count( $GLOBALS['lunara_artwork_health_state']['snapshot_writes'] ), 'Completion must attempt its compact full projection even when that projection fails.' );
$GLOBALS['lunara_artwork_health_state']['throw_snapshot'] = false;

$source = file_get_contents( $backfill );
lunara_artwork_health_assert_same( 1, substr_count( $source, 'update_option( self::OPTION_KEY' ), 'Every full-job write must be centralized behind one persistence seam.' );
lunara_artwork_health_assert( false === strpos( $source, 'load_movie_importer' ), 'Artwork health must not load the broad importer/admin stack.' );

echo "Review artwork health regression checks passed.\n";
