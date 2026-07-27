<?php

declare(strict_types=1);

namespace Drupal\Tests\grok_ai_provider\Unit;

use Drupal\grok_ai_provider\Form\GrokAiProviderConfigForm;
use PHPUnit\Framework\TestCase;

/**
 * Tests pure default-model selection behavior.
 */
final class GrokAiProviderConfigFormTest extends TestCase {

  /**
   * Ensures cached form reconstruction can restore the cost estimator.
   */
  public function testCostEstimatorSupportsLazyRecovery(): void {
    $property = new \ReflectionProperty(GrokAiProviderConfigForm::class, 'costEstimator');
    $fetcher_property = new \ReflectionProperty(GrokAiProviderConfigForm::class, 'pricingScheduleFetcher');

    self::assertFalse($property->isReadOnly());
    self::assertTrue($property->getType()?->allowsNull());
    self::assertFalse($fetcher_property->isReadOnly());
    self::assertTrue($fetcher_property->getType()?->allowsNull());
  }

  /**
   * Tests preferred aliases and the available-model fallback.
   */
  public function testPreferredModelSelection(): void {
    $form = (new \ReflectionClass(GrokAiProviderConfigForm::class))
      ->newInstanceWithoutConstructor();
    $method = new \ReflectionMethod(GrokAiProviderConfigForm::class, 'preferredModel');

    self::assertSame('grok-4.5-latest', $method->invoke($form, [
      'grok-4.3' => 'grok-4.3',
      'grok-4.5-latest' => 'grok-4.5-latest',
    ]));
    self::assertSame('grok-4.5', $method->invoke($form, [
      'grok-4.5' => 'grok-4.5',
      'grok-4.3' => 'grok-4.3',
    ]));
    self::assertSame('grok-4.3', $method->invoke($form, [
      'grok-4.3' => 'grok-4.3',
    ]));
  }

}
