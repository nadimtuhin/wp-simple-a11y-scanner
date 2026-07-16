<?php
require __DIR__ . '/../includes/scanner.php';

$s = new SimpleA11yScanner\Scanner();

// Luminance
$l = $s->colorLuminance('#ffffff');
assert(abs($l - 1.0) < 0.001, 'white luminance');
$l2 = $s->colorLuminance('#000000');
assert(abs($l2 - 0.0) < 0.001, 'black luminance');

// Contrast ratio
$r = $s->contrastRatio(1.0, 0.0);
assert(abs($r - 21.0) < 0.01, 'max contrast 21:1');

// Low contrast: light grey on white (#aaa on #fff = ~2.32:1)
$issues = $s->checkInlineContrast('<p style="color:#aaa;background-color:#fff">hi</p>');
assert(count($issues) === 1, 'low contrast detected');
assert($issues[0]['type'] === 'low_contrast', 'type is low_contrast');

// Good contrast: black on white = 21:1
$issues2 = $s->checkInlineContrast('<p style="color:#000;background-color:#fff">hi</p>');
assert(count($issues2) === 0, 'high contrast ok');

// scanContent routes contrast issues through
$issues3 = $s->scanContent('<p style="color:#aaa;background:#fff">hi</p>');
$types = array_column($issues3, 'type');
assert(in_array('low_contrast', $types), 'scanContent includes contrast');

// rgb() colour support
$l3 = $s->colorLuminance('rgb(255, 255, 255)');
assert(abs($l3 - 1.0) < 0.001, 'rgb white luminance');

echo "ALL PASS\n";
