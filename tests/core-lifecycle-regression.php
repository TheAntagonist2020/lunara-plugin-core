<?php
/**
 * Dependency-free lifecycle regression checks for Lunara Core.
 *
 * Run with: php tests/core-lifecycle-regression.php
 */

define( 'ABSPATH', __DIR__ . '/' );

$GLOBALS['lunara_test_state'] = array(
	'entity_graph_enabled' => true,
	'post_types'           => array(),
	'taxonomies'           => array(),
	'flushes'              => 0,
	'options'              => array(),
	'stylesheet'           => 'lunara-theme-blocks-20260513-2300',
	'template'             => 'blocksy',
	'switched_to'          => array(),
	'post_type'            => 'review',
	'post_meta'            => array(),
	'term_updates'         => array(),
	'slide_set_term'       => null,
	'attachments'          => array(),
	'post_updates'         => array(),
);

function plugin_dir_path( $file ) {
	return dirname( $file ) . DIRECTORY_SEPARATOR;
}

function plugin_dir_url() {
	return 'https://example.test/wp-content/plugins/lunara-core/';
}

function add_action() {}

function add_filter() {}

function register_activation_hook() {}

function register_deactivation_hook() {}

function apply_filters( $hook, $value ) {
	if ( 'lunara_enable_entity_graph' === $hook ) {
		return $GLOBALS['lunara_test_state']['entity_graph_enabled'];
	}

	return $value;
}

function __( $text ) {
	return $text;
}

function register_post_type( $post_type ) {
	$GLOBALS['lunara_test_state']['post_types'][] = $post_type;
}

function register_taxonomy( $taxonomy ) {
	$GLOBALS['lunara_test_state']['taxonomies'][] = $taxonomy;
}

function flush_rewrite_rules() {
	++$GLOBALS['lunara_test_state']['flushes'];
}

function update_option( $name, $value ) {
	$GLOBALS['lunara_test_state']['options'][ $name ] = $value;
	return true;
}

function get_option( $name, $default = false ) {
	return array_key_exists( $name, $GLOBALS['lunara_test_state']['options'] )
		? $GLOBALS['lunara_test_state']['options'][ $name ]
		: $default;
}

function get_stylesheet() {
	return $GLOBALS['lunara_test_state']['stylesheet'];
}

function get_template() {
	return $GLOBALS['lunara_test_state']['template'];
}

function wp_get_theme( $stylesheet ) {
	return new Lunara_Test_Theme( $stylesheet );
}

function switch_theme( $stylesheet ) {
	$GLOBALS['lunara_test_state']['switched_to'][] = $stylesheet;
}

function wp_is_post_revision() {
	return false;
}

function get_post_type() {
	return $GLOBALS['lunara_test_state']['post_type'];
}

function get_post_meta( $post_id, $key ) {
	return isset( $GLOBALS['lunara_test_state']['post_meta'][ $post_id ][ $key ] )
		? $GLOBALS['lunara_test_state']['post_meta'][ $post_id ][ $key ]
		: '';
}

function wp_set_object_terms( $post_id, $terms, $taxonomy, $append ) {
	$GLOBALS['lunara_test_state']['term_updates'][] = array( $post_id, $terms, $taxonomy, $append );
	return array();
}

function current_user_can() {
	return true;
}

function check_ajax_referer() {}

function wp_unslash( $value ) {
	return $value;
}

function sanitize_title( $value ) {
	return trim( preg_replace( '/[^a-z0-9-]+/', '-', strtolower( (string) $value ) ), '-' );
}

function absint( $value ) {
	return abs( (int) $value );
}

function get_term_by( $field, $value, $taxonomy ) {
	$term = $GLOBALS['lunara_test_state']['slide_set_term'];
	if ( 'slug' === $field && 'lunara_slide_set' === $taxonomy && $term instanceof WP_Term && $term->slug === $value ) {
		return $term;
	}

	return false;
}

function get_post( $post_id ) {
	return isset( $GLOBALS['lunara_test_state']['attachments'][ $post_id ] )
		? $GLOBALS['lunara_test_state']['attachments'][ $post_id ]
		: null;
}

function has_term( $term_id, $taxonomy, $post_id ) {
	$post = get_post( $post_id );
	return 'lunara_slide_set' === $taxonomy && $post instanceof WP_Post && in_array( $term_id, $post->term_ids, true );
}

function wp_update_post( $post_data ) {
	$GLOBALS['lunara_test_state']['post_updates'][] = $post_data;
	return $post_data['ID'];
}

function is_wp_error() {
	return false;
}

function wp_send_json_error( $data ) {
	throw new Lunara_Test_Json_Response( false, $data );
}

function wp_send_json_success( $data ) {
	throw new Lunara_Test_Json_Response( true, $data );
}

final class WP_Term {
	public $term_id;
	public $slug;

	public function __construct( $term_id, $slug ) {
		$this->term_id = $term_id;
		$this->slug    = $slug;
	}
}

final class WP_Post {
	public $ID;
	public $post_type;
	public $term_ids;

	public function __construct( $post_id, $post_type, $term_ids ) {
		$this->ID        = $post_id;
		$this->post_type = $post_type;
		$this->term_ids  = $term_ids;
	}
}

final class Lunara_Test_Json_Response extends RuntimeException {
	public $success;
	public $data;

	public function __construct( $success, $data ) {
		parent::__construct();
		$this->success = $success;
		$this->data    = $data;
	}
}

final class Lunara_Test_Theme {
	private $stylesheet;

	public function __construct( $stylesheet ) {
		$this->stylesheet = $stylesheet;
	}

	public function exists() {
		return '' !== $this->stylesheet;
	}

	public function errors() {
		return false;
	}
}

function lunara_test_reset_registrations() {
	$GLOBALS['lunara_test_state']['post_types'] = array();
	$GLOBALS['lunara_test_state']['taxonomies'] = array();
	$GLOBALS['lunara_test_state']['flushes']    = 0;
	$GLOBALS['lunara_test_state']['options']    = array();
}

function lunara_test_assert_same( $expected, $actual, $message ) {
	if ( $expected !== $actual ) {
		throw new RuntimeException(
			$message . '\nExpected: ' . var_export( $expected, true ) . '\nActual: ' . var_export( $actual, true )
		);
	}
}

require dirname( __DIR__ ) . '/lunara-core.php';

lunara_test_reset_registrations();
Lunara_Core::activate();

lunara_test_assert_same(
	array( 'review', 'movie', 'person', 'ledger_entry' ),
	$GLOBALS['lunara_test_state']['post_types'],
	'Activation must register every public and relational post type before flushing rewrites.'
);
lunara_test_assert_same(
	array( 'lunara_director', 'lunara_review_year', 'lunara_slide_set', 'lunara_studio' ),
	$GLOBALS['lunara_test_state']['taxonomies'],
	'Activation must register every rewrite-owning taxonomy before flushing rewrites.'
);
lunara_test_assert_same( 1, $GLOBALS['lunara_test_state']['flushes'], 'Activation must flush rewrites exactly once.' );
lunara_test_assert_same(
	LUNARA_CORE_VERSION,
	$GLOBALS['lunara_test_state']['options']['lunara_core_rewrite_version'],
	'Activation must record the entity rewrite schema version after the flush.'
);

lunara_test_reset_registrations();
$GLOBALS['lunara_test_state']['entity_graph_enabled'] = false;
Lunara_Core::activate();

lunara_test_assert_same(
	array( 'review' ),
	$GLOBALS['lunara_test_state']['post_types'],
	'Activation must respect the entity graph off-switch.'
);
lunara_test_assert_same(
	array( 'lunara_director', 'lunara_review_year', 'lunara_slide_set' ),
	$GLOBALS['lunara_test_state']['taxonomies'],
	'Disabling the entity graph must leave the review and carousel taxonomies available.'
);
lunara_test_assert_same(
	false,
	isset( $GLOBALS['lunara_test_state']['options']['lunara_core_rewrite_version'] ),
	'Disabled entity rewrites must not be marked current.'
);

$GLOBALS['lunara_test_state']['entity_graph_enabled'] = true;
$GLOBALS['lunara_test_state']['stylesheet']           = 'lunara-theme-blocks-20260513-2300';
$GLOBALS['lunara_test_state']['template']             = 'blocksy';
$GLOBALS['lunara_test_state']['switched_to']          = array();
$GLOBALS['lunara_test_state']['options']              = array();

Lunara_Guardian::bless_active_theme();
Lunara_Guardian::guard_active_theme();

lunara_test_assert_same(
	'lunara-theme-blocks-20260513-2300',
	$GLOBALS['lunara_test_state']['options'][ Lunara_Guardian::BLESSED_OPTION ],
	'Guardian must bless the active Lunara child stylesheet when Blocksy is its template.'
);
lunara_test_assert_same(
	array(),
	$GLOBALS['lunara_test_state']['switched_to'],
	'Guardian must leave the active Lunara child stylesheet untouched.'
);

$GLOBALS['lunara_test_state']['stylesheet']  = 'blocksy';
$GLOBALS['lunara_test_state']['switched_to'] = array();
Lunara_Guardian::guard_active_theme();

lunara_test_assert_same(
	array( 'lunara-theme-blocks-20260513-2300' ),
	$GLOBALS['lunara_test_state']['switched_to'],
	'Guardian must restore the blessed child when the Blocksy parent stylesheet is activated directly.'
);

$GLOBALS['lunara_test_state']['post_meta'][42] = array(
	'_lunara_director' => 'Ava DuVernay',
	'_lunara_year'     => '2026',
);
$GLOBALS['lunara_test_state']['term_updates'] = array();
Lunara_Core::instance()->sync_review_archive_terms( 42 );

lunara_test_assert_same(
	array(
		array( 42, array( 'Ava DuVernay' ), 'lunara_director', false ),
		array( 42, array( '2026' ), 'lunara_review_year', false ),
	),
	$GLOBALS['lunara_test_state']['term_updates'],
	'Review archive taxonomies must mirror populated metadata.'
);

$GLOBALS['lunara_test_state']['post_meta'][42] = array(
	'_lunara_director' => '',
	'_lunara_year'     => '',
);
$GLOBALS['lunara_test_state']['term_updates'] = array();
Lunara_Core::instance()->sync_review_archive_terms( 42 );

lunara_test_assert_same(
	array(
		array( 42, array(), 'lunara_director', false ),
		array( 42, array(), 'lunara_review_year', false ),
	),
	$GLOBALS['lunara_test_state']['term_updates'],
	'Clearing review metadata must remove stale archive taxonomy terms.'
);

$GLOBALS['lunara_test_state']['slide_set_term'] = new WP_Term( 9, 'homepage' );
$GLOBALS['lunara_test_state']['attachments']    = array(
	10 => new WP_Post( 10, 'attachment', array( 9 ) ),
	20 => new WP_Post( 20, 'attachment', array( 9 ) ),
	30 => new WP_Post( 30, 'post', array( 9 ) ),
);
$GLOBALS['lunara_test_state']['post_updates']   = array();
$_POST = array(
	'slide_set' => 'homepage',
	'order'     => array( '20', '10', '20' ),
);

$response = null;
try {
	Lunara_Core::instance()->ajax_save_carousel_order();
} catch ( Lunara_Test_Json_Response $caught_response ) {
	$response = $caught_response;
}

lunara_test_assert_same( true, $response instanceof Lunara_Test_Json_Response, 'A valid carousel order must return JSON.' );
lunara_test_assert_same( true, $response->success, 'A valid carousel order must succeed.' );

lunara_test_assert_same(
	array(
		array( 'ID' => 20, 'menu_order' => 0 ),
		array( 'ID' => 10, 'menu_order' => 1 ),
	),
	$GLOBALS['lunara_test_state']['post_updates'],
	'Carousel ordering must update each in-set attachment once.'
);

$GLOBALS['lunara_test_state']['post_updates'] = array();
$_POST['order'] = array( '10', '30' );

$response = null;
try {
	Lunara_Core::instance()->ajax_save_carousel_order();
} catch ( Lunara_Test_Json_Response $caught_response ) {
	$response = $caught_response;
}

lunara_test_assert_same( true, $response instanceof Lunara_Test_Json_Response, 'An invalid carousel order must return JSON.' );
lunara_test_assert_same( false, $response->success, 'A carousel order containing a non-attachment must fail.' );

lunara_test_assert_same(
	array(),
	$GLOBALS['lunara_test_state']['post_updates'],
	'Carousel ordering must validate every slide before writing any menu order.'
);

echo "Core lifecycle regression checks passed.\n";
