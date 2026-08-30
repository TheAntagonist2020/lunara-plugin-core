<?php
/**
 * Runtime contracts for Core-owned Site Studio destinations and health DTOs.
 *
 * Run with: php tests/site-studio-health-regression.php
 */

define( 'ABSPATH', __DIR__ . '/' );
define( 'LUNARA_CORE_VERSION', '0.8.10' );

$root          = dirname( __DIR__ );
$bridge        = $root . '/includes/class-lunara-core-site-studio-bridge.php';
$bootstrap     = file_get_contents( $root . '/lunara-core.php' );
$bridge_source = file_get_contents( $bridge );

$GLOBALS['lunara_core_health_state'] = array(
	'capabilities'       => array(),
	'events'             => array(),
	'filters'            => array(),
	'options'            => array(),
	'rows'               => array(),
	'autoloads'          => array(),
	'next_option_id'     => 101,
	'options_engine'     => 'InnoDB',
	'option_reads'       => array(),
	'option_writes'      => array(),
	'cache_deletes'      => array(),
	'update_calls'       => 0,
	'fail_update_calls'  => array(),
	'poison_on_update'   => 0,
	'poison_reads'       => 0,
	'poison_row_reads'   => 0,
	'concurrent_on_update' => 0,
	'reject_writes'      => false,
	'db_update_fails'    => false,
	'db_update_throws'   => false,
	'db_read_errors'     => 0,
	'transaction_backup' => null,
	'fail_commit'        => false,
	'commit_applies_but_false' => false,
	'commit_applies_but_throws' => false,
	'commit_failure_read_errors' => false,
	'fail_rollback'      => false,
	'fail_start'         => false,
	'throw_start'        => false,
	'start_applies_but_false' => false,
	'start_applies_but_throws' => false,
	'concurrent_on_start' => false,
	'taxonomies'         => array(),
	'post_types'         => array( 'review' => true ),
	'malicious_callback' => false,
);

final class Lunara_Core_Health_WPDB {
	public $options = 'wp_options';
	public $last_error = '';
	public $insert_id = 0;

	public function prepare( $query, ...$values ) {
		return array( 'query' => $query, 'values' => $values );
	}

	public function get_var( $prepared ) {
		$key = is_array( $prepared ) ? $prepared['values'][0] : '';
		return array_key_exists( $key, $GLOBALS['lunara_core_health_state']['autoloads'] )
			? $GLOBALS['lunara_core_health_state']['autoloads'][ $key ]
			: null;
	}

	public function get_row( $prepared, $output = null ) {
		if ( is_array( $prepared ) && 0 === strpos( $prepared['query'], 'SHOW TABLE STATUS' ) ) {
			return array( 'Engine' => $GLOBALS['lunara_core_health_state']['options_engine'] );
		}
		$key = is_array( $prepared ) ? $prepared['values'][0] : '';
		if ( $GLOBALS['lunara_core_health_state']['db_read_errors'] > 0 ) {
			$GLOBALS['lunara_core_health_state']['db_read_errors']--;
			$this->last_error = 'simulated physical read failure';
			return null;
		}
		if ( ! array_key_exists( $key, $GLOBALS['lunara_core_health_state']['rows'] ) ) {
			return null;
		}
		$row = $GLOBALS['lunara_core_health_state']['rows'][ $key ];
		if ( $GLOBALS['lunara_core_health_state']['poison_row_reads'] > 0 ) {
			$GLOBALS['lunara_core_health_state']['poison_row_reads']--;
			$row['option_value'] = serialize( array( 'schema' => 'poisoned-physical-row' ) );
		}
		return $row;
	}

	public function query( $prepared ) {
		if ( is_string( $prepared ) ) {
			if ( 'START TRANSACTION' === $prepared ) {
				if ( $GLOBALS['lunara_core_health_state']['start_applies_but_false'] || $GLOBALS['lunara_core_health_state']['start_applies_but_throws'] ) {
					$GLOBALS['lunara_core_health_state']['transaction_backup'] = array(
						'rows'      => $GLOBALS['lunara_core_health_state']['rows'],
						'options'   => $GLOBALS['lunara_core_health_state']['options'],
						'autoloads' => $GLOBALS['lunara_core_health_state']['autoloads'],
					);
					if ( $GLOBALS['lunara_core_health_state']['start_applies_but_throws'] ) {
						throw new RuntimeException( 'simulated lost START acknowledgement' );
					}
					return false;
				}
				if ( $GLOBALS['lunara_core_health_state']['concurrent_on_start'] ) {
					lunara_core_health_set_physical_option( 'lunara_core_review_artwork_health_snapshot', array( 'schema' => 'concurrent-invalid-row' ), 'yes' );
				}
				if ( $GLOBALS['lunara_core_health_state']['throw_start'] ) {
					throw new RuntimeException( 'simulated START TRANSACTION exception' );
				}
				if ( $GLOBALS['lunara_core_health_state']['fail_start'] ) {
					return false;
				}
				$GLOBALS['lunara_core_health_state']['transaction_backup'] = array(
					'rows'      => $GLOBALS['lunara_core_health_state']['rows'],
					'options'   => $GLOBALS['lunara_core_health_state']['options'],
					'autoloads' => $GLOBALS['lunara_core_health_state']['autoloads'],
				);
				return 1;
			}
			if ( 'COMMIT' === $prepared ) {
				if ( $GLOBALS['lunara_core_health_state']['commit_applies_but_throws'] ) {
					$GLOBALS['lunara_core_health_state']['transaction_backup'] = null;
					throw new RuntimeException( 'simulated lost COMMIT acknowledgement' );
				}
				if ( $GLOBALS['lunara_core_health_state']['commit_applies_but_false'] ) {
					$GLOBALS['lunara_core_health_state']['transaction_backup'] = null;
					return false;
				}
				if ( $GLOBALS['lunara_core_health_state']['fail_commit'] ) {
					if ( $GLOBALS['lunara_core_health_state']['commit_failure_read_errors'] ) {
						$GLOBALS['lunara_core_health_state']['db_read_errors'] = 1;
					}
					return false;
				}
				$GLOBALS['lunara_core_health_state']['transaction_backup'] = null;
				return 1;
			}
			if ( 'ROLLBACK' === $prepared ) {
				if ( $GLOBALS['lunara_core_health_state']['fail_rollback'] ) {
					return false;
				}
				$backup = $GLOBALS['lunara_core_health_state']['transaction_backup'];
				if ( is_array( $backup ) ) {
					$GLOBALS['lunara_core_health_state']['rows']      = $backup['rows'];
					$GLOBALS['lunara_core_health_state']['options']   = $backup['options'];
					$GLOBALS['lunara_core_health_state']['autoloads'] = $backup['autoloads'];
				}
				$GLOBALS['lunara_core_health_state']['transaction_backup'] = null;
				return 1;
			}
			throw new RuntimeException( 'Unexpected transaction query.' );
		}

		$GLOBALS['lunara_core_health_state']['update_calls']++;
		$call = $GLOBALS['lunara_core_health_state']['update_calls'];
		if ( $GLOBALS['lunara_core_health_state']['db_update_throws'] ) {
			throw new RuntimeException( 'simulated physical write exception' );
		}
		if ( $GLOBALS['lunara_core_health_state']['db_update_fails'] || in_array( $call, $GLOBALS['lunara_core_health_state']['fail_update_calls'], true ) ) {
			$this->last_error = 'simulated physical write failure';
			return false;
		}

		$query  = $prepared['query'];
		$values = $prepared['values'];
		if ( 0 === strpos( $query, 'UPDATE ' ) ) {
			list( $value, $autoload, $option_id, $key, $prior_value, $prior_autoload ) = $values;
			if ( ! isset( $GLOBALS['lunara_core_health_state']['rows'][ $key ] ) ) {
				return 0;
			}
			$row = $GLOBALS['lunara_core_health_state']['rows'][ $key ];
			if ( (int) $option_id !== (int) $row['option_id'] || $prior_value !== $row['option_value'] || $prior_autoload !== $row['autoload'] ) {
				return 0;
			}
			$GLOBALS['lunara_core_health_state']['rows'][ $key ] = array( 'option_id' => $row['option_id'], 'option_value' => $value, 'autoload' => $autoload );
			$GLOBALS['lunara_core_health_state']['options'][ $key ] = maybe_unserialize( $value );
			$GLOBALS['lunara_core_health_state']['autoloads'][ $key ] = $autoload;
		} elseif ( 0 === strpos( $query, 'INSERT ' ) ) {
			list( $key, $value, $autoload ) = $values;
			if ( isset( $GLOBALS['lunara_core_health_state']['rows'][ $key ] ) ) {
				return false;
			}
			$this->insert_id = $GLOBALS['lunara_core_health_state']['next_option_id']++;
			$GLOBALS['lunara_core_health_state']['rows'][ $key ] = array( 'option_id' => $this->insert_id, 'option_value' => $value, 'autoload' => $autoload );
			$GLOBALS['lunara_core_health_state']['options'][ $key ] = maybe_unserialize( $value );
			$GLOBALS['lunara_core_health_state']['autoloads'][ $key ] = $autoload;
		} elseif ( 0 === strpos( $query, 'DELETE ' ) ) {
			list( $option_id, $key, $value, $autoload ) = $values;
			if ( ! isset( $GLOBALS['lunara_core_health_state']['rows'][ $key ] ) ) {
				return 0;
			}
			$row = $GLOBALS['lunara_core_health_state']['rows'][ $key ];
			if ( (int) $option_id !== (int) $row['option_id'] || $value !== $row['option_value'] || $autoload !== $row['autoload'] ) {
				return 0;
			}
			unset( $GLOBALS['lunara_core_health_state']['rows'][ $key ], $GLOBALS['lunara_core_health_state']['options'][ $key ], $GLOBALS['lunara_core_health_state']['autoloads'][ $key ] );
		} else {
			throw new RuntimeException( 'Unexpected physical option query.' );
		}

		if ( $call === $GLOBALS['lunara_core_health_state']['poison_on_update'] ) {
			$GLOBALS['lunara_core_health_state']['poison_row_reads'] = 1;
		}
		if ( $call === $GLOBALS['lunara_core_health_state']['concurrent_on_update'] && isset( $GLOBALS['lunara_core_health_state']['rows'][ $key ] ) ) {
			$row = $GLOBALS['lunara_core_health_state']['rows'][ $key ];
			$concurrent = array( 'option_id' => $row['option_id'], 'option_value' => serialize( array( 'schema' => 'concurrent-invalid-row' ) ), 'autoload' => 'yes' );
			$GLOBALS['lunara_core_health_state']['rows'][ $key ] = $concurrent;
			$GLOBALS['lunara_core_health_state']['options'][ $key ] = array( 'schema' => 'concurrent-invalid-row' );
			$GLOBALS['lunara_core_health_state']['autoloads'][ $key ] = 'yes';
			if ( is_array( $GLOBALS['lunara_core_health_state']['transaction_backup'] ) ) {
				$GLOBALS['lunara_core_health_state']['transaction_backup']['rows'][ $key ]      = $concurrent;
				$GLOBALS['lunara_core_health_state']['transaction_backup']['options'][ $key ]   = array( 'schema' => 'concurrent-invalid-row' );
				$GLOBALS['lunara_core_health_state']['transaction_backup']['autoloads'][ $key ] = 'yes';
			}
		}
		return 1;
	}

	public function disconnect() {
		$backup = $GLOBALS['lunara_core_health_state']['transaction_backup'];
		if ( is_array( $backup ) ) {
			$GLOBALS['lunara_core_health_state']['rows']      = $backup['rows'];
			$GLOBALS['lunara_core_health_state']['options']   = $backup['options'];
			$GLOBALS['lunara_core_health_state']['autoloads'] = $backup['autoloads'];
		}
		$GLOBALS['lunara_core_health_state']['transaction_backup'] = null;
	}
}

$GLOBALS['wpdb'] = new Lunara_Core_Health_WPDB();

function maybe_serialize( $value ) {
	return serialize( $value );
}

function maybe_unserialize( $value ) {
	return is_string( $value ) ? unserialize( $value ) : $value;
}

function lunara_core_health_set_physical_option( $key, $value, $autoload ) {
	$serialized = maybe_serialize( $value );
	$option_id = isset( $GLOBALS['lunara_core_health_state']['rows'][ $key ] )
		? $GLOBALS['lunara_core_health_state']['rows'][ $key ]['option_id']
		: $GLOBALS['lunara_core_health_state']['next_option_id']++;
	$GLOBALS['lunara_core_health_state']['rows'][ $key ]      = array( 'option_id' => $option_id, 'option_value' => $serialized, 'autoload' => $autoload );
	$GLOBALS['lunara_core_health_state']['options'][ $key ]   = $value;
	$GLOBALS['lunara_core_health_state']['autoloads'][ $key ] = $autoload;
}

function __( $text ) {
	return $text;
}

function admin_url( $path = '' ) {
	$GLOBALS['lunara_core_health_state']['events'][] = 'admin_url:' . $path;
	return 'https://example.test/wp-admin/' . ltrim( (string) $path, '/' );
}

function add_filter( $hook, $callback, $priority = 10, $accepted_args = 1 ) {
	$GLOBALS['lunara_core_health_state']['filters'][] = array( $hook, $callback, $priority, $accepted_args );
}

function current_user_can( $capability ) {
	$GLOBALS['lunara_core_health_state']['events'][] = 'capability:' . $capability;
	return ! empty( $GLOBALS['lunara_core_health_state']['capabilities'][ $capability ] );
}

function taxonomy_exists( $taxonomy ) {
	$GLOBALS['lunara_core_health_state']['events'][] = 'taxonomy:' . $taxonomy;
	return ! empty( $GLOBALS['lunara_core_health_state']['taxonomies'][ $taxonomy ] );
}

function get_option( $key, $default = false ) {
	$GLOBALS['lunara_core_health_state']['events'][]       = 'get_option:' . $key;
	$GLOBALS['lunara_core_health_state']['option_reads'][] = $key;
	if ( 'lunara_review_artwork_backfill_job' === $key ) {
		throw new RuntimeException( 'Ordinary status must never read the full artwork job.' );
	}
	$value = array_key_exists( $key, $GLOBALS['lunara_core_health_state']['options'] )
		? $GLOBALS['lunara_core_health_state']['options'][ $key ]
		: $default;
	if ( $GLOBALS['lunara_core_health_state']['poison_reads'] > 0 && 'lunara_core_review_artwork_health_snapshot' === $key && is_array( $value ) ) {
		$GLOBALS['lunara_core_health_state']['poison_reads']--;
		$value['schema_version'] = 999;
	}
	return $value;
}

function update_option( $key, $value, $autoload = null ) {
	$GLOBALS['lunara_core_health_state']['update_calls']++;
	$call = $GLOBALS['lunara_core_health_state']['update_calls'];
	$GLOBALS['lunara_core_health_state']['events'][]        = 'update_option:' . $key;
	$GLOBALS['lunara_core_health_state']['option_writes'][] = array( $key, $value, $autoload );
	if ( $GLOBALS['lunara_core_health_state']['reject_writes'] || in_array( $call, $GLOBALS['lunara_core_health_state']['fail_update_calls'], true ) ) {
		return false;
	}
	if ( array_key_exists( $key, $GLOBALS['lunara_core_health_state']['options'] ) && $GLOBALS['lunara_core_health_state']['options'][ $key ] === $value ) {
		return false;
	}
	$GLOBALS['lunara_core_health_state']['options'][ $key ] = $value;
	$GLOBALS['lunara_core_health_state']['autoloads'][ $key ] = false === $autoload ? 'no' : 'yes';
	if ( $call === $GLOBALS['lunara_core_health_state']['poison_on_update'] ) {
		$GLOBALS['lunara_core_health_state']['poison_reads'] = 1;
	}
	return true;
}

function delete_option( $key ) {
	if ( $GLOBALS['lunara_core_health_state']['delete_fails'] ) {
		return false;
	}
	unset( $GLOBALS['lunara_core_health_state']['options'][ $key ], $GLOBALS['lunara_core_health_state']['autoloads'][ $key ] );
	return true;
}

function wp_cache_delete( $key, $group ) {
	$GLOBALS['lunara_core_health_state']['cache_deletes'][] = array( $key, $group );
	return true;
}

function post_type_exists( $post_type ) {
	$GLOBALS['lunara_core_health_state']['events'][] = 'post_type:' . $post_type;
	return ! empty( $GLOBALS['lunara_core_health_state']['post_types'][ $post_type ] );
}

function get_posts() {
	throw new RuntimeException( 'Ordinary status must never query Reviews.' );
}

function get_post_meta() {
	throw new RuntimeException( 'Ordinary status must never read Review meta.' );
}

function get_terms() {
	throw new RuntimeException( 'Carousel status must never enumerate terms.' );
}

function get_attached_media() {
	throw new RuntimeException( 'Carousel status must never enumerate attachments.' );
}

function wp_remote_get() {
	throw new RuntimeException( 'Ordinary status must never touch the network.' );
}

function wp_schedule_single_event() {
	throw new RuntimeException( 'Ordinary status must never touch cron.' );
}

function set_transient() {
	throw new RuntimeException( 'Ordinary status must never mutate cache.' );
}

function lunara_core_health_assert( $condition, $message ) {
	if ( ! $condition ) {
		fwrite( STDERR, "Assertion failed: {$message}\n" );
		exit( 1 );
	}
}

function lunara_core_health_assert_same( $expected, $actual, $message ) {
	if ( $expected !== $actual ) {
		fwrite( STDERR, "Assertion failed: {$message}\nExpected: " . var_export( $expected, true ) . "\nActual: " . var_export( $actual, true ) . "\n" );
		exit( 1 );
	}
}

function lunara_core_health_reset_memo() {
	$property = new ReflectionProperty( 'Lunara_Core_Site_Studio_Bridge', 'artwork_status_memo' );
	$property->setAccessible( true );
	$property->setValue( null, null );
}

function lunara_core_health_reset_bridge_request() {
	lunara_core_health_reset_memo();
	$property = new ReflectionProperty( 'Lunara_Core_Site_Studio_Bridge', 'artwork_uncommitted_rows' );
	$property->setAccessible( true );
	$property->setValue( null, array() );
}

function lunara_core_health_reset_option_io() {
	$GLOBALS['lunara_core_health_state']['option_reads']      = array();
	$GLOBALS['lunara_core_health_state']['option_writes']     = array();
	$GLOBALS['lunara_core_health_state']['cache_deletes']     = array();
	$GLOBALS['lunara_core_health_state']['update_calls']      = 0;
	$GLOBALS['lunara_core_health_state']['fail_update_calls'] = array();
	$GLOBALS['lunara_core_health_state']['poison_on_update']  = 0;
	$GLOBALS['lunara_core_health_state']['poison_reads']      = 0;
	$GLOBALS['lunara_core_health_state']['poison_row_reads']  = 0;
	$GLOBALS['lunara_core_health_state']['concurrent_on_update'] = 0;
	$GLOBALS['lunara_core_health_state']['reject_writes']     = false;
	$GLOBALS['lunara_core_health_state']['db_update_fails']   = false;
	$GLOBALS['lunara_core_health_state']['db_update_throws']  = false;
	$GLOBALS['lunara_core_health_state']['db_read_errors']    = 0;
	$GLOBALS['lunara_core_health_state']['transaction_backup'] = null;
	$GLOBALS['lunara_core_health_state']['fail_commit']       = false;
	$GLOBALS['lunara_core_health_state']['commit_applies_but_false'] = false;
	$GLOBALS['lunara_core_health_state']['commit_applies_but_throws'] = false;
	$GLOBALS['lunara_core_health_state']['commit_failure_read_errors'] = false;
	$GLOBALS['lunara_core_health_state']['options_engine']            = 'InnoDB';
	$GLOBALS['lunara_core_health_state']['fail_rollback']     = false;
	$GLOBALS['lunara_core_health_state']['fail_start']        = false;
	$GLOBALS['lunara_core_health_state']['throw_start']       = false;
	$GLOBALS['lunara_core_health_state']['start_applies_but_false'] = false;
	$GLOBALS['lunara_core_health_state']['start_applies_but_throws'] = false;
	$GLOBALS['lunara_core_health_state']['concurrent_on_start'] = false;
}

function lunara_core_health_poison_surface() {
	$GLOBALS['lunara_core_health_state']['malicious_callback'] = true;
	throw new RuntimeException( 'Registry metadata must never be invoked by fixed callbacks.' );
}

final class Lunara_Core {
	public function register_carousel_manager_page() {}
}

lunara_core_health_assert( file_exists( $bridge ), 'Core must ship its always-loaded Site Studio bridge.' );
require $bridge;

lunara_core_health_assert( function_exists( 'lunara_core_carousel_manager_admin_url' ), 'Core must expose the exact Carousel manager URL wrapper.' );
lunara_core_health_assert( function_exists( 'lunara_core_carousel_manager_status' ), 'Core must expose the exact Carousel manager status wrapper.' );
lunara_core_health_assert( function_exists( 'lunara_core_review_identity_artwork_admin_url' ), 'Core must expose the exact Review Identity & Artwork URL wrapper.' );
lunara_core_health_assert( function_exists( 'lunara_core_review_identity_artwork_status' ), 'Core must expose the exact Review Identity & Artwork status wrapper.' );

$generic_carousel = array(
	'schema'         => 'lunara-core-carousel-manager-health/v1',
	'schema_version' => 1,
	'plugin_version' => '0.8.10',
	'available'      => false,
	'known'          => false,
	'state'          => 'unavailable',
	'label'          => 'Destination unavailable',
	'message'        => 'This Lunara Core destination is unavailable.',
	'admin_url'      => '',
	'capability'     => 'manage_options',
	'owner'          => array( 'available' => false ),
	'taxonomy'       => array( 'available' => false ),
);
$generic_artwork = array(
	'schema'         => 'lunara-core-review-identity-artwork-health/v1',
	'schema_version' => 1,
	'plugin_version' => '0.8.10',
	'available'      => false,
	'known'          => false,
	'state'          => 'unavailable',
	'label'          => 'Destination unavailable',
	'message'        => 'This Lunara Core destination is unavailable.',
	'updated_at'     => null,
	'admin_url'      => '',
	'capability'     => 'edit_others_posts',
	'coverage'       => array(
		'known'            => false,
		'total'            => null,
		'identity_ready'   => null,
		'missing_identity' => null,
		'missing_poster'   => null,
		'missing_backdrop' => null,
		'custom_protected' => null,
	),
	'job'            => array(
		'known'        => false,
		'status'       => null,
		'processed'    => null,
		'total'        => null,
		'ready'        => null,
		'partial'      => null,
		'conflicts'    => null,
		'errors'       => null,
		'started_at'   => null,
		'completed_at' => null,
	),
	'credentials'    => array( 'known' => false, 'omdb' => false, 'tmdb' => false, 'ready' => false ),
	'snapshot'       => array( 'state' => 'missing', 'generated_at' => null, 'expires_at' => null ),
);

$GLOBALS['lunara_core_health_state']['events'] = array();
lunara_core_health_assert_same( '', lunara_core_carousel_manager_admin_url(), 'Denied Carousel callers must receive no guessed fallback URL.' );
lunara_core_health_assert_same( array( 'capability:manage_options' ), $GLOBALS['lunara_core_health_state']['events'], 'Carousel URL must enforce capability before every URL or owner read.' );
$GLOBALS['lunara_core_health_state']['events'] = array();
lunara_core_health_assert_same( $generic_carousel, lunara_core_carousel_manager_status(), 'Denied Carousel status must be one fixed generic unavailable DTO.' );
lunara_core_health_assert_same( array( 'capability:manage_options' ), $GLOBALS['lunara_core_health_state']['events'], 'Denied Carousel status must not inspect taxonomy, URL, terms, or attachments.' );

$GLOBALS['lunara_core_health_state']['events'] = array();
lunara_core_health_assert_same( '', lunara_core_review_identity_artwork_admin_url(), 'Denied artwork callers must receive no guessed fallback URL.' );
lunara_core_health_assert_same( array( 'capability:edit_others_posts' ), $GLOBALS['lunara_core_health_state']['events'], 'Artwork URL must enforce capability before every URL or owner read.' );
$GLOBALS['lunara_core_health_state']['events'] = array();
lunara_core_health_assert_same( $generic_artwork, lunara_core_review_identity_artwork_status(), 'Denied artwork status must be one fixed generic unavailable DTO.' );
lunara_core_health_assert_same( array( 'capability:edit_others_posts' ), $GLOBALS['lunara_core_health_state']['events'], 'Denied artwork status must not inspect classes, snapshots, URLs, or memoized state.' );

$GLOBALS['lunara_core_health_state']['capabilities']['manage_options'] = true;
$GLOBALS['lunara_core_health_state']['taxonomies']['lunara_slide_set']  = true;
$GLOBALS['lunara_core_health_state']['events'] = array();
lunara_core_health_assert_same(
	'https://example.test/wp-admin/themes.php?page=lunara-carousel-manager',
	lunara_core_carousel_manager_admin_url(),
	'Authorized Carousel callers must receive the exact canonical owner URL.'
);
lunara_core_health_assert_same(
	array( 'capability:manage_options', 'taxonomy:lunara_slide_set', 'admin_url:themes.php?page=lunara-carousel-manager' ),
	$GLOBALS['lunara_core_health_state']['events'],
	'Carousel URL must remain capability-first.'
);
$GLOBALS['lunara_core_health_state']['events'] = array();
$carousel_status = lunara_core_carousel_manager_status();
lunara_core_health_assert_same( true, $carousel_status['available'], 'Registered Carousel structure must report available.' );
lunara_core_health_assert_same( true, $carousel_status['known'], 'Carousel structural health is always known to authorized callers.' );
lunara_core_health_assert_same( 'ready', $carousel_status['state'], 'Registered Carousel structure must report ready.' );
lunara_core_health_assert_same( array( 'available' => true ), $carousel_status['owner'], 'Carousel status must expose only fixed owner availability.' );
lunara_core_health_assert_same( array( 'available' => true ), $carousel_status['taxonomy'], 'Carousel status must expose only taxonomy availability, not terms.' );
lunara_core_health_assert_same(
	array( 'capability:manage_options', 'taxonomy:lunara_slide_set', 'admin_url:themes.php?page=lunara-carousel-manager' ),
	$GLOBALS['lunara_core_health_state']['events'],
	'Carousel status must check capability before its one structural taxonomy and URL read.'
);

$GLOBALS['lunara_core_health_state']['taxonomies']['lunara_slide_set'] = false;
$GLOBALS['lunara_core_health_state']['events'] = array();
lunara_core_health_assert_same( '', lunara_core_carousel_manager_admin_url(), 'Missing Carousel taxonomy must suppress the action URL.' );
lunara_core_health_assert_same( array( 'capability:manage_options', 'taxonomy:lunara_slide_set' ), $GLOBALS['lunara_core_health_state']['events'], 'Carousel URL must stop before URL resolution when structure is missing.' );
$carousel_missing = lunara_core_carousel_manager_status();
lunara_core_health_assert_same( false, $carousel_missing['available'], 'Missing Carousel taxonomy must fail closed.' );
lunara_core_health_assert_same( 'unavailable', $carousel_missing['state'], 'Missing Carousel taxonomy must degrade without enumerating terms.' );
$GLOBALS['lunara_core_health_state']['taxonomies']['lunara_slide_set'] = true;

$GLOBALS['lunara_core_health_state']['capabilities']['edit_others_posts'] = true;
$GLOBALS['lunara_core_health_state']['events'] = array();
lunara_core_health_reset_memo();
$missing_owner_url = lunara_core_review_identity_artwork_admin_url();
lunara_core_health_assert_same( '', $missing_owner_url, 'An authorized caller must receive no Artwork Audit URL when the fixed owner is not loaded.' );
lunara_core_health_assert_same( array( 'capability:edit_others_posts' ), $GLOBALS['lunara_core_health_state']['events'], 'Missing-owner artwork URL must stop after capability and the non-autoloading class check.' );
$GLOBALS['lunara_core_health_state']['events'] = array();
$missing_owner = lunara_core_review_identity_artwork_status();
lunara_core_health_assert_same( $generic_artwork, $missing_owner, 'An unloaded fixed artwork owner must return the generic unavailable DTO without autoloading it.' );
lunara_core_health_assert_same(
	array( 'capability:edit_others_posts' ),
	$GLOBALS['lunara_core_health_state']['events'],
	'Missing-owner artwork status must stop before every snapshot, URL, or memoized-status read.'
);

eval( 'final class Lunara_Review_Artwork_Backfill { public static function render_page() {} }' );
lunara_core_health_reset_memo();
$GLOBALS['lunara_core_health_state']['post_types']['review'] = false;
$GLOBALS['lunara_core_health_state']['events'] = array();
lunara_core_health_assert_same( '', lunara_core_review_identity_artwork_admin_url(), 'Missing Review CPT must suppress the Artwork Audit action URL.' );
lunara_core_health_assert_same( array( 'capability:edit_others_posts', 'post_type:review' ), $GLOBALS['lunara_core_health_state']['events'], 'Artwork URL must stop before URL resolution when Review structure is missing.' );
$GLOBALS['lunara_core_health_state']['events'] = array();
lunara_core_health_assert_same( $generic_artwork, lunara_core_review_identity_artwork_status(), 'Missing Review CPT must return the generic unavailable DTO.' );
lunara_core_health_assert_same( array( 'capability:edit_others_posts', 'post_type:review' ), $GLOBALS['lunara_core_health_state']['events'], 'Missing Review structure must stop before snapshot, URL, or memo reads.' );
$GLOBALS['lunara_core_health_state']['post_types']['review'] = true;
lunara_core_health_reset_memo();
$GLOBALS['lunara_core_health_state']['events']       = array();
$GLOBALS['lunara_core_health_state']['option_reads'] = array();
$missing = lunara_core_review_identity_artwork_status();
lunara_core_health_assert_same( true, $missing['available'], 'A loaded fixed owner must be available.' );
lunara_core_health_assert_same( 'needs_attention', $missing['state'], 'Missing health data must use the bounded needs-attention state.' );
lunara_core_health_assert_same( $generic_artwork['coverage'], $missing['coverage'], 'Unknown coverage counts must be null, never zero.' );
lunara_core_health_assert_same( array( 'lunara_core_review_artwork_health_snapshot' ), $GLOBALS['lunara_core_health_state']['option_reads'], 'Artwork status must read only the compact owner snapshot.' );
$again = lunara_core_review_identity_artwork_status();
lunara_core_health_assert_same( $missing, $again, 'Authorized artwork status must memoize its normalized projection.' );
lunara_core_health_assert_same( 1, count( $GLOBALS['lunara_core_health_state']['option_reads'] ), 'Memoized artwork status must not reread the compact snapshot.' );

unset( $GLOBALS['lunara_core_health_state']['capabilities']['edit_others_posts'] );
$reads_before = count( $GLOBALS['lunara_core_health_state']['option_reads'] );
lunara_core_health_assert_same( $generic_artwork, lunara_core_review_identity_artwork_status(), 'An authorized-then-denied call must not reuse authorized memoized health.' );
lunara_core_health_assert_same( $reads_before, count( $GLOBALS['lunara_core_health_state']['option_reads'] ), 'Denied calls must not read the snapshot after an authorized call.' );
$GLOBALS['lunara_core_health_state']['capabilities']['edit_others_posts'] = true;

$now = time();
$valid_snapshot = array(
	'schema'         => 'lunara-core-review-identity-artwork-health/v1',
	'schema_version' => 1,
	'generated_at'   => $now - 100,
	'expires_at'     => $now + 1000,
	'coverage'       => array(
		'known'            => true,
		'total'            => 40,
		'identity_ready'   => 35,
		'missing_identity' => 5,
		'missing_poster'   => 3,
		'missing_backdrop' => 4,
		'custom_protected' => 7,
		'review_ids'       => array( 11, 12 ),
	),
	'job'            => array(
		'known'        => true,
		'status'       => 'running',
		'processed'    => 10,
		'total'        => 35,
		'ready'        => 8,
		'partial'      => 1,
		'conflicts'    => 2,
		'errors'       => 1,
		'started_at'   => $now - 500,
		'completed_at' => 0,
		'ids'          => range( 1, 100 ),
		'recent'       => array( array( 'review_id' => 11, 'imdb_id' => 'tt1234567' ) ),
		'last_error'   => 'SECRET provider payload',
	),
	'credentials'    => array(
		'known'  => true,
		'omdb'   => true,
		'tmdb'   => true,
		'ready'  => true,
		'source' => 'SECRET_ENVIRONMENT_SOURCE',
		'value'  => 'SECRET_KEY_VALUE',
		'length' => 99,
	),
	'arbitrary'      => array( 'title' => 'SECRET REVIEW TITLE', 'image_url' => 'https://secret.test/poster.jpg' ),
);
$GLOBALS['lunara_core_health_state']['options']['lunara_core_review_artwork_health_snapshot'] = $valid_snapshot;
lunara_core_health_reset_memo();
$status = lunara_core_review_identity_artwork_status();
lunara_core_health_assert_same( true, $status['known'], 'A compatible fresh snapshot must be known.' );
lunara_core_health_assert_same( 'needs_attention', $status['state'], 'A running job must remain under needs-attention while job.status carries the active substate.' );
lunara_core_health_assert_same( 'running', $status['job']['status'], 'The bounded job projection must carry the active job substate.' );
lunara_core_health_assert_same( 'fresh', $status['snapshot']['state'], 'A compatible unexpired snapshot must be fresh.' );
lunara_core_health_assert_same( array( 'known' => true, 'omdb' => true, 'tmdb' => true, 'ready' => true ), $status['credentials'], 'Credentials must be exactly four booleans.' );
lunara_core_health_assert_same( 10, $status['job']['processed'], 'The bounded job projection must retain progress.' );
$encoded = json_encode( $status );
foreach ( array( 'review_id', 'imdb_id', 'ids', 'recent', 'last_error', 'SECRET', 'title', 'image_url', 'source', 'value', 'length', 'tt1234567', 'poster.jpg' ) as $forbidden ) {
	lunara_core_health_assert( false === strpos( $encoded, $forbidden ), 'Artwork status leaked forbidden data: ' . $forbidden );
}

$ready_snapshot = $valid_snapshot;
$ready_snapshot['coverage'] = array_merge(
	$ready_snapshot['coverage'],
	array( 'identity_ready' => 40, 'missing_identity' => 0, 'missing_poster' => 0, 'missing_backdrop' => 0 )
);
$ready_snapshot['job'] = array_merge(
	$ready_snapshot['job'],
	array( 'status' => 'complete', 'processed' => 35, 'total' => 35, 'ready' => 35, 'partial' => 0, 'conflicts' => 0, 'errors' => 0, 'completed_at' => $now - 1 )
);
$GLOBALS['lunara_core_health_state']['options']['lunara_core_review_artwork_health_snapshot'] = $ready_snapshot;
lunara_core_health_reset_memo();
lunara_core_health_assert_same( 'ready', lunara_core_review_identity_artwork_status()['state'], 'Complete zero-issue known health must report ready.' );

$incomplete_complete = $ready_snapshot;
$incomplete_complete['job']['processed'] = 0;
$incomplete_complete['job']['ready']     = 0;
$GLOBALS['lunara_core_health_state']['options']['lunara_core_review_artwork_health_snapshot'] = $incomplete_complete;
lunara_core_health_reset_memo();
$invalid_complete = lunara_core_review_identity_artwork_status();
lunara_core_health_assert_same( false, $invalid_complete['known'], 'A complete job with unprocessed work must be rejected as unknown.' );
lunara_core_health_assert_same( 'failed', $invalid_complete['snapshot']['state'], 'A complete job with processed less than total must fail strict snapshot normalization.' );

$completed_running = $valid_snapshot;
$completed_running['job']['completed_at'] = $now - 1;
$GLOBALS['lunara_core_health_state']['options']['lunara_core_review_artwork_health_snapshot'] = $completed_running;
lunara_core_health_reset_memo();
$invalid_running = lunara_core_review_identity_artwork_status();
lunara_core_health_assert_same( false, $invalid_running['known'], 'A running job with completion time must be rejected as unknown.' );
lunara_core_health_assert_same( 'failed', $invalid_running['snapshot']['state'], 'Running jobs cannot carry completed_at.' );

$reversed_complete = $ready_snapshot;
$reversed_complete['job']['completed_at'] = $reversed_complete['job']['started_at'] - 1;
$GLOBALS['lunara_core_health_state']['options']['lunara_core_review_artwork_health_snapshot'] = $reversed_complete;
lunara_core_health_reset_memo();
$invalid_timestamp_order = lunara_core_review_identity_artwork_status();
lunara_core_health_assert_same( false, $invalid_timestamp_order['known'], 'A complete job whose completion precedes its start must be rejected as unknown.' );
lunara_core_health_assert_same( 'failed', $invalid_timestamp_order['snapshot']['state'], 'Reversed complete timestamps must fail strict snapshot normalization.' );

$startless_running = $valid_snapshot;
$startless_running['job']['processed']  = 0;
$startless_running['job']['ready']      = 0;
$startless_running['job']['started_at'] = 0;
$GLOBALS['lunara_core_health_state']['options']['lunara_core_review_artwork_health_snapshot'] = $startless_running;
lunara_core_health_reset_memo();
$invalid_running_start = lunara_core_review_identity_artwork_status();
lunara_core_health_assert_same( false, $invalid_running_start['known'], 'Running work without a nonzero start time must be rejected.' );
lunara_core_health_assert_same( 'failed', $invalid_running_start['snapshot']['state'], 'Startless active progress must fail strict normalization.' );

$excess_running_errors = $valid_snapshot;
$excess_running_errors['job']['processed'] = 0;
$excess_running_errors['job']['ready']     = 0;
$excess_running_errors['job']['errors']    = 999;
$GLOBALS['lunara_core_health_state']['options']['lunara_core_review_artwork_health_snapshot'] = $excess_running_errors;
lunara_core_health_reset_memo();
$invalid_running_errors = lunara_core_review_identity_artwork_status();
lunara_core_health_assert_same( false, $invalid_running_errors['known'], 'Running errors beyond processed work must be rejected independently of timestamp validity.' );
lunara_core_health_assert_same( 'failed', $invalid_running_errors['snapshot']['state'], 'Impossible active error counts must fail strict normalization.' );

$undercounted_complete = $ready_snapshot;
$undercounted_complete['job']['ready'] = 0;
$GLOBALS['lunara_core_health_state']['options']['lunara_core_review_artwork_health_snapshot'] = $undercounted_complete;
lunara_core_health_reset_memo();
$invalid_undercount = lunara_core_review_identity_artwork_status();
lunara_core_health_assert_same( false, $invalid_undercount['known'], 'Processed work with missing result buckets must be rejected as unknown.' );
lunara_core_health_assert_same( 'failed', $invalid_undercount['snapshot']['state'], 'Undercounted processed results must fail strict snapshot normalization.' );
lunara_core_health_assert_same( 'needs_attention', $invalid_undercount['state'], 'Undercounted results must never manufacture top-level ready health.' );

$impossible_conflicts = $valid_snapshot;
$impossible_conflicts['job']['processed'] = 1;
$impossible_conflicts['job']['ready']     = 1;
$impossible_conflicts['job']['conflicts'] = 3;
$GLOBALS['lunara_core_health_state']['options']['lunara_core_review_artwork_health_snapshot'] = $impossible_conflicts;
lunara_core_health_reset_memo();
lunara_core_health_assert_same( 'failed', lunara_core_review_identity_artwork_status()['snapshot']['state'], 'Conflict counts beyond two artwork slots per processed Review must be rejected.' );

$impossible_idle = $valid_snapshot;
$impossible_idle['job'] = array( 'known' => true, 'status' => 'idle', 'processed' => 0, 'total' => 1, 'ready' => 0, 'partial' => 0, 'conflicts' => 0, 'errors' => 0, 'started_at' => 0, 'completed_at' => 0 );
$GLOBALS['lunara_core_health_state']['options']['lunara_core_review_artwork_health_snapshot'] = $impossible_idle;
lunara_core_health_reset_memo();
lunara_core_health_assert_same( 'failed', lunara_core_review_identity_artwork_status()['snapshot']['state'], 'Idle health must remain an exact coherent zero state.' );

$paused_snapshot = $valid_snapshot;
$paused_snapshot['job']['status'] = 'paused';
$GLOBALS['lunara_core_health_state']['options']['lunara_core_review_artwork_health_snapshot'] = $paused_snapshot;
lunara_core_health_reset_memo();
$valid_paused = lunara_core_review_identity_artwork_status();
lunara_core_health_assert_same( true, $valid_paused['job']['known'], 'Coherent paused progress must remain a known job projection.' );
lunara_core_health_assert_same( 'needs_attention', $valid_paused['state'], 'Paused jobs must remain under needs-attention.' );

$terminal_paused = $paused_snapshot;
$terminal_paused['job']['processed'] = $terminal_paused['job']['total'];
$terminal_paused['job']['ready']     = $terminal_paused['job']['total'];
$GLOBALS['lunara_core_health_state']['options']['lunara_core_review_artwork_health_snapshot'] = $terminal_paused;
lunara_core_health_reset_memo();
lunara_core_health_assert_same( 'failed', lunara_core_review_identity_artwork_status()['snapshot']['state'], 'A paused processed=total projection is structurally invalid and must never be emitted by the owner.' );

$startless_paused = $paused_snapshot;
$startless_paused['job']['started_at'] = 0;
$GLOBALS['lunara_core_health_state']['options']['lunara_core_review_artwork_health_snapshot'] = $startless_paused;
lunara_core_health_reset_memo();
lunara_core_health_assert_same( 'failed', lunara_core_review_identity_artwork_status()['snapshot']['state'], 'Paused progress requires a valid nonzero start time.' );

$partial_snapshot = $ready_snapshot;
$partial_snapshot['job']['ready']   = 34;
$partial_snapshot['job']['partial'] = 1;
$GLOBALS['lunara_core_health_state']['options']['lunara_core_review_artwork_health_snapshot'] = $partial_snapshot;
lunara_core_health_reset_memo();
lunara_core_health_assert_same( 'needs_attention', lunara_core_review_identity_artwork_status()['state'], 'Nonzero partial job results must remain under needs-attention.' );

$poison_cases = array(
	'corrupt'      => array( 'raw' => 'not-an-array', 'state' => 'failed' ),
	'incompatible' => array( 'raw' => array( 'schema' => 'evil', 'schema_version' => 999 ), 'state' => 'incompatible' ),
	'failed'       => array( 'raw' => array_merge( $valid_snapshot, array( 'snapshot_state' => 'failed' ) ), 'state' => 'failed' ),
	'stale'        => array( 'raw' => array_merge( $valid_snapshot, array( 'expires_at' => $now - 1 ) ), 'state' => 'stale' ),
	'expired'      => array( 'raw' => array_merge( $valid_snapshot, array( 'expires_at' => time() ) ), 'state' => 'stale' ),
	'negative'     => array( 'raw' => array_merge( $valid_snapshot, array( 'coverage' => array_merge( $valid_snapshot['coverage'], array( 'missing_poster' => -1 ) ) ) ), 'state' => 'failed' ),
);
foreach ( $poison_cases as $name => $case ) {
	$GLOBALS['lunara_core_health_state']['options']['lunara_core_review_artwork_health_snapshot'] = $case['raw'];
	lunara_core_health_reset_memo();
	$poisoned = lunara_core_review_identity_artwork_status();
	lunara_core_health_assert_same( $case['state'], $poisoned['snapshot']['state'], ucfirst( $name ) . ' snapshots must remain explicit.' );
	lunara_core_health_assert_same( false, $poisoned['known'], ucfirst( $name ) . ' snapshots must not report known health.' );
	lunara_core_health_assert_same( 'needs_attention', $poisoned['state'], ucfirst( $name ) . ' snapshots must use the bounded needs-attention state.' );
	lunara_core_health_assert_same( $generic_artwork['coverage'], $poisoned['coverage'], ucfirst( $name ) . ' snapshots must use null unknown counts.' );
}

$GLOBALS['lunara_core_health_state']['options']['lunara_core_review_artwork_health_snapshot'] = $valid_snapshot;
lunara_core_health_reset_memo();
$malicious_surface = array(
	'admin_url'           => 'https://evil.test/steal',
	'dependency_callback' => 'lunara_core_health_poison_surface',
	'status_callback'     => 'lunara_core_health_poison_surface',
);
$registry_status = Lunara_Core_Site_Studio_Bridge::review_identity_artwork_registry_status( $malicious_surface );
lunara_core_health_assert_same( false, $GLOBALS['lunara_core_health_state']['malicious_callback'], 'Fixed status callbacks must ignore supplied callbacks and URLs.' );
lunara_core_health_assert_same( 'https://example.test/wp-admin/edit.php?post_type=review&page=lunara-review-artwork-audit', $registry_status['url'], 'Registry status must use only the fixed owner URL.' );

$write_coverage = array( 'total' => 12, 'identity_ready' => 10, 'missing_identity' => 2, 'missing_poster' => 0, 'missing_backdrop' => 1, 'custom_protected' => 3, 'ids' => range( 1, 50 ) );
$write_job = array( 'status' => 'complete', 'processed' => 10, 'total' => 10, 'counts' => array( 'ready' => 9, 'partial' => 1, 'conflicts' => 1, 'errors' => 0 ), 'started_at' => $now - 20, 'completed_at' => $now - 1, 'ids' => range( 1, 100 ), 'recent' => array( 'SECRET' ), 'last_error' => 'SECRET' );
$write_credentials = array( 'omdb' => true, 'tmdb' => false, 'ready' => true, 'source' => 'SECRET', 'value' => 'SECRET' );
lunara_core_health_set_physical_option( 'lunara_core_review_artwork_health_snapshot', $valid_snapshot, 'no' );
lunara_core_health_reset_option_io();
$GLOBALS['lunara_core_health_state']['options_engine'] = 'MyISAM';
$nontransactional_write = Lunara_Core_Site_Studio_Bridge::write_artwork_health_snapshot( $write_coverage, $write_job, $write_credentials );
lunara_core_health_assert_same( false, $nontransactional_write, 'A changed snapshot must not enter publication when the physical options table cannot provide transactional promotion.' );
lunara_core_health_assert_same( $valid_snapshot, $GLOBALS['lunara_core_health_state']['options']['lunara_core_review_artwork_health_snapshot'], 'Nontransactional publication refusal must preserve the exact prior snapshot.' );
lunara_core_health_assert_same( 0, $GLOBALS['lunara_core_health_state']['update_calls'], 'Nontransactional publication refusal must occur before staging any row.' );

lunara_core_health_set_physical_option( 'lunara_core_review_artwork_health_snapshot', $valid_snapshot, 'yes' );
lunara_core_health_reset_option_io();
$GLOBALS['lunara_core_health_state']['start_applies_but_false'] = true;
$GLOBALS['lunara_core_health_state']['fail_rollback']           = true;
$applied_false_start = Lunara_Core_Site_Studio_Bridge::write_artwork_health_snapshot( $write_coverage, $write_job, $write_credentials );
lunara_core_health_assert_same( false, $applied_false_start, 'Applied-but-unacknowledged START false must remain a failed publication.' );
$GLOBALS['wpdb']->disconnect();
lunara_core_health_reset_bridge_request();
lunara_core_health_assert_same( $valid_snapshot, $GLOBALS['lunara_core_health_state']['options']['lunara_core_review_artwork_health_snapshot'], 'Applied START false plus lost rollback acknowledgement must preserve exact prior bytes after disconnect.' );
lunara_core_health_assert_same( 'yes', $GLOBALS['lunara_core_health_state']['autoloads']['lunara_core_review_artwork_health_snapshot'], 'Applied START false must preserve legacy autoload after a fresh disconnect.' );
lunara_core_health_assert_same( 0, $GLOBALS['lunara_core_health_state']['update_calls'], 'Ambiguous START must be resolved before any option-row mutation.' );

unset( $GLOBALS['lunara_core_health_state']['options']['lunara_core_review_artwork_health_snapshot'] );
unset( $GLOBALS['lunara_core_health_state']['rows']['lunara_core_review_artwork_health_snapshot'] );
unset( $GLOBALS['lunara_core_health_state']['autoloads']['lunara_core_review_artwork_health_snapshot'] );
lunara_core_health_reset_option_io();
$GLOBALS['lunara_core_health_state']['start_applies_but_throws'] = true;
$GLOBALS['lunara_core_health_state']['fail_rollback']            = true;
$applied_throw_start = Lunara_Core_Site_Studio_Bridge::write_artwork_health_snapshot( $write_coverage, $write_job, $write_credentials );
lunara_core_health_assert_same( false, $applied_throw_start, 'Applied-but-unacknowledged throwing START must remain a failed publication.' );
$GLOBALS['wpdb']->disconnect();
lunara_core_health_reset_bridge_request();
lunara_core_health_assert_same( false, isset( $GLOBALS['lunara_core_health_state']['rows']['lunara_core_review_artwork_health_snapshot'] ), 'Applied throwing START plus lost rollback acknowledgement must preserve exact prior absence after disconnect.' );
lunara_core_health_assert_same( 0, $GLOBALS['lunara_core_health_state']['update_calls'], 'Throwing ambiguous START must occur before any staged insert.' );

lunara_core_health_set_physical_option( 'lunara_core_review_artwork_health_snapshot', $valid_snapshot, 'yes' );
lunara_core_health_reset_option_io();
$GLOBALS['lunara_core_health_state']['fail_start'] = true;
$failed_start = Lunara_Core_Site_Studio_Bridge::write_artwork_health_snapshot( $write_coverage, $write_job, $write_credentials );
lunara_core_health_assert_same( false, $failed_start, 'A false START TRANSACTION must keep the publication failed.' );
lunara_core_health_assert_same( $valid_snapshot, $GLOBALS['lunara_core_health_state']['options']['lunara_core_review_artwork_health_snapshot'], 'False START must restore the exact prior compact value.' );
lunara_core_health_assert_same( 'yes', $GLOBALS['lunara_core_health_state']['autoloads']['lunara_core_review_artwork_health_snapshot'], 'False START must restore the exact legacy autoload state.' );
lunara_core_health_assert_same( 0, $GLOBALS['lunara_core_health_state']['update_calls'], 'False START must occur before any staged option-row mutation.' );

lunara_core_health_set_physical_option( 'lunara_core_review_artwork_health_snapshot', $valid_snapshot, 'yes' );
lunara_core_health_reset_option_io();
$GLOBALS['lunara_core_health_state']['throw_start'] = true;
$throwing_start = Lunara_Core_Site_Studio_Bridge::write_artwork_health_snapshot( $write_coverage, $write_job, $write_credentials );
lunara_core_health_assert_same( false, $throwing_start, 'A throwing START TRANSACTION must keep the publication failed.' );
lunara_core_health_assert_same( $valid_snapshot, $GLOBALS['lunara_core_health_state']['options']['lunara_core_review_artwork_health_snapshot'], 'Throwing START must restore the exact prior compact value.' );
lunara_core_health_assert_same( 'yes', $GLOBALS['lunara_core_health_state']['autoloads']['lunara_core_review_artwork_health_snapshot'], 'Throwing START must restore the exact prior autoload state.' );

unset( $GLOBALS['lunara_core_health_state']['options']['lunara_core_review_artwork_health_snapshot'] );
unset( $GLOBALS['lunara_core_health_state']['rows']['lunara_core_review_artwork_health_snapshot'] );
unset( $GLOBALS['lunara_core_health_state']['autoloads']['lunara_core_review_artwork_health_snapshot'] );
lunara_core_health_reset_option_io();
$GLOBALS['lunara_core_health_state']['fail_start'] = true;
$absent_failed_start = Lunara_Core_Site_Studio_Bridge::write_artwork_health_snapshot( $write_coverage, $write_job, $write_credentials );
lunara_core_health_assert_same( false, $absent_failed_start, 'False START from an absent prestate must remain failed.' );
lunara_core_health_assert_same( false, isset( $GLOBALS['lunara_core_health_state']['rows']['lunara_core_review_artwork_health_snapshot'] ), 'False START must restore exact prior absence.' );
lunara_core_health_assert_same( 0, $GLOBALS['lunara_core_health_state']['update_calls'], 'Absent START failure must occur before any staged insert.' );

lunara_core_health_set_physical_option( 'lunara_core_review_artwork_health_snapshot', $valid_snapshot, 'yes' );
lunara_core_health_reset_option_io();
$GLOBALS['lunara_core_health_state']['fail_start']           = true;
$GLOBALS['lunara_core_health_state']['concurrent_on_start']  = true;
$concurrent_failed_start = Lunara_Core_Site_Studio_Bridge::write_artwork_health_snapshot( $write_coverage, $write_job, $write_credentials );
lunara_core_health_assert_same( false, $concurrent_failed_start, 'A failed START with a concurrent row must remain failed.' );
lunara_core_health_assert_same( array( 'schema' => 'concurrent-invalid-row' ), $GLOBALS['lunara_core_health_state']['options']['lunara_core_review_artwork_health_snapshot'], 'Failed START restoration must not overwrite a concurrent nonmatching row.' );

unset( $GLOBALS['lunara_core_health_state']['options']['lunara_core_review_artwork_health_snapshot'] );
unset( $GLOBALS['lunara_core_health_state']['rows']['lunara_core_review_artwork_health_snapshot'] );
unset( $GLOBALS['lunara_core_health_state']['autoloads']['lunara_core_review_artwork_health_snapshot'] );
lunara_core_health_reset_option_io();
$written = Lunara_Core_Site_Studio_Bridge::write_artwork_health_snapshot(
	$write_coverage,
	$write_job,
	$write_credentials
);
lunara_core_health_assert_same( true, $written, 'A normalized compact snapshot with exact readback must report a successful write.' );
lunara_core_health_assert_same( 2, $GLOBALS['lunara_core_health_state']['update_calls'], 'An absent snapshot refresh must stage one intrinsically unverified row and promote it once after exact readback.' );
lunara_core_health_assert_same( array(), $GLOBALS['lunara_core_health_state']['option_writes'], 'Snapshot persistence must bypass filtered update_option writes.' );
$write = $GLOBALS['lunara_core_health_state']['options']['lunara_core_review_artwork_health_snapshot'];
lunara_core_health_assert_same( 'no', $GLOBALS['lunara_core_health_state']['rows']['lunara_core_review_artwork_health_snapshot']['autoload'], 'Snapshot writes must explicitly disable autoload in the physical row.' );
lunara_core_health_assert_same( 'verified', $write['persistence_state'], 'Every newly written snapshot must carry an explicit durable verified marker.' );
lunara_core_health_assert( in_array( array( 'notoptions', 'options' ), $GLOBALS['lunara_core_health_state']['cache_deletes'], true ), 'Absent physical inserts must invalidate WordPress negative-option cache state.' );
$snapshot_cache_deletes = array_values( array_filter( $GLOBALS['lunara_core_health_state']['cache_deletes'], function ( $entry ) { return array( 'lunara_core_review_artwork_health_snapshot', 'options' ) === $entry; } ) );
lunara_core_health_assert_same( 3, count( $snapshot_cache_deletes ), 'Successful staged publication must invalidate the individual option cache again after COMMIT.' );
lunara_core_health_assert_same( 0, $write['coverage']['missing_poster'], 'Snapshot counts must preserve valid bounded nonnegative integers.' );
lunara_core_health_assert_same( false, $write['credentials']['ready'], 'Credential readiness must be recomputed from the two provider booleans.' );
foreach ( array( 'ids', 'recent', 'last_error', 'SECRET', 'source', 'value' ) as $forbidden ) {
	lunara_core_health_assert( false === strpos( json_encode( $write ), $forbidden ), 'Compact snapshot write leaked forbidden data: ' . $forbidden );
}
lunara_core_health_reset_memo();
$inserted_status = lunara_core_review_identity_artwork_status();
lunara_core_health_assert_same( 'fresh', $inserted_status['snapshot']['state'], 'An absent-row insert must be visible to the next ordinary compact option read.' );
lunara_core_health_assert_same( array( 'lunara_core_review_artwork_health_snapshot' ), $GLOBALS['lunara_core_health_state']['option_reads'], 'Post-insert ordinary status must read only the compact owner option.' );

$persisted_snapshot = $GLOBALS['lunara_core_health_state']['options']['lunara_core_review_artwork_health_snapshot'];
lunara_core_health_reset_option_io();
$same_value = Lunara_Core_Site_Studio_Bridge::write_artwork_health_snapshot( $write_coverage, $write_job, $write_credentials );
lunara_core_health_assert_same( true, $same_value, 'Same-second identical refresh must accept an exact persisted physical row without another write.' );
lunara_core_health_assert_same( $persisted_snapshot, $GLOBALS['lunara_core_health_state']['options']['lunara_core_review_artwork_health_snapshot'], 'Same-value refresh must preserve the exact normalized snapshot.' );
lunara_core_health_assert_same( 0, $GLOBALS['lunara_core_health_state']['update_calls'], 'Same-second identical refresh must not publish any transient state.' );

lunara_core_health_set_physical_option( 'lunara_core_review_artwork_health_snapshot', $persisted_snapshot, 'yes' );
lunara_core_health_reset_option_io();
$legacy_autoload = Lunara_Core_Site_Studio_Bridge::write_artwork_health_snapshot( $write_coverage, $write_job, $write_credentials );
lunara_core_health_assert_same( true, $legacy_autoload, 'Same-value refresh must durably correct a legacy autoloaded compact option.' );
lunara_core_health_assert_same( 'no', $GLOBALS['lunara_core_health_state']['autoloads']['lunara_core_review_artwork_health_snapshot'], 'Verified snapshot writes must durably disable autoload.' );
lunara_core_health_assert_same( 1, $GLOBALS['lunara_core_health_state']['update_calls'], 'Legacy autoload correction must use one physical CAS.' );

lunara_core_health_set_physical_option( 'lunara_core_review_artwork_health_snapshot', $prior_snapshot = $valid_snapshot, 'yes' );
lunara_core_health_reset_option_io();
$GLOBALS['lunara_core_health_state']['poison_reads'] = 20;
$filtered_adversary = Lunara_Core_Site_Studio_Bridge::write_artwork_health_snapshot( $write_coverage, $write_job, $write_credentials );
lunara_core_health_assert_same( true, $filtered_adversary, 'Filtered get_option adversaries must not affect the physical-row transaction.' );
lunara_core_health_assert_same( 20, $GLOBALS['lunara_core_health_state']['poison_reads'], 'Physical snapshot persistence must not consume a filtered option read.' );

lunara_core_health_set_physical_option( 'lunara_core_review_artwork_health_snapshot', $prior_snapshot, 'yes' );
lunara_core_health_reset_option_io();
$GLOBALS['lunara_core_health_state']['poison_on_update'] = 1;
$failed_readback = Lunara_Core_Site_Studio_Bridge::write_artwork_health_snapshot( $write_coverage, $write_job, $write_credentials );
lunara_core_health_assert_same( false, $failed_readback, 'A post-write exact-readback mismatch must fail closed.' );
lunara_core_health_assert_same( $prior_snapshot, $GLOBALS['lunara_core_health_state']['options']['lunara_core_review_artwork_health_snapshot'], 'Readback mismatch must restore the exact prior compact option value.' );
lunara_core_health_assert_same( 'yes', $GLOBALS['lunara_core_health_state']['autoloads']['lunara_core_review_artwork_health_snapshot'], 'Readback mismatch rollback must restore the prior autoload state.' );
lunara_core_health_assert_same( 1, $GLOBALS['lunara_core_health_state']['update_calls'], 'Readback mismatch must roll back the one staged mutation without a second option write.' );

lunara_core_health_set_physical_option( 'lunara_core_review_artwork_health_snapshot', $prior_snapshot, 'yes' );
lunara_core_health_reset_option_io();
$GLOBALS['lunara_core_health_state']['poison_on_update']  = 1;
$GLOBALS['lunara_core_health_state']['fail_rollback']     = true;
$owned_rollback_failure = Lunara_Core_Site_Studio_Bridge::write_artwork_health_snapshot( $write_coverage, $write_job, $write_credentials );
lunara_core_health_assert_same( false, $owned_rollback_failure, 'A failed transaction rollback must keep the refresh result failed.' );
lunara_core_health_reset_memo();
$after_owned_rollback_failure = lunara_core_review_identity_artwork_status();
lunara_core_health_assert_same( false, $after_owned_rollback_failure['known'], 'An unverified still-owned forward row must never become known after rollback failure.' );
lunara_core_health_assert_same( 'failed', $after_owned_rollback_failure['snapshot']['state'], 'Rollback failure must durably fail-close an unverified still-owned forward row.' );

lunara_core_health_set_physical_option( 'lunara_core_review_artwork_health_snapshot', $prior_snapshot, 'yes' );
lunara_core_health_reset_option_io();
$GLOBALS['lunara_core_health_state']['poison_on_update']  = 1;
$GLOBALS['lunara_core_health_state']['fail_rollback']     = true;
$double_repair_failure = Lunara_Core_Site_Studio_Bridge::write_artwork_health_snapshot( $write_coverage, $write_job, $write_credentials );
lunara_core_health_assert_same( false, $double_repair_failure, 'Forward verification plus lost rollback acknowledgement must keep the refresh failed.' );
$same_request_unverified = lunara_core_review_identity_artwork_status();
lunara_core_health_assert_same( false, $same_request_unverified['known'], 'The same request must intrinsically reject a still-owned unverified physical row.' );
lunara_core_health_assert_same( 'failed', $same_request_unverified['snapshot']['state'], 'The same request must expose an unverified physical row only as failed.' );
$GLOBALS['wpdb']->disconnect();
lunara_core_health_reset_bridge_request();
lunara_core_health_assert_same( $prior_snapshot, $GLOBALS['lunara_core_health_state']['options']['lunara_core_review_artwork_health_snapshot'], 'Disconnect after a lost rollback acknowledgement must restore the exact transaction prestate.' );

lunara_core_health_set_physical_option( 'lunara_core_review_artwork_health_snapshot', $prior_snapshot, 'yes' );
lunara_core_health_reset_option_io();
$GLOBALS['lunara_core_health_state']['poison_on_update'] = 2;
$promotion_readback_failure = Lunara_Core_Site_Studio_Bridge::write_artwork_health_snapshot( $write_coverage, $write_job, $write_credentials );
lunara_core_health_assert_same( false, $promotion_readback_failure, 'A successful verified promotion with failed exact final readback must report failure.' );
$same_request_failed_promotion = lunara_core_review_identity_artwork_status();
lunara_core_health_assert_same( true, $same_request_failed_promotion['known'], 'Failed promotion readback must expose only the exact restored prior health.' );
lunara_core_health_reset_bridge_request();
$fresh_failed_promotion = lunara_core_review_identity_artwork_status();
lunara_core_health_assert_same( $prior_snapshot['generated_at'], $fresh_failed_promotion['snapshot']['generated_at'], 'A fresh bridge read after failed promotion must see the exact prior snapshot, never the candidate.' );

lunara_core_health_set_physical_option( 'lunara_core_review_artwork_health_snapshot', $prior_snapshot, 'yes' );
lunara_core_health_reset_option_io();
$GLOBALS['lunara_core_health_state']['fail_commit'] = true;
$commit_failure_outcome = null;
$commit_failure = Lunara_Core_Site_Studio_Bridge::write_artwork_health_snapshot( $write_coverage, $write_job, $write_credentials, $commit_failure_outcome );
lunara_core_health_assert_same( false, $commit_failure, 'A verified candidate must not report success when its publication transaction cannot commit.' );
lunara_core_health_assert_same( 'failed', $commit_failure_outcome, 'A physically proved staged COMMIT outcome must remain determinate failure.' );
lunara_core_health_reset_bridge_request();
$after_commit_failure = lunara_core_review_identity_artwork_status();
lunara_core_health_assert_same( $prior_snapshot['generated_at'], $after_commit_failure['snapshot']['generated_at'], 'Proved COMMIT failure must restore and expose only the prior snapshot.' );

lunara_core_health_set_physical_option( 'lunara_core_review_artwork_health_snapshot', $prior_snapshot, 'yes' );
lunara_core_health_reset_option_io();
$GLOBALS['lunara_core_health_state']['commit_applies_but_false'] = true;
$applied_commit_outcome = null;
$applied_commit = Lunara_Core_Site_Studio_Bridge::write_artwork_health_snapshot( $write_coverage, $write_job, $write_credentials, $applied_commit_outcome );
lunara_core_health_assert_same( true, $applied_commit, 'An applied COMMIT with a lost acknowledgement must be resolved by exact post-commit physical readback, not reported as a failed publication.' );
lunara_core_health_assert_same( 'resolved', $applied_commit_outcome, 'Exact applied COMMIT readback must expose only a request-local resolved outcome.' );
lunara_core_health_reset_bridge_request();
$fresh_applied_commit = lunara_core_review_identity_artwork_status();
lunara_core_health_assert_same( true, $fresh_applied_commit['known'], 'A genuinely fresh bridge request may accept the acknowledged-by-readback committed row.' );
lunara_core_health_assert_same( 'fresh', $fresh_applied_commit['snapshot']['state'], 'Applied commit resolution must leave a fresh durable snapshot.' );

lunara_core_health_set_physical_option( 'lunara_core_review_artwork_health_snapshot', $prior_snapshot, 'yes' );
lunara_core_health_reset_option_io();
$GLOBALS['lunara_core_health_state']['commit_applies_but_throws'] = true;
$thrown_applied_commit = Lunara_Core_Site_Studio_Bridge::write_artwork_health_snapshot( $write_coverage, $write_job, $write_credentials );
lunara_core_health_assert_same( true, $thrown_applied_commit, 'An applied COMMIT whose acknowledgement throws must resolve to success after exact durable readback.' );
lunara_core_health_reset_bridge_request();
lunara_core_health_assert_same( 'fresh', lunara_core_review_identity_artwork_status()['snapshot']['state'], 'A fresh request must accept the exactly resolved applied COMMIT after a thrown acknowledgement.' );

lunara_core_health_set_physical_option( 'lunara_core_review_artwork_health_snapshot', $prior_snapshot, 'yes' );
lunara_core_health_reset_option_io();
$GLOBALS['lunara_core_health_state']['fail_commit']                = true;
$GLOBALS['lunara_core_health_state']['commit_failure_read_errors'] = true;
$unreadable_outcome = null;
$unreadable_commit_result = Lunara_Core_Site_Studio_Bridge::write_artwork_health_snapshot( $write_coverage, $write_job, $write_credentials, $unreadable_outcome );
lunara_core_health_assert_same( true, $unreadable_commit_result, 'An unreadable COMMIT outcome after exact pre-commit verification must not return the hard proved-failure boolean.' );
lunara_core_health_assert_same( 'indeterminate', $unreadable_outcome, 'Unreadable durability must be carried privately as indeterminate for the owner handler.' );
lunara_core_health_reset_bridge_request();
$fresh_unreadable_outcome = lunara_core_review_identity_artwork_status();
lunara_core_health_assert_same( $prior_snapshot['generated_at'], $fresh_unreadable_outcome['snapshot']['generated_at'], 'An indeterminate true result with proved rollback must expose only the prior snapshot, never the candidate.' );

lunara_core_health_set_physical_option( 'lunara_core_review_artwork_health_snapshot', $prior_snapshot, 'yes' );
lunara_core_health_reset_option_io();
$GLOBALS['lunara_core_health_state']['poison_on_update'] = 2;
$GLOBALS['lunara_core_health_state']['fail_rollback']    = true;
$promotion_rollback_failure = Lunara_Core_Site_Studio_Bridge::write_artwork_health_snapshot( $write_coverage, $write_job, $write_credentials );
lunara_core_health_assert_same( false, $promotion_rollback_failure, 'Promotion verification plus transaction rollback failure must remain failed.' );
$same_request_uncommitted = lunara_core_review_identity_artwork_status();
lunara_core_health_assert_same( false, $same_request_uncommitted['known'], 'An unresolved uncommitted verified candidate must be rejected in the same request.' );
lunara_core_health_reset_memo();
$fresh_uncommitted = lunara_core_review_identity_artwork_status();
lunara_core_health_assert_same( false, $fresh_uncommitted['known'], 'A fresh bridge read in the request must reject an unresolved uncommitted verified candidate.' );

unset( $GLOBALS['lunara_core_health_state']['options']['lunara_core_review_artwork_health_snapshot'] );
unset( $GLOBALS['lunara_core_health_state']['rows']['lunara_core_review_artwork_health_snapshot'] );
unset( $GLOBALS['lunara_core_health_state']['autoloads']['lunara_core_review_artwork_health_snapshot'] );
lunara_core_health_reset_option_io();
$GLOBALS['lunara_core_health_state']['poison_on_update'] = 1;
$absent_readback_failure = Lunara_Core_Site_Studio_Bridge::write_artwork_health_snapshot( $write_coverage, $write_job, $write_credentials );
lunara_core_health_assert_same( false, $absent_readback_failure, 'An absent-row post-insert readback mismatch must fail closed.' );
lunara_core_health_assert_same( false, isset( $GLOBALS['lunara_core_health_state']['rows']['lunara_core_review_artwork_health_snapshot'] ), 'An absent-row mismatch must restore exact prior absence with a fenced delete.' );
lunara_core_health_assert_same( 1, $GLOBALS['lunara_core_health_state']['update_calls'], 'Absent-row rollback must restore absence without a second option write.' );

lunara_core_health_set_physical_option( 'lunara_core_review_artwork_health_snapshot', $prior_snapshot, 'yes' );
lunara_core_health_reset_option_io();
$GLOBALS['lunara_core_health_state']['concurrent_on_update'] = 1;
$rollback_failed = Lunara_Core_Site_Studio_Bridge::write_artwork_health_snapshot( $write_coverage, $write_job, $write_credentials );
lunara_core_health_assert_same( false, $rollback_failed, 'Rollback failure must keep the refresh result failed.' );
lunara_core_health_assert_same( array( 'schema' => 'concurrent-invalid-row' ), $GLOBALS['lunara_core_health_state']['options']['lunara_core_review_artwork_health_snapshot'], 'Rollback failure must not overwrite an unknown concurrent physical row.' );
lunara_core_health_reset_memo();
$after_rollback_failure = lunara_core_review_identity_artwork_status();
lunara_core_health_assert_same( false, $after_rollback_failure['known'], 'Rollback failure must not leave a newly written fresh snapshot visible.' );
lunara_core_health_assert_same( 'incompatible', $after_rollback_failure['snapshot']['state'], 'Rollback failure must leave an unknown concurrent row explicitly non-fresh.' );

unset( $GLOBALS['lunara_core_health_state']['options']['lunara_core_review_artwork_health_snapshot'] );
unset( $GLOBALS['lunara_core_health_state']['rows']['lunara_core_review_artwork_health_snapshot'] );
unset( $GLOBALS['lunara_core_health_state']['autoloads']['lunara_core_review_artwork_health_snapshot'] );
lunara_core_health_reset_option_io();
$GLOBALS['lunara_core_health_state']['db_read_errors'] = 1;
$read_error_write = Lunara_Core_Site_Studio_Bridge::write_artwork_health_snapshot( $write_coverage, $write_job, $write_credentials );
lunara_core_health_assert_same( false, $read_error_write, 'A physical SELECT failure must never be confused with an absent row.' );
lunara_core_health_assert_same( 0, $GLOBALS['lunara_core_health_state']['update_calls'], 'A failed physical prestate read must prevent inserts.' );

lunara_core_health_set_physical_option( 'lunara_core_review_artwork_health_snapshot', $prior_snapshot, 'yes' );
lunara_core_health_reset_option_io();
$GLOBALS['lunara_core_health_state']['db_update_throws'] = true;
$throwing_physical_write = Lunara_Core_Site_Studio_Bridge::write_artwork_health_snapshot( $write_coverage, $write_job, $write_credentials );
lunara_core_health_assert_same( false, $throwing_physical_write, 'A physical write exception must fail closed at the snapshot boundary.' );

lunara_core_health_set_physical_option( 'lunara_core_review_artwork_health_snapshot', $valid_snapshot, 'no' );
lunara_core_health_reset_option_io();
$GLOBALS['lunara_core_health_state']['db_read_errors'] = 1;
$read_error_projection = Lunara_Core_Site_Studio_Bridge::update_artwork_health_job_projection(
	array(
		'status'       => 'running',
		'processed'    => 1,
		'total'        => 3,
		'started_at'   => $now - 10,
		'completed_at' => 0,
		'counts'       => array( 'ready' => 1, 'partial' => 0, 'conflicts' => 0, 'errors' => 0 ),
	)
);
lunara_core_health_assert_same( false, $read_error_projection, 'A job projection must fail closed when its physical compact prestate cannot be read.' );
lunara_core_health_assert_same( $valid_snapshot, $GLOBALS['lunara_core_health_state']['options']['lunara_core_review_artwork_health_snapshot'], 'A job projection read failure must preserve the compact snapshot exactly.' );
lunara_core_health_assert_same( 0, $GLOBALS['lunara_core_health_state']['update_calls'], 'A job projection read failure must not write unknown fields over known health.' );

unset( $GLOBALS['lunara_core_health_state']['options']['lunara_core_review_artwork_health_snapshot'] );
unset( $GLOBALS['lunara_core_health_state']['rows']['lunara_core_review_artwork_health_snapshot'] );
unset( $GLOBALS['lunara_core_health_state']['autoloads']['lunara_core_review_artwork_health_snapshot'] );
lunara_core_health_reset_option_io();
$invalid_counts = array(
	'negative'     => array( 'total' => 12, 'identity_ready' => 10, 'missing_identity' => 2, 'missing_poster' => -1, 'missing_backdrop' => 1, 'custom_protected' => 3 ),
	'fractional'   => array( 'total' => 12, 'identity_ready' => 10, 'missing_identity' => 2, 'missing_poster' => 1.5, 'missing_backdrop' => 1, 'custom_protected' => 3 ),
	'overflow'     => array( 'total' => 2147483648, 'identity_ready' => 10, 'missing_identity' => 2, 'missing_poster' => 1, 'missing_backdrop' => 1, 'custom_protected' => 3 ),
	'inconsistent' => array( 'total' => 12, 'identity_ready' => 9, 'missing_identity' => 2, 'missing_poster' => 1, 'missing_backdrop' => 1, 'custom_protected' => 3 ),
);
foreach ( $invalid_counts as $name => $coverage ) {
	$invalid_write = Lunara_Core_Site_Studio_Bridge::write_artwork_health_snapshot(
		$coverage,
		array( 'status' => 'idle', 'processed' => 0, 'total' => 0, 'counts' => array( 'ready' => 0, 'partial' => 0, 'conflicts' => 0, 'errors' => 0 ), 'started_at' => 0, 'completed_at' => 0 ),
		array( 'omdb' => true, 'tmdb' => true, 'ready' => true )
	);
	lunara_core_health_assert_same( false, $invalid_write, ucfirst( $name ) . ' snapshot counts must fail strict normalization.' );
}
lunara_core_health_assert_same( array(), $GLOBALS['lunara_core_health_state']['option_writes'], 'Rejected count candidates must not write any snapshot.' );
lunara_core_health_reset_memo();
$after_invalid = lunara_core_review_identity_artwork_status();
lunara_core_health_assert_same( false, $after_invalid['known'], 'Rejected count candidates must not become a known snapshot.' );
lunara_core_health_assert_same( 'missing', $after_invalid['snapshot']['state'], 'Rejected count candidates must leave snapshot state missing.' );

$GLOBALS['lunara_core_health_state']['option_writes'] = array();
$job_only = Lunara_Core_Site_Studio_Bridge::update_artwork_health_job_projection(
	array(
		'status'       => 'running',
		'processed'    => 1,
		'total'        => 3,
		'started_at'   => $now - 10,
		'completed_at' => 0,
		'counts'       => array( 'ready' => 1, 'partial' => 0, 'conflicts' => 0, 'errors' => 0 ),
		'ids'          => array( 17, 18, 19 ),
		'recent'       => array( 'SECRET' ),
	)
);
lunara_core_health_assert_same( true, $job_only, 'A first intermediate job write must create a bounded job-only snapshot.' );
lunara_core_health_reset_memo();
$partial = lunara_core_review_identity_artwork_status();
lunara_core_health_assert_same( false, $partial['known'], 'Top-level health must remain unknown when coverage and credentials are unknown.' );
lunara_core_health_assert_same( false, $partial['coverage']['known'], 'A job-only snapshot must preserve unknown coverage explicitly.' );
lunara_core_health_assert_same( true, $partial['job']['known'], 'A job-only snapshot must preserve known bounded job progress independently.' );
lunara_core_health_assert_same( 'running', $partial['job']['status'], 'A job-only snapshot must retain the allowlisted active job substate.' );
lunara_core_health_assert_same( false, $partial['credentials']['known'], 'A job-only snapshot must preserve unknown credential readiness explicitly.' );
lunara_core_health_assert_same( 'needs_attention', $partial['state'], 'Partial health must remain in the bounded needs-attention state.' );

lunara_core_health_set_physical_option( 'lunara_core_review_artwork_health_snapshot', $valid_snapshot, 'no' );
lunara_core_health_reset_option_io();
$projected = Lunara_Core_Site_Studio_Bridge::update_artwork_health_job_projection(
	array(
		'status'     => 'paused',
		'processed'  => 11,
		'total'      => 35,
		'started_at' => $now - 500,
		'completed_at' => 0,
		'counts'     => array( 'ready' => 9, 'partial' => 1, 'conflicts' => 2, 'errors' => 1 ),
		'ids'        => range( 1, 100 ),
		'recent'     => array( 'SECRET' ),
		'last_error' => 'SECRET',
	)
);
lunara_core_health_assert_same( true, $projected, 'Intermediate owner job writes must update the compact job projection.' );
$projection = $GLOBALS['lunara_core_health_state']['options']['lunara_core_review_artwork_health_snapshot'];
lunara_core_health_assert_same( $valid_snapshot['coverage']['total'], $projection['coverage']['total'], 'Job projection updates must preserve normalized last-known coverage.' );
lunara_core_health_assert_same( true, $projection['coverage']['known'], 'Job projection updates must preserve explicit coverage knowledge.' );
lunara_core_health_assert_same( true, $projection['credentials']['ready'], 'Job projection updates must preserve normalized last-known credentials.' );
lunara_core_health_assert_same( 'paused', $projection['job']['status'], 'Job projection updates must use only the already-in-memory job state.' );
lunara_core_health_assert_same( true, $projection['job']['known'], 'Job projection updates must mark the already-in-memory job as known.' );
lunara_core_health_assert( false === strpos( json_encode( $projection ), 'SECRET' ), 'Job projection updates must drop recent records and last errors.' );

$contributed = Lunara_Core_Site_Studio_Bridge::contribute_surfaces( array() );
$expected_carousel_surface = array(
	'id'                    => 'core-carousel-manager',
	'group'                 => 'Content',
	'label'                 => 'Carousel Manager',
	'description'           => 'Manage Lunara Carousel slide sets and ordering in the canonical Core tool.',
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
	'dependency_callback'   => array( 'Lunara_Core_Site_Studio_Bridge', 'carousel_manager_dependency' ),
	'status_callback'       => array( 'Lunara_Core_Site_Studio_Bridge', 'carousel_manager_registry_status' ),
	'danger_level'          => 'none',
	'sections'              => array(),
	'classic_url'           => 'themes.php?page=lunara-carousel-manager',
	'renderer'              => '',
);
$expected_artwork_surface = array(
	'id'                    => 'core-review-identity-artwork',
	'group'                 => 'Operations',
	'label'                 => 'Review Identity & Artwork',
	'description'           => 'Review redacted identity and artwork health, then continue in Core’s canonical audit tool.',
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
	'dependency_callback'   => array( 'Lunara_Core_Site_Studio_Bridge', 'review_identity_artwork_dependency' ),
	'status_callback'       => array( 'Lunara_Core_Site_Studio_Bridge', 'review_identity_artwork_registry_status' ),
	'danger_level'          => 'caution',
	'sections'              => array(),
	'classic_url'           => 'edit.php?post_type=review&page=lunara-review-artwork-audit',
	'renderer'              => '',
);
lunara_core_health_assert_same( $expected_carousel_surface, $contributed['core-carousel-manager'], 'Core must contribute the exact inert Carousel handoff.' );
lunara_core_health_assert_same( $expected_artwork_surface, $contributed['core-review-identity-artwork'], 'Core must contribute the exact inert Review Identity & Artwork handoff.' );
lunara_core_health_assert( isset( $contributed['core-review-studio'] ), 'The existing Review Studio contribution must remain present.' );

lunara_core_health_assert( false !== strpos( $bootstrap, 'Version: 0.8.10' ), 'Core must identify release 0.8.10.' );
lunara_core_health_assert( false !== strpos( $bootstrap, "define( 'LUNARA_CORE_VERSION', '0.8.10' );" ), 'Core runtime identity must match the 0.8.10 plugin header.' );
lunara_core_health_assert( false !== strpos( $bridge_source, 'SELECT option_id, option_value, autoload' ), 'Snapshot transactions must capture the physical option-row identity and exact raw prestate.' );
lunara_core_health_assert( false !== strpos( $bridge_source, 'BINARY option_value = BINARY %s' ), 'Snapshot CAS writes must compare raw serialized values byte-for-byte.' );
lunara_core_health_assert( false !== strpos( $bridge_source, 'option_id = %d' ), 'Snapshot forward and rollback writes must fence the physical option-row identity.' );
lunara_core_health_assert( false !== strpos( $bridge_source, 'BINARY autoload = BINARY %s LIMIT 1' ), 'Fenced snapshot UPDATE/DELETE statements must be byte-exact and explicitly bounded.' );

echo "Site Studio destination and health regression checks passed.\n";
