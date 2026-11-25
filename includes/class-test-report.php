<?php
declare(strict_types=1);

namespace BazantikPhotoGallery;

defined( 'ABSPATH' ) || exit;

/**
 * Textový diagnostický report (ke zkopírování): prostředí, počty, časy zpracování, nejpomalejší fotky, chyby.
 */
class Test_Report {
	public static function build(): string {
		global $wpdb;

		$lines   = [];
		$lines[] = __( 'Bazantik Photo Gallery diagnostic report', 'bazantik-photo-gallery' ) . ', ' . wp_date( 'Y-m-d H:i:s' );
		$lines[] = '';
		$lines[] = 'PHP ' . PHP_VERSION . ' | WordPress ' . get_bloginfo( 'version' ) . ' | memory_limit ' . ini_get( 'memory_limit' ) . ' | max_execution_time ' . ini_get( 'max_execution_time' ) . ' s';

		if ( extension_loaded( 'imagick' ) ) {
			$version = \Imagick::getVersion();
			$lines[] = 'Imagick: ' . $version['versionString'];
		} else {
			$lines[] = 'Imagick: ' . __( 'not available', 'bazantik-photo-gallery' );
		}

		$yes     = __( 'yes', 'bazantik-photo-gallery' );
		$no      = __( 'no', 'bazantik-photo-gallery' );
		$lines[] = 'WebP: ' . ( wp_image_editor_supports( [ 'mime_type' => 'image/webp' ] ) ? $yes : $no ) . ' | ' . __( 'processing paused', 'bazantik-photo-gallery' ) . ': ' . ( Processing_Status::is_paused() ? strtoupper( $yes ) : $no );
		$lines[] = '';

		$counts  = Processing_Queue::counts();
		$lines[] = sprintf(
							/* translators: 1: pending, 2: processing, 3: ready, 4: failed (number of photos in each state) */
			__( 'Photo states: pending %1$d | processing %2$d | ready %3$d | failed %4$d', 'bazantik-photo-gallery' ),
			$counts[ Processing_Status::PENDING ],
			$counts[ Processing_Status::PROCESSING ],
			$counts[ Processing_Status::READY ],
			$counts[ Processing_Status::FAILED ]
		);

		// Souhrn naměřených časů zpracování
		$stats = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT COUNT(*) AS n, AVG( CAST( meta_value AS UNSIGNED ) ) AS avg_ms, MAX( CAST( meta_value AS UNSIGNED ) ) AS max_ms
                FROM {$wpdb->postmeta}
                WHERE meta_key = %s AND EXISTS ( SELECT 1 FROM {$wpdb->posts} WHERE {$wpdb->posts}.ID = {$wpdb->postmeta}.post_id )",
				'_bazantik_proc_ms'
			),
			ARRAY_A
		);

		if ( $stats && (int) $stats['n'] > 0 ) {
			$lines[] = sprintf(
								/* translators: 1: number of photos, 2: average seconds, 3: longest seconds */
				__( 'Measured processing: %1$d photos | average %2$.1f s | longest %3$.1f s', 'bazantik-photo-gallery' ),
				(int) $stats['n'],
				(float) $stats['avg_ms'] / 1000,
				(float) $stats['max_ms'] / 1000
			);
		} else {
			$lines[] = __( 'Measured processing: no data yet', 'bazantik-photo-gallery' );
		}

		$extra = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT
                    ( SELECT AVG( CAST( meta_value AS UNSIGNED ) ) FROM {$wpdb->postmeta} WHERE meta_key = %s ) AS avg_mb,
                    ( SELECT MAX( CAST( meta_value AS UNSIGNED ) ) FROM {$wpdb->postmeta} WHERE meta_key = %s ) AS max_rss",
				'_bazantik_proc_mb',
				'_bazantik_proc_rss'
			),
			ARRAY_A
		);

		if ( $extra && null !== $extra['avg_mb'] ) {
			$lines[] = sprintf(
				/* translators: 1: average original size in MB, 2: peak process memory in MB */
				__( 'Average original size: %1$.1f MB | highest measured process memory: %2$d MB', 'bazantik-photo-gallery' ),
				(float) $extra['avg_mb'],
				(int) $extra['max_rss']
			);
		}

		$last_hour = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = %s AND CAST( meta_value AS UNSIGNED ) > %d",
				'_bazantik_proc_done',
				time() - 3600
			)
		);
		$lines[] = __( 'Processed in the last hour', 'bazantik-photo-gallery' ) . ': ' . $last_hour;

		// Nejpomalejší fotky
		$slow = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT post_id, CAST( meta_value AS UNSIGNED ) AS ms FROM {$wpdb->postmeta} WHERE meta_key = %s ORDER BY ms DESC LIMIT 5",
				'_bazantik_proc_ms'
			),
			ARRAY_A
		);

		if ( ! empty( $slow ) ) {
			$lines[] = __( 'Slowest photos', 'bazantik-photo-gallery' ) . ':';
			foreach ( $slow as $row ) {
				$id      = (int) $row['post_id'];
				$lines[] = sprintf(
									/* translators: 1: photo title, 2: seconds, 3: original size in MB */
					__( '  - %1$s: %2$.1f s (original %3$d MB)', 'bazantik-photo-gallery' ),
					(string) get_post_field( 'post_title', $id ),
					(int) $row['ms'] / 1000,
					(int) get_post_meta( $id, '_bazantik_proc_mb', true )
				);
			}
		}

		$lines[] = '';

		// Typy souborů v albech
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- dotaz neobsahuje žádný vstup, jen názvy tabulek jádra
		$mimes = $wpdb->get_results(
			"SELECT post_mime_type AS mime, COUNT(*) AS total FROM {$wpdb->posts}
            WHERE post_type = 'attachment' AND post_parent IN ( SELECT ID FROM {$wpdb->posts} WHERE post_type = 'bazantik_album' )
            GROUP BY post_mime_type ORDER BY total DESC",
			ARRAY_A
		);
		// phpcs:enable

		if ( ! empty( $mimes ) ) {
			$parts = [];
			foreach ( $mimes as $row ) {
				$parts[] = $row['mime'] . ' ×' . $row['total'];
			}
			$lines[] = __( 'Files uploaded to albums', 'bazantik-photo-gallery' ) . ': ' . implode( ', ', $parts );
		}

		// Největší alba
		$albums = get_posts(
			[
				'post_type'      => 'bazantik_album',
				'post_status'    => 'any',
				'posts_per_page' => -1,
			]
		);

		$sizes = [];
		foreach ( $albums as $album ) {
			$raw                          = (string) get_post_meta( $album->ID, '_bazantik_media_ids', true );
			$sizes[ $album->post_title ] = '' === $raw ? 0 : count( explode( ',', $raw ) );
		}
		arsort( $sizes );
		$top   = array_slice( $sizes, 0, 3, true );
		$parts = [];
		foreach ( $top as $title => $count ) {
			$parts[] = $title . ' (' . $count . ')';
		}
		$lines[] = __( 'Number of albums', 'bazantik-photo-gallery' ) . ': ' . count( $albums ) . ( $parts ? ' | ' . __( 'largest', 'bazantik-photo-gallery' ) . ': ' . implode( ', ', $parts ) : '' );

		// Cron
		$cron_last = (int) get_option( Processing_Cron::OPT_LAST );
		$cron_ext  = (int) get_option( Processing_Cron::OPT_LAST_EXT );
		$lines[]   = 'WP-Cron ' . __( 'last run', 'bazantik-photo-gallery' ) . ': ' . ( $cron_last ? wp_date( 'Y-m-d H:i:s', $cron_last ) : __( 'never', 'bazantik-photo-gallery' ) ) . ' | ' . __( 'real cron last run', 'bazantik-photo-gallery' ) . ': ' . ( $cron_ext ? wp_date( 'Y-m-d H:i:s', $cron_ext ) : __( 'never', 'bazantik-photo-gallery' ) );

		// Neúspěšné fotky
		$failed = get_posts(
			[
				'post_type'      => 'attachment',
				'post_status'    => 'inherit',
				'posts_per_page' => 10,
				'meta_key'       => Processing_Status::META_STATUS,
				'meta_value'     => Processing_Status::FAILED,
			]
		);

		if ( ! empty( $failed ) ) {
			$lines[] = '';
			$lines[] = __( 'Failed photos (at most 10):', 'bazantik-photo-gallery' );
			foreach ( $failed as $item ) {
				$lines[] = '  - ' . $item->post_title . ': ' . (string) get_post_meta( $item->ID, Processing_Status::META_ERROR, true );
			}
		}

		// Videa: co se podařilo najít přímo v souboru (datum natočení, poloha, přístroj)
		$videos = get_posts(
			[
				'post_type'      => 'attachment',
				'post_status'    => 'inherit',
				'post_mime_type' => 'video',
				'posts_per_page' => 5,
				'orderby'        => 'ID',
				'order'          => 'DESC',
			]
		);

		if ( ! empty( $videos ) ) {
			$lines[] = '';
			$lines[] = __( 'Latest videos (data found in the file):', 'bazantik-photo-gallery' );

			foreach ( $videos as $video ) {
				$v_exif  = get_post_meta( $video->ID, '_bazantik_exif_data', true );
				$v_exif  = is_array( $v_exif ) ? $v_exif : [];
				$lines[] = sprintf(
									/* translators: 1: title, 2: size in MB, 3: width, 4: height, 5: found data, 6: date, 7: GPS, 8: preview, 9: track info */
					__( '  - %1$s | %2$.1f MB | size %3$d×%4$d | found in file: %5$s | date: %6$s | GPS: %7$s | preview: %8$s | track in file: %9$s', 'bazantik-photo-gallery' ),
					(string) $video->post_title,
					(int) wp_filesize( (string) get_attached_file( $video->ID ) ) / 1048576,
					(int) get_post_meta( $video->ID, '_bazantik_video_w', true ),
					(int) get_post_meta( $video->ID, '_bazantik_video_h', true ),
					(string) ( get_post_meta( $video->ID, '_bazantik_video_meta_found', true ) ?: __( 'not checked', 'bazantik-photo-gallery' ) ),
					(string) ( $v_exif['captured_sort'] ?? '—' ),
					! empty( $v_exif['gps'] ) ? $v_exif['gps']['lat'] . ', ' . $v_exif['gps']['lng'] : '—',
					get_post_meta( $video->ID, '_bazantik_video_poster_url', true ) ? $yes : $no,
					(string) ( get_post_meta( $video->ID, '_bazantik_video_track_info', true ) ?: __( 'not checked', 'bazantik-photo-gallery' ) )
				);
			}
		}

		return implode( "\n", $lines );
	}
}
