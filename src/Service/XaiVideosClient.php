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
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\UriInterface;

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
    $deadline = microtime(TRUE) + max(10, min(3600, $timeout));
    $started = $this->request('POST', rtrim($endpoint, '/') . '/videos/generations', $api_key, $payload, $this->remainingSeconds($deadline));
    $request_id = trim((string) ($started['request_id'] ?? ''));
    if ($request_id === '') {
      throw new AiResponseErrorException((string) $this->t('xAI did not return a video request ID.'));
    }

    do {
      if ($poll_interval > 0) {
        $sleep = min(30, $poll_interval, max(0, (int) floor($deadline - microtime(TRUE))));
        if ($sleep > 0) {
          usleep($sleep * 1000000);
        }
      }
      if (microtime(TRUE) >= $deadline) {
        break;
      }
      $result = $this->request(
        'GET',
        rtrim($endpoint, '/') . '/videos/' . rawurlencode($request_id),
        $api_key,
        NULL,
        min(60, $this->remainingSeconds($deadline)),
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
        $result['_video_binary'] = $this->download($video_url, $endpoint, $this->remainingSeconds($deadline));
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
   * Lists video generation models available to the API key.
   */
  public function listModels(string $endpoint, string $api_key): array {
    return $this->request(
      'GET',
      rtrim($endpoint, '/') . '/video-generation-models',
      $api_key,
      NULL,
      60,
    );
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
      'timeout' => max(1, min(3600, $timeout)),
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
  private function download(string $url, string $endpoint, int $timeout): string {
    $this->assertAllowedDownloadUrl($url, $endpoint);
    try {
      $response = $this->httpClient->request('GET', $url, [
        'connect_timeout' => min(30, $timeout),
        'timeout' => max(1, min(3600, $timeout)),
        'allow_redirects' => [
          'max' => 3,
          'strict' => TRUE,
          'on_redirect' => function (RequestInterface $request, ResponseInterface $response, UriInterface $uri) use ($endpoint): void {
            $this->assertAllowedDownloadUrl((string) $uri, $endpoint);
          },
        ],
        'on_headers' => function (ResponseInterface $response): void {
          $content_length = (int) $response->getHeaderLine('Content-Length');
          if ($content_length > self::MAX_DOWNLOAD_BYTES) {
            throw new \RuntimeException('The generated xAI video exceeds the maximum allowed size.');
          }
        },
        'progress' => static function (int $download_total, int $downloaded_bytes): void {
          if ($download_total > self::MAX_DOWNLOAD_BYTES || $downloaded_bytes > self::MAX_DOWNLOAD_BYTES) {
            throw new \RuntimeException('The generated xAI video exceeds the maximum allowed size.');
          }
        },
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
   * Rejects asset URLs outside xAI or the configured compatible gateway.
   */
  private function assertAllowedDownloadUrl(string $url, string $endpoint): void {
    $parts = parse_url($url);
    $host = strtolower((string) ($parts['host'] ?? ''));
    $endpoint_host = strtolower((string) (parse_url($endpoint, PHP_URL_HOST) ?? ''));
    if (
      ($parts['scheme'] ?? '') !== 'https'
      || $host === ''
      || isset($parts['user'])
      || isset($parts['pass'])
      || !($host === $endpoint_host || $host === 'x.ai' || str_ends_with($host, '.x.ai'))
    ) {
      throw new AiResponseErrorException((string) $this->t('xAI returned a generated video URL outside the trusted asset hosts.'));
    }
    if (filter_var($host, FILTER_VALIDATE_IP) !== FALSE && !$this->isPublicIp($host)) {
      throw new AiResponseErrorException((string) $this->t('xAI returned a generated video URL for a private or reserved address.'));
    }
  }

  /**
   * Determines whether an IP literal is globally routable.
   */
  private function isPublicIp(string $ip): bool {
    return filter_var(
      $ip,
      FILTER_VALIDATE_IP,
      FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE,
    ) !== FALSE;
  }

  /**
   * Returns the bounded number of seconds left in an overall operation.
   */
  private function remainingSeconds(float $deadline): int {
    return max(1, min(3600, (int) ceil($deadline - microtime(TRUE))));
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
