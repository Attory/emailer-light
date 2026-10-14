<?php

declare(strict_types=1);

namespace App\Shared;

final readonly class EmailAddress
{
    public string $entered;
    public string $canonical;

    public function __construct(string $value)
    {
        $entered = \Normalizer::normalize(trim($value), \Normalizer::FORM_C);
        if (!\is_string($entered) || 254 < \strlen($entered) || false === filter_var($entered, FILTER_VALIDATE_EMAIL) || 1 === preg_match('/[\x00-\x20\x7F]/', $entered)) {
            throw new \InvalidArgumentException('Email address is invalid.');
        }
        $separator = strrpos($entered, '@');
        if (false === $separator) {
            throw new \InvalidArgumentException('Email address is invalid.');
        }
        $local = substr($entered, 0, $separator);
        $domain = substr($entered, $separator + 1);
        $asciiDomain = idn_to_ascii($domain, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46);
        if (false === $asciiDomain || '' === $asciiDomain) {
            throw new \InvalidArgumentException('Email domain is invalid.');
        }
        $this->entered = $entered;
        $this->canonical = $local.'@'.strtolower($asciiDomain);
    }

    public function masked(): string
    {
        [$local, $domain] = explode('@', $this->entered, 2);
        $labels = explode('.', $domain);
        $labels[0] = '' === $labels[0] ? '*' : mb_substr($labels[0], 0, 1, 'UTF-8').'***';

        return mb_substr($local, 0, 1, 'UTF-8').'***@'.implode('.', $labels);
    }
}
