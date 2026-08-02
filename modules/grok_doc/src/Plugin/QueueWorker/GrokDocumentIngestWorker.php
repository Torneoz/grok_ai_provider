<?php

declare(strict_types=1);

namespace Drupal\grok_doc\Plugin\QueueWorker;

use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Queue\QueueWorkerBase;
use Drupal\Core\Queue\DelayedRequeueException;
use Drupal\grok_doc\Service\CollectionDocumentManager;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Uploads and monitors xAI Collection documents.
 *
 * @QueueWorker(
 *   id = "grok_doc_ingest",
 *   title = @Translation("Grok document ingestion"),
 *   cron = {"time" = 30}
 * )
 */
final class GrokDocumentIngestWorker extends QueueWorkerBase implements ContainerFactoryPluginInterface {

  /**
   * Constructs the ingestion worker. */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, private readonly CollectionDocumentManager $manager) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * {@inheritdoc} */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    return new static($configuration, $plugin_id, $plugin_definition, $container->get('grok_doc.manager'));
  }

  /**
   * {@inheritdoc} */
  public function processItem($data): void {
    $document_id = (int) ($data['document_id'] ?? 0);
    if ($document_id <= 0) {
      return;
    }
    if ($this->manager->process($document_id) === 'retry') {
      throw new DelayedRequeueException(30, 'The document requires another ingestion or indexing attempt.');
    }
  }

}
