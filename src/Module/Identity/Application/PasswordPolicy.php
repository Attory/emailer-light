<?php

declare(strict_types=1);

namespace App\Module\Identity\Application;

final class PasswordPolicy
{
    private const MINIMUM_CHARACTERS = 15;
    private const MAXIMUM_CHARACTERS = 256;
    private const MAXIMUM_BYTES = 1_024;

    /** @var list<string> */
    private const BLOCKLIST = [
        '123456789012345',
        'administrator',
        'changemechangeme',
        'emailernext',
        'emailerlight',
        'letmeinletmein',
        'passwordpassword',
        'qwertyqwertyqwerty',
        'welcome123456789',
    ];

    public function assertAcceptable(string $password, ?string $loginIdentifier = null): void
    {
        $normalized = \Normalizer::normalize($password, \Normalizer::FORM_C);
        if (!\is_string($normalized)
            || self::MINIMUM_CHARACTERS > mb_strlen($normalized, 'UTF-8')
            || self::MAXIMUM_CHARACTERS < mb_strlen($normalized, 'UTF-8')
            || self::MAXIMUM_BYTES < \strlen($normalized)
            || \in_array(mb_strtolower($normalized, 'UTF-8'), self::BLOCKLIST, true)
            || 1 === preg_match('/^(.)\1{14,}$/us', $normalized)
            || $this->containsLoginContext($normalized, $loginIdentifier)
        ) {
            throw new \InvalidArgumentException('Password does not satisfy the security policy.');
        }
    }

    private function containsLoginContext(string $password, ?string $loginIdentifier): bool
    {
        if (null === $loginIdentifier) {
            return false;
        }
        $login = mb_strtolower(trim($loginIdentifier), 'UTF-8');
        if (6 > mb_strlen($login, 'UTF-8')) {
            return false;
        }

        return str_contains(mb_strtolower($password, 'UTF-8'), $login);
    }
}
