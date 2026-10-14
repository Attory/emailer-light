<?php

declare(strict_types=1);

namespace App\Tests\Module\Backup;

use App\Module\Backup\Application\RestoreDrillEvidence;
use App\Module\Secrets\Application\Contract\MasterKey;
use App\Shared\Identity;
use PHPUnit\Framework\TestCase;

final class RestoreDrillEvidenceTest extends TestCase
{
    public function testEvidenceIsPurposeAuthenticatedAndContainsOnlyRedactedMetadata(): void
    {
        $key = self::createStub(MasterKey::class);
        $key->method('load')->willReturn(random_bytes(32));
        $evidence = new RestoreDrillEvidence($key);
        $payload = $this->payload();
        $receipt = $evidence->issue($payload);
        self::assertSame($payload, $evidence->authenticate($receipt));
        self::assertStringNotContainsString('@', $receipt);
        self::assertStringNotContainsString('/private/', $receipt);

        $tampered = json_decode($receipt, true, 8, JSON_THROW_ON_ERROR);
        self::assertIsArray($tampered);
        $tampered['checked_assets'] = 1;
        try {
            $evidence->authenticate(json_encode($tampered, JSON_THROW_ON_ERROR));
            self::fail('Tampered drill evidence was accepted.');
        } catch (\RuntimeException $exception) {
            self::assertSame('Restore-drill evidence authentication failed.', $exception->getMessage());
        }
        $otherKey = self::createStub(MasterKey::class);
        $otherKey->method('load')->willReturn(random_bytes(32));
        $this->expectException(\RuntimeException::class);
        (new RestoreDrillEvidence($otherKey))->authenticate($receipt);
    }

    public function testMalformedOversizedAndUnclassifiedEvidenceIsRejected(): void
    {
        $key = self::createStub(MasterKey::class);
        $key->method('load')->willReturn(random_bytes(32));
        $evidence = new RestoreDrillEvidence($key);
        $payload = $this->payload();
        foreach (['{}', '[1]', '{', str_repeat('x', 4097), json_encode([...$payload, 'mac' => str_repeat('a', 64), 'path' => '/private/forbidden'], JSON_THROW_ON_ERROR), json_encode([...$payload, 'restored_at' => '2026-02-30 00:00:00.000000', 'mac' => str_repeat('a', 64)], JSON_THROW_ON_ERROR)] as $invalid) {
            try {
                $evidence->authenticate($invalid);
                self::fail('Invalid restore-drill evidence was accepted.');
            } catch (\RuntimeException $exception) {
                self::assertSame('Restore-drill evidence is invalid.', $exception->getMessage());
            }
        }
    }

    /** @return array{format: string, backup_id: string, ciphertext_sha256: string, byte_size: int, source_version: string, schema_version: string, restored_at: string, checked_tables: int, checked_assets: int} */
    private function payload(): array
    {
        return ['format' => 'emailer-light-restore-drill-v1', 'backup_id' => Identity::uuidV7(), 'ciphertext_sha256' => hash('sha256', 'synthetic backup'), 'byte_size' => 1024, 'source_version' => '1.0.0', 'schema_version' => '20260828000000', 'restored_at' => Identity::now(), 'checked_tables' => 49, 'checked_assets' => 0];
    }
}
