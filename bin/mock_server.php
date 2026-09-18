<?php
/**
 * 冒烟测试用 mock AI 服务端：同时模拟三种协议的响应。
 *
 * 启动：php -S 127.0.0.1:8765 mock_server.php
 *
 * @package Hydra_AI
 */

$path = isset( $_SERVER['REQUEST_URI'] ) ? (string) $_SERVER['REQUEST_URI'] : '';
header( 'Content-Type: application/json' );

if ( str_contains( $path, 'chat/completions' ) ) {
	echo json_encode(
		array(
			'id'     => 'chatmock-1',
			'model'  => 'smoke-model',
			'choices' => array(
				array(
					'index'         => 0,
					'message'       => array(
						'role'    => 'assistant',
						'content' => 'pong chat',
					),
					'finish_reason' => 'stop',
				),
			),
			'usage'  => array(
				'prompt_tokens'     => 1,
				'completion_tokens' => 2,
				'total_tokens'      => 3,
			),
		)
	);
	return;
}

if ( str_contains( $path, '/messages' ) ) {
	echo json_encode(
		array(
			'id'          => 'msg_1',
			'role'        => 'assistant',
			'content'     => array(
				array(
					'type' => 'text',
					'text' => 'pong anthropic',
				),
			),
			'stop_reason' => 'end_turn',
			'usage'       => array(
				'input_tokens'  => 1,
				'output_tokens' => 2,
			),
		)
	);
	return;
}

if ( str_contains( $path, '/responses' ) ) {
	echo json_encode(
		array(
			'id'     => 'resp_1',
			'status' => 'completed',
			'output' => array(
				array(
					'type'    => 'message',
					'role'    => 'assistant',
					'content' => array(
						array(
							'type' => 'output_text',
							'text' => 'pong responses',
						),
					),
				),
			),
			'usage'  => array(
				'input_tokens'  => 1,
				'output_tokens' => 2,
				'total_tokens'  => 3,
			),
		)
	);
	return;
}

http_response_code( 404 );
echo json_encode( array( 'error' => array( 'message' => 'not found' ) ) );
