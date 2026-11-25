<?php
declare(strict_types=1);

namespace BazantikPhotoGallery;

defined( 'ABSPATH' ) || exit;

/**
 * Zobrazení uloženého hesla alba nebo složky v administraci (tlačítko oko) a ochrana hesla alba před přepsáním.
 *
 * Pole pro heslo se při načtení stránky nevyplňuje (správce hesel v prohlížeči pak nemá co nabízet k uložení);
 * heslo se na požádání načte přes AJAX (oprávnění a nonce). Heslo složky se kromě hashe pro ověření ukládá
 * i šifrovaně (AES-256-GCM, klíč se odvozuje z klíčů webu v wp-config.php), aby šlo zobrazit.
 */
class Password_Reveal {
	public const ACTION   = 'bazantik_reveal_password';
	public const META_ENC = '_bazantik_folder_pwenc';

	public function init(): void {
		add_action( 'wp_ajax_' . self::ACTION, [ $this, 'handle' ] );
		add_filter( 'wp_insert_post_data', [ $this, 'keep_album_password' ], 10, 2 );
	}

	public static function nonce(): string {
		return wp_create_nonce( self::ACTION );
	}

	public static function can_encrypt(): bool {
		return function_exists( 'openssl_encrypt' ) && in_array( 'aes-256-gcm', openssl_get_cipher_methods(), true );
	}

	/**
	 * Formát v2: "v2:" + base64( IV | tag | šifra ); klíč se odvozuje přes HKDF a záznam je přes AAD svázaný
	 * s ID složky (zkopírovaný záznam u jiné složky se nedešifruje). Starší záznamy (bez předpony) se čtou dál.
	 */
	private const V2 = 'v2:';

	private static function key_v2(): string {
		return hash_hkdf( 'sha256', wp_salt( 'secure_auth' ), 32, 'bazantik-folder-password' );
	}

	public static function encrypt( string $plain, int $term_id ): string {
		if ( '' === $plain || ! self::can_encrypt() ) {
			return '';
		}
		$iv     = random_bytes( 12 );
		$tag    = '';
		$cipher = openssl_encrypt( $plain, 'aes-256-gcm', self::key_v2(), OPENSSL_RAW_DATA, $iv, $tag, 'folder:' . $term_id );
		return false === $cipher ? '' : self::V2 . base64_encode( $iv . $tag . $cipher ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- uložení binárních dat, ne zakrývání kódu
	}

	public static function decrypt( string $stored, int $term_id ): string {
		if ( '' === $stored || ! self::can_encrypt() ) {
			return '';
		}
		$v2  = 0 === strpos( $stored, self::V2 );
		$raw = base64_decode( $v2 ? substr( $stored, strlen( self::V2 ) ) : $stored, true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- čtení vlastních binárních dat
		if ( false === $raw || strlen( $raw ) < 29 ) {
			return '';
		}
		$key   = $v2 ? self::key_v2() : hash( 'sha256', wp_salt( 'secure_auth' ), true );
		$aad   = $v2 ? 'folder:' . $term_id : '';
		$plain = openssl_decrypt( substr( $raw, 28 ), 'aes-256-gcm', $key, OPENSSL_RAW_DATA, substr( $raw, 0, 12 ), substr( $raw, 12, 16 ), $aad );
		return false === $plain ? '' : $plain;
	}

	/** Uloží zašifrované heslo složky (nebo starý záznam smaže, když šifrování není k dispozici). */
	public static function store_folder_password( int $term_id, string $password ): void {
		$enc = self::encrypt( $password, $term_id );
		if ( '' === $enc ) {
			delete_term_meta( $term_id, self::META_ENC );
			return;
		}
		update_term_meta( $term_id, self::META_ENC, $enc );
	}

	public function handle(): void {
		check_ajax_referer( self::ACTION, '_wpnonce' );
		$type = isset( $_POST['type'] ) ? sanitize_key( wp_unslash( $_POST['type'] ) ) : '';
		$id   = isset( $_POST['id'] ) ? absint( wp_unslash( $_POST['id'] ) ) : 0;

		$password = '';
		if ( 'album' === $type && $id > 0 && 'bazantik_album' === get_post_type( $id ) && current_user_can( 'edit_post', $id ) ) {
			$password = (string) get_post_field( 'post_password', $id, 'raw' );
		} elseif ( 'folder' === $type && $id > 0 && term_exists( $id, Folder_Access::TAX ) && current_user_can( 'edit_term', $id ) && 'password' === Folder_Access::visibility( $id ) ) {
			$stored   = (string) get_term_meta( $id, self::META_ENC, true );
			$password = self::decrypt( $stored, $id );
			if ( '' !== $password && 0 !== strpos( $stored, self::V2 ) ) {
				self::store_folder_password( $id, $password ); // starý záznam se potichu převede na nový formát
			}
		} else {
			wp_send_json_error( null, 403 );
		}

		nocache_headers();
		if ( '' === $password ) {
			wp_send_json_error( null, 404 );
		}
		wp_send_json_success( [ 'password' => $password ] );
	}

	/**
	 * Jádro při uložení alba přepisuje heslo hodnotou z nativního pole (v Quick Editu bývá prázdné). O heslu
	 * rozhoduje formulář pluginu, proto se při jeho uložení vrátí dosavadní heslo a plugin pak nastaví výsledné.
	 *
	 * @param array<string,mixed> $data
	 * @param array<string,mixed> $postarr
	 * @return array<string,mixed>
	 */
	public function keep_album_password( array $data, array $postarr ): array {
		if ( 'bazantik_album' !== ( $data['post_type'] ?? '' ) || empty( $postarr['ID'] ) ) {
			return $data;
		}
		$editor = isset( $_POST['bazantik_album_nonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['bazantik_album_nonce'] ) ), 'bazantik_save_album' ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce se ověřuje zde
		$quick  = isset( $_POST['bazantik_qe_nonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['bazantik_qe_nonce'] ) ), 'bazantik_album_qe' ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce se ověřuje zde
		if ( $editor || $quick ) {
			$data['post_password'] = wp_slash( (string) get_post_field( 'post_password', (int) $postarr['ID'], 'raw' ) );
		}
		return $data;
	}
}
