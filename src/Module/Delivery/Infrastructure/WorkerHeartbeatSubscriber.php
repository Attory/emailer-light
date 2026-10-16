<?php

declare(strict_types=1);

namespace App\Module\Delivery\Infrastructure;

use App\Shared\Identity;
use Doctrine\DBAL\Connection;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Messenger\Event\WorkerRunningEvent;
use Symfony\Component\Messenger\Event\WorkerStartedEvent;
use Symfony\Component\Messenger\Event\WorkerStoppedEvent;

final class WorkerHeartbeatSubscriber implements EventSubscriberInterface
{
    private readonly string $workerId;
    private int $lastHeartbeat = 0;

    public function __construct(private readonly Connection $connection)
    {
        $hostname = gethostname();
        $this->workerId = substr((false === $hostname ? 'host' : $hostname).'-'.getmypid(), 0, 100);
    }

    public static function getSubscribedEvents(): array
    {
        return [WorkerStartedEvent::class => 'started', WorkerRunningEvent::class => 'running', WorkerStoppedEvent::class => 'stopped'];
    }

    public function started(): void
    {
        $now = Identity::now();
        $this->connection->executeStatement("INSERT INTO worker_heartbeat (worker_id, worker_kind, schema_version, started_at, heartbeat_at, stopped_at) VALUES (?, 'worker', 1, ?, ?, NULL) ON DUPLICATE KEY UPDATE worker_kind = 'worker', schema_version = 1, started_at = VALUES(started_at), heartbeat_at = VALUES(heartbeat_at), stopped_at = NULL", [$this->workerId, $now, $now]);
        $this->lastHeartbeat = time();
    }

    public function running(): void
    {
        if ($this->lastHeartbeat + 15 > time()) {
            return;
        }
        $this->connection->update('worker_heartbeat', ['heartbeat_at' => Identity::now()], ['worker_id' => $this->workerId]);
        $this->lastHeartbeat = time();
    }

    public function stopped(): void
    {
        $this->connection->update('worker_heartbeat', ['heartbeat_at' => Identity::now(), 'stopped_at' => Identity::now()], ['worker_id' => $this->workerId]);
    }
}
