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
  ) {
  }

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
    $form['advanced']['transport'] = [
      '#type' => 'radios',
      '#title' => $this->t('Transport'),
      '#options' => [
        'auto' => $this->t('Automatic (recommended)'),
        'chat_completions' => $this->t('Chat Completions only'),
        'responses' => $this->t('Responses API for compatible requests'),
      ],
      '#default_value' => $config->get('transport') ?: 'auto',
      '#description' => $this->t('Automatic preserves Chat Completions for ordinary Drupal AI requests and selects Responses when a hosted tool is requested.'),
      '#required' => TRUE,
    ];
    $form['advanced']['store_responses'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Allow xAI to store Responses API requests'),
      '#default_value' => (bool) $config->get('store_responses'),
      '#description' => $this->t('Disabled by default. When disabled, the provider sends store=false and does not use server-side conversation continuation.'),
    ];

    $permissions = (array) $config->get('hosted_tools');
    $form['hosted_tools'] = [
      '#type' => 'details',
      '#title' => $this->t('Hosted tool permissions'),
      '#description' => $this->t('These settings permit tools at site level. Individual requests must still select a permitted tool. Hosted tools can incur additional xAI charges.'),
      '#tree' => TRUE,
    ];
    foreach ([
      'web_search' => $this->t('Permit Web Search'),
      'x_search' => $this->t('Permit X Search'),
      'code_interpreter' => $this->t('Permit Code Interpreter'),
      'file_search' => $this->t('Permit Collections Search'),
      'mcp' => $this->t('Permit allowlisted remote MCP servers'),
    ] as $key => $label) {
      $form['hosted_tools'][$key] = [
        '#type' => 'checkbox',
        '#title' => $label,
        '#default_value' => (bool) ($permissions[$key] ?? FALSE),
      ];
    }

    $form['mcp_servers'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Remote MCP server allowlist'),
      '#default_value' => $this->formatMcpServers((array) $config->get('mcp_servers')),
      '#description' => $this->t('One server per line: label|https://server.example/mcp|allowed_tool_1,allowed_tool_2. An explicit allowed-tools list is required.'),
      '#states' => [
        'visible' => [':input[name="hosted_tools[mcp]"]' => ['checked' => TRUE]],
      ],
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    parent::validateForm($form, $form_state);
    try {
      $form_state->setValue('mcp_servers', $this->parseMcpServers((string) $form_state->getValue('mcp_servers')));
    }
    catch (\InvalidArgumentException $exception) {
      $form_state->setErrorByName('mcp_servers', $exception->getMessage());
    }
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
      ->set('transport', $form_state->getValue('transport'))
      ->set('store_responses', (bool) $form_state->getValue('store_responses'))
      ->set('hosted_tools', array_map('boolval', (array) $form_state->getValue('hosted_tools')))
      ->set('mcp_servers', (array) $form_state->getValue('mcp_servers'))
      ->save();

    $this->aiProviderManager->defaultIfNone('chat', 'grok', 'grok-4.5-latest');
    parent::submitForm($form, $form_state);
  }

  /**
   * Parses the line-oriented MCP allowlist field.
   */
  private function parseMcpServers(string $value): array {
    $servers = [];
    foreach (preg_split('/\R/', trim($value)) ?: [] as $line_number => $line) {
      if (trim($line) === '') {
        continue;
      }
      $parts = array_map('trim', explode('|', $line, 3));
      if (count($parts) !== 3 || $parts[0] === '' || !filter_var($parts[1], FILTER_VALIDATE_URL)) {
        throw new \InvalidArgumentException((string) $this->t('MCP allowlist line @line must contain a label, valid URL, and allowed-tools list separated by |.', ['@line' => $line_number + 1]));
      }
      if (parse_url($parts[1], PHP_URL_SCHEME) !== 'https') {
        throw new \InvalidArgumentException((string) $this->t('MCP server URLs must use HTTPS (line @line).', ['@line' => $line_number + 1]));
      }
      if (!preg_match('/^[a-zA-Z0-9_-]+$/', $parts[0])) {
        throw new \InvalidArgumentException((string) $this->t('MCP server labels may contain only letters, numbers, underscores, and hyphens (line @line).', ['@line' => $line_number + 1]));
      }
      $allowed_tools = array_values(array_filter(array_map('trim', explode(',', $parts[2]))));
      if ($allowed_tools === []) {
        throw new \InvalidArgumentException((string) $this->t('MCP allowlist line @line must name at least one allowed tool.', ['@line' => $line_number + 1]));
      }
      $servers[] = [
        'label' => $parts[0],
        'url' => $parts[1],
        'allowed_tools' => $allowed_tools,
      ];
    }
    return $servers;
  }

  /**
   * Formats MCP server configuration for the textarea.
   */
  private function formatMcpServers(array $servers): string {
    $lines = [];
    foreach ($servers as $server) {
      if (!empty($server['label']) && !empty($server['url'])) {
        $lines[] = $server['label'] . '|' . $server['url'] . '|' . implode(',', $server['allowed_tools'] ?? []);
      }
    }
    return implode("\n", $lines);
  }

}
