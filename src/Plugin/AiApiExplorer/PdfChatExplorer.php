<?php

declare(strict_types=1);

namespace Drupal\grok\Plugin\AiApiExplorer;

use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Component\Utility\Xss;
use Drupal\ai\Enum\AiModelCapability;
use Drupal\ai\OperationType\Chat\ChatInput;
use Drupal\ai\OperationType\Chat\ChatMessage;
use Drupal\ai\OperationType\GenericType\DocumentFile;
use Drupal\ai\Service\AiProviderFormHelper;
use Drupal\ai_api_explorer\AiApiExplorerPluginBase;
use Drupal\ai_api_explorer\Attribute\AiApiExplorer;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Provides an explorer for PDF understanding across capable providers.
 */
#[AiApiExplorer(
  id: 'grok_pdf_explorer',
  title: new TranslatableMarkup('PDF Explorer [beta]'),
  description: new TranslatableMarkup('Upload PDFs and investigate document understanding across compatible AI providers and models.'),
)]
final class PdfChatExplorer extends AiApiExplorerPluginBase {

  /**
   * Maximum number of PDFs accepted in one exploratory request.
   */
  private const MAX_FILES = 5;

  /**
   * Maximum size accepted by this Explorer for each PDF.
   */
  private const MAX_FILE_BYTES = 48 * 1024 * 1024;

  /**
   * Semantic response tags permitted after XSS filtering.
   */
  private const RESPONSE_HTML_TAGS = [
    'a',
    'blockquote',
    'br',
    'caption',
    'cite',
    'code',
    'dd',
    'del',
    'details',
    'div',
    'dl',
    'dt',
    'em',
    'figcaption',
    'figure',
    'h2',
    'h3',
    'h4',
    'h5',
    'h6',
    'hr',
    'ins',
    'kbd',
    'li',
    'mark',
    'ol',
    'p',
    'pre',
    'q',
    's',
    'samp',
    'small',
    'span',
    'strong',
    'sub',
    'summary',
    'sup',
    'table',
    'tbody',
    'td',
    'tfoot',
    'th',
    'thead',
    'tr',
    'ul',
    'var',
  ];

  /**
   * {@inheritdoc}
   */
  public function isActive(): bool {
    $providers = $this->providerManager->getProvidersForOperationType(
      'chat',
      TRUE,
      [AiModelCapability::ChatWithPdf],
    );
    return $providers !== [];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $form = $this->getFormTemplate($form, 'pdf-explorer-response');
    $form['#attributes']['enctype'] = 'multipart/form-data';

    $form['left']['introduction'] = [
      '#type' => 'html_tag',
      '#tag' => 'p',
      '#value' => $this->t('The selected provider receives the attached PDFs using its own document transport. Provider limits, retention, privacy, and billing policies apply. This explorer sends non-streamed requests.'),
    ];
    $form['left']['system_prompt'] = [
      '#type' => 'textarea',
      '#title' => $this->t('System instructions'),
      '#default_value' => 'Answer from the attached PDF documents. Cite the document filename and page number when the source makes that possible. Clearly identify uncertainty. Return semantic HTML only, without Markdown or a surrounding code fence.',
      '#rows' => 3,
    ];
    $form['left']['prompt'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Question or investigation prompt'),
      '#default_value' => 'Summarize the documents, then list the most important claims and supporting evidence.',
      '#required' => TRUE,
    ];
    $form['left']['pdfs'] = [
      '#type' => 'file',
      '#name' => 'pdfs[]',
      '#title' => $this->t('PDF documents'),
      '#description' => $this->t('Attach up to @count PDFs. Each file may be at most 48 MB.', ['@count' => self::MAX_FILES]),
      '#attributes' => [
        'accept' => 'application/pdf,.pdf',
        'multiple' => 'multiple',
      ],
    ];

    $providers = $this->providerManager->getProvidersForOperationType(
      'chat',
      TRUE,
      [AiModelCapability::ChatWithPdf],
    );
    $provider_options = [];
    foreach ($providers as $provider_id => $definition) {
      $provider_options[$provider_id] = (string) ($definition['label'] ?? $provider_id);
    }
    $selected_provider = (string) $form_state->getValue('pdf_ai_provider');
    if (!isset($provider_options[$selected_provider])) {
      $selected_provider = (string) array_key_first($provider_options);
      $form_state->setValue('pdf_ai_provider', $selected_provider);
    }
    $this->aiProviderHelper->generateAiProvidersForm(
      $form['left'],
      $form_state,
      'chat',
      'pdf',
      AiProviderFormHelper::FORM_CONFIGURATION_FULL,
    );
    $form['left']['pdf_ai_provider']['#options'] = $provider_options;
    $form['left']['pdf_ai_provider']['#default_value'] = $selected_provider;
    if (isset($form['left']['pdf_ajax_prefix']['pdf_ai_model'])) {
      $provider = $this->providerManager->createInstance($selected_provider);
      $form['left']['pdf_ajax_prefix']['pdf_ai_model']['#options'] = $provider->getConfiguredModels(
        'chat',
        [AiModelCapability::ChatWithPdf],
      );
    }

    $form['left']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Investigate PDFs'),
      '#ajax' => [
        'callback' => $this->getAjaxResponseId(),
        'wrapper' => 'pdf-explorer-response',
      ],
    ];
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function getResponse(array &$form, FormStateInterface $form_state): array {
    try {
      $uploaded_files = $this->getUploadedPdfs();
      $attachments = [];
      $diagnostics = [];
      foreach ($uploaded_files as $uploaded_file) {
        $binary = file_get_contents($uploaded_file->getPathname());
        if ($binary === FALSE) {
          throw new \RuntimeException((string) $this->t('The PDF "@name" could not be read.', [
            '@name' => $uploaded_file->getClientOriginalName(),
          ]));
        }
        $attachments[] = new DocumentFile(
          $binary,
          'application/pdf',
          $uploaded_file->getClientOriginalName(),
        );
        $diagnostics[] = $uploaded_file->getClientOriginalName() . ' (' . $this->formatBytes(strlen($binary)) . ')';
      }

      $provider = $this->aiProviderHelper->generateAiProviderFromFormSubmit(
        $form,
        $form_state,
        'chat',
        'pdf',
      );
      $input = new ChatInput([
        new ChatMessage('user', trim((string) $form_state->getValue('prompt')), $attachments),
      ]);
      $system_prompt = trim((string) $form_state->getValue('system_prompt'));
      if ($system_prompt !== '') {
        $input->setSystemPrompt($system_prompt);
      }

      $started = microtime(TRUE);
      $output = $provider->chat(
        $input,
        (string) $form_state->getValue('pdf_ai_model'),
        ['pdf_explorer', 'ai_api_explorer'],
      )->getNormalized();
      $elapsed = microtime(TRUE) - $started;
      if (!$output instanceof ChatMessage) {
        throw new \UnexpectedValueException((string) $this->t('The provider did not return a chat message.'));
      }

      $form['right']['response']['#context']['ai_response'] = [
        'heading' => [
          '#type' => 'html_tag',
          '#tag' => 'h2',
          '#value' => $this->t('AI response'),
        ],
        'text' => [
          '#type' => 'container',
          'content' => [
            '#markup' => Xss::filter($output->getText(), self::RESPONSE_HTML_TAGS),
          ],
          '#attributes' => ['class' => ['ai-text-response']],
        ],
        'diagnostics' => [
          '#type' => 'details',
          '#title' => $this->t('Request diagnostics'),
          '#open' => TRUE,
          'model' => [
            '#markup' => '<strong>' . $this->t('Provider:') . '</strong> ' . htmlspecialchars((string) $form_state->getValue('pdf_ai_provider')) . '<br><strong>' . $this->t('Model:') . '</strong> ' . htmlspecialchars((string) $form_state->getValue('pdf_ai_model')),
          ],
          'elapsed' => [
            '#markup' => '<br><strong>' . $this->t('Elapsed:') . '</strong> ' . number_format($elapsed, 2) . ' s',
          ],
          'files' => [
            '#theme' => 'item_list',
            '#title' => $this->t('Documents sent'),
            '#items' => $diagnostics,
          ],
        ],
      ];
    }
    catch (\Throwable $exception) {
      $form['right']['response']['#context']['ai_response'] = [
        'heading' => [
          '#type' => 'html_tag',
          '#tag' => 'h3',
          '#value' => $this->t('PDF investigation failed'),
        ],
        'message' => [
          '#type' => 'html_tag',
          '#tag' => 'div',
          '#plain_text' => $exception->getMessage(),
          '#attributes' => ['class' => ['ai-text-response', 'ai-error-message']],
        ],
      ];
    }
    $form_state->setRebuild();
    return $form['right'];
  }

  /**
   * Loads and validates uploaded PDFs from the current multipart request.
   *
   * @return \Symfony\Component\HttpFoundation\File\UploadedFile[]
   *   Valid PDF uploads.
   */
  private function getUploadedPdfs(): array {
    $files = $this->flattenUploadedFiles($this->getRequest()->files->all());
    if ($files === []) {
      throw new \InvalidArgumentException((string) $this->t('Attach at least one PDF document.'));
    }
    if (count($files) > self::MAX_FILES) {
      throw new \InvalidArgumentException((string) $this->t('Attach no more than @count PDF documents.', [
        '@count' => self::MAX_FILES,
      ]));
    }
    foreach ($files as $file) {
      if (!$file->isValid()) {
        throw new \InvalidArgumentException((string) $this->t('The upload "@name" failed.', [
          '@name' => $file->getClientOriginalName(),
        ]));
      }
      if ($file->getSize() > self::MAX_FILE_BYTES) {
        throw new \InvalidArgumentException((string) $this->t('The PDF "@name" exceeds the 48 MB limit.', [
          '@name' => $file->getClientOriginalName(),
        ]));
      }
      $handle = fopen($file->getPathname(), 'rb');
      $signature = $handle === FALSE ? FALSE : fread($handle, 5);
      if (is_resource($handle)) {
        fclose($handle);
      }
      if ($signature !== '%PDF-') {
        throw new \InvalidArgumentException((string) $this->t('The upload "@name" is not a valid PDF.', [
          '@name' => $file->getClientOriginalName(),
        ]));
      }
    }
    return $files;
  }

  /**
   * Flattens Symfony's nested collection of multipart uploads.
   *
   * @return \Symfony\Component\HttpFoundation\File\UploadedFile[]
   *   Uploaded files.
   */
  private function flattenUploadedFiles(mixed $value): array {
    if ($value instanceof UploadedFile) {
      return [$value];
    }
    $files = [];
    if (is_array($value)) {
      foreach ($value as $item) {
        $files = array_merge($files, $this->flattenUploadedFiles($item));
      }
    }
    return $files;
  }

  /**
   * Formats a byte count for lightweight request diagnostics.
   */
  private function formatBytes(int $bytes): string {
    if ($bytes < 1024) {
      return $bytes . ' B';
    }
    if ($bytes < 1024 * 1024) {
      return number_format($bytes / 1024, 1) . ' KB';
    }
    return number_format($bytes / (1024 * 1024), 1) . ' MB';
  }

}
