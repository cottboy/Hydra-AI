<?php
/**
 * OpenAI Chat Completions 协议转换器。
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

/**
 * OpenAI Chat Completions 协议（/v1/chat/completions）。
 *
 * 兼容所有 OpenAI Chat 协议的服务端，包括各类 OpenAI 兼容中转。
 *
 * @since 1.0.0
 */
class Hydra_Protocol_Chat implements Hydra_Protocol_Interface {

	/**
	 * @inheritDoc
	 */
	public function get_id(): string {
		return 'chat';
	}

	/**
	 * @inheritDoc
	 */
	public function get_path(): string {
		return 'chat/completions';
	}

	/**
	 * @inheritDoc
	 */
	public function build_params( array $prompt, ModelConfig $config, string $model_id ): array {
		$params = array(
			'model'    => $model_id,
			'messages' => $this->build_messages( $prompt, $config ),
		);

		$max_tokens = $config->getMaxTokens();
		if ( null !== $max_tokens ) {
			$params['max_tokens'] = $max_tokens;
		}

		$temperature = $config->getTemperature();
		if ( null !== $temperature ) {
			$params['temperature'] = $temperature;
		}

		$top_p = $config->getTopP();
		if ( null !== $top_p ) {
			$params['top_p'] = $top_p;
		}

		$stop = $config->getStopSequences();
		if ( is_array( $stop ) && $stop ) {
			$params['stop'] = $stop;
		}

		// JSON Schema 结构化输出。
		if ( 'application/json' === $config->getOutputMimeType() && $config->getOutputSchema() ) {
			$params['response_format'] = array(
				'type'        => 'json_schema',
				'json_schema' => array(
					'name'    => 'response_schema',
					'schema'  => $config->getOutputSchema(),
					'strict'  => true,
				),
			);
		}

		// 自定义函数工具。
		$declarations = $config->getFunctionDeclarations();
		if ( is_array( $declarations ) && $declarations ) {
			$params['tools'] = $this->build_tools( $declarations );
		}

		/*
		 * 自定义选项直接并入请求参数，允许调用方传递各家扩展字段。
		 * 与既有参数冲突时报错，避免静默覆盖。
		 */
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
		return array( 'Content-Type' => 'application/json' );
	}

	/**
	 * @inheritDoc
	 */
	public function apply_authentication( Request $request, string $api_key ): Request {
		return $request->withHeader( 'Authorization', 'Bearer ' . $api_key );
	}

	/**
	 * 构建 messages 参数。
	 *
	 * @since 1.0.0
	 *
	 * @param array<int,Message> $prompt 消息列表。
	 * @param ModelConfig        $config 模型配置。
	 * @return array<int,array<string,mixed>>
	 */
	private function build_messages( array $prompt, ModelConfig $config ): array {
		$messages = array();

		$system_instruction = $config->getSystemInstruction();
		if ( $system_instruction ) {
			$messages[] = array(
				'role'    => 'system',
				'content' => $system_instruction,
			);
		}

		foreach ( $prompt as $message ) {
			$converted = $this->convert_message( $message );
			if ( null !== $converted ) {
				$messages[] = $converted;
			}
		}

		return $messages;
	}

	/**
	 * 把单条消息转换为 Chat 协议的消息数组。
	 *
	 * @since 1.0.0
	 *
	 * @param Message $message 消息对象。
	 * @return array<string,mixed>|null 空消息返回 null。
	 */
	private function convert_message( Message $message ): ?array {
		$role         = $message->getRole()->isModel() ? 'assistant' : 'user';
		$tool_calls   = array();
		$tool_result  = null;
		$content      = array();
		$has_thought  = false;

		foreach ( $message->getParts() as $part ) {
			$type = $part->getType();

			if ( $type->isText() ) {
				$text = (string) $part->getText();
				if ( '' === $text ) {
					continue;
				}
				if ( $part->getChannel()->isThought() ) {
					// 思考内容不影响正文，仅跳过。
					$has_thought = true;
					continue;
				}
				$content[] = array(
					'type' => 'text',
					'text' => $text,
				);
				continue;
			}

			if ( $type->isFile() ) {
				$content[] = $this->convert_file_part( $part );
				continue;
			}

			if ( $type->isFunctionCall() ) {
				$call = $part->getFunctionCall();
				if ( ! $call ) {
					continue;
				}
				$tool_calls[] = array(
					'id'       => (string) $call->getId(),
					'type'     => 'function',
					'function' => array(
						'name'      => (string) $call->getName(),
						'arguments' => wp_json_encode( $call->getArgs() ?? new stdClass() ),
					),
				);
				continue;
			}

			if ( $type->isFunctionResponse() ) {
				$response = $part->getFunctionResponse();
				if ( $response ) {
					$tool_result = array(
						'role'         => 'tool',
						'tool_call_id' => (string) $response->getId(),
						'content'      => (string) wp_json_encode( $response->getResponse() ),
					);
				}
			}
		}

		// 工具响应消息单独成条。
		if ( $tool_result ) {
			return $tool_result;
		}

		if ( $tool_calls ) {
			$entry = array(
				'role'       => 'assistant',
				'content'    => null,
				'tool_calls' => $tool_calls,
			);
			if ( $content ) {
				$entry['content'] = 1 === count( $content ) && 'text' === $content[0]['type']
					? (string) $content[0]['text']
					: $content;
			}
			return $entry;
		}

		if ( ! $content ) {
			return null;
		}

		return array(
			'role'    => $role,
			'content' => 1 === count( $content ) && 'text' === $content[0]['type']
				? (string) $content[0]['text']
				: $content,
		);
	}

	/**
	 * 把文件部件转换为 Chat 协议的内容项。
	 *
	 * 支持图片（远程 URL 或 data URI）、音频（WAV/MP3，远端自动下载内联）
	 * 与 PDF 文档（远端自动下载内联）；其余类型抛出异常，由故障转移
	 * 切换到支持的供应商条目。
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
			// 图片：远端直接传 URL，内联传 data URI。
			$url = $file->isRemote() ? $file->getUrl() : $file->getDataUri();
			if ( ! $url ) {
				throw new RuntimeException( __( '文件消息部件缺少文件数据。', 'hydra-ai' ) );
			}

			return array(
				'type'      => 'image_url',
				'image_url' => array( 'url' => $url ),
			);
		}

		if ( $file->isAudio() ) {
			// 音频：Chat 协议仅接受内联 base64，远端文件自动下载。
			return array(
				'type'        => 'input_audio',
				'input_audio' => array(
					'data'   => Hydra_Files::to_base64( $file ),
					'format' => Hydra_Files::openai_audio_format( $file ),
				),
			);
		}

		if ( $file->isDocument() ) {
			// 文档：Chat 协议仅支持 PDF，以 data URI 内联。
			if ( 'application/pdf' !== strtolower( $file->getMimeType() ) ) {
				throw new RuntimeException( __( '该协议的文档输入仅支持 PDF。', 'hydra-ai' ) );
			}

			return array(
				'type' => 'file',
				'file' => array(
					'filename'  => Hydra_Files::file_name( $file ),
					'file_data' => Hydra_Files::to_data_uri( $file ),
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
	 * @return array<int,array<string,mixed>>
	 */
	private function build_tools( array $declarations ): array {
		$tools = array();

		foreach ( $declarations as $declaration ) {
			$parameters = $declaration->getParameters();
			if ( ! is_array( $parameters ) || ! $parameters ) {
				// OpenAI 要求 parameters 必须是 JSON 对象。
				$parameters = array(
					'type'       => 'object',
					'properties' => new stdClass(),
				);
			}

			$tools[] = array(
				'type'     => 'function',
				'function' => array(
					'name'        => (string) $declaration->getName(),
					'description' => (string) $declaration->getDescription(),
					'parameters'  => $parameters,
				),
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

		// 部分兼容服务端在 200 响应中返回错误对象。
		if ( isset( $data['error'] ) && is_array( $data['error'] ) ) {
			$message = isset( $data['error']['message'] ) ? (string) $data['error']['message'] : '';
			throw ResponseException::fromInvalidData(
				$provider_name,
				'error',
				'' !== $message ? $message : __( '服务端返回了未知错误。', 'hydra-ai' )
			);
		}

		if ( empty( $data['choices'] ) || ! is_array( $data['choices'] ) ) {
			throw ResponseException::fromMissingData( $provider_name, 'choices' );
		}

		$choice = $data['choices'][0];
		if ( ! is_array( $choice ) || empty( $choice['message'] ) || ! is_array( $choice['message'] ) ) {
			throw ResponseException::fromMissingData( $provider_name, 'choices[0].message' );
		}

		$parts = array();

		// 部分模型（如 DeepSeek-R1）会返回推理内容，映射为思考部件。
		if ( isset( $choice['message']['reasoning_content'] ) && is_string( $choice['message']['reasoning_content'] ) && '' !== $choice['message']['reasoning_content'] ) {
			$parts[] = new MessagePart( $choice['message']['reasoning_content'], MessagePartChannelEnum::thought() );
		}

		foreach ( $this->parse_content( $choice['message'] ) as $part ) {
			$parts[] = $part;
		}

		if ( ! $parts ) {
			throw ResponseException::fromMissingData( $provider_name, 'choices[0].message.content' );
		}

		$result = new Message( MessageRoleEnum::model(), $parts );

		$additional = array();
		if ( isset( $data['model'] ) && is_string( $data['model'] ) && '' !== $data['model'] ) {
			$additional['model'] = $data['model'];
		}

		return new GenerativeAiResult(
			isset( $data['id'] ) && is_string( $data['id'] ) ? $data['id'] : '',
			array( new Candidate( $result, $this->parse_finish_reason( $choice ) ) ),
			$this->parse_usage( $data ),
			$provider_metadata,
			$model_metadata,
			$additional
		);
	}

	/**
	 * 把 message.content 解析为消息部件列表。
	 *
	 * @since 1.0.0
	 *
	 * @param array<string,mixed> $message_data 响应中的 message 对象。
	 * @return array<int,MessagePart>
	 */
	private function parse_content( array $message_data ): array {
		$parts = array();

		$content = $message_data['content'] ?? null;

		if ( is_string( $content ) && '' !== $content ) {
			$parts[] = new MessagePart( $content );
		} elseif ( is_array( $content ) ) {
			foreach ( $content as $item ) {
				if ( ! is_array( $item ) ) {
					continue;
				}
				if ( isset( $item['type'], $item['text'] ) && 'text' === $item['type'] && is_string( $item['text'] ) ) {
					$parts[] = new MessagePart( $item['text'] );
				}
			}
		}

		// 工具调用转换为函数调用部件。
		if ( ! empty( $message_data['tool_calls'] ) && is_array( $message_data['tool_calls'] ) ) {
			foreach ( $message_data['tool_calls'] as $tool_call ) {
				if ( ! is_array( $tool_call ) || empty( $tool_call['function'] ) || ! is_array( $tool_call['function'] ) ) {
					continue;
				}

				$function = $tool_call['function'];
				$args     = null;
				if ( isset( $function['arguments'] ) && is_string( $function['arguments'] ) ) {
					$decoded = json_decode( $function['arguments'], true );
					if ( is_array( $decoded ) && $decoded ) {
						$args = $decoded;
					}
				}

				$parts[] = new MessagePart(
					new FunctionCall(
						isset( $tool_call['id'] ) && is_string( $tool_call['id'] ) ? $tool_call['id'] : '',
						isset( $function['name'] ) && is_string( $function['name'] ) ? $function['name'] : '',
						$args
					)
				);
			}
		}

		return $parts;
	}

	/**
	 * 解析 finish_reason。
	 *
	 * @since 1.0.0
	 *
	 * @param array<string,mixed> $choice 响应中的 choice 对象。
	 * @return FinishReasonEnum
	 */
	private function parse_finish_reason( array $choice ): FinishReasonEnum {
		switch ( isset( $choice['finish_reason'] ) ? $choice['finish_reason'] : '' ) {
			case 'length':
				return FinishReasonEnum::length();
			case 'tool_calls':
			case 'function_call':
				return FinishReasonEnum::toolCalls();
			case 'content_filter':
				return FinishReasonEnum::contentFilter();
			default:
				return FinishReasonEnum::stop();
		}
	}

	/**
	 * 解析 token 用量。
	 *
	 * @since 1.0.0
	 *
	 * @param array<string,mixed> $data 响应数据。
	 * @return TokenUsage
	 */
	private function parse_usage( array $data ): TokenUsage {
		$usage = isset( $data['usage'] ) && is_array( $data['usage'] ) ? $data['usage'] : array();

		$prompt     = isset( $usage['prompt_tokens'] ) && is_numeric( $usage['prompt_tokens'] ) ? (int) $usage['prompt_tokens'] : 0;
		$completion = isset( $usage['completion_tokens'] ) && is_numeric( $usage['completion_tokens'] ) ? (int) $usage['completion_tokens'] : 0;
		$total      = isset( $usage['total_tokens'] ) && is_numeric( $usage['total_tokens'] ) ? (int) $usage['total_tokens'] : $prompt + $completion;

		return new TokenUsage( $prompt, $completion, $total );
	}
}
