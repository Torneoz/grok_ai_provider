# Release notes

## Grok Integration 1.0.0-beta3

Grok Integration 1.0.0-beta3 is the third beta of the Drupal AI integration
for xAI. It completes translation handling for provider metadata and improves
the guidance attached to configurable Explorer prompts.

### Highlights

- Provider API-definition descriptions and form-visible example values now use
  Drupal's String Translation API.
- The text-to-video default-provider schema label is now translatable.
- Every configurable Explorer default prompt has help text explaining where
  the example is displayed.
- The translation template and all ten packaged language catalogs include the
  newly exposed interface strings.

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

Sites already running `1.0.0-beta1` or `1.0.0-beta2` can update normally and should rebuild
Drupal caches after deployment.

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

The beta3 candidate passed Composer validation, the PHP coding-standard and
unit-test suites (79 tests, 274 assertions), translation catalog validation,
and local release-archive inspection. The published commit is also subject to
the GitHub Actions dependency matrix for Drupal 10.6 with minimum dependencies
and Drupal 11 with current dependencies.

See [CHANGELOG.md](CHANGELOG.md), [TESTING.md](TESTING.md), and
[SECURITY.md](SECURITY.md) for the complete history, release gate, and security
policy.
