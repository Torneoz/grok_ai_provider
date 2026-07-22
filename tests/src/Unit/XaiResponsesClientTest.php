<?php

declare(strict_types=1);

namespace Drupal\Tests\grok_ai_provider\Unit;

use Drupal\grok_ai_provider\Service\XaiResponsesClient;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;

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

    $response = (new XaiResponsesClient($http_client))->create(
      'https://api.x.ai/v1/',
      'secret',
      ['model' => 'grok-4.5'],
    );

    self::assertSame('resp_123', $response['id']);
  }

}
