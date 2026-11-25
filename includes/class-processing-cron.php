<?php
declare(strict_types=1);

namespace BazantikPhotoGallery;

defined( 'ABSPATH' ) || exit;

/**
 * Záchranná síť: WP-Cron pravidelně dokončí zpracování fotek, i když není otevřený editor alba.
 */
class Processing_Cron {
	public const HOOK     = 'bazantik_process_queue';
	public const SCHEDULE = 'bazantik_every_minute';
	public const OPT_LAST = 'bazantik_cron_last_run';
	public const OPT_LAST_EXT = 'bazantik_cron_last_external';
	public const OPT_TOKEN    = 'bazantik_chain_token';

	public function init(): void {
		// Příjem přímého volání wp-cron.php (cron na hostingu): zaznamenáme ho hned při načtení pluginu, ještě před kontrolou zámku.
		// Návštěva webu přidává do adresy parametr doing_wp_cron, cron na hostingu ne.
		$request_path = (string) wp_parse_url( (string) wp_unslash( $_SERVER['REQUEST_URI'] ?? '' ), PHP_URL_PATH ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- z adresy se jen porovnává název souboru
		if ( 'wp-cron.php' === basename( $request_path ) && ! isset( $_GET['doing_wp_cron'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- jen rozpoznání volání wp-cron.php
			update_option( self::OPT_LAST_EXT, time(), false );
		}

		add_filter( 'cron_schedules', [ $this, 'add_schedule' ] );
		add_action( self::HOOK, [ $this, 'run' ] );
		add_action( 'wp_ajax_nopriv_bazantik_continue', [ $this, 'handle_continue' ] );
		add_action( 'wp_ajax_bazantik_continue', [ $this, 'handle_continue' ] );
		add_action( 'init', [ $this, 'ensure_scheduled' ] );
	}

	public function add_schedule( array $schedules ): array {
		$schedules[ self::SCHEDULE ] = [
			'interval' => 60,
			'display'  => __( 'Every minute (Bazantik Photo Gallery)', 'bazantik-photo-gallery' ),
		];
		return $schedules;
	}

	/**
	 * Samoléčba: pokud událost v plánu chybí, naplánuje se znovu.
	 */
	public function ensure_scheduled(): void {
		if ( ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_event( time() + 60, self::SCHEDULE, self::HOOK );
		}
	}

	/**
	 * Tajný klíč pro interní volání, kterým se zpracování řetězí samo (bez návštěvy webu).
	 */
	public static function token(): string {
		$token = (string) get_option( self::OPT_TOKEN, '' );
		if ( '' === $token ) {
			$token = wp_generate_password( 32, false );
			update_option( self::OPT_TOKEN, $token, false );
		}
		return $token;
	}

	/**
	 * Spustí na pozadí další dávku (neblokující požadavek na vlastní web), pokud ve frontě něco čeká a zpracování není pozastaveno.
	 */
	public static function kick(): void {
		if ( Processing_Status::is_paused() ) {
			return;
		}

		$counts = Processing_Queue::counts();
		if ( $counts[ Processing_Status::PENDING ] < 1 ) {
			return;
		}

		wp_remote_post(
			admin_url( 'admin-ajax.php' ),
			[
				'timeout'   => 0.01,
				'blocking'  => false,
				'sslverify' => apply_filters( 'https_local_ssl_verify', true ), // certifikát se ověřuje; web s vlastním certifikátem může filtr změnit
				'body'      => [
					'action' => 'bazantik_continue',
					'token'  => self::token(),
				],
			]
		);
	}

	/**
	 * Po dávce se zřetězí další, ale jen pokud se něco zpracovalo (ochrana proti nekonečné smyčce).
	 */
	public static function maybe_continue( array $result ): void {
		if ( ! empty( $result['busy'] ) || ! empty( $result['paused'] ) || ( (int) ( $result['done'] ?? 0 ) + (int) ( $result['failed'] ?? 0 ) ) < 1 ) {
			return;
		}

		self::kick();
	}

	/**
	 * Interní volání řetězu zpracování (chráněno tajným klíčem, porovnání v konstantním čase).
	 */
	public function handle_continue(): void {
		$given = isset( $_POST['token'] ) ? sanitize_text_field( wp_unslash( $_POST['token'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- ověřuje se tajným klíčem

		if ( '' === $given || ! hash_equals( self::token(), $given ) ) {
			wp_die( '', '', [ 'response' => 403 ] );
		}

		ignore_user_abort( true );
		self::maybe_continue( Processing_Queue::run_locked_batch( Settings::perf()['cron'] ) );
		wp_die();
	}

	public function run(): void {
		$counts  = Processing_Queue::counts();
		$waiting = $counts[ Processing_Status::PENDING ] + $counts[ Processing_Status::PROCESSING ];
		$last    = (int) get_option( self::OPT_LAST );

		// Záznam o posledním spuštění: při práci vždy, v klidu nejvýš jednou za 10 minut (šetří zápisy do DB)
		if ( $waiting > 0 || ( time() - $last ) >= 55 ) {
			update_option( self::OPT_LAST, time(), false );
		}

		if ( 0 === $waiting ) {
			return;
		}

		self::maybe_continue( Processing_Queue::run_locked_batch( Settings::perf()['cron'] ) );
	}
}
