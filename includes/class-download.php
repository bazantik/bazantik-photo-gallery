<?php
declare(strict_types=1);

namespace BazantikPhotoGallery;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.WP.AlternativeFunctions -- streamování a čtení hlavičky velkého souboru po částech

/**
 * Tlačítko Stáhnout u alba bez mapy: originál JPEG se odešle s vynulovanými GPS souřadnicemi.
 *
 * Soubor se neukládá ani nepřekládá: v hlavičce JPEG (před daty obrazu) se na místě přepíšou GPS údaje
 * ve stejné délce (EXIF GPS IFD, XMP), takže zůstanou platné všechny offsety a zbytek souboru se jen streamuje.
 * Originál otevřený přímým odkazem (např. při zoomu) zůstává beze změny.
 */
class Download {
	private const MAX_HEAD = 2097152;

	public function init(): void {
		add_action( 'template_redirect', [ $this, 'maybe_serve' ], 1 );
	}

	public static function url( int $album_id, int $attachment_id ): string {
		return add_query_arg(
			[
				'bazantik_download' => $attachment_id,
				'bazantik_album'    => $album_id,
			],
			home_url( '/' )
		);
	}

	public function maybe_serve(): void {
		if ( ! isset( $_GET['bazantik_download'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- veřejné stažení, přístup řeší heslo nebo tajný klíč alba
			return;
		}

		$attachment_id = absint( $_GET['bazantik_download'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$album_id      = absint( $_GET['bazantik_album'] ?? 0 ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		if ( ! $attachment_id || ! $album_id || 'bazantik_album' !== get_post_type( $album_id ) || 'attachment' !== get_post_type( $attachment_id ) ) {
			$this->fail( 404 );
		}

		if ( ! current_user_can( 'edit_post', $album_id ) && ! Shortcodes_Blocks::visitor_has_access( $album_id ) ) {
			$this->fail( 404 );
		}

		$album_media = array_map( 'absint', explode( ',', (string) get_post_meta( $album_id, '_bazantik_media_ids', true ) ) );
		if ( ! in_array( $attachment_id, $album_media, true ) || 'image/jpeg' !== get_post_mime_type( $attachment_id ) ) {
			$this->fail( 404 );
		}

		$path = function_exists( 'wp_get_original_image_path' ) ? (string) wp_get_original_image_path( $attachment_id ) : '';
		if ( '' === $path ) {
			$path = (string) get_attached_file( $attachment_id );
		}

		$uploads = wp_get_upload_dir();
		$base    = realpath( (string) $uploads['basedir'] );
		$real    = false;

		// Originál nemusí být JPEG (např. zachovaný .heic po převodu); pak se použije hlavní soubor přílohy, pokud je to JPEG
		$candidates = [ $path, (string) get_attached_file( $attachment_id ) ];
		foreach ( $candidates as $candidate ) {
			$resolved = '' !== $candidate ? realpath( $candidate ) : false;
			if ( false !== $resolved && false !== $base && 0 === strpos( $resolved, $base . DIRECTORY_SEPARATOR ) && is_file( $resolved ) && self::is_jpeg_file( $resolved ) ) {
				$real = $resolved;
				break;
			}
		}
		if ( false === $real ) {
			$this->fail( 404 );
		}

		$fh = fopen( $real, 'rb' );
		if ( false === $fh ) {
			$this->fail( 404 );
		}

		$head = self::read_scrubbed_head( $fh );
		if ( null === $head ) {
			fclose( $fh );
			$this->fail( 500 );
		}

		$size = (int) filesize( $real );

		// Převedený JPEG z HEIC nemá EXIF: doplní se z původního HEIC (GPS se odstraní stejně jako u ostatních fotek)
		$extra = '';
		if ( 0 !== strpos( $head, "\xFF\xD8\xFF\xE1" ) && false === strpos( substr( $head, 0, 65536 ), "Exif\0\0" ) ) {
			$heic = self::find_heic_source( $path, $real );
			if ( '' !== $heic ) {
				// U alba s mapou je poloha návštěvníkům stejně dostupná, proto se ve staženém souboru ponechá
				$extra = self::heic_exif_segment( $heic, Settings::album_shows_map( $album_id ) );
				$size += strlen( $extra );
			}
		}
		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', true );
		}
		nocache_headers();
		while ( ob_get_level() > 0 ) {
			ob_end_clean();
		}
		// Komprese výstupu by změnila skutečnou délku odpovědi proti hlavičce Content-Length; velký soubor může trvat déle než výchozí limit
		@ini_set( 'zlib.output_compression', '0' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.PHP.IniSet.Risky
		@set_time_limit( 0 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged

		status_header( 200 );
		header( 'Content-Type: image/jpeg' );
		header( 'Content-Length: ' . $size );
		header( 'Content-Disposition: attachment; filename="' . self::header_filename( $attachment_id, $real, true ) . '"; filename*=UTF-8\'\'' . rawurlencode( self::header_filename( $attachment_id, $real, false ) ) );
		header( 'X-Content-Type-Options: nosniff' );

		echo substr( $head, 0, 2 ) . $extra . substr( $head, 2 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- binární data obrázku
		fseek( $fh, strlen( $head ) );
		while ( ! feof( $fh ) ) {
			$chunk = fread( $fh, 1048576 );
			if ( false === $chunk || '' === $chunk ) {
				break;
			}
			echo $chunk; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- binární data obrázku
			flush();
		}
		fclose( $fh );
		exit;
	}

	/**
	 * Má fotka vedle sebe původní HEIC? Výsledek se uloží do meta (0/1), aby se soubory nekontrolovaly při každém zobrazení.
	 */
	public static function has_heic_source( int $attachment_id ): bool {
		$flag = (string) get_post_meta( $attachment_id, '_bazantik_heic_src', true );
		if ( '' === $flag ) {
			$path = function_exists( 'wp_get_original_image_path' ) ? (string) wp_get_original_image_path( $attachment_id ) : '';
			if ( '' === $path ) {
				$path = (string) get_attached_file( $attachment_id );
			}
			$flag = '' !== $path && '' !== self::find_heic_source( $path, $path ) ? '1' : '0';
			update_post_meta( $attachment_id, '_bazantik_heic_src', $flag );
		}
		return '1' === $flag;
	}

	/**
	 * Zdrojový HEIC převedeného JPEG: buď je stále původním souborem přílohy, nebo (po zpracování, kdy se původním souborem stane JPEG) leží vedle se stejným základem názvu.
	 */
	private static function find_heic_source( string $path, string $jpeg ): string {
		if ( preg_match( '/\.hei[cf]$/i', $path ) && is_file( $path ) ) {
			return $path;
		}
		$stem = (string) preg_replace( '/(-scaled)?\.[^.\/]+$/', '', $jpeg );
		foreach ( [ 'heic', 'HEIC', 'heif', 'HEIF' ] as $ext ) {
			if ( is_file( $stem . '.' . $ext ) ) {
				return $stem . '.' . $ext;
			}
		}
		return '';
	}

	/**
	 * Segment APP1 s EXIF z původního HEIC (bez GPS, orientace 1, protože převedené pixely už jsou otočené). Prázdný řetězec = nelze.
	 */
	private static function heic_exif_segment( string $heic, bool $keep_gps = false ): string {
		if ( ! class_exists( '\Imagick' ) ) {
			return '';
		}
		$blob = '';
		try {
			$im = new \Imagick();
			$im->pingImage( $heic );
			$blob = (string) $im->getImageProfile( 'exif' );
			$im->clear();
			$im->destroy();
		} catch ( \Throwable $e ) {
			return '';
		}
		$start = false;
		foreach ( [ "II*\0", "MM\0*" ] as $magic ) {
			$pos = strpos( $blob, $magic );
			if ( false !== $pos && ( false === $start || $pos < $start ) ) {
				$start = $pos;
			}
		}
		if ( false === $start ) {
			return '';
		}
		$tiff = substr( $blob, $start );
		$le   = 'II' === substr( $tiff, 0, 2 );
		if ( strlen( $tiff ) < 16 ) {
			return '';
		}
		$ifd0 = (int) unpack( $le ? 'V' : 'N', substr( $tiff, 4, 4 ) )[1];
		if ( $ifd0 + 2 > strlen( $tiff ) ) {
			return '';
		}
		$count = (int) unpack( $le ? 'v' : 'n', substr( $tiff, $ifd0, 2 ) )[1];
		for ( $i = 0; $i < $count && $ifd0 + 2 + 12 * ( $i + 1 ) <= strlen( $tiff ); $i++ ) {
			$e = $ifd0 + 2 + 12 * $i;
			if ( 0x0112 === (int) unpack( $le ? 'v' : 'n', substr( $tiff, $e, 2 ) )[1] ) {
				$tiff = substr_replace( $tiff, pack( $le ? 'v' : 'n', 1 ), $e + 8, 2 );
				break;
			}
		}
		$payload = $keep_gps ? "Exif\0\0" . $tiff : self::scrub_exif( "Exif\0\0" . $tiff );
		if ( null === $payload || strlen( $payload ) > 65533 ) {
			return '';
		}
		return "\xFF\xE1" . pack( 'n', strlen( $payload ) + 2 ) . $payload;
	}

	private static function is_jpeg_file( string $file ): bool {
		$fh = fopen( $file, 'rb' );
		if ( false === $fh ) {
			return false;
		}
		$sig = fread( $fh, 2 );
		fclose( $fh );
		return "\xFF\xD8" === $sig;
	}

	private function fail( int $status ): void {
		nocache_headers();
		if ( 404 === $status ) {
			// Stejná stránka 404 jako u ostatních neveřejných adres (návštěvník nevidí prázdnou stránku)
			global $wp_query;
			$wp_query->set_404();
			status_header( 404 );
			include get_query_template( '404' );
			exit;
		}
		status_header( $status );
		exit;
	}

	private static function header_filename( int $attachment_id, string $path, bool $ascii ): string {
		$title = (string) get_post_field( 'post_title', $attachment_id );
		$ext   = (string) pathinfo( $path, PATHINFO_EXTENSION );
		$name  = '' !== trim( $title ) ? $title . ( '' !== $ext ? '.' . $ext : '' ) : wp_basename( $path );
		$name  = (string) preg_replace( '/[\x00-\x1F\x7F"\\\\\/]+/u', '', $name );
		if ( $ascii ) {
			$name = (string) preg_replace( '/[^\x20-\x7E]+/', '_', $name );
		}
		return '' !== $name ? $name : 'photo.jpg';
	}

	/**
	 * Přečte hlavičku JPEG (vše před daty obrazu) a vrátí ji se stejně dlouhými, ale vyčištěnými GPS údaji.
	 * Vrací null, když soubor nejde bezpečně zpracovat (GPS se pak raději neodešle vůbec).
	 *
	 * @param resource $fh
	 */
	public static function read_scrubbed_head( $fh ): ?string {
		rewind( $fh );
		if ( "\xFF\xD8" !== fread( $fh, 2 ) ) {
			return null;
		}

		$pos      = 2;
		$segments = [];
		while ( $pos < self::MAX_HEAD ) {
			fseek( $fh, $pos );
			$h = (string) fread( $fh, 4 );
			if ( strlen( $h ) < 2 || "\xFF" !== $h[0] ) {
				return null;
			}
			$marker = ord( $h[1] );
			if ( 0xFF === $marker ) {
				++$pos;
				continue;
			}
			if ( 0xDA === $marker || 0xD9 === $marker ) {
				break;
			}
			if ( 0x01 === $marker || ( $marker >= 0xD0 && $marker <= 0xD8 ) ) {
				$pos += 2;
				continue;
			}
			if ( strlen( $h ) < 4 ) {
				return null;
			}
			$len = ( ord( $h[2] ) << 8 ) | ord( $h[3] );
			if ( $len < 2 ) {
				return null;
			}
			if ( 0xE1 === $marker && $len > 2 ) {
				$segments[] = [ $pos + 4, $len - 2 ];
			}
			$pos += 2 + $len;
		}
		if ( $pos >= self::MAX_HEAD ) {
			return null;
		}

		rewind( $fh );
		$head = (string) fread( $fh, $pos );
		if ( strlen( $head ) !== $pos ) {
			return null;
		}

		foreach ( $segments as [ $offset, $length ] ) {
			$payload = substr( $head, $offset, $length );
			$clean   = self::scrub_payload( $payload );
			if ( null === $clean ) {
				return null;
			}
			if ( $clean !== $payload ) {
				$head = substr_replace( $head, $clean, $offset, $length );
			}
		}

		return $head;
	}

	private static function scrub_payload( string $payload ): ?string {
		if ( 0 === strpos( $payload, "Exif\0\0" ) ) {
			return self::scrub_exif( $payload );
		}

		$xmp = "http://ns.adobe.com/xap/1.0/\0";
		if ( 0 === strpos( $payload, $xmp ) ) {
			// Balíček XMP může být i v UTF-16 (znaky prokládané nulami), proto se vzor hledá v textu bez nul
			if ( ! preg_match( '/GPS(?:Latitude|Longitude|Altitude|Position|Version)|exif:GPS|GPSCoordinates/i', str_replace( "\0", '', $payload ) ) ) {
				return $payload;
			}
			$packet = '<?xpacket begin="' . "\xEF\xBB\xBF" . '" id="W5M0MpCehiHzreSzNTczkc9d"?><x:xmpmeta xmlns:x="adobe:ns:meta/"><rdf:RDF xmlns:rdf="http://www.w3.org/1999/02/22-rdf-syntax-ns#"/></x:xmpmeta>';
			$tail   = '<?xpacket end="w"?>';
			$room   = strlen( $payload ) - strlen( $xmp );
			if ( $room < strlen( $packet ) + strlen( $tail ) ) {
				return $xmp . str_repeat( ' ', $room );
			}
			return $xmp . $packet . str_repeat( ' ', $room - strlen( $packet ) - strlen( $tail ) ) . $tail;
		}

		$ext = "http://ns.adobe.com/xmp/extension/\0";
		if ( 0 === strpos( $payload, $ext ) ) {
			return $ext . str_repeat( ' ', strlen( $payload ) - strlen( $ext ) );
		}

		return $payload;
	}

	/**
	 * Vynuluje GPS IFD (tag 0x8825 v IFD0) a jeho hodnoty uložené mimo záznam; velikost a offsety se nemění.
	 */
	private static function scrub_exif( string $payload ): ?string {
		$tiff = substr( $payload, 6 );
		$len  = strlen( $tiff );
		if ( $len < 8 ) {
			return null;
		}

		$order = substr( $tiff, 0, 2 );
		if ( 'II' !== $order && 'MM' !== $order ) {
			return null;
		}
		$le  = 'II' === $order;
		$u16 = static fn( string $s, int $o ): int => (int) unpack( $le ? 'v' : 'n', substr( $s, $o, 2 ) )[1];
		$u32 = static fn( string $s, int $o ): int => (int) unpack( $le ? 'V' : 'N', substr( $s, $o, 4 ) )[1];
		$w16 = static fn( int $v ): string => pack( $le ? 'v' : 'n', $v );

		if ( 42 !== $u16( $tiff, 2 ) ) {
			return null;
		}

		$ifd0 = $u32( $tiff, 4 );
		if ( $ifd0 + 2 > $len ) {
			return null;
		}
		$count = $u16( $tiff, $ifd0 );
		if ( $ifd0 + 2 + 12 * $count > $len ) {
			return null;
		}

		$gps = 0;
		for ( $i = 0; $i < $count; $i++ ) {
			$e = $ifd0 + 2 + 12 * $i;
			if ( 0x8825 === $u16( $tiff, $e ) ) {
				$gps = $u32( $tiff, $e + 8 );
				break;
			}
		}
		if ( 0 === $gps ) {
			return $payload;
		}

		if ( $gps + 2 > $len ) {
			return null;
		}
		$n = $u16( $tiff, $gps );
		if ( $gps + 2 + 12 * $n + 4 > $len ) {
			return null;
		}

		$sizes = [ 1 => 1, 2 => 1, 3 => 2, 4 => 4, 5 => 8, 6 => 1, 7 => 1, 8 => 2, 9 => 4, 10 => 8, 11 => 4, 12 => 8 ];
		for ( $i = 0; $i < $n; $i++ ) {
			$e     = $gps + 2 + 12 * $i;
			$type  = $u16( $tiff, $e + 2 );
			$total = ( $sizes[ $type ] ?? 1 ) * $u32( $tiff, $e + 4 );
			if ( $total > 4 ) {
				$off = $u32( $tiff, $e + 8 );
				if ( $off + $total > $len ) {
					return null;
				}
				$tiff = substr_replace( $tiff, str_repeat( "\0", $total ), $off, $total );
			}
		}

		$tiff = substr_replace( $tiff, $w16( 0 ) . str_repeat( "\0", 12 * $n + 4 ), $gps, 2 + 12 * $n + 4 );

		return "Exif\0\0" . $tiff;
	}
}
