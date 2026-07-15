<?php
namespace SimpleA11yScanner;

class Scanner {
    const VAGUE_PHRASES = ['click here', 'read more', 'here', 'more', 'this', 'link', 'learn more'];

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

        $check_alt   = isset( $opts['check_missing_alt'] )  ? (bool) $opts['check_missing_alt']  : true;
        $check_empty = isset( $opts['check_empty_links'] )   ? (bool) $opts['check_empty_links']   : true;
        $check_vague = isset( $opts['check_vague_links'] )   ? (bool) $opts['check_vague_links']   : true;

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

        return $issues;
    }
}
