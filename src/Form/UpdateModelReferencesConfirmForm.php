<?php

declare(strict_types=1);

namespace Drupal\grok\Form;

use Drupal\Core\Form\ConfirmFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Confirms replacement of a Grok model in Drupal AI capabilities.
 */
final class UpdateModelReferencesConfirmForm extends ConfirmFormBase {

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return (new static())->setConfigFactory($container->get('config.factory'));
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'grok_update_model_references_confirm';
  }

  /**
   * {@inheritdoc}
   */
  public function getQuestion(): string {
    return (string) $this->t('Update Grok capability references from @old to @new?', [
      '@old' => $this->getRouteMatch()->getParameter('from'),
      '@new' => $this->getRouteMatch()->getParameter('to'),
    ]);
  }

  /**
   * {@inheritdoc}
   */
  public function getDescription(): string {
    return (string) $this->t('Every Drupal AI capability whose default provider is Grok and whose model belongs to the old numbered model family will be updated. Other providers and model families are not changed.');
  }

  /**
   * {@inheritdoc}
   */
  public function getCancelUrl(): Url {
    return Url::fromRoute('grok.settings_form');
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $from = (string) $this->getRouteMatch()->getParameter('from');
    $to = (string) $this->getRouteMatch()->getParameter('to');
    $from_version = $this->modelVersion($from);
    $to_version = $this->modelVersion($to);
    if (
      $from_version === NULL ||
      $to_version === NULL ||
      version_compare($to_version, $from_version, '<=')
    ) {
      $this->messenger()->addError($this->t('The requested Grok model update is invalid.'));
      $form_state->setRedirect('grok.settings_form');
      return;
    }

    $ai_config = $this->configFactory()->getEditable('ai.settings');
    $defaults = (array) $ai_config->get('default_providers');
    $updated = 0;
    foreach ($defaults as &$default) {
      if (
        ($default['provider_id'] ?? '') === 'grok' &&
        $this->modelVersion((string) ($default['model_id'] ?? '')) === $from_version
      ) {
        $default['model_id'] = $to;
        $updated++;
      }
    }
    unset($default);
    if ($updated > 0) {
      $ai_config->set('default_providers', $defaults)->save(TRUE);
    }

    $grok_config = $this->configFactory()->getEditable('grok.settings');
    if ($this->modelVersion((string) $grok_config->get('default_model')) === $from_version) {
      $grok_config->set('default_model', $to)->save(TRUE);
    }

    $this->messenger()->addStatus($this->formatPlural(
      $updated,
      'Updated one Drupal AI capability to use @model.',
      'Updated @count Drupal AI capabilities to use @model.',
      ['@model' => $to],
    ));
    $form_state->setRedirect('grok.settings_form');
  }

  /**
   * Checks route parameters before using them as model identifiers.
   */
  private function modelVersion(string $model): ?string {
    return preg_match('/^grok-(\d+(?:\.\d+)*)(?:-|$)/i', $model, $matches)
      ? $matches[1]
      : NULL;
  }

}
