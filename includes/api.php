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

    // v1 handlers

    public function handleScan( $request ) {
        $rl = $this->checkRateLimit();
        if ( \is_wp_error( $rl ) ) {
            return new \WP_REST_Response( [ 'error' => $rl->get_error_message() ], 429 );
        }

        $content = $request->get_param( 'content' );
        if ( empty( $content ) ) {
            return new \WP_REST_Response( [ 'error' => 'Empty content' ], 400 );
        }

        /**
         * Fires before a v1 REST scan begins.
         *
         * @param string           $content The HTML content about to be scanned.
         * @param \WP_REST_Request $request The REST request object.
         */
        \do_action( 'simple_a11y_scanner_before_scan', $content, $request );

        $opts    = function_exists( 'simple_a11y_scanner_get_options' ) ? simple_a11y_scanner_get_options() : [];
        $scanner = new Scanner();
        $issues  = $scanner->scanContent( $content, $opts );
        $tab     = $scanner->analyseTabOrder( $content );

        \do_action( 'simple_a11y_scanner_after_scan', $issues, '' );

        $response_data = [
            'issues'               => $issues,
            'count'                => count( $issues ),
            'keyboard_nav_metrics' => $tab['metrics'],
        ];

        /**
         * Filter the v1 scan REST API response data before it is returned.
         * Use to add, remove, or transform fields in the response.
         *
         * @param array            $response_data Response array.
         * @param \WP_REST_Request $request       Original REST request.
         * @param array[]          $issues        Issues found.
         */
        $response_data = \apply_filters( 'simple_a11y_scanner_api_response', $response_data, $request, $issues );

        return new \WP_REST_Response( $response_data, 200 );
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

        /**
         * Filter the scan summary data before returning to client.
         *
         * @param array    $summary  Summary counts keyed by issue type.
         * @param array[]  $issues   Full issue list.
         * @param string   $content  Scanned HTML content.
         */
        $summary = \apply_filters( 'simple_a11y_scanner_scan_summary', $summary, $issues, $content );

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

        /**
         * Filter the list of URLs extracted from the sitemap before returning.
         *
         * @param string[] $result      Array of URLs.
         * @param string   $url         The sitemap URL that was parsed.
         * @param \WP_REST_Request $request REST request object.
         */
        $result = \apply_filters( 'simple_a11y_scanner_sitemap_urls', $result, $url, $request );

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

    // v2 handlers

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

        /**
         * Fires before a v2 REST scan begins.
         *
         * @param string           $content The HTML content about to be scanned.
         * @param \WP_REST_Request $request The REST request object.
         */
        \do_action( 'simple_a11y_scanner_before_scan', $content, $request );

        $opts    = function_exists( 'simple_a11y_scanner_get_options' ) ? simple_a11y_scanner_get_options() : [];
        $scanner = new Scanner();
        $issues  = $scanner->scanContent( $content, $opts );
        $tab     = $scanner->analyseTabOrder( $content );

        // Attach severity to each issue.
        foreach ( $issues as &$issue ) {
            $severity = function_exists( 'simple_a11y_scanner_severity' )
                ? simple_a11y_scanner_severity( $issue['type'] )
                : 'minor';

            /**
             * Filter the severity assigned to an individual issue.
             * Override to promote or demote specific issue types per your context.
             *
             * @param string $severity  Computed severity ('critical'|'major'|'minor').
             * @param string $type      Issue type key (e.g. 'missing_alt').
             * @param array  $issue     Full issue array.
             */
            $issue['severity'] = \apply_filters( 'simple_a11y_scanner_issue_severity', $severity, $issue['type'], $issue );
        }
        unset( $issue );

        $score_data = function_exists( 'simple_a11y_scanner_score' )
            ? simple_a11y_scanner_score( $issues )
            : [ 'critical' => 0, 'major' => 0, 'minor' => count( $issues ), 'score' => count( $issues ) ];

        \do_action( 'simple_a11y_scanner_after_scan', $issues, '' );

        $response_data = [
            'issues'               => $issues,
            'count'                => count( $issues ),
            'score'                => $score_data,
            'keyboard_nav_metrics' => $tab['metrics'],
            'api_version'          => 'v2',
        ];

        /**
         * Filter the v2 scan REST API response data before it is returned.
         *
         * @param array            $response_data Response data.
         * @param \WP_REST_Request $request       Original REST request.
         * @param array[]          $issues        Issues with severity attached.
         */
        $response_data = \apply_filters( 'simple_a11y_scanner_api_response_v2', $response_data, $request, $issues );

        return new \WP_REST_Response( $response_data, 200 );
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

        /**
         * Filter audit log entries before returning via REST.
         * Use to redact sensitive data or add computed fields.
         *
         * @param array $entries   Raw log entry rows.
         * @param int   $per_page  Requested page size.
         * @param int   $page      Current page number.
         */
        $entries  = \apply_filters( 'simple_a11y_scanner_audit_log_entries', $entries, $per_page, $page );

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

        /**
         * Filter social meta issues before returning via REST.
         * Use to add custom social meta checks or suppress known false positives.
         *
         * @param array[] $issues   Issues found in social meta fields.
         * @param int     $post_id  Post being scanned.
         */
        $issues = \apply_filters( 'simple_a11y_scanner_social_meta_issues', $issues, $post_id );

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

        /**
         * Filter the custom scan rules returned by the REST API.
         * Use to inject built-in rules, disable rules, or reorder them.
         *
         * @param array[] $rules  Stored custom rules.
         */
        $rules = \apply_filters( 'simple_a11y_scanner_scan_rules', $rules );

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

        /**
         * Fires before custom rules are persisted.
         * Use to validate, transform, or audit rule changes.
         *
         * @param array[] $clean   Sanitized rules about to be saved.
         * @param array   $raw     Raw input from the request.
         */
        \do_action( 'simple_a11y_scanner_before_save_rules', $clean, $raw );

        \update_option( 'simple_a11y_scanner_custom_rules', $clean );

        /**
         * Fires after custom rules are saved to the database.
         *
         * @param array[] $clean  The saved rules.
         */
        \do_action( 'simple_a11y_scanner_after_save_rules', $clean );

        return new \WP_REST_Response( [ 'saved' => count( $clean ), 'rules' => $clean ], 200 );
    }
}
