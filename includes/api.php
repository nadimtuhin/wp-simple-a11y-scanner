<?php
namespace SimpleA11yScanner;

class Api {
    public function registerRoutes() {
        register_rest_route('simple-a11y/v1', '/scan', [
            'methods' => 'POST',
            'callback' => [$this, 'handleScan'],
            'permission_callback' => '__return_true'
        ]);
    }

    public function handleScan($request) {
        $content = $request->get_param('content');
        if (empty($content)) {
            return new \WP_REST_Response(['error' => 'Empty content'], 400);
        }
        $scanner = new Scanner();
        $issues = $scanner->scanContent($content);
        return new \WP_REST_Response(['issues' => $issues], 200);
    }
}
