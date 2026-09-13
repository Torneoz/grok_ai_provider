<?php

declare(strict_types=1);

namespace Drupal\Tests\grok\Unit;

use Drupal\Component\Utility\Html;
use Drupal\ai\Service\HostnameFilter;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Component\Uuid\UuidInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Component\Plugin\Factory\FactoryInterface;
use Drupal\Core\Form\FormState;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\Path\CurrentPathStack;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\ai\Base\AiProviderClientBase;
use Drupal\ai\AiProviderPluginManager;
use Drupal\ai\Enum\AiModelCapability;
use Drupal\ai\OperationType\Chat\ChatInput;
use Drupal\ai\OperationType\Chat\ChatMessage;
use Drupal\ai\OperationType\Chat\ChatOutput;
use Drupal\ai\Service\AiProviderFormHelper;
use Drupal\ai_api_explorer\ExplorerHelper;
use Drupal\grok\Plugin\AiApiExplorer\PdfChatExplorer;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Tests PDF Explorer controls and uploads with the real Drupal AI form helper.
 */
final class PdfChatExplorerTest extends TestCase {

  /**
   * Temporary upload paths.
   *
   * @var string[]
   */
  private array $uploads = [];

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    foreach ($this->uploads as $path) {
      unlink($path);
    }
    parent::tearDown();
  }

  /**
   * Minimum dependencies can render Drupal HTML without serializer errors.
   */
  public function testDrupalHtmlSerialization(): void {
    self::assertStringContainsString('<p>Answer</p>', Html::normalize('<p>Answer</p>'));
  }

  /**
   * Switching providers repeatedly keeps models and configuration in sync.
   */
  public function testProviderSwitching(): void {
    $explorer = $this->explorer(new Request());
    self::assertTrue($explorer->isActive());
    $state = new FormState();
    foreach (['first', 'second', 'third', 'first'] as $id) {
      $state->setValue('pdf_ai_provider', $id);
      $form = $explorer->buildForm([], $state);
      self::assertSame(['first', 'second', 'third'], array_keys($form['left']['pdf_ai_provider']['#options']));
      self::assertSame($id . '-pdf', $state->getValue('pdf_ai_model'));
      self::assertSame([$id . '-pdf' => $id . '-pdf'], $form['left']['pdf_ajax_prefix']['pdf_ai_model']['#options']);
      self::assertArrayHasKey('pdf_ajax_prefix_configuration_' . $id, $form['left']['pdf_ajax_prefix']);
      self::assertSame($form['left'], PdfChatExplorer::reloadProviderControls($form, $state));
      self::assertTrue($state->isRebuilding());
      self::assertSame('pdf-explorer-controls', $form['left']['pdf_ai_provider']['#ajax']['wrapper']);
    }
  }

  /**
   * Multiple PDFs reach the provider and unsafe answer markup is removed.
   */
  public function testUploadsAndSafeResponse(): void {
    $request = new Request([], [], [], [], [
      'pdfs' => [
        $this->upload('%PDF-1.7 first', 'first.pdf'),
        $this->upload('%PDF-1.7 second', 'second.pdf'),
      ],
    ]);
    $explorer = $this->explorer($request, TRUE);
    $state = new FormState();
    $state->setValues([
      'pdf_ai_provider' => 'first',
      'pdf_ai_model' => 'first-pdf',
      'prompt' => 'Compare',
      'system_prompt' => 'Cite sources',
    ]);
    $form = $explorer->buildForm([], $state);
    $right = $explorer->getResponse($form, $state);
    $answer = $right['response']['#context']['ai_response'];
    $markup = $answer['text']['content']['#markup'];
    self::assertStringContainsString('<p>Answer</p>', $markup);
    self::assertStringNotContainsString('<script', $markup);
    self::assertStringNotContainsString('onclick', $markup);
    self::assertStringNotContainsString('javascript:', $markup);
    self::assertCount(2, $answer['diagnostics']['files']['#items']);
  }

  /**
   * Invalid and excessive uploads fail before a provider is invoked.
   */
  public function testRejectsInvalidUploads(): void {
    foreach ([[], [$this->upload('plain text', 'fake.pdf')], array_fill(0, 6, $this->upload('%PDF-1.7', 'many.pdf'))] as $files) {
      $explorer = $this->explorer(new Request([], [], [], [], ['pdfs' => $files]));
      $form = [];
      $result = $explorer->getResponse($form, new FormState());
      self::assertSame('PDF investigation failed', (string) $result['response']['#context']['ai_response']['heading']['#value']);
      self::assertNotEmpty($result['response']['#context']['ai_response']['message']['#plain_text']);
    }
  }

  /**
   * Builds the plugin with authenticated and ineligible provider fixtures.
   */
  private function explorer(Request $request, bool $expect_chat = FALSE): PdfChatExplorer {
    $translation = $this->createMock(TranslationInterface::class);
    $translation->method('translateString')->willReturnCallback(static fn(TranslatableMarkup $text): string => strtr($text->getUntranslatedString(), $text->getArguments()));
    $providers = [];
    foreach (['first', 'second', 'third', 'unusable', 'no_pdf'] as $id) {
      $provider = $this->createMock(PdfExplorerTestProvider::class);
      $provider->method('isUsable')->willReturn($id !== 'unusable');
      $provider->method('getConfiguredModels')->willReturnCallback(static function ($operation, array $capabilities = []) use ($id): array {
        if ($capabilities !== []) {
          self::assertSame([AiModelCapability::ChatWithPdf], $capabilities);
          return $id === 'no_pdf' ? [] : [$id . '-pdf' => $id . '-pdf'];
        }
        return [$id . '-text' => $id . '-text', $id . '-pdf' => $id . '-pdf'];
      });
      $provider->method('getAvailableConfiguration')->willReturn([
        $id => ['type' => 'string', 'label' => 'Provider setting', 'default' => $id],
      ]);
      if ($expect_chat && $id === 'first') {
        $provider->expects(self::once())->method('chat')->with(self::callback(static function (ChatInput $input): bool {
          self::assertSame('Cite sources', $input->getSystemPrompt());
          self::assertFalse($input->isStreamedOutput());
          self::assertCount(2, $input->getMessages()[0]->getFiles());
          return TRUE;
        }), 'first-pdf', ['pdf_explorer', 'ai_api_explorer'])->willReturn(new ChatOutput(new ChatMessage('assistant', '<p>Answer</p><script>alert(1)</script><a onclick="bad()" href="javascript:bad()">link</a>'), [], []));
      }
      else {
        $provider->expects(self::never())->method('chat');
      }
      $providers[$id] = $provider;
    }
    $manager = (new \ReflectionClass(AiProviderPluginManager::class))->newInstanceWithoutConstructor();
    $factory = $this->createMock(FactoryInterface::class);
    $factory->method('createInstance')->willReturnCallback(static fn(string $id) => $providers[$id]);
    $config = $this->createMock(ImmutableConfig::class);
    $config_factory = $this->createMock(ConfigFactoryInterface::class);
    $config_factory->method('get')->willReturn($config);
    foreach ([
      'definitions' => array_fill_keys(array_keys($providers), ['label' => 'Provider']),
      'factory' => $factory,
      'configFactory' => $config_factory,
      'eventDispatcher' => new EventDispatcher(),
      'loggerFactory' => $this->createMock(LoggerChannelFactoryInterface::class),
      'uuid' => $this->createMock(UuidInterface::class),
      'cacheBackend' => $this->createMock(CacheBackendInterface::class),
      'hostnameFilter' => $this->createMock(HostnameFilter::class),
    ] as $name => $value) {
      (new \ReflectionProperty($manager, $name))->setValue($manager, $value);
    }
    $helper = $this->getMockBuilder(AiProviderFormHelper::class)
      ->setConstructorArgs([
        $manager,
        $this->createMock(CurrentPathStack::class),
        $this->createMock(MessengerInterface::class),
      ])
      ->onlyMethods(['getAiProvidersOptions'])->getMock();
    $helper->method('getAiProvidersOptions')->willReturn(array_combine(array_keys($providers), array_keys($providers)));
    $helper->setStringTranslation($translation);
    $stack = new RequestStack();
    $stack->push($request);
    $explorer = new PdfChatExplorer([], 'grok_pdf_explorer', [], $stack, $helper, $this->createMock(ExplorerHelper::class), $manager);
    $explorer->setStringTranslation($translation);
    return $explorer;
  }

  /**
   * Creates an isolated Symfony test upload.
   */
  private function upload(string $content, string $name): UploadedFile {
    $path = tempnam(sys_get_temp_dir(), 'grok-pdf-test-');
    $this->uploads[] = $path;
    file_put_contents($path, $content);
    return new UploadedFile($path, $name, 'application/pdf', NULL, TRUE);
  }

}

/**
 * Combines provider configuration and chat contracts for test doubles.
 */
abstract class PdfExplorerTestProvider extends AiProviderClientBase {

  /**
   * Returns an answer without exercising generation middleware in form tests.
   */
  abstract public function chat(ChatInput $input, string $model_id, array $tags): ChatOutput;

}
