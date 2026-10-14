<?php

declare(strict_types=1);

namespace App\Module\Setup\Application\Contract;

use App\Module\Setup\Domain\ChallengeState;

interface ChallengeStore
{
    public function load(): ?ChallengeState;

    public function save(ChallengeState $state): void;

    public function delete(): void;
}
