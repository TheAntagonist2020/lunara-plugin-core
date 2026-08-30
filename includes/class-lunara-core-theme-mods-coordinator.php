<?php
/**
 * Early mutual exclusion for normal-plugin WordPress theme-mod row writers.
 *
 * The coordinator owns only the permanent physical lock and its request-local
 * lease API. Theme-owned journals, reconciliation, previews, caches, and UI
 * intentionally remain outside this module.
 *
 * @package Lunara_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Opaque request-local proof that one physical coordinator row is owned. */
final class Lunara_Core_Theme_Mods_Coordinator_Lease {
	/** @var object */
	private $seal;

	/**
	 * The coordinator alone registers issued objects; constructed lookalikes
	 * never satisfy the exact-object request registry.
	 *
	 * @param object $seal Inert request-local seal.
	 */
	public function __construct( $seal ) {
		$this->seal = $seal;
	}
}

/** Dedicated pre-DML stop used by the Options API action adapters. */
final class Lunara_Core_Theme_Mods_Coordinator_Exception extends RuntimeException {}

/** Internal bounded classification for native storage failures. */
final class Lunara_Core_Theme_Mods_Coordinator_Storage_Exception extends RuntimeException {
	/** @var string */
	private $category;

	/** @param string $category contention|connection|invariant. */
	public function __construct( $category ) {
		$this->category = in_array( $category, array( 'contention', 'connection', 'invariant' ), true ) ? $category : 'invariant';
		parent::__construct( 'Lunara Core coordinator storage operation failed.' );
	}

	/** @return string */
	public function category() {
		return $this->category;
	}
}

if ( class_exists( 'mysqli', false ) && ! class_exists( 'Lunara_Core_Theme_Mods_Native_MySQLi', false ) ) {
	/** Callback-free adapter around one newly opened native mysqli connection. */
	final class Lunara_Core_Theme_Mods_Native_MySQLi {
		/** @var string */
		public $options;

		/** @var string */
		public $prefix;

		/** @var mysqli|null */
		private $dbh;

		/** @var bool */
		private $terminated = false;

		/** @var array */
		private $no_reconnect_fact;

		/**
		 * @param mysqli $dbh Native connection.
		 * @param string $options Exact options table.
		 * @param string $prefix Exact WordPress prefix.
		 * @param array  $no_reconnect_fact Frozen verified driver/runtime/config fact.
		 */
		public function __construct( $dbh, $options, $prefix, $no_reconnect_fact ) {
			if (
				! ( $dbh instanceof mysqli )
				|| ! is_string( $options )
				|| 1 !== preg_match( '/^[A-Za-z0-9_]+$/D', $options )
				|| ! is_string( $prefix )
				|| 1 !== preg_match( '/^[A-Za-z0-9_]+$/D', $prefix )
				|| ! self::no_reconnect_fact_valid( $no_reconnect_fact )
			) {
				throw new RuntimeException( 'Invalid native coordinator session.' );
			}

			$this->dbh               = $dbh;
			$this->options           = $options;
			$this->prefix            = $prefix;
			$this->no_reconnect_fact = $no_reconnect_fact;
		}

		/** Accept only a bounded internally established reconnect-safety fact. */
		private static function no_reconnect_fact_valid( $fact ) {
			if (
				! is_array( $fact )
				|| array( 'driver', 'php_version_id', 'client_info_fingerprint', 'reconnect' ) !== array_keys( $fact )
				|| ! in_array( $fact['driver'], array( 'mysqlnd', 'libmysqlclient' ), true )
				|| ! is_int( $fact['php_version_id'] )
				|| 70000 > $fact['php_version_id']
				|| ! is_string( $fact['client_info_fingerprint'] )
				|| 1 !== preg_match( '/^[a-f0-9]{64}$/D', $fact['client_info_fingerprint'] )
				|| ! is_string( $fact['reconnect'] )
			) {
				return false;
			}
			if ( 80200 <= $fact['php_version_id'] ) {
				return 'php-8.2+' === $fact['reconnect'];
			}
			return 'mysqlnd' === $fact['driver']
				? 'mysqlnd-ignored' === $fact['reconnect']
				: 'disabled' === $fact['reconnect'];
		}

		/** @return int */
		public function lunara_core_theme_mods_raw_contract_version() {
			return 1;
		}

		/** @return bool */
		public function lunara_core_theme_mods_no_reconnect_verified() {
			return self::no_reconnect_fact_valid( $this->no_reconnect_fact );
		}

		/**
		 * @param string $sql SQL with positional placeholders.
		 * @param array  $params Scalar parameters.
		 * @return mysqli_stmt
		 */
		private function statement( $sql, $params ) {
			if (
				$this->terminated
				|| ! ( $this->dbh instanceof mysqli )
				|| ! is_string( $sql )
				|| '' === $sql
				|| ! is_array( $params )
				|| substr_count( $sql, '?' ) !== count( $params )
			) {
				throw new RuntimeException( 'Unavailable native coordinator statement.' );
			}

			try {
				$statement = mysqli_prepare( $this->dbh, $sql );
			} catch ( Throwable $error ) {
				throw $this->storage_exception( (int) $error->getCode(), 'invariant' );
			}
			if ( false === $statement ) {
				throw $this->storage_exception( (int) mysqli_errno( $this->dbh ), 'invariant' );
			}

			if ( $params ) {
				$values = array();
				foreach ( array_values( $params ) as $value ) {
					if ( ! is_string( $value ) && ! is_int( $value ) && null !== $value ) {
						mysqli_stmt_close( $statement );
						throw new RuntimeException( 'Invalid native coordinator parameter.' );
					}
					$values[] = null === $value ? null : (string) $value;
				}

				$arguments = array( str_repeat( 's', count( $values ) ) );
				foreach ( $values as $index => $value ) {
					$arguments[] =& $values[ $index ];
				}
				try {
					$bound = call_user_func_array( 'mysqli_stmt_bind_param', array_merge( array( $statement ), $arguments ) );
				} catch ( Throwable $error ) {
					mysqli_stmt_close( $statement );
					throw $this->storage_exception( (int) $error->getCode(), 'invariant' );
				}
				if ( ! $bound ) {
					$code = (int) mysqli_stmt_errno( $statement );
					mysqli_stmt_close( $statement );
					throw $this->storage_exception( $code, 'invariant' );
				}
			}

			try {
				$executed = mysqli_stmt_execute( $statement );
			} catch ( Throwable $error ) {
				mysqli_stmt_close( $statement );
				throw $this->storage_exception( (int) $error->getCode(), 'invariant' );
			}
			if ( ! $executed ) {
				$code = (int) mysqli_stmt_errno( $statement );
				mysqli_stmt_close( $statement );
				throw $this->storage_exception( $code, 'invariant' );
			}

			return $statement;
		}

		/** Convert raw driver numbers into the coordinator's bounded taxonomy. */
		private function storage_exception( $number, $fallback ) {
			if ( in_array( (int) $number, array( 1205, 1213, 3572 ), true ) ) {
				return new Lunara_Core_Theme_Mods_Coordinator_Storage_Exception( 'contention' );
			}
			if ( in_array( (int) $number, array( 2002, 2003, 2006, 2013, 2055 ), true ) ) {
				return new Lunara_Core_Theme_Mods_Coordinator_Storage_Exception( 'connection' );
			}
			return new Lunara_Core_Theme_Mods_Coordinator_Storage_Exception( 'connection' === $fallback ? 'connection' : 'invariant' );
		}

		/**
		 * @param string $sql SQL command.
		 * @param array  $params Scalar parameters.
		 * @return array
		 */
		public function lunara_core_theme_mods_raw_exec( $sql, $params = array() ) {
			$direct = array(
				'SET SESSION innodb_lock_wait_timeout = 3',
				'SET SESSION lock_wait_timeout = 3',
				'START TRANSACTION',
				'COMMIT',
				'ROLLBACK',
			);
			$is_kill = is_string( $sql ) && 1 === preg_match( '/^KILL CONNECTION [1-9][0-9]*$/D', $sql );
			if ( array() === $params && ( in_array( $sql, $direct, true ) || $is_kill ) ) {
				if ( $this->terminated || ! ( $this->dbh instanceof mysqli ) ) {
					throw new RuntimeException( 'Unavailable native coordinator control.' );
				}
				$result = mysqli_query( $this->dbh, $sql );
				if ( true !== $result ) {
					if ( $result instanceof mysqli_result ) {
						mysqli_free_result( $result );
					}
					throw new RuntimeException( 'Native coordinator control failed.' );
				}
				return array(
					'affected_rows' => (int) mysqli_affected_rows( $this->dbh ),
					'insert_id'     => (string) mysqli_insert_id( $this->dbh ),
				);
			}

			$statement = $this->statement( $sql, $params );
			$result    = array(
				'affected_rows' => (int) mysqli_stmt_affected_rows( $statement ),
				'insert_id'     => (string) mysqli_insert_id( $this->dbh ),
			);
			mysqli_stmt_close( $statement );
			return $result;
		}

		/**
		 * @param string $sql SQL query.
		 * @param array  $params Scalar parameters.
		 * @return array
		 */
		public function lunara_core_theme_mods_raw_rows( $sql, $params = array() ) {
			$statement = $this->statement( $sql, $params );
			try {
				if ( function_exists( 'mysqli_stmt_get_result' ) ) {
					$result = mysqli_stmt_get_result( $statement );
					if ( $result instanceof mysqli_result ) {
						try {
							$rows = array();
							while ( true ) {
								$row = mysqli_fetch_assoc( $result );
								if ( null === $row ) {
									break;
								}
								if ( false === $row || ! is_array( $row ) ) {
									throw new RuntimeException( 'Native coordinator result failed.' );
								}
								$rows[] = $row;
							}
							return $rows;
						} finally {
							mysqli_free_result( $result );
						}
					}
				}
				return $this->bound_result_rows( $statement );
			} finally {
				mysqli_stmt_close( $statement );
			}
		}

		/** libmysqlclient-compatible prepared-result materialization. */
		private function bound_result_rows( $statement ) {
			$metadata = mysqli_stmt_result_metadata( $statement );
			if ( ! ( $metadata instanceof mysqli_result ) ) {
				throw new RuntimeException( 'Native coordinator metadata failed.' );
			}
			try {
				$fields = mysqli_fetch_fields( $metadata );
				if ( ! is_array( $fields ) || ! $fields ) {
					throw new RuntimeException( 'Native coordinator fields failed.' );
				}
				$names  = array();
				$values = array_fill( 0, count( $fields ), null );
				foreach ( $fields as $field ) {
					$name = is_object( $field ) && isset( $field->name ) ? (string) $field->name : '';
					if ( '' === $name || 128 < strlen( $name ) || false !== strpos( $name, "\0" ) || in_array( $name, $names, true ) ) {
						throw new RuntimeException( 'Native coordinator field shape failed.' );
					}
					$names[] = $name;
				}

				$arguments = array( $statement );
				foreach ( $values as $index => $value ) {
					$arguments[] =& $values[ $index ];
				}
				if ( ! call_user_func_array( 'mysqli_stmt_bind_result', $arguments ) ) {
					throw new RuntimeException( 'Native coordinator result binding failed.' );
				}

				$rows = array();
				while ( true ) {
					$fetched = mysqli_stmt_fetch( $statement );
					if ( null === $fetched ) {
						break;
					}
					if ( true !== $fetched ) {
						throw new RuntimeException( 'Native coordinator result fetch failed.' );
					}
					$row = array();
					foreach ( $names as $index => $name ) {
						$value = $values[ $index ];
						if ( null !== $value && ! is_string( $value ) && ! is_int( $value ) ) {
							throw new RuntimeException( 'Native coordinator result value failed.' );
						}
						$row[ $name ] = $value;
					}
					$rows[] = $row;
				}
				return $rows;
			} finally {
				mysqli_free_result( $metadata );
			}
		}

		/**
		 * @param string $sql SQL query.
		 * @param array  $params Scalar parameters.
		 * @return array|null
		 */
		public function lunara_core_theme_mods_raw_row( $sql, $params = array() ) {
			$rows = $this->lunara_core_theme_mods_raw_rows( $sql, $params );
			if ( 1 < count( $rows ) ) {
				throw new RuntimeException( 'Ambiguous native coordinator row.' );
			}
			return $rows ? $rows[0] : null;
		}

		/** @return bool */
		public function lunara_core_theme_mods_terminate_verified() {
			if ( $this->terminated ) {
				return true;
			}
			if ( ! ( $this->dbh instanceof mysqli ) ) {
				return false;
			}

			try {
				$closed = mysqli_close( $this->dbh );
			} catch ( Throwable $error ) {
				return false;
			}
			if ( true !== $closed ) {
				return false;
			}

			$this->dbh        = null;
			$this->terminated = true;
			return true;
		}
	}
}

/** Core-owned request coordinator. */
final class Lunara_Core_Theme_Mods_Coordinator {
	const API_VERSION = 1;

	/** Legacy Theme row adopted into the permanent Core schema. */
	private const ROW_OPTION = 'lunara_oscars_portal_studio_outer_coordinator';

	/** Durable proof that the one-time schema migration completed atomically. */
	private const MARKER_OPTION = 'lunara_core_theme_mods_coordinator_migration';

	private const SCHEMA_VERSION = 2;
	private const MAX_REFERENCES = 1024;

	/** @var bool */
	private static $bootstrapped = false;

	/** @var bool */
	private static $hooks_registered = false;

	/** @var bool */
	private static $ready = false;

	/** @var string */
	private static $reason = 'runtime';

	/** @var array|null */
	private static $active = null;

	/** @var array */
	private static $hook_pending = array();

	/** @var array */
	private static $certified_sessions = array();

	/** @var array<string,WP_Error> */
	private static $error_prototypes = array();

	/** Register interception and establish readiness during file inclusion. */
	public static function bootstrap() {
		self::register_hooks();
		$errors_ready = self::prime_error_prototypes();
		if ( self::$bootstrapped ) {
			return;
		}
		self::$bootstrapped = true;

		if ( ! self::load_order_is_valid() ) {
			self::$reason = 'load-order';
			return;
		}
		if ( ! self::runtime_owner_is_valid() ) {
			self::$reason = 'runtime';
			return;
		}
		if ( ! $errors_ready ) {
			self::$reason = 'runtime';
			return;
		}

		$result = self::provision_or_verify_storage();
		if ( 'ready' === $result ) {
			self::$ready  = true;
			self::$reason = 'ready';
			return;
		}

		self::$reason = in_array( $result, array( 'runtime', 'storage', 'cleanup' ), true ) ? $result : 'storage';
	}

	/** @return array */
	public static function status() {
		return array(
			'api_version'    => self::API_VERSION,
			'plugin_version' => LUNARA_CORE_VERSION,
			'ready'          => self::$ready,
			'reason'         => self::$ready ? 'ready' : self::redacted_reason( self::$reason ),
			'held'           => is_array( self::$active ),
		);
	}

	/**
	 * @param string $option Exact theme-mod option.
	 * @param string $purpose Bounded caller purpose.
	 * @return Lunara_Core_Theme_Mods_Coordinator_Lease|WP_Error
	 */
	public static function acquire( $option, $purpose ) {
		if ( ! self::valid_option( $option ) ) {
			return self::error( 'lunara_core_theme_mods_invalid_option' );
		}
		if ( ! self::valid_purpose( $purpose ) ) {
			return self::error( 'lunara_core_theme_mods_invalid_purpose' );
		}
		if ( ! self::$ready ) {
			return self::error( 'lunara_core_theme_mods_unavailable' );
		}
		if ( is_array( self::$active ) ) {
			return self::error( 'lunara_core_theme_mods_already_owned' );
		}

		$sessions = self::open_attested_sessions();
		if ( ! is_array( $sessions ) ) {
			self::$ready  = false;
			self::$reason = in_array( $sessions, array( 'runtime', 'cleanup' ), true ) ? $sessions : 'storage';
			return self::error( 'lunara_core_theme_mods_unavailable' );
		}

		$started    = false;
		$failure    = 'lunara_core_theme_mods_unavailable';
		$cleaned_up = true;
		try {
			if ( 'clear' !== self::live_boundary_state( $sessions ) ) {
				throw new Lunara_Core_Theme_Mods_Coordinator_Storage_Exception( 'invariant' );
			}
			if ( ! self::bound_lock_wait( $sessions['writer'] ) || ! self::transaction_control( $sessions['writer'], 'START TRANSACTION' ) ) {
				throw new Lunara_Core_Theme_Mods_Coordinator_Storage_Exception( 'connection' );
			}
			$started = true;
			if ( ! self::writer_transaction_intact( $sessions, 1 ) ) {
				throw new Lunara_Core_Theme_Mods_Coordinator_Storage_Exception( 'invariant' );
			}

			$marker = self::read_option_row( $sessions['writer'], self::MARKER_OPTION, false );
			if ( ! self::marker_row_valid( $marker ) ) {
				throw new Lunara_Core_Theme_Mods_Coordinator_Storage_Exception( 'invariant' );
			}

			$clear_row = self::read_option_row( $sessions['writer'], self::ROW_OPTION, true );
			if ( ! self::core_clear_row_valid( $clear_row ) ) {
				throw new Lunara_Core_Theme_Mods_Coordinator_Storage_Exception( 'invariant' );
			}
			if ( ! self::writer_transaction_intact( $sessions, 1 ) ) {
				throw new Lunara_Core_Theme_Mods_Coordinator_Storage_Exception( 'invariant' );
			}

			$active_record = self::active_record( $option, $purpose );
			$active_row    = self::replace_row( $sessions['writer'], $clear_row, serialize( $active_record ) );
			if ( ! is_array( $active_row ) ) {
				throw new Lunara_Core_Theme_Mods_Coordinator_Storage_Exception( 'invariant' );
			}
			if ( ! self::writer_transaction_intact( $sessions, 1 ) ) {
				throw new Lunara_Core_Theme_Mods_Coordinator_Storage_Exception( 'invariant' );
			}

			$lease = new Lunara_Core_Theme_Mods_Coordinator_Lease( new stdClass() );
			self::$active = array(
				'lease'         => $lease,
				'option'        => $option,
				'purpose'       => $purpose,
				'references'    => 1,
				'sessions'      => $sessions,
				'clear_row'     => $clear_row,
				'active_row'    => $active_row,
				'active_record' => $active_record,
			);
			return $lease;
		} catch ( Throwable $error ) {
			if ( $started ) {
				self::transaction_control( $sessions['writer'], 'ROLLBACK' );
			}
			$cleaned_up = self::close_sessions( $sessions );
			if ( ! ( $error instanceof Lunara_Core_Theme_Mods_Coordinator_Storage_Exception ) || 'contention' !== $error->category() ) {
				self::$ready  = false;
				self::$reason = 'storage';
			}
		}

		if ( ! $cleaned_up ) {
			self::$ready  = false;
			self::$reason = 'cleanup';
			$failure      = 'lunara_core_theme_mods_cleanup_failed';
		}
		return self::error( $failure );
	}

	/**
	 * @param mixed  $lease Exact issued lease object.
	 * @param string $option Exact original option.
	 * @return Lunara_Core_Theme_Mods_Coordinator_Lease|WP_Error
	 */
	public static function reuse( $lease, $option ) {
		if (
			! self::valid_option( $option )
			|| ! is_array( self::$active )
			|| ! ( $lease instanceof Lunara_Core_Theme_Mods_Coordinator_Lease )
			|| self::$active['lease'] !== $lease
			|| self::$active['option'] !== $option
			|| ! is_int( self::$active['references'] )
			|| 1 > self::$active['references']
			|| self::MAX_REFERENCES <= self::$active['references']
		) {
			return self::error( 'lunara_core_theme_mods_invalid_lease' );
		}

		++self::$active['references'];
		return $lease;
	}

	/**
	 * @param mixed $lease Exact issued lease object.
	 * @return true|WP_Error
	 */
	public static function release( $lease ) {
		if (
			! is_array( self::$active )
			|| ! ( $lease instanceof Lunara_Core_Theme_Mods_Coordinator_Lease )
			|| self::$active['lease'] !== $lease
			|| ! is_int( self::$active['references'] )
			|| 1 > self::$active['references']
		) {
			return self::error( 'lunara_core_theme_mods_invalid_lease' );
		}

		if ( 1 < self::$active['references'] ) {
			--self::$active['references'];
			return true;
		}

		$ownership = self::$active;
		$boundary  = self::live_boundary_state( $ownership['sessions'] );
		if ( 'clear' !== $boundary ) {
			self::$ready  = false;
			self::$reason = 'storage';
			return self::error( 'lunara_core_theme_mods_release_failed' );
		}
		$success     = false;
		$commit_sent = false;
		$committed   = false;
		try {
			if ( ! self::writer_transaction_intact( $ownership['sessions'], 1 ) ) {
				throw new Lunara_Core_Theme_Mods_Coordinator_Storage_Exception( 'invariant' );
			}
			$cleared = self::replace_row( $ownership['sessions']['writer'], $ownership['active_row'], serialize( self::core_clear_record() ) );
			if ( ! is_array( $cleared ) || ! self::writer_transaction_intact( $ownership['sessions'], 1 ) ) {
				throw new Lunara_Core_Theme_Mods_Coordinator_Storage_Exception( 'invariant' );
			}
			$commit_sent = self::transaction_control( $ownership['sessions']['writer'], 'COMMIT' );
			$committed   = $commit_sent && self::writer_transaction_intact( $ownership['sessions'], 0 );
			if ( ! $commit_sent ) {
				self::transaction_control( $ownership['sessions']['writer'], 'ROLLBACK' );
			} elseif ( $committed ) {
				$verified = self::read_option_row( $ownership['sessions']['verifier'], self::ROW_OPTION, false );
				$success  = self::core_clear_row_valid( $verified )
					&& (string) $verified['option_id'] === (string) $ownership['clear_row']['option_id'];
			}
		} catch ( Throwable $error ) {
			if ( ! $commit_sent ) {
				self::transaction_control( $ownership['sessions']['writer'], 'ROLLBACK' );
			}
		}

		$closed       = self::close_sessions( $ownership['sessions'] );
		self::$active = null;
		if ( $success && $closed ) {
			return true;
		}

		self::$ready  = false;
		self::$reason = $closed ? 'storage' : 'cleanup';
		return self::error( $closed ? 'lunara_core_theme_mods_release_failed' : 'lunara_core_theme_mods_cleanup_failed' );
	}

	/** Pre-DML update_option action adapter. */
	public static function before_update( $option, $old_value = null, $value = null ) {
		self::before_dml( 'update', $option );
	}

	/** Pre-DML add_option action adapter. */
	public static function before_add( $option, $value = null ) {
		self::before_dml( 'add', $option );
	}

	/** Pre-DML delete_option action adapter. */
	public static function before_delete( $option ) {
		self::before_dml( 'delete', $option );
	}

	/** Post-DML updated_option action adapter. */
	public static function after_update( $option, $old_value = null, $value = null ) {
		self::after_dml( 'update', $option );
	}

	/** Post-DML added_option action adapter. */
	public static function after_add( $option, $value = null ) {
		self::after_dml( 'add', $option );
	}

	/** Post-DML deleted_option action adapter. */
	public static function after_delete( $option ) {
		self::after_dml( 'delete', $option );
	}

	/** Positively end every request-held reference that lacks a post hook. */
	public static function shutdown() {
		while ( self::$hook_pending ) {
			$pending = array_pop( self::$hook_pending );
			if ( is_array( self::$active ) && isset( $pending['lease'] ) && self::$active['lease'] === $pending['lease'] ) {
				self::release( $pending['lease'] );
			}
		}

		$attempts = 0;
		while ( is_array( self::$active ) && self::MAX_REFERENCES > $attempts ) {
			$lease = self::$active['lease'];
			$before = self::$active['references'];
			$result = self::release( $lease );
			++$attempts;
			if ( $result instanceof WP_Error && is_array( self::$active ) && self::$active['references'] === $before ) {
				break;
			}
		}
		if ( is_array( self::$active ) ) {
			self::$ready  = false;
			self::$reason = 'storage';
		}
	}

	/**
	 * Move Core first while preserving every non-Core plugin's relative order.
	 *
	 * The current activation/deactivation request is not upgraded to ready;
	 * persisted order is re-read so only the next request can prove inclusion.
	 *
	 * @return bool
	 */
	public static function repair_active_plugins_order() {
		if ( ! function_exists( 'get_option' ) || ! function_exists( 'plugin_basename' ) || ! function_exists( 'update_option' ) || ! defined( 'LUNARA_CORE_FILE' ) ) {
			return false;
		}

		$core    = plugin_basename( LUNARA_CORE_FILE );
		$plugins = get_option( 'active_plugins', array() );
		if ( ! is_string( $core ) || '' === $core || ! is_array( $plugins ) ) {
			return false;
		}

		$found     = false;
		$non_core  = array();
		foreach ( array_values( $plugins ) as $plugin ) {
			if ( ! is_string( $plugin ) ) {
				return false;
			}
			if ( $core === $plugin ) {
				$found = true;
				continue;
			}
			$non_core[] = $plugin;
		}

		if ( ! $found ) {
			return true;
		}
		$ordered = array_merge( array( $core ), $non_core );
		if ( $ordered !== $plugins ) {
			try {
				update_option( 'active_plugins', $ordered );
			} catch ( Throwable $error ) {
				return false;
			}
		}

		return $ordered === get_option( 'active_plugins', array() );
	}

	/** Register all generic Options API and lifecycle boundaries once. */
	private static function register_hooks() {
		if ( self::$hooks_registered || ! function_exists( 'add_action' ) ) {
			return;
		}
		self::$hooks_registered = true;

		add_action( 'update_option', array( __CLASS__, 'before_update' ), PHP_INT_MIN, 3 );
		add_action( 'add_option', array( __CLASS__, 'before_add' ), PHP_INT_MIN, 2 );
		add_action( 'delete_option', array( __CLASS__, 'before_delete' ), PHP_INT_MIN, 1 );
		add_action( 'updated_option', array( __CLASS__, 'after_update' ), PHP_INT_MIN, 3 );
		add_action( 'added_option', array( __CLASS__, 'after_add' ), PHP_INT_MIN, 2 );
		add_action( 'deleted_option', array( __CLASS__, 'after_delete' ), PHP_INT_MIN, 1 );
		add_action( 'shutdown', array( __CLASS__, 'shutdown' ), PHP_INT_MAX, 0 );
		add_action( 'activated_plugin', array( __CLASS__, 'repair_active_plugins_order' ), PHP_INT_MAX, 2 );
		add_action( 'deactivated_plugin', array( __CLASS__, 'repair_active_plugins_order' ), PHP_INT_MAX, 2 );
	}

	/**
	 * @param string $operation update|add|delete.
	 * @param mixed  $option Option name.
	 */
	private static function before_dml( $operation, $option ) {
		if ( ! self::valid_option( $option ) ) {
			return;
		}

		$lease = is_array( self::$active )
			? self::reuse( self::$active['lease'], $option )
			: self::acquire( $option, 'options-api-' . $operation );
		if ( $lease instanceof WP_Error ) {
			throw new Lunara_Core_Theme_Mods_Coordinator_Exception( 'Lunara Core theme-mod coordination is unavailable.' );
		}

		self::$hook_pending[] = array(
			'operation' => $operation,
			'option'    => $option,
			'lease'     => $lease,
		);
	}

	/**
	 * @param string $operation update|add|delete.
	 * @param mixed  $option Option name.
	 */
	private static function after_dml( $operation, $option ) {
		if ( ! is_string( $option ) || ! self::$hook_pending ) {
			return;
		}

		for ( $index = count( self::$hook_pending ) - 1; 0 <= $index; --$index ) {
			$pending = self::$hook_pending[ $index ];
			if ( $pending['operation'] !== $operation || $pending['option'] !== $option ) {
				continue;
			}
			array_splice( self::$hook_pending, $index, 1 );
			self::release( $pending['lease'] );
			return;
		}
	}

	/** @return bool */
	private static function load_order_is_valid() {
		if (
			! function_exists( 'did_action' )
			|| ! function_exists( 'get_option' )
			|| ! function_exists( 'plugin_basename' )
			|| ! defined( 'LUNARA_CORE_FILE' )
			|| 0 !== did_action( 'plugin_loaded' )
		) {
			return false;
		}

		$plugins = get_option( 'active_plugins', array() );
		$core    = plugin_basename( LUNARA_CORE_FILE );
		return is_array( $plugins )
			&& isset( $plugins[0] )
			&& is_string( $plugins[0] )
			&& is_string( $core )
			&& '' !== $core
			&& $plugins[0] === $core;
	}

	/** @return bool */
	private static function runtime_owner_is_valid() {
		global $wpdb;

		if ( defined( 'WP_CONTENT_DIR' ) && file_exists( WP_CONTENT_DIR . '/db.php' ) ) {
			return false;
		}
		if (
			! is_object( $wpdb )
			|| 'wpdb' !== get_class( $wpdb )
			|| ! isset( $wpdb->options, $wpdb->prefix )
			|| ! method_exists( $wpdb, 'get_row' )
			|| ! method_exists( $wpdb, 'query' )
			|| 1 !== preg_match( '/^[A-Za-z0-9_]+$/D', (string) $wpdb->options )
			|| 1 !== preg_match( '/^[A-Za-z0-9_]+$/D', (string) $wpdb->prefix )
		) {
			return false;
		}

		if ( self::test_factory_enabled() ) {
			return true;
		}
		return self::native_mysqli_available() && self::live_database_handle() instanceof mysqli;
	}

	/** @return bool */
	private static function valid_option( $option ) {
		return is_string( $option ) && 1 === preg_match( '/^theme_mods_[A-Za-z0-9_-]{1,128}$/D', $option );
	}

	/** @return bool */
	private static function valid_purpose( $purpose ) {
		return is_string( $purpose ) && 1 === preg_match( '/^[A-Za-z0-9][A-Za-z0-9._:-]{0,63}$/D', $purpose );
	}

	/** @return array */
	private static function legacy_clear_record() {
		return array(
			'schema_version' => 1,
			'state'          => 'clear',
			'owner'          => null,
			'action'         => null,
			'acquired_at'    => 0,
		);
	}

	/** @return array */
	private static function core_clear_record() {
		return array(
			'schema_version' => self::SCHEMA_VERSION,
			'state'          => 'clear',
			'owner'          => null,
			'option'         => null,
			'purpose'        => null,
			'acquired_at'    => 0,
		);
	}

	/** @return array */
	private static function marker_record() {
		return array(
			'schema_version'             => 1,
			'coordinator_schema_version' => self::SCHEMA_VERSION,
			'api_version'                => self::API_VERSION,
			'release'                    => '0.8.11',
			'initialized'                => true,
		);
	}

	/**
	 * @param string $option Exact option.
	 * @param string $purpose Bounded purpose.
	 * @return array
	 */
	private static function active_record( $option, $purpose ) {
		$owner = '';
		try {
			$owner = bin2hex( random_bytes( 16 ) );
		} catch ( Throwable $error ) {
			if ( function_exists( 'wp_generate_uuid4' ) ) {
				$owner = (string) wp_generate_uuid4();
			}
		}
		if ( '' === $owner ) {
			throw new RuntimeException( 'Coordinator owner unavailable.' );
		}

		return array(
			'schema_version' => self::SCHEMA_VERSION,
			'state'          => 'active',
			'owner'          => $owner,
			'option'         => $option,
			'purpose'        => $purpose,
			'acquired_at'    => time(),
		);
	}

	/** @return bool */
	private static function nonautoloaded( $autoload ) {
		return is_string( $autoload ) && in_array( $autoload, array( 'no', 'off', 'auto-off' ), true );
	}

	/**
	 * @param array|null $row Physical row.
	 * @return bool
	 */
	private static function core_clear_row_valid( $row ) {
		return self::option_row_valid( $row, self::ROW_OPTION )
			&& self::nonautoloaded( $row['autoload'] )
			&& serialize( self::core_clear_record() ) === $row['option_value'];
	}

	/**
	 * @param array|null $row Physical row.
	 * @return bool
	 */
	private static function legacy_clear_row_valid( $row ) {
		return self::option_row_valid( $row, self::ROW_OPTION )
			&& self::nonautoloaded( $row['autoload'] )
			&& serialize( self::legacy_clear_record() ) === $row['option_value'];
	}

	/**
	 * @param array|null $row Physical row.
	 * @return bool
	 */
	private static function marker_row_valid( $row ) {
		return self::option_row_valid( $row, self::MARKER_OPTION )
			&& self::nonautoloaded( $row['autoload'] )
			&& serialize( self::marker_record() ) === $row['option_value'];
	}

	/**
	 * @param array|null $row Physical option row.
	 * @param string     $name Expected exact name.
	 * @return bool
	 */
	private static function option_row_valid( $row, $name ) {
		return is_array( $row )
			&& array( 'option_id', 'option_name', 'option_value', 'autoload' ) === array_keys( $row )
			&& ( is_string( $row['option_id'] ) || is_int( $row['option_id'] ) )
			&& ctype_digit( (string) $row['option_id'] )
			&& 0 < (int) $row['option_id']
			&& $name === $row['option_name']
			&& is_string( $row['option_value'] )
			&& is_string( $row['autoload'] );
	}

	/** Atomic first-install migration or immutable post-migration verification. */
	private static function provision_or_verify_storage() {
		$sessions = self::open_attested_sessions();
		if ( ! is_array( $sessions ) ) {
			return in_array( $sessions, array( 'runtime', 'cleanup' ), true ) ? $sessions : 'storage';
		}

		$started = false;
		$valid   = false;
		try {
			if ( ! self::bound_lock_wait( $sessions['writer'] ) || ! self::transaction_control( $sessions['writer'], 'START TRANSACTION' ) ) {
				throw new RuntimeException( 'Coordinator migration start failed.' );
			}
			$started = true;
			if ( ! self::writer_transaction_intact( $sessions, 1 ) ) {
				throw new RuntimeException( 'Coordinator migration transaction identity unavailable.' );
			}
			$rows    = self::read_storage_rows( $sessions['writer'], true );
			if ( ! self::writer_transaction_intact( $sessions, 1 ) ) {
				throw new RuntimeException( 'Coordinator migration lock identity unavailable.' );
			}
			$row     = isset( $rows[ self::ROW_OPTION ] ) ? $rows[ self::ROW_OPTION ] : null;
			$marker  = isset( $rows[ self::MARKER_OPTION ] ) ? $rows[ self::MARKER_OPTION ] : null;

			if ( null !== $marker ) {
				if ( ! self::marker_row_valid( $marker ) || ! self::core_clear_row_valid( $row ) ) {
					throw new RuntimeException( 'Coordinator storage is not canonical.' );
				}
			} else {
				// A Core-shaped row without its marker is detectable marker loss,
				// never a first install. Only absent or exact schema-1 legacy input
				// may enter this one atomic migration.
				if ( null === $row ) {
					$row = self::insert_row( $sessions['writer'], self::ROW_OPTION, serialize( self::core_clear_record() ), 'no' );
				} elseif ( self::legacy_clear_row_valid( $row ) ) {
					$row = self::replace_row( $sessions['writer'], $row, serialize( self::core_clear_record() ) );
				} else {
					throw new RuntimeException( 'Coordinator migration source is not admissible.' );
				}
				if ( ! is_array( $row ) ) {
					throw new RuntimeException( 'Coordinator row migration failed.' );
				}
				if ( ! self::writer_transaction_intact( $sessions, 1 ) ) {
					throw new RuntimeException( 'Coordinator row migration transaction changed.' );
				}
				$marker = self::insert_row( $sessions['writer'], self::MARKER_OPTION, serialize( self::marker_record() ), 'no' );
				if ( ! is_array( $marker ) ) {
					throw new RuntimeException( 'Coordinator marker migration failed.' );
				}
				if ( ! self::writer_transaction_intact( $sessions, 1 ) ) {
					throw new RuntimeException( 'Coordinator marker migration transaction changed.' );
				}
			}

			$inside = self::read_storage_rows( $sessions['writer'], false );
			if (
				! isset( $inside[ self::ROW_OPTION ], $inside[ self::MARKER_OPTION ] )
				|| ! self::core_clear_row_valid( $inside[ self::ROW_OPTION ] )
				|| ! self::marker_row_valid( $inside[ self::MARKER_OPTION ] )
			) {
				throw new RuntimeException( 'Coordinator migration readback failed.' );
			}
			if ( ! self::writer_transaction_intact( $sessions, 1 ) ) {
				throw new RuntimeException( 'Coordinator migration readback transaction changed.' );
			}

			if ( ! self::transaction_control( $sessions['writer'], 'COMMIT' ) ) {
				throw new RuntimeException( 'Coordinator migration commit failed.' );
			}
			$started = false;
			if ( ! self::writer_transaction_intact( $sessions, 0 ) ) {
				throw new RuntimeException( 'Coordinator migration commit identity changed.' );
			}

			$outside = self::read_storage_rows( $sessions['verifier'], false );
			$valid   = isset( $outside[ self::ROW_OPTION ], $outside[ self::MARKER_OPTION ] )
				&& self::core_clear_row_valid( $outside[ self::ROW_OPTION ] )
				&& self::marker_row_valid( $outside[ self::MARKER_OPTION ] );
		} catch ( Throwable $error ) {
			if ( $started ) {
				self::transaction_control( $sessions['writer'], 'ROLLBACK' );
			}
			$valid = false;
		}

		$closed = self::close_sessions( $sessions );
		if ( ! $closed ) {
			return 'cleanup';
		}
		return $valid ? 'ready' : 'storage';
	}

	/**
	 * @param object $session Certified raw session.
	 * @param bool   $for_update Whether both names are locked.
	 * @return array<string,array>
	 */
	private static function read_storage_rows( $session, $for_update ) {
		$table = self::raw_options_table( $session );
		if ( '' === $table ) {
			throw new RuntimeException( 'Coordinator table unavailable.' );
		}

		$sql = "SELECT option_id, option_name, option_value, autoload FROM {$table} WHERE option_name IN (?, ?) ORDER BY option_name";
		if ( $for_update ) {
			$sql .= ' FOR UPDATE';
		}
		$rows = self::raw_rows( $session, $sql, array( self::ROW_OPTION, self::MARKER_OPTION ) );
		$map  = array();
		foreach ( $rows as $row ) {
			if (
				! is_array( $row )
				|| array( 'option_id', 'option_name', 'option_value', 'autoload' ) !== array_keys( $row )
				|| ! in_array( $row['option_name'], array( self::ROW_OPTION, self::MARKER_OPTION ), true )
				|| isset( $map[ $row['option_name'] ] )
			) {
				throw new RuntimeException( 'Coordinator storage inventory is ambiguous.' );
			}
			$map[ $row['option_name'] ] = $row;
		}
		return $map;
	}

	/**
	 * @param object $session Certified raw session.
	 * @param string $name Exact option name.
	 * @param bool   $for_update Whether to acquire its row lock.
	 * @return array|null
	 */
	private static function read_option_row( $session, $name, $for_update ) {
		$table = self::raw_options_table( $session );
		if ( '' === $table ) {
			throw new RuntimeException( 'Coordinator table unavailable.' );
		}
		$sql = "SELECT option_id, option_name, option_value, autoload FROM {$table} WHERE option_name = ? LIMIT 1";
		if ( $for_update ) {
			$sql .= ' FOR UPDATE';
		}
		$row = self::raw_row( $session, $sql, array( $name ) );
		if ( null !== $row && ! self::option_row_valid( $row, $name ) ) {
			throw new RuntimeException( 'Coordinator row shape is invalid.' );
		}
		return $row;
	}

	/**
	 * Exact physical CAS preserving option identity and autoload enum.
	 *
	 * @param object $session Certified writer.
	 * @param array  $before Exact prior row.
	 * @param string $value Exact serialized value.
	 * @return array|false
	 */
	private static function replace_row( $session, $before, $value ) {
		if ( ! is_array( $before ) || ! is_string( $value ) || ! isset( $before['option_name'] ) || ! self::option_row_valid( $before, $before['option_name'] ) ) {
			return false;
		}
		$table = self::raw_options_table( $session );
		$sql   = "UPDATE {$table} SET option_value = ? WHERE option_id = ? AND option_name = ? AND option_name = CAST(? AS BINARY) AND option_value = CAST(? AS BINARY) AND autoload = CAST(? AS BINARY) LIMIT 1";
		$result = self::raw_exec(
			$session,
			$sql,
			array(
				$value,
				(string) $before['option_id'],
				$before['option_name'],
				$before['option_name'],
				$before['option_value'],
				$before['autoload'],
			)
		);
		if ( 1 !== $result['affected_rows'] ) {
			return false;
		}
		$after                 = $before;
		$after['option_value'] = $value;
		return $after;
	}

	/**
	 * @param object $session Certified writer.
	 * @param string $name Exact option name.
	 * @param string $value Exact serialized value.
	 * @param string $autoload Exact nonautoload enum.
	 * @return array|false
	 */
	private static function insert_row( $session, $name, $value, $autoload ) {
		if ( ! is_string( $name ) || ! is_string( $value ) || ! self::nonautoloaded( $autoload ) ) {
			return false;
		}
		$table  = self::raw_options_table( $session );
		$sql    = "INSERT INTO {$table} (option_name, option_value, autoload) VALUES (?, ?, ?)";
		$result = self::raw_exec( $session, $sql, array( $name, $value, $autoload ) );
		if ( 1 !== $result['affected_rows'] || ! ctype_digit( $result['insert_id'] ) || 0 >= (int) $result['insert_id'] ) {
			return false;
		}
		return array(
			'option_id'    => $result['insert_id'],
			'option_name'  => $name,
			'option_value' => $value,
			'autoload'     => $autoload,
		);
	}

	/** @return bool */
	private static function bound_lock_wait( $session ) {
		try {
			self::raw_exec( $session, 'SET SESSION innodb_lock_wait_timeout = 3' );
			self::raw_exec( $session, 'SET SESSION lock_wait_timeout = 3' );
			return true;
		} catch ( Throwable $error ) {
			return false;
		}
	}

	/** @return bool */
	private static function transaction_control( $session, $command ) {
		if ( ! in_array( $command, array( 'START TRANSACTION', 'COMMIT', 'ROLLBACK' ), true ) ) {
			return false;
		}
		try {
			self::raw_exec( $session, $command );
			return true;
		} catch ( Throwable $error ) {
			return false;
		}
	}

	/** @return array|string Attested sessions or bounded internal reason. */
	private static function open_attested_sessions() {
		$live_profile = self::live_owner_profile();
		if ( ! is_array( $live_profile ) ) {
			return 'runtime';
		}
		$bundle = self::test_factory_enabled() ? self::open_test_sessions() : self::open_native_sessions( $live_profile );
		if ( ! is_array( $bundle ) ) {
			return 'runtime';
		}
		$bundle['live_profile'] = $live_profile;

		try {
			$attested = self::attest_sessions( $bundle );
		} catch ( Throwable $error ) {
			$attested = false;
		}
		if ( is_array( $attested ) ) {
			if ( 'clear' === self::live_boundary_state( $attested ) ) {
				return $attested;
			}
			$bundle = $attested;
		}

		$closed = self::close_sessions( $bundle );
		return $closed ? 'storage' : 'cleanup';
	}

	/** @return bool */
	private static function test_factory_enabled() {
		return defined( 'LUNARA_CORE_THEME_MODS_COORDINATOR_TESTING' )
			&& true === LUNARA_CORE_THEME_MODS_COORDINATOR_TESTING
			&& 'cli' === PHP_SAPI
			&& isset( $GLOBALS['lunara_core_theme_mods_coordinator_test_session_factory'] )
			&& is_callable( $GLOBALS['lunara_core_theme_mods_coordinator_test_session_factory'] );
	}

	/** @return array|false */
	private static function open_test_sessions() {
		try {
			$bundle = call_user_func( $GLOBALS['lunara_core_theme_mods_coordinator_test_session_factory'] );
		} catch ( Throwable $error ) {
			return false;
		}
		return is_array( $bundle )
			&& isset( $bundle['writer'], $bundle['verifier'], $bundle['watchdog'], $bundle['contract'] )
			&& 'lunara-core-theme-mods-test-v1' === $bundle['contract']
			? $bundle
			: false;
	}

	/** @return array|false */
	private static function open_native_sessions( $live_profile ) {
		global $wpdb;

		if ( ! self::native_mysqli_available() ) {
			return false;
		}
		$no_reconnect_fact = self::native_no_reconnect_fact();
		if ( ! is_array( $no_reconnect_fact ) ) {
			return false;
		}

		$handles = array();
		try {
			foreach ( array( 'writer', 'verifier', 'watchdog' ) as $role ) {
				$handle = self::connect_native_handle( $live_profile, $no_reconnect_fact );
				if ( ! ( $handle instanceof mysqli ) ) {
					throw new RuntimeException( 'Native coordinator connection unavailable.' );
				}
				$handles[ $role ] = $handle;
			}

			$bundle = array(
				'writer'   => new Lunara_Core_Theme_Mods_Native_MySQLi( $handles['writer'], (string) $wpdb->options, (string) $wpdb->prefix, $no_reconnect_fact ),
				'verifier' => new Lunara_Core_Theme_Mods_Native_MySQLi( $handles['verifier'], (string) $wpdb->options, (string) $wpdb->prefix, $no_reconnect_fact ),
				'watchdog' => new Lunara_Core_Theme_Mods_Native_MySQLi( $handles['watchdog'], (string) $wpdb->options, (string) $wpdb->prefix, $no_reconnect_fact ),
			);
			return $bundle;
		} catch ( Throwable $error ) {
			foreach ( $handles as $handle ) {
				if ( $handle instanceof mysqli ) {
					try {
						mysqli_close( $handle );
					} catch ( Throwable $close_error ) {
						// No transaction or row lock has begun.
					}
				}
			}
			return false;
		}
	}

	/** @return mysqli|false */
	private static function connect_native_handle( $live_profile, $no_reconnect_fact ) {
		if ( ! defined( 'DB_HOST' ) || ! defined( 'DB_USER' ) || ! defined( 'DB_PASSWORD' ) || ! defined( 'DB_NAME' ) ) {
			return false;
		}
		$connection = self::native_connection_parameters(
			(string) DB_HOST,
			is_array( $no_reconnect_fact ) && 'mysqlnd' === $no_reconnect_fact['driver'],
			is_array( $live_profile ) && isset( $live_profile['connection_type'] ) ? $live_profile['connection_type'] : null
		);
		if ( ! is_array( $connection ) ) {
			return false;
		}

		$handle = mysqli_init();
		if ( ! ( $handle instanceof mysqli ) ) {
			return false;
		}
		if ( defined( 'MYSQLI_OPT_CONNECT_TIMEOUT' ) ) {
			mysqli_options( $handle, MYSQLI_OPT_CONNECT_TIMEOUT, 3 );
		}
		try {
			$connected = @mysqli_real_connect( // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
				$handle,
				$connection['host'],
				(string) DB_USER,
				(string) DB_PASSWORD,
				(string) DB_NAME,
				$connection['port'],
				$connection['socket'],
				$connection['client_flags']
			);
		} catch ( Throwable $error ) {
			$connected = false;
		}
		if ( true !== $connected ) {
			try {
				mysqli_close( $handle );
			} catch ( Throwable $error ) {
				// No transaction or row lock has begun.
			}
			return false;
		}
		try {
			$client_info = mysqli_get_client_info();
		} catch ( Throwable $error ) {
			$client_info = false;
		}
		if ( ! is_string( $client_info ) || ! is_array( $no_reconnect_fact ) || hash( 'sha256', $client_info ) !== $no_reconnect_fact['client_info_fingerprint'] ) {
			try {
				mysqli_close( $handle );
			} catch ( Throwable $error ) {
				// No transaction or row lock has begun.
			}
			return false;
		}
		return $handle;
	}

	/**
	 * Parse WordPress's conventional host[:port|:socket] forms without reading
	 * environment variables or persistent configuration.
	 *
	 * @param string $db_host In-memory DB_HOST value.
	 * @return array|false
	 */
	private static function parse_db_host( $db_host ) {
		if ( '' === $db_host || 512 < strlen( $db_host ) || false !== strpos( $db_host, "\0" ) ) {
			return false;
		}

		$host       = $db_host;
		$port       = null;
		$socket     = null;
		$is_ipv6    = false;
		$socket_pos = strpos( $host, ':/' );
		if ( false !== $socket_pos ) {
			if ( false !== strpos( $host, ':/', $socket_pos + 2 ) ) {
				return false;
			}
			$socket = substr( $host, $socket_pos + 1 );
			$host   = substr( $host, 0, $socket_pos );
		}

		$match = array();
		if ( 1 < substr_count( $host, ':' ) ) {
			$is_ipv6 = true;
			$pattern = '[' === substr( $host, 0, 1 )
				? '/^\[([0-9a-fA-F:]+)\](?::([0-9]+))?$/D'
				: '/^([0-9a-fA-F:]+)$/D';
		} else {
			$pattern = '/^([^:\/\[\]\s]+)(?::([0-9]+))?$/D';
		}
		if ( 1 !== preg_match( $pattern, $host, $match ) ) {
			return false;
		}
		$host = isset( $match[1] ) ? $match[1] : '';
		if ( isset( $match[2] ) && '' !== $match[2] ) {
			$port = (int) $match[2];
		}

		if (
			'' === $host
			|| ( $is_ipv6 && false === filter_var( $host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6 ) )
			|| ( null !== $port && ( 1 > $port || 65535 < $port ) )
			|| ( null !== $socket && ( '/' !== substr( $socket, 0, 1 ) || 512 < strlen( $socket ) || false !== strpos( $socket, "\0" ) ) )
		) {
			return false;
		}
		return array( 'host' => $host, 'port' => $port, 'socket' => $socket, 'is_ipv6' => $is_ipv6 );
	}

	/**
	 * Derive a bounded no-auto-reconnect fact from independently supplied
	 * driver/runtime/config observations. Ambiguity is always unsupported.
	 *
	 * @return array|false
	 */
	private static function reconnect_safety_fact( $php_version_id, $client_info, $mysqlnd_loaded, $directive_exists, $directive_value ) {
		if (
			! is_int( $php_version_id )
			|| 70000 > $php_version_id
			|| ! is_string( $client_info )
			|| '' === $client_info
			|| 512 < strlen( $client_info )
			|| false !== strpos( $client_info, "\0" )
			|| ! is_bool( $mysqlnd_loaded )
			|| ! is_bool( $directive_exists )
		) {
			return false;
		}
		$info_is_mysqlnd = 1 === preg_match( '/^mysqlnd(?:\s|$)/iD', $client_info );
		if ( $info_is_mysqlnd !== $mysqlnd_loaded ) {
			return false;
		}
		$driver = $info_is_mysqlnd ? 'mysqlnd' : 'libmysqlclient';
		if ( 80200 <= $php_version_id ) {
			$reconnect = 'php-8.2+';
		} elseif ( 'mysqlnd' === $driver ) {
			$reconnect = 'mysqlnd-ignored';
		} elseif ( $directive_exists && ( 0 === $directive_value || '0' === $directive_value ) ) {
			$reconnect = 'disabled';
		} else {
			return false;
		}
		return array(
			'driver'                  => $driver,
			'php_version_id'          => $php_version_id,
			'client_info_fingerprint' => hash( 'sha256', $client_info ),
			'reconnect'               => $reconnect,
		);
	}

	/** Establish the actual process-wide mysqli reconnect guarantee once. */
	private static function native_no_reconnect_fact() {
		try {
			$client_info = mysqli_get_client_info();
			$settings    = ini_get_all( 'mysqli', false );
		} catch ( Throwable $error ) {
			return false;
		}
		$directive_exists = is_array( $settings ) && array_key_exists( 'mysqli.reconnect', $settings );
		$directive_value  = $directive_exists ? $settings['mysqli.reconnect'] : null;
		return self::reconnect_safety_fact(
			PHP_VERSION_ID,
			$client_info,
			extension_loaded( 'mysqlnd' ),
			$directive_exists,
			$directive_value
		);
	}

	/** Mirror stock WordPress's mysqlnd IPv6 and MYSQL_CLIENT_FLAGS behavior. */
	private static function native_connection_parameters( $db_host, $is_mysqlnd, $live_transport ) {
		$parsed = self::parse_db_host( $db_host );
		$flags  = defined( 'MYSQL_CLIENT_FLAGS' ) ? MYSQL_CLIENT_FLAGS : 0;
		if (
			! is_array( $parsed )
			|| ! is_bool( $is_mysqlnd )
			|| ! is_int( $flags )
			|| 0 > $flags
			|| ! is_string( $live_transport )
			|| ! in_array( $live_transport, array( 'TCP/IP', 'SSL/TLS', 'Socket', 'Named Pipe', 'Shared Memory' ), true )
			|| ( 'SSL/TLS' === $live_transport && ( ! defined( 'MYSQLI_CLIENT_SSL' ) || 0 === ( $flags & MYSQLI_CLIENT_SSL ) ) )
			|| ( 'Socket' === $live_transport && null === $parsed['socket'] && 'localhost' !== strtolower( $parsed['host'] ) )
		) {
			return false;
		}
		$host = $parsed['host'];
		if ( $parsed['is_ipv6'] && $is_mysqlnd ) {
			$host = '[' . $host . ']';
		}
		return array(
			'host'         => $host,
			'port'         => $parsed['port'],
			'socket'       => $parsed['socket'],
			'client_flags' => $flags,
		);
	}

	/** Read the stock wpdb mysqli owner without returning or retaining it publicly. */
	private static function live_database_handle() {
		global $wpdb;

		if ( self::test_factory_enabled() || ! is_object( $wpdb ) || 'wpdb' !== get_class( $wpdb ) ) {
			return false;
		}
		try {
			$reflection = new ReflectionObject( $wpdb );
			if ( ! $reflection->hasProperty( 'dbh' ) ) {
				return false;
			}
			$property = $reflection->getProperty( 'dbh' );
			$property->setAccessible( true );
			$handle = $property->getValue( $wpdb );
		} catch ( Throwable $error ) {
			return false;
		}
		return $handle instanceof mysqli ? $handle : false;
	}

	/** Freeze the live handle object without exposing the handle itself. */
	private static function live_handle_identity() {
		global $wpdb;

		if ( self::test_factory_enabled() ) {
			return is_object( $wpdb ) ? 'test:' . spl_object_hash( $wpdb ) : false;
		}
		$handle = self::live_database_handle();
		return $handle instanceof mysqli ? spl_object_hash( $handle ) : false;
	}

	/** Run one bounded callback-free row query on the exact live owner. */
	private static function live_profile_row( $sql ) {
		global $wpdb;

		if ( ! is_object( $wpdb ) || 'wpdb' !== get_class( $wpdb ) || ! is_string( $sql ) || '' === $sql ) {
			return false;
		}
		if ( ! self::test_factory_enabled() ) {
			$handle = self::live_database_handle();
			if ( ! ( $handle instanceof mysqli ) ) {
				return false;
			}
			$result = null;
			try {
				$result = mysqli_query( $handle, $sql );
				if ( ! ( $result instanceof mysqli_result ) ) {
					return false;
				}
				$row  = mysqli_fetch_assoc( $result );
				$next = mysqli_fetch_assoc( $result );
			} catch ( Throwable $error ) {
				return false;
			} finally {
				if ( $result instanceof mysqli_result ) {
					mysqli_free_result( $result );
				}
			}
			return null === $next && self::live_database_handle() === $handle && ( null === $row || is_array( $row ) ) ? $row : false;
		}
		if ( ! method_exists( $wpdb, 'get_row' ) ) {
			return false;
		}
		try {
			if ( property_exists( $wpdb, 'last_error' ) ) {
				$wpdb->last_error = '';
			}
			$row = $wpdb->get_row( $sql, defined( 'ARRAY_A' ) ? ARRAY_A : 'ARRAY_A' );
		} catch ( Throwable $error ) {
			return false;
		}
		if ( property_exists( $wpdb, 'last_error' ) && '' !== (string) $wpdb->last_error ) {
			return false;
		}
		return is_array( $row ) ? $row : null;
	}

	/** Query and freeze the exact stock live owner. */
	private static function live_owner_profile() {
		global $wpdb;

		if ( ! is_object( $wpdb ) || 'wpdb' !== get_class( $wpdb ) || ! isset( $wpdb->options, $wpdb->prefix ) || ! method_exists( $wpdb, 'get_row' ) ) {
			return false;
		}
		$object  = spl_object_hash( $wpdb );
		$handle  = self::live_handle_identity();
		$table   = (string) $wpdb->options;
		$prefix  = (string) $wpdb->prefix;
		$row     = self::live_profile_row( 'SELECT CONNECTION_ID() AS connection_id, DATABASE() AS database_name, @@session.autocommit AS autocommit, @@session.in_transaction AS in_transaction, @@global.read_only AS read_only, @@hostname AS server_hostname, @@port AS server_port, @@server_id AS server_id, CURRENT_USER() AS current_user' );
		if ( ! is_array( $row ) ) {
			return false;
		}
		$access = self::live_profile_row( 'SELECT @@session.transaction_read_only AS transaction_read_only' );
		if ( ! is_array( $access ) ) {
			$access = self::live_profile_row( 'SELECT @@session.tx_read_only AS transaction_read_only' );
		}
		$super = self::live_profile_row( "SHOW GLOBAL VARIABLES LIKE 'super_read_only'" );
		if ( null === $super ) {
			$super = array( 'Variable_name' => 'super_read_only', 'Value' => '0' );
		}
		$transport = self::live_profile_row( 'SELECT CONNECTION_TYPE AS connection_type FROM performance_schema.threads WHERE PROCESSLIST_ID = CONNECTION_ID() LIMIT 1' );
		$ssl       = self::live_profile_row( "SHOW SESSION STATUS LIKE 'Ssl_cipher'" );
		if (
			! is_array( $access )
			|| array( 'transaction_read_only' ) !== array_keys( $access )
			|| ! is_array( $super )
			|| array( 'Variable_name', 'Value' ) !== array_keys( $super )
			|| 'super_read_only' !== strtolower( (string) $super['Variable_name'] )
			|| ! is_array( $transport )
			|| array( 'connection_type' ) !== array_keys( $transport )
			|| ! is_array( $ssl )
			|| array( 'Variable_name', 'Value' ) !== array_keys( $ssl )
			|| 'ssl_cipher' !== strtolower( (string) $ssl['Variable_name'] )
		) {
			return false;
		}
		$super_read_only = self::normalize_server_switch( $super['Value'] );
		if ( null === $super_read_only ) {
			return false;
		}
		$row['transaction_read_only'] = $access['transaction_read_only'];
		$row['super_read_only']       = $super_read_only;
		$row['connection_type']       = $transport['connection_type'];
		$row['ssl_cipher']            = $ssl['Value'];
		$profile = self::normalize_profile( $row );
		if (
			! is_array( $profile )
			|| ! is_object( $wpdb )
			|| 'wpdb' !== get_class( $wpdb )
			|| spl_object_hash( $wpdb ) !== $object
			|| false === $handle
			|| self::live_handle_identity() !== $handle
			|| ! isset( $wpdb->options, $wpdb->prefix )
			|| (string) $wpdb->options !== $table
			|| (string) $wpdb->prefix !== $prefix
		) {
			return false;
		}
		return $profile;
	}

	/** @return array|false */
	private static function attest_sessions( $bundle ) {
		global $wpdb;

		if ( ! is_array( $bundle ) || ! isset( $bundle['writer'], $bundle['verifier'], $bundle['watchdog'], $bundle['live_profile'] ) ) {
			return false;
		}

		$profiles = array();
		foreach ( array( 'writer', 'verifier', 'watchdog' ) as $role ) {
			if ( ! self::certify_session( $bundle[ $role ] ) ) {
				return false;
			}
			$profiles[ $role ] = self::database_profile( $bundle[ $role ] );
			if ( ! is_array( $profiles[ $role ] ) ) {
				return false;
			}
		}
		$live = self::normalize_profile( $bundle['live_profile'] );
		if (
			! is_array( $live )
			|| 1 !== $live['autocommit']
			|| 0 !== $live['in_transaction']
			|| 0 !== $live['read_only']
			|| 0 !== $live['transaction_read_only']
			|| 0 !== $live['super_read_only']
		) {
			return false;
		}

		$ids = array( $live['connection_id'], $profiles['writer']['connection_id'], $profiles['verifier']['connection_id'], $profiles['watchdog']['connection_id'] );
		if ( 4 !== count( array_unique( $ids ) ) || 0 >= min( $ids ) ) {
			return false;
		}
		$server = array( $live['server_hostname'], $live['server_port'], $live['server_id'] );
		$table  = (string) $wpdb->options;
		foreach ( array( 'writer', 'verifier', 'watchdog' ) as $role ) {
			$profile = $profiles[ $role ];
			if (
				$profile['database_name'] !== $live['database_name']
				|| array( $profile['server_hostname'], $profile['server_port'], $profile['server_id'] ) !== $server
				|| $profile['current_user'] !== $live['current_user']
				|| 1 !== $profile['autocommit']
				|| 0 !== $profile['in_transaction']
				|| 0 !== $profile['read_only']
				|| 0 !== $profile['transaction_read_only']
				|| 0 !== $profile['super_read_only']
				|| $profile['connection_type'] !== $live['connection_type']
				|| ( '' === $profile['ssl_cipher'] ) !== ( '' === $live['ssl_cipher'] )
				|| self::raw_options_table( $bundle[ $role ] ) !== $table
				|| ! self::options_table_is_innodb( $bundle[ $role ], $profile['database_name'], $table )
			) {
				return false;
			}
		}

		$visible = self::raw_rows(
			$bundle['watchdog'],
			'SELECT ID FROM information_schema.PROCESSLIST WHERE ID IN (?, ?) ORDER BY ID',
			array( (string) $profiles['writer']['connection_id'], (string) $profiles['verifier']['connection_id'] )
		);
		$visible_ids = array();
		foreach ( $visible as $row ) {
			if ( ! is_array( $row ) || array( 'ID' ) !== array_keys( $row ) || ! ctype_digit( (string) $row['ID'] ) || 0 >= (int) $row['ID'] ) {
				return false;
			}
			$visible_ids[] = (int) $row['ID'];
		}
		sort( $visible_ids );
		$expected_visible = array( $profiles['writer']['connection_id'], $profiles['verifier']['connection_id'] );
		sort( $expected_visible );
		if ( $visible_ids !== $expected_visible ) {
			return false;
		}
		if ( ! self::native_write_proof( $bundle['writer'] ) || ! self::live_write_proof( $live ) ) {
			return false;
		}

		$bundle['live_profile']          = $live;
		$bundle['live_binding']          = array(
			'object'  => spl_object_hash( $wpdb ),
			'handle'  => self::live_handle_identity(),
			'options' => (string) $wpdb->options,
			'prefix'  => (string) $wpdb->prefix,
		);
		$bundle['profiles']                        = $profiles;
		$bundle['watchdog_visible_ids']            = $visible_ids;
		$bundle['watchdog_writer_identity_valid']  = true;
		return $bundle;
	}

	/**
	 * Positively prove the live WordPress owner is unchanged and outside a
	 * transaction. A later explicit transaction retains the native row lock.
	 *
	 * @param array $sessions Attested session bundle.
	 * @return string clear|transaction|invalid
	 */
	private static function live_boundary_state( $sessions ) {
		global $wpdb;

		if (
			! is_array( $sessions )
			|| ! isset( $sessions['live_profile'], $sessions['live_binding'] )
			|| ! is_array( $sessions['live_profile'] )
			|| ! is_array( $sessions['live_binding'] )
			|| array( 'object', 'handle', 'options', 'prefix' ) !== array_keys( $sessions['live_binding'] )
			|| ! is_object( $wpdb )
			|| 'wpdb' !== get_class( $wpdb )
			|| ! isset( $wpdb->options, $wpdb->prefix )
			|| spl_object_hash( $wpdb ) !== $sessions['live_binding']['object']
			|| false === $sessions['live_binding']['handle']
			|| self::live_handle_identity() !== $sessions['live_binding']['handle']
			|| (string) $wpdb->options !== $sessions['live_binding']['options']
			|| (string) $wpdb->prefix !== $sessions['live_binding']['prefix']
		) {
			return 'invalid';
		}

		$current = self::live_owner_profile();
		$before  = self::normalize_profile( $sessions['live_profile'] );
		if ( ! is_array( $current ) || ! is_array( $before ) ) {
			return 'invalid';
		}

		$transaction = $current['in_transaction'];
		$current['in_transaction'] = $before['in_transaction'];
		if ( $current !== $before || 1 !== $current['autocommit'] ) {
			return 'invalid';
		}
		return 0 === $transaction ? 'clear' : 'transaction';
	}

	/** @return array|false */
	private static function database_profile( $session ) {
		try {
			$row = self::raw_row(
				$session,
				'SELECT CONNECTION_ID() AS connection_id, DATABASE() AS database_name, @@session.autocommit AS autocommit, @@session.in_transaction AS in_transaction, @@global.read_only AS read_only, @@hostname AS server_hostname, @@port AS server_port, @@server_id AS server_id, CURRENT_USER() AS current_user'
			);
		} catch ( Throwable $error ) {
			return false;
		}
		try {
			$access = self::raw_row( $session, 'SELECT @@session.transaction_read_only AS transaction_read_only' );
		} catch ( Throwable $error ) {
			$access = false;
		}
		if ( ! is_array( $access ) ) {
			try {
				$access = self::raw_row( $session, 'SELECT @@session.tx_read_only AS transaction_read_only' );
			} catch ( Throwable $error ) {
				$access = false;
			}
		}
		try {
			$super = self::raw_row( $session, "SHOW GLOBAL VARIABLES LIKE 'super_read_only'" );
			if ( null === $super ) {
				$super = array( 'Variable_name' => 'super_read_only', 'Value' => '0' );
			}
			$transport = self::raw_row( $session, 'SELECT CONNECTION_TYPE AS connection_type FROM performance_schema.threads WHERE PROCESSLIST_ID = CONNECTION_ID() LIMIT 1' );
			$ssl       = self::raw_row( $session, "SHOW SESSION STATUS LIKE 'Ssl_cipher'" );
		} catch ( Throwable $error ) {
			return false;
		}
		if (
			! is_array( $row )
			|| ! is_array( $access )
			|| array( 'transaction_read_only' ) !== array_keys( $access )
			|| ! is_array( $super )
			|| array( 'Variable_name', 'Value' ) !== array_keys( $super )
			|| 'super_read_only' !== strtolower( (string) $super['Variable_name'] )
			|| ! is_array( $transport )
			|| array( 'connection_type' ) !== array_keys( $transport )
			|| ! is_array( $ssl )
			|| array( 'Variable_name', 'Value' ) !== array_keys( $ssl )
			|| 'ssl_cipher' !== strtolower( (string) $ssl['Variable_name'] )
		) {
			return false;
		}
		$super_read_only = self::normalize_server_switch( $super['Value'] );
		if ( null === $super_read_only ) {
			return false;
		}
		$row['transaction_read_only'] = $access['transaction_read_only'];
		$row['super_read_only']       = $super_read_only;
		$row['connection_type']       = $transport['connection_type'];
		$row['ssl_cipher']            = $ssl['Value'];
		return self::normalize_profile( $row );
	}

	/** @return array|false */
	private static function normalize_profile( $row ) {
		$keys = array( 'connection_id', 'database_name', 'autocommit', 'in_transaction', 'read_only', 'server_hostname', 'server_port', 'server_id', 'current_user', 'transaction_read_only', 'super_read_only', 'connection_type', 'ssl_cipher' );
		if ( ! is_array( $row ) || $keys !== array_keys( $row ) ) {
			return false;
		}
		foreach ( $row as $value ) {
			if ( ! is_string( $value ) && ! is_int( $value ) ) {
				return false;
			}
		}
		foreach ( array( 'connection_id', 'autocommit', 'in_transaction', 'read_only', 'server_port', 'server_id', 'transaction_read_only', 'super_read_only' ) as $numeric ) {
			if ( ! ctype_digit( (string) $row[ $numeric ] ) ) {
				return false;
			}
			$row[ $numeric ] = (int) $row[ $numeric ];
		}
		foreach ( array( 'database_name', 'server_hostname', 'current_user' ) as $text ) {
			$row[ $text ] = (string) $row[ $text ];
			if ( '' === $row[ $text ] || 512 < strlen( $row[ $text ] ) ) {
				return false;
			}
		}
		$row['connection_type'] = (string) $row['connection_type'];
		$row['ssl_cipher']      = (string) $row['ssl_cipher'];
		if (
			! in_array( $row['connection_type'], array( 'TCP/IP', 'SSL/TLS', 'Socket', 'Named Pipe', 'Shared Memory' ), true )
			|| 255 < strlen( $row['ssl_cipher'] )
			|| false !== strpos( $row['ssl_cipher'], "\0" )
			|| ( 'SSL/TLS' === $row['connection_type'] ) !== ( '' !== $row['ssl_cipher'] )
		) {
			return false;
		}
		return 0 < $row['connection_id'] && 0 < $row['server_port'] && 0 <= $row['server_id'] ? $row : false;
	}

	/** Normalize SHOW VARIABLES boolean spellings without accepting ambiguity. */
	private static function normalize_server_switch( $value ) {
		if ( ! is_string( $value ) && ! is_int( $value ) ) {
			return null;
		}
		$value = strtoupper( trim( (string) $value ) );
		if ( in_array( $value, array( '0', 'OFF' ), true ) ) {
			return '0';
		}
		if ( in_array( $value, array( '1', 'ON' ), true ) ) {
			return '1';
		}
		return null;
	}

	/** Read the minimum callback-free native transaction/identity proof. */
	private static function native_transaction_snapshot( $session ) {
		try {
			$row = self::raw_row(
				$session,
				'SELECT CONNECTION_ID() AS coordinator_connection_id, @@session.autocommit AS coordinator_autocommit, @@session.in_transaction AS coordinator_in_transaction'
			);
		} catch ( Throwable $error ) {
			return false;
		}
		if (
			! is_array( $row )
			|| array( 'coordinator_connection_id', 'coordinator_autocommit', 'coordinator_in_transaction' ) !== array_keys( $row )
			|| ! ctype_digit( (string) $row['coordinator_connection_id'] )
			|| ! ctype_digit( (string) $row['coordinator_autocommit'] )
			|| ! ctype_digit( (string) $row['coordinator_in_transaction'] )
		) {
			return false;
		}
		$snapshot = array(
			'connection_id'  => (int) $row['coordinator_connection_id'],
			'autocommit'     => (int) $row['coordinator_autocommit'],
			'in_transaction' => (int) $row['coordinator_in_transaction'],
		);
		return 0 < $snapshot['connection_id']
			&& in_array( $snapshot['autocommit'], array( 0, 1 ), true )
			&& in_array( $snapshot['in_transaction'], array( 0, 1 ), true )
			? $snapshot
			: false;
	}

	/** Reprove the original writer identity and exact transaction boundary. */
	private static function writer_transaction_intact( &$sessions, $in_transaction ) {
		if (
			! is_array( $sessions )
			|| ! isset( $sessions['writer'], $sessions['profiles']['writer']['connection_id'] )
			|| ! in_array( $in_transaction, array( 0, 1 ), true )
		) {
			if ( is_array( $sessions ) ) {
				$sessions['watchdog_writer_identity_valid'] = false;
			}
			return false;
		}
		$snapshot = self::native_transaction_snapshot( $sessions['writer'] );
		if ( ! is_array( $snapshot ) || (int) $sessions['profiles']['writer']['connection_id'] !== $snapshot['connection_id'] ) {
			$sessions['watchdog_writer_identity_valid'] = false;
			return false;
		}
		return 1 === $snapshot['autocommit']
			&& $in_transaction === $snapshot['in_transaction'];
	}

	/** Prove the native writer can issue transactional DML without durable state. */
	private static function native_write_proof( $writer ) {
		$before = self::native_transaction_snapshot( $writer );
		if ( ! is_array( $before ) || 1 !== $before['autocommit'] || 0 !== $before['in_transaction'] ) {
			return false;
		}
		$started = false;
		$proved  = false;
		try {
			if ( ! self::transaction_control( $writer, 'START TRANSACTION' ) ) {
				return false;
			}
			$started = true;
			$started_snapshot = self::native_transaction_snapshot( $writer );
			if ( ! is_array( $started_snapshot ) || $before['connection_id'] !== $started_snapshot['connection_id'] || 1 !== $started_snapshot['autocommit'] || 1 !== $started_snapshot['in_transaction'] ) {
				throw new RuntimeException( 'Coordinator native write proof boundary unavailable.' );
			}
			$table   = self::raw_options_table( $writer );
			if ( '' === $table ) {
				throw new RuntimeException( 'Coordinator write proof table unavailable.' );
			}
			$result = self::raw_exec(
				$writer,
				"UPDATE {$table} SET option_value = option_value WHERE option_id = 0 AND option_name = ? AND option_name = CAST(? AS BINARY)",
				array( self::ROW_OPTION, self::ROW_OPTION )
			);
			$after_write = self::native_transaction_snapshot( $writer );
			$proved = is_array( $result )
				&& is_array( $after_write )
				&& $before['connection_id'] === $after_write['connection_id']
				&& 1 === $after_write['autocommit']
				&& 1 === $after_write['in_transaction'];
		} catch ( Throwable $error ) {
			$proved = false;
		}
		$rolled_back = $started && self::transaction_control( $writer, 'ROLLBACK' );
		$after = $rolled_back ? self::native_transaction_snapshot( $writer ) : false;
		return $proved
			&& is_array( $after )
			&& $before['connection_id'] === $after['connection_id']
			&& 1 === $after['autocommit']
			&& 0 === $after['in_transaction'];
	}

	/** Run one fixed callback-free statement on stock live wpdb. */
	private static function live_control( $sql ) {
		global $wpdb;

		if ( ! is_object( $wpdb ) || 'wpdb' !== get_class( $wpdb ) || ! is_string( $sql ) || '' === $sql ) {
			return false;
		}
		if ( ! self::test_factory_enabled() ) {
			$handle = self::live_database_handle();
			if ( ! ( $handle instanceof mysqli ) ) {
				return false;
			}
			try {
				$result = mysqli_query( $handle, $sql );
				if ( $result instanceof mysqli_result ) {
					mysqli_free_result( $result );
					return false;
				}
			} catch ( Throwable $error ) {
				return false;
			}
			return true === $result && self::live_database_handle() === $handle;
		}
		if ( ! method_exists( $wpdb, 'query' ) ) {
			return false;
		}
		try {
			if ( property_exists( $wpdb, 'last_error' ) ) {
				$wpdb->last_error = '';
			}
			$result = $wpdb->query( $sql );
		} catch ( Throwable $error ) {
			return false;
		}
		return false !== $result && ( ! property_exists( $wpdb, 'last_error' ) || '' === (string) $wpdb->last_error );
	}

	/** Prove live wpdb UPDATE permission without opening a transaction. */
	private static function live_write_proof( $before ) {
		global $wpdb;

		if (
			! is_array( $before )
			|| ! is_object( $wpdb )
			|| 'wpdb' !== get_class( $wpdb )
			|| 1 !== $before['autocommit']
			|| 0 !== $before['in_transaction']
			|| ! isset( $wpdb->options, $wpdb->prefix )
		) {
			return false;
		}
		$object = spl_object_hash( $wpdb );
		$handle = self::live_handle_identity();
		$table  = (string) $wpdb->options;
		$prefix = (string) $wpdb->prefix;
		if ( false === $handle || 1 !== preg_match( '/^[A-Za-z0-9_]+$/D', $table ) || 1 !== preg_match( '/^[A-Za-z0-9_]+$/D', $prefix ) ) {
			return false;
		}
		$proved = self::live_control(
			"UPDATE {$table} SET option_value = option_value WHERE option_id = 0 AND option_name = 'lunara_oscars_portal_studio_outer_coordinator' AND option_name = CAST('lunara_oscars_portal_studio_outer_coordinator' AS BINARY)"
		);
		$after = self::live_owner_profile();
		return $proved
			&& is_array( $after )
			&& $before === $after
			&& is_object( $wpdb )
			&& 'wpdb' === get_class( $wpdb )
			&& spl_object_hash( $wpdb ) === $object
			&& self::live_handle_identity() === $handle
			&& isset( $wpdb->options, $wpdb->prefix )
			&& (string) $wpdb->options === $table
			&& (string) $wpdb->prefix === $prefix;
	}

	/** @return bool */
	private static function options_table_is_innodb( $session, $database, $table ) {
		try {
			$row = self::raw_row(
				$session,
				'SELECT TABLE_SCHEMA, TABLE_NAME, ENGINE FROM information_schema.TABLES WHERE BINARY TABLE_SCHEMA = BINARY DATABASE() AND BINARY TABLE_NAME = BINARY ? LIMIT 1',
				array( $table )
			);
		} catch ( Throwable $error ) {
			return false;
		}
		return is_array( $row )
			&& array( 'TABLE_SCHEMA', 'TABLE_NAME', 'ENGINE' ) === array_keys( $row )
			&& $database === $row['TABLE_SCHEMA']
			&& $table === $row['TABLE_NAME']
			&& 'InnoDB' === $row['ENGINE'];
	}

	/** Freeze one exact callback-free session capability. */
	private static function certify_session( $session ) {
		if (
			! is_object( $session )
			|| ! method_exists( $session, 'lunara_core_theme_mods_raw_contract_version' )
			|| ! method_exists( $session, 'lunara_core_theme_mods_no_reconnect_verified' )
			|| ! method_exists( $session, 'lunara_core_theme_mods_raw_exec' )
			|| ! method_exists( $session, 'lunara_core_theme_mods_raw_row' )
			|| ! method_exists( $session, 'lunara_core_theme_mods_raw_rows' )
			|| ! method_exists( $session, 'lunara_core_theme_mods_terminate_verified' )
		) {
			return false;
		}

		$key = spl_object_hash( $session );
		if ( isset( self::$certified_sessions[ $key ] ) && self::$certified_sessions[ $key ]['object'] === $session ) {
			return true;
		}
		unset( self::$certified_sessions[ $key ] );
		try {
			if (
				1 !== $session->lunara_core_theme_mods_raw_contract_version()
				|| true !== $session->lunara_core_theme_mods_no_reconnect_verified()
				|| ! isset( $session->options )
				|| 1 !== preg_match( '/^[A-Za-z0-9_]+$/D', (string) $session->options )
			) {
				return false;
			}
		} catch ( Throwable $error ) {
			return false;
		}

		self::$certified_sessions[ $key ] = array(
			'object'        => $session,
			'options_table' => (string) $session->options,
		);
		return true;
	}

	/** @return string */
	private static function raw_options_table( $session ) {
		if ( ! is_object( $session ) ) {
			return '';
		}
		$key = spl_object_hash( $session );
		return isset( self::$certified_sessions[ $key ] ) && self::$certified_sessions[ $key ]['object'] === $session
			? self::$certified_sessions[ $key ]['options_table']
			: '';
	}

	/** @return array */
	private static function raw_exec( $session, $sql, $params = array() ) {
		if ( ! self::certify_session( $session ) ) {
			throw new RuntimeException( 'Uncertified coordinator session.' );
		}
		$result = $session->lunara_core_theme_mods_raw_exec( $sql, $params );
		if (
			! is_array( $result )
			|| array( 'affected_rows', 'insert_id' ) !== array_keys( $result )
			|| ! is_int( $result['affected_rows'] )
			|| ! is_string( $result['insert_id'] )
		) {
			throw new RuntimeException( 'Invalid coordinator execution result.' );
		}
		return $result;
	}

	/** @return array|null */
	private static function raw_row( $session, $sql, $params = array() ) {
		if ( ! self::certify_session( $session ) ) {
			throw new RuntimeException( 'Uncertified coordinator session.' );
		}
		$row = $session->lunara_core_theme_mods_raw_row( $sql, $params );
		if ( null !== $row && ! is_array( $row ) ) {
			throw new RuntimeException( 'Invalid coordinator row result.' );
		}
		if ( is_array( $row ) ) {
			foreach ( $row as $key => $value ) {
				if ( ! is_string( $key ) || ( ! is_string( $value ) && ! is_int( $value ) && null !== $value ) ) {
					throw new RuntimeException( 'Unsafe coordinator row result.' );
				}
			}
		}
		return $row;
	}

	/** @return array */
	private static function raw_rows( $session, $sql, $params = array() ) {
		if ( ! self::certify_session( $session ) ) {
			throw new RuntimeException( 'Uncertified coordinator session.' );
		}
		$rows = $session->lunara_core_theme_mods_raw_rows( $sql, $params );
		if ( ! is_array( $rows ) ) {
			throw new RuntimeException( 'Invalid coordinator rows result.' );
		}
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) {
				throw new RuntimeException( 'Invalid coordinator row result.' );
			}
			foreach ( $row as $key => $value ) {
				if ( ! is_string( $key ) || ( ! is_string( $value ) && ! is_int( $value ) && null !== $value ) ) {
					throw new RuntimeException( 'Unsafe coordinator rows result.' );
				}
			}
		}
		return $rows;
	}

	/** Positively close lock-capable roles, using the attested watchdog if needed. */
	private static function close_sessions( $sessions ) {
		if ( ! is_array( $sessions ) ) {
			return false;
		}
		$all_closed = true;
		foreach ( array( 'writer', 'verifier' ) as $role ) {
			if ( ! isset( $sessions[ $role ] ) ) {
				$all_closed = false;
				continue;
			}
			if ( self::terminate_session( $sessions[ $role ] ) ) {
				self::uncertify_session( $sessions[ $role ] );
				continue;
			}
			$id         = isset( $sessions['profiles'][ $role ]['connection_id'] ) ? (int) $sessions['profiles'][ $role ]['connection_id'] : 0;
			$previsible = 0 < $id && isset( $sessions['watchdog_visible_ids'] ) && in_array( $id, $sessions['watchdog_visible_ids'], true );
			$watchdog_identity_valid = 'writer' !== $role
				|| ( isset( $sessions['watchdog_writer_identity_valid'] ) && true === $sessions['watchdog_writer_identity_valid'] );
			if ( ! $watchdog_identity_valid || ! isset( $sessions['watchdog'] ) || ! self::watchdog_kill_and_prove( $sessions['watchdog'], $id, $previsible ) ) {
				$all_closed = false;
			} else {
				self::uncertify_session( $sessions[ $role ] );
			}
		}

		if ( ! isset( $sessions['watchdog'] ) || ! self::terminate_session( $sessions['watchdog'] ) ) {
			$all_closed = false;
		} else {
			self::uncertify_session( $sessions['watchdog'] );
		}
		return $all_closed;
	}

	/** Release the static identity hold after positive native termination. */
	private static function uncertify_session( $session ) {
		if ( ! is_object( $session ) ) {
			return;
		}
		$key = spl_object_hash( $session );
		if ( isset( self::$certified_sessions[ $key ] ) && self::$certified_sessions[ $key ]['object'] === $session ) {
			unset( self::$certified_sessions[ $key ] );
		}
	}

	/** @return bool */
	private static function terminate_session( $session ) {
		if ( ! is_object( $session ) || ! method_exists( $session, 'lunara_core_theme_mods_terminate_verified' ) ) {
			return false;
		}
		try {
			return true === $session->lunara_core_theme_mods_terminate_verified();
		} catch ( Throwable $error ) {
			return false;
		}
	}

	/** @return bool */
	private static function watchdog_kill_and_prove( $watchdog, $connection_id, $previsible ) {
		if ( 0 >= $connection_id || true !== $previsible || ! self::certify_session( $watchdog ) ) {
			return false;
		}
		try {
			$row = self::raw_row(
				$watchdog,
				'SELECT ID FROM information_schema.PROCESSLIST WHERE ID = ? LIMIT 1',
				array( (string) $connection_id )
			);
			if ( null === $row ) {
				return true;
			}
			if ( array( 'ID' ) !== array_keys( $row ) || (int) $row['ID'] !== $connection_id ) {
				return false;
			}
			try {
				self::raw_exec( $watchdog, 'KILL CONNECTION ' . $connection_id );
			} catch ( Throwable $error ) {
				// Disappearance proof below remains authoritative.
			}
			for ( $attempt = 0; 3 > $attempt; ++$attempt ) {
				$row = self::raw_row(
					$watchdog,
					'SELECT ID FROM information_schema.PROCESSLIST WHERE ID = ? LIMIT 1',
					array( (string) $connection_id )
				);
				if ( null === $row ) {
					return true;
				}
				if ( array( 'ID' ) !== array_keys( $row ) || (int) $row['ID'] !== $connection_id ) {
					return false;
				}
			}
		} catch ( Throwable $error ) {
			return false;
		}
		return false;
	}

	/** Reject userland mysqli lookalikes before production admission. */
	private static function native_mysqli_available() {
		if ( ! extension_loaded( 'mysqli' ) ) {
			return false;
		}
		try {
			foreach ( array( 'mysqli', 'mysqli_result', 'mysqli_stmt' ) as $class ) {
				if ( ! class_exists( $class, false ) ) {
					return false;
				}
				$reflection = new ReflectionClass( $class );
				if ( ! $reflection->isInternal() || 'mysqli' !== strtolower( (string) $reflection->getExtensionName() ) ) {
					return false;
				}
			}
			$functions = array(
				'mysqli_affected_rows',
				'mysqli_close',
				'mysqli_fetch_assoc',
				'mysqli_fetch_fields',
				'mysqli_free_result',
				'mysqli_get_client_info',
				'mysqli_init',
				'mysqli_insert_id',
				'mysqli_errno',
				'mysqli_options',
				'mysqli_prepare',
				'mysqli_query',
				'mysqli_real_connect',
				'mysqli_stmt_affected_rows',
				'mysqli_stmt_bind_param',
				'mysqli_stmt_bind_result',
				'mysqli_stmt_close',
				'mysqli_stmt_execute',
				'mysqli_stmt_errno',
				'mysqli_stmt_fetch',
				'mysqli_stmt_result_metadata',
			);
			foreach ( $functions as $function ) {
				if ( ! function_exists( $function ) ) {
					return false;
				}
				$reflection = new ReflectionFunction( $function );
				if ( ! $reflection->isInternal() || 'mysqli' !== strtolower( (string) $reflection->getExtensionName() ) ) {
					return false;
				}
			}
			if ( function_exists( 'mysqli_stmt_get_result' ) ) {
				$reflection = new ReflectionFunction( 'mysqli_stmt_get_result' );
				if ( ! $reflection->isInternal() || 'mysqli' !== strtolower( (string) $reflection->getExtensionName() ) ) {
					return false;
				}
			}
		} catch ( Throwable $error ) {
			return false;
		}
		return true;
	}

	/** @return WP_Error */
	private static function error( $code ) {
		$allowed = self::allowed_error_codes();
		$code = in_array( $code, $allowed, true ) ? $code : 'lunara_core_theme_mods_unavailable';
		if ( isset( self::$error_prototypes[ $code ] ) ) {
			return clone self::$error_prototypes[ $code ];
		}
		$fallback = self::build_error_prototype( $code );
		return $fallback instanceof WP_Error ? $fallback : new WP_Error();
	}

	/** @return array */
	private static function allowed_error_codes() {
		return array(
			'lunara_core_theme_mods_invalid_option',
			'lunara_core_theme_mods_invalid_purpose',
			'lunara_core_theme_mods_unavailable',
			'lunara_core_theme_mods_already_owned',
			'lunara_core_theme_mods_invalid_lease',
			'lunara_core_theme_mods_release_failed',
			'lunara_core_theme_mods_cleanup_failed',
		);
	}

	/** Build one bounded error without invoking WP_Error::add() or its hook. */
	private static function build_error_prototype( $code ) {
		if ( ! class_exists( 'WP_Error', false ) || ! in_array( $code, self::allowed_error_codes(), true ) ) {
			return false;
		}
		try {
			$error = new WP_Error();
			if ( ! property_exists( $error, 'errors' ) || ! property_exists( $error, 'error_data' ) ) {
				return false;
			}
			$error->errors     = array( $code => array( '' ) );
			$error->error_data = array();
			if ( $code !== $error->get_error_code() || '' !== $error->get_error_message() || null !== $error->get_error_data() ) {
				return false;
			}
			return $error;
		} catch ( Throwable $error ) {
			return false;
		}
	}

	/** Prebuild callback-free bounded errors before any raw transaction exists. */
	private static function prime_error_prototypes() {
		if ( count( self::$error_prototypes ) === count( self::allowed_error_codes() ) ) {
			return true;
		}
		if ( ! class_exists( 'WP_Error', false ) ) {
			return false;
		}
		$prototypes = array();
		try {
			foreach ( self::allowed_error_codes() as $code ) {
				$prototype = self::build_error_prototype( $code );
				if ( ! ( $prototype instanceof WP_Error ) ) {
					return false;
				}
				$prototypes[ $code ] = $prototype;
			}
		} catch ( Throwable $error ) {
			return false;
		}
		self::$error_prototypes = $prototypes;
		return true;
	}

	/** @return string */
	private static function redacted_reason( $reason ) {
		return in_array( $reason, array( 'load-order', 'runtime', 'storage', 'cleanup' ), true ) ? $reason : 'runtime';
	}

}

/** @return array */
function lunara_core_theme_mods_coordinator_status(): array {
	return Lunara_Core_Theme_Mods_Coordinator::status();
}

/**
 * @param string $option Exact theme-mod option.
 * @param string $purpose Bounded caller purpose.
 * @return Lunara_Core_Theme_Mods_Coordinator_Lease|WP_Error
 */
function lunara_core_theme_mods_coordinator_acquire( string $option, string $purpose ) {
	return Lunara_Core_Theme_Mods_Coordinator::acquire( $option, $purpose );
}

/**
 * @param mixed  $lease Exact issued lease.
 * @param string $option Exact original option.
 * @return Lunara_Core_Theme_Mods_Coordinator_Lease|WP_Error
 */
function lunara_core_theme_mods_coordinator_reuse( $lease, string $option ) {
	return Lunara_Core_Theme_Mods_Coordinator::reuse( $lease, $option );
}

/**
 * @param mixed $lease Exact issued lease.
 * @return true|WP_Error
 */
function lunara_core_theme_mods_coordinator_release( $lease ) {
	return Lunara_Core_Theme_Mods_Coordinator::release( $lease );
}
