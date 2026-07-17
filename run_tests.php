<?php
// Run PHPUnit and capture exit code.
$output = [];
$exit   = 0;
exec( __DIR__ . '/vendor/bin/phpunit --testdox 2>&1', $output, $exit );
echo implode( "\n", $output ) . "\n";
echo "EXIT=$exit\n";
