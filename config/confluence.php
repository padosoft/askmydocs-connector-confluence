<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Confluence connector configuration
|--------------------------------------------------------------------------
|
| Provider settings for `padosoft/askmydocs-connector-confluence`.
|
| The base package merges this block under
| `config('connectors.providers.confluence')`, so concrete connector code
| reads its config via the standard
| `config('connectors.providers.confluence.<key>')` path.
|
| All knobs accept env-var overrides — set them in your host app's
| `.env` (see the package README §Credential setup).
|
*/

return [
    'client_id' => env('CONNECTOR_CONFLUENCE_CLIENT_ID'),
    'client_secret' => env('CONNECTOR_CONFLUENCE_CLIENT_SECRET'),
    'redirect_uri' => env(
        'CONNECTOR_CONFLUENCE_REDIRECT_URI',
        env('APP_URL', 'http://localhost').'/api/admin/connectors/confluence/oauth/callback'
    ),
    'oauth_authorize_url' => env(
        'CONNECTOR_CONFLUENCE_OAUTH_AUTHORIZE_URL',
        'https://auth.atlassian.com/authorize'
    ),
    'oauth_token_url' => env(
        'CONNECTOR_CONFLUENCE_OAUTH_TOKEN_URL',
        'https://auth.atlassian.com/oauth/token'
    ),
    'accessible_resources_url' => env(
        'CONNECTOR_CONFLUENCE_ACCESSIBLE_RESOURCES_URL',
        'https://api.atlassian.com/oauth/token/accessible-resources'
    ),
    'api_base' => env(
        'CONNECTOR_CONFLUENCE_API_BASE',
        'https://api.atlassian.com'
    ),
];
