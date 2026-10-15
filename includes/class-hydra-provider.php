<?php
/** Hydra AI 动态供应商定义。 */

defined( 'ABSPATH' ) || exit;

use WordPress\AiClient\AiClient;
use WordPress\AiClient\Providers\ApiBasedImplementation\AbstractApiProvider;
use WordPress\AiClient\Providers\Contracts\ModelMetadataDirectoryInterface;
use WordPress\AiClient\Providers\Contracts\ProviderAvailabilityInterface;
use WordPress\AiClient\Providers\DTO\ProviderMetadata;
use WordPress\AiClient\Providers\Enums\ProviderTypeEnum;
use WordPress\AiClient\Providers\Http\DTO\ApiKeyRequestAuthentication;
use WordPress\AiClient\Providers\Http\Enums\RequestAuthenticationMethod;
use WordPress\AiClient\Providers\Models\Contracts\ModelInterface;
use WordPress\AiClient\Providers\Models\DTO\ModelMetadata;

/** 每个命名配置都会生成一个独立的 WordPress AI 供应商类。 */
class Hydra_Provider extends AbstractApiProvider {
	protected static function baseUrl(): string { return 'https://hydra-ai.invalid'; }

	protected static function createModel( ModelMetadata $model_metadata, ProviderMetadata $provider_metadata ): ModelInterface {
		return new Hydra_Text_Model( $model_metadata, $provider_metadata );
	}

	protected static function createProviderMetadata(): ProviderMetadata {
		return self::metadata_for_profile( 'hydra-ai' );
	}

	protected static function createProviderAvailability(): ProviderAvailabilityInterface {
		return self::availability_for_profile( 'hydra-ai' );
	}

	protected static function createModelMetadataDirectory(): ModelMetadataDirectoryInterface {
		return new Hydra_Model_Directory( 'hydra-ai' );
	}

	public static function metadata_for_profile( string $profile_id ): ProviderMetadata {
		$profile = Hydra_Settings::get_profile( $profile_id );
		$name = $profile ? (string) $profile['name'] : __( 'Hydra AI', 'hydra-ai' );
		return new ProviderMetadata(
			$profile_id,
			$name,
			ProviderTypeEnum::cloud(),
			'',
			RequestAuthenticationMethod::apiKey(),
			__( '自定义 AI 供应商端点，支持 Chat Completions、Responses 和 Anthropic Messages 协议。', 'hydra-ai' ),
			null
		);
	}

	public static function availability_for_profile( string $profile_id ): ProviderAvailabilityInterface {
		return new Hydra_Availability( $profile_id );
	}

	public static function class_for_profile( string $profile_id ): string {
		$class = 'Hydra_Profile_Provider_' . strtoupper( substr( md5( $profile_id ), 0, 16 ) );
		if ( ! class_exists( $class, false ) ) {
			// AI Client 要求每个供应商对应独立类；类名只由散列生成，配置 ID 按 PHP 字面量转义。
			$quoted = var_export( $profile_id, true );
			eval( 'class ' . $class . ' extends Hydra_Provider { protected static function createProviderMetadata(): \\WordPress\\AiClient\\Providers\\DTO\\ProviderMetadata { return Hydra_Provider::metadata_for_profile(' . $quoted . '); } protected static function createProviderAvailability(): \\WordPress\\AiClient\\Providers\\Contracts\\ProviderAvailabilityInterface { return Hydra_Provider::availability_for_profile(' . $quoted . '); } protected static function createModelMetadataDirectory(): \\WordPress\\AiClient\\Providers\\Contracts\\ModelMetadataDirectoryInterface { return new Hydra_Model_Directory(' . $quoted . '); } }' );
		}
		return $class;
	}

	public static function register_all(): void {
		if ( ! class_exists( AiClient::class ) ) { return; }
		$registry = AiClient::defaultRegistry();
		foreach ( Hydra_Settings::get_profiles() as $profile ) {
			$profile_id = (string) ( $profile['id'] ?? '' );
			if ( '' === $profile_id ) { continue; }
			$class = self::class_for_profile( $profile_id );
			if ( ! $registry->hasProvider( $profile_id ) ) {
				$registry->registerProvider( $class );
			}
			$entries = Hydra_Settings::get_configured_entries( $profile_id );
			if ( $entries ) {
				// 仅提供占位密钥供其他插件识别空壳供应商，真实密钥只在插件内部用于请求。
				$registry->setProviderRequestAuthentication(
					$profile_id,
					new ApiKeyRequestAuthentication( '1' )
				);
			}
		}
	}
}
