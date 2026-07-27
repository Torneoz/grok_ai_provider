<?php

declare(strict_types=1);

namespace Drupal\grok_ai_provider\Service;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\ai\OperationType\GenericType\FileBaseInterface;
use Drupal\media\MediaInterface;

/**
 * Finds compatible Media types and saves generated Explorer files.
 */
final class ExplorerMediaHelper {

  /**
   * Constructs the Explorer media helper.
   */
  public function __construct(
    private readonly ModuleHandlerInterface $moduleHandler,
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * Returns Media type options whose source field accepts an extension.
   */
  public function getOptions(string $extension): array {
    if (!$this->moduleHandler->moduleExists('media')) {
      return [];
    }

    $options = [];
    $media_types = $this->entityTypeManager->getStorage('media_type')->loadMultiple();
    $field_storage = $this->entityTypeManager->getStorage('field_config');
    foreach ($media_types as $media_type) {
      $source = $media_type->getSource();
      $source_field = (string) ($source->getConfiguration()['source_field'] ?? '');
      if ($source_field === '') {
        continue;
      }
      $field = $field_storage->load('media.' . $media_type->id() . '.' . $source_field);
      if ($field === NULL) {
        continue;
      }
      $extensions = preg_split('/[,\s]+/', strtolower((string) ($field->getSetting('file_extensions') ?? '')), -1, PREG_SPLIT_NO_EMPTY);
      if (in_array(strtolower($extension), $extensions ?: [], TRUE)) {
        $options[$media_type->id()] = $media_type->label();
      }
    }
    asort($options);
    return $options;
  }

  /**
   * Saves a generated file to a compatible Media type.
   */
  public function save(FileBaseInterface $file, string $media_type, string $filename): MediaInterface {
    return $file->getAsMediaEntity($media_type, '', $filename);
  }

}
