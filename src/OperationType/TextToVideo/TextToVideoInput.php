<?php

declare(strict_types=1);

namespace Drupal\grok_ai_provider\OperationType\TextToVideo;

use Drupal\ai\OperationType\InputBase;
use Drupal\ai\OperationType\InputInterface;

/**
 * Text input for video generation.
 */
final class TextToVideoInput extends InputBase implements InputInterface {

  /**
   * Constructs a text-to-video input.
   */
  public function __construct(private string $text) {}

  /**
   * Returns the video prompt.
   */
  public function getText(): string {
    return $this->text;
  }

  /**
   * Sets the video prompt.
   */
  public function setText(string $text): void {
    $this->text = $text;
  }

  /**
   * {@inheritdoc}
   */
  public function toString(): string {
    return $this->text;
  }

  /**
   * {@inheritdoc}
   */
  public function __toString(): string {
    return $this->toString();
  }

  /**
   * {@inheritdoc}
   */
  public function toArray(): array {
    return ['text' => $this->text];
  }

  /**
   * Creates an input from an array.
   */
  public static function fromArray(array $data): static {
    return new static((string) ($data['text'] ?? ''));
  }

}
