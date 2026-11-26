<?php
/**
 * Plugin Name: Bazantik Photo Gallery
 * Description: Self-hosted photo gallery with folders, albums and private albums, full-screen lightbox, EXIF, maps and background processing of large uploads.
 * Version:     1.0.1
 * Author:      Martin Bazant
 * Author URI:  https://bazantik.eu
 * Requires at least: 6.3
 * Requires PHP:      8.1
 * Text Domain: bazantik-photo-gallery
 * License:     GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 *
 * Copyright (C) 2026 Martin Bazant
 *
 * This program is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License as published by the Free Software
 * Foundation; either version 2 of the License, or (at your option) any later
 * version. See the LICENSE file for details.
 */

declare(strict_types=1);

namespace BazantikPhotoGallery;

defined( 'ABSPATH' ) || exit;

define( 'BAZANTIK_VERSION', '1.0.1' );
define( 'BAZANTIK_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'BAZANTIK_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

// Autoload interních tříd (podporuje CamelCase i podtržítka)
spl_autoload_register( function ( string $class_name ): void {
	$prefix = 'BazantikPhotoGallery\\';
	if ( ! str_starts_with( $class_name, $prefix ) ) {
		return;
	}

	$relative_class = substr( $class_name, strlen( $prefix ) );
	// Převede CamelCase na pomlčky a nahradí podtržítka pomlčkami (např. Post_Types -> post-types)
	$kebab          = (string) preg_replace( '/([a-z])([A-Z])/', '$1-$2', $relative_class );
	$clean_name     = strtolower( str_replace( '_', '-', $kebab ) );
	$file_name      = 'class-' . $clean_name . '.php';
	$file_path      = BAZANTIK_PLUGIN_DIR . 'includes/' . $file_name;

	if ( file_exists( $file_path ) ) {
		require_once $file_path;
	}
} );

// Registrace aktivačních hooků pro flush rewrite rules
register_activation_hook( __FILE__, function (): void {
	Post_Types::register();
	flush_rewrite_rules();
	update_option( 'bazantik_db_version', BAZANTIK_VERSION, false );
} );

register_deactivation_hook( __FILE__, function (): void {
	wp_clear_scheduled_hook( 'bazantik_process_queue' );
	// Pravidla se musí vyprázdnit bez našich adres, proto se typ a taxonomie na chvíli odregistrují
	unregister_post_type( 'bazantik_album' );
	unregister_taxonomy( 'bazantik_folder' );
	flush_rewrite_rules();
} );

// Inicializace jádra pluginu
add_action( 'plugins_loaded', function (): void {
	Plugin::get_instance()->init();
} );
