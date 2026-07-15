<?php
use PHPUnit\Framework\TestCase;
require_once __DIR__ . '/../includes/scanner.php';

class ScannerTest extends TestCase {
    public function testMissingAltAttribute() {
        $scanner = new \SimpleA11yScanner\Scanner();
        $content = '<img src="test.jpg">';
        $issues = $scanner->scanContent($content);
        $this->assertContains('Image missing alt attribute.', $issues);
    }
}
