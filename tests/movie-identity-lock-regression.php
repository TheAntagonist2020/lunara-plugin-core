<?php
/**
 * Dependency-free regression checks for canonical Movie identity locks.
 *
 * Run with: php tests/movie-identity-lock-regression.php
 */

define( 'ABSPATH', __DIR__ . '/' );

$GLOBALS['lunara_identity_lock_test'] = array(
	'options'  => array(),
	'autoload' => array(),
	'cache_deletes' => array(),
	'uuid'     => 0,
);

final class Lunara_Test_WPDB {
	public $options = 'wp_options';
	public $before_query = null;

	public function prepare( $query, ...$args ) {
		return array(
			'query' => $query,
			'args'  => $args,
		);
	}

	public function query( $statement ) {
		if ( is_callable( $this->before_query ) ) {
			$callback           = $this->before_query;
			$this->before_query = null;
			$callback();
		}

		$args = $statement['args'];
		if ( 0 === strpos( $statement['query'], 'UPDATE ' ) ) {
			list( $replacement, $key, $expected ) = $args;
			if (
				! array_key_exists( $key, $GLOBALS['lunara_identity_lock_test']['options'] )
				|| maybe_serialize( $GLOBALS['lunara_identity_lock_test']['options'][ $key ] ) !== $expected
			) {
				return 0;
			}

			$GLOBALS['lunara_identity_lock_test']['options'][ $key ] = unserialize( $replacement, array( 'allowed_classes' => false ) );
			return 1;
		}

		list( $key, $expected ) = $args;
		if (
			! array_key_exists( $key, $GLOBALS['lunara_identity_lock_test']['options'] )
			|| maybe_serialize( $GLOBALS['lunara_identity_lock_test']['options'][ $key ] ) !== $expected
		) {
			return 0;
		}

		unset( $GLOBALS['lunara_identity_lock_test']['options'][ $key ] );
		return 1;
	}
}

$wpdb = new Lunara_Test_WPDB();

final class Lunara_Debrief_Contract {
	public static function normalize_imdb_title_id( $value ) {
		$value = strtolower( trim( (string) $value ) );
		return preg_match( '/\b(tt\d{6,9})\b/', $value, $matches ) ? $matches[1] : '';
	}
}

function add_option( $key, $value, $deprecated = '', $autoload = null ) {
	if ( array_key_exists( $key, $GLOBALS['lunara_identity_lock_test']['options'] ) ) {
		return false;
	}
	$GLOBALS['lunara_identity_lock_test']['options'][ $key ]  = $value;
	$GLOBALS['lunara_identity_lock_test']['autoload'][ $key ] = $autoload;
	return true;
}

function get_option( $key, $default = false ) {
	return array_key_exists( $key, $GLOBALS['lunara_identity_lock_test']['options'] )
		? $GLOBALS['lunara_identity_lock_test']['options'][ $key ]
		: $default;
}

function maybe_serialize( $value ) {
	return is_array( $value ) || is_object( $value ) ? serialize( $value ) : $value;
}

function wp_cache_delete( $key, $group = '' ) {
	$GLOBALS['lunara_identity_lock_test']['cache_deletes'][] = array( $key, $group );
	return true;
}

function wp_generate_uuid4() {
	++$GLOBALS['lunara_identity_lock_test']['uuid'];
	return '00000000-0000-4000-8000-' . str_pad( (string) $GLOBALS['lunara_identity_lock_test']['uuid'], 12, '0', STR_PAD_LEFT );
}

function lunara_identity_lock_assert_same( $expected, $actual, $message ) {
	if ( $expected !== $actual ) {
		throw new RuntimeException(
			$message . "\nExpected: " . var_export( $expected, true ) . "\nActual: " . var_export( $actual, true )
		);
	}
}

function lunara_identity_lock_assert_true( $condition, $message ) {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

require dirname( __DIR__ ) . '/includes/class-lunara-movie-identity-lock.php';

lunara_identity_lock_assert_same( false, Lunara_Movie_Identity_Lock::acquire( 'tt12345' ), 'Invalid IMDb identities must never create a lock.' );
lunara_identity_lock_assert_same( false, Lunara_Movie_Identity_Lock::acquire( 'tt1234567890' ), 'Ten-digit IMDb identities must remain outside the shared contract.' );

$first = Lunara_Movie_Identity_Lock::acquire( 'IMDb tt1234567', 30 );
lunara_identity_lock_assert_true( is_array( $first ), 'The first canonical identity claim must succeed.' );
lunara_identity_lock_assert_true( false === strpos( $first['key'], 'tt1234567' ), 'Option names must hash rather than expose the IMDb identity.' );
lunara_identity_lock_assert_same( 'no', $GLOBALS['lunara_identity_lock_test']['autoload'][ $first['key'] ], 'Identity locks must never autoload on public requests.' );
lunara_identity_lock_assert_same( false, Lunara_Movie_Identity_Lock::acquire( 'tt1234567' ), 'A concurrent claim for the same identity must fail without sleeping.' );

$wrong = $first;
$wrong['token'] = str_repeat( '0', 64 );
lunara_identity_lock_assert_same( false, Lunara_Movie_Identity_Lock::release( $wrong ), 'A non-owner must not release another request\'s claim.' );
lunara_identity_lock_assert_true( isset( $GLOBALS['lunara_identity_lock_test']['options'][ $first['key'] ] ), 'A rejected release must preserve the active claim.' );
lunara_identity_lock_assert_same( true, Lunara_Movie_Identity_Lock::release( $first ), 'The owner must be able to release its claim.' );

$stale = Lunara_Movie_Identity_Lock::acquire( 'tt7654321' );
$GLOBALS['lunara_identity_lock_test']['options'][ $stale['key'] ]['expires_at'] = time() - 1;
$replacement = Lunara_Movie_Identity_Lock::acquire( 'tt7654321' );
lunara_identity_lock_assert_true( is_array( $replacement ), 'An expired claim must permit one new owner.' );
lunara_identity_lock_assert_true( $stale['token'] !== $replacement['token'], 'Stale takeover must issue a new ownership token.' );
lunara_identity_lock_assert_same( false, Lunara_Movie_Identity_Lock::release( $stale ), 'A stale owner must not delete the replacement claim.' );
lunara_identity_lock_assert_same( true, Lunara_Movie_Identity_Lock::release( $replacement ), 'The replacement owner must release normally.' );

$expired = Lunara_Movie_Identity_Lock::acquire( 'tt2222222' );
$GLOBALS['lunara_identity_lock_test']['options'][ $expired['key'] ]['expires_at'] = time() - 1;
$rival = array(
	'key'        => $expired['key'],
	'token'      => str_repeat( 'a', 64 ),
	'expires_at' => time() + 30,
);
$wpdb->before_query = static function () use ( $rival ) {
	$GLOBALS['lunara_identity_lock_test']['options'][ $rival['key'] ] = $rival;
};
lunara_identity_lock_assert_same( false, Lunara_Movie_Identity_Lock::acquire( 'tt2222222' ), 'A stale takeover must lose when another owner replaces the inspected value first.' );
lunara_identity_lock_assert_same( $rival, $GLOBALS['lunara_identity_lock_test']['options'][ $rival['key'] ], 'A failed stale takeover must preserve the competing owner.' );
lunara_identity_lock_assert_same( true, Lunara_Movie_Identity_Lock::release( $rival ), 'The interleaving winner must retain a releasable claim.' );

$release_owner = Lunara_Movie_Identity_Lock::acquire( 'tt3333333' );
$release_replacement = array(
	'key'        => $release_owner['key'],
	'token'      => str_repeat( 'b', 64 ),
	'expires_at' => time() + 30,
);
$wpdb->before_query = static function () use ( $release_replacement ) {
	$GLOBALS['lunara_identity_lock_test']['options'][ $release_replacement['key'] ] = $release_replacement;
};
lunara_identity_lock_assert_same( false, Lunara_Movie_Identity_Lock::release( $release_owner ), 'A paused owner must not delete a replacement that wins before atomic release.' );
lunara_identity_lock_assert_same( $release_replacement, $GLOBALS['lunara_identity_lock_test']['options'][ $release_owner['key'] ], 'Token-matched deletion must preserve a replacement owner.' );
lunara_identity_lock_assert_same( true, Lunara_Movie_Identity_Lock::release( $release_replacement ), 'The release-race winner must retain a releasable claim.' );

echo "Movie identity lock regression checks passed.\n";
