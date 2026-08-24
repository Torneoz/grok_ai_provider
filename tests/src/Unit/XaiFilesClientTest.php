<?php

declare(strict_types=1);

namespace Drupal\Tests\grok\Unit;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\ai\Exception\AiBadRequestException;
use Drupal\ai\Exception\AiRateLimitException;
use Drupal\grok\Service\XaiFilesClient;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;

/**
 * Tests the HTTP boundary for the xAI Files API.
 */
final class XaiFilesClientTest extends TestCase {

  /**
   * Tests upload validation, multipart ordering, and filename sanitization.
   */
  public function testUploadsPdf(): void {
    $http_client = $this->createMock(ClientInterface::class);
    $http_client->expects(self::once())
      ->method('request')
      ->with('POST', 'https://api.x.ai/v1/files', self::callback(static function (array $options): bool {
        $parts = $options['multipart'];
        return array_column($parts, 'name') === ['expires_after', 'purpose', 'file']
          && $parts[0]['contents'] === '3600'
          && $parts[2]['filename'] === 'unsafe-name.pdf'
          && $parts[2]['headers']['Content-Type'] === 'application/pdf';
      }))
      ->willReturn(new Response(200, [], '{"id":"file_abc-123"}'));

    $file_id = $this->createClient($http_client)->uploadPdf(
      'https://api.x.ai/v1/',
      'secret',
      "%PDF-1.7\ncontent",
      '../unsafe name',
    );
    self::assertSame('file_abc-123', $file_id);
  }

  /**
   * Tests empty, oversized, and falsely labelled inputs.
   */
  public function testRejectsInvalidPdf(): void {
    $client = $this->createClient($this->createMock(ClientInterface::class));

    foreach ([
      ['', 'empty'],
      ['not a pdf', 'valid PDF signature'],
      ["%PDF-" . str_repeat('x', XaiFilesClient::MAX_FILE_BYTES), '48 MB'],
    ] as [$binary, $message]) {
      try {
        $client->uploadPdf('https://api.x.ai/v1', 'secret', $binary, 'file.pdf');
        self::fail('Expected invalid PDF input to be rejected.');
      }
      catch (AiBadRequestException $exception) {
        self::assertStringContainsString($message, $exception->getMessage());
      }
    }
  }

  /**
   * Tests immediate deletion of a successfully uploaded file.
   */
  public function testDeletesFile(): void {
    $http_client = $this->createMock(ClientInterface::class);
    $http_client->expects(self::once())
      ->method('request')
      ->with(
        'DELETE',
        'https://api.x.ai/v1/files/file_abc-123',
        self::callback(static fn(array $options): bool => $options['headers']['Authorization'] === 'Bearer secret'),
      )
      ->willReturn(new Response(200));

    $this->createClient($http_client)->delete('https://api.x.ai/v1', 'secret', 'file_abc-123');
  }

  /**
   * Tests Files API error mapping.
   */
  public function testMapsRateLimit(): void {
    $http_client = $this->createMock(ClientInterface::class);
    $http_client->method('request')->willThrowException(new RequestException(
      'Request failed',
      new Request('POST', 'https://api.x.ai/v1/files'),
      new Response(429, [], '{"error":{"message":"Slow down"}}'),
    ));

    $this->expectException(AiRateLimitException::class);
    $this->expectExceptionMessage('Slow down');
    $this->createClient($http_client)->uploadPdf(
      'https://api.x.ai/v1',
      'secret',
      '%PDF-1.7',
      'file.pdf',
    );
  }

  /**
   * Creates the client with a minimal string-translation service.
   */
  private function createClient(ClientInterface $http_client): XaiFilesClient {
    $translation = $this->createMock(TranslationInterface::class);
    $translation->method('translate')
      ->willReturnCallback(static fn(string $string, array $arguments = [], array $options = []): TranslatableMarkup => new TranslatableMarkup(
        $string,
        $arguments,
        $options,
        $translation,
      ));
    $translation->method('translateString')
      ->willReturnCallback(static fn(TranslatableMarkup $string): string => strtr(
        $string->getUntranslatedString(),
        $string->getArguments(),
      ));
    return new XaiFilesClient($http_client, $translation);
  }

}
