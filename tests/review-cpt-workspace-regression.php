<?php
/**
 * Canonical singular Review CPT and Review Library regression contract.
 */

$root      = dirname( __DIR__ );
$bootstrap = file_get_contents( $root . '/lunara-core.php' );
$admin     = file_get_contents( $root . '/includes/class-lunara-review-admin.php' );

function lunara_review_cpt_assert( $condition, $message ) {
	if ( ! $condition ) {
		fwrite( STDERR, $message . "\n" );
		exit( 1 );
	}
}

lunara_review_cpt_assert( false !== strpos( $bootstrap, 'Version: 0.8.13' ), 'Core must identify the review poster source release.' );
lunara_review_cpt_assert( (bool) preg_match( "/register_post_type\\(\\s*'review'/", $bootstrap ), 'The canonical post type key must remain singular `review`.' );
lunara_review_cpt_assert( ! preg_match( "/register_post_type\\(\\s*'reviews'/", $bootstrap ), 'Core must never create a second plural Review post type.' );
lunara_review_cpt_assert( (bool) preg_match( "/'menu_name'\\s*=>\\s*__\\(\\s*'Review'/", $bootstrap ), 'The WordPress menu must be singular Review.' );
lunara_review_cpt_assert( false !== strpos( $bootstrap, "'all_items'                => __( 'Review Library'" ), 'The content inventory must be named Review Library.' );
lunara_review_cpt_assert( false !== strpos( $bootstrap, "'has_archive'       => true" ), 'The established Review archive contract must remain unchanged.' );
lunara_review_cpt_assert( false !== strpos( $bootstrap, "'rewrite'           => array( 'slug' => 'reviews' )" ), 'The established /reviews/ rewrite slug must remain unchanged.' );
lunara_review_cpt_assert( false === strpos( $bootstrap, "'rest_base'" ), 'The established default /wp/v2/review REST base must remain unchanged.' );
lunara_review_cpt_assert( false !== strpos( $bootstrap, "'menu_position'     => 20" ), 'Review must remain easy to find above Film Dossiers.' );
lunara_review_cpt_assert( false !== strpos( $bootstrap, "'delete_with_user'  => false" ), 'Review content must survive account deletion workflows.' );
lunara_review_cpt_assert( false !== strpos( $bootstrap, "'show_admin_column' => true" ), 'Review archive taxonomies must be visible in wp-admin.' );
lunara_review_cpt_assert( false !== strpos( $bootstrap, "includes/class-lunara-review-admin.php" ), 'Core must load the dedicated Review Library admin module.' );

foreach ( array(
	'manage_review_posts_columns',
	'manage_review_posts_custom_column',
	'manage_edit-review_sortable_columns',
	'restrict_manage_posts',
	'pre_get_posts',
	'lunara_review_poster',
	'lunara_review_identity',
	'lunara_review_score',
	'lunara_review_debrief',
	'Film title — review headline',
) as $contract ) {
	lunara_review_cpt_assert( false !== strpos( $admin, $contract ), 'Missing Review Library contract: ' . $contract );
}

lunara_review_cpt_assert( false !== strpos( $admin, "array( 'ready', 'published' )" ), 'Debrief filters must support editorially actionable states.' );
lunara_review_cpt_assert( false !== strpos( $admin, "array( 'key' => 'debrief_status', 'compare' => 'NOT EXISTS' )" ), 'Incomplete filtering must include legacy Reviews with no saved status.' );

echo "Review CPT workspace regression checks passed.\n";
