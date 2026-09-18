<?php
/**
 * 插件引导类：挂载后台管理相关的钩子。
 *
 * @package Hydra_AI
 */

defined( 'ABSPATH' ) || exit;

/**
 * Hydra AI 插件主类。
 *
 * @since 1.0.0
 */
final class Hydra_AI {

	/**
	 * 唯一实例。
	 *
	 * @var Hydra_AI|null
	 */
	private static $instance = null;

	/**
	 * 后台管理类实例。
	 *
	 * @var Hydra_Admin|null
	 */
	private $admin;

	/**
	 * 获取唯一实例。
	 *
	 * @since 1.0.0
	 *
	 * @return Hydra_AI
	 */
	public static function instance(): Hydra_AI {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * 构造函数，注册钩子。
	 *
	 * @since 1.0.0
	 */
	private function __construct() {
		$this->admin = new Hydra_Admin();

		add_action( 'admin_menu', array( $this->admin, 'register_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this->admin, 'enqueue_assets' ) );

		// 后台 AJAX 接口：增删改、排序与连通性测试。
		add_action( 'wp_ajax_hydra_ai_save_entry', array( $this->admin, 'ajax_save_entry' ) );
		add_action( 'wp_ajax_hydra_ai_delete_entry', array( $this->admin, 'ajax_delete_entry' ) );
		add_action( 'wp_ajax_hydra_ai_reorder', array( $this->admin, 'ajax_reorder' ) );
		add_action( 'wp_ajax_hydra_ai_test_entry', array( $this->admin, 'ajax_test_entry' ) );

		// 插件列表页增加“设置”快捷入口。
		add_filter(
			'plugin_action_links_' . plugin_basename( HYDRA_AI_FILE ),
			array( $this, 'action_links' )
		);
	}

	/**
	 * 在插件列表的操作链接中加入设置页链接。
	 *
	 * @since 1.0.0
	 *
	 * @param array<int,string> $links 原有链接列表。
	 * @return array<int,string>
	 */
	public function action_links( array $links ): array {
		$url = admin_url( 'options-general.php?page=' . HYDRA_AI_SLUG );

		array_unshift(
			$links,
			'<a href="' . esc_url( $url ) . '">' . esc_html__( '设置', 'hydra-ai' ) . '</a>'
		);

		return $links;
	}
}
