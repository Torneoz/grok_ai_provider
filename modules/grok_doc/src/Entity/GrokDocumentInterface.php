<?php

declare(strict_types=1);

namespace Drupal\grok_doc\Entity;

use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityChangedInterface;

/**
 * Defines a managed Grok document record.
 */
interface GrokDocumentInterface extends ContentEntityInterface, EntityChangedInterface {

  /**
   * Returns the local Collection configuration entity identifier. */
  public function getCollectionId(): string;

  /**
   * Returns the xAI file identifier, when uploaded. */
  public function getRemoteFileId(): string;

  /**
   * Returns the ingestion status. */
  public function getStatus(): string;

  /**
   * Sets the ingestion status and optional sanitized error. */
  public function setStatus(string $status, string $error = ''): static;

  /**
   * Returns decoded document metadata. */
  public function getMetadata(): array;

}
