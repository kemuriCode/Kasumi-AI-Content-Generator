( function ( wp ) {
	const output = document.getElementById( 'kasumi-ai-preview-output' );
	const btnText = document.getElementById( 'kasumi-ai-preview-text' );
	const btnImage = document.getElementById( 'kasumi-ai-preview-image' );

	if (
		! output ||
		! btnText ||
		! btnImage ||
		! window.kasumiAiPreview ||
		! wp ||
		! wp.apiFetch
	) {
		return;
	}

	const { apiFetch } = wp;

	const setLoading = () => {
		output.innerHTML =
			'<div class="notice notice-info inline">' +
			'<span class="spinner is-active" role="status"></span>' +
			'<p>' +
			window.kasumiAiPreview.i18n.loading +
			'</p></div>';
	};

	const setError = ( message ) => {
		output.innerHTML =
			'<div class="notice notice-error"><p>' +
			( message || window.kasumiAiPreview.i18n.error ) +
			'</p></div>';
	};

	const escapeHtml = ( str ) =>
		String( str || '' )
			.replace( /&/g, '&amp;' )
			.replace( /</g, '&lt;' )
			.replace( />/g, '&gt;' )
			.replace( /"/g, '&quot;' )
			.replace( /'/g, '&#039;' );

	const renderText = ( article ) => {
		output.innerHTML =
			'<article class="kasumi-ai-preview-article">' +
			( article.title ? '<h3>' + escapeHtml( article.title ) + '</h3>' : '' ) +
			( article.excerpt ? '<p><em>' + escapeHtml( article.excerpt ) + '</em></p>' : '' ) +
			( article.content
				? '<div class="kasumi-ai-preview-content">' + article.content + '</div>'
				: '' ) +
			'</article>';
	};

	const renderImage = ( image, article ) => {
		output.innerHTML =
			'<div class="kasumi-ai-preview-image">' +
			'<img src="' + image + '" alt="' + escapeHtml( article?.title || '' ) + '">' +
			( article?.title ? '<p>' + escapeHtml( article.title ) + '</p>' : '' ) +
			'</div>';
	};

	const requestPreview = ( type ) => {
		setLoading();
		const formData = new window.FormData();
		formData.append( 'action', 'kasumi_ai_preview' );
		formData.append( 'nonce', window.kasumiAiPreview.nonce );
		formData.append( 'type', type );

		apiFetch( {
			url: window.kasumiAiPreview.ajaxUrl,
			method: 'POST',
			body: formData,
		} )
			.then( ( payload ) => {
				if ( ! payload.success ) {
					throw new Error( payload.data?.message );
				}

				if ( 'image' === type ) {
					renderImage( payload.data.image, payload.data.article );
				} else {
					renderText( payload.data.article );
				}
			} )
			.catch( ( error ) => {
				setError( error?.message );
			} );
	};

	btnText.addEventListener( 'click', () => requestPreview( 'text' ) );
	btnImage.addEventListener( 'click', () => requestPreview( 'image' ) );
} )( window.wp || undefined );
