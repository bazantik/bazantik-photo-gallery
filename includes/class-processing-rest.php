<?php
declare(strict_types=1);

namespace BazantikPhotoGallery;

defined( 'ABSPATH' ) || exit;

/**
 * REST API pro zpracování fotek na pozadí (volá ho smyčka v editoru alba).
 */
class Processing_Rest {
	private const ROUTE_NS = 'bazantik-photo-gallery/v1';
	private const MAX_IDS  = 3000;

	public function init(): void {
		add_action( 'rest_api_init', [ $this, 'register_routes' ] );
	}

	public function register_routes(): void {
		$routes = [
			'process' => 'handle_process',
			'status'  => 'handle_status',
			'retry'   => 'handle_retry',
			'album-media' => 'handle_album_media',
			'seen'        => 'handle_seen',
			'video-poster' => 'handle_video_poster',
			'video-info'   => 'handle_video_info',
			'chain'        => 'handle_chain',
			'queue'        => 'handle_queue',
		];

		foreach ( $routes as $route => $method ) {
			register_rest_route(
				self::ROUTE_NS,
				'/' . $route,
				[
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => [ $this, $method ],
					'permission_callback' => static fn(): bool => current_user_can( in_array( $route, [ 'retry', 'queue' ], true ) ? 'edit_others_posts' : 'upload_files' ),
				]
			);
		}
	}

	/**
	 * Zpracuje krátkou dávku a vrátí aktuální stavy fotek alba.
	 */
	public function handle_process( \WP_REST_Request $request ): \WP_REST_Response {
		$budget = Settings::perf()['editor']; // délku dávky určuje profil z Nastavení, ne prohlížeč
		$priority = array_values( array_filter( array_slice( array_map( 'absint', (array) $request->get_param( 'ids' ) ), 0, self::MAX_IDS ), static fn( int $id ): bool => current_user_can( 'edit_post', $id ) ) );
		$result   = Processing_Queue::run_locked_batch( $budget, $priority );

		return new \WP_REST_Response( array_merge( $result, $this->snapshot( $request ) ), 200 );
	}

	public function handle_status( \WP_REST_Request $request ): \WP_REST_Response {
		return new \WP_REST_Response( $this->snapshot( $request ), 200 );
	}

	/**
	 * Automatické uložení seznamu fotek u konceptů alb (jen koncepty, publikovaná alba se tudy neukládají).
	 */
	public function handle_album_media( \WP_REST_Request $request ): \WP_REST_Response {
		$post_id = absint( $request->get_param( 'post_id' ) );

		if ( ! $post_id || 'bazantik_album' !== get_post_type( $post_id ) || ! current_user_can( 'edit_post', $post_id ) ) {
			return new \WP_REST_Response( [ 'saved' => false ], 200 );
		}

		if ( ! in_array( get_post_status( $post_id ), [ 'auto-draft', 'draft', 'pending' ], true ) ) {
			return new \WP_REST_Response( [ 'saved' => false ], 200 );
		}

		$ids = Library_Guard::sanitize_media_ids( $post_id, (array) $request->get_param( 'ids' ) );

		update_post_meta( $post_id, '_bazantik_media_ids', implode( ',', $ids ) );
		Library_Guard::remember_seen( $post_id, $ids );

		$cover = absint( $request->get_param( 'cover' ) );
		update_post_meta( $post_id, '_bazantik_cover_id', in_array( $cover, $ids, true ) ? $cover : 0 );

		return new \WP_REST_Response( [ 'saved' => true ], 200 );
	}

	/**
	 * Trvale zavře nabídku „nalezených“ fotek (označí je jako již viděné).
	 */
	public function handle_seen( \WP_REST_Request $request ): \WP_REST_Response {
		$post_id = absint( $request->get_param( 'post_id' ) );

		if ( ! $post_id || 'bazantik_album' !== get_post_type( $post_id ) || ! current_user_can( 'edit_post', $post_id ) ) {
			return new \WP_REST_Response( [ 'saved' => false ], 200 );
		}

		Library_Guard::remember_seen( $post_id, array_map( 'absint', (array) $request->get_param( 'ids' ) ) );

		return new \WP_REST_Response( [ 'saved' => true ], 200 );
	}

	/**
	 * Uloží náhled videa (první snímek zachycený v prohlížeči) a skutečné rozměry videa.
	 * Náhled se ukládá jako obyčejný soubor (ne jako příloha), aby se nepletl do knihovny médií.
	 */
	public function handle_video_poster( \WP_REST_Request $request ) {
		$id = absint( $request->get_param( 'attachment_id' ) );

		if ( ! $id || 'attachment' !== get_post_type( $id ) || 0 !== strpos( (string) get_post_mime_type( $id ), 'video/' ) || ! current_user_can( 'edit_post', $id ) ) {
			return new \WP_Error( 'bazantik_invalid_video', __( 'Invalid video.', 'bazantik-photo-gallery' ), [ 'status' => 404 ] );
		}

		$files = $request->get_file_params();
		$tmp   = isset( $files['poster']['tmp_name'] ) ? (string) $files['poster']['tmp_name'] : '';

		if ( '' === $tmp || ! is_uploaded_file( $tmp ) || (int) $files['poster']['size'] > 3 * MB_IN_BYTES ) {
			return new \WP_Error( 'bazantik_invalid_poster', __( 'The video preview is missing or too large.', 'bazantik-photo-gallery' ), [ 'status' => 400 ] );
		}

		// phpcs:disable WordPress.WP.AlternativeFunctions -- čtení a zápis dočasných souborů v adresáři pro dočasné soubory (náhled do 3 MB)
		$bytes = (string) file_get_contents( $tmp );
		$info  = @getimagesizefromstring( $bytes );

		if ( ! $info || 'image/jpeg' !== $info['mime'] ) {
			return new \WP_Error( 'bazantik_invalid_poster', __( 'The video preview must be a JPEG.', 'bazantik-photo-gallery' ), [ 'status' => 400 ] );
		}

		// Náhled znovu zakódujeme, aby se do uploads nikdy nedostal jiný obsah než čistý JPEG
		$temp_dir = get_temp_dir();
		$src      = $temp_dir . 'bazantik-' . bin2hex( random_bytes( 8 ) ) . '.jpg';
		$dst      = $temp_dir . 'bazantik-' . bin2hex( random_bytes( 8 ) ) . '.jpg';
		$clean    = '';
		if ( false !== file_put_contents( $src, $bytes ) ) {
			$editor = wp_get_image_editor( $src );
			if ( ! is_wp_error( $editor ) ) {
				$editor->set_quality( 85 );
				// Jiný plugin může převádět výstup JPEG na WebP/AVIF; ukládáme proto s vypnutou konverzí a čteme skutečnou cestu
				$no_conversion = static fn(): array => [];
				add_filter( 'image_editor_output_format', $no_conversion, 10001 );
				$saved = $editor->save( $dst, 'image/jpeg' );
				remove_filter( 'image_editor_output_format', $no_conversion, 10001 );
				if ( ! is_wp_error( $saved ) && ! empty( $saved['path'] ) && is_file( $saved['path'] ) ) {
					$clean = (string) file_get_contents( $saved['path'] );
					if ( $saved['path'] !== $dst ) {
						wp_delete_file( $saved['path'] );
					}
				}
			}
		}
		// phpcs:enable WordPress.WP.AlternativeFunctions
		wp_delete_file( $src );
		wp_delete_file( $dst );
		if ( '' === $clean ) {
			return new \WP_Error( 'bazantik_invalid_poster', __( 'The video preview could not be processed.', 'bazantik-photo-gallery' ), [ 'status' => 400 ] );
		}

		$upload = wp_upload_bits( bin2hex( random_bytes( 10 ) ) . '-poster.jpg', null, $clean );
		if ( ! empty( $upload['error'] ) ) {
			return new \WP_Error( 'bazantik_poster_failed', (string) $upload['error'], [ 'status' => 500 ] );
		}

		// Starý náhled (např. při opakovaném nahrání) smažeme
		Library_Guard::delete_poster( $id );

		update_post_meta( $id, '_bazantik_video_poster_url', esc_url_raw( $upload['url'] ) );
		update_post_meta( $id, '_bazantik_video_poster_path', wp_normalize_path( $upload['file'] ) );
		update_post_meta( $id, '_bazantik_video_w', min( 20000, absint( $request->get_param( 'width' ) ) ) );
		update_post_meta( $id, '_bazantik_video_h', min( 20000, absint( $request->get_param( 'height' ) ) ) );

		// Datum natočení: čas poslední změny souboru z prohlížeče (u souborů přetažených z počítače odpovídá originálu).
		// Hodnota blízká okamžiku nahrání se ignoruje (byl by to jen čas nahrání).
		$captured_sort = '';
		$last_modified = (int) floor( (float) $request->get_param( 'last_modified' ) / 1000 );

		if ( $last_modified > 946684800 && ( time() - $last_modified ) > 600 && empty( ( (array) get_post_meta( $id, '_bazantik_exif_data', true ) )['captured_sort'] ) ) {
			$exif                  = get_post_meta( $id, '_bazantik_exif_data', true );
			$exif                  = is_array( $exif ) ? $exif : [];
			$captured_sort         = wp_date( 'Y-m-d H:i:s', $last_modified ) . '.000';
			$exif['captured_at']   = wp_date( 'Y:m:d H:i', $last_modified );
			$exif['captured_sort'] = $captured_sort;
			update_post_meta( $id, '_bazantik_exif_data', $exif );
		}

		return new \WP_REST_Response(
			[
				'url'           => $upload['url'],
				'captured_sort' => $captured_sort,
			],
			200
		);
	}

	/**
	 * Spustí na pozadí dokončení zpracování (volá se při zavření editoru alba s nezpracovanými fotkami).
	 */
	public function handle_chain(): \WP_REST_Response {
		Processing_Cron::kick();

		return new \WP_REST_Response( [ 'started' => true ], 200 );
	}

	/**
	 * Uloží jen rozměry videa (a případně datum natočení z času změny souboru), když prohlížeč nezvládl zachytit náhled.
	 */
	public function handle_video_info( \WP_REST_Request $request ) {
		$id = absint( $request->get_param( 'attachment_id' ) );

		if ( ! $id || 'attachment' !== get_post_type( $id ) || 0 !== strpos( (string) get_post_mime_type( $id ), 'video/' ) || ! current_user_can( 'edit_post', $id ) ) {
			return new \WP_Error( 'bazantik_invalid_video', __( 'Invalid video.', 'bazantik-photo-gallery' ), [ 'status' => 404 ] );
		}

		$width  = min( 20000, absint( $request->get_param( 'width' ) ) );
		$height = min( 20000, absint( $request->get_param( 'height' ) ) );
		if ( $width && $height ) {
			update_post_meta( $id, '_bazantik_video_w', $width );
			update_post_meta( $id, '_bazantik_video_h', $height );
		}

		$captured_sort = '';
		$last_modified = (int) floor( (float) $request->get_param( 'last_modified' ) / 1000 );
		$exif          = get_post_meta( $id, '_bazantik_exif_data', true );
		$exif          = is_array( $exif ) ? $exif : [];

		if ( $last_modified > 946684800 && ( time() - $last_modified ) > 600 && empty( $exif['captured_sort'] ) ) {
			$captured_sort         = wp_date( 'Y-m-d H:i:s', $last_modified ) . '.000';
			$exif['captured_at']   = wp_date( 'Y:m:d H:i', $last_modified );
			$exif['captured_sort'] = $captured_sort;
			update_post_meta( $id, '_bazantik_exif_data', $exif );
		}

		return new \WP_REST_Response( [ 'captured_sort' => $captured_sort ], 200 );
	}

	/**
	 * Aktuální stav fronty pro stránku Zpracování fotek (počty a řádky tabulky nedokončených fotek).
	 */
	public function handle_queue(): \WP_REST_Response {
		return new \WP_REST_Response(
			[
				'counts' => Processing_Queue::counts_cached(),
				'rows'   => Processing_Admin::build_rows(),
			],
			200
		);
	}

	public function handle_retry(): \WP_REST_Response {
		$retried = Processing_Queue::retry_failed();
		if ( $retried > 0 ) {
			Processing_Cron::kick();
		}
		return new \WP_REST_Response( [ 'retried' => $retried ], 200 );
	}

	/**
	 * Stavy zadaných příloh + celkové počty ve frontě.
	 */
	private function snapshot( \WP_REST_Request $request ): array {
		$ids = array_map( 'absint', (array) $request->get_param( 'ids' ) );
		$ids = array_slice( array_values( array_unique( array_filter( $ids ) ) ), 0, self::MAX_IDS );

		$statuses = [];
		$errors   = [];

		if ( ! empty( $ids ) ) {
			_prime_post_caches( $ids, false, true );

			foreach ( $ids as $id ) {
				if ( 'attachment' !== get_post_type( $id ) ) {
					$statuses[ (string) $id ] = 'missing'; // příloha už neexistuje (smazaná z knihovny médií)
					continue;
				}

				if ( ! current_user_can( 'edit_post', $id ) ) {
					continue; // stav cizích příloh se nesděluje
				}

				$status                   = Processing_Status::get( $id );
				$statuses[ (string) $id ] = $status;

				if ( Processing_Status::FAILED === $status ) {
					$errors[ (string) $id ] = (string) get_post_meta( $id, Processing_Status::META_ERROR, true );
				}
			}
		}

		return [
			'counts'   => Processing_Queue::counts_cached(),
			'statuses' => (object) $statuses,
			'errors'   => (object) $errors,
		];
	}
}
