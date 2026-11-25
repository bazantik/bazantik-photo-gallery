<?php
declare(strict_types=1);

namespace BazantikPhotoGallery;

defined( 'ABSPATH' ) || exit;

class Access_Control {
	public function init(): void {
		add_action( 'pre_get_posts', [ $this, 'filter_queries' ] );
		add_filter( 'posts_where', [ $this, 'hide_password_albums' ], 10, 2 );
		add_filter( 'wp_sitemaps_posts_query_args', [ $this, 'exclude_from_sitemaps' ], 10, 2 );
		add_action( 'template_redirect', [ $this, 'enforce_album_privacy' ] );
		add_filter( 'wp_robots', [ $this, 'robots_rules' ] );
		add_filter( 'redirect_canonical', [ $this, 'stop_canonical_leak' ], 10, 2 );
		add_filter( 'oembed_response_data', [ $this, 'hide_private_oembed' ], 10, 2 );
		add_filter( 'old_slug_redirect_url', [ $this, 'hide_private_redirect' ] );

		// Ochrana před prozrazením přes šablonové odkazy "Předchozí / Další album"
		foreach ( [ 'previous', 'next' ] as $dir ) {
			add_filter( "get_{$dir}_post_join", [ $this, 'adjacent_join' ], 10, 5 );
			add_filter( "get_{$dir}_post_where", [ $this, 'adjacent_where' ], 10, 5 );
		}
	}

	/** Při zjišťování cíle přesměrování (url_to_postid) se vlastní filtr dotazů nesmí uplatnit, jinak by neveřejné album „nenašel“. */
	private bool $resolving_url = false;

	public function filter_queries( \WP_Query $query ): void {
		if ( $this->resolving_url || ( is_admin() && ! wp_doing_ajax() ) || $query->get( 'bazantik_ignore_privacy' ) ) {
			return;
		}

		if ( $query->is_main_query() ) {
			// Adresa s polem typů (?post_type[]=post&post_type[]=bazantik_album) nebo post_type=any není archiv alb, ale alba by vypsala
			$mixed_types = ! $query->is_singular() && ! current_user_can( 'edit_others_posts' ) && array_intersect( (array) $query->get( 'post_type' ), [ 'bazantik_album', 'any' ] );
			if ( ! ( $mixed_types || $query->is_post_type_archive( 'bazantik_album' ) || $query->is_tax( 'bazantik_folder' ) || $query->is_search() || $query->is_feed() || $query->is_author() ) ) {
				return;
			}
			// Přehled alb a složek vidí správce se všemi alby (neveřejná jsou v něm označena ikonou)
			if ( ( $query->is_post_type_archive( 'bazantik_album' ) || $query->is_tax( 'bazantik_folder' ) ) && ! $query->is_feed() && current_user_can( 'edit_others_posts' ) ) {
				return;
			}
		} elseif ( ! $this->is_public_secondary_query( $query ) ) {
			return;
		}

		// Podmínka soukromí se přidá jako vnořená skupina s AND, aby ji cizí meta_query s relation OR nevyřadil z platnosti
		$existing   = (array) $query->get( 'meta_query', [] );
		$meta_query = [
			'relation' => 'AND',
			[
				'relation' => 'OR',
				[ 'key' => '_bazantik_visibility', 'compare' => 'NOT EXISTS' ],
				[ 'key' => '_bazantik_visibility', 'value' => 'public', 'compare' => '=' ],
			],
		];
		if ( ! empty( $existing ) ) {
			$meta_query[] = $existing;
		}
		$query->set( 'meta_query', $meta_query );
		$query->set( 'bazantik_hide_pw', true );

		// Alba v neveřejných složkách se mimo výpis složky neobjeví (vyhledávání, feedy, archivy, widgety)
		$hidden = Folder_Access::hidden_album_ids();
		if ( ! empty( $hidden ) && ( $query->is_feed() || ! $query->is_tax( 'bazantik_folder' ) ) ) {
			$query->set( 'post__not_in', array_merge( (array) $query->get( 'post__not_in', [] ), $hidden ) );
			// WP_Query při zadaném „post__in“ ignoruje „post__not_in“, proto se skrytá ID odeberou i ze seznamu
			$in = (array) $query->get( 'post__in', [] );
			if ( ! empty( $in ) ) {
				$left = array_values( array_diff( array_map( 'absint', $in ), $hidden ) );
				$query->set( 'post__in', empty( $left ) ? [ 0 ] : $left );
			}
		}
	}

	/**
	 * Album s heslem jádra, ale bez meta soukromí (např. po importu), se nesmí objevit ve veřejných výpisech.
	 * Podmínka se týká jen typu album, běžné chráněné příspěvky ve vyhledávání zůstávají.
	 */
	public function hide_password_albums( string $where, \WP_Query $query ): string {
		if ( $query->get( 'bazantik_hide_pw' ) ) {
			global $wpdb;
			$where .= " AND NOT ({$wpdb->posts}.post_type = 'bazantik_album' AND {$wpdb->posts}.post_password <> '')";
		}
		return $where;
	}

	/**
	 * Vedlejší dotaz na alba na veřejné části webu (widgety, prvky Avady, SEO pluginy). Administrace, REST, AJAX, cron,
	 * WP-CLI a uživatelé s právem upravovat cizí příspěvky se nefiltrují (anonymní AJAX, např. živé hledání, se filtruje), aby alba neztratily vlastní části pluginu.
	 */
	private function is_public_secondary_query( \WP_Query $query ): bool {
		if ( wp_doing_cron() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) || defined( 'WP_CLI' ) ) {
			return false;
		}

		$post_type = $query->get( 'post_type' );
		$types     = is_array( $post_type ) ? $post_type : [ $post_type ];
		// Prázdný typ u hledání, taxonomie složek a feedů jádro rozšíří na všechny typy až po pre_get_posts, takže by alba unikla
		$implicit = empty( array_filter( $types ) ) && ( $query->is_search() || $query->is_tax( 'bazantik_folder' ) || $query->is_feed() );
		if ( ! $implicit && ! array_intersect( $types, [ 'bazantik_album', 'any' ] ) ) {
			return false;
		}

		return ! current_user_can( 'edit_others_posts' );
	}

	/**
	 * Ikona před názvem neveřejného alba v přehledech: zámek u zaheslovaného (otevřený zámek, pokud ho tento prohlížeč už odemkl), odkaz u skrytého.
	 */
	public static function visibility_badge( int $album_id ): string {
		$vis = (string) get_post_meta( $album_id, '_bazantik_visibility', true );
		$has_password = '' !== (string) get_post_field( 'post_password', $album_id );
		if ( $has_password ) {
			return self::vis_icon( post_password_required( $album_id ) ? 'lock' : 'unlock' );
		}
		// Režim s heslem bez uloženého hesla (např. po importu) se chová jako skryté album, proto má ikonu odkazu
		if ( 'password' === $vis ) {
			return self::vis_icon( 'link' );
		}
		return 'unlisted' === $vis ? self::vis_icon( 'link' ) : '';
	}

	/**
	 * Decentní ikona před názvem v přehledech (zámek, otevřený zámek, odkaz); nahrazuje emoji. Vrací hotové HTML.
	 */
	public static function vis_icon( string $kind ): string {
		$paths = [
			'lock'   => '<rect x="5" y="11" width="14" height="10" rx="2"/><path d="M8 11V8a4 4 0 0 1 8 0v3"/>',
			'unlock' => '<rect x="5" y="11" width="14" height="10" rx="2"/><path d="M8 11V8a4 4 0 0 1 7.5-2"/>',
			'link'   => '<path d="M10 13a5 5 0 0 0 7.5.5l3-3a5 5 0 0 0-7-7l-1.7 1.7"/><path d="M14 11a5 5 0 0 0-7.5-.5l-3 3a5 5 0 0 0 7 7l1.7-1.7"/>',
		];
		if ( ! isset( $paths[ $kind ] ) ) {
			return '';
		}
		return '<svg class="bazantik-vis-icon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">' . $paths[ $kind ] . '</svg>';
	}

	public function exclude_from_sitemaps( array $args, string $post_type ): array {
		if ( 'bazantik_album' === $post_type ) {
			$args['meta_query'] = [
				'relation' => 'OR',
				[ 'key' => '_bazantik_visibility', 'compare' => 'NOT EXISTS' ],
				[ 'key' => '_bazantik_visibility', 'value' => 'public', 'compare' => '=' ],
			];
			$args['has_password'] = false;
			$hidden = Folder_Access::hidden_album_ids();
			if ( ! empty( $hidden ) ) {
				$args['post__not_in'] = array_merge( (array) ( $args['post__not_in'] ?? [] ), $hidden );
				if ( ! empty( $args['post__in'] ) ) {
					$left             = array_values( array_diff( array_map( 'absint', (array) $args['post__in'] ), $hidden ) );
					$args['post__in'] = empty( $left ) ? [ 0 ] : $left;
				}
			}
		}
		return $args;
	}

	public function enforce_album_privacy(): void {
		if ( ! is_singular( 'bazantik_album' ) ) {
			return;
		}

		$post_id    = (int) get_the_ID();
		$visibility = get_post_meta( $post_id, '_bazantik_visibility', true ) ?: 'public';
		// Soukromí „heslo“ bez uloženého hesla (např. selhal zápis) se nesmí chovat jako veřejné: platí jako skryté
		if ( 'password' === $visibility && '' === (string) get_post_field( 'post_password', $post_id ) ) {
			$visibility = 'unlisted';
		}

		// Ochrana před cachovacími pluginy a CDN (LiteSpeed Cache, WP Rocket, Cloudflare atd.)
		if ( 'public' !== $visibility || '' !== (string) get_post_field( 'post_password', $post_id ) || Folder_Access::album_in_restricted( $post_id ) ) {
			if ( ! defined( 'DONOTCACHEPAGE' ) ) {
				define( 'DONOTCACHEPAGE', true );
			}
			do_action( 'litespeed_control_set_nocache', 'bazantik_private_album' );
			if ( ! headers_sent() ) {
				header( 'Cache-Control: no-store, no-cache, must-revalidate, max-age=0' );
				// Tajný klíč z adresy se nesmí dostat do hlavičky Referer odkazů vedoucích ven (OSM dlaždice dostanou jen původ)
				header( 'Referrer-Policy: strict-origin-when-cross-origin' );
				nocache_headers();
			}
		}

		if ( current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		if ( 'unlisted' === $visibility ) {
			$token    = (string) get_post_meta( $post_id, '_bazantik_secret_token', true );
			$provided = sanitize_text_field( wp_unslash( $_GET['key'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- tajný klíč v adrese

			if ( empty( $token ) || ! hash_equals( $token, $provided ) ) {
				global $wp_query;
				$wp_query->set_404();
				status_header( 404 );
				nocache_headers();
				include get_query_template( '404' );
				exit;
			}
		}
	}

	public function robots_rules( array $robots ): array {
		if ( is_singular( 'bazantik_album' ) ) {
			$visibility = get_post_meta( (int) get_the_ID(), '_bazantik_visibility', true ) ?: 'public';
			if ( 'public' !== $visibility || Folder_Access::album_only_restricted( (int) get_the_ID() ) ) {
				$robots['noindex']  = true;
				$robots['nofollow'] = true;
			}
		}
		return $robots;
	}

	/**
	 * Neveřejné album se bez platného klíče nesmí kanonickým přesměrováním (např. /?p=ID) prozradit pretty adresou.
	 *
	 * @param mixed $redirect_url
	 * @return mixed
	 */
	public function stop_canonical_leak( $redirect_url, $requested_url ) {
		if ( ! is_string( $redirect_url ) || '' === $redirect_url ) {
			return $redirect_url;
		}
		// Cíl se zjišťuje z adresy: platí i pro odhad slugu z 404 a pro /?p=ID s hezkými odkazy (is_singular() tehdy neplatí).
		$post_id = $this->resolve_post_id( $redirect_url );
		if ( ! $post_id || ! $this->is_hidden_from_visitor( $post_id ) ) {
			return $redirect_url;
		}
		$token    = (string) get_post_meta( $post_id, '_bazantik_secret_token', true );
		$provided = sanitize_text_field( wp_unslash( $_GET['key'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- tajný klíč v adrese
		$unlisted = 'unlisted' === get_post_meta( $post_id, '_bazantik_visibility', true );
		return ( $unlisted && '' !== $token && hash_equals( $token, $provided ) ) ? $redirect_url : false;
	}

	/** url_to_postid() bez uplatnění vlastního filtru dotazů. */
	private function resolve_post_id( string $url ): int {
		$this->resolving_url = true;
		$post_id             = (int) url_to_postid( $url );
		$this->resolving_url = false;
		return $post_id;
	}

	private function is_hidden_from_visitor( int $post_id ): bool {
		if ( 'bazantik_album' !== get_post_type( $post_id ) || current_user_can( 'edit_post', $post_id ) ) {
			return false;
		}
		return 'public' !== ( get_post_meta( $post_id, '_bazantik_visibility', true ) ?: 'public' )
			|| '' !== (string) get_post_field( 'post_password', $post_id )
			|| Folder_Access::album_only_restricted( $post_id );
	}

	/**
	 * oEmbed by jinak prozradil název a náhled neveřejného alba.
	 *
	 * @param mixed $data
	 * @param mixed $post
	 * @return mixed
	 */
	public function hide_private_oembed( $data, $post ) {
		return ( $post instanceof \WP_Post && $this->is_hidden_from_visitor( (int) $post->ID ) ) ? false : $data;
	}

	/**
	 * Přesměrování po změně nebo uhodnutí adresy by neveřejnému albu prozradilo jeho pretty adresu.
	 *
	 * @param mixed $url
	 * @return mixed
	 */
	public function hide_private_redirect( $url ) {
		if ( ! is_string( $url ) || '' === $url ) {
			return $url;
		}
		$post_id = $this->resolve_post_id( $url );
		return ( $post_id && $this->is_hidden_from_visitor( $post_id ) ) ? false : $url;
	}

	public function adjacent_join( string $join, $in_same_term, $excluded_terms, $taxonomy, $post ): string {
		if ( $post instanceof \WP_Post && 'bazantik_album' === $post->post_type ) {
			global $wpdb;
			$join .= " LEFT JOIN {$wpdb->postmeta} AS bpv ON (bpv.post_id = p.ID AND bpv.meta_key = '_bazantik_visibility')";
		}
		return $join;
	}

	public function adjacent_where( string $where, $in_same_term, $excluded_terms, $taxonomy, $post ): string {
		if ( $post instanceof \WP_Post && 'bazantik_album' === $post->post_type ) {
			$where .= " AND (bpv.meta_value IS NULL OR bpv.meta_value = 'public') AND p.post_password = ''" . Folder_Access::exclusion_sql();
		}
		return $where;
	}
}
