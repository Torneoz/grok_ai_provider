# Grok Integration

Grok Integration connects Drupal's [AI module](https://www.drupal.org/project/ai)
to xAI. It provides Grok chat and multimodal models, Grok Imagine image and
video generation, voice operations, hosted tools, Drupal AI Explorer
enhancements, and best-effort cost estimates.

The xAI API key is stored in Drupal's [Key module](https://www.drupal.org/project/key),
not in exported configuration. This is an unofficial community integration and
is not affiliated with or endorsed by xAI.

## Supported Drupal AI operations

- Chat, streaming chat, vision input, function tools, and structured output
- Text to Image and Image to Image
- Text to Video and Image to Video
- Text to Speech and Speech to Text
- Image Classification
- Model-based Moderation
- Grok Collections knowledge-base search through xAI Collections Search. The
  optional [Grok Documents (`grok_doc`)](https://github.com/Torneoz/grok_doc)
  module adds Drupal administration for registering existing Collections,
  uploading documents, and queued bulk ingestion.

Ordinary requests use Chat Completions. Requests that enable xAI-hosted tools
use the Responses API automatically. Moderation is a probabilistic Grok
assessment, not a dedicated safety or compliance endpoint.

## Drupal AI Explorer enhancements

Compatible Explorers include operation-specific example prompts, token and
reasoning usage, xAI-reported or estimated request cost, shared image inputs,
and optional generated audio/video saving to Drupal Media. Administrators can
customize or disable the example prompts from the provider configuration form.

Image operations can use an upload, the bundled example image, or a compatible
Drupal Media item. The optional Media Library Form Element module provides a
visual selector; entity autocomplete remains available without it.

## Cost estimation

When xAI reports `usage.cost_in_usd_ticks`, that value is shown. Otherwise,
Grok Integration estimates cost from an editable, versioned pricing schedule
covering tokens, cached and long-context input, images, video, speech
characters, and transcription hours. Estimates are informational and may
differ from the final xAI invoice.

## Optional xAI-hosted tools

- Web Search
- X Search
- Code Interpreter
- Collections Search
- Allowlisted remote MCP servers

Hosted tools are disabled by default at both site and request level. Remote MCP
servers require HTTPS and an explicit allowed-tools list. Grok Integration
includes Collections Search, but it does not itself manage collections or
documents. Install the separately maintained Grok Documents module when Drupal
needs collection registration, document upload, or queued bulk ingestion.

## Installation and configuration

Install the current beta with Composer:

```bash
composer require 'drupal/grok:^1.0@beta'
drush en grok
```

Create a Drupal Key entity containing an xAI API key, then configure Grok
Integration at `/admin/config/ai/providers/grok`. Use **Test connection and
load models** to verify credentials and discover models available to the key.

Drupal 11.2+ sites can alternatively apply the included recipe after Composer
installation:

```bash
vendor/bin/dr recipe web/modules/contrib/grok/recipes/grok
```

### Upgrading from alpha8 or earlier

The machine name changed from `grok_ai_provider` to `grok` in alpha9. Export or
record the existing settings, uninstall the former Grok AI Provider module,
update the code, enable `grok`, and restore the settings.

## Requirements

- PHP 8.1 or later
- Drupal 10.6 or Drupal 11.2+
- [AI 1.4 or later](https://www.drupal.org/project/ai)
- [Key 1.22 or later](https://www.drupal.org/project/key)
- An xAI API key

## Security and privacy

Prompts, uploaded media, generated content, and enabled hosted-tool context are
sent to the configured API endpoint. Web Search, X Search, Code Interpreter,
Collections Search, and remote MCP can send context to additional services.
Review data-residency, retention, and privacy requirements before enabling
them.

Responses API storage is disabled by default. A custom API base URL receives
the configured xAI key and must be trusted. Grok Documents should use a
separate, least-privilege Management API key for document operations.

Report security issues privately through the process in the project's
`SECURITY.md`; do not publish secrets or customer data in an issue.

## Translations

Grok Integration uses Drupal's String Translation API and includes basic
packaged translations for Arabic, German, Spanish, French, Hindi, Japanese,
Portuguese, Russian, Swahili, and Simplified Chinese. Community translations
can be contributed through [Drupal localization](https://localize.drupal.org/).

## Project status

The 1.0.x beta series is intended for compatibility and release-candidate
testing across Drupal 10.6 and Drupal 11.2+. Please report reproducible defects
with Drupal, PHP, AI module, Key module, and Grok Integration versions.
