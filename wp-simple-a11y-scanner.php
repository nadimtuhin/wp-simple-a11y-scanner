<?php
/**
 * Plugin Name: Simple A11y Scanner
 * Description: Scans WordPress content for accessibility issues.
 * Version:     1.2.0
 * Author:      Omar Faruque Tuhin (Nadim)
 * License:     GPL-2.0+
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

require_once __DIR__ . '/includes/scanner.php';
require_once __DIR__ . '/includes/api.php';
require_once __DIR__ . '/includes/settings.php';
require_once __DIR__ . '/includes/notifications.php';
require_once __DIR__ . '/includes/pdf-export.php';

// WP-CLI commands — only load in CLI context.
if ( defined( 'WP_CLI' ) && WP_CLI ) {
    require_once __DIR__ . '/includes/cli.php';
}

// Register REST routes.
add_action( 'rest_api_init', function () {
    $api = new \SimpleA11yScanner\Api();
    $api->registerRoutes();
} );

// Admin menu: main page + settings sub-page.
add_action( 'admin_menu', function () {
    add_menu_page(
        __( 'A11y Scanner', 'wp-simple-a11y-scanner' ),
        __( 'A11y Scanner', 'wp-simple-a11y-scanner' ),
        'manage_options',
        'simple-a11y-scanner',
        'simple_a11y_scanner_admin_page',
        'dashicons-universal-access',
        80
    );

    add_submenu_page(
        'simple-a11y-scanner',
        __( 'A11y Scanner Settings', 'wp-simple-a11y-scanner' ),
        __( 'Settings', 'wp-simple-a11y-scanner' ),
        'manage_options',
        'simple-a11y-scanner-settings',
        'simple_a11y_scanner_settings_page'
    );
} );

function simple_a11y_scanner_admin_page() {
    $opts = simple_a11y_scanner_get_options();
    ?>
    <div class="wrap">
        <h1><?php echo esc_html__( 'Simple A11y Scanner', 'wp-simple-a11y-scanner' ); ?></h1>
        <p><?php echo esc_html__( 'Scan your content for accessibility issues via the REST API or WP-CLI.', 'wp-simple-a11y-scanner' ); ?></p>

        <h2><?php echo esc_html__( 'Active Checks', 'wp-simple-a11y-scanner' ); ?></h2>
        <ul>
            <?php if ( $opts['check_missing_alt'] ) : ?>
                <li><?php echo esc_html__( 'Images missing alt attributes', 'wp-simple-a11y-scanner' ); ?></li>
            <?php endif; ?>
            <?php if ( $opts['check_empty_links'] ) : ?>
                <li><?php echo esc_html__( 'Links with empty text', 'wp-simple-a11y-scanner' ); ?></li>
            <?php endif; ?>
            <?php if ( $opts['check_vague_links'] ) : ?>
                <li><?php echo esc_html__( 'Links with vague text (e.g., "click here", "read more")', 'wp-simple-a11y-scanner' ); ?></li>
            <?php endif; ?>
        </ul>

        <p><a href="<?php echo esc_url( admin_url( 'admin.php?page=simple-a11y-scanner-settings' ) ); ?>" class="button">
            <?php echo esc_html__( 'Configure Settings →', 'wp-simple-a11y-scanner' ); ?>
        </a></p>

        <h2><?php echo esc_html__( 'REST API Usage', 'wp-simple-a11y-scanner' ); ?></h2>
        <pre style="background:#f0f0f0;padding:12px;">
POST <?php echo esc_url( rest_url( 'simple-a11y/v1/scan' ) ); ?>

Content-Type: application/json
{ "content": "&lt;img src=\"photo.jpg\"&gt;&lt;a href=\"#\"&gt;click here&lt;/a&gt;" }
        </pre>

        <h2><?php echo esc_html__( 'WP-CLI Usage', 'wp-simple-a11y-scanner' ); ?></h2>
        <pre style="background:#f0f0f0;padding:12px;">
wp a11y scan https://example.com
wp a11y scan https://example.com --format=json
wp a11y scan https://example.com --send-email
        </pre>

        <h2><?php echo esc_html__( 'Scan Summary Endpoint', 'wp-simple-a11y-scanner' ); ?></h2>
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

// Multisite: register network dashboard widget.
if ( function_exists( 'is_multisite' ) && is_multisite() ) {
    add_action( 'wp_network_dashboard_setup', function () {
        wp_add_dashboard_widget(
            'simple_a11y_scanner_network_widget',
            __( 'A11y Scanner — Network Overview', 'wp-simple-a11y-scanner' ),
            'simple_a11y_scanner_network_dashboard_widget'
        );
    } );
}

function simple_a11y_scanner_network_dashboard_widget(): void {
    $sites = get_sites( [ 'number' => 50 ] );
    echo '<p><strong>' . esc_html__( 'Sites in this network:', 'wp-simple-a11y-scanner' ) . '</strong> ' . count( $sites ) . '</p>';
    echo '<ul>';
    foreach ( $sites as $site ) {
        echo '<li><a href="' . esc_url( get_admin_url( $site->blog_id, 'admin.php?page=simple-a11y-scanner' ) ) . '">'
            . esc_html( get_blog_option( $site->blog_id, 'blogname' ) )
            . '</a></li>';
    }
    echo '</ul>';
}

// Gutenberg: enqueue block editor inspector controls panel.
add_action( 'enqueue_block_editor_assets', function () {
    $asset_file = plugin_dir_path( __FILE__ ) . 'js/inspector-controls.asset.php';
    $asset      = file_exists( $asset_file )
        ? require $asset_file
        : [ 'dependencies' => [ 'wp-plugins', 'wp-edit-post', 'wp-element', 'wp-components', 'wp-i18n' ], 'version' => '1.0.0' ];

    wp_enqueue_script(
        'simple-a11y-scanner-inspector',
        plugins_url( 'js/inspector-controls.js', __FILE__ ),
        $asset['dependencies'],
        $asset['version'],
        true
    );

    wp_set_script_translations( 'simple-a11y-scanner-inspector', 'wp-simple-a11y-scanner' );
} );

function simple_a11y_scanner_dashboard_widget() {
    echo '<p>' . esc_html__( 'Use the A11y Scanner REST API or WP-CLI to detect accessibility issues in your content.', 'wp-simple-a11y-scanner' ) . '</p>';
    echo '<p><strong>' . esc_html__( 'Endpoint:', 'wp-simple-a11y-scanner' ) . '</strong> <code>POST /wp-json/simple-a11y/v1/scan</code></p>';
    echo '<p><strong>' . esc_html__( 'CLI:', 'wp-simple-a11y-scanner' ) . '</strong> <code>wp a11y scan &lt;url&gt;</code></p>';
    echo '<p><a href="' . esc_url( admin_url( 'admin.php?page=simple-a11y-scanner' ) ) . '">' . esc_html__( 'View documentation →', 'wp-simple-a11y-scanner' ) . '</a></p>';
}
