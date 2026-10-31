<?php

namespace ContentToolkit;

use WP_CLI;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Menu, instradamento delle azioni e lock globale: un solo job alla volta su tutto il toolkit.
 */
final class Plugin {

	public const MENU_SLUG = 'content-toolkit';

	private const LOCK = 'content_toolkit_active_job_lock';

	/** @var array<string, Tool> */
	private array $tools = array();

	public readonly string $url;

	public function __construct( private string $file ) {
		$this->url = plugin_dir_url( $file );
		foreach ( array( Tools\BrokenLinks::class, Tools\BrokenShortcodes::class ) as $class ) {
			$this->tools[ $class::SLUG ] = new $class( $this );
		}
	}

	public function hook(): void {
		add_action( 'admin_menu', array( $this, 'add_pages' ) );
		add_action( 'admin_post_content_toolkit_tool_action', array( $this, 'route_action' ) );
		add_action( 'wp_ajax_content_toolkit_poll_job_status', array( $this, 'poll_job' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( $this->file ), array( $this, 'add_action_link' ) );

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			WP_CLI::add_command( 'content-toolkit', new Cli( $this ) );
		}
	}

	public function tool( string $slug ): ?Tool {
		return $this->tools[ $slug ] ?? null;
	}

	public function add_action_link( array $links ): array {
		array_unshift( $links, sprintf( '<a href="%s">%s</a>', esc_url( admin_url( 'admin.php?page=' . self::MENU_SLUG ) ), esc_html__( 'Apri', 'wp-toolkit' ) ) );
		return $links;
	}

	public function add_pages(): void {
		add_menu_page( 'Content Toolkit', 'Content Toolkit', 'manage_options', self::MENU_SLUG, array( $this, 'render_dashboard' ), 'dashicons-admin-tools', 57 );
		foreach ( $this->tools as $tool ) {
			add_submenu_page( self::MENU_SLUG, $tool->title(), $tool->title(), 'manage_options', $tool->page_slug(), array( $tool, 'render_page' ) );
		}
	}

	public function render_dashboard(): void {
		$lock = $this->lock();
		?>
		<div class="wrap">
			<h1>Content Toolkit</h1>
			<p><?php esc_html_e( 'Tool per operazioni batch. I job girano uno alla volta in un worker WP-CLI; file e log stanno nella cartella privata CONTENT_TOOLKIT_STORAGE_DIR.', 'wp-toolkit' ); ?></p>
			<p>
				<?php
				echo esc_html(
					$lock
						/* translators: 1: nome del tool, 2: ID del job. */
						? sprintf( __( 'Job attivo: %1$s (%2$s).', 'wp-toolkit' ), $lock['tool_title'], $lock['job_id'] )
						: __( 'Nessun job attivo.', 'wp-toolkit' )
				);
				?>
			</p>
			<ul>
				<?php foreach ( $this->tools as $tool ) : ?>
					<li><a href="<?php echo esc_url( $tool->page_url() ); ?>"><?php echo esc_html( $tool->title() ); ?></a></li>
				<?php endforeach; ?>
			</ul>
		</div>
		<?php
	}

	public function route_action(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Operazione non consentita.', 'wp-toolkit' ), 403 );
		}

		// Ogni azione verifica il proprio nonce in Tool::handle().
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		$tool = $this->tool( sanitize_key( $_POST['tool'] ?? '' ) );
		if ( ! $tool ) {
			wp_die( esc_html__( 'Tool non trovato.', 'wp-toolkit' ), 404 );
		}

		$tool->handle( sanitize_key( $_POST['toolkit_action'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
	}

	public function poll_job(): void {
		$tool   = $this->tool( sanitize_key( $_POST['tool'] ?? '' ) );
		$job_id = sanitize_text_field( wp_unslash( $_POST['job_id'] ?? '' ) );
		if ( ! current_user_can( 'manage_options' ) || ! $tool || ! check_ajax_referer( $tool->poll_nonce_action( $job_id ), false, false ) ) {
			wp_send_json_error( __( 'Operazione non consentita.', 'wp-toolkit' ), 403 );
		}

		$job = $tool->state()['job'];
		wp_send_json_success(
			array(
				'done' => ! $job || $job['id'] !== $job_id || Tool::is_finished( $job ),
				'html' => $tool->panel_html(),
			)
		);
	}

	public function lock(): ?array {
		$lock = get_option( self::LOCK );
		return is_array( $lock ) && ! empty( $lock['job_id'] ) ? $lock : null;
	}

	/**
	 * add_option() fallisce se il lock esiste già: fra due richieste concorrenti ne vince una.
	 * Un lock senza heartbeat da un'ora appartiene a un processo morto e si può riprendere.
	 */
	public function acquire_lock( string $slug, string $job_id ): true|WP_Error {
		wp_cache_delete( self::LOCK, 'options' );
		$lock = $this->lock();
		if ( $lock && time() - $lock['heartbeat_ts'] > HOUR_IN_SECONDS ) {
			delete_option( self::LOCK );
			$lock = null;
		}
		if ( $lock && ( $lock['tool_slug'] !== $slug || $lock['job_id'] !== $job_id ) ) {
			/* translators: %s: nome del tool che sta lavorando. */
			return new WP_Error( 'content_toolkit_locked', sprintf( __( 'Un altro job del toolkit è attivo (%s): attendi che finisca.', 'wp-toolkit' ), $lock['tool_title'] ) );
		}

		$value = array(
			'tool_slug'    => $slug,
			'tool_title'   => $this->tool( $slug )?->title() ?? $slug,
			'job_id'       => $job_id,
			'heartbeat_ts' => time(),
		);
		if ( $lock ) {
			update_option( self::LOCK, $value, false );
		} elseif ( ! add_option( self::LOCK, $value, '', false ) ) {
			return new WP_Error( 'content_toolkit_locked', __( 'Un altro job del toolkit è appena partito.', 'wp-toolkit' ) );
		}
		return true;
	}

	public function release_lock( string $slug, string $job_id ): void {
		$lock = $this->lock();
		if ( $lock && $lock['tool_slug'] === $slug && $lock['job_id'] === $job_id ) {
			delete_option( self::LOCK );
		}
	}
}
