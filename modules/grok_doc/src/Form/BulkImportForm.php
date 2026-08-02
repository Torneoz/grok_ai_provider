<?php

declare(strict_types=1);

namespace Drupal\grok_doc\Form;

use Drupal\Component\Serialization\Json;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\grok_doc\Service\CollectionDocumentManager;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Queues multiple Drupal-managed files for Collection ingestion.
 */
final class BulkImportForm extends FormBase {

  /**
   * Constructs the bulk import form. */
  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly CollectionDocumentManager $manager,
    private readonly AccountProxyInterface $currentUser,
  ) {}

  /**
   * {@inheritdoc} */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('entity_type.manager'),
      $container->get('grok_doc.manager'),
      $container->get('current_user'),
    );
  }

  /**
   * {@inheritdoc} */
  public function getFormId(): string {
    return 'grok_doc_bulk_import';
  }

  /**
   * {@inheritdoc} */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $options = [];
    foreach ($this->entityTypeManager->getStorage('grok_doc_collection')->loadMultiple() as $collection) {
      if ($collection->isEnabled()) {
        $options[$collection->id()] = $collection->label() . ' — ' . $collection->getRemoteId();
      }
    }
    if (!$options) {
      $form['empty'] = [
        '#markup' => $this->t('Register and enable a Grok collection before importing documents.'),
      ];
      return $form;
    }
    $form['collection'] = [
      '#type' => 'select',
      '#title' => $this->t('Collection'),
      '#options' => $options,
      '#required' => TRUE,
    ];
    $form['files'] = [
      '#type' => 'managed_file',
      '#title' => $this->t('Documents'),
      '#multiple' => TRUE,
      '#upload_location' => 'temporary://grok_doc',
      '#upload_validators' => [
        'FileExtension' => ['extensions' => 'txt md csv json html htm pdf docx pptx xlsx xml yaml yml'],
      ],
      '#description' => $this->t('Select multiple documents. Identical files already registered in this collection are skipped.'),
      '#required' => TRUE,
    ];
    $form['metadata'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Batch metadata (JSON object)'),
      '#default_value' => '{}',
      '#description' => $this->t('These values override collection defaults for every document in this batch.'),
    ];
    $form['notice'] = [
      '#type' => 'item',
      '#title' => $this->t('Cost notice'),
      '#markup' => $this->t('Uploaded content incurs xAI file and Collection storage charges until it is removed. Processing occurs through Drupal queues.'),
    ];
    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Queue import'),
      '#button_type' => 'primary',
    ];
    return $form;
  }

  /**
   * {@inheritdoc} */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    try {
      $metadata = Json::decode((string) $form_state->getValue('metadata'));
      if (!is_array($metadata) || array_is_list($metadata)) {
        throw new \InvalidArgumentException();
      }
    }
    catch (\Throwable) {
      $form_state->setErrorByName('metadata', $this->t('Metadata must be a valid JSON object.'));
    }
    $collection = $this->entityTypeManager->getStorage('grok_doc_collection')->load($form_state->getValue('collection'));
    $total = 0;
    $max_file = (int) $this->config('grok_doc.settings')->get('max_file_bytes');
    foreach ($this->loadFiles($form_state) as $file) {
      $size = (int) $file->getSize();
      $total += $size;
      if ($size <= 0 || $size > $max_file) {
        $form_state->setErrorByName('files', $this->t('%file exceeds the permitted file size.', ['%file' => $file->getFilename()]));
      }
    }
    if ($collection && $total > $collection->getMaxBatchBytes()) {
      $form_state->setErrorByName('files', $this->t('The selected files exceed this collection’s maximum batch size.'));
    }
  }

  /**
   * {@inheritdoc} */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $collection = $this->entityTypeManager->getStorage('grok_doc_collection')->load($form_state->getValue('collection'));
    $metadata = Json::decode((string) $form_state->getValue('metadata'));
    $queued = 0;
    $duplicates = 0;
    foreach ($this->loadFiles($form_state) as $file) {
      try {
        $before = $this->entityTypeManager->getStorage('grok_doc_document')->getQuery()
          ->accessCheck(FALSE)
          ->condition('collection_id', $collection->id())
          ->condition('file', $file->id())
          ->count()
          ->execute();
        $document = $this->manager->enqueue($file, $collection, $metadata, (int) $this->currentUser->id());
        $registered_file_id = (int) $document->get('file')->target_id;
        if ((int) $before > 0 || $registered_file_id !== (int) $file->id() || $document->getStatus() !== 'pending') {
          $duplicates++;
        }
        else {
          $file->setPermanent();
          $file->save();
          $queued++;
        }
      }
      catch (\Throwable $exception) {
        $this->messenger()->addError($this->t('%file could not be queued: @message', [
          '%file' => $file->getFilename(),
          '@message' => $exception->getMessage(),
        ]));
      }
    }
    $this->messenger()->addStatus($this->t('Queued @count document(s); @duplicates duplicate(s) were skipped.', [
      '@count' => $queued,
      '@duplicates' => $duplicates,
    ]));
    $form_state->setRedirect('entity.grok_doc_document.collection');
  }

  /**
   * Loads managed files selected by the form.
   *
   * @return \Drupal\file\FileInterface[]
   *   Files keyed by file ID.
   */
  private function loadFiles(FormStateInterface $form_state): array {
    $ids = array_values(array_filter(array_map('intval', (array) $form_state->getValue('files'))));
    return $ids ? $this->entityTypeManager->getStorage('file')->loadMultiple($ids) : [];
  }

}
