<?php
declare(strict_types=1);

namespace BazantikPhotoGallery;

defined( 'ABSPATH' ) || exit;

class Media_Optimizer {
	/** Platí jen pro právě zpracovávanou přílohu (nahrávka do alba nebo úloha workeru). */
	private static bool $in_scope = false;

	public static function set_scope( bool $in_scope ): void {
		self::$in_scope = $in_scope;
	}

	public function detect_scope( int $attachment_id ): void {
		$parent_id      = (int) wp_get_post_parent_id( $attachment_id );
		self::$in_scope = $parent_id > 0 && 'bazantik_album' === get_post_type( $parent_id ) && wp_attachment_is_image( $attachment_id );
	}

	/** Umí editor obrázků na tomto serveru zapisovat WebP? */
	private static function webp_supported(): bool {
		static $supported = null;
		if ( null === $supported ) {
			$supported = wp_image_editor_supports( [ 'mime_type' => 'image/webp' ] );
		}
		return $supported;
	}

	public function init(): void {
		// Zlatý standard 82 %: přesně odpovídá macOS Preview (~5 MB pro 4K náhled při bezchybné ostrosti)
		// Jen pro fotky alb; ostatní obrázky webu zůstanou na nastavení WordPressu a jiných pluginů
		add_filter( 'jpeg_quality', fn( $quality ) => self::$in_scope ? 82 : $quality );
		add_filter( 'wp_editor_set_quality', fn( $quality ) => self::$in_scope ? 82 : $quality );

		// Registrace našeho optimalizovaného ImageMagick editoru (4:2:0 vzorkování + Huffmanovy tabulky jako v macOS)
		add_filter( 'wp_image_editors', [ $this, 'register_custom_image_editor' ] );

		// Kaskádový strop (výchozí 3840 px, dle Nastavení) s prioritou 9999 (přebije Avadu a garantuje 4K zmenšeninu bez duplicit), jen pro fotky alb
		add_filter( 'big_image_size_threshold', fn( $threshold ) => self::$in_scope ? Settings::large_size() : $threshold, 9999 );

		// Přepne generování webových zmenšenin z JPEG do WebP formátu (jen pro fotky alb)
		add_filter( 'image_editor_output_format', function ( array $formats ): array {
					// Bez podpory WebP na serveru zůstanou zmenšeniny v JPEG (jinak by zpracování selhalo)
			if ( self::$in_scope && self::webp_supported() ) {
				$formats['image/jpeg'] = 'image/webp';
			}
					return $formats;
		} );

		// Zeštíhlení: zakáže zbytečné mezistupně (1536, large, medium) a šetří RAM na WEDOS NoLimit (jen pro fotky alb)
		add_filter( 'intermediate_image_sizes_advanced', [ $this, 'filter_intermediate_sizes' ] );

		// Přejmenování Title na čistý název souboru (např. 3A místo fotoaparátového DSC_0475)
		add_filter( 'wp_read_image_metadata', [ $this, 'clean_image_metadata_title' ], 10, 3 );

		// Stabilizace procesoru WEDOSu: omezí Imagick na 1 vlákno (zabrání zasekávání FastCGI při dávkovém uploadu)
		add_action( 'wp_image_editor_before_change', [ $this, 'limit_imagick_threads' ] );

		// Rozsah úprav: nahrávky do alba (příloha s rodičem bazantik_album); ostatní média webu zůstávají beze změny
		add_action( 'add_attachment', [ $this, 'detect_scope' ], 5 );

		// Ochrana CPU na sdíleném hostingu (WEDOS): 0.5s pauza po kompletním zpracování JEDNÉ fotky
		add_filter( 'wp_generate_attachment_metadata', function ( array $metadata ): array {
			if ( self::$in_scope && ! Deferred_Upload::is_active() ) {
				usleep( 500000 ); // Uspí PHP vlákno na 0,5 s (v rychlém režimu uploadu se přeskakuje)
			}
				self::$in_scope = false;
			return $metadata;
		}, 999 );

	}

	/**
	 * Ponechá pouze velikosti nezbytné pro naši galerii a administraci (odstraní balast), jen pro fotky alb
	 */
	public function filter_intermediate_sizes( array $sizes ): array {
		if ( self::$in_scope ) {
			unset( $sizes['medium'], $sizes['large'], $sizes['1536x1536'] );
		}
		return $sizes;
	}

	/**
	 * Zabrání přepsání názvu interním číslem závěrky fotoaparátu (DSC_...) a ponechá čistý název souboru (jen fotky alb)
	 */
	public function clean_image_metadata_title( array $meta, string $file, int $image_type ): array {
		// Nahrávka do alba: rodič je v požadavku už při čtení metadat (příloha ještě neexistuje)
		$parent_id = isset( $_REQUEST['post_id'] ) ? absint( $_REQUEST['post_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- jen určení rozsahu úprav
		if ( self::$in_scope || ( $parent_id > 0 && 'bazantik_album' === get_post_type( $parent_id ) ) ) {
			$meta['title'] = '';
		}
		return $meta;
	}

	/**
	 * Omezí ImageMagick na 1 výpočetní vlákno pro prevenci FastCGI pádů na sdíleném CPU WEDOSu
	 */
	public function limit_imagick_threads( $editor ): void {
		if ( self::$in_scope && is_a( $editor, 'WP_Image_Editor_Imagick' ) && extension_loaded( 'imagick' ) ) {
			if ( method_exists( 'Imagick', 'setResourceLimit' ) ) {
				\Imagick::setResourceLimit( \Imagick::RESOURCETYPE_THREAD, 1 );
			}
		}
	}

	/**
	 * Zaregistruje náš optimalizovaný editor s předností před výchozím editorem WordPressu
	 */
	public function register_custom_image_editor( array $editors ): array {
		if ( self::$in_scope && extension_loaded( 'imagick' ) && class_exists( Image_Editor_Imagick::class ) ) {
			return array_merge( [ Image_Editor_Imagick::class ], $editors );
		}
		return $editors;
	}
}
