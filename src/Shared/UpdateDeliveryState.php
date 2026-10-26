<?php

declare(strict_types=1);

namespace App\Shared;

/** Root-owned update evidence is outside application checkpoints and mutable code. */
final readonly class UpdateDeliveryState
{
    private string $file;

    public function __construct(string $privateDirectory)
    {
        $this->file = \dirname($privateDirectory).'/update-maintenance.json';
    }

    /** @return array{blocked: bool, phase: string, message: string, paused_campaign_ids: list<string>} */
    public function status(): array
    {
        $empty = ['blocked' => false, 'phase' => 'complete', 'message' => '', 'paused_campaign_ids' => []];
        $unknown = ['blocked' => true, 'phase' => 'unknown', 'message' => 'Update delivery state could not be verified. Sending is blocked; start the same setup package and choose Check or Finish.', 'paused_campaign_ids' => []];
        if (is_dir(\dirname($this->file)) && !is_executable(\dirname($this->file))) {
            return $unknown; // An unreadable control directory is not proof of absence.
        }
        if (!file_exists($this->file) && !is_link($this->file)) {
            return $empty;
        }
        $info = @lstat($this->file);
        $parent = @lstat(\dirname($this->file));
        if (!\is_array($info) || !\is_array($parent) || 0o100000 !== ($info['mode'] & 0o170000) || 0 !== $info['uid'] || 0 !== ($info['mode'] & 0o022) || 1 !== $info['nlink'] || 0o040000 !== ($parent['mode'] & 0o170000) || 0 !== $parent['uid'] || 0 !== ($parent['mode'] & 0o022) || 1048576 < $info['size']) {
            return $unknown;
        }
        $bytes = @file_get_contents($this->file);
        $value = false === $bytes ? null : json_decode($bytes, true);
        if (!\is_array($value) || 'emailer-update-delivery-v1' !== ($value['format'] ?? null) || !\is_string($value['phase'] ?? null)) {
            return $unknown;
        }
        if ('complete' === $value['phase']) {
            return $empty;
        }
        $phase = $value['phase'];
        if (!\in_array($phase, ['acquiring', 'in_progress', 'resuming', 'blocked', 'failed', 'recovered'], true)) {
            return $unknown;
        }
        $ids = [];
        if (1 === ($value['prior_accepting_work'] ?? null) && \is_array($value['campaigns'] ?? null)) {
            foreach ($value['campaigns'] as $campaign) {
                if (\is_array($campaign) && \is_string($campaign['id'] ?? null) && 1 === preg_match('/^[a-f0-9-]{36}$/D', $campaign['id']) && 'sending' === ($campaign['state'] ?? null) && \is_int($campaign['remaining'] ?? null) && 0 < $campaign['remaining']) {
                    $ids[] = $campaign['id'];
                }
            }
        }
        if ('acquiring' === $phase) {
            $ids = []; // The database hold commit has not yet been confirmed.
        }
        $message = \in_array($phase, ['failed', 'blocked', 'recovered'], true)
            ? 'The update is not allowing sending. '.(\is_string($value['reason'] ?? null) ? mb_substr($value['reason'], 0, 1200) : 'Start the same setup package and choose Check or Finish.')
            : ([] === $ids ? 'Update in progress; sending temporarily unavailable.' : 'Active campaign paused during update; sending temporarily unavailable.');

        return ['blocked' => true, 'phase' => $phase, 'message' => $message, 'paused_campaign_ids' => array_values(array_unique($ids))];
    }

    public function assertSendingAllowed(): void
    {
        $status = $this->status();
        if ($status['blocked']) {
            throw new UpdateSendingBlocked($status['message']);
        }
    }

    public function assertResumeAllowed(): void
    {
        $status = $this->status();
        if ($status['blocked'] && 'resuming' !== $status['phase']) {
            throw new UpdateSendingBlocked('An update still owns the delivery hold. Start its setup package and choose Finish after repairing the reported issue.');
        }
    }
}
