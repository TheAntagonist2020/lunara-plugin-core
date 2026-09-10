<?php
/** Artwork must queue after importers finish writing the canonical identity. */
define( 'ABSPATH', __DIR__ . '/' );
define( 'HOUR_IN_SECONDS', 3600 );
$GLOBALS['artwork_queue'] = array( 'hooks' => array(), 'meta' => array(), 'events' => array() );
function add_action( $hook, $callback, $priority = 10, $accepted_args = 1 ) {
    $GLOBALS['artwork_queue']['hooks'][ $hook ][] = array( $callback, $accepted_args );
}
function is_admin() { return false; }
function absint( $value ) { return abs( (int) $value ); }
function get_post_type( $id ) { return 99 === $id ? 'journal' : 'review'; }
function get_post_meta( $id, $key, $single = true ) { return $GLOBALS['artwork_queue']['meta'][ $id ][ $key ] ?? ''; }
function update_post_meta( $id, $key, $value ) {
    $exists = isset( $GLOBALS['artwork_queue']['meta'][ $id ][ $key ] );
    $old = get_post_meta( $id, $key );
    if ( $exists && $old === $value ) { return false; }
    $GLOBALS['artwork_queue']['meta'][ $id ][ $key ] = $value;
    foreach ( $GLOBALS['artwork_queue']['hooks'][ $exists ? 'updated_post_meta' : 'added_post_meta' ] ?? array() as $hook ) {
        call_user_func_array( $hook[0], array_slice( array( 1, $id, $key, $value ), 0, $hook[1] ) );
    }
    return true;
}
function wp_next_scheduled( $hook, $args ) { return $GLOBALS['artwork_queue']['events'][ $hook . ':' . json_encode( $args ) ] ?? false; }
function wp_schedule_single_event( $time, $hook, $args ) { $GLOBALS['artwork_queue']['events'][ $hook . ':' . json_encode( $args ) ] = $time; return true; }
function queue_assert( $condition, $message ) { if ( ! $condition ) { throw new RuntimeException( $message ); } }
function queued( $id, $imdb ) { return wp_next_scheduled( Lunara_Review_Image_Studio::HYDRATE_HOOK, array( $id, $imdb ) ); }
require dirname( __DIR__ ) . '/includes/class-lunara-review-image-studio.php';
Lunara_Review_Image_Studio::init();

Lunara_Review_Image_Studio::queue_review( 10 );
queue_assert( empty( $GLOBALS['artwork_queue']['events'] ), 'An insert without IMDb metadata must not queue a guessed identity.' );
update_post_meta( 10, '_lunara_imdb_title_id', 'tt21285562' );
queue_assert( queued( 10, 'tt21285562' ), 'Writing the IMDb ID after the post-save hook must queue artwork retrieval.' );
queue_assert( 'queued' === get_post_meta( 10, Lunara_Review_Image_Studio::HYDRATE_STATUS ), 'Late metadata must expose queued status.' );
$count = count( $GLOBALS['artwork_queue']['events'] );
Lunara_Review_Image_Studio::queue_review( 10 );
update_post_meta( 10, '_lunara_year', '2026' );
update_post_meta( 99, '_lunara_imdb_title_id', 'tt21285562' );
update_post_meta( 11, '_lunara_imdb_title_id', 'invalid' );
queue_assert( $count === count( $GLOBALS['artwork_queue']['events'] ), 'Repeated saves, unrelated metadata, non-Reviews and invalid IDs must not add jobs.' );

update_post_meta( 10, '_lunara_imdb_title_id', 'tt22084616' );
queue_assert( queued( 10, 'tt22084616' ), 'Changing the canonical identity must queue the new film.' );
$before = $GLOBALS['artwork_queue']['meta'];
$stale = Lunara_Review_Image_Studio::hydrate_review_identity( 10, 'tt21285562' );
queue_assert( 'stale_identity' === $stale['status'], 'A queued job for an old identity must stop before fetching or writing artwork.' );
queue_assert( $before === $GLOBALS['artwork_queue']['meta'], 'An obsolete artwork job must leave the current Review untouched.' );

echo "Review artwork queue regression checks passed.\n";
