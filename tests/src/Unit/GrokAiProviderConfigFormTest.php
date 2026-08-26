<?php

declare(strict_types=1);

namespace Drupal\Tests\grok\Unit;

use Drupal\grok\Form\GrokAiProviderConfigForm;
use Drupal\grok\Form\UpdateModelReferencesConfirmForm;
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

    self::assertSame('grok-4.6', $method->invoke($form, [
      'grok-4.3' => 'grok-4.3',
      'grok-4.5-latest' => 'grok-4.5-latest',
      'grok-4.6' => 'grok-4.6',
    ]));
    self::assertSame('grok-4.5', $method->invoke($form, [
      'grok-4.5' => 'grok-4.5',
      'grok-4.3' => 'grok-4.3',
    ]));
    self::assertSame('grok-4.3', $method->invoke($form, [
      'grok-4.3' => 'grok-4.3',
    ]));
  }

  /**
   * Tests discovery of a newer numbered Grok family.
   */
  public function testModelUpgradeDetection(): void {
    $form = (new \ReflectionClass(GrokAiProviderConfigForm::class))
      ->newInstanceWithoutConstructor();
    $method = new \ReflectionMethod(GrokAiProviderConfigForm::class, 'modelUpgrade');

    self::assertSame([
      'from' => 'grok-4.5-latest',
      'to' => 'grok-4.6-latest',
    ], $method->invoke($form, 'grok-4.5-latest', [
      'grok-4.5-latest' => 'grok-4.5-latest',
      'grok-4.6' => 'grok-4.6',
      'grok-4.6-latest' => 'grok-4.6-latest',
    ]));
    self::assertNull($method->invoke($form, 'grok-4.6', [
      'grok-4.5-latest' => 'grok-4.5-latest',
      'grok-4.6' => 'grok-4.6',
    ]));
    self::assertNull($method->invoke($form, 'grok-beta', [
      'grok-4.6' => 'grok-4.6',
    ]));
  }

  /**
   * Tests model-family extraction used by the confirmed bulk update.
   */
  public function testUpdateModelFamilyMatching(): void {
    $form = (new \ReflectionClass(UpdateModelReferencesConfirmForm::class))
      ->newInstanceWithoutConstructor();
    $method = new \ReflectionMethod(UpdateModelReferencesConfirmForm::class, 'modelVersion');

    self::assertSame('4.5', $method->invoke($form, 'grok-4.5'));
    self::assertSame('4.5', $method->invoke($form, 'grok-4.5-latest'));
    self::assertSame('4.6', $method->invoke($form, 'grok-4.6-fast'));
    self::assertNull($method->invoke($form, 'grok-beta'));
    self::assertNull($method->invoke($form, 'other-4.5'));
  }

  /**
   * Ensures bulk updates retain operation-specific models.
   */
  public function testCapabilityDefaultsCoverEveryAdvertisedOperation(): void {
    $form = (new \ReflectionClass(UpdateModelReferencesConfirmForm::class))
      ->newInstanceWithoutConstructor();
    $method = new \ReflectionMethod(UpdateModelReferencesConfirmForm::class, 'capabilityDefaults');

    self::assertSame([
      'chat' => 'grok-4.6-latest',
      'moderation' => 'grok-4.6-latest',
      'text_to_image' => 'grok-imagine-image-quality',
      'speech_to_text' => 'xai-stt',
    ], $method->invoke($form, [
      'chat' => 'grok-4.5-latest',
      'moderation' => 'grok-4.5-latest',
      'text_to_image' => 'grok-imagine-image-quality',
      'speech_to_text' => 'xai-stt',
    ], 'grok-4.5-latest', 'grok-4.6-latest'));
  }

  /**
   * Tests exact pricing coverage checks for discovered aliases.
   */
  public function testModelsMissingPricing(): void {
    $form = (new \ReflectionClass(GrokAiProviderConfigForm::class))
      ->newInstanceWithoutConstructor();
    $method = new \ReflectionMethod(GrokAiProviderConfigForm::class, 'modelsMissingPricing');

    self::assertSame(['grok-4.6', 'grok-4.6-latest'], $method->invoke($form, [
      'grok-4.5',
      'grok-4.6',
      'grok-4.6-latest',
    ], [
      ['model' => 'grok-4.5', 'type' => 'tokens'],
      ['model' => '*', 'operation' => 'text_to_speech', 'type' => 'characters'],
    ]));
  }

  /**
   * Ensures model discovery refreshes both affected AJAX sections.
   */
  public function testConnectionAjaxRefreshesPricing(): void {
    $source = file_get_contents(
      (new \ReflectionClass(GrokAiProviderConfigForm::class))->getFileName(),
    );

    self::assertIsString($source);
    self::assertStringContainsString(
      "new ReplaceCommand('#grok-connection-wrapper', \$form['connection'])",
      $source,
    );
    self::assertStringContainsString(
      "new ReplaceCommand('#grok-pricing-wrapper', \$form['cost_estimates'])",
      $source,
    );
  }

  /**
   * Ensures capability updates require a successfully loaded model list.
   */
  public function testCapabilityUpdateButtonRequiresLoadedModels(): void {
    $source = file_get_contents(
      (new \ReflectionClass(GrokAiProviderConfigForm::class))->getFileName(),
    );

    self::assertIsString($source);
    self::assertStringContainsString(
      "'#disabled' => \$form_state->get('grok_models') === NULL",
      $source,
    );
  }

  /**
   * Ensures Next Steps handles both AI Image Studio installation states.
   */
  public function testImageStudioNextStepsAreConditional(): void {
    $source = file_get_contents(
      (new \ReflectionClass(GrokAiProviderConfigForm::class))->getFileName(),
    );

    self::assertIsString($source);
    self::assertStringContainsString("moduleExists('ai_image_studio')", $source);
    self::assertStringContainsString("Url::fromRoute('ai_image_studio.new')", $source);
    self::assertStringContainsString(
      'https://www.drupal.org/project/ai_image_studio',
      $source,
    );
  }

  /**
   * Ensures the About section uses the injected extension list service.
   */
  public function testAboutVersionUsesInjectedModuleList(): void {
    $source = file_get_contents(
      (new \ReflectionClass(GrokAiProviderConfigForm::class))->getFileName(),
    );

    self::assertIsString($source);
    self::assertStringContainsString(
      "\$this->moduleList->getExtensionInfo('grok')",
      $source,
    );
    self::assertStringNotContainsString(
      "\$module_list->getExtensionInfo('grok')",
      $source,
    );
  }

}
