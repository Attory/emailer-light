<?php

declare(strict_types=1);

namespace App\Module\Configuration\Application\Contract;

interface MailProbe
{
    public function check(string $host, int $port, string $encryption, string $username, string $password, string $sender, ?string $destination): void;
}
