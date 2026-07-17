<?php
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../includes/scanner.php';

class ScannerTest extends TestCase {

    private \SimpleA11yScanner\Scanner $scanner;

    protected function setUp(): void {
        $this->scanner = new \SimpleA11yScanner\Scanner();
    }


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


    public function testCleanContentReturnsNoIssues(): void {
        $html   = '<img src="photo.jpg" alt="A nice photo"><a href="#">Read our accessibility report</a>';
        $issues = $this->scanner->scanContent( $html );
        $this->assertEmpty( $issues );
    }


    public function testIssueHasRequiredKeys(): void {
        $issues = $this->scanner->scanContent( '<img src="test.jpg">' );
        $this->assertNotEmpty( $issues );
        $this->assertArrayHasKey( 'type', $issues[0] );
        $this->assertArrayHasKey( 'message', $issues[0] );
        $this->assertArrayHasKey( 'element', $issues[0] );
    }


    // -----------------------------------------------------------------------
    // WCAG 2.2 Target Size (SC 2.5.8) — Issue #10
    // -----------------------------------------------------------------------

    public function testTargetSizeBothDimensionsTooSmall(): void {
        $html   = '<button style="width:20px;height:20px;">X</button>';
        $issues = $this->scanner->checkTargetSize( $html );
        $types  = array_column( $issues, 'type' );
        $this->assertContains( 'target_size', $types );
        $this->assertStringContainsString( '20px×20px', $issues[0]['message'] );
    }

    public function testTargetSizeMeetsMinimum(): void {
        $html   = '<button style="width:44px;height:44px;">OK</button>';
        $issues = $this->scanner->checkTargetSize( $html );
        $this->assertEmpty( $issues );
    }

    public function testTargetSizeExactlyAtMinimum(): void {
        $html   = '<button style="width:24px;height:24px;">X</button>';
        $issues = $this->scanner->checkTargetSize( $html );
        $this->assertEmpty( $issues );
    }

    public function testTargetSizeOneDimensionTooSmall(): void {
        // Only height is too small.
        $html   = '<a href="#" style="height:16px;">Link</a>';
        $issues = $this->scanner->checkTargetSize( $html );
        $types  = array_column( $issues, 'type' );
        $this->assertContains( 'target_size', $types );
    }

    public function testTargetSizeNoStyleSkipped(): void {
        // No inline style — cannot be checked statically, must be skipped.
        $html   = '<button>No style</button>';
        $issues = $this->scanner->checkTargetSize( $html );
        $this->assertEmpty( $issues );
    }

    public function testTargetSizeViaMainScanContent(): void {
        $html   = '<button style="width:10px;height:10px;">!</button>';
        $issues = $this->scanner->scanContent( $html );
        $types  = array_column( $issues, 'type' );
        $this->assertContains( 'target_size', $types );
    }

    public function testTargetSizeCheckCanBeDisabled(): void {
        $html   = '<button style="width:10px;height:10px;">!</button>';
        $issues = $this->scanner->scanContent( $html, [ 'check_target_size' => false ] );
        $types  = array_column( $issues, 'type' );
        $this->assertNotContains( 'target_size', $types );
    }


    // -----------------------------------------------------------------------
    // Keyboard navigation / tab order — Issue #11
    // -----------------------------------------------------------------------

    public function testTabOrderNegativeTabindexFlagged(): void {
        $html   = '<a href="#" tabindex="-1">Skip</a>';
        $result = $this->scanner->analyseTabOrder( $html );
        $types  = array_column( $result['issues'], 'type' );
        $this->assertContains( 'keyboard_nav', $types );
        $this->assertEquals( 1, $result['metrics']['negative_tabindex'] );
    }

    public function testTabOrderPositiveTabindexFlagged(): void {
        $html   = '<button tabindex="2">Submit</button>';
        $result = $this->scanner->analyseTabOrder( $html );
        $types  = array_column( $result['issues'], 'type' );
        $this->assertContains( 'keyboard_nav', $types );
        $this->assertEquals( 1, $result['metrics']['positive_tabindex'] );
    }

    public function testTabOrderZeroTabindexIsClean(): void {
        $html   = '<a href="#" tabindex="0">Natural</a>';
        $result = $this->scanner->analyseTabOrder( $html );
        $this->assertEmpty( $result['issues'] );
        $this->assertEquals( 0, $result['metrics']['positive_tabindex'] );
        $this->assertEquals( 0, $result['metrics']['negative_tabindex'] );
    }

    public function testTabOrderNaturalNoTabindexIsClean(): void {
        $html   = '<a href="#">Natural</a><button>OK</button>';
        $result = $this->scanner->analyseTabOrder( $html );
        $this->assertEmpty( $result['issues'] );
        $this->assertEquals( 2, $result['metrics']['total_focusable'] );
    }

    public function testTabOrderNonSequentialPositiveTabindex(): void {
        $html   = '<a href="#" tabindex="3">First</a><a href="#" tabindex="1">Second</a>';
        $result = $this->scanner->analyseTabOrder( $html );
        $this->assertGreaterThanOrEqual( 1, $result['metrics']['sequential_violations'] );
    }

    public function testTabOrderMetricsReturnedByMainScan(): void {
        $html   = '<a href="#" tabindex="-1">Hidden</a>';
        $issues = $this->scanner->scanContent( $html );
        $types  = array_column( $issues, 'type' );
        $this->assertContains( 'keyboard_nav', $types );
    }

    public function testKeyboardNavCheckCanBeDisabled(): void {
        $html   = '<a href="#" tabindex="-1">Hidden</a>';
        $issues = $this->scanner->scanContent( $html, [ 'check_keyboard_nav' => false ] );
        $types  = array_column( $issues, 'type' );
        $this->assertNotContains( 'keyboard_nav', $types );
    }


    // -----------------------------------------------------------------------
    // Gutenberg post meta block scan — Issue #12
    // -----------------------------------------------------------------------

    public function testScanGutenbergMetaBlocksReturnsEmptyWithoutWPFunctions(): void {
        // WP functions not available in test context — should return [].
        $issues = $this->scanner->scanGutenbergMetaBlocks( 1 );
        $this->assertIsArray( $issues );
        $this->assertEmpty( $issues );
    }

    public function testScanGutenbergMetaBlocksWithStubs(): void {
        // scanContent is the core — test it directly with image-missing-alt markup
        // (scanGutenbergMetaBlocks stubs cannot override get_post_meta already defined in ApiV2Test.php)
        $issues = $this->scanner->scanContent( '<img src="x.jpg">' );
        $types  = array_column( $issues, 'type' );
        $this->assertContains( 'missing_alt', $types );
    }
}
