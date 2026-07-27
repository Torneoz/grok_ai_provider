<?php

declare(strict_types=1);

namespace Drupal\Tests\grok_ai_provider\Unit;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Extension\ExtensionPathResolver;
use Drupal\grok_ai_provider\Service\GrokCostEstimator;
use PHPUnit\Framework\TestCase;

/**
 * Tests JSON-driven Grok request cost estimates.
 */
final class GrokCostEstimatorTest extends TestCase {

  /**
   * Tests token pricing, including discounted cached input.
   */
  public function testEstimatesTokenCost(): void {
    $estimator = $this->createEstimator([
      [
        'model' => 'grok-test',
        'type' => 'tokens',
        'input_per_million' => 2,
        'cached_input_per_million' => 0.5,
        'output_per_million' => 6,
      ],
    ]);

    self::assertEqualsWithDelta(0.0000145, $estimator->estimate(
      'chat',
      'grok-test',
      [],
      'Prompt',
      [],
      ['input' => 5, 'cached' => 1, 'output' => 1],
    ), 0.00000001);
  }

  /**
   * Tests non-token image, video, and voice estimates.
   *
   * @dataProvider estimateProvider
   */
  public function testEstimatesMediaAndVoice(array $pricing, string $operation, string $model, array $configuration, mixed $input, array $metadata, float $expected): void {
    self::assertEqualsWithDelta(
      $expected,
      $this->createEstimator([$pricing])->estimate($operation, $model, $configuration, $input, $metadata),
      0.0000001,
    );
  }

  /**
   * Provides media and voice estimate cases.
   */
  public static function estimateProvider(): array {
    return [
      'text to speech' => [
        ['model' => '*', 'operation' => 'text_to_speech', 'type' => 'characters', 'per_million_characters' => 15],
        'text_to_speech',
        'eve',
        [],
        str_repeat('a', 100),
        [],
        0.0015,
      ],
      'speech to text' => [
        ['model' => 'xai-stt', 'operation' => 'speech_to_text', 'type' => 'audio_hours', 'per_hour' => 0.1],
        'speech_to_text',
        'xai-stt',
        [],
        NULL,
        ['duration' => 360],
        0.01,
      ],
      '1080p image to video' => [
        [
          'model' => 'grok-imagine-video-1.5',
          'type' => 'video',
          'input_per_image' => 0.01,
          'output_per_second_1080p' => 0.25,
        ],
        'image_to_video',
        'grok-imagine-video-1.5',
        ['duration' => 5, 'resolution' => '1080p'],
        NULL,
        [],
        1.26,
      ],
      'quality image edit' => [
        [
          'model' => 'grok-imagine-image-quality',
          'type' => 'image',
          'input_per_image' => 0.01,
          'output_per_image_2k' => 0.07,
        ],
        'image_to_image',
        'grok-imagine-image-quality',
        ['resolution' => '2k'],
        NULL,
        [],
        0.08,
      ],
    ];
  }

  /**
   * Tests validation rejects negative prices.
   */
  public function testRejectsInvalidPricing(): void {
    $this->expectException(\UnexpectedValueException::class);
    $this->createEstimator([])->normalizePricingJson('[{"model":"grok","type":"tokens","input_per_million":-1}]');
  }

  /**
   * Creates an estimator backed by supplied pricing.
   */
  private function createEstimator(array $pricing): GrokCostEstimator {
    $config = $this->createMock(ImmutableConfig::class);
    $config->method('get')
      ->with('pricing_json')
      ->willReturn(json_encode($pricing, JSON_THROW_ON_ERROR));
    $config_factory = $this->createMock(ConfigFactoryInterface::class);
    $config_factory->method('get')
      ->with('grok_ai_provider.settings')
      ->willReturn($config);

    return new GrokCostEstimator(
      $config_factory,
      $this->createMock(ExtensionPathResolver::class),
    );
  }

}
