<?php
/**
 * Exact-identity Review artwork backfill contract regression.
 */

$root      = dirname( __DIR__ );
$bootstrap = file_get_contents( $root . '/lunara-core.php' );
$studio    = file_get_contents( $root . '/includes/class-lunara-review-image-studio.php' );
$backfill  = file_get_contents( $root . '/includes/class-lunara-review-artwork-backfill.php' );

function lunara_artwork_backfill_assert( $condition, $message ) {
	if ( ! $condition ) {
		fwrite( STDERR, $message . "\n" );
		exit( 1 );
	}
}

lunara_artwork_backfill_assert( false !== strpos( $bootstrap, 'Version: 0.8.11' ), 'Core must identify the theme-mod coordinator release.' );
lunara_artwork_backfill_assert( false !== strpos( $bootstrap, 'class-lunara-review-artwork-backfill.php' ), 'Core must load the artwork backfill worker.' );
lunara_artwork_backfill_assert( false !== strpos( $bootstrap, 'Lunara_Review_Artwork_Backfill::init()' ), 'Core must initialize the artwork backfill worker.' );
lunara_artwork_backfill_assert( false !== strpos( $backfill, "'edit.php?post_type=review'" ), 'Artwork Audit must live beneath the singular Review menu.' );
lunara_artwork_backfill_assert( false !== strpos( $backfill, "'_lunara_imdb_title_id'" ), 'Every queued Review must be gated by canonical IMDb identity.' );
lunara_artwork_backfill_assert( false !== strpos( $backfill, 'refresh_review_artwork' ), 'The worker must call the exact-identity Review Image Studio refresh.' );
lunara_artwork_backfill_assert( false !== strpos( $backfill, 'wp_schedule_single_event' ), 'Provider calls must be paced through background single events.' );
lunara_artwork_backfill_assert( false !== strpos( $backfill, 'custom_protected' ), 'The census must disclose custom artwork protection.' );
lunara_artwork_backfill_assert( false !== strpos( $studio, "'refresh_managed'" ), 'Image hydration must expose the provider-managed refresh policy.' );
lunara_artwork_backfill_assert( false !== strpos( $studio, 'curated_art_preserved' ), 'Unmarked curated artwork must be protected and reported.' );
lunara_artwork_backfill_assert( false !== strpos( $studio, "'image.tmdb.org'" ), 'Only TMDb-source-marked attachments may be automatically refreshed.' );
lunara_artwork_backfill_assert( false !== strpos( $studio, 'is_review_slot_editorially_protected' ), 'Custom and unmarked Review artwork must block automatic replacement.' );
lunara_artwork_backfill_assert( false !== strpos( $backfill, "'paused' === \$job['status']" ), 'Paused jobs must resume without discarding completed work.' );
lunara_artwork_backfill_assert( false === strpos( $backfill, 'search_movie' ), 'The batch worker must never fall back to title-only search.' );

echo "Review artwork backfill regression checks passed.\n";
