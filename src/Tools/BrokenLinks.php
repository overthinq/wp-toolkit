<?php

namespace ContentToolkit\Tools;

use ContentToolkit\Html;
use ContentToolkit\Storage;
use ContentToolkit\Tool;
use WP_Error;
use WP_HTML_Tag_Processor;
use WP_Post;

defined( 'ABSPATH' ) || exit;

/**
 * Toglie dagli articoli i link segnalati da un audit (CSV: pagina, URL rotto) e ne
 * conserva il contenuto. Il link originale può restare in un commento HTML.
 */
final class BrokenLinks extends Tool {

	public const SLUG = 'broken-link-manager';

	private const CHUNK = 100;

	private const ANCHOR = '~<a\b(?:[^"\'<>]|"[^"]*"|\'[^\']*\')*>(.*?)</a>~is';

	public function title(): string {
		return __( 'Link rotti', 'wp-toolkit' );
	}

	protected function read_form(): array {
		// Nonce verificato in Tool::handle().
		// phpcs:disable WordPress.Security.NonceVerification.Missing
		return array(
			'dry_run'     => ! empty( $_POST['dry_run'] ),
			'delete_tags' => ! empty( $_POST['delete_tags'] ),
		);
		// phpcs:enable
	}

	protected function job_data( array $form ): array|WP_Error {
		$csv = Storage::upload( self::SLUG, 'csv_file', 'csv' );
		return is_wp_error( $csv ) ? $csv : array( 'csv' => $csv ) + $form;
	}

	protected function render_form( array $state ): void {
		$form = $state['form'] + array(
			'dry_run'     => true,
			'delete_tags' => false,
		);
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" enctype="multipart/form-data">
			<?php $this->action_fields( 'start' ); ?>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="content-toolkit-csv"><?php esc_html_e( 'CSV', 'wp-toolkit' ); ?></label></th>
					<td>
						<input type="file" id="content-toolkit-csv" name="csv_file" accept=".csv,text/csv" required>
						<p class="description"><?php esc_html_e( 'Riga di intestazione, poi due colonne: URL della pagina, URL rotto. Il file resta nella cartella privata e si cancella a fine job.', 'wp-toolkit' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Opzioni', 'wp-toolkit' ); ?></th>
					<td>
						<label><input type="checkbox" name="dry_run" value="1" <?php checked( $form['dry_run'] ); ?>> <?php esc_html_e( 'Simulazione: conta articoli e link senza modificarli', 'wp-toolkit' ); ?></label><br>
						<label><input type="checkbox" name="delete_tags" value="1" <?php checked( $form['delete_tags'] ); ?>> <?php esc_html_e( 'Elimina il tag <a> invece di conservarlo in un commento HTML', 'wp-toolkit' ); ?></label>
					</td>
				</tr>
			</table>
			<?php submit_button( __( 'Avvia', 'wp-toolkit' ), 'primary', 'submit', true, $this->is_running( $state ) ? array( 'disabled' => 'disabled' ) : array() ); ?>
		</form>
		<?php
	}

	protected function step( array $job ): array|WP_Error {
		return isset( $job['data']['items'] ) ? $this->clean_chunk( $job ) : $this->prepare( $job );
	}

	protected function cleanup( array $job ): void {
		foreach ( array( $job['data']['csv'], $job['data']['items'] ?? '' ) as $file ) {
			if ( $file && is_file( $file ) ) {
				wp_delete_file( $file );
			}
		}
	}

	protected function stopped_summary( array $job ): string {
		/* translators: %s: conteggi dell'elaborazione. */
		return sprintf( __( 'Interrotto. %s', 'wp-toolkit' ), $this->counts( $job ) );
	}

	/**
	 * Primo blocco: legge il CSV, raggruppa i link per pagina e trova gli articoli.
	 * Le pagine vanno su file, ordinate dagli articoli più recenti.
	 */
	private function prepare( array $job ): array|WP_Error {
		$host   = strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
		$handle = fopen( $job['data']['csv'], 'r' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		if ( ! $handle ) {
			return new WP_Error( 'content_toolkit_csv', __( 'Impossibile leggere il CSV.', 'wp-toolkit' ) );
		}

		$pages = array();
		fgetcsv( $handle, null, ',', '"', '' );
		while ( ( $row = fgetcsv( $handle, null, ',', '"', '' ) ) !== false ) { // phpcs:ignore Generic.CodeAnalysis.AssignmentInCondition.FoundInWhileCondition
			$page   = trim( $row[0] ?? '' );
			$broken = trim( $row[1] ?? '' );
			if ( '' !== $broken && strtolower( (string) wp_parse_url( $page, PHP_URL_HOST ) ) === $host ) {
				$pages[ $page ][ $broken ] = true;
			}
		}
		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		if ( ! $pages ) {
			return new WP_Error( 'content_toolkit_csv', __( 'Il CSV non contiene righe valide per questo sito.', 'wp-toolkit' ) );
		}

		$items = array();
		foreach ( $pages as $page => $broken ) {
			$post    = self::find_post( $page );
			$items[] = array(
				'page'    => $page,
				'broken'  => array_keys( $broken ),
				'post_id' => $post?->ID ?? 0,
				'date'    => $post?->post_date ?? '',
			);
		}
		usort( $items, static fn( $a, $b ) => array( $b['post_id'] > 0, $b['date'], $b['post_id'] ) <=> array( $a['post_id'] > 0, $a['date'], $a['post_id'] ) );

		$file = Storage::write( self::SLUG, "items-{$job['id']}.json", (string) wp_json_encode( $items ) );
		if ( is_wp_error( $file ) ) {
			return $file;
		}

		$job['data'] += array(
			'items'     => $file,
			'found'     => 0,
			'updated'   => 0,
			'unchanged' => 0,
			'missing'   => 0,
			'anchors'   => 0,
			'warnings'  => array(),
		);
		$job['total'] = count( $items );
		$job['label'] = __( 'CSV analizzato.', 'wp-toolkit' );
		return $job;
	}

	private function clean_chunk( array $job ): array|WP_Error {
		$items = json_decode( (string) file_get_contents( $job['data']['items'] ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		if ( ! is_array( $items ) ) {
			return new WP_Error( 'content_toolkit_job_data', __( 'Dati temporanei del job mancanti o non validi.', 'wp-toolkit' ) );
		}

		$data = $job['data'];
		foreach ( array_slice( $items, $job['done'], self::CHUNK ) as $item ) {
			$post   = $item['post_id'] ? get_post( $item['post_id'] ) : null;
			$post ??= self::find_post( $item['page'] );
			if ( ! $post ) {
				++$data['missing'];
				/* translators: %s: URL della pagina. */
				$data['warnings'][] = sprintf( __( 'Articolo non trovato: %s', 'wp-toolkit' ), $item['page'] );
				continue;
			}

			++$data['found'];
			[ $content, $count ] = self::remove_anchors( $post->post_content, $item['broken'], $data['delete_tags'] );
			if ( ! $count ) {
				++$data['unchanged'];
				continue;
			}

			if ( ! $data['dry_run'] ) {
				$saved = wp_update_post(
					array(
						'ID'           => $post->ID,
						'post_content' => $content,
					),
					true
				);
				if ( is_wp_error( $saved ) ) {
					/* translators: 1: ID dell'articolo, 2: messaggio di errore. */
					$data['warnings'][] = sprintf( __( 'Aggiornamento dell\'articolo %1$d non riuscito: %2$s', 'wp-toolkit' ), $post->ID, $saved->get_error_message() );
					continue;
				}
			}

			++$data['updated'];
			$data['anchors'] += $count;
		}
		$job['data'] = $data;

		$job['done']  = min( $job['total'], $job['done'] + self::CHUNK );
		$job['label'] = $this->counts( $job );
		if ( $job['done'] >= $job['total'] ) {
			$job['result'] = array(
				/* translators: %s: conteggi dell'elaborazione. */
				'summary'  => sprintf( $job['data']['dry_run'] ? __( 'Simulazione completata. %s', 'wp-toolkit' ) : __( 'Completato. %s', 'wp-toolkit' ), $this->counts( $job ) ),
				'warnings' => $job['data']['warnings'],
			);
		}
		return $job;
	}

	private function counts( array $job ): string {
		$data = $job['data'] + array_fill_keys( array( 'found', 'updated', 'unchanged', 'missing', 'anchors' ), 0 );
		$text = $data['dry_run']
			/* translators: 1: pagine, 2: articoli trovati, 3: da modificare, 4: invariati, 5: non trovati, 6: link. */
			? __( 'Pagine: %1$d, articoli trovati: %2$d, da modificare: %3$d, invariati: %4$d, non trovati: %5$d, link da togliere: %6$d.', 'wp-toolkit' )
			/* translators: 1: pagine, 2: articoli trovati, 3: modificati, 4: invariati, 5: non trovati, 6: link. */
			: __( 'Pagine: %1$d, articoli trovati: %2$d, modificati: %3$d, invariati: %4$d, non trovati: %5$d, link tolti: %6$d.', 'wp-toolkit' );

		return sprintf( $text, $job['total'], $data['found'], $data['updated'], $data['unchanged'], $data['missing'], $data['anchors'] );
	}

	private static function find_post( string $url ): ?WP_Post {
		$id = url_to_postid( $url );
		if ( $id ) {
			return get_post( $id );
		}

		$path = trim( (string) wp_parse_url( $url, PHP_URL_PATH ), '/' );
		if ( '' === $path ) {
			return null;
		}
		$types = array_diff( get_post_types( array( 'public' => true ) ), array( 'attachment' ) );
		return get_page_by_path( $path, OBJECT, array_values( $types ) );
	}

	/**
	 * Confronta gli href decodificati con gli URL rotti, rispettando maiuscole di percorso e query.
	 *
	 * @return array{string, int} Contenuto aggiornato e numero di link tolti.
	 */
	public static function remove_anchors( string $content, array $broken, bool $delete_tags ): array {
		$broken   = array_fill_keys( $broken, true );
		$comments = Html::comment_ranges( $content );
		$count    = 0;

		$content = preg_replace_callback(
			self::ANCHOR,
			static function ( array $found ) use ( $broken, $comments, $delete_tags, &$count ): string {
				$anchor = new WP_HTML_Tag_Processor( $found[0][0] );
				$anchor->next_tag( 'A' );
				$href = trim( (string) $anchor->get_attribute( 'href' ) );
				if ( ! isset( $broken[ $href ] ) || Html::in_ranges( $found[0][1], $comments ) ) {
					return $found[0][0];
				}

				++$count;
				if ( $delete_tags ) {
					return $found[1][0];
				}
				// "--" chiuderebbe il commento prima del tempo.
				return '<!-- ' . str_replace( '--', '- -', $found[0][0] ) . " -->{$found[1][0]}";
			},
			$content,
			flags: PREG_OFFSET_CAPTURE
		);

		return array( $content, $count );
	}
}
