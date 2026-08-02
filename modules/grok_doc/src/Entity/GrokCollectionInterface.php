<?php

declare(strict_types=1);

namespace Drupal\grok_doc\Entity;

use Drupal\Core\Config\Entity\ConfigEntityInterface;

/**
 * Defines the Grok collection configuration entity contract.
 */
interface GrokCollectionInterface extends ConfigEntityInterface {

  /**
   * Returns the xAI Collection identifier. */
  public function getRemoteId(): string;

  /**
   * Returns the Management API Key entity identifier. */
  public function getManagementKeyId(): string;

  /**
   * Returns whether ingestion is enabled. */
  public function isEnabled(): bool;

  /**
   * Returns whether search use is approved. */
  public function isSearchable(): bool;

  /**
   * Returns validated default document metadata.
   */
  public function getDefaultMetadata(): array;

  /**
   * Returns the maximum import batch size. */
  public function getMaxBatchBytes(): int;

}
