<?php

namespace ContentToolkit;

use WP_CLI;

defined( 'ABSPATH' ) || exit;

final class Cli {

	public function __construct( private Plugin $plugin ) {}

	/**
	 * Esegue un job accodato da un tool del toolkit, un blocco alla volta.
	 *
	 * ## OPTIONS
	 *
	 * <tool>
	 * : Slug del tool.
	 *
	 * <job_id>
	 * : ID del job.
	 *
	 * @subcommand run-job
	 */
	public function run_job( array $args ): void {
		[ $slug, $job_id ] = $args;

		$tool = $this->plugin->tool( $slug );
		if ( ! $tool ) {
			WP_CLI::error( "Tool sconosciuto: $slug" );
		}

		do {
			$job = $tool->run_step( $job_id );
			if ( is_wp_error( $job ) ) {
				WP_CLI::error( $job->get_error_message() );
			}
			WP_CLI::log( sprintf( '%s %d/%d %s', $job['status'], $job['done'], $job['total'], $job['label'] ) );
		} while ( ! Tool::is_finished( $job ) );

		WP_CLI::success( "Job terminato: {$job['status']}." );
	}
}
