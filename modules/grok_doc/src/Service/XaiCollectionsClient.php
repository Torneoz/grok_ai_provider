<?php

declare(strict_types=1);

namespace Drupal\grok_doc\Service;

use Drupal\Component\Serialization\Json;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslationInterface;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\RequestException;

/**
 * Minimal client for xAI Management Collections document operations.
 */
final class XaiCollectionsClient {

  use StringTranslationTrait;

  private const BASE_URL = 'https://management-api.x.ai/v1';

  public function __construct(
    private readonly ClientInterface $httpClient,
    TranslationInterface $string_translation,
  ) {
    $this->stringTranslation = $string_translation;
  }

  /**
   * Uploads and attaches one document to a Collection.
   */
  public function uploadDocument(string $api_key, string $collection_id, string $path, string $filename, string $mime, array $fields = []): array {
    $this->assertCredentials($api_key, $collection_id);
    $handle = fopen($path, 'rb');
    if ($handle === FALSE) {
      throw new \RuntimeException((string) $this->t('The source document could not be opened.'));
    }
    $multipart = [
      ['name' => 'name', 'contents' => $filename],
      ['name' => 'content_type', 'contents' => $mime],
      ['name' => 'fields', 'contents' => Json::encode($fields)],
      ['name' => 'data', 'contents' => $handle, 'filename' => $filename],
    ];
    try {
      return $this->request('POST', '/collections/' . rawurlencode($collection_id) . '/documents', $api_key, [
        'multipart' => $multipart,
        'timeout' => 300,
      ]);
    }
    finally {
      fclose($handle);
    }
  }

  /**
   * Retrieves one document's current indexing state.
   */
  public function getDocument(string $api_key, string $collection_id, string $file_id): array {
    $this->assertCredentials($api_key, $collection_id);
    if (!preg_match('/^file[_-][A-Za-z0-9-]+$/', $file_id)) {
      throw new \InvalidArgumentException('Invalid xAI file ID.');
    }
    return $this->request('GET', '/collections/' . rawurlencode($collection_id) . '/documents/' . rawurlencode($file_id), $api_key);
  }

  /**
   * Removes one document from a Collection.
   */
  public function deleteDocument(string $api_key, string $collection_id, string $file_id): void {
    $this->assertCredentials($api_key, $collection_id);
    $this->request('DELETE', '/collections/' . rawurlencode($collection_id) . '/documents/' . rawurlencode($file_id), $api_key);
  }

  /**
   * Sends an authenticated request and decodes a bounded JSON response. */
  private function request(string $method, string $path, string $api_key, array $options = []): array {
    $options['headers']['Authorization'] = 'Bearer ' . $api_key;
    $options += ['connect_timeout' => 20, 'timeout' => 120];
    try {
      $response = $this->httpClient->request($method, self::BASE_URL . $path, $options);
      $body = trim((string) $response->getBody());
      if ($body === '') {
        return [];
      }
      $decoded = Json::decode($body);
      if (!is_array($decoded)) {
        throw new \RuntimeException((string) $this->t('xAI returned an invalid Collections response.'));
      }
      return $decoded;
    }
    catch (RequestException $exception) {
      $status = $exception->getResponse()?->getStatusCode() ?? 0;
      $body = trim(strip_tags((string) $exception->getResponse()?->getBody()));
      $detail = $body === '' ? $exception->getMessage() : mb_substr($body, 0, 1000);
      throw new \RuntimeException((string) $this->t('xAI Collections API returned HTTP @status: @detail', [
        '@status' => $status ?: $this->t('connection error'),
        '@detail' => $detail,
      ]), $status, $exception);
    }
  }

  /**
   * Validates credentials and the remote Collection identifier. */
  private function assertCredentials(string $api_key, string $collection_id): void {
    if ($api_key === '') {
      throw new \InvalidArgumentException('An xAI Management API key is required.');
    }
    if (!preg_match('/^collection_[A-Za-z0-9-]+$/', $collection_id)) {
      throw new \InvalidArgumentException('Invalid xAI collection ID.');
    }
  }

}
