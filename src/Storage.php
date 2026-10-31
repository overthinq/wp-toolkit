<?php

namespace ContentToolkit;

use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * CSV, dati dei job e log contengono informazioni sui contenuti: stanno in una cartella
 * privata fuori dalla web root, una sottocartella per tool.
 */
final class Storage {

	public static function dir( string $tool ): string|WP_Error {
		$base  = defined( 'CONTENT_TOOLKIT_STORAGE_DIR' ) ? realpath( CONTENT_TOOLKIT_STORAGE_DIR ) : false;
		$roots = array_filter( array_map( 'realpath', array_filter( array( sanitize_text_field( wp_unslash( $_SERVER['DOCUMENT_ROOT'] ?? '' ) ), ABSPATH ) ) ) );
		foreach ( $roots as $root ) {
			if ( $base && str_starts_with( $base . '/', $root . '/' ) ) {
				$base = false;
			}
		}
		if ( ! $base || ! wp_is_writable( $base ) ) {
			return new WP_Error( 'content_toolkit_storage', __( 'Imposta CONTENT_TOOLKIT_STORAGE_DIR su una cartella scrivibile fuori dalla web root.', 'wp-toolkit' ) );
		}

		$dir = "$base/$tool";
		if ( ! is_dir( $dir ) && ! mkdir( $dir, 0700 ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir
			return new WP_Error( 'content_toolkit_storage', __( 'Impossibile creare la cartella privata del tool.', 'wp-toolkit' ) );
		}
		return $dir;
	}

	public static function path( string $tool, string $name ): string|WP_Error {
		$dir = self::dir( $tool );
		return is_wp_error( $dir ) ? $dir : $dir . '/' . sanitize_file_name( $name );
	}

	public static function write( string $tool, string $name, string $contents, int $flags = 0 ): string|WP_Error {
		$path = self::path( $tool, $name );
		if ( ! is_wp_error( $path ) && false === file_put_contents( $path, $contents, $flags ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			return new WP_Error( 'content_toolkit_write', __( 'Impossibile scrivere nella cartella privata.', 'wp-toolkit' ) );
		}
		return $path;
	}

	/**
	 * Ultima parte di un file di testo, per i log mostrati durante il job.
	 */
	public static function tail( string $path, int $bytes = 16000 ): string {
		if ( '' === $path || ! is_readable( $path ) ) {
			return '';
		}
		$size = filesize( $path );
		return ltrim( (string) file_get_contents( $path, false, null, max( 0, $size - $bytes ) ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
	}

	public static function upload( string $tool, string $field, string $extension ): string|WP_Error {
		// Nonce verificato in Tool::handle(); del file si controllano errore, estensione e is_uploaded_file().
		// phpcs:disable WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput
		$file = $_FILES[ $field ] ?? null;
		if ( ! is_array( $file ) || UPLOAD_ERR_NO_FILE === $file['error'] ) {
			/* translators: %s: estensione richiesta. */
			return new WP_Error( 'content_toolkit_upload', sprintf( __( 'Seleziona un file .%s.', 'wp-toolkit' ), $extension ) );
		}
		if ( UPLOAD_ERR_OK !== $file['error'] ) {
			return new WP_Error( 'content_toolkit_upload', __( 'Caricamento del file non riuscito.', 'wp-toolkit' ) );
		}

		$name = sanitize_file_name( wp_unslash( $file['name'] ) );
		if ( strtolower( pathinfo( $name, PATHINFO_EXTENSION ) ) !== $extension ) {
			/* translators: %s: estensione richiesta. */
			return new WP_Error( 'content_toolkit_upload', sprintf( __( 'Carica un file .%s.', 'wp-toolkit' ), $extension ) );
		}

		$dir = self::dir( $tool );
		if ( is_wp_error( $dir ) ) {
			return $dir;
		}

		$path = $dir . '/' . wp_unique_filename( $dir, $name );
		if ( ! is_uploaded_file( $file['tmp_name'] ) || ! move_uploaded_file( $file['tmp_name'], $path ) ) {
			return new WP_Error( 'content_toolkit_upload', __( 'Impossibile salvare il file caricato.', 'wp-toolkit' ) );
		}
		// phpcs:enable

		chmod( $path, 0600 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod
		return $path;
	}
}
