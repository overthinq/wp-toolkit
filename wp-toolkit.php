<?php
/**
 * Plugin Name:       Content Toolkit
 * Description:       Gestore di tool per operazioni batch eseguite da worker WP-CLI in background.
 * Version:           0.2.0
 * Requires at least: 6.8
 * Requires PHP:      8.3
 * License:           GPL-2.0-or-later
 * Text Domain:       wp-toolkit
 */

namespace ContentToolkit;

defined( 'ABSPATH' ) || exit;

spl_autoload_register(
	static function ( string $class_name ): void {
		if ( str_starts_with( $class_name, __NAMESPACE__ . '\\' ) ) {
			require __DIR__ . '/src/' . str_replace( '\\', '/', substr( $class_name, strlen( __NAMESPACE__ ) + 1 ) ) . '.php';
		}
	}
);

( new Plugin( __FILE__ ) )->hook();
