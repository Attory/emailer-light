<?php

declare(strict_types=1);

namespace App\Tests\Module\Templates;

use App\Module\Templates\Application\TemplateContentPolicy;
use App\Module\Templates\Application\TemplateMergeException;
use App\Module\Templates\Presentation\Http\TemplateEditorMessages;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class TemplateMergeDiagnosticTest extends TestCase
{
    #[DataProvider('invalidFields')]
    public function testDiagnosticNamesExactPlaceholderInputAndLine(string $subject, string $preheader, string $html, string $text, string $field, string $placeholder, int $line, string $correction): void
    {
        try {
            (new TemplateContentPolicy())->prepare($subject, $preheader, $html, $text);
            self::fail('Invalid placeholder was accepted.');
        } catch (TemplateMergeException $failure) {
            $message = TemplateEditorMessages::save($failure);
            self::assertStringContainsString($field.' · line '.$line, $message);
            self::assertStringContainsString($placeholder, $message);
            self::assertStringContainsString($correction, $message);
            self::assertStringContainsString('content is unchanged', $message);
        }
    }

    /** @return iterable<string, array{string, string, string, string, string, string, int, string}> */
    public static function invalidFields(): iterable
    {
        yield 'subject' => ['Hi {{ first_name }}', '', '<p>Hello</p>', '', 'Subject', '{{ first_name }}', 1, '{{ recipient.first_name }}'];
        yield 'preheader' => ['Hello', '{{ invalid }}', '<p>Hello</p>', '', 'Preheader', '{{ invalid }}', 1, 'Choose a supported placeholder'];
        yield 'html second line' => ['Hello', '', "<p>Hi</p>\n<p>{{ contact.email }}</p>", '', 'HTML', '{{ contact.email }}', 2, 'Choose a supported placeholder'];
        yield 'plain third line' => ['Hello', '', '<p>Hello</p>', "Hi\nThere\n{{ bad }}", 'Plain text', '{{ bad }}', 3, 'Choose a supported placeholder'];
        yield 'incomplete after known field' => ['Hello', '', "<p>{{ recipient.first_name }}</p>\n<p>{{ broken</p>", '', 'HTML', '{{ broken', 2, 'two closing braces'];
        yield 'misplaced URL' => ['Hello', '', "<p>Hi</p>\n<p>{{ unsubscribe_url }}</p>", '', 'HTML', '{{ unsubscribe_url }}', 2, 'complete href'];
        yield 'URL after a valid occurrence' => ['Hello', '', "<a href=\"{{ unsubscribe_url }}\">Unsubscribe</a>\n<p>Text</p>\n<p>{{ unsubscribe_url }}</p>", '', 'HTML', '{{ unsubscribe_url }}', 3, 'complete href'];
        yield 'attribute' => ['Hello', '', '<p title="{{ recipient.first_name }}">Hi</p>', '', 'HTML', '{{ recipient.first_name }}', 1, 'outside the HTML tag'];
    }
}
