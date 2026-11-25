<?php
declare(strict_types=1);

namespace BazantikPhotoGallery;

defined( 'ABSPATH' ) || exit;

class Shortcodes_Blocks {

	/** Jak dlouho (s) se uchovává odlehčený výstup alba pro návštěvníky. */
	private const FRAGMENT_TTL = 15;
	public function init(): void {
		add_shortcode( 'bazantik_album', [ $this, 'render_album_shortcode' ] );
		add_shortcode( 'bazantik_albums_archive', [ $this, 'render_archive_shortcode' ] );
		add_action( 'init', [ $this, 'register_block' ] );
		add_action( 'rest_api_init', [ $this, 'register_rest_routes' ] );
		add_action( 'template_redirect', [ $this, 'maybe_render_fragment' ], 1 );
	}

	/**
	 * Odlehčený výstup galerie pro průběžné doplňování nehotových dlaždic (volá ho JS na stránce alba).
	 * Přístup (heslo, tajný odkaz) řeší stejný kód jako při běžném zobrazení alba.
	 */
	public function maybe_render_fragment(): void {
		if ( ! isset( $_GET['bazantik_fragment'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- veřejný odlehčený výstup, nic se neukládá
			return;
		}

		$album_id = absint( $_GET['bazantik_fragment'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- veřejný odlehčený výstup, nic se neukládá

		// Jen publikovaná alba (koncepty vidí pouze lidé, kteří je smějí upravovat)
		if ( ! $album_id || 'bazantik_album' !== get_post_type( $album_id ) || ( 'publish' !== get_post_status( $album_id ) && ! current_user_can( 'edit_post', $album_id ) ) ) {
			status_header( 404 );
			exit;
		}

		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', true );
		}
		nocache_headers();
		header( 'Content-Type: text/html; charset=utf-8' );

		// Návštěvníci (desítky za jednou IP, např. ve škole) sdílejí jednu krátce uloženou odpověď; autor alba vždy vidí aktuální stav
		$cache_key = '';
		if ( ! current_user_can( 'edit_post', $album_id ) ) {
			if ( ! $this->visitor_has_access( $album_id ) ) {
				status_header( 404 );
				exit;
			}
			$cache_key = 'bazantik_frag_' . $album_id . '_' . substr( md5( Settings::notice_audience() . Settings::map_mode() ), 0, 8 );
			$cached    = get_transient( $cache_key );
			if ( is_string( $cached ) && '' !== $cached ) {
				echo $cached; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- výstup je již escapovaný
				exit;
			}
		}

		$html = $this->render_album_shortcode( [ 'id' => $album_id ] );

		// Bez přístupu (heslo, chybějící klíč) se nevrací prázdná stránka s 200, ale 404 jako u skrytého alba
		if ( '' === $html || false !== strpos( $html, 'bazantik-password-form-wrapper' ) ) {
			status_header( 404 );
			exit;
		}

		if ( '' !== $cache_key ) {
			set_transient( $cache_key, $html, self::FRAGMENT_TTL );
		}

		echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- výstup je již escapovaný
		exit;
	}

	/**
	 * Smí návštěvník bez práva úprav vidět album? Pravidla odpovídají kontrole v render_album_shortcode();
	 * používá se před načtením krátké mezipaměti odlehčeného výstupu, aby se přes ni nedalo obejít heslo ani tajný odkaz.
	 */
	public static function visitor_has_access( int $album_id ): bool {
		if ( 'publish' !== get_post_status( $album_id ) || post_password_required( $album_id ) ) {
			return false;
		}

		$visibility = get_post_meta( $album_id, '_bazantik_visibility', true ) ?: 'public';
		// Soukromí „heslo“ bez uloženého hesla platí jako skryté (stejně jako v Access_Control::enforce_album_privacy)
		if ( 'password' === $visibility && '' === (string) get_post_field( 'post_password', $album_id ) ) {
			$visibility = 'unlisted';
		}

		if ( 'unlisted' === $visibility ) {
			$token    = (string) get_post_meta( $album_id, '_bazantik_secret_token', true );
			$provided = sanitize_text_field( wp_unslash( $_GET['key'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- tajný klíč v adrese
			return '' !== $token && hash_equals( $token, $provided );
		}

		return true;
	}

	public function register_rest_routes(): void {
		register_rest_route(
			'bazantik-photo-gallery/v1',
			'/albums',
			[
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => [ $this, 'get_albums_for_block' ],
				'permission_callback' => fn(): bool => current_user_can( 'edit_posts' ),
			]
		);
	}

	public function get_albums_for_block(): \WP_REST_Response {
		$query_args = [
			'post_type'      => 'bazantik_album',
			'posts_per_page' => 150,
			'post_status'    => [ 'publish', 'draft' ],
			'orderby'        => 'title',
			'order'          => 'ASC',
		];

		// Ochrana před únikem privátních dat: autoři s nižšími právy vidí pouze svá vlastní alba
		if ( ! current_user_can( 'edit_others_posts' ) ) {
			$query_args['author'] = get_current_user_id();
		}

		$posts = get_posts( $query_args );

		$albums = [];
		foreach ( $posts as $p ) {
			$vis = get_post_meta( $p->ID, '_bazantik_visibility', true ) ?: 'public';
			$icon = 'public' === $vis ? '🟢' : ( 'unlisted' === $vis ? '🔗' : '🔒' );
			$albums[] = [
				'id'    => $p->ID,
				'title' => "{$icon} " . ( $p->post_title ?: __( '(untitled)', 'bazantik-photo-gallery' ) ),
			];
		}
		return new \WP_REST_Response( $albums, 200 );
	}

	/** Na stránce už je (nebo je rezervováno místo pro) plná galerie; další alba se vykreslí jako karty. */
	private static bool $gallery_rendered = false;

	public function render_album_shortcode( array $atts ): string {
		$parsed  = shortcode_atts( [ 'id' => 0 ], $atts, 'bazantik_album' );
		$post_id = absint( $parsed['id'] );

		if ( ! $post_id || 'bazantik_album' !== get_post_type( $post_id ) ) {
			return '';
		}

		$visibility = get_post_meta( $post_id, '_bazantik_visibility', true ) ?: 'public';
		$can_edit   = current_user_can( 'edit_post', $post_id );
		// Soukromí „heslo“ bez uloženého hesla platí jako skryté (stejně jako ve visitor_has_access)
		if ( 'password' === $visibility && '' === (string) get_post_field( 'post_password', $post_id ) ) {
			$visibility = 'unlisted';
		}

		// Zabezpečení neveřejných alb vložených přes shortcode nebo Gutenberg blok
		if ( ! $can_edit ) {
			if ( 'publish' !== get_post_status( $post_id ) ) {
				return '';
			}

			if ( post_password_required( $post_id ) ) {
				// Formulář hesla drží místo plné galerie na stránce (po zadání hesla se na stejném místě vykreslí galerie, další alba jsou karty)
				self::$gallery_rendered = true;
				// Formulář vrací návštěvníka na tuto stránku (viz Album_Password)
				$form = get_the_password_form( $post_id );
				return '<div class="bazantik-password-form-wrapper">' . $form . '</div>';
			}

			if ( 'unlisted' === $visibility ) {
				$token    = (string) get_post_meta( $post_id, '_bazantik_secret_token', true );
				$provided = sanitize_text_field( wp_unslash( $_GET['key'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- tajný klíč v adrese

				if ( empty( $token ) || ! hash_equals( $token, $provided ) ) {
					return ''; // Neoprávněným návštěvníkům se skrytý obsah vůbec nevykreslí
				}
			}
		}

		// Ochrana před uložením privátního alba do veřejné cache (LiteSpeed, Cloudflare, Nginx)
		if ( 'public' !== $visibility || '' !== (string) get_post_field( 'post_password', $post_id ) ) {
			if ( ! defined( 'DONOTCACHEPAGE' ) ) {
				define( 'DONOTCACHEPAGE', true );
			}
			do_action( 'litespeed_control_set_nocache', 'bazantik_private_album' );
			if ( ! headers_sent() ) {
				header( 'Cache-Control: no-store, no-cache, must-revalidate, max-age=0' );
				header( 'Referrer-Policy: strict-origin-when-cross-origin' );
				nocache_headers();
			}
		}

		ob_start();

		if ( self::$gallery_rendered && ! isset( $_GET['bazantik_fragment'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- veřejný odlehčený výstup, nic se neukládá
			// Další album na téže stránce: mřížka a lightbox počítají s jednou galerií na stránku, proto se vykreslí jako karta s odkazem
			echo $this->render_album_card( $post_id ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- výstup je již escapovaný
		} else {
			self::$gallery_rendered = true;
			echo '<div class="bazantik-album-embed">';
			$this->render_gallery_markup( $post_id );
			echo '</div>';
		}
		return ob_get_clean();
	}

	/**
	 * Karta alba s odkazem (použije se, když je na stránce víc alb: lightbox počítá s jednou galerií na stránku).
	 */
	private function render_album_card( int $album_id ): string {
		$cover_id = (int) get_post_meta( $album_id, '_bazantik_cover_id', true );
		if ( ! $cover_id ) {
			$raw      = (string) get_post_meta( $album_id, '_bazantik_media_ids', true );
			$cover_id = '' !== $raw ? (int) explode( ',', $raw )[0] : 0;
		}

		$cover = ( $cover_id && Processing_Status::is_ready( $cover_id ) )
		? Library_Guard::cover_html( $cover_id, 'medium_large' )
		: '<div class="bazantik-no-cover"></div>';

		$url = get_permalink( $album_id );
		if ( 'unlisted' === (string) get_post_meta( $album_id, '_bazantik_visibility', true ) ) {
			$url = add_query_arg( 'key', rawurlencode( (string) get_post_meta( $album_id, '_bazantik_secret_token', true ) ), $url );
		}

		return '<div class="bazantik-albums-archive-grid bazantik-albums-archive-grid--embedded"><a href="' . esc_url( $url ) . '" class="bazantik-album-card"><div class="bazantik-album-card-cover">' . $cover . '</div><div class="bazantik-album-card-meta"><div class="bazantik-album-card-title">' . esc_html( get_the_title( $album_id ) ) . '</div>' . Album_Stats::render( $album_id ) . '</div></a></div>';
	}

	public function render_archive_shortcode( array $atts ): string {
		Plugin::get_instance()->enqueue_styles();
		return Overview::render( 0, true );
	}

	public function register_block(): void {
		if ( ! function_exists( 'register_block_type' ) ) {
			return;
		}

		wp_register_script(
			'bazantik-block-editor',
			BAZANTIK_PLUGIN_URL . 'assets/js/block.js',
			[ 'wp-blocks', 'wp-element', 'wp-block-editor', 'wp-components', 'wp-api-fetch', 'wp-i18n' ],
			BAZANTIK_VERSION,
			true
		);

		wp_set_script_translations( 'bazantik-block-editor', 'bazantik-photo-gallery' );

		// Metadata bloku (název, atributy) jsou v block.json, zde se doplňuje jen skript editoru a vykreslení
		register_block_type( BAZANTIK_PLUGIN_DIR . 'block.json', [
			'editor_script'   => 'bazantik-block-editor',
			'render_callback' => function ( array $attributes ): string {
				$album_id = absint( $attributes['albumId'] ?? 0 );
				return $album_id > 0 ? $this->render_album_shortcode( [ 'id' => $album_id ] ) : '';
			},
		] );
	}

	public function render_gallery_markup( int $album_id ): void {
		// Pojistka pro Avada Fusion Builder: garantuje načtení assetů a DOMu v patičce i z vnořených kontejnerů
		Plugin::get_instance()->enqueue_assets();

		$raw_ids   = get_post_meta( $album_id, '_bazantik_media_ids', true );
		$ids       = ! empty( $raw_ids ) ? array_filter( array_map( 'absint', explode( ',', $raw_ids ) ) ) : [];
		$show_exif = get_post_meta( $album_id, '_bazantik_show_exif', true ) !== '0';
		$show_map  = Settings::album_shows_map( $album_id );

		if ( empty( $ids ) ) {
			echo '<p class="bazantik-empty-notice">' . esc_html__( 'This album does not contain any photos yet.', 'bazantik-photo-gallery' ) . '</p>';
			return;
		}

		// Hromadné načtení postů a metadat v 1 SQL dotazu
		_prime_post_caches( $ids, false, true );

		// Smazané přílohy a neúspěšné fotky se nezobrazují ani nezapočítávají (jinak by součet neodpovídal dlaždicím)
		$ids = array_values( array_filter( $ids, static function ( int $id ): bool {
			$att = get_post( $id );
			return $att && 'attachment' === $att->post_type && Processing_Status::is_visible( $id );
		} ) );
		$total_count = count( $ids ); // počet skutečně zobrazených dlaždic (hotové + čekající)

		// Upozornění, že album ještě není kompletní: komu se ukáže, určuje volba bazantik_notice_audience ('all' výchozí, 'editors')
		$ready_count = count( array_filter( $ids, [ Processing_Status::class, 'is_ready' ] ) );
		$show_notice = 'editors' !== Settings::notice_audience() || current_user_can( 'edit_post', $album_id );
		if ( $ready_count < $total_count && $show_notice ) {
			echo '<p class="bazantik-processing-notice" style="text-align:center;opacity:.7;margin:0 0 16px;">' . esc_html(
				sprintf(
																																/* translators: 1: number of photos shown, 2: total number of photos in the album */
					_n(
						'Processed %1$d of %2$d photo. The remaining photo is being processed right now, the album fills in gradually.',
						'Processed %1$d of %2$d photos. The remaining photos are being processed right now, the album fills in gradually.',
						$total_count,
						'bazantik-photo-gallery'
					),
					$ready_count,
					$total_count
				)
			) . '</p>';
		}
		if ( empty( $ids ) ) {
			echo '<p class="bazantik-empty-notice">' . esc_html__( 'This album does not contain any photos that can be displayed yet.', 'bazantik-photo-gallery' ) . '</p>';
			return;
		}
		?>
		<div class="bazantik-justified-grid bazantik-nojs" data-album-id="<?php echo esc_attr( (string) $album_id ); ?>" data-mobile-zoom="<?php echo esc_attr( Settings::mobile_zoom_for_album( $album_id ) ); ?>"<?php echo current_user_can( 'edit_post', $album_id ) ? ' data-bazantik-editor="1"' : ''; ?>>
		<?php
		foreach ( $ids as $index => $media_id ) :
			$post = get_post( $media_id );
			if ( ! $post ) {
				continue;
			}

			// Fotka se ještě zpracovává: rozostřená dlaždice se správnými rozměry (mřížka se po dokončení nepřeskládá)
			if ( ! Processing_Status::is_ready( $media_id ) ) {
				$ph_exif = get_post_meta( $media_id, '_bazantik_exif_data', true );
				$ph_exif = is_array( $ph_exif ) ? $ph_exif : [];
				$ph_meta = (array) wp_get_attachment_metadata( $media_id );
				$ph_w    = ! empty( $ph_exif['orig_width'] ) ? (int) $ph_exif['orig_width'] : ( ! empty( $ph_meta['width'] ) ? (int) $ph_meta['width'] : 3 );
				$ph_h    = ! empty( $ph_exif['orig_height'] ) ? (int) $ph_exif['orig_height'] : ( ! empty( $ph_meta['height'] ) ? (int) $ph_meta['height'] : 2 );
				$ph_img  = wp_get_attachment_image_src( $media_id, 'thumbnail' );
				$ph_data = [
					'index'  => $index,
					'type'   => 'pending',
					'width'  => $ph_w,
					'height' => $ph_h,
				];

				echo '<div class="bazantik-grid-item bazantik-grid-item--pending" style="--bazantik-ar:' . esc_attr( $ph_w . ' / ' . $ph_h ) . '" data-attachment-id="' . esc_attr( (string) $media_id ) . '" data-media="' . esc_attr( (string) wp_json_encode( $ph_data ) ) . '"><div class="bazantik-item-wrapper">';
				if ( ! empty( $ph_img[0] ) ) {
					echo '<img src="' . esc_url( (string) $ph_img[0] ) . '" alt="" decoding="async">';
				}
				echo '<div class="bazantik-pending-overlay" aria-hidden="true"><svg viewBox="0 0 40 40" width="36" height="36" class="bazantik-spinner-svg"><circle cx="20" cy="5" r="2.8" opacity="1"/><circle cx="30.6" cy="9.4" r="2.8" opacity="0.875"/><circle cx="35" cy="20" r="2.8" opacity="0.75"/><circle cx="30.6" cy="30.6" r="2.8" opacity="0.625"/><circle cx="20" cy="35" r="2.8" opacity="0.5"/><circle cx="9.4" cy="30.6" r="2.8" opacity="0.375"/><circle cx="5" cy="20" r="2.8" opacity="0.25"/><circle cx="9.4" cy="9.4" r="2.8" opacity="0.125"/></svg></div>';
				echo '</div></div>';
				continue;
			}

			$mime = (string) $post->post_mime_type;
			// Starší HEIC přílohy (převedené WordPressem na JPEG) mají stále typ HEIC: opraví se jednorázově
			if ( in_array( $mime, [ 'image/heic', 'image/heif' ], true ) && preg_match( '/\.jpe?g$/i', (string) get_attached_file( $media_id ) ) ) {
				wp_update_post( [ 'ID' => $media_id, 'post_mime_type' => 'image/jpeg' ] );
				$mime = 'image/jpeg';
			}
			$is_video = str_starts_with( $mime, 'video/' );

			// Zajištění přístupu ke skutečnému nekomprimovanému originálu bez zásahu WordPress -scaled limitu
			$true_original = function_exists( 'wp_get_original_image_url' )
			? ( wp_get_original_image_url( $media_id ) ?: (string) wp_get_attachment_url( $media_id ) )
			: (string) wp_get_attachment_url( $media_id );

			// Zachovaný HEIC prohlížeč nezobrazí ani se nestáhne jako použitelná fotka: použije se převedený JPEG
			if ( preg_match( '/\.hei[cf]$/i', (string) wp_parse_url( $true_original, PHP_URL_PATH ) ) ) {
				$true_original = (string) wp_get_attachment_url( $media_id );
			}
			$original_url = $true_original;

			$meta = wp_get_attachment_metadata( $media_id );
			$raw_exif = get_post_meta( $media_id, '_bazantik_exif_data', true );
			$raw_exif = is_array( $raw_exif ) ? $raw_exif : [];
			$exif     = $show_exif ? $raw_exif : [];
			// Souřadnice a pomocné řazení se do HTML nikdy neposílají v poli EXIF (GPS jen samostatně a jen při zapnuté mapě)
			unset( $exif['gps'], $exif['captured_sort'] );

			// Rozměry bereme primárně z předem uložených dat v DB, sekundárně z metadat WP (0 diskových dotazů)
			$orig_w = ! empty( $exif['orig_width'] ) ? (int) $exif['orig_width'] : ( ! empty( $meta['width'] ) ? (int) $meta['width'] : 0 );
			$orig_h = ! empty( $exif['orig_height'] ) ? (int) $exif['orig_height'] : ( ! empty( $meta['height'] ) ? (int) $meta['height'] : 0 );

			if ( ! $orig_w || ! $orig_h ) {
				$img_data = wp_get_attachment_image_src( $media_id, 'full' );
				if ( $img_data ) {
					$orig_w = (int) $img_data[1];
					$orig_h = (int) $img_data[2];
				}
			}

			// Sestavení responzivních variant: 'full' nese fyzický nekomprimovaný originál z Nikonu
			$sources = [
				'full' => [
					'url' => $original_url,
					'w'   => $orig_w,
					'h'   => $orig_h,
				],
			];

			// Zmenšeniny registrovaných mezistupňů (medium_large 768 px pro mřížku, 2048x2048 pro mobily)
			$registered_sizes = [ 'medium', 'medium_large', 'large', '1536x1536', '2048x2048' ];
			foreach ( $registered_sizes as $sz ) {
				$src_info = wp_get_attachment_image_src( $media_id, $sz );
				if ( $src_info && ! empty( $src_info[0] ) && isset( $meta['sizes'][ $sz ] ) ) {
					$sources[ $sz ] = [
						'url' => (string) $src_info[0],
						'w'   => (int) $src_info[1],
						'h'   => (int) $src_info[2],
					];
				}
			}

			// 4K prezentační vrstva z kaskády: použijeme 3840px soubor -scaled vygenerovaný WordPress jádrem
			$scaled_info = wp_get_attachment_image_src( $media_id, 'full' );
			if ( $scaled_info && ! empty( $scaled_info[0] ) ) {
				$scaled_url = (string) $scaled_info[0];
				$scaled_w   = (int) $scaled_info[1];
				$scaled_h   = (int) $scaled_info[2];

				// Pokud WordPress vytvořil -scaled zmenšeninu (např. 3840 px)
				if ( $scaled_url !== $original_url || $scaled_w >= 2560 ) {
					$sources['bazantik_4k'] = [
						'url' => $scaled_url,
						'w'   => $scaled_w,
						'h'   => $scaled_h,
					];
				}
			}

			// Zpětná kompatibilita pro dříve nahrané soubory, které mají fyzickou velikost bazantik_4k
			$legacy_4k = wp_get_attachment_image_src( $media_id, 'bazantik_4k' );
			if ( $legacy_4k && ! empty( $legacy_4k[0] ) ) {
				$sources['bazantik_4k'] = [
					'url' => (string) $legacy_4k[0],
					'w'   => (int) $legacy_4k[1],
					'h'   => (int) $legacy_4k[2],
				];
			}

			$grid_thumb_img = wp_get_attachment_image_src( $media_id, 'medium_large' )
			?: wp_get_attachment_image_src( $media_id, 'medium' );
			$thumb_url = $is_video ? includes_url( 'images/media/video.png' ) : ( ! empty( $grid_thumb_img[0] ) ? (string) $grid_thumb_img[0] : $original_url );

			// Garance existence $file_path a sestavení původního názvu včetně mezer a velkých písmen z Title
			$file_path = get_attached_file( $media_id );
			$clean_file_basename = ! empty( $meta['original_image'] ) && ! preg_match( '/\.hei[cf]$/i', (string) $meta['original_image'] )
			? wp_basename( $meta['original_image'] )
			: ( $file_path ? preg_replace( '/-scaled(\.[a-zA-Z0-9]+)$/i', '$1', wp_basename( $file_path ) ) : '' );

			$file_ext = pathinfo( $clean_file_basename, PATHINFO_EXTENSION );
			// Pokud má fotka hezký Title s mezerami (např. "3C - crazy"), použijeme ho pro stažení i infopanel
			$human_filename = ! empty( $post->post_title ) ? $post->post_title . ( $file_ext ? '.' . $file_ext : '' ) : $clean_file_basename;

			$exif['filename']       = sanitize_text_field( $human_filename );
			$exif['filename_clean'] = sanitize_text_field( pathinfo( $human_filename, PATHINFO_FILENAME ) );
			// Video: náhled a skutečné rozměry (zachytil je prohlížeč při nahrání), aby dlaždice měla správnou orientaci
			$poster_url = '';
			if ( $is_video ) {
				$poster_url = (string) get_post_meta( $media_id, '_bazantik_video_poster_url', true );
				$video_w    = (int) get_post_meta( $media_id, '_bazantik_video_w', true );
				$video_h    = (int) get_post_meta( $media_id, '_bazantik_video_h', true );
				if ( $video_w && $video_h ) {
					$orig_w = $video_w;
					$orig_h = $video_h;
				}
			}

			$exif['dimensions'] = ( $orig_w && $orig_h ) ? "{$orig_w} × {$orig_h} px" : '';

			// U videa WordPress eviduje jen základní údaje; čas vytvoření z jeho metadat odpovídá času nahrání, proto se nezobrazuje
			if ( $is_video && is_array( $meta ) ) {
				if ( ! empty( $meta['filesize'] ) ) {
					$exif['filesize'] = size_format( (int) $meta['filesize'], 2 );
				}
			}

			if ( ! empty( $exif['camera'] ) ) {
				$cam = (string) $exif['camera'];
				$cam = preg_replace( '/\b(CORPORATION|CORP\.?|CO\.?,?\s*LTD\.?|INC\.?|AG|GMBH)\b/i', '', $cam );
				$cam = trim( preg_replace( '/\s+/', ' ', $cam ) );
				$cam = preg_replace( '/^([A-Za-z0-9]+)\s+\1\b/i', '$1', $cam );
				$exif['camera'] = $cam;
			}

			if ( ! empty( $exif['captured_at'] ) ) {
				$raw_date = trim( (string) $exif['captured_at'] );
				if ( preg_match( '/^(\d{4})[:\.\-](\d{2})[:\.\-](\d{2})\s*(\d{2}:\d{2})?/', $raw_date, $m ) ) {
					// Údaj z EXIF je čas bez pásma; formátuje se v UTC, aby se neposunul.
					$has_time = ! empty( $m[4] );
					$stamp    = gmmktime( $has_time ? (int) substr( $m[4], 0, 2 ) : 0, $has_time ? (int) substr( $m[4], 3, 2 ) : 0, 0, (int) $m[2], (int) $m[3], (int) $m[1] );
					$format   = get_option( 'date_format' ) . ( $has_time ? ' ' . get_option( 'time_format' ) : '' );
					$exif['captured_at'] = wp_date( $format, $stamp, new \DateTimeZone( 'UTC' ) );
				}
			}

			$gps = ( $show_map && ! empty( $raw_exif['gps'] ) ) ? $raw_exif['gps'] : null;

			$filename_clean = pathinfo( $clean_file_basename, PATHINFO_FILENAME );
			$clean_title    = preg_replace( '/-scaled$/', '', $filename_clean );
			$display_title  = wp_get_attachment_caption( $media_id ) ?: ( $post->post_title ?: $clean_title );

			$item_data = [
				'index'    => $index,
				'type'     => $is_video ? 'video' : 'image',
				'original' => $original_url,
				'sources'  => $sources,
				'width'    => $orig_w,
				'height'   => $orig_h,
				'caption'  => $display_title,
				'poster'   => $poster_url,
				'exif'     => $exif,
				'gps'      => $gps,
			];

			// Bez mapy u alba se JPEG stahuje přes Download (bez GPS); JPEG převedený z HEIC vždy (aby dostal EXIF z HEIC); ostatní formáty zůstávají na přímém odkazu
			if ( ! $is_video && 'image/jpeg' === $mime && ( ! $show_map || Download::has_heic_source( $media_id ) ) ) {
				$item_data['download'] = Download::url( $album_id, $media_id );
			}

			$is_above_fold = $index < 8;
			?>
		<div class="bazantik-grid-item" style="--bazantik-ar:<?php echo esc_attr( ( $orig_w && $orig_h ) ? $orig_w . ' / ' . $orig_h : '3 / 2' ); ?>" data-attachment-id="<?php echo esc_attr( (string) $media_id ); ?>" data-media="<?php echo esc_attr( (string) wp_json_encode( $item_data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) ); ?>">
		<div class="bazantik-item-wrapper">
			<?php if ( $is_video ) : ?>
				<?php if ( '' !== $poster_url ) : ?>
				<img src="<?php echo esc_url( $poster_url ); ?>" loading="lazy" decoding="async" alt="<?php echo esc_attr( $display_title ); ?>">
				<?php else : ?>
					<div class="bazantik-video-placeholder"></div>
					<?php endif; ?>
		<div class="bazantik-play-overlay"><svg width="22" height="22" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true" focusable="false"><path d="M8 5v14l11-7z"/></svg></div>
		<?php else : ?>
			<img src="<?php echo esc_url( $thumb_url ); ?>"
		loading="<?php echo $is_above_fold ? 'eager' : 'lazy'; ?>"
			<?php if ( $is_above_fold ) : ?>fetchpriority="high"<?php endif; ?>
		decoding="async"
		alt="<?php echo esc_attr( $display_title ); ?>">
		<?php endif; ?>
		</div>
		<?php /* Bez JavaScriptu je dlaždice obyčejný odkaz na soubor (s JavaScriptem se noscript ignoruje) */ ?>
		<noscript><a class="bazantik-nojs-link" style="position:absolute;top:0;right:0;bottom:0;left:0;z-index:2;display:block" href="<?php echo esc_url( $original_url ); ?>" aria-label="<?php echo esc_attr( $display_title ); ?>"></a></noscript>
		</div>
		<?php endforeach; ?>
		</div>
		<?php
		if ( Settings::show_credit() ) {
			echo '<p class="bazantik-credit" style="margin:1em 0 0;text-align:center;font-size:12px;opacity:.7"><a href="' . esc_url( Settings::URL_PLUGIN ) . '" target="_blank" rel="noopener">' . esc_html__( 'Gallery by Bazantik Photo Gallery', 'bazantik-photo-gallery' ) . '</a></p>';
		}
	}
}
