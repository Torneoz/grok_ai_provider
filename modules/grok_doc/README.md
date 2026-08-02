# Grok Documents (`grok_doc`)

Grok Documents is an optional, experimental submodule for bulk ingestion into
existing xAI Collections. The main `grok` module continues to provide AI
operations and Collections Search; this submodule manages the documents that
make those searches useful.

## Alpha scope

- Register existing `collection_...` identifiers as Drupal configuration.
- Store xAI Management credentials through Drupal Key.
- Upload multiple Drupal-managed documents with batch metadata.
- Enforce 100 MiB per-file and configurable per-batch limits.
- Detect identical documents by SHA-256 within a Collection.
- Upload and index asynchronously through Drupal's Queue API.
- Track local, remote, indexing, ready, and failed states.
- Restrict Collections Search to registrations explicitly approved for search.
- Keep local registration deletion separate from destructive remote deletion.

The alpha intentionally does not create or delete remote Collections. Create a
temporary Collection in the xAI Console, then register its identifier in Drupal.
This prevents an early administrative feature from accidentally deleting
billable or production data.

## Installation

Enable the module, create a least-privilege xAI Management API key in Drupal
Key, and grant its upstream credential `AddFileToCollection` access.

```bash
drush en grok_doc
```

Visit **Configuration → AI → Grok collections**, register the Collection, and
then use **Bulk import**. Cron processes queued files; administrators may also
run a bounded queue batch from the process route during alpha testing.

## Security and cost notes

- A Management API key is more privileged than an ordinary inference key.
- Secrets are resolved from Key only while a queue item is processed.
- xAI stores uploaded Files and Collection indexes until they are removed.
- Storage, downloads, searches, and model tokens can all be billed separately.
- Zero-data-retention configurations are not compatible with persistent
  Collections.
- Test with non-sensitive documents and a temporary Collection first.

## Known alpha limitations

- Existing xAI Collections must be created outside Drupal.
- Remote deletion and replacement workflows are not exposed.
- Media Library selection and directory-based Drush import are not included.
- Progress refresh is manual; indexing is eventually consistent.
- Metadata is accepted as a JSON object but is not yet validated against remote
  Collection field definitions.

