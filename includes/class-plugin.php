<?php
declare(strict_types=1);

namespace BazantikPhotoGallery;

defined( 'ABSPATH' ) || exit;

final class Plugin {
	private static ?Plugin $instance = null;

	public static function get_instance(): Plugin {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {}

	public function init(): void {
		// Registrace CPT a taxonomií s prioritou 0 (garantuje spuštění před šablonou Avada / Fusion Builder)
		add_action( 'init', [ Post_Types::class, 'register' ], 0 );
		add_action( 'template_redirect', [ Post_Types::class, 'redirect_bare_bases' ], 0 );
		add_filter( 'post_type_archive_title', [ Post_Types::class, 'archive_title' ], 10, 2 );
		add_filter( 'plugin_row_meta', static function ( array $links, string $file ): array {
			if ( plugin_basename( BAZANTIK_PLUGIN_DIR . 'bazantik-photo-gallery.php' ) === $file ) {
				$links[] = '<a href="' . esc_url( Settings::URL_SUPPORT ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Support the author', 'bazantik-photo-gallery' ) . '</a>';
			}
			return $links;
		}, 10, 2 );
		// Po aktualizaci pluginu jednou obnovit pravidla adres (jen když se změnila verze)
		add_action( 'init', static function (): void {
			if ( get_option( 'bazantik_db_version' ) !== BAZANTIK_VERSION || get_option( Settings::OPT_FLUSH ) ) {
				flush_rewrite_rules( false );
				update_option( 'bazantik_db_version', BAZANTIK_VERSION, false );
				delete_option( Settings::OPT_FLUSH );
			}
		}, 99 );

		// Inicializace služeb a modulů
		( new Media_Optimizer() )->init();
		( new Exif_Service() )->init();
		( new Access_Control() )->init();
		( new Folder_Access() )->init();
		( new Admin_Metaboxes() )->init();
		( new Shortcodes_Blocks() )->init();
		( new Deferred_Upload() )->init();
		( new Processing_Admin() )->init();
		( new Settings() )->init();
		( new Overview_Sort() )->init();
		( new Processing_Rest() )->init();
		( new Processing_Cron() )->init();
		( new File_Protection() )->init();
		( new Library_Guard() )->init();
		( new Album_Password() )->init();
		( new Password_Reveal() )->init();
		( new Download() )->init();
		( new Privacy() )->init();

		// Assety, šablony a globální Lightbox DOM v patičce (ochrana před Stacking Contextem Avady)
		add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_frontend_assets' ] );
		add_action( 'wp_footer', [ $this, 'render_lightbox_markup' ] );
		add_filter( 'template_include', [ $this, 'template_override' ], PHP_INT_MAX );
	}

	public function enqueue_frontend_assets(): void {
		global $post;

		$has_block     = is_a( $post, 'WP_Post' ) && has_block( 'bazantik-photo-gallery/album-embed', $post );

		$has_album_embed = $has_block || ( is_a( $post, 'WP_Post' ) && has_shortcode( $post->post_content, 'bazantik_album' ) );
		$has_overview    = is_a( $post, 'WP_Post' ) && has_shortcode( $post->post_content, 'bazantik_albums_archive' );

		// Plné assety (lightbox, mapa) jen tam, kde se album skutečně otevírá; přehledy alb a složek potřebují jen styly karet
		if ( is_singular( 'bazantik_album' ) || $has_album_embed ) {
			$this->enqueue_assets();
		} elseif ( is_post_type_archive( 'bazantik_album' ) || is_tax( 'bazantik_folder' ) || $has_overview ) {
			$this->enqueue_styles();
		}
	}

	/**
	 * Verze souboru pro adresu `?ver=`: číslo verze + čas změny souboru, aby hosting ani prohlížeč
	 * po aktualizaci nepoužily 30 dní starou kopii stejné adresy.
	 */
	private static function asset_version( string $relative_path ): string {
		$mtime = @filemtime( BAZANTIK_PLUGIN_DIR . $relative_path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		return $mtime ? BAZANTIK_VERSION . '.' . $mtime : BAZANTIK_VERSION;
	}

	public function enqueue_styles(): void {
		wp_enqueue_style( 'bazantik-photo-gallery-css', BAZANTIK_PLUGIN_URL . 'assets/css/gallery.css', [], self::asset_version( 'assets/css/gallery.css' ) );
	}

	public function enqueue_assets(): void {
		if ( wp_script_is( 'bazantik-lightbox-js', 'enqueued' ) ) {
			return;
		}

		// Leaflet CSS & JS pro mapy (přibaleno lokálně v pluginu – 100% GDPR soulad bez externích CDN)
		wp_enqueue_style( 'bazantik-leaflet-css', BAZANTIK_PLUGIN_URL . 'assets/vendor/leaflet/leaflet.css', [], '1.9.4' );
		wp_enqueue_script( 'bazantik-leaflet-js', BAZANTIK_PLUGIN_URL . 'assets/vendor/leaflet/leaflet.js', [], '1.9.4', true );

		// Vlastní frontend styly a skripty
		wp_enqueue_style( 'bazantik-photo-gallery-css', BAZANTIK_PLUGIN_URL . 'assets/css/gallery.css', [], self::asset_version( 'assets/css/gallery.css' ) );
		wp_enqueue_script( 'bazantik-lightbox-js', BAZANTIK_PLUGIN_URL . 'assets/js/gallery-lightbox.js', [ 'bazantik-leaflet-js' ], self::asset_version( 'assets/js/gallery-lightbox.js' ), true );

		// Při globálně vypnutých mapách se adresy dlaždic (a v nich API klíč) do stránky vůbec nevypisují
		$maps_on = 'off' !== Settings::map_mode();

		wp_localize_script( 'bazantik-lightbox-js', 'bazantikConfig', [
			'tileLayer'   => $maps_on ? Settings::tile_url() : Settings::OSM_TILE_URL,
			'homeUrl'     => home_url( '/' ),
			'attribution' => $maps_on ? Settings::tile_attribution() : Settings::osm_attribution(),
			'mapProvider' => $maps_on ? Settings::effective_provider() : 'osm',
			'mapProviderName' => $maps_on ? Settings::tile_provider_name() : 'OpenStreetMap',
			'mapLogo'     => $maps_on ? Settings::tile_logo() : [],
			'mapLayers'   => $maps_on ? Settings::map_layers() : [],
			'mapSwitch'   => $maps_on && Settings::map_layer_switch(),
			'osmTileLayer' => Settings::OSM_TILE_URL,
			'osmAttribution' => Settings::osm_attribution(),
			'strings'     => [
				'slideshowStart' => __( 'Start slideshow', 'bazantik-photo-gallery' ),
				'slideshowStop'  => __( 'Stop slideshow', 'bazantik-photo-gallery' ),
				'close'          => __( 'Close (Esc)', 'bazantik-photo-gallery' ),
				'videoError'     => __( 'This video could not be played in this browser (probably an unsupported codec, e.g. HEVC). Try another browser or download the video.', 'bazantik-photo-gallery' ),
				'expandToolbar'  => __( 'Expand toolbar', 'bazantik-photo-gallery' ),
				'collapseToolbar' => __( 'Collapse toolbar', 'bazantik-photo-gallery' ),
				'mediaDetail'    => __( 'Media detail', 'bazantik-photo-gallery' ),
				'isoTitle'       => __( 'ISO sensitivity', 'bazantik-photo-gallery' ),
				'shutterTitle'   => __( 'Shutter speed', 'bazantik-photo-gallery' ),
				'apertureTitle'  => __( 'Aperture (iris)', 'bazantik-photo-gallery' ),
				'focalTitle'     => __( 'Focal length (lens)', 'bazantik-photo-gallery' ),
				'focalTitle35'   => __( 'Focal length (35mm equivalent)', 'bazantik-photo-gallery' ),
				'grpFile'        => __( 'File', 'bazantik-photo-gallery' ),
				'grpExposure'    => __( 'Exposure settings', 'bazantik-photo-gallery' ),
				'grpGear'        => __( 'Gear & software', 'bazantik-photo-gallery' ),
				'lblFile'        => __( 'File:', 'bazantik-photo-gallery' ),
				'lblSize'        => __( 'Size:', 'bazantik-photo-gallery' ),
				'lblDimensions'  => __( 'Dimensions:', 'bazantik-photo-gallery' ),
				'lblCreated'     => __( 'Date:', 'bazantik-photo-gallery' ),
				'lblIso'         => __( 'ISO:', 'bazantik-photo-gallery' ),
				'lblExposureTime' => __( 'Exposure time:', 'bazantik-photo-gallery' ),
				'lblAperture'    => __( 'Aperture:', 'bazantik-photo-gallery' ),
				'lblFocal'       => __( 'Focal length:', 'bazantik-photo-gallery' ),
				'lblFocal35'     => __( 'Focal length (35mm equiv.):', 'bazantik-photo-gallery' ),
				'lblBias'        => __( 'Exposure compensation:', 'bazantik-photo-gallery' ),
				'lblCamera'      => __( 'Camera:', 'bazantik-photo-gallery' ),
				'lblLens'        => __( 'Lens:', 'bazantik-photo-gallery' ),
				'lblSoftware'    => __( 'Software:', 'bazantik-photo-gallery' ),
				'fullscreenEnter' => __( 'Full screen', 'bazantik-photo-gallery' ),
				'fullscreenExit' => __( 'Exit full screen', 'bazantik-photo-gallery' ),
				/* translators: %s: map provider name */
				'mapProblem'     => __( 'The map from %s is not loading (the provider may be unavailable or its free limit has been used up).', 'bazantik-photo-gallery' ),
				'mapUseOsm'      => __( 'Switch to OpenStreetMap (it will receive your IP address)', 'bazantik-photo-gallery' ),
			],
		] );
	}

	/**
	 * Vykreslí kompletní DOM Lightboxu přímo do <body> (přes wp_footer).
	 * Zabraňuje uvěznění Lightboxu ve vnořeném kontejneru šablony Avada (CSS transform / filter).
	 */
	public function render_lightbox_markup(): void {
		// Vykreslit pouze pokud byly načteny skripty galerie
		if ( ! wp_script_is( 'bazantik-lightbox-js', 'enqueued' ) ) {
			return;
		}
		?>
		<div id="bazantik-lightbox" class="bazantik-lightbox-modal" tabindex="-1" role="dialog" aria-modal="true" aria-label="<?php echo esc_attr__( 'Photo viewer', 'bazantik-photo-gallery' ); ?>" aria-hidden="true">
		<div class="bazantik-lightbox-backdrop"></div>
		<div class="bazantik-lightbox-content">
		<div class="bazantik-media-viewport" id="bazantik-viewport">
		<div id="bazantik-media-holder"></div>
		<div class="bazantik-lightbox-spinner" id="bazantik-spinner">
		<svg viewBox="0 0 40 40" width="44" height="44" class="bazantik-spinner-svg">
		<circle cx="20" cy="5" r="2.8" opacity="1"></circle>
		<circle cx="30.6" cy="9.4" r="2.8" opacity="0.875"></circle>
		<circle cx="35" cy="20" r="2.8" opacity="0.75"></circle>
		<circle cx="30.6" cy="30.6" r="2.8" opacity="0.625"></circle>
		<circle cx="20" cy="35" r="2.8" opacity="0.5"></circle>
		<circle cx="9.4" cy="30.6" r="2.8" opacity="0.375"></circle>
		<circle cx="5" cy="20" r="2.8" opacity="0.25"></circle>
		<circle cx="9.4" cy="9.4" r="2.8" opacity="0.125"></circle>
		</svg>
		</div>
		</div>
		
		<aside class="bazantik-lightbox-sidebar" id="bazantik-sidebar">
		<div class="bazantik-sidebar-header">
		<nav class="bazantik-sidebar-tabs" role="tablist">
		<button type="button" class="bazantik-tab-btn is-active" id="bazantik-tab-info" role="tab" aria-selected="true" aria-controls="bazantik-panel-info"><?php esc_html_e( 'Photo information', 'bazantik-photo-gallery' ); ?></button>
		<button type="button" class="bazantik-tab-btn" id="bazantik-tab-settings" role="tab" aria-selected="false" aria-controls="bazantik-panel-settings"><?php esc_html_e( 'Settings', 'bazantik-photo-gallery' ); ?></button>
		</nav>
		</div>
		
		<div class="bazantik-sidebar-body">
		<div id="bazantik-panel-info" class="bazantik-panel-view is-active" role="tabpanel" aria-labelledby="bazantik-tab-info">
		<h3 class="bazantik-photo-title" id="bazantik-sidebar-title"></h3>
		<div id="bazantik-exif-container" class="bazantik-info-section"></div>
		<div id="bazantik-map-container" class="bazantik-info-section">
		<h4><?php esc_html_e( 'Capture location', 'bazantik-photo-gallery' ); ?></h4>
		<div id="bazantik-map-consent" class="bazantik-map-consent">
		<button type="button" id="bazantik-map-load" class="bazantik-map-load"><?php esc_html_e( 'Show map', 'bazantik-photo-gallery' ); ?></button>
		<p><?php esc_html_e( 'After you click, the map background is loaded from the map provider, which then receives your IP address.', 'bazantik-photo-gallery' ); ?></p>
		</div>
		<div id="bazantik-leaflet-map"></div>
		<button type="button" id="bazantik-map-revoke" class="bazantik-map-revoke"><?php esc_html_e( 'Withdraw consent to loading maps from an external provider (sharing your IP address). The map will be shown again only after you consent again.', 'bazantik-photo-gallery' ); ?></button>
		</div>
		</div>
		
		<div id="bazantik-panel-settings" class="bazantik-panel-view" role="tabpanel" aria-labelledby="bazantik-tab-settings">
		<div class="bazantik-settings-group">
		<div class="bazantik-settings-group-title"><?php esc_html_e( 'Automatic slideshow', 'bazantik-photo-gallery' ); ?></div>
		
		<div class="bazantik-settings-row">
		<div class="bazantik-setting-header">
		<span class="bazantik-setting-label" id="bazantik-lbl-speed"><?php esc_html_e( 'Slideshow speed', 'bazantik-photo-gallery' ); ?></span>
		<span class="bazantik-setting-badge" id="bazantik-speed-val">4 s</span>
		</div>
		<div class="bazantik-slider-wrap">
		<span class="bazantik-slider-bound">1 s</span>
		<input type="range" id="bazantik-setting-speed" aria-labelledby="bazantik-lbl-speed" min="1" max="10" step="1" value="4">
		<span class="bazantik-slider-bound">10 s</span>
		</div>
		</div>
		
		<div class="bazantik-settings-row bazantik-settings-row-toggle">
		<div class="bazantik-setting-text">
		<span class="bazantik-setting-label" id="bazantik-lbl-loop"><?php esc_html_e( 'Loop slideshow', 'bazantik-photo-gallery' ); ?></span>
		<span class="bazantik-setting-desc"><?php esc_html_e( 'Start again from the beginning after the last photo', 'bazantik-photo-gallery' ); ?></span>
		</div>
		<label class="bazantik-switch" title="<?php esc_attr_e( 'Toggle loop', 'bazantik-photo-gallery' ); ?>">
		<input type="checkbox" id="bazantik-setting-loop" aria-labelledby="bazantik-lbl-loop" checked>
		<span class="bazantik-switch-slider"></span>
		</label>
		</div>
		</div>
		
		<div class="bazantik-settings-group">
		<div class="bazantik-settings-group-title"><?php esc_html_e( 'Display and controls', 'bazantik-photo-gallery' ); ?></div>
		
		<div class="bazantik-settings-row bazantik-settings-row-toggle" id="bazantik-row-zoom-source" style="display:none">
		<div class="bazantik-setting-text">
		<span class="bazantik-setting-label" id="bazantik-lbl-zoom-original"><?php esc_html_e( 'Original when zooming', 'bazantik-photo-gallery' ); ?></span>
		<span class="bazantik-setting-desc"><?php esc_html_e( 'Load the full original when zooming in (sharper, but up to several tens of MB extra of mobile data, depending on the photo size). Off = faster compressed version of the photo.', 'bazantik-photo-gallery' ); ?></span>
		</div>
		<label class="bazantik-switch" title="<?php esc_attr_e( 'Toggle zoom source', 'bazantik-photo-gallery' ); ?>">
		<input type="checkbox" id="bazantik-setting-zoom-original" aria-labelledby="bazantik-lbl-zoom-original">
		<span class="bazantik-switch-slider"></span>
		</label>
		</div>
		
		<div class="bazantik-settings-row bazantik-settings-row-toggle">
		<div class="bazantik-setting-text">
		<span class="bazantik-setting-label" id="bazantik-lbl-double-tap"><?php esc_html_e( 'Double-tap or double-click to zoom', 'bazantik-photo-gallery' ); ?></span>
		<span class="bazantik-setting-desc"><?php esc_html_e( 'Off = a single tap or click zooms in', 'bazantik-photo-gallery' ); ?></span>
		</div>
		<label class="bazantik-switch" title="<?php esc_attr_e( 'Toggle double-tap zoom', 'bazantik-photo-gallery' ); ?>">
		<input type="checkbox" id="bazantik-setting-double-tap" aria-labelledby="bazantik-lbl-double-tap">
		<span class="bazantik-switch-slider"></span>
		</label>
		</div>
		
		<div class="bazantik-settings-row bazantik-settings-row-toggle bazantik-desktop-only">
		<div class="bazantik-setting-text">
		<span class="bazantik-setting-label" id="bazantik-lbl-arrows"><?php esc_html_e( 'Navigation arrows', 'bazantik-photo-gallery' ); ?></span>
		<span class="bazantik-setting-desc"><?php esc_html_e( 'Show side arrows for switching photos', 'bazantik-photo-gallery' ); ?></span>
		</div>
		<label class="bazantik-switch" title="<?php esc_attr_e( 'Toggle arrow visibility', 'bazantik-photo-gallery' ); ?>">
		<input type="checkbox" id="bazantik-setting-arrows" aria-labelledby="bazantik-lbl-arrows" checked>
		<span class="bazantik-switch-slider"></span>
		</label>
		</div>
		
		<div class="bazantik-settings-row bazantik-settings-row-toggle">
		<div class="bazantik-setting-text">
		<span class="bazantik-setting-label" id="bazantik-lbl-autohide"><?php esc_html_e( 'Hide controls automatically', 'bazantik-photo-gallery' ); ?></span>
		<span class="bazantik-setting-desc"><?php esc_html_e( 'Smoothly hide all bars and arrows when idle', 'bazantik-photo-gallery' ); ?></span>
		</div>
		<label class="bazantik-switch" title="<?php esc_attr_e( 'Toggle automatic hiding', 'bazantik-photo-gallery' ); ?>">
		<input type="checkbox" id="bazantik-setting-autohide" aria-labelledby="bazantik-lbl-autohide">
		<span class="bazantik-switch-slider"></span>
		</label>
		</div>
		
		<div class="bazantik-settings-row" id="bazantik-row-autohide-delay" style="display: none;">
		<div class="bazantik-setting-header">
		<span class="bazantik-setting-label" id="bazantik-lbl-autohide-delay"><?php esc_html_e( 'Time until controls hide', 'bazantik-photo-gallery' ); ?></span>
		<span class="bazantik-setting-badge" id="bazantik-autohide-val">3 s</span>
		</div>
		<div class="bazantik-slider-wrap">
		<span class="bazantik-slider-bound">1 s</span>
		<input type="range" id="bazantik-setting-autohide-delay" aria-labelledby="bazantik-lbl-autohide-delay" min="1" max="5" step="1" value="3">
		<span class="bazantik-slider-bound">5 s</span>
		</div>
		</div>
		
		<div class="bazantik-settings-row bazantik-settings-row-toggle">
		<div class="bazantik-setting-text">
		<span class="bazantik-setting-label" id="bazantik-lbl-translucent"><?php esc_html_e( 'Translucent background', 'bazantik-photo-gallery' ); ?></span>
		<span class="bazantik-setting-desc"><?php esc_html_e( 'Let the page show faintly through the background', 'bazantik-photo-gallery' ); ?></span>
		</div>
		<label class="bazantik-switch" title="<?php esc_attr_e( 'Toggle translucent background', 'bazantik-photo-gallery' ); ?>">
		<input type="checkbox" id="bazantik-setting-translucent" aria-labelledby="bazantik-lbl-translucent" checked>
		<span class="bazantik-switch-slider"></span>
		</label>
		</div>
		
		<div class="bazantik-settings-row" id="bazantik-row-backdrop">
		<div class="bazantik-setting-header">
		<span class="bazantik-setting-label" id="bazantik-lbl-backdrop"><?php esc_html_e( 'Background darkness', 'bazantik-photo-gallery' ); ?></span>
		<span class="bazantik-setting-badge" id="bazantik-backdrop-val">95 %</span>
		</div>
		<div class="bazantik-slider-wrap">
		<span class="bazantik-slider-bound">75 %</span>
		<input type="range" id="bazantik-setting-backdrop" aria-labelledby="bazantik-lbl-backdrop" min="75" max="95" step="5" value="95">
		<span class="bazantik-slider-bound">95 %</span>
		</div>
		</div>
		
		<div class="bazantik-settings-row bazantik-settings-row-toggle" id="bazantik-row-blur-toggle">
		<div class="bazantik-setting-text">
		<span class="bazantik-setting-label" id="bazantik-lbl-blur"><?php esc_html_e( 'Blur background', 'bazantik-photo-gallery' ); ?></span>
		<span class="bazantik-setting-desc"><?php esc_html_e( 'Frosted glass effect behind the photo', 'bazantik-photo-gallery' ); ?></span>
		</div>
		<label class="bazantik-switch" title="<?php esc_attr_e( 'Toggle background blur', 'bazantik-photo-gallery' ); ?>">
		<input type="checkbox" id="bazantik-setting-blur" aria-labelledby="bazantik-lbl-blur">
		<span class="bazantik-switch-slider"></span>
		</label>
		</div>
		
		<div class="bazantik-settings-row" id="bazantik-row-blur-amount" style="display: none;">
		<div class="bazantik-setting-header">
		<span class="bazantik-setting-label" id="bazantik-lbl-blur-amount"><?php esc_html_e( 'Blur strength', 'bazantik-photo-gallery' ); ?></span>
		<span class="bazantik-setting-badge" id="bazantik-blur-val">5 px</span>
		</div>
		<div class="bazantik-slider-wrap">
		<span class="bazantik-slider-bound">1 px</span>
		<input type="range" id="bazantik-setting-blur-amount" aria-labelledby="bazantik-lbl-blur-amount" min="1" max="10" step="1" value="5">
		<span class="bazantik-slider-bound">10 px</span>
		</div>
		</div>
		</div>
		</div>
		</div>
		</aside>
		</div>
		
		<button type="button" class="bazantik-lb-btn bazantik-btn-prev" id="bazantik-btn-prev" title="<?php esc_attr_e( 'Previous', 'bazantik-photo-gallery' ); ?>" aria-label="<?php esc_attr_e( 'Previous', 'bazantik-photo-gallery' ); ?>">
		<svg viewBox="0 0 24 24" width="28" height="28" stroke="currentColor" stroke-width="2" fill="none" stroke-linecap="round" stroke-linejoin="round"><polyline points="15 18 9 12 15 6"></polyline></svg>
		</button>
		<button type="button" class="bazantik-lb-btn bazantik-btn-next" id="bazantik-btn-next" title="<?php esc_attr_e( 'Next', 'bazantik-photo-gallery' ); ?>" aria-label="<?php esc_attr_e( 'Next', 'bazantik-photo-gallery' ); ?>">
		<svg viewBox="0 0 24 24" width="28" height="28" stroke="currentColor" stroke-width="2" fill="none" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 18 15 12 9 6"></polyline></svg>
		</button>
		
		<div class="bazantik-top-toolbar" id="bazantik-top-toolbar">
		<div class="bazantik-toolbar-actions" id="bazantik-toolbar-actions">
		<button type="button" class="bazantik-tool-btn" id="bazantik-btn-info" title="<?php esc_attr_e( 'Information and settings', 'bazantik-photo-gallery' ); ?>" aria-label="<?php esc_attr_e( 'Information and settings', 'bazantik-photo-gallery' ); ?>">
		<svg viewBox="0 0 24 24" width="20" height="20" stroke="currentColor" stroke-width="2" fill="none" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"></circle><line x1="12" y1="16" x2="12" y2="12"></line><line x1="12" y1="8" x2="12.01" y2="8"></line></svg>
		</button>
		<button type="button" class="bazantik-tool-btn" id="bazantik-btn-fullscreen" title="<?php esc_attr_e( 'Full screen', 'bazantik-photo-gallery' ); ?>" aria-label="<?php esc_attr_e( 'Full screen', 'bazantik-photo-gallery' ); ?>">
		<svg id="bazantik-icon-fs" viewBox="0 0 24 24" width="20" height="20" stroke="currentColor" stroke-width="2" fill="none" stroke-linecap="round" stroke-linejoin="round"><path d="M8 3H5a2 2 0 0 0-2 2v3m18 0V5a2 2 0 0 0-2-2h-3m0 18h3a2 2 0 0 0 2-2v-3M3 16v3a2 2 0 0 0 2 2h3"/></svg>
		</button>
		<button type="button" class="bazantik-tool-btn" id="bazantik-btn-play" title="<?php esc_attr_e( 'Slideshow', 'bazantik-photo-gallery' ); ?>" aria-label="<?php esc_attr_e( 'Slideshow', 'bazantik-photo-gallery' ); ?>">
		<svg id="bazantik-icon-play" viewBox="0 0 24 24" width="20" height="20" stroke="currentColor" stroke-width="2" fill="none" stroke-linecap="round" stroke-linejoin="round"><polygon points="5 3 19 12 5 21 5 3"></polygon></svg>
		</button>
		<a class="bazantik-tool-btn" id="bazantik-btn-download" tabindex="0" title="<?php esc_attr_e( 'Download in full quality', 'bazantik-photo-gallery' ); ?>" aria-label="<?php esc_attr_e( 'Download', 'bazantik-photo-gallery' ); ?>" download>
		<svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
		</a>
		<button type="button" class="bazantik-tool-btn bazantik-btn-close" id="bazantik-btn-close" title="<?php esc_attr_e( 'Close (Esc)', 'bazantik-photo-gallery' ); ?>" aria-label="<?php esc_attr_e( 'Close', 'bazantik-photo-gallery' ); ?>">
		<svg viewBox="0 0 24 24" width="20" height="20" stroke="currentColor" stroke-width="2" fill="none" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg>
		</button>
		</div>
		<button type="button" class="bazantik-tool-btn bazantik-btn-collapse" id="bazantik-btn-collapse" title="<?php esc_attr_e( 'Collapse / expand the toolbar', 'bazantik-photo-gallery' ); ?>" aria-label="<?php esc_attr_e( 'Collapse toolbar', 'bazantik-photo-gallery' ); ?>">
		<svg id="bazantik-icon-collapse" viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
		<polyline points="13 17 18 12 13 7"></polyline>
		<polyline points="6 17 11 12 6 7"></polyline>
		</svg>
		</button>
		</div>
		
		<div class="bazantik-hq-spinner" id="bazantik-hq-spinner" title="<?php esc_attr_e( 'Loading the full sharpness of the original...', 'bazantik-photo-gallery' ); ?>" aria-label="<?php esc_attr_e( 'Loading full sharpness', 'bazantik-photo-gallery' ); ?>">
		<svg viewBox="0 0 40 40" width="20" height="20" class="bazantik-spinner-svg">
		<circle cx="20" cy="5" r="2.8" opacity="1"></circle>
		<circle cx="30.6" cy="9.4" r="2.8" opacity="0.875"></circle>
		<circle cx="35" cy="20" r="2.8" opacity="0.75"></circle>
		<circle cx="30.6" cy="30.6" r="2.8" opacity="0.625"></circle>
		<circle cx="20" cy="35" r="2.8" opacity="0.5"></circle>
		<circle cx="9.4" cy="30.6" r="2.8" opacity="0.375"></circle>
		<circle cx="5" cy="20" r="2.8" opacity="0.25"></circle>
		<circle cx="9.4" cy="9.4" r="2.8" opacity="0.125"></circle>
		</svg>
		</div>
		</div>
		<?php
	}

	/**
	 * Název části šablony tématu pro danou oblast (header/footer). Téma může hlavičku nazvat jinak
	 * než „header“, proto se hledá podle oblasti; „header“/„footer“ je výchozí a záložní hodnota.
	 */
	private static function template_part_slug( string $area ): string {
		static $cache = [];
		if ( isset( $cache[ $area ] ) ) {
			return $cache[ $area ];
		}
		$slug = $area;
		if ( function_exists( 'get_block_templates' ) ) {
			$slugs = [];
			foreach ( get_block_templates( [ 'area' => $area ], 'wp_template_part' ) as $part ) {
				if ( $part->area === $area ) {
					$slugs[] = $part->slug;
				}
			}
			if ( $slugs && ! in_array( $area, $slugs, true ) ) {
				$slug = $slugs[0];
			}
		}
		$cache[ $area ] = sanitize_key( $slug );
		return $cache[ $area ];
	}

	/**
	 * Hlavička stránky pro šablony pluginu. Klasické téma: get_header(). Blokové téma nemá header.php,
	 * proto se sestaví dokument ručně a použije se část šablony „header“ tématu.
	 */
	public static function theme_header(): void {
		if ( ! function_exists( 'wp_is_block_theme' ) || ! wp_is_block_theme() ) {
			get_header();
			return;
		}
		// Hlavička se vykreslí před wp_head(), aby styly bloků použitých v ní skončily v <head>
		$header_html = do_blocks( '<!-- wp:template-part {"slug":"' . self::template_part_slug( 'header' ) . '","tagName":"header"} /-->' );
		?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="<?php bloginfo( 'charset' ); ?>">
<meta name="viewport" content="width=device-width, initial-scale=1">
		<?php wp_head(); ?>
</head>
<body <?php body_class(); ?>>
		<?php wp_body_open(); ?>
<div class="wp-site-blocks">
		<?php
		echo $header_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- vykreslení bloku jádrem
	}

	public static function theme_footer(): void {
		if ( ! function_exists( 'wp_is_block_theme' ) || ! wp_is_block_theme() ) {
			get_footer();
			return;
		}
		echo do_blocks( '<!-- wp:template-part {"slug":"' . self::template_part_slug( 'footer' ) . '","tagName":"footer"} /-->' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- vykreslení bloku jádrem
		echo '</div>';
		wp_footer();
		echo '</body></html>';
	}

	public function template_override( string $template ): string {
		if ( is_singular( 'bazantik_album' ) ) {
			$theme_file = locate_template( [ 'single-bazantik_album.php' ] );
			return $theme_file ? $theme_file : BAZANTIK_PLUGIN_DIR . 'templates/single-bazantik_album.php';
		}

		if ( is_post_type_archive( 'bazantik_album' ) || is_tax( 'bazantik_folder' ) ) {
			$theme_file = locate_template( [ 'archive-bazantik_album.php' ] );
			return $theme_file ? $theme_file : BAZANTIK_PLUGIN_DIR . 'templates/archive-bazantik_album.php';
		}

		return $template;
	}
}
