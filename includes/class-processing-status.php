<?php
declare(strict_types=1);

namespace BazantikPhotoGallery;

defined( 'ABSPATH' ) || exit;

/**
 * Stavový model zpracování fotek (pending → processing → ready / failed).
 * Chybějící příznak = hotovo (zpětná kompatibilita se stávajícími fotkami).
 */
final class Processing_Status {
	/**
	 * Zdvořilý worker (volitelný, v Nastavení nebo konstanta BAZANTIK_POLITE_WORKER): dokud přicházejí nahrávané soubory, nezačíná další fotku.
	 */
	public static function wait_for_quiet_uploads( int $max_seconds ): void {
		if ( ! Settings::polite_worker() ) {
			return;
		}

		global $wpdb;

		for ( $i = 0; $i < $max_seconds; $i++ ) {
			$last = (int) $wpdb->get_var(
				$wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", 'bazantik_last_upload_at' )
			);

			if ( ( time() - $last ) >= 10 ) {
				return;
			}

			sleep( 1 );
		}
	}
	public const OPT_PAUSED = 'bazantik_queue_paused';

	/**
	 * Je zpracování fotek na pozadí pozastavené? (záchranná brzda při velkých testech)
	 */
	public static function is_paused(): bool {
		// Dlouhá dávka musí vidět pozastavení nastavené jiným požadavkem, proto obejdeme mezipaměť voleb
		wp_cache_delete( self::OPT_PAUSED, 'options' );
		wp_cache_delete( 'notoptions', 'options' );

		return (bool) get_option( self::OPT_PAUSED, 0 );
	}

	public static function set_paused( bool $paused ): void {
		update_option( self::OPT_PAUSED, $paused ? 1 : 0, false );
	}
	public const META_STATUS   = '_bazantik_proc_status';
	public const META_ATTEMPTS = '_bazantik_proc_attempts';
	public const META_ERROR    = '_bazantik_proc_error';
	public const META_STARTED  = '_bazantik_proc_started';
	public const META_HOLD     = '_bazantik_upload_hold'; // fotka se ještě nahrává (rychlý režim), worker ji nesmí převzít
	public const HOLD_TTL      = 120;                // s – po této době se pojistka ignoruje (nahrávání spadlo)

	public const PENDING    = 'pending';
	public const PROCESSING = 'processing';
	public const READY      = 'ready';
	public const FAILED     = 'failed';

	public static function get( int $attachment_id ): string {
		$status = (string) get_post_meta( $attachment_id, self::META_STATUS, true );
		return '' === $status ? self::READY : $status;
	}

	public static function set( int $attachment_id, string $status ): void {
		if ( ! in_array( $status, [ self::PENDING, self::PROCESSING, self::READY, self::FAILED ], true ) ) {
			return;
		}
		update_post_meta( $attachment_id, self::META_STATUS, $status );
	}

	/**
	 * Použitelné i jako callback pro array_filter (proto netypovaný parametr).
	 */
	public static function is_ready( $attachment_id ): bool {
		return self::READY === self::get( (int) $attachment_id );
	}

	/**
	 * Fotka se smí na webu zobrazit (hotová, nebo čekající jako rozostřená dlaždice). Neúspěšné se skrývají.
	 */
	public static function is_visible( $attachment_id ): bool {
		return self::FAILED !== self::get( (int) $attachment_id );
	}
}
