<?php
declare(strict_types=1);

namespace BazantikPhotoGallery;

defined( 'ABSPATH' ) || exit;

/**
 * Stránka Nastavení (Photo Albums → Nastavení) a čtení voleb pluginu.
 *
 * Bez uložených voleb platí výchozí hodnoty (rychlé nahrávání zapnuto, mapy vypnuté s možností zapnout u alba).
 * Konstanty ve wp-config.php (BAZANTIK_DEFERRED_UPLOADS, BAZANTIK_POLITE_WORKER) mají přednost před volbami.
 */
final class Settings {
	public const GROUP = 'bazantik_settings';
	public const SLUG  = 'bazantik-settings';

	public const OPT_DEFERRED  = 'bazantik_deferred_upload';
	public const OPT_POLITE    = 'bazantik_polite_worker';
	public const OPT_RANDOM    = 'bazantik_random_filenames';
	public const OPT_PERF      = 'bazantik_perf_profile';
	public const OPT_LARGE     = 'bazantik_large_size';
	public const OPT_MAP_MODE  = 'bazantik_map_mode';
	public const OPT_MAP_SRC   = 'bazantik_map_provider';
	public const OPT_TILE_URL  = 'bazantik_map_tile_url';
	public const OPT_TILE_ATTR = 'bazantik_map_attribution';
	public const OPT_MAP_KEY   = 'bazantik_map_api_keys';
	public const OPT_MAP_SWITCH = 'bazantik_map_layer_switch';
	public const OSM_TILE_URL  = 'https://tile.openstreetmap.org/{z}/{x}/{y}.png';
	public const LEGACY_OPT_MAP = 'bazantik_map_enabled';
	public const OPT_AUDIENCE  = 'bazantik_notice_audience';
	public const OPT_CREDIT    = 'bazantik_show_credit';

	/** Odkazy na stránku pluginu, hodnocení a podporu autora. */
	public const URL_PLUGIN  = 'https://wordpress.org/plugins/bazantik-photo-gallery/';
	public const URL_REVIEW  = 'https://wordpress.org/support/plugin/bazantik-photo-gallery/reviews/#new-post';
	public const URL_SUPPORT = 'https://ko-fi.com/bazantik';
	public const OPT_ZOOM      = 'bazantik_mobile_zoom';
	public const OPT_ZOOM_ALBUM = 'bazantik_mobile_zoom_per_album';
	public const META_ZOOM     = '_bazantik_mobile_zoom';
	public const ACTION_ZOOM_ALL = 'bazantik_zoom_apply_all';
	public const OPT_THROTTLE  = 'bazantik_throttle_enabled';
	public const OPT_TRIES     = 'bazantik_throttle_tries';
	public const OPT_MINUTES   = 'bazantik_throttle_minutes';
	public const OPT_SLUG_ARCHIVE = 'bazantik_slug_archive';
	public const OPT_SLUG_ALBUM   = 'bazantik_slug_album';
	public const OPT_SLUG_FOLDER  = 'bazantik_slug_folder';
	public const OPT_FLUSH        = 'bazantik_flush_rewrite';

	/** Výchozí základy webových adres. */
	public const SLUG_DEFAULTS = [
		self::OPT_SLUG_ARCHIVE => 'bazantik-gallery',
		self::OPT_SLUG_ALBUM   => 'bazantik-album',
		self::OPT_SLUG_FOLDER  => 'bazantik-folder',
	];

	/** Adresy, které WordPress nebo web používá sám a nesmí je přebít archiv pluginu. */
	private const RESERVED_SLUGS = [
		'wp-admin', 'wp-content', 'wp-includes', 'wp-json', 'wp-login', 'wp-sitemap', 'wp-cron', 'feed', 'rss', 'rss2', 'rdf', 'atom',
		'page', 'embed', 'category', 'tag', 'author', 'search', 'attachment', 'comments', 'trackback', 'xmlrpc', 'admin', 'login', 'robots', 'sitemap',
	];

	/** Všechny volby stránky (uninstall.php je maže). */
	public const OPTIONS = [
		self::OPT_SLUG_ARCHIVE,
		self::OPT_SLUG_ALBUM,
		self::OPT_SLUG_FOLDER,
		self::OPT_DEFERRED,
		self::OPT_POLITE,
		self::OPT_RANDOM,
		self::OPT_PERF,
		self::OPT_LARGE,
		self::OPT_MAP_MODE,
		self::OPT_MAP_SRC,
		self::OPT_TILE_URL,
		self::OPT_TILE_ATTR,
		self::OPT_MAP_KEY,
		self::OPT_MAP_SWITCH,
		self::OPT_AUDIENCE,
		self::OPT_CREDIT,
		self::OPT_ZOOM,
		self::OPT_ZOOM_ALBUM,
		self::OPT_THROTTLE,
		self::OPT_TRIES,
		self::OPT_MINUTES,
	];

	public function init(): void {
		add_action( 'admin_menu', [ $this, 'register_menu' ] );
		add_action( 'admin_init', [ $this, 'register_settings' ] );
		add_action( 'admin_post_' . self::ACTION_ZOOM_ALL, [ $this, 'handle_zoom_apply_all' ] );
		foreach ( array_keys( self::SLUG_DEFAULTS ) as $option ) {
			add_action( 'add_option_' . $option, [ self::class, 'schedule_rewrite_flush' ] );
			add_action( 'update_option_' . $option, [ self::class, 'schedule_rewrite_flush' ] );
		}
	}

	/** Zdroj pro zoom na mobilu podle Nastavení: '4k' (výchozí) nebo 'original'. */
	public static function slug( string $option ): string {
		$default = self::SLUG_DEFAULTS[ $option ] ?? '';
		$slug    = sanitize_title( (string) get_option( $option, $default ) );
		return '' === $slug ? $default : $slug;
	}

	/**
	 * Zkontroluje nový základ adresy: nesmí být prázdný, rezervovaný, shodný s jinou adresou pluginu ani se stránkou webu.
	 * Při chybě vrátí dosavadní hodnotu.
	 *
	 * @param mixed $value
	 */
	public static function sanitize_slug( $value, string $option ): string {
		$slug     = sanitize_title( trim( is_string( $value ) ? $value : '', "/ \t\n\r" ) );
		$previous = self::slug( $option );

		if ( '' === $slug ) {
			return self::SLUG_DEFAULTS[ $option ];
		}
		if ( $slug === $previous ) {
			return $slug;
		}

		$message = '';
		if ( in_array( $slug, self::RESERVED_SLUGS, true ) ) {
			/* translators: %s: address base */
			$message = sprintf( __( 'The address base "%s" is reserved by WordPress. The previous value was kept.', 'bazantik-photo-gallery' ), $slug );
		} elseif ( get_page_by_path( $slug, OBJECT, [ 'page', 'post' ] ) ) {
			/* translators: %s: address base */
			$message = sprintf( __( 'The address base "%s" is already used by a page or post on your site. The previous value was kept.', 'bazantik-photo-gallery' ), $slug );
		} else {
			foreach ( array_keys( self::SLUG_DEFAULTS ) as $other ) {
				// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- volby ukládá options.php po ověření nonce, hodnota se sanitizuje funkcí sanitize_title()
				$submitted = $other !== $option && isset( $_POST[ $other ] ) ? sanitize_title( trim( (string) wp_unslash( $_POST[ $other ] ), "/ \t\n\r" ) ) : '';
				if ( $other !== $option && ( $submitted === $slug || ( '' === $submitted && self::slug( $other ) === $slug ) ) ) {
					/* translators: %s: address base */
					$message = sprintf( __( 'The address base "%s" is used for more than one purpose. The previous value was kept.', 'bazantik-photo-gallery' ), $slug );
					break;
				}
			}
		}

		if ( '' !== $message ) {
			add_settings_error( $option, 'bazantik_slug_' . $option, $message );
			return $previous;
		}
		return $slug;
	}

	/** Po změně základu adresy se pravidla přepíšou při dalším načtení (až je CPT zaregistrovaný s novou hodnotou). */
	public static function schedule_rewrite_flush(): void {
		update_option( self::OPT_FLUSH, '1', false );
	}

	public static function mobile_zoom_default(): string {
		return 'original' === get_option( self::OPT_ZOOM, '4k' ) ? 'original' : '4k';
	}

	public static function zoom_per_album(): bool {
		return '1' === (string) get_option( self::OPT_ZOOM_ALBUM, '0' );
	}

	/** Volba alba: 'inherit', '4k' nebo 'original' (uloží se i při vypnuté volbě po albech, aby se při jejím zapnutí nemusela zadávat znovu). */
	public static function album_zoom_choice( int $album_id ): string {
		$choice = (string) get_post_meta( $album_id, self::META_ZOOM, true );
		return in_array( $choice, [ '4k', 'original' ], true ) ? $choice : 'inherit';
	}

	public static function mobile_zoom_for_album( int $album_id ): string {
		if ( self::zoom_per_album() ) {
			$choice = self::album_zoom_choice( $album_id );
			if ( 'inherit' !== $choice ) {
				return $choice;
			}
		}

		return self::mobile_zoom_default();
	}

	/** Tlačítko „Použít u všech alb“: smaže ruční volby u alb, takže všechna alba dědí výchozí z Nastavení. */
	public function handle_zoom_apply_all(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to perform this action.', 'bazantik-photo-gallery' ), 403 );
		}
		check_admin_referer( self::ACTION_ZOOM_ALL );
		delete_post_meta_by_key( self::META_ZOOM );
		wp_safe_redirect( add_query_arg( 'bazantik_zoom_applied', '1', admin_url( 'edit.php?post_type=bazantik_album&page=' . self::SLUG ) ) );
		exit;
	}

	public static function deferred_upload(): bool {
		return self::flag( self::OPT_DEFERRED, 'BAZANTIK_DEFERRED_UPLOADS', true );
	}

	public static function polite_worker(): bool {
		return self::flag( self::OPT_POLITE, 'BAZANTIK_POLITE_WORKER', false );
	}

	/** Profil tempa zpracování na pozadí: 'gentle', 'balanced' (výchozí, dosavadní chování) nebo 'fast'. */
	public static function perf_profile(): string {
		$profile = (string) get_option( self::OPT_PERF, 'balanced' );
		return in_array( $profile, [ 'gentle', 'fast' ], true ) ? $profile : 'balanced';
	}

	/**
	 * Parametry worker: pauza po fotce (násobek doby zpracování, nejvýše max_pause s) a délka dávky v sekundách
	 * (editor alba, cron, ruční dávka).
	 *
	 * @return array{pause_factor: float, max_pause: float, editor: int, cron: int, manual: int}
	 */
	public static function perf(): array {
		$profiles = [
			'gentle'   => [
				'pause_factor' => 0.6,
				'max_pause'    => 5.0,
				'editor'       => 10,
				'cron'         => 40,
				'manual'       => 30,
			],
			'balanced' => [
				'pause_factor' => 0.3,
				'max_pause'    => 3.0,
				'editor'       => 20,
				'cron'         => 100,
				'manual'       => 60,
			],
			'fast'     => [
				'pause_factor' => 0.1,
				'max_pause'    => 1.0,
				'editor'       => 30,
				'cron'         => 150,
				'manual'       => 90,
			],
		];
		return $profiles[ self::perf_profile() ];
	}

	/** Delší strana velké verze fotky (px): 3840 (výchozí) nebo 2560. Platí pro nově zpracované fotky. */
	public static function large_size(): int {
		return 2560 === (int) get_option( self::OPT_LARGE, 3840 ) ? 2560 : 3840;
	}

	public static function random_filenames(): bool {
		return self::flag( self::OPT_RANDOM, '', true );
	}

	/**
	 * Předvolby poskytovatelů dlaždic. Každý poskytovatel má seznam vrstev (první je výchozí); adresa vrstvy obsahuje
	 * zástupný znak {key} (API klíč), který se při použití nahradí. Vrstva může mít vlastní 'attr', jinak platí 'attr' poskytovatele.
	 * 'key' = vyžaduje klíč, 'optional' = klíč lze vynechat (Stadia Maps umí ověření podle domény).
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function map_presets(): array {
		$osm = '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors';
		return [
			'mapycz'        => [
				'label'  => 'Mapy.com',
				'layers' => [
					'basic'   => [ 'label' => __( 'Basic', 'bazantik-photo-gallery' ), 'url' => 'https://api.mapy.com/v1/maptiles/basic/256/{z}/{x}/{y}?apikey={key}' ],
					'outdoor' => [ 'label' => __( 'Tourist', 'bazantik-photo-gallery' ), 'url' => 'https://api.mapy.com/v1/maptiles/outdoor/256/{z}/{x}/{y}?apikey={key}' ],
					'aerial'  => [ 'label' => __( 'Aerial', 'bazantik-photo-gallery' ), 'url' => 'https://api.mapy.com/v1/maptiles/aerial/256/{z}/{x}/{y}?apikey={key}' ],
				],
				'attr'   => '&copy; <a href="https://www.seznam.cz/">Seznam.cz a.s.</a> and others, ' . $osm,
				'home'   => 'https://developer.mapy.com/',
				'key'    => true,
				'logo'   => 'https://api.mapy.com/img/api/logo.svg',
				'link'   => 'https://mapy.com/',
			],
			'thunderforest' => [
				'label'  => 'Thunderforest',
				'layers' => [
					'atlas'     => [ 'label' => 'Atlas', 'url' => 'https://tile.thunderforest.com/atlas/{z}/{x}/{y}.png?apikey={key}' ],
					'landscape' => [ 'label' => 'Landscape', 'url' => 'https://tile.thunderforest.com/landscape/{z}/{x}/{y}.png?apikey={key}' ],
				],
				'attr'   => 'Maps &copy; <a href="https://www.thunderforest.com/">Thunderforest</a>, data ' . $osm,
				'home'   => 'https://www.thunderforest.com/',
				'key'    => true,
			],
			'stadia'        => [
				'label'  => 'Stadia Maps',
				'layers' => [
					'outdoors'   => [ 'label' => 'Outdoors', 'url' => 'https://tiles.stadiamaps.com/tiles/outdoors/{z}/{x}/{y}{r}.png?api_key={key}' ],
					'smooth'     => [ 'label' => 'Smooth', 'url' => 'https://tiles.stadiamaps.com/tiles/alidade_smooth/{z}/{x}/{y}{r}.png?api_key={key}' ],
					'smooth_dark' => [ 'label' => 'Smooth Dark', 'url' => 'https://tiles.stadiamaps.com/tiles/alidade_smooth_dark/{z}/{x}/{y}{r}.png?api_key={key}' ],
					'satellite'  => [
						'label' => __( 'Satellite', 'bazantik-photo-gallery' ),
						'url'   => 'https://tiles.stadiamaps.com/tiles/alidade_satellite/{z}/{x}/{y}{r}.jpg?api_key={key}',
						'attr'  => '&copy; CNES, Distribution Airbus DS, &copy; Airbus DS, &copy; PlanetObserver (Contains Copernicus Data) | &copy; <a href="https://stadiamaps.com/">Stadia Maps</a> &copy; <a href="https://openmaptiles.org/">OpenMapTiles</a> ' . $osm,
					],
				],
				'attr'   => '&copy; <a href="https://stadiamaps.com/">Stadia Maps</a> &copy; <a href="https://openmaptiles.org/">OpenMapTiles</a> ' . $osm,
				'home'   => 'https://stadiamaps.com/',
				'key'    => 'optional',
			],
			'maptiler'      => [
				'label'  => 'MapTiler',
				'layers' => [
					'streets'   => [ 'label' => 'Streets', 'url' => 'https://api.maptiler.com/maps/streets-v2/256/{z}/{x}/{y}.png?key={key}' ],
					'outdoor'   => [ 'label' => 'Outdoor', 'url' => 'https://api.maptiler.com/maps/outdoor-v2/256/{z}/{x}/{y}.png?key={key}' ],
					'satellite' => [ 'label' => __( 'Satellite', 'bazantik-photo-gallery' ), 'url' => 'https://api.maptiler.com/maps/satellite/256/{z}/{x}/{y}.jpg?key={key}' ],
				],
				'attr'   => '&copy; <a href="https://www.maptiler.com/copyright/">MapTiler</a> ' . $osm,
				'home'   => 'https://www.maptiler.com/',
				'key'    => true,
				'logo'   => 'https://api.maptiler.com/resources/logo.svg',
				'link'   => 'https://www.maptiler.com/',
			],
			'mapbox'        => [
				'label'  => 'Mapbox',
				'layers' => [
					'streets'   => [ 'label' => 'Streets', 'url' => 'https://api.mapbox.com/styles/v1/mapbox/streets-v12/tiles/256/{z}/{x}/{y}{r}?access_token={key}' ],
					'outdoors'  => [ 'label' => 'Outdoors', 'url' => 'https://api.mapbox.com/styles/v1/mapbox/outdoors-v12/tiles/256/{z}/{x}/{y}{r}?access_token={key}' ],
					'satellite' => [
						'label' => __( 'Satellite', 'bazantik-photo-gallery' ),
						'url'   => 'https://api.mapbox.com/styles/v1/mapbox/satellite-streets-v12/tiles/256/{z}/{x}/{y}{r}?access_token={key}',
						'attr'  => '&copy; <a href="https://www.mapbox.com/about/maps/">Mapbox</a> &copy; <a href="https://www.maxar.com/">Maxar</a> ' . $osm,
					],
				],
				'attr'   => '&copy; <a href="https://www.mapbox.com/about/maps/">Mapbox</a> ' . $osm,
				'home'   => 'https://www.mapbox.com/',
				'key'    => true,
			],
		];
	}

	/** Zvolený poskytovatel: 'osm', 'custom', nebo klíč předvolby. Neznámá hodnota = 'osm'. */
	public static function map_provider(): string {
		$provider = (string) get_option( self::OPT_MAP_SRC, 'osm' );
		return 'custom' === $provider || isset( self::map_presets()[ $provider ] ) ? $provider : 'osm';
	}

	/** API klíč: jen písmena, číslice, pomlčka, podtržítko a tečka (nic, co by mohlo změnit adresu). */
	public static function clean_api_key( string $key ): string {
		return substr( (string) preg_replace( '/[^A-Za-z0-9._-]/', '', $key ), 0, 128 );
	}

	/** Uložený API klíč poskytovatele (každý poskytovatel má vlastní klíč). */
	public static function api_key( string $provider ): string {
		$keys = get_option( self::OPT_MAP_KEY, [] );
		return is_array( $keys ) && isset( $keys[ $provider ] ) ? self::clean_api_key( (string) $keys[ $provider ] ) : '';
	}

	/**
	 * Ošetření pole klíčů: jen známí poskytovatelé, jen platné znaky, prázdné se vynechají.
	 *
	 * @param mixed $value Odeslaná hodnota.
	 * @return array<string, string>
	 */
	public static function sanitize_api_keys( $value ): array {
		$clean = [];
		if ( is_array( $value ) ) {
			foreach ( self::map_presets() as $id => $preset ) {
				$key = isset( $value[ $id ] ) ? self::clean_api_key( (string) $value[ $id ] ) : '';
				// Mapbox: do veřejné stránky patří jen veřejný klíč (pk.), tajný (sk.) se nikdy neukládá
				if ( 'mapbox' === $id && '' !== $key && 0 !== strpos( $key, 'pk.' ) ) {
					add_settings_error( self::OPT_MAP_KEY, 'bazantik_mapbox_key', __( 'The Mapbox key must be a public token (it starts with "pk."). It was not saved.', 'bazantik-photo-gallery' ) );
					$key = '';
				}
				if ( '' !== $key ) {
					$clean[ $id ] = $key;
				}
			}
		}
		return $clean;
	}

	/** Adresa dlaždic s dosazeným klíčem; prázdná, pokud chybí povinný klíč. */
	private static function layer_url( string $provider, string $template ): string {
		$preset = self::map_presets()[ $provider ] ?? null;
		if ( ! $preset ) {
			return '';
		}
		$key = self::api_key( $provider );
		if ( '' === $key ) {
			if ( true === $preset['key'] ) {
				return '';
			}
			// Klíč je volitelný: parametr s klíčem se z adresy vypustí.
			return (string) preg_replace( '/[?&][a-z_]+=\{key\}/', '', $template );
		}
		return str_replace( '{key}', rawurlencode( $key ), $template );
	}

	/** Adresa dlaždic výchozí vrstvy předvolby; prázdná, pokud chybí povinný klíč. */
	private static function preset_url( string $provider ): string {
		$preset = self::map_presets()[ $provider ] ?? null;
		$first  = $preset ? reset( $preset['layers'] ) : false;
		return $first ? self::layer_url( $provider, (string) $first['url'] ) : '';
	}

	/**
	 * Adresa dlaždic mapy: OpenStreetMap, předvolba poskytovatele, nebo vlastní adresa (jen když je platná).
	 */
	public static function tile_url(): string {
		$provider = self::map_provider();
		if ( 'custom' === $provider ) {
			$url = self::clean_tile_url( (string) get_option( self::OPT_TILE_URL, '' ) );
			if ( '' !== $url ) {
				return $url;
			}
		} elseif ( 'osm' !== $provider ) {
			$url = self::preset_url( $provider );
			if ( '' !== $url ) {
				return $url;
			}
		}
		return self::OSM_TILE_URL;
	}

	/** Návštěvník smí přepínat vrstvy mapy (výchozí zapnuto). */
	public static function map_layer_switch(): bool {
		return '1' === (string) get_option( self::OPT_MAP_SWITCH, '1' );
	}

	/**
	 * Vrstvy použitého poskytovatele pro frontend (první je výchozí). U OpenStreetMap a vlastní adresy je jen jedna.
	 *
	 * @return list<array{id: string, label: string, url: string, attr: string}>
	 */
	public static function map_layers(): array {
		$provider = self::effective_provider();
		$preset   = self::map_presets()[ $provider ] ?? null;
		if ( ! $preset ) {
			return [ [ 'id' => '', 'label' => '', 'url' => self::tile_url(), 'attr' => self::tile_attribution() ] ];
		}
		$layers = [];
		foreach ( $preset['layers'] as $id => $layer ) {
			$layers[] = [
				'id'    => (string) $id,
				'label' => (string) $layer['label'],
				'url'   => self::layer_url( $provider, (string) $layer['url'] ),
				'attr'  => self::clean_attribution( (string) ( $layer['attr'] ?? $preset['attr'] ) ),
			];
		}
		return $layers;
	}

	/** Poskytovatel, který se skutečně použije (po případném návratu na OSM, když chybí klíč nebo adresa). */
	public static function effective_provider(): string {
		return self::OSM_TILE_URL === self::tile_url() ? 'osm' : self::map_provider();
	}

	/** Text o původu mapových podkladů (atribuce) zobrazený v rohu mapy. */
	public static function tile_attribution(): string {
		$provider = self::effective_provider();
		if ( 'custom' === $provider ) {
			return self::clean_attribution( (string) get_option( self::OPT_TILE_ATTR, '' ) );
		}
		if ( 'osm' !== $provider ) {
			$layers = self::map_layers();
			return $layers[0]['attr'];
		}
		return self::osm_attribution();
	}

	public static function osm_attribution(): string {
		return __( '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors', 'bazantik-photo-gallery' );
	}

	/** Název použitého poskytovatele pro upozornění na mapě. */
	public static function tile_provider_name(): string {
		$provider = self::effective_provider();
		if ( 'osm' === $provider ) {
			return 'OpenStreetMap';
		}
		return 'custom' === $provider ? __( 'the selected provider', 'bazantik-photo-gallery' ) : (string) self::map_presets()[ $provider ]['label'];
	}

	/**
	 * Logo poskytovatele, které jeho podmínky vyžadují na mapě.
	 *
	 * @return array{src: string, href: string}|array{}
	 */
	public static function tile_logo(): array {
		$provider = self::effective_provider();
		$preset   = self::map_presets()[ $provider ] ?? null;
		return $preset && ! empty( $preset['logo'] ) ? [ 'src' => (string) $preset['logo'], 'href' => (string) $preset['link'] ] : [];
	}

	/** Povolí jen https adresu se zástupnými znaky {z}, {x}, {y} (a volitelně {s}, {r}); jinak vrátí prázdný řetězec. */
	public static function clean_tile_url( string $url ): string {
		$url = trim( $url );
		if ( ! preg_match( '#^https://#i', $url ) || ! str_contains( $url, '{z}' ) || ! str_contains( $url, '{x}' ) || ! str_contains( $url, '{y}' ) ) {
			return '';
		}
		$map   = [ '{z}' => 'bazantikz', '{x}' => 'bazantikx', '{y}' => 'bazantiky', '{s}' => 'bazantiks', '{r}' => 'bazantikr' ];
		$clean = esc_url_raw( str_replace( array_keys( $map ), array_values( $map ), $url ), [ 'https' ] );
		return '' === $clean ? '' : str_replace( array_values( $map ), array_keys( $map ), $clean );
	}

	public static function clean_attribution( string $html ): string {
		return wp_kses( $html, [ 'a' => [ 'href' => true, 'target' => true, 'rel' => true ] ], [ 'https' ] );
	}

	/**
	 * Mapy a GPS: 'optin' = vypnuto, u alba lze zapnout (výchozí); 'on' = zapnuto u všech alb, u alba lze vypnout;
	 * 'off' = vypnuto úplně. Starší volba bazantik_map_enabled = '0' se čte jako 'off'.
	 */
	public static function map_mode(): string {
		$mode = get_option( self::OPT_MAP_MODE, '' );
		if ( in_array( $mode, [ 'on', 'optin', 'off' ], true ) ) {
			return $mode;
		}

		return '0' === (string) get_option( self::LEGACY_OPT_MAP, '1' ) ? 'off' : 'optin';
	}

	/**
	 * Volba alba: 'inherit' (podle Nastavení), 'on', 'off'. Starší příznak _bazantik_show_map = '0' znamená 'off', jinak 'inherit'.
	 */
	public static function album_map_choice( int $album_id ): string {
		$choice = (string) get_post_meta( $album_id, '_bazantik_map_mode', true );
		if ( in_array( $choice, [ 'inherit', 'on', 'off' ], true ) ) {
			return $choice;
		}

		return '0' === (string) get_post_meta( $album_id, '_bazantik_show_map', true ) ? 'off' : 'inherit';
	}

	public static function album_shows_map( int $album_id ): bool {
		$mode = self::map_mode();
		if ( 'off' === $mode ) {
			return false;
		}

		$choice = self::album_map_choice( $album_id );
		if ( 'inherit' === $choice ) {
			return 'on' === $mode;
		}

		return 'on' === $choice;
	}

	/** Odkaz na plugin pod galerií; ve výchozím stavu vypnuto, zapíná ho jen majitel webu. */
	public static function show_credit(): bool {
		return self::flag( self::OPT_CREDIT, '', false );
	}

	public static function notice_audience(): string {
		return 'editors' === get_option( self::OPT_AUDIENCE, 'all' ) ? 'editors' : 'all';
	}

	/** Omezení počtu neúspěšných pokusů o zadání hesla (složky i alba); ve výchozím stavu vypnuto. */
	public static function throttle_enabled(): bool {
		return '1' === (string) get_option( self::OPT_THROTTLE, '0' );
	}

	public static function throttle_tries(): int {
		return max( 1, min( 1000, (int) get_option( self::OPT_TRIES, 10 ) ) );
	}

	public static function throttle_minutes(): int {
		return max( 1, min( 1440, (int) get_option( self::OPT_MINUTES, 10 ) ) );
	}

	/** Identifikace klienta pro omezení pokusů o heslo: IPv4 celá, IPv6 podle sítě /64 (jinak by se omezení obešlo změnou adresy). */
	public static function client_id(): string {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		// Za reverzní proxy nebo CDN lze skutečnou adresu návštěvníka dodat filtrem (výchozí je REMOTE_ADDR, hlavičky od klienta se nedůvěřují).
		$ip = (string) apply_filters( 'bazantik_client_ip', $ip );
		if ( str_contains( $ip, ':' ) ) {
			$bin = @inet_pton( $ip ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- neplatná adresa se řeší níže
			if ( false !== $bin && 16 === strlen( $bin ) ) {
				// IPv4 zapsaná jako IPv6 (::ffff:a.b.c.d) zůstane jako samostatná adresa, jinak by se všichni sloučili do jednoho koše
				if ( str_starts_with( $bin, str_repeat( "\0", 10 ) . "\xff\xff" ) ) {
					return (string) inet_ntop( substr( $bin, 12 ) );
				}
				return bin2hex( substr( $bin, 0, 8 ) );
			}
		}
		return $ip;
	}

	/** Klíč čítače pokusů o heslo (klient + objekt); adresa klienta se do databáze neukládá v čitelné podobě. */
	public static function throttle_key( string $prefix, int $object_id ): string {
		return $prefix . substr( hash_hmac( 'sha256', self::client_id() . '|' . $object_id, wp_salt( 'nonce' ) ), 0, 32 );
	}

	/**
	 * Započítá jeden pokus o heslo ještě před jeho ověřením a vrátí false, když je limit vyčerpán.
	 * Čtení a zápis čítače běží pod zámkem databáze (MySQL/MariaDB), takže souběžné požadavky limit neobejdou;
	 * bez podpory zámků (např. SQLite) se čítač zapisuje bez zámku.
	 */
	public static function reserve_attempt( string $key ): bool {
		global $wpdb;
		$lock = 'bazantik_' . md5( $key );
		$prev = $wpdb->suppress_errors( true );
		$got  = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK( %s, 3 )', $lock ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->suppress_errors( $prev );
		$used = (int) get_transient( $key );
		$ok   = $used < self::throttle_tries();
		if ( $ok ) {
			set_transient( $key, $used + 1, self::throttle_minutes() * MINUTE_IN_SECONDS );
		}
		if ( $got ) {
			$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK( %s )', $lock ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		}
		return $ok;
	}

	private static function constant_forces( string $constant ): bool {
		return '' !== $constant && defined( $constant ) && true === constant( $constant );
	}

	private static function flag( string $option, string $constant, bool $default ): bool {
		if ( self::constant_forces( $constant ) ) {
			return true;
		}

		return '1' === (string) get_option( $option, $default ? '1' : '0' );
	}

	public function register_menu(): void {
		add_submenu_page(
			'edit.php?post_type=bazantik_album',
			__( 'Bazantik Photo Gallery settings', 'bazantik-photo-gallery' ),
			__( 'Settings', 'bazantik-photo-gallery' ),
			'manage_options',
			self::SLUG,
			[ $this, 'render_page' ]
		);
	}

	public function register_settings(): void {
		foreach ( [ self::OPT_DEFERRED, self::OPT_POLITE, self::OPT_RANDOM, self::OPT_MAP_SWITCH, self::OPT_CREDIT ] as $option ) {
			register_setting(
				self::GROUP,
				$option,
				[
					'type'              => 'string',
					'sanitize_callback' => [ self::class, 'sanitize_flag' ],
				]
			);
		}

		register_setting(
			self::GROUP,
			self::OPT_PERF,
			[
				'type'              => 'string',
				'sanitize_callback' => static fn( $value ): string => in_array( $value, [ 'gentle', 'fast' ], true ) ? $value : 'balanced',
			]
		);
		register_setting(
			self::GROUP,
			self::OPT_LARGE,
			[
				'type'              => 'string',
				'sanitize_callback' => static fn( $value ): string => '2560' === (string) $value ? '2560' : '3840',
			]
		);

		register_setting(
			self::GROUP,
			self::OPT_ZOOM,
			[
				'type'              => 'string',
				'sanitize_callback' => static fn( $value ): string => 'original' === $value ? 'original' : '4k',
			]
		);
		register_setting(
			self::GROUP,
			self::OPT_ZOOM_ALBUM,
			[
				'type'              => 'string',
				'sanitize_callback' => [ self::class, 'sanitize_flag' ],
			]
		);

		register_setting(
			self::GROUP,
			self::OPT_MAP_MODE,
			[
				'type'              => 'string',
				'sanitize_callback' => [ self::class, 'sanitize_map_mode' ],
			]
		);

		register_setting(
			self::GROUP,
			self::OPT_MAP_SRC,
			[
				'type'              => 'string',
				'sanitize_callback' => static fn( $value ): string => 'custom' === $value || isset( self::map_presets()[ (string) $value ] ) ? (string) $value : 'osm',
			]
		);
		register_setting(
			self::GROUP,
			self::OPT_MAP_KEY,
			[
				'type'              => 'array',
				'sanitize_callback' => [ self::class, 'sanitize_api_keys' ],
			]
		);
		register_setting(
			self::GROUP,
			self::OPT_TILE_URL,
			[
				'type'              => 'string',
				'sanitize_callback' => [ self::class, 'sanitize_tile_url' ],
			]
		);
		register_setting(
			self::GROUP,
			self::OPT_TILE_ATTR,
			[
				'type'              => 'string',
				'sanitize_callback' => static fn( $value ): string => self::clean_attribution( (string) $value ),
			]
		);

		register_setting(
			self::GROUP,
			self::OPT_THROTTLE,
			[
				'type'              => 'string',
				'sanitize_callback' => [ self::class, 'sanitize_flag' ],
			]
		);
		register_setting(
			self::GROUP,
			self::OPT_TRIES,
			[
				'type'              => 'integer',
				'sanitize_callback' => static fn( $value ): int => max( 1, min( 1000, absint( $value ) ?: 10 ) ),
			]
		);
		register_setting(
			self::GROUP,
			self::OPT_MINUTES,
			[
				'type'              => 'integer',
				'sanitize_callback' => static fn( $value ): int => max( 1, min( 1440, absint( $value ) ?: 10 ) ),
			]
		);

		foreach ( array_keys( self::SLUG_DEFAULTS ) as $slug_option ) {
			register_setting(
				self::GROUP,
				$slug_option,
				[
					'type'              => 'string',
					'sanitize_callback' => static fn( $value ): string => self::sanitize_slug( $value, $slug_option ),
				]
			);
		}

		register_setting(
			self::GROUP,
			self::OPT_AUDIENCE,
			[
				'type'              => 'string',
				'sanitize_callback' => [ self::class, 'sanitize_audience' ],
			]
		);
	}

	/**
	 * @param mixed $value
	 */
	public static function sanitize_flag( $value ): string {
		return empty( $value ) ? '0' : '1';
	}

	/**
	 * @param mixed $value
	 */
	public static function sanitize_map_mode( $value ): string {
		return in_array( $value, [ 'on', 'optin', 'off' ], true ) ? $value : 'optin';
	}

	/**
	 * @param mixed $value
	 */
	public static function sanitize_tile_url( $value ): string {
		$value = (string) $value;
		$clean = self::clean_tile_url( $value );
		if ( '' === $clean && '' !== trim( $value ) ) {
			add_settings_error( self::OPT_TILE_URL, 'bazantik_tile_url', __( 'The map tile URL must start with https:// and contain the placeholders {z}, {x} and {y}. OpenStreetMap will be used.', 'bazantik-photo-gallery' ) );
		}
		return $clean;
	}

	/**
	 * @param mixed $value
	 */
	public static function sanitize_audience( $value ): string {
		return 'editors' === $value ? 'editors' : 'all';
	}

	public function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to view this page.', 'bazantik-photo-gallery' ), 403 );
		}
		wp_register_style( 'bazantik-settings-page', false, [], BAZANTIK_VERSION );
		wp_enqueue_style( 'bazantik-settings-page' );
		wp_add_inline_style(
			'bazantik-settings-page',
			'.bazantik-settings-layout{display:flex;gap:24px;align-items:flex-start}.bazantik-settings-main{flex:1 1 auto;min-width:0}.bazantik-support-card{flex:0 0 240px;width:240px;margin-top:20px;padding:16px 20px;box-sizing:border-box}.bazantik-support-card img{display:block;border-radius:14px;margin-bottom:12px}.bazantik-support-card .title{margin-top:0}@media (max-width:1100px){.bazantik-settings-layout{flex-direction:column;align-items:stretch}.bazantik-support-card{flex:0 0 auto;width:auto;max-width:520px}}'
		);
		wp_enqueue_script( 'bazantik-settings-page', BAZANTIK_PLUGIN_URL . 'assets/js/settings-admin.js', [], BAZANTIK_VERSION, true );
		wp_localize_script(
			'bazantik-settings-page',
			'bazantikSettings',
			[ 'confirmZoom' => __( 'Manual zoom source choices on individual albums will be erased and cannot be restored. Continue?', 'bazantik-photo-gallery' ) ]
		);
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Bazantik Photo Gallery settings', 'bazantik-photo-gallery' ); ?></h1>
			<?php settings_errors(); ?>
			<div class="bazantik-settings-layout">
			<div class="bazantik-settings-main">
			<form method="post" action="options.php" id="bazantik-settings-form">
				<?php settings_fields( self::GROUP ); ?>

				<h2 class="title"><?php esc_html_e( 'Uploading and processing photos', 'bazantik-photo-gallery' ); ?></h2>
				<table class="form-table" role="presentation">
					<?php
					$this->checkbox_row(
						self::OPT_DEFERRED,
						__( 'Fast upload', 'bazantik-photo-gallery' ),
						__( 'A photo appears in the album thumbnails right after it is uploaded; its processing for the gallery runs in the background while the next queued photos keep uploading.', 'bazantik-photo-gallery' ),
						self::deferred_upload(),
						self::constant_forces( 'BAZANTIK_DEFERRED_UPLOADS' ),
						'BAZANTIK_DEFERRED_UPLOADS'
					);
					$this->checkbox_row(
						self::OPT_POLITE,
						__( 'Prioritize uploading over processing', 'bazantik-photo-gallery' ),
						__( 'While files are being uploaded, their background processing for the gallery waits, which saves hosting performance. Uploading stays fast, but processing finishes a little later.', 'bazantik-photo-gallery' ),
						self::polite_worker(),
						self::constant_forces( 'BAZANTIK_POLITE_WORKER' ),
						'BAZANTIK_POLITE_WORKER'
					);
					$this->checkbox_row(
						self::OPT_RANDOM,
						__( 'Random file names', 'bazantik-photo-gallery' ),
						__( 'Files uploaded to an album get a random name so they cannot be guessed from the URL. The photo\'s caption in the lightbox stays based on the original file name.', 'bazantik-photo-gallery' ),
						self::random_filenames()
					);
					?>
					<tr>
						<th scope="row"><label for="bazantik_perf_profile"><?php esc_html_e( 'Processing speed', 'bazantik-photo-gallery' ); ?></label></th>
						<td>
							<select name="<?php echo esc_attr( self::OPT_PERF ); ?>" id="bazantik_perf_profile">
								<option value="gentle" <?php selected( self::perf_profile(), 'gentle' ); ?>><?php esc_html_e( 'Gentle (weaker or heavily shared hosting)', 'bazantik-photo-gallery' ); ?></option>
								<option value="balanced" <?php selected( self::perf_profile(), 'balanced' ); ?>><?php esc_html_e( 'Balanced (default)', 'bazantik-photo-gallery' ); ?></option>
								<option value="fast" <?php selected( self::perf_profile(), 'fast' ); ?>><?php esc_html_e( 'Fast (powerful hosting or VPS)', 'bazantik-photo-gallery' ); ?></option>
							</select>
							<p class="description"><?php esc_html_e( 'How hard the background processing works: the pause between photos and the length of one batch. Gentle processes photos more slowly and puts less load on the server; fast finishes sooner but may slow down your site while it runs. The change takes effect with the next batch.', 'bazantik-photo-gallery' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="bazantik_large_size"><?php esc_html_e( 'Photo size for the lightbox', 'bazantik-photo-gallery' ); ?></label></th>
						<td>
							<select name="<?php echo esc_attr( self::OPT_LARGE ); ?>" id="bazantik_large_size">
								<option value="3840" <?php selected( self::large_size(), 3840 ); ?>><?php esc_html_e( '3840 px (sharpest, default)', 'bazantik-photo-gallery' ); ?></option>
								<option value="2560" <?php selected( self::large_size(), 2560 ); ?>><?php esc_html_e( '2560 px (smaller files, less memory and disk space)', 'bazantik-photo-gallery' ); ?></option>
							</select>
							<p class="description"><?php esc_html_e( 'The photo size (longer side) that the lightbox shows. A smaller size saves disk space, memory and mobile data. It applies only to photos processed after you save this setting; photos that are already processed keep their size.', 'bazantik-photo-gallery' ); ?></p>
							<p class="description"><?php esc_html_e( 'Recommended size for modern hosting is 3840 px. If your hosting has limited disk space or is less powerful and you run into problems when processing photos in albums, try 2560 px.', 'bazantik-photo-gallery' ); ?></p>
						</td>
					</tr>
				</table>

				<h2 class="title"><?php esc_html_e( 'Display for visitors', 'bazantik-photo-gallery' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="bazantik_notice_audience"><?php esc_html_e( '“Processed X of Y photos” notice', 'bazantik-photo-gallery' ); ?></label></th>
						<td>
							<select name="<?php echo esc_attr( self::OPT_AUDIENCE ); ?>" id="bazantik_notice_audience">
								<option value="all" <?php selected( self::notice_audience(), 'all' ); ?>><?php esc_html_e( 'All visitors', 'bazantik-photo-gallery' ); ?></option>
								<option value="editors" <?php selected( self::notice_audience(), 'editors' ); ?>><?php esc_html_e( 'Only people who are allowed to edit the album', 'bazantik-photo-gallery' ); ?></option>
							</select>
							<p class="description"><?php esc_html_e( 'Shown above the album until all photos are processed.', 'bazantik-photo-gallery' ); ?></p>
						</td>
					</tr>
					<?php
					$this->checkbox_row(
						self::OPT_CREDIT,
						__( 'Link to the plugin', 'bazantik-photo-gallery' ),
						__( 'Shows a small “Gallery by Bazantik Photo Gallery” link under the gallery that leads to the plugin page on wordpress.org, so other people can find the plugin. Off by default.', 'bazantik-photo-gallery' ),
						self::show_credit()
					);
					?>
				</table>

				<h2 class="title"><?php esc_html_e( 'Display on mobile', 'bazantik-photo-gallery' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="bazantik_mobile_zoom"><?php esc_html_e( 'Image source when zooming on mobile', 'bazantik-photo-gallery' ); ?></label></th>
						<td>
							<select name="<?php echo esc_attr( self::OPT_ZOOM ); ?>" id="bazantik_mobile_zoom">
								<option value="4k" <?php selected( self::mobile_zoom_default(), '4k' ); ?>><?php esc_html_e( 'Lightbox version (faster, saves mobile data)', 'bazantik-photo-gallery' ); ?></option>
								<option value="original" <?php selected( self::mobile_zoom_default(), 'original' ); ?>><?php esc_html_e( 'Full original (sharpest, up to tens of MB depending on the photo when zooming in)', 'bazantik-photo-gallery' ); ?></option>
							</select>
							<p class="description"><?php esc_html_e( 'Applies to phones and narrow windows. Visitors can choose differently in the lightbox (Settings tab). On a computer the full original is always used when zooming in.', 'bazantik-photo-gallery' ); ?></p>
							<p>
								<label>
									<input type="checkbox" name="<?php echo esc_attr( self::OPT_ZOOM_ALBUM ); ?>" value="1" <?php checked( self::zoom_per_album() ); ?>>
									<?php esc_html_e( 'Allow choosing the source when creating / editing individual albums', 'bazantik-photo-gallery' ); ?>
								</label>
							</p>
							<p class="description"><?php esc_html_e( 'On: the source choice for display on mobile is active in the album editor (the default is “Use plugin setting”). When you turn this option off, the source chosen here is used for all albums. Values chosen manually on albums are remembered and applied again when you turn this option back on.', 'bazantik-photo-gallery' ); ?></p>
							<p><button type="submit" class="button" form="bazantik-zoom-apply-all"><?php esc_html_e( 'Use the default source for all albums', 'bazantik-photo-gallery' ); ?></button></p>
							<p class="description"><?php esc_html_e( 'The manually chosen zoom source is cleared on all albums and the value chosen here is used (save your changes here in Settings first).', 'bazantik-photo-gallery' ); ?></p>
							<?php if ( isset( $_GET['bazantik_zoom_applied'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
								<p><strong><?php esc_html_e( 'Done: all albums now use the default source from Settings.', 'bazantik-photo-gallery' ); ?></strong></p>
							<?php endif; ?>
						</td>
					</tr>
				</table>

				<h2 class="title"><?php esc_html_e( 'Maps and privacy', 'bazantik-photo-gallery' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="bazantik_map_mode"><?php esc_html_e( 'Maps and location (GPS)', 'bazantik-photo-gallery' ); ?></label></th>
						<td>
							<select name="<?php echo esc_attr( self::OPT_MAP_MODE ); ?>" id="bazantik_map_mode">
								<option value="on" <?php selected( self::map_mode(), 'on' ); ?>><?php esc_html_e( 'On (can be turned off per album)', 'bazantik-photo-gallery' ); ?></option>
								<option value="optin" <?php selected( self::map_mode(), 'optin' ); ?>><?php esc_html_e( 'Off (can be turned on per album)', 'bazantik-photo-gallery' ); ?></option>
								<option value="off" <?php selected( self::map_mode(), 'off' ); ?>><?php esc_html_e( 'Off completely', 'bazantik-photo-gallery' ); ?></option>
							</select>
							<p class="description"><?php esc_html_e( '“Off completely” makes it impossible to turn the map on for a specific album and overrides the manual changes made on all albums. Albums remember their manual setting, which applies again after you switch back to “On” or “Off”. The map background is loaded from the map provider only after the visitor clicks “Show map” in the information panel (which gives the provider the visitor\'s IP address). When the map is not enabled for an album, GPS coordinates are not printed into the page and the Download button removes the GPS fields from the standard EXIF and XMP data of the original (JPEG). A file opened by a direct link (e.g. when zooming) still contains all of this data.', 'bazantik-photo-gallery' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="bazantik_map_provider"><?php esc_html_e( 'Map tile source', 'bazantik-photo-gallery' ); ?></label></th>
						<td>
							<?php $bazantik_provider = self::map_provider(); ?>
							<select name="<?php echo esc_attr( self::OPT_MAP_SRC ); ?>" id="bazantik_map_provider">
								<option value="osm" <?php selected( $bazantik_provider, 'osm' ); ?>><?php esc_html_e( 'OpenStreetMap (default)', 'bazantik-photo-gallery' ); ?></option>
								<?php foreach ( self::map_presets() as $bazantik_id => $bazantik_preset ) : ?>
									<option value="<?php echo esc_attr( $bazantik_id ); ?>" data-key="<?php echo esc_attr( true === $bazantik_preset['key'] ? 'required' : 'optional' ); ?>" data-home="<?php echo esc_url( (string) $bazantik_preset['home'] ); ?>" <?php selected( $bazantik_provider, $bazantik_id ); ?>><?php echo esc_html( (string) $bazantik_preset['label'] ); ?></option>
								<?php endforeach; ?>
								<option value="custom" <?php selected( $bazantik_provider, 'custom' ); ?>><?php esc_html_e( 'Another provider (custom tile URL)', 'bazantik-photo-gallery' ); ?></option>
							</select>
							<p class="description"><?php esc_html_e( 'Where the visitor\'s browser downloads the map images from after the visitor consents. The provider thereby receives the visitor\'s IP address, so mention this in your privacy policy.', 'bazantik-photo-gallery' ); ?></p>
							<p class="description" id="bazantik_map_home" style="display:none"><a href="#" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Open the provider\'s website (account, API key, terms of use)', 'bazantik-photo-gallery' ); ?></a></p>
						</td>
					</tr>
					<tr class="bazantik-key-map" style="display:none">
						<th scope="row"><?php esc_html_e( 'Map layers', 'bazantik-photo-gallery' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="<?php echo esc_attr( self::OPT_MAP_SWITCH ); ?>" value="1" <?php checked( self::map_layer_switch() ); ?>>
								<?php esc_html_e( 'Let visitors switch between the map layers offered by the provider', 'bazantik-photo-gallery' ); ?>
							</label>
							<p class="description"><?php esc_html_e( 'When turned off, only the first layer of the provider is shown. The visitor\'s choice is remembered in the visitor\'s browser.', 'bazantik-photo-gallery' ); ?></p>
						</td>
					</tr>
					<tr class="bazantik-key-map" style="display:none">
						<th scope="row"><label><?php esc_html_e( 'Provider API key', 'bazantik-photo-gallery' ); ?></label></th>
						<td>
							<?php foreach ( self::map_presets() as $bazantik_id => $bazantik_preset ) : ?>
								<input type="text" class="regular-text code bazantik-key-input" name="<?php echo esc_attr( self::OPT_MAP_KEY . '[' . $bazantik_id . ']' ); ?>" data-provider="<?php echo esc_attr( $bazantik_id ); ?>" value="<?php echo esc_attr( self::api_key( $bazantik_id ) ); ?>" autocomplete="off" spellcheck="false" aria-label="<?php esc_attr_e( 'Provider API key', 'bazantik-photo-gallery' ); ?>">
							<?php endforeach; ?>
							<p class="description"><?php esc_html_e( 'The key is inserted into the map tile URL and is visible to visitors in the page source, so restrict it to your domain in the provider\'s dashboard. Without a valid key OpenStreetMap is used.', 'bazantik-photo-gallery' ); ?></p>
							<p class="description" id="bazantik_map_key_optional" style="display:none"><?php esc_html_e( 'The key is optional for this provider if you authorize your domain in its dashboard.', 'bazantik-photo-gallery' ); ?></p>
						</td>
					</tr>
					<tr class="bazantik-custom-map" <?php echo 'custom' === $bazantik_provider ? '' : 'style="display:none"'; ?>>
						<th scope="row"><label for="bazantik_map_tile_url"><?php esc_html_e( 'Map tile URL', 'bazantik-photo-gallery' ); ?></label></th>
						<td>
							<input type="url" class="large-text code" name="<?php echo esc_attr( self::OPT_TILE_URL ); ?>" id="bazantik_map_tile_url" value="<?php echo esc_attr( (string) get_option( self::OPT_TILE_URL, '' ) ); ?>" placeholder="https://tiles.example.com/{z}/{x}/{y}.png">
							<p class="description"><?php esc_html_e( 'You can find the URL in the map provider\'s documentation. It must start with https:// and contain the placeholders {z}, {x} and {y}.', 'bazantik-photo-gallery' ); ?></p>
						</td>
					</tr>
					<tr class="bazantik-custom-map" <?php echo 'custom' === $bazantik_provider ? '' : 'style="display:none"'; ?>>
						<th scope="row"><label for="bazantik_map_attribution"><?php esc_html_e( 'Map attribution text', 'bazantik-photo-gallery' ); ?></label></th>
						<td>
							<input type="text" class="large-text" name="<?php echo esc_attr( self::OPT_TILE_ATTR ); ?>" id="bazantik_map_attribution" value="<?php echo esc_attr( (string) get_option( self::OPT_TILE_ATTR, '' ) ); ?>" placeholder="<?php echo esc_attr__( '&copy; Provider, &copy; OpenStreetMap contributors', 'bazantik-photo-gallery' ); ?>">
							<p class="description"><?php esc_html_e( 'Shown in the corner of the map. Most providers require it in their terms. Only links are allowed.', 'bazantik-photo-gallery' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Password attempt limit', 'bazantik-photo-gallery' ); ?></th>
						<td>
							<label>
								<input type="checkbox" id="bazantik_throttle_enabled" name="<?php echo esc_attr( self::OPT_THROTTLE ); ?>" value="1" <?php checked( self::throttle_enabled() ); ?>>
								<?php esc_html_e( 'Limit the number of failed attempts to enter a folder / album password', 'bazantik-photo-gallery' ); ?>
							</label>
							<?php $dim = self::throttle_enabled() ? '' : ' readonly style="opacity:.5"'; ?>
							<p>
								<?php
								$tries_input = '<input type="number" min="1" max="1000" class="small-text bazantik-throttle-field" name="' . esc_attr( self::OPT_TRIES ) . '" value="' . esc_attr( (string) self::throttle_tries() ) . '" aria-label="' . esc_attr__( 'Number of attempts', 'bazantik-photo-gallery' ) . '"' . $dim . '>';
								$mins_input  = '<input type="number" min="1" max="1440" class="small-text bazantik-throttle-field" name="' . esc_attr( self::OPT_MINUTES ) . '" value="' . esc_attr( (string) self::throttle_minutes() ) . '" aria-label="' . esc_attr__( 'Number of minutes', 'bazantik-photo-gallery' ) . '"' . $dim . '>';
								/* translators: 1: number input (attempts), 2: number input (minutes) */
								echo wp_kses( sprintf( esc_html__( '%1$s attempts per %2$s minutes / IP address', 'bazantik-photo-gallery' ), $tries_input, $mins_input ), [ 'input' => [ 'type' => true, 'min' => true, 'max' => true, 'class' => true, 'name' => true, 'value' => true, 'aria-label' => true, 'readonly' => true, 'style' => true ] ] );
								?>
							</p>
							<p class="description"><?php esc_html_e( 'After the attempts are used up, the visitor must wait the given number of minutes. Applies to folder and album passwords. The limit is off by default.', 'bazantik-photo-gallery' ); ?></p>
						</td>
					</tr>
				</table>

				<h2 class="title"><?php esc_html_e( 'Web addresses', 'bazantik-photo-gallery' ); ?></h2>
				<table class="form-table" role="presentation">
					<?php
					$slug_rows = [
						self::OPT_SLUG_ARCHIVE => __( 'Overview of all albums and folders', 'bazantik-photo-gallery' ),
						self::OPT_SLUG_ALBUM   => __( 'Single album', 'bazantik-photo-gallery' ),
						self::OPT_SLUG_FOLDER  => __( 'Folder', 'bazantik-photo-gallery' ),
					];
					foreach ( $slug_rows as $slug_option => $slug_label ) :
						?>
					<tr>
						<th scope="row"><label for="<?php echo esc_attr( $slug_option ); ?>"><?php echo esc_html( $slug_label ); ?></label></th>
						<td>
							<code><?php echo esc_html( home_url( '/' ) ); ?></code><input type="text" class="regular-text code" style="width:14em" name="<?php echo esc_attr( $slug_option ); ?>" id="<?php echo esc_attr( $slug_option ); ?>" value="<?php echo esc_attr( self::slug( $slug_option ) ); ?>" placeholder="<?php echo esc_attr( self::SLUG_DEFAULTS[ $slug_option ] ); ?>"><code>/</code>
						</td>
					</tr>
					<?php endforeach; ?>
					<tr>
						<th scope="row"></th>
						<td><p class="description"><?php esc_html_e( 'Letters, numbers and hyphens only. The addresses are regenerated automatically when you save. Old addresses stop working and links to them (bookmarks, shared links, search engines) will lead to a 404 page, so change this before you start sharing albums. Leave a field empty to restore the default.', 'bazantik-photo-gallery' ); ?></p></td>
					</tr>
				</table>

				<?php submit_button(); ?>
			</form>

			</div>

			<aside class="card bazantik-support-card">
				<img src="<?php echo esc_url( BAZANTIK_PLUGIN_URL . 'assets/images/icon-128x128.png' ); ?>" width="64" height="64" alt="">
				<h2 class="title"><?php esc_html_e( 'Do you like the plugin?', 'bazantik-photo-gallery' ); ?></h2>
				<p>
					<?php
					printf(
						wp_kses(
							/* translators: 1: link to review the plugin on wordpress.org, 2: link to support the author */
							__( 'Bazantik Photo Gallery is free. If it is useful to you, a %1$s or a %2$s helps its further development.', 'bazantik-photo-gallery' ),
							[]
						),
						'<a href="' . esc_url( self::URL_REVIEW ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'review on wordpress.org', 'bazantik-photo-gallery' ) . '</a>',
						'<a href="' . esc_url( self::URL_SUPPORT ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'small contribution', 'bazantik-photo-gallery' ) . '</a>'
					);
					?>
				</p>
			</aside>
			</div>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" id="bazantik-zoom-apply-all">
				<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION_ZOOM_ALL ); ?>">
				<?php wp_nonce_field( self::ACTION_ZOOM_ALL ); ?>
			</form>
		</div>
		<?php
	}

	private function checkbox_row( string $option, string $label, string $description, bool $checked, bool $locked = false, string $constant = '' ): void {
		?>
		<tr>
			<th scope="row"><?php echo esc_html( $label ); ?></th>
			<td>
				<?php if ( $locked ) : ?>
					<input type="hidden" name="<?php echo esc_attr( $option ); ?>" value="<?php echo esc_attr( (string) get_option( $option, '0' ) ); ?>">
				<?php endif; ?>
				<label>
					<input type="checkbox" name="<?php echo esc_attr( $option ); ?>" value="1" <?php checked( $checked ); ?> <?php disabled( $locked ); ?>>
					<?php esc_html_e( 'On', 'bazantik-photo-gallery' ); ?>
				</label>
				<p class="description"><?php echo esc_html( $description ); ?></p>
				<?php if ( $locked ) : ?>
					<p class="description"><strong>
						<?php
						/* translators: %s: name of the constant in wp-config.php */
						echo esc_html( sprintf( __( 'Set in wp-config.php (%s), a change here has no effect.', 'bazantik-photo-gallery' ), $constant ) );
						?>
					</strong></p>
				<?php endif; ?>
			</td>
		</tr>
		<?php
	}
}
