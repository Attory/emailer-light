<?php

declare(strict_types=1);

namespace App\Module\Delivery\Application\Contract;

final class DeliveryTransportFailure extends \RuntimeException
{
    public readonly bool $affectsCircuit;

    public function __construct(public readonly string $classification, string $safeMessage = 'Delivery transport failed.', ?\Throwable $previous = null, ?bool $affectsCircuit = null)
    {
        if (!\in_array($classification, ['transient', 'permanent', 'ambiguous'], true)) {
            throw new \InvalidArgumentException('Delivery failure classification is invalid.');
        }
        $this->affectsCircuit = $affectsCircuit ?? 'permanent' !== $classification;
        parent::__construct($safeMessage, 0, $previous);
    }
}
