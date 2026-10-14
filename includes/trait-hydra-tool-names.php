<?php
/** 将 WordPress 工具名称转换成三种协议允许的名称，并还原调用结果。 */

defined( 'ABSPATH' ) || exit;

trait Hydra_Tool_Names {
	private $original_tool_names = array();

	private function protocol_tool_name( string $name ): string {
		if ( preg_match( '/^[a-zA-Z0-9_-]{1,64}$/', $name ) ) {
			$this->original_tool_names[ $name ] = $name;
			return $name;
		}
		$prefix = trim( (string) preg_replace( '/[^a-zA-Z0-9_-]+/', '_', $name ), '_-' );
		$safe   = substr( '' !== $prefix ? $prefix : 'tool', 0, 47 ) . '_' . substr( hash( 'sha256', $name ), 0, 16 );
		$this->original_tool_names[ $safe ] = $name;
		return $safe;
	}

	private function original_tool_name( string $name ): string {
		return $this->original_tool_names[ $name ] ?? $name;
	}
}
