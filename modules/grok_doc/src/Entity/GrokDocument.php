<?php

declare(strict_types=1);

namespace Drupal\grok_doc\Entity;

use Drupal\Component\Serialization\Json;
use Drupal\Core\Entity\ContentEntityBase;
use Drupal\Core\Entity\EntityChangedTrait;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Field\BaseFieldDefinition;

/**
 * Records a local file's ingestion into an xAI Collection.
 *
 * @ContentEntityType(
 *   id = "grok_doc_document",
 *   label = @Translation("Grok document"),
 *   label_collection = @Translation("Grok documents"),
 *   handlers = {
 *     "list_builder" = "Drupal\grok_doc\GrokDocumentListBuilder",
 *     "access" = "Drupal\grok_doc\GrokDocumentAccessControlHandler"
 *   },
 *   base_table = "grok_doc_document",
 *   admin_permission = "administer grok collections",
 *   entity_keys = {
 *     "id" = "id",
 *     "uuid" = "uuid",
 *     "label" = "filename",
 *     "owner" = "uid"
 *   },
 *   links = {
 *     "collection" = "/admin/config/ai/grok/documents"
 *   }
 * )
 */
final class GrokDocument extends ContentEntityBase implements GrokDocumentInterface {

  use EntityChangedTrait;

  public const STATUS_PENDING = 'pending';
  public const STATUS_UPLOADING = 'uploading';
  public const STATUS_INDEXING = 'indexing';
  public const STATUS_READY = 'ready';
  public const STATUS_FAILED = 'failed';
  public const STATUS_REMOVED = 'removed';

  /**
   * {@inheritdoc} */
  public function getCollectionId(): string {
    return (string) $this->get('collection_id')->value;
  }

  /**
   * {@inheritdoc} */
  public function getRemoteFileId(): string {
    return (string) $this->get('remote_file_id')->value;
  }

  /**
   * {@inheritdoc} */
  public function getStatus(): string {
    return (string) $this->get('status')->value;
  }

  /**
   * {@inheritdoc} */
  public function setStatus(string $status, string $error = ''): static {
    $this->set('status', $status);
    $this->set('last_error', mb_substr($error, 0, 4000));
    return $this;
  }

  /**
   * {@inheritdoc} */
  public function getMetadata(): array {
    try {
      $metadata = Json::decode((string) $this->get('metadata')->value);
    }
    catch (\Throwable) {
      return [];
    }
    return is_array($metadata) ? $metadata : [];
  }

  /**
   * {@inheritdoc} */
  public static function baseFieldDefinitions(EntityTypeInterface $entity_type): array {
    $fields = parent::baseFieldDefinitions($entity_type);
    $fields['id'] = BaseFieldDefinition::create('integer')
      ->setLabel(t('ID'))
      ->setReadOnly(TRUE)
      ->setSetting('unsigned', TRUE);
    $fields['uuid'] = BaseFieldDefinition::create('uuid')
      ->setLabel(t('UUID'))
      ->setReadOnly(TRUE);
    $fields['filename'] = BaseFieldDefinition::create('string')
      ->setLabel(t('Filename'))
      ->setRequired(TRUE)
      ->setSetting('max_length', 255);
    $fields['file'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(t('Drupal file'))
      ->setRequired(TRUE)
      ->setSetting('target_type', 'file');
    $fields['collection_id'] = BaseFieldDefinition::create('string')
      ->setLabel(t('Collection configuration ID'))
      ->setRequired(TRUE)
      ->setSetting('max_length', 128);
    $fields['remote_file_id'] = BaseFieldDefinition::create('string')
      ->setLabel(t('xAI file ID'))
      ->setSetting('max_length', 128);
    $fields['sha256'] = BaseFieldDefinition::create('string')
      ->setLabel(t('SHA-256'))
      ->setRequired(TRUE)
      ->setSetting('max_length', 64);
    $fields['size'] = BaseFieldDefinition::create('integer')
      ->setLabel(t('Size'))
      ->setRequired(TRUE);
    $fields['mime'] = BaseFieldDefinition::create('string')
      ->setLabel(t('MIME type'))
      ->setSetting('max_length', 255);
    $fields['metadata'] = BaseFieldDefinition::create('string_long')
      ->setLabel(t('Metadata JSON'));
    $fields['status'] = BaseFieldDefinition::create('string')
      ->setLabel(t('Status'))
      ->setDefaultValue(self::STATUS_PENDING)
      ->setRequired(TRUE)
      ->setSetting('max_length', 32);
    $fields['attempts'] = BaseFieldDefinition::create('integer')
      ->setLabel(t('Attempts'))
      ->setDefaultValue(0);
    $fields['last_error'] = BaseFieldDefinition::create('string_long')
      ->setLabel(t('Last error'));
    $fields['uid'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(t('Imported by'))
      ->setSetting('target_type', 'user')
      ->setDefaultValueCallback(static::class . '::getDefaultEntityOwner');
    $fields['created'] = BaseFieldDefinition::create('created')->setLabel(t('Created'));
    $fields['changed'] = BaseFieldDefinition::create('changed')->setLabel(t('Changed'));
    return $fields;
  }

  /**
   * Returns the active account as the default owner. */
  public static function getDefaultEntityOwner(): array {
    return [\Drupal::currentUser()->id()];
  }

}
