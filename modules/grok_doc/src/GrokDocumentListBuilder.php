<?php

declare(strict_types=1);

namespace Drupal\grok_doc;

use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityListBuilder;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Entity\EntityStorageInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Lists collection document ingestion records.
 */
final class GrokDocumentListBuilder extends EntityListBuilder {

  /**
   * Constructs the document list builder. */
  public function __construct(EntityTypeInterface $entity_type, EntityStorageInterface $storage, private readonly DateFormatterInterface $dateFormatter) {
    parent::__construct($entity_type, $storage);
  }

  /**
   * {@inheritdoc} */
  public static function createInstance(ContainerInterface $container, EntityTypeInterface $entity_type): static {
    return new static($entity_type, $container->get('entity_type.manager')->getStorage($entity_type->id()), $container->get('date.formatter'));
  }

  /**
   * {@inheritdoc} */
  public function buildHeader(): array {
    return [
      'filename' => $this->t('Document'),
      'collection' => $this->t('Collection'),
      'status' => $this->t('Status'),
      'size' => $this->t('Size'),
      'attempts' => $this->t('Attempts'),
      'changed' => $this->t('Updated'),
    ];
  }

  /**
   * {@inheritdoc} */
  public function buildRow(EntityInterface $entity): array {
    return [
      'filename' => $entity->label(),
      'collection' => $entity->getCollectionId(),
      'status' => $entity->getStatus(),
      'size' => format_size((int) $entity->get('size')->value),
      'attempts' => (int) $entity->get('attempts')->value,
      'changed' => $this->dateFormatter->format((int) $entity->getChangedTime(), 'short'),
    ];
  }

  /**
   * {@inheritdoc} */
  protected function getEntityIds(): array {
    $query = $this->getStorage()->getQuery()
      ->accessCheck(TRUE)
      ->sort('changed', 'DESC');
    if ($this->limit) {
      $query->pager($this->limit);
    }
    return $query->execute();
  }

}
