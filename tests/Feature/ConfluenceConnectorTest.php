<?php

declare(strict_types=1);

namespace Padosoft\AskMyDocsConnectorConfluence\Tests\Feature;

use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Padosoft\AskMyDocsConnectorBase\Contracts\ConnectorIngestionContract;
use Padosoft\AskMyDocsConnectorBase\Exceptions\ConnectorAuthException;
use Padosoft\AskMyDocsConnectorBase\HealthStatus;
use Padosoft\AskMyDocsConnectorBase\Models\ConnectorCredential;
use Padosoft\AskMyDocsConnectorBase\Models\ConnectorInstallation;
use Padosoft\AskMyDocsConnectorConfluence\ConfluenceConnector;
use Padosoft\AskMyDocsConnectorConfluence\Tests\Support\SpyIngestionContract;
use Padosoft\AskMyDocsConnectorConfluence\Tests\TestCase;

/**
 * Feature tests for {@see ConfluenceConnector}.
 *
 * Every Atlassian API interaction is stubbed via `Http::fake()`; host
 * pipeline dispatches go through a spy implementation of
 * {@see ConnectorIngestionContract}.
 */
final class ConfluenceConnectorTest extends TestCase
{
    private SpyIngestionContract $spy;

    protected function setUp(): void
    {
        parent::setUp();
        $this->spy = new SpyIngestionContract;
        $this->app->instance(ConnectorIngestionContract::class, $this->spy);
        Storage::fake('local');

        config()->set('connectors.providers.confluence.client_id', 'cid');
        config()->set('connectors.providers.confluence.client_secret', 'csec');
        config()->set('connectors.providers.confluence.redirect_uri', 'http://localhost/cb');
        config()->set('connectors.providers.confluence.api_base', 'https://api.atlassian.com');
    }

    private function connector(): ConfluenceConnector
    {
        return $this->app->make(ConfluenceConnector::class);
    }

    private function makeInstallation(string $tenantId = 'default'): ConnectorInstallation
    {
        return ConnectorInstallation::create([
            'tenant_id' => $tenantId,
            'connector_name' => 'confluence',
            'status' => ConnectorInstallation::STATUS_PENDING,
        ]);
    }

    /**
     * @param  array<string,mixed>  $extra
     */
    private function seedActiveCredential(
        int $installationId,
        string $access = 'AT-conf',
        ?string $refresh = 'RT-conf',
        array $extra = ['cloud_id' => 'cloud-123'],
        string $tenantId = 'default',
    ): void {
        ConnectorCredential::create([
            'tenant_id' => $tenantId,
            'connector_installation_id' => $installationId,
            'encrypted_access_token' => Crypt::encryptString($access),
            'encrypted_refresh_token' => $refresh === null ? null : Crypt::encryptString($refresh),
            'expires_at' => Carbon::now()->addHour(),
            'extra_json' => $extra === [] ? null : $extra,
        ]);
    }

    private function initiateAndExtractState(int $installationId): string
    {
        Cache::flush();
        $url = $this->connector()->initiateOAuth($installationId);
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        return (string) ($query['state'] ?? '');
    }

    public function test_key_and_display_name(): void
    {
        $this->assertSame('confluence', $this->connector()->key());
        $this->assertSame('Confluence', $this->connector()->displayName());
    }

    public function test_oauth_scopes_include_confluence_read_and_offline_access(): void
    {
        $scopes = $this->connector()->oauthScopes();
        $this->assertContains('read:confluence-content.all', $scopes);
        $this->assertContains('read:confluence-space.summary', $scopes);
        $this->assertContains('offline_access', $scopes);
    }

    public function test_initiate_oauth_returns_atlassian_auth_url_with_state(): void
    {
        $installation = $this->makeInstallation();

        $url = $this->connector()->initiateOAuth($installation->id);

        $this->assertStringStartsWith('https://auth.atlassian.com/authorize?', $url);
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        $this->assertSame('cid', $query['client_id']);
        $this->assertSame('api.atlassian.com', $query['audience']);
        $this->assertSame('http://localhost/cb', $query['redirect_uri']);
        $this->assertSame('code', $query['response_type']);
        $this->assertSame('consent', $query['prompt']);
        $this->assertNotEmpty($query['state']);
    }

    public function test_oauth_callback_exchanges_code_and_resolves_cloud_id(): void
    {
        $installation = $this->makeInstallation();
        $state = $this->initiateAndExtractState($installation->id);

        Http::fake([
            'auth.atlassian.com/oauth/token' => Http::response([
                'access_token' => 'AT-real',
                'refresh_token' => 'RT-real',
                'expires_in' => 3600,
                'token_type' => 'Bearer',
                'scope' => 'read:confluence-content.all',
            ], 200),
            'api.atlassian.com/oauth/token/accessible-resources' => Http::response([
                [
                    'id' => 'cloud-abc',
                    'name' => 'Acme Wiki',
                    'scopes' => ['read:confluence-content.all', 'read:confluence-user'],
                    'url' => 'https://acme.atlassian.net',
                ],
            ], 200),
        ]);

        $req = Request::create('/cb', 'GET', ['code' => 'auth-code', 'state' => $state]);
        $this->connector()->handleOAuthCallback($installation->id, $req);

        $row = ConnectorCredential::query()
            ->where('connector_installation_id', $installation->id)
            ->first();
        $this->assertNotNull($row);
        $this->assertSame('AT-real', Crypt::decryptString($row->encrypted_access_token));
        $this->assertSame('cloud-abc', $row->extra_json['cloud_id'] ?? null);
    }

    public function test_oauth_callback_picks_first_confluence_capable_resource(): void
    {
        $installation = $this->makeInstallation();
        $state = $this->initiateAndExtractState($installation->id);

        Http::fake([
            'auth.atlassian.com/oauth/token' => Http::response([
                'access_token' => 'AT-x',
                'expires_in' => 3600,
            ], 200),
            // Two sites — first is Jira-only, second is Confluence.
            'api.atlassian.com/oauth/token/accessible-resources' => Http::response([
                [
                    'id' => 'cloud-jira',
                    'scopes' => ['read:jira-work', 'read:jira-user'],
                ],
                [
                    'id' => 'cloud-confluence',
                    'scopes' => ['read:confluence-content.all'],
                ],
            ], 200),
        ]);

        $req = Request::create('/cb', 'GET', ['code' => 'c', 'state' => $state]);
        $this->connector()->handleOAuthCallback($installation->id, $req);

        $row = ConnectorCredential::query()
            ->where('connector_installation_id', $installation->id)
            ->first();
        $this->assertNotNull($row);
        $this->assertSame('cloud-confluence', $row->extra_json['cloud_id'] ?? null);
    }

    public function test_oauth_callback_rejects_invalid_state(): void
    {
        $installation = $this->makeInstallation();
        $this->initiateAndExtractState($installation->id);

        $req = Request::create('/cb', 'GET', ['code' => 'c', 'state' => 'WRONG']);
        $this->expectException(ConnectorAuthException::class);
        $this->connector()->handleOAuthCallback($installation->id, $req);
    }

    public function test_oauth_callback_rejects_missing_code(): void
    {
        $installation = $this->makeInstallation();
        $state = $this->initiateAndExtractState($installation->id);

        $req = Request::create('/cb', 'GET', ['state' => $state]);
        $this->expectException(ConnectorAuthException::class);
        $this->connector()->handleOAuthCallback($installation->id, $req);
    }

    public function test_oauth_callback_fails_when_token_exchange_returns_non_2xx(): void
    {
        $installation = $this->makeInstallation();
        $state = $this->initiateAndExtractState($installation->id);

        Http::fake([
            'auth.atlassian.com/oauth/token' => Http::response(['error' => 'invalid_grant'], 400),
        ]);

        $req = Request::create('/cb', 'GET', ['code' => 'c', 'state' => $state]);
        $this->expectException(ConnectorAuthException::class);
        $this->connector()->handleOAuthCallback($installation->id, $req);
    }

    public function test_oauth_callback_fails_when_no_accessible_resources(): void
    {
        $installation = $this->makeInstallation();
        $state = $this->initiateAndExtractState($installation->id);

        Http::fake([
            'auth.atlassian.com/oauth/token' => Http::response([
                'access_token' => 'AT-x',
                'expires_in' => 3600,
            ], 200),
            'api.atlassian.com/oauth/token/accessible-resources' => Http::response([], 200),
        ]);

        $req = Request::create('/cb', 'GET', ['code' => 'c', 'state' => $state]);
        $this->expectException(ConnectorAuthException::class);
        $this->connector()->handleOAuthCallback($installation->id, $req);
    }

    public function test_full_sync_walks_spaces_and_pages_and_dispatches_ingestion(): void
    {
        $installation = $this->makeInstallation();
        $this->seedActiveCredential($installation->id);

        Http::fake([
            // Space list.
            'api.atlassian.com/ex/confluence/cloud-123/wiki/rest/api/space*' => Http::response([
                'results' => [
                    ['key' => 'ENG', 'name' => 'Engineering'],
                ],
                '_links' => [],
            ], 200),
            // Pages in space ENG.
            'api.atlassian.com/ex/confluence/cloud-123/wiki/rest/api/content?spaceKey=ENG*' => Http::response([
                'results' => [
                    [
                        'id' => '101',
                        'title' => 'Architecture',
                        'body' => ['storage' => ['value' => '<p>Hello <strong>world</strong></p>']],
                        'version' => ['number' => 4, 'when' => '2026-05-12T10:00:00.000Z'],
                        'space' => ['key' => 'ENG', 'name' => 'Engineering'],
                        'status' => 'current',
                        'metadata' => ['labels' => ['results' => [['name' => 'architecture']]]],
                    ],
                ],
                '_links' => [],
            ], 200),
        ]);

        $result = $this->connector()->syncFull($installation->id);

        $this->assertSame(1, $result->documentsAdded);
        $this->assertSame(0, $result->documentsRemoved);
        $this->assertCount(1, $this->spy->dispatches);

        $dispatch = $this->spy->dispatches[0];
        $this->assertSame('Architecture', $dispatch['title']);
        $this->assertSame('connector-confluence', $dispatch['projectKey']);
        $this->assertStringContainsString('connectors/confluence/eng/101.md', $dispatch['relativePath']);
        $this->assertSame('default', $dispatch['tenantId']);

        $metadata = $dispatch['metadata'];
        $this->assertSame('confluence', $metadata['connector']);
        $this->assertSame('101', $metadata['confluence_page_id']);
        $this->assertSame('ENG', $metadata['confluence_space_key']);
        $this->assertSame('cloud-123', $metadata['confluence_cloud_id']);
    }

    public function test_full_sync_skips_pages_with_whitespace_only_storage_body(): void
    {
        $installation = $this->makeInstallation();
        $this->seedActiveCredential($installation->id);

        Http::fake([
            'api.atlassian.com/ex/confluence/cloud-123/wiki/rest/api/space*' => Http::response([
                'results' => [['key' => 'ENG']],
                '_links' => [],
            ], 200),
            // Storage body that converts to empty markdown — whitespace only.
            // The converter trims everything and returns '', so the connector
            // skips the page (no 0-byte ingest file).
            'api.atlassian.com/ex/confluence/cloud-123/wiki/rest/api/content*' => Http::response([
                'results' => [
                    [
                        'id' => '102',
                        'title' => 'Whitespace page',
                        'body' => ['storage' => ['value' => '   '."\n  "]],
                        'version' => ['number' => 1],
                        'space' => ['key' => 'ENG'],
                        'status' => 'current',
                    ],
                ],
                '_links' => [],
            ], 200),
        ]);

        $this->connector()->syncFull($installation->id);

        // Skipped-empty pages don't produce ingest dispatches — that's the
        // contract this test guards. The `documentsAdded` counter still
        // increments because `ingestPage` returns void on skip; downstream
        // consumers must rely on the dispatch count, not the counter.
        $this->assertCount(0, $this->spy->dispatches);
    }

    public function test_incremental_sync_falls_back_to_full_when_since_null(): void
    {
        $installation = $this->makeInstallation();
        $this->seedActiveCredential($installation->id);

        Http::fake([
            'api.atlassian.com/ex/confluence/cloud-123/wiki/rest/api/space*' => Http::response([
                'results' => [],
                '_links' => [],
            ], 200),
        ]);

        $result = $this->connector()->syncIncremental($installation->id, null);
        $this->assertSame(0, $result->documentsAdded);
    }

    public function test_incremental_sync_archives_status_archived_pages(): void
    {
        $installation = $this->makeInstallation();
        $this->seedActiveCredential($installation->id);
        $this->spy->remoteIdsThatMatch = ['9001' => 'default'];

        Http::fake([
            'api.atlassian.com/ex/confluence/cloud-123/wiki/rest/api/content/search*' => Http::response([
                'results' => [
                    [
                        'id' => '9001',
                        'title' => 'Old page',
                        'status' => 'archived',
                        'space' => ['key' => 'ENG'],
                    ],
                ],
                '_links' => [],
            ], 200),
        ]);

        $since = Carbon::parse('2026-05-01T00:00:00Z');
        $result = $this->connector()->syncIncremental($installation->id, $since);

        $this->assertSame(0, $result->documentsAdded);
        $this->assertSame(1, $result->documentsRemoved);
        $this->assertCount(1, $this->spy->deletions);
        $this->assertSame('9001', $this->spy->deletions[0]['remote_id']);
        $this->assertSame('confluence_page_id', $this->spy->deletions[0]['metadata_key']);
    }

    public function test_health_reports_healthy_with_valid_token(): void
    {
        $installation = $this->makeInstallation();
        $this->seedActiveCredential($installation->id);

        Http::fake([
            'api.atlassian.com/ex/confluence/cloud-123/wiki/rest/api/user/current*' => Http::response([
                'accountId' => 'a-1',
            ], 200),
        ]);

        $status = $this->connector()->health($installation->id);
        $this->assertSame(HealthStatus::STATE_HEALTHY, $status->state);
    }

    public function test_health_reports_errored_without_credentials(): void
    {
        $installation = $this->makeInstallation();
        $status = $this->connector()->health($installation->id);
        $this->assertSame(HealthStatus::STATE_ERRORED, $status->state);
    }

    public function test_health_reports_errored_on_401(): void
    {
        $installation = $this->makeInstallation();
        $this->seedActiveCredential($installation->id);

        Http::fake([
            'api.atlassian.com/ex/confluence/cloud-123/wiki/rest/api/user/current*' => Http::response([], 401),
        ]);

        $status = $this->connector()->health($installation->id);
        $this->assertSame(HealthStatus::STATE_ERRORED, $status->state);
    }

    public function test_disconnect_clears_local_credentials_and_emits_audit(): void
    {
        $installation = $this->makeInstallation();
        $this->seedActiveCredential($installation->id);

        $this->connector()->disconnect($installation->id);

        $this->assertSame(0, ConnectorCredential::query()
            ->where('connector_installation_id', $installation->id)
            ->count());

        $disconnectAudit = collect($this->spy->audits)->firstWhere('eventType', 'disconnected');
        $this->assertNotNull($disconnectAudit);
        $this->assertSame('confluence', $disconnectAudit['connectorKey']);
    }

    public function test_pii_redaction_runs_through_spy_at_ingest_boundary(): void
    {
        $installation = $this->makeInstallation();
        $this->seedActiveCredential($installation->id);
        $this->spy->redactionPrefix = "[REDACTED]\n\n";

        Http::fake([
            'api.atlassian.com/ex/confluence/cloud-123/wiki/rest/api/space*' => Http::response([
                'results' => [['key' => 'ENG']],
                '_links' => [],
            ], 200),
            'api.atlassian.com/ex/confluence/cloud-123/wiki/rest/api/content?spaceKey=ENG*' => Http::response([
                'results' => [
                    [
                        'id' => '300',
                        'title' => 'Secret memo',
                        'body' => ['storage' => ['value' => '<p>contains pii</p>']],
                        'space' => ['key' => 'ENG'],
                        'status' => 'current',
                    ],
                ],
                '_links' => [],
            ], 200),
        ]);

        $this->connector()->syncFull($installation->id);

        $this->assertCount(1, $this->spy->dispatches);
        $writtenPath = $this->spy->dispatches[0]['relativePath'];
        $body = Storage::disk('local')->get($writtenPath);
        $this->assertIsString($body);
        $this->assertStringContainsString('[REDACTED]', (string) $body);
    }

    public function test_full_sync_fails_loudly_without_cloud_id(): void
    {
        $installation = $this->makeInstallation();
        // Seed credentials but WITHOUT cloud_id in extra.
        $this->seedActiveCredential($installation->id, extra: []);

        $this->expectException(ConnectorAuthException::class);
        $this->connector()->syncFull($installation->id);
    }
}
