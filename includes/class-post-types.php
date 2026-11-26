<?php
declare(strict_types=1);

namespace BazantikPhotoGallery;

defined( 'ABSPATH' ) || exit;

class Post_Types {
	/** Samotný základ adresy alba nebo složky (bez názvu) nikam nevede, proto míří na přehled alb. */
	public static function redirect_bare_bases(): void {
		if ( ! is_404() ) {
			return;
		}

		$path  = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_parse_url( esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ), PHP_URL_PATH ) : '';
		$home  = trim( (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH ), '/' );
		$path  = trim( $path, '/' );
		$path  = '' !== $home && 0 === strpos( $path, $home . '/' ) ? substr( $path, strlen( $home ) + 1 ) : $path;
		$bases = [ Settings::slug( Settings::OPT_SLUG_ALBUM ), Settings::slug( Settings::OPT_SLUG_FOLDER ) ];

		if ( in_array( $path, $bases, true ) ) {
			$target = get_post_type_archive_link( 'bazantik_album' );
			if ( $target ) {
				wp_safe_redirect( $target, 302 );
				exit;
			}
		}
	}

	/**
	 * Titulek přehledu na webu (záložka prohlížeče) je „Gallery“ jako nadpis stránky; popisek „Albums“ zůstává jen v administraci,
	 * protože přehled obsahuje složky i alba.
	 */
	public static function archive_title( $title, $post_type = '' ) {
		return ( 'bazantik_album' === $post_type && ! is_admin() ) ? __( 'Gallery', 'bazantik-photo-gallery' ) : $title;
	}

	/** WordPress po uložení vytahuje zaškrtnuté složky na začátek seznamu, takže vnořená složka vypadá, že nemá rodiče. */
	public static function keep_folder_tree( $args ) {
		if ( is_array( $args ) && isset( $args['taxonomy'] ) && 'bazantik_folder' === $args['taxonomy'] ) {
			$args['checked_ontop'] = false;
		}
		return $args;
	}

	public static function register(): void {
		// Custom Post Type: bazantik_album
		$album_labels = [
			'name'               => __( 'Albums', 'bazantik-photo-gallery' ),
			'all_items'          => __( 'Albums', 'bazantik-photo-gallery' ),
			'singular_name'      => __( 'Photo album', 'bazantik-photo-gallery' ),
			'menu_name'          => __( 'Bazantik Photo Gallery', 'bazantik-photo-gallery' ),
			'add_new_item'       => __( 'Add new album', 'bazantik-photo-gallery' ),
			'edit_item'          => __( 'Edit album', 'bazantik-photo-gallery' ),
			'view_item'          => __( 'View album', 'bazantik-photo-gallery' ),
			'search_items'       => __( 'Search albums', 'bazantik-photo-gallery' ),
			'not_found'          => __( 'No albums found', 'bazantik-photo-gallery' ),
		];

		register_post_type( 'bazantik_album', [
			'labels'             => $album_labels,
			'public'             => true,
			'has_archive'        => Settings::slug( Settings::OPT_SLUG_ARCHIVE ),
			'rewrite'            => [ 'slug' => Settings::slug( Settings::OPT_SLUG_ALBUM ), 'with_front' => false ],
			'supports'           => [ 'title', 'editor', 'thumbnail' ],
			'menu_icon'          => 'dashicons-format-gallery',
			'show_in_rest'       => false,
		] );

		// Hierarchická taxonomie pro složky a podsložky
		$folder_labels = [
			'name'              => __( 'Album folders', 'bazantik-photo-gallery' ),
			'singular_name'     => __( 'Folder', 'bazantik-photo-gallery' ),
			'search_items'      => __( 'Search folders', 'bazantik-photo-gallery' ),
			'all_items'         => __( 'All folders', 'bazantik-photo-gallery' ),
			'parent_item'       => __( 'Parent folder', 'bazantik-photo-gallery' ),
			'parent_item_colon' => __( 'Parent folder:', 'bazantik-photo-gallery' ),
			'parent_field_description' => __( 'To create a folder hierarchy, choose a parent folder in which the new folder will be nested.', 'bazantik-photo-gallery' ),
			'edit_item'         => __( 'Edit folder', 'bazantik-photo-gallery' ),
			'update_item'       => __( 'Update folder', 'bazantik-photo-gallery' ),
			'add_new_item'      => __( 'Add new folder', 'bazantik-photo-gallery' ),
			'menu_name'         => __( 'Album folders', 'bazantik-photo-gallery' ),
			'back_to_items'     => __( '← Back to album folders', 'bazantik-photo-gallery' ),
		];

		register_taxonomy( 'bazantik_folder', [ 'bazantik_album' ], [
			'hierarchical'      => true,
			'labels'            => $folder_labels,
			'show_ui'           => true,
			'show_admin_column' => true,
			'query_var'         => true,
			'rewrite'           => [ 'slug' => Settings::slug( Settings::OPT_SLUG_FOLDER ), 'hierarchical' => true ],
			'show_in_rest'      => false,
		] );
	}
}
