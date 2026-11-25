<?php
declare(strict_types=1);

namespace BazantikPhotoGallery;

defined( 'ABSPATH' ) || exit;

/**
 * Soukromí složek alb (taxonomie bazantik_folder): veřejná / skrytá (odkaz s klíčem) / zaheslovaná.
 * Klíč nebo heslo chrání jen samotnou složku (výpis jejích alb a podsložek), ne alba v ní; každé album a podsložka má vlastní nastavení.
 */
class Folder_Access {
	public const TAX        = 'bazantik_folder';
	public const META_VIS   = '_bazantik_folder_visibility';
	public const META_TOKEN = '_bazantik_folder_token';
	public const META_HASH  = '_bazantik_folder_pwhash';

	private const COOKIE_DAYS = 10;

	/** @var int[]|null */
	private static ?array $restricted = null;

	/** @var int[]|null */
	private static ?array $hidden_albums = null;

	public function init(): void {
		$tax = self::TAX;
		add_action( "{$tax}_add_form_fields", [ $this, 'render_add_fields' ] );
		add_action( "{$tax}_edit_form_fields", [ $this, 'render_edit_fields' ] );
		add_action( "created_{$tax}", [ $this, 'save_fields' ] );
		add_action( "edited_{$tax}", [ $this, 'save_fields' ] );
		add_filter( "manage_edit-{$tax}_columns", [ $this, 'add_column' ] );
		add_filter( "manage_{$tax}_custom_column", [ $this, 'render_column' ], 10, 3 );
		add_action( 'pre_delete_term', [ $this, 'before_delete' ], 10, 2 );
		add_action( "{$tax}_edit_form", [ $this, 'render_cancel' ] );
		add_action( 'quick_edit_custom_box', [ $this, 'render_quick_edit' ], 10, 3 );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_admin' ] );
		add_filter( 'term_updated_messages', [ $this, 'term_messages' ] );
		add_action( 'load-edit-tags.php', [ $this, 'diagnose_update_error' ] );
		add_action( 'admin_init', [ $this, 'unique_slug_for_new_folder' ] );

		add_action( 'template_redirect', [ $this, 'enforce' ] );
		add_filter( 'redirect_canonical', [ $this, 'stop_canonical_leak' ], 10, 2 );
		add_action( 'admin_post_nopriv_bazantik_folder_unlock', [ $this, 'handle_unlock' ] );
		add_action( 'admin_post_bazantik_folder_unlock', [ $this, 'handle_unlock' ] );

		add_filter( 'wp_robots', [ $this, 'robots_rules' ] );
		add_filter( 'get_the_terms', [ $this, 'hide_restricted_terms' ], 10, 3 );
		add_filter( 'wp_sitemaps_taxonomies', [ $this, 'exclude_taxonomy_from_sitemap' ] );
	}

	/**
	 * Nová složka se stejným názvem (nebo adresou) jako existující vznikne s upravenou adresou (hidden-folder-2),
	 * jádro by jinak odmítlo vytvoření hláškou o existujícím termu. Upravuje se jen po ověření nonce a oprávnění.
	 */
	public function unique_slug_for_new_folder(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- nonce se ověřuje níže (nonce přidání termu z jádra)
		if ( ! isset( $_POST['action'], $_POST['taxonomy'], $_POST['tag-name'] ) || ! is_string( $_POST['action'] ) || ! is_string( $_POST['taxonomy'] ) || ! is_string( $_POST['tag-name'] ) || 'add-tag' !== $_POST['action'] || self::TAX !== sanitize_key( wp_unslash( $_POST['taxonomy'] ) ) ) {
			return;
		}
		$add_nonce = isset( $_POST['_wpnonce_add-tag'] ) ? sanitize_text_field( wp_unslash( $_POST['_wpnonce_add-tag'] ) ) : '';
		$tax_obj   = get_taxonomy( self::TAX );
		if ( ! wp_verify_nonce( $add_nonce, 'add-tag' ) || ! $tax_obj || ! current_user_can( $tax_obj->cap->edit_terms ) ) {
			return;
		}
		$name   = trim( sanitize_text_field( wp_unslash( $_POST['tag-name'] ) ) );
		$slug   = isset( $_POST['slug'] ) && is_string( $_POST['slug'] ) ? sanitize_title( wp_unslash( $_POST['slug'] ) ) : '';
		$parent = isset( $_POST['parent'] ) ? max( 0, (int) $_POST['parent'] ) : 0;
		// phpcs:enable WordPress.Security.NonceVerification.Missing
		if ( '' === $name ) {
			return;
		}
		$base = '' !== $slug ? $slug : sanitize_title( $name );
		if ( '' === $base ) {
			return;
		}
		// Jádro u hierarchické taxonomie při kolizi adresy dopisuje adresu rodiče (parent-name), proto se kontroluje celá taxonomie.
		$clash = (bool) get_term_by( 'slug', $base, self::TAX );
		if ( ! $clash && '' === $slug ) {
			$same_name = get_terms( [ 'taxonomy' => self::TAX, 'hide_empty' => false, 'parent' => $parent, 'name' => $name, 'fields' => 'ids' ] );
			$clash     = ! empty( $same_name ) && ! is_wp_error( $same_name );
		}
		if ( ! $clash ) {
			return;
		}
		$n = 2;
		while ( get_term_by( 'slug', $base . '-' . $n, self::TAX ) ) {
			++$n;
		}
		$_POST['slug'] = $base . '-' . $n; // phpcs:ignore WordPress.Security.NonceVerification.Missing
	}

	/* ---------- Stav a oprávnění ---------- */

	public static function visibility( int $term_id ): string {
		$vis = (string) get_term_meta( $term_id, self::META_VIS, true );
		return in_array( $vis, [ 'unlisted', 'password' ], true ) ? $vis : 'public';
	}

	public static function is_manager(): bool {
		return current_user_can( 'edit_others_posts' );
	}

	/** Předpona „wp-postpass_“ je nutná: cache hostingu (WEDOS) zahazuje neznámé cookies a tuto propouští. */
	private static function cookie_name( int $term_id ): string {
		return 'wp-postpass_bazantik_folder_' . $term_id;
	}

	/** Podepsaná hodnota cookie ve tvaru „expirace.podpis“; podpis pokrývá i expiraci, takže ji nejde prodloužit. */
	private static function cookie_value( int $term_id, int $expires ): string {
		$hash = (string) get_term_meta( $term_id, self::META_HASH, true );
		return $expires . '.' . hash_hmac( 'sha256', $term_id . '|' . $hash . '|' . $expires, wp_salt( 'auth' ) );
	}

	/** Cookie skryté složky (odkaz s klíčem) má vlastní název, aby se nepřepisovala s cookie hesla při změně soukromí. */
	private static function link_cookie_name( int $term_id ): string {
		return 'wp-postpass_bazantik_folderkey_' . $term_id;
	}

	/** Podpis je vázaný na klíč složky, takže nový klíč okamžitě zneplatní všechny uložené cookies. */
	private static function link_cookie_value( int $term_id, int $expires ): string {
		$token = (string) get_term_meta( $term_id, self::META_TOKEN, true );
		return $expires . '.' . hash_hmac( 'sha256', 'link|' . $term_id . '|' . $token . '|' . $expires, wp_salt( 'auth' ) );
	}

	/** Návštěvník dřív otevřel skrytou složku platným odkazem a cookie ještě platí. */
	public static function is_link_unlocked( int $term_id ): bool {
		$token = (string) get_term_meta( $term_id, self::META_TOKEN, true );
		$name  = self::link_cookie_name( $term_id );
		if ( '' === $token || 'unlisted' !== self::visibility( $term_id ) || empty( $_COOKIE[ $name ] ) ) {
			return false;
		}
		$given = wp_unslash( (string) $_COOKIE[ $name ] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- hodnota se přísně ověří regulárním výrazem a podpisem
		if ( ! preg_match( '/^(\d{9,12})\.[a-f0-9]{64}$/', $given, $m ) || (int) $m[1] < time() ) {
			return false;
		}
		return hash_equals( self::link_cookie_value( $term_id, (int) $m[1] ), $given );
	}

	/** Platný klíč v adrese (porovnání bez úniku času). */
	private static function has_valid_key( int $term_id ): bool {
		$token    = (string) get_term_meta( $term_id, self::META_TOKEN, true );
		$provided = sanitize_text_field( wp_unslash( $_GET['key'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- tajný klíč v adrese
		return '' !== $token && hash_equals( $token, $provided );
	}

	public static function is_unlocked( int $term_id ): bool {
		$hash = (string) get_term_meta( $term_id, self::META_HASH, true );
		$name = self::cookie_name( $term_id );
		if ( '' === $hash || empty( $_COOKIE[ $name ] ) ) {
			return false;
		}
		$given = wp_unslash( (string) $_COOKIE[ $name ] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- hodnota se přísně ověří regulárním výrazem a podpisem
		if ( ! preg_match( '/^(\d{9,12})\.[a-f0-9]{64}$/', $given, $m ) || (int) $m[1] < time() ) {
			return false;
		}
		return hash_equals( self::cookie_value( $term_id, (int) $m[1] ), $given );
	}

	/**
	 * Může návštěvník otevřít výpis složky? ok = ano, password = potřebuje heslo, denied = složka pro něj neexistuje.
	 */
	public static function access( int $term_id ): string {
		if ( self::is_manager() ) {
			return 'ok';
		}
		$vis = self::visibility( $term_id );
		if ( 'public' === $vis ) {
			return 'ok';
		}
		if ( 'unlisted' === $vis ) {
			return ( self::has_valid_key( $term_id ) || self::is_link_unlocked( $term_id ) ) ? 'ok' : 'denied';
		}
		return self::is_unlocked( $term_id ) ? 'ok' : 'password';
	}

	/**
	 * ID složek, které nejsou veřejné, včetně všech jejich podsložek (alba v nich se mimo výpis složky nesmí objevit).
	 *
	 * @return int[]
	 */
	public static function restricted_ids(): array {
		if ( null !== self::$restricted ) {
			return self::$restricted;
		}
		$ids   = [];
		$terms = get_terms( [ 'taxonomy' => self::TAX, 'hide_empty' => false, 'fields' => 'ids' ] );
		if ( is_array( $terms ) ) {
			foreach ( $terms as $id ) {
				$id = (int) $id;
				if ( 'public' === self::visibility( $id ) ) {
					continue;
				}
				$ids[] = $id;
				$kids  = get_term_children( $id, self::TAX );
				if ( is_array( $kids ) ) {
					$ids = array_merge( $ids, array_map( 'intval', $kids ) );
				}
			}
		}
		self::$restricted = array_values( array_unique( $ids ) );
		return self::$restricted;
	}

	/**
	 * Album leží jen v neveřejných složkách (v žádné veřejné ani mimo složky), takže se nemá indexovat ani prozrazovat přes REST.
	 */
	public static function album_only_restricted( int $album_id ): bool {
		$restricted = self::restricted_ids();
		if ( empty( $restricted ) ) {
			return false;
		}
		$terms = wp_get_object_terms( $album_id, self::TAX, [ 'fields' => 'ids' ] );
		if ( ! is_array( $terms ) || empty( $terms ) ) {
			return false;
		}
		return empty( array_diff( array_map( 'intval', $terms ), $restricted ) );
	}

	/** Album leží alespoň v jedné neveřejné složce (drobečky mohou záviset na cookie návštěvníka, takže se nesmí cachovat). */
	public static function album_in_restricted( int $album_id ): bool {
		$restricted = self::restricted_ids();
		if ( empty( $restricted ) ) {
			return false;
		}
		$terms = wp_get_object_terms( $album_id, self::TAX, [ 'fields' => 'ids' ] );
		return is_array( $terms ) && ! empty( array_intersect( array_map( 'intval', $terms ), $restricted ) );
	}

	/**
	 * ID alb, která leží výhradně v neveřejných složkách (album i ve veřejné složce nebo mimo složky zůstává viditelné).
	 *
	 * @return int[]
	 */
	public static function hidden_album_ids(): array {
		if ( null !== self::$hidden_albums ) {
			return self::$hidden_albums;
		}
		self::$hidden_albums = [];
		$restricted          = self::restricted_ids();
		if ( empty( $restricted ) ) {
			return self::$hidden_albums;
		}
		$in_restricted = get_objects_in_term( $restricted, self::TAX );
		if ( is_wp_error( $in_restricted ) || empty( $in_restricted ) ) {
			return self::$hidden_albums;
		}
		$all_terms = get_terms( [ 'taxonomy' => self::TAX, 'hide_empty' => false, 'fields' => 'ids' ] );
		$public    = is_array( $all_terms ) ? array_values( array_diff( array_map( 'intval', $all_terms ), $restricted ) ) : [];
		$in_public = empty( $public ) ? [] : get_objects_in_term( $public, self::TAX );
		$in_public = is_wp_error( $in_public ) ? [] : array_map( 'intval', $in_public );
		self::$hidden_albums = array_values( array_diff( array_map( 'intval', $in_restricted ), $in_public ) );
		return self::$hidden_albums;
	}

	/**
	 * SQL doplněk pro odkazy předchozí/další album (jen celá čísla, tedy bez rizika injekce).
	 */
	public static function exclusion_sql(): string {
		$ids = self::hidden_album_ids();
		return empty( $ids ) ? '' : ' AND p.ID NOT IN (' . implode( ',', $ids ) . ')';
	}

	/**
	 * Stránka, jejíž obsah závisí na neveřejné složce nebo zamčeném albu, nesmí skončit ve sdílené cache.
	 */
	public static function mark_nocache(): void {
		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', true );
		}
		do_action( 'litespeed_control_set_nocache', 'bazantik_private_folder' );
		if ( ! headers_sent() ) {
			header( 'Cache-Control: no-store, no-cache, must-revalidate, max-age=0' );
			header( 'Referrer-Policy: strict-origin-when-cross-origin' );
			nocache_headers();
		}
	}

	/* ---------- Vstup do složky ---------- */

	/** Stránka s přehledem galerie se nesmí cachovat; hlavičky se musí poslat dřív, než šablona začne vypisovat obsah. */
	private static function is_gallery_page(): bool {
		if ( is_post_type_archive( 'bazantik_album' ) ) {
			return true;
		}
		$post = is_singular() ? get_post() : null;
		return $post instanceof \WP_Post
			&& ( has_shortcode( $post->post_content, 'bazantik_albums_archive' ) || has_shortcode( $post->post_content, 'bazantik_album' ) || has_block( 'bazantik-photo-gallery/album-embed', $post ) );
	}

	public function enforce(): void {
		if ( ! is_tax( self::TAX ) ) {
			if ( self::is_gallery_page() ) {
				self::mark_nocache();
			}
			return;
		}
		$term = get_queried_object();
		if ( ! $term instanceof \WP_Term ) {
			return;
		}

		// Stránka složky (i veřejné) může obsahovat neveřejné prvky a hlavičky už nelze po výstupu změnit, proto se nikdy neukládá do cache
		self::mark_nocache();

		$state = self::access( $term->term_id );
		if ( 'denied' === $state || ( 'password' === $state && is_feed() ) ) {
			global $wp_query;
			$wp_query->set_404();
			status_header( 404 );
			nocache_headers();
			include get_query_template( '404' );
			exit;
		}

		// Platný odkaz se zapamatuje v podepsané cookie, aby složka zůstala v drobečcích i bez klíče v adrese
		if ( 'unlisted' === self::visibility( $term->term_id ) && ! self::is_manager() && self::has_valid_key( $term->term_id ) && ! headers_sent() ) {
			$expires = time() + self::COOKIE_DAYS * DAY_IN_SECONDS;
			setcookie( self::link_cookie_name( $term->term_id ), self::link_cookie_value( $term->term_id, $expires ), [
				'expires'  => $expires,
				'path'     => COOKIEPATH,
				'domain'   => COOKIE_DOMAIN ? COOKIE_DOMAIN : '',
				'secure'   => is_ssl(),
				'httponly' => true,
				'samesite' => 'Lax',
			] );
		}
	}

	/**
	 * Skrytá složka se bez klíče nesmí prozradit kanonickým přesměrováním z /?bazantik_folder=slug na pretty adresu
	 * (jinak by se odpověď lišila od neexistující složky); návštěvník dostane stejné 404.
	 *
	 * @param mixed $redirect_url
	 * @return mixed
	 */
	public function stop_canonical_leak( $redirect_url, $requested_url ) {
		if ( is_tax( self::TAX ) ) {
			$term = get_queried_object();
			if ( $term instanceof \WP_Term && 'denied' === self::access( $term->term_id ) ) {
				return false;
			}
		}
		return $redirect_url;
	}

	public function robots_rules( array $robots ): array {
		if ( is_tax( self::TAX ) ) {
			$term = get_queried_object();
			if ( $term instanceof \WP_Term && 'public' !== self::visibility( $term->term_id ) ) {
				$robots['noindex']  = true;
				$robots['nofollow'] = true;
			}
		}
		return $robots;
	}

	/**
	 * Názvy neveřejných složek se návštěvníkům neprozradí přes šablonové výpisy kategorií (drobečky apod.).
	 *
	 * @param mixed $terms
	 * @return mixed
	 */
	public function hide_restricted_terms( $terms, $post_id, $taxonomy ) {
		if ( self::TAX !== $taxonomy || ! is_array( $terms ) || is_admin() || self::is_manager() ) {
			return $terms;
		}
		$restricted = self::restricted_ids();
		if ( empty( $restricted ) ) {
			return $terms;
		}
		return array_values( array_filter( $terms, static fn( $t ) => ! ( $t instanceof \WP_Term ) || ! in_array( (int) $t->term_id, $restricted, true ) ) );
	}

	public function exclude_taxonomy_from_sitemap( array $taxonomies ): array {
		unset( $taxonomies[ self::TAX ] );
		return $taxonomies;
	}

	/* ---------- Odemknutí heslem ---------- */

	public function handle_unlock(): void {
		$term_id = isset( $_POST['bazantik_folder_id'] ) ? absint( $_POST['bazantik_folder_id'] ) : 0;
		$term    = $term_id ? get_term( $term_id, self::TAX ) : null;
		if ( ! $term instanceof \WP_Term ) {
			Password_Field::respond( home_url( '/' ) );
			exit;
		}

		// Adresa složky se prozradí jen u zaheslované složky (ta je ve výpisech vidět); skrytá složka ji vrátit nesmí
		if ( 'password' !== self::visibility( $term_id ) || '' === (string) get_term_meta( $term_id, self::META_HASH, true ) ) {
			Password_Field::respond( home_url( '/' ) );
			exit;
		}

		$link = get_term_link( $term );
		$back = is_wp_error( $link ) ? home_url( '/' ) : $link;

		$nonce = isset( $_POST['_bazantik_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['_bazantik_nonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, 'bazantik_folder_unlock_' . $term_id ) ) {
			Password_Field::respond( add_query_arg( 'bazantik_folder_error', '3', $back ) );
			exit;
		}

		$hash = (string) get_term_meta( $term_id, self::META_HASH, true );
		$tries_k  = Settings::throttle_key( 'bazantik_fl_', $term_id );
		$limit    = Settings::throttle_enabled();
		$password = isset( $_POST['bazantik_folder_password'] ) && is_string( $_POST['bazantik_folder_password'] ) ? trim( (string) wp_unslash( $_POST['bazantik_folder_password'] ) ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- heslo se nesmí upravovat

		// Pokus se započítá atomicky před ověřením hesla (prázdný pokus se nepočítá); úspěch čítač vynuluje
		if ( $limit && '' !== $password && ! Settings::reserve_attempt( $tries_k ) ) {
			Password_Field::respond( add_query_arg( 'bazantik_folder_error', '2', $back ) );
			exit;
		}

		if ( '' === $password || ! wp_check_password( $password, $hash ) ) {
			Password_Field::respond( add_query_arg( 'bazantik_folder_error', '1', $back ) );
			exit;
		}

		delete_transient( $tries_k );
		$expires = time() + self::COOKIE_DAYS * DAY_IN_SECONDS;
		setcookie(
			self::cookie_name( $term_id ),
			self::cookie_value( $term_id, $expires ),
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

	/**
	 * Formulář pro zadání hesla složky.
	 */
	public static function password_form_html( \WP_Term $term ): string {
		$error = isset( $_GET['bazantik_folder_error'] ) ? absint( $_GET['bazantik_folder_error'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- jen výběr zprávy
		$msgs  = [
			1 => __( 'Incorrect password.', 'bazantik-photo-gallery' ),
			2 => __( 'Too many incorrect attempts. Please try again in a few minutes.', 'bazantik-photo-gallery' ),
			3 => __( 'The form has expired. Please try again.', 'bazantik-photo-gallery' ),
		];

		$html  = '<div class="bazantik-password-form-wrapper bazantik-folder-password">';
		$html .= '<p>' . esc_html__( 'This folder is password protected. Enter the password to view it.', 'bazantik-photo-gallery' ) . '</p>';
		if ( isset( $msgs[ $error ] ) ) {
			$html .= '<p class="bazantik-folder-error" role="alert">' . esc_html( $msgs[ $error ] ) . '</p>';
		}
		$html .= '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">'
			. '<input type="hidden" name="action" value="bazantik_folder_unlock">'
			. '<input type="hidden" name="bazantik_folder_id" value="' . esc_attr( (string) $term->term_id ) . '">'
			. '<input type="hidden" name="_bazantik_nonce" value="' . esc_attr( wp_create_nonce( 'bazantik_folder_unlock_' . $term->term_id ) ) . '">'
			. '<label for="bazantik-folder-pw" class="screen-reader-text">' . esc_html__( 'Password', 'bazantik-photo-gallery' ) . '</label>'
			. Password_Field::input( 'bazantik_folder_password', [ 'id' => 'bazantik-folder-pw', 'autocomplete' => 'current-password', 'required' => true ] ) . ' '
			. '<input type="submit" value="' . esc_attr__( 'Unlock', 'bazantik-photo-gallery' ) . '">'
			. '</form></div>';
		return $html;
	}

	/* ---------- Administrace složky ---------- */

	private function fields_html( string $vis ): string {
		$html  = wp_nonce_field( 'bazantik_folder_save', 'bazantik_folder_nonce', true, false );
		$html .= '<select name="bazantik_folder_visibility" id="bazantik_folder_visibility">'
			. '<option value="public"' . selected( $vis, 'public', false ) . '>' . esc_html__( 'Public folder', 'bazantik-photo-gallery' ) . '</option>'
			. '<option value="unlisted"' . selected( $vis, 'unlisted', false ) . '>' . esc_html__( 'Hidden folder (link only)', 'bazantik-photo-gallery' ) . '</option>'
			. '<option value="password"' . selected( $vis, 'password', false ) . '>' . esc_html__( 'Password-protected folder', 'bazantik-photo-gallery' ) . '</option>'
			. '</select>';
		$html .= '<p class="description">' . esc_html__( 'Privacy protects only the folder itself. Albums and subfolders inside it have their own privacy settings and are not unlocked by unlocking the folder.', 'bazantik-photo-gallery' ) . '</p>';
		return $html;
	}

	public function render_add_fields(): void {
		echo '<div class="form-field"><label for="bazantik_folder_visibility">' . esc_html__( 'Folder privacy', 'bazantik-photo-gallery' ) . '</label>';
		echo $this->fields_html( 'public' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escapováno uvnitř
		echo '</div><div class="form-field bazantik-fld-unlisted" style="display:none"><p class="description">' . esc_html__( 'The secret link is created after the folder is added. It is copied to the clipboard automatically and is also shown when you edit the folder.', 'bazantik-photo-gallery' ) . '</p></div>';
		echo '<div class="form-field bazantik-fld-password" style="display:none"><label for="bazantik_folder_pw">' . esc_html__( 'Folder password', 'bazantik-photo-gallery' ) . '</label>';
		echo Password_Field::input( 'bazantik_folder_pw', [ 'id' => 'bazantik_folder_pw', 'block' => true ] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escapováno uvnitř
		echo '</div>';
	}

	/**
	 * Jádro při chybě uložení složky zahodí důvod a zobrazí jen obecnou hlášku. Před uložením proto předem
	 * zjistíme nejčastější příčiny (prázdný název, adresa už používaná jinou složkou) a text si krátce uložíme.
	 */
	public function diagnose_update_error(): void {
        // phpcs:disable WordPress.Security.NonceVerification.Missing -- nonce se ověřuje níže
		if ( ! isset( $_POST['action'], $_POST['taxonomy'], $_POST['tag_ID'] ) || 'editedtag' !== $_POST['action'] || self::TAX !== sanitize_key( wp_unslash( $_POST['taxonomy'] ) ) ) {
			return;
		}
		$term_id = absint( $_POST['tag_ID'] );
		$nonce   = isset( $_POST['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ) : '';
		$term    = $term_id ? get_term( $term_id, self::TAX ) : null;
		if ( ! $term instanceof \WP_Term || ! wp_verify_nonce( $nonce, 'update-tag_' . $term_id ) || ! current_user_can( 'edit_term', $term_id ) ) {
			return;
		}
		$name   = isset( $_POST['name'] ) ? trim( (string) wp_unslash( $_POST['name'] ) ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- název složky ošetří jádro při uložení termu
		$slug   = isset( $_POST['slug'] ) ? sanitize_title( wp_unslash( $_POST['slug'] ) ) : '';
		$parent = isset( $_POST['parent'] ) ? max( 0, (int) $_POST['parent'] ) : (int) $term->parent;
        // phpcs:enable WordPress.Security.NonceVerification.Missing
		$text = '';
		if ( '' === $name ) {
			$text = __( 'The folder could not be updated: the folder name must not be empty.', 'bazantik-photo-gallery' );
		} elseif ( '' !== $slug ) {
			$other = get_term_by( 'slug', $slug, self::TAX );
			if ( $other instanceof \WP_Term && (int) $other->term_id !== $term_id && $parent === (int) $term->parent ) {
				/* translators: %s: folder slug */
				$text = sprintf( __( 'The folder could not be updated: the slug “%s” already belongs to another folder. Choose a different one.', 'bazantik-photo-gallery' ), $slug );
			}
		}
		if ( '' !== $text ) {
			set_transient( 'bazantik_folder_err_' . get_current_user_id() . '_' . $term_id, $text, MINUTE_IN_SECONDS );
		}
	}

	/**
	 * @param array<string,array<int,string>> $messages
	 * @return array<string,array<int,string>>
	 */
	public function term_messages( array $messages ): array {
		$messages[ self::TAX ] = [
			0 => '',
			1 => __( 'Folder added.', 'bazantik-photo-gallery' ),
			2 => __( 'Folder deleted.', 'bazantik-photo-gallery' ),
			3 => __( 'Folder updated.', 'bazantik-photo-gallery' ),
			4 => __( 'The folder could not be added. Check that it has a name and that no folder with the same slug exists yet.', 'bazantik-photo-gallery' ),
			5 => __( 'The folder could not be updated. Check the name and the slug.', 'bazantik-photo-gallery' ),
			6 => __( 'Folders deleted.', 'bazantik-photo-gallery' ),
		];
		$tag_id = isset( $_GET['tag_ID'] ) ? absint( $_GET['tag_ID'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- jen výběr zprávy
		$detail = $tag_id ? get_transient( 'bazantik_folder_err_' . get_current_user_id() . '_' . $tag_id ) : false;
		if ( is_string( $detail ) && '' !== $detail ) {
			$messages[ self::TAX ][5] = $detail;
		}
		return $messages;
	}

	public function render_edit_fields( \WP_Term $term ): void {
		$vis      = self::visibility( $term->term_id );
		$has_hash = '' !== (string) get_term_meta( $term->term_id, self::META_HASH, true );
		self::ensure_token( $term->term_id );
		$link     = self::secret_link( $term );

		echo '<tr class="form-field"><th scope="row"><label for="bazantik_folder_visibility">' . esc_html__( 'Folder privacy', 'bazantik-photo-gallery' ) . '</label></th><td>';
		echo $this->fields_html( $vis ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escapováno uvnitř
		echo '</td></tr>';
		echo '<tr class="form-field bazantik-fld-unlisted"' . ( 'unlisted' === $vis ? '' : ' style="display:none"' ) . '><th scope="row"><label for="bazantik_folder_link">' . esc_html__( 'Secret link', 'bazantik-photo-gallery' ) . '</label></th><td>';
		if ( '' !== $link ) {
			echo '<input type="text" readonly class="large-text bazantik-select-on-click" id="bazantik_folder_link" value="' . esc_attr( $link ) . '">';
			echo '<p class="description">' . esc_html__( 'When you change privacy to “Hidden”, the link is copied to the clipboard after saving.', 'bazantik-photo-gallery' ) . '</p>';
			echo '<p>' . self::new_key_control() . '</p>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escapováno uvnitř
		} else {
			echo '<p class="description">' . esc_html__( 'The secret link is created after the folder is saved.', 'bazantik-photo-gallery' ) . '</p>';
		}
		echo '</td></tr>';
		echo '<tr class="form-field bazantik-fld-password"' . ( 'password' === $vis ? '' : ' style="display:none"' ) . '><th scope="row"><label for="bazantik_folder_pw">' . esc_html__( 'Folder password', 'bazantik-photo-gallery' ) . '</label></th><td>';
		$can_reveal = '' !== (string) get_term_meta( $term->term_id, Password_Reveal::META_ENC, true );
		$pw_args    = [ 'id' => 'bazantik_folder_pw', 'block' => true ];
		if ( $has_hash ) {
			$pw_args['placeholder'] = __( 'A password is set', 'bazantik-photo-gallery' );
			$pw_args['reveal']      = $can_reveal ? [ 'type' => 'folder', 'id' => (int) $term->term_id ] : null;
		}
		echo Password_Field::input( 'bazantik_folder_pw', $pw_args ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escapováno uvnitř
		if ( $has_hash ) {
			echo '<input type="hidden" id="bazantik_folder_hashed" value="1">';
		}
		if ( $has_hash && $can_reveal ) {
			echo '<p class="description">' . esc_html__( 'Click the eye to show the saved password. To change it, type a new one.', 'bazantik-photo-gallery' ) . '</p>';
		} elseif ( ! $has_hash ) {
			echo '<p class="description">' . esc_html__( 'Enter a password to protect this folder.', 'bazantik-photo-gallery' ) . '</p>';
		}
		echo '</td></tr>';
	}

	/** Volitelné vytvoření nového klíče: tlačítko jen nastaví příznak, klíč se vymění při uložení. */
	private static function new_key_control(): string {
		return '<span class="bazantik-newkey"><input type="hidden" name="bazantik_folder_new_key" value="0">'
			. '<button type="button" class="button bazantik-newkey-btn">' . esc_html__( 'Generate new link', 'bazantik-photo-gallery' ) . '</button>'
			. '<span class="bazantik-newkey-note description" style="display:none">' . esc_html__( 'A new link will be created when you save. The old link and everyone who opened it will lose access.', 'bazantik-photo-gallery' ) . '</span></span>';
	}

	/** Klíč složky vzniká vždy (platí jen, když je složka skrytá), aby byl odkaz k dispozici hned po přepnutí soukromí. */
	private static function ensure_token( int $term_id ): string {
		$token = (string) get_term_meta( $term_id, self::META_TOKEN, true );
		if ( '' === $token ) {
			$token = wp_generate_password( 24, false );
			update_term_meta( $term_id, self::META_TOKEN, $token );
		}
		return $token;
	}

	/** Tajný odkaz složky (prázdný řetězec, dokud klíč nevznikl). */
	private static function secret_link( \WP_Term $term ): string {
		$token = (string) get_term_meta( $term->term_id, self::META_TOKEN, true );
		$link  = get_term_link( $term );
		if ( '' === $token || is_wp_error( $link ) ) {
			return '';
		}
		return add_query_arg( 'key', rawurlencode( $token ), $link );
	}

	public function render_cancel(): void {
		$url = add_query_arg( [ 'taxonomy' => self::TAX, 'post_type' => 'bazantik_album' ], admin_url( 'edit-tags.php' ) );
		echo '<p id="bazantik-folder-cancel-wrap"><a id="bazantik-folder-cancel" class="button" href="' . esc_url( $url ) . '">' . esc_html__( 'Cancel', 'bazantik-photo-gallery' ) . '</a></p>';
	}

	/**
	 * Rychlá úprava složky: výběr soukromí a nové heslo (stav a odkaz se doplní skriptem z dat ve sloupci).
	 */
	public function render_quick_edit( string $column, string $screen, string $taxonomy = '' ): void {
		if ( 'bazantik_privacy' !== $column || self::TAX !== $taxonomy ) {
			return;
		}
		echo '<fieldset><div class="inline-edit-col bazantik-qe-col">';
		echo '<input type="hidden" name="bazantik_folder_nonce" value="' . esc_attr( wp_create_nonce( 'bazantik_folder_save' ) ) . '">';
        // phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- wp_dropdown_categories() escapuje sama
		echo '<label><span class="title">' . esc_html__( 'Parent folder', 'bazantik-photo-gallery' ) . '</span><span class="input-text-wrap">'
			. wp_dropdown_categories( [
				'taxonomy'          => self::TAX,
				'hide_empty'        => false,
				'hierarchical'      => true,
				'orderby'           => 'name',
				'name'              => 'parent',
				'id'                => 'bazantik-qe-parent',
				'class'             => 'bazantik-qe-parent',
				'show_option_none'  => __( 'None (top level)', 'bazantik-photo-gallery' ),
				'option_none_value' => '0',
				'echo'              => false,
			] ) . '</span></label>';
        // phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '<label><span class="title">' . esc_html__( 'Folder privacy', 'bazantik-photo-gallery' ) . '</span><span class="input-text-wrap">'
			. '<select name="bazantik_folder_visibility" class="bazantik-qe-vis">'
			. '<option value="public">' . esc_html__( 'Public folder', 'bazantik-photo-gallery' ) . '</option>'
			. '<option value="unlisted">' . esc_html__( 'Hidden folder (link only)', 'bazantik-photo-gallery' ) . '</option>'
			. '<option value="password">' . esc_html__( 'Password-protected folder', 'bazantik-photo-gallery' ) . '</option>'
			. '</select></span></label>';
		echo '<label class="bazantik-fld-unlisted" style="display:none"><span class="title">' . esc_html__( 'Link', 'bazantik-photo-gallery' ) . '</span><span class="input-text-wrap">'
			. '<input type="text" readonly class="bazantik-select-on-click bazantik-qe-link" title="' . esc_attr__( 'The link is copied to the clipboard when you save.', 'bazantik-photo-gallery' ) . '"></span></label>';
		echo '<div class="bazantik-fld-unlisted" style="display:none"><span class="title">&nbsp;</span><span class="input-text-wrap">' . self::new_key_control() . '</span></div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escapováno uvnitř
        // phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- Password_Field::input() escapuje atributy
		echo '<label class="bazantik-fld-password" style="display:none"><span class="title">' . esc_html__( 'Password', 'bazantik-photo-gallery' ) . '</span><span class="input-text-wrap">'
			. Password_Field::input( 'bazantik_folder_pw', [ 'class' => 'bazantik-qe-pw', 'placeholder' => __( 'Enter a password', 'bazantik-photo-gallery' ), 'block' => true, 'reveal' => [ 'type' => 'folder', 'id' => 0 ] ] ) . '</span></label>';
        // phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '</div></fieldset>';
	}

	public function enqueue_admin( string $hook ): void {
		$screen = get_current_screen();
		if ( ! $screen || self::TAX !== $screen->taxonomy || ! in_array( $hook, [ 'edit-tags.php', 'term.php' ], true ) ) {
			return;
		}
		$deps = [ 'jquery' ];
		if ( 'edit-tags.php' === $hook ) {
			$deps[] = 'inline-edit-tax';
		}
		wp_enqueue_style( 'bazantik-admin-folders', BAZANTIK_PLUGIN_URL . 'assets/css/admin-folders.css', [ 'dashicons' ], BAZANTIK_VERSION );
		wp_enqueue_script( 'bazantik-admin-folders', BAZANTIK_PLUGIN_URL . 'assets/js/admin-folders.js', $deps, BAZANTIK_VERSION, true );
		wp_localize_script( 'bazantik-admin-folders', 'bazantikFolders', [
			'copied'    => __( 'The secret link was copied to the clipboard.', 'bazantik-photo-gallery' ),
			'notCopied' => __( 'The secret link could not be copied automatically. Please copy it manually.', 'bazantik-photo-gallery' ),
			'newKey'     => __( 'Generate new link', 'bazantik-photo-gallery' ),
			'keepKey'    => __( 'Keep current link', 'bazantik-photo-gallery' ),
			'pwKeep'     => __( 'Empty = no change', 'bazantik-photo-gallery' ),
			'pwEnter'    => __( 'Enter a password', 'bazantik-photo-gallery' ),
			'pwRequired' => __( 'A password-protected folder must have a password. Enter a password or choose a different privacy setting.', 'bazantik-photo-gallery' ),
		] );
	}

	/**
	 * Odkazy na složku a její nadřazené složky (od nejnadřazenější). Neveřejné složky se návštěvníkům neprozradí.
	 *
	 * @return string[]
	 */
	private static function chain_links( \WP_Term $term, bool $include_self ): array {
		$restricted = self::is_manager() ? [] : self::restricted_ids();
		$chain      = array_reverse( get_ancestors( $term->term_id, self::TAX, 'taxonomy' ) );
		if ( $include_self ) {
			$chain[] = $term->term_id;
		}
		$parts    = [];
		$unlocked = false;
		foreach ( $chain as $id ) {
			$item = get_term( (int) $id, self::TAX );
			$url  = $item instanceof \WP_Term ? get_term_link( $item ) : null;
			if ( ! $item instanceof \WP_Term || is_wp_error( $url ) ) {
				continue;
			}
			// Neveřejná složka se ukáže jen návštěvníkovi, který ji odemkl (heslem nebo odkazem); veřejná podsložka jen pod odemčenou
			if ( in_array( $item->term_id, $restricted, true ) ) {
				$vis = self::visibility( $item->term_id );
				if ( 'password' === $vis ? self::is_unlocked( $item->term_id ) : ( 'unlisted' === $vis && self::is_link_unlocked( $item->term_id ) ) ) {
					$unlocked = true;
				} elseif ( 'public' !== $vis || ! $unlocked ) {
					$unlocked = false;
					continue;
				}
			}
			$parts[] = '<a href="' . esc_url( $url ) . '">' . esc_html( $item->name ) . '</a>';
		}
		return $parts;
	}

	private static function root_link(): string {
		$url = get_post_type_archive_link( 'bazantik_album' );
		return $url ? '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Gallery', 'bazantik-photo-gallery' ) . '</a>' : '';
	}

	/** Drobečky stránky složky: Galerie › nadřazené složky (název otevřené složky je v nadpisu). */
	public static function folder_breadcrumb_html( \WP_Term $term ): string {
		return implode( ' &rsaquo; ', array_filter( array_merge( [ self::root_link() ], self::chain_links( $term, false ) ) ) );
	}

	/**
	 * Drobečky alba: Galerie › složka, kterou návštěvník otevřel (parametr bazantik_folder z karty ve složce);
	 * bez něj všechny cesty, kudy lze k albu dojít.
	 */
	public static function breadcrumb_html( int $album_id ): string {
		$root  = self::root_link();
		$paths = [];

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- jen výběr drobečků veřejného výpisu
		$hint = isset( $_GET['bazantik_folder'] ) ? absint( $_GET['bazantik_folder'] ) : 0;
		$term = $hint ? get_term( $hint, self::TAX ) : null;
		if ( $term instanceof \WP_Term && has_term( $term->term_id, self::TAX, $album_id ) ) {
			$links = self::chain_links( $term, true );
			if ( $links ) {
				$paths[] = $links;
			}
		}

		if ( ! $paths ) {
			$terms = get_the_terms( $album_id, self::TAX );
			$terms = is_array( $terms ) ? $terms : [];
			$ids   = array_map( static fn( $t ) => (int) $t->term_id, $terms );
			foreach ( $terms as $item ) {
				// Nadřazená složka, ve které je album i v podsložce, se nevypisuje zvlášť
				foreach ( $ids as $other ) {
					if ( $other !== (int) $item->term_id && in_array( (int) $item->term_id, get_ancestors( $other, self::TAX, 'taxonomy' ), true ) ) {
						continue 2;
					}
				}
				$links = self::chain_links( $item, true );
				if ( $links ) {
					$paths[] = $links;
				}
			}
		}

		$out = [];
		foreach ( $paths as $links ) {
			$out[] = '<span class="bazantik-crumb-path">' . implode( ' &rsaquo; ', array_filter( array_merge( [ $root ], $links ) ) ) . '</span>';
		}
		return $out ? implode( '', $out ) : '<span class="bazantik-crumb-path">' . $root . '</span>';
	}

	public function save_fields( int $term_id ): void {
		$nonce = isset( $_POST['bazantik_folder_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['bazantik_folder_nonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, 'bazantik_folder_save' ) || ! current_user_can( 'edit_term', $term_id ) ) {
			return;
		}

		// Chybějící nebo neplatná hodnota ponechá stávající soukromí beze změny
		$vis = isset( $_POST['bazantik_folder_visibility'] ) ? sanitize_key( wp_unslash( $_POST['bazantik_folder_visibility'] ) ) : '';
		if ( ! in_array( $vis, [ 'public', 'unlisted', 'password' ], true ) ) {
			return;
		}

		$password = isset( $_POST['bazantik_folder_pw'] ) && is_string( $_POST['bazantik_folder_pw'] ) ? trim( (string) wp_unslash( $_POST['bazantik_folder_pw'] ) ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- heslo se nesmí upravovat
		// Prázdné pole znamená beze změny; neprázdná hodnota je vždy úprava (i bez JavaScriptu, který přepíná příznak „changed“)
		if ( Password_Field::too_long( $password ) ) {
			$password = '';
		}
		// Zaheslovaná složka bez hesla by nešla odemknout (administrace to zachytí dřív); bezpečnější je nechat ji skrytou
		if ( 'password' === $vis && '' === $password && '' === (string) get_term_meta( $term_id, self::META_HASH, true ) ) {
			$vis = 'unlisted';
		}

		// Stejné heslo odeslané znovu (např. po odkrytí oka v Rychlé úpravě) se nepřehašuje, aby neplatila cookies odemčených návštěvníků
		$current_hash = (string) get_term_meta( $term_id, self::META_HASH, true );
		if ( '' !== $password && '' !== $current_hash && wp_check_password( $password, $current_hash ) ) {
			$password = '';
		}

		if ( 'public' === $vis ) {
			delete_term_meta( $term_id, self::META_VIS );
		} else {
			update_term_meta( $term_id, self::META_VIS, $vis );
		}

		// Nový klíč jen na výslovné přání a jen u skryté složky; staré odkazy i cookies tím přestanou platit
		if ( 'unlisted' === $vis && isset( $_POST['bazantik_folder_new_key'] ) && '1' === sanitize_text_field( wp_unslash( $_POST['bazantik_folder_new_key'] ) ) ) {
			delete_term_meta( $term_id, self::META_TOKEN );
		}
		self::ensure_token( $term_id );

		// Po přepnutí z režimu s heslem se hash ani odhalitelná kopie hesla nesmí uchovávat
		if ( 'password' !== $vis ) {
			delete_term_meta( $term_id, self::META_HASH );
			delete_term_meta( $term_id, Password_Reveal::META_ENC );
			$password = '';
		}

		if ( '' !== $password ) {
			update_term_meta( $term_id, self::META_HASH, wp_hash_password( $password ) );
			Password_Reveal::store_folder_password( $term_id, $password );
		}
		self::$restricted    = null;
		self::$hidden_albums = null;
	}

	/**
	 * @param array<string,string> $columns
	 * @return array<string,string>
	 */
	public function add_column( array $columns ): array {
		$columns['bazantik_privacy'] = __( 'Privacy', 'bazantik-photo-gallery' );
		return $columns;
	}

	public function render_column( string $content, string $column, int $term_id ): string {
		if ( 'bazantik_privacy' !== $column ) {
			return $content;
		}
		$labels = [
			'public'   => '🟢 ' . _x( 'Public', 'folder privacy badge', 'bazantik-photo-gallery' ),
			'unlisted' => '🔗 ' . _x( 'Hidden (link)', 'folder privacy badge', 'bazantik-photo-gallery' ),
			'password' => '🔒 ' . _x( 'Password protected', 'folder privacy badge', 'bazantik-photo-gallery' ),
		];
		$vis  = self::visibility( $term_id );
		$term = get_term( $term_id, self::TAX );
		if ( $term instanceof \WP_Term && current_user_can( 'edit_term', $term_id ) ) {
			self::ensure_token( $term_id );
		}
		$link   = $term instanceof \WP_Term ? self::secret_link( $term ) : '';
		$hashed = '' !== (string) get_term_meta( $term_id, self::META_HASH, true ) ? '1' : '';
		$reveal = '' !== (string) get_term_meta( $term_id, Password_Reveal::META_ENC, true ) ? '1' : '';
		return '<span class="bazantik-vis" data-vis="' . esc_attr( $vis ) . '" data-link="' . esc_attr( $link ) . '" data-hashed="' . esc_attr( $hashed ) . '" data-reveal="' . esc_attr( $reveal ) . '">' . esc_html( $labels[ $vis ] ) . '</span>';
	}

	/**
	 * Smazání neveřejné složky nesmí nic zveřejnit: veřejná alba, která v ní jsou jediná složka, se skryjí (odkaz),
	 * a veřejné podsložky (WordPress je přesune o úroveň výš) se skryjí také.
	 */
	public function before_delete( int $term_id, string $taxonomy ): void {
		// Veřejná podsložka neveřejné složky je také omezená, proto se neposuzuje jen její vlastní viditelnost
		if ( self::TAX !== $taxonomy || ! in_array( $term_id, self::restricted_ids(), true ) ) {
			return;
		}

		$kids = get_terms( [ 'taxonomy' => $taxonomy, 'parent' => $term_id, 'hide_empty' => false, 'fields' => 'ids' ] );
		if ( is_array( $kids ) ) {
			foreach ( $kids as $kid ) {
				$kid = (int) $kid;
				if ( 'public' === self::visibility( $kid ) ) {
					update_term_meta( $kid, self::META_VIS, 'unlisted' );
					if ( '' === (string) get_term_meta( $kid, self::META_TOKEN, true ) ) {
						update_term_meta( $kid, self::META_TOKEN, wp_generate_password( 24, false ) );
					}
				}
			}
		}

		$albums = get_posts( [
			'post_type'          => 'bazantik_album',
			'post_status'        => 'any',
			'posts_per_page'     => -1,
			'fields'             => 'ids',
			'no_found_rows'      => true,
			'bazantik_ignore_privacy' => true,
			'tax_query'          => [ [ 'taxonomy' => $taxonomy, 'field' => 'term_id', 'terms' => [ $term_id ], 'include_children' => false ] ],
		] );
		foreach ( $albums as $album_id ) {
			$album_id = (int) $album_id;
			$other    = wp_get_object_terms( $album_id, $taxonomy, [ 'fields' => 'ids', 'exclude' => [ $term_id ] ] );
			if ( is_array( $other ) && ! empty( $other ) ) {
				continue;
			}
			$album_vis = (string) get_post_meta( $album_id, '_bazantik_visibility', true );
			if ( '' !== $album_vis && 'public' !== $album_vis ) {
				continue;
			}
			update_post_meta( $album_id, '_bazantik_visibility', 'unlisted' );
			if ( '' === (string) get_post_meta( $album_id, '_bazantik_secret_token', true ) ) {
				update_post_meta( $album_id, '_bazantik_secret_token', wp_generate_password( 24, false ) );
			}
		}
	}
}
