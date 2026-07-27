<?php

declare(strict_types=1);

namespace Drupal\grok_ai_provider\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Extension\ExtensionPathResolver;

/**
 * Estimates Grok request costs from editable JSON pricing data.
 */
final class GrokCostEstimator {

  /**
   * Pricing fields that must contain non-negative numeric values.
   */
  private const NUMERIC_FIELDS = [
    'cached_input_per_million',
    'input_per_image',
    'input_per_million',
    'long_cached_input_per_million',
    'long_context_threshold',
    'long_input_per_million',
    'long_output_per_million',
    'output_per_image_1k',
    'output_per_image_2k',
    'output_per_million',
    'output_per_second_480p',
    'output_per_second_720p',
    'output_per_second_1080p',
    'per_hour',
    'per_million_characters',
  ];

  /**
   * Constructs the Grok cost estimator.
   */
  public function __construct(
    private readonly ConfigFactoryInterface $configFactory,
    private readonly ExtensionPathResolver $extensionPathResolver,
  ) {}

  /**
   * Returns configured JSON, falling back to the packaged price list.
   */
  public function getPricingJson(): string {
    $configured = trim((string) $this->configFactory->get('grok_ai_provider.settings')->get('pricing_json'));
    if ($configured !== '') {
      return $configured;
    }
    return $this->getPackagedPricingJson();
  }

  /**
   * Returns the pricing schedule shipped with the installed module.
   */
  public function getPackagedPricingJson(): string {
    $module_path = $this->extensionPathResolver->getPath('module', 'grok_ai_provider');
    $json = file_get_contents(DRUPAL_ROOT . '/' . $module_path . '/data/xai_pricing.json');
    if (!is_string($json) || trim($json) === '') {
      throw new \RuntimeException('The packaged xAI pricing data could not be read.');
    }
    return $json;
  }

  /**
   * Validates and consistently formats pricing JSON.
   */
  public function normalizePricingJson(string $json): string {
    $pricing = json_decode($json, TRUE, 512, JSON_THROW_ON_ERROR);
    if (!is_array($pricing) || !array_is_list($pricing)) {
      throw new \UnexpectedValueException('Pricing data must be a JSON array.');
    }
    foreach ($pricing as $index => $row) {
      if (!is_array($row)) {
        throw new \UnexpectedValueException(sprintf('Pricing row %d must be an object.', $index + 1));
      }
      $model = trim((string) ($row['model'] ?? ''));
      $type = trim((string) ($row['type'] ?? ''));
      if ($model === '' || !in_array($type, ['tokens', 'image', 'video', 'characters', 'audio_hours'], TRUE)) {
        throw new \UnexpectedValueException(sprintf('Pricing row %d requires a model and supported type.', $index + 1));
      }
      if (isset($row['operation']) && (!is_string($row['operation']) || trim($row['operation']) === '')) {
        throw new \UnexpectedValueException(sprintf('Pricing row %d has an invalid operation.', $index + 1));
      }
      foreach (self::NUMERIC_FIELDS as $field) {
        if (isset($row[$field]) && (!is_numeric($row[$field]) || (float) $row[$field] < 0)) {
          throw new \UnexpectedValueException(sprintf('Pricing row %d has an invalid %s value.', $index + 1, $field));
        }
      }
      $required = match ($type) {
        'tokens' => isset($row['input_per_million'], $row['output_per_million']),
        'image' => isset($row['output_per_image_1k']) || isset($row['output_per_image_2k']),
        'video' => isset($row['output_per_second_480p']) || isset($row['output_per_second_720p']) || isset($row['output_per_second_1080p']),
        'characters' => isset($row['per_million_characters']),
        'audio_hours' => isset($row['per_hour']),
      };
      if (!$required) {
        throw new \UnexpectedValueException(sprintf('Pricing row %d is missing rates required by its type.', $index + 1));
      }
    }
    return (string) json_encode($pricing, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
  }

  /**
   * Estimates one request from configured pricing.
   */
  public function estimate(string $operation, string $model, array $configuration, mixed $input, array $metadata, array $tokens = []): ?float {
    try {
      $pricing = json_decode($this->getPricingJson(), TRUE, 512, JSON_THROW_ON_ERROR);
    }
    catch (\Throwable) {
      return NULL;
    }
    if (!is_array($pricing)) {
      return NULL;
    }

    $row = $this->findPricing($pricing, $operation, $model);
    if ($row === NULL) {
      return NULL;
    }
    return match ($row['type'] ?? '') {
      'tokens' => $this->estimateTokens($row, $tokens),
      'image' => $this->estimateImage($row, $operation, $configuration),
      'video' => $this->estimateVideo($row, $operation, $configuration, $metadata),
      'characters' => $this->estimateCharacters($row, $input),
      'audio_hours' => $this->estimateAudioHours($row, $metadata),
      default => NULL,
    };
  }

  /**
   * Finds an exact model/operation row, with wildcard model fallback.
   */
  private function findPricing(array $pricing, string $operation, string $model): ?array {
    $wildcard = NULL;
    foreach ($pricing as $row) {
      if (!is_array($row)) {
        continue;
      }
      $row_operation = trim((string) ($row['operation'] ?? ''));
      if ($row_operation !== '' && $row_operation !== $operation) {
        continue;
      }
      if (($row['model'] ?? '') === $model) {
        return $row;
      }
      if (($row['model'] ?? '') === '*') {
        $wildcard = $row;
      }
    }
    return $wildcard;
  }

  /**
   * Estimates token-priced requests.
   */
  private function estimateTokens(array $row, array $tokens): ?float {
    $input = $this->numericValue($tokens['input'] ?? NULL);
    $output = $this->numericValue($tokens['output'] ?? NULL);
    if ($input === NULL && $output === NULL) {
      return NULL;
    }
    $cached = min($input ?? 0, $this->numericValue($tokens['cached'] ?? NULL) ?? 0);
    $uncached = max(0, ($input ?? 0) - $cached);
    $long_context = isset($row['long_context_threshold'])
      && ($input ?? 0) >= (float) $row['long_context_threshold'];
    $input_rate = (float) ($long_context ? ($row['long_input_per_million'] ?? $row['input_per_million'] ?? 0) : ($row['input_per_million'] ?? 0));
    $cached_rate = (float) ($long_context ? ($row['long_cached_input_per_million'] ?? $row['cached_input_per_million'] ?? $input_rate) : ($row['cached_input_per_million'] ?? $input_rate));
    $output_rate = (float) ($long_context ? ($row['long_output_per_million'] ?? $row['output_per_million'] ?? 0) : ($row['output_per_million'] ?? 0));
    return (
      $uncached * $input_rate
      + $cached * $cached_rate
      + ($output ?? 0) * $output_rate
    ) / 1000000;
  }

  /**
   * Estimates image generation and editing.
   */
  private function estimateImage(array $row, string $operation, array $configuration): float {
    $resolution = strtolower((string) ($configuration['resolution'] ?? '1k'));
    $output_rate = (float) ($row['output_per_image_' . $resolution] ?? 0);
    $count = $operation === 'text_to_image'
      ? max(1, min(4, (int) ($configuration['n'] ?? 1)))
      : 1;
    $input_cost = $operation === 'image_to_image' ? (float) ($row['input_per_image'] ?? 0) : 0;
    return $input_cost + $output_rate * $count;
  }

  /**
   * Estimates generated video.
   */
  private function estimateVideo(array $row, string $operation, array $configuration, array $metadata): float {
    $duration = (float) ($metadata['duration'] ?? $configuration['duration'] ?? 5);
    $resolution = strtolower((string) ($configuration['resolution'] ?? '480p'));
    $rate = (float) ($row['output_per_second_' . $resolution] ?? 0);
    $input_cost = $operation === 'image_to_video' ? (float) ($row['input_per_image'] ?? 0) : 0;
    return $input_cost + $duration * $rate;
  }

  /**
   * Estimates text-to-speech by character count.
   */
  private function estimateCharacters(array $row, mixed $input): float {
    $text = is_string($input)
      ? $input
      : (is_object($input) && method_exists($input, 'getText') ? $input->getText() : '');
    return mb_strlen((string) $text) * (float) ($row['per_million_characters'] ?? 0) / 1000000;
  }

  /**
   * Estimates REST transcription by source duration.
   */
  private function estimateAudioHours(array $row, array $metadata): ?float {
    if (!is_numeric($metadata['duration'] ?? NULL)) {
      return NULL;
    }
    return (float) $metadata['duration'] * (float) ($row['per_hour'] ?? 0) / 3600;
  }

  /**
   * Converts numeric input to a float.
   */
  private function numericValue(mixed $value): ?float {
    return is_numeric($value) ? (float) $value : NULL;
  }

}
