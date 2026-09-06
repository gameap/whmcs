<?php

namespace GameAP\Whmcs\Tests;

use PHPUnit\Framework\TestCase;
use WHMCS\Module\Server\Gameap\HttpClient;

class HttpClientTest extends TestCase
{
    /**
     * The panel decodes bodies into structs, and json_encode([]) is "[]" — a
     * list, which the panel reports as a 500 rather than a validation error.
     * Test Connection sends empty bodies on purpose, so this matters.
     */
    public function testAnEmptyBodyIsAnObject(): void
    {
        $this->assertSame('{}', HttpClient::encodeBody([]));
    }

    public function testListsAndMapsKeepTheirShape(): void
    {
        $this->assertSame('{"delete_files":true}', HttpClient::encodeBody(['delete_files' => true]));
        $this->assertSame(
            '[{"name":"maxplayers","value":"24"}]',
            HttpClient::encodeBody([['name' => 'maxplayers', 'value' => '24']])
        );
    }

    public function testSlashesAndUnicodeAreNotEscaped(): void
    {
        $this->assertSame('{"name":"cs2 #1 / Анна"}', HttpClient::encodeBody(['name' => 'cs2 #1 / Анна']));
    }
}
