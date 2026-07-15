<?php
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../includes/scanner.php';
require_once __DIR__ . '/../includes/api.php';

if ( ! function_exists( 'do_action' ) ) {
    function do_action( $hook, ...$args ) {} // no-op stub for tests
}

if ( ! function_exists( 'simple_a11y_scanner_get_options' ) ) {
    function simple_a11y_scanner_get_options() { return []; } // no-op stub
}

if ( ! class_exists( 'WP_REST_Request' ) ) {
    class WP_REST_Request {
        public array $params = [];
        public function __construct( string $method = '', string $route = '' ) {}
        public function get_param( string $key ) {
            return $this->params[ $key ] ?? null;
        }
    }
}

if ( ! class_exists( 'WP_REST_Response' ) ) {
    class WP_REST_Response {
        public $data;
        public int $status;
        public function __construct( $data, int $status ) {
            $this->data   = $data;
            $this->status = $status;
        }
    }
}

class ApiTest extends TestCase {

    private \SimpleA11yScanner\Api $api;

    protected function setUp(): void {
        $this->api = new \SimpleA11yScanner\Api();
    }

    // ── handleScan ─────────────────────────────────────────────────────────

    public function testHandleScanReturnsIssues(): void {
        $request                   = new WP_REST_Request();
        $request->params['content'] = '<img src="test.jpg">';
        $response                  = $this->api->handleScan( $request );
        $this->assertEquals( 200, $response->status );
        $types = array_column( $response->data['issues'], 'type' );
        $this->assertContains( 'missing_alt', $types );
    }

    public function testHandleScanEmptyContentReturns400(): void {
        $request                   = new WP_REST_Request();
        $request->params['content'] = '';
        $response                  = $this->api->handleScan( $request );
        $this->assertEquals( 400, $response->status );
        $this->assertArrayHasKey( 'error', $response->data );
    }

    public function testHandleScanNullContentReturns400(): void {
        $request  = new WP_REST_Request();
        $response = $this->api->handleScan( $request );
        $this->assertEquals( 400, $response->status );
    }

    public function testHandleScanResponseHasCount(): void {
        $request                   = new WP_REST_Request();
        $request->params['content'] = '<img src="a.jpg"><a href="#"></a>';
        $response                  = $this->api->handleScan( $request );
        $this->assertEquals( 200, $response->status );
        $this->assertArrayHasKey( 'count', $response->data );
        $this->assertEquals( 2, $response->data['count'] );
    }

    public function testHandleScanCleanContentReturnsEmptyIssues(): void {
        $request                   = new WP_REST_Request();
        $request->params['content'] = '<p>Clean paragraph with no issues.</p>';
        $response                  = $this->api->handleScan( $request );
        $this->assertEquals( 200, $response->status );
        $this->assertEmpty( $response->data['issues'] );
        $this->assertEquals( 0, $response->data['count'] );
    }

    // ── handleScanSummary ──────────────────────────────────────────────────

    public function testHandleScanSummaryReturnsGroupedCounts(): void {
        $html = '<img src="a.jpg"><img src="b.jpg"><a href="#"></a><a href="#">click here</a>';

        $request                   = new WP_REST_Request();
        $request->params['content'] = $html;
        $response                  = $this->api->handleScanSummary( $request );

        $this->assertEquals( 200, $response->status );
        $summary = $response->data['summary'];
        $this->assertEquals( 2, $summary['missing_alt'] );
        $this->assertEquals( 1, $summary['empty_link'] );
        $this->assertEquals( 1, $summary['vague_link'] );
        $this->assertEquals( 4, $summary['total'] );
    }

    public function testHandleScanSummaryEmptyContentReturns400(): void {
        $request                   = new WP_REST_Request();
        $request->params['content'] = '';
        $response                  = $this->api->handleScanSummary( $request );
        $this->assertEquals( 400, $response->status );
    }
}
