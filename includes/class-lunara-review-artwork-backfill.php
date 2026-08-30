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
	const REFRESH_ACTION = 'lunara_review_artwork_health_refresh';
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
		add_action( 'admin_post_' . self::REFRESH_ACTION, array( __CLASS__, 'handle_refresh_health' ) );
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

	/** Render compact read-only health and guarded owner controls. */
	public static function render_page() {
		if ( ! current_user_can( 'edit_others_posts' ) ) {
			wp_die( esc_html__( 'You do not have permission to audit Review artwork.', 'lunara-core' ) );
		}

		$health     = Lunara_Core_Site_Studio_Bridge::review_identity_artwork_status();
		$census     = $health['coverage'];
		$job        = self::get_job();
		$credential = $health['credentials'];
		$running    = 'running' === $job['status'];
		?>
		<div class="wrap lunara-review-artwork-audit">
			<h1><?php esc_html_e( 'Review Artwork Audit', 'lunara-core' ); ?></h1>
			<p><?php esc_html_e( 'This runner uses each Review’s canonical IMDb title ID to call the movie providers, localize the primary TMDb poster and backdrop, and reconnect automatic Review artwork. Custom and unmarked Media Library choices are never overwritten.', 'lunara-core' ); ?></p>

			<?php if ( ! $credential['known'] ) : ?>
				<div class="notice notice-warning inline"><p><?php esc_html_e( 'Health is not known yet. Refresh the compact health snapshot to update coverage and credential readiness.', 'lunara-core' ); ?></p></div>
			<?php elseif ( ! $credential['ready'] ) : ?>
				<div class="notice notice-error inline"><p><?php esc_html_e( 'OMDb and TMDb credentials are not both available. The audit is safe to view, but the backfill cannot start.', 'lunara-core' ); ?></p></div>
			<?php endif; ?>

			<?php if ( isset( $_GET['health_snapshot'] ) && 'refreshed' === sanitize_key( wp_unslash( $_GET['health_snapshot'] ) ) ) : ?>
				<div class="notice notice-success inline"><p><?php esc_html_e( 'Health snapshot refreshed.', 'lunara-core' ); ?></p></div>
			<?php elseif ( isset( $_GET['health_snapshot'] ) && 'indeterminate' === sanitize_key( wp_unslash( $_GET['health_snapshot'] ) ) ) : ?>
				<div class="notice notice-warning inline"><p><?php esc_html_e( 'Health snapshot refresh could not be confirmed. No fresh state was reported.', 'lunara-core' ); ?></p></div>
			<?php elseif ( isset( $_GET['health_snapshot'] ) && 'failed' === sanitize_key( wp_unslash( $_GET['health_snapshot'] ) ) ) : ?>
				<div class="notice notice-error inline"><p><?php esc_html_e( 'Health snapshot refresh failed. No fresh state was reported.', 'lunara-core' ); ?></p></div>
			<?php endif; ?>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin:16px 0;">
				<input type="hidden" name="action" value="<?php echo esc_attr( self::REFRESH_ACTION ); ?>">
				<?php wp_nonce_field( self::NONCE_ACTION ); ?>
				<?php submit_button( __( 'Refresh health snapshot', 'lunara-core' ), 'secondary', 'submit', false ); ?>
			</form>

			<table class="widefat striped" style="max-width:960px;margin:20px 0;">
				<tbody>
					<tr><th><?php esc_html_e( 'Reviews found', 'lunara-core' ); ?></th><td><?php echo esc_html( self::display_count( $census['total'] ) ); ?></td></tr>
					<tr><th><?php esc_html_e( 'Exact IMDb identities ready', 'lunara-core' ); ?></th><td><?php echo esc_html( self::display_count( $census['identity_ready'] ) ); ?></td></tr>
					<tr><th><?php esc_html_e( 'Missing IMDb identity', 'lunara-core' ); ?></th><td><?php echo esc_html( self::display_count( $census['missing_identity'] ) ); ?></td></tr>
					<tr><th><?php esc_html_e( 'Poster currently missing', 'lunara-core' ); ?></th><td><?php echo esc_html( self::display_count( $census['missing_poster'] ) ); ?></td></tr>
					<tr><th><?php esc_html_e( 'Banner currently missing', 'lunara-core' ); ?></th><td><?php echo esc_html( self::display_count( $census['missing_backdrop'] ) ); ?></td></tr>
					<tr><th><?php esc_html_e( 'Reviews with protected custom art', 'lunara-core' ); ?></th><td><?php echo esc_html( self::display_count( $census['custom_protected'] ) ); ?></td></tr>
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
		$state = self::get_job_state();
		if ( null === $state ) {
			wp_safe_redirect( self::page_url() );
			exit;
		}
		$job = $state['job'];
		if ( 'running' === $job['status'] ) {
			wp_safe_redirect( self::page_url() );
			exit;
		}

		$credential = self::credentials_status();
		$coverage   = self::census();
		if ( ! $credential['ready'] ) {
			$current = self::get_job_state();
			if ( null !== $current ) {
				self::write_full_health_snapshot( $coverage, $current['job'], $credential );
			}
			wp_safe_redirect( self::page_url() );
			exit;
		}

		if ( 'paused' === $job['status'] && $job['cursor'] < $job['total'] ) {
			$job['status'] = 'running';
		} elseif ( in_array( $job['status'], array( 'idle', 'complete' ), true ) ) {
			$ids = self::review_ids( true );
			$job = self::empty_job();
			$job['status']     = empty( $ids ) ? 'complete' : 'running';
			$job['ids']        = $ids;
			$job['total']      = count( $ids );
			$job['started_at'] = time();
			$job['completed_at'] = empty( $ids ) ? $job['started_at'] : 0;
		} else {
			wp_safe_redirect( self::page_url() );
			exit;
		}
		$saved = self::save_job( $job, true, $coverage, $credential, $state['row'] );
		if ( $saved && 'running' === $job['status'] ) {
			self::schedule_next( 1 );
		}

		wp_safe_redirect( self::page_url() );
		exit;
	}

	/** Pause without discarding progress or the audit trail. */
	public static function handle_pause() {
		self::authorize();
		$state = self::get_job_state();
		if ( null === $state ) {
			wp_safe_redirect( self::page_url() );
			exit;
		}
		$job = $state['job'];
		if ( 'running' === $job['status'] ) {
			$job['status'] = 'paused';
		}
		if ( 'paused' === $job['status'] && self::save_job( $job, false, null, null, $state['row'] ) ) {
			wp_clear_scheduled_hook( self::WORK_HOOK );
		}
		wp_safe_redirect( self::page_url() );
		exit;
	}

	/** Refresh the compact owner health snapshot on explicit request only. */
	public static function handle_refresh_health() {
		self::authorize();
		$published = false;
		$outcome   = 'failed';
		try {
			$published = self::write_full_health_snapshot( self::census(), self::get_job(), self::credentials_status(), $outcome );
		} catch ( Throwable $error ) {
			$published = false;
			$outcome   = 'failed';
		}

		$result = $published && 'resolved' === $outcome ? 'refreshed' : ( $published && 'indeterminate' === $outcome ? 'indeterminate' : 'failed' );
		$url    = add_query_arg( 'health_snapshot', $result, self::page_url() );
		wp_safe_redirect( $url );
	}

	/** Process one Review, then yield before the next provider request set. */
	public static function process_next() {
		$state = self::get_job_state();
		if ( null === $state ) {
			return;
		}
		$job = $state['job'];
		if ( 'running' !== $job['status'] || get_transient( self::LOCK_KEY ) ) {
			return;
		}

		set_transient( self::LOCK_KEY, 1, MINUTE_IN_SECONDS );
		$base_job = $job;
		$saved    = false;
		try {
			if ( $job['cursor'] >= $job['total'] || empty( $job['ids'][ $job['cursor'] ] ) ) {
				$job['status']       = 'complete';
				$job['completed_at'] = time();
				self::save_job( $job, true, null, null, $state['row'] );
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
			$saved = self::save_job( $job, 'complete' === $job['status'], null, null, $state['row'] );
			if ( ! $saved ) {
				$reconciled = self::reconcile_paused_worker_progress( $base_job, $job );
				if ( is_array( $reconciled ) ) {
					$job   = $reconciled;
					$saved = true;
				}
			}
		} catch ( Throwable $error ) {
			$job['status']     = 'paused';
			$job['last_error'] = sanitize_text_field( $error->getMessage() );
			$saved = self::save_job( $job, false, null, null, $state['row'] );
		} finally {
			delete_transient( self::LOCK_KEY );
		}

		if ( $saved && 'running' === $job['status'] ) {
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
		if ( ! class_exists( 'Lunara_Movie_Provider_Gateway', false ) && class_exists( 'Lunara_Core', false ) && method_exists( 'Lunara_Core', 'load_movie_provider_gateway' ) ) {
			Lunara_Core::load_movie_provider_gateway();
		}
		if ( ! class_exists( 'Lunara_Movie_Provider_Gateway', false ) ) {
			return array( 'omdb' => false, 'tmdb' => false, 'ready' => false );
		}
		$gateway = new Lunara_Movie_Provider_Gateway();
		return $gateway->credentials_status();
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

	/** @return array<string,mixed>|null */
	private static function get_job_state() {
		$row = self::read_job_option_row();
		if ( ! $row['read_ok'] ) {
			return null;
		}

		$raw = $row['exists'] ? self::unserialize_job_option( $row['option_value'] ) : array();
		$job = wp_parse_args( is_array( $raw ) ? $raw : array(), self::empty_job() );
		return array( 'job' => $job, 'row' => $row );
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

	/**
	 * Persist the canonical full job first, then best-effort compact health.
	 *
	 * @param array<string,mixed>      $job         Already-loaded full job.
	 * @param bool                     $refresh_full Whether this is a full refresh seam.
	 * @param array<string,mixed>|null $coverage    Optional already-read census.
	 * @param array<string,bool>|null  $credentials Optional already-read readiness.
	 * @param array<string,mixed>|null $expected_row Exact physical prestate for state-conditional mutation.
	 * @return bool Whether the exact canonical job was conditionally persisted and verified.
	 */
	private static function save_job( $job, $refresh_full = false, $coverage = null, $credentials = null, $expected_row = null ) {
		if ( ! is_array( $expected_row ) ) {
			$state = self::get_job_state();
			if ( null === $state ) {
				return false;
			}
			$expected_row = $state['row'];
		}

		if ( ! self::persist_job_row( $expected_row, $job ) ) {
			return false;
		}

		try {
			if ( $refresh_full ) {
				$coverage    = is_array( $coverage ) ? $coverage : self::census();
				$credentials = is_array( $credentials ) ? $credentials : self::credentials_status();
				self::write_full_health_snapshot( $coverage, $job, $credentials );
			} else {
				Lunara_Core_Site_Studio_Bridge::update_artwork_health_job_projection( $job );
			}
		} catch ( Throwable $error ) {
			// Compact snapshot failure must never interrupt the canonical job write.
		}

		return true;
	}

	/** @return array<string,mixed>|false */
	private static function reconcile_paused_worker_progress( $base_job, $advanced_job ) {
		$latest          = self::get_job_state();
		$expected_paused = $base_job;
		$expected_paused['status'] = 'paused';
		if (
			null === $latest
			|| $latest['job'] !== $expected_paused
			|| $advanced_job['cursor'] >= $advanced_job['total']
		) {
			return false;
		}

		$merged                 = $latest['job'];
		$merged['cursor']       = $advanced_job['cursor'];
		$merged['processed']    = $advanced_job['processed'];
		$merged['counts']       = $advanced_job['counts'];
		$merged['recent']       = $advanced_job['recent'];
		$merged['last_error']   = $advanced_job['last_error'];
		$merged['completed_at'] = 0;
		return self::save_job( $merged, false, null, null, $latest['row'] ) ? $merged : false;
	}

	/** @param array<string,mixed> $expected Exact physical prestate. @param array<string,mixed> $job Desired canonical job. @return bool */
	private static function persist_job_row( $expected, $job ) {
		$desired = self::serialize_job_option( $job );
		if ( $expected['exists'] && $desired === $expected['option_value'] && self::autoload_is_disabled( $expected['autoload'] ) ) {
			return true;
		}

		$write    = self::replace_job_option_row( $expected, $desired, 'no' );
		$verified = self::read_job_option_row();
		if ( self::job_row_matches( $verified, $desired, 'no', $write['option_id'] ) ) {
			return true;
		}

		self::restore_job_option_row( $expected, $desired, $write['option_id'] );
		return false;
	}

	/** @return array<string,mixed> */
	private static function read_job_option_row() {
		global $wpdb;
		$empty = array( 'read_ok' => false, 'exists' => false, 'option_id' => 0, 'option_value' => '', 'autoload' => '' );
		if ( ! is_object( $wpdb ) || ! isset( $wpdb->options ) || ! method_exists( $wpdb, 'prepare' ) || ! method_exists( $wpdb, 'get_row' ) ) {
			return $empty;
		}

		$wpdb->last_error = '';
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT option_id, option_value, autoload FROM {$wpdb->options} WHERE option_name = %s LIMIT 1",
				self::OPTION_KEY
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
			|| ! ctype_digit( (string) $row['option_id'] )
			|| (int) $row['option_id'] <= 0
			|| ! is_string( $row['option_value'] )
			|| ! is_string( $row['autoload'] )
		) {
			return $empty;
		}

		return array( 'read_ok' => true, 'exists' => true, 'option_id' => (int) $row['option_id'], 'option_value' => $row['option_value'], 'autoload' => $row['autoload'] );
	}

	/** @param array<string,mixed> $expected Exact prestate. @param string $value Serialized job. @param string $autoload Desired autoload. @return array<string,mixed> */
	private static function replace_job_option_row( $expected, $value, $autoload ) {
		global $wpdb;
		$outcome = array( 'result' => false, 'option_id' => $expected['exists'] ? $expected['option_id'] : 0 );
		if ( ! is_object( $wpdb ) || ! isset( $wpdb->options ) || ! method_exists( $wpdb, 'prepare' ) || ! method_exists( $wpdb, 'query' ) ) {
			return $outcome;
		}

		try {
			if ( $expected['exists'] ) {
				$outcome['result'] = $wpdb->query(
					$wpdb->prepare(
						"UPDATE {$wpdb->options} SET option_value = %s, autoload = %s WHERE option_id = %d AND option_name = %s AND BINARY option_value = BINARY %s AND BINARY autoload = BINARY %s LIMIT 1",
						$value,
						$autoload,
						$expected['option_id'],
						self::OPTION_KEY,
						$expected['option_value'],
						$expected['autoload']
					)
				);
			} else {
				$outcome['result'] = $wpdb->query(
					$wpdb->prepare(
						"INSERT INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, %s)",
						self::OPTION_KEY,
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

		self::clear_job_option_cache();
		return $outcome;
	}

	/** @param array<string,mixed> $prior Exact original row. @param string $desired Serialized attempted job. @param int $option_id Fenced row identity. @return bool */
	private static function restore_job_option_row( $prior, $desired, $option_id ) {
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
						self::OPTION_KEY,
						$desired,
						'no'
					)
				);
			} else {
				$wpdb->query(
					$wpdb->prepare(
						"DELETE FROM {$wpdb->options} WHERE option_id = %d AND option_name = %s AND BINARY option_value = BINARY %s AND BINARY autoload = BINARY %s LIMIT 1",
						$option_id,
						self::OPTION_KEY,
						$desired,
						'no'
					)
				);
			}
		} catch ( Throwable $error ) {
			return false;
		}

		self::clear_job_option_cache();
		return self::job_rows_equal( self::read_job_option_row(), $prior );
	}

	/** @param array<string,mixed> $row Row. @param string $value Raw value. @param string $autoload Raw autoload. @param int $option_id Physical ID. @return bool */
	private static function job_row_matches( $row, $value, $autoload, $option_id ) {
		return $row['read_ok'] && $row['exists'] && $option_id > 0 && $row['option_id'] === $option_id && $row['option_value'] === $value && $row['autoload'] === $autoload;
	}

	/** @param array<string,mixed> $left Row. @param array<string,mixed> $right Row. @return bool */
	private static function job_rows_equal( $left, $right ) {
		return $left['read_ok'] && $right['read_ok'] && $left['exists'] === $right['exists'] && ( ! $left['exists'] || ( $left['option_id'] === $right['option_id'] && $left['option_value'] === $right['option_value'] && $left['autoload'] === $right['autoload'] ) );
	}

	/** @param string $autoload Raw autoload. @return bool */
	private static function autoload_is_disabled( $autoload ) {
		return in_array( $autoload, array( 'no', 'off', 'auto-off' ), true );
	}

	/** @param mixed $value Job value. @return string */
	private static function serialize_job_option( $value ) {
		return function_exists( 'maybe_serialize' ) ? maybe_serialize( $value ) : serialize( $value );
	}

	/** @param string $value Raw job value. @return mixed */
	private static function unserialize_job_option( $value ) {
		return function_exists( 'maybe_unserialize' ) ? maybe_unserialize( $value ) : @unserialize( $value );
	}

	private static function clear_job_option_cache() {
		if ( function_exists( 'wp_cache_delete' ) ) {
			wp_cache_delete( self::OPTION_KEY, 'options' );
			wp_cache_delete( 'alloptions', 'options' );
			wp_cache_delete( 'notoptions', 'options' );
		}
	}

	/** @return bool */
	private static function write_full_health_snapshot( $coverage, $job, $credentials, &$outcome = null ) {
		return Lunara_Core_Site_Studio_Bridge::write_artwork_health_snapshot( $coverage, $job, $credentials, $outcome );
	}

	/** @param int|null $count Count or unknown. @return int|string */
	private static function display_count( $count ) {
		return null === $count ? __( 'Unknown', 'lunara-core' ) : max( 0, (int) $count );
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
