<?php

declare(strict_types=1);

namespace Drupal\grok_ai_provider\EventSubscriber;

use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\ai\Event\PostGenerateResponseEvent;
use Drupal\ai\OperationType\Chat\ChatOutput;
use Drupal\ai\OperationType\OutputInterface;
use Drupal\grok_ai_provider\Service\GrokCostEstimator;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Collects the latest Grok Explorer result for usage presentation.
 */
final class ExplorerResultSubscriber implements EventSubscriberInterface {

  use StringTranslationTrait;

  /**
   * Pricing date used by fallback xAI estimates.
   */
  private const PRICING_DATE = '2026-07-27';

  /**
   * The latest Grok result in this request.
   */
  private ?array $result = NULL;

  /**
   * Constructs the Explorer result subscriber.
   */
  public function __construct(
    private readonly GrokCostEstimator $costEstimator,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [
      PostGenerateResponseEvent::EVENT_NAME => 'capture',
    ];
  }

  /**
   * Captures successful Grok responses.
   */
  public function capture(PostGenerateResponseEvent $event): void {
    if ($event->getProviderId() !== 'grok') {
      return;
    }
    $this->result = [
      'operation' => $event->getOperationType(),
      'model' => $event->getModelId(),
      'configuration' => $event->getConfiguration(),
      'input' => $event->getInput(),
      'output' => $event->getOutput(),
    ];
  }

  /**
   * Clears a result before a new Explorer request.
   */
  public function reset(): void {
    $this->result = NULL;
  }

  /**
   * Builds an Explorer result usage fieldset.
   */
  public function buildFieldset(): array {
    if ($this->result === NULL) {
      return [];
    }

    $output = $this->result['output'];
    $metadata = is_array($output->getMetadata()) ? $output->getMetadata() : [];
    $raw = is_array($output->getRawOutput()) ? $output->getRawOutput() : [];
    $usage = $this->findUsage($metadata, $raw);
    $tokens = $this->findTokens($output, $metadata, $usage);
    $reported_cost = isset($usage['cost_in_usd_ticks']) && is_numeric($usage['cost_in_usd_ticks'])
      ? (float) $usage['cost_in_usd_ticks'] / 10000000000
      : NULL;
    $estimated_cost = $reported_cost ?? $this->estimateCost(
      (string) $this->result['operation'],
      (string) $this->result['model'],
      (array) $this->result['configuration'],
      $this->result['input'],
      $metadata,
      $tokens,
    );

    $rows = [
      [$this->t('Input tokens'), $this->formatInteger($tokens['input'])],
      [$this->t('Output tokens'), $this->formatInteger($tokens['output'])],
      [$this->t('Total tokens'), $this->formatInteger($tokens['total'])],
    ];
    if ($tokens['cached'] !== NULL) {
      $rows[] = [$this->t('Cached input tokens'), $this->formatInteger($tokens['cached'])];
    }
    if ($tokens['reasoning'] !== NULL) {
      $rows[] = [$this->t('Reasoning tokens'), $this->formatInteger($tokens['reasoning'])];
    }
    $rows[] = [
      $reported_cost !== NULL ? $this->t('Reported API cost') : $this->t('Estimated cost'),
      $estimated_cost === NULL ? $this->t('Unavailable') : sprintf('$%.6f USD', $estimated_cost),
    ];

    return [
      '#type' => 'details',
      '#title' => $this->t('Usage and estimated cost'),
      '#open' => TRUE,
      '#attributes' => ['class' => ['grok-explorer-usage']],
      'summary' => [
        '#type' => 'table',
        '#header' => [$this->t('Metric'), $this->t('Value')],
        '#rows' => $rows,
        '#empty' => $this->t('No usage information was returned.'),
      ],
      'note' => [
        '#type' => 'html_tag',
        '#tag' => 'p',
        '#value' => $reported_cost !== NULL
          ? $this->t('The cost was reported by xAI for this request. Token counts are unavailable for media and voice operations that are billed by image, duration, or character.')
          : $this->t('This is a best-effort estimate using public xAI pricing checked on @date. Actual billing, discounts, cached tokens, tools, and regional pricing can differ.', [
            '@date' => self::PRICING_DATE,
          ]),
      ],
    ];
  }

  /**
   * Finds the API usage object.
   */
  private function findUsage(array $metadata, array $raw): array {
    foreach ([
      $metadata['usage'] ?? NULL,
      $raw['usage'] ?? NULL,
      $metadata['response']['usage'] ?? NULL,
    ] as $usage) {
      if (is_array($usage)) {
        return $usage;
      }
    }
    return [];
  }

  /**
   * Finds normalized token counts.
   */
  private function findTokens(OutputInterface $output, array $metadata, array $usage): array {
    $tokens = [];
    if ($output instanceof ChatOutput) {
      $tokens = $output->getTokenUsage()->toArray();
    }
    elseif (is_array($metadata['token_usage'] ?? NULL)) {
      $tokens = $metadata['token_usage'];
    }

    $input = $this->nullableInteger($tokens['input'] ?? $usage['input_tokens'] ?? $usage['prompt_tokens'] ?? NULL);
    $output_count = $this->nullableInteger($tokens['output'] ?? $usage['output_tokens'] ?? $usage['completion_tokens'] ?? NULL);
    $total = $this->nullableInteger($tokens['total'] ?? $usage['total_tokens'] ?? NULL);
    if ($total === NULL && ($input !== NULL || $output_count !== NULL)) {
      $total = ($input ?? 0) + ($output_count ?? 0);
    }

    return [
      'input' => $input,
      'output' => $output_count,
      'total' => $total,
      'cached' => $this->nullableInteger($tokens['cached'] ?? $usage['input_tokens_details']['cached_tokens'] ?? NULL),
      'reasoning' => $this->nullableInteger($tokens['reasoning'] ?? $usage['output_tokens_details']['reasoning_tokens'] ?? NULL),
    ];
  }

  /**
   * Provides a fallback estimate when xAI omits a reported request cost.
   */
  private function estimateCost(string $operation, string $model, array $configuration, mixed $input, array $metadata, array $tokens): ?float {
    return $this->costEstimator->estimate($operation, $model, $configuration, $input, $metadata, $tokens);
  }

  /**
   * Converts a numeric token count to an integer or NULL.
   */
  private function nullableInteger(mixed $value): ?int {
    return is_numeric($value) ? (int) $value : NULL;
  }

  /**
   * Formats a token count for the result table.
   */
  private function formatInteger(?int $value): string {
    return $value === NULL ? (string) $this->t('Not applicable') : number_format($value);
  }

}
