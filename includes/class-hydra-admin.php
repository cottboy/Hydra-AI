<?php
/**
 * 设置页面：供应商列表、排序、增删改与连通性测试。
 *
 * @package Hydra_AI
 */

defined( 'ABSPATH' ) || exit;

/**
 * 后台管理。
 *
 * @since 1.0.0
 */
class Hydra_Admin {

	/**
	 * AJAX 动作公共 nonce 名称。
	 *
	 * @var string
	 */
	const NONCE_ACTION = 'hydra_ai_admin';

	/**
	 * 注册“设置 → Hydra AI”菜单。
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function register_menu(): void {
		add_options_page(
			__( 'Hydra AI', 'hydra-ai' ),
			__( 'Hydra AI', 'hydra-ai' ),
			'manage_options',
			HYDRA_AI_SLUG,
			array( $this, 'render_page' )
		);
	}

	/**
	 * 仅在插件设置页加载样式与脚本。
	 *
	 * @since 1.0.0
	 *
	 * @param string $hook_suffix 当前页面钩子后缀。
	 * @return void
	 */
	public function enqueue_assets( string $hook_suffix ): void {
		if ( 'settings_page_' . HYDRA_AI_SLUG !== $hook_suffix ) {
			return;
		}

		wp_enqueue_style(
			'hydra-ai-admin',
			HYDRA_AI_URL . 'assets/admin.css',
			array(),
			HYDRA_AI_VERSION
		);

		wp_enqueue_script( 'jquery-ui-sortable' );

		wp_enqueue_script(
			'hydra-ai-admin',
			HYDRA_AI_URL . 'assets/admin.js',
			array( 'jquery', 'jquery-ui-sortable' ),
			HYDRA_AI_VERSION,
			true
		);

		wp_localize_script( 'hydra-ai-admin', 'HydraAdmin', $this->get_script_data() );
	}

	/**
	 * 提供给脚本的数据与翻译字符串。
	 *
	 * @since 1.0.0
	 *
	 * @return array<string,mixed>
	 */
	private function get_script_data(): array {
		return array(
			'ajaxUrl'  => admin_url( 'admin-ajax.php' ),
			'nonce'    => wp_create_nonce( self::NONCE_ACTION ),
			'i18n'     => array(
				'confirmDelete' => __( '确定要删除供应商“%s”吗？删除后不可恢复。', 'hydra-ai' ),
				'saveFailed'    => __( '保存失败：%s', 'hydra-ai' ),
				'reorderFailed' => __( '排序保存失败，请刷新页面重试。', 'hydra-ai' ),
				'testSuccess'   => __( '连接成功：%s', 'hydra-ai' ),
				'testFailed'    => __( '连接失败：%s', 'hydra-ai' ),
				'keyExists'     => __( '已设置密钥', 'hydra-ai' ),
				'networkError'  => __( '请求失败，请检查网络后重试。', 'hydra-ai' ),
				'testing'       => __( '测试中…', 'hydra-ai' ),
				'dialogAdd'     => __( '添加供应商', 'hydra-ai' ),
				'dialogEdit'    => __( '编辑供应商', 'hydra-ai' ),
			),
		);
	}

	/**
	 * 渲染设置页。
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$entries     = Hydra_Settings::get_entries();
		$ai_missing  = ! class_exists( \WordPress\AiClient\AiClient::class );
		$last_report = Hydra_Settings::get_last_failover();
		?>
		<div class="wrap hydra-ai-wrap">
			<h1>
				<?php esc_html_e( 'Hydra AI', 'hydra-ai' ); ?>
				<button type="button" class="page-title-action" id="hydra-add-entry">
					<?php esc_html_e( '添加供应商', 'hydra-ai' ); ?>
				</button>
			</h1>

			<p class="description">
				<?php esc_html_e( '供应商按列表顺序从上到下依次请求，失败时自动切换到下一个；拖动行可调整优先级。', 'hydra-ai' ); ?>
			</p>

			<?php if ( $ai_missing ) : ?>
				<div class="notice notice-warning">
					<p><?php esc_html_e( '当前 WordPress 未加载 AI 客户端（需要 WordPress 7.0+ 且未禁用 AI 功能），供应商注册与故障转移能力不可用。', 'hydra-ai' ); ?></p>
				</div>
			<?php endif; ?>

			<?php if ( $last_report ) : ?>
				<?php $this->render_failover_report( $last_report ); ?>
			<?php endif; ?>

			<div id="hydra-notice" class="hydra-notice" hidden></div>

			<?php if ( $entries ) : ?>
				<table class="widefat striped hydra-entries-table" id="hydra-entries-table">
					<thead>
						<tr>
							<th class="hydra-col-order"><?php esc_html_e( '优先级', 'hydra-ai' ); ?></th>
							<th><?php esc_html_e( '供应商', 'hydra-ai' ); ?></th>
							<th class="hydra-col-protocol"><?php esc_html_e( '协议', 'hydra-ai' ); ?></th>
							<th class="hydra-col-model"><?php esc_html_e( '模型', 'hydra-ai' ); ?></th>
							<th class="hydra-col-status"><?php esc_html_e( '状态', 'hydra-ai' ); ?></th>
							<th class="hydra-col-actions"><?php esc_html_e( '操作', 'hydra-ai' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $entries as $index => $entry ) : ?>
							<?php $this->render_row( $entry, $index + 1 ); ?>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php else : ?>
				<div class="hydra-empty-state">
					<p><?php esc_html_e( '还没有供应商。点击右上角“添加供应商”，配置你的第一个 AI 端点。', 'hydra-ai' ); ?></p>
				</div>
			<?php endif; ?>

			<?php $this->render_dialog(); ?>
		</div>
		<?php
	}

	/**
	 * 渲染单个供应商行。
	 *
	 * @since 1.0.0
	 *
	 * @param array<string,mixed> $entry 供应商条目。
	 * @param int                 $order 序号（从 1 开始）。
	 * @return void
	 */
	private function render_row( array $entry, int $order ): void {
		$protocol = isset( $entry['protocol'] ) ? (string) $entry['protocol'] : '';
		$has_key  = '' !== (string) ( $entry['api_key'] ?? '' );
		$enabled  = ! empty( $entry['enabled'] );
		?>
		<tr
			data-id="<?php echo esc_attr( (string) $entry['id'] ); ?>"
			data-name="<?php echo esc_attr( (string) $entry['name'] ); ?>"
			data-protocol="<?php echo esc_attr( $protocol ); ?>"
			data-endpoint="<?php echo esc_attr( (string) $entry['endpoint'] ); ?>"
			data-model="<?php echo esc_attr( (string) $entry['model'] ); ?>"
			data-enabled="<?php echo esc_attr( $enabled ? '1' : '0' ); ?>"
			data-has-key="<?php echo esc_attr( $has_key ? '1' : '0' ); ?>"
		>
			<td class="hydra-col-order">
				<span class="hydra-order-num"><?php echo esc_html( (string) $order ); ?></span>
				<span class="hydra-drag-handle dashicons dashicons-menu" title="<?php esc_attr_e( '拖动调整优先级', 'hydra-ai' ); ?>" aria-hidden="true"></span>
			</td>
			<td class="hydra-col-name">
				<strong class="hydra-name-text"><?php echo esc_html( (string) $entry['name'] ); ?></strong>
			</td>
			<td class="hydra-col-protocol">
				<span class="hydra-badge hydra-badge-<?php echo esc_attr( $protocol ); ?>">
					<?php echo esc_html( Hydra_Protocols::get_label( $protocol ) ); ?>
				</span>
			</td>
			<td class="hydra-col-model">
				<code><?php echo esc_html( (string) $entry['model'] ); ?></code>
			</td>
			<td class="hydra-col-status">
				<label class="hydra-switch">
					<input type="checkbox" class="hydra-toggle-enabled" <?php checked( $enabled ); ?> aria-label="<?php esc_attr_e( '启用供应商', 'hydra-ai' ); ?>" />
					<span class="hydra-slider"></span>
				</label>
			</td>
			<td class="hydra-col-actions">
				<button type="button" class="button button-small hydra-test" data-id="<?php echo esc_attr( (string) $entry['id'] ); ?>">
					<?php esc_html_e( '测试', 'hydra-ai' ); ?>
				</button>
				<button type="button" class="button button-small hydra-edit" data-id="<?php echo esc_attr( (string) $entry['id'] ); ?>">
					<?php esc_html_e( '编辑', 'hydra-ai' ); ?>
				</button>
				<button type="button" class="button button-small hydra-delete" data-id="<?php echo esc_attr( (string) $entry['id'] ); ?>">
					<?php esc_html_e( '删除', 'hydra-ai' ); ?>
				</button>
				<span class="hydra-test-result" aria-live="polite"></span>
			</td>
		</tr>
		<?php
	}

	/**
	 * 渲染故障转移报告。
	 *
	 * @since 1.0.0
	 *
	 * @param array<string,mixed> $report 报告数据。
	 * @return void
	 */
	private function render_failover_report( array $report ): void {
		$attempts = isset( $report['attempts'] ) && is_array( $report['attempts'] ) ? $report['attempts'] : array();
		if ( ! $attempts ) {
			return;
		}

		$success = ! empty( $report['success'] );
		$lines   = array();
		foreach ( $attempts as $attempt ) {
			if ( ! is_array( $attempt ) ) {
				continue;
			}
			$name = isset( $attempt['name'] ) ? (string) $attempt['name'] : '';
			$icon = ! empty( $attempt['ok'] ) ? '✓' : '✗';
			$msg  = isset( $attempt['message'] ) && '' !== (string) $attempt['message']
				? ' — ' . (string) $attempt['message']
				: '';
			$lines[] = sprintf( '%s %s%s', $icon, $name, $msg );
		}

		$title = $success
			? __( '最近一次故障转移（已成功）：', 'hydra-ai' )
			: __( '最近一次故障转移（全部失败）：', 'hydra-ai' );
		?>
		<div class="notice notice-<?php echo esc_attr( $success ? 'success' : 'error' ); ?> hydra-report">
			<p><strong><?php echo esc_html( $title ); ?></strong></p>
			<ul>
				<?php foreach ( $lines as $line ) : ?>
					<li><?php echo esc_html( $line ); ?></li>
				<?php endforeach; ?>
			</ul>
		</div>
		<?php
	}

	/**
	 * 渲染添加/编辑弹窗。
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	private function render_dialog(): void {
		?>
		<dialog id="hydra-entry-dialog" class="hydra-dialog">
			<form id="hydra-entry-form" method="post">
				<input type="hidden" name="id" id="hydra-field-id" value="" />
				<h2 id="hydra-dialog-title"><?php esc_html_e( '添加供应商', 'hydra-ai' ); ?></h2>

				<p>
					<label for="hydra-field-name"><?php esc_html_e( '名称', 'hydra-ai' ); ?> <span class="required">*</span></label>
					<input type="text" id="hydra-field-name" name="name" class="regular-text" required maxlength="100" />
				</p>

				<p>
					<label for="hydra-field-protocol"><?php esc_html_e( '协议', 'hydra-ai' ); ?> <span class="required">*</span></label>
					<select id="hydra-field-protocol" name="protocol">
						<option value="chat"><?php esc_html_e( 'Chat Completions', 'hydra-ai' ); ?></option>
						<option value="responses"><?php esc_html_e( 'Responses', 'hydra-ai' ); ?></option>
						<option value="anthropic"><?php esc_html_e( 'Anthropic Messages', 'hydra-ai' ); ?></option>
					</select>
				</p>

				<p>
					<label for="hydra-field-endpoint"><?php esc_html_e( '端点（基础地址）', 'hydra-ai' ); ?></label>
					<input type="url" id="hydra-field-endpoint" name="endpoint" class="regular-text code" spellcheck="false" />
				</p>

				<p>
					<label for="hydra-field-api-key"><?php esc_html_e( 'API Key', 'hydra-ai' ); ?></label>
					<input type="password" id="hydra-field-api-key" name="api_key" class="regular-text code" autocomplete="new-password" spellcheck="false" />
					<span class="description" id="hydra-key-hint"><?php esc_html_e( '留空则使用 WordPress 连接中保存的 Hydra AI 密钥；编辑时留空表示保持不变。', 'hydra-ai' ); ?></span>
				</p>

				<p>
					<label for="hydra-field-model"><?php esc_html_e( '模型', 'hydra-ai' ); ?> <span class="required">*</span></label>
					<input type="text" id="hydra-field-model" name="model" class="regular-text code" required maxlength="200" spellcheck="false" />
				</p>

				<p>
					<label>
						<input type="checkbox" id="hydra-field-enabled" name="enabled" value="1" checked />
						<?php esc_html_e( '启用该供应商', 'hydra-ai' ); ?>
					</label>
				</p>

				<div class="hydra-dialog-footer">
					<button type="button" class="button" id="hydra-dialog-cancel"><?php esc_html_e( '取消', 'hydra-ai' ); ?></button>
					<button type="submit" class="button button-primary" id="hydra-dialog-save"><?php esc_html_e( '保存', 'hydra-ai' ); ?></button>
				</div>
			</form>
		</dialog>
		<?php
	}

	/**
	 * AJAX 公共守卫：权限与 nonce 校验。
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	private function guard(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error(
				array( 'message' => __( '权限不足。', 'hydra-ai' ) ),
				403
			);
		}

		check_ajax_referer( self::NONCE_ACTION, 'nonce' );
	}

	/**
	 * AJAX：保存（新增或更新）供应商条目。
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function ajax_save_entry(): void {
		$this->guard();

		$id   = isset( $_POST['id'] ) ? sanitize_key( (string) wp_unslash( $_POST['id'] ) ) : '';
		$raw  = array(
			'name'     => isset( $_POST['name'] ) ? wp_unslash( (string) $_POST['name'] ) : '',
			'protocol' => isset( $_POST['protocol'] ) ? wp_unslash( (string) $_POST['protocol'] ) : '',
			'endpoint' => isset( $_POST['endpoint'] ) ? wp_unslash( (string) $_POST['endpoint'] ) : '',
			'api_key'  => isset( $_POST['api_key'] ) ? wp_unslash( (string) $_POST['api_key'] ) : '',
			'model'    => isset( $_POST['model'] ) ? wp_unslash( (string) $_POST['model'] ) : '',
			'enabled'  => ! empty( $_POST['enabled'] ),
		);

		$entries = Hydra_Settings::get_entries();

		if ( '' !== $id ) {
			// 更新：定位原条目以保留原密钥与位置。
			$index = null;
			foreach ( $entries as $i => $entry ) {
				if ( isset( $entry['id'] ) && hash_equals( (string) $entry['id'], $id ) ) {
					$index = $i;
					break;
				}
			}

			if ( null === $index ) {
				wp_send_json_error( array( 'message' => __( '条目不存在或已被删除。', 'hydra-ai' ) ) );
			}

			$sanitized = Hydra_Settings::sanitize_entry( $raw, $entries[ $index ] );
			if ( is_wp_error( $sanitized ) ) {
				wp_send_json_error( array( 'message' => $sanitized->get_error_message() ) );
			}

			$entries[ $index ] = $sanitized;
		} else {
			// 新增：检查数量上限。
			if ( count( $entries ) >= HYDRA_AI_MAX_ENTRIES ) {
				wp_send_json_error(
					array(
						'message' => sprintf(
							/* translators: %d: 最大条目数。 */
							__( '最多支持 %d 个供应商条目。', 'hydra-ai' ),
							HYDRA_AI_MAX_ENTRIES
						),
					)
				);
			}

			$sanitized = Hydra_Settings::sanitize_entry( $raw );
			if ( is_wp_error( $sanitized ) ) {
				wp_send_json_error( array( 'message' => $sanitized->get_error_message() ) );
			}

			$entries[] = $sanitized;
		}

		Hydra_Settings::save_entries( $entries );

		wp_send_json_success();
	}

	/**
	 * AJAX：删除供应商条目。
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function ajax_delete_entry(): void {
		$this->guard();

		$id = isset( $_POST['id'] ) ? sanitize_key( (string) wp_unslash( $_POST['id'] ) ) : '';

		$entries = Hydra_Settings::get_entries();
		$kept    = array();

		foreach ( $entries as $entry ) {
			if ( isset( $entry['id'] ) && '' !== $id && hash_equals( (string) $entry['id'], $id ) ) {
				continue;
			}
			$kept[] = $entry;
		}

		if ( count( $kept ) === count( $entries ) ) {
			wp_send_json_error( array( 'message' => __( '条目不存在或已被删除。', 'hydra-ai' ) ) );
		}

		Hydra_Settings::save_entries( $kept );

		wp_send_json_success();
	}

	/**
	 * AJAX：保存拖拽后的条目顺序。
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function ajax_reorder(): void {
		$this->guard();

		$order = isset( $_POST['order'] ) ? array_map( 'sanitize_key', (array) wp_unslash( $_POST['order'] ) ) : array();

		$entries = Hydra_Settings::get_entries();

		// 顺序数据必须完整覆盖现有条目，防止意外丢失配置。
		if ( count( $order ) !== count( $entries ) ) {
			wp_send_json_error( array( 'message' => __( '排序数据与条目数量不一致。', 'hydra-ai' ) ) );
		}

		$mapped = array();
		foreach ( $entries as $entry ) {
			$mapped[ (string) $entry['id'] ] = $entry;
		}

		$sorted = array();
		foreach ( $order as $id ) {
			if ( ! isset( $mapped[ $id ] ) ) {
				wp_send_json_error( array( 'message' => __( '排序数据包含未知条目。', 'hydra-ai' ) ) );
			}
			$sorted[] = $mapped[ $id ];
			unset( $mapped[ $id ] );
		}

		Hydra_Settings::save_entries( $sorted );

		wp_send_json_success();
	}

	/**
	 * AJAX：测试单个供应商条目的连通性。
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function ajax_test_entry(): void {
		$this->guard();

		$id   = isset( $_POST['id'] ) ? sanitize_key( (string) wp_unslash( $_POST['id'] ) ) : '';
		$entry = Hydra_Settings::get_entry( $id );

		if ( ! $entry ) {
			wp_send_json_error( array( 'message' => __( '条目不存在或已被删除。', 'hydra-ai' ) ) );
		}

		$result = Hydra_Text_Model::test_entry( $entry );

		wp_send_json_success( $result );
	}
}
