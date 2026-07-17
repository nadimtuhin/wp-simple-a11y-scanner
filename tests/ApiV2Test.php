<?php
/**
 * Tests for API v2 endpoints (issues #16, #18, #20, #21, #24, #25).
 */
use PHPUnit\Framework\TestCase;

if ( ! defined( 'PHPUNIT_RUNNING' ) ) {
    define( 'PHPUNIT_RUNNING', true );
}

// ---- Minimal WP stubs ----
if ( ! class_exists( 'WP_REST_Request' ) ) {
    class WP_REST_Request {
        public array $params = [];
        private array $json  = [];
        public function __construct( string $method = '', string $route = '' ) {}
        public function get_param( string $key ) { return $this->params[ $key ] ?? null; }
        public function get_json_params(): array { return $this->json; }
        public function set_body( string $data ) { $this->json = json_decode( $data, true ); }
        public function set_json( array $data ): void { $this->json = $data; }
    }
}

if ( ! class_exists( 'WP_REST_Response' ) ) {
    class WP_REST_Response {
        public $data;
        public int $status;
        private array $headers = [];
        public function __construct( $data, int $status ) {
            $this->data   = $data;
            $this->status = $status;
        }
        public function header( string $key, $value ): void { $this->headers[ $key ] = $value; }
        public function get_header( string $key ) { return $this->headers[ $key ] ?? null; }
    }
}

if ( ! class_exists( 'WP_Error' ) ) {
    class WP_Error {
        public string $code;
        public string $message;
        public int $status;
        public function __construct( string $code, string $message, $data = [] ) {
            $this->code    = $code;
            $this->message = $message;
            $this->status  = is_array( $data ) ? ( $data['status'] ?? 400 ) : 400;
        }
        public function get_error_message(): string { return $this->message; }
    }
}

if ( ! function_exists( 'is_wp_error' ) ) {
    function is_wp_error( $v ): bool { return $v instanceof WP_Error; }
}
if ( ! function_exists( 'do_action' ) ) {
    function do_action( $hook, ...$args ) {}
}
if ( ! function_exists( 'apply_filters' ) ) {
    function apply_filters( $tag, $value ) { return $value; }
}
if ( ! function_exists( 'current_user_can' ) ) {
    function current_user_can( $cap ): bool { return true; }
}
if ( ! function_exists( 'simple_a11y_scanner_get_options' ) ) {
    function simple_a11y_scanner_get_options(): array { return []; }
}
// simple_a11y_scanner_check_rate_limit skipped (defined in includes/rate-limit.php)
// if ( ! function_exists( 'simple_a11y_scanner_check_rate_limit' ) ) {
//    function simple_a11y_scanner_check_rate_limit( int $limit = 30, int $window = 60 ) { return true; }
// }
if ( ! function_exists( 'get_post_meta' ) ) {
    function get_post_meta( $id, $key, $single = false ) { return $single ? '' : []; }
}
if ( ! function_exists( 'get_the_title' ) ) {
    function get_the_title( $id ): string { return 'Test Post'; }
}
if ( ! function_exists( 'get_option' ) ) {
    function get_option( $key, $default = false ) {
        return $GLOBALS['_test_options'][ $key ] ?? $default;
    }
}
if ( ! function_exists( 'update_option' ) ) {
    function update_option( $key, $value ): bool {
        $GLOBALS['_test_options'][ $key ] = $value;
        return true;
    }
}
if ( ! function_exists( 'get_current_user_id' ) ) {
    function get_current_user_id(): int { return 1; }
}
if ( ! function_exists( 'current_time' ) ) {
    function current_time( $type ): string { return date( 'Y-m-d H:i:s' ); }
}
if ( ! function_exists( 'sanitize_text_field' ) ) {
    function sanitize_text_field( $s ) { return trim( strip_tags( $s ) ); }
}
if ( ! function_exists( 'sanitize_key' ) ) {
    function sanitize_key( $s ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( $s ) ); }
}

require_once __DIR__ . '/../includes/scanner.php';
require_once __DIR__ . '/../includes/severity.php';
require_once __DIR__ . '/../includes/api.php';

class ApiV2Test extends TestCase {

    private \SimpleA11yScanner\Api $api;

    protected function setUp(): void {
        $GLOBALS['_test_options'] = [];
        $this->api = new \SimpleA11yScanner\Api();
    }

    // ---- v2 scan ----

    public function testHandleScanV2ReturnsIssuesWithSeverity(): void {
        $request = new WP_REST_Request();
        $request->params['content'] = '<img src="x.jpg"><a href="#"></a>';
        $response = $this->api->handleScanV2( $request );
        $this->assertEquals( 200, $response->status );
        $this->assertEquals( 'v2', $response->data['api_version'] );
        foreach ( $response->data['issues'] as $issue ) {
            $this->assertArrayHasKey( 'severity', $issue );
        }
    }

    public function testHandleScanV2HasScoreData(): void {
        $request = new WP_REST_Request();
        $request->params['content'] = '<img src="x.jpg">';
        $response = $this->api->handleScanV2( $request );
        $this->assertArrayHasKey( 'score', $response->data );
        $score = $response->data['score'];
        $this->assertArrayHasKey( 'critical', $score );
        $this->assertArrayHasKey( 'major', $score );
        $this->assertArrayHasKey( 'minor', $score );
        $this->assertArrayHasKey( 'score', $score );
        $this->assertGreaterThan( 0, $score['score'] );
    }

    public function testHandleScanV2EmptyContentReturns400(): void {
        $request = new WP_REST_Request();
        $request->params['content'] = '';
        $response = $this->api->handleScanV2( $request );
        $this->assertEquals( 400, $response->status );
    }

    public function testHandleScanV2CleanContentScoreIsZero(): void {
        $request = new WP_REST_Request();
        $request->params['content'] = '<p>Clean paragraph.</p>';
        $response = $this->api->handleScanV2( $request );
        $this->assertEquals( 200, $response->status );
        $this->assertEquals( 0, $response->data['score']['score'] );
    }

    // ---- audit log endpoint ----

    public function testHandleAuditLogWithoutClassReturns503(): void {
        // AuditLog class not loaded in this bootstrap — expect 503.
        $request = new WP_REST_Request();
        $request->params['per_page'] = 50;
        $request->params['page']     = 1;
        $response = $this->api->handleAuditLog( $request );
        $this->assertEquals( 503, $response->status );
    }

    // ---- social meta scan ----

    public function testHandleSocialMetaScanMissingEverythingFindsIssues(): void {
        // get_post_meta returns '' by default stub — all fields empty.
        $request = new WP_REST_Request();
        $request->params['post_id'] = 42;
        $response = $this->api->handleSocialMetaScan( $request );
        $this->assertEquals( 200, $response->status );
        $types = array_column( $response->data['issues'], 'type' );
        $this->assertContains( 'social_meta', $types );
        // Missing og:description and og:image at minimum.
        $fields = array_column( $response->data['issues'], 'field' );
        $this->assertContains( 'og:description', $fields );
        $this->assertContains( 'og:image', $fields );
    }

    public function testHandleSocialMetaScanResponseHasMeta(): void {
        $request = new WP_REST_Request();
        $request->params['post_id'] = 1;
        $response = $this->api->handleSocialMetaScan( $request );
        $this->assertArrayHasKey( 'meta', $response->data );
        $this->assertArrayHasKey( 'og_title', $response->data['meta'] );
    }

    // ---- rule builder REST ----

    public function testHandleListRulesReturnsEmpty(): void {
        $request  = new WP_REST_Request();
        $response = $this->api->handleListRules( $request );
        $this->assertEquals( 200, $response->status );
        $this->assertIsArray( $response->data['rules'] );
    }

    public function testHandleSaveRulesThenList(): void {
        $request = new WP_REST_Request();
        $request->set_json( [
            'rules' => [
                [
                    'id'       => 'no-blink',
                    'label'    => 'No blink element',
                    'pattern'  => '/<blink/i',
                    'message'  => 'Blink element is inaccessible.',
                    'severity' => 'major',
                    'enabled'  => true,
                ],
            ],
        ] );
        $save = $this->api->handleSaveRules( $request );
        $this->assertEquals( 200, $save->status );
        $this->assertEquals( 1, $save->data['saved'] );

        // Now list them.
        $list = $this->api->handleListRules( new WP_REST_Request() );
        $this->assertCount( 1, $list->data['rules'] );
        $this->assertEquals( 'no-blink', $list->data['rules'][0]['id'] );
        $this->assertEquals( 'major', $list->data['rules'][0]['severity'] );
    }

    public function testHandleSaveRulesSkipsInvalidEntries(): void {
        $request = new WP_REST_Request();
        $request->set_json( [
            'rules' => [
                [ 'label' => 'No ID', 'pattern' => '/x/i' ],       // missing id
                [ 'id' => 'ok-rule', 'pattern' => '/y/i' ],         // valid
                [ 'id' => 'no-pattern', 'label' => 'Test' ],        // missing pattern
            ],
        ] );
        $response = $this->api->handleSaveRules( $request );
        $this->assertEquals( 1, $response->data['saved'] );
    }
}
