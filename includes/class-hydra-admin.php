<?php
/** Hydra AI 设置页：命名配置和接口条目管理。 */

defined( 'ABSPATH' ) || exit;

class Hydra_Admin {
	const NONCE_ACTION = 'hydra_ai_admin';

	public function register_menu(): void {
		add_options_page( __( 'Hydra AI', 'hydra-ai' ), __( 'Hydra AI', 'hydra-ai' ), 'manage_options', HYDRA_AI_SLUG, array( $this, 'render_page' ) );
	}

	public function enqueue_assets( string $hook_suffix ): void {
		if ( 'settings_page_' . HYDRA_AI_SLUG !== $hook_suffix ) { return; }
		wp_enqueue_style( 'hydra-ai-admin', HYDRA_AI_URL . 'assets/admin.css', array(), HYDRA_AI_VERSION );
		wp_enqueue_script( 'jquery-ui-sortable' );
		wp_enqueue_script( 'hydra-ai-admin', HYDRA_AI_URL . 'assets/admin.js', array( 'jquery', 'jquery-ui-sortable' ), HYDRA_AI_VERSION, true );
		wp_localize_script( 'hydra-ai-admin', 'HydraAdmin', $this->get_script_data() );
	}

	private function get_script_data(): array {
		return array(
			'ajaxUrl' => admin_url( 'admin-ajax.php' ),
			'nonce' => wp_create_nonce( self::NONCE_ACTION ),
			'i18n' => array(
				'confirmDelete' => __( '确定要删除“%s”吗？删除后不可恢复。', 'hydra-ai' ),
				'confirmProfileDelete' => __( '确定要删除配置“%s”吗？WordPress 连接中的对应供应商也会消失。', 'hydra-ai' ),
				'reorderFailed' => __( '排序保存失败，请刷新页面重试。', 'hydra-ai' ),
				'testSuccess' => __( '连接成功：%s', 'hydra-ai' ),
				'testFailed' => __( '连接失败：%s', 'hydra-ai' ),
				'keyExists' => __( '已设置密钥', 'hydra-ai' ),
				'networkError' => __( '请求失败，请检查网络后重试。', 'hydra-ai' ),
				'testing' => __( '测试中…', 'hydra-ai' ),
				'dialogAdd' => __( '添加接口', 'hydra-ai' ),
				'dialogEdit' => __( '编辑接口', 'hydra-ai' ),
			),
		);
	}

	private function current_profile_id(): string {
		$id = isset( $_GET['profile'] ) && is_string( $_GET['profile'] ) ? sanitize_key( wp_unslash( $_GET['profile'] ) ) : '';
		if ( $id && Hydra_Settings::get_profile( $id ) ) {
			Hydra_Settings::set_active_profile( $id );
			return $id;
		}
		return Hydra_Settings::get_active_profile_id();
	}

	public function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) { return; }
		$profiles = Hydra_Settings::get_profiles();
		$profile_id = $this->current_profile_id();
		$profile = $profile_id ? Hydra_Settings::get_profile( $profile_id ) : null;
		$entries = $profile ? Hydra_Settings::get_profile_entries( $profile_id ) : array();
		$ai_missing = ! class_exists( '\WordPress\AiClient\AiClient' );
		$last_report = Hydra_Settings::get_last_failover( $profile_id );
		?>
		<div class="wrap hydra-ai-wrap">
			<h1 class="hydra-page-title">
				<span><?php esc_html_e( 'Hydra AI', 'hydra-ai' ); ?></span>
				<span class="hydra-profile-controls">
					<label class="screen-reader-text" for="hydra-profile-select"><?php esc_html_e( '切换配置', 'hydra-ai' ); ?></label>
					<select id="hydra-profile-select" aria-label="<?php esc_attr_e( '切换配置', 'hydra-ai' ); ?>">
						<?php foreach ( $profiles as $item ) : ?>
							<option value="<?php echo esc_attr( $item['id'] ); ?>" <?php selected( $item['id'], $profile_id ); ?>><?php echo esc_html( $item['name'] ); ?></option>
						<?php endforeach; ?>
					</select>
					<label class="screen-reader-text" for="hydra-profile-name"><?php esc_html_e( '配置名称', 'hydra-ai' ); ?></label>
					<input type="text" id="hydra-profile-name" value="<?php echo esc_attr( $profile['name'] ?? '' ); ?>" placeholder="<?php esc_attr_e( '配置名称', 'hydra-ai' ); ?>" maxlength="100" />
					<button type="button" class="button" id="hydra-profile-add"><?php esc_html_e( '新增', 'hydra-ai' ); ?></button>
					<button type="button" class="button" id="hydra-profile-delete" <?php disabled( ! $profile ); ?>><?php esc_html_e( '删除', 'hydra-ai' ); ?></button>
					<button type="button" class="button" id="hydra-profile-rename" <?php disabled( ! $profile ); ?>><?php esc_html_e( '重命名', 'hydra-ai' ); ?></button>
				</span>
			</h1>
			<?php if ( $profile ) : ?>
			<p class="description"><?php esc_html_e( '当前配置会在 WordPress 连接中注册为同名供应商。下面的接口按顺序尝试，失败时自动使用下一个。', 'hydra-ai' ); ?></p>
			<?php else : ?>
			<p class="description"><?php esc_html_e( '请在右上方输入名称并点击“新增”创建配置。', 'hydra-ai' ); ?></p>
			<?php endif; ?>
			<?php if ( $ai_missing ) : ?><div class="notice notice-warning"><p><?php esc_html_e( '当前 WordPress 未加载 AI 客户端，连接注册暂时不可用。', 'hydra-ai' ); ?></p></div><?php endif; ?>
			<?php if ( $last_report ) : $this->render_failover_report( $last_report );
endif; ?>
			<div id="hydra-notice" class="hydra-notice" hidden></div>
			<?php if ( $profile ) : ?>
				<p><button type="button" class="page-title-action" id="hydra-add-entry"><?php esc_html_e( '添加接口', 'hydra-ai' ); ?></button></p>
				<?php if ( $entries ) : ?>
				<div class="hydra-table-scroll"><table class="widefat striped hydra-entries-table" id="hydra-entries-table"><thead><tr><th class="hydra-col-order"><?php esc_html_e( '优先级', 'hydra-ai' ); ?></th><th><?php esc_html_e( '接口名称', 'hydra-ai' ); ?></th><th class="hydra-col-protocol"><?php esc_html_e( '协议', 'hydra-ai' ); ?></th><th class="hydra-col-model"><?php esc_html_e( '模型', 'hydra-ai' ); ?></th><th class="hydra-col-status"><?php esc_html_e( '状态', 'hydra-ai' ); ?></th><th class="hydra-col-actions"><?php esc_html_e( '操作', 'hydra-ai' ); ?></th></tr></thead><tbody>
					<?php foreach ( $entries as $index => $entry ) : $this->render_row( $entry, $index + 1 );
endforeach; ?>
				</tbody></table></div>
				<?php else : ?><div class="hydra-empty-state"><p><?php esc_html_e( '这个配置还没有接口，请点击“添加接口”。', 'hydra-ai' ); ?></p></div><?php endif; ?>
				<?php $this->render_dialog(); ?>
			<?php endif; ?>
		</div>
		<?php
	}

	private function render_row( array $entry, int $order ): void {
		$protocol = (string) ( $entry['protocol'] ?? '' );
		$enabled = ! empty( $entry['enabled'] );
		$has_key = '' !== (string) ( $entry['api_key'] ?? '' );
		?><tr class="<?php echo esc_attr( $enabled ? '' : 'hydra-row-disabled' ); ?>" data-id="<?php echo esc_attr( $entry['id'] ); ?>" data-name="<?php echo esc_attr( $entry['name'] ); ?>" data-protocol="<?php echo esc_attr( $protocol ); ?>" data-endpoint="<?php echo esc_attr( $entry['endpoint'] ); ?>" data-model="<?php echo esc_attr( $entry['model'] ); ?>" data-enabled="<?php echo esc_attr( $enabled ? '1' : '0' ); ?>" data-has-key="<?php echo esc_attr( $has_key ? '1' : '0' ); ?>"><td><span class="hydra-order-num"><?php echo esc_html( (string) $order ); ?></span><span class="hydra-drag-handle dashicons dashicons-menu" aria-hidden="true"></span></td><td><strong class="hydra-name-text"><?php echo esc_html( $entry['name'] ); ?></strong></td><td><span class="hydra-badge hydra-badge-<?php echo esc_attr( $protocol ); ?>"><?php echo esc_html( Hydra_Protocols::get_label( $protocol ) ); ?></span></td><td><code><?php echo esc_html( $entry['model'] ); ?></code></td><td><label class="hydra-switch"><span class="screen-reader-text"><?php esc_html_e( '启用该接口', 'hydra-ai' ); ?></span><input type="checkbox" class="hydra-toggle-enabled" <?php checked( $enabled ); ?> /><span class="hydra-slider"></span></label></td><td><button type="button" class="button button-small hydra-test" data-id="<?php echo esc_attr( $entry['id'] ); ?>"><?php esc_html_e( '测试', 'hydra-ai' ); ?></button> <button type="button" class="button button-small hydra-edit"><?php esc_html_e( '编辑', 'hydra-ai' ); ?></button> <button type="button" class="button button-small hydra-delete"><?php esc_html_e( '删除', 'hydra-ai' ); ?></button><span class="hydra-test-result" aria-live="polite"></span></td></tr><?php
	}

	private function render_failover_report( array $report ): void {
		$attempts = is_array( $report['attempts'] ?? null ) ? $report['attempts'] : array();
		if ( ! $attempts ) { return; }
		$lines = array();
		foreach ( $attempts as $attempt ) { if ( is_array( $attempt ) ) { $lines[] = ( ! empty( $attempt['ok'] ) ? '✓ ' : '✗ ' ) . (string) ( $attempt['name'] ?? '' ) . ( ! empty( $attempt['message'] ) ? ' — ' . (string) $attempt['message'] : '' ); }
		}
		?><div class="notice notice-<?php echo esc_attr( ! empty( $report['success'] ) ? 'success' : 'error' ); ?> hydra-report"><p><strong><?php esc_html_e( '最近一次接口切换结果：', 'hydra-ai' ); ?></strong></p><ul><?php foreach ( $lines as $line ) : ?><li><?php echo esc_html( $line ); ?></li><?php endforeach; ?></ul></div><?php
	}

	private function render_dialog(): void {
		?><dialog id="hydra-entry-dialog" class="hydra-dialog"><form id="hydra-entry-form" method="post"><input type="hidden" id="hydra-field-id" value="" /><h2 id="hydra-dialog-title"><?php esc_html_e( '添加接口', 'hydra-ai' ); ?></h2><p><label for="hydra-field-name"><?php esc_html_e( '接口名称', 'hydra-ai' ); ?> <span class="required">*</span></label><input type="text" id="hydra-field-name" required maxlength="100" /></p><p><label for="hydra-field-protocol"><?php esc_html_e( '协议', 'hydra-ai' ); ?> <span class="required">*</span></label><select id="hydra-field-protocol"><option value="chat"><?php esc_html_e( 'Chat Completions', 'hydra-ai' ); ?></option><option value="responses"><?php esc_html_e( 'Responses API', 'hydra-ai' ); ?></option><option value="anthropic"><?php esc_html_e( 'Anthropic Messages', 'hydra-ai' ); ?></option></select></p><p><label for="hydra-field-endpoint"><?php esc_html_e( '端点基础地址', 'hydra-ai' ); ?></label><input type="url" id="hydra-field-endpoint" class="regular-text code" spellcheck="false" /></p><p><label for="hydra-field-api-key"><?php esc_html_e( 'API Key', 'hydra-ai' ); ?></label><input type="password" id="hydra-field-api-key" class="regular-text code" autocomplete="new-password" /><span class="description"><?php esc_html_e( '留空时使用该配置在 WordPress 连接中保存的密钥；编辑时留空表示保持原值。', 'hydra-ai' ); ?></span></p><p><label for="hydra-field-model"><?php esc_html_e( '模型', 'hydra-ai' ); ?> <span class="required">*</span></label><input type="text" id="hydra-field-model" class="regular-text code" required maxlength="200" /></p><p><label><input type="checkbox" id="hydra-field-enabled" value="1" checked /> <?php esc_html_e( '启用该接口', 'hydra-ai' ); ?></label></p><p id="hydra-dialog-notice" class="hydra-error" role="alert" hidden></p><div class="hydra-dialog-footer"><button type="button" class="button" id="hydra-dialog-cancel"><?php esc_html_e( '取消', 'hydra-ai' ); ?></button><button type="submit" class="button button-primary" id="hydra-dialog-save"><?php esc_html_e( '保存', 'hydra-ai' ); ?></button></div></form></dialog><?php
	}

	/** 所有写操作都校验登录权限和本次页面的安全凭证。 */
	private function guard(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( '权限不足。', 'hydra-ai' ) ), 403 );
		}
		if ( ! isset( $_REQUEST['nonce'] ) || ! is_string( $_REQUEST['nonce'] ) ) {
			wp_send_json_error( array( 'message' => __( '请求数据无效。', 'hydra-ai' ) ), 400 );
		}
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );
	}

	/** 拒绝数组等非文本输入，并去掉 WordPress 添加的转义。 */
	private function posted_text( string $key ): string {
		if ( ! isset( $_POST[ $key ] ) ) {
			return '';
		}
		if ( ! is_string( $_POST[ $key ] ) ) {
			wp_send_json_error( array( 'message' => __( '请求数据无效。', 'hydra-ai' ) ), 400 );
		}
		return wp_unslash( $_POST[ $key ] );
	}

	private function posted_profile_id(): string {
		$id = sanitize_key( $this->posted_text( 'profile_id' ) );
		if ( ! Hydra_Settings::get_profile( $id ) ) {
			wp_send_json_error( array( 'message' => __( '配置不存在。', 'hydra-ai' ) ), 404 );
		}
		return $id;
	}

	public function ajax_add_profile(): void {
		$this->guard();
		$profile = Hydra_Settings::create_profile( $this->posted_text( 'name' ) );
		if ( is_wp_error( $profile ) ) {
			wp_send_json_error( array( 'message' => $profile->get_error_message() ), 400 );
		}
		wp_send_json_success( array( 'id' => $profile['id'] ) );
	}

	public function ajax_delete_profile(): void {
		$this->guard();
		Hydra_Settings::delete_profile( $this->posted_profile_id() );
		wp_send_json_success();
	}

	public function ajax_rename_profile(): void {
		$this->guard();
		$profile = Hydra_Settings::rename_profile( $this->posted_profile_id(), $this->posted_text( 'name' ) );
		if ( is_wp_error( $profile ) ) {
			wp_send_json_error( array( 'message' => $profile->get_error_message() ), 400 );
		}
		wp_send_json_success();
	}

	public function ajax_save_entry(): void {
		$this->guard();
		$profile_id = $this->posted_profile_id();
		$entries    = Hydra_Settings::get_profile_entries( $profile_id );
		$id         = sanitize_key( $this->posted_text( 'id' ) );
		$raw        = array();
		foreach ( array( 'name', 'protocol', 'endpoint', 'api_key', 'model' ) as $key ) {
			$raw[ $key ] = $this->posted_text( $key );
		}
		$raw['enabled'] = '1' === $this->posted_text( 'enabled' );
		$index = null;
		foreach ( $entries as $i => $entry ) {
			if ( '' !== $id && hash_equals( $entry['id'], $id ) ) {
				$index = $i;
				break;
			}
		}
		if ( '' !== $id && null === $index ) {
			wp_send_json_error( array( 'message' => __( '接口不存在或已被删除。', 'hydra-ai' ) ), 404 );
		}
		$clean = Hydra_Settings::sanitize_entry( $raw, null !== $index ? $entries[ $index ] : null );
		if ( is_wp_error( $clean ) ) {
			wp_send_json_error( array( 'message' => $clean->get_error_message() ), 400 );
		}
		if ( null !== $index ) {
			$entries[ $index ] = $clean;
		} else {
			$entries[] = $clean;
		}
		Hydra_Settings::save_profile_entries( $profile_id, $entries );
		wp_send_json_success();
	}

	public function ajax_delete_entry(): void {
		$this->guard();
		$profile_id = $this->posted_profile_id();
		$id         = sanitize_key( $this->posted_text( 'id' ) );
		$entries    = Hydra_Settings::get_profile_entries( $profile_id );
		$kept = array_values(
            array_filter(
                $entries,
                static function ( $entry ) use ( $id ): bool {
					return ! hash_equals( $entry['id'], $id );
				}
            )
        );
		if ( count( $kept ) === count( $entries ) ) {
			wp_send_json_error( array( 'message' => __( '接口不存在或已被删除。', 'hydra-ai' ) ), 404 );
		}
		Hydra_Settings::save_profile_entries( $profile_id, $kept );
		wp_send_json_success();
	}

	public function ajax_reorder(): void {
		$this->guard();
		$profile_id = $this->posted_profile_id();
		$order      = $_POST['order'] ?? null;
		$entries    = Hydra_Settings::get_profile_entries( $profile_id );
		if ( ! is_array( $order ) || count( $order ) !== count( $entries ) || array_values( $order ) !== $order ) {
			wp_send_json_error( array( 'message' => __( '排序数据不完整。', 'hydra-ai' ) ), 400 );
		}
		$map = array_column( $entries, null, 'id' );
		$sorted = array();
		foreach ( $order as $id ) {
			if ( ! is_string( $id ) || ! isset( $map[ $id ] ) ) {
				wp_send_json_error( array( 'message' => __( '排序数据无效。', 'hydra-ai' ) ), 400 );
			}
			$sorted[] = $map[ $id ];
			// 每个接口只允许出现一次。
			unset( $map[ $id ] );
		}
		Hydra_Settings::save_profile_entries( $profile_id, $sorted );
		wp_send_json_success();
	}

	public function ajax_test_entry(): void {
		$this->guard();
		$profile_id = $this->posted_profile_id();
		$entry      = Hydra_Settings::get_entry( sanitize_key( $this->posted_text( 'id' ) ), $profile_id );
		if ( ! $entry ) {
			wp_send_json_error( array( 'message' => __( '接口不存在或已被删除。', 'hydra-ai' ) ), 404 );
		}
		wp_send_json_success( Hydra_Text_Model::test_entry( $entry, $profile_id ) );
	}
}
