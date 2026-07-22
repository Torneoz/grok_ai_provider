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
