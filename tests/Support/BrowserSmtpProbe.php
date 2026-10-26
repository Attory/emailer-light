<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Module\Configuration\Application\Contract\MailProbe;
use App\Module\Configuration\Infrastructure\SmtpMailProbe;

/** Only the disposable browser harness opts in; production never loads this file. */
final readonly class BrowserSmtpProbe implements MailProbe
{
    public function __construct(private SmtpMailProbe $ordinary, private bool $enabled)
    {
    }

    public function check(string $host, int $port, string $encryption, string $username, #[\SensitiveParameter] string $password, string $sender, ?string $destination): void
    {
        if (!$this->enabled) {
            $this->ordinary->check($host, $port, $encryption, $username, $password, $sender, $destination);

            return;
        }
        $database = getenv('DATABASE_URL');
        if ('test' !== getenv('APP_ENV') || 'https://emailer.example.test' !== getenv('DEFAULT_URI') || !\is_string($database) || !str_contains($database, '@database:3306/emailer_browser_test?') || !\in_array($host, ['smtp-fixture.test', 'wrong-host.test', 'missing-name.invalid'], true) || !\in_array($port, [2465, 2525, 2499], true) || null !== $destination) {
            throw new \RuntimeException('Not a bounded synthetic browser connection test.');
        }
        // This real production probe can reach only the owned internal network;
        // the fixed CA is injected by the harness, not accepted from a form.
        (new SmtpMailProbe('prod'))->check($host, $port, $encryption, $username, $password, $sender, null);
    }
}
