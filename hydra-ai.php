<?php
/**
 * Plugin Name:  Hydra AI
 * Description:  在 WordPress 连接中注册多个自定义 AI 供应商，支持 Chat Completions、Responses 与 Anthropic Messages 协议，可自由配置端点并自动故障转移。
 * Version:      2.0.0
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

define( 'HYDRA_AI_VERSION', '2.0.0' );
define( 'HYDRA_AI_FILE', __FILE__ );
define( 'HYDRA_AI_DIR', plugin_dir_path( __FILE__ ) );
define( 'HYDRA_AI_URL', plugin_dir_url( __FILE__ ) );
define( 'HYDRA_AI_SLUG', 'hydra-ai' );
/** 供应商条目存储的选项名。 */
define( 'HYDRA_AI_OPTION', 'hydra_ai_profiles' );
/** 当前设置页选中的配置 ID。 */
define( 'HYDRA_AI_ACTIVE_PROFILE_OPTION', 'hydra_ai_active_profile' );

require_once HYDRA_AI_DIR . 'includes/class-hydra-ai.php';
require_once HYDRA_AI_DIR . 'includes/class-hydra-files.php';
require_once HYDRA_AI_DIR . 'includes/class-hydra-settings.php';
require_once HYDRA_AI_DIR . 'includes/class-hydra-stream.php';
require_once HYDRA_AI_DIR . 'includes/class-hydra-protocol-interface.php';
require_once HYDRA_AI_DIR . 'includes/trait-hydra-tool-names.php';
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
	// phpcs:disable PluginCheck.CodeAnalysis.DiscouragedFunctions -- 翻译文件打包在插件自己的 languages 目录内，WordPress 只会自动加载 wp-content/languages/plugins/，去掉这句就没有翻译。
	load_plugin_textdomain(
		'hydra-ai',
		false,
		dirname( plugin_basename( HYDRA_AI_FILE ) ) . '/languages'
	);
	// phpcs:enable PluginCheck.CodeAnalysis.DiscouragedFunctions
}
add_action( 'init', 'hydra_ai_load_textdomain', 1 );

/**
 * 将所有命名配置注册到 WP AI Client 供应商注册表。
 *
 * 注册完成后，WordPress 7.0+ 的连接器 API 会按配置名称创建 ai_provider 连接器。
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

	Hydra_Provider::register_all();
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
