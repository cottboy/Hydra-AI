<?php
/**
 * 插件卸载清理。
 *
 * @package Hydra_AI
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

// 供应商条目配置。
delete_option( 'hydra_ai_providers' );

// 连接器界面保存的 Hydra AI API Key。
delete_option( 'connectors_ai_hydra_ai_api_key' );

// 故障转移摘要。
delete_transient( 'hydra_ai_last_failover' );
