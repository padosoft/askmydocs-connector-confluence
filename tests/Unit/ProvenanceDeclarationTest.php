<?php

declare(strict_types=1);

namespace Padosoft\AskMyDocsConnectorConfluence\Tests\Unit;

use Padosoft\AskMyDocsConnectorBase\Contracts\DeclaresProvenance;
use Padosoft\AskMyDocsConnectorBase\ProvenanceTier;
use Padosoft\AskMyDocsConnectorConfluence\ConfluenceConnector;
use Padosoft\AskMyDocsConnectorConfluence\Tests\TestCase;

final class ProvenanceDeclarationTest extends TestCase
{
    public function test_connector_declares_provenance(): void
    {
        $this->assertInstanceOf(DeclaresProvenance::class, $this->app->make(ConfluenceConnector::class));
    }

    public function test_content_is_trusted_internal(): void
    {
        // This connector reads a system whose write access the organisation
        // grants, so whoever authored a document had to be given the ability
        // to author it.
        $tier = $this->app->make(ConfluenceConnector::class)->provenanceTier(1);

        $this->assertSame(ProvenanceTier::TrustedInternal, $tier);
        $this->assertFalse($tier->isExternallyAuthored());
    }

    public function test_the_tier_is_stable_across_installations(): void
    {
        $connector = $this->app->make(ConfluenceConnector::class);

        foreach ([1, 2, 99] as $installationId) {
            $this->assertSame(ProvenanceTier::TrustedInternal, $connector->provenanceTier($installationId));
        }
    }
}
