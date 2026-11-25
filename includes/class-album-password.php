<?php
declare(strict_types=1);

namespace BazantikPhotoGallery;

defined( 'ABSPATH' ) || exit;

/**
 * Odemykání zaheslovaných alb vlastní cestou (admin-post.php) a vlastní podepsanou cookie.
 *
 * Důvod: formulář jádra míří na wp-login.php?action=postpass, což ochrana hostingu (WEDOS.protection) může
 * zachytit a zobrazit ověřovací stránku. Podpis cookie navíc zahrnuje heslo, takže se změnou hesla ztratí platnost,
 * a cookie jádra (wp-postpass_HASH) album už neodemyká.
 */
class Album_Password {
	private const COOKIE_DAYS = 10;
	private const ACTION      = 'bazantik_album_unlock';

	public function init(): void {
		add_action( 'admin_post_nopriv_' . self::ACTION, [ $this, 'handle_unlock' ] );
		add_action( 'admin_post_' . self::ACTION, [ $this, 'handle_unlock' ] );
		add_filter( 'post_password_required', [ $this, 'filter_required' ], 10, 2 );
		add_filter( 'the_password_form', [ $this, 'render_form' ], 20, 2 );
		add_filter( 'protected_title_format', [ $this, 'title_format' ], 10, 2 );
	}

	/** Předpona „wp-postpass_“ je nutná: cache hostingu (WEDOS) zahazuje neznámé cookies a tuto propouští. */
	private static function cookie_name( int $album_id ): string {
		return 'wp-postpass_bazantik_album_' . $album_id;
	}

	/** Podepsaná hodnota „expirace.podpis“; podpis pokrývá album, aktuální heslo i expiraci. */
	private static function cookie_value( \WP_Post $post, int $expires ): string {
		return $expires . '.' . hash_hmac( 'sha256', $post->ID . '|' . $post->post_password . '|' . $expires, wp_salt( 'auth' ) );
	}

	public static function is_unlocked( \WP_Post $post ): bool {
		$name = self::cookie_name( (int) $post->ID );
		if ( '' === (string) $post->post_password || empty( $_COOKIE[ $name ] ) ) {
			return false;
		}
		$given = wp_unslash( (string) $_COOKIE[ $name ] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- hodnota se přísně ověří regulárním výrazem a podpisem
		if ( ! preg_match( '/^(\d{9,12})\.[a-f0-9]{64}$/', $given, $m ) || (int) $m[1] < time() ) {
			return false;
		}
		return hash_equals( self::cookie_value( $post, (int) $m[1] ), $given );
	}

	/**
	 * Zaheslované album odemyká jen vlastní cookie (cookie jádra se ignoruje).
	 *
	 * @param bool              $required Výchozí rozhodnutí jádra.
	 * @param \WP_Post|int|null $post     Příspěvek.
	 */
	public function filter_required( $required, $post ): bool {
		$post = get_post( $post );
		if ( ! $post || 'bazantik_album' !== $post->post_type || '' === (string) $post->post_password ) {
			return (bool) $required;
		}
		return ! self::is_unlocked( $post );
	}

	/** Odemčené album nemá mít v názvu „Protected:“. */
	public function title_format( $format, $post = null ): string {
		$post = get_post( $post );
		return ( $post && 'bazantik_album' === $post->post_type ) ? '%s' : (string) $format;
	}

	private static function current_url(): string {
		$home = (array) wp_parse_url( home_url() );
		$uri  = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- prochází esc_url_raw
		if ( empty( $home['host'] ) || '' === $uri ) {
			return '';
		}
		$url = ( $home['scheme'] ?? 'https' ) . '://' . $home['host'] . ( isset( $home['port'] ) ? ':' . $home['port'] : '' ) . $uri;
		return esc_url_raw( remove_query_arg( 'bazantik_album_error', $url ) );
	}

	/**
	 * Formulář hesla alba (nahrazuje formulář jádra).
	 *
	 * @param string            $output Výstup jádra.
	 * @param \WP_Post|int|null $post   Příspěvek.
	 */
	public function render_form( $output, $post = null ): string {
		$post = get_post( $post );
		if ( ! $post || 'bazantik_album' !== $post->post_type ) {
			return (string) $output;
		}

		$error = isset( $_GET['bazantik_album_error'] ) ? absint( $_GET['bazantik_album_error'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- jen výběr zprávy
		$msgs  = [
			1 => __( 'Incorrect password.', 'bazantik-photo-gallery' ),
			2 => __( 'Too many incorrect attempts. Please try again in a few minutes.', 'bazantik-photo-gallery' ),
			3 => __( 'The form has expired. Please try again.', 'bazantik-photo-gallery' ),
		];
		$id    = 'bazantik-album-pw-' . (int) $post->ID;

		$html  = '<form class="bazantik-pw-form" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		$html .= '<input type="hidden" name="action" value="' . esc_attr( self::ACTION ) . '">'
			. '<input type="hidden" name="bazantik_album_id" value="' . esc_attr( (string) $post->ID ) . '">'
			. '<input type="hidden" name="_bazantik_nonce" value="' . esc_attr( wp_create_nonce( 'bazantik_album_unlock_' . $post->ID ) ) . '">'
			. '<input type="hidden" name="redirect_to" value="' . esc_attr( self::current_url() ) . '">';
		if ( isset( $msgs[ $error ] ) ) {
			$html .= '<div class="post-password-form-invalid-password" role="alert"><p>' . esc_html( $msgs[ $error ] ) . '</p></div>';
		}
		$html .= '<p class="bazantik-pw-intro">' . esc_html__( 'This album is password protected. Enter the password to view the photos and videos.', 'bazantik-photo-gallery' ) . '</p>'
			. '<p><label for="' . esc_attr( $id ) . '" class="screen-reader-text">' . esc_html__( 'Password', 'bazantik-photo-gallery' ) . '</label>'
			. Password_Field::input( 'bazantik_album_password', [ 'id' => $id, 'autocomplete' => 'current-password', 'required' => true ] )
			. ' <input type="submit" value="' . esc_attr__( 'Unlock', 'bazantik-photo-gallery' ) . '"></p></form>';
		return $html;
	}

	public function handle_unlock(): void {
		$album_id = isset( $_POST['bazantik_album_id'] ) ? absint( $_POST['bazantik_album_id'] ) : 0;
		$post     = $album_id ? get_post( $album_id ) : null;
		if ( ! $post instanceof \WP_Post || 'bazantik_album' !== $post->post_type || 'publish' !== $post->post_status || '' === (string) $post->post_password ) {
			Password_Field::respond( home_url( '/' ) );
			exit;
		}

		$permalink = (string) get_permalink( $post );
		$redirect  = isset( $_POST['redirect_to'] ) ? esc_url_raw( wp_unslash( $_POST['redirect_to'] ) ) : '';
		$back      = remove_query_arg( 'bazantik_album_error', (string) wp_validate_redirect( $redirect, $permalink ) ?: $permalink );

		$nonce = isset( $_POST['_bazantik_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['_bazantik_nonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, 'bazantik_album_unlock_' . $album_id ) ) {
			Password_Field::respond( add_query_arg( 'bazantik_album_error', '3', $back ) );
			exit;
		}

		$limit    = Settings::throttle_enabled();
		$tries_k  = Settings::throttle_key( 'bazantik_al_', $album_id );
		$password = isset( $_POST['bazantik_album_password'] ) && is_string( $_POST['bazantik_album_password'] ) ? trim( (string) wp_unslash( $_POST['bazantik_album_password'] ) ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- heslo se nesmí upravovat

		// Pokus se započítá atomicky před ověřením hesla (prázdný pokus se nepočítá); úspěch čítač vynuluje
		if ( $limit && '' !== $password && ! Settings::reserve_attempt( $tries_k ) ) {
			Password_Field::respond( add_query_arg( 'bazantik_album_error', '2', $back ) );
			exit;
		}

		if ( '' === $password || ! hash_equals( (string) $post->post_password, $password ) ) {
			Password_Field::respond( add_query_arg( 'bazantik_album_error', '1', $back ) );
			exit;
		}

		delete_transient( $tries_k );
		$expires = time() + self::COOKIE_DAYS * DAY_IN_SECONDS;
		setcookie(
			self::cookie_name( $album_id ),
			self::cookie_value( $post, $expires ),
			[
				'expires'  => $expires,
				'path'     => COOKIEPATH,
				'domain'   => COOKIE_DOMAIN ?: '',
				'secure'   => is_ssl(),
				'httponly' => true,
				'samesite' => 'Lax',
			]
		);
		Password_Field::respond( $back );
		exit;
	}
}
