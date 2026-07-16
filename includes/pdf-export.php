<?php
/**
 * PDF export for scan reports using dompdf.
 *
 * @package SimpleA11yScanner
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Export a scan result array as a PDF download.
 *
 * @param array  $issues  Array of issue arrays with keys type, message, element.
 * @param string $title   Report title shown in the PDF.
 */
function simple_a11y_scanner_export_pdf( array $issues, string $title = '' ): void {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( esc_html__( 'Not allowed.', 'wp-simple-a11y-scanner' ) );
    }

    require_once dirname( __DIR__ ) . '/vendor/autoload.php';

    $title = $title ?: __( 'A11y Scan Report', 'wp-simple-a11y-scanner' );

    // Build HTML table for the PDF.
    $rows = '';
    foreach ( $issues as $issue ) {
        $rows .= sprintf(
            '<tr><td>%s</td><td>%s</td><td><code>%s</code></td></tr>',
            esc_html( $issue['type'] ?? '' ),
            esc_html( $issue['message'] ?? '' ),
            esc_html( $issue['element'] ?? '' )
        );
    }

    $count = count( $issues );
    $html  = '<!DOCTYPE html><html><head><meta charset="utf-8">
<style>
  body { font-family: DejaVu Sans, sans-serif; font-size: 12px; }
  h1   { font-size: 18px; }
  table { width: 100%; border-collapse: collapse; }
  th, td { border: 1px solid #ccc; padding: 6px 8px; text-align: left; }
  th { background: #f0f0f0; }
  code { font-size: 10px; }
</style>
</head><body>
<h1>' . esc_html( $title ) . '</h1>
<p>' . sprintf( esc_html__( 'Total issues: %d', 'wp-simple-a11y-scanner' ), $count ) . '</p>
<table>
  <thead><tr><th>' . esc_html__( 'Type', 'wp-simple-a11y-scanner' ) . '</th>
              <th>' . esc_html__( 'Message', 'wp-simple-a11y-scanner' ) . '</th>
              <th>' . esc_html__( 'Element', 'wp-simple-a11y-scanner' ) . '</th></tr></thead>
  <tbody>' . $rows . '</tbody>
</table>
</body></html>';

    $options = new \Dompdf\Options();
    $options->set( 'isRemoteEnabled', false );
    $dompdf = new \Dompdf\Dompdf( $options );
    $dompdf->loadHtml( $html );
    $dompdf->setPaper( 'A4', 'landscape' );
    $dompdf->render();
    $dompdf->stream( 'a11y-scan-report.pdf', [ 'Attachment' => true ] );
    exit;
}

/**
 * Handle admin-post action for PDF export.
 * Reads issues from a transient keyed by nonce-verified request param.
 */
function simple_a11y_scanner_handle_pdf_export(): void {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( esc_html__( 'Not allowed.', 'wp-simple-a11y-scanner' ) );
    }
    check_admin_referer( 'simple_a11y_scanner_export_pdf' );

    $key    = sanitize_text_field( wp_unslash( $_GET['report_key'] ?? '' ) );
    $issues = $key ? (array) get_transient( 'simple_a11y_scan_' . $key ) : [];

    simple_a11y_scanner_export_pdf( $issues );
}
add_action( 'admin_post_simple_a11y_export_pdf', 'simple_a11y_scanner_handle_pdf_export' );
