<?php

declare(strict_types=1);

namespace Drupal\grok_ai_provider\Service;

use GuzzleHttp\ClientInterface;

/**
 * Downloads the module-maintained xAI pricing schedule.
 */
final class XaiPricingScheduleFetcher {

  /**
   * The trusted machine-readable pricing schedule.
   */
  public const PRICING_URL = 'https://raw.githubusercontent.com/Torneoz/grok_ai_provider/main/data/xai_pricing.json';

  /**
   * Maximum accepted pricing response size.
   */
  private const MAX_RESPONSE_BYTES = 262144;

  /**
   * Constructs the pricing schedule fetcher.
   */
  public function __construct(
    private readonly ClientInterface $httpClient,
    private readonly GrokCostEstimator $costEstimator,
  ) {}

  /**
   * Downloads and validates the latest pricing schedule.
   *
   * @return array{json: string, source: string, checked_at: string, hash: string, rows: int}
   *   Normalized pricing data and its provenance.
   */
  public function fetch(): array {
    $response = $this->httpClient->request('GET', self::PRICING_URL, [
      'allow_redirects' => FALSE,
      'connect_timeout' => 5,
      'timeout' => 10,
      'headers' => [
        'Accept' => 'application/json',
        'User-Agent' => 'Drupal Grok AI Provider pricing updater',
      ],
    ]);
    if ($response->getStatusCode() !== 200) {
      throw new \RuntimeException(sprintf('The pricing server returned HTTP %d.', $response->getStatusCode()));
    }

    $content_length = $response->getHeaderLine('Content-Length');
    if ($content_length !== '' && (int) $content_length > self::MAX_RESPONSE_BYTES) {
      throw new \RuntimeException('The downloaded pricing schedule is too large.');
    }
    $json = (string) $response->getBody();
    if ($json === '' || strlen($json) > self::MAX_RESPONSE_BYTES) {
      throw new \RuntimeException('The downloaded pricing schedule is empty or too large.');
    }

    $normalized = $this->costEstimator->normalizePricingJson($json);
    $rows = json_decode($normalized, TRUE, 512, JSON_THROW_ON_ERROR);

    return [
      'json' => $normalized,
      'source' => self::PRICING_URL,
      'checked_at' => gmdate(DATE_ATOM),
      'hash' => hash('sha256', $normalized),
      'rows' => count($rows),
    ];
  }

}
