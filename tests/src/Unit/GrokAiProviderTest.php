<?php

declare(strict_types=1);

namespace Drupal\Tests\grok_ai_provider\Unit;

use Drupal\ai\Enum\AiModelCapability;
use Drupal\ai\Exception\AiBadRequestException;
use Drupal\ai\Exception\AiResponseErrorException;
use Drupal\grok_ai_provider\Plugin\AiProvider\GrokAiProvider;
use PHPUnit\Framework\TestCase;

/**
 * Tests Grok-specific model filtering and settings.
 */
final class GrokAiProviderTest extends TestCase {

  /**
   * Tests that model discovery retains only Grok models.
   */
  public function testFiltersNonGrokModels(): void {
    $models = $this->filterModels([
      ['id' => 'grok-4.5-latest'],
      ['id' => 'grok-3-mini'],
      ['id' => 'gpt-5'],
      ['id' => ''],
    ]);

    self::assertSame([
      'grok-4.5-latest' => 'grok-4.5-latest',
      'grok-3-mini' => 'grok-3-mini',
    ], $models);
  }

  /**
   * Tests capability-specific model filtering.
   */
  public function testFiltersCapabilities(): void {
    $models = [
      ['id' => 'grok-2'],
      ['id' => 'grok-2-vision-1212'],
      ['id' => 'grok-3-mini'],
      ['id' => 'grok-4.5-latest'],
    ];

    self::assertSame([
      'grok-2-vision-1212' => 'grok-2-vision-1212',
      'grok-4.5-latest' => 'grok-4.5-latest',
    ], $this->filterModels($models, [AiModelCapability::ChatWithImageVision]));
    self::assertSame([
      'grok-4.5-latest' => 'grok-4.5-latest',
    ], $this->filterModels($models, [AiModelCapability::ChatCombinedToolsAndStructuredResponse]));
    self::assertSame([], $this->filterModels($models, [AiModelCapability::ChatWithAudio]));
  }

  /**
   * Tests image-model discovery filtering.
   */
  public function testFiltersImageModels(): void {
    $method = new \ReflectionMethod(GrokAiProvider::class, 'filterImageModels');
    $models = $method->invoke($this->newProviderWithoutConstructor(), [
      ['id' => 'grok-imagine-image-quality'],
      ['id' => 'grok-imagine-image'],
      ['id' => 'grok-imagine-video'],
      ['id' => 'grok-4.5-latest'],
    ]);

    self::assertSame([
      'grok-imagine-image' => 'grok-imagine-image',
      'grok-imagine-image-quality' => 'grok-imagine-image-quality',
    ], $models);
  }

  /**
   * Tests base64 image normalization and MIME detection.
   */
  public function testNormalizesImageOutput(): void {
    $method = new \ReflectionMethod(GrokAiProvider::class, 'normalizeImageOutput');
    // A valid 1x1 transparent PNG.
    $png = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=';
    $output = $method->invoke($this->newProviderWithoutConstructor(), [
      'data' => [
        [
          'b64_json' => $png,
          'revised_prompt' => 'A transparent pixel.',
        ],
      ],
      'usage' => ['cost_in_usd_ticks' => 200000000],
    ]);

    self::assertCount(1, $output->getNormalized());
    self::assertSame('image/png', $output->getNormalized()[0]->getMimeType());
    self::assertSame('grok-image-1.png', $output->getNormalized()[0]->getFilename());
    self::assertSame('images', $output->getMetadata()['transport']);
    self::assertSame(200000000, $output->getMetadata()['usage']['cost_in_usd_ticks']);
  }

  /**
   * Tests MP4 video normalization and metadata.
   */
  public function testNormalizesVideoOutput(): void {
    $method = new \ReflectionMethod(GrokAiProvider::class, 'normalizeVideoOutput');
    $output = $method->invoke($this->newProviderWithoutConstructor(), [
      'status' => 'done',
      'model' => 'grok-imagine-video',
      'video' => [
        'duration' => 5,
        'respect_moderation' => TRUE,
      ],
      'usage' => ['cost_in_usd_ticks' => 2500000000],
      '_video_binary' => '0000ftypisom-video',
    ]);

    self::assertSame('video/mp4', $output->getNormalized()[0]->getMimeType());
    self::assertSame('grok-video.mp4', $output->getNormalized()[0]->getFilename());
    self::assertSame('videos', $output->getMetadata()['transport']);
    self::assertSame(5, $output->getMetadata()['duration']);
    self::assertArrayNotHasKey('_video_binary', $output->getRawOutput());
  }

  /**
   * Tests that non-MP4 video output is rejected.
   */
  public function testRejectsInvalidVideoOutput(): void {
    $method = new \ReflectionMethod(GrokAiProvider::class, 'normalizeVideoOutput');
    $this->expectException(AiResponseErrorException::class);
    $method->invoke($this->newProviderWithoutConstructor(), [
      '_video_binary' => 'not an mp4 video',
    ]);
  }

  /**
   * Tests that non-image base64 data is rejected.
   */
  public function testRejectsInvalidImageOutput(): void {
    $method = new \ReflectionMethod(GrokAiProvider::class, 'normalizeImageOutput');
    $this->expectException(AiResponseErrorException::class);
    $method->invoke($this->newProviderWithoutConstructor(), [
      'data' => [['b64_json' => base64_encode('not an image')]],
    ]);
  }

  /**
   * Tests best-effort transparent-background prompt guidance.
   */
  public function testBuildsTransparentBackgroundPrompt(): void {
    $provider = $this->newProviderWithoutConstructor();
    $configuration = new \ReflectionProperty(GrokAiProvider::class, 'configuration');
    $configuration->setValue($provider, ['transparent_background' => TRUE]);
    $method = new \ReflectionMethod(GrokAiProvider::class, 'buildImagePrompt');

    $prompt = $method->invoke($provider, 'A crocodile mascot');

    self::assertStringStartsWith('A crocodile mascot', $prompt);
    self::assertStringContainsString('real alpha channel', $prompt);
    self::assertStringContainsString('no checkerboard pattern', $prompt);
  }

  /**
   * Tests that reasoning settings are added only to compatible families.
   */
  public function testReasoningSettings(): void {
    $provider = $this->newProviderWithoutConstructor();

    self::assertArrayHasKey('reasoning_effort', $provider->getModelSettings('grok-4.5-latest'));
    self::assertArrayNotHasKey('reasoning_effort', $provider->getModelSettings('grok-3-mini'));
    self::assertArrayNotHasKey('web_search', $provider->getModelSettings('grok-3-mini', [
      'web_search' => ['type' => 'boolean'],
    ]));
    $video_settings = $provider->getModelSettings('grok-imagine-video', [
      'duration' => ['type' => 'integer'],
      'aspect_ratio' => ['type' => 'string'],
      'resolution' => ['type' => 'string'],
    ]);
    self::assertSame('Video duration', (string) $video_settings['duration']['label']);
    self::assertStringContainsString('generated video', (string) $video_settings['aspect_ratio']['description']);
  }

  /**
   * Tests Responses text, metadata, citations, and usage normalization.
   */
  public function testNormalizesResponsesOutput(): void {
    $method = new \ReflectionMethod(GrokAiProvider::class, 'normalizeResponsesOutput');
    $output = $method->invoke($this->newProviderWithoutConstructor(), [
      'id' => 'resp_123',
      'status' => 'completed',
      'output' => [
        ['type' => 'web_search_call', 'id' => 'search_1'],
        [
          'type' => 'message',
          'content' => [
            [
              'type' => 'output_text',
              'text' => 'A researched answer.',
              'annotations' => [['type' => 'url_citation', 'url' => 'https://example.com']],
            ],
          ],
        ],
      ],
      'usage' => [
        'input_tokens' => 10,
        'output_tokens' => 7,
        'total_tokens' => 17,
        'output_tokens_details' => ['reasoning_tokens' => 3],
        'input_tokens_details' => ['cached_tokens' => 2],
      ],
    ]);

    self::assertSame('A researched answer.', $output->getNormalized()->getText());
    self::assertSame('resp_123', $output->getMetadata()['response_id']);
    self::assertSame('https://example.com', $output->getMetadata()['annotations'][0]['url']);
    self::assertSame(['https://example.com'], $output->getMetadata()['citations']);
    self::assertSame(17, $output->getTokenUsage()->total);
    self::assertSame(3, $output->getTokenUsage()->reasoning);
  }

  /**
   * Tests that incomplete Responses output is never presented as successful.
   */
  public function testRejectsIncompleteResponse(): void {
    $method = new \ReflectionMethod(GrokAiProvider::class, 'normalizeResponsesOutput');
    $this->expectException(AiResponseErrorException::class);
    $this->expectExceptionMessage('incomplete');
    $method->invoke($this->newProviderWithoutConstructor(), [
      'id' => 'resp_incomplete',
      'status' => 'incomplete',
      'incomplete_details' => ['reason' => 'max_output_tokens'],
      'output' => [],
    ]);
  }

  /**
   * Tests strict date, domain, and X handle validation helpers.
   */
  public function testFilterValueValidation(): void {
    $provider = $this->newProviderWithoutConstructor();
    $date_method = new \ReflectionMethod(GrokAiProvider::class, 'isIsoDate');
    $domain_method = new \ReflectionMethod(GrokAiProvider::class, 'isValidDomain');
    $handles_method = new \ReflectionMethod(GrokAiProvider::class, 'normalizeXhandles');

    self::assertTrue($date_method->invoke($provider, '2026-07-24'));
    self::assertFalse($date_method->invoke($provider, '2026-02-30'));
    self::assertTrue($domain_method->invoke($provider, 'example.com'));
    self::assertFalse($domain_method->invoke($provider, 'https://example.com/path'));
    self::assertSame(['xai', 'open_ai'], $handles_method->invoke($provider, '@xai, open_ai'));

    $this->expectException(AiBadRequestException::class);
    $handles_method->invoke($provider, 'invalid-handle');
  }

  /**
   * Calls the private pure filtering method without booting Drupal services.
   */
  private function filterModels(array $models, array $capabilities = []): array {
    $method = new \ReflectionMethod(GrokAiProvider::class, 'filterModels');
    return $method->invoke($this->newProviderWithoutConstructor(), $models, $capabilities);
  }

  /**
   * Creates a provider suitable for testing its pure helper methods.
   */
  private function newProviderWithoutConstructor(): GrokAiProvider {
    return (new \ReflectionClass(GrokAiProvider::class))->newInstanceWithoutConstructor();
  }

}
