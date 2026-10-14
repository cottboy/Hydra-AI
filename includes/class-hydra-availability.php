<?php
/** Hydra AI 供应商可用性检查。 */

defined( 'ABSPATH' ) || exit;

use WordPress\AiClient\Providers\Contracts\ProviderAvailabilityInterface;

class Hydra_Availability implements ProviderAvailabilityInterface {
	private $profile_id;

	public function __construct( string $profile_id ) { $this->profile_id = $profile_id; }

	public function isConfigured(): bool {
		foreach ( Hydra_Settings::get_enabled_entries( $this->profile_id ) as $entry ) {
			if ( '' !== Hydra_Settings::resolve_api_key( $entry, $this->profile_id ) ) {
				return true;
			}
		}
		return false;
	}
}
