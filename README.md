# Grok AI Provider

Provides xAI Grok chat models to Drupal through the AI module. The first
version uses xAI's OpenAI-compatible Chat Completions API.

## Installation

Enable **Grok AI Provider** and its AI and Key dependencies. Create a Key
entity containing an xAI API key, then visit:

`/admin/config/ai/providers/grok`

Select the Key and save the form. The provider validates access using model
discovery; it does not make a billable chat request during configuration.

## Supported features

- Text chat and streaming
- Image input on supported Grok models
- Drupal AI function tools
- JSON and structured responses on supported models
- Token usage and rate-limit metadata exposed by the API

The API base URL defaults to `https://api.x.ai/v1` and can be changed in the
advanced settings for testing or compatible gateways.

## Current scope

This version does not expose xAI Responses API features such as hosted web or
X search, citations, code interpreter, collections, stateful response IDs,
image generation, or voice APIs.
