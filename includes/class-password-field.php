<?php
declare(strict_types=1);

namespace BazantikPhotoGallery;

defined( 'ABSPATH' ) || exit;

/**
 * Pole pro heslo (type="password") s tlačítkem „oko“ uvnitř pole. Používá se v administraci i na webu
 * (odemykání složek a alb), protože prohlížeče takové tlačítko samy nenabízejí (Safari a Chrome vůbec, Edge jen vlastní).
 */
class Password_Field {

	/**
	 * Odpověď formuláře pro odemknutí: skript na webu si vyžádá adresu (JSON) a stránku nahradí v historii prohlížeče,
	 * takže tlačítko Zpět nevrací na formulář. Bez JavaScriptu se použije běžné přesměrování.
	 */
	public static function respond( string $url ): void {
		if ( ! empty( $_POST['bazantik_ajax'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- mění se jen tvar odpovědi, ověření proběhlo dříve
			wp_send_json( [ 'url' => esc_url_raw( $url ) ] );
		}
		wp_safe_redirect( $url );
		exit;
	}

	public static function enqueue(): void {
		wp_enqueue_style( 'bazantik-password-field', BAZANTIK_PLUGIN_URL . 'assets/css/password-field.css', [], BAZANTIK_VERSION );
		wp_enqueue_script( 'bazantik-password-field', BAZANTIK_PLUGIN_URL . 'assets/js/password-field.js', [], BAZANTIK_VERSION, true );
		static $localized = false;
		if ( is_admin() && ! $localized ) {
			$localized = true;
			wp_localize_script(
				'bazantik-password-field',
				'bazantikPwReveal',
				[
					'url'   => admin_url( 'admin-ajax.php' ),
					'nonce' => Password_Reveal::nonce(),
					'error' => __( 'The password could not be loaded.', 'bazantik-photo-gallery' ),
					'albumRequired' => __( 'A password-protected album must have a password. Enter a password or choose a different privacy setting.', 'bazantik-photo-gallery' ),
				]
			);
		}
	}

	/** Nejdelší heslo, které se vejde do sloupce post_password. */
	public const MAX_LENGTH = 255;

	public static function too_long( string $password ): bool {
		return mb_strlen( $password, 'UTF-8' ) > self::MAX_LENGTH;
	}

	/**
	 * Pole s heslem a tlačítkem oko. $args: id, class, value, placeholder, autocomplete, required (bool), block (bool: pole na celou šířku),
	 * reveal (array{type: string, id: int}: uložené heslo se po kliknutí na oko načte přes AJAX; k poli přibude skrytá položka
	 * „<name>_changed“, kterou skript přepne na 1, jakmile uživatel heslo sám upraví).
	 *
	 * @param array<string,mixed> $args
	 */
	public static function input( string $name, array $args = [] ): string {
		$autocomplete = (string) ( $args['autocomplete'] ?? 'new-password' );
		// Pole pro nastavení hesla (administrace) je obyčejný text zakrytý přes CSS: Safari a další správce hesel pak nenabízejí uložení hesla
		$masked = 'new-password' === $autocomplete;
		$attrs  = ( $masked ? ' type="text" data-bazantik-mask="1"' : ' type="password"' ) . ' name="' . esc_attr( $name ) . '" maxlength="' . self::MAX_LENGTH . '"';
		if ( ! empty( $args['id'] ) ) {
			$attrs .= ' id="' . esc_attr( (string) $args['id'] ) . '"';
		}
		if ( ! empty( $args['class'] ) || $masked ) {
			$attrs .= ' class="' . esc_attr( trim( (string) ( $args['class'] ?? '' ) . ( $masked ? ' bazantik-pw-masked' : '' ) ) ) . '"';
		}
		if ( isset( $args['value'] ) && '' !== $args['value'] ) {
			$attrs .= ' value="' . esc_attr( (string) $args['value'] ) . '"';
		}
		if ( ! empty( $args['placeholder'] ) ) {
			$attrs .= ' placeholder="' . esc_attr( (string) $args['placeholder'] ) . '"';
		}
		if ( ! empty( $args['required'] ) ) {
			$attrs .= ' required';
		}
		if ( $masked ) {
			$attrs .= ' autocomplete="off" autocapitalize="off" autocorrect="off" spellcheck="false" data-1p-ignore data-lpignore="true" data-bwignore data-form-type="other"';
		} else {
			$attrs .= ' autocomplete="' . esc_attr( $autocomplete ) . '"';
		}

		$extra = '';
		if ( ! empty( $args['reveal'] ) && is_array( $args['reveal'] ) ) {
			$attrs .= ' data-bazantik-reveal="' . esc_attr( (string) ( $args['reveal']['type'] ?? '' ) ) . '" data-bazantik-id="' . absint( $args['reveal']['id'] ?? 0 ) . '"';
		}
		if ( isset( $args['reveal'] ) ) {
			$extra = '<input type="hidden" name="' . esc_attr( $name ) . '_changed" value="0" class="bazantik-pw-changed">';
		}

		return self::wrap( '<input' . $attrs . '>', ! empty( $args['block'] ) ) . $extra;
	}

	/** Obalí hotové pole typu password a přidá tlačítko oko. */
	public static function wrap( string $input_html, bool $block = false ): string {
		self::enqueue();

		$label = esc_attr__( 'Show password', 'bazantik-photo-gallery' );
		$eye   = '<svg class="bazantik-pw-eye" viewBox="0 0 796 693" aria-hidden="true" focusable="false"><path d="M372.8 38.4C370.9 38.7 363.7 39.4 356.8 40.0C349.9 40.7 336.7 42.6 327.3 44.3C200.7 67.1 90.6 149.7 27.6 269.0C7.4 307.4 1.1 328.0 0.1 359.0C-0.9 390.3 3.7 410.7 19.2 443.4C64.0 538.4 139.7 613.8 232.3 655.7C264.3 670.3 301.1 681.3 334.3 686.5C360.2 690.5 369.4 691.2 398.3 691.2C428.3 691.2 441.8 690.1 467.9 685.4C599.5 661.9 714.3 572.7 774.3 447.4C790.5 413.5 794.8 398.3 795.5 370.1C796.4 339.6 792.0 319.7 777.6 288.8C711.2 145.7 578.0 50.7 427.3 38.9C414.5 37.9 381.2 37.7 372.8 38.4ZM438.8 122.2C455.7 124.7 466.8 126.8 480.3 130.4C579.5 156.5 662.8 227.3 707.9 323.9C716.7 342.7 718.8 350.5 718.8 364.5C718.8 380.0 716.3 388.7 705.8 410.1C666.1 490.9 602.2 551.9 522.5 585.0C428.8 624.0 322.2 616.7 233.3 565.4C169.4 528.5 117.0 470.6 86.4 402.9C79.2 387.0 76.8 377.6 76.8 364.9C76.8 350.4 79.1 341.8 88.2 322.6C128.9 236.3 201.2 169.8 287.3 139.2C317.1 128.6 341.2 123.4 375.8 120.2C385.6 119.2 427.8 120.6 438.8 122.2Z"/><path d="M381.8 201.9C343.6 207.0 312.4 223.6 286.3 252.4C257.3 284.4 242.2 327.1 244.1 371.6C245.2 395.6 249.4 413.6 258.8 434.6C267.1 453.0 275.2 465.1 289.3 480.0C323.9 516.8 369.8 533.5 418.0 526.7C452.1 521.9 481.5 506.4 506.4 480.0C528.6 456.4 542.2 429.8 549.0 396.2C552.2 380.5 552.2 348.9 548.9 333.0C542.2 299.5 528.9 273.4 506.4 249.5C483.7 225.5 459.4 211.5 428.3 204.5C418.5 202.3 390.7 200.8 381.8 201.9ZM417.3 285.8C444.0 293.5 465.0 315.9 472.4 344.7C474.9 354.3 474.9 374.5 472.4 384.5C466.4 408.7 450.5 429.0 430.0 438.8C385.9 459.9 336.8 435.1 323.2 385.0C320.7 375.6 320.7 354.4 323.2 344.6C326.3 332.3 333.1 319.3 341.3 309.8C357.7 291.1 377.3 282.5 400.8 283.6C406.6 283.8 414.0 284.9 417.3 285.8Z"/></svg>';
		$off   = '<svg class="bazantik-pw-eye-off" viewBox="0 0 796 693" aria-hidden="true" focusable="false"><path d="M694.9 1.0C692.7 1.8 688.9 3.4 686.4 4.7C683.1 6.3 598.2 90.7 369.8 319.0C88.6 600.3 57.4 631.8 54.8 637.2C52.1 642.8 51.9 644.2 51.9 654.2C51.9 664.2 52.2 665.8 54.6 670.8C58.2 678.2 65.4 685.2 73.4 689.0C79.3 692.0 80.7 692.2 89.4 692.2C98.1 692.2 99.5 692.0 105.9 689.0C112.3 685.8 116.0 682.5 154.1 644.5L195.4 603.3L212.1 611.5C233.4 621.8 242.4 625.5 264.3 632.8C296.8 643.5 326.8 649.7 361.8 652.8C377.2 654.2 417.6 654.2 433.0 652.8C513.2 645.7 587.4 617.5 650.9 570.0C687.0 543.0 721.3 507.2 746.9 469.8C762.5 447.2 780.4 414.2 786.9 396.3C793.3 378.7 794.9 368.7 794.9 346.2C794.9 318.4 791.9 305.2 779.8 279.8C763.6 245.9 742.7 213.8 718.6 185.8C709.0 174.5 687.3 152.7 675.6 142.4L665.3 133.4L701.2 97.3C740.1 58.2 741.2 56.8 743.2 44.2C745.9 27.2 737.2 10.8 721.4 3.3C716.3 0.9 713.4 0.3 706.9 0.0C702.1 -0.2 697.3 0.2 694.9 1.0ZM620.9 197.2C633.3 207.5 657.6 232.3 666.9 244.0C683.4 264.8 696.0 284.5 707.3 307.4C716.0 324.8 717.4 329.5 718.1 343.2C718.8 357.8 716.8 366.2 708.0 383.8C693.3 413.2 678.9 434.8 657.9 458.8C599.0 526.0 517.1 567.7 428.4 575.8C404.3 577.8 370.9 576.8 345.4 573.2C318.3 569.2 283.7 559.2 258.9 548.3L253.0 545.7L319.3 479.3L328.6 484.0C388.4 513.5 459.3 502.0 505.9 455.2C523.9 437.2 535.5 418.7 543.3 395.2C549.4 377.2 550.8 367.4 550.8 345.8C550.8 329.4 550.5 325.8 548.1 314.8C545.1 300.8 540.7 288.2 534.6 276.2L530.5 268.2L570.2 228.4C592.0 206.7 610.1 188.8 610.4 188.8C610.7 188.8 615.4 192.5 620.9 197.2ZM473.4 334.9C474.8 343.3 474.2 356.2 471.9 365.2C469.7 373.8 462.3 388.3 456.8 394.8C441.0 413.3 420.9 422.8 397.3 422.8C391.5 422.8 386.1 422.7 385.3 422.3C384.6 422.2 382.6 421.7 381.0 421.2C378.1 420.7 379.4 419.2 424.5 374.2C450.0 348.7 471.2 327.8 471.6 327.8C471.9 327.8 472.8 331.0 473.4 334.9Z"/><path d="M378.9 38.8C233.9 44.8 102.1 125.2 29.4 252.0C21.2 266.3 10.2 289.4 7.0 298.8C1.1 316.3 0.0 323.8 0.0 345.8C-0.1 368.2 0.7 373.7 6.5 391.9C11.8 408.5 27.2 438.3 43.1 462.8C63.4 493.8 72.0 500.3 91.2 499.5C101.5 499.2 108.7 496.2 115.7 489.8C124.8 481.5 129.8 466.8 127.4 455.0C125.8 447.5 123.8 443.7 114.4 430.2C104.1 415.7 96.5 403.0 88.4 387.2C79.2 369.0 77.2 362.7 76.7 349.2C75.9 331.7 79.2 321.5 94.7 293.8C146.3 200.9 238.1 136.4 341.9 119.8C362.6 116.7 374.1 115.8 397.8 115.8C421.4 115.8 440.7 117.3 459.2 120.8C467.0 122.3 472.9 122.8 477.7 122.5C499.8 121.0 515.8 100.7 512.2 78.3C510.4 66.9 501.9 55.2 492.0 50.2C481.0 44.8 445.8 39.9 406.4 38.3C401.7 38.2 389.4 38.3 378.9 38.8Z"/></svg>';

		return '<span class="bazantik-pw-wrap' . ( $block ? ' bazantik-pw-block' : '' ) . '">' . $input_html
			. '<button type="button" class="bazantik-pw-toggle" aria-pressed="false" aria-label="' . $label . '" title="' . $label . '">' . $eye . $off . '</button></span>';
	}
}
