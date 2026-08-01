<?php

declare(strict_types=1);

namespace Drupal\grok\Service;

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
 * Sends requests to xAI's image generation API.
 */
final class XaiImagesClient {

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
   * Lists image generation models available to the API key.
   */
  public function listModels(string $endpoint, string $api_key): array {
    return $this->request(
      'GET',
      rtrim($endpoint, '/') . '/image-generation-models',
      $api_key,
    );
  }

  /**
   * Generates images from a text prompt.
   */
  public function generate(string $endpoint, string $api_key, array $payload, int $timeout = 300): array {
    return $this->request(
      'POST',
      rtrim($endpoint, '/') . '/images/generations',
      $api_key,
      $payload,
      $timeout,
    );
  }

  /**
   * Edits an image from a source image and text prompt.
   */
  public function edit(string $endpoint, string $api_key, array $payload, int $timeout = 300): array {
    return $this->request(
      'POST',
      rtrim($endpoint, '/') . '/images/edits',
      $api_key,
      $payload,
      $timeout,
    );
  }

  /**
   * Sends and decodes an xAI image API request.
   */
  private function request(string $method, string $url, string $api_key, ?array $payload = NULL, int $timeout = 60): array {
    if ($api_key === '') {
      throw new AiAccessDeniedException((string) $this->t('An xAI API key is required.'));
    }
    $options = [
      'headers' => [
        'Authorization' => 'Bearer ' . $api_key,
        'Accept' => 'application/json',
      ],
      'connect_timeout' => min(30, $timeout),
      'timeout' => max(10, min(3600, $timeout)),
    ];
    if ($payload !== NULL) {
      $options['headers']['Content-Type'] = 'application/json';
      $options['json'] = $payload;
    }

    try {
      $response = $this->httpClient->request($method, $url, $options);
      $decoded = Json::decode((string) $response->getBody());
    }
    catch (RequestException $exception) {
      $this->throwMappedRequestException($exception);
    }
    catch (\Throwable $exception) {
      throw new AiResponseErrorException((string) $this->t('The xAI image payload could not be decoded: @message', [
        '@message' => $exception->getMessage(),
      ]), $exception->getCode(), $exception);
    }

    if (!is_array($decoded)) {
      throw new AiResponseErrorException((string) $this->t('xAI returned an invalid image payload.'));
    }
    if (!empty($decoded['error'])) {
      $message = is_array($decoded['error']) ? ($decoded['error']['message'] ?? Json::encode($decoded['error'])) : (string) $decoded['error'];
      throw new AiResponseErrorException((string) $this->t('xAI returned an image error: @message', [
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
      throw new AiRequestErrorException((string) $this->t('Could not connect to the xAI image API: @message', [
        '@message' => $exception->getMessage(),
      ]), 0, $exception);
    }

    $status = $response->getStatusCode();
    $message = $this->extractErrorMessage((string) $response->getBody());
    $message = (string) $this->t('xAI image API returned HTTP @status: @message', [
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
   * Extracts bounded error detail without exposing request headers.
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
