<?php
/**
 * Dependency-free regression checks for the Review Studio workspace.
 *
 * Run with: php tests/review-workspace-regression.php
 */

$root      = dirname( __DIR__ );
$bootstrap = file_get_contents( $root . '/lunara-core.php' );
$workspace = file_get_contents( $root . '/includes/class-lunara-review-workspace.php' );
$script    = file_get_contents( $root . '/assets/js/lunara-review-workspace.js' );
$styles    = file_get_contents( $root . '/assets/css/lunara-review-workspace.css' );

function lunara_review_workspace_assert( $condition, $message ) {
	if ( ! $condition ) {
		fwrite( STDERR, "Assertion failed: {$message}\n" );
		exit( 1 );
	}
}

lunara_review_workspace_assert( false !== strpos( $bootstrap, 'Version: 0.8.10' ), 'Core must identify the Site Studio health release.' );
lunara_review_workspace_assert( false !== strpos( $bootstrap, 'includes/class-lunara-review-workspace.php' ), 'Core must load the workspace only through its admin bootstrap.' );
lunara_review_workspace_assert( false !== strpos( $bootstrap, 'Lunara_Review_Workspace::init()' ), 'Core must initialize the workspace.' );

lunara_review_workspace_assert( false !== strpos( $workspace, "add_action( 'edit_form_after_editor'" ), 'Workspace must sit immediately after the article editor.' );
lunara_review_workspace_assert( false !== strpos( $workspace, "add_action( 'admin_enqueue_scripts'" ), 'Workspace assets must use the admin enqueue hook.' );
lunara_review_workspace_assert( false !== strpos( $workspace, "'review' !== \$screen->post_type" ), 'Workspace assets must be gated to Review editors.' );
lunara_review_workspace_assert( false !== strpos( $workspace, "array( 'post.php', 'post-new.php' )" ), 'Workspace must support new and existing Reviews.' );
lunara_review_workspace_assert( false !== strpos( $workspace, 'data-lunara-review-workspace hidden' ), 'Progressive enhancement must leave the original boxes usable if JavaScript fails.' );
lunara_review_workspace_assert( false === strpos( $workspace, 'remove_meta_box' ), 'Workspace must not unregister or replace existing save surfaces.' );
lunara_review_workspace_assert( false === strpos( $workspace, 'save_post_review' ), 'Workspace must not introduce a competing persistence path.' );

foreach ( array( '#lunara_review_draft_import', '#lunara_debrief_meta', '#lunara_review_details_meta', '#acf-group_lunara_review_trinity', '#lunara-review-image-studio' ) as $selector ) {
	lunara_review_workspace_assert( false !== strpos( $script, $selector ), "Workspace must recognize {$selector}." );
}

lunara_review_workspace_assert( false !== strpos( $script, 'appendChild( box )' ), 'Workspace must move the existing boxes rather than clone their inputs.' );
lunara_review_workspace_assert( false !== strpos( $script, "classList.remove( 'hide-if-js' )" ), 'Workspace must recover Lunara panels hidden by an older Screen Options preference.' );
lunara_review_workspace_assert( false !== strpos( $script, "addEventListener( 'invalid'" ), 'Workspace must reveal a pane containing an invalid field.' );
lunara_review_workspace_assert( false !== strpos( $script, 'MutationObserver' ), 'Workspace must reveal asynchronous ACF validation errors.' );
lunara_review_workspace_assert( false !== strpos( $script, "event.key === 'ArrowRight'" ), 'Workspace tabs must support keyboard navigation.' );
lunara_review_workspace_assert( false !== strpos( $script, 'sessionStorage' ), 'Workspace should remember the active section across a save reload.' );

lunara_review_workspace_assert( false !== strpos( $styles, '.lunara-review-workspace__tabs' ), 'Workspace must ship its branded tab layout.' );
lunara_review_workspace_assert( false !== strpos( $styles, '@media screen and ( max-width: 782px )' ), 'Workspace must remain usable on narrow editor screens.' );
lunara_review_workspace_assert( false !== strpos( $styles, 'prefers-reduced-motion' ), 'Workspace must respect reduced-motion preferences.' );

echo "Review Studio workspace regression checks passed.\n";
