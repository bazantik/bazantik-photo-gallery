<?php
declare(strict_types=1);

namespace BazantikPhotoGallery;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.WP.AlternativeFunctions -- čtení hlaviček velkých souborů po částech (WP_Filesystem by načetl celý soubor)

/**
 * Čtení metadat videa (QuickTime/MP4) přímo ze souboru: datum natočení, poloha a přístroj (klíče Apple).
 * Čte se jen začátek a konec souboru, kde bývají metadata.
 */
class Video_Metadata {
	public static function apply( int $attachment_id ): void {
		$file = get_attached_file( $attachment_id );
		if ( ! $file || ! is_file( $file ) ) {
			return;
		}

		$data  = self::read_chunks( $file );
		$exif  = get_post_meta( $attachment_id, '_bazantik_exif_data', true );
		$exif  = is_array( $exif ) ? $exif : [];
		$found = [];

		// Datum natočení (místní čas s časovou zónou), např. 2026-05-25T09:00:47+0200
		$window = self::window( $data, 'com.apple.quicktime.creationdate' );
		if ( '' !== $window && preg_match( '/(\d{4})-(\d{2})-(\d{2})T(\d{2}):(\d{2}):(\d{2})/', $window, $m ) ) {
			$exif['captured_at']   = "{$m[1]}:{$m[2]}:{$m[3]} {$m[4]}:{$m[5]}";
			$exif['captured_sort'] = "{$m[1]}-{$m[2]}-{$m[3]} {$m[4]}:{$m[5]}:{$m[6]}.000";
			$found[]               = 'date';
		}

		// Poloha ve formátu ISO 6709, např. +49.8209+018.2625+250.123/
		$window = self::window( $data, 'com.apple.quicktime.location.ISO6709' );
		if ( '' !== $window && preg_match( '/([+-]\d{1,2}\.\d+)([+-]\d{1,3}\.\d+)(?:[+-]\d+(?:\.\d+)?)?\//', $window, $m ) && abs( (float) $m[1] ) <= 90 && abs( (float) $m[2] ) <= 180 ) {
			$exif['gps'] = [
				'lat' => (float) $m[1],
				'lng' => (float) $m[2],
			];
			$found[]     = 'location';
		}

		// Záloha pro videa bez klíčů Apple (Android, fotoaparáty): datum z atomu mvhd (UTC) a poloha z atomu ©xyz
		if ( empty( $exif['captured_sort'] ) ) {
			$created = self::mvhd_created( $data );
			if ( $created > 0 ) {
				$exif['captured_at']   = wp_date( 'Y:m:d H:i', $created );
				$exif['captured_sort'] = wp_date( 'Y-m-d H:i:s', $created ) . '.000';
				$found[]               = 'date (mvhd)';
			}
		}

		if ( empty( $exif['gps'] ) ) {
			$xyz_pos = strpos( $data, "\xA9xyz" );
			if ( false !== $xyz_pos && preg_match( '/([+-]\d{1,2}\.\d+)([+-]\d{1,3}\.\d+)/', substr( $data, $xyz_pos + 4, 80 ), $m ) && abs( (float) $m[1] ) <= 90 && abs( (float) $m[2] ) <= 180 ) {
				$exif['gps'] = [
					'lat' => (float) $m[1],
					'lng' => (float) $m[2],
				];
				$found[]     = 'location (©xyz)';
			}
		}

		// Přístroj
		$window = self::window( $data, 'com.apple.quicktime.model' );
		if ( '' !== $window && preg_match( '/(iPhone|iPad)[ 0-9A-Za-z]{0,20}/', $window, $m ) ) {
			$exif['camera'] = 'Apple ' . trim( $m[0] );
			$found[]        = 'camera';
		}

		if ( ! empty( $exif ) ) {
			update_post_meta( $attachment_id, '_bazantik_exif_data', $exif );
		}

		// Pro diagnostiku v testovacím reportu
		update_post_meta( $attachment_id, '_bazantik_video_meta_found', $found ? implode( ', ', $found ) : 'nothing' );
		update_post_meta( $attachment_id, '_bazantik_video_track_info', self::track_info( $data ) );
	}

	/**
	 * Diagnostika orientace: kódované rozměry a otočení videostopy z atomu tkhd (např. „3840×2160, otočení 90°“).
	 */
	private static function track_info( string $data ): string {
		$offset = 0;
		while ( false !== ( $pos = strpos( $data, 'tkhd', $offset ) ) ) {
			$offset = $pos + 4;
			$body   = substr( $data, $pos + 4, 96 );
			if ( strlen( $body ) < 84 ) {
				continue;
			}

			$version = ord( $body[0] );
			$matrix  = 0 === $version ? 40 : 52;
			if ( strlen( $body ) < $matrix + 44 ) {
				continue;
			}

			$m = unpack( 'N9', substr( $body, $matrix, 36 ) );
			$w = unpack( 'N', substr( $body, $matrix + 36, 4 ) )[1] >> 16;
			$h = unpack( 'N', substr( $body, $matrix + 40, 4 ) )[1] >> 16;
			if ( $w < 1 || $h < 1 ) {
				continue; // zvuková stopa
			}

			$a = self::fixed( (int) $m[1] );
			$b = self::fixed( (int) $m[2] );
			$angle = (int) round( rad2deg( atan2( $b, $a ) ) );
			$angle = ( $angle % 360 + 360 ) % 360;

			return $w . '×' . $h . ', rotation ' . $angle . '°';
		}

		return 'tkhd not found';
	}

	private static function fixed( int $value ): float {
		if ( $value >= 0x80000000 ) {
			$value -= 0x100000000;
		}
		return $value / 65536;
	}

	/**
	 * Čas vytvoření z atomu mvhd jako Unix čas (UTC); 0, když chybí nebo je nevěrohodný.
	 */
	private static function mvhd_created( string $data ): int {
		$offset = 0;
		while ( false !== ( $pos = strpos( $data, 'mvhd', $offset ) ) ) {
			$offset = $pos + 4;
			$body   = substr( $data, $pos + 4, 32 );

			if ( strlen( $body ) < 20 ) {
				continue;
			}

			$version = ord( $body[0] );
			if ( 0 === $version ) {
				$fields = unpack( 'Ncreated/Nmodified/Ntimescale', substr( $body, 4, 12 ) );
				$created = $fields ? (int) $fields['created'] : 0;
				$scale   = $fields ? (int) $fields['timescale'] : 0;
			} elseif ( 1 === $version && strlen( $body ) >= 28 ) {
				$fields = unpack( 'Jcreated/Jmodified/Ntimescale', substr( $body, 4, 20 ) );
				$created = $fields ? (int) $fields['created'] : 0;
				$scale   = $fields ? (int) $fields['timescale'] : 0;
			} else {
				continue;
			}

			// Počátek QuickTime je 1904-01-01; věrohodné jsou jen údaje po roce 2000 a ne v budoucnosti
			$unix = $created - 2082844800;
			if ( $scale > 0 && $unix > 946684800 && $unix <= time() + DAY_IN_SECONDS ) {
				return $unix;
			}
		}

		return 0;
	}

	/**
	 * Okolí prvního výskytu klíče (metadata Apple jsou u sebe).
	 */
	private static function window( string $data, string $key ): string {
		$pos = strpos( $data, $key );
		if ( false === $pos ) {
			return '';
		}

		return substr( $data, max( 0, $pos - 1500 ), 4000 );
	}

	/**
	 * Začátek a konec souboru (po 2 MB).
	 */
	private static function read_chunks( string $file ): string {
		$size   = (int) filesize( $file );
		$chunk  = 2 * MB_IN_BYTES;
		$handle = fopen( $file, 'rb' );

		if ( ! $handle ) {
			return '';
		}

		$data = (string) fread( $handle, $chunk );
		if ( $size > $chunk ) {
			fseek( $handle, max( $chunk, $size - $chunk ) );
			$data .= (string) fread( $handle, $chunk );
		}
		fclose( $handle );

		return $data;
	}
}
