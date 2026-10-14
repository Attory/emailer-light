<?php

declare(strict_types=1);

namespace App\Module\Setup\Application;

final class ChallengeCode
{
    private const ALPHABET = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';

    public static function encode(string $bytes): string
    {
        if (20 !== \strlen($bytes)) {
            throw new \InvalidArgumentException('The ownership challenge requires exactly 160 random bits.');
        }

        $buffer = 0;
        $bits = 0;
        $encoded = '';

        $unpacked = unpack('C*', $bytes);

        if (false === $unpacked) {
            throw new \RuntimeException('Unable to encode ownership challenge.');
        }

        foreach ($unpacked as $byte) {
            if (!\is_int($byte)) {
                throw new \RuntimeException('Unable to encode ownership challenge.');
            }

            $buffer = ($buffer << 8) | $byte;
            $bits += 8;

            while ($bits >= 5) {
                $bits -= 5;
                $encoded .= self::ALPHABET[($buffer >> $bits) & 31];
                $buffer &= (1 << $bits) - 1;
            }
        }

        if (32 !== \strlen($encoded)) {
            throw new \LogicException('Unexpected challenge encoding length.');
        }

        return $encoded;
    }

    public static function display(string $normalized): string
    {
        return implode('-', str_split($normalized, 4));
    }

    public static function normalize(string $candidate): ?string
    {
        $candidate = strtoupper(str_replace('-', '', rtrim($candidate, "\r\n")));

        if (1 !== preg_match('/^[0-9A-HJKMNP-TV-Z]{32}$/', $candidate)) {
            return null;
        }

        return $candidate;
    }
}
