<?php

declare(strict_types=1);

namespace Drupal\Tests\grok\Unit;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 3) . '/grok.module';

/**
 * Tests the packaged Drupal AI Explorer prompt defaults.
 */
final class ExplorerPromptDefaultsTest extends TestCase {

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

}
