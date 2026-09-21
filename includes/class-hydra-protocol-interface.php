<?php
/**
 * 协议转换器接口。
 *
 * @package Hydra_AI
 */

defined( 'ABSPATH' ) || exit;

use WordPress\AiClient\Messages\DTO\Message;
use WordPress\AiClient\Providers\DTO\ProviderMetadata;
use WordPress\AiClient\Providers\Http\DTO\Request;
use WordPress\AiClient\Providers\Models\DTO\ModelConfig;
use WordPress\AiClient\Providers\Models\DTO\ModelMetadata;
use WordPress\AiClient\Results\DTO\GenerativeAiResult;

/**
 * 协议转换器接口。
 *
 * 每种协议负责：把 PHP AI Client 的消息与配置转换成该协议的请求参数，
 * 以及把该协议的响应解析回 PHP AI Client 的结果对象。
 *
 * @since 1.0.0
 */
interface Hydra_Protocol_Interface {

	/**
	 * 获取协议 ID（chat / responses / anthropic）。
	 *
	 * @since 1.0.0
	 *
	 * @return string
	 */
	public function get_id(): string;

	/**
	 * 获取请求路径，追加在端点基础地址之后。
	 *
	 * @since 1.0.0
	 *
	 * @return string
	 */
	public function get_path(): string;

	/**
	 * 把消息与模型配置转换成请求参数。
	 *
	 * @since 1.0.0
	 *
	 * @param array<int,Message> $prompt   消息列表。
	 * @param ModelConfig        $config   模型配置。
	 * @param string             $model_id 模型 ID。
	 * @return array<string,mixed>
	 */
	public function build_params( array $prompt, ModelConfig $config, string $model_id ): array;

	/**
	 * 构建基础请求头（不含鉴权头）。
	 *
	 * @since 1.0.0
	 *
	 * @param ModelConfig $config 模型配置。
	 * @return array<string,string>
	 */
	public function build_headers( ModelConfig $config ): array;

	/**
	 * 向请求添加鉴权信息。
	 *
	 * @since 1.0.0
	 *
	 * @param Request $request 请求对象。
	 * @param string  $api_key API Key。
	 * @return Request
	 */
	public function apply_authentication( Request $request, string $api_key ): Request;

	/**
	 * 把协议响应解析为生成结果。
	 *
	 * @since 1.0.0
	 *
	 * @param array<string,mixed> $data              已解码的响应 JSON。
	 * @param ProviderMetadata    $provider_metadata 供应商元数据（用于结果对象与异常信息）。
	 * @param ModelMetadata       $model_metadata    模型元数据。
	 * @return GenerativeAiResult
	 */
	public function parse_response(
		array $data,
		ProviderMetadata $provider_metadata,
		ModelMetadata $model_metadata
	): GenerativeAiResult;

	/**
	 * 将完整 SSE 响应聚合为生成结果。
	 *
	 * @since 1.1.0
	 *
	 * @param string           $body              SSE 响应正文。
	 * @param ProviderMetadata $provider_metadata 供应商元数据。
	 * @param ModelMetadata    $model_metadata    模型元数据。
	 * @return GenerativeAiResult
	 */
	public function parse_stream_response(
		string $body,
		ProviderMetadata $provider_metadata,
		ModelMetadata $model_metadata
	): GenerativeAiResult;
}
