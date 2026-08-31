<?php
/**
 * Review Image Studio contract regression.
 */

define( 'ABSPATH', __DIR__ . '/fixtures/' );

$GLOBALS['lunara_image_studio_test'] = array(
	'meta' => array(
		10 => array(
			'_lunara_imdb_title_id' => 'tt1234567',
		),
		11 => array(
			'_lunara_review_hero_banner' => 'https://example.test/legacy-hero.jpg',
		),
		20 => array(
			'imdb_title_id' => 'tt1234567',
			'backdrop_image' => 202,
		),
	),
	'post_types' => array( 10 => 'review', 11 => 'review', 20 => 'movie' ),
	'thumbnails' => array( 10 => 203, 20 => 201 ),
	'urls' => array(
		201 => 'https://lunarafilm.test/uploads/poster.jpg',
		202 => 'https://lunarafilm.test/uploads/backdrop.jpg',
		203 => 'https://lunarafilm.test/uploads/review-featured.jpg',
		204 => 'https://lunarafilm.test/uploads/custom-card.jpg',
	),
	'registered_meta' => array(),
);

function __( $text ) {
	return $text;
}

function sanitize_key( $value ) {
	return strtolower( preg_replace( '/[^a-z0-9_\-]/', '', (string) $value ) );
}

function absint( $value ) {
	return abs( (int) $value );
}

function esc_url_raw( $url ) {
	return (string) $url;
}

function get_post_meta( $post_id, $key ) {
	return $GLOBALS['lunara_image_studio_test']['meta'][ $post_id ][ $key ] ?? '';
}

function get_post_type( $post_id ) {
	return $GLOBALS['lunara_image_studio_test']['post_types'][ $post_id ] ?? '';
}

function get_posts( $args ) {
	unset( $args );
	return array( 20 );
}

function get_post_thumbnail_id( $post_id ) {
	return $GLOBALS['lunara_image_studio_test']['thumbnails'][ $post_id ] ?? 0;
}

function wp_get_attachment_image_url( $attachment_id ) {
	return $GLOBALS['lunara_image_studio_test']['urls'][ $attachment_id ] ?? false;
}

function register_post_meta( $post_type, $key, $args ) {
	$GLOBALS['lunara_image_studio_test']['registered_meta'][ $post_type ][ $key ] = $args;
}

function current_user_can() {
	return true;
}

function lunara_image_studio_assert_same( $expected, $actual, $message ) {
	if ( $expected !== $actual ) {
		fwrite( STDERR, $message . "\nExpected: " . var_export( $expected, true ) . "\nActual: " . var_export( $actual, true ) . "\n" );
		exit( 1 );
	}
}

function lunara_image_studio_assert_true( $condition, $message ) {
	if ( ! $condition ) {
		fwrite( STDERR, $message . "\n" );
		exit( 1 );
	}
}

require dirname( __DIR__ ) . '/includes/class-lunara-review-image-studio.php';

$card = Lunara_Review_Image_Studio::resolve_slot( 10, 'card' );
lunara_image_studio_assert_same( 'auto', $card['mode'], 'A new Review card must default to automatic artwork.' );
lunara_image_studio_assert_same( 201, $card['attachment_id'], 'Automatic card artwork must inherit the Film Dossier poster.' );
lunara_image_studio_assert_same( 'dossier_poster', $card['source'], 'The source must identify the Film Dossier poster.' );

$hero = Lunara_Review_Image_Studio::resolve_slot( 10, 'hero_banner' );
lunara_image_studio_assert_same( 202, $hero['attachment_id'], 'Automatic hero artwork must inherit the Film Dossier backdrop.' );
lunara_image_studio_assert_same( 'dossier_backdrop', $hero['source'], 'The source must identify the Film Dossier backdrop.' );

$context = Lunara_Review_Image_Studio::resolve_slot( 10, 'context_shot' );
lunara_image_studio_assert_same( 'off', $context['mode'], 'Body stills must default off so Reviews never duplicate the same automatic backdrop.' );
lunara_image_studio_assert_same( '', $context['url'], 'An off slot must render no image.' );

$legacy = Lunara_Review_Image_Studio::resolve_slot( 11, 'hero_banner' );
lunara_image_studio_assert_same( 'custom', $legacy['mode'], 'Existing URL-based Review artwork must migrate behaviorally as a custom choice.' );
lunara_image_studio_assert_same( 'https://example.test/legacy-hero.jpg', $legacy['url'], 'Legacy Review artwork must remain visible.' );

$GLOBALS['lunara_image_studio_test']['meta'][10]['_lunara_review_image_mode_card'] = 'custom';
$GLOBALS['lunara_image_studio_test']['meta'][10]['_lunara_review_image_card_id']   = 204;
$custom = Lunara_Review_Image_Studio::resolve_slot( 10, 'card' );
lunara_image_studio_assert_same( 204, $custom['attachment_id'], 'A custom Review card must override Film Dossier artwork.' );

$GLOBALS['lunara_image_studio_test']['meta'][10]['_lunara_review_image_mode_hero_banner'] = 'off';
$off = Lunara_Review_Image_Studio::resolve_slot( 10, 'hero_banner' );
lunara_image_studio_assert_same( '', $off['url'], 'Off must suppress the hero even when the Film Dossier has a backdrop.' );

Lunara_Review_Image_Studio::register_meta();
lunara_image_studio_assert_same( 10, count( $GLOBALS['lunara_image_studio_test']['registered_meta']['review'] ), 'Five slots must expose both mode and attachment ID through registered Review meta.' );

$bootstrap = file_get_contents( dirname( __DIR__ ) . '/lunara-core.php' );
$studio    = file_get_contents( dirname( __DIR__ ) . '/includes/class-lunara-review-image-studio.php' );
$script    = file_get_contents( dirname( __DIR__ ) . '/assets/js/lunara-review-image-studio.js' );
$importer  = file_get_contents( dirname( __DIR__ ) . '/includes/class-lunara-review-draft-import-admin.php' );
lunara_image_studio_assert_true( false !== strpos( $bootstrap, 'Version: 0.8.9' ), 'Core must identify the Site Studio bridge release.' );
lunara_image_studio_assert_true( false !== strpos( $bootstrap, 'Lunara_Review_Image_Studio::init()' ), 'Core must initialize the Review Image Studio.' );
lunara_image_studio_assert_true( false !== strpos( $studio, 'media_sideload_image' ), 'Remote provider artwork must be localizable into the Media Library.' );
lunara_image_studio_assert_true( false !== strpos( $studio, 'set_post_thumbnail' ), 'Review and Film Dossier poster synchronization must remain explicit and supported.' );
lunara_image_studio_assert_true( false !== strpos( $studio, 'field_lunara_movie_backdrop_image' ), 'Film Dossier backdrop synchronization must use the canonical ACF field.' );
lunara_image_studio_assert_true( false !== strpos( $studio, 'wp_schedule_single_event' ), 'Saved IMDb identities must hydrate outside the editor request.' );
lunara_image_studio_assert_true( false !== strpos( $studio, 'get_candidate_by_imdb' ), 'Identity hydration must call the provider gateway by canonical IMDb ID.' );
lunara_image_studio_assert_true( false !== strpos( $studio, 'import_draft' ), 'Identity hydration must reuse or create a draft Film Dossier.' );
lunara_image_studio_assert_true( false !== strpos( $studio, 'https://image.tmdb.org/t/p/original' ), 'Provider paths must resolve to original-size artwork before Media Library localization.' );
lunara_image_studio_assert_true( false !== strpos( $importer, 'Lunara_Review_Image_Studio::queue_review( $review_id )' ), 'Both document import and Classic parsing must explicitly queue artwork after metadata is saved.' );
lunara_image_studio_assert_true( false !== strpos( $script, 'wp.media' ), 'The Classic Editor must use the native Media Library picker.' );

echo "Review Image Studio regression checks passed.\n";
