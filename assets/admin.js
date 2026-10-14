/** Hydra AI 设置页交互。 */
( function ( $ ) {
	'use strict';
	var config = window.HydraAdmin || {}, i18n = config.i18n || {};
	function sprintfOne( text, value ) { return String( text ).replace( '%s', function () { return String( value ); } ); }
	function request( action, data ) {
		return $.post( config.ajaxUrl, $.extend( { action: action, nonce: config.nonce }, data || {} ) ).then( function ( response ) {
			return response && response.success ? response : $.Deferred().reject( response ).promise();
		} );
	}
	function errorMessage( response ) {
		var result = response && ( response.responseJSON || response );
		return result && result.data && result.data.message ? result.data.message : i18n.networkError;
	}
	function showNotice( type, text ) { if ( $( '#hydra-entry-dialog' ).prop( 'open' ) ) { $( '#hydra-dialog-notice' ).text( text ).prop( 'hidden', false ); } var notice = $( '#hydra-notice' ); notice.attr( 'class', 'hydra-notice notice notice-' + type ).text( text ).prop( 'hidden', false ); window.clearTimeout( notice.data( 'timer' ) ); notice.data( 'timer', window.setTimeout( function () { notice.prop( 'hidden', true ); }, 6000 ) ); }
	function profileId() { return $( '#hydra-profile-select' ).val() || ''; }
	function openAddDialog() { $( '#hydra-dialog-notice' ).prop( 'hidden', true ); $( '#hydra-dialog-title' ).text( i18n.dialogAdd ); $( '#hydra-entry-form' )[ 0 ].reset(); $( '#hydra-field-id' ).val( '' ); $( '#hydra-field-api-key' ).val( '' ).removeAttr( 'placeholder' ); $( '#hydra-entry-dialog' )[ 0 ].showModal(); }
	function openEditDialog( row ) { $( '#hydra-dialog-notice' ).prop( 'hidden', true ); $( '#hydra-dialog-title' ).text( i18n.dialogEdit ); $( '#hydra-field-id' ).val( row.data( 'id' ) ); $( '#hydra-field-name' ).val( row.data( 'name' ) ); $( '#hydra-field-protocol' ).val( row.data( 'protocol' ) ); $( '#hydra-field-endpoint' ).val( row.data( 'endpoint' ) ); $( '#hydra-field-api-key' ).val( '' ).attr( 'placeholder', row.data( 'hasKey' ) ? ( i18n.keyExists || '' ) : '' ); $( '#hydra-field-model' ).val( row.data( 'model' ) ); $( '#hydra-field-enabled' ).prop( 'checked', !! row.data( 'enabled' ) ); $( '#hydra-entry-dialog' )[ 0 ].showModal(); }
	function collectEntry() { return { profile_id: profileId(), id: $( '#hydra-field-id' ).val(), name: $( '#hydra-field-name' ).val(), protocol: $( '#hydra-field-protocol' ).val(), endpoint: $( '#hydra-field-endpoint' ).val(), api_key: $( '#hydra-field-api-key' ).val(), model: $( '#hydra-field-model' ).val(), enabled: $( '#hydra-field-enabled' ).prop( 'checked' ) ? 1 : 0 }; }
	function saveOrder( table ) { var order = []; table.find( 'tbody tr' ).each( function () { order.push( String( $( this ).data( 'id' ) ) ); } ); request( 'hydra_ai_reorder', { profile_id: profileId(), order: order } ).fail( function ( response ) { showNotice( 'error', errorMessage( response ) ); } ); }
	$( function () {
		var table = $( '#hydra-entries-table' );
		if ( $.fn.sortable && table.length ) { table.find( 'tbody' ).sortable( { handle: '.hydra-drag-handle', axis: 'y', update: function () { table.find( 'tbody tr' ).each( function ( i ) { $( this ).find( '.hydra-order-num' ).text( i + 1 ); } ); saveOrder( table ); } } ); }
		function navigateProfile( id ) {
			var url = new URL( window.location.href );
			url.searchParams.set( 'profile', id );
			window.location.assign( url.href );
		}
		function profileAction( action, data, onSuccess ) {
			var controls = $( '.hydra-profile-controls button, .hydra-profile-controls input, .hydra-profile-controls select' );
			controls.prop( 'disabled', true );
			request( action, data ).done( onSuccess ).fail( function ( response ) {
				controls.prop( 'disabled', false );
				if ( ! profileId() ) { $( '#hydra-profile-delete, #hydra-profile-rename' ).prop( 'disabled', true ); }
				showNotice( 'error', errorMessage( response ) );
			} );
		}
		$( '#hydra-profile-select' ).on( 'change', function () { navigateProfile( $( this ).val() ); } );
		$( '#hydra-profile-add' ).on( 'click', function () {
			profileAction( 'hydra_ai_add_profile', { name: $( '#hydra-profile-name' ).val() }, function ( response ) { navigateProfile( response.data.id ); } );
		} );
		$( '#hydra-profile-rename' ).on( 'click', function () {
			profileAction( 'hydra_ai_rename_profile', { profile_id: profileId(), name: $( '#hydra-profile-name' ).val() }, function () { window.location.reload(); } );
		} );
		$( '#hydra-profile-delete' ).on( 'click', function () {
			var name = $( '#hydra-profile-select option:selected' ).text();
			if ( ! window.confirm( sprintfOne( i18n.confirmProfileDelete, name ) ) ) { return; }
			profileAction( 'hydra_ai_delete_profile', { profile_id: profileId() }, function () { window.location.reload(); } );
		} );

		$( '#hydra-add-entry' ).on( 'click', openAddDialog );
		$( document ).on( 'click', '.hydra-edit', function () { openEditDialog( $( this ).closest( 'tr' ) ); } );
		$( '#hydra-dialog-cancel' ).on( 'click', function () { $( '#hydra-entry-dialog' )[ 0 ].close(); } );
		$( '#hydra-entry-form' ).on( 'submit', function ( event ) { event.preventDefault(); var button = $( '#hydra-dialog-save' ).prop( 'disabled', true ); request( 'hydra_ai_save_entry', collectEntry() ).done( function () { window.location.reload(); } ).fail( function ( response ) { button.prop( 'disabled', false ); showNotice( 'error', errorMessage( response ) ); } ); } );
		$( document ).on( 'click', '.hydra-delete', function () { var row = $( this ).closest( 'tr' ); if ( ! window.confirm( sprintfOne( i18n.confirmDelete || '', row.data( 'name' ) ) ) ) { return; } request( 'hydra_ai_delete_entry', { profile_id: profileId(), id: row.data( 'id' ) } ).done( function () { window.location.reload(); } ).fail( function ( response ) { showNotice( 'error', errorMessage( response ) ); } ); } );
		$( document ).on( 'change', '.hydra-toggle-enabled', function () { var row = $( this ).closest( 'tr' ), checkbox = $( this ).prop( 'disabled', true ); request( 'hydra_ai_save_entry', { profile_id: profileId(), id: row.data( 'id' ), name: row.data( 'name' ), protocol: row.data( 'protocol' ), endpoint: row.data( 'endpoint' ), api_key: '', model: row.data( 'model' ), enabled: this.checked ? 1 : 0 } ).done( function () { row.data( 'enabled', checkbox.prop( 'checked' ) ? 1 : 0 ).toggleClass( 'hydra-row-disabled', ! checkbox.prop( 'checked' ) ); } ).fail( function ( response ) { checkbox.prop( 'checked', ! checkbox.prop( 'checked' ) ); showNotice( 'error', errorMessage( response ) ); } ).always( function () { checkbox.prop( 'disabled', false ); } ); } );
		$( document ).on( 'click', '.hydra-test', function () { var button = $( this ), result = button.closest( 'td' ).find( '.hydra-test-result' ); button.prop( 'disabled', true ); result.removeClass( 'hydra-ok hydra-error' ).text( i18n.testing || '' ); request( 'hydra_ai_test_entry', { profile_id: profileId(), id: button.data( 'id' ) } ).done( function ( response ) { var data = response.data || {}; result.addClass( data.ok ? 'hydra-ok' : 'hydra-error' ).text( sprintfOne( data.ok ? ( i18n.testSuccess || '' ) : ( i18n.testFailed || '' ), data.message || '' ) ); } ).fail( function ( response ) { result.addClass( 'hydra-error' ).text( sprintfOne( i18n.testFailed || '', errorMessage( response ) ) ); } ).always( function () { button.prop( 'disabled', false ); } ); } );
	} );
}( jQuery ) );
