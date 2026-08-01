<?php

declare(strict_types=1);

namespace Drupal\Tests\grok_ai_provider\Unit;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ExtensionPathResolver;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\grok_ai_provider\Service\ExplorerMediaHelper;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Tests the shared image-source controls used by Grok Explorers.
 */
final class ExplorerMediaHelperTest extends TestCase {

  /**
   * Tests Media Library and autocomplete value normalization.
   */
  #[DataProvider('mediaValues')]
  public function testNormalizesSelectedMediaId(mixed $value, int $expected): void {
    $form_state = $this->createMock(FormStateInterface::class);
    $form_state->method('getValue')->with('image_media')->willReturn($value);

    self::assertSame($expected, $this->newHelper()->getSelectedMediaId($form_state));
  }

  /**
   * Provides supported Media selector values.
   */
  public static function mediaValues(): array {
    return [
      'integer' => [42, 42],
      'numeric string' => ['42', 42],
      'comma string' => ['42,43', 42],
      'media selection array' => [['media_selection_id' => '42'], 42],
      'library selection array' => [['media_library_selection' => '42'], 42],
      'target ID array' => [['target_id' => '42'], 42],
      'list array' => [['42'], 42],
      'empty' => [NULL, 0],
    ];
  }

  /**
   * Tests the interface when Drupal Media is unavailable.
   */
  public function testBuildsUploadAndDefaultSourcesWithoutMedia(): void {
    $module_handler = $this->createMock(ModuleHandlerInterface::class);
    $module_handler->method('moduleExists')->with('media')->willReturn(FALSE);

    $extension_path = $this->createMock(ExtensionPathResolver::class);
    $extension_path->method('getPath')
      ->with('module', 'grok_ai_provider')
      ->willReturn('modules/contrib/grok_ai_provider');

    $form_state = $this->createMock(FormStateInterface::class);
    $form_state->method('getValue')->with('image_source')->willReturn(NULL);

    $helper = $this->newHelper($module_handler, $extension_path);
    $elements = $helper->buildImageSourceElements(
      $form_state,
      new TranslatableMarkup('Choose an image.'),
    );

    self::assertSame(['upload', 'default'], array_keys($elements['image_source']['#options']));
    self::assertSame('default', $elements['image_source']['#default_value']);
    self::assertArrayHasKey('image', $elements);
    self::assertArrayHasKey('default_media_preview', $elements);
    self::assertArrayNotHasKey('image_media', $elements);
  }

  /**
   * Creates the helper with optional module and extension mocks.
   */
  private function newHelper(
    ?ModuleHandlerInterface $module_handler = NULL,
    ?ExtensionPathResolver $extension_path = NULL,
  ): ExplorerMediaHelper {
    return new ExplorerMediaHelper(
      $module_handler ?? $this->createMock(ModuleHandlerInterface::class),
      $this->createMock(EntityTypeManagerInterface::class),
      $extension_path ?? $this->createMock(ExtensionPathResolver::class),
      $this->createMock(FileSystemInterface::class),
      $this->createMock(RequestStack::class),
    );
  }

}
