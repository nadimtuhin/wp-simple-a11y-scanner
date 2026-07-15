<?php
namespace SimpleA11yScanner;

class Api {
    public function registerRoutes() {
        register_rest_route( 'simple-a11y/v1', '/scan', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'handleScan' ],
            'permission_callback' => '__return_true',
            'args'                => [
                'content' => [
                    'required'          => true,
                    'sanitize_callback' => 'wp_kses_post',
                ],
            ],
        ] );

        register_rest_route( 'simple-a11y/v1', '/scan/summary', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'handleScanSummary' ],
            'permission_callback' => '__return_true',
        ] );
    }

    public function handleScan( $request ) {
        $content = $request->get_param( 'content' );
        if ( empty( $content ) ) {
            return new \WP_REST_Response( [ 'error' => 'Empty content' ], 400 );
        }
        $opts    = function_exists( 'simple_a11y_scanner_get_options' ) ? simple_a11y_scanner_get_options() : [];
        $scanner = new Scanner();
        $issues  = $scanner->scanContent( $content, $opts );

        // Fire notification hook (handled by notifications.php).
        \do_action( 'simple_a11y_scanner_after_scan', $issues, '' );

        return new \WP_REST_Response( [ 'issues' => $issues, 'count' => count( $issues ) ], 200 );
    }

    public function handleScanSummary( $request ) {
        $content = $request->get_param( 'content' );
        if ( empty( $content ) ) {
            return new \WP_REST_Response( [ 'error' => 'Empty content' ], 400 );
        }
        $opts    = function_exists( 'simple_a11y_scanner_get_options' ) ? simple_a11y_scanner_get_options() : [];
        $scanner = new Scanner();
        $issues  = $scanner->scanContent( $content, $opts );

        $summary = [
            'total'       => count( $issues ),
            'missing_alt' => 0,
            'empty_link'  => 0,
            'vague_link'  => 0,
        ];
        foreach ( $issues as $issue ) {
            if ( isset( $summary[ $issue['type'] ] ) ) {
                $summary[ $issue['type'] ]++;
            }
        }
        return new \WP_REST_Response( [ 'summary' => $summary ], 200 );
    }
}
