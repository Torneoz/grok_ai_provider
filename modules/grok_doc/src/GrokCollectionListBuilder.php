<?php

declare(strict_types=1);

namespace Drupal\grok_doc;

use Drupal\Core\Config\Entity\ConfigEntityListBuilder;
use Drupal\Core\Entity\EntityInterface;

/**
 * Lists registered xAI Collections.
 */
final class GrokCollectionListBuilder extends ConfigEntityListBuilder {

  /**
   * {@inheritdoc} */
  public function buildHeader(): array {
    $header['label'] = $this->t('Collection');
    $header['remote_id'] = $this->t('xAI ID');
    $header['enabled'] = $this->t('Ingestion');
    $header['searchable'] = $this->t('Search');
    return $header + parent::buildHeader();
  }

  /**
   * {@inheritdoc} */
  public function buildRow(EntityInterface $entity): array {
    /** @var \Drupal\grok_doc\Entity\GrokCollectionInterface $entity */
    $row['label'] = $entity->label();
    $row['remote_id'] = ['data' => ['#markup' => '<code>' . $entity->getRemoteId() . '</code>']];
    $row['enabled'] = $entity->isEnabled() ? $this->t('Enabled') : $this->t('Disabled');
    $row['searchable'] = $entity->isSearchable() ? $this->t('Allowed') : $this->t('Not allowed');
    return $row + parent::buildRow($entity);
  }

}
