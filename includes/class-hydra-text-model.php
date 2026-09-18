<?php
/**
 * Hydra AI 文本生成模型：多供应商故障转移核心。
 *
 * @package Hydra_AI
 */

defined( 'ABSPATH' ) || exit;

use WordPress\AiClient\AiClient;
use WordPress\AiClient\Common\Exception\RuntimeException;
use WordPress\AiClient\Messages\DTO\Message;
use WordPress\AiClient\Messages\DTO\MessagePart;
use WordPress\AiClient\Messages\Enums\MessageRoleEnum;
use WordPress\AiClient\Providers\ApiBasedImplementation\AbstractApiBasedModel;
use WordPress\AiClient\Providers\Http\DTO\ApiKeyRequestAuthentication;
use WordPress\AiClient\Providers\Http\DTO\Request;
use WordPress\AiClient\Providers\Http\DTO\RequestOptions;
use WordPress\AiClient\Providers\Http\DTO\Response;
use WordPress\AiClient\Providers\Http\Enums\HttpMethodEnum;
use WordPress\AiClient\Providers\Http\Exception\ResponseException;
use WordPress\AiClient\Providers\Http\Util\ResponseUtil;
use WordPress\AiClient\Providers\Models\DTO\ModelConfig;
use WordPress\AiClient\Providers\Models\DTO\ModelMetadata;
use WordPress\AiClient\Providers\Models\TextGeneration\Contracts\TextGenerationModelInterface;
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
class Hydra_Text_Model extends AbstractApiBasedModel implements TextGenerationModelInterface {

	/**
	 * @inheritDoc
	 */
	public function generateTextResult( array $prompt ): GenerativeAiResult {
		$entries = Hydra_Settings::get_enabled_entries();

		if ( ! $entries ) {
			throw new RuntimeException(
				__( 'Hydra AI 尚未配置任何已启用的供应商，请前往“设置 → Hydra AI”添加。', 'hydra-ai' )
			);
		}

		$ordered = $this->order_entries( $entries, $this->metadata()->getId() );

		$attempts = array();
		$last     = null;

		foreach ( $ordered as $entry ) {
			try {
				$result = $this->generate_for_entry( $entry, $prompt );

				// 记录真实的故障转移事件：曾经失败、最终成功。
				if ( $attempts ) {
					$attempts[] = $this->build_attempt( $entry, true, '' );
					$this->record_summary( $attempts, true );
				}

				return $result;
			} catch ( Throwable $e ) {
				$attempts[] = $this->build_attempt( $entry, false, $e->getMessage() );
				$last       = $e;
			}
		}

		$this->record_summary( $attempts, false );

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

		$api_key = Hydra_Settings::resolve_api_key( $entry );
		if ( '' === $api_key ) {
			// 回退：检查注册表中是否为 Hydra AI 注入过密钥。
			$api_key = $this->resolve_registry_api_key();
		}
		if ( '' === $api_key ) {
			throw new RuntimeException(
				__( '未配置 API Key（条目与连接器中均未提供）。', 'hydra-ai' )
			);
		}

		$endpoint = (string) $entry['endpoint'];
		if ( ! wp_parse_url( $endpoint, PHP_URL_HOST ) ) {
			throw new RuntimeException(
				__( '端点地址无效。', 'hydra-ai' )
			);
		}

		$url = rtrim( $endpoint, '/' ) . '/' . ltrim( $protocol->get_path(), '/' );

		$params  = $protocol->build_params( $prompt, $this->getConfig(), (string) $entry['model'] );
		$headers = $protocol->build_headers( $this->getConfig() );

		$request = new Request(
			HttpMethodEnum::POST(),
			$url,
			$headers,
			$params,
			$this->getRequestOptions()
		);

		$request = $protocol->apply_authentication( $request, $api_key );

		/*
		 * WordPress 的安全请求默认拒绝回环与内网地址，且仅放行 80/443/8080
		 * 端口，而这正是自定义端点的常见场景（如 Ollama:11434、内网中转网关）。
		 * 端点在保存时已做协议与格式校验，这里仅在单次发送期间放开：内网地址
		 * 全部放行，端口仅放行当前条目使用的端口，并使用独立闭包确保卸载的
		 * 是本插件添加的过滤器。
		 */
		$allow_external = static function () {
			return true;
		};
		add_filter( 'http_request_host_is_external', $allow_external );

		$entry_port = (int) wp_parse_url( $url, PHP_URL_PORT );
		$allow_port = null;
		if ( $entry_port > 0 ) {
			$allow_port = static function ( $ports ) use ( $entry_port ) {
				$ports = is_array( $ports ) ? $ports : array();
				$ports[] = $entry_port;

				return $ports;
			};
			add_filter( 'http_allowed_safe_ports', $allow_port );
		}

		try {
			$response = $this->getHttpTransporter()->send( $request );
		} finally {
			remove_filter( 'http_request_host_is_external', $allow_external );
			if ( null !== $allow_port ) {
				remove_filter( 'http_allowed_safe_ports', $allow_port );
			}
		}

		ResponseUtil::throwIfNotSuccessful( $response );

		$data = $response->getData();
		if ( ! is_array( $data ) ) {
			throw ResponseException::fromMissingData(
				$this->providerMetadata()->getName(),
				'body'
			);
		}

		return $protocol->parse_response( $data, $this->providerMetadata(), $this->metadata() );
	}

	/**
	 * 从注册表读取注入给 Hydra AI 的 API Key（如有）。
	 *
	 * @since 1.0.0
	 *
	 * @return string 未提供时返回空字符串。
	 */
	private function resolve_registry_api_key(): string {
		try {
			$auth = $this->getRequestAuthentication();
		} catch ( RuntimeException $e ) {
			return '';
		}

		if ( $auth instanceof ApiKeyRequestAuthentication ) {
			return $auth->getApiKey();
		}

		return '';
	}

	/**
	 * 构建一条故障转移尝试记录。
	 *
	 * @since 1.0.0
	 *
	 * @param array<string,mixed> $entry   供应商条目。
	 * @param bool                $ok      是否成功。
	 * @param string              $message 错误信息。
	 * @return array<string,mixed>
	 */
	private function build_attempt( array $entry, bool $ok, string $message ): array {
		return array(
			'name'     => (string) $entry['name'],
			'protocol' => (string) $entry['protocol'],
			'model'    => (string) $entry['model'],
			'ok'       => $ok,
			'message'  => $message,
		);
	}

	/**
	 * 保存故障转移摘要（供设置页展示）。
	 *
	 * @since 1.0.0
	 *
	 * @param array<int,array<string,mixed>> $attempts 尝试记录。
	 * @param bool                           $success  最终是否成功。
	 * @return void
	 */
	private function record_summary( array $attempts, bool $success ): void {
		Hydra_Settings::record_failover(
			array(
				'time'     => time(),
				'success'  => $success,
				'attempts' => $attempts,
			)
		);
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
	public static function test_entry( array $entry ): array {
		try {
			$model = new self(
				new ModelMetadata(
					(string) $entry['model'],
					(string) $entry['model'],
					array(),
					array()
				),
				Hydra_Provider::metadata()
			);

			// 绑定默认注册表的传输器与鉴权，保证与真实请求路径一致。
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
		} catch ( Throwable $e ) {
			return array(
				'ok'      => false,
				'message' => $e->getMessage(),
			);
		}
	}
}
