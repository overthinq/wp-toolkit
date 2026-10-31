<?php

namespace ContentToolkit;

use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Avvia `wp content-toolkit run-job` in background, staccato dalla richiesta web.
 * Output ed esito finiscono nel log del runner, visibile nel pannello del job.
 */
final class Worker {

	public static function launch( string $wp_cli, string $tool, string $job_id, string $log ): true|WP_Error {
		if ( ! function_exists( 'proc_open' ) ) {
			return new WP_Error( 'content_toolkit_worker', __( 'Il server non consente proc_open(): il worker non può partire.', 'wp-toolkit' ) );
		}

		$command = implode(
			' ',
			array(
				escapeshellarg( $wp_cli ),
				'--path=' . escapeshellarg( untrailingslashit( ABSPATH ) ),
				'--url=' . escapeshellarg( home_url( '/' ) ),
				'content-toolkit run-job',
				escapeshellarg( $tool ),
				escapeshellarg( $job_id ),
			)
		);
		$run     = sprintf( 'date "+%%F %%T avvio"; %s; status=$?; date "+%%F %%T uscita $status"', $command );
		$script  = sprintf( 'command -v %s >/dev/null || exit 127; nohup sh -c %s >>%s 2>&1 &', escapeshellarg( $wp_cli ), escapeshellarg( $run ), escapeshellarg( $log ) );

		// Shell di login: sotto PHP-FPM l'ambiente può non avere PATH. proc_open() è un requisito dichiarato nel README.
		$null    = array( 'file', '/dev/null', 'r+' );
		$process = proc_open( array( '/bin/sh', '-lc', $script ), array( $null, $null, $null ), $pipes, untrailingslashit( ABSPATH ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_proc_open
		if ( ! is_resource( $process ) ) {
			return new WP_Error( 'content_toolkit_worker', __( 'Impossibile avviare il processo del worker.', 'wp-toolkit' ) );
		}

		return match ( proc_close( $process ) ) {
			0       => true,
			/* translators: %s: comando WP-CLI configurato. */
			127     => new WP_Error( 'content_toolkit_worker', sprintf( __( 'WP-CLI non trovato sul server: %s', 'wp-toolkit' ), $wp_cli ) ),
			default => new WP_Error( 'content_toolkit_worker', __( 'Il worker in background non è partito.', 'wp-toolkit' ) ),
		};
	}
}
