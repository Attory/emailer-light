<?php

declare(strict_types=1);

namespace App\Module\Configuration\Infrastructure;

use App\Module\Configuration\Application\Contract\MailTestFailure;

final class SmtpFailureClassifier
{
    public static function classify(\Throwable $failure, string $phase, bool $uncertainMessage = false): MailTestFailure
    {
        // Inspect transient text only. Never retain provider dialogue, username,
        // AUTH exchanges, exception arguments or raw exception chains in a result.
        $text = strtolower($failure->getMessage());
        $code = $failure->getCode();
        $category = match (true) {
            1 === preg_match('/getaddrinfo|php_network_getaddresses|could not resolve|name or service not known|name resolution/', $text) => 'dns',
            1 === preg_match('/certificate|peer name|enable_crypto|crypto failed|tls handshake|starttls (?:failed|failure|required)|connect with starttls|ssl operation failed/', $text) => 'tls_certificate',
            1 === preg_match('/failed to authenticate|authentication (?:failed|failure|required)|authentication unsuccessful|authenticator [^\r\n]{0,40}failed|\b53[045]\b/', $text), \in_array($code, [530, 534, 535], true) => 'authentication',
            1 === preg_match('/timed out|timeout/', $text), \in_array($code, [60, 110], true) => 'timeout',
            1 === preg_match('/connection refused|network is unreachable|no route to host|could not connect|failed to connect|connection could not be established/', $text), \in_array($code, [61, 101, 111, 113], true) => 'connection',
            default => 'internal',
        };
        $smtpCode = 1 === preg_match('/got code ["\x27]?([45][0-9]{2})/', $text, $match) ? (int) $match[1] : (\is_int($code) && 400 <= $code && 599 >= $code ? $code : null);
        sodium_memzero($text);

        return new MailTestFailure($category, diagnostics: ['phase' => $phase, 'fault_type' => $failure::class, 'fault_code' => \is_int($code) ? $code : null, 'smtp_code' => $smtpCode], uncertainMessage: $uncertainMessage);
    }
}
