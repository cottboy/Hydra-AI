<?php
/**
 * Hydra AI 供应商可用性检查。
 *
 * @package Hydra_AI
 */

defined( 'ABSPATH' ) || exit;

use WordPress\AiClient\Providers\Contracts\ProviderAvailabilityInterface;

/**
 * 供应商可用性检查。
 *
 * 只要存在已启用的供应商条目，或已在任何位置提供 API Key（连接器设置、
 * 常量、环境变量），即视为已配置。纯本地判断，不发起网络请求。
 *
 * @since 1.0.0
 */
class Hydra_Availability implements ProviderAvailabilityInterface {

	/**
	 * @inheritDoc
	 */
	public function isConfigured(): bool {
		if ( Hydra_Settings::get_enabled_entries() ) {
			return true;
		}

		// 无条目但有密钥也算已配置：避免连接器界面保存密钥时因校验失败被清空。
		if ( '' !== (string) get_option( HYDRA_AI_CONNECTOR_KEY_OPTION, '' ) ) {
			return true;
		}

		if ( defined( 'HYDRA_AI_API_KEY' ) && is_string( HYDRA_AI_API_KEY ) && '' !== HYDRA_AI_API_KEY ) {
			return true;
		}

		$env_key = getenv( 'HYDRA_AI_API_KEY' );
		if ( is_string( $env_key ) && '' !== $env_key ) {
			return true;
		}

		return false;
	}
}
