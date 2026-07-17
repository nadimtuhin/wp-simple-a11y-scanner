<?php
/**
 * Per-category scan scheduling.
 * Allows scheduling automatic scans per taxonomy category via WP-Cron.
 *
 * @package SimpleA11yScanner
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Schedule or reschedule a category scan.
 *
 * @param int    $cat_id    Category term ID.
 * @param string $frequency 'daily'|'weekly'|'monthly'|'disabled'
 */
function simple_a11y_scanner_schedule_category( int $cat_id, string $frequency ): void {
    $hook = 'simple_a11y_scanner_cat_scan_' . $cat_id;
    wp_clear_scheduled_hook( $hook );

    if ( 'disabled' === $frequency ) {
        return;
    }

    $schedules = [ 'daily', 'weekly', 'twicedaily' ];

    /**
     * Filter the valid WP-Cron schedule names for category scans.
     * Add custom schedules registered via cron_schedules filter.
     *
     * @param string[] $schedules  Allowed schedule identifiers.
     * @param int      $cat_id     Category term ID.
     */
    $schedules = apply_filters( 'simple_a11y_scanner_category_schedules', $schedules, $cat_id );

    if ( ! in_array( $frequency, $schedules, true ) ) {
        $frequency = 'weekly';
    }

    wp_schedule_event( time(), $frequency, $hook, [ $cat_id ] );
}

/**
 * Run a category scan: scan all posts in the category and store results.
 *
 * @param int $cat_id Category term ID.
 */
function simple_a11y_scanner_run_category_scan( int $cat_id ): void {
    $opts = function_exists( 'simple_a11y_scanner_get_options' ) ? simple_a11y_scanner_get_options() : [];

    $query_args = [
        'category'    => $cat_id,
        'post_status' => 'publish',
        'numberposts' => 100,
    ];

    /**
     * Filter the WP_Query arguments used when fetching posts for a category scan.
     * Use to increase post limit, add post types, or apply date filters.
     *
     * @param array $query_args  get_posts() arguments.
     * @param int   $cat_id      Category term ID being scanned.
     */
    $query_args = apply_filters( 'simple_a11y_scanner_category_scan_query', $query_args, $cat_id );

    $posts = get_posts( $query_args );

    if ( empty( $posts ) ) {
        return;
    }

    /**
     * Fires before a category scan begins processing its posts.
     *
     * @param int      $cat_id  Category term ID.
     * @param WP_Post[] $posts  Posts about to be scanned.
     */
    do_action( 'simple_a11y_scanner_before_category_scan', $cat_id, $posts );

    $scanner    = new \SimpleA11yScanner\Scanner();
    $all_issues = 0;

    foreach ( $posts as $post ) {
        $issues = $scanner->scanContent( $post->post_content, $opts );
        $score  = function_exists( 'simple_a11y_scanner_score' ) ? simple_a11y_scanner_score( $issues ) : [ 'score' => count( $issues ) ];
        update_post_meta( $post->ID, '_a11y_scan_issues', $issues );
        update_post_meta( $post->ID, '_a11y_scan_score', $score );
        update_post_meta( $post->ID, '_a11y_scan_time', current_time( 'mysql' ) );
        $all_issues += count( $issues );
    }

    /**
     * Fires after a category scan completes.
     *
     * @param int $cat_id      Category term ID.
     * @param int $post_count  Posts scanned.
     * @param int $all_issues  Total issues found.
     */
    do_action( 'simple_a11y_scanner_category_scan_complete', $cat_id, count( $posts ), $all_issues );
}
// Dynamic hook binding: each category gets its own cron hook.
add_action( 'init', function () {
    $schedules = get_option( 'simple_a11y_scanner_cat_schedules', [] );
    foreach ( array_keys( $schedules ) as $cat_id ) {
        add_action( 'simple_a11y_scanner_cat_scan_' . $cat_id, 'simple_a11y_scanner_run_category_scan' );
    }
} );
