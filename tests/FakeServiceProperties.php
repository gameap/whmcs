<?php

namespace GameAP\Whmcs\Tests;

/**
 * Stands in for the WHMCS service model's per-service key/value store, which
 * is where the module records the link to the panel.
 */
class FakeServiceProperties
{
    /** @var array<string,mixed> */
    public array $values = [];

    /**
     * @param array<string,mixed> $values
     */
    public function save(array $values): void
    {
        $this->values = array_merge($this->values, $values);
    }

    public function get(string $key)
    {
        return $this->values[$key] ?? null;
    }
}
