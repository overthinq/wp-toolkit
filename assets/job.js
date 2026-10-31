// Aggiorna il pannello del job finché è attivo, poi ricarica la pagina col risultato.
( () => {
	const panel = document.getElementById( 'content-toolkit-job' );
	if ( ! panel?.dataset.job ) {
		return;
	}

	const body = new URLSearchParams( {
		action: 'content_toolkit_poll_job_status',
		tool: panel.dataset.tool,
		job_id: panel.dataset.job,
		_ajax_nonce: panel.dataset.nonce,
	} );
	let failures = 0;

	async function poll() {
		try {
			const response = await fetch( window.ajaxurl, { method: 'POST', body } );
			const { success, data } = await response.json();
			if ( ! success ) {
				throw new Error( data );
			}
			if ( data.done ) {
				window.location.reload();
				return;
			}
			failures = 0;
			panel.innerHTML = data.html;
			setTimeout( poll, 1500 );
		} catch {
			failures++;
			setTimeout( poll, Math.min( 8000, 2000 * failures ) );
		}
	}

	setTimeout( poll, 1500 );
} )();
