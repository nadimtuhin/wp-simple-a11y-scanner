<?php
/**
 * Scheduled audit alerts for Simple A11y Scanner.
 * Fires weekly/monthly scans on site posts and sends email summaries.
 *
 * @package SimpleA11yScanner
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Register cron schedules and hook on plugin init.
 */
function simple_a11y_scanner_schedule_audit(): void {
    $opts = function_exists( 'simple_a11y_scanner_get_options' ) ? simple_a11y_scanner_get_options() : [];
    $freq = $opts['audit_schedule'] ?? 'disabled';
    $hook = 'simple_a11y_scanner_scheduled_audit';

    if ( 'disabled' === $freq ) {
        wp_clear_scheduled_hook( $hook );
        return;
    }

    if ( ! wp_next_scheduled( $hook ) ) {
        wp_schedule_event( time(), $freq, $hook );
    }
}
add_action( 'init', 'simple_a11y_scanner_schedule_audit' );

/**
 * Run scheduled audit: scan all published posts and email summary.
 */
function simple_a11y_scanner_run_scheduled_audit(): void {
    if ( ! function_exists( 'simple_a11y_scanner_get_options' ) ) {
        return;
    }

    $opts = simple_a11y_scanner_get_options();
    if ( empty( $opts['email_notifications'] ) ) {
        return;
    }

    $posts = get_posts( [
        'post_type'      => 'post',
        'post_status'    => 'publish',
        'numberposts'    => 50,
        'fields'         => 'ids',
    ] );

    if ( empty( $posts ) ) {
        return;
    }

    $scanner    = new \SimpleA11yScanner\Scanner();
    $all_issues = [];
    $post_count = 0;

    foreach ( $posts as $post_id ) {
        $post = get_post( $post_id );
        if ( ! $post ) {
            continue;
        }
        $issues = $scanner->scanContent( $post->post_content, $opts );
        if ( ! empty( $issues ) ) {
            foreach ( $issues as $issue ) {
                $issue['post_id'] = $post_id;
                $all_issues[]     = $issue;
            }
        }
        $post_count++;
    }

    $to = sanitize_email( $opts['notification_email'] ?? get_option( 'admin_email', '' ) );
    if ( ! is_email( $to ) ) {
        return;
    }

    $issue_count = count( $all_issues );
    $subject     = sprintf(
        __( '[A11y Scanner] Scheduled audit: %d issues across %d posts', 'wp-simple-a11y-scanner' ),
        $issue_count,
        $post_count
    );

    $lines = [
        sprintf( __( 'Scheduled A11y audit complete for %s', 'wp-simple-a11y-scanner' ), home_url() ),
        sprintf( __( 'Posts scanned: %d', 'wp-simple-a11y-scanner' ), $post_count ),
        sprintf( __( 'Total issues: %d', 'wp-simple-a11y-scanner' ), $issue_count ),
        '',
    ];

    // Group by post.
    $by_post = [];
    foreach ( $all_issues as $issue ) {
        $pid = $issue['post_id'] ?? 0;
        $by_post[ $pid ][] = $issue;
    }
    foreach ( $by_post as $pid => $p_issues ) {
        $lines[] = sprintf( '--- Post ID %d (%d issues) ---', $pid, count( $p_issues ) );
        foreach ( $p_issues as $issue ) {
            $lines[] = sprintf( '  [%s] %s', strtoupper( $issue['type'] ), $issue['message'] );
        }
        $lines[] = '';
    }

    $lines[] = sprintf( __( 'Sent by Simple A11y Scanner on %s', 'wp-simple-a11y-scanner' ), home_url() );

    wp_mail( $to, $subject, implode( "\n", $lines ) );

    /**
     * Fires after a scheduled audit completes.
     *
     * @param array $all_issues All issues found across posts.
     * @param int   $post_count Number of posts scanned.
     */
    do_action( 'simple_a11y_scanner_scheduled_audit_complete', $all_issues, $post_count );
}
add_action( 'simple_a11y_scanner_scheduled_audit', 'simple_a11y_scanner_run_scheduled_audit' );
