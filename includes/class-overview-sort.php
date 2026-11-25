<?php
declare(strict_types=1);

namespace BazantikPhotoGallery;

defined( 'ABSPATH' ) || exit;

/**
 * Pořadí položek v přehledu alb: pro nejvyšší úroveň (volby) i pro každou složku (meta složky).
 * Režimy: výchozí (složky podle názvu, pak alba od nejnovějších), podle názvu, podle data, ruční.
 * Ruční pořadí je seznam klíčů „f12“ (složka) a „a34“ (album) pro danou úroveň; album ve více složkách má pořadí v každé zvlášť.
 * Stránka „Order“ v Albums umožňuje režim zvolit a ruční pořadí přetáhnout.
 */
class Overview_Sort {
	public const SLUG   = 'bazantik-order';
	public const ACTION = 'bazantik_save_order';
	public const ACTION_NESTED = 'bazantik_apply_order_nested';

	private const MODES       = [ '', 'name_asc', 'name_desc', 'date_desc', 'date_asc', 'manual' ];
	private const OPT_MODE    = 'bazantik_sort_root';
	private const OPT_MANUAL  = 'bazantik_sort_root_manual';
	private const META_MODE   = '_bazantik_sort';
	private const META_MANUAL = '_bazantik_sort_manual';

	public function init(): void {
		add_action( 'admin_menu', [ $this, 'register_menu' ] );
		add_action( 'admin_menu', [ $this, 'menu_after_folders' ], 999 );
		add_action( 'admin_post_' . self::ACTION, [ $this, 'handle_save' ] );
		add_action( 'admin_post_' . self::ACTION_NESTED, [ $this, 'handle_apply_nested' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_admin' ] );
	}

	/* ---------- Čtení a řazení ---------- */

	public static function mode( int $parent_id ): string {
		$mode = $parent_id > 0
			? (string) get_term_meta( $parent_id, self::META_MODE, true )
			: (string) get_option( self::OPT_MODE, '' );
		return in_array( $mode, self::MODES, true ) ? $mode : '';
	}

	/**
	 * @return string[]
	 */
	public static function manual_keys( int $parent_id ): array {
		$raw  = $parent_id > 0
			? (string) get_term_meta( $parent_id, self::META_MANUAL, true )
			: (string) get_option( self::OPT_MANUAL, '' );
		$keys = array_filter( explode( ',', $raw ), static fn( string $k ): bool => 1 === preg_match( '/^[fa]\d+$/', $k ) );
		return array_values( array_unique( $keys ) );
	}

	private static function key( array $item ): string {
		return ( 'folder' === $item['type'] ? 'f' : 'a' ) . $item['id'];
	}

	/**
	 * Seřadí položky úrovně podle zvoleného režimu (výchozí režim pořadí nemění).
	 *
	 * @param array<int,array{type:string,id:int}> $items
	 * @return array<int,array{type:string,id:int}>
	 */
	public static function apply( array $items, int $parent_id ): array {
		$mode = self::mode( $parent_id );
		if ( 'manual' === $mode ) {
			return self::order_manual( $items, self::manual_keys( $parent_id ) );
		}
		return self::sort_by_mode( $items, $mode );
	}

	/**
	 * @param array<int,array{type:string,id:int}> $items
	 * @return array<int,array{type:string,id:int}>
	 */
	private static function sort_by_mode( array $items, string $mode ): array {
		if ( '' === $mode || 'manual' === $mode ) {
			return $items;
		}

		$folders = [];
		$albums  = [];
		foreach ( $items as $item ) {
			if ( 'folder' === $item['type'] ) {
				$folders[] = $item;
			} else {
				$albums[] = $item;
			}
		}
		$by_name = str_starts_with( $mode, 'name' );
		$desc    = str_ends_with( $mode, 'desc' );
		$value   = static function ( array $item ) use ( $by_name ) {
			if ( 'folder' === $item['type'] ) {
				if ( ! $by_name ) {
					return $item['id']; // složka nemá datum, o pořadí vzniku vypovídá její ID
				}
				$term = get_term( $item['id'], Folder_Access::TAX );
				return $term instanceof \WP_Term ? $term->name : '';
			}
			return $by_name ? (string) get_post_field( 'post_title', $item['id'] ) : (int) get_post_time( 'U', true, $item['id'] );
		};
		$cmp     = static function ( array $a, array $b ) use ( $value, $by_name, $desc ): int {
			$r = $by_name ? strnatcasecmp( (string) $value( $a ), (string) $value( $b ) ) : ( $value( $a ) <=> $value( $b ) );
			return $desc ? -$r : $r;
		};
		usort( $folders, $cmp );
		usort( $albums, $cmp );
		return array_merge( $folders, $albums );
	}

	/**
	 * Položky v ručním pořadí; co v seznamu není (nové položky), jde na konec v původním pořadí.
	 *
	 * @param array<int,array{type:string,id:int}> $items
	 * @param string[]                             $keys
	 * @return array<int,array{type:string,id:int}>
	 */
	private static function order_manual( array $items, array $keys ): array {
		$pos = array_flip( $keys );
		$n   = count( $pos );
		$tmp = [];
		foreach ( $items as $i => $item ) {
			$tmp[] = [ $pos[ self::key( $item ) ] ?? $n + $i, $item ];
		}
		usort( $tmp, static fn( array $a, array $b ): int => $a[0] <=> $b[0] );
		return array_map( static fn( array $row ): array => $row[1], $tmp );
	}

	/* ---------- Administrace ---------- */

	public function register_menu(): void {
		add_submenu_page(
			'edit.php?post_type=bazantik_album',
			__( 'Order of albums and folders', 'bazantik-photo-gallery' ),
			__( 'Order of albums and folders', 'bazantik-photo-gallery' ),
			'edit_others_posts',
			self::SLUG,
			[ $this, 'render_page' ]
		);
	}

	/**
	 * Přesune položku hned pod „Složky alb“ (submenu se jinak řadí podle pořadí registrace).
	 */
	public function menu_after_folders(): void {
		global $submenu;
		$parent = 'edit.php?post_type=bazantik_album';
		if ( empty( $submenu[ $parent ] ) || ! is_array( $submenu[ $parent ] ) ) {
			return;
		}
		$mine   = null;
		$others = [];
		foreach ( $submenu[ $parent ] as $row ) {
			if ( isset( $row[2] ) && self::SLUG === $row[2] ) {
				$mine = $row;
			} else {
				$others[] = $row;
			}
		}
		if ( null === $mine ) {
			return;
		}
		$result   = [];
		$inserted = false;
		foreach ( $others as $row ) {
			$result[] = $row;
			if ( ! $inserted && isset( $row[2] ) && false !== strpos( (string) $row[2], 'taxonomy=' . Folder_Access::TAX ) ) {
				$result[] = $mine;
				$inserted = true;
			}
		}
		if ( ! $inserted ) {
			$result[] = $mine;
		}
		$submenu[ $parent ] = $result; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- změna pořadí submenu
	}

	public function enqueue_admin( string $hook ): void {
		if ( 'bazantik_album_page_' . self::SLUG !== $hook ) {
			return;
		}
		wp_enqueue_style( 'bazantik-admin-order', BAZANTIK_PLUGIN_URL . 'assets/css/admin-order.css', [ 'dashicons' ], BAZANTIK_VERSION );
		wp_enqueue_script( 'bazantik-admin-order', BAZANTIK_PLUGIN_URL . 'assets/js/admin-order.js', [ 'jquery', 'jquery-ui-sortable', 'jquery-touch-punch' ], BAZANTIK_VERSION, true );
		wp_localize_script( 'bazantik-admin-order', 'bazantikOrder', [
			'up'   => __( 'Move up', 'bazantik-photo-gallery' ),
			'down' => __( 'Move down', 'bazantik-photo-gallery' ),
			'confirmSwitch' => __( 'You have unsaved changes to the order. Switch to another folder and discard them?', 'bazantik-photo-gallery' ),
			'confirmNested' => __( 'The change of the order will be applied to all nested folders and cannot be undone. Do you want to continue?', 'bazantik-photo-gallery' ),
		] );
	}

	/**
	 * Úrovně k výběru: nejvyšší úroveň a všechny složky (s odsazením podle zanoření).
	 *
	 * @return array<int,string>
	 */
	private static function levels(): array {
		$levels = [ 0 => __( 'Top level (overview of all folders and albums outside folders)', 'bazantik-photo-gallery' ) ];
		$terms  = get_terms( [ 'taxonomy' => Folder_Access::TAX, 'hide_empty' => false, 'orderby' => 'name' ] );
		if ( ! is_array( $terms ) ) {
			return $levels;
		}
		$by_parent = [];
		foreach ( $terms as $term ) {
			$by_parent[ (int) $term->parent ][] = $term;
		}
		$walk = static function ( int $parent, int $depth ) use ( &$walk, &$levels, $by_parent ): void {
			foreach ( $by_parent[ $parent ] ?? [] as $term ) {
				$levels[ (int) $term->term_id ] = str_repeat( '— ', $depth ) . $term->name;
				$walk( (int) $term->term_id, $depth + 1 );
			}
		};
		$walk( 0, 0 );
		return $levels;
	}

	private static function modes_labels(): array {
		return [
			''          => __( 'Default (folders by name, then albums newest first)', 'bazantik-photo-gallery' ),
			'name_asc'  => __( 'By name A–Z (folders first)', 'bazantik-photo-gallery' ),
			'name_desc' => __( 'By name Z–A (folders first)', 'bazantik-photo-gallery' ),
			'date_desc' => __( 'Newest first (folders first)', 'bazantik-photo-gallery' ),
			'date_asc'  => __( 'Oldest first (folders first)', 'bazantik-photo-gallery' ),
			'manual'    => __( 'Manual order', 'bazantik-photo-gallery' ),
		];
	}

	public function render_page(): void {
		if ( ! current_user_can( 'edit_others_posts' ) ) {
			wp_die( esc_html__( 'You do not have permission to perform this action.', 'bazantik-photo-gallery' ), 403 );
		}
		$levels = self::levels();
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- jen výběr zobrazené úrovně
		$level = isset( $_GET['level'] ) ? absint( $_GET['level'] ) : 0;
		if ( ! isset( $levels[ $level ] ) ) {
			$level = 0;
		}
		$mode   = self::mode( $level );
		$raw    = Overview::all_items( $level );
		$manual = self::order_manual( $raw, self::manual_keys( $level ) );
		$items  = 'manual' === $mode ? $manual : self::sort_by_mode( $raw, $mode );

		// Pořadí pro každý režim spočítá server, aby náhled v prohlížeči přesně odpovídal výsledku po uložení
		$orders = [];
		foreach ( self::MODES as $m ) {
			$orders[ $m ] = array_map( [ self::class, 'key' ], 'manual' === $m ? $manual : self::sort_by_mode( $raw, $m ) );
		}

		$nested_ids = self::nested_ids( $level );
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- jen zpráva o uložení
		$just_changed = isset( $_GET['bazantik_order_changed'] );

		echo '<div class="wrap bazantik-order-wrap"><h1>' . esc_html__( 'Order of albums and folders', 'bazantik-photo-gallery' ) . '</h1>';
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- jen zpráva o uložení
		if ( isset( $_GET['bazantik_order_saved'] ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'The order was saved.', 'bazantik-photo-gallery' ) . '</p></div>';
		}
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- jen zpráva o provedené změně
		if ( isset( $_GET['bazantik_order_nested'] ) ) {
            // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$count = absint( $_GET['bazantik_order_nested'] );
			/* translators: %d: number of folders */
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( sprintf( _n( 'The order was applied to %d nested folder.', 'The order was applied to %d nested folders.', $count, 'bazantik-photo-gallery' ), $count ) ) . '</p></div>';
		}
		echo '<p>' . esc_html__( 'Choose where to edit the order. Each folder has its own order. Nested folders keep their own order – the setting is not inherited by nested folders unless you choose so.', 'bazantik-photo-gallery' ) . '</p>';

		echo '<form method="get" class="bazantik-order-level"><input type="hidden" name="post_type" value="bazantik_album"><input type="hidden" name="page" value="' . esc_attr( self::SLUG ) . '">';
		echo '<label for="bazantik-order-level">' . esc_html__( 'Folder', 'bazantik-photo-gallery' ) . '</label> <select id="bazantik-order-level" name="level">';
		foreach ( $levels as $id => $label ) {
			echo '<option value="' . esc_attr( (string) $id ) . '"' . selected( $level, $id, false ) . '>' . esc_html( $label ) . '</option>';
		}
		echo '</select> <noscript><button type="submit" class="button">' . esc_html__( 'Show', 'bazantik-photo-gallery' ) . '</button></noscript></form>';

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="bazantik-order-form" id="bazantik-order-form" data-orders="' . esc_attr( (string) wp_json_encode( $orders ) ) . '" data-mode="' . esc_attr( $mode ) . '">';
		wp_nonce_field( self::ACTION );
		echo '<input type="hidden" name="action" value="' . esc_attr( self::ACTION ) . '"><input type="hidden" name="level" value="' . esc_attr( (string) $level ) . '">';
		echo '<input type="hidden" name="bazantik_order" id="bazantik-order-input" value="' . esc_attr( implode( ',', $orders['manual'] ) ) . '">';

		echo '<p><label for="bazantik-order-mode"><strong>' . esc_html__( 'Order', 'bazantik-photo-gallery' ) . '</strong></label><br><select id="bazantik-order-mode" name="bazantik_sort">';
		foreach ( self::modes_labels() as $value => $label ) {
			echo '<option value="' . esc_attr( $value ) . '"' . selected( $mode, $value, false ) . '>' . esc_html( $label ) . '</option>';
		}
		echo '</select></p>';

		echo '<div id="bazantik-order-manual">';
		if ( empty( $items ) ) {
			echo '<p>' . esc_html__( 'There is nothing to order on this level yet.', 'bazantik-photo-gallery' ) . '</p>';
		} else {
			echo '<p class="description bazantik-order-hint-manual">' . esc_html__( 'Drag the items by the handle, or use the arrows. New items are added to the end.', 'bazantik-photo-gallery' ) . '</p>';
			echo '<p class="description bazantik-order-hint-auto">' . esc_html__( 'This is a preview of the order given by the selected setting. Choose “Manual order” to rearrange the items by hand.', 'bazantik-photo-gallery' ) . '</p><ul id="bazantik-order-list" class="bazantik-order-list">';
			foreach ( $items as $item ) {
				$folder = 'folder' === $item['type'];
				if ( $folder ) {
					$term  = get_term( $item['id'], Folder_Access::TAX );
					$label = $term instanceof \WP_Term ? $term->name : '';
				} else {
					$label = (string) get_post_field( 'post_title', $item['id'] );
				}
				if ( '' === $label ) {
					$label = __( '(untitled)', 'bazantik-photo-gallery' );
				}
				echo '<li data-key="' . esc_attr( self::key( $item ) ) . '"><span class="bazantik-order-handle dashicons dashicons-menu" aria-hidden="true"></span>'
					. '<span class="dashicons ' . ( $folder ? 'dashicons-category' : 'dashicons-format-gallery' ) . '" aria-hidden="true"></span>'
					. '<span class="bazantik-order-label">' . esc_html( $label ) . '</span>'
					. '<span class="bazantik-order-kind">' . esc_html( $folder ? __( 'Folder', 'bazantik-photo-gallery' ) : __( 'Album', 'bazantik-photo-gallery' ) ) . '</span></li>';
			}
			echo '</ul>';
		}
		echo '</div>';

		$can_nested = $just_changed && 'manual' !== $mode && ! empty( $nested_ids );
		echo '<p class="submit"><button type="submit" class="button button-primary" id="bazantik-order-save" disabled>' . esc_html__( 'Save order', 'bazantik-photo-gallery' ) . '</button> ';
		echo '<button type="submit" form="bazantik-order-nested-form" class="button" id="bazantik-order-nested"' . ( $can_nested ? '' : ' disabled' ) . '>' . esc_html__( 'Apply the change to nested folders', 'bazantik-photo-gallery' ) . '</button></p>';
		echo '</form>';

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" id="bazantik-order-nested-form">';
		wp_nonce_field( self::ACTION_NESTED, '_wpnonce_nested', false );
		echo '<input type="hidden" name="action" value="' . esc_attr( self::ACTION_NESTED ) . '"><input type="hidden" name="level" value="' . esc_attr( (string) $level ) . '"></form>';
		echo '</div>';
	}

	/**
	 * ID všech složek vnořených pod danou úrovní (pro nejvyšší úroveň všechny složky).
	 *
	 * @return int[]
	 */
	private static function nested_ids( int $level ): array {
		if ( $level > 0 ) {
			$ids = get_term_children( $level, Folder_Access::TAX );
		} else {
			$ids = get_terms( [ 'taxonomy' => Folder_Access::TAX, 'hide_empty' => false, 'fields' => 'ids' ] );
		}
		return is_array( $ids ) ? array_map( 'intval', $ids ) : [];
	}

	public function handle_save(): void {
		if ( ! current_user_can( 'edit_others_posts' ) ) {
			wp_die( esc_html__( 'You do not have permission to perform this action.', 'bazantik-photo-gallery' ), 403 );
		}
		check_admin_referer( self::ACTION );

		$level = isset( $_POST['level'] ) ? absint( $_POST['level'] ) : 0;
		if ( $level > 0 && ! get_term( $level, Folder_Access::TAX ) instanceof \WP_Term ) {
			$level = 0;
		}
		$mode = isset( $_POST['bazantik_sort'] ) ? sanitize_text_field( wp_unslash( $_POST['bazantik_sort'] ) ) : '';
		if ( ! in_array( $mode, self::MODES, true ) ) {
			$mode = '';
		}
		$raw  = isset( $_POST['bazantik_order'] ) ? sanitize_text_field( wp_unslash( $_POST['bazantik_order'] ) ) : '';
		$keys = array_values( array_unique( array_filter( explode( ',', $raw ), static fn( string $k ): bool => 1 === preg_match( '/^[fa]\d+$/', $k ) ) ) );
		$list = implode( ',', $keys );

		$changed = self::mode( $level ) !== $mode;
		if ( $level > 0 ) {
			'' === $mode ? delete_term_meta( $level, self::META_MODE ) : update_term_meta( $level, self::META_MODE, $mode );
			'' === $list ? delete_term_meta( $level, self::META_MANUAL ) : update_term_meta( $level, self::META_MANUAL, $list );
		} else {
			'' === $mode ? delete_option( self::OPT_MODE ) : update_option( self::OPT_MODE, $mode, false );
			'' === $list ? delete_option( self::OPT_MANUAL ) : update_option( self::OPT_MANUAL, $list, false );
		}

		$args = [ 'level' => $level, 'bazantik_order_saved' => '1' ];
		if ( $changed ) {
			$args['bazantik_order_changed'] = '1';
		}
		wp_safe_redirect( add_query_arg( $args, admin_url( 'edit.php?post_type=bazantik_album&page=' . self::SLUG ) ) );
		exit;
	}

	/**
	 * Přenese uložený režim řazení dané úrovně na všechny vnořené složky (jejich ruční pořadí se nemění).
	 */
	public function handle_apply_nested(): void {
		if ( ! current_user_can( 'edit_others_posts' ) ) {
			wp_die( esc_html__( 'You do not have permission to perform this action.', 'bazantik-photo-gallery' ), 403 );
		}
		check_admin_referer( self::ACTION_NESTED, '_wpnonce_nested' );

		$level = isset( $_POST['level'] ) ? absint( $_POST['level'] ) : 0;
		if ( $level > 0 && ! get_term( $level, Folder_Access::TAX ) instanceof \WP_Term ) {
			$level = 0;
		}
		$mode = self::mode( $level );
		$ids  = self::nested_ids( $level );
		if ( 'manual' !== $mode ) {
			foreach ( $ids as $id ) {
				'' === $mode ? delete_term_meta( $id, self::META_MODE ) : update_term_meta( $id, self::META_MODE, $mode );
			}
		} else {
			$ids = [];
		}

		wp_safe_redirect( add_query_arg( [ 'level' => $level, 'bazantik_order_nested' => count( $ids ) ], admin_url( 'edit.php?post_type=bazantik_album&page=' . self::SLUG ) ) );
		exit;
	}
}
