<?php
/** Hydra AI 卸载清理。 */
defined( 'WP_UNINSTALL_PLUGIN' ) || exit;
$hydra_profiles = get_option( 'hydra_ai_profiles', array() );
if ( is_array( $hydra_profiles ) ) {
	foreach ( $hydra_profiles as $hydra_profile ) {
		if ( is_array( $hydra_profile ) && ! empty( $hydra_profile['id'] ) ) {
			delete_option( 'connectors_ai_' . str_replace( '-', '_', sanitize_key( (string) $hydra_profile['id'] ) ) . '_api_key' );
		}
	}
}
delete_option( 'hydra_ai_profiles' );
delete_option( 'hydra_ai_active_profile' );
