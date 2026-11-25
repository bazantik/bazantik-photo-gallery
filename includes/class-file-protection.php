<?php
declare(strict_types=1);

namespace BazantikPhotoGallery;

defined( 'ABSPATH' ) || exit;

/**
 * Ochrana souborů neveřejných alb:
 *  - fotky nahrané do alba dostanou náhodný název souboru (titulek zůstává původní),
 *  - přílohy alb se nevypisují přes veřejné REST API a nemají veřejné stránky příloh.
 */
class File_Protection {
	/** Platí jen pro právě probíhající nahrávání souboru do alba (jeden požadavek = jeden soubor). */
	private static bool $randomize = false;

	public function init(): void {
		add_filter( 'wp_handle_upload_prefilter', [ $this, 'maybe_flag_upload' ] );
		add_filter( 'wp_unique_filename', [ $this, 'random_filename' ], 10, 3 );
		add_filter( 'wp_handle_upload', [ $this, 'unflag_upload' ] );

		add_action( 'template_redirect', [ $this, 'block_attachment_pages' ], 1 );
		add_filter( 'rest_attachment_query', [ $this, 'filter_rest_media_query' ] );
		add_filter( 'rest_pre_dispatch', [ $this, 'guard_rest_media_item' ], 10, 3 );
	}

	/**
	 * Rozpozná nahrávání do alba. Soubor sám nemění (jinak by se podle nového názvu odvodil i titulek).
	 */
	public function maybe_flag_upload( array $file ): array {
		self::$randomize = false;

		if ( ! apply_filters( 'bazantik_random_filenames', Settings::random_filenames() ) ) {
			return $file;
		}

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- nonce ověřuje samotné nahrávání v jádru WordPressu
		$post_id = isset( $_REQUEST['post_id'] ) ? absint( $_REQUEST['post_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- jen čtení ID rodiče pro filtr dotazu, nic se neukládá

		if ( $post_id && 'bazantik_album' === get_post_type( $post_id ) && current_user_can( 'edit_post', $post_id ) ) {
			self::$randomize = true;
		}

		return $file;
	}

	/**
	 * Náhodný název souboru (přípona zůstává).
	 */
	public function random_filename( $filename, $ext = '', $dir = '' ) {
		if ( ! self::$randomize ) {
			return $filename;
		}

		$ext = (string) $ext;
		$dir = trailingslashit( (string) $dir );

		do {
			$name = bin2hex( random_bytes( 10 ) ) . $ext;
		} while ( file_exists( $dir . $name ) );

		return $name;
	}

	public function unflag_upload( $upload ) {
		self::$randomize = false;
		return $upload;
	}

	/**
	 * Stránky příloh fotek z alb (?attachment_id=123) pro nepovolané vracejí 404 (jinak by šly po číslech procházet).
	 */
	public function block_attachment_pages(): void {
		if ( ! is_attachment() ) {
			return;
		}

		$attachment_id = (int) get_queried_object_id();
		$parent_id     = (int) wp_get_post_parent_id( $attachment_id );
		$is_album_file = $parent_id && 'bazantik_album' === get_post_type( $parent_id );

		if ( $is_album_file ) {
			if ( current_user_can( 'edit_post', $parent_id ) ) {
				return;
			}
		} elseif ( current_user_can( 'edit_post', $attachment_id ) || ! in_array( $attachment_id, Library_Guard::private_album_media_ids(), true ) ) {
			return;
		}

		global $wp_query;
		$wp_query->set_404();
		status_header( 404 );
		nocache_headers();
		include get_query_template( '404' );
		exit;
	}

	/**
	 * Veřejný výpis médií přes REST API nevrací přílohy, které patří albům.
	 */
	public function filter_rest_media_query( array $args ): array {
		// Právo edit_posts má i přispěvatel; neveřejné přílohy alb smí ve výpisu vidět jen správce obsahu
		if ( current_user_can( 'edit_others_posts' ) ) {
			return $args;
		}

		$album_ids = get_posts(
			[
				'post_type'      => 'bazantik_album',
				'bazantik_ignore_privacy' => true,
				'post_status'    => [ 'publish', 'future', 'draft', 'pending', 'private', 'auto-draft', 'trash' ],
				'posts_per_page' => -1,
				'fields'         => 'ids',
			]
		);

		if ( ! empty( $album_ids ) ) {
			$args['post_parent__not_in'] = array_merge( (array) ( $args['post_parent__not_in'] ?? [] ), $album_ids );
			$args                        = self::strip_ids_from_in_list( $args, 'post_parent__in', $album_ids );
		}

		// Fotky vybrané z Knihovny médií nemají rodiče alba, proto se chrání podle seznamu médií neveřejných alb
		$private_ids = Library_Guard::private_album_media_ids();
		if ( ! empty( $private_ids ) ) {
			$args['post__not_in'] = array_merge( (array) ( $args['post__not_in'] ?? [] ), $private_ids );
			$args                 = self::strip_ids_from_in_list( $args, 'post__in', $private_ids );
		}

		return $args;
	}

	/**
	 * WP_Query u „post__in“ a „post_parent__in“ ignoruje odpovídající „__not_in“, proto se chráněná ID musí odebrat
	 * i ze zadaného seznamu (jinak by je šlo vyžádat přes ?include= nebo ?parent=). Prázdný výsledek znamená žádnou shodu.
	 *
	 * @param array<string,mixed> $args
	 * @param int[]               $blocked
	 * @return array<string,mixed>
	 */
	private static function strip_ids_from_in_list( array $args, string $key, array $blocked ): array {
		if ( empty( $args[ $key ] ) ) {
			return $args;
		}

		$left         = array_values( array_diff( array_map( 'absint', (array) $args[ $key ] ), array_map( 'absint', $blocked ) ) );
		$args[ $key ] = empty( $left ) ? [ 0 ] : $left;

		return $args;
	}

	/**
	 * Jednotlivou přílohu alba (/wp/v2/media/123) smí načíst jen ten, kdo smí album upravovat.
	 */
	public function guard_rest_media_item( $result, $server, $request ) {
		if ( null !== $result || ! $request instanceof \WP_REST_Request ) {
			return $result;
		}

		if ( preg_match( '#^/wp/v2/media/(\d+)#', $request->get_route(), $matches ) ) {
			$media_id  = (int) $matches[1];
			$parent_id = (int) wp_get_post_parent_id( $media_id );

			$hidden = ( $parent_id && 'bazantik_album' === get_post_type( $parent_id ) && ! current_user_can( 'edit_post', $parent_id ) )
				|| ( ! current_user_can( 'edit_post', $media_id ) && in_array( $media_id, Library_Guard::private_album_media_ids(), true ) );

			if ( $hidden ) {
				return new \WP_Error( 'rest_post_invalid_id', __( 'Invalid post ID.', 'bazantik-photo-gallery' ), [ 'status' => 404 ] );
			}
		}

		return $result;
	}
}
