<?php
declare(strict_types=1);

namespace BazantikPhotoGallery;

defined( 'ABSPATH' ) || exit;

/**
 * Odolnost knihovny médií: úklid při mazání, ochrana při smazání fotky během zpracování,
 * kontrola chybějících souborů a pojistka pro fotky z foťáku s EXIF Orientation.
 */
class Library_Guard {
	/**
	 * Zapamatuje si fotky, které kdy byly v seznamu alba (ručně odstraněné se pak nenabízejí jako „chybějící“).
	 */
	public static function remember_seen( int $album_id, array $ids ): void {
		$seen   = array_map( 'absint', explode( ',', (string) get_post_meta( $album_id, '_bazantik_seen_ids', true ) ) );
		$merged = array_values( array_unique( array_filter( array_merge( $seen, array_map( 'absint', $ids ) ) ) ) );

		update_post_meta( $album_id, '_bazantik_seen_ids', implode( ',', $merged ) );
	}

	/**
	 * Ponechá jen existující obrázky a videa, která už v albu jsou, nebo které smí uživatel upravovat (nelze podstrčit cizí ID).
	 */
	public static function sanitize_media_ids( int $album_id, array $ids ): array {
		$ids = array_values( array_unique( array_filter( array_map( 'absint', $ids ) ) ) );
		if ( empty( $ids ) ) {
			return [];
		}

		_prime_post_caches( $ids, false, false );
		$current = array_map( 'absint', explode( ',', (string) get_post_meta( $album_id, '_bazantik_media_ids', true ) ) );

		return array_values(
			array_filter(
				$ids,
				static fn( int $id ): bool => 'attachment' === get_post_type( $id )
										&& preg_match( '#^(image|video)/#', (string) get_post_mime_type( $id ) )
										&& ( in_array( $id, $current, true ) || current_user_can( 'edit_post', $id ) )
			)
		);
	}

	public static function seen_ids( int $album_id ): array {
		return array_values( array_filter( array_map( 'absint', explode( ',', (string) get_post_meta( $album_id, '_bazantik_seen_ids', true ) ) ) ) );
	}

	/**
	 * HTML obálky alba: u fotky běžný obrázek, u videa uložený náhled (wp_get_attachment_image() pro video nevrací nic).
	 */
	public static function cover_html( int $attachment_id, string $size = 'medium_large', array $attr = [] ): string {
		if ( 0 === strpos( (string) get_post_mime_type( $attachment_id ), 'video/' ) ) {
			$poster = (string) get_post_meta( $attachment_id, '_bazantik_video_poster_url', true );
			if ( '' === $poster ) {
				return '';
			}
			$style = isset( $attr['style'] ) ? ' style="' . esc_attr( (string) $attr['style'] ) . '"' : '';
			return '<img src="' . esc_url( $poster ) . '" alt="" loading="lazy" decoding="async"' . $style . '>';
		}

		return (string) wp_get_attachment_image( $attachment_id, $size, false, $attr );
	}

	/**
	 * Ke každé z uvedených fotek vrátí názvy alb, ve kterých je zařazená (pro přehled na stránce Zpracování fotek).
	 */
	public static function albums_for( array $ids ): array {
		global $wpdb;

		$wanted = array_flip( array_map( 'absint', $ids ) );
		$map    = [];

		// Jediný dotaz místo načítání všech alb a jejich metadat po jednom (stránka se obnovuje pollingem)
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT p.post_title, m.meta_value FROM {$wpdb->postmeta} m INNER JOIN {$wpdb->posts} p ON p.ID = m.post_id WHERE m.meta_key = %s AND p.post_type = %s AND p.post_status NOT IN ( 'trash', 'auto-draft' )",
				'_bazantik_media_ids',
				'bazantik_album'
			)
		);

		foreach ( (array) $rows as $row ) {
			foreach ( explode( ',', (string) $row->meta_value ) as $part ) {
				$n = absint( $part );
				if ( $n && isset( $wanted[ $n ] ) ) {
					$map[ $n ][] = $row->post_title;
				}
			}
		}

		return $map;
	}

	/**
	 * Smaže soubor s náhledem videa (a související údaje).
	 */
	public static function delete_poster( int $attachment_id ): void {
		$poster = (string) get_post_meta( $attachment_id, '_bazantik_video_poster_path', true );
		if ( '' === $poster ) {
			return;
		}

		$uploads = wp_get_upload_dir();
		wp_delete_file_from_directory( $poster, $uploads['basedir'] );

		delete_post_meta( $attachment_id, '_bazantik_video_poster_path' );
		delete_post_meta( $attachment_id, '_bazantik_video_poster_url' );
	}

	/**
	 * Po nahrání videa přečte údaje z jeho souboru (datum natočení, poloha, přístroj).
	 */
	public function read_video_metadata( $metadata, $attachment_id ) {
		// Ostatní nahrávání webu se netýká: čtou se jen videa nahraná do alba
		if ( 0 === strpos( (string) get_post_mime_type( (int) $attachment_id ), 'video/' ) && 'bazantik_album' === get_post_type( (int) wp_get_post_parent_id( (int) $attachment_id ) ) ) {
			Video_Metadata::apply( (int) $attachment_id );
		}

		return $metadata;
	}

	public const PRIVATE_MEDIA_TRANSIENT = 'bazantik_private_media_ids';

	/**
	 * ID médií zařazených v neveřejných albech (koncept, soukromé, neuvedené, heslo). Platí i pro fotky vybrané z Knihovny médií,
	 * které nemají rodiče alba. Výsledek je krátce uložen v transientu a při změně alba se maže.
	 */
	public static function private_album_media_ids(): array {
		$cached = get_transient( self::PRIVATE_MEDIA_TRANSIENT );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		$ids    = [];
		$albums = get_posts(
			[
				'post_type'      => 'bazantik_album',
				'bazantik_ignore_privacy' => true,
				'post_status'    => [ 'publish', 'future', 'draft', 'pending', 'private', 'auto-draft', 'trash' ],
				'posts_per_page' => -1,
			]
		);

		foreach ( $albums as $album ) {
			$visibility = (string) ( get_post_meta( $album->ID, '_bazantik_visibility', true ) ?: 'public' );
			if ( 'publish' === $album->post_status && 'public' === $visibility && '' === (string) $album->post_password && ! Folder_Access::album_only_restricted( (int) $album->ID ) ) {
				continue;
			}

			foreach ( explode( ',', (string) get_post_meta( $album->ID, '_bazantik_media_ids', true ) ) as $part ) {
				$n = absint( $part );
				if ( $n ) {
					$ids[ $n ] = true;
				}
			}
		}

		$ids = array_keys( $ids );
		set_transient( self::PRIVATE_MEDIA_TRANSIENT, $ids, 10 * MINUTE_IN_SECONDS );

		return $ids;
	}

	public static function flush_private_media_cache(): void {
		delete_transient( self::PRIVATE_MEDIA_TRANSIENT );
	}

	/**
	 * Změna alba nebo jeho seznamu fotek zneplatní uloženou množinu chráněných médií.
	 */
	public function flush_on_album_change( $post_id ): void {
		if ( 'bazantik_album' === get_post_type( (int) $post_id ) ) {
			self::flush_private_media_cache();
		}
	}

	/** Změna složek nebo jejich soukromí mění, která alba jsou veřejně dostupná. */
	public function flush_on_folder_change(): void {
		self::flush_private_media_cache();
	}

	public function flush_on_status_change( $new_status, $old_status, $post ): void {
		if ( $post instanceof \WP_Post && 'bazantik_album' === $post->post_type && $new_status !== $old_status ) {
			self::flush_private_media_cache();
		}
	}

	public function flush_on_meta_change( $meta_id, $post_id, $meta_key ): void {
		if ( in_array( $meta_key, [ '_bazantik_media_ids', '_bazantik_visibility' ], true ) ) {
			self::flush_private_media_cache();
		}
	}

	/**
	 * Smazání neveřejného alba nemaže jeho fotky z Knihovny médií; přestanou ale být chráněné před nalezením.
	 * Upozornění se zobrazí po přesměrování zpět do administrace (bez vyskakovacího okna).
	 */
	public function remember_private_album_removal( $post_id ): void {
		$post_id = (int) $post_id;
		$album   = get_post( $post_id );
		if ( ! $album instanceof \WP_Post || 'bazantik_album' !== $album->post_type || 'trash' === $album->post_status ) {
			return;
		}

		$was_public = 'publish' === $album->post_status
			&& 'public' === ( (string) get_post_meta( $post_id, '_bazantik_visibility', true ) ?: 'public' )
			&& '' === (string) $album->post_password
			&& ! Folder_Access::album_only_restricted( $post_id );
		$has_media  = false;
		if ( ! $was_public ) {
			// Počítají se jen fotky, které ještě existují (smazané z knihovny médií upozornění nevyvolají)
			$media_ids = array_filter( array_map( 'absint', explode( ',', (string) get_post_meta( $post_id, '_bazantik_media_ids', true ) ) ) );
			if ( ! empty( $media_ids ) ) {
				_prime_post_caches( $media_ids, false, false );
				foreach ( $media_ids as $media_id ) {
					if ( 'attachment' === get_post_type( $media_id ) ) {
						$has_media = true;
						break;
					}
				}
			}
		}

		if ( ! $was_public && $has_media ) {
			set_transient( 'bazantik_removed_private_' . get_current_user_id(), (string) $album->post_title, 5 * MINUTE_IN_SECONDS );
		}
	}

	public function show_private_album_removal_notice(): void {
		$key   = 'bazantik_removed_private_' . get_current_user_id();
		$title = get_transient( $key );
		if ( false === $title ) {
			return;
		}
		delete_transient( $key );

		printf(
			'<div class="notice notice-warning is-dismissible"><p>%s</p></div>',
			esc_html(
				sprintf(
					/* translators: %s: album title */
					__( 'The non-public album “%s” was removed, but its photos remain in the Media Library (Media → Library) and are no longer protected from being found through attachment pages and the public REST listing. If you do not want to keep them, delete them in the Media Library.', 'bazantik-photo-gallery' ),
					(string) $title
				)
			)
		);
	}

	public function init(): void {
		foreach ( [ 'save_post_bazantik_album', 'deleted_post', 'trashed_post', 'untrashed_post' ] as $hook ) {
			add_action( $hook, [ $this, 'flush_on_album_change' ] );
		}
		foreach ( [ 'added_post_meta', 'updated_post_meta', 'deleted_post_meta' ] as $hook ) {
			add_action( $hook, [ $this, 'flush_on_meta_change' ], 10, 3 );
		}
		add_action( 'transition_post_status', [ $this, 'flush_on_status_change' ], 10, 3 );
		foreach ( [ 'set_object_terms', 'created_bazantik_folder', 'edited_bazantik_folder', 'delete_bazantik_folder' ] as $hook ) {
			add_action( $hook, [ $this, 'flush_on_folder_change' ], 20 );
		}

		add_action( 'wp_trash_post', [ $this, 'remember_private_album_removal' ] );
		add_action( 'before_delete_post', [ $this, 'remember_private_album_removal' ] );
		add_action( 'admin_notices', [ $this, 'show_private_album_removal_notice' ] );

		add_action( 'delete_attachment', [ $this, 'delete_preview_file' ] );
		add_filter( 'wp_generate_attachment_metadata', [ $this, 'read_video_metadata' ], 25, 2 );
		add_filter( 'wp_read_image_metadata', [ $this, 'ignore_orientation_on_fast_upload' ], 20 );
	}

	/**
	 * Při rychlém nahrání neotáčíme celou fotku (je to drahé). Náhled se otočí sám a 4K verze při dokončování.
	 */
	public function ignore_orientation_on_fast_upload( $meta ) {
		if ( Deferred_Upload::is_active() && is_array( $meta ) ) {
			$meta['orientation'] = 0;
		}
		return $meta;
	}

	/**
	 * Při smazání přílohy smaže i náhled videa a (ze starších verzí) případný vlastní náhledový soubor.
	 */
	public function delete_preview_file( $post_id ): void {
		self::delete_poster( (int) $post_id );

		$path = (string) get_post_meta( (int) $post_id, '_bazantik_custom_preview_path', true );
		if ( '' === $path ) {
			return;
		}

		$uploads = wp_get_upload_dir();
		wp_delete_file_from_directory( $path, $uploads['basedir'] );
	}

	/**
	 * Existuje příloha opravdu v databázi? (bez mezipaměti, protože fotku mohl smazat jiný požadavek)
	 */
	public static function attachment_exists( int $id ): bool {
		global $wpdb;

		return (bool) $wpdb->get_var( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE ID = %d", $id ) );
	}

	/**
	 * Smaže osiřelá metadata po neexistující příloze (jen pokud příloha opravdu neexistuje).
	 */
	public static function purge_meta( int $id ): void {
		global $wpdb;

		if ( self::attachment_exists( $id ) ) {
			return;
		}

		$wpdb->delete( $wpdb->postmeta, [ 'post_id' => $id ] );
		wp_cache_delete( $id, 'post_meta' );
	}

	/**
	 * Smaže soubory, které mezitím vytvořil worker pro fotku, jež už neexistuje.
	 */
	public static function discard_files( array $meta, string $original ): void {
		$dir   = trailingslashit( dirname( $original ) );
		$files = [ $original ];

		if ( ! empty( $meta['file'] ) ) {
			$files[] = $dir . wp_basename( (string) $meta['file'] );
		}

		foreach ( (array) ( $meta['sizes'] ?? [] ) as $size ) {
			if ( ! empty( $size['file'] ) ) {
				$files[] = $dir . wp_basename( (string) $size['file'] );
			}
		}

		$uploads = wp_get_upload_dir();
		foreach ( array_unique( $files ) as $file ) {
			wp_delete_file_from_directory( $file, $uploads['basedir'] );
		}
	}

	/**
	 * Zkontroluje, že fotce nechybí hlavní soubor, originál ani žádná z velikostí. Vrací popis problému, nebo null.
	 */
	/** Fotka je nenávratně ztracená, jen když chybí hlavní soubor nebo originál (chybějící zmenšeniny worker vytvoří znovu). */
	public static function is_unrecoverable( int $id ): bool {
		$main = get_attached_file( $id );
		if ( ! $main || ! is_file( $main ) ) {
			return true;
		}
		$original = wp_get_original_image_path( $id );
		return (bool) ( $original && ! is_file( $original ) );
	}

	public static function check_files( int $id ): ?string {
		$main = get_attached_file( $id );
		if ( ! $main || ! is_file( $main ) ) {
			return __( 'The photo file is missing on disk.', 'bazantik-photo-gallery' );
		}

		$original = wp_get_original_image_path( $id );
		if ( $original && ! is_file( $original ) ) {
			return __( 'The original photo file is missing.', 'bazantik-photo-gallery' );
		}

		$meta = wp_get_attachment_metadata( $id );
		$dir  = trailingslashit( dirname( $main ) );

		foreach ( (array) ( is_array( $meta ) ? ( $meta['sizes'] ?? [] ) : [] ) as $name => $size ) {
			$path = $dir . wp_basename( (string) ( $size['file'] ?? '' ) );
			if ( ! is_file( $path ) ) {
				/* translators: %s: image size name */
				return sprintf( __( 'The file for size “%s” is missing.', 'bazantik-photo-gallery' ), $name );
			}
		}

		if ( is_array( $meta ) && wp_attachment_is_image( $id ) ) {
			return self::incomplete_sizes( $meta );
		}

		return null;
	}

	/**
	 * Zkontroluje, že zpracovaná fotka má velikosti, bez kterých by galerie sahala po originálu:
	 * zmenšenou velkou verzi (-scaled) u fotky nad 3840 px a náhled 768 px (dlaždice mřížky).
	 * WordPress totiž při selhání editoru obrázku tiše vrátí metadata bez velikostí a bez chyby.
	 */
	public static function incomplete_sizes( array $meta ): ?string {
		$width  = (int) ( $meta['width'] ?? 0 );
		$height = (int) ( $meta['height'] ?? 0 );
		if ( $width < 1 || $height < 1 ) {
			return null;
		}

		if ( empty( $meta['original_image'] ) && max( $width, $height ) > 3840 ) {
			return __( 'The large (scaled) version of the photo was not created.', 'bazantik-photo-gallery' );
		}

		$required = (array) apply_filters( 'bazantik_required_image_sizes', [ 'thumbnail', 'medium_large', '2048x2048' ] );
		if ( $width > 768 && in_array( 'medium_large', $required, true ) && empty( $meta['sizes']['medium_large'] ) ) {
			return __( 'The 768 px preview of the photo was not created.', 'bazantik-photo-gallery' );
		}

		return null;
	}

	/**
	 * ID všech médií, která jsou zařazená v nějakém albu.
	 */
	public static function album_media_ids(): array {
		$ids    = [];
		$albums = get_posts(
			[
				'post_type'      => 'bazantik_album',
				'bazantik_ignore_privacy' => true,
				'post_status'    => 'any',
				'posts_per_page' => -1,
				'fields'         => 'ids',
			]
		);

		foreach ( $albums as $album_id ) {
			$raw = (string) get_post_meta( (int) $album_id, '_bazantik_media_ids', true );
			if ( '' === $raw ) {
				continue;
			}
			foreach ( explode( ',', $raw ) as $part ) {
				$n = absint( $part );
				if ( $n ) {
					$ids[ $n ] = true;
				}
			}
		}

		return array_keys( $ids );
	}

	/**
	 * Zkontroluje soubory všech fotek v albech. Fotky s chybějícím souborem označí jako „Selhalo“.
	 */
	public static function verify_library( int $budget = 25 ): array {
		if ( function_exists( 'set_time_limit' ) ) {
			set_time_limit( $budget + 30 );
		}

		$start   = microtime( true );
		$checked = 0;
		$missing = 0;

		foreach ( array_chunk( self::album_media_ids(), 200 ) as $chunk ) {
			_prime_post_caches( $chunk, false, true );

			foreach ( $chunk as $id ) {
				if ( ( microtime( true ) - $start ) > $budget ) {
					break 2;
				}

				if ( 'attachment' !== get_post_type( $id ) ) {
					continue;
				}

				$status = Processing_Status::get( $id );
				if ( Processing_Status::PENDING === $status || Processing_Status::PROCESSING === $status ) {
					continue;
				}

				++$checked;
				$problem = self::check_files( $id );
				if ( null === $problem ) {
					continue;
				}

				++$missing;

				if ( Processing_Status::FAILED !== $status ) {
					update_post_meta( $id, Processing_Status::META_ERROR, $problem );
					update_post_meta( $id, Processing_Status::META_ATTEMPTS, Processing_Queue::MAX_ATTEMPTS );
					Processing_Status::set( $id, Processing_Status::FAILED );
				}
			}
		}

		return [
			'checked' => $checked,
			'missing' => $missing,
		];
	}
}
