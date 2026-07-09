<?php
/**
 * Lunara Guardian — the availability safety net.
 *
 * Lives in the CORE PLUGIN on purpose: a plugin keeps running even when
 * the active theme is knocked out, which is exactly the failure this
 * guards against. Two incidents motivated it — a half-deleted plugin
 * fataling wp-admin, and WordPress silently falling back to a default
 * theme (Twenty Twenty-Two) so the whole site looked destroyed while the
 * Lunara theme sat untouched on disk.
 *
 * What it does, conservatively:
 *   1. Blesses the Lunara theme as the canonical active theme whenever
 *      one is legitimately in use.
 *   2. If WordPress ever falls back to a core default theme (any
 *      "twenty*" theme), or activates the Blocksy parent instead of the
 *      blessed Lunara child theme, it switches back automatically on the
 *      very next request — no human needed — and records the event.
 *   3. Surfaces a dismissible admin notice so the editor knows a heal
 *      happened, when, and from what.
 *
 * It deliberately does NOT touch switches to any other non-default
 * stylesheet, so trying out a real third-party theme is respected. The
 * Blocksy-parent exception above can be disabled with the constant
 * LUNARA_GUARDIAN_DISABLE or the lunara_guardian_enabled filter.
 *
 * @package Lunara_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Lunara_Guardian {

	const BLESSED_OPTION = 'lunara_guardian_blessed_theme';
	const LOG_OPTION     = 'lunara_guardian_events';
	const THEME_PREFIX   = 'lunara-theme-blocks';

	public static function init() {
		add_action( 'after_setup_theme', array( __CLASS__, 'bless_active_theme' ), 1 );
		add_action( 'after_setup_theme', array( __CLASS__, 'guard_active_theme' ), 2 );
		add_action( 'admin_notices', array( __CLASS__, 'render_admin_notice' ) );
		add_action( 'admin_post_lunara_guardian_dismiss', array( __CLASS__, 'dismiss_notice' ) );
	}

	public static function is_enabled() {
		if ( defined( 'LUNARA_GUARDIAN_DISABLE' ) && LUNARA_GUARDIAN_DISABLE ) {
			return false;
		}
		return (bool) apply_filters( 'lunara_guardian_enabled', true );
	}

	/**
	 * A Lunara theme is any stylesheet under the theme's directory family.
	 */
	private static function is_lunara_theme( $stylesheet ) {
		return ( 0 === strpos( (string) $stylesheet, self::THEME_PREFIX ) );
	}

	/**
	 * The takeover signatures Guardian reverses while a Lunara theme is
	 * blessed: WordPress core defaults ("twenty…") — the classic missing-
	 * theme fallback — and the Blocksy parent stylesheet itself. Lunara is a
	 * Blocksy child theme, so Blocksy remains its required template; a
	 * stylesheet value of "blocksy" means the parent was activated directly
	 * and replaced the child presentation. A truly deliberate parent-theme
	 * activation goes through the lunara_guardian_enabled filter or
	 * LUNARA_GUARDIAN_DISABLE first.
	 */
	private static function is_unblessed_takeover( $stylesheet ) {
		$stylesheet = (string) $stylesheet;
		if ( 0 === strpos( $stylesheet, 'twenty' ) ) {
			return true;
		}
		return ( 'blocksy' === $stylesheet || 0 === strpos( $stylesheet, 'blocksy-' ) );
	}

	/**
	 * Record the currently-active Lunara theme as canonical.
	 */
	public static function bless_active_theme() {
		$stylesheet = get_stylesheet();
		if ( ! self::is_lunara_theme( $stylesheet ) ) {
			return;
		}
		if ( get_option( self::BLESSED_OPTION ) === $stylesheet ) {
			return; // Already blessed; nothing to write.
		}
		$theme = wp_get_theme( $stylesheet );
		if ( ! $theme->exists() || $theme->errors() ) {
			return;
		}
		update_option( self::BLESSED_OPTION, $stylesheet, true );
	}

	/**
	 * Heal an involuntary fallback to a core default theme.
	 */
	public static function guard_active_theme() {
		if ( ! self::is_enabled() ) {
			return;
		}

		$current = get_stylesheet();
		if ( ! self::is_unblessed_takeover( $current ) ) {
			return; // Any other theme is a deliberate choice — leave it alone.
		}

		$blessed = (string) get_option( self::BLESSED_OPTION, '' );
		if ( '' === $blessed || $blessed === $current ) {
			return;
		}

		$theme = wp_get_theme( $blessed );
		if ( ! $theme->exists() || $theme->errors() ) {
			return; // Never restore to something missing or broken.
		}

		// Heal: flips the active-theme options so the next request serves
		// the Lunara theme again. The current (already-broken) request is
		// unavoidable, but the site self-recovers immediately after.
		switch_theme( $blessed );
		self::log_event( $current, $blessed );

		if ( function_exists( 'rocket_clean_domain' ) ) {
			rocket_clean_domain();
		}
	}

	/**
	 * Append a heal event (kept to the most recent handful, no autoload).
	 */
	private static function log_event( $from, $to ) {
		$events   = get_option( self::LOG_OPTION, array() );
		$events   = is_array( $events ) ? $events : array();
		$events[] = array(
			'from' => (string) $from,
			'to'   => (string) $to,
			'time' => time(),
		);
		if ( count( $events ) > 10 ) {
			$events = array_slice( $events, -10 );
		}
		update_option( self::LOG_OPTION, $events, false );
	}

	/**
	 * Tell the editor, in wp-admin, that the site healed itself.
	 */
	public static function render_admin_notice() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$events = get_option( self::LOG_OPTION, array() );
		if ( empty( $events ) || ! is_array( $events ) ) {
			return;
		}
		$latest    = end( $events );
		$when       = isset( $latest['time'] ) ? (int) $latest['time'] : 0;
		$from       = isset( $latest['from'] ) ? (string) $latest['from'] : '';
		$count      = count( $events );
		$dismiss_url = wp_nonce_url( admin_url( 'admin-post.php?action=lunara_guardian_dismiss' ), 'lunara_guardian_dismiss' );
		?>
		<div class="notice notice-success">
			<p>
				<strong><?php esc_html_e( 'Lunara Guardian restored your theme.', 'lunara-core' ); ?></strong>
				<?php
				echo ' ' . esc_html(
					sprintf(
						/* translators: 1: fallback theme slug, 2: human time diff */
						__( 'The site had fallen back to “%1$s”; the Lunara theme was reactivated automatically %2$s ago. Your content was never affected.', 'lunara-core' ),
						$from,
						$when ? human_time_diff( $when ) : __( 'moments', 'lunara-core' )
					)
				);
				if ( $count > 1 ) {
					echo ' ' . esc_html( sprintf( /* translators: %d: event count */ __( '(%d self-heals recorded.)', 'lunara-core' ), $count ) );
				}
				?>
				<a href="<?php echo esc_url( $dismiss_url ); ?>" style="margin-left:6px;"><?php esc_html_e( 'Dismiss', 'lunara-core' ); ?></a>
			</p>
		</div>
		<?php
	}

	public static function dismiss_notice() {
		if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( 'lunara_guardian_dismiss' ) ) {
			wp_die( esc_html__( 'Guardian dismiss request rejected.', 'lunara-core' ) );
		}
		delete_option( self::LOG_OPTION );
		wp_safe_redirect( wp_get_referer() ? wp_get_referer() : admin_url() );
		exit;
	}
}
