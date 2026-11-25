<?php
declare(strict_types=1);

namespace BazantikPhotoGallery;

defined( 'ABSPATH' ) || exit;

class Admin_Metaboxes {
	public function init(): void {
		add_action( 'add_meta_boxes', [ $this, 'register_metaboxes' ] );
		add_action( 'save_post_bazantik_album', [ $this, 'save_album_metadata' ], 10, 2 );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_admin_assets' ] );

		// Sloupce v přehledu alb (Soukromí a Počet fotek)
		add_filter( 'manage_bazantik_album_posts_columns', [ $this, 'add_admin_columns' ] );
		add_action( 'manage_bazantik_album_posts_custom_column', [ $this, 'render_admin_columns' ], 10, 2 );
		add_action( 'quick_edit_custom_box', [ $this, 'render_quick_edit' ], 10, 2 );
		add_action( 'save_post_bazantik_album', [ $this, 'save_quick_edit' ], 20, 2 );
	}

	public function enqueue_admin_assets( string $hook ): void {
		global $post_type;
		if ( in_array( $hook, [ 'post.php', 'post-new.php' ], true ) && 'bazantik_album' === $post_type ) {
			wp_enqueue_media();
			wp_enqueue_style( 'bazantik-admin-css', BAZANTIK_PLUGIN_URL . 'assets/css/admin-gallery.css', [], BAZANTIK_VERSION );
			wp_enqueue_script( 'jquery-ui-sortable' );
			wp_enqueue_script( 'bazantik-admin-js', BAZANTIK_PLUGIN_URL . 'assets/js/admin-gallery.js', [ 'jquery', 'jquery-ui-sortable', 'wp-api-fetch', 'wp-plupload' ], BAZANTIK_VERSION, true );
			wp_localize_script(
				'bazantik-admin-js',
				'bazantikAdmin',
				[
					'videoIcon'   => includes_url( 'images/media/video.png' ),
					'defaultIcon' => includes_url( 'images/media/default.png' ),
					'strings' => [
						'close'             => __( 'Close', 'bazantik-photo-gallery' ),
						'selectMedia'       => __( 'Select photos and videos for the album', 'bazantik-photo-gallery' ),
						'addToAlbum'        => __( 'Add to album', 'bazantik-photo-gallery' ),
						'setCover'          => __( 'Set as album cover', 'bazantik-photo-gallery' ),
						'remove'            => __( 'Remove', 'bazantik-photo-gallery' ),
						/* translators: 1: processed photos, 2: total photos */
						'procProgress'      => __( 'Processed %1$d of %2$d photos', 'bazantik-photo-gallery' ),
						/* translators: 1: processed photos, 2: total photos (exactly one) */
						'procProgressOne'   => __( 'Processed %1$d of %2$d photo', 'bazantik-photo-gallery' ),
						'procBackground'    => __( 'processing continues in the background', 'bazantik-photo-gallery' ),
						/* translators: %d: number of failed photos or files */
						'failedCount'       => __( 'failed: %d', 'bazantik-photo-gallery' ),
						'procPaused'        => __( 'Processing is paused (resume it on the Photo processing page).', 'bazantik-photo-gallery' ),
						'procConnFail'      => __( 'Connection to the server failed. Try reloading the page.', 'bazantik-photo-gallery' ),
						/* translators: 1: uploaded files, 2: total files, 3: elapsed time (m:ss) */
						'uploadProgress'    => __( 'Uploaded %1$d of %2$d files (%3$s)', 'bazantik-photo-gallery' ),
						'uploadStay'        => __( 'do not leave the page until the upload finishes', 'bazantik-photo-gallery' ),
						'uploadSaveBlocked' => __( 'SAVING WILL BE POSSIBLE AFTER THE UPLOAD FINISHES', 'bazantik-photo-gallery' ),
						/* translators: %s: file name */
						'badFileType'       => __( '%s: this file type cannot be added to the album (only photos and videos).', 'bazantik-photo-gallery' ),
						'fileFallback'      => __( 'File', 'bazantik-photo-gallery' ),
						'photosAndVideos'   => __( 'Photos and videos', 'bazantik-photo-gallery' ),
						'uploadFailed'      => __( 'upload failed', 'bazantik-photo-gallery' ),
						'imageError'        => __( 'The server cannot process the image. This can happen if the server is busy or does not have enough resources to complete the task. If the error is not caused by an internet connection outage, uploading a smaller image may help.', 'bazantik-photo-gallery' ),
						/* translators: %d: number of files */
						'retryUploads'      => __( 'Retry uploading the failed files (%d)', 'bazantik-photo-gallery' ),
					],
				]
			);
		}
		if ( 'edit.php' === $hook && 'bazantik_album' === $post_type ) {
			wp_enqueue_style( 'bazantik-admin-folders', BAZANTIK_PLUGIN_URL . 'assets/css/admin-folders.css', [ 'dashicons' ], BAZANTIK_VERSION );
			wp_enqueue_script( 'bazantik-admin-album-list', BAZANTIK_PLUGIN_URL . 'assets/js/admin-album-list.js', [ 'jquery', 'inline-edit-post' ], BAZANTIK_VERSION, true );
			wp_localize_script(
				'bazantik-admin-album-list',
				'bazantikAlbumList',
				[
					'pwSet'   => __( 'A password is set', 'bazantik-photo-gallery' ),
					'pwEnter' => __( 'Enter a password', 'bazantik-photo-gallery' ),
					'pwRequired' => __( 'A password-protected album must have a password. Enter a password or choose a different privacy setting.', 'bazantik-photo-gallery' ),
				]
			);
		}
	}

	public function register_metaboxes(): void {
		add_meta_box(
			'bazantik_album_media',
			__( 'Photos and videos in the album', 'bazantik-photo-gallery' ),
			[ $this, 'render_media_metabox' ],
			'bazantik_album',
			'normal',
			'high'
		);

		add_meta_box(
			'bazantik_album_settings',
			__( 'Display and privacy settings', 'bazantik-photo-gallery' ),
			[ $this, 'render_settings_metabox' ],
			'bazantik_album',
			'side',
			'core' // stejná priorita jako Publish a Složky: hned za nimi, před boxy šablon a pluginů
		);
	}

	public function render_media_metabox( \WP_Post $post ): void {
		wp_nonce_field( 'bazantik_save_album', 'bazantik_album_nonce' );
		$media_ids = get_post_meta( $post->ID, '_bazantik_media_ids', true );
		$cover_id  = (int) get_post_meta( $post->ID, '_bazantik_cover_id', true );
		$ids_array = ! empty( $media_ids ) ? array_filter( array_map( 'absint', explode( ',', $media_ids ) ) ) : [];

		// Fotky smazané mezitím z knihovny médií v seznamu alba nenecháváme (jinak by zůstaly prázdné rámečky)
		_prime_post_caches( $ids_array, false, true );
		$ids_array = array_values(
			array_filter(
				$ids_array,
				static fn( $id ): bool => 'attachment' === get_post_type( $id )
			)
		);
		if ( $cover_id && ! in_array( $cover_id, $ids_array, true ) ) {
			$cover_id = 0;
		}

		if ( ! $cover_id && ! empty( $ids_array ) ) {
			$cover_id = $ids_array[0];
		}
		?>
		<div id="bazantik-media-manager">
		<input type="hidden" name="bazantik_media_ids" id="bazantik_media_ids" value="<?php echo esc_attr( implode( ',', $ids_array ) ); ?>">
		<input type="hidden" name="bazantik_cover_id" id="bazantik_cover_id" value="<?php echo esc_attr( (string) $cover_id ); ?>">
		<div class="bazantik-actions" style="margin-bottom:12px;">
		<p class="bazantik-admin-tip"><strong><?php esc_html_e( 'To get the most out of Bazantik Photo Gallery, upload photos using this field:', 'bazantik-photo-gallery' ); ?></strong></p>
		</div>
		<?php
		// Fotky nahrané do tohoto alba v posledních hodinách, které v seznamu chybí (např. zavřený panel během nahrávání)
		$bazantik_missing = get_posts(
			[
				'post_type'      => 'attachment',
				'post_status'    => 'inherit',
				'post_parent'    => $post->ID,
				'post_mime_type' => [ 'image', 'video' ],
				'post__not_in'   => array_values( array_unique( array_merge( $ids_array, Library_Guard::seen_ids( $post->ID ) ) ) ),
				'date_query'     => [
					[
						'column' => 'post_date_gmt',
						'after'  => '6 hours ago',
					],
				],
				'orderby'        => 'ID',
				'order'          => 'ASC',
				'posts_per_page' => 500,
			]
		);

		$bazantik_missing_data = [];
		if ( ! empty( $bazantik_missing ) ) {
			_prime_post_caches( wp_list_pluck( $bazantik_missing, 'ID' ), false, true );

			foreach ( $bazantik_missing as $missing_post ) {
				$m_id    = (int) $missing_post->ID;
				$m_exif  = get_post_meta( $m_id, '_bazantik_exif_data', true );
				$m_meta  = wp_get_attachment_metadata( $m_id );
				$m_thumb = ! empty( $m_meta['sizes']['thumbnail'] ) ? wp_get_attachment_image_src( $m_id, 'thumbnail' ) : false;

				$bazantik_missing_data[] = [
					'id'         => $m_id,
					'attributes' => [
						'type'        => str_starts_with( (string) $missing_post->post_mime_type, 'video/' ) ? 'video' : 'image',
						'url'         => (string) wp_get_attachment_url( $m_id ),
						'sizes'       => ! empty( $m_thumb[0] ) ? [ 'thumbnail' => [ 'url' => (string) $m_thumb[0] ] ] : [],
						'title'       => (string) $missing_post->post_title,
						'bazantikStatus'   => Processing_Status::get( $m_id ),
						'bazantikCaptured' => is_array( $m_exif ) ? (string) ( $m_exif['captured_sort'] ?? '' ) : '',
					],
				];
			}
		}
		?>
				<?php if ( ! empty( $bazantik_missing_data ) ) : ?>
				<div class="notice notice-warning inline" id="bazantik-missing-notice" data-missing="<?php echo esc_attr( (string) wp_json_encode( $bazantik_missing_data ) ); ?>">
				<p>
					<?php echo esc_html( sprintf( /* translators: %d: number of photos */ _n( 'Photo uploaded to this album that is not part of it: %d.', 'Photos uploaded to this album that are not part of it: %d.', count( $bazantik_missing_data ), 'bazantik-photo-gallery' ), count( $bazantik_missing_data ) ) ); ?>
				<button type="button" class="button button-small" id="bazantik-missing-add"><?php esc_html_e( 'Add to album', 'bazantik-photo-gallery' ); ?></button>
				<button type="button" class="button button-small" id="bazantik-missing-dismiss"><?php esc_html_e( 'Close', 'bazantik-photo-gallery' ); ?></button>
				</p>
				</div>
				<?php endif; ?>
				<div id="bazantik-dropzone" class="bazantik-dropzone">
				<div class="bazantik-dropzone-text">
				<span class="dashicons dashicons-upload"></span>
				<?php esc_html_e( 'Drag photos and videos here, or', 'bazantik-photo-gallery' ); ?>
				<button type="button" class="button" id="bazantik-upload-browse"><?php esc_html_e( 'choose files from your device', 'bazantik-photo-gallery' ); ?></button>
				</div>
				<div class="bazantik-upload-status" id="bazantik-upload-status" style="display:none;">
				<div class="bazantik-processing-bar"><span id="bazantik-upload-bar-fill"></span></div>
				<div class="bazantik-processing-text"><span id="bazantik-upload-text"></span></div>
				</div>
				<button type="button" class="button-link bazantik-upload-errors-clear" id="bazantik-upload-errors-clear" style="display:none;"><?php esc_html_e( 'Close all notices', 'bazantik-photo-gallery' ); ?></button>
				<button type="button" class="button" id="bazantik-upload-retry" style="display:none;"></button>
				<ul class="bazantik-upload-errors" id="bazantik-upload-errors"></ul>
				</div>
				<div id="bazantik-processing-panel" class="bazantik-processing-panel" style="display:none;">
				<div class="bazantik-processing-bar"><span id="bazantik-processing-bar-fill"></span></div>
				<div class="bazantik-processing-text">
				<span id="bazantik-processing-text"></span>
				<button type="button" class="button button-small" id="bazantik-processing-retry" style="display:none;"><?php esc_html_e( 'Retry failed', 'bazantik-photo-gallery' ); ?></button>
				</div>
				</div>
				<div class="bazantik-sort-toolbar">
				<label for="bazantik-sort-mode"><?php esc_html_e( 'Sort photos:', 'bazantik-photo-gallery' ); ?></label>
				<select id="bazantik-sort-mode">
				<option value=""><?php esc_html_e( '— choose a sort order —', 'bazantik-photo-gallery' ); ?></option>
				<option value="captured-asc"><?php esc_html_e( 'By capture date (oldest first)', 'bazantik-photo-gallery' ); ?></option>
				<option value="captured-desc"><?php esc_html_e( 'By capture date (newest first)', 'bazantik-photo-gallery' ); ?></option>
				<option value="title-asc"><?php esc_html_e( 'By name (A → Z)', 'bazantik-photo-gallery' ); ?></option>
				<option value="title-desc"><?php esc_html_e( 'By name (Z → A)', 'bazantik-photo-gallery' ); ?></option>
				<option value="uploaded-asc"><?php esc_html_e( 'By upload order (oldest first)', 'bazantik-photo-gallery' ); ?></option>
				<option value="uploaded-desc"><?php esc_html_e( 'By upload order (newest first)', 'bazantik-photo-gallery' ); ?></option>
				<option value="reverse"><?php esc_html_e( 'Reverse order', 'bazantik-photo-gallery' ); ?></option>
				<option value="shuffle"><?php esc_html_e( 'Random', 'bazantik-photo-gallery' ); ?></option>
				</select>
				<p class="bazantik-admin-tip"><?php esc_html_e( 'Click the star to choose the album\'s cover photo. If you don\'t choose one, the first photo in the album is used.', 'bazantik-photo-gallery' ); ?></p>
				</div>
				<ul id="bazantik-media-list" class="bazantik-grid-sortable" style="<?php echo esc_attr( '--bazantik-cover-label: "' . addcslashes( __( 'Cover photo', 'bazantik-photo-gallery' ), '"\\' ) . '"' ); ?>">
				<?php
				_prime_post_caches( $ids_array, false, true );
				foreach ( $ids_array as $attachment_id ) :
					$mime = get_post_mime_type( $attachment_id );
					$is_video = str_starts_with( (string) $mime, 'video/' );
					$thumb_url = $is_video ? ( (string) get_post_meta( $attachment_id, '_bazantik_video_poster_url', true ) ?: includes_url( 'images/media/video.png' ) ) : wp_get_attachment_image_url( $attachment_id, 'thumbnail' );
					$is_cover   = ( $attachment_id === $cover_id );
					$bazantik_status = Processing_Status::get( (int) $attachment_id );
					$bazantik_exif   = get_post_meta( $attachment_id, '_bazantik_exif_data', true );
					$bazantik_sort   = is_array( $bazantik_exif ) ? (string) ( $bazantik_exif['captured_sort'] ?? '' ) : '';
					?>
				<li class="bazantik-media-item<?php echo $is_cover ? ' is-cover' : ''; ?><?php echo 'ready' !== $bazantik_status ? ' is-' . esc_attr( $bazantik_status ) : ''; ?>" data-id="<?php echo esc_attr( (string) $attachment_id ); ?>" data-status="<?php echo esc_attr( $bazantik_status ); ?>" data-title="<?php echo esc_attr( get_post_field( 'post_title', $attachment_id ) ); ?>" data-captured="<?php echo esc_attr( $bazantik_sort ); ?>">
				<div class="bazantik-media-preview">
				<img src="<?php echo esc_url( $thumb_url ?: '' ); ?>" alt="">
					<?php if ( $is_video ) : ?>
				<span class="dashicons dashicons-video-alt3 bazantik-badge-video"></span>
				<?php endif; ?>
				</div>
				<button type="button" class="bazantik-cover-btn dashicons dashicons-star-filled" title="<?php esc_attr_e( 'Set as album cover', 'bazantik-photo-gallery' ); ?>"></button>
				<button type="button" class="bazantik-remove-item dashicons dashicons-no-alt" title="<?php esc_attr_e( 'Remove', 'bazantik-photo-gallery' ); ?>"></button>
				</li>
				<?php endforeach; ?>
				</ul>
				<div class="bazantik-library-action">
			<p class="bazantik-admin-tip"><strong><?php esc_html_e( 'Already have photos on your site that were uploaded by this plugin?', 'bazantik-photo-gallery' ); ?></strong> <?php esc_html_e( 'Add them from the media library. For new photos, the upload field above works best.', 'bazantik-photo-gallery' ); ?></p>
			<button type="button" class="button" id="bazantik-add-media-btn">
			<span class="dashicons dashicons-images-alt2" style="vertical-align: middle;"></span>
			<?php esc_html_e( 'Add photos and videos from the media library', 'bazantik-photo-gallery' ); ?>
			</button>
				</div>
				</div>
				<?php
	}

	public function render_settings_metabox( \WP_Post $post ): void {
		$visibility = get_post_meta( $post->ID, '_bazantik_visibility', true ) ?: 'public';
		$token      = get_post_meta( $post->ID, '_bazantik_secret_token', true );
		$show_exif  = get_post_meta( $post->ID, '_bazantik_show_exif', true ) !== '0';
		$map_choice = Settings::album_map_choice( $post->ID );
		$map_mode   = Settings::map_mode();

		if ( empty( $token ) ) {
			$token = wp_generate_password( 24, false );
			update_post_meta( $post->ID, '_bazantik_secret_token', $token );
		}

		$unlisted_url = add_query_arg( 'key', $token, get_permalink( $post->ID ) );
		?>
					<div class="bazantik-settings-panel">
					<div class="bazantik-embed-box">
					<p><strong><?php esc_html_e( 'Embed the album in a page:', 'bazantik-photo-gallery' ); ?></strong></p>
					<p><?php esc_html_e( 'Album ID:', 'bazantik-photo-gallery' ); ?> <code><?php echo esc_html( (string) $post->ID ); ?></code></p>
					<input type="text" readonly class="bazantik-embed-shortcode" value="<?php echo esc_attr( '[bazantik_album id="' . $post->ID . '"]' ); ?>" data-bazantik-select>
					<p class="description"><?php esc_html_e( 'Click the field and copy the shortcode (Cmd/Ctrl + C). In the block editor, use the “Bazantik Photo Gallery album” block instead.', 'bazantik-photo-gallery' ); ?></p>
					</div>
					<p>
					<label><strong><?php esc_html_e( 'Visibility:', 'bazantik-photo-gallery' ); ?></strong></label><br>
					<select name="bazantik_visibility" id="bazantik_visibility" style="width:100%; margin-top:5px;">
					<option value="public" <?php selected( $visibility, 'public' ); ?>><?php esc_html_e( 'Public', 'bazantik-photo-gallery' ); ?></option>
					<option value="unlisted" <?php selected( $visibility, 'unlisted' ); ?>><?php esc_html_e( 'Hidden (link only)', 'bazantik-photo-gallery' ); ?></option>
					<option value="password" <?php selected( $visibility, 'password' ); ?>><?php esc_html_e( 'Password protected', 'bazantik-photo-gallery' ); ?></option>
					</select>
					</p>
					
					<div id="bazantik-password-box" style="display: <?php echo 'password' === $visibility ? 'block' : 'none'; ?>; background:#f9f9f9; padding:10px; border:1px solid #ccd0d4; border-radius:4px; margin-bottom:12px;">
					<label for="bazantik_password"><strong><?php esc_html_e( 'Album password:', 'bazantik-photo-gallery' ); ?></strong></label>
					<?php
					$has_password = '' !== (string) $post->post_password;
					$password_field = Password_Field::input(
						'bazantik_password',
						[
							'id'          => 'bazantik_password',
							'placeholder' => $has_password ? __( 'A password is set', 'bazantik-photo-gallery' ) : __( 'Enter a password', 'bazantik-photo-gallery' ),
							'block'       => true,
							'reveal'      => $has_password ? [ 'type' => 'album', 'id' => (int) $post->ID ] : null,
						]
					);
					echo $password_field; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escapováno uvnitř Password_Field::input()
					?>
					</div>
					
					<div id="bazantik-unlisted-box" style="display: <?php echo 'unlisted' === $visibility ? 'block' : 'none'; ?>; background:#f9f9f9; padding:8px; border:1px solid #ccd0d4; border-radius:4px; margin-bottom:12px;">
					<label><strong><?php esc_html_e( 'Link to the hidden album:', 'bazantik-photo-gallery' ); ?></strong></label>
					<input type="text" readonly value="<?php echo esc_url( $unlisted_url ); ?>" style="width:100%; font-size:11px; margin-top:4px;" data-bazantik-select>
					</div>
					
					<p>
					<label>
					<input type="checkbox" name="bazantik_show_exif" value="1" <?php checked( $show_exif ); ?>>
					<?php esc_html_e( 'Show EXIF data', 'bazantik-photo-gallery' ); ?>
					</label>
					</p>
					
					<p>
					<label for="bazantik_map_mode"><?php esc_html_e( 'GPS map (Leaflet)', 'bazantik-photo-gallery' ); ?></label><br>
					<select name="bazantik_map_mode" id="bazantik_map_mode" <?php disabled( 'off' === $map_mode ); ?>>
					<option value="inherit" <?php selected( $map_choice, 'inherit' ); ?>>
					<?php echo esc_html( 'on' === $map_mode ? __( 'Use plugin setting (currently on)', 'bazantik-photo-gallery' ) : __( 'Use plugin setting (currently off)', 'bazantik-photo-gallery' ) ); ?>
					</option>
					<option value="on" <?php selected( $map_choice, 'on' ); ?>><?php esc_html_e( 'Turn on for this album', 'bazantik-photo-gallery' ); ?></option>
					<option value="off" <?php selected( $map_choice, 'off' ); ?>><?php esc_html_e( 'Turn off for this album', 'bazantik-photo-gallery' ); ?></option>
					</select>
					<br><span class="description"><?php esc_html_e( 'The map background is loaded from the map provider (OpenStreetMap by default, can be changed in the plugin settings) only after the visitor clicks “Show map” (the provider receives the visitor\'s IP address). With the map off, GPS coordinates are not printed into the page at all.', 'bazantik-photo-gallery' ); ?></span>
					<?php if ( 'off' === $map_mode ) : ?>
					<br><span class="description"><?php esc_html_e( 'Maps are turned off completely in the plugin settings, so this album will not show one.', 'bazantik-photo-gallery' ); ?></span>
					<?php endif; ?>
					</p>
					<p>
					<label for="bazantik_mobile_zoom"><?php esc_html_e( 'Zoom on mobile', 'bazantik-photo-gallery' ); ?></label><br>
					<select name="bazantik_mobile_zoom" id="bazantik_mobile_zoom" <?php disabled( ! Settings::zoom_per_album() ); ?>>
					<option value="inherit" <?php selected( Settings::album_zoom_choice( $post->ID ), 'inherit' ); ?>>
					<?php echo esc_html( 'original' === Settings::mobile_zoom_default() ? __( 'Use plugin setting (currently original)', 'bazantik-photo-gallery' ) : __( 'Use plugin setting (currently lightbox version)', 'bazantik-photo-gallery' ) ); ?>
					</option>
					<option value="4k" <?php selected( Settings::album_zoom_choice( $post->ID ), '4k' ); ?>><?php esc_html_e( 'Lightbox version (saves data)', 'bazantik-photo-gallery' ); ?></option>
					<option value="original" <?php selected( Settings::album_zoom_choice( $post->ID ), 'original' ); ?>><?php esc_html_e( 'Full original (sharpest)', 'bazantik-photo-gallery' ); ?></option>
					</select>
					<br><span class="description">
					<?php
					echo esc_html( Settings::zoom_per_album()
						? __( 'Which variant (quality) a phone loads when a photo is zoomed in. Visitors can change it themselves in the lightbox settings.', 'bazantik-photo-gallery' )
					: __( 'The general plugin setting applies. To be able to change it here, first enable the choice in the plugin settings.', 'bazantik-photo-gallery' ) );
					?>
					</span>
					</p>
					</div>
					<?php
	}

	public function save_album_metadata( int $post_id, \WP_Post $post ): void {
		if ( ! isset( $_POST['bazantik_album_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['bazantik_album_nonce'] ) ), 'bazantik_save_album' ) ) {
			return;
		}

		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		if ( isset( $_POST['bazantik_media_ids'] ) ) {
			$raw_ids   = explode( ',', sanitize_text_field( wp_unslash( $_POST['bazantik_media_ids'] ) ) );
			$clean_ids = Library_Guard::sanitize_media_ids( $post_id, $raw_ids );
			update_post_meta( $post_id, '_bazantik_media_ids', implode( ',', $clean_ids ) );
			Library_Guard::remember_seen( $post_id, $clean_ids );
			Album_Stats::get( $post_id );
		}

		if ( isset( $_POST['bazantik_cover_id'] ) ) {
			$cover_id   = absint( $_POST['bazantik_cover_id'] );
			$album_ids  = array_map( 'absint', explode( ',', (string) get_post_meta( $post_id, '_bazantik_media_ids', true ) ) );
			update_post_meta( $post_id, '_bazantik_cover_id', in_array( $cover_id, $album_ids, true ) ? $cover_id : 0 );
		}

		// Soukromí se mění jen s platnou hodnotou z formuláře (jinak by chybějící pole omylem smazalo heslo)
		$visibility = sanitize_text_field( wp_unslash( $_POST['bazantik_visibility'] ?? '' ) );
		if ( in_array( $visibility, [ 'public', 'unlisted', 'password' ], true ) ) {
			$existing = (string) get_post_field( 'post_password', $post_id, 'raw' );
			$password = isset( $_POST['bazantik_password'] ) && is_string( $_POST['bazantik_password'] ) ? trim( wp_unslash( $_POST['bazantik_password'] ) ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- heslo se nesmí upravovat

			// Pole se při načtení stránky nevyplňuje: bez úpravy uživatelem (příznak „changed“) zůstává dosavadní heslo.
			// Neprázdná hodnota je vždy úprava (bez JavaScriptu by se příznak nepřepnul a nové heslo by se tiše zahodilo).
			if ( '' === $password && isset( $_POST['bazantik_password_changed'] ) && '1' !== sanitize_text_field( wp_unslash( $_POST['bazantik_password_changed'] ) ) ) {
				$password = $existing;
				// Heslo z nativního pole v boxu Publish (jádro ho před uložením vrací; hodnota se porovná s dosavadním heslem)
				if ( isset( $_POST['post_password'] ) && is_string( $_POST['post_password'] ) && in_array( $visibility, [ 'public', 'password' ], true ) ) {
					$native_pw = trim( wp_unslash( $_POST['post_password'] ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- heslo se nesmí upravovat
					if ( '' !== $native_pw && $native_pw !== $existing ) {
						$visibility = 'password';
						$password   = $native_pw;
					} elseif ( '' === $native_pw && '' !== $existing && 'password' === $visibility ) {
						$visibility = 'public';
					}
				}
			}
			// Sloupec post_password má 255 znaků; delší heslo by se neuložilo a album by zůstalo bez ochrany
			if ( Password_Field::too_long( $password ) ) {
				$password = $existing;
			}
			if ( 'password' === $visibility && '' === $password ) {
				$visibility = 'unlisted';
			}

			global $wpdb;
			$saved = $wpdb->update( $wpdb->posts, [ 'post_password' => 'password' === $visibility ? $password : '' ], [ 'ID' => $post_id ] );
			if ( false === $saved && 'password' === $visibility ) {
				$visibility = 'unlisted';
			}
			clean_post_cache( $post_id );

			update_post_meta( $post_id, '_bazantik_visibility', $visibility );
			Library_Guard::flush_private_media_cache();
		}

		// Tajný token se generuje jen na serveru, z formuláře se nepřijímá
		if ( empty( get_post_meta( $post_id, '_bazantik_secret_token', true ) ) ) {
			update_post_meta( $post_id, '_bazantik_secret_token', wp_generate_password( 24, false ) );
		}

		update_post_meta( $post_id, '_bazantik_show_exif', isset( $_POST['bazantik_show_exif'] ) ? '1' : '0' );
		if ( isset( $_POST['bazantik_map_mode'] ) ) {
			$map_choice = sanitize_key( wp_unslash( $_POST['bazantik_map_mode'] ) );
			update_post_meta( $post_id, '_bazantik_map_mode', in_array( $map_choice, [ 'inherit', 'on', 'off' ], true ) ? $map_choice : 'inherit' );
		}
		if ( isset( $_POST['bazantik_mobile_zoom'] ) ) {
			$zoom_choice = sanitize_key( wp_unslash( $_POST['bazantik_mobile_zoom'] ) );
			update_post_meta( $post_id, Settings::META_ZOOM, in_array( $zoom_choice, [ '4k', 'original' ], true ) ? $zoom_choice : 'inherit' );
		}
	}

				/**
				 * Rychlá úprava alba: soukromí, odkaz a heslo (nativní pole Password a Private se skriptem skrývají).
				 */
	public function render_quick_edit( string $column, string $post_type ): void {
		if ( 'bazantik_visibility' !== $column || 'bazantik_album' !== $post_type ) {
			return;
		}
		echo '<fieldset class="inline-edit-col-left"><div class="inline-edit-col">';
		echo '<input type="hidden" name="bazantik_qe_nonce" value="' . esc_attr( wp_create_nonce( 'bazantik_album_qe' ) ) . '">';
		echo '<label><span class="title">' . esc_html__( 'Privacy', 'bazantik-photo-gallery' ) . '</span><span class="input-text-wrap">'
			. '<select name="bazantik_qe_visibility" class="bazantik-qe-vis">'
			. '<option value="public">' . esc_html__( 'Public', 'bazantik-photo-gallery' ) . '</option>'
			. '<option value="unlisted">' . esc_html__( 'Hidden (link only)', 'bazantik-photo-gallery' ) . '</option>'
			. '<option value="password">' . esc_html__( 'Password protected', 'bazantik-photo-gallery' ) . '</option>'
			. '</select></span></label>';
		echo '<label class="bazantik-fld-unlisted"><span class="title">' . esc_html__( 'Link', 'bazantik-photo-gallery' ) . '</span><span class="input-text-wrap">'
			. '<input type="text" readonly class="bazantik-select-on-click bazantik-qe-link" placeholder="' . esc_attr__( 'Created after the album is saved', 'bazantik-photo-gallery' ) . '"></span></label>';
		echo '<label class="bazantik-fld-password"><span class="title">' . esc_html__( 'Password', 'bazantik-photo-gallery' ) . '</span><span class="input-text-wrap">'
			. Password_Field::input( 'bazantik_qe_password', [ 'class' => 'bazantik-qe-pw', 'placeholder' => __( 'Enter a password', 'bazantik-photo-gallery' ), 'reveal' => [ 'type' => 'album', 'id' => 0 ] ] ) . '</span></label>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escapováno uvnitř
		echo '</div></fieldset>';
	}

				/**
				 * Uložení z Quick Editu: mění jen soukromí a heslo (ostatní nastavení alba zůstává).
				 */
	public function save_quick_edit( int $post_id, \WP_Post $post ): void {
		if ( ! isset( $_POST['bazantik_qe_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['bazantik_qe_nonce'] ) ), 'bazantik_album_qe' ) ) {
			return;
		}
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}
		$visibility = sanitize_key( wp_unslash( $_POST['bazantik_qe_visibility'] ?? '' ) );
		if ( ! in_array( $visibility, [ 'public', 'unlisted', 'password' ], true ) ) {
			return;
		}
		$password = isset( $_POST['bazantik_qe_password'] ) && is_string( $_POST['bazantik_qe_password'] ) ? trim( wp_unslash( $_POST['bazantik_qe_password'] ) ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- heslo se nesmí upravovat
		// Pole se nevyplňuje: bez úpravy uživatelem zůstává dosavadní heslo, vymazané pole heslo zruší (album se změní na skryté)
		$flagged  = isset( $_POST['bazantik_qe_password_changed'] ) && '1' === sanitize_text_field( wp_unslash( $_POST['bazantik_qe_password_changed'] ) );
		$existing = (string) get_post_field( 'post_password', $post_id, 'raw' );
		if ( ! $flagged && '' === $password ) {
			$password = $existing;
		}
		if ( Password_Field::too_long( $password ) ) {
			$password = $existing;
		}
		if ( 'password' === $visibility && '' === $password ) {
			$visibility = 'unlisted';
		}

		global $wpdb;
		$saved = $wpdb->update( $wpdb->posts, [ 'post_password' => 'password' === $visibility ? $password : '' ], [ 'ID' => $post_id ] );
		if ( false === $saved && 'password' === $visibility ) {
			$visibility = 'unlisted';
		}
		clean_post_cache( $post_id );
		update_post_meta( $post_id, '_bazantik_visibility', $visibility );
		Library_Guard::flush_private_media_cache();
		if ( empty( get_post_meta( $post_id, '_bazantik_secret_token', true ) ) ) {
			update_post_meta( $post_id, '_bazantik_secret_token', wp_generate_password( 24, false ) );
		}
	}

	public function add_admin_columns( array $columns ): array {
		$new_columns = [];
		foreach ( $columns as $key => $title ) {
			$new_columns[ $key ] = $title;
			if ( 'cb' === $key ) {
				$new_columns['bazantik_cover'] = __( 'Cover', 'bazantik-photo-gallery' );
			}
			if ( 'title' === $key ) {
				$new_columns['bazantik_visibility'] = __( 'Privacy', 'bazantik-photo-gallery' );
				$new_columns['bazantik_count']      = __( 'Photo count', 'bazantik-photo-gallery' );
				$new_columns['bazantik_shortcode']  = __( 'Shortcode', 'bazantik-photo-gallery' );
			}
		}
		return $new_columns;
	}

	public function render_admin_columns( string $column, int $post_id ): void {
		if ( 'bazantik_visibility' === $column ) {
			$vis = get_post_meta( $post_id, '_bazantik_visibility', true ) ?: 'public';
			$labels = [
				'public'   => '🟢 ' . __( 'Public', 'bazantik-photo-gallery' ),
				'unlisted' => '🔗 ' . __( 'Hidden (link)', 'bazantik-photo-gallery' ),
				'password' => '🔒 ' . __( 'Password protected', 'bazantik-photo-gallery' ),
			];
			$token = (string) get_post_meta( $post_id, '_bazantik_secret_token', true );
			$link  = '' !== $token ? add_query_arg( 'key', $token, (string) get_permalink( $post_id ) ) : '';
			echo '<span class="bazantik-vis" data-vis="' . esc_attr( (string) $vis ) . '" data-link="' . esc_attr( $link ) . '">' . esc_html( $labels[ $vis ] ?? $vis ) . '</span>';
		} elseif ( 'bazantik_cover' === $column ) {
			$cover_id = (int) get_post_meta( $post_id, '_bazantik_cover_id', true );
			if ( ! $cover_id ) {
				$cover_ids = (string) get_post_meta( $post_id, '_bazantik_media_ids', true );
				$cover_id  = '' !== $cover_ids ? (int) explode( ',', $cover_ids )[0] : 0;
			}
			// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- HTML escapuje jádro WordPressu a Library_Guard::cover_html()
			echo $cover_id && 'attachment' === get_post_type( $cover_id )
			? Library_Guard::cover_html( $cover_id, 'thumbnail', [ 'style' => 'width:64px;height:64px;object-fit:cover;border-radius:4px;' ] )
			: '—';
			// phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped
		} elseif ( 'bazantik_shortcode' === $column ) {
					echo '<code>' . esc_html( '[bazantik_album id="' . $post_id . '"]' ) . '</code>';
		} elseif ( 'bazantik_count' === $column ) {
						// Počítají se jen existující přílohy (fotky smazané z knihovny médií se z alba neodečítají hned)
						$ids   = array_filter( array_map( 'absint', explode( ',', (string) get_post_meta( $post_id, '_bazantik_media_ids', true ) ) ) );
						$count = 0;
						if ( ! empty( $ids ) ) {
							_prime_post_caches( $ids, false, false );
							foreach ( $ids as $id ) {
								if ( 'attachment' === get_post_type( $id ) ) {
									++$count;
								}
							}
						}
						echo esc_html( (string) $count );
		}
	}
}
