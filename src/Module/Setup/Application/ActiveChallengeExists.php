<?php

declare(strict_types=1);

namespace App\Module\Setup\Application;

final class ActiveChallengeExists extends \RuntimeException
{
    public function __construct(public readonly string $identifier)
    {
        parent::__construct('An active setup challenge already exists.');
    }
}
