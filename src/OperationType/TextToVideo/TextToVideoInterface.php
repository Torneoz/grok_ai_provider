<?php

declare(strict_types=1);

namespace Drupal\grok_ai_provider\OperationType\TextToVideo;

use Drupal\ai\OperationType\OperationTypeInterface;

/**
 * Defines text-to-video generation for Drupal AI.
 */
interface TextToVideoInterface extends OperationTypeInterface {

  /**
   * Generates a video from text.
   */
  public function textToVideo(string|TextToVideoInput $input, string $model_id, array $tags = []): TextToVideoOutput;

}
