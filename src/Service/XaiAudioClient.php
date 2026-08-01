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
use Psr\Http\Message\ResponseInterface;

/**
 * Sends requests to xAI's REST voice APIs.
 */
final class XaiAudioClient {

  use StringTranslationTrait;

  /**
   * Maximum generated audio response size.
   */
  private const MAX_GENERATED_AUDIO_BYTES = 50 * 1024 * 1024;

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
   * Lists the built-in voices available to the API key.
   */
  public function listVoices(string $endpoint, string $api_key): array {
    return $this->jsonRequest(
      'GET',
      rtrim($endpoint, '/') . '/tts/voices',
      $api_key,
    );
  }

  /**
   * Converts text to raw MP3 bytes.
   */
  public function synthesize(string $endpoint, string $api_key, array $payload, int $timeout = 300): array {
    $this->assertApiKey($api_key);
    try {
      $response = $this->httpClient->request('POST', rtrim($endpoint, '/') . '/tts', [
        'headers' => [
          'Authorization' => 'Bearer ' . $api_key,
          'Accept' => 'audio/mpeg',
          'Content-Type' => 'application/json',
        ],
        'json' => $payload,
        'connect_timeout' => min(30, $timeout),
        'timeout' => max(10, min(3600, $timeout)),
        'on_headers' => function (ResponseInterface $response): void {
          $content_length = (int) $response->getHeaderLine('Content-Length');
          if ($content_length > self::MAX_GENERATED_AUDIO_BYTES) {
            throw new \RuntimeException('The generated xAI audio exceeds the maximum allowed size.');
          }
        },
        'progress' => static function (int $download_total, int $downloaded_bytes): void {
          if ($download_total > self::MAX_GENERATED_AUDIO_BYTES || $downloaded_bytes > self::MAX_GENERATED_AUDIO_BYTES) {
            throw new \RuntimeException('The generated xAI audio exceeds the maximum allowed size.');
          }
        },
      ]);
      $content_length = (int) $response->getHeaderLine('Content-Length');
      if ($content_length > self::MAX_GENERATED_AUDIO_BYTES) {
        throw new AiResponseErrorException((string) $this->t('The generated xAI audio exceeds the maximum allowed size.'));
      }
      $body = $response->getBody();
      $binary = '';
      while (!$body->eof()) {
        $binary .= $body->read(1024 * 1024);
        if (strlen($binary) > self::MAX_GENERATED_AUDIO_BYTES) {
          throw new AiResponseErrorException((string) $this->t('The generated xAI audio exceeds the maximum allowed size.'));
        }
      }
    }
    catch (RequestException $exception) {
      $this->throwMappedRequestException($exception);
    }
    catch (AiResponseErrorException $exception) {
      throw $exception;
    }
    catch (\Throwable $exception) {
      throw new AiResponseErrorException((string) $this->t('Could not read the generated xAI audio: @message', [
        '@message' => $exception->getMessage(),
      ]), $exception->getCode(), $exception);
    }

    if ($binary === '') {
      throw new AiResponseErrorException((string) $this->t('xAI returned an empty text-to-speech response.'));
    }
    $has_id3_header = str_starts_with($binary, 'ID3');
    $has_frame_sync = strlen($binary) >= 2
      && ord($binary[0]) === 0xFF
      && (ord($binary[1]) & 0xE0) === 0xE0;
    if (!$has_id3_header && !$has_frame_sync) {
      throw new AiResponseErrorException((string) $this->t('xAI returned text-to-speech data that is not a valid MP3 stream.'));
    }
    return [
      'binary' => $binary,
      'content_type' => 'audio/mpeg',
    ];
  }

  /**
   * Transcribes an audio file through xAI's multipart REST endpoint.
   */
  public function transcribe(string $endpoint, string $api_key, array $fields, array $file, int $timeout = 300): array {
    $this->assertApiKey($api_key);
    $multipart = [];
    foreach ($fields as $name => $value) {
      if (is_array($value)) {
        foreach ($value as $item) {
          $multipart[] = ['name' => $name, 'contents' => (string) $item];
        }
      }
      elseif ($value !== NULL && $value !== '') {
        $multipart[] = [
          'name' => $name,
          'contents' => is_bool($value) ? ($value ? 'true' : 'false') : (string) $value,
        ];
      }
    }
    // xAI requires the file part to appear after all other multipart fields.
    $multipart[] = [
      'name' => 'file',
      'contents' => $file['binary'],
      'filename' => $file['filename'],
      'headers' => ['Content-Type' => $file['mime_type']],
    ];

    try {
      $response = $this->httpClient->request('POST', rtrim($endpoint, '/') . '/stt', [
        'headers' => [
          'Authorization' => 'Bearer ' . $api_key,
          'Accept' => 'application/json',
        ],
        'multipart' => $multipart,
        'connect_timeout' => min(30, $timeout),
        'timeout' => max(10, min(3600, $timeout)),
      ]);
      $decoded = Json::decode((string) $response->getBody());
    }
    catch (RequestException $exception) {
      $this->throwMappedRequestException($exception);
    }
    catch (\Throwable $exception) {
      throw new AiResponseErrorException((string) $this->t('The xAI transcription payload could not be decoded: @message', [
        '@message' => $exception->getMessage(),
      ]), $exception->getCode(), $exception);
    }

    if (!is_array($decoded)) {
      throw new AiResponseErrorException((string) $this->t('xAI returned an invalid transcription payload.'));
    }
    return $decoded;
  }

  /**
   * Sends and decodes a JSON voice API request.
   */
  private function jsonRequest(string $method, string $url, string $api_key): array {
    $this->assertApiKey($api_key);
    try {
      $response = $this->httpClient->request($method, $url, [
        'headers' => [
          'Authorization' => 'Bearer ' . $api_key,
          'Accept' => 'application/json',
        ],
        'connect_timeout' => 30,
        'timeout' => 60,
      ]);
      $decoded = Json::decode((string) $response->getBody());
    }
    catch (RequestException $exception) {
      $this->throwMappedRequestException($exception);
    }
    catch (\Throwable $exception) {
      throw new AiResponseErrorException((string) $this->t('The xAI voice payload could not be decoded: @message', [
        '@message' => $exception->getMessage(),
      ]), $exception->getCode(), $exception);
    }
    if (!is_array($decoded)) {
      throw new AiResponseErrorException((string) $this->t('xAI returned an invalid voice payload.'));
    }
    return $decoded;
  }

  /**
   * Ensures requests never proceed without credentials.
   */
  private function assertApiKey(string $api_key): void {
    if ($api_key === '') {
      throw new AiAccessDeniedException((string) $this->t('An xAI API key is required.'));
    }
  }

  /**
   * Maps HTTP failures into Drupal AI exceptions.
   */
  private function throwMappedRequestException(RequestException $exception): never {
    $response = $exception->getResponse();
    if ($response === NULL) {
      throw new AiRequestErrorException((string) $this->t('Could not connect to the xAI voice API: @message', [
        '@message' => $exception->getMessage(),
      ]), 0, $exception);
    }
    $status = $response->getStatusCode();
    $message = $this->extractErrorMessage((string) $response->getBody());
    $message = (string) $this->t('xAI voice API returned HTTP @status: @message', [
      '@status' => $status,
      '@message' => $message,
    ]);
    if ($status === 401 || $status === 403) {
      throw new AiAccessDeniedException($message, $status, $exception);
    }
    if ($status === 429) {
      throw new AiRateLimitException($message, $status, $exception);
    }
    if (in_array($status, [400, 404, 405, 413, 415, 422], TRUE)) {
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
