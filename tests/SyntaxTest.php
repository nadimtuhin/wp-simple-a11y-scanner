<?php
use PHPUnit\Framework\TestCase;

/**
 * Every shipped PHP file must parse; a parse/compile error in any include
 * takes down the whole plugin on load.
 */
class SyntaxTest extends TestCase {

    public function phpFileProvider(): array {
        $root  = dirname( __DIR__ );
        $files = array_merge( [ $root . '/wp-simple-a11y-scanner.php' ], glob( $root . '/includes/*.php' ) );
        $out   = [];
        foreach ( $files as $file ) {
            $out[ basename( $file ) ] = [ $file ];
        }
        return $out;
    }

    /**
     * @dataProvider phpFileProvider
     */
    public function testFileLints( string $file ): void {
        $output = [];
        $exit   = 0;
        exec( escapeshellarg( PHP_BINARY ) . ' -l ' . escapeshellarg( $file ) . ' 2>&1', $output, $exit );
        $this->assertSame( 0, $exit, implode( "\n", $output ) );
    }
}
