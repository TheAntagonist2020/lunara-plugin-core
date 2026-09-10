<?php
/** Provider-to-meta-to-render contract. No network or WordPress writes. */
define( 'ABSPATH', __DIR__ . '/' );
$GLOBALS['hydration'] = array( 'meta' => array( 10 => array( '_lunara_imdb_title_id' => 'tt21285562' ) ), 'thumbnails' => array(), 'calls' => array() );
class WP_Error {
    public function __construct( private $code, private $message, private $data = array() ) {}
    public function get_error_code() { return $this->code; }
    public function get_error_data() { return $this->data; }
}
class Lunara_Movie_Repository {}
class Lunara_Movie_Importer {
    public function __construct( ...$args ) {}
    public function import_draft( $candidate, $context ) { return array( 'movie_id' => 20 ); }
}
class Hydration_Gateway {
    public function get_candidate_by_imdb( $id ) {
        throw new RuntimeException( 'The Review artwork worker must not require full OMDb enrichment.' );
    }
    public function get_artwork_by_imdb( $id ) {
        $GLOBALS['hydration']['calls'][] = $id;
        return $GLOBALS['hydration']['response'];
    }
}
function __( $text ) { return $text; }
function absint( $value ) { return abs( (int) $value ); }
function sanitize_key( $value ) { return strtolower( preg_replace( '/[^a-z0-9_\-]/i', '', $value ) ); }
function sanitize_text_field( $value ) { return strip_tags( $value ); }
function esc_url_raw( $url ) { return $url; }
function wp_parse_url( $url, $part = -1 ) { return parse_url( $url, $part ); }
function wp_http_validate_url( $url ) { return filter_var( $url, FILTER_VALIDATE_URL ); }
function get_post_meta( $id, $key, $single = true ) { return $GLOBALS['hydration']['meta'][$id][$key] ?? ''; }
function update_post_meta( $id, $key, $value ) { $GLOBALS['hydration']['meta'][$id][$key] = $value; return true; }
function delete_post_meta( $id, $key ) { unset( $GLOBALS['hydration']['meta'][$id][$key] ); }
function get_post_type( $id ) { return 10 === $id ? 'review' : 'movie'; }
function get_the_title( $id ) { return 'The Dog Stars — Review'; }
function get_post_thumbnail_id( $id ) { return $GLOBALS['hydration']['thumbnails'][$id] ?? 0; }
function set_post_thumbnail( $id, $attachment ) { $GLOBALS['hydration']['thumbnails'][$id] = $attachment; return true; }
function wp_get_attachment_image_url( $id ) { return 'https://example.test/' . $id . '.jpg'; }
function get_posts( $args ) {
    if ( 'attachment' === $args['post_type'] ) return array( str_contains( $args['meta_value'], 'poster' ) ? 91 : 92 );
    return array( 20 );
}
function apply_filters( $hook, $value ) { return 'lunara_movie_provider_gateway_service' === $hook ? new Hydration_Gateway() : $value; }
function is_wp_error( $value ) { return $value instanceof WP_Error; }
function do_action( ...$args ) {}
function hydration_assert( $condition, $message ) { if ( ! $condition ) throw new RuntimeException( $message ); }
require dirname( __DIR__ ) . '/includes/class-lunara-review-image-studio.php';
$GLOBALS['hydration']['response'] = array( 'imdb_title_id' => 'tt21285562', 'title' => 'The Dog Stars', 'poster_path' => '/poster.jpg', 'backdrop_path' => '/backdrop.jpg' );
$result = Lunara_Review_Image_Studio::hydrate_review_identity( 10, 'tt21285562' );
hydration_assert( 'ready' === $result['status'], 'A complete exact-identity response must hydrate successfully.' );
hydration_assert( array( 'tt21285562' ) === $GLOBALS['hydration']['calls'], 'The provider must receive the canonical IMDb ID.' );
$poster = 'https://image.tmdb.org/t/p/original/poster.jpg';
hydration_assert( $poster === get_post_meta( 10, '_lunara_tmdb_poster_url' ), 'TMDB poster paths must populate the exact canonical Review meta key.' );
hydration_assert( $poster === get_post_meta( 20, 'tmdb_poster_url' ), 'The linked dossier must receive the same verified poster.' );
hydration_assert( $poster === Lunara_Review_Image_Studio::resolve_slot( 10, 'card' )['url'], 'The saved TMDB poster must reach public/editor card resolution.' );
hydration_assert( 92 === Lunara_Review_Image_Studio::resolve_slot( 10, 'hero_banner' )['attachment_id'], 'The backdrop must remain separate from the card poster.' );
$GLOBALS['hydration']['response'] = new WP_Error( 'lunara_movie_provider_unavailable', 'https://secret.invalid/?apikey=DO_NOT_KEEP', array( 'provider' => 'tmdb', 'token' => 'DO_NOT_KEEP' ) );
$result = Lunara_Review_Image_Studio::hydrate_review_identity( 10, 'tt21285562' );
$issue = get_post_meta( 10, Lunara_Review_Image_Studio::PROVIDER_ISSUE );
hydration_assert( str_starts_with( $issue, 'TMDB: ' ), 'A provider failure must identify the failing service for the editor.' );
hydration_assert( ! str_contains( json_encode( array( $result, $GLOBALS['hydration']['meta'] ) ), 'DO_NOT_KEEP' ), 'Raw provider errors and credentials must never be retained.' );
hydration_assert( $poster === get_post_meta( 10, '_lunara_tmdb_poster_url' ), 'A failed retry must retain existing artwork.' );
$GLOBALS['hydration']['response'] = array( 'imdb_title_id' => 'tt21285562', 'title' => 'The Dog Stars', 'poster_path' => '/poster.jpg', 'backdrop_path' => '/backdrop.jpg' );
Lunara_Review_Image_Studio::hydrate_review_identity( 10, 'tt21285562' );
hydration_assert( '' === get_post_meta( 10, Lunara_Review_Image_Studio::PROVIDER_ISSUE ), 'A successful retry must clear the old failure explanation.' );
echo "Review artwork hydration regression checks passed.\n";
