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
 * Sends asynchronous requests to xAI's video generation API.
 */
final class XaiVideosClient {

  use StringTranslationTrait;

  /**
   * Maximum generated video download size.
   */
  private const MAX_DOWNLOAD_BYTES = 200 * 1024 * 1024;

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
   * Generates a video, polls until completion, and downloads it.
   */
  public function generate(string $endpoint, string $api_key, array $payload, int $timeout = 300, int $poll_interval = 2): array {
    $started = $this->request('POST', rtrim($endpoint, '/') . '/videos/generations', $api_key, $payload, $timeout);
    $request_id = trim((string) ($started['request_id'] ?? ''));
    if ($request_id === '') {
      throw new AiResponseErrorException((string) $this->t('xAI did not return a video request ID.'));
    }

    $deadline = microtime(TRUE) + max(10, min(3600, $timeout));
    do {
      if ($poll_interval > 0) {
        usleep(min(30, $poll_interval) * 1000000);
      }
      $result = $this->request(
        'GET',
        rtrim($endpoint, '/') . '/videos/' . rawurlencode($request_id),
        $api_key,
        NULL,
        min(60, $timeout),
      );
      $status = strtolower(trim((string) ($result['status'] ?? '')));
      if ($status === 'done') {
        if (isset($result['video']['respect_moderation']) && $result['video']['respect_moderation'] === FALSE) {
          throw new AiResponseErrorException((string) $this->t('xAI did not return the video because it did not pass content moderation.'));
        }
        $video_url = trim((string) ($result['video']['url'] ?? ''));
        if ($video_url === '' || !str_starts_with(strtolower($video_url), 'https://')) {
          throw new AiResponseErrorException((string) $this->t('xAI returned an invalid generated video URL.'));
        }
        $result['_video_binary'] = $this->download($video_url, $timeout);
        return $result;
      }
      if (in_array($status, ['failed', 'expired'], TRUE)) {
        throw new AiResponseErrorException((string) $this->t('xAI video generation ended with status “@status”.', [
          '@status' => $status,
        ]));
      }
    } while (microtime(TRUE) < $deadline);

    throw new AiResponseErrorException((string) $this->t('xAI video generation did not finish within @seconds seconds.', [
      '@seconds' => $timeout,
    ]));
  }

  /**
   * Sends and decodes an xAI video API request.
   */
  private function request(string $method, string $url, string $api_key, ?array $payload, int $timeout): array {
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
      throw new AiResponseErrorException((string) $this->t('The xAI video payload could not be decoded: @message', [
        '@message' => $exception->getMessage(),
      ]), $exception->getCode(), $exception);
    }
    if (!is_array($decoded)) {
      throw new AiResponseErrorException((string) $this->t('xAI returned an invalid video payload.'));
    }
    return $decoded;
  }

  /**
   * Downloads the ephemeral generated video.
   */
  private function download(string $url, int $timeout): string {
    try {
      $response = $this->httpClient->request('GET', $url, [
        'connect_timeout' => min(30, $timeout),
        'timeout' => max(10, min(3600, $timeout)),
        'allow_redirects' => ['max' => 3, 'strict' => TRUE],
      ]);
      $content_length = (int) $response->getHeaderLine('Content-Length');
      if ($content_length > self::MAX_DOWNLOAD_BYTES) {
        throw new AiResponseErrorException((string) $this->t('The generated xAI video exceeds the maximum allowed size.'));
      }
      $body = $response->getBody();
      $binary = '';
      while (!$body->eof()) {
        $binary .= $body->read(1024 * 1024);
        if (strlen($binary) > self::MAX_DOWNLOAD_BYTES) {
          throw new AiResponseErrorException((string) $this->t('The generated xAI video exceeds the maximum allowed size.'));
        }
      }
      return $binary;
    }
    catch (\Throwable $exception) {
      throw new AiResponseErrorException((string) $this->t('Could not download the generated xAI video: @message', [
        '@message' => $exception->getMessage(),
      ]), $exception->getCode(), $exception);
    }
  }

  /**
   * Maps HTTP failures into Drupal AI exceptions.
   */
  private function throwMappedRequestException(RequestException $exception): never {
    $response = $exception->getResponse();
    if ($response === NULL) {
      throw new AiRequestErrorException((string) $this->t('Could not connect to the xAI video API: @message', [
        '@message' => $exception->getMessage(),
      ]), 0, $exception);
    }
    $status = $response->getStatusCode();
    $message = (string) $this->t('xAI video API returned HTTP @status.', ['@status' => $status]);
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

}
