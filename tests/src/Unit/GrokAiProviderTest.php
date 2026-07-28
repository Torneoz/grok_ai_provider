<?php

declare(strict_types=1);

namespace Drupal\Tests\grok_ai_provider\Unit;

use Drupal\ai\Enum\AiModelCapability;
use Drupal\ai\Exception\AiBadRequestException;
use Drupal\ai\Exception\AiResponseErrorException;
use Drupal\ai\OperationType\Chat\ChatMessage;
use Drupal\ai\OperationType\Chat\ChatOutput;
use Drupal\ai\OperationType\GenericType\AudioFile;
use Drupal\ai\OperationType\GenericType\ImageFile;
use Drupal\ai\OperationType\ImageClassification\ImageClassificationInput;
use Drupal\ai\OperationType\ImageClassification\ImageClassificationOutput;
use Drupal\ai\OperationType\ImageToImage\ImageToImageInput;
use Drupal\ai\OperationType\ImageToImage\ImageToImageOutput;
use Drupal\ai\OperationType\ImageToVideo\ImageToVideoInput;
use Drupal\ai\OperationType\ImageToVideo\ImageToVideoOutput;
use Drupal\ai\OperationType\Moderation\ModerationOutput;
use Drupal\ai\OperationType\SpeechToText\SpeechToTextInput;
use Drupal\ai\OperationType\SpeechToText\SpeechToTextOutput;
use Drupal\ai\OperationType\TextToSpeech\TextToSpeechOutput;
use Drupal\grok_ai_provider\Plugin\AiProvider\GrokAiProvider;
use PHPUnit\Framework\TestCase;

/**
 * Tests Grok-specific model filtering and settings.
 */
final class GrokAiProviderTest extends TestCase {

  /**
   * Tests that model discovery retains only Grok models.
   */
  public function testFiltersNonGrokModels(): void {
    $models = $this->filterModels([
      ['id' => 'grok-4.5-latest'],
      ['id' => 'grok-3-mini'],
      ['id' => 'gpt-5'],
      ['id' => ''],
    ]);

    self::assertSame([
      'grok-4.5-latest' => 'grok-4.5-latest',
      'grok-3-mini' => 'grok-3-mini',
    ], $models);
  }

  /**
   * Tests capability-specific model filtering.
   */
  public function testFiltersCapabilities(): void {
    $models = [
      ['id' => 'grok-2'],
      ['id' => 'grok-2-vision-1212'],
      ['id' => 'grok-3-mini'],
      ['id' => 'grok-4.5-latest'],
    ];

    self::assertSame([
      'grok-2-vision-1212' => 'grok-2-vision-1212',
      'grok-4.5-latest' => 'grok-4.5-latest',
    ], $this->filterModels($models, [AiModelCapability::ChatWithImageVision]));
    self::assertSame([
      'grok-4.5-latest' => 'grok-4.5-latest',
    ], $this->filterModels($models, [AiModelCapability::ChatCombinedToolsAndStructuredResponse]));
    self::assertSame([], $this->filterModels($models, [AiModelCapability::ChatWithAudio]));
  }

  /**
   * Tests image-model discovery filtering.
   */
  public function testFiltersImageModels(): void {
    $method = new \ReflectionMethod(GrokAiProvider::class, 'filterImageModels');
    $models = $method->invoke($this->newProviderWithoutConstructor(), [
      ['id' => 'grok-imagine-image-quality'],
      ['id' => 'grok-imagine-image'],
      ['id' => 'grok-imagine-video'],
      ['id' => 'grok-4.5-latest'],
    ]);

    self::assertSame([
      'grok-imagine-image' => 'grok-imagine-image',
      'grok-imagine-image-quality' => 'grok-imagine-image-quality',
    ], $models);
  }

  /**
   * Tests base64 image normalization and MIME detection.
   */
  public function testNormalizesImageOutput(): void {
    $method = new \ReflectionMethod(GrokAiProvider::class, 'normalizeImageOutput');
    // A valid 1x1 transparent PNG.
    $png = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=';
    $output = $method->invoke($this->newProviderWithoutConstructor(), [
      'data' => [
        [
          'b64_json' => $png,
          'revised_prompt' => 'A transparent pixel.',
        ],
      ],
      'usage' => ['cost_in_usd_ticks' => 200000000],
    ]);

    self::assertCount(1, $output->getNormalized());
    self::assertSame('image/png', $output->getNormalized()[0]->getMimeType());
    self::assertSame('grok-image-1.png', $output->getNormalized()[0]->getFilename());
    self::assertSame('images', $output->getMetadata()['transport']);
    self::assertSame(200000000, $output->getMetadata()['usage']['cost_in_usd_ticks']);
  }

  /**
   * Tests image-edit payload construction.
   */
  public function testBuildsImageToImagePayload(): void {
    $provider = $this->newProviderWithoutConstructor();
    $configuration = new \ReflectionProperty(GrokAiProvider::class, 'configuration');
    $configuration->setValue($provider, [
      'aspect_ratio' => '16:9',
      'resolution' => '2k',
    ]);
    $method = new \ReflectionMethod(GrokAiProvider::class, 'buildImageToImagePayload');
    $binary = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=', TRUE);
    self::assertIsString($binary);
    $input = new ImageToImageInput(new ImageFile($binary, 'image/png', 'source.png'));
    $input->setPrompt('Add stadium lights');
    $payload = $method->invoke($provider, $input, GrokAiProvider::DEFAULT_IMAGE_MODEL);

    self::assertSame('grok-imagine-image-quality', $payload['model']);
    self::assertSame('Add stadium lights', $payload['prompt']);
    self::assertStringStartsWith('data:image/png;base64,', $payload['image']['url']);
    self::assertSame('image_url', $payload['image']['type']);
    self::assertSame('b64_json', $payload['response_format']);
    self::assertSame('2k', $payload['resolution']);
  }

  /**
   * Tests native Drupal AI image-edit output normalization.
   */
  public function testNormalizesImageToImageOutput(): void {
    $method = new \ReflectionMethod(GrokAiProvider::class, 'normalizeImageToImageOutput');
    $png = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=';
    $output = $method->invoke($this->newProviderWithoutConstructor(), [
      'data' => [['b64_json' => $png]],
    ]);

    self::assertInstanceOf(ImageToImageOutput::class, $output);
    self::assertSame('image/png', $output->getNormalized()[0]->getMimeType());
    self::assertSame('image_edits', $output->getMetadata()['transport']);
  }

  /**
   * Tests Drupal AI's image-edit prompt and mask feature flags.
   */
  public function testImageToImageFeatureFlags(): void {
    $provider = $this->newProviderWithoutConstructor();

    self::assertTrue($provider->hasImageToImagePrompt(GrokAiProvider::DEFAULT_IMAGE_MODEL));
    self::assertTrue($provider->requiresImageToImagePrompt(GrokAiProvider::DEFAULT_IMAGE_MODEL));
    self::assertFalse($provider->hasImageToImageMask(GrokAiProvider::DEFAULT_IMAGE_MODEL));
    self::assertFalse($provider->requiresImageToImageMask(GrokAiProvider::DEFAULT_IMAGE_MODEL));
  }

  /**
   * Tests image-classification input and structured output normalization.
   */
  public function testNormalizesImageClassification(): void {
    $provider = $this->newProviderWithoutConstructor();
    $input_method = new \ReflectionMethod(GrokAiProvider::class, 'normalizeImageClassificationInput');
    $binary = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=', TRUE);
    self::assertIsString($binary);
    [$image, $labels] = $input_method->invoke($provider, new ImageClassificationInput(
      new ImageFile($binary, 'image/png', 'source.png'),
      [' flag ', 'lightning', 'flag'],
    ));
    self::assertSame('image/png', $image->getMimeType());
    self::assertSame(['flag', 'lightning'], $labels);

    $output_method = new \ReflectionMethod(GrokAiProvider::class, 'normalizeImageClassificationOutput');
    $output = $output_method->invoke(
      $provider,
      new ChatOutput(
        new ChatMessage('assistant', '{"classifications":[{"label":"flag","confidence":0.97},{"label":"lightning","confidence":0.4}]}'),
        ['id' => 'chatcmpl_classify'],
        ['transport' => 'chat_completions'],
      ),
      'grok-4.5-latest',
      $labels,
    );

    self::assertInstanceOf(ImageClassificationOutput::class, $output);
    self::assertSame('flag', $output->getNormalized()[0]->getLabel());
    self::assertSame(0.97, $output->getNormalized()[0]->getConfidenceScore());
    self::assertSame('image_classification', $output->getMetadata()['operation']);
    self::assertTrue($output->getMetadata()['model_based']);
  }

  /**
   * Tests structured model-based moderation normalization.
   */
  public function testNormalizesModeration(): void {
    $method = new \ReflectionMethod(GrokAiProvider::class, 'normalizeModerationOutput');
    $output = $method->invoke(
      $this->newProviderWithoutConstructor(),
      new ChatOutput(
        new ChatMessage('assistant', '{"flagged":true,"categories":["violence"],"explanation":"A violent threat.","confidence":0.91}'),
        ['id' => 'chatcmpl_moderate'],
        ['transport' => 'chat_completions'],
      ),
      'grok-4.5-latest',
    );

    self::assertInstanceOf(ModerationOutput::class, $output);
    self::assertTrue((bool) $output->getNormalized()->isFlagged());
    self::assertSame(['violence'], $output->getNormalized()->getInformation()['categories']);
    self::assertFalse($output->getNormalized()->getInformation()['dedicated_moderation_endpoint']);
    self::assertSame('moderation', $output->getMetadata()['operation']);
  }

  /**
   * Tests that both derived Drupal AI operations are advertised.
   */
  public function testAdvertisesClassificationAndModeration(): void {
    $operations = $this->newProviderWithoutConstructor()->getSupportedOperationTypes();

    self::assertContains('image_classification', $operations);
    self::assertContains('moderation', $operations);
  }

  /**
   * Tests voice discovery filtering and labels.
   */
  public function testFiltersVoices(): void {
    $method = new \ReflectionMethod(GrokAiProvider::class, 'filterVoices');
    $voices = $method->invoke($this->newProviderWithoutConstructor(), [
      ['voice_id' => 'EVE', 'name' => 'Eve'],
      ['voice_id' => 'ara', 'name' => 'Ara'],
      ['voice_id' => '../invalid', 'name' => 'Invalid'],
      ['voice_id' => '', 'name' => 'Missing'],
    ]);

    self::assertSame([
      'ara' => 'Ara (ara)',
      'eve' => 'Eve (eve)',
    ], $voices);
  }

  /**
   * Tests native Drupal AI text-to-speech output normalization.
   */
  public function testNormalizesTextToSpeech(): void {
    $method = new \ReflectionMethod(GrokAiProvider::class, 'normalizeTextToSpeechOutput');
    $mp3 = "\xFF\xFB\x90\x64" . str_repeat("\0", 413);
    $output = $method->invoke($this->newProviderWithoutConstructor(), [
      'binary' => $mp3,
      'content_type' => 'audio/mpeg',
    ], 'eve', 'en');

    self::assertInstanceOf(TextToSpeechOutput::class, $output);
    self::assertSame('audio/mpeg', $output->getNormalized()[0]->getMimeType());
    self::assertSame('grok-speech.mp3', $output->getNormalized()[0]->getFilename());
    self::assertSame('tts', $output->getMetadata()['transport']);
    self::assertSame('eve', $output->getMetadata()['voice_id']);
  }

  /**
   * Tests native Drupal AI speech-to-text input and output normalization.
   */
  public function testNormalizesSpeechToText(): void {
    $provider = $this->newProviderWithoutConstructor();
    $input_method = new \ReflectionMethod(GrokAiProvider::class, 'normalizeSpeechToTextInput');
    $mp3 = "\xFF\xFB\x90\x64" . str_repeat("\0", 413);
    $audio = $input_method->invoke($provider, new SpeechToTextInput(
      new AudioFile($mp3, 'audio/mpeg', 'speech.mp3'),
    ));
    self::assertSame('audio/mpeg', $audio->getMimeType());

    $output_method = new \ReflectionMethod(GrokAiProvider::class, 'normalizeSpeechToTextOutput');
    $output = $output_method->invoke($provider, [
      'text' => 'Hello from Drupal.',
      'duration' => 1.2,
      'words' => [['text' => 'Hello', 'start' => 0, 'end' => 0.4]],
    ], GrokAiProvider::DEFAULT_SPEECH_TO_TEXT_MODEL);

    self::assertInstanceOf(SpeechToTextOutput::class, $output);
    self::assertSame('Hello from Drupal.', $output->getNormalized());
    self::assertSame(1.2, $output->getMetadata()['duration']);
    self::assertSame('stt', $output->getMetadata()['transport']);
  }

  /**
   * Tests audio operation advertisement and key-term normalization.
   */
  public function testAdvertisesAudioOperations(): void {
    $provider = $this->newProviderWithoutConstructor();
    $operations = $provider->getSupportedOperationTypes();
    $method = new \ReflectionMethod(GrokAiProvider::class, 'normalizeKeyterms');

    self::assertContains('text_to_speech', $operations);
    self::assertContains('speech_to_text', $operations);
    self::assertSame(['Drupal', 'Grok'], $method->invoke($provider, "Drupal, Grok\nDrupal"));
  }

  /**
   * Tests MP4 video normalization and metadata.
   */
  public function testNormalizesVideoOutput(): void {
    $method = new \ReflectionMethod(GrokAiProvider::class, 'normalizeVideoOutput');
    $output = $method->invoke($this->newProviderWithoutConstructor(), [
      'status' => 'done',
      'model' => 'grok-imagine-video',
      'video' => [
        'duration' => 5,
        'respect_moderation' => TRUE,
      ],
      'usage' => ['cost_in_usd_ticks' => 2500000000],
      '_video_binary' => '0000ftypisom-video',
    ]);

    self::assertSame('video/mp4', $output->getNormalized()[0]->getMimeType());
    self::assertSame('grok-video.mp4', $output->getNormalized()[0]->getFilename());
    self::assertSame('videos', $output->getMetadata()['transport']);
    self::assertSame(5, $output->getMetadata()['duration']);
    self::assertArrayNotHasKey('_video_binary', $output->getRawOutput());
  }

  /**
   * Tests image-to-video payload construction.
   */
  public function testBuildsImageToVideoPayload(): void {
    $provider = $this->newProviderWithoutConstructor();
    $configuration = new \ReflectionProperty(GrokAiProvider::class, 'configuration');
    $configuration->setValue($provider, [
      'prompt' => 'Make the water flow.',
      'duration' => 12,
      'aspect_ratio' => '16:9',
      'resolution' => '1080p',
    ]);
    $method = new \ReflectionMethod(GrokAiProvider::class, 'buildImageToVideoPayload');
    // A valid 1x1 transparent PNG.
    $binary = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=', TRUE);
    self::assertIsString($binary);
    $payload = $method->invoke(
      $provider,
      new ImageToVideoInput(new ImageFile($binary, 'image/png', 'source.png')),
      GrokAiProvider::DEFAULT_IMAGE_TO_VIDEO_MODEL,
    );

    self::assertSame('grok-imagine-video-1.5', $payload['model']);
    self::assertSame('Make the water flow.', $payload['prompt']);
    self::assertStringStartsWith('data:image/png;base64,', $payload['image']['url']);
    self::assertSame(12, $payload['duration']);
    self::assertSame('1080p', $payload['resolution']);
  }

  /**
   * Tests native Drupal AI image-to-video output normalization.
   */
  public function testNormalizesImageToVideoOutput(): void {
    $method = new \ReflectionMethod(GrokAiProvider::class, 'normalizeImageToVideoOutput');
    $output = $method->invoke($this->newProviderWithoutConstructor(), [
      'status' => 'done',
      'model' => GrokAiProvider::DEFAULT_IMAGE_TO_VIDEO_MODEL,
      'video' => ['duration' => 12],
      '_video_binary' => '0000ftypisom-video',
    ]);

    self::assertInstanceOf(ImageToVideoOutput::class, $output);
    self::assertSame('video/mp4', $output->getNormalized()[0]->getMimeType());
    self::assertSame(GrokAiProvider::DEFAULT_IMAGE_TO_VIDEO_MODEL, $output->getMetadata()['model']);
    self::assertArrayNotHasKey('_video_binary', $output->getRawOutput());
  }

  /**
   * Tests that non-MP4 video output is rejected.
   */
  public function testRejectsInvalidVideoOutput(): void {
    $method = new \ReflectionMethod(GrokAiProvider::class, 'normalizeVideoOutput');
    $this->expectException(AiResponseErrorException::class);
    $method->invoke($this->newProviderWithoutConstructor(), [
      '_video_binary' => 'not an mp4 video',
    ]);
  }

  /**
   * Tests that non-image base64 data is rejected.
   */
  public function testRejectsInvalidImageOutput(): void {
    $method = new \ReflectionMethod(GrokAiProvider::class, 'normalizeImageOutput');
    $this->expectException(AiResponseErrorException::class);
    $method->invoke($this->newProviderWithoutConstructor(), [
      'data' => [['b64_json' => base64_encode('not an image')]],
    ]);
  }

  /**
   * Tests best-effort transparent-background prompt guidance.
   */
  public function testBuildsTransparentBackgroundPrompt(): void {
    $provider = $this->newProviderWithoutConstructor();
    $configuration = new \ReflectionProperty(GrokAiProvider::class, 'configuration');
    $configuration->setValue($provider, ['transparent_background' => TRUE]);
    $method = new \ReflectionMethod(GrokAiProvider::class, 'buildImagePrompt');

    $prompt = $method->invoke($provider, 'A crocodile mascot');

    self::assertStringStartsWith('A crocodile mascot', $prompt);
    self::assertStringContainsString('real alpha channel', $prompt);
    self::assertStringContainsString('no checkerboard pattern', $prompt);
  }

  /**
   * Tests that reasoning settings are added only to compatible families.
   */
  public function testReasoningSettings(): void {
    $provider = $this->newProviderWithoutConstructor();

    self::assertArrayHasKey('reasoning_effort', $provider->getModelSettings('grok-4.5-latest'));
    self::assertArrayNotHasKey('reasoning_effort', $provider->getModelSettings('grok-3-mini'));
    self::assertArrayNotHasKey('web_search', $provider->getModelSettings('grok-3-mini', [
      'web_search' => ['type' => 'boolean'],
    ]));
    $video_settings = $provider->getModelSettings('grok-imagine-video', [
      'duration' => ['type' => 'integer'],
      'aspect_ratio' => ['type' => 'string'],
      'resolution' => ['type' => 'string'],
    ]);
    self::assertSame('Video duration', (string) $video_settings['duration']['label']);
    self::assertStringContainsString('generated video', (string) $video_settings['aspect_ratio']['description']);
    $image_video_settings = $provider->getModelSettings('grok-imagine-video-1.5', [
      'prompt' => ['type' => 'string_long'],
      'resolution' => ['type' => 'string'],
    ]);
    self::assertSame('Animation prompt', (string) $image_video_settings['prompt']['label']);
    self::assertStringContainsString('generated video', (string) $image_video_settings['resolution']['description']);
  }

  /**
   * Tests that the Chat Explorer's zero seed is treated as unset.
   */
  public function testNormalizesChatSeed(): void {
    $method = new \ReflectionMethod(GrokAiProvider::class, 'normalizeRequestConfiguration');
    $provider = $this->newProviderWithoutConstructor();

    self::assertArrayNotHasKey('seed', $method->invoke($provider, ['seed' => 0]));
    self::assertArrayNotHasKey('seed', $method->invoke($provider, ['seed' => -1]));
    self::assertArrayNotHasKey('seed', $method->invoke($provider, ['seed' => '']));
    self::assertSame(42, $method->invoke($provider, ['seed' => '42'])['seed']);
  }

  /**
   * Tests Responses text, metadata, citations, and usage normalization.
   */
  public function testNormalizesResponsesOutput(): void {
    $method = new \ReflectionMethod(GrokAiProvider::class, 'normalizeResponsesOutput');
    $output = $method->invoke($this->newProviderWithoutConstructor(), [
      'id' => 'resp_123',
      'status' => 'completed',
      'output' => [
        ['type' => 'web_search_call', 'id' => 'search_1'],
        [
          'type' => 'message',
          'content' => [
            [
              'type' => 'output_text',
              'text' => 'A researched answer.',
              'annotations' => [['type' => 'url_citation', 'url' => 'https://example.com']],
            ],
          ],
        ],
      ],
      'usage' => [
        'input_tokens' => 10,
        'output_tokens' => 7,
        'total_tokens' => 17,
        'output_tokens_details' => ['reasoning_tokens' => 3],
        'input_tokens_details' => ['cached_tokens' => 2],
      ],
    ]);

    self::assertSame('A researched answer.', $output->getNormalized()->getText());
    self::assertSame('resp_123', $output->getMetadata()['response_id']);
    self::assertSame('https://example.com', $output->getMetadata()['annotations'][0]['url']);
    self::assertSame(['https://example.com'], $output->getMetadata()['citations']);
    self::assertSame(17, $output->getTokenUsage()->total);
    self::assertSame(3, $output->getTokenUsage()->reasoning);
  }

  /**
   * Tests that incomplete Responses output is never presented as successful.
   */
  public function testRejectsIncompleteResponse(): void {
    $method = new \ReflectionMethod(GrokAiProvider::class, 'normalizeResponsesOutput');
    $this->expectException(AiResponseErrorException::class);
    $this->expectExceptionMessage('incomplete');
    $method->invoke($this->newProviderWithoutConstructor(), [
      'id' => 'resp_incomplete',
      'status' => 'incomplete',
      'incomplete_details' => ['reason' => 'max_output_tokens'],
      'output' => [],
    ]);
  }

  /**
   * Tests strict date, domain, and X handle validation helpers.
   */
  public function testFilterValueValidation(): void {
    $provider = $this->newProviderWithoutConstructor();
    $date_method = new \ReflectionMethod(GrokAiProvider::class, 'isIsoDate');
    $domain_method = new \ReflectionMethod(GrokAiProvider::class, 'isValidDomain');
    $handles_method = new \ReflectionMethod(GrokAiProvider::class, 'normalizeXhandles');

    self::assertTrue($date_method->invoke($provider, '2026-07-24'));
    self::assertFalse($date_method->invoke($provider, '2026-02-30'));
    self::assertTrue($domain_method->invoke($provider, 'example.com'));
    self::assertFalse($domain_method->invoke($provider, 'https://example.com/path'));
    self::assertSame(['xai', 'open_ai'], $handles_method->invoke($provider, '@xai, open_ai'));

    $this->expectException(AiBadRequestException::class);
    $handles_method->invoke($provider, 'invalid-handle');
  }

  /**
   * Calls the private pure filtering method without booting Drupal services.
   */
  private function filterModels(array $models, array $capabilities = []): array {
    $method = new \ReflectionMethod(GrokAiProvider::class, 'filterModels');
    return $method->invoke($this->newProviderWithoutConstructor(), $models, $capabilities);
  }

  /**
   * Creates a provider suitable for testing its pure helper methods.
   */
  private function newProviderWithoutConstructor(): GrokAiProvider {
    return (new \ReflectionClass(GrokAiProvider::class))->newInstanceWithoutConstructor();
  }

}
