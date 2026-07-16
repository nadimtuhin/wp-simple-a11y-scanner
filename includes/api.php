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

        register_rest_route( 'simple-a11y/v1', '/sitemap/urls', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'handleSitemapUrls' ],
            'permission_callback' => fn() => \current_user_can( 'manage_options' ),
            'args'                => [
                'url' => [
                    'required'          => true,
                    'sanitize_callback' => 'esc_url_raw',
                ],
            ],
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
            'total'        => count( $issues ),
            'missing_alt'  => 0,
            'empty_link'   => 0,
            'vague_link'   => 0,
            'low_contrast' => 0,
        ];
        foreach ( $issues as $issue ) {
            if ( isset( $summary[ $issue['type'] ] ) ) {
                $summary[ $issue['type'] ]++;
            }
        }
        return new \WP_REST_Response( [ 'summary' => $summary ], 200 );
    }

    public function handleSitemapUrls( $request ) {
        $url = $request->get_param( 'url' );

        // If URL looks like a site root (no .xml extension), auto-discover.
        if ( ! preg_match( '/\.xml$/i', $url ) ) {
            $discovered = function_exists( 'simple_a11y_scanner_discover_sitemap' )
                ? simple_a11y_scanner_discover_sitemap( $url )
                : null;
            if ( null === $discovered ) {
                return new \WP_REST_Response( [ 'error' => 'No sitemap found for ' . $url ], 404 );
            }
            $url = $discovered;
        }

        $result = function_exists( 'simple_a11y_scanner_fetch_sitemap' )
            ? simple_a11y_scanner_fetch_sitemap( $url )
            : new \WP_Error( 'not_available', 'Sitemap module not loaded.' );

        if ( \is_wp_error( $result ) ) {
            return new \WP_REST_Response( [ 'error' => $result->get_error_message() ], 502 );
        }

        return new \WP_REST_Response( [ 'sitemap_url' => $url, 'urls' => $result, 'count' => count( $result ) ], 200 );
    }
}
