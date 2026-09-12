<?php

declare(strict_types=1);

namespace Drupal\Tests\grok\Unit;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Form\FormState;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 3) . '/grok.module';

/**
 * Tests the packaged Drupal AI Explorer prompt defaults.
 */
final class ExplorerPromptDefaultsTest extends TestCase {

  /**
   * Tests that only the Grok video Explorer receives the animation example.
   */
  public function testAnimationPromptIsScopedToExplorer(): void {
    $input = 'image_video_generator_ajax_prefix_configuration_prompt';
    foreach (['grok', 'other_provider'] as $provider) {
      foreach (['grok_image_to_video_generator', 'image_studio'] as $plugin) {
        $state = (new FormState())->setBuildInfo(['args' => [$plugin]]);
        $form = [
          'left' => [
            'image_video_generator_ai_provider' => ['#default_value' => $provider],
            'image_video_generator_ajax_prefix' => [
              $input => ['#default_value' => ''],
            ],
          ],
        ];
        grok_form_ai_api_explorer_form_alter($form, $state);
        $default = $form['left']['image_video_generator_ajax_prefix'][$input]['#default_value'];
        if ($provider === 'grok' && $plugin === 'grok_image_to_video_generator') {
          self::assertInstanceOf(TranslatableMarkup::class, $default);
          self::assertStringContainsString('Make the flag wave.', $default->getUntranslatedString());
        }
        else {
          self::assertSame('', $default);
        }
      }
    }
  }

  /**
   * Tests that defaults preserve submitted prompts, including cleared input.
   */
  public function testPreservesExplorerInput(): void {
    foreach (['', 'Animate my own image.'] as $prompt) {
      foreach (['raw', 'processed', 'existing'] as $source) {
        $state = new FormState();
        $element = ['#default_value' => ''];
        if ($source === 'raw') {
          $state->setUserInput(['prompt' => $prompt]);
        }
        elseif ($source === 'processed') {
          $state->setValue('prompt', $prompt);
        }
        else {
          if ($prompt === '') {
            continue;
          }
          $element['#default_value'] = $prompt;
        }
        $original = $element;
        grok_set_explorer_default($element, $state, 'prompt', 'Example prompt');
        self::assertSame($original, $element);
      }
    }
  }

  /**
   * Ensures every configurable prompt has a non-empty packaged fallback.
   */
  public function testPackagedPromptDefaults(): void {
    $defaults = grok_explorer_prompt_defaults();

    self::assertSame([
      'chat',
      'image_to_image',
      'text_to_video',
      'moderation',
      'text_to_image',
      'text_to_speech',
    ], array_keys($defaults));

    foreach ($defaults as $default) {
      self::assertInstanceOf(TranslatableMarkup::class, $default);
      self::assertNotSame('', trim($default->getUntranslatedString()));
    }
  }

  /**
   * Tests numbered model families used by capability upgrade notices.
   */
  public function testNumberedModelVersion(): void {
    self::assertSame('4.5', grok_numbered_model_version('grok-4.5-latest'));
    self::assertSame('4.6', grok_numbered_model_version('grok-4.6'));
    self::assertNull(grok_numbered_model_version('grok-build-latest'));
    self::assertNull(grok_numbered_model_version('other-4.5'));
  }

}
