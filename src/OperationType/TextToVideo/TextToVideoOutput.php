<?php

declare(strict_types=1);

namespace Drupal\grok\OperationType\TextToVideo;

use Drupal\ai\OperationType\OutputInterface;

/**
 * Output from text-to-video generation.
 */
final class TextToVideoOutput implements OutputInterface {

  /**
   * Constructs a text-to-video output.
   */
  public function __construct(
    private readonly array $normalized,
    private readonly mixed $rawOutput,
    private readonly mixed $metadata,
  ) {}

  /**
   * Returns generated video files.
   */
  public function getNormalized(): array {
    return $this->normalized;
  }

  /**
   * Returns the provider response.
   */
  public function getRawOutput(): mixed {
    return $this->rawOutput;
  }

  /**
   * Returns provider metadata.
   */
  public function getMetadata(): mixed {
    return $this->metadata;
  }

  /**
   * {@inheritdoc}
   */
  public function toArray(): array {
    return [
      'normalized' => $this->normalized,
      'rawOutput' => $this->rawOutput,
      'metadata' => $this->metadata,
    ];
  }

}
