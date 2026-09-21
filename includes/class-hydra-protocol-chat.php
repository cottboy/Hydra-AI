<?php
/**
 * OpenAI Chat Completions 协议转换器。
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
		if (
			null !== $config->getTopK()
			|| null !== $config->getWebSearch()
			|| null !== $config->getOutputFileType()
			|| null !== $config->getOutputMediaOrientation()
			|| null !== $config->getOutputMediaAspectRatio()
		) {
			throw new RuntimeException( __( 'Chat Completions 协议不支持本次请求中的配置选项。', 'hydra-ai' ) );
		}

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

		$candidate_count = $config->getCandidateCount();
		if ( null !== $candidate_count ) {
			$params['n'] = $candidate_count;
		}

		$presence_penalty = $config->getPresencePenalty();
		if ( null !== $presence_penalty ) {
			$params['presence_penalty'] = $presence_penalty;
		}

		$frequency_penalty = $config->getFrequencyPenalty();
		if ( null !== $frequency_penalty ) {
			$params['frequency_penalty'] = $frequency_penalty;
		}

		$logprobs = $config->getLogprobs();
		if ( null !== $logprobs ) {
			$params['logprobs'] = $logprobs;
		}

		$top_logprobs = $config->getTopLogprobs();
		if ( null !== $top_logprobs ) {
			$params['top_logprobs'] = $top_logprobs;
		}

		$stop = $config->getStopSequences();
		if ( is_array( $stop ) && $stop ) {
			$params['stop'] = $stop;
		}

		// JSON Schema 结构化输出。
		if ( 'application/json' === $config->getOutputMimeType() ) {
			$params['response_format'] = $config->getOutputSchema()
				? array(
					'type'        => 'json_schema',
					'json_schema' => array(
						'name'   => 'response_schema',
						'schema' => $config->getOutputSchema(),
						'strict' => true,
					),
				)
				: array( 'type' => 'json_object' );
		}

		// 自定义函数工具。
		$declarations = $config->getFunctionDeclarations();
		if ( is_array( $declarations ) && $declarations ) {
			$params['tools'] = $this->build_tools( $declarations );
		}

		$output_modalities = $config->getOutputModalities();
		if ( is_array( $output_modalities ) && $output_modalities ) {
			$modalities = array();
			foreach ( $output_modalities as $modality ) {
				if ( $modality->isText() ) {
					$modalities[] = 'text';
				} elseif ( $modality->isAudio() ) {
					$modalities[] = 'audio';
				} else {
					throw new RuntimeException( __( 'Chat Completions 协议不支持请求该输出模态。', 'hydra-ai' ) );
				}
			}

			if ( in_array( 'audio', $modalities, true ) ) {
				// Chat 音频模型要求同时请求文本，并提供音色与格式。
				if ( ! in_array( 'text', $modalities, true ) ) {
					array_unshift( $modalities, 'text' );
				}
				$params['audio'] = array(
					'voice'  => $config->getOutputSpeechVoice() ?? 'alloy',
					'format' => $this->audio_format_from_mime( $config->getOutputMimeType() ),
				);
			}
			$params['modalities'] = array_values( array_unique( $modalities ) );
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
			foreach ( $this->convert_message( $message ) as $converted ) {
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
	 * @return array<int,array<string,mixed>> 空消息返回空数组。
	 */
	private function convert_message( Message $message ): array {
		$role         = $message->getRole()->isModel() ? 'assistant' : 'user';
		$tool_calls   = array();
		$tool_results = array();
		$content      = array();

		foreach ( $message->getParts() as $part ) {
			$type = $part->getType();

			if ( $type->isText() ) {
				$text = (string) $part->getText();
				if ( '' === $text ) {
					continue;
				}
				if ( $part->getChannel()->isThought() ) {
					// 思考内容不影响正文，仅跳过。
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
					$tool_results[] = array(
						'role'         => 'tool',
						'tool_call_id' => (string) $response->getId(),
						'content'      => (string) wp_json_encode( $response->getResponse() ),
					);
				}
			}
		}

		// 工具响应消息单独成条。
		if ( $tool_results ) {
			return $tool_results;
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
			return array( $entry );
		}

		if ( ! $content ) {
			return array();
		}

		return array(
			array(
				'role'    => $role,
				'content' => 1 === count( $content ) && 'text' === $content[0]['type']
					? (string) $content[0]['text']
					: $content,
			),
		);
	}

	/**
	 * 把文件部件转换为 Chat 协议的内容项。
	 *
	 * 支持图片（远程 URL 或 data URI）、音频（WAV/MP3，远端自动下载内联）
	 * 与文件输入（远端自动下载内联）；其余类型抛出异常，由故障转移
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

		if ( $file->isDocument() || $file->isText() ) {
			// 文件：Chat 协议没有 URL 载体，统一下载或读取为 data URI。
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
	public function parse_stream_response(
		string $body,
		ProviderMetadata $provider_metadata,
		ModelMetadata $model_metadata
	): GenerativeAiResult {
		$complete = array(
			'id'      => '',
			'choices' => array(),
		);

		foreach ( Hydra_Stream::decode( $body ) as $event ) {
			$chunk = $event['data'];
			foreach ( array( 'id', 'model', 'service_tier', 'system_fingerprint' ) as $key ) {
				if ( isset( $chunk[ $key ] ) ) {
					$complete[ $key ] = $chunk[ $key ];
				}
			}
			if ( isset( $chunk['usage'] ) && is_array( $chunk['usage'] ) ) {
				$complete['usage'] = $chunk['usage'];
			}

			foreach ( isset( $chunk['choices'] ) && is_array( $chunk['choices'] ) ? $chunk['choices'] : array() as $choice ) {
				if ( ! is_array( $choice ) ) {
					continue;
				}
				$index = isset( $choice['index'] ) && is_numeric( $choice['index'] ) ? (int) $choice['index'] : 0;
				if ( ! isset( $complete['choices'][ $index ] ) ) {
					$complete['choices'][ $index ] = array(
						'index'         => $index,
						'message'       => array( 'role' => 'assistant', 'content' => '' ),
						'finish_reason' => null,
					);
				}

				if ( isset( $choice['finish_reason'] ) && null !== $choice['finish_reason'] ) {
					$complete['choices'][ $index ]['finish_reason'] = $choice['finish_reason'];
				}
				if ( isset( $choice['logprobs'] ) && is_array( $choice['logprobs'] ) ) {
					foreach ( array( 'content', 'refusal' ) as $field ) {
						if ( isset( $choice['logprobs'][ $field ] ) && is_array( $choice['logprobs'][ $field ] ) ) {
							$complete['choices'][ $index ]['logprobs'][ $field ] = array_merge(
								$complete['choices'][ $index ]['logprobs'][ $field ] ?? array(),
								$choice['logprobs'][ $field ]
							);
						}
					}
				}
				$delta = isset( $choice['delta'] ) && is_array( $choice['delta'] ) ? $choice['delta'] : array();
				foreach ( array( 'content', 'reasoning_content', 'refusal' ) as $field ) {
					if ( isset( $delta[ $field ] ) && is_string( $delta[ $field ] ) ) {
						$complete['choices'][ $index ]['message'][ $field ] = (string) ( $complete['choices'][ $index ]['message'][ $field ] ?? '' ) . $delta[ $field ];
					}
				}

				if ( isset( $delta['audio'] ) && is_array( $delta['audio'] ) ) {
					foreach ( $delta['audio'] as $field => $value ) {
						if ( is_string( $value ) && in_array( $field, array( 'data', 'transcript' ), true ) ) {
							$complete['choices'][ $index ]['message']['audio'][ $field ] = (string) ( $complete['choices'][ $index ]['message']['audio'][ $field ] ?? '' ) . $value;
						} else {
							$complete['choices'][ $index ]['message']['audio'][ $field ] = $value;
						}
					}
				}

				foreach ( isset( $delta['tool_calls'] ) && is_array( $delta['tool_calls'] ) ? $delta['tool_calls'] : array() as $tool_delta ) {
					if ( ! is_array( $tool_delta ) ) {
						continue;
					}
					$tool_index = isset( $tool_delta['index'] ) && is_numeric( $tool_delta['index'] ) ? (int) $tool_delta['index'] : 0;
					if ( ! isset( $complete['choices'][ $index ]['message']['tool_calls'][ $tool_index ] ) ) {
						$complete['choices'][ $index ]['message']['tool_calls'][ $tool_index ] = array(
							'id'       => '',
							'type'     => 'function',
							'function' => array( 'name' => '', 'arguments' => '' ),
						);
					}
					if ( isset( $tool_delta['id'] ) && is_string( $tool_delta['id'] ) ) {
						$complete['choices'][ $index ]['message']['tool_calls'][ $tool_index ]['id'] .= $tool_delta['id'];
					}
					$function = isset( $tool_delta['function'] ) && is_array( $tool_delta['function'] ) ? $tool_delta['function'] : array();
					foreach ( array( 'name', 'arguments' ) as $field ) {
						if ( isset( $function[ $field ] ) && is_string( $function[ $field ] ) ) {
							$complete['choices'][ $index ]['message']['tool_calls'][ $tool_index ]['function'][ $field ] .= $function[ $field ];
						}
					}
				}
			}
		}

		ksort( $complete['choices'] );
		$complete['choices'] = array_values( $complete['choices'] );

		return $this->parse_response( $complete, $provider_metadata, $model_metadata );
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

		$candidates = array();
		foreach ( $data['choices'] as $index => $choice ) {
			if ( ! is_array( $choice ) || empty( $choice['message'] ) || ! is_array( $choice['message'] ) ) {
				throw ResponseException::fromMissingData( $provider_name, 'choices[' . $index . '].message' );
			}

			$parts = array();
			if ( isset( $choice['message']['reasoning_content'] ) && is_string( $choice['message']['reasoning_content'] ) && '' !== $choice['message']['reasoning_content'] ) {
				$parts[] = new MessagePart( $choice['message']['reasoning_content'], MessagePartChannelEnum::thought() );
			}

			foreach ( $this->parse_content( $choice['message'] ) as $part ) {
				$parts[] = $part;
			}

			if ( ! $parts ) {
				throw ResponseException::fromMissingData( $provider_name, 'choices[' . $index . '].message.content' );
			}

			$candidates[] = new Candidate(
				new Message( MessageRoleEnum::model(), $parts ),
				$this->parse_finish_reason( $choice )
			);
		}

		$additional = array();
		if ( isset( $data['model'] ) && is_string( $data['model'] ) && '' !== $data['model'] ) {
			$additional['model'] = $data['model'];
		}
		foreach ( array( 'service_tier', 'system_fingerprint' ) as $key ) {
			if ( isset( $data[ $key ] ) ) {
				$additional[ $key ] = $data[ $key ];
			}
		}
		if ( isset( $data['usage'] ) && is_array( $data['usage'] ) ) {
			$additional['usage_details'] = $data['usage'];
		}
		$annotations      = array();
		$audio_metadata   = array();
		$content_metadata = array();
		$choice_metadata  = array();
		foreach ( $data['choices'] as $index => $choice ) {
			$message = isset( $choice['message'] ) && is_array( $choice['message'] ) ? $choice['message'] : array();
			foreach ( isset( $message['content'] ) && is_array( $message['content'] ) ? $message['content'] : array() as $item ) {
				if ( is_array( $item ) && isset( $item['annotations'] ) && is_array( $item['annotations'] ) ) {
					$annotations = array_merge( $annotations, $item['annotations'] );
				}
				if ( is_array( $item ) ) {
					$metadata = $item;
					unset( $metadata['text'], $metadata['data'], $metadata['b64_json'] );
					if ( $metadata ) {
						$content_metadata[] = $metadata;
					}
				}
			}
			if ( isset( $message['annotations'] ) && is_array( $message['annotations'] ) ) {
				$annotations = array_merge( $annotations, $message['annotations'] );
			}
			if ( isset( $message['audio'] ) && is_array( $message['audio'] ) ) {
				$metadata = $message['audio'];
				unset( $metadata['data'] );
				$audio_metadata[] = $metadata;
			}

			$metadata = $choice;
			unset( $metadata['index'], $metadata['message'], $metadata['finish_reason'] );
			$message_metadata = $message;
			unset( $message_metadata['role'], $message_metadata['content'], $message_metadata['reasoning_content'], $message_metadata['refusal'], $message_metadata['tool_calls'], $message_metadata['annotations'], $message_metadata['audio'] );
			if ( $message_metadata ) {
				$metadata['message'] = $message_metadata;
			}
			if ( $metadata ) {
				$choice_metadata[ $index ] = $metadata;
			}
		}
		if ( $annotations ) {
			$additional['annotations'] = $annotations;
		}
		if ( $audio_metadata ) {
			$additional['audio'] = $audio_metadata;
		}
		if ( $content_metadata ) {
			$additional['content_metadata'] = $content_metadata;
		}
		if ( $choice_metadata ) {
			$additional['choice_metadata'] = $choice_metadata;
		}

		return new GenerativeAiResult(
			isset( $data['id'] ) && is_string( $data['id'] ) ? $data['id'] : '',
			$candidates,
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
				if ( isset( $item['type'], $item['text'] ) && in_array( $item['type'], array( 'text', 'output_text' ), true ) && is_string( $item['text'] ) ) {
					$parts[] = new MessagePart( $item['text'] );
					continue;
				}
				$file = $this->parse_file_content( $item );
				if ( $file ) {
					$parts[] = new MessagePart( $file );
				}
			}
		}

		if ( isset( $message_data['refusal'] ) && is_string( $message_data['refusal'] ) && '' !== $message_data['refusal'] ) {
			$parts[] = new MessagePart( $message_data['refusal'] );
		}

		if ( isset( $message_data['audio'] ) && is_array( $message_data['audio'] ) && isset( $message_data['audio']['data'] ) && is_string( $message_data['audio']['data'] ) ) {
			if ( isset( $message_data['audio']['transcript'] ) && is_string( $message_data['audio']['transcript'] ) && '' !== $message_data['audio']['transcript'] ) {
				$parts[] = new MessagePart( $message_data['audio']['transcript'] );
			}
			$mime    = $this->audio_mime_from_format( isset( $message_data['audio']['format'] ) ? (string) $message_data['audio']['format'] : 'wav' );
			$parts[] = new MessagePart( new File( $message_data['audio']['data'], $mime ) );
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
	 * 解析兼容服务返回的图片或音频内容块。
	 *
	 * @param array<string,mixed> $item 内容块。
	 * @return File|null
	 */
	private function parse_file_content( array $item ): ?File {
		$type = isset( $item['type'] ) ? (string) $item['type'] : '';
		if ( in_array( $type, array( 'image', 'image_url', 'output_image' ), true ) ) {
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
		}

		if ( in_array( $type, array( 'audio', 'output_audio' ), true ) ) {
			$value = $item['data'] ?? null;
			if ( is_array( $value ) ) {
				$value = $value['data'] ?? null;
			}
			if ( is_string( $value ) && '' !== $value ) {
				return new File( $value, isset( $item['mime_type'] ) ? (string) $item['mime_type'] : $this->audio_mime_from_format( isset( $item['format'] ) ? (string) $item['format'] : 'wav' ) );
			}
		}

		return null;
	}

	/**
	 * 将输出 MIME 类型转换为 Chat 音频格式。
	 *
	 * @param string|null $mime MIME 类型。
	 * @return string
	 */
	private function audio_format_from_mime( ?string $mime ): string {
		$map = array(
			'audio/mpeg' => 'mp3',
			'audio/mp3'  => 'mp3',
			'audio/ogg'  => 'opus',
			'audio/opus' => 'opus',
			'audio/flac' => 'flac',
			'audio/pcm'  => 'pcm16',
			'audio/wav'  => 'wav',
		);

		return isset( $map[ (string) $mime ] ) ? $map[ (string) $mime ] : 'wav';
	}

	/**
	 * 将 Chat 音频格式转换为 MIME 类型。
	 *
	 * @param string $format 音频格式。
	 * @return string
	 */
	private function audio_mime_from_format( string $format ): string {
		$map = array(
			'mp3'   => 'audio/mpeg',
			'opus'  => 'audio/ogg',
			'flac'  => 'audio/flac',
			'pcm16' => 'audio/pcm',
			'wav'   => 'audio/wav',
		);

		return isset( $map[ $format ] ) ? $map[ $format ] : 'audio/wav';
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
		$details    = isset( $usage['completion_tokens_details'] ) && is_array( $usage['completion_tokens_details'] ) ? $usage['completion_tokens_details'] : array();
		$thought    = isset( $details['reasoning_tokens'] ) && is_numeric( $details['reasoning_tokens'] ) ? (int) $details['reasoning_tokens'] : null;

		return new TokenUsage( $prompt, $completion, $total, $thought );
	}
}
