<?php
use PHPUnit\Framework\TestCase;
require_once __DIR__ . '/../includes/scanner.php';
require_once __DIR__ . '/../includes/api.php';

// Mock WP_REST_Request if needed
if (!class_exists('WP_REST_Request')) {
    class WP_REST_Request {
        public $params = [];
        public function __construct($method = '', $route = '') { }
        public function get_param($key) { return $this->params[$key] ?? null; }
    }
}

if (!class_exists('WP_REST_Response')) {
    class WP_REST_Response {
        public $data;
        public $status;
        public function __construct($data, $status) {
            $this->data = $data;
            $this->status = $status;
        }
    }
}

class ApiTest extends TestCase {
    public function testHandleScanReturnsIssues() {
        $api = new \SimpleA11yScanner\Api();
        $request = new \WP_REST_Request();
        $request->params['content'] = '<img src="test.jpg">';

        $response = $api->handleScan($request);
        $this->assertEquals(200, $response->status);
        $this->assertContains('Image missing alt attribute.', $response->data['issues']);
    }
}
