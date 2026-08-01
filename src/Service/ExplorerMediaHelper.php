<?php

declare(strict_types=1);

namespace Drupal\grok\Service;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ExtensionPathResolver;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\ai\OperationType\GenericType\FileBaseInterface;
use Drupal\ai\OperationType\GenericType\ImageFile;
use Drupal\file\FileInterface;
use Drupal\media\MediaInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Finds compatible Media types and saves generated Explorer files.
 */
final class ExplorerMediaHelper {

  /**
   * The bundled default image used by image-based Explorers.
   */
  private const DEFAULT_IMAGE = 'assets/examples/indigenous_flag.png';

  /**
   * Constructs the Explorer media helper.
   */
  public function __construct(
    private readonly ModuleHandlerInterface $moduleHandler,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly ExtensionPathResolver $extensionPathResolver,
    private readonly FileSystemInterface $fileSystem,
    private readonly RequestStack $requestStack,
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

  /**
   * Builds the shared image-source controls for image-based Explorers.
   */
  public function buildImageSourceElements(FormStateInterface $form_state, TranslatableMarkup $description): array {
    $media_types = $this->getImageMediaOptions();
    $options = [
      'upload' => new TranslatableMarkup('Upload an image'),
    ];
    if ($media_types !== []) {
      $options['media'] = new TranslatableMarkup('Use Drupal Media');
    }
    $options['default'] = new TranslatableMarkup('Use default media');

    $selected = (string) ($form_state->getValue('image_source') ?: 'default');
    if (!isset($options[$selected])) {
      $selected = 'default';
    }

    $module_path = $this->extensionPathResolver->getPath('module', 'grok');
    $elements = [
      'image_source' => [
        '#type' => 'radios',
        '#title' => new TranslatableMarkup('Image source'),
        '#options' => $options,
        '#default_value' => $selected,
        '#description' => $description,
        '#weight' => -100,
      ],
      'image' => [
        '#type' => 'file',
        '#accept' => '.jpg, .jpeg, .png, .webp',
        '#title' => new TranslatableMarkup('Upload an image'),
        '#description' => new TranslatableMarkup('Upload a JPG, JPEG, PNG, or WebP image.'),
        '#weight' => -90,
        '#states' => [
          'visible' => [
            ':input[name="image_source"]' => ['value' => 'upload'],
          ],
          'required' => [
            ':input[name="image_source"]' => ['value' => 'upload'],
          ],
        ],
      ],
      'default_media_preview' => [
        '#type' => 'container',
        '#weight' => -80,
        '#states' => [
          'visible' => [
            ':input[name="image_source"]' => ['value' => 'default'],
          ],
        ],
        'image' => [
          '#theme' => 'image',
          '#uri' => base_path() . $module_path . '/' . self::DEFAULT_IMAGE,
          '#alt' => new TranslatableMarkup('Default Explorer image'),
          '#attributes' => [
            'style' => 'max-width: 100%; height: auto;',
          ],
        ],
      ],
    ];
    if ($media_types !== []) {
      $use_media_library = $this->moduleHandler->moduleExists('media_library')
        && $this->moduleHandler->moduleExists('media_library_form_element');
      $elements['image_media'] = [
        '#type' => $use_media_library ? 'media_library' : 'entity_autocomplete',
        '#title' => new TranslatableMarkup('Drupal Media image'),
        '#description' => new TranslatableMarkup('Select an existing image from Drupal Media.'),
        '#weight' => -85,
        '#states' => [
          'visible' => [
            ':input[name="image_source"]' => ['value' => 'media'],
          ],
          'required' => [
            ':input[name="image_source"]' => ['value' => 'media'],
          ],
        ],
      ];
      if ($use_media_library) {
        $elements['image_media'] += [
          '#allowed_bundles' => array_keys($media_types),
          '#cardinality' => 1,
        ];
      }
      else {
        $elements['image_media'] += [
          '#target_type' => 'media',
          '#selection_settings' => [
            'target_bundles' => array_keys($media_types),
          ],
        ];
      }
    }
    return $elements;
  }

  /**
   * Loads the selected Explorer image.
   */
  public function loadSelectedImage(FormStateInterface $form_state, callable $upload_loader): ?ImageFile {
    return match ((string) ($form_state->getValue('image_source') ?: 'default')) {
      'upload' => $upload_loader(),
      'media' => $this->loadMediaImage($this->getSelectedMediaId($form_state)),
      'default' => $this->loadDefaultImage(),
      default => NULL,
    };
  }

  /**
   * Normalizes Media Library and entity-autocomplete values to one media ID.
   */
  public function getSelectedMediaId(FormStateInterface $form_state): int {
    $value = $form_state->getValue('image_media');
    if (is_numeric($value)) {
      return (int) $value;
    }
    if (is_string($value) && str_contains($value, ',')) {
      $first = explode(',', $value, 2)[0];
      return is_numeric($first) ? (int) $first : 0;
    }
    if (!is_array($value) || $value === []) {
      return 0;
    }
    foreach (['media_selection_id', 'media_library_selection', 'target_id'] as $key) {
      if (isset($value[$key]) && is_numeric($value[$key])) {
        return (int) $value[$key];
      }
    }
    foreach ($value as $item) {
      if (is_numeric($item)) {
        return (int) $item;
      }
      if (is_object($item) && method_exists($item, 'id')) {
        return (int) $item->id();
      }
    }
    return 0;
  }

  /**
   * Makes a selected Media/default image look like an Explorer upload.
   *
   * @return string|null
   *   The temporary Drupal URI to remove after the Explorer finishes.
   */
  public function injectSelectedImageUpload(FormStateInterface $form_state): ?string {
    $source = (string) ($form_state->getValue('image_source') ?: 'default');
    if ($source === 'upload') {
      return NULL;
    }

    $image = $this->loadSelectedImage($form_state, static fn (): ?ImageFile => NULL);
    if (!$image instanceof ImageFile) {
      throw new \RuntimeException((string) new TranslatableMarkup('A source image is required.'));
    }

    $destination = 'temporary://ai-explorers';
    $this->fileSystem->prepareDirectory($destination, FileSystemInterface::CREATE_DIRECTORY);
    $extension = match ($image->getMimeType()) {
      'image/jpeg' => 'jpg',
      'image/webp' => 'webp',
      default => 'png',
    };
    $uri = $this->fileSystem->saveData(
      $image->getBinary(),
      $destination . '/grok-explorer-source-' . bin2hex(random_bytes(8)) . '.' . $extension,
    );
    $path = $this->fileSystem->realpath($uri);
    if ($path === FALSE) {
      throw new \RuntimeException((string) new TranslatableMarkup('The selected image could not be prepared.'));
    }

    $request = $this->requestStack->getCurrentRequest();
    if ($request === NULL) {
      throw new \RuntimeException((string) new TranslatableMarkup('The current Explorer request is unavailable.'));
    }
    $files = $request->files->all();
    $files['files']['image'] = new UploadedFile(
      $path,
      $image->getFilename(),
      $image->getMimeType(),
      NULL,
      TRUE,
    );
    $request->files->replace($files);
    return $uri;
  }

  /**
   * Removes a temporary image prepared for an upstream Explorer.
   */
  public function removeTemporaryImage(?string $uri): void {
    if (is_string($uri) && str_starts_with($uri, 'temporary://ai-explorers/')) {
      $this->fileSystem->delete($uri);
    }
  }

  /**
   * Returns Media types whose source field accepts common image formats.
   */
  private function getImageMediaOptions(): array {
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
      if (array_intersect(['jpg', 'jpeg', 'png', 'webp'], $extensions ?: [])) {
        $options[$media_type->id()] = $media_type->label();
      }
    }
    asort($options);
    return $options;
  }

  /**
   * Loads an access-checked image from Drupal Media.
   */
  private function loadMediaImage(int $media_id): ImageFile {
    $media = $this->entityTypeManager->getStorage('media')->load($media_id);
    if (!$media instanceof MediaInterface || !$media->access('view')) {
      throw new \RuntimeException((string) new TranslatableMarkup('Select an accessible Drupal Media image.'));
    }
    $source_field = (string) ($media->getSource()->getConfiguration()['source_field'] ?? '');
    $file = $source_field === '' ? NULL : $media->get($source_field)->entity;
    if (!$file instanceof FileInterface) {
      throw new \RuntimeException((string) new TranslatableMarkup('The selected Media item does not contain an image file.'));
    }
    $binary = file_get_contents($file->getFileUri());
    if ($binary === FALSE || $binary === '') {
      throw new \RuntimeException((string) new TranslatableMarkup('The selected Media image could not be read.'));
    }
    return new ImageFile(
      $binary,
      $file->getMimeType() ?: 'image/png',
      $file->getFilename(),
    );
  }

  /**
   * Loads the module's bundled default image.
   */
  private function loadDefaultImage(): ImageFile {
    $module_path = $this->extensionPathResolver->getPath('module', 'grok');
    $path = DRUPAL_ROOT . '/' . $module_path . '/' . self::DEFAULT_IMAGE;
    $binary = file_get_contents($path);
    if ($binary === FALSE || $binary === '') {
      throw new \RuntimeException((string) new TranslatableMarkup('The default Explorer image could not be read.'));
    }
    return new ImageFile($binary, 'image/png', basename(self::DEFAULT_IMAGE));
  }

}
