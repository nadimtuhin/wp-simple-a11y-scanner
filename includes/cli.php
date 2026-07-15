<?php
/**
 * WP-CLI commands for Simple A11y Scanner.
 *
 * Usage: wp a11y scan <url>
 *
 * @package SimpleA11yScanner
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
    return;
}

/**
 * Scan a URL for accessibility issues.
 */
class Simple_A11y_Scanner_CLI {

    /**
     * Scan a URL for accessibility issues.
     *
     * ## OPTIONS
     *
     * <url>
     * : The URL to fetch and scan.
     *
     * [--format=<format>]
     * : Output format. Accepts: table, json, csv, yaml, count. Default: table.
     *
     * [--send-email]
     * : Send email notification if issues are found (uses plugin settings).
     *
     * ## EXAMPLES
     *
     *     wp a11y scan https://example.com
     *     wp a11y scan https://example.com --format=json
     *     wp a11y scan https://example.com --send-email
     *
     * @when after_wp_load
     *
     * @param array $args       Positional arguments.
     * @param array $assoc_args Associative arguments.
     */
    public function scan( $args, $assoc_args ) {
        if ( empty( $args[0] ) ) {
            WP_CLI::error( 'Please provide a URL to scan.' );
        }

        $url = esc_url_raw( $args[0] );
        if ( ! filter_var( $url, FILTER_VALIDATE_URL ) ) {
            WP_CLI::error( sprintf( 'Invalid URL: %s', $args[0] ) );
        }

        WP_CLI::log( sprintf( 'Fetching %s …', $url ) );

        $response = wp_remote_get( $url, [ 'timeout' => 30 ] );

        if ( is_wp_error( $response ) ) {
            WP_CLI::error( 'Failed to fetch URL: ' . $response->get_error_message() );
        }

        $status = wp_remote_retrieve_response_code( $response );
        if ( $status < 200 || $status >= 300 ) {
            WP_CLI::error( sprintf( 'HTTP %d received for %s', $status, $url ) );
        }

        $html    = wp_remote_retrieve_body( $response );
        $scanner = new \SimpleA11yScanner\Scanner();
        $opts    = function_exists( 'simple_a11y_scanner_get_options' )
            ? simple_a11y_scanner_get_options()
            : [];

        $issues = $scanner->scanContent( $html, $opts );

        if ( empty( $issues ) ) {
            WP_CLI::success( 'No accessibility issues found.' );
            return;
        }

        $format = \WP_CLI\Utils\get_flag_value( $assoc_args, 'format', 'table' );

        // Flatten for table output.
        $rows = array_map( function ( $issue ) use ( $url ) {
            return [
                'url'     => $url,
                'type'    => $issue['type'],
                'message' => $issue['message'],
                'element' => substr( $issue['element'], 0, 80 ),
            ];
        }, $issues );

        \WP_CLI\Utils\format_items( $format, $rows, [ 'type', 'message', 'element' ] );

        WP_CLI::log( sprintf( 'Found %d issue(s).', count( $issues ) ) );

        // Email notification if --send-email flag or plugin setting enabled.
        $send_email = \WP_CLI\Utils\get_flag_value( $assoc_args, 'send-email', false );
        if ( $send_email || ( ! empty( $opts['email_notifications'] ) ) ) {
            simple_a11y_scanner_send_notification( $url, $issues, $opts );
        }

        // Exit with non-zero so CI pipelines can catch issues.
        WP_CLI::halt( 1 );
    }
}

WP_CLI::add_command( 'a11y', 'Simple_A11y_Scanner_CLI' );
