<?php

declare(strict_types=1);

namespace App\Module\Delivery\Infrastructure;

use App\Module\Operations\Application\Contract\OperationalStatusProvider;
use Doctrine\DBAL\Connection;

final readonly class OperationalDeliveryStatus implements OperationalStatusProvider
{
    public function __construct(private Connection $connection)
    {
    }

    public function status(): array
    {
        $runtime = $this->connection->fetchAssociative("SELECT accepting_work, active_claims FROM delivery_runtime WHERE singleton_key = '1'");
        $queueValue = $this->connection->fetchOne("SELECT COUNT(*) FROM messenger_messages WHERE queue_name <> 'failed'");
        $failedValue = $this->connection->fetchOne("SELECT COUNT(*) FROM messenger_messages WHERE queue_name = 'failed'");
        $queue = is_numeric($queueValue) ? (int) $queueValue : 0;
        $failed = is_numeric($failedValue) ? (int) $failedValue : 0;
        $lastAccepted = $this->connection->fetchOne("SELECT MAX(finished_at) FROM delivery_attempt WHERE state = 'accepted'");
        $accepting = false !== $runtime && is_numeric($runtime['accepting_work']) && 1 === (int) $runtime['accepting_work'];
        $claims = false !== $runtime && is_numeric($runtime['active_claims']) ? (int) $runtime['active_claims'] : 0;

        return ['key' => 'delivery', 'title' => 'Delivery', 'status' => $accepting && 0 === $failed ? 'ready' : ($accepting ? 'attention' : 'quiesced'), 'metrics' => [
            ['label' => 'Queue depth', 'value' => $queue],
            ['label' => 'Failed jobs', 'value' => $failed],
            ['label' => 'Active claims', 'value' => $claims],
            ['label' => 'Last accepted send', 'value' => \is_string($lastAccepted) ? $lastAccepted : 'Never'],
        ]];
    }
}
