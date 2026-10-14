<?php

declare(strict_types=1);

namespace App\Module\Templates\Application\Contract;

interface TemplateAssetStorage
{
    /** @return array{filename: string, media_type: string, byte_size: int, width: int, height: int, content_sha256: string, url: string} */
    public function store(string $sourcePath): array;

    public function delete(string $filename): void;

    public function publicPath(string $filename): string;
}
