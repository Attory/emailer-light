<?php

declare(strict_types=1);

namespace App\Module\Templates\Application\Contract;

interface PublishedTemplateProvider
{
    /** @return array{id: string, subject: string, preheader: ?string, html: string, text: string, merge_fields: list<string>, links: list<string>, content_sha256: string} */
    public function get(string $versionId): array;
}
