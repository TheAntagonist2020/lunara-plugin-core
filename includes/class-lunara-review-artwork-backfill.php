<?php
/**
 * Exact-identity Review artwork audit and background backfill.
 *
 * @package Lunara_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Lunara_Review_Artwork_Backfill {

	const PAGE_SLUG    = 'lunara-review-artwork-audit';
	const OPTION_KEY   = 'lunara_review_artwork_backfill_job';
	const WORK_HOOK    = 'lunara_review_artwork_backfill_tick';
	const START_ACTION = 'lunara_review_artwork_backfill_start';
	const PAUSE_ACTION = 'lunara_review_artwork_backfill_pause';
	const NONCE_ACTION = 'lunara_review_artwork_backfill_manage';
	const LOCK_KEY     = 'lunara_review_artwork_backfill_lock';

	/** Register the operator surface and background worker. */
	public static function init() {
		add_action( self::WORK_HOOK, array( __CLASS__, 'process_next' ) );

		if ( ! function_exists( 'is_admin' ) || ! is_admin() ) {
			return;
		}

		add_action( 'admin_menu', array( __CLASS__, 'register_page' ), 35 );
		add_action( 'admin_post_' . self::START_ACTION, array( __CLASS__, 'handle_start' ) );
		add_action( 'admin_post_' . self::PAUSE_ACTION, array( __CLASS__, 'handle_pause' ) );
	}

	/** Add the page beneath the singular Review menu. */
	public static function register_page() {
		add_submenu_page(
			'edit.php?post_type=review',
			__( 'Review Artwork Audit', 'lunara-core' ),
			__( 'Artwork Audit', 'lunara-core' ),
			'edit_others_posts',
			self::PAGE_SLUG,
			array( __CLASS__, 'render_page' )
		);
	}

	/** Render the read-only census and guarded background-run controls. */
	public static function render_page() {
		if ( ! current_user_can( 'edit_others_posts' ) ) {
			wp_die( esc_html__( 'You do not have permission to audit Review artwork.', 'lunara-core' ) );
		}

		$census     = self::census();
		$job        = self::get_job();
		$credential = self::credentials_status();
		$running    = 'running' === $job['status'];
		?>
		<div class="wrap lunara-review-artwork-audit">
			<h1><?php esc_html_e( 'Review Artwork Audit', 'lunara-core' ); ?></h1>
			<p><?php esc_html_e( 'This runner uses each Review’s canonical IMDb title ID to call the movie providers, localize the primary TMDb poster and backdrop, and reconnect automatic Review artwork. Custom and unmarked Media Library choices are never overwritten.', 'lunara-core' ); ?></p>

			<?php if ( ! $credential['ready'] ) : ?>
				<div class="notice notice-error inline"><p><?php esc_html_e( 'TMDb credentials are not available. The audit is safe to view, but the artwork backfill cannot start.', 'lunara-core' ); ?></p></div>
			<?php endif; ?>

			<table class="widefat striped" style="max-width:960px;margin:20px 0;">
				<tbody>
					<tr><th><?php esc_html_e( 'Reviews found', 'lunara-core' ); ?></th><td><?php echo esc_html( $census['total'] ); ?></td></tr>
					<tr><th><?php esc_html_e( 'Exact IMDb identities ready', 'lunara-core' ); ?></th><td><?php echo esc_html( $census['identity_ready'] ); ?></td></tr>
					<tr><th><?php esc_html_e( 'Missing IMDb identity', 'lunara-core' ); ?></th><td><?php echo esc_html( $census['missing_identity'] ); ?></td></tr>
					<tr><th><?php esc_html_e( 'Poster currently missing', 'lunara-core' ); ?></th><td><?php echo esc_html( $census['missing_poster'] ); ?></td></tr>
					<tr><th><?php esc_html_e( 'Banner currently missing', 'lunara-core' ); ?></th><td><?php echo esc_html( $census['missing_backdrop'] ); ?></td></tr>
					<tr><th><?php esc_html_e( 'Reviews with protected custom art', 'lunara-core' ); ?></th><td><?php echo esc_html( $census['custom_protected'] ); ?></td></tr>
				</tbody>
			</table>

			<h2><?php esc_html_e( 'Safe TMDb backfill', 'lunara-core' ); ?></h2>
			<p><strong><?php esc_html_e( 'State:', 'lunara-core' ); ?></strong> <?php echo esc_html( ucfirst( $job['status'] ) ); ?>
				<?php if ( $job['total'] ) : ?>
					— <?php echo esc_html( $job['processed'] . ' / ' . $job['total'] ); ?>
				<?php endif; ?>
			</p>
			<?php if ( ! empty( $job['counts'] ) ) : ?>
				<p><?php echo esc_html( sprintf( 'Ready: %1$d · Partial: %2$d · Conflicts protected: %3$d · Errors: %4$d', $job['counts']['ready'], $job['counts']['partial'], $job['counts']['conflicts'], $job['counts']['errors'] ) ); ?></p>
			<?php endif; ?>

			<?php if ( $running ) : ?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="<?php echo esc_attr( self::PAUSE_ACTION ); ?>">
					<?php wp_nonce_field( self::NONCE_ACTION ); ?>
					<?php submit_button( __( 'Pause after current Review', 'lunara-core' ), 'secondary', 'submit', false ); ?>
				</form>
				<script>window.setTimeout(function(){ window.location.reload(); }, 10000);</script>
			<?php else : ?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="<?php echo esc_attr( self::START_ACTION ); ?>">
					<?php wp_nonce_field( self::NONCE_ACTION ); ?>
					<?php submit_button( 'paused' === $job['status'] ? __( 'Resume exact-identity artwork backfill', 'lunara-core' ) : __( 'Run exact-identity artwork backfill', 'lunara-core' ), 'primary', 'submit', false, $credential['ready'] ? array() : array( 'disabled' => 'disabled' ) ); ?>
				</form>
			<?php endif; ?>

			<?php if ( ! empty( $job['recent'] ) ) : ?>
				<h2><?php esc_html_e( 'Most recent results', 'lunara-core' ); ?></h2>
				<table class="widefat striped" style="max-width:960px;">
					<thead><tr><th><?php esc_html_e( 'Review', 'lunara-core' ); ?></th><th><?php esc_html_e( 'IMDb', 'lunara-core' ); ?></th><th><?php esc_html_e( 'Status', 'lunara-core' ); ?></th><th><?php esc_html_e( 'Artwork', 'lunara-core' ); ?></th></tr></thead>
					<tbody>
					<?php foreach ( array_reverse( $job['recent'] ) as $result ) : ?>
						<tr>
							<td><a href="<?php echo esc_url( get_edit_post_link( $result['review_id'] ) ); ?>"><?php echo esc_html( get_the_title( $result['review_id'] ) ); ?></a></td>
							<td><?php echo esc_html( $result['imdb_id'] ); ?></td>
							<td><?php echo esc_html( $result['status'] ); ?></td>
							<td><?php echo esc_html( $result['poster'] . ' / ' . $result['backdrop'] . ( $result['conflicts'] ? ' · protected conflict' : '' ) ); ?></td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</div>
		<?php
	}

	/** Start or restart a complete exact-identity pass. */
	public static function handle_start() {
		self::authorize();
		$credential = self::credentials_status();
		if ( ! $credential['ready'] ) {
			wp_safe_redirect( self::page_url() );
			exit;
		}

		$job = self::get_job();
		if ( 'paused' === $job['status'] && $job['cursor'] < $job['total'] ) {
			$job['status'] = 'running';
		} else {
			$ids = self::review_ids( true );
			$job = self::empty_job();
			$job['status']     = empty( $ids ) ? 'complete' : 'running';
			$job['ids']        = $ids;
			$job['total']      = count( $ids );
			$job['started_at'] = time();
		}
		update_option( self::OPTION_KEY, $job, false );
		if ( 'running' === $job['status'] ) {
			self::schedule_next( 1 );
		}

		wp_safe_redirect( self::page_url() );
		exit;
	}

	/** Pause without discarding progress or the audit trail. */
	public static function handle_pause() {
		self::authorize();
		$job = self::get_job();
		$job['status'] = 'paused';
		update_option( self::OPTION_KEY, $job, false );
		wp_clear_scheduled_hook( self::WORK_HOOK );
		wp_safe_redirect( self::page_url() );
		exit;
	}

	/** Process one Review, then yield before the next provider request set. */
	public static function process_next() {
		$job = self::get_job();
		if ( 'running' !== $job['status'] || get_transient( self::LOCK_KEY ) ) {
			return;
		}

		set_transient( self::LOCK_KEY, 1, MINUTE_IN_SECONDS );
		try {
			if ( $job['cursor'] >= $job['total'] || empty( $job['ids'][ $job['cursor'] ] ) ) {
				$job['status']       = 'complete';
				$job['completed_at'] = time();
				update_option( self::OPTION_KEY, $job, false );
				return;
			}

			$review_id = absint( $job['ids'][ $job['cursor'] ] );
			$result    = Lunara_Review_Image_Studio::refresh_review_artwork( $review_id );
			$result    = self::normalize_result( $review_id, $result );
			$job['cursor']++;
			$job['processed']++;
			$job['counts']['ready'] += 'ready' === $result['status'] ? 1 : 0;
			$job['counts']['partial'] += in_array( $result['status'], array( 'partial', 'identity_only' ), true ) ? 1 : 0;
			$job['counts']['errors'] += in_array( $result['status'], array( 'error', 'invalid_identity' ), true ) ? 1 : 0;
			$job['counts']['conflicts'] += $result['conflicts'];
			$job['recent'][] = $result;
			$job['recent']   = array_slice( $job['recent'], -20 );

			if ( $job['cursor'] >= $job['total'] ) {
				$job['status']       = 'complete';
				$job['completed_at'] = time();
			}
			update_option( self::OPTION_KEY, $job, false );
		} catch ( Throwable $error ) {
			$job['counts']['errors']++;
			$job['status']     = 'paused';
			$job['last_error'] = sanitize_text_field( $error->getMessage() );
			update_option( self::OPTION_KEY, $job, false );
		} finally {
			delete_transient( self::LOCK_KEY );
		}

		if ( 'running' === $job['status'] ) {
			self::schedule_next( 8 );
		}
	}

	/** Count current coverage without making any external API calls. */
	public static function census() {
		$counts = array(
			'total'            => 0,
			'identity_ready'   => 0,
			'missing_identity' => 0,
			'missing_poster'   => 0,
			'missing_backdrop' => 0,
			'custom_protected' => 0,
		);

		foreach ( self::review_ids( false ) as $review_id ) {
			$counts['total']++;
			$imdb_id = (string) get_post_meta( $review_id, '_lunara_imdb_title_id', true );
			if ( preg_match( '/\btt\d{6,9}\b/i', $imdb_id ) ) {
				$counts['identity_ready']++;
			} else {
				$counts['missing_identity']++;
			}

			$poster   = Lunara_Review_Image_Studio::resolve_slot( $review_id, 'card' );
			$backdrop = Lunara_Review_Image_Studio::resolve_slot( $review_id, 'hero_banner' );
			$counts['missing_poster'] += empty( $poster['url'] ) ? 1 : 0;
			$counts['missing_backdrop'] += empty( $backdrop['url'] ) ? 1 : 0;
			$counts['custom_protected'] += 'custom' === $poster['mode'] || 'custom' === $backdrop['mode'] ? 1 : 0;
		}

		return $counts;
	}

	/** @return array<int,int> */
	private static function review_ids( $require_identity ) {
		$ids = get_posts(
			array(
				'post_type'              => 'review',
				'post_status'            => array( 'publish', 'draft', 'pending', 'private', 'future' ),
				'posts_per_page'         => -1,
				'fields'                 => 'ids',
				'orderby'                => 'ID',
				'order'                  => 'ASC',
				'no_found_rows'          => true,
				'update_post_meta_cache' => true,
				'update_post_term_cache' => false,
			)
		);

		$ids = array_map( 'absint', is_array( $ids ) ? $ids : array() );
		if ( ! $require_identity ) {
			return $ids;
		}

		return array_values(
			array_filter(
				$ids,
				static function ( $review_id ) {
					return (bool) preg_match( '/\btt\d{6,9}\b/i', (string) get_post_meta( $review_id, '_lunara_imdb_title_id', true ) );
				}
			)
		);
	}

	/** @return array<string,bool> */
	private static function credentials_status() {
		if ( class_exists( 'Lunara_Core' ) ) {
			Lunara_Core::load_movie_importer();
		}
		if ( ! class_exists( 'Lunara_Movie_Provider_Gateway' ) ) {
			return array( 'omdb' => false, 'tmdb' => false, 'ready' => false );
		}
		$gateway = new Lunara_Movie_Provider_Gateway();
		$status = $gateway->credentials_status();
		$status['ready'] = ! empty( $status['tmdb'] );
		return $status;
	}

	/** @return array<string,mixed> */
	private static function normalize_result( $review_id, $result ) {
		$result = is_array( $result ) ? $result : array();
		return array(
			'review_id' => absint( $review_id ),
			'imdb_id'   => sanitize_text_field( isset( $result['imdb_id'] ) ? $result['imdb_id'] : '' ),
			'status'    => sanitize_key( isset( $result['status'] ) ? $result['status'] : 'error' ),
			'poster'    => sanitize_key( isset( $result['poster'] ) ? $result['poster'] : 'unchanged' ),
			'backdrop'  => sanitize_key( isset( $result['backdrop'] ) ? $result['backdrop'] : 'unchanged' ),
			'conflicts' => ! empty( $result['conflicts'] ) && is_array( $result['conflicts'] ) ? count( $result['conflicts'] ) : 0,
		);
	}

	/** @return array<string,mixed> */
	private static function get_job() {
		$job = get_option( self::OPTION_KEY, array() );
		return wp_parse_args( is_array( $job ) ? $job : array(), self::empty_job() );
	}

	/** @return array<string,mixed> */
	private static function empty_job() {
		return array(
			'status'       => 'idle',
			'ids'          => array(),
			'cursor'       => 0,
			'processed'    => 0,
			'total'        => 0,
			'started_at'   => 0,
			'completed_at' => 0,
			'last_error'   => '',
			'counts'       => array( 'ready' => 0, 'partial' => 0, 'conflicts' => 0, 'errors' => 0 ),
			'recent'       => array(),
		);
	}

	private static function schedule_next( $delay ) {
		if ( ! wp_next_scheduled( self::WORK_HOOK ) ) {
			wp_schedule_single_event( time() + absint( $delay ), self::WORK_HOOK );
		}
	}

	private static function authorize() {
		if ( ! current_user_can( 'edit_others_posts' ) ) {
			wp_die( esc_html__( 'You do not have permission to manage Review artwork.', 'lunara-core' ) );
		}
		check_admin_referer( self::NONCE_ACTION );
	}

	private static function page_url() {
		return admin_url( 'edit.php?post_type=review&page=' . self::PAGE_SLUG );
	}
}
