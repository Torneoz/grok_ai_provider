<?php

declare(strict_types=1);

namespace Drupal\Tests\grok\Unit;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\ai\Exception\AiResponseErrorException;
use Drupal\grok\Service\XaiVideosClient;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;

/**
 * Tests the asynchronous xAI video transport.
 */
final class XaiVideosClientTest extends TestCase {

  /**
   * Tests video model discovery.
   */
  public function testListsVideoModels(): void {
    $http_client = $this->createMock(ClientInterface::class);
    $http_client->expects(self::once())
      ->method('request')
      ->with(
        'GET',
        'https://api.x.ai/v1/video-generation-models',
        self::callback(static fn(array $options): bool => $options['headers']['Authorization'] === 'Bearer secret'),
      )
      ->willReturn(new Response(200, [], '{"models":[{"id":"grok-imagine-video"}]}'));

    $models = $this->createClient($http_client)->listModels('https://api.x.ai/v1', 'secret');

    self::assertSame('grok-imagine-video', $models['models'][0]['id']);
  }

  /**
   * Tests starting, polling, and downloading a generated video.
   */
  public function testGeneratesAndDownloadsVideo(): void {
    $http_client = $this->createMock(ClientInterface::class);
    $call = 0;
    $http_client->expects(self::exactly(3))
      ->method('request')
      ->willReturnCallback(static function (string $method, string $url, array $options) use (&$call): Response {
        $call++;
        return match ($call) {
          1 => new Response(
            200,
            [],
            $method === 'POST'
              && $url === 'https://api.x.ai/v1/videos/generations'
              && $options['json']['model'] === 'grok-imagine-video'
                ? '{"request_id":"request-123"}'
                : '{}',
          ),
          2 => new Response(
            200,
            [],
            $method === 'GET' && $url === 'https://api.x.ai/v1/videos/request-123'
              ? '{"status":"done","model":"grok-imagine-video","video":{"url":"https://vidgen.x.ai/generated.mp4","duration":5}}'
              : '{}',
          ),
          3 => new Response(
            200,
            ['Content-Type' => 'video/mp4'],
            $method === 'GET' && $url === 'https://vidgen.x.ai/generated.mp4'
              ? '0000ftypisom-video'
              : '',
          ),
        };
      });

    $result = $this->createClient($http_client)->generate(
      'https://api.x.ai/v1',
      'secret',
      ['model' => 'grok-imagine-video', 'prompt' => 'A crocodile swimming'],
      30,
      0,
    );

    self::assertSame('done', $result['status']);
    self::assertSame('0000ftypisom-video', $result['_video_binary']);
  }

  /**
   * Tests that an upstream response cannot trigger an arbitrary HTTPS fetch.
   */
  public function testRejectsUntrustedVideoDownloadHost(): void {
    $http_client = $this->createMock(ClientInterface::class);
    $http_client->expects(self::exactly(2))
      ->method('request')
      ->willReturnOnConsecutiveCalls(
        new Response(200, [], '{"request_id":"request-123"}'),
        new Response(200, [], '{"status":"done","video":{"url":"https://internal.example/video.mp4"}}'),
      );

    $this->expectException(AiResponseErrorException::class);
    $this->createClient($http_client)->generate(
      'https://api.x.ai/v1',
      'secret',
      ['model' => 'grok-imagine-video', 'prompt' => 'Test'],
      30,
      0,
    );
  }

  /**
   * Creates the client with a minimal translation service.
   */
  private function createClient(ClientInterface $http_client): XaiVideosClient {
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
    return new XaiVideosClient($http_client, $translation);
  }

}
