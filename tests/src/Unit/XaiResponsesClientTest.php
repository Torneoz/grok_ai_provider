<?php

declare(strict_types=1);

namespace Drupal\Tests\grok\Unit;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\ai\Exception\AiAccessDeniedException;
use Drupal\ai\Exception\AiBadRequestException;
use Drupal\ai\Exception\AiRateLimitException;
use Drupal\ai\Exception\AiResponseErrorException;
use Drupal\grok\Service\XaiResponsesClient;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Tests the HTTP boundary for the xAI Responses API.
 */
final class XaiResponsesClientTest extends TestCase {

  /**
   * Tests endpoint, authentication, and payload handling.
   */
  public function testCreatesResponse(): void {
    $http_client = $this->createMock(ClientInterface::class);
    $http_client->expects(self::once())
      ->method('request')
      ->with(
        'POST',
        'https://api.x.ai/v1/responses',
        self::callback(static function (array $options): bool {
          return $options['headers']['Authorization'] === 'Bearer secret' && $options['json']['model'] === 'grok-4.5';
        }),
      )
      ->willReturn(new Response(200, [], '{"id":"resp_123","output":[]}'));

    $response = $this->createResponsesClient($http_client)->create(
      'https://api.x.ai/v1/',
      'secret',
      ['model' => 'grok-4.5'],
    );

    self::assertSame('resp_123', $response['id']);
  }

  /**
   * Tests provider-neutral HTTP exception mapping.
   *
   * @dataProvider errorMappingProvider
   */
  #[DataProvider('errorMappingProvider')]
  public function testMapsHttpErrors(int $status, string $expected_exception): void {
    $http_client = $this->createMock(ClientInterface::class);
    $http_client->method('request')->willThrowException(new RequestException(
      'Request failed',
      new Request('POST', 'https://api.x.ai/v1/responses'),
      new Response($status, [], '{"error":{"message":"Useful API detail"}}'),
    ));

    $this->expectException($expected_exception);
    $this->expectExceptionMessage('Useful API detail');
    $this->createResponsesClient($http_client)->create(
      'https://api.x.ai/v1',
      'secret',
      ['model' => 'grok-4.5'],
    );
  }

  /**
   * Provides HTTP statuses and their Drupal AI exceptions.
   */
  public static function errorMappingProvider(): array {
    return [
      'bad request' => [400, AiBadRequestException::class],
      'unauthorized' => [401, AiAccessDeniedException::class],
      'forbidden' => [403, AiAccessDeniedException::class],
      'rate limited' => [429, AiRateLimitException::class],
      'server error' => [500, AiResponseErrorException::class],
    ];
  }

  /**
   * Tests that an API key is mandatory.
   */
  public function testRejectsMissingApiKey(): void {
    $this->expectException(AiAccessDeniedException::class);
    $this->createResponsesClient($this->createMock(ClientInterface::class))->create(
      'https://api.x.ai/v1',
      '',
      ['model' => 'grok-4.5'],
    );
  }

  /**
   * Creates the client with a minimal string-translation service.
   */
  private function createResponsesClient(ClientInterface $http_client): XaiResponsesClient {
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

    return new XaiResponsesClient($http_client, $translation);
  }

}
