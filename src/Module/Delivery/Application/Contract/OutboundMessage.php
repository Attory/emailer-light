<?php

declare(strict_types=1);

namespace App\Module\Delivery\Application\Contract;

final readonly class OutboundMessage
{
    public function __construct(
        public string $recipient,
        public string $senderAddress,
        public string $senderName,
        public ?string $replyTo,
        public string $subject,
        public string $html,
        public string $text,
        public string $messageId,
        public ?string $unsubscribeUrl = null,
        public bool $oneClickUnsubscribe = false,
    ) {
    }
}
