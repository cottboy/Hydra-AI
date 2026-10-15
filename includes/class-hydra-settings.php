<?php
/**
 * Hydra AI 配置的保存、读取和输入清洗。
 *
 * @package Hydra_AI
 */

defined( 'ABSPATH' ) || exit;

/** 管理命名配置及其接口条目。 */
class Hydra_Settings {
	const PROTOCOLS = array( 'chat', 'responses', 'anthropic' );

	public static function get_profiles(): array {
		$profiles = get_option( HYDRA_AI_OPTION, null );
		if ( null === $profiles ) {
			$profiles = array( self::default_profile() );
			self::save_profiles( $profiles );
		}
		if ( ! is_array( $profiles ) ) {
			return array();
		}
		$clean = array();
		foreach ( $profiles as $profile ) {
			if ( is_array( $profile ) && ! empty( $profile['id'] ) ) {
				$clean[] = self::normalize_profile( $profile );
			}
		}
		return array_values( $clean );
	}

	public static function save_profiles( array $profiles ): void {
		update_option( HYDRA_AI_OPTION, array_values( $profiles ), false );
	}

	public static function default_profile(): array {
		return array(
			'id' => 'hydra-ai',
			'name' => __( 'Hydra AI', 'hydra-ai' ),
			'entries' => array()
		);
	}

	public static function normalize_profile( array $profile ): array {
		$id = sanitize_key( (string) ( $profile['id'] ?? '' ) );
		$name = sanitize_text_field( (string) ( $profile['name'] ?? '' ) );
		$entries = is_array( $profile['entries'] ?? null ) ? $profile['entries'] : array();
		$clean = array();
		foreach ( $entries as $entry ) {
			if ( is_array( $entry ) && ! empty( $entry['id'] ) ) {
				$normalized = self::normalize_entry( $entry );
				if ( $normalized ) {
					$clean[] = $normalized;
				}
			}
		}
		return array(
			'id' => '' !== $id ? $id : self::generate_id( 'hydra-profile' ),
			'name' => '' !== $name ? mb_substr( $name, 0, 100 ) : __( 'Hydra AI', 'hydra-ai' ),
			'entries' => array_values( $clean ),
		);
	}

	public static function get_profile( string $id ): ?array {
		foreach ( self::get_profiles() as $profile ) {
			if ( isset( $profile['id'] ) && hash_equals( (string) $profile['id'], $id ) ) {
				return $profile;
			}
		}
		return null;
	}

	public static function get_active_profile_id(): string {
		$active = sanitize_key( (string) get_option( HYDRA_AI_ACTIVE_PROFILE_OPTION, '' ) );
		$profiles = self::get_profiles();
		if ( $active && self::get_profile( $active ) ) {
			return $active;
		}
		return isset( $profiles[0]['id'] ) ? (string) $profiles[0]['id'] : '';
	}

	public static function set_active_profile( string $id ): bool {
		if ( ! self::get_profile( $id ) ) {
			return false;
		}
		update_option( HYDRA_AI_ACTIVE_PROFILE_OPTION, $id, false );
		return true;
	}

	public static function get_active_profile(): ?array {
		$id = self::get_active_profile_id();
		return '' !== $id ? self::get_profile( $id ) : null;
	}

	public static function get_profile_entries( string $profile_id ): array {
		$profile = self::get_profile( $profile_id );
		return $profile && is_array( $profile['entries'] ?? null ) ? array_values( $profile['entries'] ) : array();
	}

	public static function get_enabled_entries( ?string $profile_id = null ): array {
		$entries = self::get_profile_entries( $profile_id ?: self::get_active_profile_id() );
		return array_values(
            array_filter(
                $entries,
                static function ( $entry ): bool { return is_array( $entry ) && ! empty( $entry['enabled'] );
				}
            )
        );
	}

	public static function get_entry( string $id, ?string $profile_id = null ): ?array {
		foreach ( self::get_profile_entries( $profile_id ?: self::get_active_profile_id() ) as $entry ) {
			if ( isset( $entry['id'] ) && hash_equals( (string) $entry['id'], $id ) ) {
				return $entry;
			}
		}
		return null;
	}

	public static function create_profile( string $name ) {
		$name = self::sanitize_profile_name( $name );
		if ( is_wp_error( $name ) ) { return $name; }
		$profiles = self::get_profiles();
		foreach ( $profiles as $profile ) {
			if ( 0 === strcasecmp( (string) $profile['name'], $name ) ) {
				return new WP_Error( 'hydra_ai_duplicate_profile', __( '这个配置名称已经存在。', 'hydra-ai' ) );
			}
		}
		$profile = array(
			'id' => self::generate_id( 'hydra-profile' ),
			'name' => $name,
			'entries' => array()
		);
		$profiles[] = $profile;
		self::save_profiles( $profiles );
		self::set_active_profile( $profile['id'] );
		return $profile;
	}

	public static function rename_profile( string $id, string $name ) {
		$name = self::sanitize_profile_name( $name );
		if ( is_wp_error( $name ) ) { return $name; }
		$profiles = self::get_profiles();
		$found = false;
		foreach ( $profiles as $index => $profile ) {
			if ( hash_equals( (string) $profile['id'], $id ) ) {
				$found = true;
				$profiles[ $index ]['name'] = $name;
			} elseif ( 0 === strcasecmp( (string) $profile['name'], $name ) ) {
				return new WP_Error( 'hydra_ai_duplicate_profile', __( '这个配置名称已经存在。', 'hydra-ai' ) );
			}
		}
		if ( ! $found ) { return new WP_Error( 'hydra_ai_profile_missing', __( '配置不存在。', 'hydra-ai' ) ); }
		self::save_profiles( $profiles );
		return self::get_profile( $id );
	}

	public static function delete_profile( string $id ): bool {
		$profiles = self::get_profiles();
		$active = self::get_active_profile_id();
		$kept = array_values(
            array_filter(
                $profiles,
                static function ( $profile ) use ( $id ): bool { return ! hash_equals( (string) ( $profile['id'] ?? '' ), $id );
				}
            )
        );
		if ( count( $kept ) === count( $profiles ) ) { return false; }
		self::save_profiles( $kept );
		if ( $active === $id ) { update_option( HYDRA_AI_ACTIVE_PROFILE_OPTION, isset( $kept[0]['id'] ) ? $kept[0]['id'] : '', false ); }
		delete_option( self::connector_key_option( $id ) );
		delete_transient( 'hydra_ai_last_failover_' . $id );
		return true;
	}

	public static function save_profile_entries( string $profile_id, array $entries ): bool {
		$profiles = self::get_profiles();
		foreach ( $profiles as $index => $profile ) {
			if ( hash_equals( (string) $profile['id'], $profile_id ) ) {
				$profiles[ $index ]['entries'] = array_values( $entries );
				self::save_profiles( $profiles );
				return true;
			}
		}
		return false;
	}

	public static function sanitize_entry( array $raw, ?array $existing = null ) {
		$protocol = sanitize_key( (string) ( $raw['protocol'] ?? '' ) );
		if ( ! in_array( $protocol, self::PROTOCOLS, true ) ) { return new WP_Error( 'hydra_ai_invalid_protocol', __( '协议类型无效。', 'hydra-ai' ) ); }
		$name = mb_substr( sanitize_text_field( (string) ( $raw['name'] ?? '' ) ), 0, 100 );
		$model = mb_substr( sanitize_text_field( (string) ( $raw['model'] ?? '' ) ), 0, 200 );
		if ( '' === $name || '' === $model ) { return new WP_Error( 'hydra_ai_missing_entry_field', __( '请填写接口名称和模型名称。', 'hydra-ai' ) ); }
		$api_key = trim( (string) ( $raw['api_key'] ?? '' ) );
		$api_key = '' !== $api_key ? mb_substr( sanitize_text_field( $api_key ), 0, 500 ) : (string) ( $existing['api_key'] ?? '' );
		$endpoint = self::sanitize_endpoint( (string) ( $raw['endpoint'] ?? '' ), $protocol );
		if ( is_wp_error( $endpoint ) ) { return $endpoint; }
		return array(
			'id' => (string) ( $existing['id'] ?? self::generate_id( 'entry' ) ),
			'name' => $name,
			'protocol' => $protocol,
			'endpoint' => $endpoint,
			'api_key' => $api_key,
			'model' => $model,
			'enabled' => ! empty( $raw['enabled'] )
		);
	}

	public static function normalize_entry( array $entry ): array {
		$clean = self::sanitize_entry( $entry, $entry );
		return is_wp_error( $clean ) ? array() : $clean;
	}

	/** 校验接口地址。错误地址必须报错，避免把密钥发往其他默认地址。 */
	public static function sanitize_endpoint( string $url, string $protocol ) {
		$url = trim( $url );
		if ( '' === $url ) {
			return Hydra_Protocols::get_default_endpoint( $protocol );
		}
		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) || ! in_array( strtolower( $parts['scheme'] ?? '' ), array( 'http', 'https' ), true ) || empty( $parts['host'] ) || isset( $parts['user'] ) || isset( $parts['query'] ) || isset( $parts['fragment'] ) || preg_match( '/[\x00-\x20\x7f]/', $url ) ) {
			return new WP_Error( 'hydra_ai_invalid_endpoint', __( '请填写有效的 HTTP 或 HTTPS 基础地址，不要包含账号、查询参数或片段。', 'hydra-ai' ) );
		}
		return rtrim( esc_url_raw( $url, array( 'http', 'https' ) ), '/' );
	}

	public static function connector_key_option( string $profile_id ): string {
		return 'connectors_ai_' . str_replace( '-', '_', sanitize_key( $profile_id ) ) . '_api_key';
	}

	public static function generate_id( string $prefix ): string {
		return $prefix . '-' . wp_generate_uuid4();
	}

	private static function sanitize_profile_name( string $name ) {
		$name = mb_substr( sanitize_text_field( trim( $name ) ), 0, 100 );
		return '' !== $name ? $name : new WP_Error( 'hydra_ai_empty_profile_name', __( '请填写配置名称。', 'hydra-ai' ) );
	}

	public static function record_failover( array $summary, string $profile_id ): void { set_transient( 'hydra_ai_last_failover_' . $profile_id, $summary, DAY_IN_SECONDS ); }
	public static function get_last_failover( string $profile_id ): ?array { $summary = get_transient( 'hydra_ai_last_failover_' . $profile_id );
		return is_array( $summary ) ? $summary : null; }
}
