# Changelog

## 1.0.0-alpha6

- Added an administrator-controlled AJAX action for loading the latest
  module-maintained xAI pricing schedule, with strict validation, time and size
  limits, redirect refusal, provenance metadata, and an offline packaged-data
  restore action.
- Fixed cached and AJAX provider-form rebuilds failing to restore the cost
  estimator service.
- Reorganized Next Steps Explorer links into a nested unordered list.

## 1.0.0-alpha5

- Added configurable default prompts for compatible Drupal AI Explorers.
- Standardized Image-to-Image, Image-to-Video, and Image Classification
  Explorers with upload, Drupal Media, and bundled default image sources.
- Added optional visual Media Library selection through Media Library Form
  Element, with entity-autocomplete fallback.

## 1.0.0-alpha4

- Added Grok-specific default prompts to compatible Drupal AI Explorers.
- Added optional MP3 and MP4 saving to compatible Drupal Media types from
  audio and video generation Explorers.
- Added a result fieldset showing input, output, cached, and reasoning token
  counts when available, together with xAI-reported or best-effort estimated
  request cost.
- Added editable model-pricing JSON to the provider configuration form and a
  shared fallback estimator for token, image, video, character, and audio-hour
  pricing.

## 1.0.0-alpha3

- Added Drupal AI Text-to-Speech using xAI REST voice discovery and MP3
  synthesis.
- Added Drupal AI Speech-to-Text using xAI's multipart REST transcription API.
- Added language, speech speed, text normalization, transcript formatting,
  diarization, filler-word, and key-term controls.

## 1.0.0-alpha2

- Added native Drupal AI Text-to-Image and Image-to-Image operations using
  Grok Imagine image models.
- Added Text-to-Video and Image-to-Video operations, including AI API Explorer
  integrations and asynchronous MP4 retrieval.
- Added Drupal AI Image Classification with optional candidate labels and
  confidence scores.
- Added model-based Drupal AI Moderation with categories, explanations, and
  confidence scores.
- Added opt-in xAI hosted tools through the Responses API: Web Search, X
  Search, Code Interpreter, Collections Search, and allowlisted remote MCP
  servers.
- Added provider setup guidance, AI Explorer links, CKEditor instructions,
  example Explorer inputs, and an optional Drupal recipe.
- Added packaged basic translations for ten languages.
- Improved API-key-dependent form visibility, model discovery, validation,
  observability metadata, error handling, and documentation.

Moderation in this release is a probabilistic Grok assessment. It is not a
dedicated xAI safety or compliance endpoint.

## 1.0.0-alpha1

- Initial Drupal AI provider integration for xAI Grok chat models.
