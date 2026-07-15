<?php
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../includes/scanner.php';

class ScannerTest extends TestCase {

    private \SimpleA11yScanner\Scanner $scanner;

    protected function setUp(): void {
        $this->scanner = new \SimpleA11yScanner\Scanner();
    }

    // ── Missing alt ────────────────────────────────────────────────────────

    public function testMissingAltAttribute(): void {
        $issues = $this->scanner->scanContent( '<img src="test.jpg">' );
        $types  = array_column( $issues, 'type' );
        $this->assertContains( 'missing_alt', $types );
    }

    public function testImageWithAltHasNoIssue(): void {
        $issues = $this->scanner->scanContent( '<img src="test.jpg" alt="A test image">' );
        $types  = array_column( $issues, 'type' );
        $this->assertNotContains( 'missing_alt', $types );
    }

    public function testImageWithEmptyAltHasNoIssue(): void {
        // Empty alt="" is valid for decorative images
        $issues = $this->scanner->scanContent( '<img src="deco.jpg" alt="">' );
        $types  = array_column( $issues, 'type' );
        $this->assertNotContains( 'missing_alt', $types );
    }

    public function testMultipleImagesMissingAlt(): void {
        $html   = '<img src="a.jpg"><img src="b.jpg" alt="B"><img src="c.jpg">';
        $issues = $this->scanner->scanContent( $html );
        $missing = array_filter( $issues, fn( $i ) => $i['type'] === 'missing_alt' );
        $this->assertCount( 2, $missing );
    }

    // ── Empty link ─────────────────────────────────────────────────────────

    public function testEmptyLinkText(): void {
        $issues = $this->scanner->scanContent( '<a href="https://example.com"></a>' );
        $types  = array_column( $issues, 'type' );
        $this->assertContains( 'empty_link', $types );
    }

    public function testLinkWithOnlyWhitespaceIsEmpty(): void {
        $issues = $this->scanner->scanContent( '<a href="#">   </a>' );
        $types  = array_column( $issues, 'type' );
        $this->assertContains( 'empty_link', $types );
    }

    public function testLinkWithTextHasNoEmptyIssue(): void {
        $issues = $this->scanner->scanContent( '<a href="#">Visit our blog</a>' );
        $types  = array_column( $issues, 'type' );
        $this->assertNotContains( 'empty_link', $types );
    }

    // ── Vague link ─────────────────────────────────────────────────────────

    public function testVagueLinkClickHere(): void {
        $issues = $this->scanner->scanContent( '<a href="#">click here</a>' );
        $types  = array_column( $issues, 'type' );
        $this->assertContains( 'vague_link', $types );
    }

    public function testVagueLinkReadMore(): void {
        $issues = $this->scanner->scanContent( '<a href="#">read more</a>' );
        $types  = array_column( $issues, 'type' );
        $this->assertContains( 'vague_link', $types );
    }

    public function testVagueLinkCaseInsensitive(): void {
        $issues = $this->scanner->scanContent( '<a href="#">Click Here</a>' );
        $types  = array_column( $issues, 'type' );
        $this->assertContains( 'vague_link', $types );
    }

    public function testDescriptiveLinkHasNoVagueIssue(): void {
        $issues = $this->scanner->scanContent( '<a href="#">Download our accessibility guide</a>' );
        $types  = array_column( $issues, 'type' );
        $this->assertNotContains( 'vague_link', $types );
    }

    // ── No issues ──────────────────────────────────────────────────────────

    public function testCleanContentReturnsNoIssues(): void {
        $html   = '<img src="photo.jpg" alt="A nice photo"><a href="#">Read our accessibility report</a>';
        $issues = $this->scanner->scanContent( $html );
        $this->assertEmpty( $issues );
    }

    // ── Issue structure ────────────────────────────────────────────────────

    public function testIssueHasRequiredKeys(): void {
        $issues = $this->scanner->scanContent( '<img src="test.jpg">' );
        $this->assertNotEmpty( $issues );
        $this->assertArrayHasKey( 'type', $issues[0] );
        $this->assertArrayHasKey( 'message', $issues[0] );
        $this->assertArrayHasKey( 'element', $issues[0] );
    }
}
