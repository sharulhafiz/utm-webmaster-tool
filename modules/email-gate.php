<?php
/**
 * Per-site Email Gate module.
 *
 * This module restricts login and registration attempts to configured email
 * domains while leaving super admins exempt.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Option key for email gate settings.
 */
define( 'UTM_EMAIL_GATE_OPTION', 'utm_email_gate_settings' );

/**
 * Default settings.
 *
 * @return array
 */
function utm_email_gate_default_settings() {
	return array(
		'enabled'              => 0,
		'allowed_domains'      => '',
		'block_registration'   => 1,
	);
}

/**
 * Load settings merged with defaults.
 *
 * @return array
 */
function utm_email_gate_get_settings() {
	$settings = get_option( UTM_EMAIL_GATE_OPTION, array() );

	if ( ! is_array( $settings ) ) {
		$settings = array();
	}

	return array_merge( utm_email_gate_default_settings(), $settings );
}

/**
 * Normalize a comma/space separated domain list.
 *
 * @param string $raw_domains Raw user input.
 * @return array
 */
function utm_email_gate_parse_domains( $raw_domains ) {
	$raw_domains = strtolower( (string) $raw_domains );
	$parts = preg_split( '/[\s,]+/', $raw_domains );

	if ( ! is_array( $parts ) ) {
		return array();
	}

	$domains = array();
	foreach ( $parts as $part ) {
		$part = trim( $part );
		if ( '' === $part ) {
			continue;
		}
		$part = ltrim( $part, '@' );
		if ( '' === $part ) {
			continue;
		}
		$domains[] = $part;
	}

	return array_values( array_unique( $domains ) );
}

/**
 * Get allowed domains, falling back to SSO domains or utm.my.
 *
 * @return array
 */
function utm_email_gate_get_allowed_domains() {
	$settings = utm_email_gate_get_settings();
	$domains  = utm_email_gate_parse_domains( $settings['allowed_domains'] );

	if ( empty( $domains ) ) {
		$fallback = get_option( 'sso_allowed_domains', 'utm.my' );
		$domains  = utm_email_gate_parse_domains( $fallback );
	}

	if ( empty( $domains ) ) {
		$domains = array( 'utm.my' );
	}

	return $domains;
}

/**
 * Check whether an email address matches the allowlist.
 *
 * @param string $email Email address.
 * @param array  $allowed_domains Allowed domains.
 * @return bool
 */
function utm_email_gate_is_allowed_email( $email, $allowed_domains ) {
	$email = sanitize_email( $email );
	if ( empty( $email ) || false === strpos( $email, '@' ) ) {
		return false;
	}

	$email_domain = strtolower( substr( strrchr( $email, '@' ), 1 ) );
	if ( empty( $email_domain ) ) {
		return false;
	}

	foreach ( $allowed_domains as $allowed_domain ) {
		$allowed_domain = strtolower( trim( (string) $allowed_domain ) );
		if ( '' === $allowed_domain ) {
			continue;
		}

		if ( $email_domain === $allowed_domain ) {
			return true;
		}

		if ( substr( $email_domain, -strlen( '.' . $allowed_domain ) ) === '.' . $allowed_domain ) {
			return true;
		}
	}

	return false;
}

/**
 * Register settings.
 *
 * @return void
 */
function utm_email_gate_register_settings() {
	register_setting(
		'utm_email_gate_group',
		UTM_EMAIL_GATE_OPTION,
		array(
			'type'              => 'array',
			'sanitize_callback' => 'utm_email_gate_sanitize_settings',
			'default'           => utm_email_gate_default_settings(),
		)
	);
}
add_action( 'admin_init', 'utm_email_gate_register_settings' );

/**
 * Sanitize settings.
 *
 * @param mixed $input Raw input.
 * @return array
 */
function utm_email_gate_sanitize_settings( $input ) {
	$defaults = utm_email_gate_default_settings();

	if ( ! is_array( $input ) ) {
		return $defaults;
	}

	return array(
		'enabled'            => ! empty( $input['enabled'] ) ? 1 : 0,
		'allowed_domains'    => isset( $input['allowed_domains'] ) ? sanitize_text_field( $input['allowed_domains'] ) : '',
		'block_registration' => ! empty( $input['block_registration'] ) ? 1 : 0,
	);
}

/**
 * Add settings page.
 *
 * @return void
 */
function utm_email_gate_add_settings_page() {
	add_options_page(
		'Email Gate',
		'Email Gate',
		'manage_options',
		'utm-email-gate',
		'utm_email_gate_render_settings_page'
	);
}
add_action( 'admin_menu', 'utm_email_gate_add_settings_page' );

/**
 * Render settings UI.
 *
 * @return void
 */
function utm_email_gate_render_settings_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	$settings = utm_email_gate_get_settings();
	$allowed_domains_value = $settings['allowed_domains'];
	if ( '' === trim( $allowed_domains_value ) ) {
		$allowed_domains_value = implode( ', ', utm_email_gate_get_allowed_domains() );
	}
	?>
	<div class="wrap">
		<h1>Email Gate</h1>
		<p>Restrict login and registration to approved email domains on this site.</p>

		<form method="post" action="options.php">
			<?php settings_fields( 'utm_email_gate_group' ); ?>

			<table class="form-table" role="presentation">
				<tr>
					<th scope="row">Enable email gate</th>
					<td>
						<label>
							<input type="checkbox" name="<?php echo esc_attr( UTM_EMAIL_GATE_OPTION ); ?>[enabled]" value="1" <?php checked( ! empty( $settings['enabled'] ), 1 ); ?> />
							Enforce allowlisted email domains on login
						</label>
					</td>
				</tr>
				<tr>
					<th scope="row">Allowed domains</th>
					<td>
						<input
							type="text"
							class="regular-text"
							name="<?php echo esc_attr( UTM_EMAIL_GATE_OPTION ); ?>[allowed_domains]"
							value="<?php echo esc_attr( $allowed_domains_value ); ?>"
							placeholder="utm.my, utm.my.my"
						/>
						<p class="description">Comma-separated domains. Subdomains are allowed automatically.</p>
					</td>
				</tr>
				<tr>
					<th scope="row">Block registration</th>
					<td>
						<label>
							<input type="checkbox" name="<?php echo esc_attr( UTM_EMAIL_GATE_OPTION ); ?>[block_registration]" value="1" <?php checked( ! empty( $settings['block_registration'] ), 1 ); ?> />
							Reject registration attempts from disallowed domains
						</label>
					</td>
				</tr>
			</table>

			<?php submit_button(); ?>
		</form>
	</div>
	<?php
}

/**
 * Validate email domain on login.
 *
 * @param WP_User|WP_Error|null $user User object from previous auth step.
 * @param string                $username Username or email.
 * @param string                $password Password.
 * @return WP_User|WP_Error|null
 */
function utm_email_gate_authenticate( $user, $username, $password ) {
	if ( is_wp_error( $user ) || empty( $username ) ) {
		return $user;
	}

	$settings = utm_email_gate_get_settings();
	if ( empty( $settings['enabled'] ) ) {
		return $user;
	}

	if ( false === strpos( (string) $username, '@' ) ) {
		return $user;
	}

	$email = sanitize_email( $username );
	if ( empty( $email ) ) {
		return new WP_Error(
			'utm_email_gate_invalid_email',
			__( 'Please enter a valid email address.', 'utm-webmaster' )
		);
	}

	$existing_user = get_user_by( 'email', $email );
	if ( $existing_user && is_super_admin( $existing_user->ID ) ) {
		return $user;
	}

	if ( utm_email_gate_is_allowed_email( $email, utm_email_gate_get_allowed_domains() ) ) {
		return $user;
	}

	return new WP_Error(
		'utm_email_gate_blocked',
		sprintf(
			/* translators: %s: allowed domains list */
			__( 'Login is restricted to these email domains: %s', 'utm-webmaster' ),
			implode( ', ', utm_email_gate_get_allowed_domains() )
		)
	);
}
add_filter( 'authenticate', 'utm_email_gate_authenticate', 30, 3 );

/**
 * Validate email domain on registration.
 *
 * @param WP_Error $errors Errors object.
 * @param string    $sanitized_user_login Sanitized login.
 * @param string    $user_email Email address.
 * @return WP_Error
 */
function utm_email_gate_registration_errors( $errors, $sanitized_user_login, $user_email ) {
	$settings = utm_email_gate_get_settings();

	if ( empty( $settings['enabled'] ) || empty( $settings['block_registration'] ) ) {
		return $errors;
	}

	if ( utm_email_gate_is_allowed_email( $user_email, utm_email_gate_get_allowed_domains() ) ) {
		return $errors;
	}

	$errors->add(
		'utm_email_gate_registration_blocked',
		sprintf(
			__( 'Registration is restricted to these email domains: %s', 'utm-webmaster' ),
			implode( ', ', utm_email_gate_get_allowed_domains() )
		)
	);

	return $errors;
}
add_filter( 'registration_errors', 'utm_email_gate_registration_errors', 10, 3 );
