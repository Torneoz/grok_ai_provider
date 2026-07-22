<?php

declare(strict_types=1);

namespace Drupal\grok_ai_provider\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\ai\AiProviderPluginManager;
use Drupal\key\KeyRepositoryInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Configures xAI API access.
 */
final class GrokAiProviderConfigForm extends ConfigFormBase {

  private const CONFIG_NAME = 'grok_ai_provider.settings';

  /**
   * Constructs the configuration form.
   */
  public function __construct(
    private readonly AiProviderPluginManager $aiProviderManager,
    private readonly KeyRepositoryInterface $keyRepository,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('ai.provider'),
      $container->get('key.repository'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'grok_ai_provider_settings';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames(): array {
    return [self::CONFIG_NAME];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $config = $this->config(self::CONFIG_NAME);

    $form['api_key'] = [
      '#type' => 'key_select',
      '#title' => $this->t('xAI API key'),
      '#description' => $this->t('Select a Key containing an API key created in the xAI Console.'),
      '#default_value' => $config->get('api_key'),
      '#required' => TRUE,
    ];
    $form['advanced'] = [
      '#type' => 'details',
      '#title' => $this->t('Advanced settings'),
    ];
    $form['advanced']['host'] = [
      '#type' => 'url',
      '#title' => $this->t('API base URL'),
      '#default_value' => $config->get('host') ?: 'https://api.x.ai/v1',
      '#required' => TRUE,
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    parent::validateForm($form, $form_state);
    $key_id = (string) $form_state->getValue('api_key');
    $key = $this->keyRepository->getKey($key_id);
    $api_key = $key?->getKeyValue();
    if (!$api_key) {
      $form_state->setErrorByName('api_key', $this->t('The selected Key does not contain an API key.'));
      return;
    }

    $host = rtrim((string) $form_state->getValue('host'), '/');
    /** @var \Drupal\grok_ai_provider\Plugin\AiProvider\GrokAiProvider $provider */
    $provider = $this->aiProviderManager->createInstance('grok');
    $provider->setAuthentication($api_key);
    $provider->setConfiguration(['host' => $host]);

    try {
      if ($provider->getConfiguredModels('chat') === []) {
        $form_state->setErrorByName('api_key', $this->t('xAI did not return any accessible Grok models for this key.'));
      }
    }
    catch (\Throwable $exception) {
      $form_state->setErrorByName('api_key', $this->t('Could not authenticate with xAI: @message', ['@message' => $exception->getMessage()]));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $this->config(self::CONFIG_NAME)
      ->set('api_key', $form_state->getValue('api_key'))
      ->set('host', rtrim((string) $form_state->getValue('host'), '/'))
      ->save();

    $this->aiProviderManager->defaultIfNone('chat', 'grok', 'grok-4.5-latest');
    parent::submitForm($form, $form_state);
  }

}
