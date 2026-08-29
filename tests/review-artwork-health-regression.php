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
	'canonical_write_mode' => 'success',
	'throw_next_job_read' => false,
	'census_allowed'      => false,
	'census_calls'        => 0,
	'credential_loads'    => 0,
	'snapshot_writes'     => array(),
	'projection_writes'   => array(),
	'throw_snapshot'      => false,
	'snapshot_write_ok'   => true,
	'redirects'           => array(),
	'throw_redirect'      => false,
	'scheduled'           => array(),
	'cleared'             => array(),
	'transient'           => false,
	'image_refresh_calls' => 0,
	'throw_image_refresh' => false,
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
	if ( $GLOBALS['lunara_artwork_health_state']['throw_redirect'] ) {
		throw new RuntimeException( 'redirect stop' );
	}
	return true;
}

function get_option( $key, $default = false ) {
	if ( 'lunara_review_artwork_backfill_job' === $key && $GLOBALS['lunara_artwork_health_state']['throw_next_job_read'] ) {
		$GLOBALS['lunara_artwork_health_state']['throw_next_job_read'] = false;
		throw new RuntimeException( 'canonical readback failure' );
	}
	return array_key_exists( $key, $GLOBALS['lunara_artwork_health_state']['options'] )
		? $GLOBALS['lunara_artwork_health_state']['options'][ $key ]
		: $default;
}

function update_option( $key, $value, $autoload = null ) {
	$GLOBALS['lunara_artwork_health_state']['option_writes'][] = array( $key, $value, $autoload );
	if ( 'lunara_review_artwork_backfill_job' === $key ) {
		$mode = $GLOBALS['lunara_artwork_health_state']['canonical_write_mode'];
		if ( 'throw' === $mode ) {
			throw new RuntimeException( 'canonical write failure' );
		}
		if ( 'false_readback_throw' === $mode ) {
			$GLOBALS['lunara_artwork_health_state']['throw_next_job_read'] = true;
			return false;
		}
		if ( 'false_mismatch' === $mode || 'false_same' === $mode ) {
			return false;
		}
	}
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

function wp_clear_scheduled_hook( $hook ) {
	$GLOBALS['lunara_artwork_health_state']['cleared'][] = $hook;
	return true;
}
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
		if ( $GLOBALS['lunara_artwork_health_state']['throw_image_refresh'] ) {
			throw new RuntimeException( 'image refresh interruption' );
		}
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
$GLOBALS['lunara_artwork_health_state']['options']['lunara_review_artwork_backfill_job'] = $running_job;
$GLOBALS['lunara_artwork_health_state']['canonical_write_mode'] = 'false_mismatch';
$GLOBALS['lunara_artwork_health_state']['projection_writes'] = array();
$GLOBALS['lunara_artwork_health_state']['scheduled'] = array();
Lunara_Review_Artwork_Backfill::process_next();
lunara_artwork_health_assert_same( 0, $GLOBALS['lunara_artwork_health_state']['options']['lunara_review_artwork_backfill_job']['cursor'], 'A failed mismatched canonical write must preserve the stored cursor.' );
lunara_artwork_health_assert_same( array(), $GLOBALS['lunara_artwork_health_state']['projection_writes'], 'Compact progress must never advance beyond a failed canonical write.' );
lunara_artwork_health_assert_same( array(), $GLOBALS['lunara_artwork_health_state']['scheduled'], 'A failed canonical tick must not schedule another worker as though progress persisted.' );

$GLOBALS['lunara_artwork_health_state']['options']['lunara_review_artwork_backfill_job'] = $running_job;
$GLOBALS['lunara_artwork_health_state']['canonical_write_mode'] = 'false_readback_throw';
$GLOBALS['lunara_artwork_health_state']['projection_writes'] = array();
$GLOBALS['lunara_artwork_health_state']['scheduled'] = array();
Lunara_Review_Artwork_Backfill::process_next();
lunara_artwork_health_assert_same( 0, $GLOBALS['lunara_artwork_health_state']['options']['lunara_review_artwork_backfill_job']['cursor'], 'A throwing canonical readback must preserve the stored cursor.' );
lunara_artwork_health_assert_same( array(), $GLOBALS['lunara_artwork_health_state']['projection_writes'], 'A throwing canonical readback must suppress compact projection.' );
lunara_artwork_health_assert_same( array(), $GLOBALS['lunara_artwork_health_state']['scheduled'], 'A throwing canonical readback must suppress the next worker schedule.' );

$GLOBALS['lunara_artwork_health_state']['options']['lunara_review_artwork_backfill_job'] = $running_job;
$GLOBALS['lunara_artwork_health_state']['canonical_write_mode'] = 'throw';
$GLOBALS['lunara_artwork_health_state']['projection_writes'] = array();
$GLOBALS['lunara_artwork_health_state']['scheduled'] = array();
Lunara_Review_Artwork_Backfill::process_next();
lunara_artwork_health_assert_same( 0, $GLOBALS['lunara_artwork_health_state']['options']['lunara_review_artwork_backfill_job']['cursor'], 'A throwing canonical write must preserve the stored cursor.' );
lunara_artwork_health_assert_same( array(), $GLOBALS['lunara_artwork_health_state']['projection_writes'], 'A throwing canonical write must suppress compact projection.' );
lunara_artwork_health_assert_same( array(), $GLOBALS['lunara_artwork_health_state']['scheduled'], 'A throwing canonical write must suppress the next worker schedule.' );

$GLOBALS['lunara_artwork_health_state']['canonical_write_mode'] = 'false_same';
$GLOBALS['lunara_artwork_health_state']['projection_writes'] = array();
$save_job = new ReflectionMethod( 'Lunara_Review_Artwork_Backfill', 'save_job' );
$save_job->setAccessible( true );
$same_value_saved = $save_job->invoke( null, $running_job );
lunara_artwork_health_assert_same( true, $same_value_saved, 'A false canonical update with exact readback must remain a verified same-value success.' );
lunara_artwork_health_assert_same( 1, count( $GLOBALS['lunara_artwork_health_state']['projection_writes'] ), 'Verified same-value canonical state may update its compact projection.' );
$GLOBALS['lunara_artwork_health_state']['canonical_write_mode'] = 'success';

$stale_complete = $running_job;
$stale_complete['ids']          = array( 17 );
$stale_complete['cursor']       = 1;
$stale_complete['processed']    = 1;
$stale_complete['total']        = 1;
$stale_complete['status']       = 'complete';
$stale_complete['completed_at'] = time();
$stale_complete['counts']['ready'] = 1;
$GLOBALS['lunara_artwork_health_state']['options']['lunara_review_artwork_backfill_job'] = $stale_complete;
$GLOBALS['lunara_artwork_health_state']['option_writes'] = array();
$GLOBALS['lunara_artwork_health_state']['projection_writes'] = array();
$GLOBALS['lunara_artwork_health_state']['cleared'] = array();
$GLOBALS['lunara_artwork_health_state']['throw_redirect'] = true;
try {
	Lunara_Review_Artwork_Backfill::handle_pause();
} catch ( RuntimeException $error ) {
	lunara_artwork_health_assert_same( 'redirect stop', $error->getMessage(), 'Pause handler fixture must stop at its redirect.' );
}
lunara_artwork_health_assert_same( $stale_complete, $GLOBALS['lunara_artwork_health_state']['options']['lunara_review_artwork_backfill_job'], 'A stale pause POST must not change an already-complete canonical job.' );
lunara_artwork_health_assert_same( array(), $GLOBALS['lunara_artwork_health_state']['projection_writes'], 'A stale pause POST must not publish an impossible paused completion.' );
lunara_artwork_health_assert_same( array(), $GLOBALS['lunara_artwork_health_state']['cleared'], 'A stale pause POST must not clear schedules for a transition it did not own.' );

$already_running = $running_job;
$already_running['cursor']          = 1;
$already_running['processed']       = 1;
$already_running['counts']['ready'] = 1;
$GLOBALS['lunara_artwork_health_state']['options']['lunara_review_artwork_backfill_job'] = $already_running;
$GLOBALS['lunara_artwork_health_state']['option_writes'] = array();
$GLOBALS['lunara_artwork_health_state']['snapshot_writes'] = array();
$GLOBALS['lunara_artwork_health_state']['scheduled'] = array();
$GLOBALS['lunara_artwork_health_state']['census_allowed'] = true;
try {
	Lunara_Review_Artwork_Backfill::handle_start();
} catch ( RuntimeException $error ) {
	lunara_artwork_health_assert_same( 'redirect stop', $error->getMessage(), 'Start handler fixture must stop at its redirect.' );
}
lunara_artwork_health_assert_same( $already_running, $GLOBALS['lunara_artwork_health_state']['options']['lunara_review_artwork_backfill_job'], 'A stale start POST must not reset or replace an already-running canonical job.' );
lunara_artwork_health_assert_same( array(), $GLOBALS['lunara_artwork_health_state']['snapshot_writes'], 'A stale start POST must not publish replacement full health.' );
lunara_artwork_health_assert_same( array(), $GLOBALS['lunara_artwork_health_state']['scheduled'], 'A stale start POST must not claim a new transition by scheduling work.' );

$GLOBALS['lunara_artwork_health_state']['options']['lunara_review_artwork_backfill_job'] = $running_job;
$GLOBALS['lunara_artwork_health_state']['canonical_write_mode'] = 'false_mismatch';
$GLOBALS['lunara_artwork_health_state']['projection_writes'] = array();
$GLOBALS['lunara_artwork_health_state']['cleared'] = array();
try {
	Lunara_Review_Artwork_Backfill::handle_pause();
} catch ( RuntimeException $error ) {
	lunara_artwork_health_assert_same( 'redirect stop', $error->getMessage(), 'Failed pause fixture must stop at its redirect.' );
}
lunara_artwork_health_assert_same( 'running', $GLOBALS['lunara_artwork_health_state']['options']['lunara_review_artwork_backfill_job']['status'], 'A failed canonical pause write must leave the owner job running.' );
lunara_artwork_health_assert_same( array(), $GLOBALS['lunara_artwork_health_state']['projection_writes'], 'A failed canonical pause must not publish compact paused state.' );
lunara_artwork_health_assert_same( array(), $GLOBALS['lunara_artwork_health_state']['cleared'], 'A failed canonical pause must not clear the worker schedule.' );

$GLOBALS['lunara_artwork_health_state']['canonical_write_mode'] = 'success';
$GLOBALS['lunara_artwork_health_state']['options']['lunara_review_artwork_backfill_job'] = $running_job;
$GLOBALS['lunara_artwork_health_state']['cleared'] = array();
try {
	Lunara_Review_Artwork_Backfill::handle_pause();
} catch ( RuntimeException $error ) {
	lunara_artwork_health_assert_same( 'redirect stop', $error->getMessage(), 'Verified pause fixture must stop at its redirect.' );
}
lunara_artwork_health_assert_same( 'paused', $GLOBALS['lunara_artwork_health_state']['options']['lunara_review_artwork_backfill_job']['status'], 'A verified running job must still pause normally.' );
lunara_artwork_health_assert_same( 0, $GLOBALS['lunara_artwork_health_state']['options']['lunara_review_artwork_backfill_job']['completed_at'], 'A normal pause must preserve an active zero completion time.' );
lunara_artwork_health_assert_same( array( 'lunara_review_artwork_backfill_tick' ), $GLOBALS['lunara_artwork_health_state']['cleared'], 'A verified pause must clear the scheduled worker.' );

$paused_resume = $already_running;
$paused_resume['status'] = 'paused';
$GLOBALS['lunara_artwork_health_state']['options']['lunara_review_artwork_backfill_job'] = $paused_resume;
$GLOBALS['lunara_artwork_health_state']['scheduled'] = array();
try {
	Lunara_Review_Artwork_Backfill::handle_start();
} catch ( RuntimeException $error ) {
	lunara_artwork_health_assert_same( 'redirect stop', $error->getMessage(), 'Resume fixture must stop at its redirect.' );
}
$resumed = $GLOBALS['lunara_artwork_health_state']['options']['lunara_review_artwork_backfill_job'];
lunara_artwork_health_assert_same( 'running', $resumed['status'], 'A verified paused job must still resume normally.' );
lunara_artwork_health_assert_same( 1, $resumed['cursor'], 'Resume must preserve canonical cursor progress.' );
lunara_artwork_health_assert_same( array( 17, 18 ), $resumed['ids'], 'Resume must preserve the canonical work queue.' );
lunara_artwork_health_assert_same( 1, count( $GLOBALS['lunara_artwork_health_state']['scheduled'] ), 'A verified resume must schedule the next worker.' );
$GLOBALS['lunara_artwork_health_state']['throw_redirect'] = false;

$GLOBALS['lunara_artwork_health_state']['options']['lunara_review_artwork_backfill_job'] = $running_job;
$GLOBALS['lunara_artwork_health_state']['projection_writes'] = array();
$GLOBALS['lunara_artwork_health_state']['throw_image_refresh'] = true;
Lunara_Review_Artwork_Backfill::process_next();
$interrupted_job = $GLOBALS['lunara_artwork_health_state']['options']['lunara_review_artwork_backfill_job'];
lunara_artwork_health_assert_same( 'paused', $interrupted_job['status'], 'An interrupted owner refresh must pause without discarding its queued Review.' );
lunara_artwork_health_assert_same( 0, $interrupted_job['processed'], 'An interrupted unprocessed Review must not advance canonical progress.' );
lunara_artwork_health_assert_same( 0, $interrupted_job['counts']['errors'], 'Infrastructure interruption must not create an error count beyond processed work.' );
lunara_artwork_health_assert_same( 1, count( $GLOBALS['lunara_artwork_health_state']['projection_writes'] ), 'A verified interrupted canonical pause must still project coherent compact state.' );
$GLOBALS['lunara_artwork_health_state']['throw_image_refresh'] = false;

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
