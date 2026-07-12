<?php
/**
 * WP-CLI adapter for the read-only Debrief migration service.
 *
 * @package Lunara_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Inspect and plan Debrief migration without changing content.
 */
final class Lunara_Debrief_CLI {

    /**
     * Inventory legacy and canonical Debrief data.
     *
     * ## OPTIONS
     *
     * [--post-status=<status>]
     * : Review status to scan. Defaults to any.
     *
     * [--review-id=<ids>]
     * : Comma-separated Review IDs.
     *
     * [--limit=<number>]
     * : Maximum Reviews to scan.
     *
     * [--offset=<number>]
     * : Number of Reviews to skip.
     *
     * [--format=<format>]
     * : summary or json. Defaults to summary.
     *
     * @param array<int,string>    $args Positional arguments.
     * @param array<string,mixed> $assoc_args Named arguments.
     */
    public function census( $args, $assoc_args ) {
        unset( $args );
        try {
            $report = Lunara_Debrief_Migration::census( self::service_args( $assoc_args ) );
        } catch ( InvalidArgumentException $error ) {
            WP_CLI::error( $error->getMessage() );
            return;
        }
        self::render_report( $report, self::format( $assoc_args ) );
    }

    /**
     * Build a Debrief migration plan. Release C is dry-run only.
     *
     * ## OPTIONS
     *
     * --dry-run
     * : Required safety flag. No content is changed.
     *
     * [--post-status=<status>]
     * : Review status to scan. Defaults to any.
     *
     * [--review-id=<ids>]
     * : Comma-separated Review IDs.
     *
     * [--limit=<number>]
     * : Maximum Reviews to scan.
     *
     * [--offset=<number>]
     * : Number of Reviews to skip.
     *
     * [--format=<format>]
     * : summary or json. Defaults to summary.
     *
     * @param array<int,string>    $args Positional arguments.
     * @param array<string,mixed> $assoc_args Named arguments.
     */
    public function migrate( $args, $assoc_args ) {
        unset( $args );

        if ( isset( $assoc_args['apply'] ) ) {
            WP_CLI::error( 'Apply mode is not available. Release C is read-only.' );
            return;
        }
        if ( ! isset( $assoc_args['dry-run'] ) ) {
            WP_CLI::error( 'The --dry-run flag is required. No apply path exists.' );
            return;
        }

        try {
            $report = Lunara_Debrief_Migration::dry_run( self::service_args( $assoc_args ) );
        } catch ( InvalidArgumentException $error ) {
            WP_CLI::error( $error->getMessage() );
            return;
        }
        self::render_report( $report, self::format( $assoc_args ) );
    }

    /**
     * Convert CLI arguments into service arguments.
     *
     * @param array<string,mixed> $assoc_args CLI arguments.
     * @return array<string,mixed>
     */
    private static function service_args( $assoc_args ) {
        $review_ids_supplied = array_key_exists( 'review-id', $assoc_args );
        $review_ids = array();
        if ( $review_ids_supplied ) {
            $review_ids = preg_split( '/,/', (string) $assoc_args['review-id'] );
            $review_ids = is_array( $review_ids ) ? $review_ids : array();
        }

        return array(
            'post_status'         => isset( $assoc_args['post-status'] ) ? (string) $assoc_args['post-status'] : 'any',
            'review_ids'          => $review_ids,
            'review_ids_supplied' => $review_ids_supplied,
            'limit'               => self::integer_option( $assoc_args, 'limit', false ),
            'offset'              => self::integer_option( $assoc_args, 'offset', true ),
        );
    }

    /**
     * Parse one bounded numeric CLI option without silently widening scope.
     *
     * @param array<string,mixed> $assoc_args CLI arguments.
     * @param string              $name Option name.
     * @param bool                $allow_zero Whether zero is valid.
     * @return int
     */
    private static function integer_option( $assoc_args, $name, $allow_zero ) {
        if ( ! array_key_exists( $name, $assoc_args ) ) {
            return 0;
        }

        $value = $assoc_args[ $name ];
        if ( ! is_scalar( $value ) ) {
            throw new InvalidArgumentException( '--' . $name . ' must be ' . ( $allow_zero ? 'a non-negative' : 'a positive' ) . ' integer.' );
        }

        $token   = trim( (string) $value );
        $pattern = $allow_zero ? '/^\d+$/' : '/^[1-9]\d*$/';
        if ( ! preg_match( $pattern, $token ) ) {
            throw new InvalidArgumentException( '--' . $name . ' must be ' . ( $allow_zero ? 'a non-negative' : 'a positive' ) . ' integer.' );
        }

        return (int) $token;
    }

    /**
     * Validate the requested output format.
     *
     * @param array<string,mixed> $assoc_args CLI arguments.
     * @return string
     */
    private static function format( $assoc_args ) {
        $format = isset( $assoc_args['format'] ) ? strtolower( trim( (string) $assoc_args['format'] ) ) : 'summary';
        if ( ! in_array( $format, array( 'summary', 'json' ), true ) ) {
            WP_CLI::error( 'Invalid format. Use summary or json.' );
        }

        return $format;
    }

    /**
     * Print JSON or a compact human-readable summary.
     *
     * @param array<string,mixed> $report Migration report.
     * @param string              $format Output format.
     */
    private static function render_report( $report, $format ) {
        if ( 'json' === $format ) {
            WP_CLI::line( Lunara_Debrief_Migration::stable_json( $report, true ) );
            return;
        }

        WP_CLI::line( 'Lunara Debrief ' . $report['mode'] . ' report' );
        WP_CLI::line( 'Reviews scanned: ' . (int) $report['summary']['reviews_scanned'] );
        WP_CLI::line( 'Planned field writes: ' . (int) $report['summary']['planned_field_writes'] );
        WP_CLI::line( 'Writes performed: 0' );

        foreach ( $report['summary']['buckets'] as $bucket => $count ) {
            WP_CLI::line( $bucket . ': ' . (int) $count );
        }

        WP_CLI::line( 'Plan hash: ' . $report['plan_hash'] );
        WP_CLI::line( 'No data changed.' );
    }
}

if ( defined( 'WP_CLI' ) && WP_CLI && class_exists( 'WP_CLI' ) ) {
    WP_CLI::add_command( 'lunara debrief', 'Lunara_Debrief_CLI' );
}
