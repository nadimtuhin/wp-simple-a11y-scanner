<?php
/**
 * Plugin Name: Simple A11y Scanner
 * Description: Scans WordPress content for accessibility issues.
 * Version: 1.0.0
 * Author: Omar Faruque Tuhin (Nadim)
 * License: GPL-2.0+
 */

if (!defined('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/includes/scanner.php';
require_once __DIR__ . '/includes/api.php';
