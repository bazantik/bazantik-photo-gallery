<?php
/**
 * Odinstalace pluginu: odstraní jen volby, transienty a plánované úlohy pluginu.
 * Alba, fotky, videa a jejich metadata (`_bazantik_*`) se záměrně NEMAŽOU, aby se přeinstalací nepřišlo o data.
 */

declare(strict_types=1);

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

/**
 * Úklid jednoho webu; v Multisite se provede pro každý web sítě.
 */
function bazantik_uninstall_site(): void {
	wp_clear_scheduled_hook( 'bazantik_process_queue' );

	$bazantik_options = [
		'bazantik_worker_lock',
		'bazantik_queue_paused',
		'bazantik_cron_last_run',
		'bazantik_cron_last_external',
		'bazantik_chain_token',
		'bazantik_last_upload_at',
		'bazantik_db_version',
		'bazantik_notice_audience',
		'bazantik_show_credit',
		'bazantik_mobile_zoom',
		'bazantik_mobile_zoom_per_album',
		'bazantik_deferred_upload',
		'bazantik_polite_worker',
		'bazantik_random_filenames',
		'bazantik_perf_profile',
		'bazantik_large_size',
		'bazantik_map_enabled',
		'bazantik_map_mode',
		'bazantik_map_provider',
		'bazantik_map_tile_url',
		'bazantik_map_attribution',
		'bazantik_map_api_keys',
		'bazantik_map_layer_switch',
		'bazantik_throttle_enabled',
		'bazantik_throttle_tries',
		'bazantik_throttle_minutes',
		'bazantik_sort_root',
		'bazantik_sort_root_manual',
		'bazantik_slug_archive',
		'bazantik_slug_album',
		'bazantik_slug_folder',
		'bazantik_flush_rewrite',
	];

	foreach ( $bazantik_options as $bazantik_option ) {
		delete_option( $bazantik_option );
	}

	delete_transient( 'bazantik_private_media_ids' );
	delete_transient( 'bazantik_counts_cache' );

	// Dočasná data s proměnlivým názvem (statistiky alb, výpisy fragmentů, počítadla pokusů o odemčení složek)
	global $wpdb;
	foreach ( [ 'bazantik_stats_', 'bazantik_frag_', 'bazantik_fl_', 'bazantik_al_', 'bazantik_folder_err_', 'bazantik_pw_', 'bazantik_removed_private_' ] as $bazantik_prefix ) {
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s", $wpdb->esc_like( '_transient_' . $bazantik_prefix ) . '%', $wpdb->esc_like( '_transient_timeout_' . $bazantik_prefix ) . '%' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
	}

	// Šifrovaná kopie hesla složky (tajemství pluginu); samotné složky a jejich ostatní metadata zůstávají
	$wpdb->delete( $wpdb->termmeta, [ 'meta_key' => '_bazantik_folder_pwenc' ] ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
}

if ( is_multisite() ) {
	foreach ( get_sites( [ 'fields' => 'ids', 'number' => 0 ] ) as $bazantik_site_id ) {
		switch_to_blog( (int) $bazantik_site_id );
		bazantik_uninstall_site();
		restore_current_blog();
	}
} else {
	bazantik_uninstall_site();
}
