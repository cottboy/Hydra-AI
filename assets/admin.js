/**
 * Hydra AI 设置页脚本：列表排序、增删改、启用开关与连通性测试。
 *
 * 所有用户输入均以后端清洗结果为准，前端仅做基本的交互校验。
 */
( function ( $ ) {
	'use strict';

	var config = window.HydraAdmin || {};
	var i18n = config.i18n || {};
	var defaults = config.defaults || {};

	/**
	 * 把字符串中的第一个 %s 替换为参数。
	 *
	 * @param {string} text 含 %s 占位符的文本。
	 * @param {string} value 替换值。
	 * @return {string} 替换后的文本。
	 */
	function sprintfOne( text, value ) {
		return String( text ).replace( '%s', value );
	}

	/**
	 * 发送 AJAX 请求。
	 *
	 * @param {string} action 动作名。
	 * @param {Object} data 附加数据。
	 * @return {Promise} jQuery Promise。
	 */
	function request( action, data ) {
		return $.post(
			config.ajaxUrl,
			$.extend( { action: action, nonce: config.nonce }, data || {} )
		);
	}

	/**
	 * 从 AJAX 错误响应中提取可读信息。
	 *
	 * @param {Object} response 响应对象。
	 * @return {string} 错误信息。
	 */
	function errorMessage( response ) {
		if ( response && response.responseJSON && response.responseJSON.data && response.responseJSON.data.message ) {
			return response.responseJSON.data.message;
		}
		return i18n.networkError || '请求失败。';
	}

	/**
	 * 更新端点提示与占位符。
	 */
	function updateProtocolHints() {
		var protocol = $( '#hydra-field-protocol' ).val();
		var preset = defaults[ protocol ] || {};

		$( '.hydra-endpoint-hint' ).text( preset.hint || '' );
		$( '#hydra-field-endpoint' ).attr( 'placeholder', preset.endpoint || '' );
		$( '#hydra-field-model' ).attr( 'placeholder', preset.model || '' );
	}

	/**
	 * 以新增模式打开弹窗。
	 */
	function openAddDialog() {
		$( '#hydra-dialog-title' ).text( i18n.dialogAdd || '添加供应商' );
		$( '#hydra-entry-form' )[ 0 ].reset();
		$( '#hydra-field-id' ).val( '' );
		$( '#hydra-field-api-key' ).val( '' );
		updateProtocolHints();
		$( '#hydra-entry-dialog' )[ 0 ].showModal();
	}

	/**
	 * 以编辑模式打开弹窗。
	 *
	 * @param {jQuery} $row 当前行。
	 */
	function openEditDialog( $row ) {
		$( '#hydra-dialog-title' ).text( i18n.dialogEdit || '编辑供应商' );
		$( '#hydra-field-id' ).val( $row.data( 'id' ) );
		$( '#hydra-field-name' ).val( $row.data( 'name' ) );
		$( '#hydra-field-protocol' ).val( $row.data( 'protocol' ) );
		$( '#hydra-field-endpoint' ).val( $row.data( 'endpoint' ) );
		$( '#hydra-field-api-key' ).val( '' ).attr( 'placeholder', $row.data( 'hasKey' ) ? ( i18n.keyExists || '' ) : '' );
		$( '#hydra-field-model' ).val( $row.data( 'model' ) );
		$( '#hydra-field-enabled' ).prop( 'checked', !! $row.data( 'enabled' ) );
		updateProtocolHints();
		$( '#hydra-entry-dialog' )[ 0 ].showModal();
	}

	/**
	 * 收集表单数据。
	 *
	 * @return {Object} 表单数据。
	 */
	function collectForm() {
		return {
			id: $( '#hydra-field-id' ).val(),
			name: $( '#hydra-field-name' ).val(),
			protocol: $( '#hydra-field-protocol' ).val(),
			endpoint: $( '#hydra-field-endpoint' ).val(),
			api_key: $( '#hydra-field-api-key' ).val(),
			model: $( '#hydra-field-model' ).val(),
			enabled: $( '#hydra-field-enabled' ).prop( 'checked' ) ? 1 : 0
		};
	}

	/**
	 * 保存拖拽后的顺序。
	 *
	 * @param {jQuery} $table 列表表格。
	 */
	function saveOrder( $table ) {
		var order = [];
		$table.find( 'tbody tr' ).each( function () {
			order.push( String( $( this ).data( 'id' ) ) );
		} );
		refreshOrderNumbers( $table );

		request( 'hydra_ai_reorder', { order: order } ).fail( function () {
			showNotice( 'error', i18n.reorderFailed || '' );
		} );
	}

	/**
	 * 刷新行的优先级序号。
	 *
	 * @param {jQuery} $table 列表表格。
	 */
	function refreshOrderNumbers( $table ) {
		$table.find( 'tbody tr' ).each( function ( index ) {
			$( this ).find( '.hydra-order-num' ).text( index + 1 );
		} );
	}

	/**
	 * 在页面顶部显示提示信息。
	 *
	 * @param {string} type notice 类型（success / error）。
	 * @param {string} text 文本。
	 */
	function showNotice( type, text ) {
		var $notice = $( '#hydra-notice' );
		$notice
			.attr( 'class', 'hydra-notice notice notice-' + type )
			.text( text )
			.prop( 'hidden', false );

		window.clearTimeout( $notice.data( 'timer' ) );
		$notice.data( 'timer', window.setTimeout( function () {
			$notice.prop( 'hidden', true );
		}, 6000 ) );
	}

	$( function () {
		var $table = $( '#hydra-entries-table' );

		// 拖拽排序。
		if ( $.fn.sortable && $table.length ) {
			$table.find( 'tbody' ).sortable( {
				handle: '.hydra-drag-handle',
				axis: 'y',
				cursor: 'move',
				update: function () {
					saveOrder( $table );
				}
			} );
		}

		// 添加供应商。
		$( '#hydra-add-entry' ).on( 'click', openAddDialog );

		// 编辑供应商。
		$( document ).on( 'click', '.hydra-edit', function () {
			openEditDialog( $( this ).closest( 'tr' ) );
		} );

		// 取消编辑。
		$( '#hydra-dialog-cancel' ).on( 'click', function () {
			$( '#hydra-entry-dialog' )[ 0 ].close();
		} );

		// 协议切换时更新提示。
		$( '#hydra-field-protocol' ).on( 'change', updateProtocolHints );

		// 保存表单。
		$( '#hydra-entry-form' ).on( 'submit', function ( event ) {
			event.preventDefault();

			var $button = $( '#hydra-dialog-save' ).prop( 'disabled', true );

			request( 'hydra_ai_save_entry', collectForm() )
				.done( function () {
					window.location.reload();
				} )
				.fail( function ( response ) {
					$button.prop( 'disabled', false );
					showNotice( 'error', sprintfOne( i18n.saveFailed || '', errorMessage( response ) ) );
				} );
		} );

		// 删除供应商。
		$( document ).on( 'click', '.hydra-delete', function () {
			var $row = $( this ).closest( 'tr' );
			var name = String( $row.data( 'name' ) );

			if ( ! window.confirm( sprintfOne( i18n.confirmDelete || '', name ) ) ) {
				return;
			}

			request( 'hydra_ai_delete_entry', { id: $row.data( 'id' ) } )
				.done( function () {
					window.location.reload();
				} )
				.fail( function ( response ) {
					showNotice( 'error', sprintfOne( i18n.saveFailed || '', errorMessage( response ) ) );
				} );
		} );

		// 启用开关。
		$( document ).on( 'change', '.hydra-toggle-enabled', function () {
			var $row = $( this ).closest( 'tr' );
			var $checkbox = $( this );
			var enabled = this.checked ? 1 : 0;

			request(
				'hydra_ai_save_entry',
				{
					id: $row.data( 'id' ),
					name: $row.data( 'name' ),
					protocol: $row.data( 'protocol' ),
					endpoint: $row.data( 'endpoint' ),
					api_key: '',
					model: $row.data( 'model' ),
					enabled: enabled
				}
			).done( function () {
				$row.data( 'enabled', enabled ? 1 : 0 ).attr( 'data-enabled', enabled ? '1' : '0' );
			} ).fail( function ( response ) {
				// 失败时回滚开关状态。
				$checkbox.prop( 'checked', ! $checkbox.prop( 'checked' ) );
				showNotice( 'error', sprintfOne( i18n.saveFailed || '', errorMessage( response ) ) );
			} );
		} );

		// 连通性测试。
		$( document ).on( 'click', '.hydra-test', function () {
			var $button = $( this );
			var $result = $button.closest( 'td' ).find( '.hydra-test-result' );

			$button.prop( 'disabled', true ).addClass( 'hydra-testing' );
			$result.removeClass( 'hydra-ok hydra-error' ).text( i18n.testing || '' );

			request( 'hydra_ai_test_entry', { id: $button.data( 'id' ) } )
				.done( function ( response ) {
					var data = ( response && response.data ) || {};
					if ( data.ok ) {
						$result.addClass( 'hydra-ok' ).text( sprintfOne( i18n.testSuccess || '', data.message || '' ) );
					} else {
						$result.addClass( 'hydra-error' ).text( sprintfOne( i18n.testFailed || '', data.message || '' ) );
					}
				} )
				.fail( function ( response ) {
					$result.addClass( 'hydra-error' ).text( sprintfOne( i18n.testFailed || '', errorMessage( response ) ) );
				} )
				.always( function () {
					$button.prop( 'disabled', false ).removeClass( 'hydra-testing' );
				} );
		} );
	} );
}( jQuery ) );
