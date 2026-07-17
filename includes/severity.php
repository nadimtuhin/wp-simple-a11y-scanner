<?php
/**
 * Severity scoring for accessibility issues.
 * Critical / Major / Minor mapped by issue type.
 *
 * @package SimpleA11yScanner
 */

if ( ! defined( 'ABSPATH' ) && ! defined( 'PHPUNIT_RUNNING' ) ) {
    exit;
}

/**
 * Return severity label for an issue type.
 *
 * @param string $type  Issue type key (missing_alt, empty_link, etc.).
 * @return string       'critical' | 'major' | 'minor'
 */
function simple_a11y_scanner_severity( string $type ): string {
    $map = [
        'missing_alt'  => 'critical',
        'empty_link'   => 'critical',
        'low_contrast' => 'major',
        'keyboard_nav' => 'major',
        'target_size'  => 'major',
        'vague_link'   => 'minor',
    ];

    /**
     * Filter the severity map.
     *
     * @param array  $map  Default map of type => severity.
     * @param string $type The issue type being looked up.
     */
    $map = apply_filters( 'simple_a11y_scanner_severity_map', $map, $type );

    return $map[ $type ] ?? 'minor';
}

/**
 * Score an array of issues: returns counts per severity and a numeric score.
 * Score: critical=3, major=2, minor=1.
 *
 * @param array $issues  List of issue arrays (must have 'type' key).
 * @return array { critical: int, major: int, minor: int, score: int }
 */
function simple_a11y_scanner_score( array $issues ): array {
    $counts = [ 'critical' => 0, 'major' => 0, 'minor' => 0 ];
    foreach ( $issues as $issue ) {
        $sev = simple_a11y_scanner_severity( $issue['type'] ?? '' );
        $counts[ $sev ]++;
    }
    $counts['score'] = ( $counts['critical'] * 3 ) + ( $counts['major'] * 2 ) + $counts['minor'];
    return $counts;
}
