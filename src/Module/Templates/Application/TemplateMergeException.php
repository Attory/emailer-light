<?php

declare(strict_types=1);

namespace App\Module\Templates\Application;

/** Safe editor diagnostic, never logged or used as executable markup. */
final class TemplateMergeException extends \InvalidArgumentException
{
    public function __construct(string $placeholder, string $input, string $content, int $offset, string $correction)
    {
        $line = 1 + substr_count(substr($content, 0, $offset), "\n");
        $shown = mb_substr(preg_replace('/[\x00-\x1F\x7F]/u', ' ', $placeholder) ?? '', 0, 180);
        if (mb_strlen($placeholder) > 180) {
            $shown .= '…';
        }
        parent::__construct($input.' · line '.$line.': unsupported or misplaced placeholder "'.$shown.'". '.$correction.' Your entered content is unchanged.');
    }
}
