<?php
declare(strict_types=1);

namespace BazantikPhotoGallery;

defined( 'ABSPATH' ) || exit;

/**
 * Fronta a worker: dokončuje zpracování fotek ve stavu "pending".
 */
final class Processing_Queue {
	public const MAX_ATTEMPTS = 3;    // Po tolika pokusech přejde fotka do stavu "failed"
	public const STALE_AFTER  = 600;  // s – "processing" starší než toto se považuje za přerušené

	private static ?int $current_id          = null;
	private static bool $shutdown_registered = false;
	private static bool $lock_held           = false;
	private static string $lock_value        = '';

	public const LOCK_OPTION = 'bazantik_worker_lock'; // zámek: v jednu chvíli smí zpracovávat jen jeden proces
	public const LOCK_TTL    = 180;               // s – po této době se zámek považuje za opuštěný

	/**
	 * Zpracuje dávku fotek, pokud právě neběží jiný proces (smyčka v prohlížeči, ruční dávka, cron).
	 */
	public static function run_locked_batch( int $budget = 20, array $priority = [] ): array {
		if ( Processing_Status::is_paused() ) {
			return [
				'done'   => 0,
				'failed' => 0,
				'busy'   => false,
				'paused' => true,
			];
		}
		if ( ! self::acquire_lock() ) {
			return [
				'done'   => 0,
				'failed' => 0,
				'busy'   => true,
			];
		}

		try {
			$result = self::run_batch( $budget, $priority );
		} finally {
			self::release_lock();
		}

		$result['busy'] = false;
		return $result;
	}

	private static function acquire_lock(): bool {
		global $wpdb;

		$now = time();

		// Hodnota zámku = čas a náhodný podpis vlastníka, aby cizí proces nemohl zámek uvolnit ani prodloužit
		$value = $now . ':' . wp_generate_password( 12, false );

		// Atomické vytvoření zámku (option_name je v databázi unikátní)
		$inserted = $wpdb->query(
			$wpdb->prepare(
				"INSERT IGNORE INTO {$wpdb->options} ( option_name, option_value, autoload ) VALUES ( %s, %s, 'no' )",
				self::LOCK_OPTION,
				$value
			)
		);
		wp_cache_delete( self::LOCK_OPTION, 'options' );

		if ( 1 === $inserted ) {
			self::$lock_held  = true;
			self::$lock_value = $value;
			return true;
		}

		// Zámek už existuje: převezmeme ho jen tehdy, když je opuštěný (prošlý)
		$old = (string) $wpdb->get_var(
			$wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", self::LOCK_OPTION )
		);

		if ( '' !== $old && ( $now - (int) $old ) > self::LOCK_TTL ) {
			$taken = $wpdb->update(
				$wpdb->options,
				[ 'option_value' => $value ],
				[
					'option_name'  => self::LOCK_OPTION,
					'option_value' => $old,
				]
			);
			wp_cache_delete( self::LOCK_OPTION, 'options' );

			if ( 1 === $taken ) {
				self::$lock_held  = true;
				self::$lock_value = $value;
				return true;
			}
		}

		return false;
	}

	/**
	 * Prodlouží platnost zámku (volá se po každé fotce, aby dlouhá dávka nebyla považována za opuštěnou).
	 * Když zámek mezitím převzal jiný proces, nic se nezmění a vrací se false (dávka musí skončit).
	 */
	private static function refresh_lock(): bool {
		global $wpdb;

		if ( ! self::$lock_held ) {
			return true; // zámek se vůbec nepoužil (např. běh bez zámku), není co hlídat
		}

		$new = time() . ':' . substr( self::$lock_value, strpos( self::$lock_value, ':' ) + 1 );
		$ok  = $wpdb->update(
			$wpdb->options,
			[ 'option_value' => $new ],
			[
				'option_name'  => self::LOCK_OPTION,
				'option_value' => self::$lock_value,
			]
		);
		wp_cache_delete( self::LOCK_OPTION, 'options' );

		if ( 1 === $ok ) {
			self::$lock_value = $new;
			return true;
		}

		// 0 řádků může znamenat i stejnou hodnotu (ve stejné vteřině); zámek je ztracen, jen když v databázi patří jinému
		$current = (string) $wpdb->get_var(
			$wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", self::LOCK_OPTION )
		);
		if ( $current === self::$lock_value || $current === $new ) {
			return true;
		}

		self::$lock_held  = false;
		self::$lock_value = '';
		return false;
	}

	/**
	 * Stáří zámku v sekundách (null = zámek je volný). Slouží k zobrazení v administraci.
	 */
	public static function lock_age(): ?int {
		global $wpdb;

		$value = $wpdb->get_var(
			$wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", self::LOCK_OPTION )
		);

		return null === $value ? null : max( 0, time() - (int) $value );
	}

	private static function release_lock(): void {
		if ( ! self::$lock_held ) {
			return;
		}

		self::$lock_held = false;

		// Smaže se jen vlastní zámek (po převzetí jiným procesem zůstane jeho zámek nedotčen)
		global $wpdb;
		$wpdb->delete(
			$wpdb->options,
			[
				'option_name'  => self::LOCK_OPTION,
				'option_value' => self::$lock_value,
			]
		);
		wp_cache_delete( self::LOCK_OPTION, 'options' );
		self::$lock_value = '';
	}

	/**
	 * Počty fotek podle stavu (jen fotky, které mají explicitní příznak).
	 */
	/**
	 * Počty pro zobrazení v administraci (polling): krátká mezipaměť, aby se seskupený dotaz neprováděl při každém dotazu.
	 * Worker a cron používají přesné counts().
	 */
	public static function counts_cached(): array {
		$cached = get_transient( 'bazantik_counts_cache' );
		if ( is_array( $cached ) ) {
			return $cached;
		}
		$counts = self::counts();
		set_transient( 'bazantik_counts_cache', $counts, 2 );
		return $counts;
	}

	public static function counts(): array {
		global $wpdb;

		$counts = [
			Processing_Status::PENDING    => 0,
			Processing_Status::PROCESSING => 0,
			Processing_Status::READY      => 0,
			Processing_Status::FAILED     => 0,
		];

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT meta_value AS status, COUNT(*) AS total FROM {$wpdb->postmeta} WHERE meta_key = %s AND EXISTS ( SELECT 1 FROM {$wpdb->posts} WHERE {$wpdb->posts}.ID = {$wpdb->postmeta}.post_id ) GROUP BY meta_value",
				Processing_Status::META_STATUS
			),
			ARRAY_A
		);

		foreach ( (array) $rows as $row ) {
			if ( isset( $counts[ $row['status'] ] ) ) {
				$counts[ $row['status'] ] = (int) $row['total'];
			}
		}

		return $counts;
	}

	/**
	 * Zpracuje co nejvíc fotek v rámci časového rozpočtu (v sekundách). Vrací počet úspěšně hotových.
	 */
	public static function run_batch( int $budget = 60, array $priority = [] ): array {
		require_once ABSPATH . 'wp-admin/includes/image.php';
		require_once ABSPATH . 'wp-admin/includes/file.php';

		if ( function_exists( 'set_time_limit' ) ) {
			set_time_limit( $budget + 180 );
		}
		// Hosting může změnu limitu zakázat; dávka pak musí skončit dřív, než proces zabije PHP
		$limit = (int) ini_get( 'max_execution_time' );
		if ( $limit > 0 ) {
			$budget = (int) min( $budget, max( 5, $limit - 15 ) );
		}
		wp_raise_memory_limit( 'image' );

		self::recover_stale();

		$start   = microtime( true );
		$done    = 0;
		$tried   = 0;
		$total   = 0.0;
		$skipped = [];
		$failed  = 0;

		while ( true ) {
			$avg = $tried > 0 ? $total / $tried : min( 8.0, $budget * 0.8 ); // první odhad doby jedné fotky (krátký limit hostingu dávku nezablokuje)
			if ( ( microtime( true ) - $start ) + $avg > $budget ) {
				break;
			}

			Processing_Status::wait_for_quiet_uploads( (int) min( 15, max( 0, $budget - ( microtime( true ) - $start ) - $avg ) ) );

			if ( Processing_Status::is_paused() ) {
				break; // zpracování bylo mezitím pozastaveno
			}

			$id = self::claim_next( $skipped, $priority );
			if ( ! $id ) {
				break;
			}

			$t  = microtime( true );
			$ok = self::process( $id );
			$duration = microtime( true ) - $t;

			++$tried;
			$total += $duration;

			if ( $ok ) {
				++$done;
			} else {
				$skipped[] = $id; // neúspěšnou fotku v téže dávce znovu nezkoušíme
				++$failed;
			}

			if ( ! self::refresh_lock() ) {
				break; // zámek převzal jiný proces, dávka dál nepokračuje
			}

			// pauza po fotce šetří sdílené CPU; poměr a strop dává profil z Nastavení
			$perf  = Settings::perf();
			$pause = min( $perf['max_pause'], $duration * $perf['pause_factor'] );
			usleep( (int) ( $pause * 1000000 ) );
		}

		return [
			'done'   => $done,
			'failed' => $failed,
		];
	}

	/**
	 * Všechny neúspěšné fotky vrátí do fronty.
	 */
	public static function retry_failed(): int {
		global $wpdb;

		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value = %s",
				Processing_Status::META_STATUS,
				Processing_Status::FAILED
			)
		);

		foreach ( $ids as $id ) {
			$id = (int) $id;
			update_post_meta( $id, Processing_Status::META_ATTEMPTS, 0 );
			delete_post_meta( $id, Processing_Status::META_ERROR );
			Processing_Status::set( $id, Processing_Status::PENDING );
		}

		return count( $ids );
	}

	/**
	 * Fotky zaseknuté ve stavu "processing" (pád serveru, vypršení času) vrátí do fronty nebo označí jako neúspěšné.
	 */
	public static function recover_stale(): int {
		global $wpdb;

		$cutoff = time() - self::STALE_AFTER;

		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT s.post_id FROM {$wpdb->postmeta} s
                LEFT JOIN {$wpdb->postmeta} t ON ( t.post_id = s.post_id AND t.meta_key = %s )
                WHERE s.meta_key = %s AND s.meta_value = %s
                AND ( t.meta_value IS NULL OR CAST( t.meta_value AS UNSIGNED ) < %d )",
				Processing_Status::META_STARTED,
				Processing_Status::META_STATUS,
				Processing_Status::PROCESSING,
				$cutoff
			)
		);

		foreach ( $ids as $id ) {
			self::fail_or_retry(
				(int) $id,
				__( 'Processing was interrupted (probably the time limit was exceeded or memory ran out).', 'bazantik-photo-gallery' )
			);
		}

		return count( $ids );
	}

	/**
	 * Filtr: při dokončování fotek vytváříme jen velikosti, které galerie skutečně používá
	 * (vyřadí např. velikosti přidané šablonou Avada).
	 */
	public static function limit_sizes( array $sizes ): array {
		$allowed = (array) apply_filters( 'bazantik_required_image_sizes', [ 'thumbnail', 'medium_large', '2048x2048' ] );
		return array_intersect_key( $sizes, array_flip( $allowed ) );
	}

	/**
	 * Záchytný bod při fatální chybě (vypršení času, paměť): rozpracovanou fotku označí k opakování.
	 */
	public static function on_shutdown(): void {
		self::release_lock(); // při fatální chybě uvolníme zámek, ať frontu neblokuje
		if ( null === self::$current_id ) {
			return;
		}

		$id               = self::$current_id;
		self::$current_id = null;

		// error_get_last() vrací i starší varování a upozornění; za příčinu ukončení bereme jen fatální chyby
		$error   = error_get_last();
		$fatal   = E_ERROR | E_PARSE | E_CORE_ERROR | E_COMPILE_ERROR | E_USER_ERROR | E_RECOVERABLE_ERROR;
		$message = ( $error && ( $error['type'] & $fatal ) )
		? sprintf(
			/* translators: %s: PHP error message */
			__( 'Fatal error: %s', 'bazantik-photo-gallery' ),
			$error['message']
		)
		: __( 'Processing ended unexpectedly.', 'bazantik-photo-gallery' );

		self::fail_or_retry( $id, $message );
	}

		/**
		 * Atomicky převezme další čekající fotku (ochrana proti souběžnému zpracování dvěma procesy).
		 */
	private static function claim_next( array $skipped, array $priority = [] ): int {
		global $wpdb;

		$exclude = '';
		if ( ! empty( $skipped ) ) {
			$exclude = ' AND post_id NOT IN (' . implode( ',', array_map( 'absint', $skipped ) ) . ')';
		}

		// Fotky z právě otevřeného alba mají přednost před zbytkem fronty
		$order = ' ORDER BY post_id ASC';
		if ( ! empty( $priority ) ) {
			$order = ' ORDER BY ( post_id IN (' . implode( ',', array_map( 'absint', $priority ) ) . ') ) DESC, post_id ASC';
		}

		for ( $i = 0; $i < 5; $i++ ) {
			// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- $exclude a $order obsahují jen čísla (absint)
			$id = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value = %s AND EXISTS ( SELECT 1 FROM {$wpdb->posts} WHERE {$wpdb->posts}.ID = {$wpdb->postmeta}.post_id ) AND NOT EXISTS ( SELECT 1 FROM {$wpdb->postmeta} AS hold WHERE hold.post_id = {$wpdb->postmeta}.post_id AND hold.meta_key = %s AND CAST( hold.meta_value AS UNSIGNED ) > %d ){$exclude}{$order} LIMIT 1",
					Processing_Status::META_STATUS,
					Processing_Status::PENDING,
					Processing_Status::META_HOLD,
					time() - Processing_Status::HOLD_TTL
				)
			);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter

			if ( ! $id ) {
				return 0;
			}

			// Čas startu zapisujeme PŘED převzetím, aby úloha nikdy nebyla "processing" bez časové značky
			update_post_meta( $id, Processing_Status::META_STARTED, time() );

			$claimed = $wpdb->update(
				$wpdb->postmeta,
				[ 'meta_value' => Processing_Status::PROCESSING ],
				[
					'post_id'    => $id,
					'meta_key'   => Processing_Status::META_STATUS,
					'meta_value' => Processing_Status::PENDING,
				]
			);
			wp_cache_delete( $id, 'post_meta' );

			if ( 1 === $claimed ) {
				$attempts = (int) get_post_meta( $id, Processing_Status::META_ATTEMPTS, true );
				update_post_meta( $id, Processing_Status::META_ATTEMPTS, $attempts + 1 );
				delete_post_meta( $id, Processing_Status::META_ERROR );
				return $id;
			}
		}

		return 0;
	}

		/**
		 * Dokončí zpracování jedné fotky. Vrací true při úspěchu.
		 */
	private static function process( int $id ): bool {
		self::$current_id = $id;
		$started_at       = microtime( true ); // měření doby zpracování (testovací report)

		if ( ! self::$shutdown_registered ) {
			self::$shutdown_registered = true;
			register_shutdown_function( [ self::class, 'on_shutdown' ] );
		}

		Image_Editor_Imagick::take_error(); // chyba editoru z předchozí fotky se nesmí přenést

		try {
			// Vždy začínáme z pravého originálu (ne z případného -scaled po dřívějším přerušeném pokusu)
			$original = wp_get_original_image_path( $id );
			if ( ! $original || ! is_file( $original ) ) {
				throw new \RuntimeException( __( 'The original file was not found.', 'bazantik-photo-gallery' ) );
			}
			// U HEIC je „originálem“ pro WordPress soubor .heic, ale zpracovává se převedený JPEG
			if ( preg_match( '/\.hei[cf]$/i', $original ) ) {
				$converted = (string) get_attached_file( $id );
				if ( preg_match( '/\.jpe?g$/i', $converted ) && is_file( $converted ) ) {
					$original = $converted;
				}
			}
			$old_meta  = wp_get_attachment_metadata( $id );
			$old_files = self::generated_files( is_array( $old_meta ) ? $old_meta : [], $original );

			update_attached_file( $id, $original );

			add_filter( 'intermediate_image_sizes_advanced', [ self::class, 'limit_sizes' ], 10001 );
			Media_Optimizer::set_scope( true );
			try {
				$new_meta = wp_create_image_subsizes( $original, $id );
			} finally {
				Media_Optimizer::set_scope( false );
				remove_filter( 'intermediate_image_sizes_advanced', [ self::class, 'limit_sizes' ], 10001 );
			}

			if ( is_wp_error( $new_meta ) ) {
				throw new \RuntimeException( $new_meta->get_error_message() );
			}
			if ( empty( $new_meta ) || ! is_array( $new_meta ) ) {
				throw new \RuntimeException( __( 'WordPress did not create the image metadata.', 'bazantik-photo-gallery' ) );
			}

			// Fotka mezitím byla smazána z knihovny: dokončené soubory smažeme a nenecháme po ní osiřelá metadata
			if ( ! Library_Guard::attachment_exists( $id ) ) {
				Library_Guard::discard_files( $new_meta, $original );
				Library_Guard::purge_meta( $id );
				self::$current_id = null;
				return true;
			}

			wp_update_attachment_metadata( $id, $new_meta );

			$problem = self::verify_files( $id, $new_meta );
			if ( null !== $problem ) {
				$detail = Image_Editor_Imagick::take_error();
				throw new \RuntimeException( '' !== $detail ? $problem . ' (' . $detail . ')' : $problem );
			}

			self::delete_stale_files( $old_files, $new_meta, $original );

			delete_post_meta( $id, Processing_Status::META_ERROR );
			delete_post_meta( $id, Processing_Status::META_STARTED );
			// Měření pro testovací report: doba zpracování, velikost originálu, špička paměti procesu
			$usage = function_exists( 'getrusage' ) ? getrusage() : [];
			update_post_meta( $id, '_bazantik_proc_ms', (int) round( ( microtime( true ) - $started_at ) * 1000 ) );
			update_post_meta( $id, '_bazantik_proc_mb', (int) round( (int) filesize( $original ) / 1048576 ) );
			update_post_meta( $id, '_bazantik_proc_rss', ! empty( $usage['ru_maxrss'] ) ? (int) round( $usage['ru_maxrss'] / 1024 ) : 0 );
			update_post_meta( $id, '_bazantik_proc_done', time() );

			Processing_Status::set( $id, Processing_Status::READY );

			self::$current_id = null;
			clean_post_cache( $id );
			return true;
		} catch ( \Throwable $e ) {
			self::$current_id = null;
			self::fail_or_retry( $id, $e->getMessage() );
			return false;
		}
	}

			/**
			 * Vrátí fotku do fronty, nebo ji po vyčerpání pokusů označí jako neúspěšnou.
			 */
	private static function fail_or_retry( int $id, string $message ): void {
		// Fotka mezitím zmizela z knihovny: metadata znovu nezapisujeme (vznikla by osiřelá)
		if ( ! Library_Guard::attachment_exists( $id ) ) {
			Library_Guard::purge_meta( $id );
			return;
		}
		$attempts = (int) get_post_meta( $id, Processing_Status::META_ATTEMPTS, true );

		update_post_meta( $id, Processing_Status::META_ERROR, mb_substr( sanitize_text_field( $message ), 0, 500 ) );
		delete_post_meta( $id, Processing_Status::META_STARTED );

		Processing_Status::set(
			$id,
			$attempts >= self::MAX_ATTEMPTS ? Processing_Status::FAILED : Processing_Status::PENDING
		);
	}

			/**
			 * Seznam souborů vygenerovaných pro přílohu (hlavní soubor + všechny velikosti).
			 */
	private static function generated_files( array $meta, string $original ): array {
		$dir   = trailingslashit( dirname( $original ) );
		$files = [];

		if ( ! empty( $meta['file'] ) ) {
			$files[] = wp_normalize_path( $dir . wp_basename( (string) $meta['file'] ) );
		}

		foreach ( (array) ( $meta['sizes'] ?? [] ) as $size ) {
			if ( ! empty( $size['file'] ) ) {
				$files[] = wp_normalize_path( $dir . wp_basename( (string) $size['file'] ) );
			}
		}

		return array_values( array_unique( $files ) );
	}

			/**
			 * Zkontroluje, že všechny soubory uvedené v metadatech skutečně existují, nejsou prázdné a že nechybí povinné velikosti.
			 */
	private static function verify_files( int $id, array $meta ): ?string {
		$main = get_attached_file( $id );
		if ( ! $main || ! is_file( $main ) || ! filesize( $main ) ) {
			return __( 'The main image file is missing or empty.', 'bazantik-photo-gallery' );
		}

		$dir = trailingslashit( dirname( $main ) );
		foreach ( (array) ( $meta['sizes'] ?? [] ) as $name => $size ) {
			$path = $dir . wp_basename( (string) ( $size['file'] ?? '' ) );
			if ( ! is_file( $path ) || ! filesize( $path ) ) {
				/* translators: %s: image size name */
				return sprintf( __( 'The file for size “%s” is missing.', 'bazantik-photo-gallery' ), $name );
			}
		}

		return Library_Guard::incomplete_sizes( $meta );
	}

			/**
			 * Smaže soubory z předchozího stavu (např. JPEG thumbnail z rychlého uploadu), které už metadata nepoužívají.
			 * Originál se nikdy nemaže.
			 */
	private static function delete_stale_files( array $old_files, array $new_meta, string $original ): void {
		$original_norm = wp_normalize_path( $original );
		$keep          = array_merge( self::generated_files( $new_meta, $original ), [ $original_norm ] );

		foreach ( array_diff( $old_files, $keep ) as $path ) {
			if ( $path !== $original_norm && is_file( $path ) ) {
				wp_delete_file( $path );
			}
		}
	}
}
