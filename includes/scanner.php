<?php
namespace SimpleA11yScanner;

class Scanner {
    const VAGUE_PHRASES = ['click here', 'read more', 'here', 'more', 'this', 'link', 'learn more'];

    public function scanContent( $content ) {
        $issues = [];

        // a) Images missing alt attribute
        if ( preg_match_all( '/<img\b[^>]*>/i', $content, $img_matches ) ) {
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

        // b) & c) Links: empty text or vague text
        if ( preg_match_all( '/<a\b[^>]*>(.*?)<\/a>/is', $content, $link_matches, PREG_SET_ORDER ) ) {
            foreach ( $link_matches as $match ) {
                $link_tag  = $match[0];
                $link_text = trim( strip_tags( $match[1] ) );

                if ( $link_text === '' ) {
                    $issues[] = [
                        'type'    => 'empty_link',
                        'message' => 'Link has empty text.',
                        'element' => $link_tag,
                    ];
                } elseif ( in_array( strtolower( $link_text ), self::VAGUE_PHRASES, true ) ) {
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
