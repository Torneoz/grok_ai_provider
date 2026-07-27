# Grok AI Provider

Provides xAI Grok chat, image generation, image editing, video generation,
text-to-speech, and speech-to-text to Drupal through the AI module, together
with model-based moderation and image classification using xAI's Chat
Completions, Responses, Imagine, and Voice APIs.

## Installation

Enable **Grok AI Provider** and its AI and Key dependencies. Create a Key
entity containing an xAI API key, then visit:

`/admin/config/ai/providers/grok`

Select the Key and save the form. The provider validates access using model
discovery; it does not make a billable chat request during configuration.
Use **Test connection and load models** to verify unsaved credentials and
populate the default-model selector with the models accessible to that key.
To use image generation, select Grok and an available Grok Imagine model for
the **Text To Image** operation on Drupal AI's default-model settings page.
Select Grok and an available Grok Imagine image model for the **Image To
Image** operation to enable prompt-based image editing. The source image and a
non-empty editing prompt are required; masks are not currently supported.
Select a vision-capable Grok model for **Image Classification** and a
structured-output-capable Grok model for **Moderation**. These adapters use
Grok chat inference; xAI does not provide a dedicated moderation endpoint.
For **Text To Speech**, select an available xAI voice such as `eve`. Select
`xai-stt` for **Speech To Text**. Drupal AI's standard Text-to-Speech and
Speech-to-Text Explorers become available when those defaults are configured.
Select Grok and `grok-imagine-video` for the **Text To Video** operation on the
same page.
Select Grok and `grok-imagine-video-1.5` for the **Image To Video** operation.
When AI API Explorer is enabled, the standard **Image-To-Image Explorer**
accepts a source image and editing prompt. The module corrects the Explorer's
copied classification upload text and ensures its dynamically added prompt is
preserved during AJAX submissions. The Grok-specific **Text-To-Video
Generation Explorer** and **Image-To-Video Generation Explorer** can generate
and preview short MP4 videos.

When Grok is selected, compatible Explorers provide operation-specific example
prompts without replacing submitted values. Successful Explorer responses also
show available input, output, cached, and reasoning token counts. The result
fieldset prefers the exact cost reported by xAI and otherwise shows a
best-effort estimate based on public xAI pricing. Media and voice operations do
not use text tokens, so their token rows are marked as not applicable.

If the optional core Media module is enabled, Text-to-Speech and both video
Explorers offer **Save to Media**. Only Media types whose source field accepts
MP3 or MP4 respectively are offered. Saved files use the destination configured
on the selected Media type and become permanent Media items.

### Optional recipe

The included recipe installs the Grok AI Provider, AI, and Key modules:

```bash
drush recipe web/modules/contrib/grok_ai_provider/recipes/grok_ai_provider
```

It does not install Language or Interface Translation and does not add
languages. Sites that already use Drupal localization can import the packaged
translations independently.

## Supported features

- Text chat and streaming
- Image input on supported Grok models
- Image classification with optional candidate labels and confidence scores
- Model-based text moderation with safety categories, an explanation, and a
  confidence score
- Text-to-speech with live xAI voice discovery, language selection, speech
  speed, text normalization, and MP3 output
- REST speech-to-text for supported audio files, with optional formatting,
  speaker diarization, filler words, and key-term prompting
- Drupal AI function tools
- JSON and structured responses on supported models
- Text-to-image generation with available Grok Imagine image models
- Prompt-based image-to-image editing through xAI's `/images/edits` endpoint,
  with optional aspect ratio and 1k or 2k output resolution
- Optional best-effort transparent-background prompting for generated images
- Text-to-video generation with `grok-imagine-video`, including configurable
  duration, aspect ratio, and resolution
- Image-to-video generation with `grok-imagine-video-1.5`, including a source
  image, animation prompt, and up to 1080p output
- Token usage and rate-limit metadata exposed by the API
- Automatic dual transport: ordinary requests use Chat Completions and
  hosted-tool requests use the Responses API
- Opt-in xAI Web Search, X Search, Code Interpreter, Collections Search,
  and allowlisted remote MCP servers
- Responses citations, response IDs, hosted-tool output, and reasoning usage
  preserved in `ChatOutput` metadata and raw output
- Grok-specific Explorer example prompts, usage summaries, reported or
  estimated costs, and optional generated audio/video Media saving

Moderation is a probabilistic Grok assessment rather than a dedicated safety
or compliance service. Applications with legal, regulatory, or high-risk
moderation requirements should apply additional purpose-built controls.

The API base URL defaults to `https://api.x.ai/v1` and can be changed in the
advanced settings for testing or compatible gateways.

## Hosted tools

Hosted tools must first be permitted at site level on the provider settings
form. They are still disabled for individual requests until selected in that
request's model configuration. Remote MCP entries require HTTPS and an
explicit allowed-tools list.

Responses are not stored by xAI unless an administrator opts in. Drupal
function tools continue to use Chat Completions so the existing Drupal AI tool
execution loop remains unchanged.

Streaming Responses requests, stateful response continuation, and
collection/file management are not yet included. Streaming ordinary chat
continues to work through Chat Completions, including when the provider-wide
transport preference is Responses. Hosted tools are never silently removed
from a streamed request.

Responses requests have a configurable timeout between 10 and 3600 seconds.
API and remote MCP endpoints must use HTTPS.

Video requests use xAI's asynchronous API and are billed by generated duration
and resolution. The module polls until completion and immediately downloads the
MP4 because xAI-hosted result URLs are temporary.

Audio requests use xAI's REST Voice API. Text-to-speech input is limited to
15,000 characters and returns MP3 for compatibility with Drupal AI's current
Explorer. Speech-to-text accepts up to 100 MB in this module, below xAI's
service limit, to keep Drupal request memory bounded. Realtime WebSocket
speech-to-speech and custom voice management are not included.

Explorer estimates use the public xAI prices documented on 27 July 2026.
Reported `cost_in_usd_ticks` values are preferred whenever xAI supplies them.
Estimates are informational only and can differ from actual billing because of
model changes, caching, discounts, tools, and regional pricing.

## Translations

All administrative labels, descriptions, validation messages, provider-setting
metadata, and user-facing API errors use Drupal's String Translation API.
Routing, menu-link, module information, and configuration-schema labels use
Drupal's standard translatable YAML locations.

Published releases can also be translated through
[Drupal's translation system](https://localize.drupal.org/). The packaged
basic `.po` translations provide a fallback until community localization is
available. Sites can optionally enable the core Language, Interface
Translation, and Configuration Translation modules to install and manage
translations.

## Torneoz

This module is part of the [Torneoz project](https://torneoz.org), a global sports
management AI system for Drupal.
