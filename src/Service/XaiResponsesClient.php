<?php

declare(strict_types=1);

namespace Drupal\grok_ai_provider\Service;

use Drupal\Component\Serialization\Json;
use Drupal\ai\Exception\AiResponseErrorException;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;

/**
 * Sends synchronous requests to xAI's OpenAI-compatible Responses API.
 */
final class XaiResponsesClient {

  /**
   * Constructs the client.
   */
  public function __construct(private readonly ClientInterface $httpClient) {
  }

  /**
   * Creates an xAI response.
   */
  public function create(string $endpoint, string $api_key, array $payload): array {
    try {
      $response = $this->httpClient->request('POST', rtrim($endpoint, '/') . '/responses', [
        'headers' => [
          'Authorization' => 'Bearer ' . $api_key,
          'Content-Type' => 'application/json',
        ],
        'json' => $payload,
      ]);
      $decoded = Json::decode((string) $response->getBody());
    }
    catch (GuzzleException $exception) {
      throw new AiResponseErrorException('The xAI Responses request failed: ' . $exception->getMessage(), $exception->getCode(), $exception);
    }
    catch (\Throwable $exception) {
      throw new AiResponseErrorException('The xAI Responses payload could not be decoded: ' . $exception->getMessage(), $exception->getCode(), $exception);
    }

    if (!is_array($decoded)) {
      throw new AiResponseErrorException('xAI returned an invalid Responses payload.');
    }
    if (!empty($decoded['error'])) {
      $message = is_array($decoded['error']) ? ($decoded['error']['message'] ?? Json::encode($decoded['error'])) : (string) $decoded['error'];
      throw new AiResponseErrorException('xAI returned an error: ' . $message);
    }

    return $decoded;
  }

}
