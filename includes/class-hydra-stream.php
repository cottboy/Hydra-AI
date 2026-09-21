<?php
/**
 * SSE 流式响应解析工具。
 *
 * @package Hydra_AI
 */

defined( 'ABSPATH' ) || exit;

use WordPress\AiClient\Common\Exception\RuntimeException;

/**
 * 将传输层收集到的完整 SSE 响应拆成协议事件。
 *
 * WordPress AI Client 7.0 的 HTTP 适配器采用阻塞请求，不提供逐块回调；
 * 因此这里在请求完成后聚合事件，保证调用方设置 stream=true 时仍可得到
 * 与非流式请求一致的 GenerativeAiResult。
 *
 * @since 1.1.0
 */
final class Hydra_Stream {

	/**
	 * 解码 SSE 文本。
	 *
	 * @param string $body 完整响应正文。
	 * @return array<int,array{event:string,data:array<string,mixed>}>
	 * @throws RuntimeException 响应不包含有效事件时抛出。
	 */
	public static function decode( string $body ): array {
		$events = array();
		$blocks = preg_split( '/\r?\n\r?\n/', trim( $body ) );

		foreach ( is_array( $blocks ) ? $blocks : array() as $block ) {
			$event_name = '';
			$data_lines = array();

			foreach ( preg_split( '/\r?\n/', $block ) ?: array() as $line ) {
				if ( 0 === strpos( $line, 'event:' ) ) {
					$event_name = trim( substr( $line, 6 ) );
				} elseif ( 0 === strpos( $line, 'data:' ) ) {
					$data_lines[] = ltrim( substr( $line, 5 ) );
				}
			}

			$payload = implode( "\n", $data_lines );
			if ( '' === $payload || '[DONE]' === $payload ) {
				continue;
			}

			$data = json_decode( $payload, true );
			if ( is_array( $data ) ) {
				$events[] = array(
					'event' => $event_name,
					'data'  => $data,
				);
			}
		}

		if ( ! $events ) {
			throw new RuntimeException( __( '服务端返回的流式响应无效。', 'hydra-ai' ) );
		}

		return $events;
	}
}
