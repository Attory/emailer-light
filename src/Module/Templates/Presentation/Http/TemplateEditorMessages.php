<?php

declare(strict_types=1);

namespace App\Module\Templates\Presentation\Http;

use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Symfony\Component\HttpFoundation\Response;

/** Fixed presentation messages: never return database or exception detail. */
final class TemplateEditorMessages
{
    public static function save(\Throwable $failure): string
    {
        if ($failure instanceof \App\Module\Templates\Application\TemplateMergeException) {
            return $failure->getMessage();
        }
        if ($failure instanceof UniqueConstraintViolationException) {
            return 'That template name is already in use, including archived templates. Choose a different name. Your content is still here.';
        }
        if (!$failure instanceof \InvalidArgumentException) {
            return 'The save could not be confirmed. Your content is still here. Check Templates in another tab before retrying, in case the first request was saved.';
        }

        return match ($failure->getMessage()) {
            'Template name is invalid.' => 'Enter a template name of up to 160 characters without control characters.',
            'Template header text is invalid.' => 'Check Subject and Preheader: use up to 200 characters each, without line breaks or unsupported characters.',
            'Unknown merge field.', 'Merge fields are invalid.', 'Merge syntax is invalid.' => 'A merge field is not supported or is incomplete. Check its spelling and braces against the supported fields. Your original HTML is still here.',
            'URL merge fields are only allowed as complete link targets.', 'Merge fields are not allowed in this HTML attribute context.' => 'Use merge fields only in supported places. A URL field must be the whole link address, not part of another HTML attribute.',
            'Template image source is invalid.' => 'Use an image from the saved-image gallery. Insert image creates the supported image reference automatically.',
            'Template link is invalid.', 'Template link host is invalid.' => 'Check the link addresses. Use supported HTTPS or email links without credentials or incomplete merge fields.',
            'Template HTML is invalid.' => 'Check the HTML size and content. Scripts, forms, embedded pages and unsupported markup cannot be saved. Your original HTML is still here.',
            'Template text is invalid.' => 'Check the plain text size and remove unsupported control characters. Your original text is still here.',
            'Template is unavailable.', 'Template version is unavailable.' => 'This template is no longer available for editing. Your content is still here; keep a copy before choosing another template.',
            default => 'Check the template name and supported content. Your entries are still here so you can correct them and try again.',
        };
    }

    public static function upload(): string
    {
        return 'Choose a JPEG, PNG or WebP image up to 2 MB and 2400 pixels on each side. The image must be readable and will be re-encoded. Your template entries are unchanged.';
    }

    public static function status(\Throwable $failure): int
    {
        return $failure instanceof \InvalidArgumentException || $failure instanceof UniqueConstraintViolationException
            ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_SERVICE_UNAVAILABLE;
    }
}
