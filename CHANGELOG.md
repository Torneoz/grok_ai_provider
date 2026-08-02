# Changelog

## 1.1.0-alpha1 (unreleased)

- Added the optional experimental `grok_doc` submodule for registering existing
  xAI Collections and bulk-ingesting Drupal-managed documents.
- Added queued uploads, indexing-state tracking, SHA-256 duplicate detection,
  batch metadata, least-privilege Management-key configuration, and import size
  limits.
- Added an allowlist boundary so Collections Search can only use registrations
  explicitly approved by Grok Documents when the submodule is installed.
- Remote Collection creation and destructive deletion remain intentionally out
  of scope for the first alpha.

## 1.0.0-beta1

- Added an explicit CI dependency matrix covering Drupal 10.6 with minimum
  dependencies and Drupal 11 with current dependencies.
- Raised the supported Drupal floor to 10.6 and Drupal 11.2 to match maintained
  Drupal core and current Drupal AI 1.4 dependency constraints.
- Added reproducible release-archive verification and a required release
  evidence record for integration, upgrade-path, and live API smoke tests.
- Updated release-facing documentation and fallback version metadata for the
  beta channel.
- Documented that the optional recipe uses Drupal 11's supported recipe runner;
  Drupal 10 installations continue to use Composer and direct enablement.
- Fixed string chat inputs being forwarded as an invalid Chat Completions
  `messages` payload instead of a Drupal AI user message.
- Added ignore rules for local dependencies, test caches, editor settings, and
  operating-system metadata.

## 1.0.0-alpha9

- Renamed the Drupal module machine name from `grok_ai_provider` to `grok` so
  Composer installation and Drush enablement consistently use `grok`.
- Renamed module files, PHP namespaces, configuration, services, routes,
  libraries, recipe, translation catalogs, and test namespaces accordingly.
- Documented the required uninstall and reconfiguration procedure for users
  upgrading from alpha8 or earlier.

- Restricted generated-video downloads and redirects to trusted xAI or
  configured-gateway hosts and rejected private or reserved IP literals.
- Added in-transfer size enforcement for generated audio and video responses.
- Added MP3 signature validation for generated text-to-speech responses.
- Applied one overall deadline to video start, polling, and download work.
- Added live xAI video-model discovery, including image-input capability
  filtering, instead of advertising inaccessible hard-coded models.
- Allowed unchanged provider settings to be saved during a temporary xAI
  outage while retaining live validation for connection-setting changes.
- Added missing configuration update defaults for the next release.
- Added Composer autoloading, PHPUnit and PHPCS configuration, and a GitHub
  Actions quality workflow.
- Added security, testing, privacy, and memory guidance for alpha testers.

## 1.0.0-alpha8

- Added conditional AI Image Studio guidance to the provider configuration
  form's Next Steps section.
- Sites with AI Image Studio enabled receive a direct link to its image
  workspace and guidance for using Grok Text-to-Image and Image-to-Image
  models.
- Sites without AI Image Studio receive an optional recommendation without
  introducing a module dependency.
- Added packaged translations for the new AI Image Studio guidance.

## 1.0.0-alpha7

- Fixed Chat Explorer requests failing when Drupal AI supplied its default
  `seed` value of zero; non-positive seeds are now treated as unset.
- Added optional shared pricing integration with Torneo AI for Explorer cost
  estimates while preserving the standalone Grok pricing fallback.

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
