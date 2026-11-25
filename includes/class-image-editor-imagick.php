<?php
declare(strict_types=1);

namespace BazantikPhotoGallery;

defined( 'ABSPATH' ) || exit;

// Základní třídy editoru WordPressu; soubor se načte až při prvním použití (viz Media_Optimizer::register_custom_image_editor)
if ( ! class_exists( 'WP_Image_Editor_Imagick' ) ) {
	require_once ABSPATH . WPINC . '/class-wp-image-editor.php';
	require_once ABSPATH . WPINC . '/class-wp-image-editor-imagick.php';
}

/**
 * Rozšířený ImageMagick editor: vynucuje webové vzorkování 4:2:0 a Huffmanovy tabulky (obdoba Apple ImageIO)
 */
class Image_Editor_Imagick extends \WP_Image_Editor_Imagick {
	/** Od kolika pixelů se JPEG čte zmenšeně (běžné fotoaparáty do ~61 MP se nikdy nezmenšují). */
	private const REDUCED_READ_MIN_PIXELS = 64000000;

	/** První chyba editoru od posledního vyzvednutí (WordPress chyby při tvorbě velikostí jinak zahodí). */
	private static string $last_error = '';

	/**
	 * Vrátí a vymaže poslední chybu editoru (pro srozumitelnou hlášku u neúspěšné fotky).
	 */
	public static function take_error(): string {
		$error            = self::$last_error;
		self::$last_error = '';
		return $error;
	}

	/**
	 * @param mixed $result Výsledek operace editoru.
	 * @return mixed
	 */
	private static function note_error( $result ) {
		if ( is_wp_error( $result ) && '' === self::$last_error ) {
			self::$last_error = $result->get_error_message();
		}
		return $result;
	}

	/**
	 * Limit na 1 vlákno se nastaví při každém načtení obrázku (hook wp_image_editor_before_change se volá jen při úpravě obrázku v administraci).
	 */
	public function load() {
		if ( method_exists( 'Imagick', 'setResourceLimit' ) ) {
			try {
				\Imagick::setResourceLimit( \Imagick::RESOURCETYPE_THREAD, 1 );
			} catch ( \Throwable $e ) {}
		}

		if ( ! $this->image instanceof \Imagick && $this->load_reduced() ) {
			return $this->set_quality();
		}

		return self::note_error( parent::load() );
	}

	/**
	 * Obří JPEG (nad ~64 MP) načte rovnou zmenšený díky dekódování DCT (nápověda jpeg:size), takže se nerozbalí
	 * celý do paměti. Zdroj zůstává větší než největší cílová velikost (nikdy se nezvětšuje); originál na disku se nemění.
	 * Při jakékoli pochybnosti vrátí false a načte se běžným způsobem.
	 */
	private function load_reduced(): bool {
		$file = (string) $this->file;
		if ( ! preg_match( '/\.jpe?g$/i', $file ) || ! is_file( $file ) ) {
			return false;
		}

		$size = wp_getimagesize( $file );
		if ( ! is_array( $size ) || 'image/jpeg' !== ( $size['mime'] ?? '' ) ) {
			return false;
		}

		$width  = (int) $size[0];
		$height = (int) $size[1];
		$long   = max( $width, $height );
		if ( $width * $height <= self::REDUCED_READ_MIN_PIXELS ) {
			return false;
		}

		$target = Settings::large_size();
		$hint   = 0.0;

		// ImageMagick zaokrouhluje měřítko na osminy a umí tím zdroj zmenšit pod cíl, proto nápovědu ověříme (ping čte jen hlavičku)
		foreach ( [ 1.0, 1.5, 2.0, 3.0 ] as $factor ) {
			$hint_w = (int) ceil( $target * $factor * $width / $long );
			$hint_h = (int) ceil( $target * $factor * $height / $long );
			try {
				$probe = new \Imagick();
				$probe->setOption( 'jpeg:size', $hint_w . 'x' . $hint_h );
				$probe->pingImage( $file );
				$reduced = max( $probe->getImageWidth(), $probe->getImageHeight() );
				$probe->clear();
				$probe->destroy();
			} catch ( \Throwable $e ) {
				return false;
			}
			if ( $reduced >= $target ) {
				if ( $reduced < $long ) {
					$hint = $factor;
				}
				break;
			}
		}

		if ( $hint <= 0.0 ) {
			return false;
		}

		wp_raise_memory_limit( 'image' );

		$image = null;
		try {
			$image = new \Imagick();
			$image->setOption( 'jpeg:size', ( (int) ceil( $target * $hint * $width / $long ) ) . 'x' . ( (int) ceil( $target * $hint * $height / $long ) ) );
			$image->readImage( $file );

			if ( ! $image->valid() || max( $image->getImageWidth(), $image->getImageHeight() ) < $target ) {
				$image->clear();
				$image->destroy();
				return false;
			}

			if ( is_callable( [ $image, 'setIteratorIndex' ] ) ) {
				$image->setIteratorIndex( 0 );
			}

			$this->image     = $image;
			$this->mime_type = 'image/jpeg';
			$this->update_size();
			return true;
		} catch ( \Throwable $e ) {
			if ( $image instanceof \Imagick ) {
				try {
					$image->clear();
					$image->destroy();
				} catch ( \Throwable $ignored ) {}
			}
			return false;
		}
	}

	public function resize( $max_w, $max_h, $crop = false ) {
		return self::note_error( parent::resize( $max_w, $max_h, $crop ) );
	}

	public function make_subsize( $size_data ) {
		return self::note_error( parent::make_subsize( $size_data ) );
	}

	/**
	 * Vynutí 4:2:0 vzorkování a Huffmanovy tabulky při nastavení kvality
	 */
	public function set_quality( $quality = null, $dims = array() ) {
		$res = parent::set_quality( $quality, $dims );
		if ( $this->image instanceof \Imagick ) {
			try {
				$this->image->setSamplingFactors( [ '2x2', '1x1', '1x1' ] );
				$this->image->setOption( 'jpeg:optimize-coding', 'true' );
			} catch ( \Throwable $e ) {}
		}
		return $res;
	}

	/**
	 * Záchytný bod pro uložení souboru: WP jádro má write_image jako private,
	 * proto nastavení aplikujeme v protected metodě _save(), která má 100% jistotu spuštění.
	 */
	protected function _save( $image, $filename = null, $mime_type = null ) {
		$target = ( $image instanceof \Imagick ) ? $image : ( ( $this->image instanceof \Imagick ) ? $this->image : null );
		if ( $target instanceof \Imagick ) {
			try {
				// 1. Záchrana barevného profilu (ICC) a nekompromisní smazání EXIF/XMP balastu
				if ( is_callable( [ $target, 'getImageProfiles' ] ) && is_callable( [ $target, 'stripImage' ] ) ) {
					$icc_profiles = $target->getImageProfiles( 'icc', true );
					$target->stripImage(); // Smaže EXIF a obří vestavěné náhledy (ušetří MB dat)
					if ( ! empty( $icc_profiles ) ) {
						$target->profileImage( 'icc', $icc_profiles['icc'] );
					}
				}

				// 2. Nastavení specifická pro WebP s optimalizací na sdílené CPU (method 4)
				if ( 'image/webp' === $mime_type ) {
					if ( is_callable( [ $target, 'setOption' ] ) ) {
						$target->setOption( 'webp:method', '4' );
						$target->setOption( 'webp:lossless', 'false' );
					}
				}
				// 3. Fallback pro JPEG záchyt
				elseif ( 'image/jpeg' === $mime_type ) {
					if ( is_callable( [ $target, 'setSamplingFactors' ] ) ) {
						$target->setSamplingFactors( [ '2x2', '1x1', '1x1' ] );
					}
					if ( is_callable( [ $target, 'setOption' ] ) ) {
						$target->setOption( 'jpeg:optimize-coding', 'true' );
					}
					if ( is_callable( [ $target, 'setInterlaceScheme' ] ) ) {
						$target->setInterlaceScheme( \Imagick::INTERLACE_PLANE );
					}
				}
			} catch ( \Throwable $e ) {}
		}
		return self::note_error( parent::_save( $image, $filename, $mime_type ) );
	}
}
