<?php

declare(strict_types=1);

namespace Drupal\grok_ai_provider\Plugin\AiProvider;

use Drupal\Component\Serialization\Json;
use Drupal\Component\Utility\Crypt;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\ai\Attribute\AiProvider;
use Drupal\ai\Base\OpenAiBasedProviderClientBase;
use Drupal\ai\Dto\TokenUsageDto;
use Drupal\ai\Enum\AiModelCapability;
use Drupal\ai\Exception\AiBadRequestException;
use Drupal\ai\Exception\AiMissingFeatureException;
use Drupal\ai\Exception\AiResponseErrorException;
use Drupal\ai\Exception\AiSetupFailureException;
use Drupal\ai\OperationType\Chat\ChatInput;
use Drupal\ai\OperationType\Chat\ChatMessage;
use Drupal\ai\OperationType\Chat\ChatOutput;
use Drupal\ai\OperationType\GenericType\ImageFile;
use Drupal\grok_ai_provider\Service\XaiResponsesClient;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides xAI Grok models through the OpenAI-compatible API.
 */
#[AiProvider(
  id: 'grok',
  label: new TranslatableMarkup('Grok (xAI)'),
)]
final class GrokAiProvider extends OpenAiBasedProviderClientBase {

  /**
   * The default xAI API base URL.
   */
  public const DEFAULT_ENDPOINT = 'https://api.x.ai/v1';

  /**
   * An endpoint supplied at runtime, such as during settings validation.
   */
  private string $configuredEndpoint = '';

  /**
   * Provider-only settings removed before an API payload is assembled.
   */
  private array $providerOptions = [];

  /**
   * The xAI Responses transport.
   */
  private XaiResponsesClient $responsesClient;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    $instance = parent::create($container, $configuration, $plugin_id, $plugin_definition);
    $instance->responsesClient = $container->get('grok_ai_provider.responses_client');
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  public function getConfiguredModels(?string $operation_type = NULL, array $capabilities = []): array {
    if ($operation_type !== NULL && $operation_type !== 'chat') {
      return [];
    }

    $this->loadClient();
    $cache_context = [$this->getEndpoint(), $this->apiKey, $capabilities];
    $cache_key = 'grok_models_' . Crypt::hashBase64(Json::encode($cache_context));
    if ($cached = $this->cacheBackend->get($cache_key)) {
      return $cached->data;
    }

    try {
      $response = $this->client->models()->list()->toArray();
    }
    catch (\Throwable $exception) {
      throw new AiResponseErrorException('Unable to retrieve models from xAI: ' . $exception->getMessage(), $exception->getCode(), $exception);
    }

    if (!isset($response['data']) || !is_array($response['data'])) {
      throw new AiResponseErrorException('xAI returned an invalid model-list payload.');
    }
    $models = $this->filterModels($response['data'], $capabilities);
    asort($models);
    // Model access and aliases can change; avoid a permanent discovery cache.
    $this->cacheBackend->set($cache_key, $models, time() + ($models === [] ? 300 : 3600));

    return $models;
  }

  /**
   * {@inheritdoc}
   */
  public function getSupportedOperationTypes(): array {
    return ['chat'];
  }

  /**
   * {@inheritdoc}
   */
  public function getModelSettings(string $model_id, array $generalConfig = []): array {
    if (!preg_match('/^grok-4/i', $model_id)) {
      foreach ([
        'web_search',
        'web_allowed_domains',
        'web_excluded_domains',
        'web_image_search',
        'web_image_understanding',
        'x_search',
        'x_allowed_handles',
        'x_excluded_handles',
        'x_from_date',
        'x_to_date',
        'x_image_understanding',
        'x_video_understanding',
        'code_interpreter',
        'file_search',
        'collection_ids',
        'file_search_max_results',
        'mcp_servers',
      ] as $hosted_setting) {
        unset($generalConfig[$hosted_setting]);
      }
    }
    if ($this->isReasoningModel($model_id)) {
      // xAI rejects these sampling controls for Grok 4.5 reasoning models.
      unset($generalConfig['frequency_penalty'], $generalConfig['presence_penalty']);
      $generalConfig['reasoning_effort'] = [
        'type' => 'select',
        'label' => 'Reasoning effort',
        'description' => 'Controls how much reasoning effort the model uses.',
        'default' => 'medium',
        'required' => FALSE,
        'constraints' => [
          'options' => ['low', 'medium', 'high'],
        ],
      ];
    }

    return $generalConfig;
  }

  /**
   * {@inheritdoc}
   */
  public function getSetupData(): array {
    $default_model = (string) ($this->getConfig()->get('default_model') ?: 'grok-4.5-latest');
    return [
      'key_config_name' => 'api_key',
      'default_models' => [
        'chat' => $default_model,
        'chat_with_image_vision' => $default_model,
        'chat_with_complex_json' => $default_model,
        'chat_with_tools' => $default_model,
        'chat_with_structured_response' => $default_model,
      ],
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function setConfiguration(array $configuration): void {
    if (isset($configuration['host'])) {
      $this->configuredEndpoint = rtrim(trim((string) $configuration['host']), '/');
      unset($configuration['host']);
      $this->client = NULL;
    }
    $provider_keys = [
      'use_responses_api',
      'web_search',
      'web_allowed_domains',
      'web_excluded_domains',
      'web_image_search',
      'web_image_understanding',
      'x_search',
      'x_allowed_handles',
      'x_excluded_handles',
      'x_from_date',
      'x_to_date',
      'x_image_understanding',
      'x_video_understanding',
      'code_interpreter',
      'file_search',
      'collection_ids',
      'file_search_max_results',
      'mcp_servers',
    ];
    $this->providerOptions = array_intersect_key($configuration, array_flip($provider_keys));
    $configuration = array_diff_key($configuration, array_flip($provider_keys));
    parent::setConfiguration($configuration);
  }

  /**
   * {@inheritdoc}
   */
  public function chat(array|string|ChatInput $input, string $model_id, array $tags = []): ChatOutput {
    if (!$this->shouldUseResponses($input)) {
      return parent::chat($input, $model_id, $tags);
    }
    if ($this->streamed || ($input instanceof ChatInput && $input->isStreamedOutput())) {
      // Preserve Drupal AI streaming for ordinary chat until the Responses
      // SSE iterator is implemented. Hosted tools cannot be silently dropped.
      if (!$this->hasRequestedHostedTools()) {
        return parent::chat($input, $model_id, $tags);
      }
      throw new AiMissingFeatureException('Streaming xAI Responses requests are not yet supported. Disable streaming or use Chat Completions.');
    }

    $this->loadClient();
    $payload = $this->buildResponsesPayload($input, $model_id);
    $timeout = max(10, min(3600, (int) ($this->getConfig()->get('request_timeout') ?: 300)));
    $response = $this->responsesClient->create(
      $this->getEndpoint() ?: self::DEFAULT_ENDPOINT,
      $this->apiKey ?: $this->loadApiKey(),
      $payload,
      $timeout,
    );
    return $this->normalizeResponsesOutput($response);
  }

  /**
   * Initializes the OpenAI-compatible client with the xAI endpoint.
   */
  protected function loadClient(): void {
    $host = $this->configuredEndpoint ?: trim((string) $this->getConfig()->get('host'));
    $this->setEndpoint(rtrim($host ?: self::DEFAULT_ENDPOINT, '/'));

    try {
      parent::loadClient();
    }
    catch (AiSetupFailureException $exception) {
      throw new AiSetupFailureException('Failed to initialize the xAI client: ' . $exception->getMessage(), $exception->getCode(), $exception);
    }
  }

  /**
   * Filters model discovery results for a requested Drupal AI capability.
   */
  private function filterModels(array $model_data, array $capabilities): array {
    foreach ($capabilities as $capability) {
      if (in_array($capability, [
        AiModelCapability::ChatWithAudio,
        AiModelCapability::ChatWithVideo,
        AiModelCapability::ChatWithPdf,
      ], TRUE)) {
        return [];
      }
    }

    $models = [];
    foreach ($model_data as $model) {
      $model_id = trim((string) ($model['id'] ?? ''));
      if (!preg_match('/^grok(?:-|$)/i', $model_id)) {
        continue;
      }

      if (in_array(AiModelCapability::ChatWithImageVision, $capabilities, TRUE) && !$this->supportsVision($model_id)) {
        continue;
      }
      if (in_array(AiModelCapability::ChatCombinedToolsAndStructuredResponse, $capabilities, TRUE) && !$this->supportsCombinedStructuredTools($model_id)) {
        continue;
      }
      if ((in_array(AiModelCapability::ChatStructuredResponse, $capabilities, TRUE) || in_array(AiModelCapability::ChatJsonOutput, $capabilities, TRUE)) && !$this->supportsStructuredOutput($model_id)) {
        continue;
      }

      $models[$model_id] = $model_id;
    }

    return $models;
  }

  /**
   * Determines whether a Grok model accepts image input.
   */
  private function supportsVision(string $model_id): bool {
    return (bool) preg_match('/^grok-(?:2-vision|4|build)/i', $model_id);
  }

  /**
   * Determines whether a Grok model supports structured output.
   */
  private function supportsStructuredOutput(string $model_id): bool {
    return (bool) preg_match('/^grok-(?:3|4|build)/i', $model_id);
  }

  /**
   * Determines whether tools and structured output can be combined.
   */
  private function supportsCombinedStructuredTools(string $model_id): bool {
    return (bool) preg_match('/^grok-4/i', $model_id);
  }

  /**
   * Determines whether a model supports configurable reasoning effort.
   */
  private function isReasoningModel(string $model_id): bool {
    return (bool) preg_match('/^grok-4\.5(?:-|$)/i', $model_id);
  }

  /**
   * Selects Responses only when it can preserve the Drupal tool contract.
   */
  private function shouldUseResponses(array|string|ChatInput $input): bool {
    // Drupal function tools use the established Chat Completions tool loop.
    if ($input instanceof ChatInput && $input->getChatTools()) {
      return FALSE;
    }
    $transport = (string) ($this->getConfig()->get('transport') ?: 'auto');
    if ($transport === 'chat_completions') {
      if ($this->hasRequestedHostedTools()) {
        throw new AiMissingFeatureException('Hosted xAI tools require the Responses API, but this provider is configured for Chat Completions only.');
      }
      return FALSE;
    }
    return $transport === 'responses' || !empty($this->providerOptions['use_responses_api']) || $this->hasRequestedHostedTools();
  }

  /**
   * Determines whether a request selected any hosted tool.
   */
  private function hasRequestedHostedTools(): bool {
    foreach (['web_search', 'x_search', 'code_interpreter', 'file_search'] as $tool) {
      if (!empty($this->providerOptions[$tool])) {
        return TRUE;
      }
    }
    return $this->csvValues((string) ($this->providerOptions['mcp_servers'] ?? '')) !== [];
  }

  /**
   * Builds an xAI Responses API payload from Drupal chat input.
   */
  private function buildResponsesPayload(array|string|ChatInput $input, string $model_id): array {
    $payload = [
      'model' => $model_id,
      'input' => $this->buildResponsesInput($input),
      'store' => (bool) $this->getConfig()->get('store_responses'),
    ];
    $configuration = $this->configuration;
    // xAI's Responses API does not support Chat Completions penalties.
    unset($configuration['frequency_penalty'], $configuration['presence_penalty']);
    if (isset($configuration['max_tokens'])) {
      $configuration['max_output_tokens'] = $configuration['max_tokens'];
      unset($configuration['max_tokens']);
    }
    if (isset($configuration['reasoning_effort'])) {
      $payload['reasoning'] = ['effort' => $configuration['reasoning_effort']];
      unset($configuration['reasoning_effort']);
    }
    $payload += $configuration;

    $tools = $this->buildHostedTools();
    if ($tools !== []) {
      $payload['tools'] = $tools;
      $tool_types = array_column($tools, 'type');
      $include = [];
      if (in_array('web_search', $tool_types, TRUE)) {
        $include[] = 'web_search_call.action.sources';
      }
      if (in_array('code_interpreter', $tool_types, TRUE)) {
        $include[] = 'code_interpreter_call.outputs';
      }
      if (in_array('file_search', $tool_types, TRUE)) {
        $include[] = 'file_search_call.results';
      }
      if ($include !== []) {
        $payload['include'] = $include;
      }
    }
    if ($input instanceof ChatInput && ($schema = $input->getChatStructuredJsonSchema())) {
      $payload['text']['format'] = [
        'type' => 'json_schema',
        'name' => $schema['name'] ?? 'json_schema',
        'schema' => $schema['schema'] ?? [],
        'strict' => (bool) ($schema['strict'] ?? FALSE),
      ];
      if (!empty($schema['description'])) {
        $payload['text']['format']['description'] = $schema['description'];
      }
    }
    return $payload;
  }

  /**
   * Converts Drupal messages into Responses input messages.
   */
  private function buildResponsesInput(array|string|ChatInput $input): array {
    if (is_string($input)) {
      if (trim($input) === '') {
        throw new AiBadRequestException('A non-empty chat prompt is required.');
      }
      return [['role' => 'user', 'content' => $input]];
    }
    if (is_array($input)) {
      if ($input === []) {
        throw new AiBadRequestException('At least one input message is required.');
      }
      return $input;
    }

    $messages = [];
    if ($input->getSystemPrompt() !== '') {
      $messages[] = ['role' => 'system', 'content' => $input->getSystemPrompt()];
    }
    foreach ($input->getMessages() as $message) {
      if (!in_array($message->getRole(), ['system', 'developer', 'user', 'assistant'], TRUE)) {
        throw new AiBadRequestException(sprintf('The message role "%s" is not supported by xAI Responses.', $message->getRole()));
      }
      $content = [];
      if ($message->getText() !== '') {
        $content[] = ['type' => 'input_text', 'text' => $message->getText()];
      }
      foreach ($message->getFiles() as $file) {
        if ($file instanceof ImageFile) {
          $content[] = ['type' => 'input_image', 'image_url' => $file->getAsBase64EncodedString()];
        }
        else {
          throw new AiMissingFeatureException(sprintf('Responses input does not yet support the file type "%s".', $file->getMimeType()));
        }
      }
      if ($content === []) {
        throw new AiBadRequestException('Each chat message must contain text or a supported image.');
      }
      $messages[] = [
        'role' => $message->getRole(),
        'content' => count($content) === 1 && ($content[0]['type'] ?? '') === 'input_text' ? $message->getText() : $content,
      ];
    }
    return $messages;
  }

  /**
   * Builds allowlisted xAI hosted tool definitions.
   */
  private function buildHostedTools(): array {
    $permissions = (array) $this->getConfig()->get('hosted_tools');
    $tools = [];
    foreach (['web_search', 'x_search', 'code_interpreter', 'file_search'] as $tool_name) {
      if (!empty($this->providerOptions[$tool_name]) && empty($permissions[$tool_name])) {
        throw new AiMissingFeatureException(sprintf('The xAI hosted tool "%s" is not permitted in the Grok provider settings.', $tool_name));
      }
    }
    if (!empty($this->providerOptions['web_search']) && !empty($permissions['web_search'])) {
      $tool = ['type' => 'web_search'];
      $raw_allowed = $this->csvValues((string) ($this->providerOptions['web_allowed_domains'] ?? ''));
      $raw_excluded = $this->csvValues((string) ($this->providerOptions['web_excluded_domains'] ?? ''));
      if ($raw_allowed !== [] && $raw_excluded !== []) {
        throw new AiBadRequestException('Web Search allowed and excluded domains cannot be combined.');
      }
      if (count($raw_allowed) > 5 || count($raw_excluded) > 5) {
        throw new AiBadRequestException('Web Search accepts no more than five allowed or excluded domains.');
      }
      foreach (array_merge($raw_allowed, $raw_excluded) as $domain) {
        if (!$this->isValidDomain($domain)) {
          throw new AiBadRequestException(sprintf('"%s" is not a valid Web Search domain.', $domain));
        }
      }
      $allowed = $raw_allowed;
      $excluded = $raw_excluded;
      if ($allowed !== []) {
        $tool['filters']['allowed_domains'] = $allowed;
      }
      elseif ($excluded !== []) {
        $tool['filters']['excluded_domains'] = $excluded;
      }
      $tool['enable_image_search'] = !empty($this->providerOptions['web_image_search']);
      $tool['enable_image_understanding'] = !empty($this->providerOptions['web_image_understanding']);
      $tools[] = $tool;
    }
    if (!empty($this->providerOptions['x_search']) && !empty($permissions['x_search'])) {
      $tool = ['type' => 'x_search'];
      $allowed = $this->normalizeXHandles((string) ($this->providerOptions['x_allowed_handles'] ?? ''));
      $excluded = $this->normalizeXHandles((string) ($this->providerOptions['x_excluded_handles'] ?? ''));
      if ($allowed !== [] && $excluded !== []) {
        throw new AiBadRequestException('X Search allowed and excluded handles cannot be combined.');
      }
      if (count($allowed) > 20 || count($excluded) > 20) {
        throw new AiBadRequestException('X Search accepts no more than twenty allowed or excluded handles.');
      }
      if ($allowed !== []) {
        $tool['allowed_x_handles'] = array_slice($allowed, 0, 20);
      }
      elseif ($excluded !== []) {
        $tool['excluded_x_handles'] = array_slice($excluded, 0, 20);
      }
      foreach (['from_date' => 'x_from_date', 'to_date' => 'x_to_date'] as $target => $source) {
        if (!empty($this->providerOptions[$source])) {
          $date = (string) $this->providerOptions[$source];
          if (!$this->isIsoDate($date)) {
            throw new AiBadRequestException(sprintf('The X Search %s value must use YYYY-MM-DD format.', $target));
          }
          $tool[$target] = $date;
        }
      }
      if (isset($tool['from_date'], $tool['to_date']) && $tool['from_date'] > $tool['to_date']) {
        throw new AiBadRequestException('X Search start date cannot be later than its end date.');
      }
      $tool['enable_image_understanding'] = !empty($this->providerOptions['x_image_understanding']);
      $tool['enable_video_understanding'] = !empty($this->providerOptions['x_video_understanding']);
      $tools[] = $tool;
    }
    if (!empty($this->providerOptions['code_interpreter']) && !empty($permissions['code_interpreter'])) {
      $tools[] = ['type' => 'code_interpreter'];
    }
    if (!empty($this->providerOptions['file_search']) && !empty($permissions['file_search'])) {
      $collection_ids = $this->csvValues((string) ($this->providerOptions['collection_ids'] ?? ''));
      if ($collection_ids === []) {
        throw new AiMissingFeatureException('Collections Search requires at least one xAI collection ID.');
      }
      foreach ($collection_ids as $collection_id) {
        if (!preg_match('/^collection_[a-zA-Z0-9-]+$/', $collection_id)) {
          throw new AiBadRequestException(sprintf('"%s" is not a valid xAI collection ID.', $collection_id));
        }
      }
      $tools[] = [
        'type' => 'file_search',
        'vector_store_ids' => $collection_ids,
        'max_num_results' => max(1, min(50, (int) ($this->providerOptions['file_search_max_results'] ?? 10))),
      ];
    }
    if (!empty($permissions['mcp'])) {
      $requested = $this->csvValues((string) ($this->providerOptions['mcp_servers'] ?? ''));
      $matched = [];
      foreach ((array) $this->getConfig()->get('mcp_servers') as $server) {
        if (in_array($server['label'] ?? '', $requested, TRUE) && !empty($server['allowed_tools'])) {
          $matched[] = $server['label'];
          $tools[] = [
            'type' => 'mcp',
            'server_label' => $server['label'],
            'server_url' => $server['url'],
            'allowed_tools' => array_values($server['allowed_tools']),
          ];
        }
      }
      $missing = array_diff($requested, $matched);
      if ($missing !== []) {
        throw new AiMissingFeatureException('The following MCP servers are not allowlisted: ' . implode(', ', $missing));
      }
    }
    elseif ($this->csvValues((string) ($this->providerOptions['mcp_servers'] ?? '')) !== []) {
      throw new AiMissingFeatureException('Remote MCP tools are not permitted in the Grok provider settings.');
    }
    return $tools;
  }

  /**
   * Normalizes Responses output into Drupal AI's standard chat output.
   */
  private function normalizeResponsesOutput(array $response): ChatOutput {
    if (!empty($response['error'])) {
      $error = is_array($response['error']) ? ($response['error']['message'] ?? Json::encode($response['error'])) : (string) $response['error'];
      throw new AiResponseErrorException('xAI Responses failed: ' . $error);
    }
    $status = (string) ($response['status'] ?? '');
    if ($status !== '' && $status !== 'completed') {
      $reason = $response['incomplete_details']['reason'] ?? $status;
      throw new AiResponseErrorException(sprintf('xAI Responses finished with status "%s" (%s).', $status, $reason));
    }
    $text = '';
    $annotations = [];
    $citations = is_array($response['citations'] ?? NULL) ? $response['citations'] : [];
    $refusals = [];
    $tool_usage = [];
    foreach ($response['output'] ?? [] as $item) {
      if (($item['type'] ?? '') !== 'message') {
        $tool_usage[] = $item;
        continue;
      }
      foreach ($item['content'] ?? [] as $content) {
        if (($content['type'] ?? '') === 'output_text') {
          $text .= (string) ($content['text'] ?? '');
          $annotations = array_merge($annotations, $content['annotations'] ?? []);
        }
        elseif (($content['type'] ?? '') === 'refusal') {
          $refusals[] = (string) ($content['refusal'] ?? $content['text'] ?? 'The request was refused.');
        }
      }
    }
    if ($text === '') {
      if ($refusals !== []) {
        throw new AiResponseErrorException('xAI refused the request: ' . implode(' ', $refusals));
      }
      throw new AiResponseErrorException('xAI Responses did not return output text.');
    }
    foreach ($annotations as $annotation) {
      if (is_array($annotation) && !empty($annotation['url'])) {
        $citations[] = $annotation['url'];
      }
    }
    $citations = array_values(array_unique(array_filter($citations, 'is_string')));

    $usage = (array) ($response['usage'] ?? []);
    $token_usage = new TokenUsageDto(
      input: isset($usage['input_tokens']) ? (int) $usage['input_tokens'] : NULL,
      output: isset($usage['output_tokens']) ? (int) $usage['output_tokens'] : NULL,
      total: isset($usage['total_tokens']) ? (int) $usage['total_tokens'] : NULL,
      reasoning: isset($usage['output_tokens_details']['reasoning_tokens']) ? (int) $usage['output_tokens_details']['reasoning_tokens'] : NULL,
      cached: isset($usage['input_tokens_details']['cached_tokens']) ? (int) $usage['input_tokens_details']['cached_tokens'] : NULL,
    );
    $metadata = [
      'transport' => 'responses',
      'response_id' => $response['id'] ?? NULL,
      'status' => $response['status'] ?? NULL,
      'annotations' => $annotations,
      'citations' => $citations,
      'tool_usage' => $tool_usage,
    ];
    return new ChatOutput(new ChatMessage('assistant', $text), $response, $metadata, $token_usage);
  }

  /**
   * Converts a comma-separated option into unique non-empty strings.
   */
  private function csvValues(string $value): array {
    return array_values(array_unique(array_filter(array_map('trim', explode(',', $value)))));
  }

  /**
   * Normalizes comma-separated X handles without leading @ characters.
   */
  private function normalizeXHandles(string $value): array {
    $handles = array_values(array_filter(array_map(
      static fn(string $handle): string => ltrim($handle, '@'),
      $this->csvValues($value),
    )));
    foreach ($handles as $handle) {
      if (!preg_match('/^[a-zA-Z0-9_]{1,15}$/', $handle)) {
        throw new AiBadRequestException(sprintf('"%s" is not a valid X handle.', $handle));
      }
    }
    return $handles;
  }

  /**
   * Validates an ISO calendar date without accepting normalized overflows.
   */
  private function isIsoDate(string $value): bool {
    $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    return $date !== FALSE && $date->format('Y-m-d') === $value;
  }

  /**
   * Validates a bare hostname accepted by xAI's web-search filters.
   */
  private function isValidDomain(string $domain): bool {
    if ($domain === '' || str_contains($domain, '://') || str_contains($domain, '/')) {
      return FALSE;
    }
    return filter_var($domain, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) !== FALSE;
  }

}
