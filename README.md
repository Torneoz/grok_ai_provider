# Grok AI Provider

Provides xAI Grok chat models to Drupal through the AI module. The first
version uses xAI's OpenAI-compatible Chat Completions API.

## Installation

Enable **Grok AI Provider** and its AI and Key dependencies. Create a Key
entity containing an xAI API key, then visit:

`/admin/config/ai/providers/grok`

Select the Key and save the form. The provider validates access using model
discovery; it does not make a billable chat request during configuration.
Use **Test connection and load models** to verify unsaved credentials and
populate the default-model selector with the models accessible to that key.

## Supported features

- Text chat and streaming
- Image input on supported Grok models
- Drupal AI function tools
- JSON and structured responses on supported models
- Token usage and rate-limit metadata exposed by the API
- Automatic dual transport: ordinary requests use Chat Completions and
  hosted-tool requests use the Responses API
- Opt-in xAI Web Search, X Search, Code Interpreter, Collections Search,
  and allowlisted remote MCP servers
- Responses citations, response IDs, hosted-tool output, and reasoning usage
  preserved in `ChatOutput` metadata and raw output

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

Streaming Responses requests, stateful response continuation, collection/file
management, image generation, and voice APIs are not yet included. Streaming
ordinary chat continues to work through Chat Completions, including when the
provider-wide transport preference is Responses. Hosted tools are never
silently removed from a streamed request.

Responses requests have a configurable timeout between 10 and 3600 seconds.
API and remote MCP endpoints must use HTTPS.

## Translations

All administrative labels, descriptions, validation messages, provider-setting
metadata, and user-facing API errors use Drupal's String Translation API.
Routing, menu-link, module information, and configuration-schema labels use
Drupal's standard translatable YAML locations.

Published releases can be translated through
[Drupal's translation system](https://localize.drupal.org/). Do not commit
generated `.po` or `.pot` files for Drupal.org releases; Drupal.org extracts
the source strings and publishes the project translation packages
automatically. Sites can enable the core Language, Interface Translation, and
Configuration Translation modules to install and manage translations.

## Torneoz

This module is part of the [Torneoz project](https://torneoz.org), a global
management AI system for Drupal. v12
