<?php

declare(strict_types=1);

namespace Drupal\Tests\grok_ai_provider\Unit;

use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\ai\Exception\AiResponseErrorException;
use Drupal\grok_ai_provider\Service\XaiAudioClient;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;

/**
 * Tests the xAI REST voice transport.
 */
final class XaiAudioClientTest extends TestCase {

  /**
   * Tests voice discovery.
   */
  public function testListsVoices(): void {
    $http_client = $this->createMock(ClientInterface::class);
    $http_client->expects(self::once())
      ->method('request')
      ->with(
        'GET',
        'https://api.x.ai/v1/tts/voices',
        self::callback(static fn(array $options): bool => $options['headers']['Authorization'] === 'Bearer secret'),
      )
      ->willReturn(new Response(200, [], '{"voices":[{"voice_id":"eve","name":"Eve"}]}'));

    $response = $this->createAudioClient($http_client)->listVoices('https://api.x.ai/v1/', 'secret');

    self::assertSame('eve', $response['voices'][0]['voice_id']);
  }

  /**
   * Tests text-to-speech authentication, payload, and raw audio handling.
   */
  public function testSynthesizesSpeech(): void {
    $http_client = $this->createMock(ClientInterface::class);
    $http_client->expects(self::once())
      ->method('request')
      ->with(
        'POST',
        'https://api.x.ai/v1/tts',
        self::callback(static function (array $options): bool {
          return $options['headers']['Authorization'] === 'Bearer secret'
            && $options['json']['text'] === 'Hello'
            && $options['json']['voice_id'] === 'eve'
            && $options['json']['output_format']['codec'] === 'mp3';
        }),
      )
      ->willReturn(new Response(200, ['Content-Type' => 'audio/mpeg'], 'ID3audio'));

    $response = $this->createAudioClient($http_client)->synthesize(
      'https://api.x.ai/v1/',
      'secret',
      [
        'text' => 'Hello',
        'voice_id' => 'eve',
        'language' => 'en',
        'output_format' => ['codec' => 'mp3'],
      ],
    );

    self::assertSame('ID3audio', $response['binary']);
    self::assertSame('audio/mpeg', $response['content_type']);
  }

  /**
   * Tests that a successful HTTP response must still contain MP3 data.
   */
  public function testRejectsInvalidSynthesizedAudio(): void {
    $http_client = $this->createMock(ClientInterface::class);
    $http_client->method('request')
      ->willReturn(new Response(200, ['Content-Type' => 'text/html'], '<html>error</html>'));

    $this->expectException(AiResponseErrorException::class);
    $this->createAudioClient($http_client)->synthesize(
      'https://api.x.ai/v1/',
      'secret',
      ['text' => 'Hello', 'voice_id' => 'eve'],
    );
  }

  /**
   * Tests multipart speech-to-text requests with the file part last.
   */
  public function testTranscribesSpeech(): void {
    $http_client = $this->createMock(ClientInterface::class);
    $http_client->expects(self::once())
      ->method('request')
      ->with(
        'POST',
        'https://api.x.ai/v1/stt',
        self::callback(static function (array $options): bool {
          $parts = $options['multipart'];
          $names = array_column($parts, 'name');
          return $options['headers']['Authorization'] === 'Bearer secret'
            && end($names) === 'file'
            && $parts[array_key_last($parts)]['filename'] === 'speech.mp3'
            && count(array_filter($names, static fn(string $name): bool => $name === 'keyterm')) === 2;
        }),
      )
      ->willReturn(new Response(200, [], '{"text":"Hello world","duration":1.2}'));

    $response = $this->createAudioClient($http_client)->transcribe(
      'https://api.x.ai/v1/',
      'secret',
      [
        'language' => 'en',
        'format' => TRUE,
        'keyterm' => ['Drupal', 'Grok'],
      ],
      [
        'binary' => 'ID3audio',
        'filename' => 'speech.mp3',
        'mime_type' => 'audio/mpeg',
      ],
    );

    self::assertSame('Hello world', $response['text']);
    self::assertSame(1.2, $response['duration']);
  }

  /**
   * Creates the client with a test translator.
   */
  private function createAudioClient(ClientInterface $http_client): XaiAudioClient {
    return new XaiAudioClient(
      $http_client,
      $this->createMock(TranslationInterface::class),
    );
  }

}
