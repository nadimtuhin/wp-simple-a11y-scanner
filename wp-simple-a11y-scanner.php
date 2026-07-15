<?php
/**
 * Plugin Name: Simple A11y Scanner
 * Description: Scans WordPress content for accessibility issues.
 * Version:     1.1.0
 * Author:      Omar Faruque Tuhin (Nadim)
 * License:     GPL-2.0+
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

require_once __DIR__ . '/includes/scanner.php';
require_once __DIR__ . '/includes/api.php';

// Register REST routes.
add_action( 'rest_api_init', function () {
    $api = new \SimpleA11yScanner\Api();
    $api->registerRoutes();
} );

// Admin page — shows scan summary UI.
add_action( 'admin_menu', function () {
    add_menu_page(
        'A11y Scanner',
        'A11y Scanner',
        'manage_options',
        'simple-a11y-scanner',
        'simple_a11y_scanner_admin_page',
        'dashicons-universal-access',
        80
    );
} );

function simple_a11y_scanner_admin_page() {
    ?>
    <div class="wrap">
        <h1><?php echo esc_html__( 'Simple A11y Scanner', 'wp-simple-a11y-scanner' ); ?></h1>
        <p><?php echo esc_html__( 'Scan your content for accessibility issues via the REST API.', 'wp-simple-a11y-scanner' ); ?></p>

        <h2><?php echo esc_html__( 'Available Checks', 'wp-simple-a11y-scanner' ); ?></h2>
        <ul>
            <li><?php echo esc_html__( 'Images missing alt attributes', 'wp-simple-a11y-scanner' ); ?></li>
            <li><?php echo esc_html__( 'Links with empty text', 'wp-simple-a11y-scanner' ); ?></li>
            <li><?php echo esc_html__( 'Links with vague text (e.g., "click here", "read more")', 'wp-simple-a11y-scanner' ); ?></li>
        </ul>

        <h2><?php echo esc_html__( 'API Usage', 'wp-simple-a11y-scanner' ); ?></h2>
        <pre style="background:#f0f0f0;padding:12px;">
POST <?php echo esc_url( rest_url( 'simple-a11y/v1/scan' ) ); ?>

Content-Type: application/json
{ "content": "&lt;img src=\"photo.jpg\"&gt;&lt;a href=\"#\"&gt;click here&lt;/a&gt;" }
        </pre>

        <h2><?php echo esc_html__( 'Scan Summary', 'wp-simple-a11y-scanner' ); ?></h2>
        <p><?php echo esc_html__( 'Use POST /wp-json/simple-a11y/v1/scan/summary to get an issue count grouped by type.', 'wp-simple-a11y-scanner' ); ?></p>
    </div>
    <?php
}

// Dashboard widget — quick summary.
add_action( 'wp_dashboard_setup', function () {
    wp_add_dashboard_widget(
        'simple_a11y_scanner_widget',
        __( 'A11y Scanner', 'wp-simple-a11y-scanner' ),
        'simple_a11y_scanner_dashboard_widget'
    );
} );

function simple_a11y_scanner_dashboard_widget() {
    echo '<p>' . esc_html__( 'Use the A11y Scanner REST API to detect accessibility issues in your content.', 'wp-simple-a11y-scanner' ) . '</p>';
    echo '<p><strong>' . esc_html__( 'Endpoint:', 'wp-simple-a11y-scanner' ) . '</strong> <code>POST /wp-json/simple-a11y/v1/scan</code></p>';
    echo '<p><a href="' . esc_url( admin_url( 'admin.php?page=simple-a11y-scanner' ) ) . '">' . esc_html__( 'View full documentation →', 'wp-simple-a11y-scanner' ) . '</a></p>';
}
