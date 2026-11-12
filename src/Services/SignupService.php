<?php
/**
 * Create account from the app (v1 parity P2b, D127 Q5): on a FluentCommunity site FC's own rules — is signup open,
 * its custom signup page, its fields and terms, its emailed code, its rate limit and its way of creating the user
 * (profile included). Elsewhere WordPress's: "Anyone can register", username / email / password, and our own code.
 * Only FC's public helpers are called; its files are never touched.
 */

namespace AppzaCore\Plugin\Services;

if ( ! defined( 'WPINC' ) ) {
	die;
}

class SignupService {

	const CODE_TTL      = 600;
	const CODE_ATTEMPTS = 10;
	const WP_LIMIT      = 10;
	const WP_WINDOW     = 5 * MINUTE_IN_SECONDS;

	public static function is_fc(): bool {
		return class_exists( '\\FluentCommunity\\Modules\\Auth\\AuthHelper' )
			&& class_exists( '\\FluentCommunity\\App\\Services\\AuthenticationService' );
	}

	/** open, and when not: why ('closed' | 'custom_page') and where the site's own page is. */
	public static function state(): array {
		if ( self::is_fc() ) {
			$custom = (string) \FluentCommunity\App\Services\AuthenticationService::getCustomSignupPageUrl();
			if ( '' !== $custom ) {
				return array( 'open' => false, 'reason' => 'custom_page', 'url' => $custom );
			}
			if ( ! \FluentCommunity\Modules\Auth\AuthHelper::isRegistrationEnabled() ) {
				return array( 'open' => false, 'reason' => 'closed', 'url' => '' );
			}
			return array( 'open' => true, 'reason' => '', 'url' => '' );
		}
		return get_option( 'users_can_register' )
			? array( 'open' => true, 'reason' => '', 'url' => '' )
			: array( 'open' => false, 'reason' => 'closed', 'url' => '' );
	}

	/** What the app's sheet shows: the site's own wording and fields. */
	public static function form(): array {
		$state = self::state();
		$out   = $state + array(
			'title'       => sprintf( /* translators: %s: site name */ __( 'Join %s', 'appza-core' ), get_bloginfo( 'name' ) ),
			'description' => __( 'Create an account to get started.', 'appza-core' ),
			'submit_label' => __( 'Sign up', 'appza-core' ),
			'email_code'  => true,
			'fields'      => array(),
		);

		if ( self::is_fc() ) {
			$settings = \FluentCommunity\App\Services\AuthenticationService::getAuthSettings();
			$form     = isset( $settings['signup']['form'] ) && is_array( $settings['signup']['form'] ) ? $settings['signup']['form'] : array();
			foreach ( array( 'title' => 'title', 'description' => 'description', 'button_label' => 'submit_label' ) as $from => $to ) {
				if ( ! empty( $form[ $from ] ) && is_string( $form[ $from ] ) ) {
					$out[ $to ] = wp_strip_all_tags( $form[ $from ] );
				}
			}
			$out['email_code'] = (bool) \FluentCommunity\Modules\Auth\AuthHelper::isTwoFactorEnabled();
			foreach ( self::fc_fields() as $name => $f ) {
				$out['fields'][] = self::field_out( $name, $f );
			}
			return $out;
		}

		$out['fields'] = array(
			array( 'name' => 'username', 'label' => __( 'Username', 'appza-core' ), 'type' => 'text', 'placeholder' => '', 'required' => true ),
			array( 'name' => 'email', 'label' => __( 'Email', 'appza-core' ), 'type' => 'email', 'placeholder' => '', 'required' => true ),
			array( 'name' => 'password', 'label' => __( 'Password', 'appza-core' ), 'type' => 'password', 'placeholder' => '', 'required' => true ),
		);
		return $out;
	}

	/**
	 * One call per step. No challenge: check everything, then email a code (or create at once when the site has
	 * the code off). With challenge + code: check everything again, check the code, create the user.
	 *
	 * @return array|\WP_Error  ['step' => 'code', 'challenge', 'email'] or ['step' => 'done', 'user_id', 'username']
	 */
	public static function register( array $input ) {
		$state = self::state();
		if ( ! $state['open'] ) {
			return 'custom_page' === $state['reason']
				? new \WP_Error( 'appza_signup_custom_page', __( 'This site signs people up on its own page.', 'appza-core' ), array( 'status' => 422, 'url' => $state['url'] ) )
				: new \WP_Error( 'appza_signup_closed', __( 'New sign-ups are closed on this site.', 'appza-core' ), array( 'status' => 403 ) );
		}

		// Rate limit before any "already taken" answer, so the route can't be used to find accounts.
		$limited = self::is_fc() ? \FluentCommunity\Modules\Auth\AuthHelper::isAuthRateLimit() : self::wp_rate_limit();
		if ( is_wp_error( $limited ) ) {
			return new \WP_Error( 'appza_signup_rate_limited', $limited->get_error_message(), array( 'status' => 429 ) );
		}

		$data = self::is_fc() ? self::fc_check( $input ) : self::wp_check( $input );
		if ( is_wp_error( $data ) ) {
			return $data;
		}

		$code_on   = self::is_fc() ? (bool) \FluentCommunity\Modules\Auth\AuthHelper::isTwoFactorEnabled() : true;
		$challenge = isset( $input['challenge'] ) ? (string) $input['challenge'] : '';
		if ( $code_on ) {
			if ( '' === $challenge ) {
				$sent = self::is_fc() ? self::fc_send_code( $data ) : self::wp_send_code( $data );
				if ( is_wp_error( $sent ) ) {
					return $sent;
				}
				return array( 'step' => 'code', 'challenge' => $sent, 'email' => $data['email'] );
			}
			$code = trim( (string) ( $input['code'] ?? '' ) );
			if ( '' === $code ) {
				return self::field_error( 'code', __( 'Enter the code from the email.', 'appza-core' ) );
			}
			$ok = self::is_fc()
				? \FluentCommunity\Modules\Auth\AuthHelper::validateVerificationCode( $code, $challenge, $data )
				: self::wp_check_code( $code, $challenge, $data['email'] );
			if ( is_wp_error( $ok ) ) {
				return self::field_error( 'code', $ok->get_error_message() );
			}
		}

		$user_id = self::create( $data );
		if ( is_wp_error( $user_id ) ) {
			return $user_id;
		}
		return array( 'step' => 'done', 'user_id' => (int) $user_id, 'username' => $data['username'] );
	}

	// ---- FluentCommunity ------------------------------------------------------------------------------------

	/** FC's own field list, with the site's terms setting, minus switched-off fields. */
	private static function fc_fields(): array {
		$fields   = \FluentCommunity\Modules\Auth\AuthHelper::getFormFields();
		$settings = \FluentCommunity\App\Services\AuthenticationService::getAuthSettings();
		$terms    = $settings['signup']['form']['fields']['terms'] ?? null;
		if ( $terms ) {
			$fields['terms'] = $terms;
		}
		return array_filter(
			$fields,
			static function ( $f ) {
				return is_array( $f ) && empty( $f['disabled'] );
			}
		);
	}

	private static function field_out( string $name, array $f ): array {
		$type  = (string) ( $f['type'] ?? 'text' );
		$label = (string) ( $f['label'] ?? '' );
		$link  = '';
		if ( 'inline_checkbox' === $type || 'checkbox' === $type ) {
			$html = (string) ( $f['inline_label'] ?? $label );
			if ( preg_match( '/href="([^"]+)"/', $html, $m ) ) {
				$link = esc_url_raw( html_entity_decode( $m[1] ) );
			}
			$label = trim( preg_replace( '/\s+/', ' ', wp_strip_all_tags( $html ) ) );
			$type  = 'checkbox';
		} elseif ( ! in_array( $type, array( 'text', 'email', 'password' ), true ) ) {
			$type = 'text';
		}
		return array(
			'name'        => $name,
			'label'       => '' !== $label ? $label : ucfirst( str_replace( '_', ' ', $name ) ),
			'type'        => $type,
			'placeholder' => (string) ( $f['placeholder'] ?? '' ),
			'required'    => ! empty( $f['required'] ),
			'link'        => $link,
		);
	}

	/** FC's checks and messages (Modules/Auth handleUserSignup), field by field. */
	private static function fc_check( array $input ) {
		$fields = self::fc_fields();
		$data   = array();
		foreach ( $fields as $name => $f ) {
			$v = $input[ $name ] ?? '';
			$data[ $name ] = is_bool( $v ) ? $v : ( 'password' === ( $f['type'] ?? '' ) ? (string) $v : trim( (string) $v ) );
		}
		$errors = array();

		$full = sanitize_text_field( (string) ( $data['full_name'] ?? '' ) );
		if ( isset( $fields['full_name'] ) ) {
			if ( '' === $full ) {
				$errors['full_name'] = __( 'Full name is required', 'fluent-community' );
			} elseif ( mb_strlen( $full ) > 100 ) {
				$errors['full_name'] = __( 'Please use 100 characters or fewer.', 'appza-core' );
			}
		}

		$username = (string) ( $data['username'] ?? '' );
		if ( '' === $username ) {
			$errors['username'] = __( 'Username is required', 'fluent-community' );
		} elseif ( ! preg_match( '/^[A-Za-z0-9_]+$/', $username ) ) {
			$errors['username'] = __( 'Use letters, numbers and _ only, with no spaces.', 'appza-core' );
		} else {
			$username = sanitize_user( strtolower( $username ) );
			$len      = strlen( $username );
			if ( $len < 4 || $len > 30 ) {
				$errors['username'] = __( 'Use 4 to 30 characters.', 'appza-core' );
			} elseif ( ! \FluentCommunity\App\Services\ProfileHelper::isUsernameAvailable( $username ) ) {
				$errors['username'] = __( 'Username is already taken. Please use a different username', 'fluent-community' );
			}
		}

		$email = sanitize_email( (string) ( $data['email'] ?? '' ) );
		if ( '' === (string) ( $data['email'] ?? '' ) ) {
			$errors['email'] = __( 'Email is required', 'fluent-community' );
		} elseif ( ! is_email( $email ) ) {
			$errors['email'] = __( 'Email is not valid', 'fluent-community' );
		} elseif ( email_exists( $email ) ) {
			$errors['email'] = __( 'Email is already taken', 'fluent-community' );
		}

		$password = (string) ( $data['password'] ?? '' );
		if ( '' === $password ) {
			$errors['password'] = __( 'Password is required', 'fluent-community' );
		} elseif ( strlen( $password ) > 50 ) {
			$errors['password'] = __( 'Please use 50 characters or fewer.', 'appza-core' );
		}
		if ( isset( $fields['conf_password'] ) && ! isset( $errors['password'] ) && (string) ( $data['conf_password'] ?? '' ) !== $password ) {
			$errors['conf_password'] = __( 'Password and confirmation password do not match', 'fluent-community' );
		}

		// Everything else the site asks for (terms, fields added by filter) only has to be filled in.
		foreach ( $fields as $name => $f ) {
			if ( isset( $errors[ $name ] ) || in_array( $name, array( 'full_name', 'username', 'email', 'password', 'conf_password' ), true ) ) {
				continue;
			}
			if ( ! empty( $f['required'] ) && ( true !== $data[ $name ] && ( '' === $data[ $name ] || false === $data[ $name ] ) ) ) {
				$errors[ $name ] = 'terms' === $name
					? __( 'You must agree to the terms and conditions', 'fluent-community' )
					: __( 'This field is required.', 'appza-core' );
			}
		}

		if ( $errors ) {
			return new \WP_Error( 'appza_signup_invalid', __( 'Please fill in all required fields correctly.', 'fluent-community' ), array( 'status' => 422, 'errors' => $errors ) );
		}

		$parts = explode( ' ', $full );
		return array(
			'username'   => $username,
			'email'      => $email,
			'password'   => $password,
			'first_name' => array_shift( $parts ),
			'last_name'  => implode( ' ', $parts ),
		);
	}

	/** FC's own code email; its challenge id is the hidden field of the form it returns. */
	private static function fc_send_code( array $data ) {
		$html = (string) \FluentCommunity\Modules\Auth\AuthHelper::get2FaRegistrationCodeForm( $data );
		if ( ! preg_match( '/name="__two_fa_signed_token"\s+value="([^"]+)"/', $html, $m ) ) {
			return new \WP_Error( 'appza_signup_code_failed', __( "We couldn't send the code. Please try again later.", 'appza-core' ), array( 'status' => 500 ) );
		}
		return html_entity_decode( $m[1] );
	}

	// ---- WordPress --------------------------------------------------------------------------------------------

	private static function wp_check( array $input ) {
		$errors   = array();
		$username = trim( (string) ( $input['username'] ?? '' ) );
		if ( '' === $username ) {
			$errors['username'] = __( 'Enter a username.', 'appza-core' );
		} elseif ( sanitize_user( $username, true ) !== $username || ! validate_username( $username ) ) {
			$errors['username'] = __( 'Use letters, numbers and . _ - @ only, with no spaces.', 'appza-core' );
		} elseif ( username_exists( $username ) ) {
			$errors['username'] = __( 'That username is taken. Please choose another.', 'appza-core' );
		}
		$email = sanitize_email( (string) ( $input['email'] ?? '' ) );
		if ( '' === $email || ! is_email( $email ) ) {
			$errors['email'] = __( 'Enter a valid email address.', 'appza-core' );
		} elseif ( email_exists( $email ) ) {
			$errors['email'] = __( 'That email already has an account. Try signing in.', 'appza-core' );
		}
		$password = (string) ( $input['password'] ?? '' );
		if ( '' === $password ) {
			$errors['password'] = __( 'Enter a password.', 'appza-core' );
		}
		if ( $errors ) {
			return new \WP_Error( 'appza_signup_invalid', __( 'Please fix the fields below.', 'appza-core' ), array( 'status' => 422, 'errors' => $errors ) );
		}
		return array( 'username' => $username, 'email' => $email, 'password' => $password, 'first_name' => '', 'last_name' => '' );
	}

	private static function wp_rate_limit() {
		$ip  = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : 'unknown';
		$key = 'appza_signup_rl_' . md5( $ip );
		$n   = (int) get_transient( $key );
		if ( $n >= self::WP_LIMIT ) {
			return new \WP_Error( 'rate_limit', __( 'Too many requests. Please wait a few minutes and try again.', 'appza-core' ) );
		}
		set_transient( $key, $n + 1, self::WP_WINDOW );
		return true;
	}

	/** Same shape as FC's: only a hash is kept, 10 minutes, 10 wrong tries burn it, single use. */
	private static function wp_send_code( array $data ) {
		$code  = str_pad( (string) random_int( 0, 999999 ), 6, '0', STR_PAD_LEFT );
		$token = 'apz_' . wp_generate_password( 40, false );
		set_transient(
			'appza_signup_code_' . $token,
			array( 'email' => $data['email'], 'hash' => wp_hash_password( $code ), 'attempts' => 0, 'expires' => time() + self::CODE_TTL ),
			self::CODE_TTL
		);
		$site = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
		wp_mail(
			$data['email'],
			/* translators: %s: site name */
			sprintf( __( 'Your sign-up code for %s', 'appza-core' ), $site ),
			/* translators: %s: the code */
			sprintf( __( "Your code is %s. It works for 10 minutes.\n\nIf you didn't try to sign up, ignore this email.", 'appza-core' ), $code )
		);
		return $token;
	}

	private static function wp_check_code( string $code, string $token, string $email ) {
		$key = 'appza_signup_code_' . $token;
		$c   = get_transient( $key );
		if ( ! is_array( $c ) || (int) $c['expires'] < time() || $c['email'] !== $email ) {
			delete_transient( $key );
			return new \WP_Error( 'expired', __( 'That code has expired. Go back and send a new one.', 'appza-core' ) );
		}
		if ( (int) $c['attempts'] >= self::CODE_ATTEMPTS ) {
			delete_transient( $key );
			return new \WP_Error( 'too_many', __( 'Too many wrong codes. Go back and send a new one.', 'appza-core' ) );
		}
		if ( ! wp_check_password( $code, $c['hash'] ) ) {
			$c['attempts'] = (int) $c['attempts'] + 1;
			set_transient( $key, $c, max( 1, (int) $c['expires'] - time() ) );
			return new \WP_Error( 'wrong', __( "That code isn't right. Please check the email and try again.", 'appza-core' ) );
		}
		delete_transient( $key );
		return true;
	}

	// ---- both ------------------------------------------------------------------------------------------------

	private static function create( array $data ) {
		$extra = array( 'first_name' => $data['first_name'], 'last_name' => $data['last_name'], 'role' => get_option( 'default_role', 'subscriber' ) );
		if ( self::is_fc() ) {
			$id = \FluentCommunity\Modules\Auth\AuthHelper::registerNewUser( $data['username'], $data['email'], $data['password'], $extra );
			if ( is_wp_error( $id ) ) {
				return new \WP_Error( 'appza_signup_failed', wp_strip_all_tags( $id->get_error_message() ), array( 'status' => 422 ) );
			}
			// What FC does after its own signup: the community profile.
			$user = \FluentCommunity\App\Models\User::find( $id );
			if ( $user ) {
				$user->syncXProfile( true, true );
			}
			return $id;
		}
		$id = wp_insert_user(
			array(
				'user_login' => $data['username'],
				'user_email' => $data['email'],
				'user_pass'  => $data['password'],
				'role'       => $extra['role'],
			)
		);
		if ( is_wp_error( $id ) ) {
			return new \WP_Error( 'appza_signup_failed', $id->get_error_message(), array( 'status' => 422 ) );
		}
		do_action( 'register_new_user', $id ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
		return $id;
	}

	private static function field_error( string $field, string $message ): \WP_Error {
		return new \WP_Error( 'appza_signup_invalid', $message, array( 'status' => 422, 'errors' => array( $field => $message ) ) );
	}
}
