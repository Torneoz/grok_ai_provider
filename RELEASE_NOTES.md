# Release notes

## Grok Integration 1.0.0-rc1

- Requires HTML5 serializer 2.9 or later after minimum-dependency integration
  testing exposed a Drupal HTML rendering failure with version 2.7.

- Requires Drupal AI 1.4.8 or later to exclude the affected 1.4 releases.
- Raises the development PHP_CodeSniffer minimum to 3.13.6 and adds a
  dependency security audit to both CI matrix jobs.
- Includes Chat with PDF when configuring AI capability defaults. The confirmed
  update selects the requested model when it supports PDFs, otherwise an
  authenticated PDF-capable model. Existing PDF defaults are preserved when no
  PDF-capable model is available.
- Rejects PDF requests on unsupported models before uploading documents.
- Adds regression coverage for multi-PDF requests, cleanup on partial upload and
  response failures, cleanup failures, incompatible transports, provider
  switching, and safe PDF Explorer output.
- Keeps the example animation prompt scoped to the image-to-video Explorer.

Upgrade using Composer, run database updates, and rebuild Drupal caches. No new
configuration update hook is required. To configure PDF chat as a default,
load models and confirm **Update AI Capabilities**; this intentionally replaces
other providers' defaults for Grok-supported capabilities.

Release validation results belong outside the distributable archive and must
identify the exact tested commit. Attach the evidence record before publishing
the GitHub release or Drupal.org project release.

## Grok Integration 1.0.0-beta5

Grok Integration 1.0.0-beta5 adds native PDF document understanding to Drupal
AI chat through xAI's Files and Responses APIs.

Beta5 is the final planned beta in the 1.0 release cycle. After this release is
validated and any required fixes are completed, the next development milestone
will be the first release candidate.

### Highlights

- Agentic Grok models are now advertised for Drupal AI's **Chat with PDF**
  capability.
- PDF attachments automatically use Responses transport when the provider is
  in automatic mode.
- Private PDFs are validated, uploaded with a one-hour expiry, and deleted
  immediately after the synchronous request on a best-effort basis.
- Explicit errors prevent PDFs from being silently dropped when Chat
  Completions, Responses streaming, or Drupal function tools are selected.
- PDF attachments are limited to 48 MB each.
- The provider-neutral **PDF Explorer [beta]** can test any configured provider
  and model advertising Drupal AI's **Chat with PDF** capability. Provider
  implementations retain responsibility for transport and retention behavior.
- After models are loaded successfully, **Update AI Capabilities** configures
  Grok as the default provider for every operation it supports. Chat-related
  operations use the selected model; image, video, and speech operations use
  their appropriate Grok models.

### Upgrade note

The capability update is intentionally comprehensive. Confirming it replaces
existing defaults from other providers for every operation supported by Grok.
Review the confirmation before applying it. If Grok is not selected for any AI
capability, the model-upgrade notice does not appear on Drupal AI's settings
page.

The consumer Grok PDF artifact skill is not exposed by xAI's developer API.
This release supports reading and reasoning over PDF chat attachments; it does
not claim native PDF artifact creation, merging, or splitting.

## Grok Integration 1.0.0-beta4

Grok Integration 1.0.0-beta4 adds an administrator-controlled upgrade path for
new numbered Grok model families and keeps fallback pricing coverage aligned
with models discovered from xAI.

### Highlights

- **Test connection and load models** now detects the newest available numbered
  Grok family rather than relying on a hard-coded preferred version.
- Drupal AI's **AI Capabilities from Installed Providers** section displays a
  confirmed update action when Grok capabilities still reference an older
  numbered family.
- One action updates all matching aliases in the old family and synchronizes
  the provider default without changing unrelated providers or model families.
- Published pricing rows for newly discovered models are merged into the
  editable pricing JSON without replacing administrator-maintained rows.
- Models whose fallback rates have not yet been published are clearly listed;
  exact request costs reported by xAI continue to take precedence.

### Requirements

- PHP 8.1 or later
- Drupal 10.6 or Drupal 11.2+
- Drupal AI 1.4 or later
- Key 1.22 or later
- An xAI API key stored in a Drupal Key entity

Install or update with Composer:

```bash
composer require 'drupal/grok:^1.0@beta'
drush en grok
```

The optional Grok Documents module is not installed by Grok Integration and is
not required for Collections Search. Install it separately only when Drupal
needs to administer collection registrations or document ingestion.

### Upgrade notes

Sites already running any earlier `1.0.0-beta` release can update normally and
should rebuild Drupal caches after deployment. Run **Test connection and load
models**, then review **AI Capabilities from Installed Providers** on Drupal
AI's settings page for model-family update actions. Review and save any pricing
rows merged into the provider form.

Sites upgrading from alpha8 or earlier must first export or record their
provider settings and uninstall the former Grok AI Provider
(`grok_ai_provider`) module. Update the code, enable `grok`, and restore the
settings. Drupal treats the old and new machine names as separate modules.

### Security and operational notes

- Hosted tools, including Collections Search, are disabled until permitted by
  an administrator and selected for an individual request.
- Grok Documents should use a separate least-privilege xAI Management API key;
  Grok Integration's inference key is not exposed to it automatically.
- Prompts, uploaded media, generated content, and hosted-tool context are sent
  to the configured API endpoint and may reach additional services selected by
  the administrator.
- Model-based moderation is probabilistic and is not a dedicated safety or
  compliance service.

### Release verification

The beta4 candidate passed Composer validation, the PHP coding-standard suite,
84 unit tests with 290 assertions, translation catalog validation, and local
release-archive inspection. The published commit is also subject to
the GitHub Actions dependency matrix for Drupal 10.6 with minimum dependencies
and Drupal 11 with current dependencies.

See [CHANGELOG.md](CHANGELOG.md), [TESTING.md](TESTING.md), and
[SECURITY.md](SECURITY.md) for the complete history, release gate, and security
policy.
