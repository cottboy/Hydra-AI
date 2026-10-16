<?php
/** Hydra AI 卸载清理。 */
defined( 'WP_UNINSTALL_PLUGIN' ) || exit;
$profiles = get_option( 'hydra_ai_profiles', array() );
if ( is_array( $profiles ) ) {
	foreach ( $profiles as $profile ) {
		if ( is_array( $profile ) && ! empty( $profile['id'] ) ) {
			delete_option( 'connectors_ai_' . str_replace( '-', '_', sanitize_key( (string) $profile['id'] ) ) . '_api_key' );
		}
	}
}
delete_option( 'hydra_ai_profiles' );
delete_option( 'hydra_ai_active_profile' );
