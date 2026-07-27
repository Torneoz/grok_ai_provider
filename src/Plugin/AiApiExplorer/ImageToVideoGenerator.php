<?php

declare(strict_types=1);

namespace Drupal\grok_ai_provider\Plugin\AiApiExplorer;

use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Url;
use Drupal\ai\AiProviderPluginManager;
use Drupal\ai\OperationType\GenericType\ImageFile;
use Drupal\ai\OperationType\ImageToVideo\ImageToVideoInput;
use Drupal\ai\Service\AiProviderFormHelper;
use Drupal\ai_api_explorer\AiApiExplorerPluginBase;
use Drupal\ai_api_explorer\Attribute\AiApiExplorer;
use Drupal\ai_api_explorer\ExplorerHelper;
use Drupal\grok_ai_provider\Service\ExplorerMediaHelper;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Provides a Grok image-to-video API Explorer.
 */
#[AiApiExplorer(
  id: 'grok_image_to_video_generator',
  title: new TranslatableMarkup('Image-To-Video Generation Explorer'),
  description: new TranslatableMarkup('Animate a still image with Grok Imagine Video 1.5.'),
)]
final class ImageToVideoGenerator extends AiApiExplorerPluginBase {

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
    private readonly ExplorerMediaHelper $mediaHelper,
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
      $container->get('grok_ai_provider.explorer_media'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function isActive(): bool {
    return $this->providerManager->hasProvidersForOperationType('image_to_video');
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $form = $this->getFormTemplate($form, 'ai-image-video-response');
    foreach ($this->mediaHelper->buildImageSourceElements(
      $form_state,
      new TranslatableMarkup('Choose the still image that Grok should animate. Video generation is asynchronous and billed by duration and resolution; keep this page open until it finishes.'),
    ) as $key => $element) {
      $form['left'][$key] = $element;
    }
    $form['#validate'][] = 'grok_ai_provider_validate_explorer_image_source';
    $this->aiProviderHelper->generateAiProvidersForm(
      $form['left'],
      $form_state,
      'image_to_video',
      'image_video_generator',
      AiProviderFormHelper::FORM_CONFIGURATION_FULL,
    );
    $form['left']['image_video_generator_ai_provider']['#ajax']['callback'] = $this::class . '::loadModelsAjaxCallback';
    $media_options = $this->mediaHelper->getOptions('mp4');
    if ($media_options !== []) {
      $form['left']['save_as_media'] = [
        '#type' => 'select',
        '#title' => $this->t('Save to Media'),
        '#options' => ['' => $this->t('Do not save')] + $media_options,
        '#description' => $this->t('Optionally save the generated MP4 as a permanent Drupal Media item.'),
      ];
    }
    $form['left']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Generate a Video'),
      '#ajax' => [
        'callback' => $this->getAjaxResponseId(),
        'wrapper' => 'ai-image-video-response',
      ],
    ];
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function getResponse(array &$form, FormStateInterface $form_state): array {
    try {
      $image = $this->mediaHelper->loadSelectedImage(
        $form_state,
        fn (): ?ImageFile => $this->generateFile('image'),
      );
      if ($image === NULL) {
        throw new \RuntimeException((string) $this->t('A source image is required.'));
      }
      $provider = $this->aiProviderHelper->generateAiProviderFromFormSubmit(
        $form,
        $form_state,
        'image_to_video',
        'image_video_generator',
      );
      $output = $provider->imageToVideo(
        new ImageToVideoInput($image),
        (string) $form_state->getValue('image_video_generator_ai_model'),
        ['ai_api_explorer'],
      );
      $videos = $output->getNormalized();
      if ($videos === []) {
        throw new \RuntimeException((string) $this->t('The provider did not return a video.'));
      }

      $destination = 'temporary://ai-explorers';
      $this->fileSystem->prepareDirectory($destination, FileSystemInterface::CREATE_DIRECTORY);
      $filename = 'grok-image-video-' . bin2hex(random_bytes(8)) . '.mp4';
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
      $media_type = (string) $form_state->getValue('save_as_media');
      if ($media_type !== '') {
        $media = $this->mediaHelper->save($videos[0], $media_type, $filename);
        $form['right']['response']['#context']['ai_response']['media'] = [
          '#type' => 'link',
          '#title' => $this->t('Saved to Media: @label', ['@label' => $media->label()]),
          '#url' => $media->toUrl(),
        ];
      }
    }
    catch (\Throwable $exception) {
      $form['right']['response']['#context']['ai_response'] = [
        'heading' => [
          '#type' => 'html_tag',
          '#tag' => 'h3',
          '#value' => $this->t('Image-to-video generation failed'),
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
