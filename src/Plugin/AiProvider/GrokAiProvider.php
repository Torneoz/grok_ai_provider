<?php

declare(strict_types=1);

namespace Drupal\grok_ai_provider\Plugin\AiProvider;

use Drupal\Component\Serialization\Json;
use Drupal\Component\Utility\Crypt;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\ai\Attribute\AiProvider;
use Drupal\ai\Base\OpenAiBasedProviderClientBase;
use Drupal\ai\Enum\AiModelCapability;
use Drupal\ai\Exception\AiResponseErrorException;
use Drupal\ai\Exception\AiSetupFailureException;

/**
 * Provides xAI Grok models through the OpenAI-compatible API.
 */
#[AiProvider(
  id: 'grok',
  label: new TranslatableMarkup('Grok (xAI)'),
)]
final class GrokAiProvider extends OpenAiBasedProviderClientBase {

  /**
   * The default xAI API base URL.
   */
  public const DEFAULT_ENDPOINT = 'https://api.x.ai/v1';

  /**
   * An endpoint supplied at runtime, such as during settings validation.
   */
  private string $configuredEndpoint = '';

  /**
   * {@inheritdoc}
   */
  public function getConfiguredModels(?string $operation_type = NULL, array $capabilities = []): array {
    if ($operation_type !== NULL && $operation_type !== 'chat') {
      return [];
    }

    $this->loadClient();
    $cache_context = [$this->getEndpoint(), $this->apiKey, $capabilities];
    $cache_key = 'grok_models_' . Crypt::hashBase64(Json::encode($cache_context));
    if ($cached = $this->cacheBackend->get($cache_key)) {
      return $cached->data;
    }

    try {
      $response = $this->client->models()->list()->toArray();
    }
    catch (\Throwable $exception) {
      throw new AiResponseErrorException('Unable to retrieve models from xAI: ' . $exception->getMessage(), $exception->getCode(), $exception);
    }

    $models = $this->filterModels($response['data'] ?? [], $capabilities);
    if ($models !== []) {
      asort($models);
      $this->cacheBackend->set($cache_key, $models);
    }

    return $models;
  }

  /**
   * {@inheritdoc}
   */
  public function getSupportedOperationTypes(): array {
    return ['chat'];
  }

  /**
   * {@inheritdoc}
   */
  public function getModelSettings(string $model_id, array $generalConfig = []): array {
    if ($this->isReasoningModel($model_id)) {
      // xAI rejects these sampling controls for Grok 4.5 reasoning models.
      unset($generalConfig['frequency_penalty'], $generalConfig['presence_penalty']);
      $generalConfig['reasoning_effort'] = [
        'type' => 'select',
        'label' => 'Reasoning effort',
        'description' => 'Controls how much reasoning effort the model uses.',
        'default' => 'medium',
        'required' => FALSE,
        'constraints' => [
          'options' => ['low', 'medium', 'high'],
        ],
      ];
    }

    return $generalConfig;
  }

  /**
   * {@inheritdoc}
   */
  public function getSetupData(): array {
    return [
      'key_config_name' => 'api_key',
      'default_models' => [
        'chat' => 'grok-4.5-latest',
        'chat_with_image_vision' => 'grok-4.5-latest',
        'chat_with_complex_json' => 'grok-4.5-latest',
        'chat_with_tools' => 'grok-4.5-latest',
        'chat_with_structured_response' => 'grok-4.5-latest',
      ],
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function setConfiguration(array $configuration): void {
    if (isset($configuration['host'])) {
      $this->configuredEndpoint = rtrim(trim((string) $configuration['host']), '/');
      unset($configuration['host']);
      $this->client = NULL;
    }
    parent::setConfiguration($configuration);
  }

  /**
   * Initializes the OpenAI-compatible client with the xAI endpoint.
   */
  protected function loadClient(): void {
    $host = $this->configuredEndpoint ?: trim((string) $this->getConfig()->get('host'));
    $this->setEndpoint(rtrim($host ?: self::DEFAULT_ENDPOINT, '/'));

    try {
      parent::loadClient();
    }
    catch (AiSetupFailureException $exception) {
      throw new AiSetupFailureException('Failed to initialize the xAI client: ' . $exception->getMessage(), $exception->getCode(), $exception);
    }
  }

  /**
   * Filters model discovery results for a requested Drupal AI capability.
   */
  private function filterModels(array $model_data, array $capabilities): array {
    foreach ($capabilities as $capability) {
      if (in_array($capability, [
        AiModelCapability::ChatWithAudio,
        AiModelCapability::ChatWithVideo,
        AiModelCapability::ChatWithPdf,
      ], TRUE)) {
        return [];
      }
    }

    $models = [];
    foreach ($model_data as $model) {
      $model_id = trim((string) ($model['id'] ?? ''));
      if (!preg_match('/^grok(?:-|$)/i', $model_id)) {
        continue;
      }

      if (in_array(AiModelCapability::ChatWithImageVision, $capabilities, TRUE) && !$this->supportsVision($model_id)) {
        continue;
      }
      if (in_array(AiModelCapability::ChatCombinedToolsAndStructuredResponse, $capabilities, TRUE) && !$this->supportsCombinedStructuredTools($model_id)) {
        continue;
      }
      if ((in_array(AiModelCapability::ChatStructuredResponse, $capabilities, TRUE) || in_array(AiModelCapability::ChatJsonOutput, $capabilities, TRUE)) && !$this->supportsStructuredOutput($model_id)) {
        continue;
      }

      $models[$model_id] = $model_id;
    }

    return $models;
  }

  /**
   * Determines whether a Grok model accepts image input.
   */
  private function supportsVision(string $model_id): bool {
    return (bool) preg_match('/^grok-(?:2-vision|4|build)/i', $model_id);
  }

  /**
   * Determines whether a Grok model supports structured output.
   */
  private function supportsStructuredOutput(string $model_id): bool {
    return (bool) preg_match('/^grok-(?:3|4|build)/i', $model_id);
  }

  /**
   * Determines whether tools and structured output can be combined.
   */
  private function supportsCombinedStructuredTools(string $model_id): bool {
    return (bool) preg_match('/^grok-4/i', $model_id);
  }

  /**
   * Determines whether a model supports configurable reasoning effort.
   */
  private function isReasoningModel(string $model_id): bool {
    return (bool) preg_match('/^grok-4\.5(?:-|$)/i', $model_id);
  }

}
