<?php
/**
 * Short-lived atomic claims for canonical Movie identities.
 *
 * @package Lunara_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Lunara_Movie_Identity_Lock {

	const OPTION_PREFIX = 'lunara_movie_identity_lock_';
	const DEFAULT_TTL   = 30;

	/**
	 * Acquire one IMDb-scoped claim using WordPress's unique option name.
	 *
	 * @param mixed $imdb_id Canonical IMDb identity.
	 * @param int   $ttl      Claim lifetime in seconds.
	 * @return array<string,mixed>|false Opaque ownership handle or false.
	 */
	public static function acquire( $imdb_id, $ttl = self::DEFAULT_TTL ) {
		$imdb_id = self::normalize_imdb_title_id( $imdb_id );
		if (
			'' === $imdb_id
			|| ! function_exists( 'add_option' )
			|| ! function_exists( 'get_option' )
		) {
			return false;
		}

		$ttl    = max( 5, min( 120, (int) $ttl ) );
		$key    = self::OPTION_PREFIX . hash( 'sha256', $imdb_id );
		$handle = array(
			'key'        => $key,
			'token'      => self::token(),
			'expires_at' => time() + $ttl,
		);

		if ( add_option( $key, $handle, '', 'no' ) ) {
			return $handle;
		}

		$existing = get_option( $key, false );
		if ( ! is_array( $existing ) || (int) ( $existing['expires_at'] ?? 0 ) >= time() ) {
			return false;
		}

		// Replace only the exact expired value we inspected. A competing owner
		// that wins first changes option_value, causing this CAS to affect zero rows.
		return self::replace_exact( $key, $existing, $handle ) ? $handle : false;
	}

	/**
	 * Release a claim only when its ownership token still matches.
	 *
	 * @param mixed $handle Opaque handle returned by acquire().
	 * @return bool
	 */
	public static function release( $handle ) {
		if (
			! is_array( $handle )
			|| empty( $handle['key'] )
			|| empty( $handle['token'] )
			|| 0 !== strpos( (string) $handle['key'], self::OPTION_PREFIX )
		) {
			return false;
		}

		return self::delete_exact( (string) $handle['key'], $handle );
	}

	/**
	 * Atomically replace one exact option value.
	 *
	 * @param string $key         Option name.
	 * @param mixed  $expected    Value that must still own the row.
	 * @param mixed  $replacement New ownership value.
	 * @return bool
	 */
	private static function replace_exact( $key, $expected, $replacement ) {
		global $wpdb;

		if ( ! self::has_atomic_storage( $wpdb ) ) {
			return false;
		}

		$changed = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s",
				maybe_serialize( $replacement ),
				$key,
				maybe_serialize( $expected )
			)
		);
		if ( 1 !== (int) $changed ) {
			return false;
		}

		self::clear_option_cache( $key );
		return true;
	}

	/**
	 * Atomically delete one exact option value.
	 *
	 * @param string $key      Option name.
	 * @param mixed  $expected Ownership value that must still match.
	 * @return bool
	 */
	private static function delete_exact( $key, $expected ) {
		global $wpdb;

		if ( ! self::has_atomic_storage( $wpdb ) ) {
			return false;
		}

		$changed = $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s",
				$key,
				maybe_serialize( $expected )
			)
		);
		if ( 1 !== (int) $changed ) {
			return false;
		}

		self::clear_option_cache( $key );
		return true;
	}

	/** @param mixed $storage WordPress database adapter. @return bool */
	private static function has_atomic_storage( $storage ) {
		return is_object( $storage )
			&& ! empty( $storage->options )
			&& is_callable( array( $storage, 'prepare' ) )
			&& is_callable( array( $storage, 'query' ) )
			&& function_exists( 'maybe_serialize' );
	}

	/** @param string $key Option name. */
	private static function clear_option_cache( $key ) {
		if ( function_exists( 'wp_cache_delete' ) ) {
			wp_cache_delete( $key, 'options' );
		}
	}

	/**
	 * Normalize through the shared Movie/Debrief identity contract.
	 *
	 * @param mixed $value Raw IMDb identity.
	 * @return string
	 */
	private static function normalize_imdb_title_id( $value ) {
		if ( class_exists( 'Lunara_Movie_Import_Contract' ) ) {
			return Lunara_Movie_Import_Contract::normalize_imdb_title_id( $value );
		}
		if ( class_exists( 'Lunara_Debrief_Contract' ) ) {
			return Lunara_Debrief_Contract::normalize_imdb_title_id( $value );
		}

		$value = strtolower( trim( (string) $value ) );
		return preg_match( '/\b(tt\d{6,9})\b/', $value, $matches ) ? $matches[1] : '';
	}

	/**
	 * Generate an unguessable ownership token without storing an identity.
	 *
	 * @return string
	 */
	private static function token() {
		if ( function_exists( 'wp_generate_uuid4' ) ) {
			return hash( 'sha256', wp_generate_uuid4() . '|' . microtime( true ) );
		}

		return hash( 'sha256', uniqid( '', true ) . '|' . microtime( true ) );
	}
}
