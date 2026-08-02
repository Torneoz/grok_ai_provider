<?php

declare(strict_types=1);

namespace Drupal\grok_doc\Form;

use Drupal\Core\Entity\EntityConfirmFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;

/**
 * Deletes only the local collection registration.
 */
final class GrokCollectionDeleteForm extends EntityConfirmFormBase {

  /**
   * {@inheritdoc} */
  public function getQuestion(): string {
    return (string) $this->t('Delete the local registration for %label?', ['%label' => $this->entity->label()]);
  }

  /**
   * {@inheritdoc} */
  public function getDescription(): string {
    return (string) $this->t('This does not delete the Collection or documents from xAI. Remote deletion is not included in the alpha release.');
  }

  /**
   * {@inheritdoc} */
  public function getCancelUrl(): Url {
    return Url::fromRoute('entity.grok_doc_collection.collection');
  }

  /**
   * {@inheritdoc} */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $this->entity->delete();
    $this->messenger()->addStatus($this->t('Deleted the local collection registration.'));
    $form_state->setRedirectUrl($this->getCancelUrl());
  }

}
