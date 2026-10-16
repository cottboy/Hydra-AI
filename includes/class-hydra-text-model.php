<?php
/**
 * Hydra AI 文本生成模型：多供应商故障转移核心。
 *
 * @package Hydra_AI
 */

defined( 'ABSPATH' ) || exit;

use WordPress\AiClient\AiClient;
use WordPress\AiClient\Common\Exception\RuntimeException;
use WordPress\AiClient\Common\Exception\TokenLimitReachedException;
use WordPress\AiClient\Messages\DTO\Message;
use WordPress\AiClient\Messages\DTO\MessagePart;
use WordPress\AiClient\Messages\Enums\MessageRoleEnum;
use WordPress\AiClient\Providers\ApiBasedImplementation\AbstractApiBasedModel;
use WordPress\AiClient\Providers\Http\DTO\Request;
use WordPress\AiClient\Providers\Http\DTO\RequestOptions;
use WordPress\AiClient\Providers\Http\DTO\Response;
use WordPress\AiClient\Providers\Http\Enums\HttpMethodEnum;
use WordPress\AiClient\Providers\Http\Exception\ResponseException;
use WordPress\AiClient\Providers\Http\Util\ResponseUtil;
use WordPress\AiClient\Providers\Models\DTO\ModelConfig;
use WordPress\AiClient\Providers\Models\DTO\ModelMetadata;
use WordPress\AiClient\Providers\Models\ImageGeneration\Contracts\ImageGenerationModelInterface;
use WordPress\AiClient\Providers\Models\SpeechGeneration\Contracts\SpeechGenerationModelInterface;
use WordPress\AiClient\Providers\Models\TextGeneration\Contracts\TextGenerationModelInterface;
use WordPress\AiClient\Providers\Models\TextToSpeechConversion\Contracts\TextToSpeechConversionModelInterface;
use WordPress\AiClient\Results\DTO\GenerativeAiResult;

/**
 * Hydra AI 文本生成模型。
 *
 * 按用户配置的优先级（列表从上到下）依次请求各供应商条目：
 * 单个条目失败（网络错误、HTTP 错误、响应解析失败等）时自动切换到下一个，
 * 直至某个条目成功或全部失败。跨协议回退同样适用。
 *
 * @since 1.0.0
 */
class Hydra_Text_Model extends AbstractApiBasedModel implements TextGenerationModelInterface, ImageGenerationModelInterface, SpeechGenerationModelInterface, TextToSpeechConversionModelInterface {

	/** Anthropic 服务端工具暂停后最多自动续接次数。 */
	private const MAX_ANTHROPIC_CONTINUATIONS = 5;

	/**
	 * @inheritDoc
	 */
	public function generateTextResult( array $prompt ): GenerativeAiResult {
		return $this->generate_result( $prompt );
	}

	/**
	 * @inheritDoc
	 */
	public function generateImageResult( array $prompt ): GenerativeAiResult {
		return $this->generate_result( $prompt );
	}

	/**
	 * @inheritDoc
	 */
	public function generateSpeechResult( array $prompt ): GenerativeAiResult {
		return $this->generate_result( $prompt );
	}

	/**
	 * @inheritDoc
	 */
	public function convertTextToSpeechResult( array $prompt ): GenerativeAiResult {
		return $this->generate_result( $prompt );
	}

	/**
	 * 执行一次生成请求，并在条目间故障转移。
	 *
	 * 输出类型由 WordPress AI Client 写入 ModelConfig 的 outputModalities，
	 * 协议转换器据此生成对应请求。
	 *
	 * @since 1.1.0
	 *
	 * @param array<int,Message> $prompt 消息列表。
	 * @return GenerativeAiResult
	 */
	private function generate_result( array $prompt ): GenerativeAiResult {
		$profile_id = $this->providerMetadata()->getId();
		$entries = Hydra_Settings::get_configured_entries( $profile_id );

		if ( ! $entries ) {
			throw new RuntimeException(
				__( 'Hydra AI 尚未配置任何已启用的供应商，请前往“设置 → Hydra AI”添加。', 'hydra-ai' )
			);
		}

		$ordered = $this->order_entries( $entries, $this->metadata()->getId() );

		$last = null;

		foreach ( $ordered as $entry ) {
			try {
				return $this->generate_for_entry( $entry, $prompt );
			} catch ( Throwable $e ) {
				$last = $e;
			}
		}

		// 保留最后一个异常的原始类型，便于调用方按 SDK 异常类型处理。
		throw $last;
	}

	/**
	 * 按请求模型对条目排序：模型匹配者优先（保持列表顺序），其余按列表顺序。
	 *
	 * 显式请求某个模型时优先命中同模型条目；未命中或全部失败时，
	 * 仍按用户设定的优先级顺序回退到其他条目。
	 *
	 * @since 1.0.0
	 *
	 * @param array<int,array<string,mixed>> $entries       已启用条目。
	 * @param string                         $requested_model 请求的模型 ID。
	 * @return array<int,array<string,mixed>>
	 */
	private function order_entries( array $entries, string $requested_model ): array {
		$matched = array();
		$rest    = array();

		foreach ( $entries as $entry ) {
			if ( isset( $entry['model'] ) && hash_equals( (string) $entry['model'], $requested_model ) ) {
				$matched[] = $entry;
			} else {
				$rest[] = $entry;
			}
		}

		return array_merge( $matched, $rest );
	}

	/**
	 * 使用单个供应商条目完成一次生成请求。
	 *
	 * @since 1.0.0
	 *
	 * @param array<string,mixed> $entry  供应商条目。
	 * @param array<int,Message>  $prompt 消息列表。
	 * @return GenerativeAiResult
	 * @throws Throwable 请求失败或响应无效时抛出。
	 */
	private function generate_for_entry( array $entry, array $prompt ): GenerativeAiResult {
		$protocol = Hydra_Protocols::get( (string) $entry['protocol'] );

		$api_key = (string) ( $entry['api_key'] ?? '' );
		if ( '' === $api_key ) {
			throw new RuntimeException(
				__( '未配置接口密钥，请前往“设置 → Hydra AI”填写。', 'hydra-ai' )
			);
		}

		$endpoint = (string) $entry['endpoint'];
		if ( ! wp_parse_url( $endpoint, PHP_URL_HOST ) ) {
			throw new RuntimeException(
				__( '端点地址无效。', 'hydra-ai' )
			);
		}

		$params  = $protocol->build_params( $prompt, $this->getConfig(), (string) $entry['model'] );
		$headers = $protocol->build_headers( $this->getConfig() );

		if ( $protocol instanceof Hydra_Protocol_Anthropic ) {
			return $this->generate_anthropic_result( $protocol, $endpoint, $params, $headers, $api_key );
		}

		$response = $this->send_protocol_request( $protocol, $endpoint, $params, $headers, $api_key );
		$data     = $response->getData();
		if ( is_array( $data ) ) {
			return $protocol->parse_response( $data, $this->providerMetadata(), $this->metadata() );
		}
		return $protocol->parse_stream_response( (string) $response->getBody(), $this->providerMetadata(), $this->metadata() );
	}

	/**
	 * 原样续接服务端暂停的 Messages 回答，合并内容与所有请求的用量。
	 *
	 * 先完成原始内容的续接，再转换结果，确保服务端工具和容器状态不会丢失。
	 */
	private function generate_anthropic_result( Hydra_Protocol_Anthropic $protocol, string $url, array $params, array $headers, string $api_key ): GenerativeAiResult {
		$content = array();
		$usage   = array();
		$thought = null;

		for ( $index = 0; $index <= self::MAX_ANTHROPIC_CONTINUATIONS; $index++ ) {
			$response = $this->send_protocol_request( $protocol, $url, $params, $headers, $api_key );
			$data     = $response->getData();
			if ( ! is_array( $data ) ) {
				$data = $protocol->decode_stream_response( (string) $response->getBody() );
			}
			if ( isset( $data['error'] ) ) {
				return $protocol->parse_response( $data, $this->providerMetadata(), $this->metadata() );
			}
			if ( ! isset( $data['content'] ) || ! is_array( $data['content'] ) ) {
				throw ResponseException::fromMissingData( $this->providerMetadata()->getName(), 'content' );
			}
			$content = array_merge( $content, $data['content'] );
			$leg_usage = isset( $data['usage'] ) && is_array( $data['usage'] ) ? $data['usage'] : array();
			foreach ( array( 'input_tokens', 'output_tokens', 'cache_creation_input_tokens', 'cache_read_input_tokens' ) as $key ) {
				$usage[ $key ] = ( $usage[ $key ] ?? 0 ) + ( is_int( $leg_usage[ $key ] ?? null ) ? $leg_usage[ $key ] : 0 );
			}
			if ( isset( $leg_usage['output_tokens_details']['thinking_tokens'] ) && is_int( $leg_usage['output_tokens_details']['thinking_tokens'] ) ) {
				$thought = ( $thought ?? 0 ) + $leg_usage['output_tokens_details']['thinking_tokens'];
			}
			if ( 'pause_turn' !== ( $data['stop_reason'] ?? '' ) || $index === self::MAX_ANTHROPIC_CONTINUATIONS ) {
				break;
			}
			$params['messages'][] = array(
				'role' => 'assistant',
				'content' => $data['content']
			);
			if ( isset( $data['container']['id'] ) && is_string( $data['container']['id'] ) ) {
				$params['container'] = $data['container']['id'];
			}
		}
		$data['content'] = $content;
		// 保留最后一段的扩展用量字段，并覆盖需要累加的字段。
		$data['usage'] = array_merge( $leg_usage, $usage );
		if ( null !== $thought ) {
			$data['usage']['output_tokens_details']['thinking_tokens'] = $thought;
		}
		return $protocol->parse_response( $data, $this->providerMetadata(), $this->metadata() );
	}

	/** 发送协议请求，只为管理员明确配置的地址允许内网访问。 */
	private function send_protocol_request( Hydra_Protocol_Interface $protocol, string $url, array $params, array $headers, string $api_key ): Response {
		$options = $this->getRequestOptions() ? clone $this->getRequestOptions() : new RequestOptions();
		// 禁止携带密钥跳转到其他地址。
		$options->setMaxRedirects( 0 );
		$request = new Request( HttpMethodEnum::POST(), $url, $headers, $params, $options );
		$request = $protocol->apply_authentication( $request, $api_key );

		$entry_host = (string) wp_parse_url( $url, PHP_URL_HOST );
		$allow_external = static function ( $allowed, $host ) use ( $entry_host ) {
			return 0 === strcasecmp( (string) $host, $entry_host ) ? true : $allowed;
		};
		add_filter( 'http_request_host_is_external', $allow_external, 10, 2 );
		$entry_port = (int) wp_parse_url( $url, PHP_URL_PORT );
		$allow_port = static function ( $ports ) use ( $entry_port ) {
			if ( $entry_port > 0 ) {
				$ports[] = $entry_port;
			}
			return $ports;
		};
		add_filter( 'http_allowed_safe_ports', $allow_port );
		try {
			$response = $this->getHttpTransporter()->send( $request );
		} finally {
			remove_filter( 'http_request_host_is_external', $allow_external );
			remove_filter( 'http_allowed_safe_ports', $allow_port );
		}
		ResponseUtil::throwIfNotSuccessful( $response );
		return $response;
	}

	/**
	 * 对单个供应商条目执行连通性测试（设置页“测试”按钮）。
	 *
	 * 只请求该条目本身，不触发故障转移。
	 *
	 * @since 1.0.0
	 *
	 * @param array<string,mixed> $entry 供应商条目。
	 * @return array{ok:bool,message:string}
	 */
	public static function test_entry( array $entry, string $profile_id ): array {
		try {
			$provider_class = Hydra_Provider::class_for_profile( $profile_id );
			$model = new self(
				new ModelMetadata(
					(string) $entry['model'],
					(string) $entry['model'],
					array(),
					array()
				),
				$provider_class::metadata()
			);

			// 绑定默认注册表的传输器；请求密钥只取当前接口的配置。
			AiClient::defaultRegistry()->bindModelDependencies( $model );

			$options = new RequestOptions();
			$options->setTimeout( 20.0 );
			$model->setRequestOptions( $options );

			// 最小化请求：1 个输出 token。
			$model->setConfig( ModelConfig::fromArray( array( ModelConfig::KEY_MAX_TOKENS => 1 ) ) );

			$model->generate_for_entry(
				$entry,
				array( new Message( MessageRoleEnum::user(), array( new MessagePart( 'Hi' ) ) ) )
			);

			return array(
				'ok'      => true,
				'message' => __( '连接成功。', 'hydra-ai' ),
			);
		} catch ( TokenLimitReachedException $e ) {
			// 测试仅请求很少的输出额度，额度用尽仍说明接口和密钥有效。
			return array(
				'ok' => true,
				'message' => __( '连接成功。', 'hydra-ai' )
			);
		} catch ( Throwable $e ) {
			return array(
				'ok'      => false,
				'message' => $e->getMessage(),
			);
		}
	}
}
