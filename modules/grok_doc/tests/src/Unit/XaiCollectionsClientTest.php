<?php

declare(strict_types=1);

namespace Drupal\Tests\grok_doc\Unit;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\grok_doc\Service\XaiCollectionsClient;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;

/**
 * Tests the xAI Collections Management API boundary.
 */
final class XaiCollectionsClientTest extends TestCase {

  /**
   * Tests document status retrieval and authorization.
   */
  public function testGetsDocument(): void {
    $http_client = $this->createMock(ClientInterface::class);
    $http_client->expects(self::once())
      ->method('request')
      ->with(
        'GET',
        'https://management-api.x.ai/v1/collections/collection_123/documents/file_456',
        self::callback(static fn (array $options): bool => $options['headers']['Authorization'] === 'Bearer secret'),
      )
      ->willReturn(new Response(200, [], '{"status":"DOCUMENT_STATUS_PROCESSED"}'));

    $result = $this->createClient($http_client)->getDocument('secret', 'collection_123', 'file_456');
    self::assertSame('DOCUMENT_STATUS_PROCESSED', $result['status']);
  }

  /**
   * Tests local rejection of malformed Collection identifiers.
   */
  public function testRejectsInvalidCollectionId(): void {
    $this->expectException(\InvalidArgumentException::class);
    $this->createClient($this->createMock(ClientInterface::class))->getDocument('secret', '../bad', 'file_456');
  }

  /**
   * Tests bounded upstream error reporting.
   */
  public function testReportsHttpFailure(): void {
    $http_client = $this->createMock(ClientInterface::class);
    $http_client->method('request')->willThrowException(new RequestException(
      'Request failed',
      new Request('GET', 'https://management-api.x.ai/v1/collections/collection_123/documents/file_456'),
      new Response(429, [], 'rate limited'),
    ));
    $this->expectException(\RuntimeException::class);
    $this->expectExceptionMessage('HTTP 429');
    $this->createClient($http_client)->getDocument('secret', 'collection_123', 'file_456');
  }

  /**
   * Creates the client with a minimal translation service.
   */
  private function createClient(ClientInterface $http_client): XaiCollectionsClient {
    $translation = $this->createMock(TranslationInterface::class);
    $translation->method('translate')
      ->willReturnCallback(static fn (string $string, array $arguments = [], array $options = []): TranslatableMarkup => new TranslatableMarkup(
        $string,
        $arguments,
        $options,
        $translation,
      ));
    $translation->method('translateString')
      ->willReturnCallback(static fn (TranslatableMarkup $string): string => strtr(
        $string->getUntranslatedString(),
        $string->getArguments(),
      ));
    return new XaiCollectionsClient($http_client, $translation);
  }

}
