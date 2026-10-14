<?php

declare(strict_types=1);

namespace App\Module\Templates\Infrastructure;

use App\Module\Templates\Application\Contract\PublishedTemplateProvider;
use Doctrine\DBAL\Connection;

final readonly class DoctrinePublishedTemplateProvider implements PublishedTemplateProvider
{
    public function __construct(private Connection $connection)
    {
    }

    public function get(string $versionId): array
    {
        if (1 !== preg_match('/^[0-9a-f-]{36}$/', $versionId)) {
            throw new \InvalidArgumentException('Template version is invalid.');
        }
        $row = $this->connection->fetchAssociative('SELECT v.id, v.subject, v.preheader, v.html_body, v.text_body, v.merge_fields_json, v.link_manifest_json, v.content_sha256 FROM template_version v INNER JOIN email_template t ON t.id = v.template_id WHERE v.id = ? AND v.published_at IS NOT NULL AND t.status = ?', [$versionId, 'published']);
        if (false === $row || !\is_string($row['id']) || !\is_string($row['subject']) || !\is_string($row['html_body']) || !\is_string($row['text_body']) || !\is_string($row['merge_fields_json']) || !\is_string($row['content_sha256'])) {
            throw new \InvalidArgumentException('Published template version is unavailable.');
        }
        $fields = json_decode($row['merge_fields_json'], true, 32, JSON_THROW_ON_ERROR);
        if (!\is_array($fields) || [] !== array_filter($fields, static fn (mixed $field): bool => !\is_string($field))) {
            throw new \UnexpectedValueException('Template merge-field manifest is invalid.');
        }
        $fieldList = [];
        foreach ($fields as $field) {
            if (!\is_string($field)) {
                throw new \UnexpectedValueException('Template merge-field manifest is invalid.');
            }
            $fieldList[] = $field;
        }

        $links = json_decode($this->stringValue($row['link_manifest_json']), true, 32, JSON_THROW_ON_ERROR);
        if (!\is_array($links) || [] !== array_filter($links, static fn (mixed $link): bool => !\is_string($link))) {
            throw new \UnexpectedValueException('Template link manifest is invalid.');
        }

        $linkList = [];
        foreach ($links as $link) {
            if (!\is_string($link)) {
                throw new \UnexpectedValueException('Template link manifest is invalid.');
            }
            $linkList[] = $link;
        }

        return ['id' => $row['id'], 'subject' => $row['subject'], 'preheader' => \is_string($row['preheader']) ? $row['preheader'] : null, 'html' => $row['html_body'], 'text' => $row['text_body'], 'merge_fields' => $fieldList, 'links' => $linkList, 'content_sha256' => $row['content_sha256']];
    }

    private function stringValue(mixed $value): string
    {
        if (!\is_string($value)) {
            throw new \UnexpectedValueException('Template value is invalid.');
        }

        return $value;
    }
}
