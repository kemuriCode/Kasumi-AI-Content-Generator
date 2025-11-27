( function ( $ ) {
	$( function () {
		const tabs = $( '#kasumi-ai-tabs' );
		const adminData = window.kasumiAiAdmin || {};
		const wpApi = window.wp || {};

		if ( tabs.length ) {
			tabs.tabs();
		}

		$( '[data-kasumi-tooltip]' ).tooltip( {
			items: '[data-kasumi-tooltip]',
			content: function () {
				return $( this ).attr( 'data-kasumi-tooltip' );
			},
			position: {
				my: 'left+15 center',
				at: 'right center',
			},
		} );

		const fetchModels = function ( control, autoload ) {
			const select = control.find( '[data-kasumi-model]' );
			const provider = select.data( 'kasumi-model' );
			if ( ! provider || ! adminData.ajaxUrl ) {
				return;
			}

			const spinner = control.find( '.kasumi-model-spinner' );
			if ( autoload ) {
				spinner.addClass( 'is-active' );
			}

			const formData = new window.FormData();
			formData.append( 'action', 'kasumi_ai_models' );
			formData.append( 'nonce', adminData.nonce || '' );
			formData.append( 'provider', provider );

			if ( ! wpApi.apiFetch ) {
				return;
			}

			wpApi.apiFetch( {
				url: adminData.ajaxUrl,
				method: 'POST',
				body: formData,
			} ).then( function ( payload ) {
				if ( ! payload.success ) {
					throw new Error( payload.data?.message || adminData.i18n?.error );
				}

				const models = payload.data.models || [];
				const current = select.data( 'current-value' ) || select.val();
				select.empty();

				if ( ! models.length ) {
					select.append(
						$( '<option>' ).text( adminData.i18n?.noModels || 'No models' )
					);
				} else {
					models.forEach( function ( model ) {
						select.append(
							$( '<option>' )
								.val( model.id )
								.text( model.label || model.id )
						);
					} );
				}

				if ( current ) {
					select.val( current );
				}
			} ).catch( function ( error ) {
				window.alert( error.message || adminData.i18n?.error || 'Error' );
			} ).finally( function () {
				spinner.removeClass( 'is-active' );
			} );
		};

		$( '.kasumi-model-control' ).each( function () {
			const control = $( this );
			const refresh = control.find( '.kasumi-refresh-models' );

			refresh.on( 'click', function () {
				fetchModels( control, true );
			} );

			if ( '1' === control.data( 'autoload' ) ) {
				fetchModels( control, false );
			}
		} );
	} );
} )( window.jQuery );
