<?php

declare(strict_types=1);

namespace Drupal\Tests\grok_ai_provider\Unit;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Extension\ExtensionPathResolver;
use Drupal\grok_ai_provider\Service\GrokCostEstimator;
use Drupal\grok_ai_provider\Service\XaiPricingScheduleFetcher;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;

/**
 * Tests retrieval of the trusted pricing schedule.
 */
final class XaiPricingScheduleFetcherTest extends TestCase {

  /**
   * Tests that valid remote pricing is normalized and described.
   */
  public function testFetchesAndValidatesPricing(): void {
    $client = $this->createMock(ClientInterface::class);
    $client->expects(self::once())
      ->method('request')
      ->with(
        'GET',
        XaiPricingScheduleFetcher::PRICING_URL,
        self::callback(static fn (array $options): bool =>
          $options['allow_redirects'] === FALSE &&
          $options['timeout'] === 10
        ),
      )
      ->willReturn(new Response(200, [], '[{"model":"grok-test","type":"tokens","input_per_million":1,"output_per_million":2}]'));

    $schedule = $this->createFetcher($client)->fetch();

    self::assertSame(XaiPricingScheduleFetcher::PRICING_URL, $schedule['source']);
    self::assertSame(1, $schedule['rows']);
    self::assertSame(hash('sha256', $schedule['json']), $schedule['hash']);
    self::assertStringContainsString("\n", $schedule['json']);
  }

  /**
   * Tests that malformed pricing never reaches the form.
   */
  public function testRejectsInvalidPricing(): void {
    $client = $this->createMock(ClientInterface::class);
    $client->method('request')
      ->willReturn(new Response(200, [], '{"unexpected":"object"}'));

    $this->expectException(\UnexpectedValueException::class);
    $this->createFetcher($client)->fetch();
  }

  /**
   * Tests that non-success responses are rejected without following redirects.
   */
  public function testRejectsUnexpectedResponse(): void {
    $client = $this->createMock(ClientInterface::class);
    $client->method('request')
      ->willReturn(new Response(302, ['Location' => 'https://example.com/pricing.json']));

    $this->expectException(\RuntimeException::class);
    $this->expectExceptionMessage('HTTP 302');
    $this->createFetcher($client)->fetch();
  }

  /**
   * Tests that an oversized response is rejected before its body is processed.
   */
  public function testRejectsOversizedResponse(): void {
    $client = $this->createMock(ClientInterface::class);
    $client->method('request')
      ->willReturn(new Response(200, ['Content-Length' => '262145'], '[]'));

    $this->expectException(\RuntimeException::class);
    $this->expectExceptionMessage('too large');
    $this->createFetcher($client)->fetch();
  }

  /**
   * Creates a fetcher with the real pricing validator.
   */
  private function createFetcher(ClientInterface $client): XaiPricingScheduleFetcher {
    return new XaiPricingScheduleFetcher(
      $client,
      new GrokCostEstimator(
        $this->createMock(ConfigFactoryInterface::class),
        $this->createMock(ExtensionPathResolver::class),
      ),
    );
  }

}
