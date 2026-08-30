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

	const ARTWORK_HEALTH_OPTION         = 'lunara_core_review_artwork_health_snapshot';
	const ARTWORK_HEALTH_SCHEMA         = 'lunara-core-review-identity-artwork-health/v1';
	const ARTWORK_HEALTH_SCHEMA_VERSION = 1;
	const CAROUSEL_HEALTH_SCHEMA        = 'lunara-core-carousel-manager-health/v1';
	const SNAPSHOT_TTL                  = 86400;
	const MAX_TIMESTAMP                 = 4102444800;

	/** @var array<string,mixed>|null */
	private static $artwork_status_memo = null;

	/** @var array<string,bool> Verified payloads observed only inside an unresolved transaction in this request. */
	private static $artwork_uncommitted_rows = array();

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
	 * Resolve the canonical owner-side Carousel manager.
	 *
	 * @return string
	 */
	public static function carousel_manager_admin_url() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return '';
		}
		if ( ! self::carousel_owner_available() ) {
			return '';
		}
		if ( ! taxonomy_exists( 'lunara_slide_set' ) ) {
			return '';
		}

		return admin_url( 'themes.php?page=lunara-carousel-manager' );
	}

	/**
	 * Report bounded structural Carousel availability without enumerating content.
	 *
	 * @return array<string,mixed>
	 */
	public static function carousel_manager_status() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return self::unavailable_carousel_status();
		}
		if ( ! self::carousel_owner_available() ) {
			return self::unavailable_carousel_status();
		}

		$taxonomy_available = taxonomy_exists( 'lunara_slide_set' );

		return array(
			'schema'         => self::CAROUSEL_HEALTH_SCHEMA,
			'schema_version' => 1,
			'plugin_version' => LUNARA_CORE_VERSION,
			'available'      => $taxonomy_available,
			'known'          => true,
			'state'          => $taxonomy_available ? 'ready' : 'unavailable',
			'label'          => $taxonomy_available ? __( 'Carousel Manager available', 'lunara-core' ) : __( 'Carousel Manager unavailable', 'lunara-core' ),
			'message'        => $taxonomy_available
				? __( 'Lunara Core provides the canonical Carousel manager.', 'lunara-core' )
				: __( 'The Carousel structure is unavailable.', 'lunara-core' ),
			'admin_url'      => $taxonomy_available ? admin_url( 'themes.php?page=lunara-carousel-manager' ) : '',
			'capability'     => 'manage_options',
			'owner'          => array( 'available' => true ),
			'taxonomy'       => array( 'available' => $taxonomy_available ),
		);
	}

	/**
	 * Resolve Core's canonical Review Artwork Audit.
	 *
	 * @return string
	 */
	public static function review_identity_artwork_admin_url() {
		if ( ! current_user_can( 'edit_others_posts' ) ) {
			return '';
		}
		if ( ! self::artwork_owner_available() ) {
			return '';
		}
		if ( ! post_type_exists( 'review' ) ) {
			return '';
		}

		return admin_url( 'edit.php?post_type=review&page=lunara-review-artwork-audit' );
	}

	/**
	 * Read exactly one compact owner snapshot and return its fixed redacted DTO.
	 *
	 * @return array<string,mixed>
	 */
	public static function review_identity_artwork_status() {
		if ( ! current_user_can( 'edit_others_posts' ) ) {
			return self::unavailable_artwork_status();
		}
		if ( ! self::artwork_owner_available() ) {
			return self::unavailable_artwork_status();
		}
		if ( ! post_type_exists( 'review' ) ) {
			return self::unavailable_artwork_status();
		}

		if ( null !== self::$artwork_status_memo ) {
			return self::$artwork_status_memo;
		}

		$raw        = get_option( self::ARTWORK_HEALTH_OPTION, false );
		$normalized = self::normalize_stored_snapshot( $raw );
		$admin_url  = admin_url( 'edit.php?post_type=review&page=lunara-review-artwork-audit' );

		self::$artwork_status_memo = self::artwork_status_from_snapshot( $normalized, true, $admin_url );
		return self::$artwork_status_memo;
	}

	/**
	 * Write a complete compact owner snapshot with non-autoloaded exact readback.
	 *
	 * @param mixed $coverage    Current census projection.
	 * @param mixed $job         Already-loaded full job.
	 * @param mixed $credentials Narrow credential booleans.
	 * @param string|null $outcome Request-local resolved|failed|indeterminate outcome.
	 * @return bool False only when the desired verified row is proved non-durable;
	 *              true also covers an unreadable COMMIT outcome after exact
	 *              staged and promoted verification.
	 */
	public static function write_artwork_health_snapshot( $coverage, $job, $credentials, &$outcome = null ) {
		$now      = self::bounded_timestamp( time() );
		$snapshot = self::build_snapshot( $coverage, $job, $credentials, $now, min( self::MAX_TIMESTAMP, $now + self::SNAPSHOT_TTL ), false );

		$outcome                  = 'failed';
		self::$artwork_status_memo = null;
		if ( null === $snapshot ) {
			return false;
		}
		return self::persist_artwork_snapshot( $snapshot, $outcome );
	}

	/**
	 * Update only the compact projection of an already-loaded owner job.
	 *
	 * @param mixed $job Already-loaded full job.
	 * @return bool False only for a proved non-publication; see the public writer.
	 */
	public static function update_artwork_health_job_projection( $job ) {
		$prior_row = self::read_artwork_option_row();
		if ( ! $prior_row['read_ok'] ) {
			return false;
		}
		$prior_raw = $prior_row['exists'] ? self::unserialize_artwork_option( $prior_row['option_value'] ) : false;
		$existing  = self::normalize_stored_snapshot( $prior_raw );
		$now       = self::bounded_timestamp( time() );
		$generated = 'fresh' === $existing['snapshot_state'] || 'stale' === $existing['snapshot_state']
			? $existing['generated_at']
			: $now;
		$expires   = 'fresh' === $existing['snapshot_state'] || 'stale' === $existing['snapshot_state']
			? $existing['expires_at']
			: min( self::MAX_TIMESTAMP, $now + self::SNAPSHOT_TTL );
		$snapshot  = self::build_snapshot(
			$existing['coverage'],
			$job,
			$existing['credentials'],
			$generated,
			$expires,
			true
		);

		self::$artwork_status_memo = null;
		if ( null === $snapshot ) {
			return false;
		}
		return self::persist_artwork_snapshot( $snapshot );
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
		$surfaces['core-carousel-manager'] = array(
			'id'                    => 'core-carousel-manager',
			'group'                 => __( 'Content', 'lunara-core' ),
			'label'                 => __( 'Carousel Manager', 'lunara-core' ),
			'description'           => __( 'Manage Lunara Carousel slide sets and ordering in the canonical Core tool.', 'lunara-core' ),
			'aliases'               => array( 'carousel', 'slides', 'slide sets', 'homepage carousel' ),
			'owner'                 => 'plugin:lunara-core',
			'kind'                  => 'content',
			'capability'            => 'manage_options',
			'supports_preview'      => false,
			'preview_route'         => '',
			'preview_query_arg'     => '',
			'adapter_factory'       => '',
			'state_schema_callback' => '',
			'admin_url'             => 'themes.php?page=lunara-carousel-manager',
			'dependency_callback'   => array( __CLASS__, 'carousel_manager_dependency' ),
			'status_callback'       => array( __CLASS__, 'carousel_manager_registry_status' ),
			'danger_level'          => 'none',
			'sections'              => array(),
			'classic_url'           => 'themes.php?page=lunara-carousel-manager',
			'renderer'              => '',
		);
		$surfaces['core-review-identity-artwork'] = array(
			'id'                    => 'core-review-identity-artwork',
			'group'                 => __( 'Operations', 'lunara-core' ),
			'label'                 => __( 'Review Identity & Artwork', 'lunara-core' ),
			'description'           => __( 'Review redacted identity and artwork health, then continue in Core’s canonical audit tool.', 'lunara-core' ),
			'aliases'               => array( 'review artwork', 'review identity', 'posters', 'banners', 'artwork audit' ),
			'owner'                 => 'plugin:lunara-core',
			'kind'                  => 'operations',
			'capability'            => 'edit_others_posts',
			'supports_preview'      => false,
			'preview_route'         => '',
			'preview_query_arg'     => '',
			'adapter_factory'       => '',
			'state_schema_callback' => '',
			'admin_url'             => 'edit.php?post_type=review&page=lunara-review-artwork-audit',
			'dependency_callback'   => array( __CLASS__, 'review_identity_artwork_dependency' ),
			'status_callback'       => array( __CLASS__, 'review_identity_artwork_registry_status' ),
			'danger_level'          => 'caution',
			'sections'              => array(),
			'classic_url'           => 'edit.php?post_type=review&page=lunara-review-artwork-audit',
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

	/** @param mixed $surface Unused registry metadata. @return array<string,mixed> */
	public static function carousel_manager_dependency( $surface = null ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			return array( 'available' => false, 'reason' => 'unavailable', 'message' => __( 'This Lunara Core destination is unavailable.', 'lunara-core' ) );
		}
		if ( ! self::carousel_owner_available() ) {
			return array( 'available' => false, 'reason' => 'unavailable', 'message' => __( 'This Lunara Core destination is unavailable.', 'lunara-core' ) );
		}
		$available = taxonomy_exists( 'lunara_slide_set' );
		return array(
			'available' => $available,
			'reason'    => $available ? '' : 'carousel_structure_unavailable',
			'message'   => $available ? __( 'Lunara Core Carousel Manager is available.', 'lunara-core' ) : __( 'The Carousel structure is unavailable.', 'lunara-core' ),
		);
	}

	/** @param mixed $surface Unused registry metadata. @return array<string,mixed> */
	public static function carousel_manager_registry_status( $surface = null ) {
		$status = self::carousel_manager_status();
		return array(
			'state'        => $status['state'],
			'label'        => $status['label'],
			'message'      => $status['message'],
			'action_label' => __( 'Open Carousel Manager', 'lunara-core' ),
			'url'          => $status['admin_url'],
		);
	}

	/** @param mixed $surface Unused registry metadata. @return array<string,mixed> */
	public static function review_identity_artwork_dependency( $surface = null ) {
		if ( ! current_user_can( 'edit_others_posts' ) ) {
			return array( 'available' => false, 'reason' => 'unavailable', 'message' => __( 'This Lunara Core destination is unavailable.', 'lunara-core' ) );
		}
		$owner_available = self::artwork_owner_available();
		$available       = $owner_available && post_type_exists( 'review' );
		return array(
			'available' => $available,
			'reason'    => $available ? '' : ( $owner_available ? 'review_cpt_unavailable' : 'artwork_owner_unavailable' ),
			'message'   => $available ? __( 'Lunara Core Review Artwork Audit is available.', 'lunara-core' ) : __( 'The Review Artwork Audit is unavailable.', 'lunara-core' ),
		);
	}

	/** @param mixed $surface Unused registry metadata. @return array<string,mixed> */
	public static function review_identity_artwork_registry_status( $surface = null ) {
		$status = self::review_identity_artwork_status();
		return array(
			'state'        => $status['state'],
			'label'        => $status['label'],
			'message'      => $status['message'],
			'action_label' => __( 'Open Artwork Audit', 'lunara-core' ),
			'url'          => $status['admin_url'],
		);
	}

	/** @return array<string,mixed> */
	private static function unavailable_carousel_status() {
		return array(
			'schema'         => self::CAROUSEL_HEALTH_SCHEMA,
			'schema_version' => 1,
			'plugin_version' => LUNARA_CORE_VERSION,
			'available'      => false,
			'known'          => false,
			'state'          => 'unavailable',
			'label'          => __( 'Destination unavailable', 'lunara-core' ),
			'message'        => __( 'This Lunara Core destination is unavailable.', 'lunara-core' ),
			'admin_url'      => '',
			'capability'     => 'manage_options',
			'owner'          => array( 'available' => false ),
			'taxonomy'       => array( 'available' => false ),
		);
	}

	/** @return array<string,mixed> */
	private static function unavailable_artwork_status() {
		return array(
			'schema'         => self::ARTWORK_HEALTH_SCHEMA,
			'schema_version' => self::ARTWORK_HEALTH_SCHEMA_VERSION,
			'plugin_version' => LUNARA_CORE_VERSION,
			'available'      => false,
			'known'          => false,
			'state'          => 'unavailable',
			'label'          => __( 'Destination unavailable', 'lunara-core' ),
			'message'        => __( 'This Lunara Core destination is unavailable.', 'lunara-core' ),
			'updated_at'     => null,
			'admin_url'      => '',
			'capability'     => 'edit_others_posts',
			'coverage'       => self::unknown_coverage(),
			'job'            => self::unknown_job(),
			'credentials'    => self::unknown_credentials(),
			'snapshot'       => array( 'state' => 'missing', 'generated_at' => null, 'expires_at' => null ),
		);
	}

	/** @param array<string,mixed> $snapshot Normalized internal snapshot. @return array<string,mixed> */
	private static function artwork_status_from_snapshot( $snapshot, $owner_available, $admin_url ) {
		$snapshot_state = $snapshot['snapshot_state'];
		$fresh          = $owner_available && 'fresh' === $snapshot_state;
		$coverage       = $fresh ? $snapshot['coverage'] : self::unknown_coverage();
		$job            = $fresh ? $snapshot['job'] : self::unknown_job();
		$credentials    = $fresh ? $snapshot['credentials'] : self::unknown_credentials();
		$known          = $fresh && $coverage['known'] && $job['known'] && $credentials['known'];
		$state          = 'needs_attention';
		$label          = __( 'Health snapshot needed', 'lunara-core' );
		$message        = __( 'Refresh health snapshot in Core’s Artwork Audit.', 'lunara-core' );

		if ( ! $owner_available ) {
			$state   = 'unavailable';
			$label   = __( 'Review Artwork Audit unavailable', 'lunara-core' );
			$message = __( 'The Review Artwork Audit owner is unavailable.', 'lunara-core' );
		} elseif ( $known && 'running' === $job['status'] ) {
			$state   = 'needs_attention';
			$label   = __( 'Artwork backfill running', 'lunara-core' );
			$message = __( 'Core is processing the owner-managed artwork backfill.', 'lunara-core' );
		} elseif ( $known && self::artwork_is_ready( $coverage, $job, $credentials ) ) {
			$state   = 'ready';
			$label   = __( 'Review identity and artwork ready', 'lunara-core' );
			$message = __( 'The last owner snapshot reports complete identity and artwork coverage.', 'lunara-core' );
		} elseif ( $known ) {
			$state   = 'needs_attention';
			$label   = __( 'Review identity or artwork needs attention', 'lunara-core' );
			$message = __( 'Open Core’s Artwork Audit for owner-managed details and actions.', 'lunara-core' );
		}

		return array(
			'schema'         => self::ARTWORK_HEALTH_SCHEMA,
			'schema_version' => self::ARTWORK_HEALTH_SCHEMA_VERSION,
			'plugin_version' => LUNARA_CORE_VERSION,
			'available'      => (bool) $owner_available,
			'known'          => $known,
			'state'          => $state,
			'label'          => $label,
			'message'        => $message,
			'updated_at'     => $fresh ? $snapshot['generated_at'] : null,
			'admin_url'      => $admin_url,
			'capability'     => 'edit_others_posts',
			'coverage'       => $coverage,
			'job'            => $job,
			'credentials'    => $credentials,
			'snapshot'       => array(
				'state'        => $snapshot_state,
				'generated_at' => $snapshot['generated_at'],
				'expires_at'   => $snapshot['expires_at'],
			),
		);
	}

	/** @param mixed $raw Stored option. @return array<string,mixed> */
	private static function normalize_stored_snapshot( $raw ) {
		$empty = array(
			'snapshot_state' => 'missing',
			'generated_at'   => null,
			'expires_at'     => null,
			'coverage'       => self::unknown_coverage(),
			'job'            => self::unknown_job(),
			'credentials'    => self::unknown_credentials(),
		);
		if ( false === $raw || null === $raw || array() === $raw ) {
			return $empty;
		}
		if ( ! is_array( $raw ) ) {
			$empty['snapshot_state'] = 'failed';
			return $empty;
		}
		if ( ! isset( $raw['schema'], $raw['schema_version'] ) || self::ARTWORK_HEALTH_SCHEMA !== $raw['schema'] || self::ARTWORK_HEALTH_SCHEMA_VERSION !== $raw['schema_version'] ) {
			$empty['snapshot_state'] = 'incompatible';
			return $empty;
		}
		$generated = isset( $raw['generated_at'] ) ? self::strict_timestamp( $raw['generated_at'] ) : null;
		$expires   = isset( $raw['expires_at'] ) ? self::strict_timestamp( $raw['expires_at'] ) : null;
		if ( ! isset( $raw['coverage'], $raw['job'], $raw['credentials'] ) || ! is_array( $raw['coverage'] ) || ! is_array( $raw['job'] ) || ! is_array( $raw['credentials'] ) || null === $generated || null === $expires || $generated <= 0 || $expires < $generated ) {
			$empty['snapshot_state'] = 'failed';
			return $empty;
		}
		if (
			( isset( $raw['persistence_state'] ) && 'verified' !== $raw['persistence_state'] )
			|| isset( self::$artwork_uncommitted_rows[ hash( 'sha256', self::serialize_artwork_option( $raw ) ) ] )
		) {
			$empty['snapshot_state'] = 'failed';
			$empty['generated_at']   = $generated;
			$empty['expires_at']     = $expires;
			return $empty;
		}
		if ( isset( $raw['snapshot_state'] ) && 'failed' === $raw['snapshot_state'] ) {
			$empty['snapshot_state'] = 'failed';
			$empty['generated_at']   = $generated;
			$empty['expires_at']     = $expires;
			return $empty;
		}

		$coverage    = self::normalize_coverage( $raw['coverage'], true );
		$job         = self::normalize_job_projection( $raw['job'], false, true );
		$credentials = self::normalize_credentials( $raw['credentials'], true );
		if ( null === $coverage || null === $job || null === $credentials ) {
			$empty['snapshot_state'] = 'failed';
			return $empty;
		}

		return array(
			'snapshot_state' => $expires <= time() ? 'stale' : 'fresh',
			'generated_at'   => $generated,
			'expires_at'     => $expires,
			'coverage'       => $coverage,
			'job'            => $job,
			'credentials'    => $credentials,
		);
	}

	/**
	 * Persist a compact snapshot without confusing same-value writes with failure.
	 *
	 * @param array<string,mixed> $snapshot Strict normalized snapshot.
	 * @return bool
	 */
	private static function persist_artwork_snapshot( $snapshot, &$outcome = null ) {
		$outcome = 'failed';
		$prior = self::read_artwork_option_row();
		if ( ! $prior['read_ok'] ) {
			return false;
		}

		$snapshot['persistence_state'] = 'verified';
		$desired                       = self::serialize_artwork_option( $snapshot );
		if ( $prior['exists'] && $desired === $prior['option_value'] ) {
			if ( self::autoload_is_disabled( $prior['autoload'] ) ) {
				$outcome = 'resolved';
				self::$artwork_status_memo = null;
				return true;
			}

			$autoload_write = self::replace_artwork_option_row( $prior, $desired, 'no' );
			$autoload_read  = self::read_artwork_option_row();
			$autoload_ok    = self::row_matches( $autoload_read, $desired, 'no', $autoload_write['option_id'] );
			$outcome        = $autoload_ok ? 'resolved' : 'failed';
			self::$artwork_status_memo = null;
			return $autoload_ok;
		}
		if ( ! self::artwork_option_transactions_supported() ) {
			self::$artwork_status_memo = null;
			return false;
		}

		$staged_snapshot                      = $snapshot;
		$staged_snapshot['persistence_state'] = 'unverified';
		$staged                               = self::serialize_artwork_option( $staged_snapshot );
		$forward                              = self::replace_artwork_option_row( $prior, $staged, 'no' );
		$staged_read                          = self::read_artwork_option_row();
		if ( ! self::row_matches( $staged_read, $staged, 'no', $forward['option_id'] ) ) {
			self::restore_artwork_option_row( $prior, $staged, $forward['option_id'] );
			self::$artwork_status_memo = null;
			return false;
		}

		if ( ! self::begin_artwork_option_transaction() ) {
			self::restore_artwork_option_row( $prior, $staged, $forward['option_id'] );
			self::$artwork_status_memo = null;
			return false;
		}

		$promotion     = self::replace_artwork_option_row( $staged_read, $desired, 'no' );
		$verified_read = self::read_artwork_option_row();
		$verified      = self::row_matches( $verified_read, $desired, 'no', $promotion['option_id'] );
		if ( ! $verified ) {
			self::$artwork_uncommitted_rows[ hash( 'sha256', $desired ) ] = true;
			self::rollback_artwork_option_transaction();
			self::$artwork_status_memo = null;
			return false;
		}

		$committed = self::commit_artwork_option_transaction();
		if ( ! $committed ) {
			self::rollback_artwork_option_transaction();
		}

		// Resolve the durable outcome after COMMIT. False means the desired
		// verified row was proved absent; an unreadable ambiguous outcome returns
		// true after exact pre-commit verification so a false result can never be
		// followed by the same desired row becoming fresh in another request.
		$durable = self::read_artwork_option_row();
		self::clear_artwork_option_cache();
		if ( self::row_matches( $durable, $desired, 'no', $promotion['option_id'] ) ) {
			$outcome = 'resolved';
			unset( self::$artwork_uncommitted_rows[ hash( 'sha256', $desired ) ] );
			self::$artwork_status_memo = null;
			return true;
		}
		if ( ! $durable['read_ok'] ) {
			$outcome = 'indeterminate';
			self::$artwork_uncommitted_rows[ hash( 'sha256', $desired ) ] = true;
			self::$artwork_status_memo = null;
			return true;
		}

		self::$artwork_status_memo = null;
		return false;
	}

	/** @return bool Whether the physical options table can make promotion rollback meaningful. */
	private static function artwork_option_transactions_supported() {
		global $wpdb;
		if ( ! is_object( $wpdb ) || ! isset( $wpdb->options ) || ! method_exists( $wpdb, 'prepare' ) || ! method_exists( $wpdb, 'get_row' ) ) {
			return false;
		}

		try {
			$wpdb->last_error = '';
			$row = $wpdb->get_row(
				$wpdb->prepare( "SHOW TABLE STATUS WHERE Name = %s", $wpdb->options ),
				defined( 'ARRAY_A' ) ? ARRAY_A : 'ARRAY_A'
			);
		} catch ( Throwable $error ) {
			return false;
		}
		return empty( $wpdb->last_error ) && is_array( $row ) && isset( $row['Engine'] ) && 'innodb' === strtolower( (string) $row['Engine'] );
	}

	/** @return bool */
	private static function begin_artwork_option_transaction() {
		global $wpdb;
		try {
			return is_object( $wpdb ) && method_exists( $wpdb, 'query' ) && false !== $wpdb->query( 'START TRANSACTION' );
		} catch ( Throwable $error ) {
			return false;
		}
	}

	/** @return bool */
	private static function commit_artwork_option_transaction() {
		global $wpdb;
		try {
			return is_object( $wpdb ) && method_exists( $wpdb, 'query' ) && false !== $wpdb->query( 'COMMIT' );
		} catch ( Throwable $error ) {
			return false;
		}
	}

	/** @return bool */
	private static function rollback_artwork_option_transaction() {
		global $wpdb;
		try {
			return is_object( $wpdb ) && method_exists( $wpdb, 'query' ) && false !== $wpdb->query( 'ROLLBACK' );
		} catch ( Throwable $error ) {
			return false;
		}
	}

	/** @return array<string,mixed> */
	private static function read_artwork_option_row() {
		global $wpdb;
		$empty = array( 'read_ok' => false, 'exists' => false, 'option_id' => 0, 'option_value' => '', 'autoload' => '' );
		if ( ! is_object( $wpdb ) || ! isset( $wpdb->options ) || ! method_exists( $wpdb, 'prepare' ) || ! method_exists( $wpdb, 'get_row' ) ) {
			return $empty;
		}

		$wpdb->last_error = '';
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT option_id, option_value, autoload FROM {$wpdb->options} WHERE option_name = %s LIMIT 1",
				self::ARTWORK_HEALTH_OPTION
			),
			defined( 'ARRAY_A' ) ? ARRAY_A : 'ARRAY_A'
		);
		if ( ! empty( $wpdb->last_error ) || false === $row ) {
			return $empty;
		}
		if ( null === $row ) {
			$empty['read_ok'] = true;
			return $empty;
		}
		if (
			! is_array( $row )
			|| ! isset( $row['option_id'], $row['option_value'], $row['autoload'] )
			|| ! is_scalar( $row['option_id'] )
			|| ! ctype_digit( (string) $row['option_id'] )
			|| (int) $row['option_id'] <= 0
			|| ! is_string( $row['option_value'] )
			|| ! is_string( $row['autoload'] )
		) {
			return $empty;
		}

		return array( 'read_ok' => true, 'exists' => true, 'option_id' => (int) $row['option_id'], 'option_value' => $row['option_value'], 'autoload' => $row['autoload'] );
	}

	/** @param array<string,mixed> $prior Expected current row. @param string $value New serialized value. @param string $autoload New autoload state. @return array<string,mixed> */
	private static function replace_artwork_option_row( $prior, $value, $autoload ) {
		global $wpdb;
		$outcome = array( 'result' => false, 'option_id' => $prior['exists'] ? $prior['option_id'] : 0 );
		if ( ! is_object( $wpdb ) || ! isset( $wpdb->options ) || ! method_exists( $wpdb, 'prepare' ) || ! method_exists( $wpdb, 'query' ) ) {
			return $outcome;
		}

		try {
			if ( $prior['exists'] ) {
				$outcome['result'] = $wpdb->query(
					$wpdb->prepare(
					"UPDATE {$wpdb->options} SET option_value = %s, autoload = %s WHERE option_id = %d AND option_name = %s AND BINARY option_value = BINARY %s AND BINARY autoload = BINARY %s LIMIT 1",
					$value,
					$autoload,
					$prior['option_id'],
					self::ARTWORK_HEALTH_OPTION,
					$prior['option_value'],
					$prior['autoload']
					)
				);
			} else {
				$outcome['result'] = $wpdb->query(
					$wpdb->prepare(
					"INSERT INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, %s)",
					self::ARTWORK_HEALTH_OPTION,
					$value,
					$autoload
					)
				);
				if ( false !== $outcome['result'] && isset( $wpdb->insert_id ) ) {
					$outcome['option_id'] = (int) $wpdb->insert_id;
				}
			}
		} catch ( Throwable $error ) {
			$outcome['result'] = false;
		}

		self::clear_artwork_option_cache();
		return $outcome;
	}

	/** @param array<string,mixed> $prior Original row. @param string $desired Serialized forward value. @param int $option_id Fenced forward-row identity. @return bool */
	private static function restore_artwork_option_row( $prior, $desired, $option_id ) {
		global $wpdb;
		if ( ! is_object( $wpdb ) || ! isset( $wpdb->options ) || ! method_exists( $wpdb, 'prepare' ) || ! method_exists( $wpdb, 'query' ) || $option_id <= 0 ) {
			return false;
		}

		try {
			if ( $prior['exists'] ) {
				$wpdb->query(
					$wpdb->prepare(
					"UPDATE {$wpdb->options} SET option_value = %s, autoload = %s WHERE option_id = %d AND option_name = %s AND BINARY option_value = BINARY %s AND BINARY autoload = BINARY %s LIMIT 1",
					$prior['option_value'],
					$prior['autoload'],
					$option_id,
					self::ARTWORK_HEALTH_OPTION,
					$desired,
					'no'
					)
				);
			} else {
				$wpdb->query(
					$wpdb->prepare(
					"DELETE FROM {$wpdb->options} WHERE option_id = %d AND option_name = %s AND BINARY option_value = BINARY %s AND BINARY autoload = BINARY %s LIMIT 1",
					$option_id,
					self::ARTWORK_HEALTH_OPTION,
					$desired,
					'no'
					)
				);
			}
		} catch ( Throwable $error ) {
			return false;
		}

		self::clear_artwork_option_cache();
		$verified = self::read_artwork_option_row();
		return self::rows_equal( $verified, $prior );
	}

	/** @param array<string,mixed> $row Row. @param string $value Serialized value. @param string $autoload Expected autoload. @param int $option_id Expected physical identity. @return bool */
	private static function row_matches( $row, $value, $autoload, $option_id ) {
		return $row['read_ok'] && $row['exists'] && $option_id > 0 && $option_id === $row['option_id'] && $value === $row['option_value'] && $autoload === $row['autoload'];
	}

	/** @param array<string,mixed> $left Row. @param array<string,mixed> $right Row. @return bool */
	private static function rows_equal( $left, $right ) {
		return $left['read_ok'] && $right['read_ok'] && $left['exists'] === $right['exists'] && ( ! $left['exists'] || ( $left['option_id'] === $right['option_id'] && $left['option_value'] === $right['option_value'] && $left['autoload'] === $right['autoload'] ) );
	}

	/** @param string $autoload Autoload state. @return bool */
	private static function autoload_is_disabled( $autoload ) {
		return in_array( $autoload, array( 'no', 'off', 'auto-off' ), true );
	}

	/** @param mixed $value Option value. @return string */
	private static function serialize_artwork_option( $value ) {
		return function_exists( 'maybe_serialize' ) ? maybe_serialize( $value ) : serialize( $value );
	}

	/** @param string $value Serialized option. @return mixed */
	private static function unserialize_artwork_option( $value ) {
		return function_exists( 'maybe_unserialize' ) ? maybe_unserialize( $value ) : @unserialize( $value );
	}

	private static function clear_artwork_option_cache() {
		if ( function_exists( 'wp_cache_delete' ) ) {
			wp_cache_delete( self::ARTWORK_HEALTH_OPTION, 'options' );
			wp_cache_delete( 'alloptions', 'options' );
			wp_cache_delete( 'notoptions', 'options' );
		}
	}

	/** @return array<string,mixed>|null */
	private static function build_snapshot( $coverage, $job, $credentials, $generated, $expires, $allow_partial ) {
		$coverage    = self::normalize_coverage( is_array( $coverage ) ? $coverage : array(), $allow_partial );
		$job         = self::normalize_job_projection( is_array( $job ) ? $job : array(), false, false );
		$credentials = self::normalize_credentials( is_array( $credentials ) ? $credentials : array(), $allow_partial );
		if ( null === $coverage || null === $job || null === $credentials ) {
			return null;
		}
		return array(
			'schema'         => self::ARTWORK_HEALTH_SCHEMA,
			'schema_version' => self::ARTWORK_HEALTH_SCHEMA_VERSION,
			'generated_at'   => self::bounded_timestamp( $generated ),
			'expires_at'     => self::bounded_timestamp( $expires ),
			'coverage'       => $coverage,
			'job'            => $job,
			'credentials'    => $credentials,
		);
	}

	/** @param array<string,mixed> $coverage Raw coverage. @param bool $allow_unknown Whether an explicit unknown projection is allowed. @return array<string,mixed>|null */
	private static function normalize_coverage( $coverage, $allow_unknown ) {
		if ( $allow_unknown && isset( $coverage['known'] ) && false === $coverage['known'] ) {
			return self::unknown_coverage();
		}
		$normalized = array( 'known' => true );
		foreach ( array( 'total', 'identity_ready', 'missing_identity', 'missing_poster', 'missing_backdrop', 'custom_protected' ) as $key ) {
			if ( ! array_key_exists( $key, $coverage ) ) {
				return null;
			}
			$count = self::strict_count( $coverage[ $key ] );
			if ( null === $count ) {
				return null;
			}
			$normalized[ $key ] = $count;
		}
		if (
			$normalized['identity_ready'] + $normalized['missing_identity'] !== $normalized['total']
			|| $normalized['missing_poster'] > $normalized['total']
			|| $normalized['missing_backdrop'] > $normalized['total']
			|| $normalized['custom_protected'] > $normalized['total']
		) {
			return null;
		}
		return $normalized;
	}

	/** @param array<string,mixed> $job Raw job. @param bool $default_status Whether absent status becomes idle. @param bool $allow_unknown Whether an explicit unknown projection is allowed. @return array<string,mixed>|null */
	private static function normalize_job_projection( $job, $default_status, $allow_unknown ) {
		if ( $allow_unknown && isset( $job['known'] ) && false === $job['known'] ) {
			return self::unknown_job();
		}
		$status = isset( $job['status'] ) ? $job['status'] : ( $default_status ? 'idle' : '' );
		if ( ! is_string( $status ) ) {
			return null;
		}
		if ( ! in_array( $status, array( 'idle', 'running', 'paused', 'complete' ), true ) ) {
			return null;
		}
		$counts = isset( $job['counts'] ) && is_array( $job['counts'] ) ? $job['counts'] : $job;
		$required = array( 'processed', 'total', 'started_at', 'completed_at' );
		foreach ( $required as $key ) {
			if ( ! array_key_exists( $key, $job ) ) {
				return null;
			}
		}
		foreach ( array( 'ready', 'partial', 'conflicts', 'errors' ) as $key ) {
			if ( ! array_key_exists( $key, $counts ) ) {
				return null;
			}
		}
		$processed   = self::strict_count( $job['processed'] );
		$total       = self::strict_count( $job['total'] );
		$ready       = self::strict_count( $counts['ready'] );
		$partial     = self::strict_count( $counts['partial'] );
		$conflicts   = self::strict_count( $counts['conflicts'] );
		$errors      = self::strict_count( $counts['errors'] );
		$started_at  = self::strict_timestamp( $job['started_at'] );
		$completed_at = self::strict_timestamp( $job['completed_at'] );
		if ( in_array( null, array( $processed, $total, $ready, $partial, $conflicts, $errors, $started_at, $completed_at ), true ) ) {
			return null;
		}
		// A processed Review can report a protected conflict for poster and backdrop.
		$max_conflicts = $processed * 2;
		if (
			$processed > $total
			|| $ready + $partial + $errors !== $processed
			|| $conflicts > $max_conflicts
		) {
			return null;
		}
		if (
			'idle' === $status
			&& ( 0 !== $processed || 0 !== $total || 0 !== $ready || 0 !== $partial || 0 !== $conflicts || 0 !== $errors || 0 !== $started_at || 0 !== $completed_at )
		) {
			return null;
		}
		if (
			in_array( $status, array( 'running', 'paused' ), true )
			&& ( $started_at <= 0 || 0 !== $completed_at || $total <= 0 || $processed >= $total )
		) {
			return null;
		}
		if (
			'complete' === $status
			&& ( $processed !== $total || $started_at <= 0 || $completed_at < $started_at )
		) {
			return null;
		}
		return array(
			'known'        => true,
			'status'       => $status,
			'processed'    => $processed,
			'total'        => $total,
			'ready'        => $ready,
			'partial'      => $partial,
			'conflicts'    => $conflicts,
			'errors'       => $errors,
			'started_at'   => $started_at,
			'completed_at' => $completed_at,
		);
	}

	/** @param array<string,mixed> $credentials Raw readiness. @param bool $allow_unknown Whether an explicit unknown projection is allowed. @return array<string,bool>|null */
	private static function normalize_credentials( $credentials, $allow_unknown ) {
		if ( $allow_unknown && isset( $credentials['known'] ) && false === $credentials['known'] ) {
			return self::unknown_credentials();
		}
		if ( ! array_key_exists( 'omdb', $credentials ) || ! array_key_exists( 'tmdb', $credentials ) || ! is_bool( $credentials['omdb'] ) || ! is_bool( $credentials['tmdb'] ) ) {
			return null;
		}
		$known = true;
		$omdb  = $credentials['omdb'];
		$tmdb  = $credentials['tmdb'];
		return array( 'known' => $known, 'omdb' => $omdb, 'tmdb' => $tmdb, 'ready' => $known && $omdb && $tmdb );
	}

	/** @return array<string,mixed> */
	private static function unknown_coverage() {
		return array( 'known' => false, 'total' => null, 'identity_ready' => null, 'missing_identity' => null, 'missing_poster' => null, 'missing_backdrop' => null, 'custom_protected' => null );
	}

	/** @return array<string,mixed> */
	private static function unknown_job() {
		return array( 'known' => false, 'status' => null, 'processed' => null, 'total' => null, 'ready' => null, 'partial' => null, 'conflicts' => null, 'errors' => null, 'started_at' => null, 'completed_at' => null );
	}

	/** @return array<string,bool> */
	private static function unknown_credentials() {
		return array( 'known' => false, 'omdb' => false, 'tmdb' => false, 'ready' => false );
	}

	/** @param mixed $value Raw count. @return int|null */
	private static function strict_count( $value ) {
		return is_int( $value ) && $value >= 0 && $value <= 2147483647 ? $value : null;
	}

	/** @param mixed $value Raw timestamp. @return int|null */
	private static function strict_timestamp( $value ) {
		return is_int( $value ) && $value >= 0 && $value <= self::MAX_TIMESTAMP ? $value : null;
	}

	/** @param mixed $value Raw timestamp. @return int */
	private static function bounded_timestamp( $value ) {
		return min( self::MAX_TIMESTAMP, max( 0, is_numeric( $value ) ? (int) $value : 0 ) );
	}

	/** @return bool */
	private static function artwork_is_ready( $coverage, $job, $credentials ) {
		foreach ( array( 'total', 'identity_ready', 'missing_identity', 'missing_poster', 'missing_backdrop', 'custom_protected' ) as $key ) {
			if ( null === $coverage[ $key ] ) {
				return false;
			}
		}
		return $credentials['ready']
			&& in_array( $job['status'], array( 'idle', 'complete' ), true )
			&& 0 === $coverage['missing_identity']
			&& 0 === $coverage['missing_poster']
			&& 0 === $coverage['missing_backdrop']
			&& 0 === $job['partial']
			&& 0 === $job['conflicts']
			&& 0 === $job['errors'];
	}

	/** @return bool */
	private static function carousel_owner_available() {
		return class_exists( 'Lunara_Core', false ) && method_exists( 'Lunara_Core', 'register_carousel_manager_page' );
	}

	/** @return bool */
	private static function artwork_owner_available() {
		return class_exists( 'Lunara_Review_Artwork_Backfill', false ) && method_exists( 'Lunara_Review_Artwork_Backfill', 'render_page' );
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

if ( ! function_exists( 'lunara_core_carousel_manager_admin_url' ) ) {
	/** @return string */
	function lunara_core_carousel_manager_admin_url() {
		return Lunara_Core_Site_Studio_Bridge::carousel_manager_admin_url();
	}
}

if ( ! function_exists( 'lunara_core_carousel_manager_status' ) ) {
	/** @return array<string,mixed> */
	function lunara_core_carousel_manager_status() {
		return Lunara_Core_Site_Studio_Bridge::carousel_manager_status();
	}
}

if ( ! function_exists( 'lunara_core_review_identity_artwork_admin_url' ) ) {
	/** @return string */
	function lunara_core_review_identity_artwork_admin_url() {
		return Lunara_Core_Site_Studio_Bridge::review_identity_artwork_admin_url();
	}
}

if ( ! function_exists( 'lunara_core_review_identity_artwork_status' ) ) {
	/** @return array<string,mixed> */
	function lunara_core_review_identity_artwork_status() {
		return Lunara_Core_Site_Studio_Bridge::review_identity_artwork_status();
	}
}
