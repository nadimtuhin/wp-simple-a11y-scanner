<?php
/**
 * Settings page for Simple A11y Scanner.
 *
 * @package SimpleA11yScanner
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Register plugin settings.
 */
function simple_a11y_scanner_register_settings() {
    register_setting(
        'simple_a11y_scanner_options',
        'simple_a11y_scanner_options',
        [
            'sanitize_callback' => 'simple_a11y_scanner_sanitize_options',
            'default'           => simple_a11y_scanner_default_options(),
        ]
    );

    add_settings_section(
        'simple_a11y_scanner_checks',
        __( 'Accessibility Checks', 'wp-simple-a11y-scanner' ),
        '__return_false',
        'simple-a11y-scanner-settings'
    );

    add_settings_field(
        'check_missing_alt',
        __( 'Check for missing alt attributes', 'wp-simple-a11y-scanner' ),
        'simple_a11y_scanner_field_checkbox',
        'simple-a11y-scanner-settings',
        'simple_a11y_scanner_checks',
        [ 'key' => 'check_missing_alt', 'label' => __( 'Flag images that have no alt attribute', 'wp-simple-a11y-scanner' ) ]
    );

    add_settings_field(
        'check_empty_links',
        __( 'Check for empty link text', 'wp-simple-a11y-scanner' ),
        'simple_a11y_scanner_field_checkbox',
        'simple-a11y-scanner-settings',
        'simple_a11y_scanner_checks',
        [ 'key' => 'check_empty_links', 'label' => __( 'Flag links with no visible text', 'wp-simple-a11y-scanner' ) ]
    );

    add_settings_field(
        'check_vague_links',
        __( 'Check for vague link text', 'wp-simple-a11y-scanner' ),
        'simple_a11y_scanner_field_checkbox',
        'simple-a11y-scanner-settings',
        'simple_a11y_scanner_checks',
        [ 'key' => 'check_vague_links', 'label' => __( 'Flag links with vague text ("click here", "read more", etc.)', 'wp-simple-a11y-scanner' ) ]
    );

    add_settings_field(
        'check_inline_contrast',
        __( 'Check inline CSS colour contrast', 'wp-simple-a11y-scanner' ),
        'simple_a11y_scanner_field_checkbox',
        'simple-a11y-scanner-settings',
        'simple_a11y_scanner_checks',
        [ 'key' => 'check_inline_contrast', 'label' => __( 'Flag elements with inline colour/background pairs failing WCAG AA (4.5:1)', 'wp-simple-a11y-scanner' ) ]
    );

    add_settings_section(
        'simple_a11y_scanner_notifications',
        __( 'Email Notifications', 'wp-simple-a11y-scanner' ),
        '__return_false',
        'simple-a11y-scanner-settings'
    );

    add_settings_field(
        'email_notifications',
        __( 'Enable email alerts', 'wp-simple-a11y-scanner' ),
        'simple_a11y_scanner_field_checkbox',
        'simple-a11y-scanner-settings',
        'simple_a11y_scanner_notifications',
        [ 'key' => 'email_notifications', 'label' => __( 'Send an email when issues are found during a scan', 'wp-simple-a11y-scanner' ) ]
    );

    add_settings_field(
        'notification_email',
        __( 'Notification email address', 'wp-simple-a11y-scanner' ),
        'simple_a11y_scanner_field_email',
        'simple-a11y-scanner-settings',
        'simple_a11y_scanner_notifications',
        [ 'key' => 'notification_email' ]
    );

    add_settings_field(
        'notification_threshold',
        __( 'Minimum issues to trigger alert', 'wp-simple-a11y-scanner' ),
        'simple_a11y_scanner_field_number',
        'simple-a11y-scanner-settings',
        'simple_a11y_scanner_notifications',
        [ 'key' => 'notification_threshold', 'min' => 1, 'max' => 100 ]
    );
}
add_action( 'admin_init', 'simple_a11y_scanner_register_settings' );

/**
 * Default option values.
 */
function simple_a11y_scanner_default_options() {
    return [
        'check_missing_alt'      => true,
        'check_empty_links'      => true,
        'check_vague_links'      => true,
        'check_inline_contrast'  => true,
        'email_notifications'    => false,
        'notification_email'     => get_option( 'admin_email', '' ),
        'notification_threshold' => 1,
    ];
}

/**
 * Get merged options (defaults + saved).
 */
function simple_a11y_scanner_get_options() {
    $saved = get_option( 'simple_a11y_scanner_options', [] );
    return wp_parse_args( $saved, simple_a11y_scanner_default_options() );
}

/**
 * Sanitize options before saving.
 */
function simple_a11y_scanner_sanitize_options( $input ) {
    $clean = simple_a11y_scanner_default_options();

    $clean['check_missing_alt']   = ! empty( $input['check_missing_alt'] );
    $clean['check_empty_links']   = ! empty( $input['check_empty_links'] );
    $clean['check_vague_links']   = ! empty( $input['check_vague_links'] );
    $clean['check_inline_contrast'] = ! empty( $input['check_inline_contrast'] );
    $clean['email_notifications'] = ! empty( $input['email_notifications'] );

    $email = isset( $input['notification_email'] ) ? sanitize_email( $input['notification_email'] ) : '';
    $clean['notification_email'] = is_email( $email ) ? $email : get_option( 'admin_email', '' );

    $threshold = isset( $input['notification_threshold'] ) ? absint( $input['notification_threshold'] ) : 1;
    $clean['notification_threshold'] = max( 1, $threshold );

    return $clean;
}

/* ── Field renderers ─────────────────────────────────────────────────── */

function simple_a11y_scanner_field_checkbox( $args ) {
    $opts = simple_a11y_scanner_get_options();
    $key  = $args['key'];
    $val  = ! empty( $opts[ $key ] );
    printf(
        '<label><input type="checkbox" name="simple_a11y_scanner_options[%s]" value="1" %s> %s</label>',
        esc_attr( $key ),
        checked( $val, true, false ),
        esc_html( $args['label'] ?? '' )
    );
}

function simple_a11y_scanner_field_email( $args ) {
    $opts = simple_a11y_scanner_get_options();
    $key  = $args['key'];
    printf(
        '<input type="email" name="simple_a11y_scanner_options[%s]" value="%s" class="regular-text">',
        esc_attr( $key ),
        esc_attr( $opts[ $key ] ?? '' )
    );
}

function simple_a11y_scanner_field_number( $args ) {
    $opts = simple_a11y_scanner_get_options();
    $key  = $args['key'];
    printf(
        '<input type="number" name="simple_a11y_scanner_options[%s]" value="%s" min="%d" max="%d" class="small-text">',
        esc_attr( $key ),
        esc_attr( $opts[ $key ] ?? 1 ),
        absint( $args['min'] ?? 1 ),
        absint( $args['max'] ?? 100 )
    );
}

/* ── Settings page HTML ──────────────────────────────────────────────── */

function simple_a11y_scanner_settings_page() {
    if ( ! current_user_can( 'manage_options' ) ) {
        return;
    }
    ?>
    <div class="wrap">
        <h1><?php echo esc_html__( 'A11y Scanner — Settings', 'wp-simple-a11y-scanner' ); ?></h1>
        <form method="post" action="options.php">
            <?php
            settings_fields( 'simple_a11y_scanner_options' );
            do_settings_sections( 'simple-a11y-scanner-settings' );
            submit_button( __( 'Save Settings', 'wp-simple-a11y-scanner' ) );
            ?>
        </form>
    </div>
    <?php
}
