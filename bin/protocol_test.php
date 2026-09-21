<?php
/**
 * 协议转换回归测试，不依赖 WordPress 数据库。
 *
 * 用法：php.exe protocol_test.php
 *
 * @package Hydra_AI
 */

define( 'ABSPATH', dirname( __DIR__, 4 ) . '/' );

require ABSPATH . 'wp-includes/php-ai-client/autoload.php';

if ( ! function_exists( '__' ) ) {
	function __( string $text, string $domain = '' ): string { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
		return $text;
	}
}
if ( ! function_exists( 'wp_json_encode' ) ) {
	function wp_json_encode( $value ): string {
		return (string) json_encode( $value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
	}
}
if ( ! function_exists( 'array_is_list' ) ) {
	function array_is_list( array $array ): bool {
		return array() === $array || array_keys( $array ) === range( 0, count( $array ) - 1 );
	}
}
if ( ! function_exists( 'str_starts_with' ) ) {
	function str_starts_with( string $haystack, string $needle ): bool {
		return '' === $needle || 0 === strpos( $haystack, $needle );
	}
}

class Hydra_Settings {
	/** @var array<int,array<string,mixed>> */
	public static $entries = array();

	/** @return array<int,array<string,mixed>> */
	public static function get_enabled_entries(): array {
		return self::$entries;
	}
}

require dirname( __DIR__ ) . '/includes/class-hydra-files.php';
require dirname( __DIR__ ) . '/includes/class-hydra-stream.php';
require dirname( __DIR__ ) . '/includes/class-hydra-protocol-interface.php';
require dirname( __DIR__ ) . '/includes/class-hydra-protocol-chat.php';
require dirname( __DIR__ ) . '/includes/class-hydra-protocol-responses.php';
require dirname( __DIR__ ) . '/includes/class-hydra-protocol-anthropic.php';
require dirname( __DIR__ ) . '/includes/class-hydra-model-directory.php';
require dirname( __DIR__ ) . '/includes/class-hydra-text-model.php';

use WordPress\AiClient\Files\DTO\File;
use WordPress\AiClient\Messages\DTO\Message;
use WordPress\AiClient\Messages\DTO\MessagePart;
use WordPress\AiClient\Messages\Enums\MessageRoleEnum;
use WordPress\AiClient\Messages\Enums\ModalityEnum;
use WordPress\AiClient\Providers\DTO\ProviderMetadata;
use WordPress\AiClient\Providers\Enums\ProviderTypeEnum;
use WordPress\AiClient\Providers\Http\Enums\RequestAuthenticationMethod;
use WordPress\AiClient\Providers\Models\DTO\ModelConfig;
use WordPress\AiClient\Providers\Models\DTO\ModelMetadata;

$failures = 0;

/**
 * 记录断言结果。
 *
 * @param string $name 断言名称。
 * @param bool   $ok   是否通过。
 * @return void
 */
function protocol_assert( string $name, bool $ok ): void {
	global $failures;
	echo ( $ok ? '[PASS] ' : '[FAIL] ' ) . $name . "\n";
	if ( ! $ok ) {
		++$failures;
	}
}

$provider = new ProviderMetadata(
	'hydra-ai',
	'Hydra AI',
	ProviderTypeEnum::cloud(),
	'',
	RequestAuthenticationMethod::apiKey(),
	'',
	null
);
$model  = new ModelMetadata( 'test-model', 'Test Model', array(), array() );
$prompt = array( new Message( MessageRoleEnum::user(), array( new MessagePart( '测试' ) ) ) );

// Responses 图片生成请求与输出。
$image_config = ModelConfig::fromArray(
	array(
		ModelConfig::KEY_OUTPUT_MODALITIES          => array( 'image' ),
		ModelConfig::KEY_OUTPUT_MIME_TYPE           => 'image/webp',
		ModelConfig::KEY_OUTPUT_MEDIA_ASPECT_RATIO  => '3:2',
	)
);
$responses    = new Hydra_Protocol_Responses();
$params       = $responses->build_params( $prompt, $image_config, 'test-model' );
$image_tool   = $params['tools'][0] ?? array();
protocol_assert( 'Responses 自动启用图片生成工具', 'image_generation' === ( $image_tool['type'] ?? '' ) );
protocol_assert( 'Responses 图片格式映射', 'webp' === ( $image_tool['output_format'] ?? '' ) );
protocol_assert( 'Responses 图片比例映射', '1536x1024' === ( $image_tool['size'] ?? '' ) );

$result = $responses->parse_response(
	array(
		'id'     => 'resp-image',
		'status' => 'completed',
		'output' => array(
			array(
				'type'          => 'image_generation_call',
				'result'        => 'aW1hZ2U=',
				'output_format' => 'webp',
			),
		),
	),
	$provider,
	$model
);
protocol_assert( 'Responses 图片输出转换为 WordPress 文件', 'image/webp' === $result->toImageFile()->getMimeType() );

// Chat 音频输出、多候选及兼容图片内容块。
$audio_config = ModelConfig::fromArray(
	array(
		ModelConfig::KEY_OUTPUT_MODALITIES   => array( 'audio' ),
		ModelConfig::KEY_OUTPUT_MIME_TYPE    => 'audio/mpeg',
		ModelConfig::KEY_OUTPUT_SPEECH_VOICE => 'nova',
		ModelConfig::KEY_CANDIDATE_COUNT     => 2,
	)
);
$chat         = new Hydra_Protocol_Chat();
$params       = $chat->build_params( $prompt, $audio_config, 'test-model' );
protocol_assert( 'Chat 音频输出同时请求文本', array( 'text', 'audio' ) === $params['modalities'] );
protocol_assert( 'Chat 音频参数映射', 'nova' === $params['audio']['voice'] && 'mp3' === $params['audio']['format'] );
protocol_assert( 'Chat 候选数量映射', 2 === $params['n'] );

$result = $chat->parse_response(
	array(
		'id'      => 'chat-multi',
		'choices' => array(
			array(
				'message'       => array( 'audio' => array( 'data' => 'YXVkaW8=', 'format' => 'mp3' ) ),
				'finish_reason' => 'stop',
			),
			array(
				'message'       => array( 'content' => array( array( 'type' => 'image_url', 'image_url' => array( 'url' => 'https://example.com/image.png' ) ) ) ),
				'finish_reason' => 'stop',
			),
		),
	),
	$provider,
	$model
);
protocol_assert( 'Chat 保留全部候选', 2 === $result->getCandidateCount() );
protocol_assert( 'Chat 音频输出转换为 WordPress 文件', 'audio/mpeg' === $result->getCandidates()[0]->getMessage()->getParts()[0]->getFile()->getMimeType() );
protocol_assert( 'Chat 图片输出转换为远程文件', $result->getCandidates()[1]->getMessage()->getParts()[0]->getFile()->isRemote() );

// 无 Schema 的 JSON 模式与纯文本文件输入。
$json_config = ModelConfig::fromArray( array( ModelConfig::KEY_OUTPUT_MIME_TYPE => 'application/json' ) );
$chat_json   = $chat->build_params( $prompt, $json_config, 'test-model' );
$resp_json   = $responses->build_params( $prompt, $json_config, 'test-model' );
protocol_assert( 'Chat 无 Schema 时使用 JSON Object', 'json_object' === ( $chat_json['response_format']['type'] ?? '' ) );
protocol_assert( 'Responses 无 Schema 时使用 JSON Object', 'json_object' === ( $resp_json['text']['format']['type'] ?? '' ) );

$text_prompt = array(
	new Message(
		MessageRoleEnum::user(),
		array( new MessagePart( '读取文件' ), new MessagePart( new File( 'TVQ=', 'text/plain' ) ) )
	)
);
$chat_file = $chat->build_params( $text_prompt, new ModelConfig(), 'test-model' );
$resp_file = $responses->build_params( $text_prompt, new ModelConfig(), 'test-model' );
protocol_assert( 'Chat 支持纯文本文件输入', 'file' === ( $chat_file['messages'][0]['content'][1]['type'] ?? '' ) );
protocol_assert( 'Responses 支持纯文本文件输入', 'input_file' === ( $resp_file['input'][0]['content'][1]['type'] ?? '' ) );

// Responses 拒答与推理 Token。
$result = $responses->parse_response(
	array(
		'id'     => 'resp-refusal',
		'status' => 'completed',
		'output' => array(
			array(
				'type'    => 'message',
				'content' => array( array( 'type' => 'refusal', 'refusal' => '无法处理' ) ),
			),
		),
		'usage'  => array(
			'input_tokens'          => 2,
			'output_tokens'         => 5,
			'total_tokens'          => 7,
			'output_tokens_details' => array( 'reasoning_tokens' => 3 ),
		),
	),
	$provider,
	$model
);
protocol_assert( 'Responses 拒答映射内容过滤停止原因', $result->getCandidates()[0]->getFinishReason()->isContentFilter() );
protocol_assert( 'Responses 保留推理 Token', 3 === $result->getTokenUsage()->getThoughtTokens() );

// Messages 扩展思考签名必须往返保留。
$anthropic = new Hydra_Protocol_Anthropic();
$result    = $anthropic->parse_response(
	array(
		'id'          => 'msg-thinking',
		'content'     => array( array( 'type' => 'thinking', 'thinking' => '分析', 'signature' => 'sig-1' ), array( 'type' => 'text', 'text' => '答案' ) ),
		'stop_reason' => 'end_turn',
	),
	$provider,
	$model
);
$thought = $result->getCandidates()[0]->getMessage()->getParts()[0];
protocol_assert( 'Messages 思考通道转换', $thought->getChannel()->isThought() );
protocol_assert( 'Messages 思考签名保留', 'sig-1' === $thought->getThoughtSignature() );

$search_config = ModelConfig::fromArray(
	array(
		ModelConfig::KEY_WEB_SEARCH => array( 'allowedDomains' => array( 'example.com' ) ),
	)
);
$search_params = $anthropic->build_params( $prompt, $search_config, 'test-model' );
protocol_assert( 'Messages 映射网页搜索允许域名', array( 'example.com' ) === ( $search_params['tools'][0]['allowed_domains'] ?? array() ) );

$search_config = ModelConfig::fromArray(
	array(
		ModelConfig::KEY_WEB_SEARCH => array( 'disallowedDomains' => array( 'blocked.example' ) ),
	)
);
$search_params = $anthropic->build_params( $prompt, $search_config, 'test-model' );
protocol_assert( 'Messages 映射网页搜索排除域名', array( 'blocked.example' ) === ( $search_params['tools'][0]['blocked_domains'] ?? array() ) );

// 三协议流式响应在 WordPress 阻塞传输完成后聚合。
$chat_stream = implode(
	"\n\n",
	array(
		'data: {"id":"chat-stream","choices":[{"index":0,"delta":{"content":"你"}}]}',
		'data: {"choices":[{"index":0,"delta":{"content":"好"},"finish_reason":"stop"}],"usage":{"prompt_tokens":1,"completion_tokens":1,"total_tokens":2}}',
		'data: [DONE]',
	)
);
$result = $chat->parse_stream_response( $chat_stream, $provider, $model );
protocol_assert( 'Chat 流式文本聚合', '你好' === $result->toText() );

$responses_stream = 'event: response.completed' . "\n" . 'data: {"type":"response.completed","response":{"id":"resp-stream","status":"completed","output":[{"type":"message","role":"assistant","content":[{"type":"output_text","text":"完成"}]}]}}';
$result = $responses->parse_stream_response( $responses_stream, $provider, $model );
protocol_assert( 'Responses 最终流事件解析', '完成' === $result->toText() );

$anthropic_stream = implode(
	"\n\n",
	array(
		'event: message_start' . "\n" . 'data: {"type":"message_start","message":{"id":"msg-stream","model":"claude-test","content":[],"usage":{"input_tokens":1}}}',
		'event: content_block_start' . "\n" . 'data: {"type":"content_block_start","index":0,"content_block":{"type":"text","text":""}}',
		'event: content_block_delta' . "\n" . 'data: {"type":"content_block_delta","index":0,"delta":{"type":"text_delta","text":"完成"}}',
		'event: message_delta' . "\n" . 'data: {"type":"message_delta","delta":{"stop_reason":"end_turn"},"usage":{"output_tokens":1}}',
	)
);
$result = $anthropic->parse_stream_response( $anthropic_stream, $provider, $model );
protocol_assert( 'Messages 流式内容聚合', '完成' === $result->toText() );

// 模型目录按协议动态声明 WordPress 能力，模型实现对应同步接口。
Hydra_Settings::$entries = array(
	array( 'model' => 'multi-model', 'name' => 'Responses', 'protocol' => 'responses' ),
	array( 'model' => 'multi-model', 'name' => 'Chat', 'protocol' => 'chat' ),
);
$metadata     = ( new Hydra_Model_Directory() )->getModelMetadata( 'multi-model' );
$capabilities = array_map(
	static function ( $capability ): string {
		return $capability->value;
	},
	$metadata->getSupportedCapabilities()
);
protocol_assert( '模型目录声明图片生成能力', in_array( 'image_generation', $capabilities, true ) );
protocol_assert( '模型目录声明语音生成能力', in_array( 'speech_generation', $capabilities, true ) );
protocol_assert( 'Hydra 模型实现图片生成接口', is_subclass_of( Hydra_Text_Model::class, \WordPress\AiClient\Providers\Models\ImageGeneration\Contracts\ImageGenerationModelInterface::class ) );
protocol_assert( 'Hydra 模型实现语音生成接口', is_subclass_of( Hydra_Text_Model::class, \WordPress\AiClient\Providers\Models\SpeechGeneration\Contracts\SpeechGenerationModelInterface::class ) );
$requirements = \WordPress\AiClient\Providers\Models\DTO\ModelRequirements::fromPromptData(
	\WordPress\AiClient\Providers\Models\Enums\CapabilityEnum::imageGeneration(),
	$prompt,
	$image_config
);
protocol_assert( '图片 MIME 与输出模态通过 WordPress 能力匹配', $requirements->areMetBy( $metadata ) );

echo "\n";
if ( $failures ) {
	echo '测试失败：' . $failures . " 项\n";
	exit( 1 );
}

echo "全部通过\n";
