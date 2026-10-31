<?php

namespace ContentToolkit\Tools;

use ContentToolkit\Html;
use ContentToolkit\Storage;
use ContentToolkit\Tool;
use WP_Error;
use WP_Post;

defined( 'ABSPATH' ) || exit;

/**
 * Toglie dagli articoli gli shortcode indicati e conserva il contenuto racchiuso.
 * Gli shortcode non vengono mai eseguiti; la modalità prudente salta quelli registrati.
 */
final class BrokenShortcodes extends Tool {

	public const SLUG = 'broken-shortcode-manager';

	private const CHUNK = 250;

	private const STATUSES = array( 'publish', 'draft', 'pending', 'future', 'private' );

	public function title(): string {
		return __( 'Shortcode obsoleti', 'wp-toolkit' );
	}

	protected function read_form(): array {
		// Nonce verificato in Tool::handle().
		// phpcs:disable WordPress.Security.NonceVerification.Missing
		return array(
			'patterns'     => sanitize_textarea_field( wp_unslash( $_POST['shortcode_patterns'] ?? '' ) ),
			'safe_removal' => ! empty( $_POST['safe_removal'] ),
			'dry_run'      => ! empty( $_POST['dry_run'] ),
		);
		// phpcs:enable
	}

	/**
	 * Una riga per shortcode; conta solo il nome, con o senza "[" e attributi.
	 */
	protected function job_data( array $form ): array|WP_Error {
		preg_match_all( '/^\s*\[?\s*([\w-]+)/m', $form['patterns'], $matches );
		$tags = array_values( array_unique( array_filter( array_map( 'sanitize_key', $matches[1] ) ) ) );
		if ( ! $tags ) {
			return new WP_Error( 'content_toolkit_shortcodes', __( 'Inserisci almeno uno shortcode, uno per riga.', 'wp-toolkit' ) );
		}

		return array(
			'tags'         => $tags,
			'safe_removal' => $form['safe_removal'],
			'dry_run'      => $form['dry_run'],
		);
	}

	protected function render_form( array $state ): void {
		$form = $state['form'] + array(
			'patterns'     => '',
			'safe_removal' => false,
			'dry_run'      => true,
		);
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<?php $this->action_fields( 'start' ); ?>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="content-toolkit-shortcodes"><?php esc_html_e( 'Shortcode da togliere', 'wp-toolkit' ); ?></label></th>
					<td>
						<textarea id="content-toolkit-shortcodes" name="shortcode_patterns" rows="6" class="large-text code" placeholder="old-gallery&#10;[legacy-button"><?php echo esc_textarea( $form['patterns'] ); ?></textarea>
						<p class="description"><?php esc_html_e( 'Uno per riga. Conta solo il nome: «old-gallery» e «[old-gallery» trovano anche [old-gallery id="3"]. Il contenuto racchiuso resta.', 'wp-toolkit' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Opzioni', 'wp-toolkit' ); ?></th>
					<td>
						<label><input type="checkbox" name="dry_run" value="1" <?php checked( $form['dry_run'] ); ?>> <?php esc_html_e( 'Simulazione: conta gli shortcode senza modificare gli articoli', 'wp-toolkit' ); ?></label><br>
						<label><input type="checkbox" name="safe_removal" value="1" <?php checked( $form['safe_removal'] ); ?>> <?php esc_html_e( 'Prudente: salta gli shortcode ancora registrati da tema o plugin', 'wp-toolkit' ); ?></label>
					</td>
				</tr>
			</table>
			<?php submit_button( __( 'Avvia', 'wp-toolkit' ), 'primary', 'submit', true, $this->is_running( $state ) ? array( 'disabled' => 'disabled' ) : array() ); ?>
		</form>
		<?php
	}

	protected function details_file( array $job ): string {
		return $job['data']['details'] ?? '';
	}

	protected function cleanup( array $job ): void {
		$file = $this->details_file( $job );
		if ( $file && is_file( $file ) ) {
			wp_delete_file( $file );
		}
	}

	protected function stopped_summary( array $job ): string {
		/* translators: %s: conteggi dell'elaborazione. */
		return sprintf( __( 'Interrotto. %s', 'wp-toolkit' ), $this->counts( $job ) );
	}

	protected function step( array $job ): array|WP_Error {
		global $wpdb;

		$data  = $job['data'];
		$where = $wpdb->prepare( "post_type = 'post' AND post_status IN (" . implode( ',', array_fill( 0, count( self::STATUSES ), '%s' ) ) . ')', self::STATUSES );
		if ( ! isset( $data['details'] ) ) {
			$details = Storage::write( self::SLUG, "details-{$job['id']}.txt", '' );
			if ( is_wp_error( $details ) ) {
				return $details;
			}
			$job['total'] = (int) $wpdb->get_var( "SELECT COUNT(ID) FROM {$wpdb->posts} WHERE $where" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $where è preparato sopra.
			$data        += array(
				'details'   => $details,
				'cursor'    => null,
				'updated'   => 0,
				'unchanged' => 0,
				'removed'   => 0,
				'skipped'   => 0,
				'warnings'  => array(),
			);
		}

		// Paginazione per (data, ID): resta stabile anche se gli articoli cambiano durante il job.
		if ( $data['cursor'] ) {
			$where .= $wpdb->prepare( ' AND (post_date < %s OR (post_date = %s AND ID < %d))', $data['cursor'][0], $data['cursor'][0], $data['cursor'][1] );
		}
		$rows = $wpdb->get_results( "SELECT ID, post_date FROM {$wpdb->posts} WHERE $where ORDER BY post_date DESC, ID DESC LIMIT " . self::CHUNK ); // phpcs:ignore WordPress.DB.PreparedSQL -- $where è preparato sopra, CHUNK è una costante.

		foreach ( $rows as $row ) {
			$post   = get_post( $row->ID );
			$result = $this->clean_post( $post, $data['tags'], $data['safe_removal'], $data['dry_run'] );
			if ( is_wp_error( $result ) ) {
				/* translators: 1: ID dell'articolo, 2: messaggio di errore. */
				$data['warnings'][] = sprintf( __( 'Aggiornamento dell\'articolo %1$d non riuscito: %2$s', 'wp-toolkit' ), $post->ID, $result->get_error_message() );
				continue;
			}

			[ $removed, $skipped, $lines ] = $result;
			if ( $removed ) {
				++$data['updated'];
			} else {
				++$data['unchanged'];
			}
			$data['removed'] += $removed;
			$data['skipped'] += $skipped;

			if ( $lines ) {
				$block   = sprintf( "#%d %s\n- %s\n\n", $post->ID, get_the_title( $post ), implode( "\n- ", $lines ) );
				$written = Storage::write( self::SLUG, basename( $data['details'] ), $block, FILE_APPEND );
				if ( is_wp_error( $written ) ) {
					return $written;
				}
			}
		}

		if ( $rows ) {
			$last           = end( $rows );
			$data['cursor'] = array( $last->post_date, (int) $last->ID );
		}
		$job['data']  = $data;
		$job['done'] += count( $rows );
		$job['label'] = $this->counts( $job );
		if ( count( $rows ) < self::CHUNK ) {
			$job['result'] = array(
				/* translators: %s: conteggi dell'elaborazione. */
				'summary'  => sprintf( $data['dry_run'] ? __( 'Simulazione completata. %s', 'wp-toolkit' ) : __( 'Completato. %s', 'wp-toolkit' ), $this->counts( $job ) ),
				'warnings' => $data['warnings'],
			);
		}
		return $job;
	}

	/**
	 * @return array{int, int, string[]}|WP_Error Rimossi, saltati e righe di dettaglio.
	 */
	private function clean_post( WP_Post $post, array $tags, bool $safe_removal, bool $dry_run ): array|WP_Error {
		$content = $post->post_content;
		if ( ! str_contains( $content, '[' ) ) {
			return array( 0, 0, array() );
		}

		$comments = Html::comment_ranges( $content );
		$removed  = 0;
		$skipped  = 0;
		$lines    = array();
		$content  = preg_replace_callback(
			'/' . get_shortcode_regex( $tags ) . '/i',
			static function ( array $found ) use ( $comments, $safe_removal, &$removed, &$skipped, &$lines ): string {
				[ $shortcode, $offset ] = $found[0];
				$tag                    = $found[2][0];
				// [[tag]] è uno shortcode mostrato come testo; i commenti HTML sono contenuto disattivato.
				if ( '[' === $found[1][0] || ']' === $found[6][0] || Html::in_ranges( $offset, $comments ) ) {
					return $shortcode;
				}
				if ( $safe_removal && shortcode_exists( $tag ) ) {
					++$skipped;
					$lines[] = sprintf( 'SALTATO (registrato) %s', $shortcode );
					return $shortcode;
				}

				++$removed;
				$lines[] = sprintf( 'RIMOSSO %s', $shortcode );
				return $found[5][0] ?? '';
			},
			$content,
			flags: PREG_OFFSET_CAPTURE
		);

		if ( $removed && ! $dry_run ) {
			$saved = wp_update_post(
				array(
					'ID'           => $post->ID,
					'post_content' => $content,
				),
				true
			);
			if ( is_wp_error( $saved ) ) {
				return $saved;
			}
		}
		return array( $removed, $skipped, $lines );
	}

	private function counts( array $job ): string {
		$data = $job['data'] + array_fill_keys( array( 'updated', 'unchanged', 'removed', 'skipped' ), 0 );
		return sprintf(
			/* translators: 1: articoli esaminati, 2: totale, 3: modificati, 4: invariati, 5: shortcode tolti, 6: saltati. */
			__( 'Articoli esaminati: %1$d di %2$d, modificati: %3$d, invariati: %4$d, shortcode tolti: %5$d, saltati: %6$d.', 'wp-toolkit' ),
			$job['done'],
			$job['total'],
			$data['updated'],
			$data['unchanged'],
			$data['removed'],
			$data['skipped']
		);
	}
}
