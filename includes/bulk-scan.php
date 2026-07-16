<?php
/**
 * Bulk/background scanning via Action Scheduler.
 *
 * Uses Action Scheduler (bundled with WooCommerce or standalone) when available.
 * Falls back to WP-Cron single event when Action Scheduler is not installed.
 *
 * @package SimpleA11yScanner
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// -------------------------------------------------------------------------
// Scheduling helpers
// -------------------------------------------------------------------------

/**
 * Check whether Action Scheduler is available.
 *
 * @return bool
 */
function simple_a11y_scanner_has_action_scheduler() {
    return function_exists( 'as_enqueue_async_action' );
}

/**
 * Enqueue a bulk scan job for a list of URLs.
 *
 * Each URL gets its own async action so they run independently and failures
 * don't block the entire batch.
 *
 * @param string[] $urls         URLs to scan.
 * @param string   $batch_id     Unique ID for this batch (for result grouping).
 * @return int                   Number of jobs enqueued.
 */
function simple_a11y_scanner_enqueue_bulk( array $urls, $batch_id = '' ) {
    if ( '' === $batch_id ) {
        $batch_id = wp_generate_uuid4();
    }

    $count = 0;
    foreach ( $urls as $url ) {
        $url = esc_url_raw( trim( $url ) );
        if ( '' === $url ) {
            continue;
        }

        if ( simple_a11y_scanner_has_action_scheduler() ) {
            as_enqueue_async_action(
                'simple_a11y_scanner_scan_url',
                [ 'url' => $url, 'batch_id' => $batch_id ],
                'simple-a11y-scanner'
            );
        } else {
            // Fallback: WP-Cron single event, staggered 5 s apart so the server
            // isn't hammered all at once.
            wp_schedule_single_event(
                time() + ( $count * 5 ),
                'simple_a11y_scanner_scan_url',
                [ [ 'url' => $url, 'batch_id' => $batch_id ] ]
            );
        }

        $count++;
    }

    // Store batch metadata so the admin UI can query progress.
    $batches = get_option( 'simple_a11y_scanner_batches', [] );
    $batches[ $batch_id ] = [
        'total'     => $count,
        'scheduled' => current_time( 'mysql' ),
        'status'    => 'pending',
    ];
    update_option( 'simple_a11y_scanner_batches', $batches );

    return $count;
}

/**
 * Enqueue a bulk scan from a sitemap URL.
 *
 * Fetches the sitemap, extracts URLs, then hands off to
 * simple_a11y_scanner_enqueue_bulk().
 *
 * @param string $sitemap_url  Sitemap URL.
 * @return int|WP_Error        Jobs enqueued, or WP_Error.
 */
function simple_a11y_scanner_enqueue_from_sitemap( $sitemap_url ) {
    if ( ! function_exists( 'simple_a11y_scanner_fetch_sitemap' ) ) {
        return new \WP_Error( 'sitemap_not_loaded', 'Sitemap module is not loaded.' );
    }

    $urls = simple_a11y_scanner_fetch_sitemap( $sitemap_url );
    if ( \is_wp_error( $urls ) ) {
        return $urls;
    }

    return simple_a11y_scanner_enqueue_bulk( $urls );
}

// -------------------------------------------------------------------------
// Worker callback — runs in the background for each URL
// -------------------------------------------------------------------------

/**
 * Scan a single URL and persist the result.
 * Hooked to the 'simple_a11y_scanner_scan_url' action.
 *
 * @param string $url       URL to fetch and scan.
 * @param string $batch_id  Batch identifier.
 */
function simple_a11y_scanner_process_url( $url, $batch_id = '' ) {
    $response = wp_remote_get( esc_url_raw( $url ), [
        'timeout'    => 20,
        'user-agent' => 'SimpleA11yScanner/1.0',
    ] );

    if ( \is_wp_error( $response ) ) {
        simple_a11y_scanner_store_result( $batch_id, $url, [], $response->get_error_message() );
        return;
    }

    $body    = wp_remote_retrieve_body( $response );
    $opts    = function_exists( 'simple_a11y_scanner_get_options' ) ? simple_a11y_scanner_get_options() : [];
    $scanner = new \SimpleA11yScanner\Scanner();
    $issues  = $scanner->scanContent( $body, $opts );

    // Fire the same notification hook as the REST endpoint.
    \do_action( 'simple_a11y_scanner_after_scan', $issues, $url );

    simple_a11y_scanner_store_result( $batch_id, $url, $issues );
}
add_action( 'simple_a11y_scanner_scan_url', 'simple_a11y_scanner_process_url', 10, 2 );

// -------------------------------------------------------------------------
// Result storage (lightweight — transient-based, 7-day TTL)
// -------------------------------------------------------------------------

/**
 * Persist scan results for a URL within a batch.
 *
 * @param string   $batch_id  Batch ID.
 * @param string   $url       Scanned URL.
 * @param array[]  $issues    Issue list from Scanner::scanContent().
 * @param string   $error     Error message if the fetch failed.
 */
function simple_a11y_scanner_store_result( $batch_id, $url, array $issues, $error = '' ) {
    $key     = 'sas_batch_' . md5( $batch_id );
    $results = get_transient( $key );
    if ( ! is_array( $results ) ) {
        $results = [];
    }

    $results[ $url ] = [
        'issues' => $issues,
        'count'  => count( $issues ),
        'error'  => $error,
        'time'   => current_time( 'mysql' ),
    ];

    set_transient( $key, $results, WEEK_IN_SECONDS );
}

/**
 * Retrieve stored batch results.
 *
 * @param string $batch_id
 * @return array[]  Keyed by URL.
 */
function simple_a11y_scanner_get_batch_results( $batch_id ) {
    $key = 'sas_batch_' . md5( $batch_id );
    return (array) get_transient( $key );
}

// -------------------------------------------------------------------------
// REST endpoints for bulk scan
// -------------------------------------------------------------------------

add_action( 'rest_api_init', function () {

    // POST /wp-json/simple-a11y/v1/bulk-scan — enqueue URLs for background scanning.
    register_rest_route( 'simple-a11y/v1', '/bulk-scan', [
        'methods'             => 'POST',
        'callback'            => 'simple_a11y_scanner_rest_bulk_scan',
        'permission_callback' => fn() => \current_user_can( 'manage_options' ),
        'args'                => [
            'urls' => [
                'required' => false,
                'type'     => 'array',
                'items'    => [ 'type' => 'string' ],
            ],
            'sitemap_url' => [
                'required'          => false,
                'sanitize_callback' => 'esc_url_raw',
            ],
        ],
    ] );

    // GET /wp-json/simple-a11y/v1/bulk-scan/{batch_id} — retrieve results.
    register_rest_route( 'simple-a11y/v1', '/bulk-scan/(?P<batch_id>[a-f0-9\-]{36})', [
        'methods'             => 'GET',
        'callback'            => 'simple_a11y_scanner_rest_bulk_results',
        'permission_callback' => fn() => \current_user_can( 'manage_options' ),
    ] );

} );

function simple_a11y_scanner_rest_bulk_scan( \WP_REST_Request $request ) {
    $sitemap_url = $request->get_param( 'sitemap_url' );
    $urls        = $request->get_param( 'urls' ) ?? [];

    if ( $sitemap_url ) {
        $result = simple_a11y_scanner_enqueue_from_sitemap( $sitemap_url );
        if ( \is_wp_error( $result ) ) {
            return new \WP_REST_Response( [ 'error' => $result->get_error_message() ], 502 );
        }
        // Extract batch_id from last enqueued batch.
        $batches  = get_option( 'simple_a11y_scanner_batches', [] );
        end( $batches );
        $batch_id = key( $batches );
        return new \WP_REST_Response( [
            'batch_id' => $batch_id,
            'queued'   => $result,
            'engine'   => simple_a11y_scanner_has_action_scheduler() ? 'action-scheduler' : 'wp-cron',
        ], 202 );
    }

    if ( empty( $urls ) ) {
        return new \WP_REST_Response( [ 'error' => 'Provide urls[] or sitemap_url.' ], 400 );
    }

    $urls     = array_map( 'esc_url_raw', (array) $urls );
    $batch_id = wp_generate_uuid4();
    $queued   = simple_a11y_scanner_enqueue_bulk( $urls, $batch_id );

    return new \WP_REST_Response( [
        'batch_id' => $batch_id,
        'queued'   => $queued,
        'engine'   => simple_a11y_scanner_has_action_scheduler() ? 'action-scheduler' : 'wp-cron',
    ], 202 );
}

function simple_a11y_scanner_rest_bulk_results( \WP_REST_Request $request ) {
    $batch_id = sanitize_text_field( $request->get_param( 'batch_id' ) );
    $results  = simple_a11y_scanner_get_batch_results( $batch_id );

    $batches  = get_option( 'simple_a11y_scanner_batches', [] );
    $meta     = $batches[ $batch_id ] ?? [];

    return new \WP_REST_Response( [
        'batch_id'   => $batch_id,
        'meta'       => $meta,
        'completed'  => count( $results ),
        'results'    => $results,
    ], 200 );
}
