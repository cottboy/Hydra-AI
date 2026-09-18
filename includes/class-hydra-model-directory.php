<?php
/**
 * 基于供应商条目的模型元数据目录。
 *
 * @package Hydra_AI
 */

defined( 'ABSPATH' ) || exit;

use WordPress\AiClient\Common\Exception\InvalidArgumentException;
use WordPress\AiClient\Messages\Enums\ModalityEnum;
use WordPress\AiClient\Providers\Contracts\ModelMetadataDirectoryInterface;
use WordPress\AiClient\Providers\Models\DTO\ModelMetadata;
use WordPress\AiClient\Providers\Models\DTO\SupportedOption;
use WordPress\AiClient\Providers\Models\Enums\CapabilityEnum;
use WordPress\AiClient\Providers\Models\Enums\OptionEnum;

/**
 * 模型元数据目录。
 *
 * Hydra 的“模型”直接来自用户配置的供应商条目：每个条目暴露其模型 ID，
 * 相同模型 ID 的重复条目以优先级最高的为准。
 *
 * @since 1.0.0
 */
class Hydra_Model_Directory implements ModelMetadataDirectoryInterface {

	/**
	 * @inheritDoc
	 */
	public function listModelMetadata(): array {
		$models = array();
		$seen   = array();

		foreach ( Hydra_Settings::get_enabled_entries() as $entry ) {
			$model_id = isset( $entry['model'] ) ? (string) $entry['model'] : '';

			if ( '' === $model_id || isset( $seen[ $model_id ] ) ) {
				continue;
			}
			$seen[ $model_id ] = true;

			$name = $model_id;
			if ( isset( $entry['name'] ) && '' !== (string) $entry['name'] ) {
				$name = sprintf( '%s（%s）', $model_id, (string) $entry['name'] );
			}

			$models[] = new ModelMetadata(
				$model_id,
				$name,
				array(
					CapabilityEnum::textGeneration(),
					CapabilityEnum::chatHistory(),
				),
				$this->supported_options()
			);
		}

		return $models;
	}

	/**
	 * @inheritDoc
	 */
	public function hasModelMetadata( string $model_id ): bool {
		foreach ( Hydra_Settings::get_enabled_entries() as $entry ) {
			if ( isset( $entry['model'] ) && hash_equals( (string) $entry['model'], $model_id ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * @inheritDoc
	 */
	public function getModelMetadata( string $model_id ): ModelMetadata {
		foreach ( $this->listModelMetadata() as $model ) {
			if ( hash_equals( $model->getId(), $model_id ) ) {
				return $model;
			}
		}

		throw new InvalidArgumentException(
			sprintf( '模型 "%s" 未在 Hydra AI 供应商条目中定义。', $model_id )
		);
	}

	/**
	 * 声明全部条目统一支持的配置选项。
	 *
	 * 输入模态覆盖三种协议可传递的全部组合（需求匹配是精确集合比较，
	 * 必须逐一枚举）；具体某个模型是否真支持由故障转移兜底——不支持的
	 * 条目会请求失败并自动切换到下一个。
	 *
	 * @since 1.0.0
	 *
	 * @return array<int,SupportedOption>
	 */
	private function supported_options(): array {
		$modality_combos = array(
			array( ModalityEnum::text() ),
			array( ModalityEnum::text(), ModalityEnum::image() ),
			array( ModalityEnum::text(), ModalityEnum::audio() ),
			array( ModalityEnum::text(), ModalityEnum::document() ),
			array( ModalityEnum::text(), ModalityEnum::image(), ModalityEnum::audio() ),
			array( ModalityEnum::text(), ModalityEnum::image(), ModalityEnum::document() ),
			array( ModalityEnum::text(), ModalityEnum::audio(), ModalityEnum::document() ),
			array( ModalityEnum::text(), ModalityEnum::image(), ModalityEnum::audio(), ModalityEnum::document() ),
		);

		return array(
			new SupportedOption( OptionEnum::systemInstruction() ),
			new SupportedOption( OptionEnum::maxTokens() ),
			new SupportedOption( OptionEnum::temperature() ),
			new SupportedOption( OptionEnum::topP() ),
			new SupportedOption( OptionEnum::topK() ),
			new SupportedOption( OptionEnum::stopSequences() ),
			new SupportedOption( OptionEnum::outputMimeType(), array( 'text/plain', 'application/json' ) ),
			new SupportedOption( OptionEnum::outputSchema() ),
			new SupportedOption( OptionEnum::functionDeclarations() ),
			new SupportedOption( OptionEnum::customOptions() ),
			new SupportedOption( OptionEnum::inputModalities(), $modality_combos ),
			new SupportedOption(
				OptionEnum::outputModalities(),
				array( array( ModalityEnum::text() ) )
			),
		);
	}
}
