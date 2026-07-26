<?php

declare(strict_types=1);

namespace Drupal\grok_ai_provider\Form;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\TypedConfigManagerInterface;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\ai\AiProviderPluginManager;
use Drupal\key\KeyRepositoryInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Configures xAI API access.
 */
final class GrokAiProviderConfigForm extends ConfigFormBase {

  private const CONFIG_NAME = 'grok_ai_provider.settings';

  /**
   * The AI provider plugin manager.
   */
  private ?AiProviderPluginManager $aiProviderManager = NULL;

  /**
   * The Key repository.
   */
  private ?KeyRepositoryInterface $keyRepository = NULL;

  /**
   * Constructs the configuration form.
   */
  public function __construct(
    ConfigFactoryInterface $config_factory,
    TypedConfigManagerInterface $typed_config_manager,
    AiProviderPluginManager $ai_provider_manager,
    KeyRepositoryInterface $key_repository,
  ) {
    parent::__construct($config_factory, $typed_config_manager);
    $this->aiProviderManager = $ai_provider_manager;
    $this->keyRepository = $key_repository;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('config.factory'),
      $container->get('config.typed'),
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
    $module_list = \Drupal::service('extension.list.module');
    $module_path = $module_list->getPath('grok_ai_provider');

    $form['#attached']['library'][] = 'grok_ai_provider/config_form';
    $form['branding'] = [
      '#type' => 'container',
      '#attributes' => [
        'class' => ['grok-ai-provider-branding'],
      ],
      '#weight' => -100,
      'logo' => [
        '#theme' => 'image',
        '#uri' => $module_path . '/assets/Grok_Logomark_Light.svg',
        '#alt' => $this->t('Grok'),
        '#attributes' => [
          'class' => ['grok-ai-provider-branding__logo'],
        ],
      ],
      'identity' => [
        '#type' => 'container',
        '#attributes' => [
          'class' => ['grok-ai-provider-branding__identity'],
        ],
        'name' => [
          '#markup' => '<div class="grok-ai-provider-branding__name">' . $this->t('Grok AI Provider') . '</div>',
        ],
        'status' => [
          '#markup' => '<div class="grok-ai-provider-branding__status">' . $this->t('Unofficial') . '</div>',
        ],
      ],
    ];

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
      '#description' => $this->t('These settings control how the provider connects to xAI and selects between the legacy Chat Completions transport and the newer Responses transport. The defaults are appropriate for most sites. Change them only when required by your hosting environment, privacy policy, or integration workflow.'),
      '#open' => FALSE,
      '#states' => [
        'visible' => [
          ':input[name="api_key"]' => ['!value' => ''],
        ],
      ],
    ];
    $form['advanced']['host'] = [
      '#type' => 'url',
      '#title' => $this->t('API base URL'),
      '#default_value' => $config->get('host') ?: 'https://api.x.ai/v1',
      '#description' => $this->t('The HTTPS base URL used for xAI inference and model-discovery requests. Keep the official <code>https://api.x.ai/v1</code> endpoint unless your organization uses an approved xAI-compatible gateway or regional proxy. URLs containing credentials, query strings, or fragments are rejected to protect the selected API key.'),
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
      '#description' => $this->t('<strong>Automatic</strong> uses Chat Completions for ordinary Drupal AI and Drupal function-tool requests, then switches to Responses when an xAI-hosted tool is requested. <strong>Chat Completions only</strong> provides the broadest compatibility but prevents hosted Web Search, X Search, Code Interpreter, Collections Search, and remote MCP. <strong>Responses API</strong> prefers Responses for compatible non-streaming requests; Drupal function tools and ordinary streaming continue to use Chat Completions until equivalent Responses support is available.'),
      '#required' => TRUE,
    ];
    $form['advanced']['store_responses'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Allow xAI to store Responses API requests'),
      '#default_value' => (bool) $config->get('store_responses'),
      '#description' => $this->t('Controls xAI server-side storage for requests sent through the Responses API. It is disabled by default, causing the provider to send <code>store=false</code>. Enable it only when your privacy and data-retention policies allow prompts, uploaded content, tool activity, and responses to be retained by xAI. Storage is required for future workflows that continue a conversation using a previous response ID, but it is not required for normal Drupal AI chat history.'),
    ];
    $form['advanced']['request_timeout'] = [
      '#type' => 'number',
      '#title' => $this->t('Responses request timeout'),
      '#default_value' => (int) ($config->get('request_timeout') ?: 300),
      '#min' => 10,
      '#max' => 3600,
      '#field_suffix' => $this->t('seconds'),
      '#description' => $this->t('The maximum time Drupal waits for a synchronous Responses API request. Hosted searches, code execution, collections, MCP calls, and reasoning models may take longer than ordinary chat. Increase this value for complex workflows, or reduce it to release PHP workers sooner when an upstream request stalls. Your web server, PHP runtime, reverse proxy, or hosting platform may enforce a shorter timeout.'),
      '#required' => TRUE,
    ];

    $models = (array) $form_state->get('grok_models');
    $configured_default = (string) ($config->get('default_model') ?: 'grok-4.5-latest');
    if ($models === []) {
      $models = [$configured_default => $configured_default];
    }
    $selected_default = (string) ($form_state->getValue('default_model') ?: $configured_default);
    if (!isset($models[$selected_default])) {
      $selected_default = (string) array_key_first($models);
    }
    $form['connection'] = [
      '#type' => 'details',
      '#title' => $this->t('Test connection'),
      '#open' => TRUE,
      '#attributes' => ['id' => 'grok-connection-wrapper'],
      '#states' => [
        'visible' => [
          ':input[name="api_key"]' => ['!value' => ''],
        ],
      ],
    ];
    $form['connection']['test_connection'] = [
      '#type' => 'submit',
      '#name' => 'test_connection',
      '#value' => $this->t('Test connection and load models'),
      '#submit' => ['::testConnection'],
      '#limit_validation_errors' => [
        ['api_key'],
        ['host'],
      ],
      '#ajax' => [
        'callback' => '::connectionAjax',
        'wrapper' => 'grok-connection-wrapper',
        'progress' => ['type' => 'throbber'],
      ],
    ];
    $form['connection']['default_model'] = [
      '#type' => 'select',
      '#title' => $this->t('Default Grok model'),
      '#options' => $models,
      '#default_value' => $selected_default,
      '#description' => $form_state->get('grok_models') === NULL
        ? $this->t('Test the connection to load the models available to the selected API key.')
        : $this->t('Used as this provider’s default chat, vision, tools, and structured-output model.'),
      '#required' => TRUE,
    ];
    if ($status = $form_state->get('grok_connection_status')) {
      $form['connection']['status'] = [
        '#type' => 'container',
        '#attributes' => [
          'class' => ['messages', $status['type'] === 'error' ? 'messages--error' : 'messages--status'],
        ],
        'message' => ['#plain_text' => $status['message']],
      ];
    }

    $permissions = (array) $config->get('hosted_tools');
    $form['hosted_tools'] = [
      '#type' => 'details',
      '#title' => $this->t('Hosted tool permissions'),
      '#description' => $this->t('These settings permit tools at site level. Individual requests must still select a permitted tool. Hosted tools can incur additional xAI charges.'),
      '#tree' => TRUE,
      '#states' => [
        'visible' => [
          ':input[name="api_key"]' => ['!value' => ''],
        ],
      ],
    ];
    foreach ([
      'web_search' => [
        'label' => $this->t('Permit Web Search'),
        'description' => $this->t('Allows Grok to search and browse the public web for current information. Requests can optionally restrict searches to allowed or excluded domains and enable image search or image understanding. xAI charges separately for hosted search calls.'),
      ],
      'x_search' => [
        'label' => $this->t('Permit X Search'),
        'description' => $this->t('Allows Grok to search public posts, users, and threads on X. Individual requests can restrict handles and dates or enable image and video understanding. xAI charges separately for hosted search calls.'),
      ],
      'code_interpreter' => [
        'label' => $this->t('Permit Code Interpreter'),
        'description' => $this->t('Allows Grok to write and execute Python in an isolated environment managed by xAI. It is useful for calculations and data analysis. The environment has no access to this Drupal site unless data is included in the prompt, and tool calls may incur additional charges.'),
      ],
      'file_search' => [
        'label' => $this->t('Permit Collections Search'),
        'description' => $this->t('Allows Grok to search xAI collections containing previously uploaded documents. Individual requests must provide permitted collection IDs. Collection storage, retrieval, and search can incur additional xAI charges.'),
      ],
      'mcp' => [
        'label' => $this->t('Permit allowlisted remote MCP servers'),
        'description' => $this->t('Allows xAI to connect Grok to explicitly allowlisted remote MCP servers. Each server must use HTTPS and expose only the tool names listed below. Enable this only for trusted servers because MCP tools may read or modify external systems.'),
      ],
    ] as $key => $tool) {
      $form['hosted_tools'][$key] = [
        '#type' => 'checkbox',
        '#title' => $tool['label'],
        '#description' => $tool['description'],
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

    $form = parent::buildForm($form, $form_state);

    $next_steps_items = [
      $this->t('Use <strong>Test connection and load models</strong>, select the Grok model you want this provider to use by default, and save this form.'),
      $this->t('Open <a href=":url">Default Models for AI Operations</a> and select <strong>Grok (xAI)</strong> for Chat, Text To Image, Image To Image, Image To Video, and Text To Video. Choose the appropriate Grok chat and Grok Imagine models for features that rely on site-wide defaults.', [
        ':url' => Url::fromRoute('ai.settings_form')->toString(),
      ]),
    ];
    if (\Drupal::moduleHandler()->moduleExists('ai_api_explorer')) {
      $next_steps_items[] = $this->t('Try the configured models in the <a href=":chat_url">Chat Generation Explorer</a>, <a href=":image_url">Text-To-Image Generation Explorer</a>, <a href=":image_edit_url">Image-To-Image Explorer</a>, <a href=":image_video_url">Image-To-Video Generation Explorer</a>, and <a href=":video_url">Text-To-Video Generation Explorer</a>.', [
        ':chat_url' => Url::fromRoute('ai_api_explorer.form.chat_generator')->toString(),
        ':image_url' => Url::fromRoute('ai_api_explorer.form.text_to_image_generator')->toString(),
        ':image_edit_url' => Url::fromRoute('ai_api_explorer.form.image_to_image_generator')->toString(),
        ':image_video_url' => Url::fromRoute('ai_api_explorer.form.grok_image_to_video_generator')->toString(),
        ':video_url' => Url::fromRoute('ai_api_explorer.form.grok_text_to_video_generator')->toString(),
      ]);
      $next_steps_items[] = $this->t('Configure Grok as the default for Image Classification and Moderation, then test it in the <a href=":classification_url">Image Classification Explorer</a> and <a href=":moderation_url">Moderation Explorer</a>. Moderation is a model-based assessment, not a dedicated xAI safety endpoint.', [
        ':classification_url' => Url::fromRoute('ai_api_explorer.form.image_classification_generator')->toString(),
        ':moderation_url' => Url::fromRoute('ai_api_explorer.form.moderation_generator')->toString(),
      ]);
    }
    $next_steps_items[] = $this->t('To use Grok in CKEditor, enable the <strong>AI CKEditor integration</strong> module, then open <a href=":url">Text formats and editors</a>. Edit each CKEditor 5 text format that needs AI, add the AI button to its active toolbar, enable the required AI actions, and select <strong>Grok (xAI)</strong> with a Grok chat model for each action, or verify that the action uses the site-wide Chat default. Also grant the appropriate roles the <em>use ai ckeditor</em> permission.', [
      ':url' => Url::fromRoute('filter.admin_overview')->toString(),
    ]);
    $next_steps_items[] = $this->t('Review each Drupal AI module or feature you enable. Configure it to use the site-wide Chat default, or select Grok explicitly when that feature provides its own provider and model settings.');
    $next_steps_items[] = $this->t('Grok supports Chat-based text, vision, function tools, structured output, text-to-image generation, image-to-image editing, image-to-video generation, and text-to-video generation. Operations such as embeddings and speech require another provider that supports those operation types.');
    $next_steps_items[] = $this->t('To use xAI-hosted tools, permit them in <strong>Hosted tool permissions</strong>, then enable the permitted tools in the individual model or request configuration that needs them.');

    $form['next_steps'] = [
      '#type' => 'details',
      '#title' => $this->t('Next Steps'),
      '#open' => FALSE,
      '#weight' => 900,
      '#states' => [
        'visible' => [
          ':input[name="api_key"]' => ['!value' => ''],
        ],
      ],
      'introduction' => [
        '#markup' => '<p>' . $this->t('After saving a working xAI API key and default Grok model, connect Grok to the Drupal AI features that should use it:') . '</p>',
      ],
      'instructions' => [
        '#theme' => 'item_list',
        '#list_type' => 'ol',
        '#items' => $next_steps_items,
      ],
    ];

    $module_info = $module_list->getExtensionInfo('grok_ai_provider');
    $version = (string) ($module_info['version'] ?? '1.0.0-alpha1');
    $form['about'] = [
      '#type' => 'details',
      '#title' => $this->t('About'),
      '#open' => FALSE,
      '#attributes' => [
        'class' => ['grok-ai-provider-about'],
      ],
      '#weight' => 1000,
      'built_by' => [
        '#markup' => '<p><strong>' . $this->t('Built By Crocodiles 🐊') . '</strong></p>',
      ],
      'version' => [
        '#markup' => '<p class="grok-ai-provider-about__supporting-text">' . $this->t('Version: @version', ['@version' => $version]) . '</p>',
      ],
      'framework' => [
        '#markup' => '<p class="grok-ai-provider-about__supporting-text">' . $this->t('A part of the <a href=":url" title="@title" target="_blank" rel="noopener noreferrer">Torneoz</a> framework', [
          ':url' => 'https://torneoz.org',
          '@title' => $this->t('Torneoz is a global management AI system for Drupal.'),
        ]) . '</p>',
      ],
      'disclaimer' => [
        '#markup' => '<p class="grok-ai-provider-about__supporting-text">' . $this->t('This project is not affiliated with, funded, or endorsed by SpaceXAi or its subsidiaries. Copyright on all assets is retained by the owners.') . '</p>',
      ],
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    parent::validateForm($form, $form_state);
    if (($form_state->getTriggeringElement()['#name'] ?? '') === 'test_connection') {
      return;
    }
    $host = rtrim((string) $form_state->getValue('host'), '/');
    if (parse_url($host, PHP_URL_SCHEME) !== 'https') {
      $form_state->setErrorByName('host', $this->t('The API base URL must use HTTPS to protect the API key.'));
    }
    if (parse_url($host, PHP_URL_HOST) === NULL || parse_url($host, PHP_URL_USER) !== NULL || parse_url($host, PHP_URL_PASS) !== NULL || parse_url($host, PHP_URL_QUERY) !== NULL || parse_url($host, PHP_URL_FRAGMENT) !== NULL) {
      $form_state->setErrorByName('host', $this->t('Enter an HTTPS API base URL without credentials, a query, or a fragment.'));
    }
    if (!in_array($form_state->getValue('transport'), ['auto', 'chat_completions', 'responses'], TRUE)) {
      $form_state->setErrorByName('transport', $this->t('Select a valid transport.'));
    }
    try {
      $form_state->setValue('mcp_servers', $this->parseMcpServers((string) $form_state->getValue('mcp_servers')));
    }
    catch (\InvalidArgumentException $exception) {
      $form_state->setErrorByName('mcp_servers', $exception->getMessage());
    }
    if ($form_state->hasAnyErrors()) {
      return;
    }

    try {
      $models = $this->discoverModels(
        (string) $form_state->getValue('api_key'),
        $host,
      );
      $default_model = (string) $form_state->getValue('default_model');
      if (!isset($models[$default_model])) {
        $form_state->setErrorByName('default_model', $this->t('Select a model available to the current API key. Test the connection to refresh the model list.'));
      }
      $form_state->set('grok_models', $models);
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
      ->set('default_model', $form_state->getValue('default_model'))
      ->set('transport', $form_state->getValue('transport'))
      ->set('request_timeout', max(10, min(3600, (int) $form_state->getValue('request_timeout'))))
      ->set('store_responses', (bool) $form_state->getValue('store_responses'))
      ->set('hosted_tools', array_map('boolval', (array) $form_state->getValue('hosted_tools')))
      ->set('mcp_servers', (array) $form_state->getValue('mcp_servers'))
      ->save();

    $this->getAiProviderManager()->defaultIfNone('chat', 'grok', (string) $form_state->getValue('default_model'));
    parent::submitForm($form, $form_state);
  }

  /**
   * Tests the credentials and rebuilds the available default-model options.
   */
  public function testConnection(array &$form, FormStateInterface $form_state): void {
    try {
      $models = $this->discoverModels(
        (string) $form_state->getValue('api_key'),
        rtrim((string) $form_state->getValue('host'), '/'),
      );
      $preferred = $this->preferredModel($models);
      $form_state->set('grok_models', $models);
      $form_state->setValue('default_model', $preferred);
      $form_state->set('grok_connection_status', [
        'type' => 'status',
        'message' => (string) $this->formatPlural(
          count($models),
          'Connection successful. One Grok model is available.',
          'Connection successful. @count Grok models are available.',
        ),
      ]);
    }
    catch (\Throwable $exception) {
      $form_state->set('grok_models', NULL);
      $form_state->set('grok_connection_status', [
        'type' => 'error',
        'message' => (string) $this->t('Connection failed: @message', ['@message' => $exception->getMessage()]),
      ]);
    }
    $form_state->setRebuild();
  }

  /**
   * Returns the AJAX-rebuilt connection controls.
   */
  public function connectionAjax(array &$form, FormStateInterface $form_state): array {
    return $form['connection'];
  }

  /**
   * Discovers chat models using unsaved key and endpoint form values.
   */
  private function discoverModels(string $key_id, string $host): array {
    if (
      parse_url($host, PHP_URL_SCHEME) !== 'https' ||
      parse_url($host, PHP_URL_HOST) === NULL ||
      parse_url($host, PHP_URL_USER) !== NULL ||
      parse_url($host, PHP_URL_PASS) !== NULL ||
      parse_url($host, PHP_URL_QUERY) !== NULL ||
      parse_url($host, PHP_URL_FRAGMENT) !== NULL
    ) {
      throw new \InvalidArgumentException((string) $this->t('Enter a valid HTTPS API base URL.'));
    }
    $key = $this->getKeyRepository()->getKey($key_id);
    $api_key = $key?->getKeyValue();
    if (!$api_key) {
      throw new \InvalidArgumentException((string) $this->t('The selected Key does not contain an API key.'));
    }

    /** @var \Drupal\grok_ai_provider\Plugin\AiProvider\GrokAiProvider $provider */
    $provider = $this->getAiProviderManager()->createInstance('grok');
    $provider->setAuthentication($api_key);
    $provider->setConfiguration(['host' => $host]);
    $models = $provider->getConfiguredModels('chat');
    if ($models === []) {
      throw new \RuntimeException((string) $this->t('xAI did not return any accessible Grok models for this key.'));
    }
    return $models;
  }

  /**
   * Selects the best available default without assuming an alias exists.
   */
  private function preferredModel(array $models): string {
    foreach (['grok-4.5-latest', 'grok-4.5'] as $preferred) {
      if (isset($models[$preferred])) {
        return $preferred;
      }
    }
    return (string) array_key_first($models);
  }

  /**
   * Gets the provider manager after normal or cached form reconstruction.
   */
  private function getAiProviderManager(): AiProviderPluginManager {
    if (!$this->aiProviderManager instanceof AiProviderPluginManager) {
      $this->aiProviderManager = \Drupal::service('ai.provider');
    }
    return $this->aiProviderManager;
  }

  /**
   * Gets the Key repository after normal or cached form reconstruction.
   */
  private function getKeyRepository(): KeyRepositoryInterface {
    if (!$this->keyRepository instanceof KeyRepositoryInterface) {
      $this->keyRepository = \Drupal::service('key.repository');
    }
    return $this->keyRepository;
  }

  /**
   * Parses the line-oriented MCP allowlist field.
   */
  private function parseMcpServers(string $value): array {
    $servers = [];
    $labels = [];
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
      if (parse_url($parts[1], PHP_URL_USER) !== NULL || parse_url($parts[1], PHP_URL_PASS) !== NULL || parse_url($parts[1], PHP_URL_FRAGMENT) !== NULL) {
        throw new \InvalidArgumentException((string) $this->t('MCP server URLs cannot contain credentials or fragments (line @line).', ['@line' => $line_number + 1]));
      }
      if (!preg_match('/^[a-zA-Z0-9_-]+$/', $parts[0])) {
        throw new \InvalidArgumentException((string) $this->t('MCP server labels may contain only letters, numbers, underscores, and hyphens (line @line).', ['@line' => $line_number + 1]));
      }
      if (isset($labels[$parts[0]])) {
        throw new \InvalidArgumentException((string) $this->t('MCP server label @label is duplicated.', ['@label' => $parts[0]]));
      }
      $allowed_tools = array_values(array_unique(array_filter(array_map('trim', explode(',', $parts[2])))));
      if ($allowed_tools === []) {
        throw new \InvalidArgumentException((string) $this->t('MCP allowlist line @line must name at least one allowed tool.', ['@line' => $line_number + 1]));
      }
      foreach ($allowed_tools as $tool) {
        if (!preg_match('/^[a-zA-Z0-9_.-]+$/', $tool)) {
          throw new \InvalidArgumentException((string) $this->t('MCP tool name @tool contains unsupported characters.', ['@tool' => $tool]));
        }
      }
      $labels[$parts[0]] = TRUE;
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
