# Testing and beta release checklist

## Automated checks

From a clean checkout:

```bash
composer install
composer validate --strict
composer phpcs
composer test
```

GitHub Actions runs these checks on PHP 8.1 and PHP 8.3. Dependency resolution
must cover Drupal 10.3 at the minimum end and Drupal 11 at the current end of
the declared `drupal/core` constraint. Before a stable release, also test the
minimum supported Drupal AI 1.4 release and the newest compatible AI 1.x
release explicitly rather than relying only on Composer's normal resolution.

## Drupal integration checks

Run these checks in fresh Drupal 10.3 and Drupal 11 test sites:

1. Install Key, AI, and Grok AI Provider directly and through the recipe.
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

Live API tests incur xAI charges. Use tightly bounded prompts and record the
model IDs and pricing-schedule hash with the release evidence.

## Beta tag gate

- Automated checks pass on both supported Drupal major versions.
- The upgrade path from all published alphas passes.
- Live smoke tests cover every advertised operation.
- README, schema, translations, pricing date, and changelog agree with code.
- No API keys, generated private media, test credentials, or local artifacts
  are present in the release archive.
