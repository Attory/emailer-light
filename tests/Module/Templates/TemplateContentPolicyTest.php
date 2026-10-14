<?php

declare(strict_types=1);

namespace App\Tests\Module\Templates;

use App\Module\Templates\Application\TemplateContentPolicy;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class TemplateContentPolicyTest extends TestCase
{
    public function testSanitizerUsesAnExplicitAllowlistAndProducesPlainText(): void
    {
        $policy = new TemplateContentPolicy();
        $prepared = $policy->prepare(
            'Hello {{ recipient.first_name }}',
            'Safe preheader',
            '<p onclick="steal()">Hello <strong>{{ recipient.display_name }}</strong><script>alert(1)</script><iframe src="https://evil.test"></iframe></p>',
            '',
        );

        self::assertStringNotContainsString('onclick', $prepared['html']);
        self::assertStringNotContainsString('script', $prepared['html']);
        self::assertStringNotContainsString('iframe', $prepared['html']);
        self::assertStringContainsString('{{ recipient.display_name }}', $prepared['html']);
        self::assertSame('Hello {{ recipient.display_name }}', $prepared['text']);
        self::assertEqualsCanonicalizing(['recipient.first_name', 'recipient.display_name'], $prepared['fields']);
    }

    public function testAllowedLinksAndUrlMergeFieldsAreRecorded(): void
    {
        $policy = new TemplateContentPolicy();
        $prepared = $policy->prepare('Subject', '', '<p><a href="https://example.test/path">One</a><a href="{{ unsubscribe_url }}">Two</a></p>', 'Text');

        self::assertContains('https://example.test/path', $prepared['links']);
        self::assertContains('{{ unsubscribe_url }}', $prepared['links']);
        self::assertStringContainsString('noopener noreferrer', $prepared['html']);
    }

    public function testSyntheticRenderingEscapesContactValues(): void
    {
        $rendered = (new TemplateContentPolicy())->renderHtml('<p>{{ recipient.display_name }}</p>', ['recipient.display_name' => '<img src=x onerror=alert(1)>']);

        self::assertSame('<p>&lt;img src=x onerror=alert(1)&gt;</p>', $rendered);
    }

    #[DataProvider('unsafeTemplates')]
    public function testUnsafeTemplateConstructsAreRejected(string $subject, string $html): void
    {
        $this->expectException(\InvalidArgumentException::class);

        (new TemplateContentPolicy())->prepare($subject, '', $html, 'Text');
    }

    /** @return iterable<string, array{string, string}> */
    public static function unsafeTemplates(): iterable
    {
        yield 'header injection' => ["Subject\r\nBcc: attacker@example.test", '<p>Text</p>'];
        yield 'unknown merge field' => ['Subject', '<p>{{ contact.email }}</p>'];
        yield 'twig expression' => ['Subject', '<p>{% if true %}Text{% endif %}</p>'];
        yield 'attribute interpolation' => ['Subject', '<p title="{{ recipient.first_name }}">Text</p>'];
        yield 'javascript link' => ['Subject', '<a href="javascript:alert(1)">Text</a>'];
        yield 'credential link' => ['Subject', '<a href="https://user:pass@example.test/">Text</a>'];
        yield 'relative link' => ['Subject', '<a href="/recipient/path">Text</a>'];
        yield 'remote image' => ['Subject', '<img src="//tracking.example.test/pixel.png" alt="pixel">'];
        yield 'unregistered local image' => ['Subject', '<img src="/uploads/pixel.png" alt="pixel">'];
        yield 'url merge in text' => ['Subject', '<p>{{ unsubscribe_url }}</p>'];
    }
}
