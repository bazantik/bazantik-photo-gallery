<?php
declare(strict_types=1);

namespace BazantikPhotoGallery;

defined( 'ABSPATH' ) || exit;

/**
 * Rychlý upload: u JPEG nahraných z editace alba vytvoří jen thumbnail
 * a těžké zpracování (4K WebP, další velikosti) odloží na worker.
 *
 * Zapíná se v Nastavení pluginu nebo konstantou BAZANTIK_DEFERRED_UPLOADS ve wp-config.php (konstanta má přednost).
 */
class Deferred_Upload {
	/** ID přílohy, která je právě zpracovávána v rychlém režimu (platí jen pro aktuální požadavek). */
	private static ?int $active_id = null;

	public function init(): void {
		add_action( 'add_attachment', [ $this, 'maybe_start_deferred' ] );
		add_action( 'add_attachment', [ $this, 'remember_upload_key' ], 5 );
		// async-upload.php volá obsluhu nahrání přímo (bez akce wp_ajax_*), proto se kontroluje už na admin_init
		add_action( 'admin_init', [ $this, 'reuse_duplicate_upload' ], 0 );
		add_filter( 'big_image_size_threshold', [ $this, 'disable_threshold' ], 10000, 4 );
		add_filter( 'intermediate_image_sizes_advanced', [ $this, 'only_thumbnail' ], 10000, 3 );
		add_filter( 'image_editor_output_format', [ $this, 'disable_conversion' ], 10000 );
		add_filter( 'wp_generate_attachment_metadata', [ $this, 'finish_deferred' ], 9999, 2 );
		add_filter( 'wp_prepare_attachment_for_js', [ $this, 'add_status_to_js' ], 20, 2 );

		// Sloupec „Zpracování“ v Media → Library (zobrazení seznamu)
		add_filter( 'manage_media_columns', [ $this, 'add_media_column' ] );
		add_action( 'manage_media_custom_column', [ $this, 'render_media_column' ], 10, 2 );
	}

	public static function is_enabled(): bool {
		return Settings::deferred_upload();
	}

	public static function is_active(): bool {
		return null !== self::$active_id;
	}

	private const UPLOAD_KEY_META = '_bazantik_upload_key';

	private static function posted_upload_key(): string {
		$key = isset( $_POST['bazantik_upload_key'] ) ? sanitize_text_field( wp_unslash( $_POST['bazantik_upload_key'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce ověřuje jádro (upload-attachment) i tento kód před použitím
		return preg_match( '/^[A-Za-z0-9-]{8,64}$/', $key ) ? $key : '';
	}

	/**
	 * Klíč nahrání se uloží k příloze vytvořené v editoru alba (volá se jen v požadavku upload-attachment).
	 */
	public function remember_upload_key( int $attachment_id ): void {
		if ( ! wp_doing_ajax() || sanitize_key( wp_unslash( $_POST['action'] ?? '' ) ) !== 'upload-attachment' ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce se ověřuje v reuse_duplicate_upload() před nahráním
			return;
		}
		$key = self::posted_upload_key();
		if ( '' !== $key ) {
			update_post_meta( $attachment_id, self::UPLOAD_KEY_META, $key );
		}
	}

	/**
	 * Opakované nahrání po chybě spojení: pokud server předchozí pokus ve skutečnosti dokončil, vrátí se už
	 * vytvořená příloha místo druhé kopie. Klíč má jen opakování téhož souboru; nové nahrání stejného souboru ho nemá.
	 */
	public function reuse_duplicate_upload(): void {
		if ( ! wp_doing_ajax() || sanitize_key( wp_unslash( $_POST['action'] ?? '' ) ) !== 'upload-attachment' ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce se ověřuje hned níže
			return;
		}
		check_ajax_referer( 'media-form' );
		if ( ! current_user_can( 'upload_files' ) ) {
			return; // chybu vrátí jádro
		}
		$key = self::posted_upload_key();
		if ( '' === $key ) {
			return;
		}
		$found = get_posts(
			[
				'post_type'        => 'attachment',
				'post_status'      => 'inherit',
				'author'           => get_current_user_id(),
				'meta_key'         => self::UPLOAD_KEY_META, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value'       => $key, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
				'posts_per_page'   => 1,
				'fields'           => 'ids',
				'no_found_rows'    => true,
				'suppress_filters' => true, // phpcs:ignore WordPressVIPMinimum.Performance.WPQueryParams.SuppressFilters_suppress_filters -- hledání podle klíče nahrání nesmí ovlivnit cizí filtry dotazů
			]
		);
		if ( empty( $found ) ) {
			return;
		}
		$data = wp_prepare_attachment_for_js( (int) $found[0] );
		if ( $data ) {
			wp_send_json_success( $data );
		}
	}

	/**
	 * Spustí se hned po vložení přílohy do DB (ještě před generováním velikostí).
	 */
	public function maybe_start_deferred( int $attachment_id ): void {
		self::$active_id = null;

		if ( ! self::is_enabled() ) {
			return;
		}
		if ( 'image/jpeg' !== get_post_mime_type( $attachment_id ) ) {
			return;
		}

		$parent_id = (int) wp_get_post_parent_id( $attachment_id );
		if ( ! $parent_id || 'bazantik_album' !== get_post_type( $parent_id ) ) {
			return;
		}

		self::$active_id = $attachment_id;
		update_option( 'bazantik_last_upload_at', time(), false ); // pro volitelný „zdvořilý“ worker
		update_post_meta( $attachment_id, Processing_Status::META_HOLD, time() );
		Processing_Status::set( $attachment_id, Processing_Status::PENDING );
		update_post_meta( $attachment_id, Processing_Status::META_ATTEMPTS, 0 );
	}

	public function disable_threshold( $threshold, $imagesize = [], $file = '', $attachment_id = 0 ) {
		if ( null !== self::$active_id && (int) $attachment_id === self::$active_id ) {
			return false;
		}
		return $threshold;
	}

	public function only_thumbnail( array $sizes, $image_meta = [], $attachment_id = 0 ): array {
		if ( null !== self::$active_id && (int) $attachment_id === self::$active_id ) {
			return isset( $sizes['thumbnail'] ) ? [ 'thumbnail' => $sizes['thumbnail'] ] : [];
		}
		return $sizes;
	}

	public function disable_conversion( $formats ) {
		return self::is_active() ? [] : $formats;
	}

	/**
	 * Konec rychlého režimu pro tento požadavek.
	 */
	public function finish_deferred( array $metadata, int $attachment_id ): array {
		if ( self::$active_id === $attachment_id ) {
			self::$active_id = null;
			delete_post_meta( $attachment_id, Processing_Status::META_HOLD );
		}
		return $metadata;
	}

	/**
	 * Stav zpracování pro JavaScript (metabox alba po nahrání přes okno médií).
	 */
	public function add_status_to_js( array $response, \WP_Post $attachment ): array {
		$response['bazantikStatus'] = Processing_Status::get( $attachment->ID );

		$exif                    = get_post_meta( $attachment->ID, '_bazantik_exif_data', true );
		$response['bazantikCaptured'] = is_array( $exif ) ? (string) ( $exif['captured_sort'] ?? '' ) : '';
		return $response;
	}

	public function add_media_column( array $columns ): array {
		$columns['bazantik_status'] = __( 'Processing', 'bazantik-photo-gallery' );
		return $columns;
	}

	public function render_media_column( string $column_name, int $attachment_id ): void {
		if ( 'bazantik_status' !== $column_name ) {
			return;
		}

		$status = (string) get_post_meta( $attachment_id, Processing_Status::META_STATUS, true );
		if ( '' === $status ) {
			echo '—';
			return;
		}

		$labels = [
			Processing_Status::PENDING    => __( '⏳ Pending', 'bazantik-photo-gallery' ),
			Processing_Status::PROCESSING => __( '⚙️ Processing', 'bazantik-photo-gallery' ),
			Processing_Status::READY      => __( '✅ Ready', 'bazantik-photo-gallery' ),
			Processing_Status::FAILED     => __( '❌ Failed', 'bazantik-photo-gallery' ),
		];
		echo esc_html( $labels[ $status ] ?? $status );
	}
}
