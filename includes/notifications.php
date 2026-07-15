<?php
/**
 * Email notification support for Simple A11y Scanner.
 *
 * @package SimpleA11yScanner
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Send an email notification about accessibility issues found during a scan.
 *
 * @param string $url    The scanned URL or content identifier.
 * @param array  $issues Array of issue arrays (type, message, element).
 * @param array  $opts   Plugin options (uses defaults if not provided).
 * @return bool          True if mail was sent, false otherwise.
 */
function simple_a11y_scanner_send_notification( $url, array $issues, array $opts = [] ) {
    if ( empty( $opts ) && function_exists( 'simple_a11y_scanner_get_options' ) ) {
        $opts = simple_a11y_scanner_get_options();
    }

    $threshold = absint( $opts['notification_threshold'] ?? 1 );
    if ( count( $issues ) < $threshold ) {
        return false;
    }

    $to = sanitize_email( $opts['notification_email'] ?? get_option( 'admin_email', '' ) );
    if ( ! is_email( $to ) ) {
        return false;
    }

    $subject = sprintf(
        /* translators: %d = issue count, %s = URL */
        _n(
            '[A11y Scanner] %d accessibility issue found on %s',
            '[A11y Scanner] %d accessibility issues found on %s',
            count( $issues ),
            'wp-simple-a11y-scanner'
        ),
        count( $issues ),
        $url
    );

    $lines = [
        sprintf( __( 'A11y Scanner found %d issue(s) on: %s', 'wp-simple-a11y-scanner' ), count( $issues ), $url ),
        '',
    ];

    foreach ( $issues as $i => $issue ) {
        $lines[] = sprintf( '%d. [%s] %s', $i + 1, strtoupper( $issue['type'] ), $issue['message'] );
        if ( ! empty( $issue['element'] ) ) {
            $lines[] = '   ' . substr( $issue['element'], 0, 120 );
        }
        $lines[] = '';
    }

    $lines[] = '---';
    $lines[] = sprintf( __( 'Sent by Simple A11y Scanner on %s', 'wp-simple-a11y-scanner' ), home_url() );

    $message = implode( "\n", $lines );

    /**
     * Filter the recipient address before sending.
     *
     * @param string $to     Recipient email.
     * @param string $url    Scanned URL.
     * @param array  $issues Issues found.
     */
    $to = apply_filters( 'simple_a11y_scanner_notification_email', $to, $url, $issues );

    /**
     * Filter the email subject.
     *
     * @param string $subject Email subject.
     * @param string $url     Scanned URL.
     * @param array  $issues  Issues found.
     */
    $subject = apply_filters( 'simple_a11y_scanner_notification_subject', $subject, $url, $issues );

    /**
     * Filter the email message body.
     *
     * @param string $message Email body.
     * @param string $url     Scanned URL.
     * @param array  $issues  Issues found.
     */
    $message = apply_filters( 'simple_a11y_scanner_notification_message', $message, $url, $issues );

    $sent = wp_mail( $to, $subject, $message );

    /**
     * Fires after a notification email is attempted.
     *
     * @param bool   $sent   Whether wp_mail succeeded.
     * @param string $to     Recipient.
     * @param string $url    Scanned URL.
     * @param array  $issues Issues found.
     */
    do_action( 'simple_a11y_scanner_notification_sent', $sent, $to, $url, $issues );

    return $sent;
}

/**
 * Hook into REST scan responses to send emails when configured.
 * Called from Api::handleScan() result via action hook.
 *
 * @param array $issues Issues returned by scanner.
 * @param string $url   URL or context identifier.
 */
function simple_a11y_scanner_maybe_notify( array $issues, $url = '' ) {
    $opts = simple_a11y_scanner_get_options();
    if ( empty( $opts['email_notifications'] ) || empty( $issues ) ) {
        return;
    }
    simple_a11y_scanner_send_notification( $url, $issues, $opts );
}
add_action( 'simple_a11y_scanner_after_scan', 'simple_a11y_scanner_maybe_notify', 10, 2 );
