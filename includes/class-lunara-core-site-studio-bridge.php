<?php
/**
 * Stable, redacted handoff between Lunara Core and Site Studio consumers.
 *
 * @package Lunara_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Keep plugin-owned editorial destinations stable without exposing records.
 */
final class Lunara_Core_Site_Studio_Bridge {

	/** Register the inert theme-extension hook. */
	public static function init() {
		add_filter( 'lunara_site_studio_surfaces', array( __CLASS__, 'contribute_surfaces' ), 10, 1 );
	}

	/**
	 * Resolve the canonical Review Studio destination.
	 *
	 * @param mixed $review_id Optional Review ID.
	 * @param mixed $tab       Optional bounded destination selector.
	 * @return string
	 */
	public static function review_studio_admin_url( $review_id = 0, $tab = '' ) {
		$fallback  = admin_url( 'edit.php?post_type=review' );
		$review_id = is_scalar( $review_id ) ? absint( $review_id ) : 0;
		$tab       = is_scalar( $tab ) ? sanitize_key( (string) $tab ) : '';

		if ( $review_id > 0 ) {
			$post = get_post( $review_id );
			if ( ! ( $post instanceof WP_Post ) || 'review' !== $post->post_type || ! current_user_can( 'edit_post', $review_id ) ) {
				return $fallback;
			}

			$edit_url = get_edit_post_link( $review_id, 'raw' );
			if ( ! is_string( $edit_url ) || '' === trim( $edit_url ) ) {
				return $fallback;
			}

			return preg_replace( '/#.*$/', '', $edit_url ) . '#lunara-review-workspace';
		}

		if ( 0 === $review_id && 'new' === $tab && current_user_can( 'edit_posts' ) ) {
			return admin_url( 'post-new.php?post_type=review' ) . '#lunara-review-workspace';
		}

		return $fallback;
	}

	/**
	 * Report only the bounded Review Studio integration contract.
	 *
	 * @return array<string,mixed>
	 */
	public static function review_studio_status() {
		$registered = post_type_exists( 'review' );

		return array(
			'available' => $registered,
			'version'   => LUNARA_CORE_VERSION,
			'cpt'       => array(
				'key'        => 'review',
				'registered' => $registered,
			),
			'actions'   => array(
				'choose' => $registered,
				'new'    => $registered,
				'edit'   => $registered,
			),
		);
	}

	/**
	 * Add routing metadata without reading or copying Review records.
	 *
	 * @param array<string,array<string,mixed>> $surfaces Existing surfaces.
	 * @return array<string,array<string,mixed>>
	 */
	public static function contribute_surfaces( $surfaces ) {
		$surfaces = is_array( $surfaces ) ? $surfaces : array();
		$surfaces['core-review-studio'] = array(
			'id'                    => 'core-review-studio',
			'group'                 => __( 'Editorial', 'lunara-core' ),
			'label'                 => __( 'Review Studio', 'lunara-core' ),
			'description'           => __( 'Write a Review and finish its film details, Debrief, and images in the canonical editor.', 'lunara-core' ),
			'aliases'               => array( 'review library', 'new review', 'edit review', 'film identity', 'score', 'debrief', 'review images' ),
			'owner'                 => 'plugin:lunara-core',
			'kind'                  => 'content',
			'capability'            => 'edit_posts',
			'supports_preview'      => false,
			'preview_route'         => '',
			'preview_query_arg'     => '',
			'adapter_factory'       => '',
			'state_schema_callback' => '',
			'admin_url'             => 'edit.php?post_type=review',
			'dependency_callback'   => array( __CLASS__, 'dependency' ),
			'status_callback'       => array( __CLASS__, 'registry_status' ),
			'danger_level'          => 'none',
			'sections'              => array( 'review-intake', 'film-details', 'debrief', 'images' ),
			'classic_url'           => 'edit.php?post_type=review',
			'renderer'              => '',
		);

		return $surfaces;
	}

	/**
	 * Report whether the canonical Review content model is registered.
	 *
	 * @param array<string,mixed>|null $surface Normalized registry metadata, unused.
	 * @return array<string,mixed>
	 */
	public static function dependency( $surface = null ) {
		if ( ! post_type_exists( 'review' ) ) {
			return array(
				'available' => false,
				'reason'    => 'review_cpt_unavailable',
				'message'   => __( 'The Review Studio is unavailable because the canonical Review post type is not registered.', 'lunara-core' ),
			);
		}

		return array(
			'available' => true,
			'reason'    => '',
			'message'   => __( 'Lunara Core Review Studio is available.', 'lunara-core' ),
		);
	}

	/**
	 * Project the stable status into the theme's allowlisted status envelope.
	 *
	 * @param array<string,mixed>|null $surface Normalized registry metadata, unused.
	 * @return array<string,string>
	 */
	public static function registry_status( $surface = null ) {
		$status = self::review_studio_status();

		return array(
			'state'        => $status['available'] ? 'ready' : 'unavailable',
			'label'        => $status['available'] ? __( 'Review Studio available', 'lunara-core' ) : __( 'Review Studio unavailable', 'lunara-core' ),
			'message'      => $status['available']
				? sprintf( __( 'Lunara Core %s provides the canonical Review editor.', 'lunara-core' ), $status['version'] )
				: __( 'The canonical Review post type is not registered.', 'lunara-core' ),
			'action_label' => __( 'Open Review Studio', 'lunara-core' ),
			'url'          => self::review_studio_admin_url(),
		);
	}
}

if ( ! function_exists( 'lunara_core_review_studio_admin_url' ) ) {
	/**
	 * Public Review Studio handoff API.
	 *
	 * @param mixed $review_id Optional Review ID.
	 * @param mixed $tab       Optional bounded destination selector.
	 * @return string
	 */
	function lunara_core_review_studio_admin_url( $review_id = 0, $tab = '' ) {
		return Lunara_Core_Site_Studio_Bridge::review_studio_admin_url( $review_id, $tab );
	}
}

if ( ! function_exists( 'lunara_core_review_studio_status' ) ) {
	/**
	 * Public redacted Review Studio status API.
	 *
	 * @return array<string,mixed>
	 */
	function lunara_core_review_studio_status() {
		return Lunara_Core_Site_Studio_Bridge::review_studio_status();
	}
}
