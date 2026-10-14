<?php

declare(strict_types=1);

namespace App\Module\Configuration\Application\Contract;

final readonly class MailInput
{
    public function __construct(
        public string $host,
        public int $port,
        public string $encryption,
        public string $username,
        #[\SensitiveParameter]
        public string $password,
        public string $senderAddress,
        public string $senderName,
        public string $replyTo,
    ) {
    }
}
