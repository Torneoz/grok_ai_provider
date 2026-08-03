# Release notes

## Grok Integration 1.0.0-beta2

Grok Integration 1.0.0-beta2 is the second beta of the Drupal AI integration
for xAI. It fixes configuration after the module machine-name transition,
standardizes the public module name, and introduces a safe integration boundary
for the optional Grok Documents project.

### Highlights

- The module is now consistently presented as **Grok Integration** in current
  documentation, administration labels, recipe metadata, and release material.
- The provider configuration form now obtains extension information from the
  correct `grok` module, fixing the fatal error that could occur after the old
  `grok_ai_provider` machine name was removed.
- Collections Search remains an opt-in xAI hosted tool in Grok Integration.
  When the separately installed Grok Documents (`grok_doc`) module is present,
  searches can be restricted to collection registrations approved there.
- Grok Documents provides the optional administration workflow for registering
  existing xAI Collections, uploading documents, and processing bulk ingestion
  through Drupal queues. It remains a separate project and dependency.
- The translation template and all ten packaged translations include the new
  administration label.

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

Sites already running `1.0.0-beta1` can update normally and should rebuild
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

The beta2 candidate passed the PHP coding-standard and unit-test suites (79
tests, 274 assertions), translation catalog validation, and the GitHub Actions
dependency matrix for Drupal 10.6 with minimum dependencies and Drupal 11 with
current dependencies. The release archive job also passed.

See [CHANGELOG.md](CHANGELOG.md), [TESTING.md](TESTING.md), and
[SECURITY.md](SECURITY.md) for the complete history, release gate, and security
policy.
