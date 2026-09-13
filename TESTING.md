# Testing and release candidate checklist

## Automated checks

From a clean checkout, run the default/current dependency set:

```bash
composer install
composer validate --strict
composer phpcs
composer test
composer audit
```

GitHub Actions runs these checks on PHP 8.1 and PHP 8.3. Dependency resolution
explicitly covers Drupal 10.6 with minimum dependencies and Drupal 11 with
current dependencies. This includes the minimum supported Drupal AI 1.4.8 and
the newest compatible AI 1.x release instead of relying only on Composer's
normal resolution.

## Drupal integration checks

Run these checks in fresh Drupal 10.6 and Drupal 11 test sites:

1. Install Key, AI, and Grok Integration directly on both versions, and through
   the recipe on Drupal 11.
2. Run database updates from a database last used by every published alpha.
3. Validate exported configuration with Drupal's typed configuration manager.
4. Select a Key, test the connection, save a chat default, and clear caches.
5. Confirm an unrelated setting can be saved while xAI is temporarily
   unreachable, but changed connection settings are still validated.
6. Exercise each supported operation with minimum and maximum form values.
7. Exercise Explorer AJAX rebuilds, upload/default/Media image selection, and
   MP3/MP4 Media saving with and without optional Media modules.
8. Confirm hosted tools remain disabled at both site and request level by
   default, and verify each allowed tool independently.
9. Verify a video URL on an untrusted host, a private IP literal, an untrusted
   redirect, and an oversized response are all rejected.
10. Export and re-import configuration, uninstall the module, and confirm no
    unexpected configuration or temporary files remain.
11. Attach one and multiple PDFs to chat and verify the provider uses Responses,
    answers from each document, and removes the uploaded xAI files afterward.
12. Verify empty, falsely labelled, malformed, and larger-than-48-MB PDFs are
    rejected by the provider/API boundary, and that the one-hour expiry is sent
    before the multipart file. Local validation checks size and signature;
    structural PDF parsing is performed by xAI.
13. Verify PDF chat returns clear errors with forced Chat Completions,
    Responses streaming, unsupported models, and Drupal function tools.
14. Enable AI API Explorer and verify PDF Explorer [beta] lists only providers
    that are authenticated and have models advertising `ChatWithPdf`; verify
    repeatedly changing between at least three providers reloads each filtered
    model list and its configuration controls over AJAX. Confirm it accepts one
    and multiple genuine PDFs, rejects non-PDF uploads,
    displays allowed response HTML and request diagnostics, and strips scripts,
    event handlers, and other unsafe response markup.

Live API tests incur xAI charges. Use tightly bounded prompts and record the
model IDs and pricing-schedule hash with the release evidence.

## Release evidence

Create a release-evidence record outside the distributable archive and include:

- Commit and proposed tag.
- Links to successful dependency-matrix CI jobs.
- Drupal, PHP, AI, and Key versions used for each integration site.
- Pass/fail results for integration checks 1–14 on Drupal 10.6 and Drupal 11.
- The source alpha version used for every upgrade-path test.
- Live smoke-test operation, model ID, result, UTC timestamp, and pricing hash.
- Any accepted limitation, with an issue link and release-note text.

Do not include API keys, prompts containing private data, or generated private
media in the evidence.

## Release archive verification

Build and inspect the same tracked-file archive represented by the tag:

```bash
git archive --format=tar --output=/tmp/grok-release.tar HEAD
tar -tf /tmp/grok-release.tar
```

Confirm that the archive contains the module source, recipe, configuration,
translations, example asset, pricing data, README, changelog, testing guidance,
and security policy. Confirm that it excludes dependency trees, editor files,
test caches, credentials, generated media, and operating-system metadata.

## Release candidate tag gate

- Automated checks pass on both supported Drupal major versions.
- The upgrade path from all published alphas passes.
- Live smoke tests cover every advertised operation.
- README, schema, translations, pricing date, and changelog agree with code.
- No API keys, generated private media, test credentials, or local artifacts
  are present in the release archive.
- Release evidence identifies the exact commit and records every required
  matrix, integration, upgrade, and live smoke-test result.
