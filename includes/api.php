<?php
namespace SimpleA11yScanner;

class Api {

    /**
     * Get the required capability for scan endpoints from options.
     */
    private function scanCap(): string {
        $opts = function_exists( 'simple_a11y_scanner_get_options' ) ? simple_a11y_scanner_get_options() : [];
        return $opts['scan_capability'] ?? 'manage_options';
    }

    /**
     * Check rate limit for current request.
     * Returns WP_Error if throttled.
     *
     * @return true|\WP_Error
     */
    private function checkRateLimit() {
        if ( ! function_exists( 'simple_a11y_scanner_check_rate_limit' ) ) {
            return true;
        }
        $opts   = function_exists( 'simple_a11y_scanner_get_options' ) ? simple_a11y_scanner_get_options() : [];
        $limit  = (int) ( $opts['rate_limit_max'] ?? 30 );
        $window = (int) ( $opts['rate_limit_window'] ?? 60 );
        return simple_a11y_scanner_check_rate_limit( $limit, $window );
    }

    public function registerRoutes(): void {
        // --- v1 routes (kept for backward compat) ---
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

        register_rest_route( 'simple-a11y/v1', '/scan/post-meta/(?P<post_id>\d+)', [
            'methods'             => 'GET',
            'callback'            => [ $this, 'handleScanPostMeta' ],
            'permission_callback' => fn() => \current_user_can( 'edit_posts' ),
            'args'                => [
                'post_id' => [
                    'validate_callback' => fn( $v ) => is_numeric( $v ) && (int) $v > 0,
                ],
            ],
        ] );

        // --- v2 routes ---
        register_rest_route( 'simple-a11y/v2', '/scan', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'handleScanV2' ],
            'permission_callback' => fn() => \current_user_can( $this->scanCap() ),
            'args'                => [
                'content' => [
                    'required'          => true,
                    'sanitize_callback' => 'wp_kses_post',
                ],
            ],
        ] );

        register_rest_route( 'simple-a11y/v2', '/audit-log', [
            'methods'             => 'GET',
            'callback'            => [ $this, 'handleAuditLog' ],
            'permission_callback' => fn() => \current_user_can( 'manage_options' ),
            'args'                => [
                'per_page' => [ 'default' => 50, 'sanitize_callback' => 'absint' ],
                'page'     => [ 'default' => 1,  'sanitize_callback' => 'absint' ],
            ],
        ] );

        register_rest_route( 'simple-a11y/v2', '/scan/social/(?P<post_id>\d+)', [
            'methods'             => 'GET',
            'callback'            => [ $this, 'handleSocialMetaScan' ],
            'permission_callback' => fn() => \current_user_can( 'edit_posts' ),
            'args'                => [
                'post_id' => [
                    'validate_callback' => fn( $v ) => is_numeric( $v ) && (int) $v > 0,
                ],
            ],
        ] );

        register_rest_route( 'simple-a11y/v2', '/rules', [
            'methods'             => 'GET',
            'callback'            => [ $this, 'handleListRules' ],
            'permission_callback' => fn() => \current_user_can( 'manage_options' ),
        ] );

        register_rest_route( 'simple-a11y/v2', '/rules', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'handleSaveRules' ],
            'permission_callback' => fn() => \current_user_can( 'manage_options' ),
        ] );
    }

    // -------------------------------------------------------------------------
    // v1 handlers
    // -------------------------------------------------------------------------

    public function handleScan( $request ) {
        $rl = $this->checkRateLimit();
        if ( \is_wp_error( $rl ) ) {
            return new \WP_REST_Response( [ 'error' => $rl->get_error_message() ], 429 );
        }

        $content = $request->get_param( 'content' );
        if ( empty( $content ) ) {
            return new \WP_REST_Response( [ 'error' => 'Empty content' ], 400 );
        }
        $opts    = function_exists( 'simple_a11y_scanner_get_options' ) ? simple_a11y_scanner_get_options() : [];
        $scanner = new Scanner();
        $issues  = $scanner->scanContent( $content, $opts );
        $tab     = $scanner->analyseTabOrder( $content );

        \do_action( 'simple_a11y_scanner_after_scan', $issues, '' );

        return new \WP_REST_Response( [
            'issues'               => $issues,
            'count'                => count( $issues ),
            'keyboard_nav_metrics' => $tab['metrics'],
        ], 200 );
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
            'target_size'  => 0,
            'keyboard_nav' => 0,
        ];
        foreach ( $issues as $issue ) {
            if ( isset( $summary[ $issue['type'] ] ) ) {
                $summary[ $issue['type'] ]++;
            }
        }

        $tab = $scanner->analyseTabOrder( $content );

        return new \WP_REST_Response( [
            'summary'              => $summary,
            'keyboard_nav_metrics' => $tab['metrics'],
        ], 200 );
    }

    public function handleSitemapUrls( $request ) {
        $url = $request->get_param( 'url' );

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

    public function handleScanPostMeta( $request ) {
        $post_id = (int) $request->get_param( 'post_id' );
        $opts    = function_exists( 'simple_a11y_scanner_get_options' ) ? simple_a11y_scanner_get_options() : [];
        $scanner = new Scanner();
        $issues  = $scanner->scanGutenbergMetaBlocks( $post_id, $opts );

        return new \WP_REST_Response( [
            'post_id' => $post_id,
            'issues'  => $issues,
            'count'   => count( $issues ),
        ], 200 );
    }

    // -------------------------------------------------------------------------
    // v2 handlers
    // -------------------------------------------------------------------------

    /**
     * v2 scan: includes severity scoring and writes audit log.
     */
    public function handleScanV2( $request ) {
        $rl = $this->checkRateLimit();
        if ( \is_wp_error( $rl ) ) {
            return new \WP_REST_Response( [ 'error' => $rl->get_error_message() ], 429 );
        }

        $content = $request->get_param( 'content' );
        if ( empty( $content ) ) {
            return new \WP_REST_Response( [ 'error' => 'Empty content' ], 400 );
        }

        $opts    = function_exists( 'simple_a11y_scanner_get_options' ) ? simple_a11y_scanner_get_options() : [];
        $scanner = new Scanner();
        $issues  = $scanner->scanContent( $content, $opts );
        $tab     = $scanner->analyseTabOrder( $content );

        // Attach severity to each issue.
        foreach ( $issues as &$issue ) {
            $issue['severity'] = function_exists( 'simple_a11y_scanner_severity' )
                ? simple_a11y_scanner_severity( $issue['type'] )
                : 'minor';
        }
        unset( $issue );

        $score_data = function_exists( 'simple_a11y_scanner_score' )
            ? simple_a11y_scanner_score( $issues )
            : [ 'critical' => 0, 'major' => 0, 'minor' => count( $issues ), 'score' => count( $issues ) ];

        \do_action( 'simple_a11y_scanner_after_scan', $issues, '' );

        return new \WP_REST_Response( [
            'issues'               => $issues,
            'count'                => count( $issues ),
            'score'                => $score_data,
            'keyboard_nav_metrics' => $tab['metrics'],
            'api_version'          => 'v2',
        ], 200 );
    }

    /**
     * Return paginated audit log entries.
     */
    public function handleAuditLog( $request ) {
        if ( ! class_exists( '\SimpleA11yScanner\AuditLog' ) ) {
            return new \WP_REST_Response( [ 'error' => 'Audit log not available' ], 503 );
        }
        $per_page = (int) $request->get_param( 'per_page' );
        $page     = (int) $request->get_param( 'page' );

        $entries  = AuditLog::getEntries( $per_page, $page );
        $total    = AuditLog::countEntries();
        $response = new \WP_REST_Response( $entries, 200 );
        $response->header( 'X-WP-Total', $total );
        $response->header( 'X-WP-TotalPages', (int) ceil( $total / $per_page ) );
        return $response;
    }

    /**
     * Scan social meta (og:title, og:description, twitter:card) for a11y issues.
     */
    public function handleSocialMetaScan( $request ) {
        $post_id = (int) $request->get_param( 'post_id' );
        $issues  = [];

        // Read common social meta keys (supports Yoast, RankMath, custom).
        $og_title       = \get_post_meta( $post_id, '_yoast_wpseo_opengraph-title', true )
            ?: \get_post_meta( $post_id, 'rank_math_facebook_title', true )
            ?: \get_the_title( $post_id );
        $og_description = \get_post_meta( $post_id, '_yoast_wpseo_opengraph-description', true )
            ?: \get_post_meta( $post_id, 'rank_math_facebook_description', true )
            ?: \get_post_meta( $post_id, '_yoast_wpseo_metadesc', true );
        $og_image       = \get_post_meta( $post_id, '_yoast_wpseo_opengraph-image', true )
            ?: \get_post_meta( $post_id, 'rank_math_facebook_image', true );

        if ( empty( $og_title ) ) {
            $issues[] = [
                'type'    => 'social_meta',
                'message' => 'Missing og:title — social share previews will use raw post title which may not be descriptive.',
                'field'   => 'og:title',
            ];
        } elseif ( strlen( $og_title ) > 70 ) {
            $issues[] = [
                'type'    => 'social_meta',
                'message' => sprintf( 'og:title is %d characters (recommended ≤70).', strlen( $og_title ) ),
                'field'   => 'og:title',
            ];
        }

        if ( empty( $og_description ) ) {
            $issues[] = [
                'type'    => 'social_meta',
                'message' => 'Missing og:description — social share previews will have no context.',
                'field'   => 'og:description',
            ];
        } elseif ( strlen( $og_description ) > 200 ) {
            $issues[] = [
                'type'    => 'social_meta',
                'message' => sprintf( 'og:description is %d characters (recommended ≤200).', strlen( $og_description ) ),
                'field'   => 'og:description',
            ];
        }

        if ( empty( $og_image ) ) {
            $issues[] = [
                'type'    => 'social_meta',
                'message' => 'Missing og:image — social shares will appear without a preview image (reduced engagement).',
                'field'   => 'og:image',
            ];
        }

        return new \WP_REST_Response( [
            'post_id' => $post_id,
            'issues'  => $issues,
            'count'   => count( $issues ),
            'meta'    => [
                'og_title'       => $og_title,
                'og_description' => $og_description,
                'og_image'       => $og_image,
            ],
        ], 200 );
    }

    /**
     * List custom rules stored in options.
     */
    public function handleListRules( $request ) {
        $rules = \get_option( 'simple_a11y_scanner_custom_rules', [] );
        return new \WP_REST_Response( [ 'rules' => $rules, 'count' => count( $rules ) ], 200 );
    }

    /**
     * Save/replace custom rules.
     */
    public function handleSaveRules( $request ) {
        $raw   = $request->get_json_params();
        $rules = isset( $raw['rules'] ) && is_array( $raw['rules'] ) ? $raw['rules'] : [];

        $clean = [];
        foreach ( $rules as $rule ) {
            if ( empty( $rule['id'] ) || empty( $rule['pattern'] ) ) {
                continue;
            }
            $clean[] = [
                'id'       => sanitize_key( $rule['id'] ),
                'label'    => sanitize_text_field( $rule['label'] ?? '' ),
                'pattern'  => sanitize_text_field( $rule['pattern'] ),
                'message'  => sanitize_text_field( $rule['message'] ?? '' ),
                                'severity' => ( isset( $rule['severity'] ) && in_array( $rule['severity'], [ 'critical', 'major', 'minor' ], true ) )
                    ? $rule['severity']
                    : 'minor',
                'enabled'  => ! empty( $rule['enabled'] ),
            ];
        }

        \update_option( 'simple_a11y_scanner_custom_rules', $clean );

        return new \WP_REST_Response( [ 'saved' => count( $clean ), 'rules' => $clean ], 200 );
    }
}
