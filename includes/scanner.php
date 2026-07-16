<?php
namespace SimpleA11yScanner;

class Scanner {
    const VAGUE_PHRASES = ['click here', 'read more', 'here', 'more', 'this', 'link', 'learn more'];

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

            if ( $ratio < 4.5 ) {
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
     * Scan HTML content for accessibility issues.
     *
     * @param string $content HTML to scan.
     * @param array  $opts    Plugin options controlling which checks run.
     *                        Keys: check_missing_alt, check_empty_links, check_vague_links.
     *                        Defaults to all checks enabled.
     * @return array[]        List of issue arrays (type, message, element).
     */
    public function scanContent( $content, array $opts = [] ) {
        $issues = [];

        $check_alt      = isset( $opts['check_missing_alt'] )    ? (bool) $opts['check_missing_alt']    : true;
        $check_empty    = isset( $opts['check_empty_links'] )     ? (bool) $opts['check_empty_links']     : true;
        $check_vague    = isset( $opts['check_vague_links'] )     ? (bool) $opts['check_vague_links']     : true;
        $check_contrast = isset( $opts['check_inline_contrast'] ) ? (bool) $opts['check_inline_contrast'] : true;

        // a) Images missing alt attribute.
        if ( $check_alt && preg_match_all( '/<img\b[^>]*>/i', $content, $img_matches ) ) {
            foreach ( $img_matches[0] as $img_tag ) {
                if ( ! preg_match( '/\balt\s*=/i', $img_tag ) ) {
                    $issues[] = [
                        'type'    => 'missing_alt',
                        'message' => 'Image missing alt attribute.',
                        'element' => $img_tag,
                    ];
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
                    $issues[] = [
                        'type'    => 'empty_link',
                        'message' => 'Link has empty text.',
                        'element' => $link_tag,
                    ];
                } elseif ( $check_vague && in_array( strtolower( $link_text ), self::VAGUE_PHRASES, true ) ) {
                    $issues[] = [
                        'type'    => 'vague_link',
                        'message' => sprintf( 'Link text "%s" is vague and not descriptive.', $link_text ),
                        'element' => $link_tag,
                    ];
                }
            }
        }

        // d) Inline CSS colour contrast.
        if ( $check_contrast ) {
            $issues = array_merge( $issues, $this->checkInlineContrast( $content ) );
        }

        return $issues;
    }
}
