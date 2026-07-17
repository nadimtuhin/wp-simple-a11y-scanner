<?php
echo "PHP version: " . PHP_VERSION . "\n";
echo "CWD: " . getcwd() . "\n";

require_once __DIR__ . '/../includes/severity.php';
$sev = simple_a11y_scanner_severity('missing_alt');
echo "Severity of missing_alt: $sev\n";
echo "Expected: critical\n";
echo "Match: " . ($sev === 'critical' ? 'YES' : 'NO') . "\n";
