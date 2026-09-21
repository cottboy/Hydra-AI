<?php
/**
 * 基于供应商条目的模型元数据目录。
 *
 * @package Hydra_AI
 */

defined( 'ABSPATH' ) || exit;

use WordPress\AiClient\Common\Exception\InvalidArgumentException;
use WordPress\AiClient\Files\Enums\FileTypeEnum;
use WordPress\AiClient\Files\Enums\MediaOrientationEnum;
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
		$grouped = array();

		foreach ( Hydra_Settings::get_enabled_entries() as $entry ) {
			$model_id = isset( $entry['model'] ) ? (string) $entry['model'] : '';

			if ( '' === $model_id ) {
				continue;
			}
			$grouped[ $model_id ][] = $entry;
		}

		foreach ( $grouped as $model_id => $entries ) {
			$entry = $entries[0];

			$name = $model_id;
			if ( isset( $entry['name'] ) && '' !== (string) $entry['name'] ) {
				$name = sprintf( '%s（%s）', $model_id, (string) $entry['name'] );
			}

			$models[] = new ModelMetadata(
				$model_id,
				$name,
				$this->supported_capabilities( $entries ),
				$this->supported_options( $entries )
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
	 * 按同一模型配置的协议合并 WordPress 生成能力。
	 *
	 * @since 1.1.0
	 *
	 * @param array<int,array<string,mixed>> $entries 同一模型的启用条目。
	 * @return array<int,CapabilityEnum>
	 */
	private function supported_capabilities( array $entries ): array {
		$protocols    = array_column( $entries, 'protocol' );
		$capabilities = array(
			CapabilityEnum::textGeneration(),
			CapabilityEnum::chatHistory(),
		);

		if ( in_array( 'responses', $protocols, true ) ) {
			$capabilities[] = CapabilityEnum::imageGeneration();
		}

		if ( in_array( 'chat', $protocols, true ) ) {
			$capabilities[] = CapabilityEnum::speechGeneration();
			$capabilities[] = CapabilityEnum::textToSpeechConversion();
		}

		return $capabilities;
	}

	/**
	 * 按同一模型实际配置的协议合并受支持选项。
	 *
	 * @since 1.1.0
	 *
	 * @param array<int,array<string,mixed>> $entries 同一模型的启用条目。
	 * @return array<int,SupportedOption>
	 */
	private function supported_options( array $entries ): array {
		$protocols       = array_column( $entries, 'protocol' );
		$has_openai_input = in_array( 'chat', $protocols, true ) || in_array( 'responses', $protocols, true );

		$input_modalities = array( ModalityEnum::text(), ModalityEnum::image(), ModalityEnum::document() );
		if ( $has_openai_input ) {
			$input_modalities[] = ModalityEnum::audio();
		}
		$modality_combos = $this->modality_combinations( $input_modalities );

		$output_combos = array( array( ModalityEnum::text() ) );
		$output_mimes  = array( 'text/plain', 'application/json' );
		if ( in_array( 'responses', $protocols, true ) ) {
			$output_combos[] = array( ModalityEnum::image() );
			$output_combos[] = array( ModalityEnum::text(), ModalityEnum::image() );
			$output_mimes[]  = 'image/png';
			$output_mimes[]  = 'image/jpeg';
			$output_mimes[]  = 'image/webp';
		}
		if ( in_array( 'chat', $protocols, true ) ) {
			$output_combos[] = array( ModalityEnum::audio() );
			$output_combos[] = array( ModalityEnum::text(), ModalityEnum::audio() );
			$output_mimes[]  = 'audio/mpeg';
			$output_mimes[]  = 'audio/ogg';
			$output_mimes[]  = 'audio/wav';
			$output_mimes[]  = 'audio/flac';
			$output_mimes[]  = 'audio/pcm';
		}

		$options = array(
			new SupportedOption( OptionEnum::systemInstruction() ),
			new SupportedOption( OptionEnum::maxTokens() ),
			new SupportedOption( OptionEnum::temperature() ),
			new SupportedOption( OptionEnum::topP() ),
			new SupportedOption( OptionEnum::outputMimeType(), array_values( array_unique( $output_mimes ) ) ),
			new SupportedOption( OptionEnum::outputSchema() ),
			new SupportedOption( OptionEnum::functionDeclarations() ),
			new SupportedOption( OptionEnum::customOptions() ),
			new SupportedOption( OptionEnum::inputModalities(), $modality_combos ),
			new SupportedOption( OptionEnum::outputModalities(), $output_combos ),
		);

		if ( in_array( 'chat', $protocols, true ) ) {
			$options[] = new SupportedOption( OptionEnum::candidateCount() );
			$options[] = new SupportedOption( OptionEnum::presencePenalty() );
			$options[] = new SupportedOption( OptionEnum::frequencyPenalty() );
			$options[] = new SupportedOption( OptionEnum::logprobs() );
			$options[] = new SupportedOption( OptionEnum::topLogprobs() );
		}

		if ( in_array( 'chat', $protocols, true ) || in_array( 'anthropic', $protocols, true ) ) {
			$options[] = new SupportedOption( OptionEnum::stopSequences() );
		}

		if ( in_array( 'anthropic', $protocols, true ) ) {
			$options[] = new SupportedOption( OptionEnum::topK() );
		}

		if ( in_array( 'responses', $protocols, true ) || in_array( 'anthropic', $protocols, true ) ) {
			$options[] = new SupportedOption( OptionEnum::webSearch() );
		}

		if ( in_array( 'responses', $protocols, true ) ) {
			$options[] = new SupportedOption( OptionEnum::outputFileType(), array( FileTypeEnum::inline() ) );
			$options[] = new SupportedOption( OptionEnum::outputMediaOrientation(), array( MediaOrientationEnum::square(), MediaOrientationEnum::landscape(), MediaOrientationEnum::portrait() ) );
			$options[] = new SupportedOption( OptionEnum::outputMediaAspectRatio(), array( '1:1', '3:2', '2:3' ) );
		}

		if ( in_array( 'chat', $protocols, true ) ) {
			$options[] = new SupportedOption( OptionEnum::outputSpeechVoice() );
		}

		return $options;
	}

	/**
	 * 生成非空模态组合，供 WordPress 做精确集合匹配。
	 *
	 * @since 1.1.0
	 *
	 * @param array<int,ModalityEnum> $modalities 可用模态。
	 * @return array<int,array<int,ModalityEnum>>
	 */
	private function modality_combinations( array $modalities ): array {
		$combinations = array();
		$limit        = 1 << count( $modalities );

		for ( $mask = 1; $mask < $limit; ++$mask ) {
			$combination = array();
			foreach ( $modalities as $index => $modality ) {
				if ( $mask & ( 1 << $index ) ) {
					$combination[] = $modality;
				}
			}
			$combinations[] = $combination;
		}

		return $combinations;
	}
}
