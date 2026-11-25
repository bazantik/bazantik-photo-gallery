<?php
declare(strict_types=1);

namespace BazantikPhotoGallery;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.Security.NonceVerification -- adresové parametry jen zobrazují výsledek akce a nic neukládají; akce ověřují nonce v authorize()

/**
 * Administrační stránka „Zpracování fotek“ (přehled stavů + ruční spuštění dávky).
 */
class Processing_Admin {
	public function init(): void {
		add_action( 'admin_menu', [ $this, 'register_menu' ] );
		add_action( 'admin_post_bazantik_run_batch', [ $this, 'handle_run_batch' ] );
		add_action( 'admin_post_bazantik_retry_failed', [ $this, 'handle_retry_failed' ] );
		add_action( 'admin_post_bazantik_verify_library', [ $this, 'handle_verify_library' ] );
		add_action( 'admin_post_bazantik_delete_failed', [ $this, 'handle_delete_failed' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_queue_script' ] );
		add_action( 'admin_post_bazantik_toggle_pause', [ $this, 'handle_toggle_pause' ] );
	}

	public function register_menu(): void {
		add_submenu_page(
			'edit.php?post_type=bazantik_album',
			__( 'Background photo processing', 'bazantik-photo-gallery' ),
			__( 'Photo processing', 'bazantik-photo-gallery' ),
			'edit_others_posts',
			'bazantik-processing',
			[ $this, 'render_page' ]
		);
	}

	public function handle_run_batch(): void {
		$this->authorize( 'bazantik_run_batch' );

		$result = Processing_Queue::run_locked_batch( Settings::perf()['manual'] );
		$this->redirect(
			[
				'bazantik_done'   => $result['done'],
				'bazantik_failed' => $result['failed'],
				'bazantik_busy'   => ! empty( $result['busy'] ) ? 1 : 0,
			]
		);
	}

	public function handle_retry_failed(): void {
		$this->authorize( 'bazantik_retry_failed' );

		$count = Processing_Queue::retry_failed();
		if ( $count > 0 ) {
			Processing_Cron::kick();
		}
		$this->redirect( [ 'bazantik_retried' => $count ] );
	}

	public function handle_toggle_pause(): void {
		$this->authorize( 'bazantik_toggle_pause' );

		Processing_Status::set_paused( ! Processing_Status::is_paused() );
		$this->redirect( [] );
	}

	/**
	 * Trvale smaže neúspěšnou fotku (nebo všechny neúspěšné) včetně všech souborů. Smazat jde jen fotka ve stavu „Selhalo“.
	 */
	public function handle_delete_failed(): void {
		$this->authorize( 'bazantik_delete_failed' );

		$single = isset( $_POST['attachment_id'] ) ? absint( $_POST['attachment_id'] ) : 0;
		$ids    = $single
		? [ $single ]
		: get_posts(
			[
				'post_type'      => 'attachment',
				'post_status'    => 'inherit',
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'meta_key'       => Processing_Status::META_STATUS,
				'meta_value'     => Processing_Status::FAILED,
			]
		);

		$deleted = 0;
		$kept    = 0;
		foreach ( $ids as $id ) {
			$id = (int) $id;

			// Hromadné mazání se týká jen fotek, které nejdou obnovit; ostatní (chybí jen zmenšenina) zůstanou k vrácení do fronty
			if ( ! $single && ! Library_Guard::is_unrecoverable( $id ) ) {
				++$kept;
				continue;
			}

			if (
				'attachment' === get_post_type( $id )
				&& Processing_Status::FAILED === Processing_Status::get( $id )
				&& current_user_can( 'delete_post', $id )
				&& wp_delete_attachment( $id, true )
				) {
					++$deleted;
			}
		}

		$this->redirect( [ 'bazantik_deleted' => $deleted, 'bazantik_kept' => $kept ] );
	}

	/**
	 * Průběžná aktualizace stránky Zpracování fotek (lehký dotaz jen při viditelné záložce).
	 */
	public function enqueue_queue_script(): void {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- jen rozpoznání stránky
		if ( ! isset( $_GET['page'] ) || 'bazantik-processing' !== sanitize_key( wp_unslash( $_GET['page'] ) ) ) {
			return;
		}

		wp_enqueue_script( 'bazantik-processing-admin', BAZANTIK_PLUGIN_URL . 'assets/js/processing-admin.js', [ 'wp-api-fetch' ], BAZANTIK_VERSION, true );
	}

	/**
	 * Řádky tabulky nedokončených fotek (pro průběžnou aktualizaci přes REST).
	 */
	public static function build_rows(): string {
		$labels = [
			Processing_Status::PENDING    => __( '⏳ Pending', 'bazantik-photo-gallery' ),
			Processing_Status::PROCESSING => __( '⚙️ Processing', 'bazantik-photo-gallery' ),
			Processing_Status::READY      => __( '✅ Ready', 'bazantik-photo-gallery' ),
			Processing_Status::FAILED     => __( '❌ Failed', 'bazantik-photo-gallery' ),
		];

		$items = get_posts(
			[
				'post_type'      => 'attachment',
				'post_status'    => 'inherit',
				'posts_per_page' => 50,
				'orderby'        => 'ID',
				'order'          => 'ASC',
				'meta_query'     => [
					[
						'key'     => Processing_Status::META_STATUS,
						'value'   => [ Processing_Status::PENDING, Processing_Status::PROCESSING, Processing_Status::FAILED ],
						'compare' => 'IN',
					],
				],
			]
		);

		if ( empty( $items ) ) {
			return '';
		}

		$album_map = Library_Guard::albums_for( wp_list_pluck( $items, 'ID' ) );
		$confirm   = esc_attr( __( 'Are you sure you want to permanently delete this photo including all of its files? This action cannot be undone.', 'bazantik-photo-gallery' ) );
		$html      = '';

		foreach ( $items as $item ) {
			$status   = Processing_Status::get( $item->ID );
			$attempts = (int) get_post_meta( $item->ID, Processing_Status::META_ATTEMPTS, true );
			$error    = (string) get_post_meta( $item->ID, Processing_Status::META_ERROR, true );
			$albums   = isset( $album_map[ $item->ID ] ) ? implode( ', ', array_unique( $album_map[ $item->ID ] ) ) : '—';
			$action   = '';

			if ( Processing_Status::FAILED === $status ) {
				$action = '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" data-bazantik-confirm="' . $confirm . '" style="margin:0;">'
				. '<input type="hidden" name="action" value="bazantik_delete_failed">'
				. '<input type="hidden" name="attachment_id" value="' . esc_attr( (string) $item->ID ) . '">'
				. wp_nonce_field( 'bazantik_delete_failed', '_wpnonce', true, false )
				. '<input type="submit" class="button button-small delete" value="' . esc_attr__( 'Delete permanently', 'bazantik-photo-gallery' ) . '">'
				. '</form>';
			}

			$html .= '<tr><td>' . esc_html( (string) $item->ID ) . '</td>'
			. '<td>' . esc_html( $item->post_title ) . '</td>'
			. '<td>' . esc_html( $labels[ $status ] ?? $status ) . '</td>'
			. '<td>' . esc_html( (string) $attempts ) . '</td>'
			. '<td>' . esc_html( $error ) . '</td>'
			. '<td>' . esc_html( $albums ) . '</td>'
			. '<td>' . $action . '</td></tr>';
		}

		return $html;
	}

	public function handle_verify_library(): void {
		$this->authorize( 'bazantik_verify_library' );

		$result = Library_Guard::verify_library( 25 );
		$this->redirect(
			[
				'bazantik_checked' => $result['checked'],
				'bazantik_missing' => $result['missing'],
			]
		);
	}

	public function render_page(): void {
		if ( ! current_user_can( 'edit_others_posts' ) ) {
			return;
		}

		$counts  = Processing_Queue::counts();
		$done    = isset( $_GET['bazantik_done'] ) ? absint( $_GET['bazantik_done'] ) : null;
		$retried = isset( $_GET['bazantik_retried'] ) ? absint( $_GET['bazantik_retried'] ) : null;

		$labels = [
			Processing_Status::PENDING    => __( '⏳ Pending', 'bazantik-photo-gallery' ),
			Processing_Status::PROCESSING => __( '⚙️ Processing', 'bazantik-photo-gallery' ),
			Processing_Status::READY      => __( '✅ Ready', 'bazantik-photo-gallery' ),
			Processing_Status::FAILED     => __( '❌ Failed', 'bazantik-photo-gallery' ),
		];

		$items = get_posts(
			[
				'post_type'      => 'attachment',
				'post_status'    => 'inherit',
				'posts_per_page' => 50,
				'orderby'        => 'ID',
				'order'          => 'ASC',
				'meta_query'     => [
					[
						'key'     => Processing_Status::META_STATUS,
						'value'   => [ Processing_Status::PENDING, Processing_Status::PROCESSING, Processing_Status::FAILED ],
						'compare' => 'IN',
					],
				],
			]
		);
		?>
	<div class="wrap">
	<h1><?php esc_html_e( 'Background photo processing', 'bazantik-photo-gallery' ); ?></h1>
	
		<?php if ( null !== $done && $done > 0 ) : ?>
	<div class="notice notice-success is-dismissible"><p>
			<?php echo esc_html( sprintf( /* translators: %d: number of processed photos */ __( 'Batch finished, photos processed: %d.', 'bazantik-photo-gallery' ), $done ) ); ?>
	</p></div>
	<?php endif; ?>
	
		<?php $batch_failed = isset( $_GET['bazantik_failed'] ) ? absint( $_GET['bazantik_failed'] ) : 0; ?>
		<?php if ( $batch_failed > 0 ) : ?>
	<div class="notice notice-error is-dismissible"><p>
			<?php echo esc_html( sprintf( /* translators: %d: number of photos with an error */ _n( 'Processing failed for %d photo. See the “Unfinished photos” table below for details.', 'Processing failed for %d photos. See the “Unfinished photos” table below for details.', $batch_failed, 'bazantik-photo-gallery' ), $batch_failed ) ); ?>
	</p></div>
	<?php endif; ?>
	
		<?php if ( ! empty( $_GET['bazantik_busy'] ) ) : ?>
	<div class="notice notice-info is-dismissible"><p>
			<?php esc_html_e( 'Processing is currently running elsewhere (for example in the album editor). Please try again in a moment.', 'bazantik-photo-gallery' ); ?>
	</p></div>
	<?php elseif ( 0 === $done && 0 === $batch_failed ) : ?>
		<?php if ( $counts[ Processing_Status::FAILED ] > 0 && 0 === $counts[ Processing_Status::PENDING ] ) : ?>
	<div class="notice notice-error is-dismissible"><p>
			<?php echo esc_html( sprintf( /* translators: %d: number of failed photos */ _n( 'Nothing is waiting to be processed, but %d photo is in the “Failed” state. Fix the cause and click “Return failed to the queue”.', 'Nothing is waiting to be processed, but %d photos are in the “Failed” state. Fix the cause and click “Return failed to the queue”.', $counts[ Processing_Status::FAILED ], 'bazantik-photo-gallery' ), $counts[ Processing_Status::FAILED ] ) ); ?>
	</p></div>
	<?php else : ?>
	<div class="notice notice-info is-dismissible"><p>
		<?php esc_html_e( 'Nothing is waiting in the queue.', 'bazantik-photo-gallery' ); ?>
	</p></div>
	<?php endif; ?>
	<?php endif; ?>
	
		<?php if ( isset( $_GET['bazantik_checked'] ) ) : ?>
			<?php $verify_missing = isset( $_GET['bazantik_missing'] ) ? absint( $_GET['bazantik_missing'] ) : 0; ?>
	<div class="notice <?php echo $verify_missing > 0 ? 'notice-error' : 'notice-success'; ?> is-dismissible"><p>
			<?php
			echo esc_html(
				sprintf(
				/* translators: 1: number of photos checked, 2: number of photos with a problem */
					__( 'Photos checked in albums: %1$d, with a problem: %2$d.', 'bazantik-photo-gallery' ),
					absint( $_GET['bazantik_checked'] ),
					$verify_missing
				)
			);
			if ( $verify_missing > 0 ) {
				echo ' ' . esc_html__( 'These photos are marked as “Failed” and are not shown to visitors.', 'bazantik-photo-gallery' );
			}
			?>
	</p></div>
	<?php endif; ?>
	
		<?php if ( isset( $_GET['bazantik_deleted'] ) ) : ?>
		<div class="notice notice-info is-dismissible"><p>
			<?php echo esc_html( sprintf( /* translators: %d: number of deleted photos */ _n( 'Photo permanently deleted: %d.', 'Photos permanently deleted: %d.', absint( $_GET['bazantik_deleted'] ), 'bazantik-photo-gallery' ), absint( $_GET['bazantik_deleted'] ) ) ); ?>
			<?php if ( ! empty( $_GET['bazantik_kept'] ) ) : ?>
				<?php echo esc_html( sprintf( /* translators: %d: number of kept photos */ _n( 'Photo kept for recovery: %d (only the resized versions are missing, use “Return failed to the queue”).', 'Photos kept for recovery: %d (only the resized versions are missing, use “Return failed to the queue”).', absint( $_GET['bazantik_kept'] ), 'bazantik-photo-gallery' ), absint( $_GET['bazantik_kept'] ) ) ); ?>
		<?php endif; ?>
	</p></div>
	<?php endif; ?>
	
		<?php if ( null !== $retried ) : ?>
		<div class="notice notice-info is-dismissible"><p>
			<?php echo esc_html( sprintf( /* translators: %d: number of photos returned to the queue */ _n( 'Photo returned to the queue: %d.', 'Photos returned to the queue: %d.', $retried, 'bazantik-photo-gallery' ), $retried ) ); ?>
	</p></div>
	<?php endif; ?>
	
	<table class="widefat striped" style="max-width:420px;margin:16px 0;">
	<tbody>
		<?php foreach ( $labels as $status => $label ) : ?>
	<tr>
	<th><?php echo esc_html( $label ); ?></th>
	<td data-bazantik-count="<?php echo esc_attr( $status ); ?>"><?php echo esc_html( (string) $counts[ $status ] ); ?></td>
	</tr>
	<?php endforeach; ?>
	</tbody>
	</table>
	
		<?php
		$cron_last    = (int) get_option( Processing_Cron::OPT_LAST );
		$cron_next    = wp_next_scheduled( Processing_Cron::HOOK );
		$cron_waiting = $counts[ Processing_Status::PENDING ] + $counts[ Processing_Status::PROCESSING ];
		?>
	<p class="description">
		<?php
		echo esc_html(
			sprintf(
							/* translators: 1: time of the last run, 2: time of the next scheduled run */
				__( 'Background processing (WP-Cron): last run %1$s, next run %2$s.', 'bazantik-photo-gallery' ),
				$cron_last ? wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $cron_last ) : __( 'never', 'bazantik-photo-gallery' ),
				$cron_next ? wp_date( get_option( 'time_format' ), $cron_next ) : __( 'not scheduled', 'bazantik-photo-gallery' )
			)
		);
		?>
	</p>
		<?php if ( $cron_waiting > 0 && ( ! $cron_next || ( time() - (int) $cron_next ) > 180 ) ) : ?>
		<div class="notice notice-warning inline"><p>
			<?php
			echo esc_html(
				sprintf(
								/* translators: %s: adresa souboru wp-cron.php */
					__( 'Photos are waiting to be processed, but background processing has not run for a long time. WordPress runs it only when the site is visited. For reliable processing without an open editor, set up a scheduled task (cron) on your hosting that requests %s regularly (for example every hour).', 'bazantik-photo-gallery' ),
					site_url( 'wp-cron.php' )
				)
			);
			?>
	</p></div>
	<?php endif; ?>
	
	<p class="description">
		<?php
		$cron_ext = (int) get_option( Processing_Cron::OPT_LAST_EXT );
		echo esc_html(
			sprintf(
							/* translators: %s: time of the last run */
				__( 'Last run by a real cron on the hosting: %s.', 'bazantik-photo-gallery' ),
				$cron_ext ? wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $cron_ext ) : __( 'never', 'bazantik-photo-gallery' )
			)
		);
		?>
	</p>
	
		<?php $paused = Processing_Status::is_paused(); ?>
		<?php if ( $paused ) : ?>
		<div class="notice notice-warning inline"><p>
			<?php esc_html_e( 'Background photo processing is PAUSED. Pending photos are not being processed (neither by the album editor nor by cron).', 'bazantik-photo-gallery' ); ?>
	</p></div>
	<?php endif; ?>
	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline-block;margin:6px 12px 6px 0;">
	<input type="hidden" name="action" value="bazantik_toggle_pause">
		<?php wp_nonce_field( 'bazantik_toggle_pause' ); ?>
		<?php submit_button( $paused ? __( 'Resume processing', 'bazantik-photo-gallery' ) : __( 'Pause processing', 'bazantik-photo-gallery' ), 'secondary', 'submit', false ); ?>
	</form>
	
		<?php $lock_age = Processing_Queue::lock_age(); ?>
	<p class="description">
		<?php
		echo esc_html(
			null === $lock_age
					? __( 'Processing lock: free.', 'bazantik-photo-gallery' )
					: sprintf( /* translators: %d: age of the lock in seconds */ __( 'Processing lock: held (%d s ago). Processing is running now.', 'bazantik-photo-gallery' ), $lock_age )
		);
		?>
	</p>
	
	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline-block;margin-right:12px;">
	<input type="hidden" name="action" value="bazantik_run_batch">
		<?php wp_nonce_field( 'bazantik_run_batch' ); ?>
		<?php /* translators: %d: batch length in seconds */ submit_button( sprintf( __( 'Run a batch (max. about %d s)', 'bazantik-photo-gallery' ), Settings::perf()['manual'] ), 'primary', 'submit', false ); ?>
	</form>
	
	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline-block;">
	<input type="hidden" name="action" value="bazantik_retry_failed">
		<?php wp_nonce_field( 'bazantik_retry_failed' ); ?>
		<?php submit_button( __( 'Return failed to the queue', 'bazantik-photo-gallery' ), 'secondary', 'submit', false ); ?>
	</form>
	
	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline-block;margin-left:12px;">
	<input type="hidden" name="action" value="bazantik_verify_library">
		<?php wp_nonce_field( 'bazantik_verify_library' ); ?>
		<?php submit_button( __( 'Verify the library (missing files and sizes)', 'bazantik-photo-gallery' ), 'secondary', 'submit', false ); ?>
	</form>
	
	<p class="description"><?php esc_html_e( 'After you click, the page may load for up to a minute until the batch finishes. Click again for more photos.', 'bazantik-photo-gallery' ); ?></p>
	
	<details id="bazantik-report" style="margin:16px 0;"<?php echo isset( $_GET['bazantik_report'] ) ? ' open' : ''; ?>>
	<summary><strong><?php esc_html_e( 'Diagnostic report (to copy)', 'bazantik-photo-gallery' ); ?></strong></summary>
		<?php if ( isset( $_GET['bazantik_report'] ) ) : ?>
	<textarea readonly rows="24" style="width:100%;max-width:900px;font-family:monospace;margin-top:8px;" data-bazantik-select><?php echo esc_textarea( Test_Report::build() ); ?></textarea>
	<?php else : ?>
	<p><a class="button" href="<?php echo esc_url( add_query_arg( 'bazantik_report', '1' ) . '#bazantik-report' ); ?>"><?php esc_html_e( 'Create a diagnostic report', 'bazantik-photo-gallery' ); ?></a></p>
	<?php endif; ?>
	</details>
	
		<?php if ( ! empty( $items ) ) : ?>
		<h2><?php esc_html_e( 'Unfinished photos', 'bazantik-photo-gallery' ); ?></h2>
			<?php /* formulář je vždy v DOM a JS ho při změně počtu neúspěšných zobrazí nebo skryje */ ?>
		<form id="bazantik-delete-all-failed"<?php echo $counts[ Processing_Status::FAILED ] > 0 ? '' : ' hidden'; ?> method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" data-bazantik-confirm="<?php echo esc_attr( __( 'Only failed photos whose file is missing on disk and cannot be recovered will be deleted. The others stay (return them to the queue). Deletion cannot be undone. Continue?', 'bazantik-photo-gallery' ) ); ?>" style="margin:0 0 10px;">
		<input type="hidden" name="action" value="bazantik_delete_failed">
			<?php wp_nonce_field( 'bazantik_delete_failed' ); ?>
			<?php submit_button( __( 'Permanently delete all failed photos', 'bazantik-photo-gallery' ), 'delete', 'submit', false ); ?>
	</form>
	<table class="widefat striped">
	<thead>
	<tr>
	<th>ID</th>
	<th><?php esc_html_e( 'File', 'bazantik-photo-gallery' ); ?></th>
	<th><?php esc_html_e( 'Status', 'bazantik-photo-gallery' ); ?></th>
	<th><?php esc_html_e( 'Attempts', 'bazantik-photo-gallery' ); ?></th>
	<th><?php esc_html_e( 'Error', 'bazantik-photo-gallery' ); ?></th>
	<th class="bazantik-col-alba"><?php esc_html_e( 'Albums', 'bazantik-photo-gallery' ); ?></th>
	<th><?php esc_html_e( 'Actions', 'bazantik-photo-gallery' ); ?></th>
	</tr>
	</thead>
	<tbody>
			<?php echo self::build_rows(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- řádky se escapují při sestavení ?>
	</tbody>
	</table>
	<?php endif; ?>
	</div>
		<?php
	}

	private function authorize( string $nonce_action ): void {
		if ( ! current_user_can( 'edit_others_posts' ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'bazantik-photo-gallery' ), '', [ 'response' => 403 ] );
		}
		check_admin_referer( $nonce_action );
	}

	private function redirect( array $args ): void {
		$url = add_query_arg(
			array_merge( [ 'post_type' => 'bazantik_album', 'page' => 'bazantik-processing' ], $args ),
			admin_url( 'edit.php' )
		);
		wp_safe_redirect( $url );
		exit;
	}
}
