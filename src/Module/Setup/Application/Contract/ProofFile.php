<?php

declare(strict_types=1);

namespace App\Module\Setup\Application\Contract;

interface ProofFile
{
    public function readNormalizedCode(): ?string;

    public function delete(): void;
}
