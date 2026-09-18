<?php
/**
 * 供应商条目的存储、读取与清洗。
 *
 * @package Hydra_AI
 */

defined( 'ABSPATH' ) || exit;

/**
 * 供应商配置存取。
 *
 * 所有条目以有序数组形式存于单个选项中，数组顺序即故障转移优先级。
 *
 * @since 1.0.0
 */
class Hydra_Settings {

	/**
	 * 允许的协议 ID 列表。
	 *
	 * @var array<int,string>
	 */
	const PROTOCOLS = array( 'chat', 'responses', 'anthropic' );

	/**
	 * 获取全部供应商条目（保持用户排序）。
	 *
	 * @since 1.0.0
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public static function get_entries(): array {
		$entries = get_option( HYDRA_AI_OPTION, array() );

		return is_array( $entries ) ? array_values( $entries ) : array();
	}

	/**
	 * 获取全部已启用的供应商条目（保持用户排序）。
	 *
	 * @since 1.0.0
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public static function get_enabled_entries(): array {
		$entries = array();

		foreach ( self::get_entries() as $entry ) {
			if ( ! empty( $entry['enabled'] ) ) {
				$entries[] = $entry;
			}
		}

		return $entries;
	}

	/**
	 * 根据 ID 查找条目。
	 *
	 * @since 1.0.0
	 *
	 * @param string $id 条目 ID。
	 * @return array<string,mixed>|null
	 */
	public static function get_entry( string $id ): ?array {
		foreach ( self::get_entries() as $entry ) {
			if ( isset( $entry['id'] ) && hash_equals( (string) $entry['id'], $id ) ) {
				return $entry;
			}
		}

		return null;
	}

	/**
	 * 保存全部条目。
	 *
	 * @since 1.0.0
	 *
	 * @param array<int,array<string,mixed>> $entries 已清洗的条目列表。
	 * @return void
	 */
	public static function save_entries( array $entries ): void {
		update_option( HYDRA_AI_OPTION, array_values( $entries ), false );
	}

	/**
	 * 生成新的条目 ID。
	 *
	 * @since 1.0.0
	 *
	 * @return string
	 */
	public static function generate_id(): string {
		return substr( md5( uniqid( 'hydra', true ) ), 0, 12 );
	}

	/**
	 * 清洗一组用户提交的条目数据。
	 *
	 * 编辑已有条目时传入 $existing，API Key 留空表示保持原值。
	 *
	 * @since 1.0.0
	 *
	 * @param array<string,mixed>           $raw      原始输入（未信任）。
	 * @param array<string,mixed>|null      $existing 数据库中的原条目。
	 * @return array<string,mixed>|WP_Error 清洗后的条目，或校验错误。
	 */
	public static function sanitize_entry( array $raw, ?array $existing = null ) {
		// 协议必须在白名单内。
		$protocol = isset( $raw['protocol'] ) ? sanitize_key( (string) $raw['protocol'] ) : '';
		if ( ! in_array( $protocol, self::PROTOCOLS, true ) ) {
			return new WP_Error( 'hydra_ai_invalid_protocol', __( '协议类型无效。', 'hydra-ai' ) );
		}

		// 名称：必填，超长截断。
		$name = isset( $raw['name'] ) ? sanitize_text_field( (string) $raw['name'] ) : '';
		if ( '' === $name ) {
			return new WP_Error( 'hydra_ai_empty_name', __( '请填写供应商名称。', 'hydra-ai' ) );
		}
		$name = mb_substr( $name, 0, 100 );

		// 模型：必填，作为请求中的 model 参数。
		$model = isset( $raw['model'] ) ? sanitize_text_field( (string) $raw['model'] ) : '';
		if ( '' === $model ) {
			return new WP_Error( 'hydra_ai_empty_model', __( '请填写模型名称。', 'hydra-ai' ) );
		}
		$model = mb_substr( $model, 0, 200 );

		// API Key：留空时保持数据库中的原值。
		$api_key = isset( $raw['api_key'] ) ? trim( (string) wp_unslash( $raw['api_key'] ) ) : '';
		if ( '' !== $api_key ) {
			$api_key = sanitize_text_field( $api_key );
			$api_key = mb_substr( $api_key, 0, 500 );
		} elseif ( $existing && isset( $existing['api_key'] ) ) {
			$api_key = (string) $existing['api_key'];
		}

		return array(
			'id'       => $existing && isset( $existing['id'] ) ? (string) $existing['id'] : self::generate_id(),
			'name'     => $name,
			'protocol' => $protocol,
			'endpoint' => self::sanitize_endpoint( isset( $raw['endpoint'] ) ? (string) wp_unslash( $raw['endpoint'] ) : '', $protocol ),
			'api_key'  => $api_key,
			'model'    => $model,
			'enabled'  => ! empty( $raw['enabled'] ),
		);
	}

	/**
	 * 清洗端点 URL：仅允许 http/https，必须是合法 URL。
	 *
	 * 端点为“基础地址”，协议路径（如 /chat/completions）由插件自动拼接；
	 * 留空时回退到协议默认端点。
	 *
	 * @since 1.0.0
	 *
	 * @param string $url      用户输入的端点。
	 * @param string $protocol 协议 ID。
	 * @return string
	 */
	public static function sanitize_endpoint( string $url, string $protocol ): string {
		$url = trim( $url );

		if ( '' === $url ) {
			return Hydra_Protocols::get_default_endpoint( $protocol );
		}

		$url = esc_url_raw( $url, array( 'http', 'https' ) );

		// esc_url_raw 对明显非法的输入返回空串，这里再做一次主机名校验。
		$host = (string) wp_parse_url( $url, PHP_URL_HOST );
		if ( '' === $url || '' === $host ) {
			return Hydra_Protocols::get_default_endpoint( $protocol );
		}

		return rtrim( $url, '/' );
	}

	/**
	 * 解析某个条目实际使用的 API Key。
	 *
	 * 优先级：条目自身密钥 > 连接器密钥（数据库 / 常量 HYDRA_AI_API_KEY）。
	 *
	 * @since 1.0.0
	 *
	 * @param array<string,mixed> $entry 供应商条目。
	 * @return string 找不到密钥时返回空字符串。
	 */
	public static function resolve_api_key( array $entry ): string {
		if ( isset( $entry['api_key'] ) && '' !== (string) $entry['api_key'] ) {
			return (string) $entry['api_key'];
		}

		// WordPress 连接器界面保存的密钥。
		$connector_key = (string) get_option( HYDRA_AI_CONNECTOR_KEY_OPTION, '' );
		if ( '' !== $connector_key ) {
			return $connector_key;
		}

		// 支持通过常量或环境变量提供默认密钥。
		if ( defined( 'HYDRA_AI_API_KEY' ) && is_string( HYDRA_AI_API_KEY ) && '' !== HYDRA_AI_API_KEY ) {
			return HYDRA_AI_API_KEY;
		}
		$env_key = getenv( 'HYDRA_AI_API_KEY' );
		if ( is_string( $env_key ) && '' !== $env_key ) {
			return $env_key;
		}

		return '';
	}

	/**
	 * 记录最近一次故障转移摘要，供设置页展示。
	 *
	 * @since 1.0.0
	 *
	 * @param array<string,mixed> $summary 摘要数据。
	 * @return void
	 */
	public static function record_failover( array $summary ): void {
		set_transient( 'hydra_ai_last_failover', $summary, DAY_IN_SECONDS );
	}

	/**
	 * 读取最近一次故障转移摘要。
	 *
	 * @since 1.0.0
	 *
	 * @return array<string,mixed>|null
	 */
	public static function get_last_failover(): ?array {
		$summary = get_transient( 'hydra_ai_last_failover' );

		return is_array( $summary ) ? $summary : null;
	}
}
