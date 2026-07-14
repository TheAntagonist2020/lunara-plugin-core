<?php
/**
 * Dependency-free regression checks for the Review CPT admin labels.
 *
 * Run with: php tests/review-cpt-labels-regression.php
 */

define( 'ABSPATH', __DIR__ . '/' );

function plugin_dir_path( $file ) {
	return dirname( $file ) . '/';
}

function plugin_dir_url( $file ) {
	return 'https://example.test/wp-content/plugins/lunara-core/';
}

function __( $text ) {
	return $text;
}

function add_action() {}
function register_activation_hook() {}
function register_deactivation_hook() {}
function is_admin() {
	return false;
}
function apply_filters( $tag, $value ) {
	return $value;
}
function add_filter() {}

function register_post_type( $post_type, $args ) {
	$GLOBALS['lunara_review_cpt_labels_test'][ $post_type ] = $args;
}

function lunara_review_cpt_labels_assert_same( $expected, $actual, $message ) {
	if ( $expected !== $actual ) {
		throw new RuntimeException(
			$message . "\nExpected: " . var_export( $expected, true ) . "\nActual: " . var_export( $actual, true )
		);
	}
}

function lunara_review_cpt_labels_assert_true( $condition, $message ) {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

require dirname( __DIR__ ) . '/lunara-core.php';

Lunara_Core::register_reviews_cpt();

$registered = $GLOBALS['lunara_review_cpt_labels_test'] ?? array();
$review     = $registered['review'] ?? array();
$labels = $review['labels'] ?? array();

lunara_review_cpt_labels_assert_true( array_key_exists( 'review', $registered ), 'The Review CPT key must remain review.' );
lunara_review_cpt_labels_assert_same( 'Review', $labels['menu_name'] ?? '', 'The admin menu must expose the singular Review label.' );
lunara_review_cpt_labels_assert_same( 'All Reviews', $labels['all_items'] ?? '', 'The list view must remain explicitly discoverable as All Reviews.' );
lunara_review_cpt_labels_assert_same( 'Review', $labels['name_admin_bar'] ?? '', 'The admin-bar New menu must use the singular Review label.' );
lunara_review_cpt_labels_assert_same( true, $review['public'] ?? false, 'Reviews must remain public.' );
lunara_review_cpt_labels_assert_same( true, $review['has_archive'] ?? false, 'Reviews must retain their archive.' );
lunara_review_cpt_labels_assert_same( 'reviews', $review['rewrite']['slug'] ?? '', 'The public Reviews URL must remain stable.' );

echo "Review CPT label regression checks passed.\n";
