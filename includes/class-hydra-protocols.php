<?php
/**
 * 协议注册表与工厂。
 *
 * @package Hydra_AI
 */

defined( 'ABSPATH' ) || exit;

/**
 * 协议注册表。
 *
 * @since 1.0.0
 */
final class Hydra_Protocols {

	/**
	 * 协议实例缓存。
	 *
	 * @var array<string,Hydra_Protocol_Interface>
	 */
	private static $instances = array();

	/**
	 * 获取协议实例。
	 *
	 * @since 1.0.0
	 *
	 * @param string $protocol_id 协议 ID。
	 * @return Hydra_Protocol_Interface
	 * @throws InvalidArgumentException 协议不存在时抛出。
	 */
	public static function get( string $protocol_id ): Hydra_Protocol_Interface {
		if ( isset( self::$instances[ $protocol_id ] ) ) {
			return self::$instances[ $protocol_id ];
		}

		switch ( $protocol_id ) {
			case 'chat':
				self::$instances[ $protocol_id ] = new Hydra_Protocol_Chat();
				break;
			case 'responses':
				self::$instances[ $protocol_id ] = new Hydra_Protocol_Responses();
				break;
			case 'anthropic':
				self::$instances[ $protocol_id ] = new Hydra_Protocol_Anthropic();
				break;
			default:
				throw new InvalidArgumentException( sprintf( '未知协议：%s', $protocol_id ) );
		}

		return self::$instances[ $protocol_id ];
	}

	/**
	 * 判断协议 ID 是否受支持。
	 *
	 * @since 1.0.0
	 *
	 * @param string $protocol_id 协议 ID。
	 * @return bool
	 */
	public static function has( string $protocol_id ): bool {
		return in_array( $protocol_id, Hydra_Settings::PROTOCOLS, true );
	}

	/**
	 * 获取协议的展示名称（可翻译）。
	 *
	 * @since 1.0.0
	 *
	 * @param string $protocol_id 协议 ID。
	 * @return string
	 */
	public static function get_label( string $protocol_id ): string {
		switch ( $protocol_id ) {
			case 'chat':
				return __( 'Chat Completions', 'hydra-ai' );
			case 'responses':
				return __( 'Responses API', 'hydra-ai' );
			case 'anthropic':
				return __( 'Anthropic Messages', 'hydra-ai' );
			default:
				return $protocol_id;
		}
	}

	/**
	 * 获取协议默认端点（基础地址）。
	 *
	 * @since 1.0.0
	 *
	 * @param string $protocol_id 协议 ID。
	 * @return string
	 */
	public static function get_default_endpoint( string $protocol_id ): string {
		switch ( $protocol_id ) {
			case 'chat':
				return 'https://api.openai.com/v1';
			case 'responses':
				return 'https://api.openai.com/v1';
			case 'anthropic':
				return 'https://api.anthropic.com/v1';
			default:
				return '';
		}
	}

	/**
	 * 获取协议默认模型占位符。
	 *
	 * @since 1.0.0
	 *
	 * @param string $protocol_id 协议 ID。
	 * @return string
	 */
	public static function get_default_model( string $protocol_id ): string {
		switch ( $protocol_id ) {
			case 'chat':
				return 'gpt-4o-mini';
			case 'responses':
				return 'gpt-4.1-mini';
			case 'anthropic':
				return 'claude-sonnet-4-5';
			default:
				return '';
		}
	}

	/**
	 * 获取协议的端点说明文案（可翻译）。
	 *
	 * @since 1.0.0
	 *
	 * @param string $protocol_id 协议 ID。
	 * @return string
	 */
	public static function get_endpoint_hint( string $protocol_id ): string {
		switch ( $protocol_id ) {
			case 'chat':
				return __( '填写基础地址，最终请求 {端点}/chat/completions', 'hydra-ai' );
			case 'responses':
				return __( '填写基础地址，最终请求 {端点}/responses', 'hydra-ai' );
			case 'anthropic':
				return __( '填写基础地址，最终请求 {端点}/messages', 'hydra-ai' );
			default:
				return '';
		}
	}
}
