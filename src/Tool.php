<?php

namespace ContentToolkit;

use Throwable;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Base dei tool: un modulo, un job alla volta eseguito a blocchi dal worker WP-CLI e
 * lo stato salvato in un'option dopo ogni blocco. I tool implementano solo modulo e blocco.
 *
 * Il job passa da starting a running (stopping se si chiede l'arresto) e chiude in
 * completed, stopped o failed.
 */
abstract class Tool {

	public const SLUG = '';

	/** Senza primo blocco entro questo tempo il worker si considera mai partito. */
	private const START_TIMEOUT = 90;

	/** Oltre questo silenzio un job in corso si chiude come fallito. */
	private const RUN_TIMEOUT = 12 * HOUR_IN_SECONDS;

	public function __construct( protected Plugin $plugin ) {}

	abstract public function title(): string;

	abstract protected function render_form( array $state ): void;

	/**
	 * Valori del modulo inviato, ricordati per il prossimo avvio.
	 */
	abstract protected function read_form(): array;

	/**
	 * Dati iniziali del job, che diventano $job['data'].
	 */
	abstract protected function job_data( array $form ): array|WP_Error;

	/**
	 * Esegue un blocco e restituisce il job aggiornato (data, done, total, label).
	 * A lavoro finito imposta $job['result'] con summary e warnings.
	 */
	abstract protected function step( array $job ): array|WP_Error;

	/**
	 * Riepilogo per un job fermato dall'utente.
	 */
	abstract protected function stopped_summary( array $job ): string;

	/**
	 * Rimuove i file temporanei del job quando si chiude.
	 */
	protected function cleanup( array $job ): void {}

	/**
	 * File di dettaglio mostrato mentre il job gira e copiato nel log finale.
	 */
	protected function details_file( array $job ): string { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- usato dalle sottoclassi.
		return '';
	}

	public static function is_finished( ?array $job ): bool {
		return in_array( $job['status'] ?? '', array( 'completed', 'stopped', 'failed' ), true );
	}

	public function page_slug(): string {
		return Plugin::MENU_SLUG . '-' . static::SLUG;
	}

	public function page_url(): string {
		return admin_url( 'admin.php?page=' . $this->page_slug() );
	}

	public function poll_nonce_action( string $job_id ): string {
		return 'content_toolkit_job_' . static::SLUG . '_' . $job_id;
	}

	/*
	 * Stato.
	 */

	public function state(): array {
		wp_cache_delete( $this->option(), 'options' );
		$state = get_option( $this->option() );
		$state = is_array( $state ) ? $state : array();
		$state = $state + array(
			'form'   => array(),
			'job'    => null,
			'result' => null,
		);

		return $this->expire( $state );
	}

	protected function save( array $state ): void {
		update_option( $this->option(), $state, false );
	}

	private function option(): string {
		return 'content_toolkit_' . str_replace( '-', '_', static::SLUG ) . '_state';
	}

	/**
	 * Un worker morto non chiude il suo job: lo si chiude qui alla prima lettura.
	 */
	private function expire( array $state ): array {
		$job = $state['job'];
		if ( ! $job || self::is_finished( $job ) ) {
			return $state;
		}

		$message = '';
		if ( ! $job['started_at'] && time() - $job['created_at'] > self::START_TIMEOUT ) {
			$message = __( 'Il worker non ha eseguito il primo blocco entro il tempo previsto.', 'wp-toolkit' );
		} elseif ( $job['started_at'] && time() - $job['heartbeat_at'] > self::RUN_TIMEOUT ) {
			$message = __( 'Il job non dà segni di vita da troppo tempo.', 'wp-toolkit' );
		}

		return $message ? $this->fail( $state, $message ) : $state;
	}

	/*
	 * Ciclo del job.
	 */

	private function start( array $state, array $data ): true|WP_Error {
		if ( $state['job'] && ! self::is_finished( $state['job'] ) ) {
			return new WP_Error( 'content_toolkit_running', __( 'Questo tool ha già un job in corso.', 'wp-toolkit' ) );
		}

		$job_id = wp_generate_uuid4();
		$lock   = $this->plugin->acquire_lock( static::SLUG, $job_id );
		if ( is_wp_error( $lock ) ) {
			return $lock;
		}

		$state['job']    = array(
			'id'             => $job_id,
			'status'         => 'starting',
			'data'           => $data,
			'done'           => 0,
			'total'          => 0,
			'label'          => '',
			'stop_requested' => false,
			'error'          => '',
			'log'            => '',
			'created_at'     => time(),
			'started_at'     => 0,
			'heartbeat_at'   => time(),
			'finished_at'    => 0,
		);
		$state['result'] = null;
		$this->save( $state );

		return $this->launch( $state );
	}

	private function launch( array $state ): true|WP_Error {
		$job_id = $state['job']['id'];
		$log    = Storage::path( static::SLUG, "runner-$job_id.log" );
		if ( is_wp_error( $log ) ) {
			return $this->launch_failed( $state, $log );
		}

		$state['job']['log']   = $log;
		$state['job']['label'] = __( 'Avvio del worker…', 'wp-toolkit' );
		$this->save( $state );

		$wp_cli   = (string) apply_filters( 'content_toolkit_wp_cli_binary', 'wp', static::SLUG, $this );
		$launched = Worker::launch( trim( $wp_cli ), static::SLUG, $job_id, $log );
		return is_wp_error( $launched ) ? $this->launch_failed( $state, $launched ) : true;
	}

	/**
	 * Un worker mai partito resta riavviabile dopo aver corretto la configurazione del server.
	 */
	private function launch_failed( array $state, WP_Error $error ): WP_Error {
		$state['job']['error'] = $error->get_error_message();
		$this->fail( $state, $error->get_error_message() );
		return $error;
	}

	/**
	 * Un blocco del job, chiamato dal worker. Il lock su file esclude due worker sullo stesso tool.
	 */
	public function run_step( string $job_id ): array|WP_Error {
		$handle = $this->worker_lock();
		if ( is_wp_error( $handle ) ) {
			return $handle;
		}

		try {
			$state = $this->state();
			$job   = $state['job'];
			if ( ! $job || $job['id'] !== $job_id || self::is_finished( $job ) ) {
				return new WP_Error( 'content_toolkit_job_closed', __( 'Job non trovato o già chiuso.', 'wp-toolkit' ) );
			}

			$lock = $this->plugin->acquire_lock( static::SLUG, $job_id );
			if ( is_wp_error( $lock ) ) {
				return $lock;
			}
			if ( $job['stop_requested'] ) {
				return $this->close( $state, 'stopped' )['job'];
			}

			set_time_limit( 0 );
			$state['job'] = array(
				'status'       => 'running',
				'started_at'   => $job['started_at'] ? $job['started_at'] : time(),
				'heartbeat_at' => time(),
				'error'        => '',
			) + $job;
			$this->save( $state );

			$job = $this->step( $state['job'] );
			if ( is_wp_error( $job ) ) {
				$this->fail( $this->state(), $job->get_error_message() );
				return $job;
			}

			// Lo stop può arrivare dall'admin mentre il blocco gira: va riletto prima di salvare.
			$job['stop_requested'] = $this->state()['job']['stop_requested'] ?? true;
			$job['heartbeat_at']   = time();
			$job['status']         = $job['stop_requested'] ? 'stopping' : 'running';
			$state['job']          = $job;

			if ( isset( $job['result'] ) ) {
				$state = $this->close( $state, 'completed' );
			} elseif ( $job['stop_requested'] ) {
				$state = $this->close( $state, 'stopped' );
			} else {
				$this->save( $state );
			}
			return $state['job'];
		} catch ( Throwable $error ) {
			$this->fail( $this->state(), $error->getMessage() );
			return new WP_Error( 'content_toolkit_worker', $error->getMessage() );
		} finally {
			$this->release_worker_lock( $handle );
		}
	}

	private function stop( array $state ): string {
		$job = $state['job'];
		if ( ! $job || self::is_finished( $job ) ) {
			return __( 'Nessun job attivo da fermare.', 'wp-toolkit' );
		}

		if ( ! $job['started_at'] ) {
			$this->close( $state, 'stopped' );
			return __( 'Job fermato prima del primo blocco.', 'wp-toolkit' );
		}

		$state['job'] = array(
			'stop_requested' => true,
			'status'         => 'stopping',
			'label'          => __( 'Arresto richiesto: il job si ferma alla fine del blocco in corso.', 'wp-toolkit' ),
		) + $job;
		$this->save( $state );
		return __( 'Arresto richiesto.', 'wp-toolkit' );
	}

	/**
	 * Rilancia il worker dall'ultimo blocco salvato, se il precedente non è più vivo.
	 * Non è un rollback: i tool devono tollerare la ripetizione del blocco interrotto.
	 */
	public function restart( string $job_id ): true|WP_Error {
		$handle = $this->worker_lock();
		if ( is_wp_error( $handle ) ) {
			return $handle;
		}

		try {
			$state = $this->state();
			if ( ! $state['job'] || $state['job']['id'] !== $job_id || ! self::is_restartable( $state['job'] ) ) {
				return new WP_Error( 'content_toolkit_restart', __( 'Il job non si può riavviare.', 'wp-toolkit' ) );
			}

			$lock = $this->plugin->acquire_lock( static::SLUG, $job_id );
			if ( is_wp_error( $lock ) ) {
				return $lock;
			}

			$state['job']    = array(
				'status'       => $state['job']['started_at'] ? 'running' : 'starting',
				'created_at'   => $state['job']['started_at'] ? $state['job']['created_at'] : time(),
				'heartbeat_at' => time(),
				'finished_at'  => 0,
				'error'        => '',
			) + $state['job'];
			$state['result'] = null;
			$this->save( $state );
		} finally {
			$this->release_worker_lock( $handle );
		}

		return $this->launch( $state );
	}

	/**
	 * Si riavvia un job attivo senza segnali di vita, o uno il cui worker non è mai partito.
	 * Il lock su file in restart() esclude comunque un worker ancora vivo.
	 */
	public static function is_restartable( array $job ): bool {
		if ( $job['stop_requested'] ) {
			return false;
		}
		if ( 'failed' === $job['status'] ) {
			return ! $job['started_at'] && '' !== $job['error'];
		}
		if ( self::is_finished( $job ) ) {
			return false;
		}
		return time() - $job['heartbeat_at'] > ( $job['started_at'] ? 3 * MINUTE_IN_SECONDS : 45 );
	}

	private function close( array $state, string $status ): array {
		$job    = $state['job'];
		$result = $job['result'] ?? array( 'summary' => $this->stopped_summary( $job ) );
		$lines  = array_filter( array( $result['summary'], Storage::tail( $this->details_file( $job ), PHP_INT_MAX ), implode( "\n", $result['warnings'] ?? array() ) ) );
		$log    = Storage::write( static::SLUG, 'log-' . gmdate( 'Y-m-d-His' ) . '.txt', implode( "\n\n", $lines ) . "\n" );

		unset( $job['result'] );
		$state['job']    = array(
			'status'      => $status,
			'label'       => '',
			'finished_at' => time(),
		) + $job;
		$state['result'] = array(
			'type'     => 'completed' === $status ? 'success' : 'warning',
			'summary'  => $result['summary'],
			'warnings' => $result['warnings'] ?? array(),
			'log'      => is_wp_error( $log ) ? '' : basename( $log ),
		);
		return $this->finish( $state );
	}

	private function fail( array $state, string $message ): array {
		$state['job']    = array(
			'status'      => 'failed',
			'label'       => '',
			'finished_at' => time(),
		) + $state['job'];
		$state['result'] = array(
			'type'     => 'error',
			'summary'  => $message,
			'warnings' => array(),
			'log'      => '',
		);
		return $this->finish( $state );
	}

	private function finish( array $state ): array {
		$this->save( $state );
		$this->cleanup( $state['job'] );
		$this->plugin->release_lock( static::SLUG, $state['job']['id'] );
		return $state;
	}

	/**
	 * @return resource|WP_Error
	 */
	private function worker_lock(): mixed {
		$path = Storage::path( static::SLUG, 'worker.lock' );
		if ( is_wp_error( $path ) ) {
			return $path;
		}

		$handle = fopen( $path, 'c' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		if ( $handle && flock( $handle, LOCK_EX | LOCK_NB ) ) {
			return $handle;
		}
		if ( $handle ) {
			fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		}
		return new WP_Error( 'content_toolkit_worker_busy', __( 'Un worker di questo tool è ancora attivo.', 'wp-toolkit' ) );
	}

	private function release_worker_lock( mixed $handle ): void {
		flock( $handle, LOCK_UN );
		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
	}

	/*
	 * Admin.
	 */

	public function handle( string $action ): void {
		check_admin_referer( 'content_toolkit_' . static::SLUG . '_' . $action );
		$state = $this->state();

		switch ( $action ) {
			case 'start':
				$state['form'] = $this->read_form();
				$this->save( $state );
				$data   = $this->job_data( $state['form'] );
				$result = is_wp_error( $data ) ? $data : $this->start( $state, $data );
				$this->notice( is_wp_error( $result ) ? 'error' : 'success', is_wp_error( $result ) ? $result->get_error_message() : __( 'Job avviato.', 'wp-toolkit' ) );
				break;
			case 'stop':
				$this->notice( 'warning', $this->stop( $state ) );
				break;
			case 'restart':
				$result = $this->restart( sanitize_text_field( wp_unslash( $_POST['job_id'] ?? '' ) ) );
				$this->notice( is_wp_error( $result ) ? 'error' : 'success', is_wp_error( $result ) ? $result->get_error_message() : __( 'Worker riavviato.', 'wp-toolkit' ) );
				break;
			case 'clear':
				$state['result'] = null;
				if ( self::is_finished( $state['job'] ) ) {
					$state['job'] = null;
				}
				$this->save( $state );
				break;
		}

		wp_safe_redirect( $this->page_url() );
		exit;
	}

	private function notice( string $type, string $message ): void {
		set_transient( 'content_toolkit_notice_' . get_current_user_id(), array( $type, $message ), MINUTE_IN_SECONDS );
	}

	public function render_page(): void {
		$state  = $this->state();
		$notice = get_transient( 'content_toolkit_notice_' . get_current_user_id() );
		delete_transient( 'content_toolkit_notice_' . get_current_user_id() );
		$job     = $state['job'];
		$running = $this->is_running( $state );
		if ( $running ) {
			wp_enqueue_script( 'content-toolkit-job', $this->plugin->url . 'assets/job.js', array(), '0.2.0', true );
		}
		?>
		<div class="wrap">
			<h1><?php echo esc_html( $this->title() ); ?></h1>
			<?php if ( $notice ) : ?>
				<div class="notice notice-<?php echo esc_attr( $notice[0] ); ?> is-dismissible"><p><?php echo esc_html( $notice[1] ); ?></p></div>
			<?php endif; ?>
			<?php $this->render_form( $state ); ?>
			<div id="content-toolkit-job"
				<?php if ( $running ) : ?>
					data-tool="<?php echo esc_attr( static::SLUG ); ?>"
					data-job="<?php echo esc_attr( $job['id'] ); ?>"
					data-nonce="<?php echo esc_attr( wp_create_nonce( $this->poll_nonce_action( $job['id'] ) ) ); ?>"
				<?php endif; ?>
			><?php $this->render_panel( $state ); ?></div>
		</div>
		<?php
	}

	public function panel_html(): string {
		ob_start();
		$this->render_panel( $this->state() );
		return (string) ob_get_clean();
	}

	protected function action_fields( string $action ): void {
		wp_nonce_field( 'content_toolkit_' . static::SLUG . '_' . $action );
		printf( '<input type="hidden" name="action" value="content_toolkit_tool_action"><input type="hidden" name="tool" value="%s"><input type="hidden" name="toolkit_action" value="%s">', esc_attr( static::SLUG ), esc_attr( $action ) );
	}

	protected function is_running( array $state ): bool {
		return $state['job'] && ! self::is_finished( $state['job'] );
	}

	private function render_panel( array $state ): void {
		$job    = $state['job'];
		$result = $state['result'];
		$url    = admin_url( 'admin-post.php' );

		if ( $job ) :
			$statuses = array(
				'starting'  => __( 'In avvio', 'wp-toolkit' ),
				'running'   => __( 'In corso', 'wp-toolkit' ),
				'stopping'  => __( 'In arresto', 'wp-toolkit' ),
				'stopped'   => __( 'Fermato', 'wp-toolkit' ),
				'completed' => __( 'Completato', 'wp-toolkit' ),
				'failed'    => __( 'Fallito', 'wp-toolkit' ),
			);
			?>
			<hr>
			<h2><?php esc_html_e( 'Job', 'wp-toolkit' ); ?></h2>
			<p><strong><?php echo esc_html( $statuses[ $job['status'] ] ); ?></strong> <?php echo esc_html( $job['label'] ); ?></p>
			<?php if ( $job['total'] ) : ?>
				<p>
					<progress max="<?php echo (int) $job['total']; ?>" value="<?php echo (int) $job['done']; ?>"></progress>
					<?php echo (int) $job['done']; ?> / <?php echo (int) $job['total']; ?>
				</p>
			<?php endif; ?>
			<p class="description">
				<?php
				/* translators: 1: ID del job, 2: tempo trascorso dall'ultimo segnale. */
				printf( esc_html__( 'Job %1$s, ultimo segnale %2$s fa.', 'wp-toolkit' ), esc_html( $job['id'] ), esc_html( human_time_diff( $job['heartbeat_at'] ) ) );
				?>
			</p>
			<?php if ( ! self::is_finished( $job ) ) : ?>
				<form method="post" action="<?php echo esc_url( $url ); ?>">
					<?php $this->action_fields( 'stop' ); ?>
					<?php submit_button( __( 'Ferma', 'wp-toolkit' ), 'secondary', 'submit', false, $job['stop_requested'] ? array( 'disabled' => 'disabled' ) : array() ); ?>
				</form>
			<?php endif; ?>
			<?php if ( self::is_restartable( $job ) ) : ?>
				<form method="post" action="<?php echo esc_url( $url ); ?>">
					<?php $this->action_fields( 'restart' ); ?>
					<input type="hidden" name="job_id" value="<?php echo esc_attr( $job['id'] ); ?>">
					<?php submit_button( __( 'Riavvia worker', 'wp-toolkit' ), 'secondary', 'submit', false ); ?>
					<p class="description"><?php esc_html_e( 'Riprende dall\'ultimo blocco salvato se il worker precedente non è più attivo.', 'wp-toolkit' ); ?></p>
				</form>
			<?php endif; ?>
			<?php
			foreach ( array( $job['log'], $this->details_file( $job ) ) as $file ) {
				$tail = Storage::tail( $file );
				if ( '' !== $tail ) {
					printf( '<p><code>%s</code></p><textarea readonly class="large-text code" rows="8">%s</textarea>', esc_html( basename( $file ) ), esc_textarea( $tail ) );
				}
			}
		endif;

		if ( $result ) :
			?>
			<hr>
			<h2><?php esc_html_e( 'Ultimo risultato', 'wp-toolkit' ); ?></h2>
			<div class="notice notice-<?php echo esc_attr( $result['type'] ); ?> inline"><p><?php echo esc_html( $result['summary'] ); ?></p></div>
			<?php if ( $result['warnings'] ) : ?>
				<textarea readonly class="large-text code" rows="<?php echo (int) min( 12, count( $result['warnings'] ) + 1 ); ?>"><?php echo esc_textarea( implode( "\n", $result['warnings'] ) ); ?></textarea>
			<?php endif; ?>
			<?php if ( $result['log'] ) : ?>
				<p>
					<?php
					/* translators: %s: nome del file di log nella cartella privata. */
					printf( esc_html__( 'Log completo: %s', 'wp-toolkit' ), '<code>' . esc_html( $result['log'] ) . '</code>' );
					?>
				</p>
			<?php endif; ?>
			<form method="post" action="<?php echo esc_url( $url ); ?>">
				<?php $this->action_fields( 'clear' ); ?>
				<?php submit_button( __( 'Pulisci', 'wp-toolkit' ), 'secondary', 'submit', false ); ?>
			</form>
			<?php
		endif;
	}
}
