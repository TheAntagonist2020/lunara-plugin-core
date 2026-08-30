<?php
/**
 * Dependency-free behavioral contracts for Core's early theme-mod coordinator.
 *
 * Run with: php tests/theme-mods-coordinator-regression.php
 */

function lunara_coordinator_assert( $condition, $message ) {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

function lunara_coordinator_assert_same( $expected, $actual, $message ) {
	if ( $expected !== $actual ) {
		throw new RuntimeException(
			$message . "\nExpected: " . var_export( $expected, true ) . "\nActual: " . var_export( $actual, true )
		);
	}
}

function lunara_coordinator_connection_drifts( $events ) {
	return array_values(
		array_filter(
			$events,
			static function ( $event ) {
				return is_array( $event ) && isset( $event[0] ) && 'injected-connection-change' === $event[0];
			}
		)
	);
}

function lunara_coordinator_watchdog_cleanup_queries( $events ) {
	return array_values(
		array_filter(
			$events,
			static function ( $event ) {
				return is_array( $event ) && isset( $event[0], $event[1] ) && 'sql-row' === $event[0] && false !== strpos( $event[1], 'information_schema.PROCESSLIST' );
			}
		)
	);
}

function lunara_coordinator_core_clear_record() {
	return array(
		'schema_version' => 2,
		'state'          => 'clear',
		'owner'          => null,
		'option'         => null,
		'purpose'        => null,
		'acquired_at'    => 0,
	);
}

function lunara_coordinator_legacy_clear_record() {
	return array(
		'schema_version' => 1,
		'state'          => 'clear',
		'owner'          => null,
		'action'         => null,
		'acquired_at'    => 0,
	);
}

function lunara_coordinator_marker_record() {
	return array(
		'schema_version'             => 1,
		'coordinator_schema_version' => 2,
		'api_version'                => 1,
		'release'                    => '0.8.11',
		'initialized'                => true,
	);
}

function lunara_coordinator_run_child( $case, $no_ini = false ) {
	$command = $no_ini ? array( PHP_BINARY, '-n', __FILE__, $case ) : array( PHP_BINARY, __FILE__, $case );
	$pipes   = array();
	$process = proc_open(
		$command,
		array(
			0 => array( 'pipe', 'r' ),
			1 => array( 'pipe', 'w' ),
			2 => array( 'pipe', 'w' ),
		),
		$pipes,
		dirname( __DIR__ )
	);
	lunara_coordinator_assert( is_resource( $process ), 'The coordinator regression could not start its isolated ' . $case . ' case.' );
	fclose( $pipes[0] );
	$stdout = stream_get_contents( $pipes[1] );
	$stderr = stream_get_contents( $pipes[2] );
	fclose( $pipes[1] );
	fclose( $pipes[2] );
	$exit = proc_close( $process );
	if ( 0 !== $exit ) {
		throw new RuntimeException( "Coordinator case {$case} failed with exit {$exit}.\n{$stdout}{$stderr}" );
	}
	return $stdout;
}

function lunara_coordinator_real_database_config() {
	$keys = array( 'HOST', 'USER', 'PASSWORD', 'NAME' );
	if ( '1' !== getenv( 'LUNARA_CORE_COORDINATOR_TEST_DB_SAFE' ) ) {
		return null;
	}
	$config = array();
	foreach ( $keys as $key ) {
		$value = getenv( 'LUNARA_CORE_COORDINATOR_TEST_DB_' . $key );
		if ( false === $value || '' === $value ) {
			return null;
		}
		$config[ strtolower( $key ) ] = $value;
	}
	$config['port'] = (int) getenv( 'LUNARA_CORE_COORDINATOR_TEST_DB_PORT' );
	return $config;
}

function lunara_coordinator_run_real_serialization_probe() {
	$config = lunara_coordinator_real_database_config();
	if ( null === $config || ! extension_loaded( 'mysqli' ) ) {
		echo "SKIP: real options-row EXPLAIN/locking requires mysqli and an explicitly safe LUNARA_CORE_COORDINATOR_TEST_DB_* database.\n";
		return;
	}

	$table = 'lunara_core_coordinator_probe_' . substr( hash( 'sha256', __FILE__ . PHP_VERSION ), 0, 12 );
	$db    = mysqli_init();
	lunara_coordinator_assert( $db instanceof mysqli, 'The real serialization probe could not initialize mysqli.' );
	$connected = @mysqli_real_connect(
		$db,
		$config['host'],
		$config['user'],
		$config['password'],
		$config['name'],
		0 < $config['port'] ? $config['port'] : null
	);
	lunara_coordinator_assert( true === $connected, 'The explicitly configured serialization database could not be opened.' );
	$quoted_table = '`' . str_replace( '`', '``', $table ) . '`';
	try {
		lunara_coordinator_assert(
			true === @mysqli_query( $db, "CREATE TABLE IF NOT EXISTS {$quoted_table} (option_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, option_name VARCHAR(191) NOT NULL, option_value LONGTEXT NOT NULL, autoload VARCHAR(20) NOT NULL DEFAULT 'no', PRIMARY KEY (option_id), UNIQUE KEY option_name (option_name)) ENGINE=InnoDB" ),
			'The options-shaped serialization probe table could not be created.'
		);
		lunara_coordinator_assert( true === @mysqli_query( $db, "INSERT INTO {$quoted_table} (option_name, option_value, autoload) VALUES ('lunara_oscars_portal_studio_outer_coordinator', 'clear', 'no'), ('lunara_core_theme_mods_coordinator_migration', 'marker', 'no'), ('ordinary_unrelated_option', 'unchanged', 'yes') ON DUPLICATE KEY UPDATE option_value = VALUES(option_value), autoload = VALUES(autoload)" ), 'The exact options probe rows could not be initialized.' );

		$explain_queries = array(
			"EXPLAIN SELECT option_id, option_name, option_value, autoload FROM {$quoted_table} WHERE option_name IN ('lunara_oscars_portal_studio_outer_coordinator', 'lunara_core_theme_mods_coordinator_migration') ORDER BY option_name FOR UPDATE",
			"EXPLAIN SELECT option_id, option_name, option_value, autoload FROM {$quoted_table} WHERE option_name = 'lunara_oscars_portal_studio_outer_coordinator' LIMIT 1 FOR UPDATE",
		);
		foreach ( $explain_queries as $explain_sql ) {
			$explain = @mysqli_query( $db, $explain_sql );
			lunara_coordinator_assert( $explain instanceof mysqli_result, 'The exact options-row EXPLAIN could not run.' );
			$plan = mysqli_fetch_assoc( $explain );
			mysqli_free_result( $explain );
			lunara_coordinator_assert( is_array( $plan ) && isset( $plan['key'] ) && 'option_name' === $plan['key'], 'The exact option_name predicate must use the existing unique option_name index.' );
		}

		$holder = proc_open(
			array( PHP_BINARY, __FILE__, 'real_lock_holder' ),
			array( 0 => array( 'pipe', 'r' ), 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) ),
			$pipes,
			dirname( __DIR__ )
		);
		lunara_coordinator_assert( is_resource( $holder ), 'The serialization lock holder could not start.' );
		fwrite( $pipes[0], json_encode( array( 'config' => $config, 'table' => $table ) ) . "\n" );
		fclose( $pipes[0] );
		$ready = trim( fgets( $pipes[1] ) );
		lunara_coordinator_assert_same( 'locked', $ready, 'The first process must acquire the InnoDB row lock.' );
		$unrelated_started = microtime( true );
		lunara_coordinator_assert( true === @mysqli_query( $db, "UPDATE {$quoted_table} SET option_value = 'changed' WHERE option_name = 'ordinary_unrelated_option'" ), 'An unrelated options row must remain writable while both exact coordinator names are locked.' );
		lunara_coordinator_assert( 0.5 > microtime( true ) - $unrelated_started, 'The exact coordinator locking read must not wait on or lock an unrelated options row.' );
		$started = microtime( true );
		lunara_coordinator_assert( true === @mysqli_query( $db, 'SET SESSION innodb_lock_wait_timeout = 5' ), 'The contender lock timeout could not be bounded.' );
		lunara_coordinator_assert( true === @mysqli_query( $db, 'START TRANSACTION' ), 'The contender transaction could not start.' );
		$result = @mysqli_query( $db, "SELECT option_value FROM {$quoted_table} WHERE option_name = 'lunara_oscars_portal_studio_outer_coordinator' LIMIT 1 FOR UPDATE" );
		$elapsed = microtime( true ) - $started;
		lunara_coordinator_assert( $result instanceof mysqli_result, 'The contender must acquire the row after the holder commits.' );
		mysqli_free_result( $result );
		@mysqli_query( $db, 'ROLLBACK' );
		stream_get_contents( $pipes[2] );
		fclose( $pipes[1] );
		fclose( $pipes[2] );
		$exit = proc_close( $holder );
		lunara_coordinator_assert( 0 === $exit, 'The options-row lock holder failed with a redacted child error.' );
		lunara_coordinator_assert( 0.75 <= $elapsed, 'The independent contender must wait for the physical InnoDB row lock.' );
		echo "Real options-row EXPLAIN and two-process InnoDB locking passed.\n";
	} finally {
		@mysqli_query( $db, "DROP TABLE IF EXISTS {$quoted_table}" );
		mysqli_close( $db );
	}
}

if ( 1 === $argc ) {
	$coordinator = dirname( __DIR__ ) . '/includes/class-lunara-core-theme-mods-coordinator.php';
	lunara_coordinator_assert( file_exists( $coordinator ), 'Core must ship the early theme-mod coordinator module.' );

	$cases = array(
		'first_install',
		'bootstrap_error_observer',
		'legacy_adoption',
		'existing_install',
		'ready_hooks',
		'lease_api',
		'sql_shape',
		'single_identity_mismatch',
		'nested_and_failed_dml',
		'live_transaction_before_bootstrap',
		'manual_transaction_commit',
		'manual_transaction_rollback',
		'later_pre_dml_transaction',
		'late_load',
		'misordered_load',
		'api_unavailable',
		'ordering_repair',
		'provision_crash',
		'marker_loss',
		'marker_corruption',
		'row_loss',
		'row_corruption',
		'wrong_autoload',
		'non_innodb',
		'db_dropin',
		'non_stock_wpdb',
		'read_only',
		'live_autocommit',
		'transaction_read_only',
		'tx_read_only_fallback',
		'super_read_only',
		'super_read_only_unavailable',
		'transport_contract',
		'ssl_flag_guard',
		'reconnect_policy',
		'transport_mismatch',
		'ssl_downgrade',
		'native_adapter_rows',
		'reporting_scope',
		'live_probe_contract',
		'live_write_proof_failure',
		'live_proof_rollback_failure',
		'live_proof_post_profile_failure',
		'native_write_proof_failure',
		'session_mismatch',
		'lock_contention',
		'invariant_write_failure',
		'connection_loss',
		'migration_reconnect_after_start',
		'migration_transaction_loss_after_write',
		'migration_reconnect_cleanup_uncertainty',
		'acquire_reconnect_after_lock',
		'acquire_transaction_loss_after_cas',
		'acquire_reconnect_cleanup_uncertainty',
		'internal_error_callbacks',
		'shutdown_active_transaction',
		'watchdog_attestation_bootstrap',
		'watchdog_attestation_acquire',
		'watchdog_kill',
		'cleanup_uncertainty',
	);
	foreach ( $cases as $case ) {
		lunara_coordinator_run_child( $case, 'native_adapter_rows' === $case );
	}
	lunara_coordinator_run_real_serialization_probe();
	echo "Theme-mod coordinator regression checks passed.\n";
	exit( 0 );
}

if ( 'real_lock_holder' === $argv[1] ) {
	$payload = json_decode( stream_get_contents( STDIN ), true );
	$config  = is_array( $payload ) && isset( $payload['config'] ) && is_array( $payload['config'] ) ? $payload['config'] : array();
	$table   = is_array( $payload ) && isset( $payload['table'] ) && is_string( $payload['table'] ) ? $payload['table'] : '';
	if ( 1 !== preg_match( '/^[A-Za-z0-9_]+$/D', $table ) || ! isset( $config['host'], $config['user'], $config['password'], $config['name'], $config['port'] ) ) {
		exit( 2 );
	}
	$db     = mysqli_init();
	if ( ! ( $db instanceof mysqli ) || ! @mysqli_real_connect( $db, $config['host'], $config['user'], $config['password'], $config['name'], 0 < (int) $config['port'] ? (int) $config['port'] : null ) ) {
		exit( 3 );
	}
	$quoted_table = '`' . str_replace( '`', '``', $table ) . '`';
	$before_result = @mysqli_query( $db, 'SELECT CONNECTION_ID() AS connection_id' );
	$before        = $before_result instanceof mysqli_result ? mysqli_fetch_assoc( $before_result ) : null;
	if ( $before_result instanceof mysqli_result ) {
		mysqli_free_result( $before_result );
	}
	$lock_result = true === @mysqli_query( $db, 'START TRANSACTION' )
		? @mysqli_query( $db, "SELECT option_id, option_name, option_value, autoload FROM {$quoted_table} WHERE option_name IN ('lunara_oscars_portal_studio_outer_coordinator', 'lunara_core_theme_mods_coordinator_migration') ORDER BY option_name FOR UPDATE" )
		: false;
	if ( ! is_array( $before ) || ! ( $lock_result instanceof mysqli_result ) ) {
		exit( 4 );
	}
	mysqli_free_result( $lock_result );
	$after_result = @mysqli_query( $db, 'SELECT CONNECTION_ID() AS connection_id, @@session.autocommit AS autocommit, @@session.in_transaction AS in_transaction' );
	$after        = $after_result instanceof mysqli_result ? mysqli_fetch_assoc( $after_result ) : null;
	if ( $after_result instanceof mysqli_result ) {
		mysqli_free_result( $after_result );
	}
	if ( ! is_array( $after ) || $before['connection_id'] !== $after['connection_id'] || '1' !== (string) $after['autocommit'] || '1' !== (string) $after['in_transaction'] ) {
		exit( 5 );
	}
	echo "locked\n";
	flush();
	usleep( 1250000 );
	@mysqli_query( $db, 'COMMIT' );
	mysqli_close( $db );
	exit( 0 );
}

$lunara_coordinator_case = $argv[1];

/* The -n adapter case supplies a tiny mysqli surface so both result paths are
 * behavioral even when the ordinary test runtime has no mysqli extension. */
if ( 'native_adapter_rows' === $lunara_coordinator_case && ! class_exists( 'mysqli', false ) ) {
	if ( ! function_exists( 'ctype_digit' ) ) {
		function ctype_digit( $value ) {
			return is_string( $value ) && 1 === preg_match( '/^[0-9]+$/D', $value );
		}
	}
	class mysqli {
		public $closed = false;
	}
	class mysqli_stmt {
		public $rows = array();
		public $position = 0;
		public $bound = array();
	}
	class mysqli_result {
		public $rows = array();
		public $position = 0;
		public $fields = array();
		public function __construct( $rows = array(), $fields = array() ) {
			$this->rows   = $rows;
			$this->fields = $fields;
		}
	}

	function mysqli_prepare( $dbh, $sql ) {
		$statement       = new mysqli_stmt();
		$statement->rows = $GLOBALS['lunara_fake_mysqli_rows'];
		return $statement;
	}
	function mysqli_stmt_bind_param( $statement, $types, &...$values ) {
		return true;
	}
	function mysqli_stmt_execute( $statement ) {
		return 'lock-error' !== $GLOBALS['lunara_fake_mysqli_mode'];
	}
	function mysqli_stmt_errno( $statement ) {
		return 'lock-error' === $GLOBALS['lunara_fake_mysqli_mode'] ? 1205 : 0;
	}
	function mysqli_errno( $dbh ) {
		return 0;
	}
	function mysqli_stmt_get_result( $statement ) {
		return 'get-result' === $GLOBALS['lunara_fake_mysqli_mode'] ? new mysqli_result( $statement->rows ) : false;
	}
	function mysqli_fetch_assoc( $result ) {
		if ( ! isset( $result->rows[ $result->position ] ) ) {
			return null;
		}
		return $result->rows[ $result->position++ ];
	}
	function mysqli_free_result( $result ) {
		return true;
	}
	function mysqli_stmt_result_metadata( $statement ) {
		$fields = array();
		if ( isset( $statement->rows[0] ) ) {
			foreach ( array_keys( $statement->rows[0] ) as $name ) {
				$field       = new stdClass();
				$field->name = $name;
				$fields[]    = $field;
			}
		}
		return new mysqli_result( array(), $fields );
	}
	function mysqli_fetch_fields( $result ) {
		return $result->fields;
	}
	function mysqli_stmt_bind_result( $statement, &...$values ) {
		foreach ( $values as $index => &$value ) {
			$statement->bound[ $index ] =& $value;
		}
		return true;
	}
	function mysqli_stmt_fetch( $statement ) {
		if ( ! isset( $statement->rows[ $statement->position ] ) ) {
			return null;
		}
		$row = array_values( $statement->rows[ $statement->position++ ] );
		foreach ( $statement->bound as $index => &$value ) {
			$value = $row[ $index ];
		}
		return true;
	}
	function mysqli_stmt_affected_rows( $statement ) {
		return 0;
	}
	function mysqli_insert_id( $dbh ) {
		return 0;
	}
	function mysqli_stmt_close( $statement ) {
		return true;
	}
	function mysqli_close( $dbh ) {
		$dbh->closed = true;
		return true;
	}
}

$lunara_dropin_dir       = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'lunara-coordinator-' . md5( __FILE__ . $lunara_coordinator_case );
if ( ! is_dir( $lunara_dropin_dir ) ) {
	mkdir( $lunara_dropin_dir, 0777, true );
}
if ( 'db_dropin' === $lunara_coordinator_case ) {
	file_put_contents( $lunara_dropin_dir . DIRECTORY_SEPARATOR . 'db.php', "<?php\n" );
} elseif ( file_exists( $lunara_dropin_dir . DIRECTORY_SEPARATOR . 'db.php' ) ) {
	unlink( $lunara_dropin_dir . DIRECTORY_SEPARATOR . 'db.php' );
}

define( 'ABSPATH', dirname( __DIR__ ) . '/' );
define( 'WP_CONTENT_DIR', $lunara_dropin_dir );
define( 'LUNARA_CORE_FILE', 'C:/wordpress/wp-content/plugins/lunara-core/lunara-core.php' );
define( 'LUNARA_CORE_VERSION', '0.8.11' );
define( 'DB_HOST', 'secret-db.internal:3306' );
define( 'DB_USER', 'secret_core_user' );
define( 'DB_PASSWORD', 'secret_core_password' );
define( 'DB_NAME', 'lunara_test' );
if ( ! defined( 'MYSQL_CLIENT_FLAGS' ) ) {
	define( 'MYSQL_CLIENT_FLAGS', 'ssl_flag_guard' === $lunara_coordinator_case ? 0 : 2048 );
}
if ( ! defined( 'MYSQLI_CLIENT_SSL' ) ) {
	define( 'MYSQLI_CLIENT_SSL', 2048 );
}
define( 'LUNARA_CORE_THEME_MODS_COORDINATOR_TESTING', true );

final class WP_Error {
	public $errors = array();
	public $error_data = array();

	public function __construct( $code = '', $message = '', $data = null ) {
		$code    = (string) $code;
		$message = (string) $message;
		if ( '' === $code ) {
			return;
		}
		if ( function_exists( 'do_action' ) ) {
			do_action( 'wp_error_added', $code, $message, $data, $this );
		}
		$this->errors[ $code ][] = $message;
		if ( null !== $data ) {
			$this->error_data[ $code ] = $data;
		}
	}

	public function get_error_code() {
		$codes = array_keys( $this->errors );
		return isset( $codes[0] ) ? $codes[0] : '';
	}

	public function get_error_message() {
		$code = $this->get_error_code();
		return isset( $this->errors[ $code ][0] ) ? $this->errors[ $code ][0] : '';
	}

	public function get_error_data() {
		$code = $this->get_error_code();
		return isset( $this->error_data[ $code ] ) ? $this->error_data[ $code ] : null;
	}
}

function is_wp_error( $value ) {
	$is_error = $value instanceof WP_Error;
	if ( $is_error && function_exists( 'do_action' ) ) {
		do_action( 'is_wp_error_instance', $value );
	}
	return $is_error;
}

class wpdb {
	public $options = 'wp_options';
	public $prefix  = 'wp_';
	public $last_error = '';
	protected $dbh = 'stock-wpdb-handle-is-not-public';
	protected $dbhost = 'secret-db.internal:3306';

	public function get_row( $sql, $output = 'ARRAY_A' ) {
		$store = $GLOBALS['lunara_coordinator_store'];
		if ( false !== strpos( $sql, '@@session.transaction_read_only' ) ) {
			if ( ! $store->transaction_read_only_supported ) {
				return null;
			}
			return array( 'transaction_read_only' => (string) $store->live_transaction_read_only );
		}
		if ( false !== strpos( $sql, '@@session.tx_read_only' ) ) {
			return array( 'transaction_read_only' => (string) $store->live_transaction_read_only );
		}
		if ( false !== strpos( $sql, "SHOW GLOBAL VARIABLES LIKE 'super_read_only'" ) ) {
			if ( ! $store->super_read_only_supported ) {
				return null;
			}
			return array( 'Variable_name' => 'super_read_only', 'Value' => $store->super_read_only ? 'ON' : 'OFF' );
		}
		if ( false !== strpos( $sql, "SHOW SESSION STATUS LIKE 'Ssl_cipher'" ) ) {
			return array( 'Variable_name' => 'Ssl_cipher', 'Value' => $store->live_ssl_cipher );
		}
		if ( false !== strpos( $sql, 'performance_schema.threads' ) ) {
			return array( 'connection_type' => $store->live_connection_type );
		}

		if ( false !== strpos( $sql, '@@session.in_transaction' ) ) {
			++$store->live_profile_reads;
			if ( $store->live_profile_reads === $store->live_profile_fail_on_read ) {
				return null;
			}
			return array(
				'connection_id'   => '7',
				'database_name'   => 'lunara_test',
				'autocommit'      => (string) $store->live_autocommit,
				'in_transaction'  => (string) $store->live_in_transaction,
				'read_only'       => (string) $store->read_only,
				'server_hostname' => 'secret-primary.internal',
				'server_port'     => '3306',
				'server_id'       => '73',
				'current_user'    => 'secret_core_user@%',
			);
		}

		return array(
			'connection_id'   => '7',
			'database_name'   => 'lunara_test',
			'autocommit'      => (string) $store->live_autocommit,
			'read_only'       => (string) $store->read_only,
			'server_hostname' => 'secret-primary.internal',
			'server_port'     => '3306',
			'server_id'       => '73',
			'current_user'    => 'secret_core_user@%',
		);
	}

	public function query( $sql ) {
		$store = $GLOBALS['lunara_coordinator_store'];
		$this->last_error = '';
		$store->events[]  = array( 'live-query', $sql );
		if ( 'START TRANSACTION' === $sql ) {
			if ( $store->live_in_transaction ) {
				$this->last_error = 'nested transaction';
				return false;
			}
			$store->live_in_transaction = 1;
			return 0;
		}
		if ( 'ROLLBACK' === $sql && $store->live_rollback_failure ) {
			$this->last_error = 'bounded fake live rollback denial';
			return false;
		}
		if ( 'COMMIT' === $sql || 'ROLLBACK' === $sql ) {
			$store->live_in_transaction = 0;
			return 0;
		}
		if ( false !== strpos( $sql, 'option_id = 0' ) ) {
			if ( $store->live_write_proof_failure ) {
				$this->last_error = 'bounded fake live write denial';
				return false;
			}
			return 0;
		}
		$this->last_error = 'unexpected live query';
		return false;
	}
}

final class Lunara_Coordinator_Custom_WPDB extends wpdb {}

final class Lunara_Coordinator_Test_Store {
	const ROW_OPTION    = 'lunara_oscars_portal_studio_outer_coordinator';
	const MARKER_OPTION = 'lunara_core_theme_mods_coordinator_migration';

	public $rows = array();
	public $next_option_id = 1;
	public $next_connection_id = 100;
	public $connections = array();
	public $lock_owner = null;
	public $events = array();
	public $bundle_count = 0;
	public $live_profile_reads = 0;
	public $engine = 'InnoDB';
	public $read_only = 0;
	public $live_autocommit = 1;
	public $live_in_transaction = 0;
	public $live_transaction_read_only = 0;
	public $native_transaction_read_only = 0;
	public $transaction_read_only_supported = true;
	public $super_read_only = 0;
	public $super_read_only_supported = true;
	public $live_write_proof_failure = false;
	public $live_rollback_failure = false;
	public $live_profile_fail_on_read = 0;
	public $native_write_proof_failure = false;
	public $lock_contention = false;
	public $invariant_write_failure = false;
	public $single_case_mismatch = false;
	public $live_connection_type = 'SSL/TLS';
	public $native_connection_type = 'SSL/TLS';
	public $live_ssl_cipher = 'TLS_AES_256_GCM_SHA384';
	public $native_ssl_cipher = 'TLS_AES_256_GCM_SHA384';
	public $mismatch = false;
	public $connection_loss = false;
	public $reconnect_fault = '';
	public $watchdog_visibility_fail_on_bundle = 0;
	public $native_no_reconnect_verified = true;
	public $watchdog_kill = false;
	public $cleanup_uncertainty = false;
	public $provision_crash = false;
	public $provision_write_count = 0;
	public $fail_dml = '';
	public $active_plugins = array(
		'lunara-core/lunara-core.php',
		'example-one/example-one.php',
		'example-two/example-two.php',
	);
	public $active_plugins_autoload = 'yes';

	public function __construct( $case ) {
		$core   = serialize( lunara_coordinator_core_clear_record() );
		$legacy = serialize( lunara_coordinator_legacy_clear_record() );
		$marker = serialize( lunara_coordinator_marker_record() );
		if ( in_array( $case, array( 'legacy_adoption', 'provision_crash' ), true ) ) {
			$this->seed_raw( self::ROW_OPTION, $legacy, 'no' );
		} elseif ( ! in_array( $case, array( 'first_install', 'ready_hooks', 'lease_api', 'nested_and_failed_dml', 'late_load', 'misordered_load', 'api_unavailable', 'ordering_repair', 'db_dropin', 'non_stock_wpdb', 'non_innodb', 'read_only', 'session_mismatch', 'connection_loss', 'migration_reconnect_after_start', 'migration_transaction_loss_after_write', 'migration_reconnect_cleanup_uncertainty', 'watchdog_attestation_bootstrap', 'watchdog_kill', 'cleanup_uncertainty' ), true ) ) {
			$this->seed_raw( self::ROW_OPTION, $core, 'no' );
			$this->seed_raw( self::MARKER_OPTION, $marker, 'no' );
		}

		if ( 'existing_install' === $case ) {
			$this->rows = array();
			$this->next_option_id = 1;
			$this->seed_raw( self::ROW_OPTION, $core, 'no' );
			$this->seed_raw( self::MARKER_OPTION, $marker, 'no' );
		}
		if ( 'marker_loss' === $case ) {
			unset( $this->rows[ self::MARKER_OPTION ] );
		}
		if ( 'marker_corruption' === $case ) {
			$this->rows[ self::MARKER_OPTION ]['option_value'] = serialize( array( 'schema_version' => 99 ) );
		}
		if ( 'row_loss' === $case ) {
			unset( $this->rows[ self::ROW_OPTION ] );
		}
		if ( 'row_corruption' === $case ) {
			$this->rows[ self::ROW_OPTION ]['option_value'] = serialize( array( 'schema_version' => 2, 'state' => 'active' ) );
		}
		if ( 'wrong_autoload' === $case ) {
			$this->rows[ self::ROW_OPTION ]['autoload'] = 'yes';
		}
		if ( 'non_innodb' === $case ) {
			$this->engine = 'MyISAM';
		}
		if ( 'read_only' === $case ) {
			$this->read_only = 1;
		}
		if ( 'live_autocommit' === $case ) {
			$this->live_autocommit = 0;
		}
		if ( 'live_transaction_before_bootstrap' === $case ) {
			$this->live_in_transaction = 1;
		}
		if ( 'transaction_read_only' === $case ) {
			$this->live_transaction_read_only   = 1;
			$this->native_transaction_read_only = 1;
		}
		if ( 'tx_read_only_fallback' === $case ) {
			$this->transaction_read_only_supported = false;
		}
		if ( 'super_read_only' === $case ) {
			$this->super_read_only = 1;
		}
		if ( 'super_read_only_unavailable' === $case ) {
			$this->super_read_only_supported = false;
		}
		if ( 'transport_mismatch' === $case ) {
			$this->native_connection_type = 'TCP/IP';
			$this->native_ssl_cipher      = '';
		}
		if ( 'ssl_downgrade' === $case ) {
			$this->native_ssl_cipher = '';
		}
		if ( 'live_write_proof_failure' === $case ) {
			$this->live_write_proof_failure = true;
		}
		if ( 'live_proof_rollback_failure' === $case ) {
			$this->live_rollback_failure = true;
		}
		if ( 'live_proof_post_profile_failure' === $case ) {
			$this->live_profile_fail_on_read = 2;
		}
		if ( 'native_write_proof_failure' === $case ) {
			$this->native_write_proof_failure = true;
		}
		if ( 'session_mismatch' === $case ) {
			$this->mismatch = true;
		}
		if ( 'connection_loss' === $case ) {
			$this->connection_loss = true;
		}
		if ( 'migration_reconnect_after_start' === $case ) {
			$this->reconnect_fault = 'migration-after-start';
		}
		if ( 'migration_transaction_loss_after_write' === $case ) {
			$this->reconnect_fault = 'migration-after-write';
		}
		if ( 'migration_reconnect_cleanup_uncertainty' === $case ) {
			$this->reconnect_fault     = 'migration-after-start';
			$this->cleanup_uncertainty = true;
		}
		if ( 'acquire_reconnect_after_lock' === $case ) {
			$this->reconnect_fault = 'acquire-after-lock';
		}
		if ( 'acquire_transaction_loss_after_cas' === $case ) {
			$this->reconnect_fault = 'acquire-after-cas';
		}
		if ( 'acquire_reconnect_cleanup_uncertainty' === $case ) {
			$this->reconnect_fault = 'acquire-after-lock';
		}
		if ( 'watchdog_attestation_bootstrap' === $case ) {
			$this->watchdog_visibility_fail_on_bundle = 1;
		}
		if ( 'watchdog_kill' === $case ) {
			$this->watchdog_kill = true;
		}
		if ( 'provision_crash' === $case ) {
			$this->provision_crash = true;
		}
		if ( 'late_load' === $case ) {
			$GLOBALS['lunara_coordinator_did_actions']['plugin_loaded'] = 1;
		}
		if ( in_array( $case, array( 'misordered_load', 'ordering_repair' ), true ) ) {
			$this->active_plugins = array(
				'example-one/example-one.php',
				'lunara-core/lunara-core.php',
				'example-two/example-two.php',
				'example-three/example-three.php',
			);
		}
	}

	public function seed_raw( $name, $value, $autoload ) {
		$this->rows[ $name ] = array(
			'option_id'    => (string) $this->next_option_id++,
			'option_name'  => $name,
			'option_value' => $value,
			'autoload'     => $autoload,
		);
	}

	public function set_value( $name, $value, $autoload = 'yes' ) {
		if ( isset( $this->rows[ $name ] ) ) {
			$this->rows[ $name ]['option_value'] = serialize( $value );
			$this->rows[ $name ]['autoload']     = $autoload;
			return;
		}
		$this->seed_raw( $name, serialize( $value ), $autoload );
	}

	public function value( $name, $default = false ) {
		if ( ! isset( $this->rows[ $name ] ) ) {
			return $default;
		}
		return unserialize( $this->rows[ $name ]['option_value'], array( 'allowed_classes' => false ) );
	}

	public function bundle() {
		++$this->bundle_count;
		$writer   = new Lunara_Coordinator_Test_Session( $this, 'writer', $this->next_connection_id++, $this->bundle_count );
		$verifier = new Lunara_Coordinator_Test_Session( $this, 'verifier', $this->next_connection_id++, $this->bundle_count );
		$watchdog = new Lunara_Coordinator_Test_Session( $this, 'watchdog', $this->next_connection_id++, $this->bundle_count );
		return array(
			'writer'       => $writer,
			'verifier'     => $verifier,
			'watchdog'     => $watchdog,
			'contract'     => 'lunara-core-theme-mods-test-v1',
		);
	}

	public function connection_open( $id ) {
		return ! empty( $this->connections[ $id ] );
	}

	public function kill( $id ) {
		$this->events[] = array( 'kill', $id );
		if ( $this->cleanup_uncertainty ) {
			return;
		}
		$this->connections[ $id ] = false;
		if ( $this->lock_owner === $id ) {
			$this->lock_owner = null;
		}
	}

	/** Model only the database effects PHP request teardown supplies. */
	public function model_request_teardown() {
		foreach ( array_keys( $this->connections ) as $id ) {
			$this->connections[ $id ] = false;
		}
		$this->lock_owner          = null;
		$this->live_in_transaction = 0;
		$this->events[]           = array( 'modeled-request-teardown' );
	}
}

final class Lunara_Coordinator_Test_Session {
	public $options = 'wp_options';
	public $prefix  = 'wp_';
	public $store;
	public $role;
	public $connection_id;
	public $bundle_number;
	public $transaction = false;
	public $profile_transaction_override = null;
	public $start_count = 0;
	public $working_rows = array();
	public $dirty_names = array();
	public $terminated = false;

	private $no_reconnect_verified;

	public function __construct( $store, $role, $connection_id, $bundle_number ) {
		$this->store         = $store;
		$this->role          = $role;
		$this->connection_id = $connection_id;
		$this->bundle_number = $bundle_number;
		$this->no_reconnect_verified = true === $store->native_no_reconnect_verified;
		$this->store->connections[ $connection_id ] = true;
	}

	public function lunara_core_theme_mods_raw_contract_version() {
		return 1;
	}

	public function lunara_core_theme_mods_no_reconnect_verified() {
		return $this->no_reconnect_verified;
	}

	private function change_connection_identity( $fault ) {
		$old_id = $this->connection_id;
		$new_id = $this->store->next_connection_id++;
		$this->store->connections[ $old_id ] = false;
		$this->store->connections[ $new_id ] = true;
		$this->connection_id = $new_id;
		if ( $this->store->lock_owner === $old_id ) {
			$this->store->lock_owner = $new_id;
		}
		$this->store->events[] = array( 'injected-connection-change', $fault, $old_id, $new_id );
	}

	private function rows() {
		return $this->transaction ? $this->working_rows : $this->store->rows;
	}

	private function option_row( $name ) {
		$rows = $this->rows();
		if ( ! isset( $rows[ $name ] ) ) {
			return null;
		}
		$row = $rows[ $name ];
		if ( $this->store->single_case_mismatch && Lunara_Coordinator_Test_Store::ROW_OPTION === $name ) {
			$row['option_name'] = strtoupper( $name );
		}
		return $row;
	}

	public function lunara_core_theme_mods_raw_rows( $sql, $params = array() ) {
		$this->store->events[] = array( 'sql-rows', $sql, $params );
		if ( false !== strpos( $sql, 'information_schema.PROCESSLIST' ) ) {
			if ( 'watchdog' === $this->role && $this->bundle_number === $this->store->watchdog_visibility_fail_on_bundle ) {
				throw new Lunara_Core_Theme_Mods_Coordinator_Storage_Exception( 'connection' );
			}
			$rows = array();
			foreach ( $params as $id ) {
				if ( $this->store->connection_open( (int) $id ) ) {
					$rows[] = array( 'ID' => (string) (int) $id );
				}
			}
			usort( $rows, static function ( $left, $right ) { return (int) $left['ID'] <=> (int) $right['ID']; } );
			return $rows;
		}
		if ( false !== strpos( $sql, 'option_name IN' ) ) {
			if ( false !== stripos( $sql, 'FOR UPDATE' ) ) {
				if ( ! $this->transaction || null !== $this->store->lock_owner ) {
					throw new RuntimeException( 'lock unavailable secret exception' );
				}
				$this->store->lock_owner = $this->connection_id;
				$this->store->events[]   = array( 'lock', array_values( $params ), $this->connection_id );
			}
			$rows = array();
			foreach ( $params as $name ) {
				$row = $this->option_row( $name );
				if ( null !== $row ) {
					$rows[] = $row;
				}
			}
			usort( $rows, static function ( $left, $right ) { return strcmp( $left['option_name'], $right['option_name'] ); } );
			return $rows;
		}
		throw new RuntimeException( 'unexpected rows query' );
	}

	public function lunara_core_theme_mods_raw_row( $sql, $params = array() ) {
		$this->store->events[] = array( 'sql-row', $sql, $params );
		if ( false !== strpos( $sql, 'AS coordinator_connection_id' ) ) {
			return array(
				'coordinator_connection_id'  => (string) $this->connection_id,
				'coordinator_autocommit'     => '1',
				'coordinator_in_transaction' => null === $this->profile_transaction_override ? ( $this->transaction ? '1' : '0' ) : (string) $this->profile_transaction_override,
			);
		}
		if ( false !== strpos( $sql, 'SELECT CONNECTION_ID()' ) ) {
			if ( false !== strpos( $sql, '@@session.in_transaction' ) ) {
				return array(
					'connection_id'   => (string) $this->connection_id,
					'database_name'   => 'lunara_test',
					'autocommit'      => '1',
					'in_transaction'  => null === $this->profile_transaction_override ? ( $this->transaction ? '1' : '0' ) : (string) $this->profile_transaction_override,
					'read_only'       => (string) $this->store->read_only,
					'server_hostname' => $this->store->mismatch && 'verifier' === $this->role ? 'different-primary.internal' : 'secret-primary.internal',
					'server_port'     => '3306',
					'server_id'       => '73',
					'current_user'    => 'secret_core_user@%',
				);
			}
			return array(
				'connection_id'   => (string) $this->connection_id,
				'database_name'   => 'lunara_test',
				'autocommit'      => '1',
				'read_only'       => (string) $this->store->read_only,
				'server_hostname' => $this->store->mismatch && 'verifier' === $this->role ? 'different-primary.internal' : 'secret-primary.internal',
				'server_port'     => '3306',
				'server_id'       => '73',
				'current_user'    => 'secret_core_user@%',
			);
		}
		if ( false !== strpos( $sql, '@@session.transaction_read_only' ) || false !== strpos( $sql, '@@session.tx_read_only' ) ) {
			if ( false !== strpos( $sql, '@@session.transaction_read_only' ) && ! $this->store->transaction_read_only_supported ) {
				throw new RuntimeException( 'unsupported transaction_read_only' );
			}
			return array( 'transaction_read_only' => (string) $this->store->native_transaction_read_only );
		}
		if ( false !== strpos( $sql, "SHOW GLOBAL VARIABLES LIKE 'super_read_only'" ) ) {
			if ( ! $this->store->super_read_only_supported ) {
				return null;
			}
			return array( 'Variable_name' => 'super_read_only', 'Value' => $this->store->super_read_only ? 'ON' : 'OFF' );
		}
		if ( false !== strpos( $sql, "SHOW SESSION STATUS LIKE 'Ssl_cipher'" ) ) {
			return array( 'Variable_name' => 'Ssl_cipher', 'Value' => $this->store->native_ssl_cipher );
		}
		if ( false !== strpos( $sql, 'performance_schema.threads' ) ) {
			return array( 'connection_type' => $this->store->native_connection_type );
		}
		if ( false !== strpos( $sql, 'information_schema.TABLES' ) ) {
			return array(
				'TABLE_SCHEMA' => 'lunara_test',
				'TABLE_NAME'   => 'wp_options',
				'ENGINE'       => $this->store->engine,
			);
		}
		if ( false !== strpos( $sql, 'information_schema.PROCESSLIST' ) ) {
			$id = (int) $params[0];
			return $this->store->connection_open( $id ) ? array( 'ID' => (string) $id ) : null;
		}
		if ( false !== strpos( $sql, 'WHERE option_name = ?' ) ) {
			if ( false !== stripos( $sql, 'FOR UPDATE' ) ) {
				if ( $this->store->lock_contention ) {
					throw new Lunara_Core_Theme_Mods_Coordinator_Storage_Exception( 'contention' );
				}
				if ( ! $this->transaction || ( null !== $this->store->lock_owner && $this->store->lock_owner !== $this->connection_id ) ) {
					throw new RuntimeException( 'row lock unavailable' );
				}
				$this->store->lock_owner = $this->connection_id;
				$this->store->events[]   = array( 'lock-one', $params[0], $this->connection_id );
				if ( 2 === $this->bundle_number && 'acquire-after-lock' === $this->store->reconnect_fault ) {
					$this->change_connection_identity( 'acquire-after-lock' );
				}
			}
			return $this->option_row( $params[0] );
		}
		throw new RuntimeException( 'unexpected row query' );
	}

	public function lunara_core_theme_mods_raw_exec( $sql, $params = array() ) {
		$this->store->events[] = array( 'sql-exec', $sql, $params );
		if ( 'SET SESSION innodb_lock_wait_timeout = 3' === $sql || 'SET SESSION lock_wait_timeout = 3' === $sql ) {
			return array( 'affected_rows' => 0, 'insert_id' => '' );
		}
		if ( 'START TRANSACTION' === $sql ) {
			if ( $this->transaction ) {
				throw new RuntimeException( 'nested transaction' );
			}
			$this->transaction = true;
			++$this->start_count;
			$this->working_rows = $this->store->rows;
			$this->dirty_names = array();
			$this->store->events[] = array( 'start', $this->role, $this->connection_id );
			if ( 1 === $this->bundle_number && 'writer' === $this->role && 2 === $this->start_count && 'migration-after-start' === $this->store->reconnect_fault ) {
				$this->change_connection_identity( 'migration-after-start' );
			}
			return array( 'affected_rows' => 0, 'insert_id' => '' );
		}
		if ( 'COMMIT' === $sql ) {
			if ( ! $this->transaction ) {
				throw new RuntimeException( 'commit without transaction' );
			}
			foreach ( array_keys( $this->dirty_names ) as $name ) {
				if ( isset( $this->working_rows[ $name ] ) ) {
					$this->store->rows[ $name ] = $this->working_rows[ $name ];
				} else {
					unset( $this->store->rows[ $name ] );
				}
			}
			$this->transaction = false;
			$this->profile_transaction_override = null;
			$this->working_rows = array();
			$this->dirty_names = array();
			if ( $this->store->lock_owner === $this->connection_id ) {
				$this->store->lock_owner = null;
			}
			$this->store->events[] = array( 'commit', $this->role, $this->connection_id );
			return array( 'affected_rows' => 0, 'insert_id' => '' );
		}
		if ( 'ROLLBACK' === $sql ) {
			$this->transaction = false;
			$this->profile_transaction_override = null;
			$this->working_rows = array();
			$this->dirty_names = array();
			if ( $this->store->lock_owner === $this->connection_id ) {
				$this->store->lock_owner = null;
			}
			$this->store->events[] = array( 'rollback', $this->role, $this->connection_id );
			return array( 'affected_rows' => 0, 'insert_id' => '' );
		}
		if ( 0 === strpos( $sql, 'KILL CONNECTION ' ) ) {
			$this->store->kill( (int) substr( $sql, strlen( 'KILL CONNECTION ' ) ) );
			return array( 'affected_rows' => 0, 'insert_id' => '' );
		}
		if ( false !== strpos( $sql, 'SET option_value = option_value' ) && false !== strpos( $sql, 'option_id = 0' ) ) {
			if ( ! $this->transaction || $this->store->native_write_proof_failure ) {
				throw new RuntimeException( 'bounded fake native write denial' );
			}
			return array( 'affected_rows' => 0, 'insert_id' => '' );
		}
		if ( false !== strpos( $sql, 'INSERT INTO' ) ) {
			if ( ! $this->transaction || 3 !== count( $params ) || isset( $this->working_rows[ $params[0] ] ) ) {
				throw new RuntimeException( 'invalid insert' );
			}
			$this->working_rows[ $params[0] ] = array(
				'option_id'    => (string) $this->store->next_option_id++,
				'option_name'  => $params[0],
				'option_value' => $params[1],
				'autoload'     => $params[2],
			);
			$this->dirty_names[ $params[0] ] = true;
			$this->store->events[] = array( 'insert', $params[0], $this->transaction );
			++$this->store->provision_write_count;
			if ( 1 === $this->bundle_number && 'migration-after-write' === $this->store->reconnect_fault && 1 === $this->store->provision_write_count ) {
				$this->profile_transaction_override = 0;
				$this->store->events[] = array( 'injected-transaction-change', 'migration-after-write', $this->connection_id );
			}
			if ( $this->store->provision_crash && 1 === $this->store->provision_write_count ) {
				throw new RuntimeException( 'secret crash after first migration write' );
			}
			return array( 'affected_rows' => 1, 'insert_id' => $this->working_rows[ $params[0] ]['option_id'] );
		}
		if ( false !== strpos( $sql, 'UPDATE' ) && false !== strpos( $sql, 'option_value = ?' ) ) {
			if ( ! $this->transaction || 6 !== count( $params ) ) {
				throw new RuntimeException( 'invalid update' );
			}
			list( $new_value, $option_id, $name, $exact_name, $old_value, $autoload ) = $params;
			if ( $name !== $exact_name || ! isset( $this->working_rows[ $name ] ) ) {
				return array( 'affected_rows' => 0, 'insert_id' => '' );
			}
			if ( $this->store->invariant_write_failure && Lunara_Coordinator_Test_Store::ROW_OPTION === $name && false !== strpos( $new_value, 'active' ) ) {
				return array( 'affected_rows' => 0, 'insert_id' => '' );
			}
			$row = $this->working_rows[ $name ];
			if ( (string) $row['option_id'] !== (string) $option_id || $row['option_value'] !== $old_value || $row['autoload'] !== $autoload ) {
				return array( 'affected_rows' => 0, 'insert_id' => '' );
			}
			if ( $this->store->connection_loss && Lunara_Coordinator_Test_Store::ROW_OPTION === $name && false !== strpos( $new_value, 'active' ) ) {
				$this->transaction = false;
				$this->working_rows = array();
				$this->store->connections[ $this->connection_id ] = false;
				$this->store->lock_owner = null;
				throw new RuntimeException( 'secret connection lost' );
			}
			$this->working_rows[ $name ]['option_value'] = $new_value;
			$this->dirty_names[ $name ] = true;
			$this->store->events[] = array( 'update-row', $name, $this->transaction );
			++$this->store->provision_write_count;
			if ( 2 === $this->bundle_number && 'acquire-after-cas' === $this->store->reconnect_fault && Lunara_Coordinator_Test_Store::ROW_OPTION === $name && false !== strpos( $new_value, 'active' ) ) {
				$this->profile_transaction_override = 0;
				$this->store->events[] = array( 'injected-transaction-change', 'acquire-after-cas', $this->connection_id );
			}
			if ( $this->store->provision_crash && 1 === $this->store->provision_write_count ) {
				throw new RuntimeException( 'secret crash after first migration write' );
			}
			return array( 'affected_rows' => 1, 'insert_id' => '' );
		}
		throw new RuntimeException( 'unexpected exec query' );
	}

	public function lunara_core_theme_mods_terminate_verified() {
		if ( $this->terminated ) {
			return true;
		}
		if ( ( $this->store->watchdog_kill || $this->store->cleanup_uncertainty ) && 'writer' === $this->role ) {
			$this->store->events[] = array( 'close-failed', $this->role, $this->connection_id );
			return false;
		}
		$this->terminated = true;
		$this->store->connections[ $this->connection_id ] = false;
		if ( $this->store->lock_owner === $this->connection_id ) {
			$this->store->lock_owner = null;
		}
		$this->store->events[] = array( 'close', $this->role, $this->connection_id );
		return true;
	}
}

$GLOBALS['lunara_coordinator_actions']     = array();
$GLOBALS['lunara_coordinator_action_seq']  = 0;
$GLOBALS['lunara_coordinator_did_actions'] = isset( $GLOBALS['lunara_coordinator_did_actions'] ) ? $GLOBALS['lunara_coordinator_did_actions'] : array();
$GLOBALS['lunara_coordinator_store']       = new Lunara_Coordinator_Test_Store( $lunara_coordinator_case );
$GLOBALS['wpdb'] = 'non_stock_wpdb' === $lunara_coordinator_case ? new Lunara_Coordinator_Custom_WPDB() : new wpdb();

function plugin_basename( $file ) {
	return 'lunara-core/lunara-core.php';
}

function add_action( $hook, $callback, $priority = 10, $accepted_args = 1 ) {
	$GLOBALS['lunara_coordinator_actions'][ $hook ][] = array(
		'callback'      => $callback,
		'priority'      => $priority,
		'accepted_args' => $accepted_args,
		'sequence'      => $GLOBALS['lunara_coordinator_action_seq']++,
	);
}

function do_action( $hook ) {
	$args = array_slice( func_get_args(), 1 );
	$GLOBALS['lunara_coordinator_did_actions'][ $hook ] = isset( $GLOBALS['lunara_coordinator_did_actions'][ $hook ] )
		? $GLOBALS['lunara_coordinator_did_actions'][ $hook ] + 1
		: 1;
	$callbacks = isset( $GLOBALS['lunara_coordinator_actions'][ $hook ] ) ? $GLOBALS['lunara_coordinator_actions'][ $hook ] : array();
	usort(
		$callbacks,
		static function ( $left, $right ) {
			return $left['priority'] === $right['priority']
				? $left['sequence'] <=> $right['sequence']
				: $left['priority'] <=> $right['priority'];
		}
	);
	foreach ( $callbacks as $registered ) {
		call_user_func_array( $registered['callback'], array_slice( $args, 0, $registered['accepted_args'] ) );
	}
}

function did_action( $hook ) {
	return isset( $GLOBALS['lunara_coordinator_did_actions'][ $hook ] ) ? $GLOBALS['lunara_coordinator_did_actions'][ $hook ] : 0;
}

function get_option( $name, $default = false ) {
	$store = $GLOBALS['lunara_coordinator_store'];
	if ( 'active_plugins' === $name ) {
		return $store->active_plugins;
	}
	return $store->value( $name, $default );
}

function update_option( $name, $value, $autoload = null ) {
	$store = $GLOBALS['lunara_coordinator_store'];
	if ( 'active_plugins' === $name ) {
		if ( $store->active_plugins === $value ) {
			return false;
		}
		$old = $store->active_plugins;
		do_action( 'update_option', $name, $old, $value );
		$store->events[] = array( 'dml', 'update', $name, null !== $store->lock_owner );
		$store->active_plugins = $value;
		if ( 3 <= func_num_args() && null !== $autoload ) {
			$store->active_plugins_autoload = is_bool( $autoload ) ? ( $autoload ? 'yes' : 'no' ) : (string) $autoload;
		}
		do_action( 'updated_option', $name, $old, $value );
		return true;
	}
	$present = isset( $store->rows[ $name ] );
	$old     = $store->value( $name, false );
	if ( $present && $old === $value ) {
		return false;
	}
	if ( ! $present ) {
		return add_option( $name, $value, '', null === $autoload ? 'yes' : $autoload );
	}
	do_action( 'update_option', $name, $old, $value );
	$store->events[] = array( 'dml', 'update', $name, null !== $store->lock_owner );
	if ( 'update' === $store->fail_dml ) {
		$store->fail_dml = '';
		return false;
	}
	$store->set_value( $name, $value, $store->rows[ $name ]['autoload'] );
	do_action( 'updated_option', $name, $old, $value );
	$store->events[] = array( 'after-post', 'update', $name, null !== $store->lock_owner );
	return true;
}

function add_option( $name, $value = '', $deprecated = '', $autoload = 'yes' ) {
	$store = $GLOBALS['lunara_coordinator_store'];
	if ( isset( $store->rows[ $name ] ) ) {
		return false;
	}
	do_action( 'add_option', $name, $value );
	$store->events[] = array( 'dml', 'add', $name, null !== $store->lock_owner );
	if ( 'add' === $store->fail_dml ) {
		$store->fail_dml = '';
		return false;
	}
	$store->set_value( $name, $value, is_bool( $autoload ) ? ( $autoload ? 'yes' : 'no' ) : (string) $autoload );
	do_action( 'added_option', $name, $value );
	$store->events[] = array( 'after-post', 'add', $name, null !== $store->lock_owner );
	return true;
}

function delete_option( $name ) {
	$store = $GLOBALS['lunara_coordinator_store'];
	if ( ! isset( $store->rows[ $name ] ) ) {
		return false;
	}
	do_action( 'delete_option', $name );
	$store->events[] = array( 'dml', 'delete', $name, null !== $store->lock_owner );
	if ( 'delete' === $store->fail_dml ) {
		$store->fail_dml = '';
		return false;
	}
	unset( $store->rows[ $name ] );
	do_action( 'deleted_option', $name );
	$store->events[] = array( 'after-post', 'delete', $name, null !== $store->lock_owner );
	return true;
}

function wp_cache_delete( $key, $group = '' ) {
	$GLOBALS['lunara_coordinator_store']->events[] = array( 'cache-delete', $key, $group );
	return true;
}

if ( 'api_unavailable' !== $lunara_coordinator_case ) {
	$GLOBALS['lunara_core_theme_mods_coordinator_test_session_factory'] = static function () {
		return $GLOBALS['lunara_coordinator_store']->bundle();
	};
}

$lunara_bootstrap_error_events = array();
if ( 'bootstrap_error_observer' === $lunara_coordinator_case ) {
	add_action(
		'wp_error_added',
		static function ( $code, $message, $data ) use ( &$lunara_bootstrap_error_events ) {
			$lunara_bootstrap_error_events[] = array( $code, $message, $data );
		},
		10,
		3
	);
}

$coordinator_file = dirname( __DIR__ ) . '/includes/class-lunara-core-theme-mods-coordinator.php';
lunara_coordinator_assert( file_exists( $coordinator_file ), 'Core must ship the early theme-mod coordinator module.' );
require $coordinator_file;
$lunara_coordinator_bootstrap_error = null;
try {
	Lunara_Core_Theme_Mods_Coordinator::bootstrap();
} catch ( Throwable $error ) {
	$lunara_coordinator_bootstrap_error = $error;
}

$store  = $GLOBALS['lunara_coordinator_store'];
$status = lunara_core_theme_mods_coordinator_status();

if ( 'bootstrap_error_observer' === $lunara_coordinator_case ) {
	lunara_coordinator_assert_same(
		array( 'api_version' => 1, 'plugin_version' => '0.8.11', 'ready' => true, 'reason' => 'ready', 'held' => false ),
		$status,
		'The observer case must complete a successful ordinary bootstrap.'
	);
	lunara_coordinator_assert_same( array(), $lunara_bootstrap_error_events, 'Prototype preparation must emit zero false wp_error_added events to an observer installed before bootstrap.' );
	lunara_coordinator_assert_same( 0, did_action( 'wp_error_added' ), 'Successful bootstrap must not pollute the global wp_error_added action count.' );
	$error = lunara_core_theme_mods_coordinator_acquire( 'ordinary_option', 'observer-check' );
	lunara_coordinator_assert( $error instanceof WP_Error, 'A prepared bounded prototype must still return a real WP_Error.' );
	lunara_coordinator_assert_same( 'lunara_core_theme_mods_invalid_option', $error->get_error_code(), 'The side-effect-free prototype must preserve its exact bounded code.' );
	lunara_coordinator_assert_same( '', $error->get_error_message(), 'The side-effect-free prototype must preserve an empty public message.' );
	lunara_coordinator_assert_same( null, $error->get_error_data(), 'The side-effect-free prototype must preserve empty public data.' );
	lunara_coordinator_assert_same( array(), $lunara_bootstrap_error_events, 'Cloning a prepared bounded error must not invoke the observer.' );
	echo "bootstrap_error_observer passed.\n";
	exit( 0 );
}

function lunara_coordinator_assert_denied_before_dml( $operation, $option ) {
	$store  = $GLOBALS['lunara_coordinator_store'];
	$events = count( $store->events );
	$caught = null;
	try {
		if ( 'update' === $operation ) {
			$store->set_value( $option, array( 'before' => true ) );
			update_option( $option, array( 'after' => true ) );
		} elseif ( 'add' === $operation ) {
			add_option( $option, array( 'after' => true ) );
		} else {
			$store->set_value( $option, array( 'before' => true ) );
			delete_option( $option );
		}
	} catch ( Throwable $error ) {
		$caught = $error;
	}
	lunara_coordinator_assert( $caught instanceof Lunara_Core_Theme_Mods_Coordinator_Exception, 'Matched ' . $operation . ' must throw the dedicated coordinator exception before DML.' );
	foreach ( array_slice( $store->events, $events ) as $event ) {
		lunara_coordinator_assert( 'dml' !== $event[0], 'Denied ' . $operation . ' must never reach WordPress DML.' );
	}
}

if ( in_array( $lunara_coordinator_case, array( 'first_install', 'legacy_adoption', 'existing_install' ), true ) ) {
	lunara_coordinator_assert_same(
		array(
			'api_version'    => 1,
			'plugin_version' => '0.8.11',
			'ready'          => true,
			'reason'         => 'ready',
			'held'           => false,
		),
		$status,
		'An eligible complete Core installation must expose only the exact redacted ready status.'
	);
	lunara_coordinator_assert_same( lunara_coordinator_core_clear_record(), $store->value( Lunara_Coordinator_Test_Store::ROW_OPTION ), 'Provisioning must leave the permanent schema-2 Core clear row.' );
	lunara_coordinator_assert_same( lunara_coordinator_marker_record(), $store->value( Lunara_Coordinator_Test_Store::MARKER_OPTION ), 'Provisioning must leave the exact durable versioned marker.' );
	lunara_coordinator_assert( in_array( $store->rows[ Lunara_Coordinator_Test_Store::ROW_OPTION ]['autoload'], array( 'no', 'off', 'auto-off' ), true ), 'The permanent coordinator row must remain nonautoloaded.' );
	lunara_coordinator_assert( in_array( $store->rows[ Lunara_Coordinator_Test_Store::MARKER_OPTION ]['autoload'], array( 'no', 'off', 'auto-off' ), true ), 'The coordinator marker must remain nonautoloaded.' );
	lunara_coordinator_assert_same( array(), array_filter( $store->connections ), 'Provisioning/verification must positively terminate every native session.' );
	lunara_coordinator_assert_same(
		array(),
		array_values( array_filter( $store->events, static function ( $event ) { return 'cache-delete' === $event[0]; } ) ),
		'Core coordination must not own cache invalidation, even for its private storage options.'
	);
	$locks = array_values( array_filter( $store->events, static function ( $event ) { return 'lock' === $event[0]; } ) );
	lunara_coordinator_assert( ! empty( $locks ), 'Provisioning/verification must physically lock the row and marker names.' );
	$locked_names = $locks[0][1];
	sort( $locked_names );
	$expected_names = array( Lunara_Coordinator_Test_Store::MARKER_OPTION, Lunara_Coordinator_Test_Store::ROW_OPTION );
	sort( $expected_names );
	lunara_coordinator_assert_same( $expected_names, $locked_names, 'Provisioning must lock both exact option names in one transaction.' );
	if ( 'existing_install' === $lunara_coordinator_case ) {
		$writes = array_filter( $store->events, static function ( $event ) { return in_array( $event[0], array( 'insert', 'update-row' ), true ); } );
		lunara_coordinator_assert_same( array(), array_values( $writes ), 'A complete existing install must be verified without being rewritten.' );
	}
	echo $lunara_coordinator_case . " passed.\n";
	exit( 0 );
}

if ( 'sql_shape' === $lunara_coordinator_case ) {
	lunara_coordinator_assert_same( true, $status['ready'], 'The SQL-shape case requires canonical ready storage.' );
	$bootstrap_locks = array_values(
		array_filter(
			$store->events,
			static function ( $event ) {
				return 'sql-rows' === $event[0] && false !== strpos( $event[1], 'option_name IN' ) && false !== strpos( $event[1], 'FOR UPDATE' );
			}
		)
	);
	lunara_coordinator_assert_same( 1, count( $bootstrap_locks ), 'Bootstrap must use one two-name locking read.' );
	lunara_coordinator_assert( false !== strpos( $bootstrap_locks[0][1], 'WHERE option_name IN (?, ?)' ), 'The two-name locking read must lead with the existing option_name index predicate.' );
	lunara_coordinator_assert( false === strpos( $bootstrap_locks[0][1], 'BINARY option_name' ), 'The two-name locking read must not cast the indexed option_name column.' );
	lunara_coordinator_assert_same(
		array( Lunara_Coordinator_Test_Store::ROW_OPTION, Lunara_Coordinator_Test_Store::MARKER_OPTION ),
		$bootstrap_locks[0][2],
		'The indexed bootstrap query must bind only the two exact storage names.'
	);

	$before = count( $store->events );
	$lease  = lunara_core_theme_mods_coordinator_acquire( 'theme_mods_lunara-test', 'sql-shape' );
	lunara_coordinator_assert( $lease instanceof Lunara_Core_Theme_Mods_Coordinator_Lease, 'The SQL-shape case must acquire a lease.' );
	lunara_coordinator_assert( true === lunara_core_theme_mods_coordinator_release( $lease ), 'The SQL-shape case must release its lease.' );
	$lease_events = array_slice( $store->events, $before );
	$single_locks = array_values(
		array_filter(
			$lease_events,
			static function ( $event ) {
				return 'sql-row' === $event[0] && false !== strpos( $event[1], 'option_name' ) && false !== strpos( $event[1], 'FOR UPDATE' );
			}
		)
	);
	lunara_coordinator_assert_same( 1, count( $single_locks ), 'Acquire must use one single-name locking read.' );
	lunara_coordinator_assert( false !== strpos( $single_locks[0][1], 'WHERE option_name = ?' ), 'Acquire must lead with the existing unique option_name index predicate.' );
	lunara_coordinator_assert( false === strpos( $single_locks[0][1], 'BINARY option_name' ), 'Acquire must not cast the indexed option_name column.' );

	$cas_queries = array_values(
		array_filter(
			$lease_events,
			static function ( $event ) {
				return 'sql-exec' === $event[0] && false !== strpos( $event[1], 'UPDATE wp_options SET option_value = ?' );
			}
		)
	);
	lunara_coordinator_assert_same( 2, count( $cas_queries ), 'Acquire/final release must each use one exact physical CAS.' );
	foreach ( $cas_queries as $cas ) {
		lunara_coordinator_assert( false !== strpos( $cas[1], 'WHERE option_id = ? AND option_name = ?' ), 'Physical CAS must lead with primary/unique indexed predicates.' );
		lunara_coordinator_assert( false === strpos( $cas[1], 'BINARY option_name' ), 'Physical CAS must not cast option_name.' );
		lunara_coordinator_assert( false === strpos( $cas[1], 'BINARY option_value' ), 'Physical CAS must compare bytes by casting bound values, not table columns.' );
	}
	echo "sql_shape passed.\n";
	exit( 0 );
}

if ( 'single_identity_mismatch' === $lunara_coordinator_case ) {
	lunara_coordinator_assert_same( true, $status['ready'], 'The exact-identity case must bootstrap from canonical storage.' );
	$store->single_case_mismatch = true;
	$lease = lunara_core_theme_mods_coordinator_acquire( 'theme_mods_lunara-test', 'identity-check' );
	lunara_coordinator_assert( is_wp_error( $lease ), 'A collation-equal but byte-different physical option name must fail closed after the indexed lookup.' );
	lunara_coordinator_assert_same( false, lunara_core_theme_mods_coordinator_status()['ready'], 'A byte-identity failure must mark request readiness as storage-unavailable.' );
	echo "single_identity_mismatch passed.\n";
	exit( 0 );
}

if ( in_array( $lunara_coordinator_case, array( 'tx_read_only_fallback', 'super_read_only_unavailable' ), true ) ) {
	lunara_coordinator_assert_same( true, $status['ready'], 'A supported compatibility fallback must still prove writable-primary readiness: ' . $lunara_coordinator_case );
	echo $lunara_coordinator_case . " passed.\n";
	exit( 0 );
}

if ( 'transport_contract' === $lunara_coordinator_case ) {
	lunara_coordinator_assert_same( true, $status['ready'], 'The transport contract case must begin ready.' );
	$parser = new ReflectionMethod( 'Lunara_Core_Theme_Mods_Coordinator', 'parse_db_host' );
	$parser->setAccessible( true );
	$settings = new ReflectionMethod( 'Lunara_Core_Theme_Mods_Coordinator', 'native_connection_parameters' );
	$settings->setAccessible( true );
	$forms = array(
		'db.internal' => array( 'host' => 'db.internal', 'port' => null, 'socket' => null, 'is_ipv6' => false ),
		'db.internal:3307' => array( 'host' => 'db.internal', 'port' => 3307, 'socket' => null, 'is_ipv6' => false ),
		'db.internal:3307:/var/run/mysql.sock' => array( 'host' => 'db.internal', 'port' => 3307, 'socket' => '/var/run/mysql.sock', 'is_ipv6' => false ),
		'localhost:/var/run/mysql.sock' => array( 'host' => 'localhost', 'port' => null, 'socket' => '/var/run/mysql.sock', 'is_ipv6' => false ),
		'[2001:db8::1]:3307' => array( 'host' => '2001:db8::1', 'port' => 3307, 'socket' => null, 'is_ipv6' => true ),
		'2001:db8::1' => array( 'host' => '2001:db8::1', 'port' => null, 'socket' => null, 'is_ipv6' => true ),
		'[2001:db8::1]:3307:/var/run/mysql.sock' => array( 'host' => '2001:db8::1', 'port' => 3307, 'socket' => '/var/run/mysql.sock', 'is_ipv6' => true ),
	);
	foreach ( $forms as $form => $expected ) {
		lunara_coordinator_assert_same( $expected, $parser->invoke( null, $form ), 'Core must mirror the supported stock WordPress DB_HOST form: ' . $form );
	}
	foreach ( array( '', 'db.internal:not-a-port', 'db.internal:70000', '[]:3306', '[2001:db8::1', 'db.internal:3306:relative.sock' ) as $ambiguous ) {
		lunara_coordinator_assert_same( false, $parser->invoke( null, $ambiguous ), 'Unsupported or ambiguous DB_HOST must fail closed: ' . $ambiguous );
	}
	lunara_coordinator_assert_same(
		array( 'host' => '[2001:db8::1]', 'port' => 3307, 'socket' => null, 'client_flags' => 2048 ),
		$settings->invoke( null, '[2001:db8::1]:3307', true, 'SSL/TLS' ),
		'mysqlnd must receive bracketed IPv6 plus the exact WordPress client flags.'
	);
	lunara_coordinator_assert_same(
		array( 'host' => '2001:db8::1', 'port' => 3307, 'socket' => null, 'client_flags' => 2048 ),
		$settings->invoke( null, '[2001:db8::1]:3307', false, 'SSL/TLS' ),
		'libmysqlclient must receive unbracketed IPv6 plus the exact WordPress client flags.'
	);
	echo "transport_contract passed.\n";
	exit( 0 );
}

if ( 'ssl_flag_guard' === $lunara_coordinator_case ) {
	$settings = new ReflectionMethod( 'Lunara_Core_Theme_Mods_Coordinator', 'native_connection_parameters' );
	$settings->setAccessible( true );
	lunara_coordinator_assert_same( false, $settings->invoke( null, 'db.internal:3306', true, 'SSL/TLS' ), 'Core must fail before opening a native connection when live wpdb is TLS but WordPress client flags cannot require TLS.' );
	$source = file_get_contents( $coordinator_file );
	lunara_coordinator_assert( false !== strpos( $source, 'open_native_sessions( $live_profile )' ), 'Native connections must be opened only after the live transport profile is known.' );
	echo "ssl_flag_guard passed.\n";
	exit( 0 );
}

if ( 'reconnect_policy' === $lunara_coordinator_case ) {
	lunara_coordinator_assert_same( true, $status['ready'], 'The reconnect-policy case must begin from canonical ready storage.' );
	$policy = new ReflectionMethod( 'Lunara_Core_Theme_Mods_Coordinator', 'reconnect_safety_fact' );
	$policy->setAccessible( true );
	$safe_cases = array(
		array( array( 80100, 'mysqlnd 8.1.0', true, true, '1' ), 'mysqlnd', 'mysqlnd-ignored' ),
		array( array( 80100, 'libmysqlclient 8.0.35', false, true, '0' ), 'libmysqlclient', 'disabled' ),
		array( array( 80200, 'libmysqlclient 8.0.35', false, false, null ), 'libmysqlclient', 'php-8.2+' ),
		array( array( 80424, 'mysqlnd 8.4.24', true, false, null ), 'mysqlnd', 'php-8.2+' ),
	);
	foreach ( $safe_cases as $safe ) {
		$fact = $policy->invokeArgs( null, $safe[0] );
		lunara_coordinator_assert( is_array( $fact ), 'A documented no-auto-reconnect runtime must produce a frozen fact.' );
		lunara_coordinator_assert_same( $safe[1], $fact['driver'], 'The reconnect fact must freeze the established mysqli driver.' );
		lunara_coordinator_assert_same( $safe[2], $fact['reconnect'], 'The reconnect fact must freeze the exact documented/configured guarantee.' );
		lunara_coordinator_assert( 1 === preg_match( '/^[a-f0-9]{64}$/D', $fact['client_info_fingerprint'] ), 'The reconnect fact must freeze a bounded client-library fingerprint.' );
	}
	$unsafe_cases = array(
		array( 80100, 'libmysqlclient 8.0.35', false, true, '1' ),
		array( 80100, 'libmysqlclient 8.0.35', false, false, null ),
		array( 80100, 'libmysqlclient 8.0.35', false, true, 'ambiguous' ),
		array( 80100, 'mysqlnd 8.1.0', false, true, '0' ),
		array( 80100, 'libmysqlclient 8.0.35', true, true, '0' ),
	);
	foreach ( $unsafe_cases as $unsafe ) {
		lunara_coordinator_assert_same( false, $policy->invokeArgs( null, $unsafe ), 'Enabled, missing, ambiguous, or driver-mismatched reconnect state must fail closed.' );
	}
	echo "reconnect_policy passed.\n";
	exit( 0 );
}

if ( 'native_adapter_rows' === $lunara_coordinator_case ) {
	lunara_coordinator_assert( class_exists( 'Lunara_Core_Theme_Mods_Native_MySQLi', false ), 'The native adapter must load against the isolated mysqli contract.' );
	$unsafe_error = null;
	try {
		new Lunara_Core_Theme_Mods_Native_MySQLi( new mysqli(), 'wp_options', 'wp_', false );
	} catch ( Throwable $error ) {
		$unsafe_error = $error;
	}
	lunara_coordinator_assert( $unsafe_error instanceof RuntimeException, 'A native adapter without a frozen no-reconnect fact must be rejected before admission.' );
	$GLOBALS['lunara_fake_mysqli_rows'] = array(
		array( 'option_id' => 7, 'option_name' => 'alpha', 'option_value' => null ),
		array( 'option_id' => 8, 'option_name' => 'beta', 'option_value' => 'value' ),
	);
	$dbh = new mysqli();
	$adapter = new Lunara_Core_Theme_Mods_Native_MySQLi(
		$dbh,
		'wp_options',
		'wp_',
		array(
			'driver'                  => 'mysqlnd',
			'php_version_id'          => 80100,
			'client_info_fingerprint' => str_repeat( 'a', 64 ),
			'reconnect'               => 'mysqlnd-ignored',
		)
	);
	lunara_coordinator_assert_same( true, $adapter->lunara_core_theme_mods_no_reconnect_verified(), 'The adapter proof must derive from the frozen verified driver/version/config fact.' );
	foreach ( array( 'get-result', 'bind-result' ) as $mode ) {
		$GLOBALS['lunara_fake_mysqli_mode'] = $mode;
		lunara_coordinator_assert_same(
			$GLOBALS['lunara_fake_mysqli_rows'],
			$adapter->lunara_core_theme_mods_raw_rows( 'SELECT option_id, option_name, option_value FROM wp_options' ),
			'Both mysqlnd get_result and libmysqlclient bind_result adapters must preserve exact associative rows: ' . $mode
		);
	}
	$GLOBALS['lunara_fake_mysqli_mode'] = 'lock-error';
	$caught = null;
	try {
		$adapter->lunara_core_theme_mods_raw_rows( 'SELECT option_id FROM wp_options WHERE option_name = ? FOR UPDATE', array( 'alpha' ) );
	} catch ( Throwable $error ) {
		$caught = $error;
	}
	lunara_coordinator_assert( $caught instanceof Lunara_Core_Theme_Mods_Coordinator_Storage_Exception, 'The native adapter must classify a database lock timeout with the bounded storage exception.' );
	lunara_coordinator_assert_same( 'contention', $caught->category(), 'The native adapter must distinguish transient lock contention from invariant/write failures.' );
	lunara_coordinator_assert( $adapter->lunara_core_theme_mods_terminate_verified(), 'The isolated native adapter must close positively.' );
	echo "native_adapter_rows passed.\n";
	exit( 0 );
}

if ( 'reporting_scope' === $lunara_coordinator_case ) {
	$source = file_get_contents( $coordinator_file );
	lunara_coordinator_assert( is_string( $source ), 'The reporting-scope contract must read the coordinator source.' );
	lunara_coordinator_assert( false === strpos( $source, 'mysqli_report(' ), 'Core must not leave or lease-scope any process-global mysqli report-mode mutation.' );
	echo "reporting_scope passed.\n";
	exit( 0 );
}

if ( 'live_probe_contract' === $lunara_coordinator_case ) {
	$source = file_get_contents( __FILE__ );
	lunara_coordinator_assert( is_string( $source ), 'The live-probe contract must read its own harness.' );
	$forbidden_argv_encoding = 'base64_' . "encode( json_encode( \$config ) )";
	lunara_coordinator_assert( false === strpos( $source, $forbidden_argv_encoding ), 'Safe live database credentials must never be placed in a child argv value.' );
	lunara_coordinator_assert( false !== strpos( $source, 'stream_get_contents( STDIN )' ), 'The safe live child must receive its restricted configuration through stdin.' );
	lunara_coordinator_assert( false !== strpos( $source, 'EXPLAIN SELECT option_id, option_name, option_value, autoload' ), 'The safe live probe must verify the exact option-name locking query uses an index.' );
	lunara_coordinator_assert( false !== strpos( $source, 'UNIQUE KEY option_name (option_name)' ), 'The safe live probe must use an options-shaped unique option_name index.' );
	lunara_coordinator_assert( false !== strpos( $source, "option_name = 'ordinary_unrelated_option'" ), 'The safe live probe must prove an unrelated options row remains writable during the exact coordinator lock.' );
	echo "live_probe_contract passed.\n";
	exit( 0 );
}

if ( 'ready_hooks' === $lunara_coordinator_case ) {
	lunara_coordinator_assert_same( true, $status['ready'], 'Core-first inclusion must be ready before a later plugin runs.' );
	lunara_coordinator_assert_same( 0, did_action( 'plugin_loaded' ), 'The fake later plugin must run before plugins_loaded/plugin_loaded coverage is lost.' );
	foreach ( array( 'update_option', 'add_option', 'delete_option', 'updated_option', 'added_option', 'deleted_option' ) as $hook ) {
		$callbacks = isset( $GLOBALS['lunara_coordinator_actions'][ $hook ] ) ? $GLOBALS['lunara_coordinator_actions'][ $hook ] : array();
		lunara_coordinator_assert_same( PHP_INT_MIN, $callbacks[0]['priority'], 'Coordinator hook ' . $hook . ' must register at PHP_INT_MIN.' );
	}

	$store->set_value( 'theme_mods_lunara-test', array( 'color' => 'black' ) );
	lunara_coordinator_assert( update_option( 'theme_mods_lunara-test', array( 'color' => 'white' ) ), 'A fake later plugin update must succeed under the coordinator.' );
	lunara_coordinator_assert( add_option( 'theme_mods_lunara-added', array( 'layout' => 'wide' ) ), 'A fake later plugin add must succeed under the coordinator.' );
	$before = $store->bundle_count;
	lunara_coordinator_assert_same( false, add_option( 'theme_mods_lunara-added', array( 'layout' => 'duplicate' ) ), 'Adding an existing theme-mod option must remain a WordPress no-op.' );
	lunara_coordinator_assert_same( $before, $store->bundle_count, 'Adding an existing option must bypass coordinator acquisition.' );
	lunara_coordinator_assert( delete_option( 'theme_mods_lunara-added' ), 'A fake later plugin delete must succeed under the coordinator.' );
	$before = $store->bundle_count;
	lunara_coordinator_assert_same( false, delete_option( 'theme_mods_lunara-added' ), 'Deleting a missing theme-mod option must remain a WordPress no-op.' );
	lunara_coordinator_assert_same( $before, $store->bundle_count, 'Deleting a missing option must bypass coordinator acquisition.' );
	$dml = array_values( array_filter( $store->events, static function ( $event ) { return 'dml' === $event[0] && 0 === strpos( $event[2], 'theme_mods_' ); } ) );
	lunara_coordinator_assert_same( array( 'update', 'add', 'delete' ), array_column( $dml, 1 ), 'The fake later plugin must exercise update/add/delete in order.' );
	foreach ( $dml as $event ) {
		lunara_coordinator_assert_same( true, $event[3], 'The coordinator row lock must be held before ' . $event[1] . ' DML.' );
	}
	$posts = array_values( array_filter( $store->events, static function ( $event ) { return 'after-post' === $event[0]; } ) );
	foreach ( $posts as $event ) {
		lunara_coordinator_assert_same( false, $event[3], 'The physical owner must end only after the matching ' . $event[1] . ' post hook.' );
	}

	$before = $store->bundle_count;
	lunara_coordinator_assert_same( false, update_option( 'theme_mods_lunara-test', array( 'color' => 'white' ) ), 'A no-op update must remain a WordPress no-op.' );
	lunara_coordinator_assert_same( $before, $store->bundle_count, 'A no-op update must bypass coordinator acquisition.' );
	lunara_coordinator_assert( update_option( 'theme_mods_update-to-add', array( 'created' => true ) ), 'WordPress update-to-add must use the add interception path.' );
	lunara_coordinator_assert_same( array( 'created' => true ), $store->value( 'theme_mods_update-to-add' ), 'Update-to-add must persist its value.' );

	$large_numeric_value = array(
		0      => 'zero',
		42     => array( 7 => 'nested-numeric-key' ),
		'blob' => str_repeat( 'L', 1024 * 1024 ),
	);
	lunara_coordinator_assert( update_option( 'theme_mods_lunara-test', $large_numeric_value ), 'Large serialized theme-mod values with numeric keys must remain value-agnostic.' );
	lunara_coordinator_assert_same( $large_numeric_value, $store->value( 'theme_mods_lunara-test' ), 'Coordination must not alter large values or numeric array keys.' );
	foreach ( array( 'theme_mods_a', 'theme_mods_' . str_repeat( 'a', 128 ), 'theme_mods_valid-_9' ) as $valid_name ) {
		lunara_coordinator_assert( add_option( $valid_name, array( 0 => 'boundary' ) ), 'The exact valid option-name boundary must be coordinated: ' . $valid_name );
		lunara_coordinator_assert( delete_option( $valid_name ), 'A valid boundary option must remain deletable: ' . $valid_name );
	}

	$before = $store->bundle_count;
	lunara_coordinator_assert( add_option( 'ordinary_draft_option', array( 'draft' => true ) ), 'Unrelated draft/options must remain untouched.' );
	lunara_coordinator_assert( add_option( 'theme_mods_bad.dot', array( 'not' => 'a stylesheet option' ) ), 'An invalid theme-mod lookalike must remain outside the exact grammar.' );
	lunara_coordinator_assert_same( $before, $store->bundle_count, 'Unrelated and invalid-lookalike options must not open coordinator sessions.' );
	lunara_coordinator_assert_same(
		array(),
		array_values( array_filter( $store->events, static function ( $event ) { return 'cache-delete' === $event[0]; } ) ),
		'Coordinator provisioning and leases must never perform cache invalidation.'
	);

	$serialized_status = serialize( lunara_core_theme_mods_coordinator_status() );
	foreach ( array( DB_HOST, DB_USER, DB_PASSWORD, DB_NAME, 'secret-primary.internal', 'secret_core_user@%', 'wp_options', '100' ) as $secret ) {
		lunara_coordinator_assert( false === strpos( $serialized_status, $secret ), 'Redacted status leaked private database material: ' . $secret );
	}
	echo "ready_hooks passed.\n";
	exit( 0 );
}

if ( 'lease_api' === $lunara_coordinator_case ) {
	$reflection = new ReflectionClass( 'Lunara_Core_Theme_Mods_Coordinator_Lease' );
	lunara_coordinator_assert( $reflection->isFinal(), 'The public lease token must be an opaque final object.' );
	$error_actions_while_locked = 0;
	add_action(
		'wp_error_added',
		static function () use ( &$error_actions_while_locked, $store ) {
			if ( null !== $store->lock_owner ) {
				++$error_actions_while_locked;
			}
		},
		10,
		4
	);
	$lease = lunara_core_theme_mods_coordinator_acquire( 'theme_mods_lunara-test', 'oscars-save' );
	lunara_coordinator_assert( $lease instanceof Lunara_Core_Theme_Mods_Coordinator_Lease, 'Acquire must return the opaque lease object.' );
	lunara_coordinator_assert( null !== $store->lock_owner, 'Acquire must hold the physical row lock.' );
	lunara_coordinator_assert_same(
		array( 'api_version' => 1, 'plugin_version' => '0.8.11', 'ready' => true, 'reason' => 'ready', 'held' => true ),
		lunara_core_theme_mods_coordinator_status(),
		'The exact status envelope must report request-local ownership immediately after acquire.'
	);
	lunara_coordinator_assert_same( $lease, lunara_core_theme_mods_coordinator_reuse( $lease, 'theme_mods_lunara-test' ), 'Exact-object same-option reuse must return the same lease.' );
	lunara_coordinator_assert_same(
		array( 'api_version' => 1, 'plugin_version' => '0.8.11', 'ready' => true, 'reason' => 'ready', 'held' => true ),
		lunara_core_theme_mods_coordinator_status(),
		'Reusing the exact lease must keep held true without changing the public envelope.'
	);
	lunara_coordinator_assert( true === lunara_core_theme_mods_coordinator_release( $lease ), 'The first reference release must succeed.' );
	lunara_coordinator_assert( null !== $store->lock_owner, 'The physical owner must remain through a non-final release.' );
	lunara_coordinator_assert_same(
		array( 'api_version' => 1, 'plugin_version' => '0.8.11', 'ready' => true, 'reason' => 'ready', 'held' => true ),
		lunara_core_theme_mods_coordinator_status(),
		'A non-final release must retain request-local held state.'
	);
	lunara_coordinator_assert( true === lunara_core_theme_mods_coordinator_release( $lease ), 'The final reference release must succeed.' );
	lunara_coordinator_assert_same( null, $store->lock_owner, 'The final reference release must positively end the physical owner.' );
	lunara_coordinator_assert_same(
		array( 'api_version' => 1, 'plugin_version' => '0.8.11', 'ready' => true, 'reason' => 'ready', 'held' => false ),
		lunara_core_theme_mods_coordinator_status(),
		'A successful final release must clear request-local held state.'
	);
	lunara_coordinator_assert_same( array(), array_filter( $store->connections ), 'Final release must positively terminate the writer, verifier, and watchdog sessions.' );
	lunara_coordinator_assert( is_wp_error( lunara_core_theme_mods_coordinator_reuse( new stdClass(), 'theme_mods_lunara-test' ) ), 'A bad object must fail closed.' );
	$lease_two = lunara_core_theme_mods_coordinator_acquire( 'theme_mods_lunara-test', 'oscars-restore' );
	lunara_coordinator_assert( is_wp_error( lunara_core_theme_mods_coordinator_reuse( $lease_two, 'theme_mods_other-theme' ) ), 'The exact lease must not be reusable for another option.' );
	lunara_coordinator_assert_same( 0, $error_actions_while_locked, 'Bounded API errors must not invoke WordPress callbacks while the physical row lock is held.' );
	lunara_coordinator_assert( true === lunara_core_theme_mods_coordinator_release( $lease_two ), 'A valid lease must still release after rejected reuse.' );
	lunara_coordinator_assert( is_wp_error( lunara_core_theme_mods_coordinator_release( $lease_two ) ), 'A released lease must fail closed on a second release.' );
	foreach ( array( 'theme_mods_', 'theme_mods_bad.dot', 'theme_mods_' . str_repeat( 'a', 129 ), 'ordinary_option' ) as $invalid ) {
		lunara_coordinator_assert( is_wp_error( lunara_core_theme_mods_coordinator_acquire( $invalid, 'test' ) ), 'Acquire must reject invalid option grammar: ' . $invalid );
	}
	$error = lunara_core_theme_mods_coordinator_acquire( 'theme_mods_lunara-test', "bad purpose\nsecret_core_password" );
	lunara_coordinator_assert( is_wp_error( $error ), 'Unbounded purpose data must fail closed.' );
	$serialized_error = serialize( $error );
	foreach ( array( DB_HOST, DB_USER, DB_PASSWORD, 'secret-primary.internal', 'secret_core_user@%', 'wp_options' ) as $secret ) {
		lunara_coordinator_assert( false === strpos( $serialized_error, $secret ), 'A bounded coordinator error leaked private database material.' );
	}
	echo "lease_api passed.\n";
	exit( 0 );
}

if ( 'nested_and_failed_dml' === $lunara_coordinator_case ) {
	$store->set_value( 'theme_mods_lunara-test', array( 'step' => 0 ) );
	$nested = false;
	add_action(
		'update_option',
		static function ( $option ) use ( &$nested ) {
			if ( 'theme_mods_lunara-test' === $option && ! $nested ) {
				$nested = true;
				update_option( $option, array( 'step' => 1 ) );
			}
		},
		0,
		1
	);
	$before = $store->bundle_count;
	lunara_coordinator_assert( update_option( 'theme_mods_lunara-test', array( 'step' => 2 ) ), 'A nested same-row write must complete safely.' );
	lunara_coordinator_assert_same( $before + 1, $store->bundle_count, 'Nested same-request writes must reuse one physical owner without recursive acquisition.' );
	lunara_coordinator_assert_same( null, $store->lock_owner, 'Nested post hooks must reference-count to one final release.' );

	$store->fail_dml = 'update';
	$before = $store->bundle_count;
	lunara_coordinator_assert_same( false, update_option( 'theme_mods_lunara-test', array( 'step' => 3 ) ), 'The deterministic harness must simulate a failed WordPress DML.' );
	lunara_coordinator_assert( null !== $store->lock_owner, 'A failed DML without a post hook must retain its lease until shutdown.' );
	lunara_coordinator_assert_same(
		array( 'api_version' => 1, 'plugin_version' => '0.8.11', 'ready' => true, 'reason' => 'ready', 'held' => true ),
		lunara_core_theme_mods_coordinator_status(),
		'A failed DML must remain visibly held without exposing its value or operation.'
	);
	$held_owner = $store->lock_owner;
	lunara_coordinator_assert( update_option( 'theme_mods_lunara-test', array( 'step' => 4 ) ), 'A later same-option write may reuse the held request owner.' );
	lunara_coordinator_assert_same( $before + 1, $store->bundle_count, 'A later write under a failed-DML owner must not open a second lock.' );
	lunara_coordinator_assert_same( $held_owner, $store->lock_owner, 'The retained physical owner must remain authoritative after the later post hook.' );
	do_action( 'shutdown' );
	lunara_coordinator_assert_same( null, $store->lock_owner, 'Shutdown fallback must release every retained failed-DML reference.' );
	lunara_coordinator_assert_same(
		array( 'api_version' => 1, 'plugin_version' => '0.8.11', 'ready' => true, 'reason' => 'ready', 'held' => false ),
		lunara_core_theme_mods_coordinator_status(),
		'A successful shutdown fallback must clear request-local held state.'
	);
	echo "nested_and_failed_dml passed.\n";
	exit( 0 );
}

if ( in_array( $lunara_coordinator_case, array( 'manual_transaction_commit', 'manual_transaction_rollback' ), true ) ) {
	lunara_coordinator_assert_same( true, $status['ready'], 'Manual transaction-boundary cases must begin ready.' );
	$lease = lunara_core_theme_mods_coordinator_acquire( 'theme_mods_lunara-test', 'manual-boundary' );
	lunara_coordinator_assert( $lease instanceof Lunara_Core_Theme_Mods_Coordinator_Lease, 'The manual boundary case must acquire the coordinator first.' );
	lunara_coordinator_assert_same( 0, $GLOBALS['wpdb']->query( 'START TRANSACTION' ), 'The fake live owner must enter an explicit transaction while autocommit remains enabled.' );
	lunara_coordinator_assert_same( 1, $store->live_autocommit, 'START TRANSACTION must not be confused with changing the autocommit variable.' );
	$early = lunara_core_theme_mods_coordinator_release( $lease );
	lunara_coordinator_assert( is_wp_error( $early ), 'Final release must be refused while the actual live WordPress transaction is active.' );
	lunara_coordinator_assert( null !== $store->lock_owner, 'The physical coordinator owner must remain held while live DML can still roll back.' );
	lunara_coordinator_assert_same(
		array( 'api_version' => 1, 'plugin_version' => '0.8.11', 'ready' => false, 'reason' => 'storage', 'held' => true ),
		lunara_core_theme_mods_coordinator_status(),
		'An unsafe final-release boundary must fail closed without claiming the lease ended.'
	);
	$boundary = 'manual_transaction_commit' === $lunara_coordinator_case ? 'COMMIT' : 'ROLLBACK';
	lunara_coordinator_assert_same( 0, $GLOBALS['wpdb']->query( $boundary ), 'The fake live transaction boundary must complete.' );
	lunara_coordinator_assert( true === lunara_core_theme_mods_coordinator_release( $lease ), 'The same lease may end only after a positively proven commit/rollback boundary.' );
	lunara_coordinator_assert_same( null, $store->lock_owner, 'The coordinator lock must end after the proven live boundary.' );
	lunara_coordinator_assert_same( false, lunara_core_theme_mods_coordinator_status()['held'], 'Held must clear after the deferred final release.' );
	echo $lunara_coordinator_case . " passed.\n";
	exit( 0 );
}

if ( 'later_pre_dml_transaction' === $lunara_coordinator_case ) {
	$store->set_value( 'theme_mods_lunara-test', array( 'before' => true ) );
	add_action(
		'update_option',
		static function ( $option ) {
			if ( 'theme_mods_lunara-test' === $option ) {
				$GLOBALS['wpdb']->query( 'START TRANSACTION' );
			}
		},
		0,
		1
	);
	lunara_coordinator_assert( update_option( 'theme_mods_lunara-test', array( 'after' => true ) ), 'The fake WordPress DML must reach its post hook inside the later-started transaction.' );
	lunara_coordinator_assert( null !== $store->lock_owner, 'A later pre-DML callback transaction must prevent early coordinator release.' );
	lunara_coordinator_assert_same( true, lunara_core_theme_mods_coordinator_status()['held'], 'The exact status envelope must continue to report held while live DML is uncommitted.' );
	lunara_coordinator_assert_same( 0, $GLOBALS['wpdb']->query( 'COMMIT' ), 'The later live transaction must reach a positive boundary.' );
	do_action( 'shutdown' );
	lunara_coordinator_assert_same( null, $store->lock_owner, 'Shutdown may release the retained owner after the live boundary is positively clear.' );
	lunara_coordinator_assert_same( false, lunara_core_theme_mods_coordinator_status()['held'], 'Shutdown must clear held only after the live commit.' );
	echo "later_pre_dml_transaction passed.\n";
	exit( 0 );
}

if ( 'migration_reconnect_cleanup_uncertainty' === $lunara_coordinator_case ) {
	lunara_coordinator_assert( null === $lunara_coordinator_bootstrap_error, 'Combined migration drift/cleanup uncertainty must remain bounded during bootstrap.' );
	lunara_coordinator_assert_same(
		array( 'api_version' => 1, 'plugin_version' => '0.8.11', 'ready' => false, 'reason' => 'cleanup', 'held' => false ),
		$status,
		'A changed writer identity with unproved direct termination must report cleanup uncertainty, never stale-ID storage cleanup success.'
	);
	lunara_coordinator_assert_same( false, isset( $store->rows[ Lunara_Coordinator_Test_Store::ROW_OPTION ] ), 'Combined migration drift must not leave a half-created permanent row.' );
	lunara_coordinator_assert_same( false, isset( $store->rows[ Lunara_Coordinator_Test_Store::MARKER_OPTION ] ), 'Combined migration drift must not leave a half-created marker.' );
	$drifts = lunara_coordinator_connection_drifts( $store->events );
	lunara_coordinator_assert_same( 1, count( $drifts ), 'The migration cleanup case must exercise one actual writer identity change.' );
	lunara_coordinator_assert( $store->connection_open( (int) $drifts[0][3] ), 'Failed direct termination must remain honestly visible as a surviving replacement writer session.' );
	$stale_watchdog_queries = lunara_coordinator_watchdog_cleanup_queries( $store->events );
	lunara_coordinator_assert_same( array(), $stale_watchdog_queries, 'Cleanup must not ask the watchdog to certify disappearance of the frozen pre-drift writer ID.' );
	$result = lunara_core_theme_mods_coordinator_acquire( 'theme_mods_lunara-test', 'after-migration-cleanup-uncertainty' );
	lunara_coordinator_assert( $result instanceof WP_Error, 'Cleanup uncertainty must never permit a lease.' );
	echo "migration_reconnect_cleanup_uncertainty passed.\n";
	exit( 0 );
}

if ( in_array( $lunara_coordinator_case, array( 'migration_reconnect_after_start', 'migration_transaction_loss_after_write' ), true ) ) {
	lunara_coordinator_assert( null === $lunara_coordinator_bootstrap_error, 'A simulated native migration identity/transaction change must be contained, never escape bootstrap.' );
	lunara_coordinator_assert_same(
		array( 'api_version' => 1, 'plugin_version' => '0.8.11', 'ready' => false, 'reason' => 'storage', 'held' => false ),
		$status,
		'A native migration identity/transaction change must fail closed with the exact redacted envelope.'
	);
	lunara_coordinator_assert_same( false, isset( $store->rows[ Lunara_Coordinator_Test_Store::ROW_OPTION ] ), 'A simulated reconnect must never leave a half-created permanent row.' );
	lunara_coordinator_assert_same( false, isset( $store->rows[ Lunara_Coordinator_Test_Store::MARKER_OPTION ] ), 'A simulated reconnect must never leave a half-created migration marker.' );
	lunara_coordinator_assert_same( array(), array_filter( $store->connections ), 'A failed migration identity/transaction proof must positively close every native session.' );
	echo $lunara_coordinator_case . " passed.\n";
	exit( 0 );
}

if ( 'acquire_reconnect_cleanup_uncertainty' === $lunara_coordinator_case ) {
	lunara_coordinator_assert_same( true, $status['ready'], 'Combined acquire drift/cleanup uncertainty must bootstrap from canonical ready storage.' );
	$store->cleanup_uncertainty = true;
	$result = lunara_core_theme_mods_coordinator_acquire( 'theme_mods_lunara-test', 'reconnect-cleanup' );
	lunara_coordinator_assert( $result instanceof WP_Error, 'Combined acquire drift/direct-close failure must return a bounded WP_Error, never a lease.' );
	lunara_coordinator_assert_same( 'lunara_core_theme_mods_cleanup_failed', $result->get_error_code(), 'Unproved replacement-writer termination must return the exact bounded cleanup code.' );
	lunara_coordinator_assert_same(
		array( 'api_version' => 1, 'plugin_version' => '0.8.11', 'ready' => false, 'reason' => 'cleanup', 'held' => false ),
		lunara_core_theme_mods_coordinator_status(),
		'Combined acquire drift/direct-close failure must report cleanup uncertainty without request-local ownership.'
	);
	lunara_coordinator_assert_same( lunara_coordinator_core_clear_record(), $store->value( Lunara_Coordinator_Test_Store::ROW_OPTION ), 'Combined acquire drift must not leave a durable active record.' );
	$drifts = lunara_coordinator_connection_drifts( $store->events );
	lunara_coordinator_assert_same( 1, count( $drifts ), 'The acquire cleanup case must exercise one actual writer identity change.' );
	lunara_coordinator_assert( $store->connection_open( (int) $drifts[0][3] ), 'Failed direct termination must remain honestly visible as a surviving replacement writer session.' );
	$stale_watchdog_queries = lunara_coordinator_watchdog_cleanup_queries( $store->events );
	lunara_coordinator_assert_same( array(), $stale_watchdog_queries, 'Acquire cleanup must not probe the stale frozen writer ID after drift.' );
	echo "acquire_reconnect_cleanup_uncertainty passed.\n";
	exit( 0 );
}

if ( in_array( $lunara_coordinator_case, array( 'acquire_reconnect_after_lock', 'acquire_transaction_loss_after_cas' ), true ) ) {
	lunara_coordinator_assert_same( true, $status['ready'], 'Acquire reconnect-defense cases must bootstrap from canonical ready storage.' );
	$lease = lunara_core_theme_mods_coordinator_acquire( 'theme_mods_lunara-test', 'reconnect-defense' );
	lunara_coordinator_assert( $lease instanceof WP_Error, 'A changed native writer identity/transaction must return a bounded WP_Error, never a lease.' );
	lunara_coordinator_assert_same( lunara_coordinator_core_clear_record(), $store->value( Lunara_Coordinator_Test_Store::ROW_OPTION ), 'A simulated acquire reconnect must never leave a durable active record.' );
	lunara_coordinator_assert_same(
		array( 'api_version' => 1, 'plugin_version' => '0.8.11', 'ready' => false, 'reason' => 'storage', 'held' => false ),
		lunara_core_theme_mods_coordinator_status(),
		'A failed native writer reproof must disable readiness without claiming request ownership.'
	);
	lunara_coordinator_assert_same( array(), array_filter( $store->connections ), 'A failed acquire identity/transaction proof must positively close every native session.' );
	echo $lunara_coordinator_case . " passed.\n";
	exit( 0 );
}

if ( 'internal_error_callbacks' === $lunara_coordinator_case ) {
	lunara_coordinator_assert_same( true, $status['ready'], 'The callback-safety case must begin ready.' );
	$lease = lunara_core_theme_mods_coordinator_acquire( 'theme_mods_lunara-test', 'callback-safety' );
	lunara_coordinator_assert( $lease instanceof Lunara_Core_Theme_Mods_Coordinator_Lease, 'The callback-safety case must hold the real request owner.' );
	$callback_hits = 0;
	add_action(
		'is_wp_error_instance',
		static function () use ( &$callback_hits ) {
			++$callback_hits;
		},
		10,
		1
	);
	$caught = null;
	try {
		Lunara_Core_Theme_Mods_Coordinator::before_update( 'theme_mods_other-theme' );
	} catch ( Throwable $error ) {
		$caught = $error;
	}
	lunara_coordinator_assert( $caught instanceof Lunara_Core_Theme_Mods_Coordinator_Exception, 'A rejected different-option reuse must still throw the dedicated pre-DML exception.' );
	lunara_coordinator_assert_same( 0, $callback_hits, 'Coordinator-private rejected-reuse control flow must not invoke is_wp_error_instance while the row lock is held.' );
	lunara_coordinator_assert( true === lunara_core_theme_mods_coordinator_release( $lease ), 'The original exact lease must remain releasable after rejected reuse.' );
	echo "internal_error_callbacks passed.\n";
	exit( 0 );
}

if ( 'shutdown_active_transaction' === $lunara_coordinator_case ) {
	lunara_coordinator_assert_same( true, $status['ready'], 'The retained-shutdown case must begin ready.' );
	$lease = lunara_core_theme_mods_coordinator_acquire( 'theme_mods_lunara-test', 'shutdown-boundary' );
	lunara_coordinator_assert( $lease instanceof Lunara_Core_Theme_Mods_Coordinator_Lease, 'The retained-shutdown case must acquire the real row owner.' );
	lunara_coordinator_assert_same( 0, $GLOBALS['wpdb']->query( 'START TRANSACTION' ), 'The live WordPress owner must enter an explicit transaction with autocommit still enabled.' );
	$callback_hits = 0;
	add_action(
		'is_wp_error_instance',
		static function () use ( &$callback_hits ) {
			++$callback_hits;
		},
		10,
		1
	);
	$profile_reads = $store->live_profile_reads;
	$held_owner    = $store->lock_owner;
	do_action( 'shutdown' );
	lunara_coordinator_assert_same( 0, $callback_hits, 'Unsafe shutdown release refusal must not invoke is_wp_error_instance while the row lock is held.' );
	lunara_coordinator_assert_same( $profile_reads + 1, $store->live_profile_reads, 'Shutdown must make one release-boundary attempt and stop without spinning while the live transaction remains active.' );
	lunara_coordinator_assert_same( $held_owner, $store->lock_owner, 'Shutdown must retain the authoritative native row owner while live DML remains uncommitted.' );
	lunara_coordinator_assert_same(
		array( 'api_version' => 1, 'plugin_version' => '0.8.11', 'ready' => false, 'reason' => 'storage', 'held' => true ),
		lunara_core_theme_mods_coordinator_status(),
		'Unsafe shutdown must remain visibly held and fail closed.'
	);
	$store->model_request_teardown();
	lunara_coordinator_assert_same( 0, $store->live_in_transaction, 'The harness request-teardown model must roll back the live transaction.' );
	lunara_coordinator_assert_same( null, $store->lock_owner, 'The harness request-teardown model must release the physical native row lock.' );
	lunara_coordinator_assert_same( array(), array_filter( $store->connections ), 'The harness request-teardown model must make every native connection disappear.' );
	lunara_coordinator_assert_same( true, lunara_core_theme_mods_coordinator_status()['held'], 'The in-process harness cannot claim PHP request-local memory teardown; held remains true until the modeled process ends.' );
	echo "shutdown_active_transaction passed.\n";
	exit( 0 );
}

if ( 'watchdog_attestation_bootstrap' === $lunara_coordinator_case ) {
	lunara_coordinator_assert( null === $lunara_coordinator_bootstrap_error, 'A watchdog visibility exception must be contained during inclusion-time bootstrap.' );
	lunara_coordinator_assert_same(
		array( 'api_version' => 1, 'plugin_version' => '0.8.11', 'ready' => false, 'reason' => 'storage', 'held' => false ),
		$status,
		'Bootstrap attestation failure must expose only the exact redacted storage envelope.'
	);
	lunara_coordinator_assert_same( array(), array_filter( $store->connections ), 'Bootstrap attestation failure must positively close every partially opened session.' );
	$error = lunara_core_theme_mods_coordinator_acquire( 'theme_mods_lunara-test', 'after-bootstrap-attestation-failure' );
	lunara_coordinator_assert( $error instanceof WP_Error, 'After bootstrap attestation failure the public API must return a bounded WP_Error.' );
	lunara_coordinator_assert_same( 'lunara_core_theme_mods_unavailable', $error->get_error_code(), 'Bootstrap attestation failure must return the exact bounded unavailable code.' );
	lunara_coordinator_assert_same( '', $error->get_error_message(), 'Bootstrap attestation failure must not expose an internal message.' );
	lunara_coordinator_assert_same( null, $error->get_error_data(), 'Bootstrap attestation failure must not expose internal data.' );
	lunara_coordinator_assert( false === strpos( serialize( $error ), 'PROCESSLIST' ), 'The bounded bootstrap error must not expose watchdog query detail.' );
	echo "watchdog_attestation_bootstrap passed.\n";
	exit( 0 );
}

if ( 'watchdog_attestation_acquire' === $lunara_coordinator_case ) {
	lunara_coordinator_assert_same( true, $status['ready'], 'The acquire-attestation case must bootstrap successfully before the injected later failure.' );
	$store->watchdog_visibility_fail_on_bundle = $store->bundle_count + 1;
	$caught = null;
	$result = null;
	try {
		$result = lunara_core_theme_mods_coordinator_acquire( 'theme_mods_lunara-test', 'watchdog-attestation' );
	} catch ( Throwable $error ) {
		$caught = $error;
	}
	lunara_coordinator_assert( null === $caught, 'A watchdog visibility exception during acquire must not escape the public API.' );
	lunara_coordinator_assert( $result instanceof WP_Error, 'Acquire attestation failure must return a bounded WP_Error.' );
	lunara_coordinator_assert_same( 'lunara_core_theme_mods_unavailable', $result->get_error_code(), 'Acquire attestation failure must return the exact bounded unavailable code.' );
	lunara_coordinator_assert_same( '', $result->get_error_message(), 'Acquire attestation failure must not expose an internal message.' );
	lunara_coordinator_assert_same( null, $result->get_error_data(), 'Acquire attestation failure must not expose internal data.' );
	lunara_coordinator_assert_same(
		array( 'api_version' => 1, 'plugin_version' => '0.8.11', 'ready' => false, 'reason' => 'storage', 'held' => false ),
		lunara_core_theme_mods_coordinator_status(),
		'Acquire attestation failure must expose only the exact redacted storage envelope.'
	);
	lunara_coordinator_assert_same( array(), array_filter( $store->connections ), 'Acquire attestation failure must positively close every partially opened session.' );
	lunara_coordinator_assert( false === strpos( serialize( $result ), 'PROCESSLIST' ), 'The bounded acquire error must not expose watchdog query detail.' );
	echo "watchdog_attestation_acquire passed.\n";
	exit( 0 );
}

if ( in_array( $lunara_coordinator_case, array( 'late_load', 'misordered_load', 'api_unavailable' ), true ) ) {
	$expected_reason = 'api_unavailable' === $lunara_coordinator_case ? 'runtime' : 'load-order';
	lunara_coordinator_assert_same(
		array( 'api_version' => 1, 'plugin_version' => '0.8.11', 'ready' => false, 'reason' => $expected_reason, 'held' => false ),
		$status,
		'Late, misordered, or runtime-unavailable Core must expose only the exact unavailable status envelope.'
	);
	lunara_coordinator_assert_denied_before_dml( 'update', 'theme_mods_lunara-test' );
	lunara_coordinator_assert_denied_before_dml( 'add', 'theme_mods_lunara-added' );
	lunara_coordinator_assert_denied_before_dml( 'delete', 'theme_mods_lunara-delete' );
	$before = $store->bundle_count;
	lunara_coordinator_assert( add_option( 'ordinary_draft_option', array( 'draft' => true ) ), 'Unavailable coordinator state must still leave unrelated options untouched.' );
	lunara_coordinator_assert_same( $before, $store->bundle_count, 'Unrelated options must bypass an unavailable coordinator.' );
	echo $lunara_coordinator_case . " passed.\n";
	exit( 0 );
}

if ( 'ordering_repair' === $lunara_coordinator_case ) {
	lunara_coordinator_assert_same( false, $status['ready'], 'A request that loaded Core second must remain not-ready.' );
	lunara_coordinator_assert( Lunara_Core_Theme_Mods_Coordinator::repair_active_plugins_order(), 'Lifecycle ordering repair must persist and recheck Core-first order.' );
	lunara_coordinator_assert_same(
		array(
			'lunara-core/lunara-core.php',
			'example-one/example-one.php',
			'example-two/example-two.php',
			'example-three/example-three.php',
		),
		$store->active_plugins,
		'Ordering repair must preserve every non-Core plugin relative position.'
	);
	lunara_coordinator_assert_same( 'yes', $store->active_plugins_autoload, 'Ordering repair must preserve the physical active_plugins autoload state.' );
	Lunara_Core_Theme_Mods_Coordinator::bootstrap();
	lunara_coordinator_assert_same( false, lunara_core_theme_mods_coordinator_status()['ready'], 'Repair must not upgrade the current late request to a partial coverage claim.' );
	echo "ordering_repair passed.\n";
	exit( 0 );
}

if ( 'provision_crash' === $lunara_coordinator_case ) {
	lunara_coordinator_assert_same( false, $status['ready'], 'A crash between migration writes must fail closed.' );
	lunara_coordinator_assert_same( lunara_coordinator_legacy_clear_record(), $store->value( Lunara_Coordinator_Test_Store::ROW_OPTION ), 'Atomic rollback must preserve the complete legacy state after a migration crash.' );
	lunara_coordinator_assert_same( false, isset( $store->rows[ Lunara_Coordinator_Test_Store::MARKER_OPTION ] ), 'Atomic rollback must never expose a half-created marker.' );
	echo "provision_crash passed.\n";
	exit( 0 );
}

if ( 'live_proof_rollback_failure' === $lunara_coordinator_case ) {
	lunara_coordinator_assert( null === $lunara_coordinator_bootstrap_error, 'The autocommit live write proof must remain bounded when the obsolete rollback path is configured to fail.' );
	lunara_coordinator_assert_same(
		array( 'api_version' => 1, 'plugin_version' => '0.8.11', 'ready' => true, 'reason' => 'ready', 'held' => false ),
		$status,
		'An autocommit sentinel UPDATE must prove live writability without depending on rollback.'
	);
	lunara_coordinator_assert_same( 0, $store->live_in_transaction, 'Core live-writability proof must never introduce or leak a transaction on the external wpdb owner.' );
	lunara_coordinator_assert_same( false, in_array( array( 'live-query', 'START TRANSACTION' ), $store->events, true ), 'Core must not start a transaction on the external live wpdb connection.' );
	lunara_coordinator_assert_same( false, in_array( array( 'live-query', 'ROLLBACK' ), $store->events, true ), 'Core must not issue rollback on a live transaction it never starts.' );
	$proof_queries = array_values(
		array_filter(
			$store->events,
			static function ( $event ) {
				return is_array( $event ) && isset( $event[0], $event[1] ) && 'live-query' === $event[0] && false !== strpos( $event[1], 'option_id = 0' );
			}
		)
	);
	lunara_coordinator_assert_same( 1, count( $proof_queries ), 'Core must issue exactly one zero-change sentinel UPDATE for live writability.' );
	echo "live_proof_rollback_failure passed.\n";
	exit( 0 );
}

if ( 'live_proof_post_profile_failure' === $lunara_coordinator_case ) {
	lunara_coordinator_assert( null === $lunara_coordinator_bootstrap_error, 'A post-proof live-profile failure must remain bounded.' );
	lunara_coordinator_assert_same(
		array( 'api_version' => 1, 'plugin_version' => '0.8.11', 'ready' => false, 'reason' => 'storage', 'held' => false ),
		$status,
		'An unprovable post-UPDATE live profile must fail closed without creating a cleanup obligation.'
	);
	lunara_coordinator_assert_same( 0, $store->live_in_transaction, 'Post-proof profile failure must leave no Core-created live transaction.' );
	lunara_coordinator_assert_same( false, in_array( array( 'live-query', 'START TRANSACTION' ), $store->events, true ), 'The live proof must remain transaction-free before a post-proof profile failure.' );
	lunara_coordinator_assert_same( false, in_array( array( 'live-query', 'ROLLBACK' ), $store->events, true ), 'Post-proof failure must not require rollback cleanup.' );
	lunara_coordinator_assert_same( 2, $store->live_profile_reads, 'The deterministic failure must occur on the exact profile read after the sentinel UPDATE.' );
	lunara_coordinator_assert_same( array(), array_filter( $store->connections ), 'Post-proof profile failure must still positively close the native bundle.' );
	lunara_coordinator_assert_denied_before_dml( 'update', 'theme_mods_lunara-test' );
	echo "live_proof_post_profile_failure passed.\n";
	exit( 0 );
}

if ( in_array( $lunara_coordinator_case, array( 'marker_loss', 'marker_corruption', 'row_loss', 'row_corruption', 'wrong_autoload', 'non_innodb', 'db_dropin', 'non_stock_wpdb', 'read_only', 'live_autocommit', 'live_transaction_before_bootstrap', 'transaction_read_only', 'super_read_only', 'transport_mismatch', 'ssl_downgrade', 'live_write_proof_failure', 'native_write_proof_failure', 'session_mismatch' ), true ) ) {
	lunara_coordinator_assert_same( false, $status['ready'], 'Corrupt, missing, mismatched, or unsupported coordinator storage must fail closed: ' . $lunara_coordinator_case );
	if ( 'marker_loss' === $lunara_coordinator_case ) {
		lunara_coordinator_assert_same( false, isset( $store->rows[ Lunara_Coordinator_Test_Store::MARKER_OPTION ] ), 'A missing marker beside a Core-shaped row must never be silently recreated.' );
	}
	lunara_coordinator_assert_denied_before_dml( 'update', 'theme_mods_lunara-test' );
	echo $lunara_coordinator_case . " passed.\n";
	exit( 0 );
}

if ( 'lock_contention' === $lunara_coordinator_case ) {
	lunara_coordinator_assert_same( true, $status['ready'], 'The transient contention case must bootstrap ready.' );
	$store->lock_contention = true;
	$lease = lunara_core_theme_mods_coordinator_acquire( 'theme_mods_lunara-test', 'contention' );
	lunara_coordinator_assert( is_wp_error( $lease ), 'Bounded exact-row lock contention must return a bounded error.' );
	lunara_coordinator_assert_same(
		array( 'api_version' => 1, 'plugin_version' => '0.8.11', 'ready' => true, 'reason' => 'ready', 'held' => false ),
		lunara_core_theme_mods_coordinator_status(),
		'Transient lock contention must not be misclassified as durable storage corruption.'
	);
	echo "lock_contention passed.\n";
	exit( 0 );
}

if ( 'invariant_write_failure' === $lunara_coordinator_case ) {
	lunara_coordinator_assert_same( true, $status['ready'], 'The invariant-write case must bootstrap ready.' );
	$store->invariant_write_failure = true;
	$lease = lunara_core_theme_mods_coordinator_acquire( 'theme_mods_lunara-test', 'write-failure' );
	lunara_coordinator_assert( is_wp_error( $lease ), 'An exact active-row CAS failure must return a bounded error.' );
	lunara_coordinator_assert_same(
		array( 'api_version' => 1, 'plugin_version' => '0.8.11', 'ready' => false, 'reason' => 'storage', 'held' => false ),
		lunara_core_theme_mods_coordinator_status(),
		'Invariant/write failure must disable request readiness as storage-unavailable.'
	);
	echo "invariant_write_failure passed.\n";
	exit( 0 );
}

if ( 'connection_loss' === $lunara_coordinator_case ) {
	$lease = lunara_core_theme_mods_coordinator_acquire( 'theme_mods_lunara-test', 'connection-loss' );
	lunara_coordinator_assert( is_wp_error( $lease ), 'Connection loss during acquisition must return a bounded WP_Error.' );
	lunara_coordinator_assert_same( null, $store->lock_owner, 'A lost writer connection must not leave a request-visible owned lock.' );
	lunara_coordinator_assert( false === strpos( serialize( $lease ), 'secret connection lost' ), 'Connection exception text must never escape in the API error.' );
	echo "connection_loss passed.\n";
	exit( 0 );
}

if ( 'watchdog_kill' === $lunara_coordinator_case ) {
	$lease = lunara_core_theme_mods_coordinator_acquire( 'theme_mods_lunara-test', 'watchdog-close' );
	lunara_coordinator_assert( $lease instanceof Lunara_Core_Theme_Mods_Coordinator_Lease, 'The watchdog case must acquire a real request lease.' );
	$writer_id = $store->lock_owner;
	lunara_coordinator_assert( true === lunara_core_theme_mods_coordinator_release( $lease ), 'An attested watchdog kill plus disappearance proof must positively release the lease.' );
	lunara_coordinator_assert( ! $store->connection_open( $writer_id ), 'The watchdog must prove the exact writer connection disappeared.' );
	lunara_coordinator_assert( in_array( array( 'kill', $writer_id ), $store->events, true ), 'The watchdog must kill only the exact pre-attested writer connection.' );
	echo "watchdog_kill passed.\n";
	exit( 0 );
}

if ( 'cleanup_uncertainty' === $lunara_coordinator_case ) {
	$lease = lunara_core_theme_mods_coordinator_acquire( 'theme_mods_lunara-test', 'uncertain-close' );
	lunara_coordinator_assert( $lease instanceof Lunara_Core_Theme_Mods_Coordinator_Lease, 'The cleanup-uncertainty case must first acquire a lease.' );
	$store->cleanup_uncertainty = true;
	$result = lunara_core_theme_mods_coordinator_release( $lease );
	lunara_coordinator_assert( is_wp_error( $result ), 'Unproved native-session cleanup must fail closed.' );
	lunara_coordinator_assert_same(
		array( 'api_version' => 1, 'plugin_version' => '0.8.11', 'ready' => false, 'reason' => 'cleanup', 'held' => false ),
		lunara_core_theme_mods_coordinator_status(),
		'Cleanup uncertainty must expose only the exact bounded failure envelope and clear request-local ownership.'
	);
	echo "cleanup_uncertainty passed.\n";
	exit( 0 );
}

throw new RuntimeException( 'Unknown coordinator regression case: ' . $lunara_coordinator_case );
