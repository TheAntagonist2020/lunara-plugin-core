<?php
/**
 * Review Studio workspace for the Classic Editor.
 *
 * @package Lunara_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Consolidate Review-owned tools without changing their storage or save hooks.
 */
final class Lunara_Review_Workspace {

	/** Register the private editor-only surface. */
	public static function init() {
		add_action( 'edit_form_after_editor', array( __CLASS__, 'render_workspace' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ), 100 );
		add_filter( 'admin_body_class', array( __CLASS__, 'body_class' ) );
	}

	/**
	 * Render a progressively enhanced shell after the Review body editor.
	 *
	 * The existing meta boxes remain untouched when JavaScript is unavailable.
	 * The editor script moves only known Lunara-owned boxes into the shell.
	 *
	 * @param WP_Post $post Current post.
	 */
	public static function render_workspace( $post ) {
		if ( ! ( $post instanceof WP_Post ) || 'review' !== $post->post_type || ! current_user_can( 'edit_post', $post->ID ) ) {
			return;
		}

		$tabs = array(
			'intake'  => array(
				'label'       => __( 'Review Intake', 'lunara-core' ),
				'description' => __( 'Paste, upload, and confirm automatic draft parsing.', 'lunara-core' ),
			),
			'details' => array(
				'label'       => __( 'Film Details', 'lunara-core' ),
				'description' => __( 'Score, identity, availability, and publication details.', 'lunara-core' ),
			),
			'debrief' => array(
				'label'       => __( 'Debrief', 'lunara-core' ),
				'description' => __( 'Curate the Echo, Counter, and Context.', 'lunara-core' ),
			),
			'images'   => array(
				'label'       => __( 'Images', 'lunara-core' ),
				'description' => __( 'Control every Review image position from one place.', 'lunara-core' ),
			),
		);
		?>
		<section id="lunara-review-workspace" class="lunara-review-workspace" data-lunara-review-workspace hidden>
			<header class="lunara-review-workspace__masthead">
				<div class="lunara-review-workspace__identity">
					<span class="lunara-review-workspace__eyebrow"><?php esc_html_e( 'Lunara Film', 'lunara-core' ); ?></span>
					<h2><?php esc_html_e( 'Review Studio', 'lunara-core' ); ?></h2>
					<p><?php esc_html_e( 'Write or paste the review above, then finish the complete editorial package here without leaving this screen.', 'lunara-core' ); ?></p>
				</div>
				<a class="lunara-review-workspace__copy-link" href="#post-body-content"><?php esc_html_e( 'Back to review copy', 'lunara-core' ); ?></a>
			</header>

			<div class="lunara-review-workspace__assurances" aria-label="<?php esc_attr_e( 'Review workflow safeguards', 'lunara-core' ); ?>">
				<span><?php esc_html_e( 'Classic Editor preserved', 'lunara-core' ); ?></span>
				<span><?php esc_html_e( 'Automatic parsing active', 'lunara-core' ); ?></span>
				<span><?php esc_html_e( 'No content migration', 'lunara-core' ); ?></span>
			</div>

			<div class="lunara-review-workspace__tabs" role="tablist" aria-label="<?php esc_attr_e( 'Review Studio sections', 'lunara-core' ); ?>">
				<?php foreach ( $tabs as $slug => $tab ) : ?>
					<button
						type="button"
						class="lunara-review-workspace__tab"
						id="lunara-review-workspace-tab-<?php echo esc_attr( $slug ); ?>"
						role="tab"
						aria-controls="lunara-review-workspace-panel-<?php echo esc_attr( $slug ); ?>"
						aria-selected="false"
						tabindex="-1"
						data-lunara-review-tab="<?php echo esc_attr( $slug ); ?>"
					>
						<strong><?php echo esc_html( $tab['label'] ); ?></strong>
						<span><?php echo esc_html( $tab['description'] ); ?></span>
					</button>
				<?php endforeach; ?>
			</div>

			<div class="lunara-review-workspace__stage" data-lunara-review-stage></div>
			<p class="lunara-review-workspace__empty" data-lunara-review-empty hidden>
				<?php esc_html_e( 'The Review tools are still loading. Your article and saved fields are unaffected.', 'lunara-core' ); ?>
			</p>
		</section>
		<?php
	}

	/** Enqueue the workspace only on new and existing Review editor screens. */
	public static function enqueue_assets( $hook ) {
		if ( ! in_array( $hook, array( 'post.php', 'post-new.php' ), true ) || ! function_exists( 'get_current_screen' ) ) {
			return;
		}

		$screen = get_current_screen();
		if ( ! $screen || 'review' !== $screen->post_type ) {
			return;
		}

		$css_path = LUNARA_CORE_DIR . 'assets/css/lunara-review-workspace.css';
		$js_path  = LUNARA_CORE_DIR . 'assets/js/lunara-review-workspace.js';

		wp_enqueue_style(
			'lunara-core-review-workspace',
			LUNARA_CORE_URL . 'assets/css/lunara-review-workspace.css',
			array(),
			file_exists( $css_path ) ? (string) filemtime( $css_path ) : LUNARA_CORE_VERSION
		);

		wp_enqueue_script(
			'lunara-core-review-workspace',
			LUNARA_CORE_URL . 'assets/js/lunara-review-workspace.js',
			array(),
			file_exists( $js_path ) ? (string) filemtime( $js_path ) : LUNARA_CORE_VERSION,
			true
		);
	}

	/**
	 * Add a narrow styling hook to Review editors only.
	 *
	 * @param string $classes Existing admin body classes.
	 * @return string
	 */
	public static function body_class( $classes ) {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( $screen && 'review' === $screen->post_type && in_array( $screen->base, array( 'post', 'post-new' ), true ) ) {
			$classes .= ' lunara-review-editor';
		}

		return $classes;
	}
}
