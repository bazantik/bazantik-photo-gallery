<?php
declare(strict_types=1);

namespace BazantikPhotoGallery;

defined( 'ABSPATH' ) || exit;

/**
 * Návrh textu pro zásady ochrany osobních údajů (Settings → Privacy → Policy Guide).
 */
class Privacy {
	public function init(): void {
		add_action( 'admin_init', [ $this, 'add_policy_content' ] );
	}

	public function add_policy_content(): void {
		if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
			return;
		}

		$html  = '<h2>' . esc_html__( 'Photo gallery (Bazantik Photo Gallery)', 'bazantik-photo-gallery' ) . '</h2>';
		$html .= '<p class="privacy-policy-tutorial">' . esc_html__( 'The text below is a suggestion. Adjust it to the settings of your site.', 'bazantik-photo-gallery' ) . '</p>';
		$html .= '<p>' . esc_html__( 'The gallery does not track visitors and does not set analytics or advertising cookies.', 'bazantik-photo-gallery' ) . '</p>';
		$html .= '<p>' . esc_html__( 'After an album or folder protected by a password is unlocked, or a hidden folder is opened with its secret link, a signed cookie (valid for 10 days) is stored in the browser to keep it unlocked. If limiting password attempts is turned on, only a hashed fingerprint of the visitor\'s IP address is stored for 10 minutes by default (adjustable in Settings). The gallery\'s browser settings (slideshow speed, panel state, sorting, map consent) stay in the browser\'s storage and are not sent to the server.', 'bazantik-photo-gallery' ) . '</p>';
		$html .= '<p>' . esc_html__( 'If we show a map for an album, the map background is loaded from the map provider (OpenStreetMap by default) only after the visitor clicks “Show map”. The provider thereby receives the visitor\'s IP address.', 'bazantik-photo-gallery' ) . '</p>';
		$html .= '<p>' . esc_html__( 'Photos can contain metadata (EXIF), including the place where they were taken. For albums without the map turned on, coordinates are not shown on the page and the Download button removes them from JPEG files.', 'bazantik-photo-gallery' ) . '</p>';

		wp_add_privacy_policy_content( 'Bazantik Photo Gallery', wp_kses_post( $html ) );
	}
}
