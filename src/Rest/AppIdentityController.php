<?php
/**
 * The app's identity (v1 parity P1c): its name, icon and splash.
 *
 *   GET  /wp-json/appza/v1/app-identity
 *   POST /wp-json/appza/v1/app-identity   {name, icon_id, splash_id, splash_background}
 *
 * Stored in one option. The icon and the splash image are media-library ids, so the files live in WordPress like
 * any upload. Saved now, used by the customer's own app builds (P7): test builds are always "Appza QA".
 * The builder's one Save (`builder/save`) writes it too, through check() and write().
 */

namespace AppzaCore\Plugin\Rest;

if ( ! defined( 'WPINC' ) ) {
	die;
}

class AppIdentityController {

	const OPTION_KEY         = 'appza_core_app_identity';
	const NAME_MAX           = 30;
	const MAX_BYTES          = 2097152;
	const ICON_SIZE          = 1024;
	const SPLASH_MIN         = 512;
	const SPLASH_MAX         = 2732;
	const DEFAULT_BACKGROUND = '#FFFFFF';

	public function permission_check() {
		return current_user_can( 'manage_options' );
	}

	/** The stored identity with each image's address, as the builder reads it. */
	public static function current() {
		$raw = get_option( self::OPTION_KEY, array() );
		$raw = is_array( $raw ) ? $raw : array();
		$out = array(
			'name'              => isset( $raw['name'] ) && is_string( $raw['name'] ) ? $raw['name'] : '',
			'icon_id'           => isset( $raw['icon_id'] ) ? (int) $raw['icon_id'] : 0,
			'splash_id'         => isset( $raw['splash_id'] ) ? (int) $raw['splash_id'] : 0,
			'splash_background' => isset( $raw['splash_background'] ) && is_string( $raw['splash_background'] ) ? $raw['splash_background'] : self::DEFAULT_BACKGROUND,
			'saved'             => ! empty( $raw ),
		);
		$out['icon_url']   = self::image_url( $out['icon_id'] );
		$out['splash_url'] = self::image_url( $out['splash_id'] );
		// An image deleted from the media library since is no longer part of the app.
		if ( '' === $out['icon_url'] ) {
			$out['icon_id'] = 0;
		}
		if ( '' === $out['splash_url'] ) {
			$out['splash_id'] = 0;
		}
		return $out;
	}

	public function get( \WP_REST_Request $request ) {
		return rest_ensure_response( self::current() );
	}

	public function save( \WP_REST_Request $request ) {
		$body = $request->get_json_params();
		if ( ! is_array( $body ) ) {
			$body = $request->get_params();
		}
		list( $clean, $errors ) = self::check( $body );
		if ( $errors ) {
			return new \WP_Error(
				'appza_app_identity_invalid',
				__( 'Some of your app\'s details are not valid. Nothing was saved.', 'appza-core' ),
				array( 'status' => 422, 'errors' => $errors )
			);
		}
		self::write( $clean );
		return rest_ensure_response( self::current() );
	}

	/**
	 * Every field is checked; each problem is named in plain words, keyed by field.
	 *
	 * @return array{0: array, 1: array<string,string>}
	 */
	public static function check( $raw ) {
		if ( ! is_array( $raw ) ) {
			return array( array(), array( 'identity' => 'must be an object' ) );
		}
		$errors = array();

		$name = trim( (string) ( $raw['name'] ?? '' ) );
		$len  = function_exists( 'mb_strlen' ) ? mb_strlen( $name ) : strlen( $name );
		if ( 0 === $len || $len > self::NAME_MAX ) {
			$errors['name'] = sprintf( 'The app name needs 1 to %d characters.', self::NAME_MAX );
		} elseif ( $name !== sanitize_text_field( $name ) ) {
			$errors['name'] = 'The app name can only be plain text.';
		}

		$background = strtoupper( trim( (string) ( $raw['splash_background'] ?? self::DEFAULT_BACKGROUND ) ) );
		if ( ! preg_match( '/^#[0-9A-F]{6}$/', $background ) ) {
			$errors['splash_background'] = 'The splash background must be a colour like #1A73E8.';
		}

		$icon_id = self::id_of( $raw['icon_id'] ?? 0 );
		if ( null === $icon_id ) {
			$errors['icon_id'] = 'The icon must be an image from the media library.';
		} elseif ( $icon_id ) {
			$problem = self::check_icon( $icon_id );
			if ( $problem ) {
				$errors['icon_id'] = $problem;
			}
		}

		$splash_id = self::id_of( $raw['splash_id'] ?? 0 );
		if ( null === $splash_id ) {
			$errors['splash_id'] = 'The splash image must be an image from the media library.';
		} elseif ( $splash_id ) {
			$problem = self::check_splash( $splash_id );
			if ( $problem ) {
				$errors['splash_id'] = $problem;
			}
		}

		$clean = array(
			'name'              => $name,
			'icon_id'           => (int) $icon_id,
			'splash_id'         => (int) $splash_id,
			'splash_background' => $background,
		);
		return array( $clean, $errors );
	}

	/** Writes a checked identity. Returns false only when the database refused it. */
	public static function write( array $clean ) {
		global $wpdb;
		update_option( self::OPTION_KEY, $clean, false );
		return '' === $wpdb->last_error;
	}

	/** 0 for none, a positive id, or null when it isn't a number. */
	protected static function id_of( $value ) {
		if ( null === $value || '' === $value || 0 === $value || '0' === $value ) {
			return 0;
		}
		if ( is_int( $value ) && $value > 0 ) {
			return $value;
		}
		if ( is_string( $value ) && ctype_digit( $value ) ) {
			return (int) $value;
		}
		return null;
	}

	protected static function image_url( $id ) {
		if ( ! $id || 'attachment' !== get_post_type( $id ) ) {
			return '';
		}
		$url = wp_get_attachment_url( $id );
		return $url ? esc_url_raw( $url ) : '';
	}

	/** @return array{0: string, 1: int, 2: int}|string The file, width, height; or what's wrong. */
	protected static function png_file( $id, $what ) {
		if ( 'attachment' !== get_post_type( $id ) ) {
			return "The {$what} is no longer in the media library. Pick it again.";
		}
		$file = get_attached_file( $id );
		if ( ! $file || ! is_readable( $file ) ) {
			return "The {$what} file is missing from the media library. Upload it again.";
		}
		if ( 'image/png' !== get_post_mime_type( $id ) ) {
			return "The {$what} must be a PNG image.";
		}
		if ( filesize( $file ) > self::MAX_BYTES ) {
			return "The {$what} must be 2 MB or smaller.";
		}
		$size = @getimagesize( $file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		if ( ! $size || IMAGETYPE_PNG !== $size[2] ) {
			return "The {$what} must be a PNG image.";
		}
		return array( $file, (int) $size[0], (int) $size[1] );
	}

	protected static function check_icon( $id ) {
		$png = self::png_file( $id, 'icon' );
		if ( is_string( $png ) ) {
			return $png;
		}
		list( $file, $w, $h ) = $png;
		if ( self::ICON_SIZE !== $w || self::ICON_SIZE !== $h ) {
			return sprintf( 'The icon must be %1$d × %1$d pixels (this one is %2$d × %3$d).', self::ICON_SIZE, $w, $h );
		}
		if ( self::has_transparency( $file ) ) {
			return 'The icon can\'t have see-through parts: the app stores refuse it. Fill the background.';
		}
		return '';
	}

	protected static function check_splash( $id ) {
		$png = self::png_file( $id, 'splash image' );
		if ( is_string( $png ) ) {
			return $png;
		}
		list( , $w, $h ) = $png;
		if ( $w !== $h ) {
			return sprintf( 'The splash image must be square (this one is %d × %d).', $w, $h );
		}
		if ( $w < self::SPLASH_MIN || $w > self::SPLASH_MAX ) {
			return sprintf( 'The splash image must be %d to %d pixels wide (this one is %d).', self::SPLASH_MIN, self::SPLASH_MAX, $w );
		}
		return '';
	}

	/**
	 * Whether any pixel is see-through. The PNG header says when there can't be any (no alpha channel, no tRNS
	 * chunk); otherwise every pixel is read with GD.
	 */
	protected static function has_transparency( $file ) {
		$head = (string) file_get_contents( $file, false, null, 0, 33 );
		if ( strlen( $head ) < 26 ) {
			return true;
		}
		$color_type = ord( $head[25] );
		$has_alpha  = 4 === $color_type || 6 === $color_type;
		if ( ! $has_alpha && false === strpos( (string) file_get_contents( $file ), 'tRNS' ) ) {
			return false;
		}
		if ( ! function_exists( 'imagecreatefrompng' ) ) {
			// Can't look inside: an alpha channel may still be fully opaque, so only refuse a declared one.
			return true;
		}
		$img = @imagecreatefrompng( $file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		if ( ! $img ) {
			return true;
		}
		$w = imagesx( $img );
		$h = imagesy( $img );
		for ( $y = 0; $y < $h; $y++ ) {
			for ( $x = 0; $x < $w; $x++ ) {
				$rgba = imagecolorat( $img, $x, $y );
				$alpha = imageistruecolor( $img ) ? ( $rgba >> 24 ) & 0x7F : imagecolorsforindex( $img, $rgba )['alpha'];
				if ( $alpha > 0 ) {
					return true;
				}
			}
		}
		return false;
	}
}
