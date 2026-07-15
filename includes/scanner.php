<?php
namespace SimpleA11yScanner;

class Scanner {
    public function scanContent($content) {
        $issues = [];
        if (strpos($content, '<img') !== false && strpos($content, 'alt=') === false) {
            $issues[] = 'Image missing alt attribute.';
        }
        return $issues;
    }
}
