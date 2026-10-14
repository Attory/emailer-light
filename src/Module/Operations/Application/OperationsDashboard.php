<?php

declare(strict_types=1);

namespace App\Module\Operations\Application;

use App\Module\Operations\Application\Contract\OperationalStatusProvider;

final readonly class OperationsDashboard
{
    /** @param iterable<OperationalStatusProvider> $providers */
    public function __construct(private iterable $providers)
    {
    }

    /** @return list<array<string, mixed>> */
    public function sections(): array
    {
        $sections = [];
        foreach ($this->providers as $provider) {
            try {
                $section = $provider->status();
                if (!$this->safe($section)) {
                    throw new \UnexpectedValueException('Operational status is unsafe.');
                }
                $sections[] = $section;
            } catch (\Throwable) {
                $sections[] = ['key' => 'unavailable', 'title' => 'Operational component', 'status' => 'unavailable', 'metrics' => [['label' => 'Status', 'value' => 'Redacted status unavailable']]];
            }
        }
        usort($sections, static fn (array $a, array $b): int => $a['key'] <=> $b['key']);

        return $sections;
    }

    /** @param array<string, mixed> $section */
    private function safe(array $section): bool
    {
        if (!\is_string($section['key'] ?? null) || 1 !== preg_match('/^[a-z][a-z0-9_]{1,31}$/', $section['key']) || !\is_string($section['title'] ?? null) || !\is_string($section['status'] ?? null) || !\is_array($section['metrics'] ?? null)) {
            return false;
        }
        foreach ($section['metrics'] as $metric) {
            if (!\is_array($metric) || !\is_string($metric['label'] ?? null) || !(\is_string($metric['value'] ?? null) || \is_int($metric['value'] ?? null))) {
                return false;
            }
            if (str_contains($metric['label'], '@') || (\is_string($metric['value']) && (str_contains($metric['value'], '@') || 160 < \strlen($metric['value'])))) {
                return false;
            }
        }

        return true;
    }
}
