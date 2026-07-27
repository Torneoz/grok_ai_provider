<?php

declare(strict_types=1);

namespace Drupal\Tests\grok_ai_provider\Unit;

use Drupal\grok_ai_provider\EventSubscriber\ExplorerResultSubscriber;
use PHPUnit\Framework\TestCase;

/**
 * Tests Grok Explorer cost fallback calculations.
 */
final class ExplorerResultSubscriberTest extends TestCase {

  /**
   * Tests public xAI media and voice pricing estimates.
   *
   * @dataProvider estimateProvider
   */
  public function testEstimates(string $operation, string $model, array $configuration, mixed $input, array $metadata, float $expected): void {
    $subscriber = new ExplorerResultSubscriber();
    $method = new \ReflectionMethod(ExplorerResultSubscriber::class, 'estimateCost');

    self::assertEqualsWithDelta(
      $expected,
      $method->invoke($subscriber, $operation, $model, $configuration, $input, $metadata),
      0.0000001,
    );
  }

  /**
   * Provides media and voice estimate cases.
   */
  public static function estimateProvider(): array {
    return [
      'text to speech' => [
        'text_to_speech',
        'eve',
        [],
        'One hundred characters..............................................................................',
        [],
        0.0015,
      ],
      'speech to text' => [
        'speech_to_text',
        'xai-stt',
        [],
        NULL,
        ['duration' => 360],
        0.01,
      ],
      '720p text to video' => [
        'text_to_video',
        'grok-imagine-video',
        ['duration' => 5, 'resolution' => '720p'],
        NULL,
        [],
        0.35,
      ],
      '1080p image to video' => [
        'image_to_video',
        'grok-imagine-video-1.5',
        ['duration' => 5, 'resolution' => '1080p'],
        NULL,
        [],
        1.26,
      ],
      'quality image edit' => [
        'image_to_image',
        'grok-imagine-image-quality',
        ['resolution' => '2k'],
        NULL,
        [],
        0.08,
      ],
    ];
  }

}
