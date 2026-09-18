<?php
/**
 * Plugin Name:  Hydra AI
 * Description:  在 WordPress 连接中注册一个自定义 AI 供应商，支持 OpenAI Chat、OpenAI Responses 与 Anthropic 三种协议，可自由配置端点，并按优先级从上到下自动故障转移。
 * Version:      1.0.0
 * Requires at least: 7.0
 * Requires PHP: 7.4
 * Author:       Hydra AI
 * License:      GPL-2.0-or-later
 * License URI:  https://spdx.org/licenses/GPL-2.0-or-later.html
 * Text Domain:  hydra-ai
 * Domain Path:  /languages
 *
 * @package Hydra_AI
 */

defined( 'ABSPATH' ) || exit;

define( 'HYDRA_AI_VERSION', '1.0.0' );
define( 'HYDRA_AI_FILE', __FILE__ );
define( 'HYDRA_AI_DIR', plugin_dir_path( __FILE__ ) );
define( 'HYDRA_AI_URL', plugin_dir_url( __FILE__ ) );
define( 'HYDRA_AI_SLUG', 'hydra-ai' );
/** 供应商条目存储的选项名。 */
define( 'HYDRA_AI_OPTION', 'hydra_ai_providers' );
/** 注册到 WP AI Client 的供应商 ID，同时是连接器 ID。 */
define( 'HYDRA_AI_PROVIDER_ID', 'hydra-ai' );
/** 连接器界面保存的 API Key 选项名（由 WordPress 连接器命名规则生成）。 */
define( 'HYDRA_AI_CONNECTOR_KEY_OPTION', 'connectors_ai_hydra_ai_api_key' );
/** 供应商条目数量上限，防止配置无限膨胀。 */
define( 'HYDRA_AI_MAX_ENTRIES', 50 );

require_once HYDRA_AI_DIR . 'includes/class-hydra-ai.php';
require_once HYDRA_AI_DIR . 'includes/class-hydra-settings.php';
require_once HYDRA_AI_DIR . 'includes/class-hydra-protocol-interface.php';
require_once HYDRA_AI_DIR . 'includes/class-hydra-protocol-chat.php';
require_once HYDRA_AI_DIR . 'includes/class-hydra-protocol-responses.php';
require_once HYDRA_AI_DIR . 'includes/class-hydra-protocol-anthropic.php';
require_once HYDRA_AI_DIR . 'includes/class-hydra-protocols.php';
require_once HYDRA_AI_DIR . 'includes/class-hydra-model-directory.php';
require_once HYDRA_AI_DIR . 'includes/class-hydra-availability.php';
require_once HYDRA_AI_DIR . 'includes/class-hydra-provider.php';
require_once HYDRA_AI_DIR . 'includes/class-hydra-text-model.php';
require_once HYDRA_AI_DIR . 'includes/class-hydra-admin.php';

/**
 * 加载插件翻译文件。
 *
 * @since 1.0.0
 *
 * @return void
 */
function hydra_ai_load_textdomain(): void {
	load_plugin_textdomain(
		'hydra-ai',
		false,
		dirname( plugin_basename( HYDRA_AI_FILE ) ) . '/languages'
	);
}
add_action( 'init', 'hydra_ai_load_textdomain', 1 );

/**
 * 将 Hydra AI 注册到 WP AI Client 供应商注册表。
 *
 * 注册完成后，WordPress 7.0+ 的连接器 API 会自动为 Hydra AI
 * 创建一个 ai_provider 类型的连接器（init 15 触发）。
 *
 * @since 1.0.0
 *
 * @return void
 */
function hydra_ai_register_provider(): void {
	// 环境禁用了 AI 或 WordPress 版本过旧时直接跳过。
	if ( ! function_exists( 'wp_supports_ai' ) || ! wp_supports_ai() ) {
		return;
	}
	if ( ! class_exists( \WordPress\AiClient\AiClient::class ) ) {
		return;
	}

	$registry = \WordPress\AiClient\AiClient::defaultRegistry();

	if ( $registry->hasProvider( HYDRA_AI_PROVIDER_ID ) || $registry->hasProvider( Hydra_Provider::class ) ) {
		return;
	}

	$registry->registerProvider( Hydra_Provider::class );
}
add_action( 'init', 'hydra_ai_register_provider', 5 );

/**
 * 获取插件主类实例并完成初始化。
 *
 * @since 1.0.0
 *
 * @return Hydra_AI
 */
function hydra_ai(): Hydra_AI {
	return Hydra_AI::instance();
}
hydra_ai();
