<?php

declare(strict_types=1);

namespace App\Module\Delivery\Application;

use App\Module\Configuration\Application\Contract\DeliveryConfiguration;
use App\Module\Delivery\Application\Contract\CampaignDeliveryGateway;
use App\Module\Delivery\Application\Contract\DeliveryTransport;
use App\Module\Delivery\Application\Contract\DeliveryTransportFailure;
use App\Module\Delivery\Application\Contract\OutboundMessage;
use App\Module\Delivery\Application\Contract\SendingGate;
use App\Module\Delivery\Application\Message\DeliverCampaignTest;
use App\Module\Suppression\Application\Contract\SuppressionLookup;
use App\Module\Templates\Application\Contract\PublishedTemplateProvider;
use App\Shared\Identity;
use Doctrine\DBAL\Connection;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;

#[AsMessageHandler]
final readonly class DeliverCampaignTestHandler
{
    public function __construct(private Connection $connection, private PublishedTemplateProvider $templates, private DeliveryConfiguration $configuration, private DeliveryTransport $transport, private SuppressionLookup $suppressions, private SendingGate $sendingGate, private CampaignDeliveryGateway $gateway, private ?Contract\DeliveryControl $control = null)
    {
    }

    public function __invoke(DeliverCampaignTest $message): void
    {
        try {
            $this->sendingGate->assertReady();
        } catch (\DomainException) {
            $this->gateway->dispatchTestAfter($message->testDeliveryId, 30_000);

            return;
        }
        try {
            $lease = $this->control?->claimLease();
        } catch (\DomainException) {
            $this->gateway->dispatchTestAfter($message->testDeliveryId, 30_000);

            return;
        }
        try {
            $row = $this->connection->transactional(function () use ($message): array|false {
                $row = $this->connection->fetchAssociative('SELECT t.state, t.recipient_address, t.draft_version, c.operation_version, c.state campaign_state, c.id campaign_id, c.name, c.template_version_id, c.rsvp_enabled FROM campaign_test_delivery t INNER JOIN campaign c ON c.id = t.campaign_id WHERE t.id = ? FOR UPDATE', [$message->testDeliveryId]);
                if (false === $row || 'pending' !== $row['state']) {
                    return false;
                }
                if (!is_numeric($row['draft_version']) || !is_numeric($row['operation_version']) || (int) $row['draft_version'] !== (int) $row['operation_version'] || !\in_array($row['campaign_state'], ['draft', 'review'], true)) {
                    $this->connection->update('campaign_test_delivery', ['state' => 'failed', 'failure_class' => 'superseded'], ['id' => $message->testDeliveryId]);

                    return false;
                }
                $runtime = $this->connection->fetchAssociative("SELECT schema_version, accepting_work FROM delivery_runtime WHERE singleton_key = '1' FOR UPDATE");
                if (false === $runtime || !is_numeric($runtime['schema_version']) || !is_numeric($runtime['accepting_work']) || 1 !== (int) $runtime['schema_version'] || 1 !== (int) $runtime['accepting_work']) {
                    $this->gateway->dispatchTestAfter($message->testDeliveryId, 30_000);

                    return false;
                }
                $this->connection->update('campaign_test_delivery', ['state' => 'processing'], ['id' => $message->testDeliveryId]);

                return $row;
            });
        } finally {
            $lease?->release();
        }
        if (false === $row || !\is_string($row['template_version_id']) || !\is_string($row['recipient_address']) || !\is_string($row['campaign_id']) || !\is_string($row['name'])) {
            return;
        }
        $transportInvoked = false;
        try {
            if ($this->suppressions->isSuppressed($row['recipient_address'])) {
                throw new DeliveryTransportFailure('permanent', 'Campaign test recipient is suppressed.');
            }
            $template = $this->templates->get($row['template_version_id']);
            $configuration = $this->configuration->current();
            if (!is_numeric($row['rsvp_enabled'])) {
                throw new \UnexpectedValueException('Campaign RSVP state is invalid.');
            }
            $values = ['recipient.first_name' => 'Test', 'recipient.display_name' => 'Test Recipient', 'campaign.name' => $row['name'], 'unsubscribe_url' => 'https://example.test/r/test-only/unsubscribe', 'view_in_browser_url' => 'https://example.test/r/test-only/view', 'rsvp_url' => 1 === (int) $row['rsvp_enabled'] ? 'https://example.test/r/test-only/rsvp' : ''];
            $render = static function (string $content, bool $html) use ($values): string {
                foreach ($values as $field => $value) {
                    $content = preg_replace('/\{\{\s*'.preg_quote($field, '/').'\s*\}\}/', $html ? htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8') : $value, $content) ?? $content;
                }

                return $content;
            };
            $outbound = new OutboundMessage($row['recipient_address'], $configuration['sender_address'], $configuration['sender_name'], $configuration['reply_to'], $render($template['subject'], false), $render($template['html'], true), $render($template['text'], false), hash_hmac('sha256', 'test\0'.$message->testDeliveryId, $configuration['installation_id']).'@emailer-light.invalid');
            $transportInvoked = true;
            $this->transport->send($outbound);
            $this->connection->update('campaign_test_delivery', ['state' => 'accepted', 'accepted_at' => Identity::now()], ['id' => $message->testDeliveryId, 'state' => 'processing']);
        } catch (DeliveryTransportFailure $failure) {
            $this->connection->update('campaign_test_delivery', ['state' => 'failed', 'failure_class' => $failure->classification], ['id' => $message->testDeliveryId, 'state' => 'processing']);
            throw new UnrecoverableMessageHandlingException('Campaign test delivery failed.');
        } catch (\Throwable) {
            $classification = $transportInvoked ? 'ambiguous' : 'permanent';
            $this->connection->update('campaign_test_delivery', ['state' => 'failed', 'failure_class' => $classification], ['id' => $message->testDeliveryId, 'state' => 'processing']);
            throw new UnrecoverableMessageHandlingException($transportInvoked ? 'Campaign test delivery outcome is ambiguous.' : 'Campaign test could not be prepared.');
        }
    }
}
