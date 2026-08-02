<?php

declare(strict_types=1);

namespace Drupal\grok_doc\Form;

use Drupal\Component\Serialization\Json;
use Drupal\Core\Entity\EntityForm;
use Drupal\Core\Form\FormStateInterface;
use Drupal\key\KeyRepositoryInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Adds or edits a registered xAI Collection.
 */
final class GrokCollectionForm extends EntityForm {

  /**
   * Constructs the Collection form. */
  public function __construct(private readonly KeyRepositoryInterface $keyRepository) {}

  /**
   * {@inheritdoc} */
  public static function create(ContainerInterface $container): static {
    return new static($container->get('key.repository'));
  }

  /**
   * {@inheritdoc} */
  public function form(array $form, FormStateInterface $form_state): array {
    /** @var \Drupal\grok_doc\Entity\GrokCollectionInterface $collection */
    $collection = $this->entity;
    $keys = [];
    foreach ($this->keyRepository->getKeys() as $key) {
      $keys[$key->id()] = $key->label();
    }
    $form['label'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Label'),
      '#default_value' => $collection->label(),
      '#required' => TRUE,
    ];
    $form['id'] = [
      '#type' => 'machine_name',
      '#default_value' => $collection->id(),
      '#machine_name' => ['exists' => '\Drupal\grok_doc\Entity\GrokCollection::load'],
      '#disabled' => !$collection->isNew(),
    ];
    $form['remote_id'] = [
      '#type' => 'textfield',
      '#title' => $this->t('xAI collection ID'),
      '#description' => $this->t('Register an existing ID beginning with collection_. Remote collection creation is intentionally deferred until after the alpha ingestion workflow is proven.'),
      '#default_value' => $collection->getRemoteId(),
      '#required' => TRUE,
    ];
    $form['management_key'] = [
      '#type' => 'select',
      '#title' => $this->t('xAI Management API key'),
      '#description' => $this->t('Use a dedicated least-privilege Management key with AddFileToCollection permission.'),
      '#options' => $keys,
      '#empty_option' => $this->t('- Select -'),
      '#default_value' => $collection->getManagementKeyId(),
      '#required' => TRUE,
    ];
    $form['description'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Description'),
      '#default_value' => $collection->get('description'),
    ];
    $form['default_metadata'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Default metadata (JSON object)'),
      '#default_value' => $collection->get('default_metadata') ?: '{}',
      '#description' => $this->t('These values are merged with metadata entered for an import batch.'),
    ];
    $form['max_batch_bytes'] = [
      '#type' => 'number',
      '#title' => $this->t('Maximum import batch size in bytes'),
      '#default_value' => $collection->getMaxBatchBytes(),
      '#min' => 1,
      '#required' => TRUE,
    ];
    $form['enabled'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Allow document ingestion'),
      '#default_value' => $collection->isEnabled(),
    ];
    $form['searchable'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Approve this collection for Grok search configurations'),
      '#default_value' => $collection->isSearchable(),
    ];
    return parent::form($form, $form_state);
  }

  /**
   * {@inheritdoc} */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    parent::validateForm($form, $form_state);
    $remote_id = trim((string) $form_state->getValue('remote_id'));
    if (!preg_match('/^collection_[A-Za-z0-9-]+$/', $remote_id)) {
      $form_state->setErrorByName('remote_id', $this->t('Enter a valid xAI collection ID beginning with collection_.'));
    }
    try {
      $metadata = Json::decode((string) $form_state->getValue('default_metadata'));
      if (!is_array($metadata) || array_is_list($metadata)) {
        throw new \InvalidArgumentException();
      }
    }
    catch (\Throwable) {
      $form_state->setErrorByName('default_metadata', $this->t('Metadata must be a valid JSON object.'));
    }
  }

  /**
   * {@inheritdoc} */
  public function save(array $form, FormStateInterface $form_state): int {
    $status = parent::save($form, $form_state);
    $this->messenger()->addStatus($this->t('Saved the %label collection registration.', ['%label' => $this->entity->label()]));
    $form_state->setRedirect('entity.grok_doc_collection.collection');
    return $status;
  }

}
