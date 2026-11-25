<?php
declare(strict_types=1);

namespace BazantikPhotoGallery;

defined( 'ABSPATH' ) || exit;

/**
 * Údaje pod názvem karty alba: počet fotek a videí a rozpětí dat pořízení.
 * Výsledek se ukládá do transientu platného jen pro aktuální seznam ID v albu (při změně alba se přepočítá).
 */
class Album_Stats {

	/**
	 * @return array{count:int,from:string,to:string}
	 */
	public static function get( int $album_id ): array {
		$raw      = (string) get_post_meta( $album_id, '_bazantik_media_ids', true );
		$hash     = md5( $raw );
		// Mezipaměť je v meta alba (načtou se hromadně s albem, bez dotazu navíc pro každou kartu)
		$cached   = get_post_meta( $album_id, '_bazantik_stats_cache', true );
		if ( is_array( $cached ) && ( $cached['hash'] ?? '' ) === $hash && ( (int) ( $cached['ts'] ?? 0 ) ) > time() - DAY_IN_SECONDS && is_array( $cached['data'] ?? null ) ) {
			return $cached['data'];
		}

		// Návštěvníkovi se po vypršení meta mezipaměti použije krátký transient, aby se údaje nepřepočítávaly při každém zobrazení
		$transient_key = 'bazantik_stats_' . $album_id . '_' . substr( $hash, 0, 12 );
		$short         = get_transient( $transient_key );
		if ( is_array( $short ) ) {
			return $short;
		}

		$ids = array_filter( array_map( 'absint', explode( ',', $raw ) ) );
		if ( ! empty( $ids ) ) {
			update_meta_cache( 'post', $ids );
			_prime_post_caches( $ids, false, false ); // typ příspěvku se čte z mezipaměti, ne dotazem pro každou fotku
		}

		$count = 0;
		$from  = '';
		$to    = '';
		foreach ( $ids as $id ) {
			if ( 'attachment' !== get_post_type( $id ) || ! Processing_Status::is_visible( $id ) ) {
				continue;
			}
			++$count;
			$exif = get_post_meta( $id, '_bazantik_exif_data', true );
			$date = is_array( $exif ) ? substr( (string) ( $exif['captured_sort'] ?? '' ), 0, 10 ) : '';
			if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
				continue;
			}
			if ( '' === $from || $date < $from ) {
				$from = $date;
			}
			if ( '' === $to || $date > $to ) {
				$to = $date;
			}
		}

		$data = [ 'count' => $count, 'from' => $from, 'to' => $to ];
		// Mezipaměť se zapisuje jen při práci správce (uložení alba, přehled v administraci); požadavek návštěvníka nic nezapisuje
		if ( current_user_can( 'edit_post', $album_id ) ) {
			update_post_meta( $album_id, '_bazantik_stats_cache', [ 'hash' => $hash, 'data' => $data, 'ts' => time() ] );
		} else {
			set_transient( $transient_key, $data, 10 * MINUTE_IN_SECONDS );
		}
		return $data;
	}

	/**
	 * HTML řádku pod názvem karty (prázdný řetězec u prázdného alba).
	 */
	public static function render( int $album_id ): string {
		$stats = self::get( $album_id );
		if ( $stats['count'] < 1 ) {
			return '';
		}

		$fmt  = static fn( string $d ): string => wp_date( (string) get_option( 'date_format' ), (int) strtotime( $d . ' 12:00:00 UTC' ), new \DateTimeZone( 'UTC' ) );
		$date = '';
		if ( '' !== $stats['from'] ) {
			$date = $fmt( $stats['from'] );
			if ( $stats['to'] !== $stats['from'] ) {
				$date .= ' – ' . $fmt( $stats['to'] );
			}
		}

		$icon = '<svg class="bazantik-stats-icon" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linejoin="round" aria-hidden="true" focusable="false"><rect x="7" y="3" width="14" height="14" rx="2"/><path d="M3 7v12a2 2 0 0 0 2 2h12"/><path d="M10 14l3-3.5 3 3.5 2-2.2 3 3.2" stroke-linecap="round"/></svg>';

		$html = '<div class="bazantik-album-card-stats">';
		if ( '' !== $date ) {
			$html .= '<span class="bazantik-stats-date">' . esc_html( $date ) . '</span><span class="bazantik-stats-sep" aria-hidden="true">|</span>';
		}
		$html .= '<span class="bazantik-stats-count" title="' . esc_attr__( 'Number of photos and videos', 'bazantik-photo-gallery' ) . '">' . (int) $stats['count'] . ' ' . $icon . '<span class="screen-reader-text">' . esc_html__( 'photos and videos', 'bazantik-photo-gallery' ) . '</span></span>';
		return $html . '</div>';
	}
}
