<?php
/**
 * Admin audit log for scan history.
 * Stores scan events in a custom DB table.
 *
 * @package SimpleA11yScanner
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

namespace SimpleA11yScanner;

class AuditLog {

    const TABLE_VERSION_OPTION = 'simple_a11y_log_table_v';
    const TABLE_VERSION        = 1;

    public static function tableName(): string {
        global $wpdb;
        return $wpdb->prefix . 'a11y_scan_log';
    }

    /**
     * Create table if needed (version-gated).
     */
    public static function maybeCreateTable(): void {
        if ( (int) \get_option( self::TABLE_VERSION_OPTION, 0 ) >= self::TABLE_VERSION ) {
            return;
        }
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $table   = self::tableName();
        $charset = $wpdb->get_charset_collate();
        dbDelta( "CREATE TABLE {$table} (
            id          BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            scan_url    VARCHAR(2083)       NOT NULL DEFAULT '',
            post_id     BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
            issue_count INT(11)             NOT NULL DEFAULT 0,
            severity    VARCHAR(10)         NOT NULL DEFAULT 'minor',
            score       INT(11)             NOT NULL DEFAULT 0,
            user_id     BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
            client_ip   VARCHAR(45)         NOT NULL DEFAULT '',
            created_at  DATETIME            NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY created_at (created_at)
        ) {$charset};" );
        \update_option( self::TABLE_VERSION_OPTION, self::TABLE_VERSION );
    }

    /**
     * Insert a scan log entry.
     *
     * @param string $url         Scanned URL or context.
     * @param int    $post_id     Post ID (0 if REST/CLI scan).
     * @param array  $issues      Issues array.
     * @param string $client_ip   Requester IP.
     */
    public static function insert( string $url, int $post_id, array $issues, string $client_ip = '' ): void {
        global $wpdb;
        $score_data = function_exists( 'simple_a11y_scanner_score' ) ? simple_a11y_scanner_score( $issues ) : [ 'score' => count( $issues ) ];

        $worst = 'minor';
        if ( $score_data['critical'] ?? 0 ) {
            $worst = 'critical';
        } elseif ( $score_data['major'] ?? 0 ) {
            $worst = 'major';
        }

        $wpdb->insert(
            self::tableName(),
            [
                'scan_url'    => substr( $url, 0, 2083 ),
                'post_id'     => $post_id,
                'issue_count' => count( $issues ),
                'severity'    => $worst,
                'score'       => $score_data['score'],
                'user_id'     => \get_current_user_id(),
                'client_ip'   => substr( $client_ip, 0, 45 ),
                'created_at'  => \current_time( 'mysql' ),
            ],
            [ '%s', '%d', '%d', '%s', '%d', '%d', '%s', '%s' ]
        );
    }

    /**
     * Fetch paginated log entries.
     *
     * @param int $per_page  Entries per page.
     * @param int $page      1-based page number.
     * @return array
     */
    public static function getEntries( int $per_page = 50, int $page = 1 ): array {
        global $wpdb;
        $offset = ( max( 1, $page ) - 1 ) * $per_page;
        return $wpdb->get_results(
            $wpdb->prepare(
                'SELECT * FROM ' . self::tableName() . ' ORDER BY created_at DESC LIMIT %d OFFSET %d',
                $per_page,
                $offset
            )
        );
    }

    /**
     * Count total entries.
     */
    public static function countEntries(): int {
        global $wpdb;
        return (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . self::tableName() );
    }
}

// Create table on activation and at plugin load.
\register_activation_hook( dirname( __DIR__ ) . '/wp-simple-a11y-scanner.php', [ AuditLog::class, 'maybeCreateTable' ] );
add_action( 'plugins_loaded', [ AuditLog::class, 'maybeCreateTable' ] );

// Log after every scan.
add_action( 'simple_a11y_scanner_after_scan', function ( array $issues, string $url = '' ) {
    $ip = function_exists( 'simple_a11y_scanner_client_ip' ) ? simple_a11y_scanner_client_ip() : '';
    AuditLog::insert( $url, 0, $issues, $ip );
}, 10, 2 );
