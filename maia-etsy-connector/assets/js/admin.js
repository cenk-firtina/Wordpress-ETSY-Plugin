/* global jQuery, MEC */
( function ( $ ) {
	'use strict';

	function post( action, data ) {
		return $.post(
			MEC.ajaxUrl,
			$.extend( { action: action, nonce: MEC.nonce }, data )
		);
	}

	function collectSelected() {
		var ids = [];
		$( '.mec-cb:checked' ).each( function () {
			ids.push( $( this ).val() );
		} );
		return ids;
	}

	function setRow( pid, text ) {
		$( 'tr[data-product="' + pid + '"] .mec-status' ).text( text );
	}

	// Poll progress for a set of ids until nothing is pending.
	function poll( ids, $status ) {
		if ( ! ids.length ) {
			return;
		}
		var timer = setInterval( function () {
			post( 'mec_progress', { ids: ids } ).done( function ( res ) {
				if ( ! res || ! res.success ) {
					return;
				}
				var d = res.data;
				if ( $status ) {
					$status.text(
						'Toplam: ' + d.total +
						' · Başarılı: ' + d.success +
						' · Bekleyen: ' + d.pending +
						' · Hatalı: ' + d.error +
						' · %' + d.percent
					);
					$( '.mec-progress-bar' ).css( 'width', d.percent + '%' );
				}
				$.each( d.rows, function ( pid, row ) {
					var label = row.state === 'success' ? 'Aktarıldı' : ( row.state === 'error' ? 'Hatalı' : 'Bekliyor' );
					setRow( pid, label );
				} );
				if ( d.pending === 0 ) {
					clearInterval( timer );
				}
			} );
		}, 4000 );
	}

	$( function () {
		// Select all.
		$( '#mec-select-all' ).on( 'change', function () {
			$( '.mec-cb' ).prop( 'checked', $( this ).prop( 'checked' ) );
		} );

		// Bulk transfer.
		$( '#mec-bulk-transfer' ).on( 'click', function ( e ) {
			e.preventDefault();
			var ids = collectSelected();
			if ( ! ids.length ) {
				window.alert( MEC.i18n.noSelection );
				return;
			}
			if ( ! window.confirm( MEC.i18n.confirmBulk ) ) {
				return;
			}
			var $status = $( '.mec-bulk-status' );
			$status.html( '<span class="mec-progress"><span class="mec-progress-bar"></span></span> ' + MEC.i18n.working );
			post( 'mec_enqueue', { ids: ids, op: 'create' } ).done( function ( res ) {
				if ( res && res.success ) {
					$.each( ids, function ( i, pid ) {
						setRow( pid, 'Bekliyor' );
					} );
					poll( ids, $status );
				} else {
					$status.text( ( res && res.data && res.data.message ) || MEC.i18n.error );
				}
			} );
		} );

		// Per-row action (create or update modes).
		$( document ).on( 'click', '.mec-action', function ( e ) {
			e.preventDefault();
			var $btn = $( this );
			var pid = $btn.closest( 'tr' ).data( 'product' );
			var op = $btn.data( 'op' ) || 'create';
			$btn.prop( 'disabled', true );
			setRow( pid, MEC.i18n.working );
			post( 'mec_enqueue', { ids: [ pid ], op: op } ).done( function ( res ) {
				if ( res && res.success ) {
					setRow( pid, 'Bekliyor' );
					poll( [ pid ], null );
				} else {
					setRow( pid, ( res && res.data && res.data.message ) || MEC.i18n.error );
				}
				$btn.prop( 'disabled', false );
			} );
		} );
	} );
}( jQuery ) );
