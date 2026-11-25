<?php
declare(strict_types=1);

namespace BazantikPhotoGallery;

defined( 'ABSPATH' ) || exit;

class Exif_Service {
	public function init(): void {
		add_filter( 'wp_generate_attachment_metadata', [ $this, 'extract_exif_data' ], 20, 2 );
		// Fotka z Knihovny médií přidaná do alba později nemá rodiče-album, EXIF se přečte až při přidání do alba
		add_action( 'added_post_meta', [ $this, 'ensure_on_album_media_change' ], 10, 4 );
		add_action( 'updated_post_meta', [ $this, 'ensure_on_album_media_change' ], 10, 4 );
	}

	private bool $forced = false;

	/** Po uložení seznamu fotek alba dohraje EXIF těm, které ho ještě nemají (např. fotka z Knihovny médií). */
	public function ensure_on_album_media_change( $meta_id, $post_id, $meta_key, $meta_value ): void {
		if ( '_bazantik_media_ids' !== $meta_key || 'bazantik_album' !== get_post_type( (int) $post_id ) ) {
			return;
		}
		foreach ( array_filter( array_map( 'absint', explode( ',', (string) $meta_value ) ) ) as $media_id ) {
			if ( is_array( get_post_meta( $media_id, '_bazantik_exif_data', true ) ) || '' !== (string) get_post_meta( $media_id, '_bazantik_exif_checked', true ) ) {
				continue;
			}
			// Video z Knihovny médií přidané do alba později: údaje ze souboru se přečtou až teď
			if ( 0 === strpos( (string) get_post_mime_type( $media_id ), 'video/' ) ) {
				if ( '' === (string) get_post_meta( $media_id, '_bazantik_video_meta_found', true ) ) {
					Video_Metadata::apply( $media_id );
				}
				continue;
			}
			$meta = wp_get_attachment_metadata( $media_id );
			$this->forced = true;
			$this->extract_exif_data( is_array( $meta ) ? $meta : [], $media_id );
			$this->forced = false;
			// Fotka bez EXIFu se při dalším uložení alba (autosave každé 3 s) nečte z disku znovu
			if ( ! is_array( get_post_meta( $media_id, '_bazantik_exif_data', true ) ) ) {
				update_post_meta( $media_id, '_bazantik_exif_checked', '1' );
			}
		}
	}

	public function extract_exif_data( array $metadata, int $attachment_id ): array {
		$file_path = get_attached_file( $attachment_id );
		if ( ! $file_path || ! file_exists( $file_path ) ) {
			return $metadata;
		}

		$mime_type = get_post_mime_type( $attachment_id );

		// HEIC/HEIF převede WordPress na JPEG, ale typ přílohy ponechá; bez opravy by se nečetlo EXIF a nenabídlo bezpečné stažení
		if ( in_array( $mime_type, [ 'image/heic', 'image/heif' ], true ) && preg_match( '/\.jpe?g$/i', $file_path ) && ( $this->forced || 'bazantik_album' === get_post_type( (int) wp_get_post_parent_id( $attachment_id ) ) ) ) {
			global $wpdb;
			$wpdb->update( $wpdb->posts, [ 'post_mime_type' => 'image/jpeg' ], [ 'ID' => $attachment_id ] ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- změna typu přílohy bez vedlejších účinků hooků
			clean_post_cache( $attachment_id );
			$mime_type = 'image/jpeg';
		}

		if ( ! in_array( $mime_type, [ 'image/jpeg', 'image/tiff' ], true ) ) {
			return $metadata;
		}

		// Ostatní nahrávání webu (Knihovna médií, články) se netýká: EXIF a GPS se neukládají bez důvodu
		if ( ! $this->forced && 'bazantik_album' !== get_post_type( (int) wp_get_post_parent_id( $attachment_id ) ) ) {
			return $metadata;
		}

		// Zjištění fyzické velikosti a rozměrů originálu jednorázově při uploadu (prevence N+1 I/O v šabloně)
		$raw_filesize     = file_exists( $file_path ) ? (int) filesize( $file_path ) : 0;
		$orig_w           = ! empty( $metadata['width'] ) ? (int) $metadata['width'] : 0;
		$orig_h           = ! empty( $metadata['height'] ) ? (int) $metadata['height'] : 0;
		$source_exif_file = $file_path;

		// Pokud WordPress vygeneroval -scaled variantu, čteme rozměry i EXIF VŽDY z původního netknutého originálu
		if ( ! empty( $metadata['original_image'] ) ) {
			$orig_path = pathinfo( $file_path, PATHINFO_DIRNAME ) . '/' . $metadata['original_image'];
			if ( file_exists( $orig_path ) ) {
				$orig_info = @getimagesize( $orig_path );
				if ( $orig_info ) {
					$orig_w       = (int) $orig_info[0];
					$orig_h       = (int) $orig_info[1];
					$raw_filesize = (int) filesize( $orig_path );
				}
				$source_exif_file = $orig_path; // Čteme EXIF přímo z originálu z fotoaparátu!
			}
		}

		// Rozšíření exif nemusí být na hostingu zapnuté; bez něj se údaje prostě nenačtou
		if ( ! function_exists( 'exif_read_data' ) ) {
			return $metadata;
		}

		$is_heic = (bool) preg_match( '/\.hei[cf]$/i', $source_exif_file );
		$exif_raw = $is_heic ? $this->read_heic_exif( $source_exif_file ) : @exif_read_data( $source_exif_file, 'ANY_TAG', true );
		if ( empty( $exif_raw ) ) {
			return $metadata;
		}

		// Fotky z foťáku s EXIF Orientation 5 až 8 jsou uložené „na ležato“: rozměry pro mřížku musí být prohozené
		// (u HEIC je rozměr z převedeného JPEG už správně otočený)
		$orientation = $is_heic ? 0 : (int) ( $exif_raw['IFD0']['Orientation'] ?? 0 );
		if ( $orientation >= 5 && $orientation <= 8 ) {
			[ $orig_w, $orig_h ] = [ $orig_h, $orig_w ];
		}

		$clean_orig_filename = ! empty( $metadata['original_image'] ) && ! $is_heic ? $metadata['original_image'] : basename( $file_path );

		$exif = [
			'filename'        => sanitize_text_field( $clean_orig_filename ),
			'filesize'        => $raw_filesize > 0 ? size_format( $raw_filesize, 2 ) : '',
			'orig_width'      => $orig_w,
			'orig_height'     => $orig_h,
			'camera'          => sanitize_text_field( $this->extract_camera( $exif_raw ) ),
			'lens'            => sanitize_text_field( $this->extract_lens( $exif_raw ) ),
			'focal_length'    => sanitize_text_field( $this->extract_focal_length( $exif_raw ) ),
			'focal_35mm'      => ! empty( $exif_raw['EXIF']['FocalLengthIn35mmFilm'] ) ? sanitize_text_field( (string) $exif_raw['EXIF']['FocalLengthIn35mmFilm'] . ' mm' ) : '',
			'aperture'        => sanitize_text_field( $this->extract_aperture( $exif_raw ) ),
			'shutter_speed'   => sanitize_text_field( $this->extract_shutter_speed( $exif_raw ) ),
			'iso'             => sanitize_text_field( $this->extract_iso( $exif_raw ) ),
			'exposure_bias'   => sanitize_text_field( $this->extract_exposure_bias( $exif_raw ) ),
			'software'        => sanitize_text_field( trim( (string) ( $exif_raw['IFD0']['Software'] ?? '' ) ) ),
			'captured_at'     => sanitize_text_field( $this->extract_date( $exif_raw ) ),
			'gps'             => $this->extract_gps( $exif_raw ),
			'captured_sort'   => sanitize_text_field( $this->extract_sort_key( $exif_raw ) ),
		];

		update_post_meta( $attachment_id, '_bazantik_exif_data', $exif );

		return $metadata;
	}

	/**
	 * PHP umí číst EXIF jen z JPEG/TIFF; z HEIC se EXIF blok vytáhne přes Imagick a přečte se jako TIFF.
	 *
	 * @return array<string, mixed>
	 */
	private function read_heic_exif( string $path ): array {
		if ( ! class_exists( '\Imagick' ) ) {
			return [];
		}
		$blob = '';
		try {
			$im = new \Imagick();
			$im->pingImage( $path );
			$blob = (string) $im->getImageProfile( 'exif' );
			$im->clear();
			$im->destroy();
		} catch ( \Throwable $e ) {
			return [];
		}
		$start = false;
		foreach ( [ "II*\0", "MM\0*" ] as $magic ) {
			$pos = strpos( $blob, $magic );
			if ( false !== $pos && ( false === $start || $pos < $start ) ) {
				$start = $pos;
			}
		}
		if ( false === $start ) {
			return [];
		}
		$stream = fopen( 'php://temp', 'r+' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- paměťový proud, ne soubor na disku
		if ( false === $stream ) {
			return [];
		}
		fwrite( $stream, substr( $blob, $start ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- paměťový proud
		rewind( $stream );
		$raw = @exif_read_data( $stream, 'ANY_TAG', true );
		fclose( $stream ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- paměťový proud
		return is_array( $raw ) ? $raw : [];
	}

	private function extract_camera( array $raw ): string {
		$make  = trim( (string) ( $raw['IFD0']['Make'] ?? '' ) );
		$model = trim( (string) ( $raw['IFD0']['Model'] ?? '' ) );

		// Očištění výrobce od korporátních přípon
		$clean_make = preg_replace( '/\b(CORPORATION|CORP\.?|CO\.?,?\s*LTD\.?|INC\.?|AG|GMBH)\b/i', '', $make );
		$clean_make = trim( preg_replace( '/\s+/', ' ', $clean_make ) );

		$clean_model = trim( preg_replace( '/\s+/', ' ', $model ) );

		if ( empty( $clean_make ) ) return $clean_model;
		if ( empty( $clean_model ) ) return $clean_make;

		// Pokud model již obsahuje značku (např. Model = "NIKON Z 5", Make = "Nikon"), neduplikujeme
		if ( stripos( $clean_model, $clean_make ) !== false ) {
			return $clean_model;
		}

		// Pokud v modelu značka není (např. Make = "Canon", Model = "EOS R5"), spojíme je
		return $clean_make . ' ' . $clean_model;
	}

	private function extract_lens( array $raw ): string {
		return trim( (string) ( $raw['EXIF']['UndefinedTag:0xA434'] ?? $raw['EXIF']['LensModel'] ?? '' ) );
	}

	private function extract_focal_length( array $raw ): string {
		if ( ! empty( $raw['EXIF']['FocalLength'] ) ) {
			$fl = $raw['EXIF']['FocalLength'];
			if ( is_string( $fl ) && str_contains( $fl, '/' ) ) {
				[ $num, $den ] = explode( '/', $fl );
				if ( (float) $den > 0 ) {
					return round( (float) $num / (float) $den, 1 ) . ' mm';
				}
			}
			return (string) $fl . ' mm';
		}
		return '';
	}

	private function extract_aperture( array $raw ): string {
		if ( ! empty( $raw['EXIF']['FNumber'] ) ) {
			$fn = $raw['EXIF']['FNumber'];
			if ( is_string( $fn ) && str_contains( $fn, '/' ) ) {
				[ $num, $den ] = explode( '/', $fn );
				if ( (float) $den > 0 ) {
					return 'f/' . round( (float) $num / (float) $den, 1 );
				}
			}
			return 'f/' . $fn;
		}
		return '';
	}

	private function extract_shutter_speed( array $raw ): string {
		if ( ! empty( $raw['EXIF']['ExposureTime'] ) ) {
			$et = $raw['EXIF']['ExposureTime'];
			if ( is_string( $et ) && str_contains( $et, '/' ) ) {
				[ $num, $den ] = array_pad( explode( '/', $et, 2 ), 2, '0' );
				// Čitatel 1 je už ve tvaru 1/x; ostatní zlomky (např. 30000030/1000000000 z Androidu) se přepočtou
				if ( '1' === trim( $num ) ) {
					return $et . ' s';
				}
				if ( (float) $den <= 0 ) {
					return '';
				}
				$et = (float) $num / (float) $den;
			}
			$val = (float) $et;
			if ( $val > 0 && $val < 1 ) {
				return '1/' . round( 1 / $val ) . ' s';
			}
			return round( $val, 1 ) . ' s';
		}
		return '';
	}

	private function extract_iso( array $raw ): string {
		return (string) ( $raw['EXIF']['ISOSpeedRatings'] ?? '' );
	}

	private function extract_exposure_bias( array $raw ): string {
		if ( isset( $raw['EXIF']['ExposureBiasValue'] ) ) {
			$ev = $raw['EXIF']['ExposureBiasValue'];
			if ( is_string( $ev ) && str_contains( $ev, '/' ) ) {
				[ $num, $den ] = explode( '/', $ev );
				if ( (float) $den > 0 ) {
					$val = round( (float) $num / (float) $den, 1 );
					return ( $val > 0 ? '+' : '' ) . $val . ' EV';
				}
			}
			$val = round( (float) $ev, 1 );
			return ( $val > 0 ? '+' : '' ) . $val . ' EV';
		}
		return '';
	}

	private function extract_date( array $raw ): string {
		$raw_date = trim( (string) ( $raw['EXIF']['DateTimeOriginal'] ?? $raw['IFD0']['DateTime'] ?? '' ) );
		if ( ! empty( $raw_date ) ) {
			if ( preg_match( '/^(\d{4})[:\.\-](\d{2})[:\.\-](\d{2})\s*(\d{2}:\d{2})?/', $raw_date, $m ) ) {
				// Neplatné datum (např. 0000:00:00 z fotoaparátu bez nastavených hodin) se nezobrazuje
				if ( ! checkdate( (int) $m[2], (int) $m[3], (int) $m[1] ) ) {
					return '';
				}
				// Jazykově neutrální tvar; na stránce se formátuje podle Nastavení WordPressu.
				return "{$m[1]}:{$m[2]}:{$m[3]}" . ( ! empty( $m[4] ) ? " {$m[4]}" : '' );
			}
		}
		return $raw_date;
	}

		/**
		 * Řadicí klíč data pořízení "RRRR-MM-DD HH:MM:SS.mmm" (řetězcově řaditelný, včetně zlomků sekund pro série snímků).
		 */
	private function extract_sort_key( array $raw ): string {
		$raw_date = trim( (string) ( $raw['EXIF']['DateTimeOriginal'] ?? $raw['IFD0']['DateTime'] ?? '' ) );
		if ( ! preg_match( '/^(\d{4})[:\.\-](\d{2})[:\.\-](\d{2})\s+(\d{2}):(\d{2}):(\d{2})/', $raw_date, $m ) ) {
			return '';
		}
		if ( ! checkdate( (int) $m[2], (int) $m[3], (int) $m[1] ) || (int) $m[4] > 23 || (int) $m[5] > 59 || (int) $m[6] > 59 ) {
			return '';
		}

		$subsec = preg_replace( '/\D/', '', (string) ( $raw['EXIF']['SubSecTimeOriginal'] ?? '' ) );
		$subsec = str_pad( substr( (string) $subsec, 0, 3 ), 3, '0' );

		return "{$m[1]}-{$m[2]}-{$m[3]} {$m[4]}:{$m[5]}:{$m[6]}.{$subsec}";
	}

	private function extract_gps( array $raw ): ?array {
		if ( empty( $raw['GPS']['GPSLatitude'] ) || empty( $raw['GPS']['GPSLongitude'] ) ) {
			return null;
		}

		// Poškozená data v EXIFu (pole chybného typu, nesmyslné hodnoty) nesmí shodit zpracování fotky
		try {
			$lat = $this->convert_gps_to_decimal(
				(array) $raw['GPS']['GPSLatitude'],
				(string) ( $raw['GPS']['GPSLatitudeRef'] ?? 'N' )
			);
			$lng = $this->convert_gps_to_decimal(
				(array) $raw['GPS']['GPSLongitude'],
				(string) ( $raw['GPS']['GPSLongitudeRef'] ?? 'E' )
			);
		} catch ( \Throwable $e ) {
			return null;
		}

		if ( null === $lat || null === $lng || abs( $lat ) > 90 || abs( $lng ) > 180 ) {
			return null;
		}
		// Souřadnice 0/0 dodávají zařízení, která polohu odstranila (např. výběr z Fotek na Androidu); nejde o skutečné místo
		if ( 0.0 === (float) $lat && 0.0 === (float) $lng ) {
			return null;
		}
		return [ 'lat' => $lat, 'lng' => $lng ];
	}

	private function convert_gps_to_decimal( array $coords, string $ref ): ?float {
		if ( count( $coords ) < 3 ) {
			return null;
		}

		$calc = function ( string $part ): float {
			if ( str_contains( $part, '/' ) ) {
				$frac = explode( '/', $part );
				[ $num, $den ] = [ $frac[0], $frac[1] ?? '0' ];
				return (float) $den > 0 ? (float) $num / (float) $den : 0.0;
			}
			return (float) $part;
		};

			$degrees = $calc( (string) $coords[0] );
			$minutes = $calc( (string) $coords[1] );
			$seconds = $calc( (string) $coords[2] );

			$decimal = $degrees + ( $minutes / 60 ) + ( $seconds / 3600 );
			return in_array( strtoupper( $ref ), [ 'S', 'W' ], true ) ? -$decimal : $decimal;
	}
}
