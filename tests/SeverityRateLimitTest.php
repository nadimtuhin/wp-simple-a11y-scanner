<?php
/**
 * Tests for severity scoring (issue #18) and rate-limiting (issue #15).
 */
use PHPUnit\Framework\TestCase;

if ( ! defined( 'PHPUNIT_RUNNING' ) ) {
    define( 'PHPUNIT_RUNNING', true );
}

// Stub WP functions needed by severity/rate-limit.
if ( ! function_exists( 'apply_filters' ) ) {
    function apply_filters( $tag, $value ) { return $value; }
}
if ( ! function_exists( 'do_action' ) ) {
    function do_action( $hook, ...$args ) {}
}

// ---------- Transient store for rate-limit tests ----------
if ( ! class_exists( 'WP_Error' ) ) {
    class WP_Error {
        public string $code;
        public string $message;
        public function __construct( string $code, string $message = '' ) {
            $this->code = $code;
            $this->message = $message;
        }
    }
}
$GLOBALS['_test_transients'] = [];

if ( ! function_exists( 'get_transient' ) ) {
    function get_transient( $key ) {
        return $GLOBALS['_test_transients'][ $key ] ?? false;
    }
}
if ( ! function_exists( 'set_transient' ) ) {
    function set_transient( $key, $value, $ttl = 0 ) {
        $GLOBALS['_test_transients'][ $key ] = $value;
        return true;
    }
}
if ( ! function_exists( 'sanitize_text_field' ) ) {
    function sanitize_text_field( $s ) { return trim( strip_tags( $s ) ); }
}
if ( ! function_exists( 'wp_unslash' ) ) {
    function wp_unslash( $v ) { return is_string( $v ) ? stripslashes( $v ) : $v; }
}

require_once __DIR__ . '/../includes/severity.php';
require_once __DIR__ . '/../includes/rate-limit.php';

class SeverityTest extends TestCase {
    protected function setUp(): void {
        $GLOBALS['_test_transients'] = [];
    }

    public function testMissingAltIsCritical(): void {
        $this->assertEquals( 'critical', simple_a11y_scanner_severity( 'missing_alt' ) );
    }

    public function testEmptyLinkIsCritical(): void {
        $this->assertEquals( 'critical', simple_a11y_scanner_severity( 'empty_link' ) );
    }

    public function testLowContrastIsMajor(): void {
        $this->assertEquals( 'major', simple_a11y_scanner_severity( 'low_contrast' ) );
    }

    public function testKeyboardNavIsMajor(): void {
        $this->assertEquals( 'major', simple_a11y_scanner_severity( 'keyboard_nav' ) );
    }

    public function testVagueLinkIsMinor(): void {
        $this->assertEquals( 'minor', simple_a11y_scanner_severity( 'vague_link' ) );
    }

    public function testUnknownTypeIsMinor(): void {
        $this->assertEquals( 'minor', simple_a11y_scanner_severity( 'unknown_type' ) );
    }

    public function testScoreCalculation(): void {
        $issues = [
            [ 'type' => 'missing_alt' ],  // critical = 3
            [ 'type' => 'empty_link' ],   // critical = 3
            [ 'type' => 'low_contrast' ], // major = 2
            [ 'type' => 'vague_link' ],   // minor = 1
        ];
        $result = simple_a11y_scanner_score( $issues );
        $this->assertEquals( 2, $result['critical'] );
        $this->assertEquals( 1, $result['major'] );
        $this->assertEquals( 1, $result['minor'] );
        $this->assertEquals( 9, $result['score'] ); // 2*3 + 1*2 + 1*1
    }

    public function testScoreZeroForEmptyIssues(): void {
        $result = simple_a11y_scanner_score( [] );
        $this->assertEquals( 0, $result['score'] );
        $this->assertEquals( 0, $result['critical'] );
    }
}

class RateLimitTest extends TestCase {

    protected function setUp(): void {
        // Reset transients between tests.
        $GLOBALS['_test_transients'] = [];
    }

    public function testFirstRequestAllowed(): void {
        // Override $_SERVER for test.
        $_SERVER['REMOTE_ADDR'] = '192.168.1.1';
        $result = simple_a11y_scanner_check_rate_limit( 5, 60 );
        $this->assertTrue( $result );
    }

    public function testExceedingLimitReturnsWpError(): void {
        $_SERVER['REMOTE_ADDR'] = '10.0.0.1';
        // Exhaust the limit.
        for ( $i = 0; $i < 3; $i++ ) {
            $r = simple_a11y_scanner_check_rate_limit( 2, 60 );
        }
        $result = simple_a11y_scanner_check_rate_limit( 2, 60 );
        $this->assertInstanceOf( 'WP_Error', $result );
    }

    public function testClientIpFallsBackToRemoteAddr(): void {
        unset( $_SERVER['HTTP_X_FORWARDED_FOR'] );
        $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
        $ip = simple_a11y_scanner_client_ip();
        $this->assertEquals( '127.0.0.1', $ip );
    }

    public function testClientIpUsesForwardedFor(): void {
        $_SERVER['HTTP_X_FORWARDED_FOR'] = '203.0.113.5, 10.0.0.1';
        $ip = simple_a11y_scanner_client_ip();
        $this->assertEquals( '203.0.113.5', $ip );
        unset( $_SERVER['HTTP_X_FORWARDED_FOR'] );
    }
}

// WP_Error class already defined above.
