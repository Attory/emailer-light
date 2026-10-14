<?php

declare(strict_types=1);

namespace App\Module\Delivery\Application;

use App\Module\Analytics\Application\Contract\RecipientAnalyticsLinks;
use App\Module\Analytics\Application\Contract\RecipientRsvpLinks;
use App\Module\Audit\Application\Contract\AuditRecord;
use App\Module\Audit\Application\Contract\AuditSink;
use App\Module\Configuration\Application\Contract\DeliveryConfiguration;
use App\Module\Delivery\Application\Contract\CampaignDeliveryGateway;
use App\Module\Delivery\Application\Contract\DeliveryTransport;
use App\Module\Delivery\Application\Contract\DeliveryTransportFailure;
use App\Module\Delivery\Application\Contract\OutboundMessage;
use App\Module\Delivery\Application\Contract\SendingGate;
use App\Module\Delivery\Application\Message\DeliverCampaignRecipient;
use App\Module\RecipientActions\Application\Contract\RecipientUnsubscribeLinks;
use App\Module\Suppression\Application\Contract\SuppressionLookup;
use App\Shared\Identity;
use Doctrine\DBAL\Connection;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;

#[AsMessageHandler]
final readonly class DeliverCampaignRecipientHandler
{
    private const CIRCUIT_THRESHOLD = 5;
    private const CIRCUIT_OPEN_SECONDS = 300;

    public function __construct(
        private Connection $connection,
        private DeliveryConfiguration $configuration,
        private SuppressionLookup $suppressions,
        private DeliveryTransport $transport,
        private CampaignDeliveryGateway $gateway,
        private RecipientUnsubscribeLinks $unsubscribeLinks,
        private AuditSink $audit,
        private SendingGate $sendingGate,
        private ?RecipientAnalyticsLinks $analyticsLinks = null,
        private ?RecipientRsvpLinks $rsvpLinks = null,
    ) {
    }

    public function __invoke(DeliverCampaignRecipient $message): void
    {
        try {
            $this->sendingGate->assertReady();
        } catch (\DomainException) {
            $this->gateway->dispatchAfter($message->recipientId, $message->operationVersion, 30_000);

            return;
        }
        $configuration = $this->configuration->current();
        $claim = $this->claim($message, $configuration);
        if (null === $claim) {
            return;
        }
        if (null !== $claim->delayMilliseconds) {
            $this->gateway->dispatchAfter($message->recipientId, $message->operationVersion, $claim->delayMilliseconds);

            return;
        }
        if (null === $claim->recipientId || null === $claim->email || null === $claim->profileJson || null === $claim->attemptId || null === $claim->attemptNumber) {
            throw new \LogicException('Delivery claim is incomplete.');
        }
        $transportInvoked = false;
        try {
            // This second lookup intentionally occurs after the durable claim and immediately before transport I/O.
            if ($this->suppressions->isSuppressed($claim->email)) {
                $this->finishSuppressed($message->recipientId, $claim->attemptId);

                return;
            }
            $outbound = $this->outbound($claim->recipientId, $claim->email, $claim->profileJson);
            $transportInvoked = true;
            $reference = $this->transport->send($outbound);
            $this->finishAccepted($message->recipientId, $claim->attemptId, $reference);
        } catch (DeliveryTransportFailure $failure) {
            $retry = $this->finishFailure($message->recipientId, $claim->attemptId, $claim->attemptNumber, $message->operationVersion, $failure->classification, $configuration['retry_limit'], $failure->affectsCircuit);
            if (null !== $retry) {
                $this->gateway->dispatchAfter($message->recipientId, $message->operationVersion, $retry);

                return;
            }
            throw new UnrecoverableMessageHandlingException('Delivery reached a terminal failure classification.');
        } catch (\Throwable) {
            $classification = $transportInvoked ? 'ambiguous' : 'permanent';
            $this->finishFailure($message->recipientId, $claim->attemptId, $claim->attemptNumber, $message->operationVersion, $classification, $configuration['retry_limit'], $transportInvoked);
            throw new UnrecoverableMessageHandlingException($transportInvoked ? 'Delivery outcome is ambiguous.' : 'Delivery content could not be prepared.');
        }
    }

    /** @param array{installation_id: string, host: string, port: int, encryption: string, username: ?string, credential_id: ?string, verify_peer: bool, sender_address: string, sender_name: string, reply_to: ?string, one_click_dkim_confirmed: bool, unsubscribe_footer: string, open_tracking: bool, click_tracking: bool, messages_per_minute: int, concurrency: int, retry_limit: int, max_campaign_size: int} $configuration */
    private function claim(DeliverCampaignRecipient $message, array $configuration): ?DeliveryClaim
    {
        return $this->connection->transactional(function () use ($message, $configuration): ?DeliveryClaim {
            $row = $this->connection->fetchAssociative("SELECT r.state, r.attempt_count, r.operation_version, c.id campaign_id, c.messages_per_minute, c.rate_window_started_at campaign_window_started_at, c.rate_window_count campaign_window_count, a.email_canonical, a.profile_json FROM campaign_recipient r INNER JOIN campaign c ON c.id = r.campaign_id INNER JOIN campaign_audience_recipient a ON a.campaign_id = r.campaign_id AND a.recipient_id = r.recipient_id WHERE r.id = ? AND c.state = 'sending' FOR UPDATE", [$message->recipientId]);
            if (false === $row || $message->operationVersion !== $this->intValue($row['operation_version']) || !\in_array($row['state'], ['pending', 'retry'], true) || !\is_string($row['email_canonical']) || !\is_string($row['profile_json'])) {
                return null;
            }
            if ($this->suppressions->isSuppressed($row['email_canonical'])) {
                $this->connection->update('campaign_recipient', ['state' => 'suppressed', 'terminal_at' => Identity::now(), 'updated_at' => Identity::now()], ['id' => $message->recipientId]);
                $this->completeCampaignIfTerminal($message->recipientId);

                return null;
            }
            $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
            $campaignWindow = \is_string($row['campaign_window_started_at']) ? new \DateTimeImmutable($row['campaign_window_started_at'], new \DateTimeZone('UTC')) : $now;
            $campaignCount = $this->intValue($row['campaign_window_count']);
            if ($campaignWindow <= $now->modify('-60 seconds')) {
                $campaignWindow = $now;
                $campaignCount = 0;
            }
            // Exhausted campaign budgets never contend on the installation-wide locks.
            if (null !== $row['messages_per_minute'] && $campaignCount >= $this->intValue($row['messages_per_minute'])) {
                $jitter = (int) hexdec(substr(hash('sha256', $message->recipientId), 0, 4)) % 5000;

                return $this->defer($message, max(1_000, ($campaignWindow->getTimestamp() + 60 - $now->getTimestamp()) * 1000) + $jitter);
            }
            $runtime = $this->connection->fetchAssociative("SELECT * FROM delivery_runtime WHERE singleton_key = '1' FOR UPDATE");
            if (false === $runtime) {
                throw new \RuntimeException('Delivery runtime state is unavailable.');
            }
            $policy = $this->connection->fetchAssociative("SELECT messages_per_minute, worker_concurrency FROM delivery_policy WHERE singleton_key = '1' FOR UPDATE");
            if (false === $policy) {
                throw new \RuntimeException('Delivery policy is unavailable.');
            }
            $globalRate = $this->intValue($policy['messages_per_minute']);
            if (0 === $this->intValue($runtime['accepting_work'])) {
                return $this->defer($message, 30_000);
            }
            if ('open' === $runtime['circuit_state'] && \is_string($runtime['circuit_open_until']) && new \DateTimeImmutable($runtime['circuit_open_until'], new \DateTimeZone('UTC')) > $now) {
                return $this->defer($message, 30_000);
            }
            if ('half_open' === $runtime['circuit_state']) {
                return $this->defer($message, 30_000);
            }
            if ($this->intValue($runtime['active_claims']) >= $this->intValue($policy['worker_concurrency'])) {
                return $this->defer($message, 1_000);
            }
            $window = new \DateTimeImmutable($this->stringValue($runtime['rate_window_started_at']), new \DateTimeZone('UTC'));
            $rateCount = $this->intValue($runtime['rate_window_count']);
            if ($window <= $now->modify('-60 seconds')) {
                $window = $now;
                $rateCount = 0;
            }
            if ($rateCount >= $globalRate) {
                return $this->defer($message, max(1_000, ($window->getTimestamp() + 60 - $now->getTimestamp()) * 1000));
            }
            $campaignRate = null === $row['messages_per_minute'] ? $globalRate : min($globalRate, $this->intValue($row['messages_per_minute']));
            if ($campaignCount >= $campaignRate) {
                return $this->defer($message, max(1_000, ($campaignWindow->getTimestamp() + 60 - $now->getTimestamp()) * 1000));
            }
            $atPosition = strrpos($row['email_canonical'], '@');
            $domain = false === $atPosition ? '' : substr($row['email_canonical'], $atPosition + 1);
            $domainHash = hash_hmac('sha256', $domain, $configuration['installation_id']);
            $this->connection->executeStatement('INSERT IGNORE INTO delivery_domain_rate (domain_hash, window_started_at, message_count, updated_at) VALUES (?, ?, 0, ?)', [$domainHash, Identity::now(), Identity::now()]);
            $domainRate = $this->connection->fetchAssociative('SELECT window_started_at, message_count FROM delivery_domain_rate WHERE domain_hash = ? FOR UPDATE', [$domainHash]);
            if (false === $domainRate) {
                throw new \RuntimeException('Domain rate state is unavailable.');
            }
            $domainWindow = new \DateTimeImmutable($this->stringValue($domainRate['window_started_at']), new \DateTimeZone('UTC'));
            $domainCount = $this->intValue($domainRate['message_count']);
            if ($domainWindow <= $now->modify('-60 seconds')) {
                $domainWindow = $now;
                $domainCount = 0;
            }
            $domainLimit = max(1, (int) ceil($globalRate / 2));
            if ($domainCount >= $domainLimit) {
                return $this->defer($message, max(1_000, ($domainWindow->getTimestamp() + 60 - $now->getTimestamp()) * 1000));
            }
            $attemptNumber = 1 + $this->intValue($row['attempt_count']);
            $attemptId = Identity::uuidV7();
            $openingProbe = 'open' === $runtime['circuit_state'];
            $this->connection->update('campaign', ['rate_window_started_at' => $campaignWindow->format('Y-m-d H:i:s.u'), 'rate_window_count' => 1 + $campaignCount], ['id' => $row['campaign_id']]);
            $this->connection->update('delivery_runtime', ['active_claims' => 1 + $this->intValue($runtime['active_claims']), 'rate_window_started_at' => $window->format('Y-m-d H:i:s.u'), 'rate_window_count' => 1 + $rateCount, 'circuit_state' => $openingProbe ? 'half_open' : $this->stringValue($runtime['circuit_state']), 'circuit_open_until' => $openingProbe ? $now->modify('+10 minutes')->format('Y-m-d H:i:s.u') : $runtime['circuit_open_until'], 'updated_at' => Identity::now()], ['singleton_key' => '1']);
            $this->connection->update('delivery_domain_rate', ['window_started_at' => $domainWindow->format('Y-m-d H:i:s.u'), 'message_count' => 1 + $domainCount, 'updated_at' => Identity::now()], ['domain_hash' => $domainHash]);
            $this->connection->update('campaign_recipient', ['state' => 'processing', 'attempt_count' => $attemptNumber, 'claimed_at' => Identity::now(), 'updated_at' => Identity::now()], ['id' => $message->recipientId]);
            $this->connection->insert('delivery_attempt', ['id' => $attemptId, 'campaign_recipient_id' => $message->recipientId, 'attempt_number' => $attemptNumber, 'state' => 'started', 'failure_class' => null, 'provider_reference_hash' => null, 'started_at' => Identity::now(), 'finished_at' => null]);

            return DeliveryClaim::attempt($message->recipientId, $row['email_canonical'], $row['profile_json'], $attemptId, $attemptNumber);
        });
    }

    private function defer(DeliverCampaignRecipient $message, int $milliseconds): DeliveryClaim
    {
        // The scheduler must not enqueue another job while this delayed job is in transit.
        $at = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->modify('+'.$milliseconds.' milliseconds');
        $this->connection->update('campaign_recipient', ['next_attempt_at' => $at->format('Y-m-d H:i:s.u'), 'updated_at' => Identity::now()], ['id' => $message->recipientId]);

        return DeliveryClaim::delayed($milliseconds);
    }

    private function outbound(string $recipientId, string $email, string $profileJson): OutboundMessage
    {
        $row = $this->connection->fetchAssociative("SELECT c.id campaign_id, c.name, s.subject, s.html_body, s.text_body, s.unsubscribe_footer, s.one_click_dkim_confirmed, s.sender_address, s.sender_name, s.reply_to_address, s.link_manifest_json, s.open_tracking, s.click_tracking, s.rsvp_enabled, r.recipient_id, r.message_id FROM campaign_recipient r INNER JOIN campaign c ON c.id = r.campaign_id INNER JOIN campaign_content_snapshot s ON s.campaign_id = c.id WHERE r.id = ? AND r.state = 'processing'", [$recipientId]);
        if (false === $row) {
            throw new \RuntimeException('Claimed campaign content is unavailable.');
        }
        $profile = json_decode($profileJson, true, 32, JSON_THROW_ON_ERROR);
        if (!\is_array($profile)) {
            throw new \UnexpectedValueException('Campaign recipient profile is invalid.');
        }
        $firstName = $profile['first_name'] ?? '';
        $displayName = $profile['display_name'] ?? '';
        $unsubscribeUrl = $this->unsubscribeLinks->issue($this->stringValue($row['campaign_id']), $recipientId, $this->stringValue($row['recipient_id']), $email);
        $links = null === $this->analyticsLinks ? ['view' => '', 'open' => null, 'click' => null] : $this->analyticsLinks->issue($this->stringValue($row['campaign_id']), $recipientId, $this->stringValue($row['recipient_id']), $email, 1 === $this->intValue($row['open_tracking']), 1 === $this->intValue($row['click_tracking']));
        $rsvpUrl = '';
        if (1 === $this->intValue($row['rsvp_enabled'])) {
            if (null === $this->rsvpLinks) {
                throw new \RuntimeException('RSVP link service is unavailable.');
            }
            $rsvpUrl = $this->rsvpLinks->issueRsvp($this->stringValue($row['campaign_id']), $recipientId, $this->stringValue($row['recipient_id']), $email);
        }
        $values = ['recipient.first_name' => \is_string($firstName) ? $firstName : '', 'recipient.display_name' => \is_string($displayName) ? $displayName : '', 'campaign.name' => $this->stringValue($row['name']), 'unsubscribe_url' => $unsubscribeUrl, 'view_in_browser_url' => $links['view'], 'rsvp_url' => $rsvpUrl];
        $subject = $this->render($this->stringValue($row['subject']), $values, false);
        $html = $this->render($this->stringValue($row['html_body']), $values, true);
        $text = $this->render($this->stringValue($row['text_body']), $values, false);
        if (null !== $links['click']) {
            $manifest = json_decode($this->stringValue($row['link_manifest_json']), true, 32, JSON_THROW_ON_ERROR);
            if (!\is_array($manifest)) {
                throw new \UnexpectedValueException('Campaign link manifest is invalid.');
            }
            foreach ($manifest as $index => $target) {
                if (!\is_int($index) || !\is_string($target) || 1 !== preg_match('#^https://[^\s]+$#D', $target)) {
                    continue;
                }
                $escaped = htmlspecialchars($target, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
                $tracked = htmlspecialchars($links['click'].'/'.$index, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
                $html = str_replace(['href="'.$escaped.'"', "href='".$escaped."'"], ['href="'.$tracked.'"', "href='".$tracked."'"], $html);
            }
        }
        if (null !== $links['open']) {
            $html .= '<img src="'.htmlspecialchars($links['open'], ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8').'" width="1" height="1" alt="">';
        }
        $footer = $this->stringValue($row['unsubscribe_footer']);
        if (1 !== preg_match('/\{\{\s*unsubscribe_url\s*\}\}/', $this->stringValue($row['html_body']))) {
            $html .= '<p>'.htmlspecialchars($footer, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8').' <a href="'.htmlspecialchars($unsubscribeUrl, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8').'">Unsubscribe</a></p>';
        }
        $text .= "\n\n".$footer."\nUnsubscribe: ".$unsubscribeUrl;

        return new OutboundMessage($email, $this->stringValue($row['sender_address']), $this->stringValue($row['sender_name']), \is_string($row['reply_to_address']) ? $row['reply_to_address'] : null, $subject, $html, $text, $this->stringValue($row['message_id']), $unsubscribeUrl, 1 === $this->intValue($row['one_click_dkim_confirmed']));
    }

    /** @param array<string, string> $values */
    private function render(string $body, array $values, bool $html): string
    {
        foreach ($values as $field => $value) {
            $replacement = $html ? htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8') : $value;
            $body = preg_replace_callback('/\{\{\s*'.preg_quote($field, '/').'\s*\}\}/', static fn (array $_match): string => $replacement, $body) ?? $body;
        }

        return $body;
    }

    private function finishAccepted(string $recipientId, string $attemptId, string $reference): void
    {
        $this->connection->transactional(function () use ($recipientId, $attemptId, $reference): void {
            $this->connection->update('delivery_attempt', ['state' => 'accepted', 'provider_reference_hash' => hash('sha256', $reference), 'finished_at' => Identity::now()], ['id' => $attemptId, 'state' => 'started']);
            if (1 !== $this->connection->update('campaign_recipient', ['state' => 'accepted', 'accepted_at' => Identity::now(), 'terminal_at' => Identity::now(), 'claimed_at' => null, 'updated_at' => Identity::now()], ['id' => $recipientId, 'state' => 'processing'])) {
                return;
            }
            $this->releaseRuntime(true);
            $this->completeCampaignIfTerminal($recipientId);
        });
    }

    private function finishSuppressed(string $recipientId, string $attemptId): void
    {
        $this->connection->transactional(function () use ($recipientId, $attemptId): void {
            $this->connection->delete('delivery_attempt', ['id' => $attemptId, 'state' => 'started']);
            if (1 !== $this->connection->update('campaign_recipient', ['state' => 'suppressed', 'terminal_at' => Identity::now(), 'claimed_at' => null, 'updated_at' => Identity::now()], ['id' => $recipientId, 'state' => 'processing'])) {
                return;
            }
            $this->releaseRuntime(true);
            $this->completeCampaignIfTerminal($recipientId);
        });
    }

    private function finishFailure(string $recipientId, string $attemptId, int $attemptNumber, int $operationVersion, string $classification, int $retryLimit, bool $affectsCircuit): ?int
    {
        return $this->connection->transactional(function () use ($recipientId, $attemptId, $attemptNumber, $operationVersion, $classification, $retryLimit, $affectsCircuit): ?int {
            $retry = 'transient' === $classification && $attemptNumber < $retryLimit;
            $delay = $retry ? min(3_600_000, (2 ** min(10, $attemptNumber - 1)) * 60_000 + $this->jitter($recipientId, $attemptNumber)) : null;
            $state = $retry ? 'retry' : ('permanent' === $classification ? 'permanent_failure' : 'ambiguous');
            $next = null === $delay ? null : (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->modify('+'.$delay.' milliseconds')->format('Y-m-d H:i:s.u');
            $this->connection->update('delivery_attempt', ['state' => $classification, 'failure_class' => $classification, 'finished_at' => Identity::now()], ['id' => $attemptId, 'state' => 'started']);
            if (1 !== $this->connection->update('campaign_recipient', ['state' => $state, 'next_attempt_at' => $next, 'claimed_at' => null, 'terminal_at' => $retry ? null : Identity::now(), 'last_failure_class' => $classification, 'operation_version' => $operationVersion, 'updated_at' => Identity::now()], ['id' => $recipientId, 'state' => 'processing'])) {
                return null;
            }
            $this->releaseRuntime(false, $affectsCircuit);
            if (!$retry) {
                $this->completeCampaignIfTerminal($recipientId);
            }

            return $delay;
        });
    }

    private function releaseRuntime(bool $success, bool $affectsCircuit = false): void
    {
        $runtime = $this->connection->fetchAssociative("SELECT active_claims, circuit_failures FROM delivery_runtime WHERE singleton_key = '1' FOR UPDATE");
        if (false === $runtime) {
            throw new \RuntimeException('Delivery runtime state is unavailable.');
        }
        $failures = $success || !$affectsCircuit ? 0 : 1 + $this->intValue($runtime['circuit_failures']);
        $open = !$success && $affectsCircuit && self::CIRCUIT_THRESHOLD <= $failures;
        $this->connection->update('delivery_runtime', ['active_claims' => max(0, $this->intValue($runtime['active_claims']) - 1), 'circuit_failures' => $failures, 'circuit_state' => $open ? 'open' : 'closed', 'circuit_open_until' => $open ? (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->modify('+'.self::CIRCUIT_OPEN_SECONDS.' seconds')->format('Y-m-d H:i:s.u') : null, 'updated_at' => Identity::now()], ['singleton_key' => '1']);
    }

    private function completeCampaignIfTerminal(string $recipientId): void
    {
        $campaignId = $this->connection->fetchOne('SELECT campaign_id FROM campaign_recipient WHERE id = ?', [$recipientId]);
        if (!\is_string($campaignId)) {
            return;
        }
        if (false === $this->connection->fetchOne("SELECT 1 FROM campaign_recipient WHERE campaign_id = ? AND state IN ('pending', 'processing', 'retry')", [$campaignId])) {
            if (1 === $this->connection->executeStatement("UPDATE campaign SET state = 'completed', updated_at = ? WHERE id = ? AND state = 'sending'", [Identity::now(), $campaignId])) {
                $this->audit->append(new AuditRecord(null, 'campaign.delivery.completed', $campaignId, 'success'));
            }
        }
    }

    private function jitter(string $id, int $attempt): int
    {
        return hexdec(substr(hash('sha256', $id.':'.$attempt), 0, 4)) % 30_001;
    }

    private function intValue(mixed $value): int
    {
        if (!is_numeric($value)) {
            throw new \UnexpectedValueException('Delivery numeric state is invalid.');
        }

        return (int) $value;
    }

    private function stringValue(mixed $value): string
    {
        if (!\is_string($value)) {
            throw new \UnexpectedValueException('Delivery string state is invalid.');
        }

        return $value;
    }
}
