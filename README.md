<h1 align="center">askmydocs-connector-confluence</h1>

<p align="center">
  <strong>Confluence Cloud connector for AskMyDocs — OAuth 2.0 3LO sync with native storage-format XHTML→markdown rendering, CQL-driven incremental sync, and archive-aware deletion reconciliation.</strong><br/>
  Drop-in Laravel package. <code>composer require</code> it from any AskMyDocs install and the Confluence connector appears in the admin UI on the next request.
</p>

<p align="center">
  <a href="https://github.com/padosoft/askmydocs-connector-confluence/actions/workflows/tests.yml"><img alt="CI status" src="https://img.shields.io/github/actions/workflow/status/padosoft/askmydocs-connector-confluence/tests.yml?branch=main&label=tests"></a>
  <a href="https://packagist.org/packages/padosoft/askmydocs-connector-confluence"><img alt="Packagist version" src="https://img.shields.io/packagist/v/padosoft/askmydocs-connector-confluence.svg?label=packagist"></a>
  <a href="https://packagist.org/packages/padosoft/askmydocs-connector-confluence"><img alt="Total downloads" src="https://img.shields.io/packagist/dt/padosoft/askmydocs-connector-confluence.svg?label=downloads"></a>
  <a href="LICENSE"><img alt="License" src="https://img.shields.io/badge/license-Apache--2.0-blue.svg"></a>
  <img alt="PHP version" src="https://img.shields.io/badge/php-8.3%20%7C%208.4%20%7C%208.5-777BB4">
  <img alt="Laravel version" src="https://img.shields.io/badge/laravel-12%20%7C%2013-FF2D20">
</p>

---

## Table of contents

1. [Why this package](#why-this-package)
2. [Features](#features)
3. [AI vibe-coding pack included](#-ai-vibe-coding-pack-included)
4. [Architecture at a glance](#architecture-at-a-glance)
5. [Installation](#installation)
6. [Credential setup (junior-proof, step by step)](#credential-setup-junior-proof-step-by-step)
7. [Activation inside AskMyDocs](#activation-inside-askmydocs)
8. [What gets ingested](#what-gets-ingested)
9. [Sync semantics](#sync-semantics)
10. [Testing](#testing)
11. [Live testsuite](#live-testsuite)
12. [Troubleshooting](#troubleshooting)
13. [License](#license)

---

## Why this package

[AskMyDocs](https://github.com/lopadova/AskMyDocs) is an enterprise-grade RAG + canonical knowledge compilation system. Out of the box it ingests markdown from disk, the chat UI, an HTTP API, and a Git-driven workflow — but most teams' institutional knowledge lives in Confluence.

This package is the smallest possible surface for shipping that integration:

- A `ConfluenceConnector` that implements `Padosoft\AskMyDocsConnectorBase\ConnectorInterface`.
- A `ConfluenceStorageToMarkdown` converter that flattens Confluence's storage-format XHTML (with namespaced `<ac:*>` and `<ri:*>` macros) into clean GitHub-flavoured markdown — headings, lists, tables, fenced code, panels (info/note/warning/tip), task lists (`<ac:task-list>` → `- [x]`), page-link wikilinks, expand macros to `<details>` blocks.
- An `AtlassianPaginator` walker for the shared `_links.next` pagination contract — reused at the Jira sister package.
- A composer.json that auto-registers via `extra.askmydocs.connectors`. Zero edits to your host app's config required.

> **`composer require padosoft/askmydocs-connector-confluence`. Done.**

## Features

- 🔌 **Zero-config installation** — composer-extra discovery auto-registers the connector at boot.
- 🔐 **Atlassian OAuth 2.0 3LO** — single-use state-token CSRF protection with 600 s TTL, `accessible-resources` lookup to resolve the per-tenant `cloud_id`, refresh-token rotation built-in.
- 🌐 **Cloud-id-aware** — `cloud_id` persisted in `extra_json` and re-used across all subsequent API calls; supports operators with multiple Atlassian sites without manual switching.
- ♻️ **Incremental sync** — CQL `type = "page" AND lastModified > "YYYY-MM-DD HH:mm"` query; daily syncs cost one round-trip on quiet wikis.
- 🗑️ **Archive-aware deletion** — pages flipped to `status='archived'` or `status='trashed'` route through the host's deletion service via `softDeleteByRemoteId('confluence_page_id', ...)`.
- 📑 **Storage-format-aware markdown** — handles Confluence's `<ac:*>` macros (code, info/warning/note/tip panels, expand, task-list, ac:link to other pages); unknown macros emit a visible `[macro: <name>]` placeholder so operators can audit content gaps rather than silently dropping content.
- 🧠 **Source-aware metadata** — labels, ancestor titles, space key, version, restrictions presence, last-modified timestamp all surface to the host's reranker via `SourceAwareMetadataBuilder`.
- 📚 **Page-hierarchy retrieval** — `space_key`, `ancestor_titles` and the rich frontmatter let the host's Confluence-aware chunker surface results with full "Space → Parent → … → Page → Section" breadcrumbs.
- 🚦 **Failure-loud exception taxonomy** — 401 / 403 → `ConnectorAuthException`, 5xx / 429 → `ConnectorApiException`, `_links.next` infinite loop → `ConnectorPaginationLimitException` with `maxPages` field.
- 🏢 **Per-tenant isolated** — every credential read and ingestion dispatch is scoped to the active `TenantContext`.
- 🧪 **Test-friendly** — pure-PHP unit tests for the storage-format converter, `Http::fake()` feature tests for the connector + paginator, opt-in live test against a real Atlassian sandbox cloud when `CONNECTOR_CONFLUENCE_LIVE=1`.

## 🚀 AI vibe-coding pack included

This package was built with a vibe-coding pack of Claude Code skills and rules (`.claude/` directory in the parent AskMyDocs repo) that codify the architectural invariants — the IoC contract that keeps this package standalone-agnostic, the Atlassian REST API quirks the connector navigates (relative `_links.next`, scope-driven `cloud_id` resolution, archived pages as deletion signals), the failure-loud exception taxonomy, the storage-format-XHTML parsing contract.

The `ConfluenceStorageToMarkdown` parser specifically uses `DOMDocument::loadXML()` (NOT `loadHTML()`) so namespaced `<ac:*>` and `<ri:*>` macro tags survive the parse on Linux libxml builds — a cross-platform regression caught and codified during the v4.5/W5 development of this connector, now part of the AI vibe-coding pack.

If you're using Claude Code to fork or extend this package, point the agent at the parent repo's `.claude/` pack and it stays inside the invariants automatically. No tribal-knowledge drift.

## Architecture at a glance

```
                ┌──────────────────────────────┐
Composer        │ padosoft/askmydocs-          │
require ───────▶│ connector-confluence         │
                │ (this package)               │
                └────────────┬─────────────────┘
                             │
                             │ auto-registered via composer
                             │ extra.askmydocs.connectors
                             ▼
                ┌──────────────────────────────┐
                │ padosoft/askmydocs-connector-│
                │ base v1.1.1+                 │
                │ ConnectorRegistry            │
                └────────────┬─────────────────┘
                             │
                             │ resolves ConfluenceConnector
                             ▼
                ┌──────────────────────────────┐
                │ ConfluenceConnector::syncFull│
                │  • /accessible-resources     │
                │  • GET  /wiki/.../space      │
                │  • GET  /wiki/.../content    │
                │  • ConfluenceStorageToMd     │
                │  • SourceAwareMetadata       │
                └────────────┬─────────────────┘
                             │
                             │ ConnectorIngestionContract
                             │ (IoC bridge — host implements)
                             ▼
                ┌──────────────────────────────┐
                │ Host app (AskMyDocs):        │
                │  • Storage::put → KB disk    │
                │  • IngestDocumentJob         │
                │  • kb_canonical_audit row    │
                │  • PII redactor at boundary  │
                └──────────────────────────────┘
```

The IoC bridge is the key design decision: this package never imports `App\Jobs\IngestDocumentJob`, `App\Models\KnowledgeDocument`, or any other host class. It dispatches every host-side concern through `Padosoft\AskMyDocsConnectorBase\Contracts\ConnectorIngestionContract`. The host binds its own implementation in a service provider; this package stays standalone-agnostic so it can run inside AskMyDocs Community Edition, AskMyDocs Pro, or any third-party Laravel app that wants Confluence-backed RAG.

## Installation

```bash
composer require padosoft/askmydocs-connector-confluence
```

The package follows Laravel's auto-discovery convention so no manual provider registration is required. After install, run:

```bash
php artisan vendor:publish --tag=connector-confluence-config   # optional — for env-var overrides
php artisan vendor:publish --tag=connector-confluence-assets   # optional — copies confluence.svg to public/connectors
```

The `connector-base` migrations ship in the parent package (`padosoft/askmydocs-connector-base`) and auto-load via its service provider; no extra `migrate` step is needed.

## Credential setup (junior-proof, step by step)

Confluence Cloud uses Atlassian's OAuth 2.0 3LO (3-legged OAuth) flow registered through the Atlassian Developer Console. You need a `client_id`, `client_secret`, and a redirect URI registered with Atlassian. Follow EVERY step.

### 1. Sign in to the Atlassian Developer Console

1. Open <https://developer.atlassian.com/console/myapps/> in your browser.
2. Sign in with the Atlassian account that owns (or has admin access to) the Confluence site you want to integrate.
3. If you don't yet have an Atlassian account, sign up at <https://id.atlassian.com/signup>.

### 2. Create a new OAuth 2.0 (3LO) integration

1. Click **"Create"** in the top-right of the Developer Console landing page.
2. Pick **"OAuth 2.0 integration"** from the dropdown.
3. Fill in the create-app form:
    - **Name**: `AskMyDocs` (or any label that makes sense for your operators)
    - Click **"Create"** to land on the new app's overview page.

### 3. Add the Confluence API permissions

1. From the app's left navigation, click **"Permissions"**.
2. Find the **"Confluence API"** row and click **"Add"**.
3. After adding, click **"Configure"** on the same row.
4. Tick the following scopes (and ONLY these — the connector is strictly read-only):
    - `read:confluence-content.all` — read all content (pages, blog posts, attachments)
    - `read:confluence-space.summary` — list spaces accessible to the user
    - `read:confluence-user` — read user info (used for the health probe)
    - `offline_access` — issue refresh tokens (required so sync keeps running past the initial access-token TTL)
5. Click **"Save"**.

### 4. Configure the OAuth 2.0 (3LO) callback URL

1. From the app's left navigation, click **"Authorization"**.
2. Click **"Configure"** on the **"OAuth 2.0 (3LO)"** row.
3. Set **Callback URL** to your host app's callback endpoint, for example:
   ```
   https://your-app.example.com/api/admin/connectors/confluence/oauth/callback
   ```
4. Click **"Save changes"**.

Atlassian requires the callback URL to be HTTPS in production. For local development behind `http://localhost` you can also use a tunnel (Cloudflare Tunnel, ngrok, Tailscale Funnel) — set the tunnel URL as the callback.

### 5. Capture the credentials

1. From the app's left navigation, click **"Settings"**.
2. Scroll to **"Authentication details"**:
   - **Client ID** → `CONNECTOR_CONFLUENCE_CLIENT_ID`
   - **Secret** → `CONNECTOR_CONFLUENCE_CLIENT_SECRET` (click "Show" to reveal)

### 6. Write credentials to `.env`

In your AskMyDocs host app's `.env`:

```dotenv
CONNECTOR_CONFLUENCE_CLIENT_ID=<your-client-id>
CONNECTOR_CONFLUENCE_CLIENT_SECRET=<your-client-secret>
CONNECTOR_CONFLUENCE_REDIRECT_URI=https://your-app.example.com/api/admin/connectors/confluence/oauth/callback
# Optional — only override if you proxy Atlassian's API:
# CONNECTOR_CONFLUENCE_API_BASE=https://api.atlassian.com
# CONNECTOR_CONFLUENCE_OAUTH_AUTHORIZE_URL=https://auth.atlassian.com/authorize
# CONNECTOR_CONFLUENCE_OAUTH_TOKEN_URL=https://auth.atlassian.com/oauth/token
```

### 7. Verify (curl)

After completing the OAuth flow once (step 8 below), grab the access token from the database via `php artisan tinker` and run:

```bash
curl -s https://api.atlassian.com/oauth/token/accessible-resources \
  -H "Authorization: Bearer <access-token>"
```

You should see a JSON array of accessible Atlassian sites. If the result is `[]`, the user account doesn't have access to any Atlassian site with the requested Confluence scopes — re-check the permissions granted in step 3.

### 8. Common errors

- `redirect_uri_mismatch` — The exact redirect URI in `.env` must match the one registered in the Developer Console (case-sensitive, trailing slashes matter).
- `invalid_scope` — Your Developer Console app doesn't have one of the required scopes enabled. Re-check step 3.
- `User has not granted access to any Atlassian site` — The OAuth grant succeeded but the user account has no Confluence site access. Add the user to the relevant Atlassian organization at <https://admin.atlassian.com>.

## Activation inside AskMyDocs

After `composer require` + the env vars above:

1. Run the host app's admin UI.
2. Navigate to **Settings → Connectors**.
3. The **Confluence** card appears with an **Install** button.
4. Click **Install** → browser redirects to `auth.atlassian.com` → operator authorises → returns to the admin UI → status flips to `active`.
5. The first full sync fires within the cadence window (default 15 minutes; configurable via `CONNECTOR_DEFAULT_SYNC_CADENCE_MINUTES`). To trigger immediately, click **Sync now**.

## What gets ingested

For every Confluence page the integration can see:

- **Markdown body** — storage-format XHTML rendered via `ConfluenceStorageToMarkdown`. Page title prepended as `# Title` so the host's chunker indexes it.
- **Frontmatter / metadata** captured under `metadata.converter_hints.confluence`:
  - `space_key`, `space_name`
  - `cloud_id`, `page_id`, `version`
  - `labels` — page label names
  - `ancestor_titles` — root → leaf path of parent pages
  - `restrictions_present` — `true` when the page has read restrictions
  - `status` — `current`, `draft`, `archived`, etc.
- **`_derived` reranker signals** under `metadata.converter_hints._derived`:
  - `search_tags`, `status_active`, `recency_bucket`

The synthetic MIME `application/vnd.confluence.page+json` routes the document to the host's Confluence-aware chunker when one is installed.

## Sync semantics

- **Full sync** — `GET /wiki/rest/api/space` to enumerate accessible spaces, then `GET /wiki/rest/api/content?spaceKey=...&expand=body.storage,version,space,status` per space. Pagination follows `_links.next` until exhausted. Safety cap at 100 pages per resource (~2 500 items at 25/page); when the cap fires a `ConnectorPaginationLimitException` surfaces in the per-space error counter.
- **Incremental sync** — `GET /wiki/rest/api/content/search` with CQL `type = "page" AND lastModified > "YYYY-MM-DD HH:mm"`. Confluence returns only pages modified after `$since` (UTC). Same `_links.next` pagination contract.
- **Deletion reconciliation** — pages with `status` of `archived` or `trashed` route through `ConnectorIngestionContract::softDeleteByRemoteId('confluence_page_id', ...)`. The host's deletion service finds the matching `knowledge_documents` row (tenant-scoped) and soft-deletes it.
- **Disconnect** — Atlassian does NOT expose a programmatic revoke endpoint for OAuth 2.0 3LO grants. Disconnect clears local credentials; the operator must complete revocation manually via <https://id.atlassian.com> → **Privacy and security** → **Connected apps**. The access token expires naturally regardless.

## Testing

```bash
composer install
vendor/bin/phpunit
```

The suite has three flavours:

| Suite     | What it covers                                                                                  | Network |
|-----------|-------------------------------------------------------------------------------------------------|---------|
| Unit      | `ConfluenceStorageToMarkdown` — pure PHP, 20+ XHTML / macro shape cases.                        | None    |
| Feature   | `ConfluenceConnector` + `AtlassianPaginator` against `Http::fake()` and the spy ingestion contract. | None    |
| Live      | Opt-in — actually hits the configured Atlassian cloud. Skipped unless `CONNECTOR_CONFLUENCE_LIVE=1`. | Real    |

CI runs Default (Unit + Feature) against PHP 8.3 / 8.4 / 8.5 × Laravel 12 / 13.

## Live testsuite

The live suite is **opt-in** so CI never pays for real API calls. To run it:

```bash
export CONNECTOR_CONFLUENCE_LIVE=1
export CONNECTOR_CONFLUENCE_TOKEN=<an-active-oauth-access-token>
export CONNECTOR_CONFLUENCE_CLOUD_ID=<the-cloud-id-from-accessible-resources>
vendor/bin/phpunit --testsuite=Live
```

This calls `/wiki/rest/api/user/current` on the real Atlassian cloud once to validate credentials.

## Troubleshooting

| Symptom | Likely cause | Fix |
|---|---|---|
| `401 invalid_token` during sync | Refresh token expired (Atlassian rotates them aggressively when the user revokes consent), or operator manually revoked the connection from id.atlassian.com | Re-install from the admin UI |
| `403 quota_exceeded` | Hit the per-tenant Atlassian API rate-limit (5000 requests / 5 min by default) | Wait or split the workspace across multiple installations |
| `Confluence accessible-resources returned no resources` | OAuth grant succeeded but the user has no Confluence access to any Atlassian site | Add the user as a Confluence-user member in <https://admin.atlassian.com> |
| `Confluence cloud_id missing` | Race condition during the OAuth flow — the `accessible-resources` call returned `[]` so `cloud_id` was never stored | Re-install from the admin UI; the new flow will retry the lookup |
| `[macro: <name>]` placeholders in ingested markdown | A Confluence macro the converter doesn't natively handle (e.g. `gallery`, `jira-issues`) | This is by design — the placeholder is visible so operators can audit. Open an issue if you need a specific macro supported. |
| Pages ingest with empty body | Page contains only `<ac:image>` attachments or unsupported macros | This is by design — AskMyDocs doesn't yet ingest binary attachments from Confluence. |

## License

Apache-2.0 — see [LICENSE](LICENSE).

Built and maintained by [Padosoft](https://padosoft.com/). Part of the AskMyDocs connector ecosystem.
