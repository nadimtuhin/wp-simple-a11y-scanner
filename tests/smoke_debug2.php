<?php
if (!function_exists('apply_filters')) {
    function apply_filters($tag, $value) { return $value; }
}
require_once __DIR__ . '/../includes/severity.php';
$sev = simple_a11y_scanner_severity('missing_alt');
echo "Severity: $sev\n";
echo "Done\n";
