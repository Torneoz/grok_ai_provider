<?php

declare(strict_types=1);

namespace Drupal\grok_doc\Entity;

use Drupal\Component\Serialization\Json;
use Drupal\Core\Config\Entity\ConfigEntityBase;

/**
 * Defines a registered xAI Collection.
 *
 * @ConfigEntityType(
 *   id = "grok_doc_collection",
 *   label = @Translation("Grok collection"),
 *   label_collection = @Translation("Grok collections"),
 *   handlers = {
 *     "list_builder" = "Drupal\grok_doc\GrokCollectionListBuilder",
 *     "form" = {
 *       "add" = "Drupal\grok_doc\Form\GrokCollectionForm",
 *       "edit" = "Drupal\grok_doc\Form\GrokCollectionForm",
 *       "delete" = "Drupal\grok_doc\Form\GrokCollectionDeleteForm"
 *     }
 *   },
 *   config_prefix = "collection",
 *   admin_permission = "administer grok collections",
 *   entity_keys = {
 *     "id" = "id",
 *     "label" = "label",
 *     "uuid" = "uuid"
 *   },
 *   config_export = {
 *     "id",
 *     "label",
 *     "uuid",
 *     "remote_id",
 *     "description",
 *     "management_key",
 *     "enabled",
 *     "searchable",
 *     "default_metadata",
 *     "max_batch_bytes"
 *   },
 *   links = {
 *     "collection" = "/admin/config/ai/grok/documents/collections",
 *     "add-form" = "/admin/config/ai/grok/documents/collections/add",
 *     "edit-form" = "/admin/config/ai/grok/documents/collections/{grok_doc_collection}",
 *     "delete-form" = "/admin/config/ai/grok/documents/collections/{grok_doc_collection}/delete"
 *   }
 * )
 */
final class GrokCollection extends ConfigEntityBase implements GrokCollectionInterface {

  /**
   * The configuration entity ID. */
  protected string $id;

  /**
   * The administrative label. */
  protected string $label;

  /**
   * The xAI Collection ID. */
  protected string $remote_id = '';

  /**
   * The description. */
  protected string $description = '';

  /**
   * The Management API Key entity ID. */
  protected string $management_key = '';

  /**
   * Whether ingestion is enabled. */
  protected bool $enabled = TRUE;

  /**
   * Whether search use is approved. */
  protected bool $searchable = FALSE;

  /**
   * JSON-encoded default metadata. */
  protected string $default_metadata = '{}';

  /**
   * Maximum import batch size. */
  protected int $max_batch_bytes = 524288000;

  /**
   * {@inheritdoc} */
  public function getRemoteId(): string {
    return trim($this->remote_id);
  }

  /**
   * {@inheritdoc} */
  public function getManagementKeyId(): string {
    return $this->management_key;
  }

  /**
   * {@inheritdoc} */
  public function isEnabled(): bool {
    return $this->enabled;
  }

  /**
   * {@inheritdoc} */
  public function isSearchable(): bool {
    return $this->searchable;
  }

  /**
   * {@inheritdoc} */
  public function getDefaultMetadata(): array {
    try {
      $metadata = Json::decode($this->default_metadata);
    }
    catch (\Throwable) {
      return [];
    }
    return is_array($metadata) ? $metadata : [];
  }

  /**
   * {@inheritdoc} */
  public function getMaxBatchBytes(): int {
    return max(1, $this->max_batch_bytes);
  }

}
