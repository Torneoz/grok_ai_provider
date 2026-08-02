<?php

declare(strict_types=1);

namespace Drupal\grok\Form;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\TypedConfigManagerInterface;
use Drupal\Core\Extension\ExtensionList;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Link;
use Drupal\Core\Url;
use Drupal\ai\AiProviderPluginManager;
use Drupal\grok\Service\GrokCostEstimator;
use Drupal\grok\Service\XaiPricingScheduleFetcher;
use Drupal\key\KeyRepositoryInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Configures xAI API access.
 */
final class GrokAiProviderConfigForm extends ConfigFormBase {

  private const CONFIG_NAME = 'grok.settings';

  /**
   * The AI provider plugin manager.
   */
  private ?AiProviderPluginManager $aiProviderManager = NULL;

  /**
   * The Key repository.
   */
  private ?KeyRepositoryInterface $keyRepository = NULL;

  /**
   * The fallback cost estimator.
   */
  private ?GrokCostEstimator $costEstimator = NULL;

  /**
   * The remote pricing schedule fetcher.
   */
  private ?XaiPricingScheduleFetcher $pricingScheduleFetcher = NULL;

  /**
   * The module extension list.
   */
  private ?ExtensionList $moduleList = NULL;

  /**
   * The module handler.
   */
  private ?ModuleHandlerInterface $moduleHandler = NULL;

  /**
   * Constructs the configuration form.
   */
  public function __construct(
    ConfigFactoryInterface $config_factory,
    TypedConfigManagerInterface $typed_config_manager,
    AiProviderPluginManager $ai_provider_manager,
    KeyRepositoryInterface $key_repository,
    GrokCostEstimator $cost_estimator,
    XaiPricingScheduleFetcher $pricing_schedule_fetcher,
    ExtensionList $module_list,
    ModuleHandlerInterface $module_handler,
  ) {
    parent::__construct($config_factory, $typed_config_manager);
    $this->aiProviderManager = $ai_provider_manager;
    $this->keyRepository = $key_repository;
    $this->costEstimator = $cost_estimator;
    $this->pricingScheduleFetcher = $pricing_schedule_fetcher;
    $this->moduleList = $module_list;
    $this->moduleHandler = $module_handler;
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
      $container->get('grok.cost_estimator'),
      $container->get('grok.pricing_schedule_fetcher'),
      $container->get('extension.list.module'),
      $container->get('module_handler'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'grok_settings';
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
    $module_path = $this->getModuleList()->getPath('grok');

    $form['#attached']['library'][] = 'grok/config_form';
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
      '#weight' => -90,
    ];
    $form['advanced'] = [
      '#type' => 'details',
      '#title' => $this->t('Advanced settings'),
      '#description' => $this->t('These settings control how the provider connects to xAI and selects between the legacy Chat Completions transport and the newer Responses transport. The defaults are appropriate for most sites. Change them only when required by your hosting environment, privacy policy, or integration workflow.'),
      '#open' => FALSE,
      '#weight' => -70,
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

    $pricing_schedule = (array) $form_state->get('grok_pricing_schedule');
    $pricing_json = (string) ($pricing_schedule['json'] ?? $this->getCostEstimator()->getPricingJson());
    $pricing_source = (string) ($pricing_schedule['source'] ?? $config->get('pricing_source') ?: 'packaged');
    $pricing_checked_at = (string) ($pricing_schedule['checked_at'] ?? $config->get('pricing_checked_at') ?: '');
    $pricing_hash = (string) ($pricing_schedule['hash'] ?? $config->get('pricing_hash') ?: hash('sha256', $pricing_json));
    $form['cost_estimates'] = [
      '#type' => 'details',
      '#title' => $this->t('Cost estimates'),
      '#description' => $this->t('xAI-reported request costs are always preferred. This editable price list is used only when an API response does not include a cost.'),
      '#open' => $form_state->get('grok_pricing_status') !== NULL,
      '#attributes' => ['id' => 'grok-pricing-wrapper'],
      '#weight' => -60,
      '#states' => [
        'visible' => [
          ':input[name="api_key"]' => ['!value' => ''],
        ],
      ],
    ];
    $form['cost_estimates']['pricing_json'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Model pricing JSON'),
      '#default_value' => $pricing_json,
      '#rows' => 18,
      '#description' => $this->t('JSON array of pricing rows. Each row requires <code>model</code> and <code>type</code>. Supported types are <code>tokens</code>, <code>image</code>, <code>video</code>, <code>characters</code>, and <code>audio_hours</code>. An optional <code>operation</code> limits a row to one Drupal AI operation. Costs are USD estimates; update this data when xAI pricing changes.'),
      '#required' => TRUE,
    ];
    $form['cost_estimates']['pricing_source'] = [
      '#type' => 'hidden',
      '#default_value' => $pricing_source,
    ];
    $form['cost_estimates']['pricing_checked_at'] = [
      '#type' => 'hidden',
      '#default_value' => $pricing_checked_at,
    ];
    $form['cost_estimates']['pricing_hash'] = [
      '#type' => 'hidden',
      '#default_value' => $pricing_hash,
    ];
    $form['cost_estimates']['load_pricing'] = [
      '#type' => 'submit',
      '#name' => 'load_pricing',
      '#value' => $this->t('Load latest xAI pricing schedule'),
      '#submit' => ['::loadPricingSchedule'],
      '#limit_validation_errors' => [],
      '#attributes' => [
        'data-grok-confirm' => $this->t('Replace the pricing JSON currently shown with the latest module-maintained schedule? Unsaved edits in this field will be lost.'),
      ],
      '#ajax' => [
        'callback' => '::pricingAjax',
        'wrapper' => 'grok-pricing-wrapper',
        'progress' => ['type' => 'throbber'],
      ],
    ];
    $form['cost_estimates']['restore_pricing'] = [
      '#type' => 'submit',
      '#name' => 'restore_pricing',
      '#value' => $this->t('Restore packaged pricing'),
      '#submit' => ['::restorePackagedPricing'],
      '#limit_validation_errors' => [],
      '#attributes' => [
        'data-grok-confirm' => $this->t('Replace the pricing JSON currently shown with the schedule packaged with this installed module? Unsaved edits in this field will be lost.'),
      ],
      '#ajax' => [
        'callback' => '::pricingAjax',
        'wrapper' => 'grok-pricing-wrapper',
        'progress' => ['type' => 'throbber'],
      ],
    ];
    $form['cost_estimates']['update_help'] = [
      '#markup' => '<p class="description">' . $this->t('Loading or restoring a schedule only updates this form. Review the JSON, then use <strong>Save configuration</strong> to make it active.') . '</p>',
    ];
    if ($pricing_checked_at !== '') {
      $form['cost_estimates']['provenance'] = [
        '#markup' => '<p class="description">' . $this->t('Schedule source: @source. Retrieved: @time. SHA-256: @hash.', [
          '@source' => $pricing_source,
          '@time' => $pricing_checked_at,
          '@hash' => $pricing_hash,
        ]) . '</p>',
      ];
    }
    if ($status = $form_state->get('grok_pricing_status')) {
      $form['cost_estimates']['status'] = [
        '#type' => 'container',
        '#attributes' => [
          'class' => ['messages', $status['type'] === 'error' ? 'messages--error' : 'messages--status'],
        ],
        'message' => ['#plain_text' => $status['message']],
      ];
    }

    $prompt_defaults = \grok_explorer_prompt_defaults();
    $form['explorer_prompts'] = [
      '#type' => 'details',
      '#title' => $this->t('Explorer default prompts'),
      '#description' => $this->t('Customize the example prompts shown when Grok is selected in compatible Drupal AI Explorers. Existing user input is never replaced. Leave a prompt blank to disable its default.'),
      '#open' => FALSE,
      '#tree' => TRUE,
      '#weight' => -50,
      '#states' => [
        'visible' => [
          ':input[name="api_key"]' => ['!value' => ''],
        ],
      ],
    ];
    foreach ([
      'chat' => $this->t('Chat prompt'),
      'image_to_image' => $this->t('Image-to-image prompt'),
      'text_to_video' => $this->t('Text-to-video prompt'),
      'moderation' => $this->t('Moderation prompt'),
      'text_to_image' => $this->t('Text-to-image prompt'),
      'text_to_speech' => $this->t('Text-to-speech prompt'),
    ] as $key => $label) {
      $configured_prompt = $config->get('explorer_prompts.' . $key);
      $form['explorer_prompts'][$key] = [
        '#type' => 'textarea',
        '#title' => $label,
        '#default_value' => is_string($configured_prompt)
          ? $configured_prompt
          : (string) $prompt_defaults[$key],
        '#rows' => 3,
      ];
    }

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
      '#weight' => -80,
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
      '#weight' => -40,
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
      '#weight' => -30,
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
    if ($this->getModuleHandler()->moduleExists('ai_api_explorer')) {
      $next_steps_items[] = [
        'introduction' => [
          '#markup' => $this->t('Try the configured models in these Drupal AI Explorers:'),
        ],
        'links' => [
          '#theme' => 'item_list',
          '#items' => [
            Link::fromTextAndUrl($this->t('Chat Generation Explorer'), Url::fromRoute('ai_api_explorer.form.chat_generator'))->toRenderable(),
            Link::fromTextAndUrl($this->t('Text-To-Image Generation Explorer'), Url::fromRoute('ai_api_explorer.form.text_to_image_generator'))->toRenderable(),
            Link::fromTextAndUrl($this->t('Image-To-Image Explorer'), Url::fromRoute('ai_api_explorer.form.image_to_image_generator'))->toRenderable(),
            Link::fromTextAndUrl($this->t('Image-To-Video Generation Explorer'), Url::fromRoute('ai_api_explorer.form.grok_image_to_video_generator'))->toRenderable(),
            Link::fromTextAndUrl($this->t('Text-To-Video Generation Explorer'), Url::fromRoute('ai_api_explorer.form.grok_text_to_video_generator'))->toRenderable(),
            Link::fromTextAndUrl($this->t('Image Classification Explorer'), Url::fromRoute('ai_api_explorer.form.image_classification_generator'))->toRenderable(),
            Link::fromTextAndUrl($this->t('Moderation Explorer'), Url::fromRoute('ai_api_explorer.form.moderation_generator'))->toRenderable(),
            Link::fromTextAndUrl($this->t('Text-To-Speech Explorer'), Url::fromRoute('ai_api_explorer.form.text_to_speech_generator'))->toRenderable(),
            Link::fromTextAndUrl($this->t('Speech-To-Text Explorer'), Url::fromRoute('ai_api_explorer.form.speech_to_text_generator'))->toRenderable(),
          ],
        ],
      ];
      $next_steps_items[] = $this->t('Configure Grok as the default for Image Classification and Moderation. Moderation is a model-based assessment, not a dedicated xAI safety endpoint.');
      $next_steps_items[] = $this->t('Select an xAI voice for Text To Speech and xAI Speech to Text for Speech To Text.');
    }
    $next_steps_items[] = $this->t('To use Grok in CKEditor, enable the <strong>AI CKEditor integration</strong> module, then open <a href=":url">Text formats and editors</a>. Edit each CKEditor 5 text format that needs AI, add the AI button to its active toolbar, enable the required AI actions, and select <strong>Grok (xAI)</strong> with a Grok chat model for each action, or verify that the action uses the site-wide Chat default. Also grant the appropriate roles the <em>use ai ckeditor</em> permission.', [
      ':url' => Url::fromRoute('filter.admin_overview')->toString(),
    ]);
    if ($this->getModuleHandler()->moduleExists('ai_image_studio')) {
      $next_steps_items[] = $this->t('<a href=":url">Open AI Image Studio</a> to use Grok for iterative image generation and editing. AI Image Studio works with the Grok models configured for Drupal AI\'s Text To Image and Image To Image operations.', [
        ':url' => Url::fromRoute('ai_image_studio.new')->toString(),
      ]);
    }
    else {
      $next_steps_items[] = $this->t('Grok works very well with <a href=":url">AI Image Studio</a>, which provides an iterative workspace for generating and editing images. Install and enable AI Image Studio to use it with your configured Grok image models.', [
        ':url' => 'https://www.drupal.org/project/ai_image_studio',
      ]);
    }
    $next_steps_items[] = $this->t('Review each Drupal AI module or feature you enable. Configure it to use the site-wide Chat default, or select Grok explicitly when that feature provides its own provider and model settings.');
    $next_steps_items[] = $this->t('Grok supports Chat-based text, vision, function tools, structured output, text-to-image generation, image-to-image editing, image-to-video generation, text-to-video generation, text-to-speech, and speech-to-text. Operations such as embeddings require another provider that supports those operation types.');
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

    $module_info = $module_list->getExtensionInfo('grok');
    $version = (string) ($module_info['version'] ?? '1.0.0-beta1');
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
    if (in_array(
      $form_state->getTriggeringElement()['#name'] ?? '',
      ['test_connection', 'load_pricing', 'restore_pricing'],
      TRUE,
    )) {
      return;
    }
    try {
      $form_state->setValue(
        'pricing_json',
        $this->getCostEstimator()->normalizePricingJson((string) $form_state->getValue('pricing_json')),
      );
    }
    catch (\Throwable $exception) {
      $form_state->setErrorByName('pricing_json', $this->t('Enter valid model pricing JSON: @message', [
        '@message' => $exception->getMessage(),
      ]));
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

    $config = $this->config(self::CONFIG_NAME);
    $connection_changed = (string) $config->get('api_key') !== (string) $form_state->getValue('api_key')
      || rtrim((string) $config->get('host'), '/') !== $host
      || (string) $config->get('default_model') !== (string) $form_state->getValue('default_model');
    $tested_models = $form_state->get('grok_models');
    $connection_fingerprint = hash('sha256', implode("\0", [
      (string) $form_state->getValue('api_key'),
      $host,
    ]));
    $models_are_current = is_array($tested_models)
      && $tested_models !== []
      && hash_equals((string) $form_state->get('grok_connection_fingerprint'), $connection_fingerprint);
    if (!$connection_changed && !$models_are_current) {
      return;
    }

    try {
      $models = $models_are_current
        ? $tested_models
        : $this->discoverModels(
          (string) $form_state->getValue('api_key'),
          $host,
        );
      $default_model = (string) $form_state->getValue('default_model');
      if (!isset($models[$default_model])) {
        $form_state->setErrorByName('default_model', $this->t('Select a model available to the current API key. Test the connection to refresh the model list.'));
      }
      $form_state->set('grok_models', $models);
      $form_state->set('grok_connection_fingerprint', hash('sha256', implode("\0", [
        (string) $form_state->getValue('api_key'),
        rtrim((string) $form_state->getValue('host'), '/'),
      ])));
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
      ->set('pricing_json', (string) $form_state->getValue('pricing_json'))
      ->set('pricing_source', (string) $form_state->getValue('pricing_source'))
      ->set('pricing_checked_at', (string) $form_state->getValue('pricing_checked_at'))
      ->set('pricing_hash', hash('sha256', (string) $form_state->getValue('pricing_json')))
      ->set('explorer_prompts', array_map(
        static fn (mixed $value): string => trim((string) $value),
        (array) $form_state->getValue('explorer_prompts'),
      ))
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
      $form_state->set('grok_connection_fingerprint', hash('sha256', implode("\0", [
        (string) $form_state->getValue('api_key'),
        rtrim((string) $form_state->getValue('host'), '/'),
      ])));
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
   * Loads the latest trusted pricing schedule into the unsaved form.
   */
  public function loadPricingSchedule(array &$form, FormStateInterface $form_state): void {
    try {
      $schedule = $this->getPricingScheduleFetcher()->fetch();
      $this->applyPricingSchedule($form_state, $schedule);
      $form_state->set('grok_pricing_status', [
        'type' => 'status',
        'message' => (string) $this->formatPlural(
          $schedule['rows'],
          'Loaded one pricing row. Review the schedule and save the form to activate it.',
          'Loaded @count pricing rows. Review the schedule and save the form to activate it.',
        ),
      ]);
    }
    catch (\Throwable $exception) {
      $form_state->set('grok_pricing_status', [
        'type' => 'error',
        'message' => (string) $this->t('The latest pricing schedule could not be loaded: @message', [
          '@message' => $exception->getMessage(),
        ]),
      ]);
    }
    $form_state->setRebuild();
  }

  /**
   * Restores the installed module's pricing schedule into the unsaved form.
   */
  public function restorePackagedPricing(array &$form, FormStateInterface $form_state): void {
    try {
      $json = $this->getCostEstimator()->normalizePricingJson(
        $this->getCostEstimator()->getPackagedPricingJson(),
      );
      $rows = json_decode($json, TRUE, 512, JSON_THROW_ON_ERROR);
      $schedule = [
        'json' => $json,
        'source' => 'packaged',
        'checked_at' => gmdate(DATE_ATOM),
        'hash' => hash('sha256', $json),
        'rows' => count($rows),
      ];
      $this->applyPricingSchedule($form_state, $schedule);
      $form_state->set('grok_pricing_status', [
        'type' => 'status',
        'message' => (string) $this->formatPlural(
          $schedule['rows'],
          'Restored one packaged pricing row. Save the form to activate it.',
          'Restored @count packaged pricing rows. Save the form to activate them.',
        ),
      ]);
    }
    catch (\Throwable $exception) {
      $form_state->set('grok_pricing_status', [
        'type' => 'error',
        'message' => (string) $this->t('The packaged pricing schedule could not be restored: @message', [
          '@message' => $exception->getMessage(),
        ]),
      ]);
    }
    $form_state->setRebuild();
  }

  /**
   * Returns the AJAX-rebuilt cost-estimate controls.
   */
  public function pricingAjax(array &$form, FormStateInterface $form_state): array {
    return $form['cost_estimates'];
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

    /** @var \Drupal\grok\Plugin\AiProvider\GrokAiProvider $provider */
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
      // phpcs:ignore DrupalPractice.Objects.GlobalDrupal.GlobalDrupal -- Recovers a dependency after cached form reconstruction.
      $this->aiProviderManager = \Drupal::service('ai.provider');
    }
    return $this->aiProviderManager;
  }

  /**
   * Gets the Key repository after normal or cached form reconstruction.
   */
  private function getKeyRepository(): KeyRepositoryInterface {
    if (!$this->keyRepository instanceof KeyRepositoryInterface) {
      // phpcs:ignore DrupalPractice.Objects.GlobalDrupal.GlobalDrupal -- Recovers a dependency after cached form reconstruction.
      $this->keyRepository = \Drupal::service('key.repository');
    }
    return $this->keyRepository;
  }

  /**
   * Gets the cost estimator after normal or cached form reconstruction.
   */
  private function getCostEstimator(): GrokCostEstimator {
    if (!$this->costEstimator instanceof GrokCostEstimator) {
      // phpcs:ignore DrupalPractice.Objects.GlobalDrupal.GlobalDrupal -- Recovers a dependency after cached form reconstruction.
      $this->costEstimator = \Drupal::service('grok.cost_estimator');
    }
    return $this->costEstimator;
  }

  /**
   * Gets the pricing fetcher after normal or cached form reconstruction.
   */
  private function getPricingScheduleFetcher(): XaiPricingScheduleFetcher {
    if (!$this->pricingScheduleFetcher instanceof XaiPricingScheduleFetcher) {
      // phpcs:ignore DrupalPractice.Objects.GlobalDrupal.GlobalDrupal -- Recovers a dependency after cached form reconstruction.
      $this->pricingScheduleFetcher = \Drupal::service('grok.pricing_schedule_fetcher');
    }
    return $this->pricingScheduleFetcher;
  }

  /**
   * Gets the module extension list after cached form reconstruction.
   */
  private function getModuleList(): ExtensionList {
    if (!$this->moduleList instanceof ExtensionList) {
      // phpcs:ignore DrupalPractice.Objects.GlobalDrupal.GlobalDrupal -- Recovers a dependency after cached form reconstruction.
      $this->moduleList = \Drupal::service('extension.list.module');
    }
    return $this->moduleList;
  }

  /**
   * Gets the module handler after cached form reconstruction.
   */
  private function getModuleHandler(): ModuleHandlerInterface {
    if (!$this->moduleHandler instanceof ModuleHandlerInterface) {
      // phpcs:ignore DrupalPractice.Objects.GlobalDrupal.GlobalDrupal -- Recovers a dependency after cached form reconstruction.
      $this->moduleHandler = \Drupal::service('module_handler');
    }
    return $this->moduleHandler;
  }

  /**
   * Applies a pricing schedule to both form state and submitted input.
   */
  private function applyPricingSchedule(FormStateInterface $form_state, array $schedule): void {
    $form_state->set('grok_pricing_schedule', $schedule);
    $input = $form_state->getUserInput();
    foreach (['json', 'source', 'checked_at', 'hash'] as $key) {
      $element = $key === 'json' ? 'pricing_json' : 'pricing_' . $key;
      $input[$element] = $schedule[$key];
      $form_state->setValue($element, $schedule[$key]);
    }
    $form_state->setUserInput($input);
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
