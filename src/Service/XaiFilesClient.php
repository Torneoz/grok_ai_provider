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
 * Uploads and removes short-lived files through xAI's Files API.
 */
final class XaiFilesClient {

  use StringTranslationTrait;

  /**
   * Maximum attachment size, conservatively below xAI's 50 MB limit.
   */
  public const MAX_FILE_BYTES = 48 * 1024 * 1024;

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
   * Uploads a PDF with a one-hour expiry safety net.
   */
  public function uploadPdf(string $endpoint, string $api_key, string $binary, string $filename, int $timeout = 300): string {
    if ($api_key === '') {
      throw new AiAccessDeniedException((string) $this->t('An xAI API key is required.'));
    }
    $size = strlen($binary);
    if ($size === 0) {
      throw new AiBadRequestException((string) $this->t('The attached PDF is empty.'));
    }
    if ($size > self::MAX_FILE_BYTES) {
      throw new AiBadRequestException((string) $this->t('PDF attachments must not exceed 48 MB.'));
    }
    if (strpos(substr($binary, 0, 1024), '%PDF-') === FALSE) {
      throw new AiBadRequestException((string) $this->t('The attached file does not have a valid PDF signature.'));
    }
    $filename = $this->normalizeFilename($filename);

    try {
      $response = $this->httpClient->request('POST', rtrim($endpoint, '/') . '/files', [
        'headers' => ['Authorization' => 'Bearer ' . $api_key],
        'multipart' => [
          ['name' => 'expires_after', 'contents' => '3600'],
          ['name' => 'purpose', 'contents' => 'assistants'],
          [
            'name' => 'file',
            'contents' => $binary,
            'filename' => $filename,
            'headers' => ['Content-Type' => 'application/pdf'],
          ],
        ],
        'connect_timeout' => min(30, $timeout),
        'timeout' => max(10, min(3600, $timeout)),
      ]);
      $decoded = Json::decode((string) $response->getBody());
    }
    catch (RequestException $exception) {
      $this->throwMappedRequestException($exception, 'upload');
    }
    catch (\Throwable $exception) {
      throw new AiResponseErrorException((string) $this->t('The xAI file-upload response could not be decoded: @message', [
        '@message' => $exception->getMessage(),
      ]), $exception->getCode(), $exception);
    }

    $file_id = is_array($decoded) ? (string) ($decoded['id'] ?? '') : '';
    if (!preg_match('/^file[-_][a-zA-Z0-9-]+$/', $file_id)) {
      throw new AiResponseErrorException((string) $this->t('xAI returned an invalid file-upload payload.'));
    }
    return $file_id;
  }

  /**
   * Deletes an uploaded file.
   */
  public function delete(string $endpoint, string $api_key, string $file_id, int $timeout = 30): void {
    if ($api_key === '' || !preg_match('/^file[-_][a-zA-Z0-9-]+$/', $file_id)) {
      throw new AiBadRequestException((string) $this->t('A valid xAI file ID and API key are required for deletion.'));
    }
    try {
      $this->httpClient->request('DELETE', rtrim($endpoint, '/') . '/files/' . rawurlencode($file_id), [
        'headers' => ['Authorization' => 'Bearer ' . $api_key],
        'connect_timeout' => min(10, $timeout),
        'timeout' => max(10, min(60, $timeout)),
      ]);
    }
    catch (RequestException $exception) {
      $this->throwMappedRequestException($exception, 'deletion');
    }
  }

  /**
   * Produces a safe PDF filename for multipart metadata.
   */
  private function normalizeFilename(string $filename): string {
    $filename = basename(str_replace('\\', '/', trim($filename)));
    $filename = preg_replace('/[^a-zA-Z0-9._-]+/', '-', $filename) ?: 'document.pdf';
    $filename = trim($filename, '.-');
    if ($filename === '') {
      $filename = 'document.pdf';
    }
    if (!str_ends_with(strtolower($filename), '.pdf')) {
      $filename .= '.pdf';
    }
    return substr($filename, 0, 200);
  }

  /**
   * Maps Files API failures into Drupal AI exceptions.
   */
  private function throwMappedRequestException(RequestException $exception, string $operation): never {
    $response = $exception->getResponse();
    if ($response === NULL) {
      throw new AiRequestErrorException((string) $this->t('Could not connect to the xAI Files API during @operation: @message', [
        '@operation' => $operation,
        '@message' => $exception->getMessage(),
      ]), 0, $exception);
    }
    $status = $response->getStatusCode();
    $body = trim(strip_tags((string) $response->getBody()));
    try {
      $decoded = Json::decode($body);
      if (is_array($decoded) && isset($decoded['error']['message'])) {
        $body = (string) $decoded['error']['message'];
      }
    }
    catch (\Throwable) {
      // Retain the sanitized response body.
    }
    $message = (string) $this->t('xAI Files API returned HTTP @status during @operation: @message', [
      '@status' => $status,
      '@operation' => $operation,
      '@message' => mb_substr($body ?: 'No error details were returned.', 0, 1000),
    ]);
    if (in_array($status, [401, 403], TRUE)) {
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

}
