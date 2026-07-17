<?php
/**
 * Smoke test for all new features.
 * Run: php tests/smoke_new_features.php
 */

// ---- minimal stubs ----
define( 'PHPUNIT_RUNNING', true );
function apply_filters( $tag, $value ) { return $value; }
function do_action( $hook, ...$args ) {}
function get_option( $key, $default = false ) { return $GLOBALS['_test_options'][ $key ] ?? $default; }
function update_option( $key, $value ): bool { $GLOBALS['_test_options'][ $key ] = $value; return true; }
function get_transient( $key ) { return $GLOBALS['_test_transients'][ $key ] ?? false; }
function set_transient( $key, $value, $ttl = 0 ) { $GLOBALS['_test_transients'][ $key ] = $value; return true; }
function sanitize_text_field( $s ) { return trim( strip_tags( $s ) ); }
function sanitize_key( $s ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( $s ) ); }
function wp_unslash( $v ) { return is_string( $v ) ? stripslashes( $v ) : $v; }
function current_user_can( $cap ): bool { return true; }
function is_wp_error( $v ): bool { return $v instanceof WP_Error; }
function get_post_meta( $id, $key, $single = false ) { return $single ? '' : []; }
function get_the_title( $id ): string { return 'Test Post'; }
function get_current_user_id(): int { return 1; }
function current_time( $t ): string { return date('Y-m-d H:i:s'); }
function esc_html( $v ) { return htmlspecialchars( $v, ENT_QUOTES ); }

class WP_Error {
    public string $code;
    public string $message;
    public function __construct( string $code, string $message, $data = [] ) {
        $this->code = $code; $this->message = $message;
    }
    public function get_error_message(): string { return $this->message; }
}

class WP_REST_Request {
    public array $params = [];
    private array $json  = [];
    public function get_param( string $key ) { return $this->params[ $key ] ?? null; }
    public function get_json_params(): array { return $this->json; }
    public function set_json( array $data ): void { $this->json = $data; }
}
class WP_REST_Response {
    public $data; public int $status;
    private array $headers = [];
    public function __construct( $data, int $status ) { $this->data = $data; $this->status = $status; }
    public function header( string $k, $v ): void { $this->headers[$k] = $v; }
}

function simple_a11y_scanner_get_options(): array { return []; }
// Note: NOT stubbing simple_a11y_scanner_check_rate_limit — we test the real one below.

$GLOBALS['_test_options']   = [];
$GLOBALS['_test_transients'] = [];

require_once __DIR__ . '/../includes/scanner.php';
require_once __DIR__ . '/../includes/severity.php';
require_once __DIR__ . '/../includes/rate-limit.php';
require_once __DIR__ . '/../includes/api.php';

$pass = 0;
$fail = 0;

function ok( string $label, bool $cond ): void {
    global $pass, $fail;
    if ( $cond ) { echo "[PASS] $label\n"; $pass++; }
    else          { echo "[FAIL] $label\n"; $fail++; }
}

// ---- severity scoring ----
ok( 'missing_alt is critical',    simple_a11y_scanner_severity('missing_alt')  === 'critical' );
ok( 'empty_link is critical',     simple_a11y_scanner_severity('empty_link')   === 'critical' );
ok( 'low_contrast is major',      simple_a11y_scanner_severity('low_contrast') === 'major'    );
ok( 'keyboard_nav is major',      simple_a11y_scanner_severity('keyboard_nav') === 'major'    );
ok( 'target_size is major',       simple_a11y_scanner_severity('target_size')  === 'major'    );
ok( 'vague_link is minor',        simple_a11y_scanner_severity('vague_link')   === 'minor'    );
ok( 'unknown is minor',           simple_a11y_scanner_severity('unknown')      === 'minor'    );

$score = simple_a11y_scanner_score([
    ['type'=>'missing_alt'],  // 3
    ['type'=>'empty_link'],   // 3
    ['type'=>'low_contrast'], // 2
    ['type'=>'vague_link'],   // 1
]);
ok( 'score = 9',              $score['score'] === 9 );
ok( 'critical count = 2',     $score['critical'] === 2 );
ok( 'major count = 1',        $score['major'] === 1 );
ok( 'minor count = 1',        $score['minor'] === 1 );

// ---- rate limit ----
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
$ip = simple_a11y_scanner_client_ip();
ok( 'client_ip returns REMOTE_ADDR', $ip === '127.0.0.1' );

$_SERVER['HTTP_X_FORWARDED_FOR'] = '203.0.113.5, 10.0.0.1';
$ip = simple_a11y_scanner_client_ip();
ok( 'client_ip uses XFF first IP', $ip === '203.0.113.5' );
unset( $_SERVER['HTTP_X_FORWARDED_FOR'] );

// Override check_rate_limit for real test.
function real_rate_limit_check() {
    // Use the real function, but reset transients.
    $GLOBALS['_test_transients'] = [];
    $_SERVER['REMOTE_ADDR'] = '1.2.3.4';
    $r1 = \simple_a11y_scanner_check_rate_limit( 2, 60 );
    $r2 = \simple_a11y_scanner_check_rate_limit( 2, 60 );
    $r3 = \simple_a11y_scanner_check_rate_limit( 2, 60 ); // should be throttled
    return $r3;
}
$throttled = real_rate_limit_check();
ok( 'rate limit returns WP_Error when exceeded', $throttled instanceof WP_Error );

// ---- API v2 ----
$api = new \SimpleA11yScanner\Api();

$req = new WP_REST_Request();
$req->params['content'] = '<img src="x.jpg"><a href="#"></a>';
$res = $api->handleScanV2( $req );
ok( 'v2 scan returns 200',            $res->status === 200 );
ok( 'v2 scan has api_version=v2',     $res->data['api_version'] === 'v2' );
ok( 'v2 scan has score data',         isset( $res->data['score'] ) );
ok( 'v2 issues have severity key',    isset( $res->data['issues'][0]['severity'] ) );
ok( 'v2 missing_alt is critical',     $res->data['issues'][0]['severity'] === 'critical' );
ok( 'v2 score > 0',                   $res->data['score']['score'] > 0 );

$req2 = new WP_REST_Request();
$req2->params['content'] = '';
$res2 = $api->handleScanV2( $req2 );
ok( 'v2 scan empty content = 400',    $res2->status === 400 );

// ---- audit log endpoint ----
$req3 = new WP_REST_Request();
$req3->params['per_page'] = 50;
$req3->params['page'] = 1;
$res3 = $api->handleAuditLog( $req3 );
ok( 'audit log without table = 503',  $res3->status === 503 );

// ---- social meta scan ----
$req4 = new WP_REST_Request();
$req4->params['post_id'] = 42;
$res4 = $api->handleSocialMetaScan( $req4 );
ok( 'social scan returns 200',        $res4->status === 200 );
ok( 'social scan has issues array',   is_array( $res4->data['issues'] ) );
ok( 'social scan finds og:image',     in_array( 'og:image', array_column( $res4->data['issues'], 'field' ) ) );
ok( 'social scan has meta field',     isset( $res4->data['meta'] ) );

// ---- rule builder ----
$req5 = new WP_REST_Request();
$res5 = $api->handleListRules( $req5 );
ok( 'list rules returns 200',         $res5->status === 200 );
ok( 'list rules empty initially',     count( $res5->data['rules'] ) === 0 );

$req6 = new WP_REST_Request();
$req6->set_json([
    'rules' => [
        ['id'=>'no-blink','pattern'=>'/<blink/i','label'=>'No blink','message'=>'Blink found','severity'=>'major','enabled'=>true],
        ['label'=>'Missing id'],    // should be skipped
    ]
]);
$res6 = $api->handleSaveRules( $req6 );
ok( 'save rules returns 200',         $res6->status === 200 );
ok( 'save rules saved=1',             $res6->data['saved'] === 1 );
ok( 'saved rule has correct id',      $res6->data['rules'][0]['id'] === 'no-blink' );
ok( 'saved rule has major severity',  $res6->data['rules'][0]['severity'] === 'major' );

// List again
$res7 = $api->handleListRules( new WP_REST_Request() );
ok( 'list rules after save = 1',      count( $res7->data['rules'] ) === 1 );

// ---- PDF template build (no dompdf, just HTML generation) ----
require_once __DIR__ . '/../includes/pdf-export.php';
$templates = simple_a11y_scanner_pdf_templates();
ok( 'default template exists',        isset( $templates['default'] ) );
ok( 'dark template exists',           isset( $templates['dark'] ) );
ok( 'minimal template exists',        isset( $templates['minimal'] ) );

$html = simple_a11y_scanner_build_pdf_html(
    [ ['type'=>'missing_alt','message'=>'Missing alt','element'=>'<img>'] ],
    'Test Report',
    'dark'
);
ok( 'dark template HTML has dark bg', str_contains( $html, '#1e1e1e' ) );
ok( 'PDF HTML has severity column',   str_contains( $html, 'Severity' ) );
ok( 'PDF HTML has CRITICAL',          str_contains( strtoupper( $html ), 'CRITICAL' ) );

echo "\n----\nPASS: $pass  FAIL: $fail\n";
exit( $fail > 0 ? 1 : 0 );
