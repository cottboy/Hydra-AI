<?php
/**
 * 后台页面渲染冒烟检查：以管理员身份渲染设置页，捕获模板错误。
 */
$_SERVER['HTTP_HOST']       = 'ceshi.ceshi';
$_SERVER['SERVER_NAME']     = 'ceshi.ceshi';
$_SERVER['REQUEST_URI']     = '/wp-admin/options-general.php?page=hydra-ai';
$_SERVER['REQUEST_METHOD']  = 'GET';
$_SERVER['SERVER_PROTOCOL'] = 'HTTP/1.1';

define( 'WP_USE_THEMES', false );
require 'C:/EServer-data/www/ceshi.ceshi/wp-load.php';
require_once 'C:/EServer-data/www/ceshi.ceshi/wp-content/plugins/hydra-ai/hydra-ai.php';

wp_set_current_user( 1 );
hydra_ai_register_provider();

// 捕获现场并注册关闭时恢复：即使中途崩溃也不会丢失站点已有配置。
$original = get_option( HYDRA_AI_OPTION, null );
register_shutdown_function( static function () use ( $original ): void {
	if ( null === $original ) {
		delete_option( HYDRA_AI_OPTION );
	} else {
		update_option( HYDRA_AI_OPTION, $original, false );
	}
} );

// 预置两条演示条目（覆盖三种协议徽章与密钥状态），仅用于本次渲染，结束时恢复原配置。
$entries = array(
	array( 'id' => 'demo1', 'name' => 'OpenAI 官方', 'protocol' => 'chat', 'endpoint' => 'https://api.openai.com/v1', 'api_key' => 'sk-demo', 'model' => 'gpt-4o-mini', 'enabled' => true ),
	array( 'id' => 'demo2', 'name' => '本地 Ollama', 'protocol' => 'anthropic', 'endpoint' => 'http://127.0.0.1:11434/v1', 'api_key' => '', 'model' => 'qwen3', 'enabled' => false ),
);
update_option( HYDRA_AI_OPTION, $entries, false );

ob_start();
( new Hydra_Admin() )->render_page();
$html = ob_get_clean();

echo '页面长度: ' . strlen( $html ) . " 字节\n";
foreach ( array( '添加供应商', 'hydra-entry-dialog', 'OpenAI 官方', '本地 Ollama', '端点（基础地址）', 'API Key' ) as $needle ) {
	echo ( false !== strpos( $html, $needle ) ? '[PASS] 包含 ' : '[FAIL] 缺少 ' ) . $needle . "\n";
}

// 已删除的 UI 元素不应再出现在页面中（“已设置密钥”仍作为 JS 数据下发给编辑弹窗，不在此列）。
foreach ( array( '使用说明', 'hydra-key-badge', 'hydra-endpoint-hint', '例如：OpenAI 官方', '未设置密钥', '使用连接器密钥' ) as $gone ) {
	echo ( false === strpos( $html, $gone ) ? '[PASS] 已移除 ' : '[FAIL] 仍存在 ' ) . $gone . "\n";
}
