<?php
declare(strict_types=1);

namespace BazantikPhotoGallery;

defined( 'ABSPATH' ) || exit;

/**
 * Přehled alb a složek: společné vykreslování pro /galerie/, stránku složky a shortcode [bazantik_albums_archive].
 * Na úrovni 0 jsou složky nejvyšší úrovně a alba mimo složky; ve složce její podsložky a alba.
 * Skrytá alba a složky se nevypisují (správce vidí vše), zaheslovaná se ukážou jako zamčené karty bez obálky.
 */
class Overview {
	private const PER_PAGE = 24;

	/** @var array<int,int[]> */
	private static array $album_cache = [];

	private static bool $private_seen = false;

	/** Složka, ve které se výpis zobrazuje (karta alba si ji předá drobečkové navigaci alba). */
	private static int $context_folder = 0;

	public static function render( int $parent_id, bool $embedded = false ): string {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- jen číslo stránky veřejného výpisu
		$page  = isset( $_GET['bazantik_page'] ) ? max( 1, absint( $_GET['bazantik_page'] ) ) : 1;
		self::$context_folder = $parent_id;
		$items = self::collect( $parent_id );

		if ( empty( $items ) ) {
			return '<p>' . esc_html__( 'No albums or folders available.', 'bazantik-photo-gallery' ) . '</p>';
		}

		$total = (int) ceil( count( $items ) / self::PER_PAGE );
		$page  = min( $page, max( 1, $total ) );
		$slice = array_slice( $items, ( $page - 1 ) * self::PER_PAGE, self::PER_PAGE );

		$admin = Folder_Access::is_manager();
		self::prime_slice( $slice );
		$html  = '<div class="bazantik-albums-archive-grid' . ( $embedded ? ' bazantik-albums-archive-grid--embedded' : '' ) . '">';
		foreach ( $slice as $item ) {
			$html .= 'folder' === $item['type'] ? self::folder_card( $item['id'], $admin ) : self::album_card( $item['id'], $admin );
		}
		$html .= '</div>';

		if ( self::$private_seen ) {
			Folder_Access::mark_nocache();
		}

		if ( $total > 1 ) {
			$html .= '<nav class="bazantik-archive-pagination" aria-label="' . esc_attr__( 'Album pagination', 'bazantik-photo-gallery' ) . '">' . wp_kses_post( (string) paginate_links( [
				'base'      => esc_url_raw( add_query_arg( 'bazantik_page', '%#%' ) ),
				'format'    => '',
				'current'   => $page,
				'total'     => $total,
				'prev_text' => '‹',
				'next_text' => '›',
			] ) ) . '</nav>';
		}
		return $html;
	}

	/**
	 * Položky přehledu v pořadí: složky podle názvu, pak alba od nejnovějších.
	 *
	 * @return array<int,array{type:string,id:int}>
	 */
	private static function collect( int $parent_id ): array {
		$admin = Folder_Access::is_manager();
		$items = [];

		foreach ( self::visible_folders( $parent_id, $admin ) as $term ) {
			$items[] = [ 'type' => 'folder', 'id' => (int) $term->term_id ];
		}

		foreach ( self::album_ids( $parent_id ) as $album_id ) {
			$items[] = [ 'type' => 'album', 'id' => $album_id ];
		}
		return Overview_Sort::apply( $items, $parent_id );
	}

	/**
	 * Podsložky, které se v přehledu úrovně vypisují (stejný filtr pro karty i pro počty).
	 *
	 * @return \WP_Term[]
	 */
	private static function visible_folders( int $parent_id, bool $admin ): array {
		$out = [];
		foreach ( self::child_folders( $parent_id ) as $term ) {
			if ( ! $admin ) {
				$vis = Folder_Access::visibility( $term->term_id );
				// Skrytá složka se ukáže jen návštěvníkovi, který ji otevřel platným odkazem (cookie)
				if ( 'unlisted' === $vis && ! Folder_Access::is_link_unlocked( $term->term_id ) ) {
					continue;
				}
				if ( 'public' === $vis && empty( self::album_ids( $term->term_id ) ) && empty( self::listable_children( $term->term_id ) ) ) {
					continue;
				}
			}
			$out[] = $term;
		}
		return $out;
	}

	/**
	 * Všechny složky a alba úrovně bez ohledu na viditelnost (pro stránku řazení ve správě).
	 *
	 * @return array<int,array{type:string,id:int}>
	 */
	public static function all_items( int $parent_id ): array {
		$items = [];
		foreach ( self::child_folders( $parent_id ) as $term ) {
			$items[] = [ 'type' => 'folder', 'id' => (int) $term->term_id ];
		}
		foreach ( self::album_ids( $parent_id ) as $album_id ) {
			$items[] = [ 'type' => 'album', 'id' => $album_id ];
		}
		return $items;
	}

	/**
	 * Hromadně načte obálky karet na stránce (příspěvky a meta jedním dotazem místo dotazů pro každou kartu).
	 *
	 * @param array<int,array{type:string,id:int}> $slice
	 */
	private static function prime_slice( array $slice ): void {
		$album_ids = [];
		foreach ( $slice as $item ) {
			if ( 'album' === $item['type'] ) {
				$album_ids[] = $item['id'];
			} else {
				$album_ids = array_merge( $album_ids, self::album_ids( $item['id'] ) );
			}
		}
		$album_ids = array_values( array_unique( $album_ids ) );
		if ( empty( $album_ids ) ) {
			return;
		}
		_prime_post_caches( $album_ids, false, true );

		$cover_ids = [];
		foreach ( $album_ids as $album_id ) {
			$cover = (int) get_post_meta( $album_id, '_bazantik_cover_id', true );
			if ( ! $cover ) {
				$raw   = (string) get_post_meta( $album_id, '_bazantik_media_ids', true );
				$cover = '' !== $raw ? (int) explode( ',', $raw )[0] : 0;
			}
			if ( $cover ) {
				$cover_ids[] = $cover;
			}
		}
		if ( ! empty( $cover_ids ) ) {
			_prime_post_caches( array_values( array_unique( $cover_ids ) ), false, true );
		}
	}

	/**
	 * @return \WP_Term[]
	 */
	private static function child_folders( int $parent_id ): array {
		$terms = get_terms( [ 'taxonomy' => Folder_Access::TAX, 'parent' => $parent_id, 'hide_empty' => false, 'orderby' => 'name' ] );
		return is_array( $terms ) ? $terms : [];
	}

	/**
	 * @return \WP_Term[]
	 */
	private static function listable_children( int $term_id ): array {
		return array_filter(
			self::child_folders( $term_id ),
			static fn( \WP_Term $t ) => 'unlisted' !== Folder_Access::visibility( $t->term_id ) || Folder_Access::is_link_unlocked( $t->term_id )
		);
	}

	/**
	 * ID alb, která se v dané úrovni vypisují (0 = alba mimo složky). Neveřejná (skrytá) alba se nevypisují.
	 *
	 * @return int[]
	 */
	private static function album_ids( int $parent_id ): array {
		if ( isset( self::$album_cache[ $parent_id ] ) ) {
			return self::$album_cache[ $parent_id ];
		}

		$args = [
			'post_type'          => 'bazantik_album',
			'post_status'        => 'publish',
			'posts_per_page'     => -1,
			'fields'             => 'ids',
			'no_found_rows'      => true,
			'orderby'            => 'date',
			'order'              => 'DESC',
			'bazantik_ignore_privacy' => true,
			'tax_query'          => [
				$parent_id > 0
					? [ 'taxonomy' => Folder_Access::TAX, 'field' => 'term_id', 'terms' => [ $parent_id ], 'include_children' => false ]
					: [ 'taxonomy' => Folder_Access::TAX, 'operator' => 'NOT EXISTS' ],
			],
		];
		if ( ! Folder_Access::is_manager() ) {
			$args['meta_query'] = [
				'relation' => 'OR',
				[ 'key' => '_bazantik_visibility', 'compare' => 'NOT EXISTS' ],
				[ 'key' => '_bazantik_visibility', 'value' => [ 'public', 'password' ], 'compare' => 'IN' ],
			];
		}

		$ids = array_map( 'intval', get_posts( $args ) );
		if ( ! Folder_Access::is_manager() ) {
			// Soukromí „heslo“ bez uloženého hesla platí jako skryté, v přehledu se neukáže
			$ids = array_values( array_filter( $ids, static fn( int $id ): bool => 'password' !== (string) get_post_meta( $id, '_bazantik_visibility', true ) || '' !== (string) get_post_field( 'post_password', $id ) ) );
		}
		if ( ! empty( $ids ) ) {
			_prime_post_caches( $ids, false, true );
		}
		self::$album_cache[ $parent_id ] = $ids;
		return $ids;
	}

	private static function is_locked_for_viewer( int $album_id ): bool {
		if ( current_user_can( 'edit_post', $album_id ) ) {
			return false;
		}
		$locked = 'password' === (string) get_post_meta( $album_id, '_bazantik_visibility', true ) || '' !== (string) get_post_field( 'post_password', $album_id );
		return $locked && post_password_required( $album_id );
	}

	private static function is_plain_public( int $album_id ): bool {
		$vis = (string) get_post_meta( $album_id, '_bazantik_visibility', true );
		return ( '' === $vis || 'public' === $vis ) && '' === (string) get_post_field( 'post_password', $album_id );
	}

	private static function lock_icon(): string {
		return '<svg class="bazantik-lock-icon" width="44" height="44" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><rect x="5" y="11" width="14" height="10" rx="2"/><path d="M8 11V8a4 4 0 0 1 8 0v3"/></svg>';
	}

	private static function folder_icon( int $size = 44 ): string {
		return '<svg width="' . $size . '" height="' . $size . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M3 7a2 2 0 0 1 2-2h4l2 2h8a2 2 0 0 1 2 2v8a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/></svg>';
	}

	private static function album_cover_html( int $album_id ): string {
		$cover_id = (int) get_post_meta( $album_id, '_bazantik_cover_id', true );
		if ( ! $cover_id ) {
			$raw      = (string) get_post_meta( $album_id, '_bazantik_media_ids', true );
			$cover_id = '' !== $raw ? (int) explode( ',', $raw )[0] : 0;
		}
		if ( $cover_id && Processing_Status::is_ready( $cover_id ) ) {
			return Library_Guard::cover_html( $cover_id, 'medium_large' );
		}
		$thumb = get_the_post_thumbnail( $album_id, 'medium_large' );
		return '' !== $thumb ? $thumb : '<div class="bazantik-no-cover"></div>';
	}

	private static function album_card( int $album_id, bool $admin ): string {
		$url   = (string) get_permalink( $album_id );
		if ( self::$context_folder > 0 ) {
			$url = add_query_arg( 'bazantik_folder', self::$context_folder, $url );
		}
		$title = (string) get_post_field( 'post_title', $album_id );
		if ( '' === $title ) {
			$title = __( '(untitled)', 'bazantik-photo-gallery' );
		}

		if ( self::is_locked_for_viewer( $album_id ) ) {
			self::$private_seen = true;
			return '<a href="' . esc_url( $url ) . '" class="bazantik-album-card bazantik-album-card--locked"><div class="bazantik-album-card-cover bazantik-card-locked-cover">' . self::lock_icon() . '</div>'
				. '<div class="bazantik-album-card-meta"><div class="bazantik-album-card-title">' . Access_Control::vis_icon( 'lock' ) . esc_html( $title ) . '</div>'
				. '<div class="bazantik-album-card-stats">' . esc_html__( 'Protected with a password', 'bazantik-photo-gallery' ) . '</div></div></a>';
		}

		if ( ! self::is_plain_public( $album_id ) ) {
			self::$private_seen = true;
		}
		// Správci vidí ikonu u všech neveřejných alb, návštěvník jen otevřený zámek u alba, které sám odemkl
		$badge = $admin ? Access_Control::visibility_badge( $album_id ) : '';
		if ( '' === $badge && '' !== (string) get_post_field( 'post_password', $album_id ) ) {
			$badge = Access_Control::vis_icon( 'unlock' );
		}
		return '<a href="' . esc_url( $url ) . '" class="bazantik-album-card"><div class="bazantik-album-card-cover">' . self::album_cover_html( $album_id ) . '</div>'
			. '<div class="bazantik-album-card-meta"><div class="bazantik-album-card-title">' . $badge . esc_html( $title ) . '</div>' . Album_Stats::render( $album_id ) . '</div></a>';
	}

	/**
	 * Naplní přebal složky (max. 4 buňky): nejdřív alba přímo ve složce, pak alba ve veřejných podsložkách.
	 * Do podsložek se sestupuje jen přes veřejné, takže přebal neprozradí obsah skrytých ani zaheslovaných složek.
	 *
	 * @param string[] $cells
	 */
	private static function collect_cover_cells( int $term_id, array &$cells, int $depth ): void {
		$ordered = Overview_Sort::apply( array_map( static fn( int $id ): array => [ 'type' => 'album', 'id' => $id ], self::album_ids( $term_id ) ), $term_id );
		// Přebaly jdou ve stejném pořadí, v jakém jsou alba uvnitř složky (včetně ručního řazení)
		foreach ( array_column( $ordered, 'id' ) as $album_id ) {
			if ( count( $cells ) >= 4 ) {
				return;
			}
			if ( ! self::is_plain_public( $album_id ) ) {
				continue;
			}
			$html = self::album_cover_html( $album_id );
			if ( '' !== $html && ! str_contains( $html, 'bazantik-no-cover' ) ) {
				$cells[] = '<div class="bazantik-collage-cell">' . $html . '</div>';
			}
		}
		if ( $depth >= 5 ) {
			return;
		}
		foreach ( self::child_folders( $term_id ) as $child ) {
			if ( count( $cells ) >= 4 ) {
				return;
			}
			if ( 'public' === Folder_Access::visibility( $child->term_id ) ) {
				self::collect_cover_cells( $child->term_id, $cells, $depth + 1 );
			}
		}
	}

	private static function folder_card( int $term_id, bool $admin ): string {
		$term = get_term( $term_id, Folder_Access::TAX );
		if ( ! $term instanceof \WP_Term ) {
			return '';
		}
		$link = get_term_link( $term );
		if ( is_wp_error( $link ) ) {
			return '';
		}

		$vis = Folder_Access::visibility( $term_id );
		if ( 'public' !== $vis ) {
			self::$private_seen = true;
		}

		if ( ! $admin && 'password' === $vis && ! Folder_Access::is_unlocked( $term_id ) ) {
			return '<a href="' . esc_url( $link ) . '" class="bazantik-album-card bazantik-album-card--locked bazantik-folder-card"><div class="bazantik-album-card-cover bazantik-card-locked-cover">' . self::lock_icon() . '<span class="bazantik-folder-badge">' . self::folder_icon( 18 ) . '</span></div>'
				. '<div class="bazantik-album-card-meta"><div class="bazantik-album-card-title">' . Access_Control::vis_icon( 'lock' ) . esc_html( $term->name ) . '</div>'
				. '<div class="bazantik-album-card-stats">' . esc_html__( 'Folder protected with a password', 'bazantik-photo-gallery' ) . '</div></div></a>';
		}

		$cells = [];
		$ids   = self::album_ids( $term_id );
		self::collect_cover_cells( $term_id, $cells, 0 );
		if ( $cells ) {
			// Přebal složky: až 4 přebaly alb v mřížce (oříznutí na střed), drobná ikona složky v rohu
			$cover = '<div class="bazantik-collage bazantik-collage-' . count( $cells ) . '">' . implode( '', $cells ) . '</div>'
				. '<span class="bazantik-folder-badge">' . self::folder_icon( 18 ) . '</span>';
		} else {
			$cover = '<div class="bazantik-no-cover">' . self::folder_icon() . '</div>';
		}

		$badge = '';
		if ( $admin ) {
			$badge = Access_Control::vis_icon( 'unlisted' === $vis ? 'link' : ( 'password' === $vis ? ( Folder_Access::is_unlocked( $term_id ) ? 'unlock' : 'lock' ) : '' ) );
		} elseif ( 'password' === $vis ) {
			$badge = Access_Control::vis_icon( 'unlock' );
		} elseif ( 'unlisted' === $vis ) {
			$badge = Access_Control::vis_icon( 'link' );
		}
		$albums  = count( $ids );
		$folders = count( self::visible_folders( $term_id, $admin ) );
		$parts   = [];
		if ( $albums > 0 ) {
			$parts[] = sprintf( /* translators: %d: number of albums */ _n( '%d album', '%d albums', $albums, 'bazantik-photo-gallery' ), $albums );
		}
		if ( $folders > 0 ) {
			$parts[] = sprintf( /* translators: %d: number of folders */ _n( '%d folder', '%d folders', $folders, 'bazantik-photo-gallery' ), $folders );
		}
		$text  = 2 === count( $parts )
			? sprintf( /* translators: 1: number of albums, e.g. "1 album"; 2: number of folders, e.g. "3 folders" */ __( '%1$s and %2$s', 'bazantik-photo-gallery' ), $parts[0], $parts[1] )
			: implode( '', $parts );
		$stats = '' !== $text ? '<div class="bazantik-album-card-stats">' . esc_html( $text ) . '</div>' : '';
		return '<a href="' . esc_url( $link ) . '" class="bazantik-album-card bazantik-folder-card"><div class="bazantik-album-card-cover">' . $cover . '</div>'
			. '<div class="bazantik-album-card-meta"><div class="bazantik-album-card-title">' . $badge . esc_html( $term->name ) . '</div>' . $stats . '</div></a>';
	}
}
