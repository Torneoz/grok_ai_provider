<?php

declare(strict_types=1);

namespace Drupal\grok_doc\Form;

use Drupal\Core\Form\ConfirmFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Queue\QueueFactory;
use Drupal\Core\Url;
use Drupal\grok_doc\Service\CollectionDocumentManager;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Runs a bounded number of ingestion queue items interactively.
 */
final class ProcessQueueForm extends ConfirmFormBase {

  /**
   * Constructs the manual queue form. */
  public function __construct(
    private readonly QueueFactory $queueFactory,
    private readonly CollectionDocumentManager $manager,
  ) {}

  /**
   * {@inheritdoc} */
  public static function create(ContainerInterface $container): static {
    return new static($container->get('queue'), $container->get('grok_doc.manager'));
  }

  /**
   * {@inheritdoc} */
  public function getFormId(): string {
    return 'grok_doc_process_queue';
  }

  /**
   * {@inheritdoc} */
  public function getQuestion(): string {
    return (string) $this->t('Process queued Grok documents now?');
  }

  /**
   * {@inheritdoc} */
  public function getDescription(): string {
    return (string) $this->t('Cron normally processes this queue. A bounded manual run is useful while validating the alpha release.');
  }

  /**
   * {@inheritdoc} */
  public function getCancelUrl(): Url {
    return Url::fromRoute('entity.grok_doc_document.collection');
  }

  /**
   * {@inheritdoc} */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $queue = $this->queueFactory->get(CollectionDocumentManager::QUEUE);
    $limit = max(1, (int) $this->config('grok_doc.settings')->get('queue_batch_size'));
    $processed = 0;
    for ($i = 0; $i < $limit && ($item = $queue->claimItem(300)); $i++) {
      $result = $this->manager->process((int) ($item->data['document_id'] ?? 0));
      $queue->deleteItem($item);
      if ($result === 'retry') {
        $queue->createItem($item->data);
      }
      $processed++;
    }
    $this->messenger()->addStatus($this->t('Processed @count queue item(s).', ['@count' => $processed]));
    $form_state->setRedirectUrl($this->getCancelUrl());
  }

}
