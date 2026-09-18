<?php
// 确认测试脚本未在站点数据库留下残留数据。
$_SERVER['HTTP_HOST'] = 'ceshi.ceshi'; $_SERVER['SERVER_NAME'] = 'ceshi.ceshi';
$_SERVER['REQUEST_URI'] = '/'; $_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['SERVER_PROTOCOL'] = 'HTTP/1.1';
define( 'WP_USE_THEMES', false );
require 'C:/EServer-data/www/ceshi.ceshi/wp-load.php';

foreach ( array( 'hydra_ai_providers', 'connectors_ai_hydra_ai_api_key' ) as $opt ) {
	$value = get_option( $opt, null );
	echo 'hydra-ai', ' ', $opt, ': ', null === $value ? '不存在（干净）' : '存在！', "\n";
}
$t = get_transient( 'hydra_ai_last_failover' );
echo 'transient: ', false === $t ? '不存在（干净）' : '存在！', "\n";
