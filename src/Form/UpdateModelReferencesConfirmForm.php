<?php

declare(strict_types=1);

namespace Drupal\grok\Form;

use Drupal\Core\Form\ConfirmFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\ai\AiProviderPluginManager;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Confirms replacement of a Grok model in Drupal AI capabilities.
 */
final class UpdateModelReferencesConfirmForm extends ConfirmFormBase {

  /**
   * The Drupal AI provider manager.
   */
  private AiProviderPluginManager $providerManager;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    $instance = (new static())->setConfigFactory($container->get('config.factory'));
    $instance->providerManager = $container->get('ai.provider');
    return $instance;
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
    return (string) $this->t('Update all AI capabilities for Grok using @new?', [
      '@new' => $this->getRouteMatch()->getParameter('to'),
    ]);
  }

  /**
   * {@inheritdoc}
   */
  public function getDescription(): string {
    return (string) $this->t('Grok will be set as the default provider for every AI capability it supports. Chat-related capabilities will use the selected model; image, video, and speech capabilities will use their appropriate Grok models.');
  }

  /**
   * {@inheritdoc}
   */
  public function getCancelUrl(): Url {
    return Url::fromRoute('ai.settings_form');
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $from = (string) $this->getRouteMatch()->getParameter('from');
    $to = (string) $this->getRouteMatch()->getParameter('to');
    if (!str_starts_with($from, 'grok-') || !str_starts_with($to, 'grok-')) {
      $this->messenger()->addError($this->t('The requested Grok model update is invalid.'));
      $form_state->setRedirect('ai.settings_form');
      return;
    }

    $ai_config = $this->configFactory()->getEditable('ai.settings');
    $defaults = (array) $ai_config->get('default_providers');
    $provider = $this->providerManager->createInstance('grok');
    $setup_models = (array) ($provider->getSetupData()['default_models'] ?? []);
    $capability_defaults = $this->capabilityDefaults($setup_models, $from, $to);
    $updated = 0;
    foreach ($capability_defaults as $operation => $model) {
      $new_default = [
        'provider_id' => 'grok',
        'model_id' => $model,
      ];
      if (($defaults[$operation] ?? NULL) !== $new_default) {
        $updated++;
      }
      $defaults[$operation] = $new_default;
    }
    if ($updated > 0) {
      $ai_config->set('default_providers', $defaults)->save(TRUE);
    }

    $grok_config = $this->configFactory()->getEditable('grok.settings');
    if ((string) $grok_config->get('default_model') !== $to) {
      $grok_config->set('default_model', $to)->save(TRUE);
    }

    $this->messenger()->addStatus($this->formatPlural(
      $updated,
      'Updated one Drupal AI capability to use @model.',
      'Updated @count Drupal AI capabilities to use @model.',
      ['@model' => $to],
    ));
    $form_state->setRedirect('ai.settings_form');
  }

  /**
   * Builds defaults for every capability advertised by the Grok provider.
   */
  private function capabilityDefaults(array $models, string $from, string $to): array {
    foreach ($models as &$model) {
      if ((string) $model === $from) {
        $model = $to;
      }
    }
    unset($model);
    return $models;
  }

}
