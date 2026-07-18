<?php
/**
 * Behavioral regression checks for the private Review draft importer.
 *
 * Run with: php tests/review-draft-import-admin-regression.php
 */

define( 'ABSPATH', __DIR__ . '/' );
define( 'LUNARA_CORE_DIR', dirname( __DIR__ ) . '/' );
define( 'LUNARA_CORE_URL', 'https://example.test/wp-content/plugins/lunara-core/' );
define( 'LUNARA_CORE_VERSION', '0.7.3' );

$GLOBALS['lunara_review_import_test'] = array(
    'posts' => array(
        10 => array( 'post_type' => 'review', 'post_status' => 'draft', 'post_title' => '', 'post_content' => '', 'post_excerpt' => '' ),
        20 => array( 'post_type' => 'review', 'post_status' => 'draft', 'post_title' => 'Worked Draft', 'post_content' => '<!-- wp:paragraph --><p>Existing work.</p><!-- /wp:paragraph -->', 'post_excerpt' => '' ),
        30 => array( 'post_type' => 'review', 'post_status' => 'publish', 'post_title' => '', 'post_content' => '', 'post_excerpt' => '' ),
        31 => array( 'post_type' => 'review', 'post_status' => 'future', 'post_title' => '', 'post_content' => '', 'post_excerpt' => '' ),
        32 => array( 'post_type' => 'review', 'post_status' => 'private', 'post_title' => '', 'post_content' => '', 'post_excerpt' => '' ),
        33 => array( 'post_type' => 'review', 'post_status' => 'auto-draft', 'post_title' => '', 'post_content' => '', 'post_excerpt' => '' ),
        40 => array( 'post_type' => 'review', 'post_status' => 'draft', 'post_title' => '', 'post_content' => '', 'post_excerpt' => '' ),
        50 => array( 'post_type' => 'review', 'post_status' => 'draft', 'post_title' => '', 'post_content' => '', 'post_excerpt' => '' ),
        60 => array( 'post_type' => 'review', 'post_status' => 'draft', 'post_title' => '', 'post_content' => '', 'post_excerpt' => '' ),
        70 => array( 'post_type' => 'review', 'post_status' => 'draft', 'post_title' => '', 'post_content' => '', 'post_excerpt' => '' ),
    ),
    'meta'                 => array(),
    'acf'                  => array(),
    'sync_calls'           => array(),
    'fail_meta_key'        => '',
    'fail_acf_key'         => '',
    'fail_complete_record' => false,
    'enqueued_scripts'     => array(),
    'enqueued_styles'      => array(),
    'localized_scripts'    => array(),
);

class WP_Post {
    public $ID;
    public $post_type;
    public $post_status;
    public $post_title;
    public $post_content;
    public $post_excerpt;

    public function __construct( $id, $data ) {
        $this->ID           = $id;
        $this->post_type    = $data['post_type'];
        $this->post_status  = $data['post_status'];
        $this->post_title   = $data['post_title'];
        $this->post_content = $data['post_content'];
        $this->post_excerpt = $data['post_excerpt'];
    }
}

class WP_Error {
    private $code;
    private $message;
    private $data;

    public function __construct( $code, $message, $data = array() ) {
        $this->code    = $code;
        $this->message = $message;
        $this->data    = $data;
    }

    public function get_error_code() { return $this->code; }
    public function get_error_message() { return $this->message; }
    public function get_error_data() { return $this->data; }
}

class Lunara_Review_Import_Test_Request {
    private $params;
    public function __construct( $params ) { $this->params = $params; }
    public function get_param( $key ) { return isset( $this->params[ $key ] ) ? $this->params[ $key ] : null; }
}

class Lunara_Core {
    private static $instance;
    public static function instance() {
        if ( ! self::$instance ) { self::$instance = new self(); }
        return self::$instance;
    }
    public function sync_review_archive_terms( $review_id ) {
        $GLOBALS['lunara_review_import_test']['sync_calls'][] = (int) $review_id;
    }
}

final class Lunara_Debrief_Studio {
    public static function pairing_preview_html( $review_id, $pairings ) {
        $titles = array();
        foreach ( $pairings as $pairing ) {
            $titles[] = isset( $pairing['title'] ) ? $pairing['title'] : '';
        }
        return '<div class="lunara-pair-preview">' . esc_html( implode( '|', $titles ) ) . '</div>';
    }
}

function __( $text ) { return $text; }
function absint( $value ) { return abs( (int) $value ); }
function wp_unslash( $value ) { return $value; }
function wp_slash( $value ) { return $value; }
function sanitize_key( $value ) { return strtolower( preg_replace( '/[^a-z0-9_\-]/', '', (string) $value ) ); }
function sanitize_textarea_field( $value ) { return trim( strip_tags( (string) $value ) ); }
function current_user_can() { return true; }
function get_current_user_id() { return 7; }
function current_time() { return '2026-07-13 01:02:03'; }
function get_edit_post_link( $post_id ) { return 'https://example.test/wp-admin/post.php?post=' . (int) $post_id; }
function rest_ensure_response( $value ) { return $value; }
function is_wp_error( $value ) { return $value instanceof WP_Error; }
function esc_attr( $value ) { return htmlspecialchars( (string) $value, ENT_QUOTES, 'UTF-8' ); }
function esc_html( $value ) { return htmlspecialchars( (string) $value, ENT_QUOTES, 'UTF-8' ); }
function esc_html_e( $value ) { echo esc_html( $value ); }
function esc_attr_e( $value ) { echo esc_attr( $value ); }
function esc_url_raw( $value ) { return (string) $value; }
function rest_url( $path ) { return 'https://example.test/wp-json/' . ltrim( $path, '/' ); }
function wp_create_nonce() { return 'nonce'; }

function get_current_screen() {
    return (object) array( 'post_type' => 'review' );
}

function wp_enqueue_style( $handle, $src, $dependencies, $version ) {
    $GLOBALS['lunara_review_import_test']['enqueued_styles'][ $handle ] = compact( 'src', 'dependencies', 'version' );
}

function wp_enqueue_script( $handle, $src, $dependencies, $version, $footer ) {
    $GLOBALS['lunara_review_import_test']['enqueued_scripts'][ $handle ] = compact( 'src', 'dependencies', 'version', 'footer' );
}

function wp_localize_script( $handle, $name, $value ) {
    $GLOBALS['lunara_review_import_test']['localized_scripts'][ $handle ] = compact( 'name', 'value' );
}

function get_post( $post_id ) {
    $data = isset( $GLOBALS['lunara_review_import_test']['posts'][ $post_id ] ) ? $GLOBALS['lunara_review_import_test']['posts'][ $post_id ] : null;
    return $data ? new WP_Post( $post_id, $data ) : null;
}

function wp_update_post( $data ) {
    $post_id = (int) $data['ID'];
    if ( ! isset( $GLOBALS['lunara_review_import_test']['posts'][ $post_id ] ) ) { return new WP_Error( 'missing', 'Missing post.' ); }
    foreach ( array( 'post_title', 'post_content', 'post_excerpt' ) as $key ) {
        if ( array_key_exists( $key, $data ) ) {
            $GLOBALS['lunara_review_import_test']['posts'][ $post_id ][ $key ] = $data[ $key ];
        }
    }
    return $post_id;
}

function get_post_meta( $post_id, $key, $single = true ) {
    return isset( $GLOBALS['lunara_review_import_test']['meta'][ $post_id ][ $key ] )
        ? $GLOBALS['lunara_review_import_test']['meta'][ $post_id ][ $key ]
        : '';
}

function update_post_meta( $post_id, $key, $value ) {
    if ( $key === $GLOBALS['lunara_review_import_test']['fail_meta_key'] ) {
        return false;
    }
    if (
        Lunara_Review_Draft_Import_Admin::SOURCE_META === $key
        && $GLOBALS['lunara_review_import_test']['fail_complete_record']
        && is_array( $value )
        && isset( $value['status'] )
        && 'complete' === $value['status']
    ) {
        return false;
    }
    $GLOBALS['lunara_review_import_test']['meta'][ $post_id ][ $key ] = $value;
    return true;
}

function lunara_review_import_acf_name( $field ) {
    $map = array(
        'field_lunara_review_theme_echo_movie'       => 'theme_echo_movie',
        'field_lunara_review_theme_echo_note'        => 'theme_echo_note',
        'field_lunara_review_counter_program_movie'  => 'counter_program_movie',
        'field_lunara_review_counter_program_note'   => 'counter_program_note',
        'field_lunara_review_career_context_movie'   => 'career_context_movie',
        'field_lunara_review_career_context_note'    => 'career_context_note',
        'field_lunara_review_debrief_status'          => 'debrief_status',
    );
    return isset( $map[ $field ] ) ? $map[ $field ] : $field;
}

function get_field( $field, $post_id, $format_value = true ) {
    $name = lunara_review_import_acf_name( $field );
    return isset( $GLOBALS['lunara_review_import_test']['acf'][ $post_id ][ $name ] )
        ? $GLOBALS['lunara_review_import_test']['acf'][ $post_id ][ $name ]
        : '';
}

function update_field( $field, $value, $post_id ) {
    if ( $field === $GLOBALS['lunara_review_import_test']['fail_acf_key'] ) {
        return false;
    }
    $name = lunara_review_import_acf_name( $field );
    $GLOBALS['lunara_review_import_test']['acf'][ $post_id ][ $name ] = $value;
    $GLOBALS['lunara_review_import_test']['acf'][ $post_id ][ $field ] = $value;
    return true;
}

function get_posts( $args ) {
    $value = isset( $args['meta_query'][1]['value'] ) ? $args['meta_query'][1]['value'] : '';
    if ( 'tt0317910' === $value ) { return array( 101 ); }
    if ( 'tt5013056' === $value ) { return array( 103 ); }
    return array();
}

function lunara_review_import_assert_same( $expected, $actual, $message ) {
    if ( $expected !== $actual ) {
        fwrite( STDERR, "FAIL: {$message}\nExpected: " . var_export( $expected, true ) . "\nActual: " . var_export( $actual, true ) . "\n" );
        exit( 1 );
    }
}

function lunara_review_import_assert_true( $condition, $message ) {
    if ( ! $condition ) {
        fwrite( STDERR, "FAIL: {$message}\n" );
        exit( 1 );
    }
}

require dirname( __DIR__ ) . '/includes/class-lunara-debrief-contract.php';
require dirname( __DIR__ ) . '/includes/class-lunara-review-draft-parser.php';
require dirname( __DIR__ ) . '/includes/class-lunara-review-draft-document.php';
require dirname( __DIR__ ) . '/includes/class-lunara-review-draft-import-admin.php';

$specimen_path = getenv( 'LUNARA_REVIEW_DRAFT_SPECIMEN' );
if ( $specimen_path && is_file( $specimen_path ) ) {
    $html = file_get_contents( $specimen_path );
} else {
    $html = <<<'HTML'
<!-- Oppenheimer (2023) -- tt15398776 -->
<em>A portable standfirst for the Review importer integration test.</em>
<p>Paragraph one.</p>
<p>Paragraph two.</p>
<p>Paragraph three.</p>
<p>Paragraph four.</p>
<p>Paragraph five.</p>
<p>Paragraph six.</p>
<p>Paragraph seven.</p>
<hr>
<strong>LUNARA DEBRIEF</strong>
<ul>
<li><strong>Score:</strong> 5/5</li>
<li><strong>Year:</strong> 2023</li>
<li><strong>Where to Watch:</strong> Theatrical and 70mm IMAX (Universal) | Streaming and VOD</li>
<li><strong>Theme Echo:</strong> <em>The Fog of War</em> (2003) [tt0317910] -- It carries the moral question forward.</li>
<li><strong>Counter-Program:</strong> <em>Godzilla</em> (1954) [tt0047034] -- It tells the story from beneath the bomb.</li>
<li><strong>Career Context:</strong> <em>Dunkirk</em> (2017) [tt5013056] -- It reveals Nolan's earlier experiment with war as sensation.</li>
</ul>
<!-- LUNARA EXCERPT: A portable excerpt for the integration test. -->
<!-- LUNARA METADATA
Director / Writer: Christopher Nolan (adapted from American Prometheus)
Runtime: 180 min
Studio / Distributor: Universal Pictures
Composer: Ludwig Goransson
HTML;
}

$preview = Lunara_Review_Draft_Import_Admin::rest_preview( new Lunara_Review_Import_Test_Request( array( 'review_id' => 10, 'html' => $html ) ) );

lunara_review_import_assert_true( ! is_wp_error( $preview ) && $preview['valid'], 'A valid specimen must produce an import preview.' );
lunara_review_import_assert_same( 7, $preview['summary']['paragraphCount'], 'The preview must report seven native body paragraphs.' );
lunara_review_import_assert_same( 'missing', $preview['resolutions']['counter_program']['status'], 'Missing local companion Movies must remain visible.' );
lunara_review_import_assert_same( 'published', $preview['resolutions']['theme_echo']['status'], 'Published local companion Movies must resolve without HTTP.' );
lunara_review_import_assert_true( ! $preview['existing']['content'], 'A fresh Review draft must be eligible for apply.' );
lunara_review_import_assert_true( ! $preview['existing']['recoverable'] && ! $preview['existing']['alreadyImported'], 'A fresh Review must not report recovery or completion state.' );
lunara_review_import_assert_true( ! array_filter( $preview['existing']['debrief']['theme_echo'] ), 'A fresh Review must report empty Debrief role fields.' );
lunara_review_import_assert_same(
    array( 'score', 'year', 'imdbId', 'whereToWatch', 'standfirst', 'director', 'runtime', 'studio' ),
    array_keys( $preview['existing']['fields'] ),
    'Preview state must expose every canonical field-occupancy flag used by the editor.'
);
lunara_review_import_assert_true( ! array_filter( $preview['existing']['fields'] ), 'A fresh Review draft must report all canonical fields as empty.' );
lunara_review_import_assert_true( isset( $preview['summary']['metadata']['composer'] ), 'Preview summaries must preserve unsupported source metadata.' );
lunara_review_import_assert_true( false !== strpos( $preview['debriefPreviewHtml'], 'lunara-pair-preview' ), 'The REST preview must include the shared rich Debrief preview HTML.' );
lunara_review_import_assert_true( false !== strpos( $preview['debriefPreviewHtml'], 'The Fog of War' ), 'The rich preview must receive the parsed companion films before apply.' );

$unsupported_format = Lunara_Review_Draft_Import_Admin::rest_preview( new Lunara_Review_Import_Test_Request( array( 'review_id' => 10, 'source_format' => 'doc', 'html' => $html ) ) );
lunara_review_import_assert_true( is_wp_error( $unsupported_format ) && 'unsupported_source_format' === $unsupported_format->get_error_code(), 'Legacy binary DOC and unknown source formats must be rejected explicitly.' );

$GLOBALS['lunara_review_import_test']['acf'][70] = array(
    'theme_echo_movie' => 777,
    'theme_echo_note'  => 'Existing curated reason.',
);
$preserved_preview = Lunara_Review_Draft_Import_Admin::rest_preview( new Lunara_Review_Import_Test_Request( array( 'review_id' => 70, 'html' => $html ) ) );
lunara_review_import_assert_true( $preserved_preview['existing']['debrief']['theme_echo']['movie'], 'Preview must disclose an existing Debrief Movie that apply will preserve.' );
lunara_review_import_assert_true( $preserved_preview['existing']['debrief']['theme_echo']['reason'], 'Preview must disclose an existing Debrief reason that apply will preserve.' );

$apply = Lunara_Review_Draft_Import_Admin::rest_apply( new Lunara_Review_Import_Test_Request( array( 'review_id' => 10, 'html' => $html ) ) );
lunara_review_import_assert_true( ! is_wp_error( $apply ) && $apply['valid'] && ! $apply['alreadyImported'], 'The first apply must succeed.' );

$saved = $GLOBALS['lunara_review_import_test']['posts'][10];
lunara_review_import_assert_same( 'Oppenheimer', $saved['post_title'], 'An empty Review title must be filled.' );
lunara_review_import_assert_same( 7, substr_count( $saved['post_content'], '<!-- wp:paragraph -->' ), 'The saved body must contain seven native paragraph blocks.' );
lunara_review_import_assert_true( false === strpos( $saved['post_content'], 'LUNARA DEBRIEF' ), 'The inline Debrief must never enter post_content.' );
lunara_review_import_assert_true( false === strpos( $saved['post_content'], 'The Fog of War' ), 'Companion films must remain outside article prose.' );
lunara_review_import_assert_same( '5', get_post_meta( 10, '_lunara_score', true ), 'Score fractions must normalize for the Review field.' );
lunara_review_import_assert_same( 'tt15398776', get_post_meta( 10, '_lunara_imdb_title_id', true ), 'The reviewed-film IMDb identity must be editable metadata.' );
lunara_review_import_assert_same( 'Christopher Nolan', get_post_meta( 10, '_lunara_director', true ), 'Director / Writer metadata must map to the existing Director field.' );
lunara_review_import_assert_same( 101, $GLOBALS['lunara_review_import_test']['acf'][10]['field_lunara_review_theme_echo_movie'], 'Published Theme Echo Movie must populate the canonical relation.' );
lunara_review_import_assert_same( 103, $GLOBALS['lunara_review_import_test']['acf'][10]['field_lunara_review_career_context_movie'], 'Published Career Context Movie must populate the canonical relation.' );
lunara_review_import_assert_true( ! isset( $GLOBALS['lunara_review_import_test']['acf'][10]['field_lunara_review_counter_program_movie'] ), 'A missing Movie must not create a false canonical relation.' );
lunara_review_import_assert_same( Lunara_Debrief_Contract::STATUS_INCOMPLETE, $GLOBALS['lunara_review_import_test']['acf'][10][ Lunara_Debrief_Contract::FIELD_STATUS_KEY ], 'Imported Debriefs must remain incomplete until local Movies are valid.' );
lunara_review_import_assert_true( isset( $GLOBALS['lunara_review_import_test']['meta'][10][ Lunara_Review_Draft_Import_Admin::SOURCE_META ]['metadata']['composer'] ), 'Unsupported credits must remain in the protected source record.' );
lunara_review_import_assert_same( 'complete', $GLOBALS['lunara_review_import_test']['meta'][10][ Lunara_Review_Draft_Import_Admin::SOURCE_META ]['status'], 'A successful import must finish with a verified complete source record.' );
lunara_review_import_assert_same( array( 10 ), $GLOBALS['lunara_review_import_test']['sync_calls'], 'Archive terms must sync after structured fields are filled.' );

$second = Lunara_Review_Draft_Import_Admin::rest_apply( new Lunara_Review_Import_Test_Request( array( 'review_id' => 10, 'html' => $html ) ) );
lunara_review_import_assert_true( ! is_wp_error( $second ) && $second['alreadyImported'], 'Re-importing the same source must be an idempotent no-op.' );
lunara_review_import_assert_same( 7, substr_count( $GLOBALS['lunara_review_import_test']['posts'][10]['post_content'], '<!-- wp:paragraph -->' ), 'Idempotent re-import must not duplicate blocks.' );

$blocked = Lunara_Review_Draft_Import_Admin::rest_apply( new Lunara_Review_Import_Test_Request( array( 'review_id' => 20, 'html' => $html ) ) );
lunara_review_import_assert_true( is_wp_error( $blocked ) && 'review_not_empty' === $blocked->get_error_code(), 'A worked-on Review must reject destructive import.' );
lunara_review_import_assert_true( ! Lunara_Review_Draft_Import_Admin::rest_permission( new Lunara_Review_Import_Test_Request( array( 'review_id' => 30 ) ) ), 'Published Reviews must never permit draft import.' );

foreach ( array( 30, 31, 32, 33 ) as $blocked_review_id ) {
    lunara_review_import_assert_true(
        ! Lunara_Review_Draft_Import_Admin::rest_permission( new Lunara_Review_Import_Test_Request( array( 'review_id' => $blocked_review_id ) ) ),
        'Only saved Review drafts may pass the shared REST permission gate.'
    );
}

$published_preview = Lunara_Review_Draft_Import_Admin::rest_preview( new Lunara_Review_Import_Test_Request( array( 'review_id' => 30, 'html' => $html ) ) );
lunara_review_import_assert_true( is_wp_error( $published_preview ) && 'review_not_draft' === $published_preview->get_error_code(), 'Published Review callbacks must retain the draft-only boundary even when called directly.' );

ob_start();
Lunara_Review_Draft_Import_Admin::render_meta_box( get_post( 30 ) );
$published_box = ob_get_clean();
lunara_review_import_assert_true( false !== strpos( $published_box, 'only while this Review is saved with Draft status' ), 'Published editors must receive a clear drafts-only notice.' );
lunara_review_import_assert_true( false === strpos( $published_box, 'data-lunara-review-import-preview' ), 'Published editors must not receive active importer controls.' );

$_GET['post'] = 30;
Lunara_Review_Draft_Import_Admin::enqueue_assets( 'post.php' );
lunara_review_import_assert_same( array(), $GLOBALS['lunara_review_import_test']['enqueued_scripts'], 'Published Review editors must not load importer assets.' );

$_GET['post'] = 10;
Lunara_Review_Draft_Import_Admin::enqueue_assets( 'post.php' );
lunara_review_import_assert_same(
    array( 'wp-data' ),
    $GLOBALS['lunara_review_import_test']['enqueued_scripts']['lunara-core-review-draft-import']['dependencies'],
    'The importer script must declare wp-data before using the block-editor store.'
);
lunara_review_import_assert_true(
    isset( $GLOBALS['lunara_review_import_test']['localized_scripts']['lunara-core-review-draft-import']['value']['strings']['saveFirst'] ),
    'The localized save-first warning must remain available to JavaScript.'
);

$GLOBALS['lunara_review_import_test']['fail_acf_key'] = 'field_lunara_review_theme_echo_note';
$acf_failed = Lunara_Review_Draft_Import_Admin::rest_apply( new Lunara_Review_Import_Test_Request( array( 'review_id' => 40, 'html' => $html ) ) );
lunara_review_import_assert_true( is_wp_error( $acf_failed ) && 'review_import_write_failed' === $acf_failed->get_error_code(), 'A failed ACF write must stop the import with a verified error.' );
lunara_review_import_assert_same( '', $GLOBALS['lunara_review_import_test']['posts'][40]['post_content'], 'The body must remain untouched when an ACF write fails.' );
lunara_review_import_assert_same( 'pending', get_post_meta( 40, Lunara_Review_Draft_Import_Admin::SOURCE_META, true )['status'], 'An ACF failure must retain a repairable pending source record.' );
lunara_review_import_assert_same( '', get_post_meta( 40, Lunara_Review_Draft_Import_Admin::HASH_META, true ), 'A failed ACF transaction must not mark the source hash complete.' );
$html_b = str_replace( '<!-- Oppenheimer (2023) -- tt15398776 -->', '<!-- Alternate Film (2024) -- tt12345678 -->', $html );
$partial_before_mismatch = serialize( array(
    $GLOBALS['lunara_review_import_test']['posts'][40],
    $GLOBALS['lunara_review_import_test']['meta'][40],
    isset( $GLOBALS['lunara_review_import_test']['acf'][40] ) ? $GLOBALS['lunara_review_import_test']['acf'][40] : array(),
) );
$GLOBALS['lunara_review_import_test']['fail_acf_key'] = '';
$mismatch = Lunara_Review_Draft_Import_Admin::rest_apply( new Lunara_Review_Import_Test_Request( array( 'review_id' => 40, 'html' => $html_b ) ) );
lunara_review_import_assert_true( is_wp_error( $mismatch ) && 'review_import_pending_source_mismatch' === $mismatch->get_error_code(), 'A pending partial import must reject every different source hash.' );
lunara_review_import_assert_same(
    $partial_before_mismatch,
    serialize( array(
        $GLOBALS['lunara_review_import_test']['posts'][40],
        $GLOBALS['lunara_review_import_test']['meta'][40],
        isset( $GLOBALS['lunara_review_import_test']['acf'][40] ) ? $GLOBALS['lunara_review_import_test']['acf'][40] : array(),
    ) ),
    'A different source must not mutate any pending Review state.'
);
$GLOBALS['lunara_review_import_test']['fail_acf_key'] = '';
$acf_repaired = Lunara_Review_Draft_Import_Admin::rest_apply( new Lunara_Review_Import_Test_Request( array( 'review_id' => 40, 'html' => $html ) ) );
lunara_review_import_assert_true( ! is_wp_error( $acf_repaired ) && $acf_repaired['valid'], 'Retrying the same source must repair a partial ACF run.' );

$GLOBALS['lunara_review_import_test']['fail_meta_key'] = '_lunara_year';
$meta_failed = Lunara_Review_Draft_Import_Admin::rest_apply( new Lunara_Review_Import_Test_Request( array( 'review_id' => 50, 'html' => $html ) ) );
lunara_review_import_assert_true( is_wp_error( $meta_failed ) && 'review_import_write_failed' === $meta_failed->get_error_code(), 'A failed canonical meta write must stop the import.' );
lunara_review_import_assert_same( '', $GLOBALS['lunara_review_import_test']['posts'][50]['post_content'], 'The body must remain untouched when canonical metadata fails.' );
lunara_review_import_assert_same( 'pending', get_post_meta( 50, Lunara_Review_Draft_Import_Admin::SOURCE_META, true )['status'], 'A metadata failure must retain a repairable pending source record.' );
lunara_review_import_assert_same( '', get_post_meta( 50, Lunara_Review_Draft_Import_Admin::HASH_META, true ), 'A failed metadata transaction must not mark the source hash complete.' );
$GLOBALS['lunara_review_import_test']['fail_meta_key'] = '';
$meta_repaired = Lunara_Review_Draft_Import_Admin::rest_apply( new Lunara_Review_Import_Test_Request( array( 'review_id' => 50, 'html' => $html ) ) );
lunara_review_import_assert_true( ! is_wp_error( $meta_repaired ) && $meta_repaired['valid'], 'Retrying the same source must repair a partial metadata run.' );

$GLOBALS['lunara_review_import_test']['fail_complete_record'] = true;
$complete_failed = Lunara_Review_Draft_Import_Admin::rest_apply( new Lunara_Review_Import_Test_Request( array( 'review_id' => 60, 'html' => $html ) ) );
lunara_review_import_assert_true( is_wp_error( $complete_failed ) && 'review_import_write_failed' === $complete_failed->get_error_code(), 'A failed complete-record promotion must be reported.' );
lunara_review_import_assert_same( 7, substr_count( $GLOBALS['lunara_review_import_test']['posts'][60]['post_content'], '<!-- wp:paragraph -->' ), 'The body may exist only after every structured write has succeeded.' );
lunara_review_import_assert_same( 'pending', get_post_meta( 60, Lunara_Review_Draft_Import_Admin::SOURCE_META, true )['status'], 'A failed promotion must leave the same source pending.' );
lunara_review_import_assert_same( '', get_post_meta( 60, Lunara_Review_Draft_Import_Admin::HASH_META, true ), 'The source hash must remain unset until a complete record is verified.' );
$recovery_preview = Lunara_Review_Draft_Import_Admin::rest_preview( new Lunara_Review_Import_Test_Request( array( 'review_id' => 60, 'html' => $html ) ) );
lunara_review_import_assert_true( $recovery_preview['existing']['recoverable'], 'Preview must expose same-source recovery after the body was safely persisted.' );
$GLOBALS['lunara_review_import_test']['fail_complete_record'] = false;
$complete_repaired = Lunara_Review_Draft_Import_Admin::rest_apply( new Lunara_Review_Import_Test_Request( array( 'review_id' => 60, 'html' => $html ) ) );
lunara_review_import_assert_true( ! is_wp_error( $complete_repaired ) && $complete_repaired['valid'], 'An exact persisted body from the same source must be recoverable.' );
lunara_review_import_assert_same( 7, substr_count( $GLOBALS['lunara_review_import_test']['posts'][60]['post_content'], '<!-- wp:paragraph -->' ), 'Recovery must not duplicate the persisted body.' );
lunara_review_import_assert_same( 'complete', get_post_meta( 60, Lunara_Review_Draft_Import_Admin::SOURCE_META, true )['status'], 'Recovery must promote the source record to complete.' );
lunara_review_import_assert_same( hash( 'sha256', $html ), get_post_meta( 60, Lunara_Review_Draft_Import_Admin::HASH_META, true ), 'Only a verified complete recovery may mark the source hash.' );

$bootstrap = file_get_contents( dirname( __DIR__ ) . '/lunara-core.php' );
$admin     = file_get_contents( dirname( __DIR__ ) . '/includes/class-lunara-review-draft-import-admin.php' );
$script    = file_get_contents( dirname( __DIR__ ) . '/assets/js/lunara-review-draft-import-admin.js' );
lunara_review_import_assert_true( false !== strpos( $bootstrap, "Version: 0.7.3" ), 'Core must identify the Review importer release.' );
lunara_review_import_assert_true( false !== strpos( $bootstrap, "'revisions'" ), 'Review CPT must retain native WordPress revisions.' );
lunara_review_import_assert_true( false !== strpos( $bootstrap, '/lunara/v1/review-draft-import/' ), 'Importer REST loading must remain exact-prefix private.' );
lunara_review_import_assert_true( false === strpos( $admin, 'add_shortcode' ), 'The importer must not create a shortcode dependency.' );
lunara_review_import_assert_true( false === strpos( $admin, 'wp_remote_' ), 'Applying a Review draft must not call remote providers.' );
lunara_review_import_assert_true( false !== strpos( $script, 'isEditedPostDirty' ), 'The block editor must refuse imports while changes are unsaved.' );
lunara_review_import_assert_true( false !== strpos( $script, "tinymce.on('AddEditor'" ), 'All classic and ACF TinyMCE editors must participate in dirty-state protection.' );
lunara_review_import_assert_true( false !== strpos( $script, '_lunaraFormDirty' ), 'Dynamic and hidden-backed meta-box controls must participate in dirty-state protection.' );
lunara_review_import_assert_true( false !== strpos( $script, '_lunaraFormGeneration' ), 'Meta-box changes during a save request must keep the importer dirty.' );
lunara_review_import_assert_true( false !== strpos( $script, '!== saveStartGeneration' ), 'The queued post-save baseline refresh must recheck for last-moment meta-box changes.' );
lunara_review_import_assert_true( false !== strpos( $script, 'didPostSaveRequestSucceed' ), 'A successful block-editor save must refresh the importer baseline.' );
lunara_review_import_assert_true( false !== strpos( $script, 'preview.existing.recoverable' ), 'The editor must expose the server-supported same-source recovery path.' );
lunara_review_import_assert_true( false !== strpos( $script, 'source_format' ), 'The editor must identify HTML, DOCX, and Google export sources for the REST contract.' );
lunara_review_import_assert_true( false !== strpos( $script, 'readAsArrayBuffer' ), 'The editor must read Word and Google export packages as bounded binary input.' );
lunara_review_import_assert_true( false !== strpos( $script, "getData('text/html')" ), 'Rich clipboard HTML from Word and Google Docs must be captured before plain-text fallback.' );
lunara_review_import_assert_true( false !== strpos( $script, 'normalizeClipboardHtml' ), 'Rich clipboard wrappers must be normalized before the strict HTML parser runs.' );
lunara_review_import_assert_true( false !== strpos( $script, 'debriefPreviewHtml' ), 'The editor must render the server-generated rich Debrief preview before apply.' );

echo "Review draft import admin regression checks passed.\n";
