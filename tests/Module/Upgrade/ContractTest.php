<?php

declare(strict_types=1);

namespace App\Tests\Module\Upgrade;

use PHPUnit\Framework\TestCase;

final class ContractTest extends TestCase
{
    public function testSyntheticFixtureAndContractPreserveRequiredDataWithoutLists(): void
    {
        $root = \dirname(__DIR__, 3);
        /** @var array{format: string, contract_version: string, direction: string, table_mappings: list<array{source: string}>, empty_target_tables: list<string>, target: array{initial_owner_role: string, initial_security_level: string, mfa_enrollment: string}} $contract */
        $contract = json_decode((string) file_get_contents($root.'/docs/upgrade/contract-1.0.json'), true, 512, JSON_THROW_ON_ERROR);
        /** @var array{installation: array{product_version: string}, synthetic: bool, campaigns: list<array{state: string}>, assertions: array<string, bool>, recipients: list<array{email: string}>} $fixture */
        $fixture = json_decode((string) file_get_contents($root.'/tests/Fixtures/upgrade-light-1.0.0.json'), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('emailer-light-to-ripe-contract-v1', $contract['format']);
        self::assertSame('1.0', $contract['contract_version']);
        self::assertSame('emailer-light->emailer-ripe', $contract['direction']);
        self::assertSame('1.0.0', $fixture['installation']['product_version']);
        self::assertTrue($fixture['synthetic']);
        self::assertSame(['draft', 'scheduled', 'paused', 'completed', 'failed'], array_column($fixture['campaigns'], 'state'));
        $mapped = array_column($contract['table_mappings'], 'source');
        foreach (['app_user', 'campaign_audience_recipient', 'campaign_recipient', 'recipient_token', 'delivery_attempt', 'analytics_aggregate', 'suppression', 'audit_event', 'encrypted_secret', 'email_template', 'template_version'] as $table) {
            self::assertContains($table, $mapped);
        }
        foreach (['contact', 'contact_list', 'list_membership', 'campaign_list_selection'] as $table) {
            self::assertContains($table, $contract['empty_target_tables']);
        }
        foreach ($fixture['assertions'] as $invariant => $required) {
            self::assertTrue($required, $invariant);
        }
        foreach ($fixture['recipients'] as $recipient) {
            self::assertStringEndsWith('@example.test', $recipient['email']);
        }
        self::assertSame('owner', $contract['target']['initial_owner_role']);
        self::assertSame('high', $contract['target']['initial_security_level']);
        self::assertSame('unconfigured', $contract['target']['mfa_enrollment']);
    }
}
