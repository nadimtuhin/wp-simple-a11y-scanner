<?php
/**
 * Post-publish automatic scan hook.
 * Scans post content on publish/update and stores results as post meta.
 *
 * @package SimpleA11yScanner
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Scan a post on publish/update and cache result in post meta.
 *
 * @param int      $post_id Post ID.
 * @param \WP_Post $post    Post object.
 */
function simple_a11y_scanner_on_publish( int $post_id, $post ): void {
    // Skip revisions, auto-saves, and non-public post types.
    if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
        return;
    }

    /**
     * Filter the post types that trigger an automatic scan on publish/save.
     * Add custom post types to extend auto-scan coverage.
     *
     * @param string[] $post_types  Post type slugs that trigger auto-scan.
     * @param int      $post_id     Post being saved.
     */
    $scanned_post_types = apply_filters( 'simple_a11y_scanner_scanned_post_types', [ 'post', 'page' ], $post_id );

    if ( ! in_array( get_post_type( $post_id ), $scanned_post_types, true ) ) {
        return;
    }

    $opts    = function_exists( 'simple_a11y_scanner_get_options' ) ? simple_a11y_scanner_get_options() : [];
    $scanner = new \SimpleA11yScanner\Scanner();
    $issues  = $scanner->scanContent( $post->post_content ?? '', $opts );

    $score_data = function_exists( 'simple_a11y_scanner_score' ) ? simple_a11y_scanner_score( $issues ) : [ 'score' => count( $issues ) ];

    update_post_meta( $post_id, '_a11y_scan_issues', $issues );
    update_post_meta( $post_id, '_a11y_scan_score', $score_data );
    update_post_meta( $post_id, '_a11y_scan_time', current_time( 'mysql' ) );

    /**
     * Fires after post-publish scan completes.
     * Use to send alerts, update dashboards, or log results.
     *
     * @param int   $post_id    Post ID.
     * @param array $issues     Issues found.
     * @param array $score_data Severity score data.
     */
    do_action( 'simple_a11y_scanner_post_scanned', $post_id, $issues, $score_data );

    // Log to audit log if available.
    if ( class_exists( '\SimpleA11yScanner\AuditLog' ) ) {
        $url = get_permalink( $post_id ) ?: '';
        \SimpleA11yScanner\AuditLog::insert( $url, $post_id, $issues );
    }
}
add_action( 'save_post', 'simple_a11y_scanner_on_publish', 10, 2 );
