<?php
namespace SimpleA11yScanner;

class Scanner {
    const VAGUE_PHRASES = ['click here', 'read more', 'here', 'more', 'this', 'link', 'learn more'];

    /** WCAG 2.2 minimum target size in CSS pixels. */
    const TARGET_SIZE_MIN = 24;

    /**
     * Convert a CSS hex/rgb colour to relative luminance (WCAG 2.1 formula).
     *
     * @param string $color  CSS colour: #rgb, #rrggbb, or rgb(r,g,b).
     * @return float|null    Luminance 0..1, or null if unparseable.
     */
    public function colorLuminance( $color ) {
        $color = trim( $color );

        // rgb(r, g, b)
        if ( preg_match( '/^rgb\(\s*(\d+)\s*,\s*(\d+)\s*,\s*(\d+)\s*\)$/i', $color, $m ) ) {
            $r = (int) $m[1]; $g = (int) $m[2]; $b = (int) $m[3];
        } elseif ( preg_match( '/^#([0-9a-f]{3,6})$/i', $color, $m ) ) {
            $hex = $m[1];
            if ( strlen( $hex ) === 3 ) {
                $hex = $hex[0].$hex[0].$hex[1].$hex[1].$hex[2].$hex[2];
            }
            $r = hexdec( substr( $hex, 0, 2 ) );
            $g = hexdec( substr( $hex, 2, 2 ) );
            $b = hexdec( substr( $hex, 4, 2 ) );
        } else {
            return null;
        }

        $srgb = function( $c ) {
            $c /= 255.0;
            return $c <= 0.04045 ? $c / 12.92 : pow( ( $c + 0.055 ) / 1.055, 2.4 );
        };
        return 0.2126 * $srgb( $r ) + 0.7152 * $srgb( $g ) + 0.0722 * $srgb( $b );
    }

    /**
     * Contrast ratio between two luminances.
     *
     * @param float $l1
     * @param float $l2
     * @return float
     */
    public function contrastRatio( $l1, $l2 ) {
        $lighter = max( $l1, $l2 );
        $darker  = min( $l1, $l2 );
        return ( $lighter + 0.05 ) / ( $darker + 0.05 );
    }

    /**
     * Check inline-style colour/background-color pairs for WCAG AA contrast.
     * Returns issues for pairs with ratio < 4.5.
     *
     * @param string $content HTML to scan.
     * @return array[]
     */
    public function checkInlineContrast( $content ) {
        $issues = [];

        // Match elements with style="..." attributes.
        if ( ! preg_match_all( '/<[a-z][^>]*\bstyle\s*=\s*["\']([^"\']*)["\'][^>]*>/i', $content, $matches, PREG_SET_ORDER ) ) {
            return $issues;
        }

        /**
         * Filter the minimum contrast ratio threshold for inline style checks.
         * Default is 4.5 (WCAG AA). Override to 3.0 for large text, etc.
         *
         * @param float  $threshold Minimum acceptable contrast ratio.
         * @param string $content   HTML being scanned.
         */
        $min_contrast = apply_filters( 'simple_a11y_scanner_min_contrast_ratio', 4.5, $content );

        foreach ( $matches as $m ) {
            $tag   = $m[0];
            $style = $m[1];

            $color_val = null;
            $bg_val    = null;

            if ( preg_match( '/(?:^|;)\s*color\s*:\s*([^;]+)/i', $style, $cm ) ) {
                $color_val = trim( $cm[1] );
            }
            if ( preg_match( '/(?:^|;)\s*background(?:-color)?\s*:\s*([^;]+)/i', $style, $bm ) ) {
                $bg_val = trim( $bm[1] );
            }

            if ( null === $color_val || null === $bg_val ) {
                continue;
            }

            $lum_fg = $this->colorLuminance( $color_val );
            $lum_bg = $this->colorLuminance( $bg_val );

            if ( null === $lum_fg || null === $lum_bg ) {
                continue; // unparseable named colour — skip
            }

            $ratio = $this->contrastRatio( $lum_fg, $lum_bg );

            if ( $ratio < $min_contrast ) {
                $issues[] = [
                    'type'    => 'low_contrast',
                    'message' => sprintf(
                        'Inline colour contrast ratio %.2f:1 is below WCAG AA minimum (4.5:1). color: %s; background: %s.',
                        $ratio,
                        $color_val,
                        $bg_val
                    ),
                    'element' => $tag,
                ];
            }
        }

        return $issues;
    }

    /**
     * Parse a CSS px value string into an integer, or return null.
     *
     * @param string $val e.g. "20px", " 20px ", "20".
     * @return int|null
     */
    private function parsePx( $val ) {
        $val = trim( $val );
        if ( preg_match( '/^(\d+(?:\.\d+)?)px?$/i', $val, $m ) ) {
            return (int) round( (float) $m[1] );
        }
        return null;
    }

    /**
     * Check interactive elements (a, button, input, select, textarea) for
     * WCAG 2.2 SC 2.5.8 minimum target size (24×24 CSS px).
     *
     * Uses inline style width/height/padding as a heuristic proxy; elements
     * without any size-related inline style are skipped (they need runtime
     * measurement).
     *
     * @param string $content HTML to scan.
     * @return array[]
     */
    public function checkTargetSize( $content ) {
        $issues = [];

        // Match interactive elements that carry a style attribute.
        $pattern = '/<(a|button|input|select|textarea)\b([^>]*)\bstyle\s*=\s*["\']([^"\']*)["\']([^>]*)>/i';
        if ( ! preg_match_all( $pattern, $content, $matches, PREG_SET_ORDER ) ) {
            return $issues;
        }

        /**
         * Filter the minimum target size in pixels (WCAG 2.2 SC 2.5.8).
         * Default is 24px. Override to enforce stricter requirements.
         *
         * @param int    $min_size Minimum size in CSS pixels.
         * @param string $content  HTML being scanned.
         */
        $min_size = apply_filters( 'simple_a11y_scanner_min_target_size', self::TARGET_SIZE_MIN, $content );

        foreach ( $matches as $m ) {
            $tag   = $m[0];
            $style = $m[3];

            $width  = null;
            $height = null;

            if ( preg_match( '/(?:^|;)\s*width\s*:\s*([^;]+)/i', $style, $wm ) ) {
                $width = $this->parsePx( $wm[1] );
            }
            if ( preg_match( '/(?:^|;)\s*height\s*:\s*([^;]+)/i', $style, $hm ) ) {
                $height = $this->parsePx( $hm[1] );
            }

            // If both dimensions are explicit, check the smaller axis.
            if ( null !== $width && null !== $height ) {
                $min_dim = min( $width, $height );
                if ( $min_dim < $min_size ) {
                    $issues[] = [
                        'type'    => 'target_size',
                        'message' => sprintf(
                            'Interactive element target size %dpx×%dpx is below WCAG 2.2 minimum of %dpx (SC 2.5.8).',
                            $width,
                            $height,
                            self::TARGET_SIZE_MIN
                        ),
                        'element' => $tag,
                    ];
                }
                continue;
            }

            // Single-axis check: if only one dimension set and it's too small.
            foreach ( [ $width, $height ] as $dim ) {
                if ( null !== $dim && $dim < $min_size ) {
                    $issues[] = [
                        'type'    => 'target_size',
                        'message' => sprintf(
                            'Interactive element has a dimension of %dpx which is below WCAG 2.2 minimum of %dpx (SC 2.5.8).',
                            $dim,
                            self::TARGET_SIZE_MIN
                        ),
                        'element' => $tag,
                    ];
                    break;
                }
            }
        }

        return $issues;
    }

    /**
     * Analyse the tab order of focusable elements (links and buttons) in HTML.
     *
     * Detects:
     *  - positive tabindex values that create an explicit (often broken) tab order
     *  - tabindex="-1" on interactive elements that removes them from tab flow
     *
     * Returns metrics and per-element violations.
     *
     * @param string $content HTML to scan.
     * @return array { issues: array[], metrics: array }
     */
    public function analyseTabOrder( $content ) {
        $issues  = [];
        $metrics = [
            'total_focusable'       => 0,
            'positive_tabindex'     => 0,
            'negative_tabindex'     => 0,
            'sequential_violations' => 0,
        ];

        // Match <a> and <button> elements (both opening tags).
        if ( ! preg_match_all( '/<(a|button)\b([^>]*)>/i', $content, $matches, PREG_SET_ORDER ) ) {
            return [ 'issues' => $issues, 'metrics' => $metrics ];
        }

        $last_positive = 0;

        foreach ( $matches as $m ) {
            $tag_html = $m[0];
            $attrs    = $m[2];

            $metrics['total_focusable']++;

            $tabindex = null;
            if ( preg_match( '/\btabindex\s*=\s*["\']?\s*(-?\d+)\s*["\']?/i', $attrs, $ti ) ) {
                $tabindex = (int) $ti[1];
            }

            if ( null === $tabindex ) {
                continue; // natural order — fine
            }

            if ( $tabindex < 0 ) {
                $metrics['negative_tabindex']++;
                $issues[] = [
                    'type'    => 'keyboard_nav',
                    'message' => 'Interactive element has tabindex="-1" and is removed from the natural tab order.',
                    'element' => $tag_html,
                ];
                continue;
            }

            if ( $tabindex > 0 ) {
                $metrics['positive_tabindex']++;
                // Detect non-sequential positive tabindex values.
                if ( $last_positive > 0 && $tabindex <= $last_positive ) {
                    $metrics['sequential_violations']++;
                    $issues[] = [
                        'type'    => 'keyboard_nav',
                        'message' => sprintf(
                            'Non-sequential tabindex=%d follows tabindex=%d — this disrupts the expected tab order.',
                            $tabindex,
                            $last_positive
                        ),
                        'element' => $tag_html,
                    ];
                } else {
                    $issues[] = [
                        'type'    => 'keyboard_nav',
                        'message' => sprintf(
                            'Positive tabindex=%d forces an explicit tab order which is often fragile and error-prone. Prefer tabindex="0" or rely on DOM order.',
                            $tabindex
                        ),
                        'element' => $tag_html,
                    ];
                }
                $last_positive = $tabindex;
            }
        }

        return [ 'issues' => $issues, 'metrics' => $metrics ];
    }

    /**
     * Scan Gutenberg post meta blocks registered via register_post_meta.
     *
     * Retrieves all post meta for $post_id that are registered as Gutenberg
     * show_in_rest meta keys, renders each value through scanContent, and
     * returns issues keyed by meta key.
     *
     * In non-WP contexts (e.g. unit tests) the WP functions are no-ops and
     * an empty array is returned.
     *
     * @param int   $post_id Post ID.
     * @param array $opts    Same option keys as scanContent.
     * @return array[]       Flat list of issues with an extra 'meta_key' field.
     */
    public function scanGutenbergMetaBlocks( $post_id, array $opts = [] ) {
        $issues = [];

        if ( ! function_exists( 'get_registered_meta_keys' ) || ! function_exists( 'get_post_meta' ) ) {
            return $issues;
        }

        $registered = get_registered_meta_keys( 'post' );

        foreach ( $registered as $key => $args ) {
            // Only scan meta that is exposed to the REST API (Gutenberg meta blocks).
            if ( empty( $args['show_in_rest'] ) ) {
                continue;
            }

            $value = get_post_meta( $post_id, $key, true );
            if ( ! is_string( $value ) || '' === $value ) {
                continue;
            }

            $meta_issues = $this->scanContent( $value, $opts );
            foreach ( $meta_issues as $issue ) {
                $issue['meta_key'] = $key;
                $issues[]          = $issue;
            }
        }

        return $issues;
    }

    /**
     * Scan HTML content for accessibility issues.
     *
     * @param string $content HTML to scan.
     * @param array  $opts    Plugin options controlling which checks run.
     *                        Keys: check_missing_alt, check_empty_links, check_vague_links,
     *                              check_inline_contrast, check_target_size, check_keyboard_nav.
     *                        Defaults to all checks enabled.
     * @return array[]        List of issue arrays (type, message, element).
     */
    public function scanContent( $content, array $opts = [] ) {
        $issues = [];

        $check_alt         = isset( $opts['check_missing_alt'] )    ? (bool) $opts['check_missing_alt']    : true;
        $check_empty       = isset( $opts['check_empty_links'] )     ? (bool) $opts['check_empty_links']     : true;
        $check_vague       = isset( $opts['check_vague_links'] )     ? (bool) $opts['check_vague_links']     : true;
        $check_contrast    = isset( $opts['check_inline_contrast'] ) ? (bool) $opts['check_inline_contrast'] : true;
        $check_target_size = isset( $opts['check_target_size'] )     ? (bool) $opts['check_target_size']     : true;
        $check_keyboard    = isset( $opts['check_keyboard_nav'] )    ? (bool) $opts['check_keyboard_nav']    : true;

        /**
         * Filter the list of vague link phrases that trigger a 'vague_link' issue.
         * Add or remove phrases to customise what is considered vague.
         *
         * @param string[] $phrases  List of lowercase phrases.
         * @param array    $opts     Plugin options in effect for this scan.
         */
        $vague_phrases = apply_filters( 'simple_a11y_scanner_vague_phrases', self::VAGUE_PHRASES, $opts );

        /**
         * Filter ignored CSS selectors (future use).
         * Allows third-party code to register selectors whose elements should
         * be excluded from all checks. This hook is intentionally fired early
         * so the list is available to all sub-checks.
         *
         * @param string[] $selectors  CSS selectors to ignore (empty by default).
         * @param string   $content    HTML being scanned.
         */
        $ignored_selectors = apply_filters( 'simple_a11y_scanner_ignored_selectors', [], $content );

        /**
         * Fires before content scanning begins.
         * Use to instrument, log, or short-circuit via output buffering.
         *
         * @param string $content HTML about to be scanned.
         * @param array  $opts    Active plugin options.
         */
        do_action( 'simple_a11y_scanner_before_scan_content', $content, $opts );

        // a) Images missing alt attribute.
        if ( $check_alt && preg_match_all( '/<img\b[^>]*>/i', $content, $img_matches ) ) {
            foreach ( $img_matches[0] as $img_tag ) {
                if ( ! preg_match( '/\balt\s*=/i', $img_tag ) ) {
                    $issue = [
                        'type'    => 'missing_alt',
                        'message' => 'Image missing alt attribute.',
                        'element' => $img_tag,
                    ];

                    /**
                     * Fires when a missing-alt issue is detected on an image tag.
                     *
                     * @param array  $issue   Issue data array.
                     * @param string $content Full HTML being scanned.
                     */
                    do_action( 'simple_a11y_scanner_issue_found', $issue, $content );

                    $issues[] = $issue;
                }
            }
        }

        // b) & c) Links: empty text or vague text.
        if ( ( $check_empty || $check_vague )
            && preg_match_all( '/<a\b[^>]*>(.*?)<\/a>/is', $content, $link_matches, PREG_SET_ORDER )
        ) {
            foreach ( $link_matches as $match ) {
                $link_tag  = $match[0];
                $link_text = trim( strip_tags( $match[1] ) );

                if ( $check_empty && $link_text === '' ) {
                    $issue = [
                        'type'    => 'empty_link',
                        'message' => 'Link has empty text.',
                        'element' => $link_tag,
                    ];

                    /**
                     * Fires when an empty-link issue is detected.
                     *
                     * @param array  $issue   Issue data array.
                     * @param string $content Full HTML being scanned.
                     */
                    do_action( 'simple_a11y_scanner_issue_found', $issue, $content );

                    $issues[] = $issue;
                } elseif ( $check_vague && in_array( strtolower( $link_text ), $vague_phrases, true ) ) {
                    $issue = [
                        'type'    => 'vague_link',
                        'message' => sprintf( 'Link text "%s" is vague and not descriptive.', $link_text ),
                        'element' => $link_tag,
                    ];

                    /**
                     * Fires when a vague-link issue is detected.
                     *
                     * @param array  $issue   Issue data array.
                     * @param string $content Full HTML being scanned.
                     */
                    do_action( 'simple_a11y_scanner_issue_found', $issue, $content );

                    $issues[] = $issue;
                }
            }
        }

        // d) Inline CSS colour contrast.
        if ( $check_contrast ) {
            $issues = array_merge( $issues, $this->checkInlineContrast( $content ) );
        }

        // e) WCAG 2.2 target size (SC 2.5.8).
        if ( $check_target_size ) {
            $issues = array_merge( $issues, $this->checkTargetSize( $content ) );
        }

        // f) Keyboard navigation / tab order.
        if ( $check_keyboard ) {
            $tab_result = $this->analyseTabOrder( $content );
            $issues     = array_merge( $issues, $tab_result['issues'] );
        }

        /**
         * Filter the complete list of issues found during a content scan.
         * Use to add custom issues, remove false positives, or reorder results.
         *
         * @param array[] $issues  All issues found.
         * @param string  $content HTML that was scanned.
         * @param array   $opts    Plugin options used for this scan.
         */
        $issues = apply_filters( 'simple_a11y_scanner_scan_issues', $issues, $content, $opts );

        /**
         * Fires after content scanning completes.
         *
         * @param array[] $issues  Issues found.
         * @param string  $content HTML that was scanned.
         * @param array   $opts    Plugin options used for this scan.
         */
        do_action( 'simple_a11y_scanner_after_scan_content', $issues, $content, $opts );

        return $issues;
    }
}
