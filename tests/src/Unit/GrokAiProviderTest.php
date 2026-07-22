<?php

declare(strict_types=1);

namespace Drupal\Tests\grok_ai_provider\Unit;

use Drupal\ai\Enum\AiModelCapability;
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
   * Tests that reasoning settings are added only to compatible families.
   */
  public function testReasoningSettings(): void {
    $provider = $this->newProviderWithoutConstructor();

    self::assertArrayHasKey('reasoning_effort', $provider->getModelSettings('grok-4.5-latest'));
    self::assertArrayNotHasKey('reasoning_effort', $provider->getModelSettings('grok-3-mini'));
    self::assertArrayNotHasKey('web_search', $provider->getModelSettings('grok-3-mini', [
      'web_search' => ['type' => 'boolean'],
    ]));
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
          'content' => [[
            'type' => 'output_text',
            'text' => 'A researched answer.',
            'annotations' => [['type' => 'url_citation', 'url' => 'https://example.com']],
          ]],
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
    self::assertSame(17, $output->getTokenUsage()->total);
    self::assertSame(3, $output->getTokenUsage()->reasoning);
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
