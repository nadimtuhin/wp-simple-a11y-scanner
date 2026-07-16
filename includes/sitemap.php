<?php
/**
 * Sitemap XML ingestion for Simple A11y Scanner.
 *
 * Fetches a sitemap (or sitemap index), extracts URLs, and returns them.
 *
 * @package SimpleA11yScanner
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Fetch and parse a sitemap URL.
 * Handles sitemap indexes by recursively fetching child sitemaps.
 *
 * @param string $sitemap_url  Absolute URL to the sitemap XML.
 * @param int    $depth        Recursion limit (prevents infinite loops on malformed indexes).
 * @return string[]|WP_Error  Array of page URLs, or WP_Error on fetch failure.
 */
function simple_a11y_scanner_fetch_sitemap( $sitemap_url, $depth = 0 ) {
    if ( $depth > 3 ) {
        return []; // guard against deeply nested sitemap indexes
    }

    $response = wp_remote_get( esc_url_raw( $sitemap_url ), [
        'timeout'    => 15,
        'user-agent' => 'SimpleA11yScanner/1.0',
    ] );

    if ( is_wp_error( $response ) ) {
        return $response;
    }

    $code = wp_remote_retrieve_response_code( $response );
    if ( 200 !== (int) $code ) {
        return new \WP_Error(
            'sitemap_fetch_error',
            sprintf( 'Sitemap fetch returned HTTP %d for %s', $code, $sitemap_url )
        );
    }

    $body = wp_remote_retrieve_body( $response );

    // Suppress XML parse warnings — malformed sitemaps shouldn't fatal the process.
    $prev = libxml_use_internal_errors( true );
    $xml  = simplexml_load_string( $body );
    libxml_use_internal_errors( $prev );

    if ( false === $xml ) {
        return new \WP_Error( 'sitemap_parse_error', 'Could not parse sitemap XML from ' . $sitemap_url );
    }

    $urls = [];

    // Sitemap index: contains <sitemap><loc>…</loc></sitemap> entries.
    if ( isset( $xml->sitemap ) ) {
        foreach ( $xml->sitemap as $child ) {
            $child_url    = isset( $child->loc ) ? (string) $child->loc : '';
            $child_result = simple_a11y_scanner_fetch_sitemap( $child_url, $depth + 1 );
            if ( ! is_wp_error( $child_result ) ) {
                $urls = array_merge( $urls, $child_result );
            }
        }
        return $urls;
    }

    // Regular sitemap: contains <url><loc>…</loc></url> entries.
    if ( isset( $xml->url ) ) {
        foreach ( $xml->url as $entry ) {
            $loc = isset( $entry->loc ) ? trim( (string) $entry->loc ) : '';
            if ( '' !== $loc ) {
                $urls[] = $loc;
            }
        }
    }

    return $urls;
}

/**
 * Auto-discover the sitemap URL for a given site URL.
 * Tries /sitemap.xml, /sitemap_index.xml, and robots.txt Sitemap directive.
 *
 * @param string $site_url  Base site URL.
 * @return string|null  Discovered sitemap URL, or null if none found.
 */
function simple_a11y_scanner_discover_sitemap( $site_url ) {
    $site_url = untrailingslashit( esc_url_raw( $site_url ) );

    $candidates = [
        $site_url . '/sitemap.xml',
        $site_url . '/sitemap_index.xml',
        $site_url . '/wp-sitemap.xml',
    ];

    foreach ( $candidates as $candidate ) {
        $r = wp_remote_head( $candidate, [ 'timeout' => 8, 'user-agent' => 'SimpleA11yScanner/1.0' ] );
        if ( ! is_wp_error( $r ) && 200 === (int) wp_remote_retrieve_response_code( $r ) ) {
            return $candidate;
        }
    }

    // Fallback: parse robots.txt for a Sitemap directive.
    $robots = wp_remote_get( $site_url . '/robots.txt', [ 'timeout' => 8, 'user-agent' => 'SimpleA11yScanner/1.0' ] );
    if ( ! is_wp_error( $robots ) ) {
        $body = wp_remote_retrieve_body( $robots );
        if ( preg_match( '/^Sitemap:\s*(\S+)/im', $body, $m ) ) {
            return $m[1];
        }
    }

    return null;
}
