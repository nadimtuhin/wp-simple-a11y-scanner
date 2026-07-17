<?php
/**
 * REST API scan throttling and IP logging.
 *
 * Limits scan requests per IP to configurable window.
 * Default: 30 requests per 60 seconds (via transient).
 *
 * @package SimpleA11yScanner
 */

if ( ! defined( 'ABSPATH' ) && ! defined( 'PHPUNIT_RUNNING' ) ) {
    exit;
}

/**
 * Check and record a rate-limited scan request for the current client IP.
 *
 * @param int $limit   Max requests allowed in the window. Default 30.
 * @param int $window  Window size in seconds. Default 60.
 * @return true|\WP_Error  True if allowed; WP_Error with code 'too_many_requests' if throttled.
 */
function simple_a11y_scanner_check_rate_limit( int $limit = 30, int $window = 60 ) {
    $ip  = simple_a11y_scanner_client_ip();
    $key = 'simple_a11y_rl_' . md5( $ip );

    $record = get_transient( $key );

    if ( false === $record ) {
        $record = [ 'count' => 0, 'first' => time(), 'ip' => $ip ];
    }

    // Reset window if expired.
    if ( ( time() - $record['first'] ) >= $window ) {
        $record = [ 'count' => 0, 'first' => time(), 'ip' => $ip ];
    }

    $record['count']++;
    set_transient( $key, $record, $window );

    /**
     * Fires on every scan request for audit/logging purposes.
     *
     * @param string $ip    Client IP.
     * @param int    $count Request count in current window.
     * @param int    $limit Rate limit.
     */
    do_action( 'simple_a11y_scanner_rate_limit_tick', $ip, $record['count'], $limit );

    if ( $record['count'] > $limit ) {
        /**
         * Fires when a client is throttled.
         *
         * @param string $ip    Client IP.
         * @param int    $count Request count.
         */
        do_action( 'simple_a11y_scanner_rate_limit_exceeded', $ip, $record['count'] );

        return new \WP_Error(
            'too_many_requests',
            sprintf( __( 'Rate limit exceeded. Maximum %d requests per %d seconds.', 'wp-simple-a11y-scanner' ), $limit, $window ),
            [ 'status' => 429 ]
        );
    }

    return true;
}

/**
 * Get client IP address (supports proxies with X-Forwarded-For).
 *
 * @return string Sanitized IP address.
 */
function simple_a11y_scanner_client_ip(): string {
    $headers = [ 'HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'REMOTE_ADDR' ];
    foreach ( $headers as $header ) {
        if ( ! empty( $_SERVER[ $header ] ) ) {
            $ip = sanitize_text_field( wp_unslash( $_SERVER[ $header ] ) );
            // X-Forwarded-For may be a comma-delimited list — take first.
            $ip = trim( explode( ',', $ip )[0] );
            if ( filter_var( $ip, FILTER_VALIDATE_IP ) ) {
                return $ip;
            }
        }
    }
    return '0.0.0.0';
}
