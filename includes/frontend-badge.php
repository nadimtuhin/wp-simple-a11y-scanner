<?php
/**
 * Frontend accessibility badge.
 * Shows a small badge on the front-end indicating the a11y score for a post.
 * Only renders when the option is enabled and user is logged in with edit_posts cap.
 *
 * @package SimpleA11yScanner
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Append a11y badge after post content on singular views.
 *
 * @param string $content Post content.
 * @return string Modified content.
 */
function simple_a11y_scanner_badge( string $content ): string {
    if ( ! is_singular() || ! is_user_logged_in() || ! current_user_can( 'edit_posts' ) ) {
        return $content;
    }

    $opts = function_exists( 'simple_a11y_scanner_get_options' ) ? simple_a11y_scanner_get_options() : [];
    if ( empty( $opts['show_frontend_badge'] ) ) {
        return $content;
    }

    $post_id = get_the_ID();
    if ( ! $post_id ) {
        return $content;
    }

    $issues     = get_post_meta( $post_id, '_a11y_scan_issues', true ) ?: [];
    $score_data = get_post_meta( $post_id, '_a11y_scan_score', true ) ?: [];
    $scan_time  = get_post_meta( $post_id, '_a11y_scan_time', true ) ?: '';

    $count    = count( $issues );
    $score    = $score_data['score'] ?? $count;
    $critical = $score_data['critical'] ?? 0;

    if ( $critical > 0 ) {
        $color = '#d63638';
        $label = __( 'Critical a11y issues', 'wp-simple-a11y-scanner' );
    } elseif ( $count > 0 ) {
        $color = '#d67c38';
        $label = __( 'A11y issues', 'wp-simple-a11y-scanner' );
    } else {
        $color = '#00a32a';
        $label = __( 'No a11y issues', 'wp-simple-a11y-scanner' );
    }

    $admin_url = esc_url( admin_url( 'admin.php?page=simple-a11y-scanner' ) );
    $time_txt  = $scan_time ? esc_html( sprintf( __( 'Last scanned: %s', 'wp-simple-a11y-scanner' ), $scan_time ) ) : '';

    $badge = sprintf(
        '<div class="a11y-badge" style="margin-top:16px;padding:8px 12px;border-left:4px solid %s;background:#f9f9f9;font-size:13px;">'
        . '<strong style="color:%s;">%s %s</strong>'
        . ' — <a href="%s">%s</a>'
        . ( $time_txt ? ' <span style="color:#666;font-size:11px;">(%s)</span>' : '' )
        . '</div>',
        esc_attr( $color ),
        esc_attr( $color ),
        esc_html( $label ),
        ( $count > 0 ? '(' . $count . ')' : '' ),
        $admin_url,
        esc_html__( 'View report', 'wp-simple-a11y-scanner' ),
        $time_txt
    );

    return $content . $badge;
}
add_filter( 'the_content', 'simple_a11y_scanner_badge' );
