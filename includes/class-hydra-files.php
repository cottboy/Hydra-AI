<?php
/**
 * 文件输入工具：远端抓取、data URI 转换与格式判定。
 *
 * @package Hydra_AI
 */

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- 异常消息只经 JSON 接口返回并在页面以纯文本显示，不拼进网页内容。

use WordPress\AiClient\Common\Exception\RuntimeException;
use WordPress\AiClient\Files\DTO\File;

/**
 * 文件输入工具。
 *
 * 三种协议对“远端 URL 文件”的支持程度不同：图片各协议都可直接传 URL，
 * 而音频、PDF 等只接受内联 base64。对不支持的远端文件，这里负责下载后
 * 转为内联数据，让所有条目都能处理远端输入；抓取失败时抛出异常，
 * 由故障转移机制切换到下一个供应商条目。
 *
 * @since 1.0.1
 */
class Hydra_Files {

	/**
	 * 远端文件大小上限：25MB。
	 *
	 * @var int
	 */
	const MAX_REMOTE_BYTES = 26214400;

	/**
	 * 远端文件下载超时（秒）。
	 *
	 * @var float
	 */
	const REMOTE_TIMEOUT = 20.0;

	/**
	 * 获取内联 base64 数据；远端文件自动下载后编码。
	 *
	 * @since 1.0.1
	 *
	 * @param File $file 文件对象。
	 * @return string base64 数据。
	 * @throws RuntimeException 下载失败或超出限制时抛出。
	 */
	public static function to_base64( File $file ): string {
		if ( $file->isInline() ) {
			return (string) $file->getBase64Data();
		}

		return self::fetch_base64( $file );
	}

	/**
	 * 获取 data URI；远端文件自动下载后构造。
	 *
	 * @since 1.0.1
	 *
	 * @param File $file 文件对象。
	 * @return string data URI。
	 * @throws RuntimeException 下载失败或超出限制时抛出。
	 */
	public static function to_data_uri( File $file ): string {
		if ( $file->isInline() ) {
			return (string) $file->getDataUri();
		}

		return 'data:' . $file->getMimeType() . ';base64,' . self::fetch_base64( $file );
	}

	/**
	 * 下载远端文件并返回 base64 数据。
	 *
	 * 文件地址可能来自普通用户输入，始终使用 WordPress 的安全下载限制。
	 *
	 * @since 1.0.1
	 *
	 * @param File $file 文件对象。
	 * @return string base64 数据。
	 * @throws RuntimeException URL 无效、下载失败或超出限制时抛出。
	 */
	private static function fetch_base64( File $file ): string {
		$url = (string) $file->getUrl();

		$scheme = (string) wp_parse_url( $url, PHP_URL_SCHEME );
		if ( ! in_array( $scheme, array( 'http', 'https' ), true ) || ! wp_parse_url( $url, PHP_URL_HOST ) ) {
			throw new RuntimeException( __( '文件 URL 无效。', 'hydra-ai' ) );
		}

		$response = wp_safe_remote_get(
			$url,
			array(
				'timeout'             => self::REMOTE_TIMEOUT,
				'limit_response_size' => self::MAX_REMOTE_BYTES + 1,
			)
		);

		if ( is_wp_error( $response ) ) {
			throw new RuntimeException(
				sprintf(
					/* translators: %s: 错误信息。 */
					__( '下载远端文件失败：%s', 'hydra-ai' ),
					$response->get_error_message()
				)
			);
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( $code < 200 || $code >= 300 ) {
			throw new RuntimeException(
				sprintf(
					/* translators: %d: HTTP 状态码。 */
					__( '下载远端文件失败：HTTP %d。', 'hydra-ai' ),
					$code
				)
			);
		}

		$body = (string) wp_remote_retrieve_body( $response );
		if ( strlen( $body ) > self::MAX_REMOTE_BYTES ) {
			throw new RuntimeException( __( '远端文件超过 25MB 大小限制。', 'hydra-ai' ) );
		}
		if ( '' === $body ) {
			throw new RuntimeException( __( '远端文件内容为空。', 'hydra-ai' ) );
		}

		return base64_encode( $body );
	}

	/**
	 * 把 MIME 类型映射为 OpenAI 音频格式标识。
	 *
	 * OpenAI 的 input_audio 仅接受 WAV 与 MP3。
	 *
	 * @since 1.0.1
	 *
	 * @param File $file 文件对象。
	 * @return string 'wav' 或 'mp3'。
	 * @throws RuntimeException 其他音频格式时抛出。
	 */
	public static function openai_audio_format( File $file ): string {
		$mime = strtolower( $file->getMimeType() );

		if ( in_array( $mime, array( 'audio/wav', 'audio/x-wav', 'audio/wave', 'audio/vnd.wave' ), true ) ) {
			return 'wav';
		}
		if ( in_array( $mime, array( 'audio/mpeg', 'audio/mp3' ), true ) ) {
			return 'mp3';
		}

		throw new RuntimeException( __( '该协议的音频输入仅支持 WAV 或 MP3 格式。', 'hydra-ai' ) );
	}

	/**
	 * 推导文件名：远端取 URL 基名，内联按 MIME 推测扩展名。
	 *
	 * @since 1.0.1
	 *
	 * @param File $file 文件对象。
	 * @return string
	 */
	public static function file_name( File $file ): string {
		if ( $file->isRemote() ) {
			$path = (string) wp_parse_url( (string) $file->getUrl(), PHP_URL_PATH );
			$name = '' !== $path ? basename( $path ) : '';
			if ( '' !== $name ) {
				return $name;
			}
		}

		return 'file.' . self::extension_from_mime( $file->getMimeType() );
	}

	/**
	 * 按常用 MIME 类型推测文件扩展名。
	 *
	 * @since 1.0.1
	 *
	 * @param string $mime MIME 类型。
	 * @return string
	 */
	private static function extension_from_mime( string $mime ): string {
		$map = array(
			'application/pdf' => 'pdf',
			'text/plain'      => 'txt',
			'image/jpeg'      => 'jpg',
			'image/png'       => 'png',
			'image/gif'       => 'gif',
			'image/webp'      => 'webp',
			'audio/wav'       => 'wav',
			'audio/x-wav'     => 'wav',
			'audio/mpeg'      => 'mp3',
			'audio/mp3'       => 'mp3',
		);

		return isset( $map[ strtolower( $mime ) ] ) ? $map[ strtolower( $mime ) ] : 'bin';
	}
}
