# Release notes

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
