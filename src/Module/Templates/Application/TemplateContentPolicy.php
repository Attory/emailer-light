<?php

declare(strict_types=1);

namespace App\Module\Templates\Application;

use Symfony\Component\HtmlSanitizer\HtmlSanitizer;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerConfig;

final readonly class TemplateContentPolicy
{
    public const FIELD_EXAMPLES = [
        'recipient.first_name' => 'Alex',
        'recipient.display_name' => 'Alex Example',
        'campaign.name' => 'October news',
        'unsubscribe_url' => 'Unsubscribe link',
        'view_in_browser_url' => 'Read online link',
        'rsvp_url' => 'RSVP link (requires RSVP enabled)',
    ];
    private const URL_FIELDS = ['unsubscribe_url', 'view_in_browser_url', 'rsvp_url'];
    private HtmlSanitizer $sanitizer;

    public function __construct()
    {
        $config = (new HtmlSanitizerConfig())
            ->allowElement('p')
            ->allowElement('br')
            ->allowElement('strong')
            ->allowElement('em')
            ->allowElement('u')
            ->allowElement('h1')
            ->allowElement('h2')
            ->allowElement('h3')
            ->allowElement('ul')
            ->allowElement('ol')
            ->allowElement('li')
            ->allowElement('blockquote')
            ->allowElement('div')
            ->allowElement('span')
            ->allowElement('table')
            ->allowElement('thead')
            ->allowElement('tbody')
            ->allowElement('tr')
            ->allowElement('th', ['colspan', 'rowspan'])
            ->allowElement('td', ['colspan', 'rowspan'])
            ->allowElement('a', ['href', 'title'])
            ->allowElement('img', ['src', 'alt', 'width', 'height'])
            ->forceAttribute('a', 'rel', 'noopener noreferrer')
            ->allowLinkSchemes(['https', 'mailto'])
            ->allowRelativeLinks(false)
            ->allowMediaSchemes([])
            ->allowRelativeMedias(true)
            ->forceHttpsUrls()
            ->withMaxInputLength(200_000);
        $this->sanitizer = new HtmlSanitizer($config);
    }

    /** @return array{html: string, text: string, fields: list<string>, links: list<string>} */
    public function prepare(string $subject, string $preheader, string $html, string $text): array
    {
        $this->headerText($subject, 200, false);
        $this->headerText($preheader, 200, true);
        if ('' === trim($html) || 200_000 < \strlen($html) || str_contains($html, '<?') || str_contains($html, '{%') || str_contains($html, '{#')) {
            throw new \InvalidArgumentException('Template HTML is invalid.');
        }
        $fields = array_values(array_unique([...$this->fields($subject, 'Subject'), ...$this->fields($preheader, 'Preheader'), ...$this->fields($html, 'HTML'), ...$this->fields($text, 'Plain text')]));
        // Preserve original source offsets while masking the permitted whole-link tokens.
        $contexts = $html;
        foreach (self::URL_FIELDS as $field) {
            preg_match_all('/href\s*=\s*(["\'])\s*(\{\{\s*'.preg_quote($field, '/').'\s*\}\})\s*\1/i', $html, $valid, PREG_OFFSET_CAPTURE);
            foreach ($valid[2] as [$placeholder, $offset]) {
                $contexts = substr_replace($contexts, str_repeat(' ', \strlen($placeholder)), $offset, \strlen($placeholder));
            }
            if (1 === preg_match('/\{\{\s*'.preg_quote($field, '/').'\s*\}\}/', $contexts, $invalid, PREG_OFFSET_CAPTURE)) {
                throw new TemplateMergeException($invalid[0][0], 'HTML', $html, $invalid[0][1], 'Use this URL placeholder as the complete href of a link, for example <a href="{{ '.$field.' }}">Open link</a>.');
            }
        }
        if (1 === preg_match('/<[^>]*(\{\{[^{}]*\}\})/', $contexts, $invalid, PREG_OFFSET_CAPTURE)) {
            throw new TemplateMergeException($invalid[1][0], 'HTML', $html, $invalid[1][1], 'Move this text placeholder outside the HTML tag, for example <p>Hello {{ recipient.first_name }}</p>. Only URL placeholders may be complete link addresses.');
        }
        $protected = $html;
        foreach (self::URL_FIELDS as $field) {
            $protected = preg_replace('/href\s*=\s*(["\'])\s*\{\{\s*'.preg_quote($field, '/').'\s*\}\}\s*\1/i', 'href="https://merge.invalid/'.$field.'"', $protected) ?? $protected;
        }
        $this->links($protected);
        $this->images($protected);
        $sanitized = $this->sanitizer->sanitize($protected);
        foreach (self::URL_FIELDS as $field) {
            $sanitized = str_replace('https://merge.invalid/'.$field, '{{ '.$field.' }}', $sanitized);
        }
        $links = $this->links($sanitized);
        $plain = '' !== trim($text) ? $this->plainText($text) : $this->plainText(html_entity_decode(strip_tags(str_replace(['</p>', '<br>', '<br/>', '<br />'], "\n", $sanitized)), ENT_QUOTES | ENT_HTML5, 'UTF-8'));

        return ['html' => $sanitized, 'text' => $plain, 'fields' => $fields, 'links' => $links];
    }

    /** @param array<string, string> $data */
    public function renderHtml(string $html, array $data): string
    {
        foreach ($this->fields($html) as $field) {
            $value = $data[$field] ?? '';
            if (\in_array($field, self::URL_FIELDS, true)) {
                if (1 !== preg_match('#^https://[^\s]+$#', $value)) {
                    throw new \InvalidArgumentException('Synthetic URL value is invalid.');
                }
                $replacement = htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
            } else {
                $replacement = htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
            }
            $html = preg_replace('/\{\{\s*'.preg_quote($field, '/').'\s*\}\}/', $replacement, $html) ?? $html;
        }

        return $html;
    }

    /** @return list<string> */
    private function fields(string $content, string $input = 'HTML'): array
    {
        preg_match_all('/\{\{[^{}]*\}\}/', $content, $matches, PREG_OFFSET_CAPTURE);
        $fields = [];
        $remaining = $content;
        foreach ($matches[0] as [$placeholder, $offset]) {
            $field = trim(substr($placeholder, 2, -2));
            if (!\in_array($field, array_keys(self::FIELD_EXAMPLES), true)) {
                $suggestions = ['first_name' => 'recipient.first_name', 'display_name' => 'recipient.display_name', 'name' => 'recipient.display_name', 'unsubscribe' => 'unsubscribe_url', 'campaign_name' => 'campaign.name'];
                $replacement = $suggestions[$field] ?? null;
                $correction = null === $replacement ? 'Choose a supported placeholder beside the editor, or remove the double braces to keep literal text.' : 'Replace it with {{ '.$replacement.' }} using Insert placeholder beside the editor.';
                throw new TemplateMergeException($placeholder, $input, $content, $offset, $correction);
            }
            $fields[] = $field;
            // Preserve byte offsets and line breaks for malformed syntax diagnostics.
            $remaining = substr_replace($remaining, str_repeat(' ', \strlen($placeholder)), $offset, \strlen($placeholder));
        }
        if (1 === preg_match('/\{\{|\}\}/', $remaining, $invalid, PREG_OFFSET_CAPTURE)) {
            $offset = $invalid[0][1];
            $line = substr($content, $offset, strcspn($content, "\r\n", $offset));
            throw new TemplateMergeException($line, $input, $content, $offset, 'Use two opening and two closing braces, for example {{ recipient.first_name }}. Insert a supported placeholder beside the editor.');
        }

        return $fields;
    }

    /** @return list<string> */
    private function links(string $html): array
    {
        $document = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);
        try {
            $document->loadHTML('<meta charset="utf-8">'.$html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NONET);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
        $links = [];
        foreach ($document->getElementsByTagName('a') as $anchor) {
            $href = $anchor->getAttribute('href');
            if ('' === $href) {
                continue;
            }
            if (1 === preg_match('/^\{\{\s*(unsubscribe_url|view_in_browser_url|rsvp_url)\s*\}\}$/', $href)) {
                $links[] = $href;
                continue;
            }
            $parts = parse_url($href);
            if (false === $parts || !isset($parts['scheme']) || !\in_array(strtolower($parts['scheme']), ['https', 'mailto'], true) || isset($parts['user']) || isset($parts['pass']) || 1 === preg_match('/[\x00-\x20\x7F]/', $href)) {
                throw new \InvalidArgumentException('Template link is invalid.');
            }
            if ('https' === strtolower($parts['scheme']) && !isset($parts['host'])) {
                throw new \InvalidArgumentException('Template link host is invalid.');
            }
            $links[] = $href;
        }

        return array_values(array_unique($links));
    }

    private function images(string $html): void
    {
        $document = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);
        try {
            $document->loadHTML('<meta charset="utf-8">'.$html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NONET);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
        foreach ($document->getElementsByTagName('img') as $image) {
            if (1 !== preg_match('#^/template-assets/[a-f0-9]{48}\.(?:jpg|png|webp)$#', $image->getAttribute('src'))) {
                throw new \InvalidArgumentException('Template image source is invalid.');
            }
        }
    }

    private function headerText(string $value, int $limit, bool $optional): void
    {
        if ((!$optional && '' === trim($value)) || mb_strlen($value, 'UTF-8') > $limit || 1 === preg_match('/[\r\n\x00-\x1F\x7F]/', $value)) {
            throw new \InvalidArgumentException('Template header text is invalid.');
        }
    }

    private function plainText(string $value): string
    {
        if (!mb_check_encoding($value, 'UTF-8') || 100_000 < \strlen($value) || 1 === preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $value)) {
            throw new \InvalidArgumentException('Template text is invalid.');
        }
        $value = preg_replace('/[ \t]+/', ' ', $value) ?? $value;
        $value = preg_replace('/\n{3,}/', "\n\n", $value) ?? $value;

        return trim($value);
    }
}
