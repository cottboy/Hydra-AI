<?php
/**
 * OpenAI Responses 协议转换器。
 *
 * @package Hydra_AI
 */

defined( 'ABSPATH' ) || exit;

use WordPress\AiClient\Common\Exception\InvalidArgumentException;
use WordPress\AiClient\Common\Exception\RuntimeException;
use WordPress\AiClient\Files\DTO\File;
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
 * OpenAI Responses 协议（/v1/responses）。
 *
 * 实现逻辑参考 WordPress 官方 ai-provider-for-openai 插件（GPL-2.0-or-later）。
 *
 * @since 1.0.0
 */
class Hydra_Protocol_Responses implements Hydra_Protocol_Interface {

	/**
	 * @inheritDoc
	 */
	public function get_id(): string {
		return 'responses';
	}

	/**
	 * @inheritDoc
	 */
	public function get_path(): string {
		return 'responses';
	}

	/**
	 * @inheritDoc
	 */
	public function build_params( array $prompt, ModelConfig $config, string $model_id ): array {
		if (
			null !== $config->getCandidateCount()
			|| null !== $config->getTopK()
			|| null !== $config->getStopSequences()
			|| null !== $config->getPresencePenalty()
			|| null !== $config->getFrequencyPenalty()
			|| null !== $config->getLogprobs()
			|| null !== $config->getTopLogprobs()
			|| null !== $config->getOutputSpeechVoice()
		) {
			throw new RuntimeException( __( 'Responses 协议不支持本次请求中的配置选项。', 'hydra-ai' ) );
		}
		$image_output = $this->requests_image_output( $config );
		$file_type    = $config->getOutputFileType();
		if ( ( null !== $file_type && $file_type->isRemote() ) || ( ! $image_output && ( null !== $config->getOutputMediaOrientation() || null !== $config->getOutputMediaAspectRatio() ) ) ) {
			throw new RuntimeException( __( 'Responses 协议不支持本次请求中的配置选项。', 'hydra-ai' ) );
		}

		$params = array(
			'model' => $model_id,
			'input' => $this->build_input( $prompt ),
		);

		$system_instruction = $config->getSystemInstruction();
		if ( $system_instruction ) {
			$params['instructions'] = $system_instruction;
		}

		$max_tokens = $config->getMaxTokens();
		if ( null !== $max_tokens ) {
			$params['max_output_tokens'] = $max_tokens;
		}

		$temperature = $config->getTemperature();
		if ( null !== $temperature ) {
			$params['temperature'] = $temperature;
		}

		$top_p = $config->getTopP();
		if ( null !== $top_p ) {
			$params['top_p'] = $top_p;
		}

		if ( 'application/json' === $config->getOutputMimeType() ) {
			$params['text'] = array(
				'format' => $config->getOutputSchema()
					? array(
						'type'   => 'json_schema',
						'name'   => 'response_schema',
						'schema' => $config->getOutputSchema(),
						'strict' => true,
					)
					: array( 'type' => 'json_object' ),
			);
		}

		$declarations = $config->getFunctionDeclarations();
		$web_search   = $config->getWebSearch();

		if ( is_array( $declarations ) || $web_search ) {
			$params['tools'] = $this->build_tools( is_array( $declarations ) ? $declarations : array(), $web_search );
		}

		if ( $image_output ) {
			$params['tools']   = isset( $params['tools'] ) ? $params['tools'] : array();
			$params['tools'][] = $this->build_image_tool( $config );
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
	 * 判断本次请求是否要求图片输出。
	 *
	 * @param ModelConfig $config 模型配置。
	 * @return bool
	 */
	private function requests_image_output( ModelConfig $config ): bool {
		$modalities = $config->getOutputModalities();
		if ( ! is_array( $modalities ) ) {
			return false;
		}
		foreach ( $modalities as $modality ) {
			if ( $modality->isImage() ) {
				return true;
			}
			if ( ! $modality->isText() ) {
				throw new RuntimeException( __( 'Responses 协议不支持请求该输出模态。', 'hydra-ai' ) );
			}
		}
		return false;
	}

	/**
	 * 构建 Responses 图片生成工具。
	 *
	 * @param ModelConfig $config 模型配置。
	 * @return array<string,mixed>
	 */
	private function build_image_tool( ModelConfig $config ): array {
		$tool = array( 'type' => 'image_generation' );
		$mime = $config->getOutputMimeType();
		if ( is_string( $mime ) && 0 === strpos( $mime, 'image/' ) ) {
			$tool['output_format'] = substr( $mime, 6 );
		}

		$ratio = $config->getOutputMediaAspectRatio();
		if ( '3:2' === $ratio ) {
			$tool['size'] = '1536x1024';
		} elseif ( '2:3' === $ratio ) {
			$tool['size'] = '1024x1536';
		} elseif ( '1:1' === $ratio ) {
			$tool['size'] = '1024x1024';
		} elseif ( null !== $config->getOutputMediaOrientation() ) {
			$orientation = $config->getOutputMediaOrientation();
			$tool['size'] = $orientation->isLandscape() ? '1536x1024' : ( $orientation->isPortrait() ? '1024x1536' : '1024x1024' );
		}

		return $tool;
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
	 * 构建 input 参数。
	 *
	 * @since 1.0.0
	 *
	 * @param array<int,Message> $prompt 消息列表。
	 * @return array<int,array<string,mixed>>
	 */
	private function build_input( array $prompt ): array {
		$input = array();

		foreach ( $prompt as $message ) {
			$input = array_merge( $input, $this->convert_message( $message ) );
		}

		return $input;
	}

	/**
	 * 把单条消息转换为 Responses API 的输入项列表。
	 *
	 * @since 1.0.0
	 *
	 * @param Message $message 消息对象。
	 * @return array<int,array<string,mixed>> 空消息返回空数组。
	 */
	private function convert_message( Message $message ): array {
		$parts     = $message->getParts();
		$is_model  = $message->getRole()->isModel();
		$content   = array();
		$top_items = array();

		foreach ( $parts as $part ) {
			$type = $part->getType();

			if ( $type->isText() ) {
				$text = (string) $part->getText();
				if ( '' === $text || $part->getChannel()->isThought() ) {
					continue;
				}
				$content[] = array(
					'type' => $is_model ? 'output_text' : 'input_text',
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
				// Responses API 要求函数调用作为顶层输入项。
				$top_items[] = array(
					'type'      => 'function_call',
					'call_id'   => (string) $call->getId(),
					'name'      => (string) $call->getName(),
					'arguments' => (string) wp_json_encode( $call->getArgs() ?? new stdClass() ),
				);
				continue;
			}

			if ( $type->isFunctionResponse() ) {
				$response = $part->getFunctionResponse();
				if ( $response ) {
					$top_items[] = array(
						'type'    => 'function_call_output',
						'call_id' => (string) $response->getId(),
						'output'  => (string) wp_json_encode( $response->getResponse() ),
					);
				}
			}
		}

		if ( $content ) {
			array_unshift(
				$top_items,
				array(
					'role'    => $is_model ? 'assistant' : 'user',
					'content' => $content,
				)
			);
		}

		return $top_items;
	}

	/**
	 * 把文件部件转换为 Responses API 的输入内容项。
	 *
	 * 支持图片（远程 URL 或 data URI）、音频（WAV/MP3，远端自动下载内联）
	 * 与文件输入（远端传 file_url，内联传 file_data）；其余类型抛出异常，
	 * 由故障转移切换到支持的供应商条目。
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
				'type'      => 'input_image',
				'image_url' => $url,
			);
		}

		if ( $file->isAudio() ) {
			// 音频：Responses 协议仅接受内联 base64，远端文件自动下载。
			return array(
				'type'   => 'input_audio',
				'data'   => Hydra_Files::to_base64( $file ),
				'format' => Hydra_Files::openai_audio_format( $file ),
			);
		}

		if ( $file->isDocument() || $file->isText() ) {
			// 文件：远端传 URL，内联传带 MIME 的 data URI。
			if ( $file->isRemote() ) {
				return array(
					'type'     => 'input_file',
					'file_url' => (string) $file->getUrl(),
				);
			}

			return array(
				'type'      => 'input_file',
				'filename'  => Hydra_Files::file_name( $file ),
				'file_data' => (string) $file->getDataUri(),
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
			$tools[] = array(
				'type'        => 'function',
				'name'        => (string) $declaration->getName(),
				'description' => (string) $declaration->getDescription(),
				'parameters'  => $declaration->getParameters() ?? array(
					'type'       => 'object',
					'properties' => new stdClass(),
				),
			);
		}

		if ( $web_search ) {
			if ( $web_search->getDisallowedDomains() ) {
				throw new RuntimeException( __( 'Responses 协议不支持排除网页搜索域名。', 'hydra-ai' ) );
			}
			$search_tool = array( 'type' => 'web_search' );
			if ( $web_search->getAllowedDomains() ) {
				$search_tool['filters'] = array( 'allowed_domains' => array_values( $web_search->getAllowedDomains() ) );
			}
			$tools[] = $search_tool;
		}

		return $tools;
	}

	/**
	 * @inheritDoc
	 */
	public function parse_stream_response(
		string $body,
		ProviderMetadata $provider_metadata,
		ModelMetadata $model_metadata
	): GenerativeAiResult {
		$final_response = null;

		foreach ( Hydra_Stream::decode( $body ) as $event ) {
			$data = $event['data'];
			$type = isset( $data['type'] ) && is_string( $data['type'] ) ? $data['type'] : $event['event'];
			if ( in_array( $type, array( 'response.completed', 'response.incomplete', 'response.failed' ), true ) && isset( $data['response'] ) && is_array( $data['response'] ) ) {
				$final_response = $data['response'];
			}
		}

		if ( ! is_array( $final_response ) ) {
			throw ResponseException::fromMissingData( $provider_metadata->getName(), 'response.completed.response' );
		}

		return $this->parse_response( $final_response, $provider_metadata, $model_metadata );
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

		if ( empty( $data['output'] ) || ! is_array( $data['output'] ) ) {
			throw ResponseException::fromMissingData( $provider_name, 'output' );
		}

		$parts          = array();
		$file_parts         = array();
		$file_metadata      = array();
		$unmapped           = array();
		$annotations        = array();
		$content_metadata   = array();
		$message_metadata   = array();
		$reasoning_metadata = array();
		$has_tool_call      = false;
		$has_refusal        = false;

		foreach ( $data['output'] as $output_item ) {
			if ( ! is_array( $output_item ) ) {
				continue;
			}

			$item_type = isset( $output_item['type'] ) ? (string) $output_item['type'] : '';

			// message 输出项：解析 content 数组。
			if ( 'message' === $item_type && ! empty( $output_item['content'] ) && is_array( $output_item['content'] ) ) {
				$metadata = $output_item;
				unset( $metadata['content'] );
				if ( $metadata ) {
					$message_metadata[] = $metadata;
				}
				foreach ( $output_item['content'] as $content_item ) {
					if ( ! is_array( $content_item ) ) {
						continue;
					}
					$content_type = isset( $content_item['type'] ) ? (string) $content_item['type'] : '';
					$metadata     = $content_item;
					unset( $metadata['text'], $metadata['refusal'], $metadata['data'], $metadata['b64_json'], $metadata['file_data'] );
					if ( $metadata ) {
						$content_metadata[] = $metadata;
					}

					if ( ( 'output_text' === $content_type || 'text' === $content_type ) && isset( $content_item['text'] ) && is_string( $content_item['text'] ) ) {
						$parts[] = new MessagePart( $content_item['text'] );
						if ( isset( $content_item['annotations'] ) && is_array( $content_item['annotations'] ) ) {
							$annotations = array_merge( $annotations, $content_item['annotations'] );
						}
						continue;
					}
					if ( 'refusal' === $content_type && isset( $content_item['refusal'] ) && is_string( $content_item['refusal'] ) ) {
						$parts[] = new MessagePart( $content_item['refusal'] );
						$has_refusal = true;
						continue;
					}
					$file = $this->parse_output_file( $content_item );
					if ( $file ) {
						$file_parts[] = new MessagePart( $file );
						$metadata = $content_item;
						unset( $metadata['data'], $metadata['b64_json'], $metadata['file_data'] );
						$file_metadata[] = $metadata;
					} else {
						$unmapped[] = $content_item;
					}
				}
				continue;
			}

			if ( 'image_generation_call' === $item_type && isset( $output_item['result'] ) && is_string( $output_item['result'] ) && '' !== $output_item['result'] ) {
				$mime         = isset( $output_item['output_format'] ) ? 'image/' . (string) $output_item['output_format'] : 'image/png';
				$file_parts[] = new MessagePart( new File( $output_item['result'], $mime ) );
				$metadata = $output_item;
				unset( $metadata['result'] );
				$file_metadata[] = $metadata;
				continue;
			}

			if ( 'reasoning' === $item_type && ! empty( $output_item['summary'] ) && is_array( $output_item['summary'] ) ) {
				foreach ( $output_item['summary'] as $summary ) {
					if ( is_array( $summary ) && isset( $summary['text'] ) && is_string( $summary['text'] ) && '' !== $summary['text'] ) {
						$parts[] = new MessagePart( $summary['text'], MessagePartChannelEnum::thought() );
					}
				}
				$metadata = $output_item;
				unset( $metadata['summary'] );
				if ( $metadata ) {
					$reasoning_metadata[] = $metadata;
				}
				continue;
			}

			// function_call 输出项：转换为函数调用部件。
			if ( 'function_call' === $item_type ) {
				$args = null;
				if ( isset( $output_item['arguments'] ) && is_string( $output_item['arguments'] ) ) {
					$decoded = json_decode( $output_item['arguments'], true );
					if ( is_array( $decoded ) && $decoded ) {
						$args = $decoded;
					}
				}

				$parts[] = new MessagePart(
					new FunctionCall(
						isset( $output_item['call_id'] ) && is_string( $output_item['call_id'] ) ? $output_item['call_id'] : '',
						isset( $output_item['name'] ) && is_string( $output_item['name'] ) ? $output_item['name'] : '',
						$args
					)
				);
				$has_tool_call = true;
				continue;
			}

			$unmapped[] = $output_item;
		}

		if ( ! $parts && ! $file_parts ) {
			throw ResponseException::fromMissingData( $provider_name, 'output[].content' );
		}

		$status = isset( $data['status'] ) && is_string( $data['status'] ) ? $data['status'] : 'completed';
		switch ( $status ) {
			case 'incomplete':
				$incomplete_reason = isset( $data['incomplete_details']['reason'] ) ? (string) $data['incomplete_details']['reason'] : '';
				$finish_reason = 'content_filter' === $incomplete_reason ? FinishReasonEnum::contentFilter() : FinishReasonEnum::length();
				break;
			case 'failed':
			case 'cancelled':
				$finish_reason = FinishReasonEnum::error();
				break;
			default:
				$finish_reason = $has_refusal ? FinishReasonEnum::contentFilter() : ( $has_tool_call ? FinishReasonEnum::toolCalls() : FinishReasonEnum::stop() );
		}

		// 用量统计。
		$usage = isset( $data['usage'] ) && is_array( $data['usage'] ) ? $data['usage'] : array();
		$input_tokens  = isset( $usage['input_tokens'] ) && is_numeric( $usage['input_tokens'] ) ? (int) $usage['input_tokens'] : 0;
		$output_tokens = isset( $usage['output_tokens'] ) && is_numeric( $usage['output_tokens'] ) ? (int) $usage['output_tokens'] : 0;
		$total_tokens  = isset( $usage['total_tokens'] ) && is_numeric( $usage['total_tokens'] ) ? (int) $usage['total_tokens'] : $input_tokens + $output_tokens;
		$output_details = isset( $usage['output_tokens_details'] ) && is_array( $usage['output_tokens_details'] ) ? $usage['output_tokens_details'] : array();
		$thought_tokens = isset( $output_details['reasoning_tokens'] ) && is_numeric( $output_details['reasoning_tokens'] ) ? (int) $output_details['reasoning_tokens'] : null;

		$additional = array();
		foreach ( array( 'model', 'status' ) as $key ) {
			if ( isset( $data[ $key ] ) && is_string( $data[ $key ] ) && '' !== $data[ $key ] ) {
				$additional[ $key ] = $data[ $key ];
			}
		}
		foreach ( array( 'incomplete_details', 'error', 'service_tier' ) as $key ) {
			if ( isset( $data[ $key ] ) ) {
				$additional[ $key ] = $data[ $key ];
			}
		}
		if ( $unmapped ) {
			$additional['unmapped_output'] = $unmapped;
		}
		if ( $usage ) {
			$additional['usage_details'] = $usage;
		}
		if ( $file_metadata ) {
			$additional['generated_files'] = $file_metadata;
		}
		if ( $annotations ) {
			$additional['annotations'] = $annotations;
		}
		if ( $content_metadata ) {
			$additional['content_metadata'] = $content_metadata;
		}
		if ( $message_metadata ) {
			$additional['messages'] = $message_metadata;
		}
		if ( $reasoning_metadata ) {
			$additional['reasoning'] = $reasoning_metadata;
		}

		$candidates = array();
		if ( $parts ) {
			$candidates[] = new Candidate( new Message( MessageRoleEnum::model(), $parts ), $finish_reason );
		}
		foreach ( $file_parts as $file_part ) {
			$candidates[] = new Candidate( new Message( MessageRoleEnum::model(), array( $file_part ) ), $finish_reason );
		}

		return new GenerativeAiResult(
			isset( $data['id'] ) && is_string( $data['id'] ) ? $data['id'] : '',
			$candidates,
			new TokenUsage( $input_tokens, $output_tokens, $total_tokens, $thought_tokens ),
			$provider_metadata,
			$model_metadata,
			$additional
		);
	}

	/**
	 * 将 Responses 内容块转换为 WordPress 文件。
	 *
	 * @param array<string,mixed> $item 内容块。
	 * @return File|null
	 */
	private function parse_output_file( array $item ): ?File {
		$type = isset( $item['type'] ) ? (string) $item['type'] : '';
		if ( in_array( $type, array( 'output_image', 'image', 'image_url' ), true ) ) {
			$value = $item['image_url'] ?? $item['url'] ?? $item['b64_json'] ?? $item['data'] ?? null;
			if ( is_array( $value ) ) {
				$value = $value['url'] ?? $value['data'] ?? null;
			}
			if ( is_string( $value ) && '' !== $value ) {
				$mime = isset( $item['mime_type'] ) ? (string) $item['mime_type'] : null;
				if ( null === $mime && false === filter_var( $value, FILTER_VALIDATE_URL ) ) {
					$mime = 'image/png';
				}
				return new File( $value, $mime );
			}
			return null;
		}

		if ( in_array( $type, array( 'output_audio', 'audio' ), true ) ) {
			$value = $item['data'] ?? $item['audio'] ?? null;
			if ( is_array( $value ) ) {
				$value = $value['data'] ?? null;
			}
			return is_string( $value ) && '' !== $value ? new File( $value, isset( $item['mime_type'] ) ? (string) $item['mime_type'] : 'audio/wav' ) : null;
		}

		if ( in_array( $type, array( 'output_file', 'file' ), true ) ) {
			$value = $item['file_url'] ?? $item['url'] ?? $item['file_data'] ?? $item['data'] ?? null;
			return is_string( $value ) && '' !== $value ? new File( $value, isset( $item['mime_type'] ) ? (string) $item['mime_type'] : 'application/octet-stream' ) : null;
		}

		return null;
	}
}
