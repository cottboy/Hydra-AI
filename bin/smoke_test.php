<?php
/**
 * Hydra AI 冒烟测试：加载完整 WordPress 环境验证注册、清洗与故障转移。
 *
 * 用法：php.exe smoke_test.php
 * 需先启动 mock 服务端：php.exe -S 127.0.0.1:8931 mock_server.php
 *
 * @package Hydra_AI
 */

// CLI 请求伪装，保证 wp-load 正常引导。
$_SERVER['HTTP_HOST']       = 'ceshi.ceshi';
$_SERVER['SERVER_NAME']     = 'ceshi.ceshi';
$_SERVER['REQUEST_URI']     = '/hydra-smoke-test';
$_SERVER['REQUEST_METHOD']  = 'GET';
$_SERVER['SERVER_PROTOCOL'] = 'HTTP/1.1';

define( 'WP_USE_THEMES', false );

require 'C:/EServer-data/www/ceshi.ceshi/wp-load.php';

$plugin_file = 'C:/EServer-data/www/ceshi.ceshi/wp-content/plugins/hydra-ai/hydra-ai.php';
require_once $plugin_file;

$failures = array();

/**
 * 记录断言结果。
 *
 * @param string $name 断言名称。
 * @param bool   $ok   是否通过。
 * @param string $note 补充说明。
 */
function assert_check( string $name, bool $ok, string $note = '' ): void {
	echo ( $ok ? '[PASS] ' : '[FAIL] ' ) . $name . ( '' !== $note ? " — {$note}" : '' ) . "\n";
	if ( ! $ok ) {
		$GLOBALS['failures'][] = $name;
	}
}

/* ---------- 1. 供应商注册 ---------- */

hydra_ai_register_provider();
$registry = \WordPress\AiClient\AiClient::defaultRegistry();

assert_check( 'hydra-ai 已注册到 WP AI Client', $registry->hasProvider( 'hydra-ai' ) );

$metadata = Hydra_Provider::metadata();
assert_check( '供应商元数据 ID 正确', 'hydra-ai' === $metadata->getId() );
assert_check( '供应商元数据名称非空', '' !== $metadata->getName() );
assert_check( '供应商 logo 文件存在', file_exists( HYDRA_AI_DIR . 'assets/hydra.svg' ) );

/* ---------- 2. 配置清洗 ---------- */

$bad = Hydra_Settings::sanitize_entry( array(
	'name'     => 'x',
	'protocol' => 'grpc',
	'model'    => 'm',
) );
assert_check( '非法协议被拒绝', is_wp_error( $bad ) );

$cleaned = Hydra_Settings::sanitize_entry( array(
	'name'     => '  测试供应商  ',
	'protocol' => 'chat',
	'endpoint' => 'https://api.example.com/v1///',
	'api_key'  => '  sk-abc  ',
	'model'    => ' gpt-x ',
	'enabled'  => 1,
) );
assert_check( '名称被正确清洗', '测试供应商' === $cleaned['name'] );
assert_check( '端点去除尾部斜杠', 'https://api.example.com/v1' === $cleaned['endpoint'] );
assert_check( '密钥去除空白', 'sk-abc' === $cleaned['api_key'] );
assert_check( '模型去除空白', 'gpt-x' === $cleaned['model'] );
assert_check( 'ID 已生成', '' !== $cleaned['id'] );

$cleaned2 = Hydra_Settings::sanitize_entry( array(
	'name'     => 'b',
	'protocol' => 'anthropic',
	'endpoint' => 'javascript:alert(1)',
	'api_key'  => '',
	'model'    => 'claude-x',
), $cleaned );
assert_check( '危险协议端点回退默认值', 'https://api.anthropic.com/v1' === $cleaned2['endpoint'] );
assert_check( '留空密钥保持原值', 'sk-abc' === $cleaned2['api_key'] );

/* ---------- 3. 可用性检查 ---------- */

// null 表示选项原本不存在，用于测试后恢复现场。
$had_entries   = get_option( HYDRA_AI_OPTION, null );
$had_connector = get_option( HYDRA_AI_CONNECTOR_KEY_OPTION, null );

$availability = new Hydra_Availability();
assert_check( '无条目无密钥时未配置', ! $availability->isConfigured() );

update_option( HYDRA_AI_CONNECTOR_KEY_OPTION, 'sk-connector', false );
assert_check( '仅有连接器密钥时已配置', $availability->isConfigured() );

/* ---------- 4. 端到端故障转移 ---------- */

// 预置条目：坏端点在最前，其后为三种协议的 mock 端点。
$entries = array(
	array(
		'id'       => 't1bad',
		'name'     => '坏端点',
		'protocol' => 'chat',
		'endpoint' => 'http://127.0.0.1:59995/v1',
		'api_key'  => 'sk-test',
		'model'    => 'smoke-model',
		'enabled'  => true,
	),
	array(
		'id'       => 't2chat',
		'name'     => 'Mock Chat',
		'protocol' => 'chat',
		'endpoint' => 'http://127.0.0.1:8931/v1',
		'api_key'  => 'sk-test',
		'model'    => 'smoke-model',
		'enabled'  => true,
	),
	array(
		'id'       => 't3resp',
		'name'     => 'Mock Responses',
		'protocol' => 'responses',
		'endpoint' => 'http://127.0.0.1:8931/v1',
		'api_key'  => 'sk-test',
		'model'    => 'smoke-model',
		'enabled'  => true,
	),
	array(
		'id'       => 't4anthropic',
		'name'     => 'Mock Anthropic',
		'protocol' => 'anthropic',
		'endpoint' => 'http://127.0.0.1:8931/v1',
		'api_key'  => 'sk-test',
		'model'    => 'smoke-model',
		'enabled'  => true,
	),
);

delete_transient( 'hydra_ai_last_failover' );
Hydra_Settings::save_entries( $entries );

$model_directory = new Hydra_Model_Directory();
assert_check( '模型目录包含 smoke-model', $model_directory->hasModelMetadata( 'smoke-model' ) );

try {
	$model = $registry->getProviderModel( 'hydra-ai', 'smoke-model' );
	$result = $model->generateTextResult(
		array( new \WordPress\AiClient\Messages\DTO\Message(
			\WordPress\AiClient\Messages\Enums\MessageRoleEnum::user(),
			array( new \WordPress\AiClient\Messages\DTO\MessagePart( 'ping' ) )
		) )
	);

	$text = $result->getCandidates()[0]->getMessage()->getParts()[0]->getText();
	assert_check( '故障转移后成功返回 Chat 结果', 'pong chat' === $text, '实际：' . $text );

	$summary = Hydra_Settings::get_last_failover();
	$attempt_count = $summary && isset( $summary['attempts'] ) ? count( $summary['attempts'] ) : 0;
	assert_check( '故障转移摘要记录了 2 次尝试', 2 === $attempt_count, '实际：' . $attempt_count );
} catch ( Throwable $e ) {
	assert_check( '故障转移请求抛出异常', false, $e->getMessage() );
}

// 禁用 Chat 条目后，应继续回退到 Responses / Anthropic。
$entries[1]['enabled'] = false;
Hydra_Settings::save_entries( $entries );
delete_transient( 'hydra_ai_last_failover' );

try {
	$model = $registry->getProviderModel( 'hydra-ai', 'smoke-model' );
	$result = $model->generateTextResult(
		array( new \WordPress\AiClient\Messages\DTO\Message(
			\WordPress\AiClient\Messages\Enums\MessageRoleEnum::user(),
			array( new \WordPress\AiClient\Messages\DTO\MessagePart( 'ping' ) )
		) )
	);
	$text = $result->getCandidates()[0]->getMessage()->getParts()[0]->getText();
	assert_check( '禁用 Chat 后跨协议回退到 Responses', 'pong responses' === $text, '实际：' . $text );
} catch ( Throwable $e ) {
	assert_check( '跨协议回退请求抛出异常', false, $e->getMessage() );
}

$entries[2]['enabled'] = false;
Hydra_Settings::save_entries( $entries );
delete_transient( 'hydra_ai_last_failover' );

try {
	$model = $registry->getProviderModel( 'hydra-ai', 'smoke-model' );
	$result = $model->generateTextResult(
		array( new \WordPress\AiClient\Messages\DTO\Message(
			\WordPress\AiClient\Messages\Enums\MessageRoleEnum::user(),
			array( new \WordPress\AiClient\Messages\DTO\MessagePart( 'ping' ) )
		) )
	);
	$text = $result->getCandidates()[0]->getMessage()->getParts()[0]->getText();
	assert_check( '继续回退到 Anthropic 协议', 'pong anthropic' === $text, '实际：' . $text );
} catch ( Throwable $e ) {
	assert_check( 'Anthropic 回退请求抛出异常', false, $e->getMessage() );
}

// 全部条目失败时应抛出最后一次的异常。
$entries[3]['enabled'] = false;
Hydra_Settings::save_entries( $entries );

try {
	$model = $registry->getProviderModel( 'hydra-ai', 'smoke-model' );
	$model->generateTextResult(
		array( new \WordPress\AiClient\Messages\DTO\Message(
			\WordPress\AiClient\Messages\Enums\MessageRoleEnum::user(),
			array( new \WordPress\AiClient\Messages\DTO\MessagePart( 'ping' ) )
		) )
	);
	assert_check( '全部失败时应抛出异常', false, '未抛出' );
} catch ( Throwable $e ) {
	assert_check( '全部失败时抛出最后一次异常', true, $e->getMessage() );
}

/* ---------- 5. 连通性测试入口 ---------- */

Hydra_Settings::save_entries( array( $entries[1] ) );
$test_result = Hydra_Text_Model::test_entry( Hydra_Settings::get_entries()[0] );
assert_check( 'test_entry 对可用端点成功', true === $test_result['ok'], $test_result['message'] );

/* ---------- 恢复站点原始数据 ---------- */

if ( null === $had_entries ) {
	delete_option( HYDRA_AI_OPTION );
} else {
	update_option( HYDRA_AI_OPTION, $had_entries, false );
}
if ( null === $had_connector ) {
	delete_option( HYDRA_AI_CONNECTOR_KEY_OPTION );
} else {
	update_option( HYDRA_AI_CONNECTOR_KEY_OPTION, $had_connector, false );
}
delete_transient( 'hydra_ai_last_failover' );

echo "\n";
if ( $failures ) {
	echo '测试失败：' . count( $failures ) . " 项\n";
	exit( 1 );
}
echo "全部通过\n";
