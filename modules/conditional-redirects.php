<?php
/**
 * Conditional Redirects (Per-Site)
 *
 * Allows site admins to configure simple redirects based on request conditions:
 * - Front page
 * - Login page (wp-login.php)
 * - Logged-in user
 * - Non-logged-in user
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Option key used for per-site redirect rules.
 */
define( 'UTM_CONDITIONAL_REDIRECTS_OPTION', 'utm_conditional_redirects_rules' );

/**
 * Return default redirect rules.
 *
 * @return array
 */
function utm_conditional_redirects_default_rules() {
    return array(
        'frontpage' => array(
            'enabled' => 0,
            'target'  => '',
        ),
        'login_page' => array(
            'enabled' => 0,
            'target'  => '',
        ),
        'logged_in' => array(
            'enabled' => 0,
            'target'  => '',
        ),
        'non_logged_in' => array(
            'enabled' => 0,
            'target'  => '',
        ),
    );
}

/**
 * Merge saved rules with defaults.
 *
 * @return array
 */
function utm_conditional_redirects_get_rules() {
    $defaults = utm_conditional_redirects_default_rules();
    $saved    = get_option( UTM_CONDITIONAL_REDIRECTS_OPTION, array() );

    if ( ! is_array( $saved ) ) {
        return $defaults;
    }

    foreach ( $defaults as $key => $rule ) {
        if ( ! isset( $saved[ $key ] ) || ! is_array( $saved[ $key ] ) ) {
            $saved[ $key ] = $rule;
            continue;
        }

        $saved[ $key ]['enabled'] = ! empty( $saved[ $key ]['enabled'] ) ? 1 : 0;
        $saved[ $key ]['target']  = isset( $saved[ $key ]['target'] ) ? (string) $saved[ $key ]['target'] : '';
    }

    return $saved;
}

/**
 * Sanitize rules before saving.
 *
 * @param mixed $input Raw option input.
 * @return array
 */
function utm_conditional_redirects_sanitize_rules( $input ) {
    $defaults  = utm_conditional_redirects_default_rules();
    $sanitized = $defaults;

    if ( ! is_array( $input ) ) {
        return $sanitized;
    }

    foreach ( $defaults as $key => $rule ) {
        $raw_rule = isset( $input[ $key ] ) && is_array( $input[ $key ] ) ? $input[ $key ] : array();

        $enabled = ! empty( $raw_rule['enabled'] ) ? 1 : 0;
        $target  = isset( $raw_rule['target'] ) ? esc_url_raw( trim( (string) $raw_rule['target'] ) ) : '';

        if ( $enabled && empty( $target ) ) {
            $enabled = 0;
        }

        $sanitized[ $key ] = array(
            'enabled' => $enabled,
            'target'  => $target,
        );
    }

    return $sanitized;
}

/**
 * Register settings.
 *
 * Per-site settings page (single site admin), not network settings.
 */
function utm_conditional_redirects_register_settings() {
    register_setting(
        'utm_conditional_redirects_group',
        UTM_CONDITIONAL_REDIRECTS_OPTION,
        array(
            'type'              => 'array',
            'sanitize_callback' => 'utm_conditional_redirects_sanitize_rules',
            'default'           => utm_conditional_redirects_default_rules(),
        )
    );
}
add_action( 'admin_init', 'utm_conditional_redirects_register_settings' );

/**
 * Add settings page under Settings.
 */
function utm_conditional_redirects_add_settings_page() {
    add_options_page(
        'UTM Conditional Redirects',
        'UTM Redirects',
        'manage_options',
        'utm-conditional-redirects',
        'utm_conditional_redirects_render_settings_page'
    );
}
add_action( 'admin_menu', 'utm_conditional_redirects_add_settings_page' );

/**
 * Render settings page.
 */
function utm_conditional_redirects_render_settings_page() {
    if ( ! current_user_can( 'manage_options' ) ) {
        return;
    }

    $rules = utm_conditional_redirects_get_rules();
    ?>
    <div class="wrap">
        <h1>UTM Conditional Redirects</h1>
        <p>Configure per-site redirect behavior for common conditions.</p>

        <form method="post" action="options.php">
            <?php settings_fields( 'utm_conditional_redirects_group' ); ?>

            <table class="form-table" role="presentation">
                <?php utm_conditional_redirects_render_rule_row( 'frontpage', 'Front page', $rules ); ?>
                <?php utm_conditional_redirects_render_rule_row( 'login_page', 'Login page (wp-login.php)', $rules ); ?>
                <?php utm_conditional_redirects_render_rule_row( 'logged_in', 'Logged-in users', $rules ); ?>
                <?php utm_conditional_redirects_render_rule_row( 'non_logged_in', 'Non-logged-in users', $rules ); ?>
            </table>

            <?php submit_button(); ?>
        </form>
    </div>
    <?php
}

/**
 * Render a single condition row.
 *
 * @param string $key   Rule key.
 * @param string $label Human label.
 * @param array  $rules Rules array.
 */
function utm_conditional_redirects_render_rule_row( $key, $label, $rules ) {
    $enabled = ! empty( $rules[ $key ]['enabled'] ) ? 1 : 0;
    $target  = isset( $rules[ $key ]['target'] ) ? $rules[ $key ]['target'] : '';
    ?>
    <tr>
        <th scope="row"><?php echo esc_html( $label ); ?></th>
        <td>
            <label style="display:block; margin-bottom:8px;">
                <input
                    type="checkbox"
                    name="<?php echo esc_attr( UTM_CONDITIONAL_REDIRECTS_OPTION ); ?>[<?php echo esc_attr( $key ); ?>][enabled]"
                    value="1"
                    <?php checked( $enabled, 1 ); ?>
                />
                Enable redirect for this condition
            </label>

            <label>
                Target URL:
                <input
                    type="url"
                    class="regular-text"
                    name="<?php echo esc_attr( UTM_CONDITIONAL_REDIRECTS_OPTION ); ?>[<?php echo esc_attr( $key ); ?>][target]"
                    value="<?php echo esc_attr( $target ); ?>"
                    placeholder="<?php echo esc_attr( home_url( '/' ) ); ?>"
                />
            </label>
        </td>
    </tr>
    <?php
}

/**
 * Detect wp-login.php request.
 *
 * @return bool
 */
function utm_conditional_redirects_is_login_page_request() {
    $script_name = isset( $_SERVER['SCRIPT_NAME'] ) ? wp_unslash( $_SERVER['SCRIPT_NAME'] ) : '';

    return is_string( $script_name ) && false !== strpos( $script_name, 'wp-login.php' );
}

/**
 * Return current URL.
 *
 * @return string
 */
function utm_conditional_redirects_current_url() {
    $scheme = is_ssl() ? 'https' : 'http';
    $host   = isset( $_SERVER['HTTP_HOST'] ) ? wp_unslash( $_SERVER['HTTP_HOST'] ) : '';
    $uri    = isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '';

    if ( empty( $host ) ) {
        return '';
    }

    return esc_url_raw( $scheme . '://' . $host . $uri );
}

/**
 * Whether request should skip redirect handling.
 *
 * @return bool
 */
function utm_conditional_redirects_should_skip_request() {
    if ( is_admin() ) {
        return true;
    }

    if ( wp_doing_ajax() ) {
        return true;
    }

    if ( defined( 'DOING_CRON' ) && DOING_CRON ) {
        return true;
    }

    if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
        return true;
    }

    if ( defined( 'XMLRPC_REQUEST' ) && XMLRPC_REQUEST ) {
        return true;
    }

    return false;
}

/**
 * Attempt safe redirect for a condition.
 *
 * @param string $key Condition key.
 * @return bool
 */
function utm_conditional_redirects_maybe_redirect( $key ) {
    $rules = utm_conditional_redirects_get_rules();

    if ( empty( $rules[ $key ]['enabled'] ) ) {
        return false;
    }

    $target = isset( $rules[ $key ]['target'] ) ? trim( (string) $rules[ $key ]['target'] ) : '';
    if ( empty( $target ) ) {
        return false;
    }

    $target_url = wp_validate_redirect( $target, false );
    if ( false === $target_url ) {
        return false;
    }

    $current_url = utm_conditional_redirects_current_url();
    if ( ! empty( $current_url ) ) {
        if ( untrailingslashit( $current_url ) === untrailingslashit( $target_url ) ) {
            return false;
        }
    }

    wp_safe_redirect( $target_url );
    exit;
}

/**
 * Handle redirects on frontend requests.
 */
function utm_conditional_redirects_handle_frontend() {
    if ( utm_conditional_redirects_should_skip_request() ) {
        return;
    }

    if ( utm_conditional_redirects_is_login_page_request() ) {
        return;
    }

    if ( is_front_page() && utm_conditional_redirects_maybe_redirect( 'frontpage' ) ) {
        return;
    }

    if ( is_user_logged_in() ) {
        utm_conditional_redirects_maybe_redirect( 'logged_in' );
        return;
    }

    utm_conditional_redirects_maybe_redirect( 'non_logged_in' );
}
add_action( 'template_redirect', 'utm_conditional_redirects_handle_frontend', 1 );

/**
 * Handle login page redirect.
 */
function utm_conditional_redirects_handle_login_page() {
    utm_conditional_redirects_maybe_redirect( 'login_page' );
}
add_action( 'login_init', 'utm_conditional_redirects_handle_login_page', 1 );
