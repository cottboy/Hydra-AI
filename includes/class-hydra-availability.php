<?php
/** Hydra AI 供应商可用性检查。 */

defined( 'ABSPATH' ) || exit;

use WordPress\AiClient\Providers\Contracts\ProviderAvailabilityInterface;

class Hydra_Availability implements ProviderAvailabilityInterface {
	private $profile_id;

	public function __construct( string $profile_id ) { $this->profile_id = $profile_id; }

	public function isConfigured(): bool {
		// 连接页的密钥仅用于完成连接；真实接口与密钥由插件管理，不在此检查或发起请求。
		return null !== Hydra_Settings::get_profile( $this->profile_id );
	}
}
