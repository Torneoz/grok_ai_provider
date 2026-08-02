<?php

declare(strict_types=1);

namespace Drupal\Tests\grok\Unit;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\ai\Exception\AiResponseErrorException;
use Drupal\grok\Service\XaiVideosClient;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Psr7\Uri;
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
   * Tests that a compatible gateway cannot point downloads at a private IP.
   */
  public function testRejectsPrivateVideoDownloadIp(): void {
    $method = new \ReflectionMethod(XaiVideosClient::class, 'assertAllowedDownloadUrl');

    $this->expectException(AiResponseErrorException::class);
    $this->expectExceptionMessage('private or reserved');
    $method->invoke(
      $this->createClient($this->createMock(ClientInterface::class)),
      'https://127.0.0.1/video.mp4',
      'https://127.0.0.1/v1',
    );
  }

  /**
   * Tests that every generated-video redirect is independently allowlisted.
   */
  public function testRejectsUntrustedVideoRedirect(): void {
    $http_client = $this->createMock(ClientInterface::class);
    $http_client->method('request')
      ->willReturnCallback(static function (string $method, string $url, array $options): Response {
        $options['allow_redirects']['on_redirect'](
          new Request($method, $url),
          new Response(302),
          new Uri('https://internal.example/video.mp4'),
        );
        return new Response(200, [], '0000ftypisom-video');
      });
    $method = new \ReflectionMethod(XaiVideosClient::class, 'download');

    $this->expectException(AiResponseErrorException::class);
    $this->expectExceptionMessage('outside the trusted asset hosts');
    $method->invoke(
      $this->createClient($http_client),
      'https://vidgen.x.ai/video.mp4',
      'https://api.x.ai/v1',
      30,
    );
  }

  /**
   * Tests that declared oversized video transfers are aborted at the headers.
   */
  public function testRejectsOversizedVideoDownload(): void {
    $http_client = $this->createMock(ClientInterface::class);
    $http_client->method('request')
      ->willReturnCallback(static function (string $method, string $url, array $options): Response {
        $options['on_headers'](new Response(200, [
          'Content-Length' => (string) (201 * 1024 * 1024),
        ]));
        return new Response(200, [], '0000ftypisom-video');
      });
    $method = new \ReflectionMethod(XaiVideosClient::class, 'download');

    $this->expectException(AiResponseErrorException::class);
    $this->expectExceptionMessage('exceeds the maximum allowed size');
    $method->invoke(
      $this->createClient($http_client),
      'https://vidgen.x.ai/video.mp4',
      'https://api.x.ai/v1',
      30,
    );
  }

  /**
   * Tests that every request receives only the remaining overall deadline.
   */
  public function testBoundsRemainingVideoDeadline(): void {
    $method = new \ReflectionMethod(XaiVideosClient::class, 'remainingSeconds');
    $client = $this->createClient($this->createMock(ClientInterface::class));

    self::assertSame(1, $method->invoke($client, microtime(TRUE) - 10));
    self::assertLessThanOrEqual(2, $method->invoke($client, microtime(TRUE) + 1));
    self::assertSame(3600, $method->invoke($client, microtime(TRUE) + 7200));
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
