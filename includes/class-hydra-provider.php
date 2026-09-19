<?php
/**
 * Hydra AI 供应商定义。
 *
 * @package Hydra_AI
 */

defined( 'ABSPATH' ) || exit;

use WordPress\AiClient\Providers\ApiBasedImplementation\AbstractApiProvider;
use WordPress\AiClient\Providers\Contracts\ModelMetadataDirectoryInterface;
use WordPress\AiClient\Providers\Contracts\ProviderAvailabilityInterface;
use WordPress\AiClient\Providers\DTO\ProviderMetadata;
use WordPress\AiClient\Providers\Enums\ProviderTypeEnum;
use WordPress\AiClient\Providers\Http\Enums\RequestAuthenticationMethod;
use WordPress\AiClient\Providers\Models\Contracts\ModelInterface;
use WordPress\AiClient\Providers\Models\DTO\ModelMetadata;

/**
 * Hydra AI 供应商。
 *
 * 请求地址不使用 baseUrl()，而是来自用户配置的供应商条目端点，
 * 因此这里的 baseUrl 仅返回占位值以满足基类要求。
 *
 * @since 1.0.0
 */
class Hydra_Provider extends AbstractApiProvider {

	/**
	 * @inheritDoc
	 */
	protected static function baseUrl(): string {
		return 'https://hydra-ai.invalid';
	}

	/**
	 * @inheritDoc
	 */
	protected static function createModel(
		ModelMetadata $model_metadata,
		ProviderMetadata $provider_metadata
	): ModelInterface {
		return new Hydra_Text_Model( $model_metadata, $provider_metadata );
	}

	/**
	 * @inheritDoc
	 */
	protected static function createProviderMetadata(): ProviderMetadata {
		// logoPath 传 null：连接页不显示供应商图标。
		return new ProviderMetadata(
			HYDRA_AI_PROVIDER_ID,
			__( 'Hydra AI', 'hydra-ai' ),
			ProviderTypeEnum::cloud(),
			'',
			RequestAuthenticationMethod::apiKey(),
			__( '自定义 AI 供应商端点，支持 Chat Completions、Responses 与 Anthropic 三种协议，并提供按优先级的故障转移。', 'hydra-ai' ),
			null
		);
	}

	/**
	 * @inheritDoc
	 */
	protected static function createProviderAvailability(): ProviderAvailabilityInterface {
		return new Hydra_Availability();
	}

	/**
	 * @inheritDoc
	 */
	protected static function createModelMetadataDirectory(): ModelMetadataDirectoryInterface {
		return new Hydra_Model_Directory();
	}
}
