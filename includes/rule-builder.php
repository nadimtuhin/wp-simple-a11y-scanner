<?php
/**
 * Rule Builder UI admin page.
 * Allows admin users to create custom regex-based a11y rules via a UI.
 * Rules are stored in options and consumed by the v2 REST endpoint.
 *
 * @package SimpleA11yScanner
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Register the Rule Builder admin page as a sub-menu under A11y Scanner.
 */
function simple_a11y_scanner_rule_builder_menu(): void {
    add_submenu_page(
        'simple-a11y-scanner',
        __( 'Custom Rules', 'wp-simple-a11y-scanner' ),
        __( 'Custom Rules', 'wp-simple-a11y-scanner' ),
        'manage_options',
        'simple-a11y-scanner-rules',
        'simple_a11y_scanner_rule_builder_page'
    );
}
add_action( 'admin_menu', 'simple_a11y_scanner_rule_builder_menu' );

/**
 * Handle rule form submission (add/delete rule).
 */
function simple_a11y_scanner_handle_rule_action(): void {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( esc_html__( 'Not allowed.', 'wp-simple-a11y-scanner' ) );
    }
    check_admin_referer( 'simple_a11y_scanner_rule_action' );

    $rules  = get_option( 'simple_a11y_scanner_custom_rules', [] );
    $action = sanitize_key( wp_unslash( $_POST['rule_action'] ?? '' ) );

    if ( 'add' === $action ) {
        $id      = sanitize_key( wp_unslash( $_POST['rule_id'] ?? '' ) );
        $label   = sanitize_text_field( wp_unslash( $_POST['rule_label'] ?? '' ) );
        $pattern = sanitize_text_field( wp_unslash( $_POST['rule_pattern'] ?? '' ) );
        $message = sanitize_text_field( wp_unslash( $_POST['rule_message'] ?? '' ) );
        $sev     = sanitize_key( wp_unslash( $_POST['rule_severity'] ?? 'minor' ) );

        if ( $id && $pattern ) {
            // Remove existing rule with same ID.
            $rules = array_filter( $rules, fn( $r ) => $r['id'] !== $id );
            $rules[] = [
                'id'       => $id,
                'label'    => $label,
                'pattern'  => $pattern,
                'message'  => $message,
                'severity' => in_array( $sev, [ 'critical', 'major', 'minor' ], true ) ? $sev : 'minor',
                'enabled'  => true,
            ];
        }
    } elseif ( 'delete' === $action ) {
        $del_id = sanitize_key( wp_unslash( $_POST['rule_delete_id'] ?? '' ) );
        $rules  = array_filter( $rules, fn( $r ) => $r['id'] !== $del_id );
    }

    update_option( 'simple_a11y_scanner_custom_rules', array_values( $rules ) );
    wp_safe_redirect( add_query_arg( 'updated', '1', admin_url( 'admin.php?page=simple-a11y-scanner-rules' ) ) );
    exit;
}
add_action( 'admin_post_simple_a11y_rule_action', 'simple_a11y_scanner_handle_rule_action' );

/**
 * Render the Rule Builder admin page.
 */
function simple_a11y_scanner_rule_builder_page(): void {
    if ( ! current_user_can( 'manage_options' ) ) {
        return;
    }
    $rules = get_option( 'simple_a11y_scanner_custom_rules', [] );
    $form_url = esc_url( admin_url( 'admin-post.php' ) );
    ?>
    <div class="wrap">
        <h1><?php esc_html_e( 'A11y Scanner — Custom Rules', 'wp-simple-a11y-scanner' ); ?></h1>

        <?php if ( ! empty( $_GET['updated'] ) ) : ?>
            <div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Rules saved.', 'wp-simple-a11y-scanner' ); ?></p></div>
        <?php endif; ?>

        <h2><?php esc_html_e( 'Add / Edit Rule', 'wp-simple-a11y-scanner' ); ?></h2>
        <form method="post" action="<?php echo $form_url; ?>">
            <?php wp_nonce_field( 'simple_a11y_scanner_rule_action' ); ?>
            <input type="hidden" name="action" value="simple_a11y_rule_action">
            <input type="hidden" name="rule_action" value="add">
            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row"><label for="rule_id"><?php esc_html_e( 'Rule ID (slug)', 'wp-simple-a11y-scanner' ); ?></label></th>
                    <td><input type="text" id="rule_id" name="rule_id" class="regular-text" placeholder="my-rule-id" required></td>
                </tr>
                <tr>
                    <th scope="row"><label for="rule_label"><?php esc_html_e( 'Label', 'wp-simple-a11y-scanner' ); ?></label></th>
                    <td><input type="text" id="rule_label" name="rule_label" class="regular-text" placeholder="Human-readable name"></td>
                </tr>
                <tr>
                    <th scope="row"><label for="rule_pattern"><?php esc_html_e( 'Regex Pattern', 'wp-simple-a11y-scanner' ); ?></label></th>
                    <td><input type="text" id="rule_pattern" name="rule_pattern" class="large-text" placeholder="/<blink\b/i" required>
                        <p class="description"><?php esc_html_e( 'PHP-compatible regex tested against post HTML content.', 'wp-simple-a11y-scanner' ); ?></p></td>
                </tr>
                <tr>
                    <th scope="row"><label for="rule_message"><?php esc_html_e( 'Issue Message', 'wp-simple-a11y-scanner' ); ?></label></th>
                    <td><input type="text" id="rule_message" name="rule_message" class="large-text" placeholder="Found deprecated element..."></td>
                </tr>
                <tr>
                    <th scope="row"><label for="rule_severity"><?php esc_html_e( 'Severity', 'wp-simple-a11y-scanner' ); ?></label></th>
                    <td>
                        <select id="rule_severity" name="rule_severity">
                            <option value="minor"><?php esc_html_e( 'Minor', 'wp-simple-a11y-scanner' ); ?></option>
                            <option value="major"><?php esc_html_e( 'Major', 'wp-simple-a11y-scanner' ); ?></option>
                            <option value="critical"><?php esc_html_e( 'Critical', 'wp-simple-a11y-scanner' ); ?></option>
                        </select>
                    </td>
                </tr>
            </table>
            <?php submit_button( __( 'Save Rule', 'wp-simple-a11y-scanner' ) ); ?>
        </form>

        <?php if ( ! empty( $rules ) ) : ?>
        <h2><?php esc_html_e( 'Existing Rules', 'wp-simple-a11y-scanner' ); ?></h2>
        <table class="widefat fixed striped">
            <thead>
                <tr>
                    <th><?php esc_html_e( 'ID', 'wp-simple-a11y-scanner' ); ?></th>
                    <th><?php esc_html_e( 'Label', 'wp-simple-a11y-scanner' ); ?></th>
                    <th><?php esc_html_e( 'Pattern', 'wp-simple-a11y-scanner' ); ?></th>
                    <th><?php esc_html_e( 'Severity', 'wp-simple-a11y-scanner' ); ?></th>
                    <th><?php esc_html_e( 'Actions', 'wp-simple-a11y-scanner' ); ?></th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ( $rules as $rule ) : ?>
                <tr>
                    <td><?php echo esc_html( $rule['id'] ); ?></td>
                    <td><?php echo esc_html( $rule['label'] ?? '' ); ?></td>
                    <td><code><?php echo esc_html( $rule['pattern'] ); ?></code></td>
                    <td><?php echo esc_html( $rule['severity'] ?? 'minor' ); ?></td>
                    <td>
                        <form method="post" action="<?php echo $form_url; ?>" style="display:inline;">
                            <?php wp_nonce_field( 'simple_a11y_scanner_rule_action' ); ?>
                            <input type="hidden" name="action" value="simple_a11y_rule_action">
                            <input type="hidden" name="rule_action" value="delete">
                            <input type="hidden" name="rule_delete_id" value="<?php echo esc_attr( $rule['id'] ); ?>">
                            <?php submit_button( __( 'Delete', 'wp-simple-a11y-scanner' ), 'delete small', 'submit', false ); ?>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php else : ?>
        <p><?php esc_html_e( 'No custom rules yet. Add one above.', 'wp-simple-a11y-scanner' ); ?></p>
        <?php endif; ?>
    </div>
    <?php
}
