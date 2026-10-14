<?php

declare(strict_types=1);

namespace App\Module\Identity\Application;

use App\Shared\Identity;

final class IdentityUtil
{
    public static function normalizeLogin(string $login): string
    {
        $normalized = \Normalizer::normalize(trim($login), \Normalizer::FORM_C);
        if (!\is_string($normalized)) {
            throw new \InvalidArgumentException('Login identifier is invalid.');
        }
        $normalized = mb_strtolower($normalized, 'UTF-8');
        if (false === filter_var($normalized, FILTER_VALIDATE_EMAIL) || 254 < \strlen($normalized)) {
            throw new \InvalidArgumentException('Login identifier must be a valid email address.');
        }

        return $normalized;
    }

    public static function uuidV7(): string
    {
        return Identity::uuidV7();
    }

    public static function now(): string
    {
        return Identity::now();
    }
}
