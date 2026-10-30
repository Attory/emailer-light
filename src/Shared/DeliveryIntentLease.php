<?php

declare(strict_types=1);

namespace App\Shared;

final class DeliveryIntentLease
{
    /** @param resource|null $stream */
    public function __construct(private mixed $stream = null)
    {
    }

    public function release(): void
    {
        if (\is_resource($this->stream)) {
            flock($this->stream, LOCK_UN);
            fclose($this->stream);
            $this->stream = null;
        }
    }

    public function __destruct()
    {
        $this->release();
    }
}
