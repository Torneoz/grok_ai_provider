<?php

declare(strict_types=1);

namespace Drupal\grok_ai_provider\Service;

use Drupal\Component\Serialization\Json;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\ai\Exception\AiAccessDeniedException;
use Drupal\ai\Exception\AiBadRequestException;
use Drupal\ai\Exception\AiRateLimitException;
use Drupal\ai\Exception\AiRequestErrorException;
use Drupal\ai\Exception\AiResponseErrorException;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\RequestException;

/**
 * Sends synchronous requests to xAI's OpenAI-compatible Responses API.
 */
final class XaiResponsesClient {

  use StringTranslationTrait;

  /**
   * Constructs the client.
   */
  public function __construct(
    private readonly ClientInterface $httpClient,
    TranslationInterface $string_translation,
  ) {
    $this->stringTranslation = $string_translation;
  }

  /**
   * Creates an xAI response.
   */
  public function create(string $endpoint, string $api_key, array $payload, int $timeout = 300): array {
    if ($api_key === '') {
      throw new AiAccessDeniedException((string) $this->t('An xAI API key is required.'));
    }
    try {
      $response = $this->httpClient->request('POST', rtrim($endpoint, '/') . '/responses', [
        'headers' => [
          'Authorization' => 'Bearer ' . $api_key,
          'Content-Type' => 'application/json',
        ],
        'json' => $payload,
        'connect_timeout' => min(30, $timeout),
        'timeout' => max(10, min(3600, $timeout)),
      ]);
      $decoded = Json::decode((string) $response->getBody());
    }
    catch (RequestException $exception) {
      $this->throwMappedRequestException($exception);
    }
    catch (\Throwable $exception) {
      throw new AiResponseErrorException((string) $this->t('The xAI Responses payload could not be decoded: @message', [
        '@message' => $exception->getMessage(),
      ]), $exception->getCode(), $exception);
    }

    if (!is_array($decoded)) {
      throw new AiResponseErrorException((string) $this->t('xAI returned an invalid Responses payload.'));
    }
    if (!empty($decoded['error'])) {
      $message = is_array($decoded['error']) ? ($decoded['error']['message'] ?? Json::encode($decoded['error'])) : (string) $decoded['error'];
      throw new AiResponseErrorException((string) $this->t('xAI returned an error: @message', [
        '@message' => $message,
      ]));
    }

    return $decoded;
  }

  /**
   * Maps HTTP failures into Drupal AI's provider-neutral exceptions.
   */
  private function throwMappedRequestException(RequestException $exception): never {
    $response = $exception->getResponse();
    if ($response === NULL) {
      throw new AiRequestErrorException((string) $this->t('Could not connect to the xAI Responses API: @message', [
        '@message' => $exception->getMessage(),
      ]), 0, $exception);
    }

    $status = $response->getStatusCode();
    $message = $this->extractErrorMessage((string) $response->getBody());
    $message = (string) $this->t('xAI Responses API returned HTTP @status: @message', [
      '@status' => $status,
      '@message' => $message,
    ]);
    if ($status === 401 || $status === 403) {
      throw new AiAccessDeniedException($message, $status, $exception);
    }
    if ($status === 429) {
      throw new AiRateLimitException($message, $status, $exception);
    }
    if (in_array($status, [400, 404, 405, 415, 422], TRUE)) {
      throw new AiBadRequestException($message, $status, $exception);
    }
    throw new AiResponseErrorException($message, $status, $exception);
  }

  /**
   * Extracts a bounded, useful API error without exposing request headers.
   */
  private function extractErrorMessage(string $body): string {
    try {
      $decoded = Json::decode($body);
      if (is_array($decoded) && isset($decoded['error'])) {
        $error = $decoded['error'];
        if (is_array($error) && !empty($error['message'])) {
          return mb_substr((string) $error['message'], 0, 1000);
        }
        if (is_string($error)) {
          return mb_substr($error, 0, 1000);
        }
      }
    }
    catch (\Throwable) {
      // Fall through to the sanitized response body.
    }
    $body = trim(strip_tags($body));
    return $body === ''
      ? (string) $this->t('No error details were returned.')
      : mb_substr($body, 0, 1000);
  }

}
