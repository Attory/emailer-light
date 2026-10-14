<?php

declare(strict_types=1);

namespace App\Module\Setup\Application\Contract;

interface SetupLifecycle
{
    /**
     * @template T
     *
     * @param \Closure(): T $operation
     *
     * @return T
     */
    public function mutate(\Closure $operation): mixed;

    /**
     * @template T
     *
     * @param \Closure(): T $operation
     *
     * @return T
     */
    public function finalize(\Closure $operation): mixed;
}
