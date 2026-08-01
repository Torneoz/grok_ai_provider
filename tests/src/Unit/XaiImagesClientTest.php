<?php

declare(strict_types=1);

namespace Drupal\Tests\grok_ai_provider\Unit;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\ai\Exception\AiAccessDeniedException;
use Drupal\ai\Exception\AiBadRequestException;
use Drupal\ai\Exception\AiRateLimitException;
use Drupal\ai\Exception\AiResponseErrorException;
use Drupal\grok_ai_provider\Service\XaiImagesClient;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Tests the HTTP boundary for the xAI image API.
 */
final class XaiImagesClientTest extends TestCase {

  /**
   * Tests image generation authentication and payload handling.
   */
  public function testGeneratesImages(): void {
    $http_client = $this->createMock(ClientInterface::class);
    $http_client->expects(self::once())
      ->method('request')
      ->with(
        'POST',
        'https://api.x.ai/v1/images/generations',
        self::callback(static function (array $options): bool {
          return $options['headers']['Authorization'] === 'Bearer secret'
            && $options['json']['model'] === 'grok-imagine-image-quality'
            && $options['json']['response_format'] === 'b64_json';
        }),
      )
      ->willReturn(new Response(200, [], '{"data":[{"b64_json":"aW1hZ2U="}]}'));

    $response = $this->createImagesClient($http_client)->generate(
      'https://api.x.ai/v1/',
      'secret',
      [
        'model' => 'grok-imagine-image-quality',
        'prompt' => 'A crocodile',
        'response_format' => 'b64_json',
      ],
    );

    self::assertSame('aW1hZ2U=', $response['data'][0]['b64_json']);
  }

  /**
   * Tests image editing authentication and payload handling.
   */
  public function testEditsImages(): void {
    $http_client = $this->createMock(ClientInterface::class);
    $http_client->expects(self::once())
      ->method('request')
      ->with(
        'POST',
        'https://api.x.ai/v1/images/edits',
        self::callback(static function (array $options): bool {
          return $options['headers']['Authorization'] === 'Bearer secret'
            && $options['json']['model'] === 'grok-imagine-image-quality'
            && str_starts_with($options['json']['image']['url'], 'data:image/png;base64,')
            && $options['json']['response_format'] === 'b64_json';
        }),
      )
      ->willReturn(new Response(200, [], '{"data":[{"b64_json":"ZWRpdGVk"}]}'));

    $response = $this->createImagesClient($http_client)->edit(
      'https://api.x.ai/v1/',
      'secret',
      [
        'model' => 'grok-imagine-image-quality',
        'prompt' => 'Add stadium lights',
        'image' => ['url' => 'data:image/png;base64,aW1hZ2U='],
        'response_format' => 'b64_json',
      ],
    );

    self::assertSame('ZWRpdGVk', $response['data'][0]['b64_json']);
  }

  /**
   * Tests image-model discovery.
   */
  public function testListsImageModels(): void {
    $http_client = $this->createMock(ClientInterface::class);
    $http_client->expects(self::once())
      ->method('request')
      ->with(
        'GET',
        'https://api.x.ai/v1/image-generation-models',
        self::callback(static fn(array $options): bool => $options['headers']['Authorization'] === 'Bearer secret'),
      )
      ->willReturn(new Response(200, [], '{"models":[{"id":"grok-imagine-image-quality"}]}'));

    $response = $this->createImagesClient($http_client)->listModels(
      'https://api.x.ai/v1',
      'secret',
    );

    self::assertSame('grok-imagine-image-quality', $response['models'][0]['id']);
  }

  /**
   * Tests provider-neutral HTTP exception mapping.
   */
  #[DataProvider('errorMappingProvider')]
  public function testMapsHttpErrors(int $status, string $expected_exception): void {
    $http_client = $this->createMock(ClientInterface::class);
    $http_client->method('request')->willThrowException(new RequestException(
      'Request failed',
      new Request('POST', 'https://api.x.ai/v1/images/generations'),
      new Response($status, [], '{"error":{"message":"Useful image API detail"}}'),
    ));

    $this->expectException($expected_exception);
    $this->expectExceptionMessage('Useful image API detail');
    $this->createImagesClient($http_client)->generate(
      'https://api.x.ai/v1',
      'secret',
      ['model' => 'grok-imagine-image-quality', 'prompt' => 'A crocodile'],
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
    $this->createImagesClient($this->createMock(ClientInterface::class))->listModels(
      'https://api.x.ai/v1',
      '',
    );
  }

  /**
   * Creates the client with a minimal string-translation service.
   */
  private function createImagesClient(ClientInterface $http_client): XaiImagesClient {
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

    return new XaiImagesClient($http_client, $translation);
  }

}
