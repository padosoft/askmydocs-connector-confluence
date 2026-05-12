# Changelog

All notable changes to `padosoft/askmydocs-connector-confluence` are documented here.

## v1.0.0 — 2026-05-12 — Initial extraction

Inaugural release. Extracted from AskMyDocs v4.5 (`feature/v4.6` cycle, W4) as a standalone composer package.

### Added

- `ConfluenceConnector` implementing `Padosoft\AskMyDocsConnectorBase\ConnectorInterface`:
  - Atlassian OAuth 2.0 3LO with state-token CSRF protection (600 s TTL).
  - `accessible-resources` lookup picks the first Confluence-capable site (scopes include `read:confluence-*`); `cloud_id` persisted in `extra_json`.
  - Full sync via `/wiki/rest/api/space` + `/wiki/rest/api/content?spaceKey=...`.
  - Incremental sync via CQL `type = "page" AND lastModified > "YYYY-MM-DD HH:mm"` against `/wiki/rest/api/content/search`.
  - Archive-aware deletion reconciliation — pages with `status` of `archived`/`trashed` route through `softDeleteByRemoteId('confluence_page_id', ...)`.
  - Health probe against `/wiki/rest/api/user/current`.
  - Disconnect clears local credentials; Atlassian doesn't expose a programmatic revoke for OAuth 2.0 3LO grants.
- `Confluence\ConfluenceStorageToMarkdown` — storage-format-XHTML → markdown converter. Uses `DOMDocument::loadXML()` so namespaced `<ac:*>` / `<ri:*>` macros survive the parse on Linux libxml builds (cross-platform fix codified during v4.5/W5). Falls back to `loadHTML()` only for malformed input.
- `Confluence\AtlassianPaginator` — generic `_links.next` walker for Atlassian REST endpoints; eager `walk()` + lazy `walkLazy()` modes; explicit `ConnectorPaginationLimitException` on `maxPages` overflow.
- `ConfluenceServiceProvider` — registers the config block under `connectors.providers.confluence`, publishes the config + brand-icon as opt-in tags.
- Auto-registration via `extra.askmydocs.connectors` composer-extra discovery.
- 48 tests / 98 assertions; CI matrix PHP 8.3 / 8.4 / 8.5 × Laravel 12 / 13.

### Architecture

- IoC bridge via `Padosoft\AskMyDocsConnectorBase\Contracts\ConnectorIngestionContract` — this package never imports host classes (`App\Jobs\IngestDocumentJob`, `App\Models\KnowledgeDocument`); host applications bind their own implementation.
- Per-tenant isolation enforced via `TenantContext` injected by the base package.
- PII redaction at the ingest boundary via `$this->maybeRedactContent()` (no-op when the host doesn't wire it).
- Source-aware metadata builder surfaces `space_key`, `ancestor_titles`, `labels`, `restrictions_present`, `status`, `cloud_id`, `version`, and `last_modified` to the host reranker.
