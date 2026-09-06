<?php

namespace GameAP\Whmcs\Tests;

use PHPUnit\Framework\TestCase;
use WHMCS\Module\Server\Gameap\Diagnostics;
use WHMCS\Module\Server\Gameap\ModuleException;
use WHMCS\Module\Server\Gameap\PanelClient;

class DiagnosticsTest extends TestCase
{
    private FakeHttpClient $http;

    protected function setUp(): void
    {
        $this->http = new FakeHttpClient();
        $this->stubHealthyPanel();
    }

    private function diagnostics(): Diagnostics
    {
        return new Diagnostics($this->http, new PanelClient($this->http));
    }

    private function stubHealthyPanel(): void
    {
        $this->http
            ->on('GET', '/api/servers', ['total' => 0, 'data' => []])
            ->on('GET', '/api/nodes', [['id' => 1, 'name' => 'eu-1', 'enabled' => true]])
            ->on('GET', '/api/games', [['code' => 'cs2']])
            ->on('GET', '/api/users', [])
            // The write probes send an empty body on purpose: the panel
            // rejects it at validation, which is after the ability check.
            ->on('POST', '/api/servers', new ModuleException(ModuleException::CODE_PANEL, 'name is required', 422))
            ->on('POST', '/api/users', new ModuleException(ModuleException::CODE_PANEL, 'login is required', 422))
            ->on(
                'POST',
                '/api/auth/sso/tickets',
                new ModuleException(ModuleException::CODE_PANEL, 'user_id is required', 422)
            );
    }

    public function testHealthyPanelPasses(): void
    {
        $report = $this->diagnostics()->run();

        $this->assertTrue($report['ok'], implode(' ', $report['problems']));
        $this->assertSame([], $report['problems']);
    }

    public function testProbesDoNotCreateAnything(): void
    {
        $this->diagnostics()->run();

        foreach ($this->http->calls as $call) {
            if ($call['method'] === 'POST') {
                $this->assertSame([], $call['body'], 'write probes must send an empty body');
            }

            $this->assertNotSame('DELETE', $call['method']);
            $this->assertNotSame('PUT', $call['method']);
        }
    }

    /**
     * The failure that costs support tickets is a token that connects fine and
     * then fails on the first order, so a missing ability has to be named.
     */
    public function testMissingAbilityIsReportedByName(): void
    {
        $this->http->on(
            'POST',
            '/api/auth/sso/tickets',
            new ModuleException(ModuleException::CODE_FORBIDDEN, 'missing ability', 403)
        );

        $report = $this->diagnostics()->run();

        $this->assertFalse($report['ok']);
        $this->assertSame(['The token is missing the "admin:user:sso" ability.'], $report['problems']);
    }

    public function testEveryMissingAbilityIsCollected(): void
    {
        $forbidden = new ModuleException(ModuleException::CODE_FORBIDDEN, 'missing ability', 403);

        $this->http
            ->on('GET', '/api/nodes', $forbidden)
            ->on('GET', '/api/games', $forbidden);

        $report = $this->diagnostics()->run();

        $this->assertCount(2, $report['problems']);
        $this->assertStringContainsString('admin:node:read', $report['problems'][0]);
        $this->assertStringContainsString('admin:game:read', $report['problems'][1]);
    }

    /**
     * The panel checks that the token's owner is an administrator before it
     * looks at abilities, so without this every admin probe would fail and
     * the report would list five missing abilities that are all present.
     */
    public function testANonAdministratorTokenOwnerIsReportedOnce(): void
    {
        $refused = new ModuleException(
            ModuleException::CODE_FORBIDDEN,
            'refused',
            403,
            null,
            'admin permissions required'
        );

        $this->http
            ->on('GET', '/api/nodes', $refused)
            ->on('GET', '/api/games', $refused)
            ->on('GET', '/api/users', $refused)
            ->on('POST', '/api/servers', $refused);

        $report = $this->diagnostics()->run();

        $this->assertFalse($report['ok']);
        $this->assertCount(1, $report['problems']);
        $this->assertStringContainsString('not an administrator', $report['problems'][0]);
    }

    public function testARejectedTokenShortCircuits(): void
    {
        $this->http->on(
            'GET',
            '/api/servers',
            new ModuleException(ModuleException::CODE_AUTH, 'unauthenticated', 401)
        );

        $report = $this->diagnostics()->run();

        $this->assertFalse($report['ok']);
        $this->assertCount(1, $report['problems']);
        $this->assertStringContainsString('Access Hash', $report['problems'][0]);
    }

    public function testAnUnreachablePanelIsReportedOnce(): void
    {
        $this->http->on(
            'GET',
            '/api/servers',
            new ModuleException(ModuleException::CODE_TRANSPORT, 'Could not reach the GameAP panel')
        );

        $report = $this->diagnostics()->run();

        $this->assertFalse($report['ok']);
        $this->assertCount(1, $report['problems']);
    }

    public function testNoEnabledNodesIsCalledOut(): void
    {
        $this->http->on('GET', '/api/nodes', [['id' => 1, 'name' => 'eu-1', 'enabled' => false]]);

        $report = $this->diagnostics()->run();

        $this->assertTrue($report['ok']);
        $this->assertStringContainsString('No enabled nodes', implode(' ', $report['notes']));
    }
}
