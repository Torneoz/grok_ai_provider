<?php

declare(strict_types=1);

namespace Drupal\grok_ai_provider\Plugin\AiApiExplorer;

use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Url;
use Drupal\ai\AiProviderPluginManager;
use Drupal\ai\Service\AiProviderFormHelper;
use Drupal\ai_api_explorer\AiApiExplorerPluginBase;
use Drupal\ai_api_explorer\Attribute\AiApiExplorer;
use Drupal\ai_api_explorer\ExplorerHelper;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Provides a Grok text-to-video API Explorer.
 */
#[AiApiExplorer(
  id: 'grok_text_to_video_generator',
  title: new TranslatableMarkup('Text-To-Video Generation Explorer'),
  description: new TranslatableMarkup('Experiment with Grok text-to-video generation.'),
)]
final class TextToVideoGenerator extends AiApiExplorerPluginBase {

  /**
   * Constructs the Explorer plugin.
   */
  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    RequestStack $requestStack,
    AiProviderFormHelper $aiProviderHelper,
    ExplorerHelper $explorerHelper,
    AiProviderPluginManager $providerManager,
    private readonly FileSystemInterface $fileSystem,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition, $requestStack, $aiProviderHelper, $explorerHelper, $providerManager);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('request_stack'),
      $container->get('ai.form_helper'),
      $container->get('ai_api_explorer.helper'),
      $container->get('ai.provider'),
      $container->get('file_system'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function isActive(): bool {
    return $this->providerManager->hasProvidersForOperationType('text_to_video');
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $form = $this->getFormTemplate($form, 'ai-video-response');
    $form['left']['prompt'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Describe the video to generate.'),
      '#description' => $this->t('Video generation is asynchronous, billable by duration and resolution, and can take several minutes. Keep this page open until it finishes.'),
      '#required' => TRUE,
    ];
    $this->aiProviderHelper->generateAiProvidersForm(
      $form['left'],
      $form_state,
      'text_to_video',
      'video_generator',
      AiProviderFormHelper::FORM_CONFIGURATION_FULL,
    );
    $form['left']['video_generator_ai_provider']['#ajax']['callback'] = $this::class . '::loadModelsAjaxCallback';
    $form['left']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Generate a Video'),
      '#ajax' => [
        'callback' => $this->getAjaxResponseId(),
        'wrapper' => 'ai-video-response',
      ],
    ];
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function getResponse(array &$form, FormStateInterface $form_state): array {
    try {
      $provider = $this->aiProviderHelper->generateAiProviderFromFormSubmit(
        $form,
        $form_state,
        'text_to_video',
        'video_generator',
      );
      $output = $provider->textToVideo(
        trim((string) $form_state->getValue('prompt')),
        (string) $form_state->getValue('video_generator_ai_model'),
        ['ai_api_explorer'],
      );
      $videos = $output->getNormalized();
      if ($videos === []) {
        throw new \RuntimeException((string) $this->t('The provider did not return a video.'));
      }

      $destination = 'temporary://ai-explorers';
      $this->fileSystem->prepareDirectory($destination, FileSystemInterface::CREATE_DIRECTORY);
      $filename = 'grok-video-' . bin2hex(random_bytes(8)) . '.mp4';
      $uri = $this->fileSystem->saveData($videos[0]->getBinary(), $destination . '/' . $filename);
      $url = Url::fromRoute('system.temporary', [], [
        'query' => ['file' => 'ai-explorers/' . basename($uri)],
      ])->toString();
      $form['right']['response']['#context']['ai_response'] = [
        'video' => [
          '#type' => 'html_tag',
          '#tag' => 'video',
          '#attributes' => [
            'src' => $url,
            'controls' => TRUE,
            'preload' => 'metadata',
            'style' => 'max-width: 100%; height: auto;',
          ],
        ],
      ];
    }
    catch (\Throwable $exception) {
      $form['right']['response']['#context']['ai_response'] = [
        'heading' => [
          '#type' => 'html_tag',
          '#tag' => 'h3',
          '#value' => $this->t('Video generation failed'),
        ],
        'message' => [
          '#type' => 'html_tag',
          '#tag' => 'div',
          '#value' => $exception->getMessage(),
          '#attributes' => ['class' => ['ai-text-response', 'ai-error-message']],
        ],
      ];
    }
    $form_state->setRebuild();
    return $form['right'];
  }

}
