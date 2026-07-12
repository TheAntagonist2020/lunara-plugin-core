<?php
/**
 * Dependency-free regression checks for the private Movie importer shell.
 *
 * Run with: php tests/movie-import-admin-regression.php
 */

define( 'ABSPATH', __DIR__ . '/' );
define( 'LUNARA_CORE_DIR', dirname( __DIR__ ) . '/' );
define( 'LUNARA_CORE_URL', 'https://example.test/wp-content/plugins/lunara-core/' );
define( 'LUNARA_CORE_VERSION', 'test' );

$GLOBALS['lunara_import_admin_test'] = array(
    'actions' => array(),
    'routes'  => array(),
    'caps'    => array(
        'manage_options' => true,
        'edit_post'      => true,
    ),
    'posts'   => array(
        99  => array( 'type' => 'review', 'status' => 'draft', 'title' => 'Review' ),
        100 => array( 'type' => 'review', 'status' => 'auto-draft', 'title' => '' ),
        701 => array( 'type' => 'movie', 'status' => 'draft', 'title' => 'The Godfather' ),
    ),
    'nonce_valid' => true,
    'service'     => null,
    'localized'   => array(),
    'styles'      => array(),
    'scripts'     => array(),
);

class WP_Error {
    private $code;
    private $message;
    private $data;

    public function __construct( $code, $message, $data = array() ) {
        $this->code    = $code;
        $this->message = $message;
        $this->data    = $data;
    }

    public function get_error_code() {
        return $this->code;
    }

    public function get_error_message() {
        return $this->message;
    }

    public function get_error_data() {
        return $this->data;
    }
}

class WP_REST_Response {
    public $data;
    public $status;

    public function __construct( $data, $status = 200 ) {
        $this->data   = $data;
        $this->status = $status;
    }
}

class Lunara_Test_REST_Request {
    private $params;
    private $headers;

    public function __construct( $params, $headers = array( 'X-WP-Nonce' => 'valid' ) ) {
        $this->params  = $params;
        $this->headers = $headers;
    }

    public function get_param( $name ) {
        return $this->params[ $name ] ?? null;
    }

    public function get_header( $name ) {
        return $this->headers[ $name ] ?? '';
    }
}

final class Lunara_Debrief_Contract {
    public static function roles() {
        return array(
            'theme_echo' => array(
                'label'           => 'Theme Echo',
                'movie_field_key' => 'field_lunara_review_theme_echo_movie',
            ),
            'counter_program' => array(
                'label'           => 'Counter Program',
                'movie_field_key' => 'field_lunara_review_counter_program_movie',
            ),
            'career_context' => array(
                'label'           => 'Career Context',
                'movie_field_key' => 'field_lunara_review_career_context_movie',
            ),
        );
    }

    public static function normalize_imdb_title_id( $value ) {
        return preg_match( '/\b(tt\d{6,9})\b/i', (string) $value, $matches )
            ? strtolower( $matches[1] )
            : '';
    }
}

final class Lunara_Test_Movie_Importer {
    public $previews = array();
    public $imports  = array();
    public $preview_status = 'ready';
    public $preview_local  = false;
    public $apply_status   = 'created';

    public function preview_by_imdb( $imdb_id ) {
        $this->previews[] = $imdb_id;
        if ( 'unavailable' === $this->preview_status || 'invalid' === $this->preview_status ) {
            return array(
                'status'           => $this->preview_status,
                'candidate'        => array(),
                'movie_id'         => 0,
                'local'            => false,
                'writes_performed' => 0,
                'issues'           => array( $this->preview_status . '_fixture' ),
            );
        }

        return array(
            'status'         => $this->preview_status,
            'schema_version' => 'lunara-movie-import/v1',
            'candidate'      => array(
                'imdb_title_id' => $imdb_id,
                'title'         => 'The Godfather',
                'release_year'  => '1972',
                'runtime'       => '175 min',
                'directors'     => array(
                    array( 'name' => 'Francis Ford Coppola', 'tmdb_person_id' => 1776 ),
                ),
                'overview'      => 'A family crime saga.',
                'poster_url'    => 'https://provider.invalid/private.jpg',
            ),
            'plan_hash'      => 'opaque-plan-hash',
            'movie_id'       => ( 'local' === $this->preview_status || $this->preview_local ) ? 701 : 0,
            'local'          => 'local' === $this->preview_status || $this->preview_local,
        );
    }

    public function import_draft( $candidate, $context = array() ) {
        $this->imports[] = array( $candidate, $context );
        return array(
            'status'   => $this->apply_status,
            'movie_id' => 701,
        );
    }
}

final class Lunara_Movie_Repository {}

final class Lunara_Movie_Provider_Gateway {
    public static $constructed = 0;

    public function __construct() {
        ++self::$constructed;
    }
}

final class Lunara_Movie_Importer {
    public static $gateway = null;

    public function __construct( $repository, $gateway = null ) {
        self::$gateway = $gateway;
    }

    public function preview_by_imdb( $imdb_id ) {
        return array(
            'status'    => 'ready',
            'candidate' => array(
                'imdb_title_id' => $imdb_id,
                'title'         => 'Default Service Film',
            ),
            'local'     => false,
            'movie_id'  => 0,
        );
    }

    public function import_draft( $candidate, $context = array() ) {
        return array( 'status' => 'created', 'movie_id' => 701 );
    }
}

function add_action( $hook, $callback ) {
    $GLOBALS['lunara_import_admin_test']['actions'][] = array( $hook, $callback );
}

function register_rest_route( $namespace, $route, $args ) {
    $GLOBALS['lunara_import_admin_test']['routes'][] = array( $namespace, $route, $args );
}

function apply_filters( $hook, $value ) {
    if ( 'lunara_movie_importer_service' === $hook ) {
        return $GLOBALS['lunara_import_admin_test']['service'];
    }
    return $value;
}

function __( $text ) {
    return $text;
}

function esc_html__( $text ) {
    return htmlspecialchars( $text, ENT_QUOTES, 'UTF-8' );
}

function esc_attr__( $text ) {
    return htmlspecialchars( $text, ENT_QUOTES, 'UTF-8' );
}

function esc_html( $text ) {
    return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
}

function esc_attr( $text ) {
    return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
}

function esc_url_raw( $url ) {
    return (string) $url;
}

function absint( $value ) {
    return abs( (int) $value );
}

function sanitize_key( $value ) {
    return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $value ) );
}

function sanitize_html_class( $value ) {
    return preg_replace( '/[^a-z0-9_\-]/i', '', (string) $value );
}

function sanitize_text_field( $value ) {
    return trim( strip_tags( (string) $value ) );
}

function sanitize_textarea_field( $value ) {
    return trim( strip_tags( (string) $value ) );
}

function wp_unslash( $value ) {
    return $value;
}

function wp_verify_nonce( $nonce, $action ) {
    return $GLOBALS['lunara_import_admin_test']['nonce_valid'] && 'valid' === $nonce && 'wp_rest' === $action;
}

function current_user_can( $capability, $post_id = 0 ) {
    return ! empty( $GLOBALS['lunara_import_admin_test']['caps'][ $capability ] );
}

function get_post_type( $post_id ) {
    return $GLOBALS['lunara_import_admin_test']['posts'][ $post_id ]['type'] ?? '';
}

function get_post_status( $post_id ) {
    return $GLOBALS['lunara_import_admin_test']['posts'][ $post_id ]['status'] ?? '';
}

function get_the_title( $post_id ) {
    return $GLOBALS['lunara_import_admin_test']['posts'][ $post_id ]['title'] ?? '';
}

function get_edit_post_link( $post_id, $context = '' ) {
    return 'https://example.test/wp-admin/post.php?post=' . absint( $post_id ) . '&action=edit';
}

function get_current_user_id() {
    return 1;
}

function is_wp_error( $value ) {
    return $value instanceof WP_Error;
}

function rest_url( $path ) {
    return 'https://example.test/wp-json/' . ltrim( $path, '/' );
}

function wp_create_nonce( $action ) {
    return 'valid';
}

function wp_enqueue_style( $handle ) {
    $GLOBALS['lunara_import_admin_test']['styles'][] = $handle;
}

function wp_enqueue_script( $handle ) {
    $GLOBALS['lunara_import_admin_test']['scripts'][] = $handle;
}

function wp_localize_script( $handle, $name, $data ) {
    $GLOBALS['lunara_import_admin_test']['localized'] = compact( 'handle', 'name', 'data' );
}

function get_current_screen() {
    return (object) array( 'post_type' => 'review' );
}

function lunara_import_admin_assert_same( $expected, $actual, $message ) {
    if ( $expected !== $actual ) {
        throw new RuntimeException(
            $message . "\nExpected: " . var_export( $expected, true ) . "\nActual: " . var_export( $actual, true )
        );
    }
}

function lunara_import_admin_assert_true( $condition, $message ) {
    if ( ! $condition ) {
        throw new RuntimeException( $message );
    }
}

require dirname( __DIR__ ) . '/includes/class-lunara-movie-import-admin.php';

$GLOBALS['lunara_import_admin_test']['service'] = new Lunara_Test_Movie_Importer();
Lunara_Movie_Import_Admin::init();

$hooks = array_column( $GLOBALS['lunara_import_admin_test']['actions'], 0 );
lunara_import_admin_assert_true( ! in_array( 'rest_api_init', $hooks, true ), 'Core must remain the single owner of private REST route bootstrapping.' );
lunara_import_admin_assert_same(
    3,
    count( array_filter( $hooks, static function( $hook ) {
        return 0 === strpos( $hook, 'acf/render_field/key=field_lunara_review_' ) && false !== strpos( $hook, '_movie' );
    } ) ),
    'Exactly three role-specific Movie field launchers must be registered.'
);

Lunara_Movie_Import_Admin::register_rest_routes();
lunara_import_admin_assert_same( 2, count( $GLOBALS['lunara_import_admin_test']['routes'] ), 'Lookup and import must be the only importer routes.' );
foreach ( $GLOBALS['lunara_import_admin_test']['routes'] as $route ) {
    lunara_import_admin_assert_same( 'lunara/v1', $route[0], 'Importer routes must use the lunara/v1 namespace.' );
    lunara_import_admin_assert_same( 'POST', $route[2]['methods'], 'Importer endpoints must be POST-only.' );
    lunara_import_admin_assert_true( is_callable( $route[2]['permission_callback'] ), 'Every importer endpoint needs an explicit permission callback.' );
}

$_GET = array( 'post' => 99 );
ob_start();
Lunara_Movie_Import_Admin::render_field_launcher( array( 'key' => 'field_lunara_review_theme_echo_movie' ) );
$launcher_html = ob_get_clean();
lunara_import_admin_assert_true( false !== strpos( $launcher_html, 'Local library first' ), 'The launcher must instruct editors to use local Movie records first.' );
lunara_import_admin_assert_true( false !== strpos( $launcher_html, 'data-role="theme_echo"' ), 'The launcher must preserve its exact Debrief role.' );
lunara_import_admin_assert_true( false !== strpos( $launcher_html, 'data-state="local-first"' ), 'Every launcher must begin in the local-first state.' );
lunara_import_admin_assert_true( false !== strpos( $launcher_html, '<dialog' ), 'The privileged saved-Review launcher must use a native dialog.' );
lunara_import_admin_assert_true( false !== strpos( $launcher_html, 'role="status"' ), 'The dialog needs a polite live status region.' );
lunara_import_admin_assert_true( false !== strpos( $launcher_html, 'role="alert"' ), 'The dialog needs an assertive error region.' );
lunara_import_admin_assert_true( false !== strpos( $launcher_html, 'pattern="tt[0-9]{6,9}"' ), 'The IMDb field must accept the shared six-to-nine-digit identity contract.' );
lunara_import_admin_assert_true( false !== strpos( $launcher_html, 'target="_blank"' ), 'Opening a Film Dossier must not navigate away from unsaved Review edits.' );
lunara_import_admin_assert_true( false !== strpos( $launcher_html, 'rel="noopener noreferrer"' ), 'The new-tab dossier link must isolate its opener.' );

$_GET = array( 'post' => 100 );
ob_start();
Lunara_Movie_Import_Admin::render_field_launcher( array( 'key' => 'field_lunara_review_theme_echo_movie' ) );
$unsaved_html = ob_get_clean();
lunara_import_admin_assert_true( false !== strpos( $unsaved_html, 'Save this Review once' ), 'An unsaved Review must explain the persistence gate.' );
lunara_import_admin_assert_true( false === strpos( $unsaved_html, '<dialog' ), 'An unsaved Review must not expose a remote importer dialog.' );

$request = new Lunara_Test_REST_Request(
    array(
        'review_id' => 99,
        'role'      => 'theme_echo',
        'imdb_id'   => 'tt0068646',
    )
);
lunara_import_admin_assert_same( true, Lunara_Movie_Import_Admin::rest_permission( $request ), 'A nonce-authenticated administrator who can edit the Review must pass.' );

$GLOBALS['lunara_import_admin_test']['nonce_valid'] = false;
$bad_nonce = Lunara_Movie_Import_Admin::rest_permission( $request );
lunara_import_admin_assert_same( 'lunara_movie_import_bad_nonce', $bad_nonce->get_error_code(), 'A REST nonce is mandatory.' );
$GLOBALS['lunara_import_admin_test']['nonce_valid'] = true;

$GLOBALS['lunara_import_admin_test']['caps']['manage_options'] = false;
$bad_cap = Lunara_Movie_Import_Admin::rest_permission( $request );
lunara_import_admin_assert_same( 'lunara_movie_import_forbidden', $bad_cap->get_error_code(), 'Remote access must initially require manage_options.' );
$GLOBALS['lunara_import_admin_test']['caps']['manage_options'] = true;

$GLOBALS['lunara_import_admin_test']['caps']['edit_post'] = false;
$bad_edit = Lunara_Movie_Import_Admin::rest_permission( $request );
lunara_import_admin_assert_same( 'lunara_movie_import_review_forbidden', $bad_edit->get_error_code(), 'The current user must also be able to edit the target Review.' );
$GLOBALS['lunara_import_admin_test']['caps']['edit_post'] = true;

$unsaved_request = new Lunara_Test_REST_Request( array( 'review_id' => 100, 'role' => 'theme_echo', 'imdb_id' => 'tt0068646' ) );
$unsaved_error   = Lunara_Movie_Import_Admin::rest_permission( $unsaved_request );
lunara_import_admin_assert_same( 'lunara_movie_import_review_required', $unsaved_error->get_error_code(), 'An auto-draft cannot authorize provider work.' );

$bad_role_request = new Lunara_Test_REST_Request( array( 'review_id' => 99, 'role' => 'wrong', 'imdb_id' => 'tt0068646' ) );
$bad_role_error   = Lunara_Movie_Import_Admin::rest_permission( $bad_role_request );
lunara_import_admin_assert_same( 'lunara_movie_import_bad_role', $bad_role_error->get_error_code(), 'The REST role must match one of the three canonical selectors.' );

lunara_import_admin_assert_same( 'tt0068646', Lunara_Movie_Import_Admin::sanitize_imdb_id( 'https://www.imdb.com/title/tt0068646/' ), 'IMDb URLs must normalize through the shared contract.' );
lunara_import_admin_assert_same( 'tt123456', Lunara_Movie_Import_Admin::sanitize_imdb_id( 'tt123456' ), 'Six-digit legacy IMDb IDs must remain valid.' );
lunara_import_admin_assert_same( '', Lunara_Movie_Import_Admin::sanitize_imdb_id( 'The Godfather' ), 'Free-text title searches must not cross the provider boundary.' );

$lookup_response = Lunara_Movie_Import_Admin::rest_lookup( $request );
lunara_import_admin_assert_same( 200, $lookup_response->status, 'A valid zero-write preview should return HTTP 200.' );
lunara_import_admin_assert_same( 'The Godfather', $lookup_response->data['candidate']['title'], 'The public preview must preserve normalized identity.' );
lunara_import_admin_assert_same( 'Francis Ford Coppola', $lookup_response->data['candidate']['directors'], 'Structured provider directors must render as names in the preview.' );
lunara_import_admin_assert_true( ! isset( $lookup_response->data['candidate']['poster_url'] ), 'Provider URLs must not be returned to the browser.' );
lunara_import_admin_assert_same( array( 'tt0068646' ), $GLOBALS['lunara_import_admin_test']['service']->previews, 'Lookup must delegate to importer preview_by_imdb once.' );

$GLOBALS['lunara_import_admin_test']['service']->preview_status = 'unavailable';
$unavailable = Lunara_Movie_Import_Admin::rest_lookup( $request );
lunara_import_admin_assert_same( 'lunara_movie_import_preview_unavailable', $unavailable->get_error_code(), 'Provider unavailability must remain distinct from a not-found candidate.' );
lunara_import_admin_assert_same( 503, $unavailable->get_error_data()['status'], 'Provider unavailability must return HTTP 503.' );

$GLOBALS['lunara_import_admin_test']['service']->preview_status = 'invalid';
$invalid_preview = Lunara_Movie_Import_Admin::rest_lookup( $request );
lunara_import_admin_assert_same( 400, $invalid_preview->get_error_data()['status'], 'An invalid preview must return HTTP 400.' );

$GLOBALS['lunara_import_admin_test']['service']->preview_status = 'local';
$imports_before_local = count( $GLOBALS['lunara_import_admin_test']['service']->imports );
$local_lookup = Lunara_Movie_Import_Admin::rest_lookup( $request );
lunara_import_admin_assert_same( 'lunara_movie_import_already_local', $local_lookup->get_error_code(), 'A local identity must stop with a precise local-library response.' );
lunara_import_admin_assert_same( 701, $local_lookup->get_error_data()['movie']['id'], 'A local draft response must expose its safe recovery ID.' );
lunara_import_admin_assert_true( false !== strpos( $local_lookup->get_error_data()['movie']['edit_url'], 'post=701' ), 'A local draft response must expose its capability-checked edit link.' );
$local_import = Lunara_Movie_Import_Admin::rest_import( $request );
lunara_import_admin_assert_same( 'lunara_movie_import_already_local', $local_import->get_error_code(), 'A local identity must never cross the draft apply boundary.' );
lunara_import_admin_assert_same( $imports_before_local, count( $GLOBALS['lunara_import_admin_test']['service']->imports ), 'A local identity must not call import_draft.' );

$GLOBALS['lunara_import_admin_test']['service']->preview_status = 'ready';
$GLOBALS['lunara_import_admin_test']['service']->preview_local  = true;
$GLOBALS['lunara_import_admin_test']['service']->apply_status   = 'updated';
$imports_before_enrichment = count( $GLOBALS['lunara_import_admin_test']['service']->imports );
$draft_lookup = Lunara_Movie_Import_Admin::rest_lookup( $request );
lunara_import_admin_assert_same( 200, $draft_lookup->status, 'A single local draft with a provider candidate must remain explicitly enrichable.' );
lunara_import_admin_assert_same( true, $draft_lookup->data['candidate']['local'], 'Draft enrichment preview must disclose that it targets a local record.' );
lunara_import_admin_assert_same( true, $draft_lookup->data['candidate']['can_import'], 'Draft enrichment must require the same explicit apply action as creation.' );
lunara_import_admin_assert_true( false !== strpos( $draft_lookup->data['candidate']['edit_url'], 'post=701' ), 'Draft enrichment preview must retain its safe recovery link.' );
$draft_import = Lunara_Movie_Import_Admin::rest_import( $request );
lunara_import_admin_assert_same( 200, $draft_import->status, 'Enriching an existing draft should return HTTP 200 rather than a creation response.' );
lunara_import_admin_assert_same( $imports_before_enrichment + 1, count( $GLOBALS['lunara_import_admin_test']['service']->imports ), 'Explicit draft enrichment must cross the apply boundary exactly once.' );
$GLOBALS['lunara_import_admin_test']['service']->preview_local = false;
$GLOBALS['lunara_import_admin_test']['service']->apply_status  = 'created';

$import_response = Lunara_Movie_Import_Admin::rest_import( $request );
lunara_import_admin_assert_same( 201, $import_response->status, 'A valid draft creation should return HTTP 201.' );
lunara_import_admin_assert_same( 'draft', $import_response->data['movie']['status'], 'The admin boundary must expose draft-only results.' );
lunara_import_admin_assert_same( 'theme_echo', $import_response->data['role'], 'The apply response must retain the target Debrief role.' );
$last_import = end( $GLOBALS['lunara_import_admin_test']['service']->imports );
lunara_import_admin_assert_same( 'draft', $last_import[1]['post_status'], 'The importer context must explicitly require draft status.' );
lunara_import_admin_assert_same( 99, $last_import[1]['review_id'], 'The importer context must retain the saved Review ID.' );

$GLOBALS['lunara_import_admin_test']['posts'][701]['status'] = 'publish';
$not_draft = Lunara_Movie_Import_Admin::rest_import( $request );
lunara_import_admin_assert_same( 'lunara_movie_import_not_draft', $not_draft->get_error_code(), 'A non-draft importer result must fail closed.' );
$GLOBALS['lunara_import_admin_test']['posts'][701]['status'] = 'draft';

$GLOBALS['lunara_import_admin_test']['service']->apply_status = 'partial';
$partial_import = Lunara_Movie_Import_Admin::rest_import( $request );
lunara_import_admin_assert_same( 'lunara_movie_import_partial', $partial_import->get_error_code(), 'A recoverable partial draft must never be reported as a clean success.' );
lunara_import_admin_assert_same( 500, $partial_import->get_error_data()['status'], 'A partial metadata apply must return HTTP 500 while preserving the draft.' );
lunara_import_admin_assert_same( 701, $partial_import->get_error_data()['movie']['id'], 'A partial result must preserve the recoverable draft ID.' );
lunara_import_admin_assert_true( false !== strpos( $partial_import->get_error_data()['movie']['edit_url'], 'post=701' ), 'A partial result must provide a safe draft recovery link.' );
$GLOBALS['lunara_import_admin_test']['service']->apply_status = 'created';

$injected_service = $GLOBALS['lunara_import_admin_test']['service'];
$GLOBALS['lunara_import_admin_test']['service'] = null;
$default_lookup = Lunara_Movie_Import_Admin::rest_lookup( $request );
lunara_import_admin_assert_same( 200, $default_lookup->status, 'Default construction must produce a usable importer service.' );
lunara_import_admin_assert_true( Lunara_Movie_Importer::$gateway instanceof Lunara_Movie_Provider_Gateway, 'Default construction must inject the private provider gateway.' );
lunara_import_admin_assert_same( 1, Lunara_Movie_Provider_Gateway::$constructed, 'The default service boundary must construct one gateway for one lookup.' );
$GLOBALS['lunara_import_admin_test']['service'] = $injected_service;

$_GET = array( 'post' => 99 );
$GLOBALS['lunara_import_admin_test']['caps']['manage_options'] = false;
Lunara_Movie_Import_Admin::enqueue_assets( 'post.php' );
lunara_import_admin_assert_same( array(), $GLOBALS['lunara_import_admin_test']['localized'], 'Editors who cannot import must receive no importer assets or localized REST nonce.' );
$GLOBALS['lunara_import_admin_test']['caps']['manage_options'] = true;
Lunara_Movie_Import_Admin::enqueue_assets( 'post.php' );
lunara_import_admin_assert_same( 'lunara/v1/movie-import/', parse_url( $GLOBALS['lunara_import_admin_test']['localized']['data']['restBase'], PHP_URL_PATH ) === '/wp-json/lunara/v1/movie-import/' ? 'lunara/v1/movie-import/' : '', 'The localized REST base must preserve the namespace separator.' );
lunara_import_admin_assert_same( array( 'lunara-core-movie-import-admin' ), $GLOBALS['lunara_import_admin_test']['styles'], 'Authorized Review editors must receive exactly one importer stylesheet.' );
lunara_import_admin_assert_same( array( 'lunara-core-movie-import-admin' ), $GLOBALS['lunara_import_admin_test']['scripts'], 'Authorized Review editors must receive exactly one importer script.' );

$source = file_get_contents( dirname( __DIR__ ) . '/includes/class-lunara-movie-import-admin.php' );
$script = file_get_contents( dirname( __DIR__ ) . '/assets/js/lunara-movie-import-admin.js' );
lunara_import_admin_assert_true( false === strpos( $source, 'wp_remote_' ), 'The admin controller must never perform provider transport.' );
lunara_import_admin_assert_true( false === strpos( $source, 'api_key' ), 'Provider credentials must not enter the admin controller.' );
lunara_import_admin_assert_true( false !== strpos( $source, "'methods'             => 'POST'" ), 'The route source must make POST-only behavior explicit.' );
lunara_import_admin_assert_true( false !== strpos( $script, "method: 'POST'" ), 'The browser client must use POST for both operations.' );
lunara_import_admin_assert_true( false !== strpos( $script, "'X-WP-Nonce'" ), 'The browser client must send the REST nonce.' );
lunara_import_admin_assert_true( false !== strpos( $script, 'dialog.showModal()' ), 'The browser client must open the native modal dialog.' );
lunara_import_admin_assert_true( false !== strpos( $script, "document.addEventListener('close'" ), 'The browser client must restore focus after native close or Escape.' );
lunara_import_admin_assert_true( false !== strpos( $script, "document.addEventListener('cancel'" ), 'The browser client must recognize the native Escape/cancel path.' );
lunara_import_admin_assert_true( false !== strpos( $script, '_lunaraRequestGeneration' ), 'The browser client must ignore stale async responses after close or a newer lookup.' );
lunara_import_admin_assert_true( false !== strpos( $script, 'showRecovery' ), 'The browser client must surface recoverable local or partial drafts.' );
lunara_import_admin_assert_true( false !== strpos( $script, 'candidate-local' ), 'The browser client must distinguish an enrichable draft from an immutable local Movie.' );
lunara_import_admin_assert_true( false !== strpos( $script, 'Enrich existing draft' ), 'The browser client must label the explicit draft-enrichment action clearly.' );
lunara_import_admin_assert_true( file_exists( dirname( __DIR__ ) . '/assets/css/lunara-movie-import-admin.css' ), 'Importer CSS is missing.' );

echo "Movie importer admin regression checks passed.\n";
