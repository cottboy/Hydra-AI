<?php
/**
 * 翻译加载冒烟检查：验证 en_US 翻译能被 WordPress 加载，中文源语言不受影响。
 */
$_SERVER['HTTP_HOST'] = 'ceshi.ceshi'; $_SERVER['SERVER_NAME'] = 'ceshi.ceshi';
$_SERVER['REQUEST_URI'] = '/'; $_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['SERVER_PROTOCOL'] = 'HTTP/1.1';
define( 'WP_USE_THEMES', false );
require 'C:/EServer-data/www/ceshi.ceshi/wp-load.php';
require_once 'C:/EServer-data/www/ceshi.ceshi/wp-content/plugins/hydra-ai/hydra-ai.php';

$failures = 0;

/* en_US：应显示英文翻译 */
switch_to_locale( 'en_US' );
hydra_ai_load_textdomain();

$cases_en = array(
	array( '保存', 'Save' ),
	array( '添加供应商', 'Add provider' ),
	array( '保存失败：%s', 'Save failed: %s' ),
	array( '测试中…', 'Testing…' ),
	array( '未设置密钥', 'No key set' ),
	array( '确定要删除供应商“%s”吗？删除后不可恢复。', 'Delete the provider "%s"? This cannot be undone.' ),
	array( '优先级', 'Priority' ),
	array( '模型', 'Model' ),
	array( 'Chat Completions（OpenAI 兼容）', 'Chat Completions (OpenAI-compatible)' ),
	array( '协议类型无效。', 'Invalid protocol type.' ),
);
echo "== en_US ==\n";
foreach ( $cases_en as $case ) {
	$got = __( $case[0], 'hydra-ai' );
	$ok  = $got === $case[1];
	if ( ! $ok ) { $failures++; }
	echo ( $ok ? '[PASS] ' : '[FAIL] ' ) . $case[0] . ' => ' . $got . "\n";
}
restore_current_locale();

/* 默认（zh_CN 站点）：应显示代码中的中文源文本 */
hydra_ai_load_textdomain();
echo "== zh_CN（源语言） ==\n";
foreach ( array( '保存', '添加供应商', '未设置密钥' ) as $src ) {
	$got = __( $src, 'hydra-ai' );
	$ok  = $got === $src;
	if ( ! $ok ) { $failures++; }
	echo ( $ok ? '[PASS] ' : '[FAIL] ' ) . $src . ' => ' . $got . "\n";
}

echo $failures ? "\n失败 {$failures} 项\n" : "\n全部通过\n";
exit( $failures ? 1 : 0 );
