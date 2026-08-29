<?php
/**
 * Dependency-free contracts for the Core-owned Site Studio bridge.
 *
 * Run with: php tests/site-studio-bridge-regression.php
 */

define( 'ABSPATH', __DIR__ . '/' );
define( 'LUNARA_CORE_VERSION', '0.8.10' );

$root      = dirname( __DIR__ );
$bridge    = $root . '/includes/class-lunara-core-site-studio-bridge.php';
$bootstrap = file_get_contents( $root . '/lunara-core.php' );
$workflow  = file_get_contents( $root . '/.github/workflows/lint.yml' );

$GLOBALS['lunara_core_bridge_state'] = array(
	'capabilities'     => array(),
	'capability_calls' => array(),
	'edit_link_calls'  => array(),
	'edit_links'       => array(),
	'filters'          => array(),
	'posts'            => array(),
	'post_types'       => array(),
);

function __( $text ) {
	return $text;
}

function absint( $value ) {
	return abs( (int) $value );
}

function sanitize_key( $value ) {
	return strtolower( preg_replace( '/[^a-z0-9_\-]/', '', (string) $value ) );
}

function admin_url( $path = '' ) {
	return 'https://example.test/wp-admin/' . ltrim( (string) $path, '/' );
}

function add_filter( $hook, $callback, $priority = 10, $accepted_args = 1 ) {
	$GLOBALS['lunara_core_bridge_state']['filters'][] = array( $hook, $callback, $priority, $accepted_args );
}

function current_user_can( $capability, $object_id = 0 ) {
	$GLOBALS['lunara_core_bridge_state']['capability_calls'][] = array( $capability, $object_id );
	$key = $object_id ? $capability . ':' . absint( $object_id ) : $capability;
	return ! empty( $GLOBALS['lunara_core_bridge_state']['capabilities'][ $key ] );
}

function post_type_exists( $post_type ) {
	return ! empty( $GLOBALS['lunara_core_bridge_state']['post_types'][ $post_type ] );
}

function get_post( $post_id ) {
	return isset( $GLOBALS['lunara_core_bridge_state']['posts'][ $post_id ] )
		? $GLOBALS['lunara_core_bridge_state']['posts'][ $post_id ]
		: null;
}

function get_edit_post_link( $post_id, $context = 'display' ) {
	$GLOBALS['lunara_core_bridge_state']['edit_link_calls'][] = array( $post_id, $context );
	return isset( $GLOBALS['lunara_core_bridge_state']['edit_links'][ $post_id ] )
		? $GLOBALS['lunara_core_bridge_state']['edit_links'][ $post_id ]
		: '';
}

function get_posts() {
	throw new RuntimeException( 'The redacted bridge must not query Review records.' );
}

function get_post_meta() {
	throw new RuntimeException( 'The redacted bridge must not read Review metadata.' );
}

function get_the_title() {
	throw new RuntimeException( 'The redacted bridge must not read Review titles.' );
}

final class WP_Post {
	public $ID;
	public $post_type;

	public function __construct( $post_id, $post_type ) {
		$this->ID        = $post_id;
		$this->post_type = $post_type;
	}
}

function lunara_core_bridge_assert( $condition, $message ) {
	if ( ! $condition ) {
		fwrite( STDERR, "Assertion failed: {$message}\n" );
		exit( 1 );
	}
}

function lunara_core_bridge_assert_same( $expected, $actual, $message ) {
	if ( $expected !== $actual ) {
		fwrite( STDERR, "Assertion failed: {$message}\nExpected: " . var_export( $expected, true ) . "\nActual: " . var_export( $actual, true ) . "\n" );
		exit( 1 );
	}
}

lunara_core_bridge_assert( file_exists( $bridge ), 'Core must ship the always-loaded Site Studio bridge module.' );
lunara_core_bridge_assert( false !== strpos( $bootstrap, 'Version: 0.8.10' ), 'Core must identify the Site Studio health release as 0.8.10.' );
lunara_core_bridge_assert( false !== strpos( $bootstrap, "define( 'LUNARA_CORE_VERSION', '0.8.10' );" ), 'Core runtime identity must match the 0.8.10 plugin header.' );
lunara_core_bridge_assert( false !== strpos( $bootstrap, "includes/class-lunara-core-site-studio-bridge.php" ), 'Core must require the bridge outside its admin-only bootstrap.' );
lunara_core_bridge_assert( false !== strpos( $bootstrap, 'Lunara_Core_Site_Studio_Bridge::init();' ), 'Core must register its inert Site Studio filter during every normal bootstrap.' );
lunara_core_bridge_assert( false !== strpos( $workflow, 'php tests/site-studio-bridge-regression.php' ), 'CI must run the Site Studio bridge contract.' );

require $bridge;

lunara_core_bridge_assert( function_exists( 'lunara_core_review_studio_admin_url' ), 'Core must expose a stable Review Studio URL helper.' );
lunara_core_bridge_assert(
	'https://example.test/wp-admin/edit.php?post_type=review' === lunara_core_review_studio_admin_url(),
	'The default Review Studio handoff must be the Review Library chooser.'
);

$fallback = 'https://example.test/wp-admin/edit.php?post_type=review';
lunara_core_bridge_assert( $fallback === lunara_core_review_studio_admin_url( 0, 'new' ), 'New Review must fall back safely when the user cannot create Reviews.' );
$GLOBALS['lunara_core_bridge_state']['capabilities']['edit_posts'] = true;
lunara_core_bridge_assert(
	'https://example.test/wp-admin/post-new.php?post_type=review#lunara-review-workspace' === lunara_core_review_studio_admin_url( 0, 'new' ),
	'Authorized New Review handoffs must open the Review workspace.'
);
lunara_core_bridge_assert( $fallback === lunara_core_review_studio_admin_url( 0, 'new?redirect=https://evil.test' ), 'Unknown or injected tabs must use the chooser fallback.' );
lunara_core_bridge_assert( $fallback === lunara_core_review_studio_admin_url( array( 17 ), array( 'new' ) ), 'Non-scalar handoff arguments must use the chooser fallback without warnings.' );

$GLOBALS['lunara_core_bridge_state']['posts'][17]                   = new WP_Post( 17, 'review' );
$GLOBALS['lunara_core_bridge_state']['edit_links'][17]              = 'https://example.test/wp-admin/post.php?post=17&action=edit';
$GLOBALS['lunara_core_bridge_state']['capabilities']['edit_post:17'] = true;
lunara_core_bridge_assert(
	'https://example.test/wp-admin/post.php?post=17&action=edit#lunara-review-workspace' === lunara_core_review_studio_admin_url( '17', 'new' ),
	'An editable Review must use its raw edit link and fixed Review workspace anchor.'
);
lunara_core_bridge_assert(
	in_array( array( 17, 'raw' ), $GLOBALS['lunara_core_bridge_state']['edit_link_calls'], true ),
	'Editable Review handoffs must request the raw edit link.'
);

$GLOBALS['lunara_core_bridge_state']['posts'][18]      = new WP_Post( 18, 'post' );
$GLOBALS['lunara_core_bridge_state']['edit_links'][18] = 'https://example.test/wp-admin/post.php?post=18&action=edit';
$GLOBALS['lunara_core_bridge_state']['capabilities']['edit_post:18'] = true;
$calls_before = count( $GLOBALS['lunara_core_bridge_state']['edit_link_calls'] );
lunara_core_bridge_assert( $fallback === lunara_core_review_studio_admin_url( 18 ), 'A non-Review ID must use the same chooser fallback.' );
lunara_core_bridge_assert( $calls_before === count( $GLOBALS['lunara_core_bridge_state']['edit_link_calls'] ), 'A non-Review ID must not resolve an edit link.' );

$calls_before = count( $GLOBALS['lunara_core_bridge_state']['edit_link_calls'] );
lunara_core_bridge_assert( $fallback === lunara_core_review_studio_admin_url( 19 ), 'An invalid ID must use the same chooser fallback.' );
lunara_core_bridge_assert( $calls_before === count( $GLOBALS['lunara_core_bridge_state']['edit_link_calls'] ), 'An invalid ID must not resolve an edit link.' );

$GLOBALS['lunara_core_bridge_state']['posts'][20]      = new WP_Post( 20, 'review' );
$GLOBALS['lunara_core_bridge_state']['edit_links'][20] = 'https://example.test/wp-admin/post.php?post=20&action=edit';
$calls_before = count( $GLOBALS['lunara_core_bridge_state']['edit_link_calls'] );
lunara_core_bridge_assert( $fallback === lunara_core_review_studio_admin_url( 20 ), 'An inaccessible Review must be indistinguishable from an invalid ID.' );
lunara_core_bridge_assert( $calls_before === count( $GLOBALS['lunara_core_bridge_state']['edit_link_calls'] ), 'An inaccessible Review must not resolve an edit link.' );
lunara_core_bridge_assert(
	in_array( array( 'edit_post', 20 ), $GLOBALS['lunara_core_bridge_state']['capability_calls'], true ),
	'Selected Review handoffs must enforce the exact edit_post meta capability.'
);

$GLOBALS['lunara_core_bridge_state']['posts'][21]                   = new WP_Post( 21, 'review' );
$GLOBALS['lunara_core_bridge_state']['edit_links'][21]              = '';
$GLOBALS['lunara_core_bridge_state']['capabilities']['edit_post:21'] = true;
lunara_core_bridge_assert( $fallback === lunara_core_review_studio_admin_url( 21 ), 'A Review without a raw edit link must use the chooser fallback.' );

lunara_core_bridge_assert( function_exists( 'lunara_core_review_studio_status' ), 'Core must expose a stable redacted Review Studio status API.' );
$expected_unavailable = array(
	'available' => false,
	'version'   => '0.8.10',
	'cpt'       => array(
		'key'        => 'review',
		'registered' => false,
	),
	'actions'   => array(
		'choose' => false,
		'new'    => false,
		'edit'   => false,
	),
);
lunara_core_bridge_assert_same( $expected_unavailable, lunara_core_review_studio_status(), 'An unregistered Review CPT must produce the exact unavailable status shape.' );

$GLOBALS['lunara_core_bridge_state']['post_types']['review'] = true;
$expected_available = $expected_unavailable;
$expected_available['available']         = true;
$expected_available['cpt']['registered'] = true;
$expected_available['actions']           = array( 'choose' => true, 'new' => true, 'edit' => true );
$status = lunara_core_review_studio_status();
lunara_core_bridge_assert_same( $expected_available, $status, 'A registered Review CPT must expose only availability, version, CPT identity, and supported actions.' );

$GLOBALS['lunara_core_bridge_state']['posts'][91] = (object) array(
	'ID'          => 91,
	'post_title'  => 'SECRET REVIEW TITLE',
	'post_status' => 'draft',
	'post_meta'   => array( '_lunara_secret_token' => 'SECRET TOKEN VALUE' ),
);
$serialized_status = json_encode( $status );
foreach ( array( 'SECRET REVIEW TITLE', 'SECRET TOKEN VALUE', 'post_title', 'post_status', 'post_meta', 'review_id', 'draft' ) as $forbidden_status_value ) {
	lunara_core_bridge_assert( false === strpos( $serialized_status, $forbidden_status_value ), 'Redacted status leaked forbidden Review data: ' . $forbidden_status_value );
}

lunara_core_bridge_assert( ! function_exists( 'lunara_site_studio_surfaces' ), 'The Core bridge regression must prove safe operation without the theme registry.' );
Lunara_Core_Site_Studio_Bridge::init();
lunara_core_bridge_assert_same(
	array(
		array( 'lunara_site_studio_surfaces', array( 'Lunara_Core_Site_Studio_Bridge', 'contribute_surfaces' ), 10, 1 ),
	),
	$GLOBALS['lunara_core_bridge_state']['filters'],
	'Core must register exactly one inert Site Studio contribution filter.'
);

$existing = array(
	'theme-fixture' => array(
		'id'    => 'theme-fixture',
		'owner' => 'theme:fixture',
	),
);
$contributed = Lunara_Core_Site_Studio_Bridge::contribute_surfaces( $existing );
lunara_core_bridge_assert_same( $existing['theme-fixture'], $contributed['theme-fixture'], 'Core must preserve every incoming registry surface byte-for-byte.' );
$expected_surface = array(
	'id'                    => 'core-review-studio',
	'group'                 => 'Editorial',
	'label'                 => 'Review Studio',
	'description'           => 'Write a Review and finish its film details, Debrief, and images in the canonical editor.',
	'aliases'               => array( 'review library', 'new review', 'edit review', 'film identity', 'score', 'debrief', 'review images' ),
	'owner'                 => 'plugin:lunara-core',
	'kind'                  => 'content',
	'capability'            => 'edit_posts',
	'supports_preview'      => false,
	'preview_route'         => '',
	'preview_query_arg'     => '',
	'adapter_factory'       => '',
	'state_schema_callback' => '',
	'admin_url'             => 'edit.php?post_type=review',
	'dependency_callback'   => array( 'Lunara_Core_Site_Studio_Bridge', 'dependency' ),
	'status_callback'       => array( 'Lunara_Core_Site_Studio_Bridge', 'registry_status' ),
	'danger_level'          => 'none',
	'sections'              => array( 'review-intake', 'film-details', 'debrief', 'images' ),
	'classic_url'           => 'edit.php?post_type=review',
	'renderer'              => '',
);
lunara_core_bridge_assert_same( $expected_surface, $contributed['core-review-studio'], 'Core must contribute the exact inert Review Studio registry metadata.' );

$collision = Lunara_Core_Site_Studio_Bridge::contribute_surfaces(
	array(
		'core-review-studio' => array( 'id' => 'core-review-studio', 'owner' => 'plugin:collision' ),
	)
);
lunara_core_bridge_assert_same( 'plugin:lunara-core', $collision['core-review-studio']['owner'], 'Core must make its ownership claim visible so the theme can quarantine collisions.' );

$GLOBALS['lunara_core_bridge_state']['post_types']['review'] = false;
lunara_core_bridge_assert_same(
	array(
		'available' => false,
		'reason'    => 'review_cpt_unavailable',
		'message'   => 'The Review Studio is unavailable because the canonical Review post type is not registered.',
	),
	call_user_func( $expected_surface['dependency_callback'], $expected_surface ),
	'The registry dependency must fail closed when the Review CPT is absent.'
);

$GLOBALS['lunara_core_bridge_state']['post_types']['review'] = true;
lunara_core_bridge_assert_same(
	array(
		'available' => true,
		'reason'    => '',
		'message'   => 'Lunara Core Review Studio is available.',
	),
	call_user_func( $expected_surface['dependency_callback'], $expected_surface ),
	'The registry dependency must report only bounded availability.'
);
lunara_core_bridge_assert_same(
	array(
		'state'        => 'ready',
		'label'        => 'Review Studio available',
		'message'      => 'Lunara Core 0.8.10 provides the canonical Review editor.',
		'action_label' => 'Open Review Studio',
		'url'          => $fallback,
	),
	call_user_func( $expected_surface['status_callback'], $expected_surface ),
	'The registry status callback must project only the theme-safe status envelope.'
);
