<?php
/**
 * Anthropic Messages 协议转换器。
 *
 * @package Hydra_AI
 */

defined( 'ABSPATH' ) || exit;

use WordPress\AiClient\Common\Exception\InvalidArgumentException;
use WordPress\AiClient\Common\Exception\RuntimeException;
use WordPress\AiClient\Messages\DTO\Message;
use WordPress\AiClient\Messages\DTO\MessagePart;
use WordPress\AiClient\Messages\Enums\MessagePartChannelEnum;
use WordPress\AiClient\Messages\Enums\MessageRoleEnum;
use WordPress\AiClient\Providers\DTO\ProviderMetadata;
use WordPress\AiClient\Providers\Http\DTO\Request;
use WordPress\AiClient\Providers\Http\Exception\ResponseException;
use WordPress\AiClient\Providers\Models\DTO\ModelConfig;
use WordPress\AiClient\Providers\Models\DTO\ModelMetadata;
use WordPress\AiClient\Results\DTO\Candidate;
use WordPress\AiClient\Results\DTO\GenerativeAiResult;
use WordPress\AiClient\Results\DTO\TokenUsage;
use WordPress\AiClient\Results\Enums\FinishReasonEnum;
use WordPress\AiClient\Tools\DTO\FunctionCall;
use WordPress\AiClient\Tools\DTO\FunctionDeclaration;
use WordPress\AiClient\Tools\DTO\WebSearch;

/**
 * Anthropic Messages 协议（/v1/messages）。
 *
 * 实现逻辑参考 WordPress 官方 ai-provider-for-anthropic 插件（GPL-2.0-or-later）。
 *
 * @since 1.0.0
 */
class Hydra_Protocol_Anthropic implements Hydra_Protocol_Interface {

	/**
	 * Anthropic API 版本号。
	 *
	 * @var string
	 */
	const API_VERSION = '2023-06-01';

	/**
	 * @inheritDoc
	 */
	public function get_id(): string {
		return 'anthropic';
	}

	/**
	 * @inheritDoc
	 */
	public function get_path(): string {
		return 'messages';
	}

	/**
	 * @inheritDoc
	 */
	public function build_params( array $prompt, ModelConfig $config, string $model_id ): array {
		$params = array(
			'model'    => $model_id,
			'messages' => $this->build_messages( $prompt ),
		);

		$system_instruction = $config->getSystemInstruction();
		if ( $system_instruction ) {
			$params['system'] = $system_instruction;
		}

		// Anthropic 要求 max_tokens 必填。
		$max_tokens = $config->getMaxTokens();
		$params['max_tokens'] = null !== $max_tokens ? $max_tokens : 4096;

		$temperature = $config->getTemperature();
		if ( null !== $temperature ) {
			$params['temperature'] = $temperature;
		}

		$top_p = $config->getTopP();
		if ( null !== $top_p ) {
			$params['top_p'] = $top_p;
		}

		$top_k = $config->getTopK();
		if ( null !== $top_k ) {
			$params['top_k'] = $top_k;
		}

		$stop = $config->getStopSequences();
		if ( is_array( $stop ) && $stop ) {
			$params['stop_sequences'] = $stop;
		}

		if ( 'application/json' === $config->getOutputMimeType() && $config->getOutputSchema() ) {
			$params['output_format'] = array(
				'type'   => 'json_schema',
				'schema' => $config->getOutputSchema(),
			);
		}

		$declarations = $config->getFunctionDeclarations();
		$web_search   = $config->getWebSearch();

		if ( is_array( $declarations ) || $web_search ) {
			$params['tools'] = $this->build_tools( is_array( $declarations ) ? $declarations : array(), $web_search );
		}

		// 自定义选项并入请求参数，冲突时报错。
		foreach ( $config->getCustomOptions() as $key => $value ) {
			if ( array_key_exists( $key, $params ) ) {
				throw new InvalidArgumentException(
					sprintf( '自定义选项 "%s" 与已有参数冲突。', $key )
				);
			}
			$params[ $key ] = $value;
		}

		return $params;
	}

	/**
	 * @inheritDoc
	 */
	public function build_headers( ModelConfig $config ): array {
		$headers = array( 'Content-Type' => 'application/json' );

		// JSON Schema 结构化输出依赖 Beta 头。
		if ( 'application/json' === $config->getOutputMimeType() && $config->getOutputSchema() ) {
			$headers['anthropic-beta'] = 'structured-outputs-2025-11-13';
		}

		return $headers;
	}

	/**
	 * @inheritDoc
	 */
	public function apply_authentication( Request $request, string $api_key ): Request {
		$request = $request->withHeader( 'anthropic-version', self::API_VERSION );

		return $request->withHeader( 'x-api-key', $api_key );
	}

	/**
	 * 构建 messages 参数。
	 *
	 * @since 1.0.0
	 *
	 * @param array<int,Message> $prompt 消息列表。
	 * @return array<int,array<string,mixed>>
	 */
	private function build_messages( array $prompt ): array {
		$messages = array();

		foreach ( $prompt as $message ) {
			$content = array();

			foreach ( $message->getParts() as $part ) {
				$block = $this->convert_part( $part );
				if ( null !== $block ) {
					$content[] = $block;
				}
			}

			if ( ! $content ) {
				continue;
			}

			$messages[] = array(
				'role'    => $message->getRole()->isModel() ? 'assistant' : 'user',
				'content' => $content,
			);
		}

		return $messages;
	}

	/**
	 * 把单个消息部件转换为 Anthropic 内容块。
	 *
	 * @since 1.0.0
	 *
	 * @param MessagePart $part 消息部件。
	 * @return array<string,mixed>|null 不支持或为空时返回 null。
	 */
	private function convert_part( MessagePart $part ): ?array {
		$type = $part->getType();

		if ( $type->isText() ) {
			$text = (string) $part->getText();
			if ( '' === $text ) {
				return null;
			}
			if ( $part->getChannel()->isThought() ) {
				return array(
					'type'     => 'thinking',
					'thinking' => $text,
				);
			}
			return array(
				'type' => 'text',
				'text' => $text,
			);
		}

		if ( $type->isFile() ) {
			return $this->convert_file_part( $part );
		}

		if ( $type->isFunctionCall() ) {
			$call = $part->getFunctionCall();
			if ( ! $call ) {
				return null;
			}

			// Anthropic 要求 input 必须是 JSON 对象。
			$args = $call->getArgs();
			if ( ! is_array( $args ) || ! $args ) {
				$args = new stdClass();
			}

			return array(
				'type'  => 'tool_use',
				'id'    => (string) $call->getId(),
				'name'  => (string) $call->getName(),
				'input' => $args,
			);
		}

		if ( $type->isFunctionResponse() ) {
			$response = $part->getFunctionResponse();
			if ( ! $response ) {
				return null;
			}

			return array(
				'type'        => 'tool_result',
				'tool_use_id' => (string) $response->getId(),
				'content'     => (string) wp_json_encode( $response->getResponse() ),
			);
		}

		return null;
	}

	/**
	 * 把文件部件转换为 Anthropic 内容块。
	 *
	 * 支持图片（内联 base64 或远程 URL，格式限 JPEG/PNG/GIF/WebP）与
	 * 文档（PDF 远程 URL 或 base64，纯文本 base64）；音频、视频等其余
	 * 类型不受 Anthropic 协议支持，抛出异常后由故障转移切换条目。
	 *
	 * @since 1.0.1
	 *
	 * @param MessagePart $part 消息部件。
	 * @return array<string,mixed>
	 * @throws RuntimeException 文件类型不受该协议支持时抛出。
	 */
	private function convert_file_part( MessagePart $part ): array {
		$file = $part->getFile();
		if ( ! $file ) {
			throw new RuntimeException( __( '文件消息部件缺少文件数据。', 'hydra-ai' ) );
		}

		if ( $file->isImage() ) {
			// 远程图片：直接传 URL，由 Anthropic 服务端抓取。
			if ( $file->isRemote() ) {
				$url = $file->getUrl();
				if ( ! $url ) {
					throw new RuntimeException( __( '文件消息部件缺少文件数据。', 'hydra-ai' ) );
				}

				return array(
					'type'   => 'image',
					'source' => array(
						'type' => 'url',
						'url'  => $url,
					),
				);
			}

			// 内联图片：base64 源，格式必须在官方支持列表内。
			$mime = strtolower( $file->getMimeType() );
			if ( ! in_array( $mime, array( 'image/jpeg', 'image/png', 'image/gif', 'image/webp' ), true ) ) {
				throw new RuntimeException( __( '该协议的内联图片仅支持 JPEG、PNG、GIF 或 WebP。', 'hydra-ai' ) );
			}

			return array(
				'type'   => 'image',
				'source' => array(
					'type'       => 'base64',
					'media_type' => $mime,
					'data'       => Hydra_Files::to_base64( $file ),
				),
			);
		}

		/*
		 * 文档输入：SDK 将 PDF 等归类为 isDocument()，纯文本文件归类为
		 * isText()，两者 Anthropic 均支持。远程 PDF 直接传 URL；
		 * 其余以内联 base64 提供，远程纯文本先下载内联。
		 */
		if ( $file->isDocument() || $file->isText() ) {
			$mime = strtolower( $file->getMimeType() );

			if ( 'application/pdf' === $mime && $file->isRemote() ) {
				// 远程 PDF：直接传 URL。
				$url = $file->getUrl();
				if ( ! $url ) {
					throw new RuntimeException( __( '文件消息部件缺少文件数据。', 'hydra-ai' ) );
				}

				return array(
					'type'   => 'document',
					'source' => array(
						'type' => 'url',
						'url'  => $url,
					),
				);
			}

			$inline_ok = in_array( $mime, array( 'application/pdf', 'text/plain' ), true );
			if ( ! $inline_ok && ! in_array( $mime, array( 'text/markdown', 'text/html' ), true ) ) {
				throw new RuntimeException( __( '该协议的文档输入仅支持 PDF 或纯文本。', 'hydra-ai' ) );
			}

			return array(
				'type'   => 'document',
				'source' => array(
					'type'       => 'base64',
					'media_type' => in_array( $mime, array( 'application/pdf' ), true ) ? 'application/pdf' : 'text/plain',
					'data'       => Hydra_Files::to_base64( $file ),
				),
			);
		}

		throw new RuntimeException(
			sprintf(
				/* translators: %s: MIME 类型。 */
				__( '该协议不支持此输入文件类型：%s', 'hydra-ai' ),
				$file->getMimeType()
			)
		);
	}

	/**
	 * 构建 tools 参数。
	 *
	 * @since 1.0.0
	 *
	 * @param array<int,FunctionDeclaration> $declarations 函数声明列表。
	 * @param WebSearch|null                 $web_search   网页搜索配置。
	 * @return array<int,array<string,mixed>>
	 */
	private function build_tools( array $declarations, ?WebSearch $web_search ): array {
		$tools = array();

		foreach ( $declarations as $declaration ) {
			// Anthropic 要求 input_schema 必须存在。
			$schema = $declaration->getParameters();
			if ( ! is_array( $schema ) || ! $schema ) {
				$schema = array(
					'type'       => 'object',
					'properties' => new stdClass(),
				);
			}

			$tools[] = array(
				'name'          => (string) $declaration->getName(),
				'description'   => (string) $declaration->getDescription(),
				'input_schema'  => $schema,
			);
		}

		if ( $web_search ) {
			$tools[] = array(
				'type'     => 'web_search_20250305',
				'name'     => 'web_search',
				'max_uses' => 1,
			);
		}

		return $tools;
	}

	/**
	 * @inheritDoc
	 */
	public function parse_response(
		array $data,
		ProviderMetadata $provider_metadata,
		ModelMetadata $model_metadata
	): GenerativeAiResult {
		$provider_name = $provider_metadata->getName();

		if ( isset( $data['error'] ) && is_array( $data['error'] ) ) {
			$message = isset( $data['error']['message'] ) ? (string) $data['error']['message'] : '';
			throw ResponseException::fromInvalidData(
				$provider_name,
				'error',
				'' !== $message ? $message : __( '服务端返回了未知错误。', 'hydra-ai' )
			);
		}

		if ( empty( $data['content'] ) || ! is_array( $data['content'] ) ) {
			throw ResponseException::fromMissingData( $provider_name, 'content' );
		}

		$parts         = array();
		$has_tool_call = false;

		foreach ( $data['content'] as $block ) {
			if ( ! is_array( $block ) || ! isset( $block['type'] ) ) {
				continue;
			}

			switch ( (string) $block['type'] ) {
				case 'text':
					if ( isset( $block['text'] ) && is_string( $block['text'] ) && '' !== $block['text'] ) {
						$parts[] = new MessagePart( $block['text'] );
					}
					break;

				case 'thinking':
					if ( isset( $block['thinking'] ) && is_string( $block['thinking'] ) && '' !== $block['thinking'] ) {
						$parts[] = new MessagePart( $block['thinking'], MessagePartChannelEnum::thought() );
					}
					break;

				case 'tool_use':
					$args = isset( $block['input'] ) && is_array( $block['input'] ) && $block['input']
						? $block['input']
						: null;

					$parts[] = new MessagePart(
						new FunctionCall(
							isset( $block['id'] ) && is_string( $block['id'] ) ? $block['id'] : '',
							isset( $block['name'] ) && is_string( $block['name'] ) ? $block['name'] : '',
							$args
						)
					);
					$has_tool_call = true;
					break;

				default:
					// redacted_thinking、server_tool_use 等块暂不处理。
					break;
			}
		}

		if ( ! $parts ) {
			throw ResponseException::fromMissingData( $provider_name, 'content[]' );
		}

		$result = new Message( MessageRoleEnum::model(), $parts );

		$stop_reason = isset( $data['stop_reason'] ) && is_string( $data['stop_reason'] ) ? $data['stop_reason'] : 'end_turn';
		switch ( $stop_reason ) {
			case 'max_tokens':
			case 'model_context_window_exceeded':
				$finish_reason = FinishReasonEnum::length();
				break;
			case 'refusal':
				$finish_reason = FinishReasonEnum::contentFilter();
				break;
			case 'tool_use':
				$finish_reason = FinishReasonEnum::toolCalls();
				break;
			default:
				$finish_reason = FinishReasonEnum::stop();
		}

		// 用量统计：输入 token 包含缓存读写的部分。
		$usage = isset( $data['usage'] ) && is_array( $data['usage'] ) ? $data['usage'] : array();
		$input_tokens = ( isset( $usage['input_tokens'] ) && is_numeric( $usage['input_tokens'] ) ? (int) $usage['input_tokens'] : 0 )
			+ ( isset( $usage['cache_creation_input_tokens'] ) && is_numeric( $usage['cache_creation_input_tokens'] ) ? (int) $usage['cache_creation_input_tokens'] : 0 )
			+ ( isset( $usage['cache_read_input_tokens'] ) && is_numeric( $usage['cache_read_input_tokens'] ) ? (int) $usage['cache_read_input_tokens'] : 0 );
		$output_tokens = isset( $usage['output_tokens'] ) && is_numeric( $usage['output_tokens'] ) ? (int) $usage['output_tokens'] : 0;

		$additional = array();
		if ( isset( $data['model'] ) && is_string( $data['model'] ) && '' !== $data['model'] ) {
			$additional['model'] = $data['model'];
		}

		return new GenerativeAiResult(
			isset( $data['id'] ) && is_string( $data['id'] ) ? $data['id'] : '',
			array( new Candidate( $result, $finish_reason ) ),
			new TokenUsage( $input_tokens, $output_tokens, $input_tokens + $output_tokens ),
			$provider_metadata,
			$model_metadata,
			$additional
		);
	}
}
