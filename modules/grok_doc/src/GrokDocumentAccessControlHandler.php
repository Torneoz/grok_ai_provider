<?php

declare(strict_types=1);

namespace Drupal\grok_doc;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Entity\EntityAccessControlHandler;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Session\AccountInterface;

/**
 * Applies the dedicated Grok Documents permissions to records.
 */
final class GrokDocumentAccessControlHandler extends EntityAccessControlHandler {

  /**
   * {@inheritdoc} */
  protected function checkAccess(EntityInterface $entity, $operation, AccountInterface $account) {
    if ($account->hasPermission('administer grok collections')) {
      return AccessResult::allowed()->cachePerPermissions();
    }
    $permission = $operation === 'delete'
      ? 'delete grok collection documents'
      : 'view grok collection status';
    return AccessResult::allowedIfHasPermission($account, $permission);
  }

  /**
   * {@inheritdoc} */
  protected function checkCreateAccess(AccountInterface $account, array $context, $entity_bundle = NULL) {
    return AccessResult::allowedIfHasPermission($account, 'upload grok collection documents');
  }

}
