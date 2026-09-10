<?php
/**
 * Review Image Studio.
 *
 * Gives Reviews explicit Auto / Custom / Off image choices while allowing
 * approved Media Library artwork to move safely between a Review and its
 * linked Film Dossier.
 *
 * @package Lunara_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Lunara_Review_Image_Studio {

	const NONCE_ACTION = 'lunara_review_image_studio_save';
	const NONCE_NAME   = 'lunara_review_image_studio_nonce';
	const IMPORT_ACTION = 'lunara_review_image_import';
	const RETRY_ACTION  = 'lunara_review_artwork_retry';
	const MODE_PREFIX   = '_lunara_review_image_mode_';
	const ID_PREFIX     = '_lunara_review_image_';
	const SOURCE_META   = '_lunara_original_source_url';
	const HYDRATE_HOOK  = 'lunara_review_image_hydrate';
	const HYDRATE_STATUS = '_lunara_review_image_hydration_status';
	const HYDRATED_IMDB  = '_lunara_review_image_hydrated_imdb';
	const HYDRATE_TIME    = '_lunara_review_image_hydration_time';
	const PROVIDER_ISSUE  = '_lunara_review_image_provider_issue';

	/** @var array<int,int> Per-request Review-to-Movie lookup cache. */
	private static $movie_cache = array();

	/**
	 * Register WordPress hooks.
	 */
	public static function init() {
		add_action( 'init', array( __CLASS__, 'register_meta' ), 30 );
		add_action( 'save_post_review', array( __CLASS__, 'save' ), 25, 3 );
		add_action( 'save_post_review', array( __CLASS__, 'queue_identity_hydration' ), 60, 3 );
		add_action( 'added_post_meta', array( __CLASS__, 'queue_identity_meta' ), 10, 4 );
		add_action( 'updated_post_meta', array( __CLASS__, 'queue_identity_meta' ), 10, 4 );
		add_action( 'transition_post_status', array( __CLASS__, 'sync_auto_grown_dossier' ), 20, 3 );
		add_action( self::HYDRATE_HOOK, array( __CLASS__, 'hydrate_review_identity' ), 10, 2 );

		if ( ! function_exists( 'is_admin' ) || ! is_admin() ) {
			return;
		}

		add_action( 'add_meta_boxes_review', array( __CLASS__, 'add_meta_box' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
		add_action( 'admin_post_' . self::IMPORT_ACTION, array( __CLASS__, 'handle_import' ) );
		add_action( 'wp_ajax_' . self::RETRY_ACTION, array( __CLASS__, 'handle_retry' ) );
		add_action( 'admin_notices', array( __CLASS__, 'render_notice' ) );
	}

	/**
	 * Supported editorial image positions.
	 *
	 * @return array<string,array<string,string>>
	 */
	public static function slots() {
		return array(
			'card' => array(
				'label'       => __( 'Card / Poster', 'lunara-core' ),
				'description' => __( 'Used by Review cards and, when approved, as the Review featured image or Film Dossier poster.', 'lunara-core' ),
				'legacy_key'  => '_lunara_review_card_image',
				'auto_role'   => 'poster',
				'default'     => 'auto',
			),
			'hero_banner' => array(
				'label'       => __( 'Hero Banner', 'lunara-core' ),
				'description' => __( 'Wide, textless artwork beneath the Review title. Auto uses the Film Dossier backdrop.', 'lunara-core' ),
				'legacy_key'  => '_lunara_review_hero_banner',
				'auto_role'   => 'backdrop',
				'default'     => 'auto',
			),
			'context_shot' => array(
				'label'       => __( 'Context Shot', 'lunara-core' ),
				'description' => __( 'Optional image near the first major movement of the Review.', 'lunara-core' ),
				'legacy_key'  => '_lunara_review_context_shot',
				'auto_role'   => 'backdrop',
				'default'     => 'off',
			),
			'visual_evidence' => array(
				'label'       => __( 'Visual Evidence', 'lunara-core' ),
				'description' => __( 'Optional image around the middle of the Review.', 'lunara-core' ),
				'legacy_key'  => '_lunara_review_visual_evidence',
				'auto_role'   => 'backdrop',
				'default'     => 'off',
			),
			'thematic_echo' => array(
				'label'       => __( 'Thematic Echo', 'lunara-core' ),
				'description' => __( 'Optional late-Review image before the Debrief.', 'lunara-core' ),
				'legacy_key'  => '_lunara_review_thematic_echo',
				'auto_role'   => 'backdrop',
				'default'     => 'off',
			),
		);
	}

	/**
	 * Register the portable image contract with the REST API.
	 */
	public static function register_meta() {
		if ( ! function_exists( 'register_post_meta' ) ) {
			return;
		}

		foreach ( array_keys( self::slots() ) as $slot ) {
			register_post_meta(
				'review',
				self::MODE_PREFIX . $slot,
				array(
					'type'              => 'string',
					'single'            => true,
					'show_in_rest'      => true,
					'sanitize_callback' => array( __CLASS__, 'sanitize_mode' ),
					'auth_callback'     => array( __CLASS__, 'can_edit_reviews' ),
				)
			);

			register_post_meta(
				'review',
				self::ID_PREFIX . $slot . '_id',
				array(
					'type'              => 'integer',
					'single'            => true,
					'show_in_rest'      => true,
					'sanitize_callback' => 'absint',
					'auth_callback'     => array( __CLASS__, 'can_edit_reviews' ),
				)
			);
		}
	}

	/**
	 * Meta authorization callback.
	 *
	 * @return bool
	 */
	public static function can_edit_reviews() {
		return current_user_can( 'edit_posts' );
	}

	/**
	 * Normalize an image mode.
	 *
	 * @param mixed $mode Raw mode.
	 * @return string
	 */
	public static function sanitize_mode( $mode ) {
		$mode = sanitize_key( (string) $mode );
		return in_array( $mode, array( 'auto', 'custom', 'off' ), true ) ? $mode : 'off';
	}

	/**
	 * Resolve the effective mode, including legacy URL compatibility.
	 *
	 * @param int    $review_id Review ID.
	 * @param string $slot Slot name.
	 * @return string
	 */
	public static function get_mode( $review_id, $slot ) {
		$slots = self::slots();
		if ( ! isset( $slots[ $slot ] ) ) {
			return 'off';
		}

		$stored = sanitize_key( (string) get_post_meta( $review_id, self::MODE_PREFIX . $slot, true ) );
		if ( in_array( $stored, array( 'auto', 'custom', 'off' ), true ) ) {
			return $stored;
		}

		$attachment_id = absint( get_post_meta( $review_id, self::ID_PREFIX . $slot . '_id', true ) );
		$legacy_url    = trim( (string) get_post_meta( $review_id, $slots[ $slot ]['legacy_key'], true ) );
		if ( $attachment_id || '' !== $legacy_url ) {
			return 'custom';
		}

		return $slots[ $slot ]['default'];
	}

	/**
	 * Resolve a Review image slot for editor previews and public rendering.
	 *
	 * @param int    $review_id Review ID.
	 * @param string $slot Slot name.
	 * @return array<string,mixed>
	 */
	public static function resolve_slot( $review_id, $slot ) {
		$slots = self::slots();
		$mode  = self::get_mode( $review_id, $slot );

		if ( ! isset( $slots[ $slot ] ) || 'off' === $mode ) {
			return self::empty_source( $mode );
		}

		if ( 'custom' === $mode ) {
			$attachment_id = absint( get_post_meta( $review_id, self::ID_PREFIX . $slot . '_id', true ) );
			if ( $attachment_id ) {
				return self::attachment_source( $attachment_id, 'review_custom', $mode, 0 );
			}

			$legacy_url = trim( (string) get_post_meta( $review_id, $slots[ $slot ]['legacy_key'], true ) );
			if ( '' !== $legacy_url ) {
				return self::url_source( $legacy_url, 'review_legacy_url', $mode, 0 );
			}

			return self::empty_source( $mode );
		}

		return self::resolve_auto_source( $review_id, $slot );
	}

	/**
	 * Resolve the Film Dossier or provider-backed automatic image.
	 *
	 * @param int    $review_id Review ID.
	 * @param string $slot Slot name.
	 * @return array<string,mixed>
	 */
	public static function resolve_auto_source( $review_id, $slot ) {
		$slots = self::slots();
		if ( ! isset( $slots[ $slot ] ) ) {
			return self::empty_source( 'auto' );
		}

		$movie_id = self::resolve_movie_id( $review_id );
		$role     = $slots[ $slot ]['auto_role'];
		// A saved canonical poster is the editorial source of truth in Auto.
		// Custom and Off are resolved above and never reach this preference.
		if ( 'poster' === $role ) {
			$url = self::provider_meta_url( $review_id, '_lunara_tmdb_poster_url' );
			if ( '' !== $url ) {
				return self::url_source( $url, 'review_provider', 'auto', $movie_id );
			}
		}
		$review_attachment_id = absint( get_post_meta( $review_id, self::ID_PREFIX . $slot . '_id', true ) );
		if ( $review_attachment_id ) {
			$source = self::attachment_source( $review_attachment_id, 'review_local_auto', 'auto', $movie_id );
			if ( '' !== $source['url'] ) {
				return $source;
			}
		}

		if ( $movie_id && 'poster' === $role ) {
			$attachment_id = get_post_thumbnail_id( $movie_id );
			if ( $attachment_id ) {
				$source = self::attachment_source( $attachment_id, 'dossier_poster', 'auto', $movie_id );
				if ( '' !== $source['url'] ) {
					return $source;
				}
			}
		}

		if ( $movie_id && 'backdrop' === $role ) {
			$attachment_id = absint( get_post_meta( $movie_id, 'backdrop_image', true ) );
			if ( $attachment_id ) {
				$source = self::attachment_source( $attachment_id, 'dossier_backdrop', 'auto', $movie_id );
				if ( '' !== $source['url'] ) {
					return $source;
				}
			}
		}

		if ( 'poster' === $role ) {
			$attachment_id = get_post_thumbnail_id( $review_id );
			if ( $attachment_id ) {
				$source = self::attachment_source( $attachment_id, 'review_featured', 'auto', $movie_id );
				if ( '' !== $source['url'] ) {
					return $source;
				}
			}
		}

		$review_keys = 'poster' === $role
			? array( '_lunara_tmdb_poster_url', 'tmdb_poster_url' )
			: array( '_lunara_tmdb_backdrop_url', 'tmdb_backdrop_url' );
		foreach ( $review_keys as $meta_key ) {
			$url = self::provider_meta_url( $review_id, $meta_key );
			if ( '' !== $url ) {
				return self::url_source( $url, 'review_provider', 'auto', $movie_id );
			}
		}

		if ( $movie_id ) {
			$movie_keys = 'poster' === $role
				? array( 'tmdb_poster_url', '_lunara_tmdb_poster_url' )
				: array( 'tmdb_backdrop_url', '_lunara_tmdb_backdrop_url' );
			foreach ( $movie_keys as $meta_key ) {
				$url = self::provider_meta_url( $movie_id, $meta_key );
				if ( '' !== $url ) {
					return self::url_source( $url, 'dossier_provider', 'auto', $movie_id );
				}
			}
		}

		return self::empty_source( 'auto', $movie_id );
	}

	/** Read a usable web image URL without letting malformed meta block fallback. */
	private static function provider_meta_url( $post_id, $key ) {
		$value = get_post_meta( $post_id, $key, true );
		if ( ! is_string( $value ) ) {
			return '';
		}
		$url = esc_url_raw( trim( $value ) );
		$parts = parse_url( $url );
		return is_array( $parts ) && ! empty( $parts['host'] ) && isset( $parts['scheme'] )
			&& in_array( strtolower( $parts['scheme'] ), array( 'https', 'http' ), true ) ? $url : '';
	}

	/**
	 * Locate the Movie connected to a Review's IMDb identity.
	 *
	 * @param int $review_id Review ID.
	 * @return int
	 */
	public static function resolve_movie_id( $review_id ) {
		$review_id = absint( $review_id );
		if ( array_key_exists( $review_id, self::$movie_cache ) ) {
			return self::$movie_cache[ $review_id ];
		}

		$cached_id = absint( get_post_meta( $review_id, '_lunara_review_movie_id', true ) );
		if ( $cached_id && 'movie' === get_post_type( $cached_id ) ) {
			self::$movie_cache[ $review_id ] = $cached_id;
			return self::$movie_cache[ $review_id ];
		}

		$imdb_id = trim( (string) get_post_meta( $review_id, '_lunara_imdb_title_id', true ) );
		if ( ! preg_match( '/tt\d{5,12}/i', $imdb_id, $matches ) || ! function_exists( 'get_posts' ) ) {
			self::$movie_cache[ $review_id ] = 0;
			return self::$movie_cache[ $review_id ];
		}

		$imdb_id = strtolower( $matches[0] );
		$ids     = get_posts(
			array(
				'post_type'              => 'movie',
				'post_status'            => array( 'publish', 'draft', 'pending', 'private' ),
				'posts_per_page'         => 1,
				'fields'                 => 'ids',
				'no_found_rows'          => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
				'meta_query'             => array(
					'relation' => 'OR',
					array(
						'key'   => 'imdb_title_id',
						'value' => $imdb_id,
					),
					array(
						'key'   => '_lunara_entity_id',
						'value' => $imdb_id,
					),
				),
			)
		);

		$movie = empty( $ids ) ? 0 : reset( $ids );
		self::$movie_cache[ $review_id ] = is_object( $movie ) && isset( $movie->ID ) ? absint( $movie->ID ) : absint( $movie );
		return self::$movie_cache[ $review_id ];
	}

	/**
	 * Register the Classic Editor meta box.
	 */
	public static function add_meta_box() {
		add_meta_box(
			'lunara-review-image-studio',
			__( 'Lunara Review Image Studio', 'lunara-core' ),
			array( __CLASS__, 'render_meta_box' ),
			'review',
			'normal',
			'high'
		);
	}

	/**
	 * Render the operator controls.
	 *
	 * @param WP_Post $post Review post.
	 */
	public static function render_meta_box( $post ) {
		wp_nonce_field( self::NONCE_ACTION, self::NONCE_NAME );
		$movie_id = self::resolve_movie_id( $post->ID );
		$hydrate_status = trim( (string) get_post_meta( $post->ID, self::HYDRATE_STATUS, true ) );
		$status_labels = array(
			'queued'  => __( 'Lookup queued — Lunara is matching the title and importing available artwork.', 'lunara-core' ),
			'running' => __( 'Matching the title and importing available artwork now.', 'lunara-core' ),
			'ready'   => __( 'Dossier, poster, and backdrop are ready.', 'lunara-core' ),
			'partial' => __( 'The title is linked; use Custom for any artwork the movie database could not supply.', 'lunara-core' ),
			'identity_only' => __( 'The draft Dossier is linked, but the movie database did not return artwork. Use Retry movie artwork to try again.', 'lunara-core' ),
			'error'   => __( 'The last lookup could not finish. Use Retry movie artwork to try again.', 'lunara-core' ),
		);
		?>
		<div class="lunara-image-studio" data-review-id="<?php echo esc_attr( $post->ID ); ?>">
			<p class="lunara-image-studio-intro">
				<?php esc_html_e( 'Automatic cards prefer this Review\'s saved TMDB poster, then available local or Film Dossier artwork. Choose Custom to keep your own image, or Off to hide a position.', 'lunara-core' ); ?>
			</p>
			<?php if ( $movie_id ) : ?>
				<p class="lunara-image-studio-link">
					<?php esc_html_e( 'Linked Film Dossier:', 'lunara-core' ); ?>
					<a href="<?php echo esc_url( get_edit_post_link( $movie_id ) ); ?>"><?php echo esc_html( get_the_title( $movie_id ) ); ?></a>
				</p>
			<?php else : ?>
				<p class="lunara-image-studio-warning"><?php esc_html_e( 'No Film Dossier is linked yet. This Review still works independently. As soon as a valid IMDb ID is parsed or saved, Lunara matches or creates the draft Dossier in the background and imports available movie artwork.', 'lunara-core' ); ?></p>
			<?php endif; ?>
			<?php if ( '' !== $hydrate_status ) : ?>
				<p class="lunara-image-studio-status"><strong><?php esc_html_e( 'Movie database:', 'lunara-core' ); ?></strong> <?php echo esc_html( isset( $status_labels[ $hydrate_status ] ) ? $status_labels[ $hydrate_status ] : ucfirst( $hydrate_status ) ); ?></p>
			<?php endif; ?>
			<?php $provider_issue = get_post_meta( $post->ID, self::PROVIDER_ISSUE, true ); ?>
			<?php if ( is_string( $provider_issue ) && '' !== $provider_issue ) : ?>
				<p class="lunara-image-studio-warning"><?php echo esc_html( $provider_issue ); ?></p>
			<?php endif; ?>

			<?php if ( '' !== self::normalize_imdb_id( get_post_meta( $post->ID, '_lunara_imdb_title_id', true ) ) ) : ?>
				<p><button type="button" class="button lunara-image-studio-retry" data-nonce="<?php echo esc_attr( wp_create_nonce( self::RETRY_ACTION . ':' . $post->ID ) ); ?>"><?php esc_html_e( 'Retry movie artwork', 'lunara-core' ); ?></button> <span class="lunara-image-studio-retry-status" role="status" aria-live="polite"></span></p>
				<p class="description"><?php esc_html_e( 'Uses the saved IMDb ID. Your article and custom image choices stay as they are.', 'lunara-core' ); ?></p>
			<?php endif; ?>
			<div class="lunara-image-studio-grid">
				<?php foreach ( self::slots() as $slot => $config ) : ?>
					<?php
					$mode        = self::get_mode( $post->ID, $slot );
					$resolved    = self::resolve_slot( $post->ID, $slot );
					$custom_id   = absint( get_post_meta( $post->ID, self::ID_PREFIX . $slot . '_id', true ) );
					$auto_source = self::resolve_auto_source( $post->ID, $slot );
					?>
					<section class="lunara-image-studio-card" data-slot="<?php echo esc_attr( $slot ); ?>">
						<header>
							<h4><?php echo esc_html( $config['label'] ); ?></h4>
							<p><?php echo esc_html( $config['description'] ); ?></p>
						</header>

						<div class="lunara-image-studio-preview">
							<?php if ( ! empty( $resolved['url'] ) ) : ?>
								<img src="<?php echo esc_url( $resolved['url'] ); ?>" alt="">
							<?php else : ?>
								<span><?php esc_html_e( 'No image selected', 'lunara-core' ); ?></span>
							<?php endif; ?>
						</div>

						<label class="lunara-image-studio-mode-label" for="lunara-image-mode-<?php echo esc_attr( $slot ); ?>"><?php esc_html_e( 'Behavior', 'lunara-core' ); ?></label>
						<select id="lunara-image-mode-<?php echo esc_attr( $slot ); ?>" class="lunara-image-studio-mode" name="lunara_review_image_mode[<?php echo esc_attr( $slot ); ?>]">
							<option value="auto" <?php selected( $mode, 'auto' ); ?>><?php esc_html_e( 'Auto — use matched movie artwork', 'lunara-core' ); ?></option>
							<option value="custom" <?php selected( $mode, 'custom' ); ?>><?php esc_html_e( 'Custom — use this Review selection', 'lunara-core' ); ?></option>
							<option value="off" <?php selected( $mode, 'off' ); ?>><?php esc_html_e( 'Off — render nothing', 'lunara-core' ); ?></option>
						</select>

						<input type="hidden" class="lunara-image-studio-id" name="lunara_review_image_id[<?php echo esc_attr( $slot ); ?>]" value="<?php echo esc_attr( $custom_id ); ?>">
						<input type="hidden" class="lunara-image-studio-clear" name="lunara_review_image_clear[<?php echo esc_attr( $slot ); ?>]" value="0">
						<div class="lunara-image-studio-buttons">
							<button type="button" class="button lunara-image-studio-select"><?php esc_html_e( 'Choose / Replace', 'lunara-core' ); ?></button>
							<button type="button" class="button-link-delete lunara-image-studio-remove"><?php esc_html_e( 'Clear custom image', 'lunara-core' ); ?></button>
						</div>

						<?php if ( 'card' !== $slot ) : ?>
							<label class="lunara-image-studio-caption-label" for="lunara-image-caption-<?php echo esc_attr( $slot ); ?>"><?php esc_html_e( 'Caption / source note (optional)', 'lunara-core' ); ?></label>
							<input id="lunara-image-caption-<?php echo esc_attr( $slot ); ?>" class="widefat" type="text" name="lunara_review_image_caption[<?php echo esc_attr( $slot ); ?>]" value="<?php echo esc_attr( get_post_meta( $post->ID, $config['legacy_key'] . '_caption', true ) ); ?>">
						<?php endif; ?>

						<?php if ( 'card' === $slot || 'hero_banner' === $slot ) : ?>
							<div class="lunara-image-studio-actions">
								<?php if ( 'card' === $slot ) : ?>
									<label><input type="checkbox" name="lunara_review_image_sync_featured" value="1"> <?php esc_html_e( 'Use the resolved poster as this Review’s Featured Image', 'lunara-core' ); ?></label>
									<label><input type="checkbox" name="lunara_review_image_sync_dossier_poster" value="1"> <?php esc_html_e( 'Send the resolved poster to the Film Dossier', 'lunara-core' ); ?></label>
								<?php else : ?>
									<label><input type="checkbox" name="lunara_review_image_sync_dossier_backdrop" value="1"> <?php esc_html_e( 'Send the resolved hero to the Film Dossier backdrop', 'lunara-core' ); ?></label>
								<?php endif; ?>

								<?php if ( empty( $auto_source['attachment_id'] ) && ! empty( $auto_source['url'] ) ) : ?>
									<a class="button button-secondary" href="<?php echo esc_url( self::import_url( $post->ID, $slot ) ); ?>"><?php esc_html_e( 'Import automatic artwork into Media Library', 'lunara-core' ); ?></a>
								<?php endif; ?>
							</div>
						<?php endif; ?>
					</section>
				<?php endforeach; ?>
			</div>
		</div>
		<?php
	}

	/**
	 * Save modes, attachments, and explicit synchronization requests.
	 *
	 * @param int     $post_id Review ID.
	 * @param WP_Post $post Review object.
	 * @param bool    $update Whether this is an update.
	 */
	public static function save( $post_id, $post, $update ) {
		unset( $update );
		if ( ! isset( $_POST[ self::NONCE_NAME ] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST[ self::NONCE_NAME ] ) ), self::NONCE_ACTION ) ) {
			return;
		}
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}
		if ( ! $post || 'review' !== $post->post_type || ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		$modes  = isset( $_POST['lunara_review_image_mode'] ) && is_array( $_POST['lunara_review_image_mode'] ) ? wp_unslash( $_POST['lunara_review_image_mode'] ) : array();
		$ids    = isset( $_POST['lunara_review_image_id'] ) && is_array( $_POST['lunara_review_image_id'] ) ? wp_unslash( $_POST['lunara_review_image_id'] ) : array();
		$clears = isset( $_POST['lunara_review_image_clear'] ) && is_array( $_POST['lunara_review_image_clear'] ) ? wp_unslash( $_POST['lunara_review_image_clear'] ) : array();
		$captions = isset( $_POST['lunara_review_image_caption'] ) && is_array( $_POST['lunara_review_image_caption'] ) ? wp_unslash( $_POST['lunara_review_image_caption'] ) : array();

		foreach ( self::slots() as $slot => $config ) {
			$mode          = isset( $modes[ $slot ] ) ? self::sanitize_mode( $modes[ $slot ] ) : self::get_mode( $post_id, $slot );
			$attachment_id = isset( $ids[ $slot ] ) ? absint( $ids[ $slot ] ) : 0;
			$clear         = ! empty( $clears[ $slot ] );

			update_post_meta( $post_id, self::MODE_PREFIX . $slot, $mode );
			if ( 'card' !== $slot && isset( $captions[ $slot ] ) ) {
				$caption = sanitize_text_field( $captions[ $slot ] );
				if ( '' === $caption ) {
					delete_post_meta( $post_id, $config['legacy_key'] . '_caption' );
				} else {
					update_post_meta( $post_id, $config['legacy_key'] . '_caption', $caption );
				}
			}

			if ( $clear ) {
				delete_post_meta( $post_id, self::ID_PREFIX . $slot . '_id' );
				delete_post_meta( $post_id, $config['legacy_key'] );
				continue;
			}

			if ( $attachment_id && self::is_image_attachment( $attachment_id ) ) {
				update_post_meta( $post_id, self::ID_PREFIX . $slot . '_id', $attachment_id );
				$url = wp_get_attachment_image_url( $attachment_id, 'full' );
				if ( $url ) {
					update_post_meta( $post_id, $config['legacy_key'], esc_url_raw( $url ) );
				}
			}
		}

		if ( ! empty( $_POST['lunara_review_image_sync_featured'] ) ) {
			self::sync_review_featured_image( $post_id );
		}
		if ( ! empty( $_POST['lunara_review_image_sync_dossier_poster'] ) ) {
			self::sync_to_dossier( $post_id, 'card' );
		}
		if ( ! empty( $_POST['lunara_review_image_sync_dossier_backdrop'] ) ) {
			self::sync_to_dossier( $post_id, 'hero_banner' );
		}
	}

	/**
	 * Enqueue the Media Library picker only on Review edit screens.
	 *
	 * @param string $hook_suffix Admin hook.
	 */
	public static function enqueue_assets( $hook_suffix ) {
		if ( ! in_array( $hook_suffix, array( 'post.php', 'post-new.php' ), true ) || ! function_exists( 'get_current_screen' ) ) {
			return;
		}
		$screen = get_current_screen();
		if ( ! $screen || 'review' !== $screen->post_type ) {
			return;
		}

		wp_enqueue_media();
		wp_enqueue_style( 'lunara-review-image-studio', LUNARA_CORE_URL . 'assets/css/lunara-review-image-studio.css', array(), LUNARA_CORE_VERSION );
		wp_enqueue_script( 'lunara-review-image-studio', LUNARA_CORE_URL . 'assets/js/lunara-review-image-studio.js', array( 'jquery' ), LUNARA_CORE_VERSION, true );
		wp_localize_script(
			'lunara-review-image-studio',
			'LunaraReviewImageStudio',
			array(
				'title'  => __( 'Choose Review artwork', 'lunara-core' ),
				'button' => __( 'Use this image', 'lunara-core' ),
				'empty'  => __( 'No image selected', 'lunara-core' ),
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'retryAction' => self::RETRY_ACTION,
				'retryPending' => __( 'Queueing artwork lookup…', 'lunara-core' ),
				'retryQueued' => __( 'Artwork lookup queued. Reopen this Review shortly to see the result.', 'lunara-core' ),
				'retryFailed' => __( 'Artwork lookup could not be queued. Please try again.', 'lunara-core' ),
			)
		);
	}

	/**
	 * Import a remote automatic source into the Media Library.
	 */
	public static function handle_import() {
		$review_id = isset( $_GET['review_id'] ) ? absint( $_GET['review_id'] ) : 0;
		$slot      = isset( $_GET['slot'] ) ? sanitize_key( wp_unslash( $_GET['slot'] ) ) : '';
		$nonce     = isset( $_GET['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ) : '';

		if ( ! $review_id || ! isset( self::slots()[ $slot ] ) || ! wp_verify_nonce( $nonce, self::IMPORT_ACTION . ':' . $review_id . ':' . $slot ) || ! current_user_can( 'edit_post', $review_id ) ) {
			wp_die( esc_html__( 'This image import request is not authorized.', 'lunara-core' ) );
		}

		$source = self::resolve_auto_source( $review_id, $slot );
		if ( ! empty( $source['attachment_id'] ) ) {
			self::redirect_after_import( $review_id, 'already-local' );
		}
		if ( empty( $source['url'] ) ) {
			self::redirect_after_import( $review_id, 'missing' );
		}

		$attachment_id = self::sideload_image( $source['url'], $review_id, get_the_title( $review_id ) . ' ' . self::slots()[ $slot ]['label'] );
		if ( is_wp_error( $attachment_id ) ) {
			self::redirect_after_import( $review_id, 'failed' );
		}

		$movie_id = self::resolve_movie_id( $review_id );
		if ( $movie_id && 'card' === $slot ) {
			set_post_thumbnail( $movie_id, $attachment_id );
			set_post_thumbnail( $review_id, $attachment_id );
		} elseif ( $movie_id && 'hero_banner' === $slot ) {
			self::update_dossier_backdrop( $movie_id, $attachment_id );
		} else {
			update_post_meta( $review_id, self::ID_PREFIX . $slot . '_id', $attachment_id );
			update_post_meta( $review_id, self::MODE_PREFIX . $slot, 'custom' );
			if ( 'card' === $slot ) {
				set_post_thumbnail( $review_id, $attachment_id );
			}
		}

		$url = wp_get_attachment_image_url( $attachment_id, 'full' );
		if ( $url ) {
			update_post_meta( $review_id, self::slots()[ $slot ]['legacy_key'], esc_url_raw( $url ) );
		}
		self::redirect_after_import( $review_id, 'success' );
	}

	/** Queue one saved Review without submitting its article editor. */
	public static function handle_retry() {
		$review_id = isset( $_POST['review_id'] ) && is_scalar( $_POST['review_id'] ) ? absint( $_POST['review_id'] ) : 0;
		$nonce = isset( $_POST['nonce'] ) && is_string( $_POST['nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['nonce'] ) ) : '';
		if ( ! $review_id || 'review' !== get_post_type( $review_id ) || ! current_user_can( 'edit_post', $review_id ) || ! wp_verify_nonce( $nonce, self::RETRY_ACTION . ':' . $review_id ) ) {
			wp_send_json_error( array( 'message' => __( 'This artwork request is not authorized.', 'lunara-core' ) ), 403 );
			return;
		}
		if ( ! self::queue_review( $review_id, true ) ) {
			wp_send_json_error( array( 'message' => __( 'Save a valid IMDb title ID before retrying artwork.', 'lunara-core' ) ), 400 );
			return;
		}
		wp_send_json_success( array( 'queued' => true ) );
	}

	/**
	 * Queue one identity-first background hydration after the parser/save flow.
	 *
	 * @param int     $review_id Review ID.
	 * @param WP_Post $post Review post.
	 * @param bool    $update Whether this is an update.
	 */
	public static function queue_identity_hydration( $review_id, $post, $update ) {
		unset( $update );
		if ( ! $post || 'review' !== $post->post_type ) {
			return;
		}
		if ( function_exists( 'wp_is_post_revision' ) && wp_is_post_revision( $review_id ) ) {
			return;
		}
		if ( function_exists( 'wp_is_post_autosave' ) && wp_is_post_autosave( $review_id ) ) {
			return;
		}

		self::queue_review( $review_id );
	}

	/** Queue when REST/importers write the canonical ID after save_post_review. */
	public static function queue_identity_meta( $meta_id, $review_id, $meta_key, $value ) {
		unset( $meta_id, $value );
		if ( '_lunara_imdb_title_id' === $meta_key ) {
			self::queue_review( $review_id );
		}
	}

	/**
	 * Queue identity hydration after an importer has finished writing metadata.
	 *
	 * This explicit entry point avoids relying on save-hook order when REST or
	 * Classic Editor parsing writes the IMDb ID after WordPress saves the post.
	 *
	 * @param int $review_id Review ID.
	 * @param bool $force Explicit editorial retry, bypassing completed/cooldown status.
	 * @return bool Whether the lookup is queued or already active.
	 */
	public static function queue_review( $review_id, $force = false ) {
		$review_id = absint( $review_id );
		if ( ! $review_id || 'review' !== get_post_type( $review_id ) ) {
			return false;
		}

		$imdb_id = self::normalize_imdb_id( get_post_meta( $review_id, '_lunara_imdb_title_id', true ) );
		if ( '' === $imdb_id ) {
			return false;
		}

		$hydrated = (string) get_post_meta( $review_id, self::HYDRATED_IMDB, true );
		$status   = (string) get_post_meta( $review_id, self::HYDRATE_STATUS, true );
		$last_run = absint( get_post_meta( $review_id, self::HYDRATE_TIME, true ) );
		if ( ! $force && $hydrated === $imdb_id && in_array( $status, array( 'ready', 'partial' ), true ) ) {
			return false;
		}
		if ( ! $force && in_array( $status, array( 'error', 'identity_only' ), true ) && $last_run > time() - HOUR_IN_SECONDS ) {
			return false;
		}
		if ( 'running' === $status && $last_run > time() - 300 ) {
			return true;
		}

		$args = array( $review_id, $imdb_id );
		if ( function_exists( 'wp_next_scheduled' ) && wp_next_scheduled( self::HYDRATE_HOOK, $args ) ) {
			return true;
		}

		if ( function_exists( 'wp_schedule_single_event' ) && wp_schedule_single_event( time() + 1, self::HYDRATE_HOOK, $args ) ) {
			update_post_meta( $review_id, self::HYDRATE_STATUS, 'queued' );
			return true;
		}
		return false;
	}

	/**
	 * Resolve/create the Film Dossier, call providers once, and localize art.
	 * Runs outside the public request path through WP-Cron.
	 *
	 * @param int    $review_id Review ID.
	 * @param string $imdb_id Canonical IMDb title ID.
	 * @param string $policy Fill missing art or refresh provider-managed art.
	 * @return array<string,mixed>
	 */
	public static function hydrate_review_identity( $review_id, $imdb_id, $policy = 'fill_missing' ) {
		$review_id = absint( $review_id );
		$imdb_id   = self::normalize_imdb_id( $imdb_id );
		$policy    = 'refresh_managed' === $policy ? 'refresh_managed' : 'fill_missing';
		if ( ! $review_id || '' === $imdb_id || 'review' !== get_post_type( $review_id ) ) {
			return array(
				'review_id' => $review_id,
				'imdb_id'   => $imdb_id,
				'status'    => 'invalid_identity',
				'conflicts' => array(),
			);
		}
		if ( $imdb_id !== self::normalize_imdb_id( get_post_meta( $review_id, '_lunara_imdb_title_id', true ) ) ) {
			return array( 'review_id' => $review_id, 'imdb_id' => $imdb_id, 'status' => 'stale_identity', 'conflicts' => array() );
		}

		$report = array(
			'review_id' => $review_id,
			'imdb_id'   => $imdb_id,
			'status'    => 'running',
			'poster'    => 'unchanged',
			'backdrop'  => 'unchanged',
			'conflicts' => array(),
		);

		update_post_meta( $review_id, self::HYDRATE_STATUS, 'running' );
		update_post_meta( $review_id, self::HYDRATE_TIME, time() );
		update_post_meta( $review_id, self::PROVIDER_ISSUE, '' );

		try {
			if ( class_exists( 'Lunara_Core' ) && method_exists( 'Lunara_Core', 'load_movie_importer' ) ) {
				Lunara_Core::load_movie_importer();
			}
			if ( ! class_exists( 'Lunara_Movie_Repository' ) || ! class_exists( 'Lunara_Movie_Importer' ) ) {
				throw new RuntimeException( 'Movie importer unavailable.' );
			}

			$gateway = apply_filters( 'lunara_movie_provider_gateway_service', null );
			if ( null === $gateway && class_exists( 'Lunara_Movie_Provider_Gateway' ) ) {
				$gateway = new Lunara_Movie_Provider_Gateway();
			}

			$candidate          = array();
			$provider_succeeded = false;
			$lookup = is_object( $gateway ) && method_exists( $gateway, 'get_artwork_by_imdb' ) ? 'get_artwork_by_imdb' : 'get_candidate_by_imdb';
			if ( is_object( $gateway ) && method_exists( $gateway, $lookup ) ) {
				$response = $gateway->$lookup( $imdb_id );
				if ( ! is_wp_error( $response ) ) {
					$candidate = isset( $response['candidate'] ) && is_array( $response['candidate'] ) ? $response['candidate'] : $response;
					$provider_succeeded = is_array( $candidate ) && ! empty( $candidate );
				} else {
					// Store only our fixed, redacted explanation, never provider payloads.
					$report['provider_issue'] = self::provider_issue_message( $response );
					update_post_meta( $review_id, self::PROVIDER_ISSUE, $report['provider_issue'] );
				}
			}

			if ( ! is_array( $candidate ) || empty( $candidate ) ) {
				$candidate = array(
					'imdb_title_id' => $imdb_id,
					'title'         => self::review_film_title( $review_id ),
					'release_year'  => absint( get_post_meta( $review_id, '_lunara_year', true ) ),
				);
			}
			$candidate['imdb_title_id'] = $imdb_id;

			$repository = new Lunara_Movie_Repository();
			$importer   = new Lunara_Movie_Importer( $repository, null );
			$result     = $importer->import_draft(
				$candidate,
				array(
					'review_id'   => $review_id,
					'role'        => 'reviewed_film',
					'requested_by'=> 0,
				)
			);

			unset( self::$movie_cache[ $review_id ] );
			$movie_id = ! empty( $result['movie_id'] ) ? absint( $result['movie_id'] ) : self::resolve_movie_id( $review_id );
			if ( $movie_id ) {
				update_post_meta( $review_id, '_lunara_review_movie_id', $movie_id );
				self::$movie_cache[ $review_id ] = $movie_id;
			}

			$poster_url   = self::candidate_image_url( $candidate, 'poster' );
			$backdrop_url = self::candidate_image_url( $candidate, 'backdrop' );
			if ( '' !== $poster_url ) {
				update_post_meta( $review_id, '_lunara_tmdb_poster_url', $poster_url );
				if ( $movie_id ) {
					update_post_meta( $movie_id, 'tmdb_poster_url', $poster_url );
				}
			}
			if ( '' !== $backdrop_url ) {
				update_post_meta( $review_id, '_lunara_tmdb_backdrop_url', $backdrop_url );
				if ( $movie_id ) {
					update_post_meta( $movie_id, 'tmdb_backdrop_url', $backdrop_url );
				}
			}

			$poster_current = $movie_id
				? get_post_thumbnail_id( $movie_id )
				: absint( get_post_meta( $review_id, self::ID_PREFIX . 'card_id', true ) );
			$poster_result  = self::reconcile_provider_artwork(
				$poster_current,
				$poster_url,
				$movie_id ? $movie_id : $review_id,
				self::review_film_title( $review_id ) . ' poster',
				$policy
			);
			$poster_id      = absint( $poster_result['attachment_id'] );
			$report['poster'] = $poster_result['action'];
			if ( ! empty( $poster_result['conflict'] ) ) {
				$report['conflicts'][] = 'poster:' . $poster_result['conflict'];
			}
			if ( $movie_id && $poster_id && $poster_id !== absint( $poster_current ) ) {
				set_post_thumbnail( $movie_id, $poster_id );
			}

			$backdrop_current = $movie_id
				? absint( get_post_meta( $movie_id, 'backdrop_image', true ) )
				: absint( get_post_meta( $review_id, self::ID_PREFIX . 'hero_banner_id', true ) );
			$backdrop_result  = self::reconcile_provider_artwork(
				$backdrop_current,
				$backdrop_url,
				$movie_id ? $movie_id : $review_id,
				self::review_film_title( $review_id ) . ' backdrop',
				$policy
			);
			$backdrop_id      = absint( $backdrop_result['attachment_id'] );
			$report['backdrop'] = $backdrop_result['action'];
			if ( ! empty( $backdrop_result['conflict'] ) ) {
				$report['conflicts'][] = 'backdrop:' . $backdrop_result['conflict'];
			}
			if ( $movie_id && $backdrop_id && $backdrop_id !== absint( $backdrop_current ) ) {
				self::update_dossier_backdrop( $movie_id, $backdrop_id );
			}

			self::reconcile_review_artwork_pointer( $review_id, 'card', $poster_id, $movie_id, $report );
			self::reconcile_review_artwork_pointer( $review_id, 'hero_banner', $backdrop_id, $movie_id, $report );
			self::reconcile_review_featured_image( $review_id, $poster_id, $policy, $report );

			if ( $movie_id && $poster_id && $backdrop_id ) {
				$status = 'ready';
			} elseif ( $provider_succeeded && $movie_id ) {
				$status = 'partial';
			} elseif ( $movie_id ) {
				$status = 'identity_only';
			} else {
				$status = 'error';
			}
			update_post_meta( $review_id, self::HYDRATED_IMDB, $imdb_id );
			update_post_meta( $review_id, self::HYDRATE_STATUS, $status );
			$report['status']   = $status;
			$report['movie_id'] = $movie_id;
			return $report;
		} catch ( Throwable $error ) {
			update_post_meta( $review_id, self::HYDRATE_STATUS, 'error' );
			do_action( 'lunara_review_image_hydration_failed', $review_id, $imdb_id, $error );
			$report['status'] = 'error';
			$report['error']  = sanitize_text_field( $error->getMessage() );
			return $report;
		}
	}

	/** Translate a provider failure without retaining its message, URL or secrets. */
	private static function provider_issue_message( $error ) {
		$messages = array(
			'lunara_movie_provider_credentials_missing' => __( 'Movie provider credentials are incomplete.', 'lunara-core' ),
			'lunara_movie_provider_not_found' => __( 'No exact IMDb match was returned.', 'lunara-core' ),
			'lunara_movie_provider_identity_mismatch' => __( 'The returned film identity did not match; artwork was not imported.', 'lunara-core' ),
			'lunara_movie_provider_rate_limited' => __( 'The provider rate limit was reached. Retry after the cooldown.', 'lunara-core' ),
			'lunara_movie_provider_circuit_open' => __( 'The provider is temporarily paused after failed requests. Retry after the cooldown.', 'lunara-core' ),
		);
		$data = $error->get_error_data();
		$provider = is_array( $data ) && isset( $data['provider'] ) && in_array( $data['provider'], array( 'omdb', 'tmdb' ), true )
			? strtoupper( $data['provider'] ) . ': ' : '';
		return $provider . ( $messages[ $error->get_error_code() ] ?? __( 'The movie provider lookup failed. Check the provider connection before retrying.', 'lunara-core' ) );
	}

	/**
	 * Run an explicit exact-identity refresh for the artwork backfill worker.
	 *
	 * @param int $review_id Review ID.
	 * @return array<string,mixed>
	 */
	public static function refresh_review_artwork( $review_id ) {
		$imdb_id = self::normalize_imdb_id( get_post_meta( $review_id, '_lunara_imdb_title_id', true ) );
		return self::hydrate_review_identity( $review_id, $imdb_id, 'refresh_managed' );
	}

	/**
	 * Carry Review-owned local artwork into a newly auto-grown Film Dossier.
	 * Existing Dossier artwork is never replaced here.
	 *
	 * @param string  $new_status New status.
	 * @param string  $old_status Previous status.
	 * @param WP_Post $post Review post.
	 */
	public static function sync_auto_grown_dossier( $new_status, $old_status, $post ) {
		if ( 'publish' !== $new_status || 'publish' === $old_status || ! $post || 'review' !== $post->post_type ) {
			return;
		}

		unset( self::$movie_cache[ $post->ID ] );
		$movie_id = self::resolve_movie_id( $post->ID );
		if ( ! $movie_id ) {
			return;
		}

		update_post_meta( $post->ID, '_lunara_review_movie_id', $movie_id );

		if ( ! get_post_thumbnail_id( $movie_id ) && 'off' !== self::get_mode( $post->ID, 'card' ) ) {
			$poster_id = absint( get_post_meta( $post->ID, self::ID_PREFIX . 'card_id', true ) );
			if ( ! $poster_id ) {
				$poster_id = get_post_thumbnail_id( $post->ID );
			}
			if ( $poster_id ) {
				set_post_thumbnail( $movie_id, $poster_id );
			}
		}

		if ( ! absint( get_post_meta( $movie_id, 'backdrop_image', true ) ) && 'off' !== self::get_mode( $post->ID, 'hero_banner' ) ) {
			$backdrop_id = absint( get_post_meta( $post->ID, self::ID_PREFIX . 'hero_banner_id', true ) );
			if ( $backdrop_id ) {
				self::update_dossier_backdrop( $movie_id, $backdrop_id );
			}
		}
	}

	/**
	 * Show an import result notice.
	 */
	public static function render_notice() {
		$status = isset( $_GET['lunara_image_import'] ) ? sanitize_key( wp_unslash( $_GET['lunara_image_import'] ) ) : '';
		if ( '' === $status ) {
			return;
		}

		$messages = array(
			'success'       => array( 'success', __( 'Artwork was imported into the Media Library and connected successfully.', 'lunara-core' ) ),
			'already-local' => array( 'info', __( 'The automatic artwork is already a local Media Library image.', 'lunara-core' ) ),
			'missing'       => array( 'warning', __( 'No automatic artwork source is currently available for that position.', 'lunara-core' ) ),
			'failed'        => array( 'error', __( 'WordPress could not import that image. The existing Review data was left unchanged.', 'lunara-core' ) ),
		);
		if ( ! isset( $messages[ $status ] ) ) {
			return;
		}

		printf( '<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>', esc_attr( $messages[ $status ][0] ), esc_html( $messages[ $status ][1] ) );
	}

	/**
	 * Synchronize the resolved card artwork to the Review thumbnail.
	 *
	 * @param int $review_id Review ID.
	 * @return bool
	 */
	private static function sync_review_featured_image( $review_id ) {
		$source = self::resolve_slot( $review_id, 'card' );
		return ! empty( $source['attachment_id'] ) && set_post_thumbnail( $review_id, $source['attachment_id'] );
	}

	/**
	 * Explicitly send local Review artwork to the linked Film Dossier.
	 *
	 * @param int    $review_id Review ID.
	 * @param string $slot Card or hero slot.
	 * @return bool
	 */
	private static function sync_to_dossier( $review_id, $slot ) {
		$movie_id = self::resolve_movie_id( $review_id );
		$source   = self::resolve_slot( $review_id, $slot );
		if ( ! $movie_id || empty( $source['attachment_id'] ) ) {
			return false;
		}

		if ( 'card' === $slot ) {
			return (bool) set_post_thumbnail( $movie_id, $source['attachment_id'] );
		}

		return self::update_dossier_backdrop( $movie_id, $source['attachment_id'] );
	}

	/**
	 * Update the ACF-backed Film Dossier backdrop.
	 *
	 * @param int $movie_id Movie ID.
	 * @param int $attachment_id Attachment ID.
	 * @return bool
	 */
	private static function update_dossier_backdrop( $movie_id, $attachment_id ) {
		if ( function_exists( 'update_field' ) ) {
			return (bool) update_field( 'field_lunara_movie_backdrop_image', $attachment_id, $movie_id );
		}
		return false !== update_post_meta( $movie_id, 'backdrop_image', $attachment_id );
	}

	/**
	 * Reconcile one provider image without overwriting curated Media Library art.
	 *
	 * Only attachments previously localized from TMDb are refreshable. An
	 * attachment with no TMDb source marker is treated as editorially curated
	 * and is reported for human review instead of being replaced.
	 *
	 * @param int    $current_id Current attachment ID.
	 * @param string $provider_url Exact-identity provider URL.
	 * @param int    $parent_id Parent post ID.
	 * @param string $description Attachment description.
	 * @param string $policy Reconciliation policy.
	 * @return array<string,mixed>
	 */
	private static function reconcile_provider_artwork( $current_id, $provider_url, $parent_id, $description, $policy ) {
		$current_id  = absint( $current_id );
		$provider_url = esc_url_raw( $provider_url );
		$current_url = $current_id ? esc_url_raw( get_post_meta( $current_id, self::SOURCE_META, true ) ) : '';

		if ( $current_id && '' === $provider_url ) {
			return array( 'attachment_id' => $current_id, 'action' => 'kept_no_provider_art', 'conflict' => '' );
		}
		if ( $current_id && $current_url === $provider_url ) {
			return array( 'attachment_id' => $current_id, 'action' => 'verified', 'conflict' => '' );
		}
		if ( $current_id && ( 'refresh_managed' !== $policy || ! self::is_tmdb_managed_attachment( $current_id ) ) ) {
			return array( 'attachment_id' => $current_id, 'action' => 'curated_protected', 'conflict' => 'curated_art_preserved' );
		}
		if ( '' === $provider_url ) {
			return array( 'attachment_id' => 0, 'action' => 'missing_provider_art', 'conflict' => '' );
		}

		$attachment_id = self::sideload_image( $provider_url, $parent_id, $description );
		if ( is_wp_error( $attachment_id ) ) {
			return array( 'attachment_id' => $current_id, 'action' => 'import_error', 'conflict' => $attachment_id->get_error_code() );
		}

		return array(
			'attachment_id' => absint( $attachment_id ),
			'action'        => $current_id ? 'provider_refreshed' : 'provider_imported',
			'conflict'      => '',
		);
	}

	/**
	 * Make automatic Review slots inherit their exact linked Dossier artwork.
	 *
	 * @param int                  $review_id Review ID.
	 * @param string               $slot Review Image Studio slot.
	 * @param int                  $attachment_id Reconciled attachment ID.
	 * @param int                  $movie_id Linked Dossier ID.
	 * @param array<string,mixed> &$report Mutable audit report.
	 */
	private static function reconcile_review_artwork_pointer( $review_id, $slot, $attachment_id, $movie_id, &$report ) {
		if ( self::is_review_slot_editorially_protected( $review_id, $slot ) || 'off' === self::get_mode( $review_id, $slot ) ) {
			return;
		}

		$meta_key   = self::ID_PREFIX . $slot . '_id';
		$current_id = absint( get_post_meta( $review_id, $meta_key, true ) );
		if ( $movie_id ) {
			if ( $current_id && self::is_tmdb_managed_attachment( $current_id ) ) {
				delete_post_meta( $review_id, $meta_key );
			} elseif ( $current_id && $current_id !== absint( $attachment_id ) ) {
				$report['conflicts'][] = $slot . ':review_auto_art_preserved';
			}
			return;
		}

		if ( $attachment_id && ( ! $current_id || self::is_tmdb_managed_attachment( $current_id ) ) ) {
			update_post_meta( $review_id, $meta_key, absint( $attachment_id ) );
		}
	}

	/**
	 * Keep the Review thumbnail aligned unless Dalton selected custom artwork.
	 *
	 * @param int                  $review_id Review ID.
	 * @param int                  $poster_id Reconciled poster ID.
	 * @param string               $policy Reconciliation policy.
	 * @param array<string,mixed> &$report Mutable audit report.
	 */
	private static function reconcile_review_featured_image( $review_id, $poster_id, $policy, &$report ) {
		if ( ! $poster_id || self::is_review_slot_editorially_protected( $review_id, 'card' ) ) {
			return;
		}

		$current_id = get_post_thumbnail_id( $review_id );
		if ( ! $current_id || ( 'refresh_managed' === $policy && self::is_tmdb_managed_attachment( $current_id ) ) ) {
			if ( absint( $current_id ) !== absint( $poster_id ) ) {
				set_post_thumbnail( $review_id, $poster_id );
			}
			return;
		}

		if ( absint( $current_id ) !== absint( $poster_id ) ) {
			$report['conflicts'][] = 'featured_image:curated_art_preserved';
		}
	}

	/**
	 * Determine whether an attachment was localized from TMDb by Lunara.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return bool
	 */
	private static function is_tmdb_managed_attachment( $attachment_id ) {
		$source = trim( (string) get_post_meta( absint( $attachment_id ), self::SOURCE_META, true ) );
		if ( '' === $source ) {
			return false;
		}

		$host = strtolower( (string) wp_parse_url( $source, PHP_URL_HOST ) );
		return 'image.tmdb.org' === $host || '.image.tmdb.org' === substr( $host, -15 );
	}

	/**
	 * Distinguish an explicit/curated Custom slot from legacy provider art.
	 *
	 * Older automatic imports can have an attachment pointer but no saved mode;
	 * get_mode() correctly exposes those as Custom for safety in the editor. The
	 * bulk reconciler may still treat the pointer as automatic only when its
	 * source marker proves that Lunara imported it from TMDb.
	 *
	 * @param int    $review_id Review ID.
	 * @param string $slot Review Image Studio slot.
	 * @return bool
	 */
	private static function is_review_slot_editorially_protected( $review_id, $slot ) {
		$slots  = self::slots();
		$stored = sanitize_key( (string) get_post_meta( $review_id, self::MODE_PREFIX . $slot, true ) );
		if ( 'custom' === $stored ) {
			return true;
		}
		if ( in_array( $stored, array( 'auto', 'off' ), true ) ) {
			return false;
		}

		$attachment_id = absint( get_post_meta( $review_id, self::ID_PREFIX . $slot . '_id', true ) );
		if ( $attachment_id ) {
			return ! self::is_tmdb_managed_attachment( $attachment_id );
		}

		$legacy_key = isset( $slots[ $slot ]['legacy_key'] ) ? $slots[ $slot ]['legacy_key'] : '';
		return '' !== $legacy_key && '' !== trim( (string) get_post_meta( $review_id, $legacy_key, true ) );
	}

	/**
	 * Normalize an IMDb title identity from a field value or URL.
	 *
	 * @param mixed $value Raw identity value.
	 * @return string
	 */
	private static function normalize_imdb_id( $value ) {
		if ( class_exists( 'Lunara_Debrief_Contract' ) && method_exists( 'Lunara_Debrief_Contract', 'normalize_imdb_id' ) ) {
			return (string) Lunara_Debrief_Contract::normalize_imdb_id( $value );
		}

		if ( preg_match( '/\b(tt\d{5,12})\b/i', (string) $value, $match ) ) {
			return strtolower( $match[1] );
		}
		return '';
	}

	/**
	 * Derive a clean film title from the Review heading.
	 *
	 * @param int $review_id Review ID.
	 * @return string
	 */
	private static function review_film_title( $review_id ) {
		$title = sanitize_text_field( get_the_title( $review_id ) );
		$parts = preg_split( '/\s+(?:\x{2014}|\x{2013}|\||:)\s+/u', $title, 2 );
		if ( is_array( $parts ) && '' !== trim( (string) $parts[0] ) ) {
			return trim( (string) $parts[0] );
		}
		return $title;
	}

	/**
	 * Resolve an original-size provider image URL from a normalized candidate.
	 *
	 * @param array  $candidate Provider candidate.
	 * @param string $role Poster or backdrop.
	 * @return string
	 */
	private static function candidate_image_url( $candidate, $role ) {
		$role = 'backdrop' === $role ? 'backdrop' : 'poster';
		$keys = array( $role . '_url', $role . '_path', $role );
		foreach ( $keys as $key ) {
			$value = isset( $candidate[ $key ] ) ? trim( (string) $candidate[ $key ] ) : '';
			if ( '' === $value ) {
				continue;
			}
			if ( 0 === strpos( $value, '/' ) ) {
				return esc_url_raw( 'https://image.tmdb.org/t/p/original' . $value );
			}
			if ( wp_http_validate_url( $value ) ) {
				return esc_url_raw( $value );
			}
		}
		return '';
	}

	/**
	 * Import one remote image with source URL deduplication.
	 *
	 * @param string $url Remote image URL.
	 * @param int    $parent_id Parent Review ID.
	 * @param string $description Attachment description.
	 * @return int|WP_Error
	 */
	private static function sideload_image( $url, $parent_id, $description ) {
		$url = esc_url_raw( $url );
		if ( ! wp_http_validate_url( $url ) ) {
			return new WP_Error( 'lunara_invalid_image_url', __( 'The artwork URL is invalid.', 'lunara-core' ) );
		}

		$existing = get_posts(
			array(
				'post_type'      => 'attachment',
				'post_status'    => 'inherit',
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'meta_key'       => self::SOURCE_META,
				'meta_value'     => $url,
			)
		);
		if ( ! empty( $existing ) ) {
			return absint( reset( $existing ) );
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$attachment_id = media_sideload_image( $url, $parent_id, sanitize_text_field( $description ), 'id' );
		if ( ! is_wp_error( $attachment_id ) ) {
			update_post_meta( $attachment_id, self::SOURCE_META, $url );
		}
		return $attachment_id;
	}

	/**
	 * Build an attachment-backed source response.
	 *
	 * @param int    $attachment_id Attachment ID.
	 * @param string $source Source label.
	 * @param string $mode Effective mode.
	 * @param int    $movie_id Linked Movie ID.
	 * @return array<string,mixed>
	 */
	private static function attachment_source( $attachment_id, $source, $mode, $movie_id ) {
		$url = wp_get_attachment_image_url( $attachment_id, 'full' );
		if ( ! $url ) {
			return self::empty_source( $mode, $movie_id );
		}
		return array(
			'mode'          => $mode,
			'attachment_id' => absint( $attachment_id ),
			'url'           => esc_url_raw( $url ),
			'source'        => $source,
			'movie_id'      => absint( $movie_id ),
		);
	}

	/**
	 * Build a URL-backed source response.
	 *
	 * @param string $url Image URL.
	 * @param string $source Source label.
	 * @param string $mode Effective mode.
	 * @param int    $movie_id Linked Movie ID.
	 * @return array<string,mixed>
	 */
	private static function url_source( $url, $source, $mode, $movie_id ) {
		$url = esc_url_raw( $url );
		return array(
			'mode'          => $mode,
			'attachment_id' => 0,
			'url'           => $url,
			'source'        => $source,
			'movie_id'      => absint( $movie_id ),
		);
	}

	/**
	 * Build an empty source response.
	 *
	 * @param string $mode Effective mode.
	 * @param int    $movie_id Linked Movie ID.
	 * @return array<string,mixed>
	 */
	private static function empty_source( $mode, $movie_id = 0 ) {
		return array(
			'mode'          => $mode,
			'attachment_id' => 0,
			'url'           => '',
			'source'        => 'none',
			'movie_id'      => absint( $movie_id ),
		);
	}

	/**
	 * Confirm an attachment is an image without breaking test harnesses.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return bool
	 */
	private static function is_image_attachment( $attachment_id ) {
		return ! function_exists( 'wp_attachment_is_image' ) || wp_attachment_is_image( $attachment_id );
	}

	/**
	 * Build the signed import action URL.
	 *
	 * @param int    $review_id Review ID.
	 * @param string $slot Slot name.
	 * @return string
	 */
	private static function import_url( $review_id, $slot ) {
		$url = add_query_arg(
			array(
				'action'    => self::IMPORT_ACTION,
				'review_id' => absint( $review_id ),
				'slot'      => sanitize_key( $slot ),
			),
			admin_url( 'admin-post.php' )
		);
		return wp_nonce_url( $url, self::IMPORT_ACTION . ':' . absint( $review_id ) . ':' . sanitize_key( $slot ) );
	}

	/**
	 * Return to the Review editor after an import action.
	 *
	 * @param int    $review_id Review ID.
	 * @param string $status Result status.
	 */
	private static function redirect_after_import( $review_id, $status ) {
		$url = add_query_arg( 'lunara_image_import', sanitize_key( $status ), get_edit_post_link( $review_id, 'url' ) );
		wp_safe_redirect( $url );
		exit;
	}
}
