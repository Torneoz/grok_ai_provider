<?php

declare(strict_types=1);

namespace Drupal\grok_doc\Service;

use Drupal\Component\Serialization\Json;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\Queue\QueueFactory;
use Drupal\file\FileInterface;
use Drupal\grok_doc\Entity\GrokCollectionInterface;
use Drupal\grok_doc\Entity\GrokDocument;
use Drupal\grok_doc\Entity\GrokDocumentInterface;
use Drupal\key\KeyRepositoryInterface;

/**
 * Creates idempotent ingestion records and processes their state transitions.
 */
final class CollectionDocumentManager {

  public const QUEUE = 'grok_doc_ingest';

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly KeyRepositoryInterface $keyRepository,
    private readonly QueueFactory $queueFactory,
    private readonly FileSystemInterface $fileSystem,
    private readonly XaiCollectionsClient $client,
    private readonly LoggerChannelInterface $logger,
    private readonly ConfigFactoryInterface $configFactory,
  ) {}

  /**
   * Registers a managed file and queues it unless an identical record exists.
   */
  public function enqueue(FileInterface $file, GrokCollectionInterface $collection, array $metadata, int $owner_id): GrokDocumentInterface {
    if (!$collection->isEnabled()) {
      throw new \InvalidArgumentException('The selected collection is disabled for ingestion.');
    }
    $path = $this->fileSystem->realpath($file->getFileUri());
    if ($path === FALSE || !is_file($path) || !is_readable($path)) {
      throw new \RuntimeException('The Drupal file is not locally readable.');
    }
    $hash = hash_file('sha256', $path);
    if ($hash === FALSE) {
      throw new \RuntimeException('The source document could not be hashed.');
    }
    $storage = $this->entityTypeManager->getStorage('grok_doc_document');
    $existing = $storage->loadByProperties([
      'collection_id' => $collection->id(),
      'sha256' => $hash,
    ]);
    if ($existing) {
      return reset($existing);
    }
    $metadata = array_replace($collection->getDefaultMetadata(), $metadata);
    /** @var \Drupal\grok_doc\Entity\GrokDocumentInterface $document */
    $document = $storage->create([
      'filename' => $file->getFilename(),
      'file' => $file->id(),
      'collection_id' => $collection->id(),
      'sha256' => $hash,
      'size' => $file->getSize(),
      'mime' => $file->getMimeType() ?: 'application/octet-stream',
      'metadata' => Json::encode($metadata),
      'status' => GrokDocument::STATUS_PENDING,
      'uid' => $owner_id,
    ]);
    $document->save();
    $this->queueFactory->get(self::QUEUE)->createItem(['document_id' => (int) $document->id()]);
    return $document;
  }

  /**
   * Uploads a queued document or refreshes its indexing state.
   */
  public function process(int $document_id): string {
    $storage = $this->entityTypeManager->getStorage('grok_doc_document');
    /** @var \Drupal\grok_doc\Entity\GrokDocumentInterface|null $document */
    $document = $storage->load($document_id);
    if (!$document || in_array($document->getStatus(), [GrokDocument::STATUS_READY, GrokDocument::STATUS_REMOVED], TRUE)) {
      return 'complete';
    }
    /** @var \Drupal\grok_doc\Entity\GrokCollectionInterface|null $collection */
    $collection = $this->entityTypeManager->getStorage('grok_doc_collection')->load($document->getCollectionId());
    if (!$collection || !$collection->isEnabled()) {
      $document->setStatus(GrokDocument::STATUS_FAILED, 'The collection registration is missing or disabled.')->save();
      return 'failed';
    }
    try {
      $key = $this->keyRepository->getKey($collection->getManagementKeyId());
      $api_key = $key ? (string) $key->getKeyValue() : '';
      if ($document->getRemoteFileId() !== '') {
        $remote = $this->client->getDocument($api_key, $collection->getRemoteId(), $document->getRemoteFileId());
      }
      else {
        /** @var \Drupal\file\FileInterface|null $file */
        $file = $document->get('file')->entity;
        $path = $file ? $this->fileSystem->realpath($file->getFileUri()) : FALSE;
        if (!$file || $path === FALSE || !is_readable($path)) {
          throw new \RuntimeException('The source file is no longer available.');
        }
        $document->setStatus(GrokDocument::STATUS_UPLOADING)->save();
        $remote = $this->client->uploadDocument(
          $api_key,
          $collection->getRemoteId(),
          $path,
          (string) $document->label(),
          (string) $document->get('mime')->value,
          $document->getMetadata(),
        );
        $file_id = $this->extractFileId($remote);
        if ($file_id === '') {
          throw new \RuntimeException('xAI did not return a file ID for the uploaded document.');
        }
        $document->set('remote_file_id', $file_id);
      }
      $status = strtoupper((string) ($remote['status'] ?? $remote['document']['status'] ?? ''));
      if ($status === '' || str_contains($status, 'PENDING') || str_contains($status, 'PROCESSING')) {
        $document->setStatus(GrokDocument::STATUS_INDEXING)->save();
        return 'retry';
      }
      if (str_contains($status, 'PROCESSED') || str_contains($status, 'READY')) {
        $document->setStatus(GrokDocument::STATUS_READY)->save();
        return 'complete';
      }
      throw new \RuntimeException((string) ($remote['error_message'] ?? 'xAI reported that document indexing failed.'));
    }
    catch (\Throwable $exception) {
      $attempts = (int) $document->get('attempts')->value + 1;
      $document->set('attempts', $attempts);
      $max_attempts = (int) $this->configFactory->get('grok_doc.settings')->get('max_attempts') ?: 5;
      $document->setStatus($attempts >= $max_attempts ? GrokDocument::STATUS_FAILED : GrokDocument::STATUS_PENDING, $exception->getMessage())->save();
      $this->logger->warning('Document @id ingestion failed on attempt @attempt: @message', [
        '@id' => $document_id,
        '@attempt' => $attempts,
        '@message' => $exception->getMessage(),
      ]);
      return $attempts >= $max_attempts ? 'failed' : 'retry';
    }
  }

  /**
   * Extracts a file identifier from supported xAI response shapes. */
  private function extractFileId(array $response): string {
    return (string) ($response['file_id'] ?? $response['id'] ?? $response['file_metadata']['file_id'] ?? $response['document']['file_metadata']['file_id'] ?? '');
  }

}
