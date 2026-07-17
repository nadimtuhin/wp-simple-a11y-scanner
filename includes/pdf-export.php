<?php
/**
 * PDF export for scan reports using dompdf.
 * Supports custom styling templates via filter.
 *
 * @package SimpleA11yScanner
 */

if ( ! defined( 'ABSPATH' ) && ! defined( 'PHPUNIT_RUNNING' ) ) {
    exit;
}

/**
 * Built-in PDF templates. Keys are template slugs.
 *
 * @return array<string, array{label: string, css: string}>
 */
function simple_a11y_scanner_pdf_templates(): array {
    return [
        'default' => [
            'label' => __( 'Default', 'wp-simple-a11y-scanner' ),
            'css'   => '
body { font-family: DejaVu Sans, sans-serif; font-size: 12px; color: #333; }
h1   { font-size: 18px; color: #1d2327; }
table { width: 100%; border-collapse: collapse; margin-top: 12px; }
th, td { border: 1px solid #ccc; padding: 6px 8px; text-align: left; }
th { background: #f0f0f0; font-weight: bold; }
code { font-size: 10px; }
',
        ],
        'dark' => [
            'label' => __( 'Dark', 'wp-simple-a11y-scanner' ),
            'css'   => '
body { font-family: DejaVu Sans, sans-serif; font-size: 12px; background: #1e1e1e; color: #f0f0f0; }
h1   { font-size: 18px; color: #4fc3f7; }
table { width: 100%; border-collapse: collapse; margin-top: 12px; }
th, td { border: 1px solid #555; padding: 6px 8px; text-align: left; }
th { background: #333; color: #fff; font-weight: bold; }
td { background: #2a2a2a; }
code { font-size: 10px; color: #a5d6a7; }
',
        ],
        'minimal' => [
            'label' => __( 'Minimal', 'wp-simple-a11y-scanner' ),
            'css'   => '
body { font-family: DejaVu Sans, sans-serif; font-size: 11px; color: #000; }
h1   { font-size: 16px; border-bottom: 2px solid #000; padding-bottom: 4px; }
table { width: 100%; border-collapse: collapse; margin-top: 8px; }
th, td { border-bottom: 1px solid #bbb; padding: 4px 6px; text-align: left; }
th { font-weight: bold; }
code { font-size: 9px; }
',
        ],
    ];
}

/**
 * Build the HTML for a PDF report.
 *
 * @param array  $issues   Issue arrays (type, message, element).
 * @param string $title    Report title.
 * @param string $template Template slug (default|dark|minimal or custom).
 * @return string          Full HTML document.
 */
function simple_a11y_scanner_build_pdf_html( array $issues, string $title, string $template = 'default' ): string {
    $templates = simple_a11y_scanner_pdf_templates();

    /**
     * Filter: register additional PDF templates.
     * Callback receives the templates array and should return a modified copy.
     * Each entry: [ 'label' => string, 'css' => string ]
     *
     * @param array $templates Built-in templates keyed by slug.
     */
    $templates = apply_filters( 'simple_a11y_scanner_pdf_templates', $templates );

    $css = isset( $templates[ $template ] )
        ? $templates[ $template ]['css']
        : $templates['default']['css'];

    $rows = '';
    foreach ( $issues as $issue ) {
        $severity = simple_a11y_scanner_severity( $issue['type'] ?? '' );
        $rows    .= sprintf(
            '<tr><td>%s</td><td>%s</td><td>%s</td><td><code>%s</code></td></tr>',
            esc_html( strtoupper( $severity ) ),
            esc_html( $issue['type'] ?? '' ),
            esc_html( $issue['message'] ?? '' ),
            esc_html( substr( $issue['element'] ?? '', 0, 120 ) )
        );
    }

    $count = count( $issues );

    $html = '<!DOCTYPE html><html><head><meta charset="utf-8"><style>' . $css . '</style></head><body>'
        . '<h1>' . esc_html( $title ) . '</h1>'
        . '<p>' . sprintf( esc_html__( 'Total issues: %d', 'wp-simple-a11y-scanner' ), $count ) . '</p>'
        . '<table><thead><tr>'
        . '<th>' . esc_html__( 'Severity', 'wp-simple-a11y-scanner' ) . '</th>'
        . '<th>' . esc_html__( 'Type', 'wp-simple-a11y-scanner' ) . '</th>'
        . '<th>' . esc_html__( 'Message', 'wp-simple-a11y-scanner' ) . '</th>'
        . '<th>' . esc_html__( 'Element', 'wp-simple-a11y-scanner' ) . '</th>'
        . '</tr></thead><tbody>' . $rows . '</tbody></table>'
        . '</body></html>';

    /**
     * Filter the full HTML document used to render the PDF report.
     * Use to add custom sections, modify layout, or inject branding.
     *
     * @param string  $html     Complete HTML document string.
     * @param array[] $issues   Issues included in the report.
     * @param string  $title    Report title.
     * @param string  $template Template slug in use.
     */
    return apply_filters( 'simple_a11y_scanner_pdf_content', $html, $issues, $title, $template );
}

/**
 * Export a scan result array as a PDF download.
 *
 * @param array  $issues   Array of issue arrays.
 * @param string $title    Report title.
 * @param string $template PDF template slug.
 */
function simple_a11y_scanner_export_pdf( array $issues, string $title = '', string $template = 'default' ): void {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( esc_html__( 'Not allowed.', 'wp-simple-a11y-scanner' ) );
    }

    require_once dirname( __DIR__ ) . '/vendor/autoload.php';

    $title = $title ?: __( 'A11y Scan Report', 'wp-simple-a11y-scanner' );

    /**
     * Fires before the PDF is generated and streamed to the browser.
     *
     * @param array[] $issues   Issues to be included in the PDF.
     * @param string  $title    Report title.
     * @param string  $template Template slug.
     */
    do_action( 'simple_a11y_scanner_before_pdf_export', $issues, $title, $template );

    $options = new \Dompdf\Options();
    $options->set( 'isRemoteEnabled', false );
    $dompdf = new \Dompdf\Dompdf( $options );
    $dompdf->loadHtml( simple_a11y_scanner_build_pdf_html( $issues, $title, $template ) );
    $dompdf->setPaper( 'A4', 'landscape' );
    $dompdf->render();
    $dompdf->stream( 'a11y-scan-report.pdf', [ 'Attachment' => true ] );

    /**
     * Fires after the PDF has been rendered (just before exit).
     * Note: headers have already been sent at this point.
     *
     * @param array[] $issues   Issues in the report.
     * @param string  $title    Report title.
     */
    do_action( 'simple_a11y_scanner_after_pdf_export', $issues, $title );

    exit;
}

/**
 * Handle admin-post action for PDF export.
 */
function simple_a11y_scanner_handle_pdf_export(): void {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( esc_html__( 'Not allowed.', 'wp-simple-a11y-scanner' ) );
    }
    check_admin_referer( 'simple_a11y_scanner_export_pdf' );

    $key      = sanitize_text_field( wp_unslash( $_GET['report_key'] ?? '' ) );
    $template = sanitize_key( wp_unslash( $_GET['template'] ?? 'default' ) );
    $issues   = $key ? (array) get_transient( 'simple_a11y_scan_' . $key ) : [];

    simple_a11y_scanner_export_pdf( $issues, '', $template );
}
add_action( 'admin_post_simple_a11y_export_pdf', 'simple_a11y_scanner_handle_pdf_export' );
