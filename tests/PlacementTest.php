<?php

namespace GameAP\Whmcs\Tests;

use PHPUnit\Framework\TestCase;
use WHMCS\Module\Server\Gameap\ModuleException;
use WHMCS\Module\Server\Gameap\PanelClient;
use WHMCS\Module\Server\Gameap\Placement;

class PlacementTest extends TestCase
{
    private FakeHttpClient $http;

    private Placement $placement;

    protected function setUp(): void
    {
        $this->http = new FakeHttpClient();
        $this->placement = new Placement(new PanelClient($this->http));
    }

    public function testPicksTheLeastLoadedNode(): void
    {
        $this->http->on('GET', '/api/nodes', [
            ['id' => 1, 'name' => 'eu-1', 'enabled' => true],
            ['id' => 2, 'name' => 'eu-2', 'enabled' => true],
        ]);

        // The count comes from the paginated total, not from the page itself.
        $this->http->onEach('GET', '/api/servers', [
            ['total' => 40, 'data' => []],
            ['total' => 3, 'data' => []],
        ]);

        $nodes = $this->placement->candidateNodes([]);

        $this->assertSame(2, $nodes[0]['id']);
        $this->assertSame(1, $nodes[1]['id']);
        $this->assertCount(2, $this->http->callsTo('GET', '/api/servers'), 'one load read per node');
    }

    public function testSkipsDisabledNodesAndNodesOutsideThePool(): void
    {
        $this->http->on('GET', '/api/nodes', [
            ['id' => 1, 'name' => 'eu-1', 'enabled' => false],
            ['id' => 2, 'name' => 'eu-2', 'enabled' => true],
            ['id' => 3, 'name' => 'us-1', 'enabled' => true],
        ]);
        $this->http->on('GET', '/api/servers', ['total' => 0, 'data' => []]);

        $nodes = $this->placement->candidateNodes(['EU-2']);

        $this->assertCount(1, $nodes);
        $this->assertSame(2, $nodes[0]['id']);
    }

    public function testNoUsableNodeIsACapacityError(): void
    {
        $this->http->on('GET', '/api/nodes', [['id' => 1, 'name' => 'eu-1', 'enabled' => false]]);

        $this->expectException(ModuleException::class);
        $this->expectExceptionMessage('No enabled nodes');

        $this->placement->candidateNodes([]);
    }

    public function testUnknownPoolNamesAreReportedBack(): void
    {
        $this->http->on('GET', '/api/nodes', [['id' => 1, 'name' => 'eu-1', 'enabled' => true]]);

        $this->expectException(ModuleException::class);
        $this->expectExceptionMessage('us-9');

        $this->placement->candidateNodes(['us-9']);
    }

    public function testAllocatesTheFirstFreeBlock(): void
    {
        $this->http->on('GET', '/api/nodes/1/busy_ports', ['10.0.0.1' => [27000, 27001]]);
        $this->http->on('GET', '/api/nodes/1/ip_list', ['10.0.0.1']);

        $allocation = $this->placement->allocate(1, ['name' => 'eu-1'], [27000, 27100], 3);

        // 27000-27002 overlaps a busy port, so the next aligned block wins.
        $this->assertSame('10.0.0.1', $allocation['ip']);
        $this->assertSame([27003, 27004, 27005], $allocation['ports']);
    }

    public function testPrefersTheLeastCrowdedAddress(): void
    {
        $this->http->on('GET', '/api/nodes/1/busy_ports', [
            '10.0.0.1' => [27000, 27001, 27002],
            '10.0.0.2' => [],
        ]);
        $this->http->on('GET', '/api/nodes/1/ip_list', ['10.0.0.1', '10.0.0.2']);

        $allocation = $this->placement->allocate(1, ['name' => 'eu-1'], [27000, 27100], 3);

        $this->assertSame('10.0.0.2', $allocation['ip']);
        $this->assertSame([27000, 27001, 27002], $allocation['ports']);
    }

    public function testExhaustedRangeIsACapacityError(): void
    {
        $this->http->on('GET', '/api/nodes/1/busy_ports', ['10.0.0.1' => [27000, 27003]]);
        $this->http->on('GET', '/api/nodes/1/ip_list', ['10.0.0.1']);

        $this->expectException(ModuleException::class);
        $this->expectExceptionMessage('No free port block');

        $this->placement->allocate(1, ['name' => 'eu-1'], [27000, 27005], 3);
    }

    public function testFallsBackToTheNodeRecordWhenTheIpListIsEmpty(): void
    {
        $this->http->on('GET', '/api/nodes/1/busy_ports', []);
        $this->http->on('GET', '/api/nodes/1/ip_list', []);

        // The node list carries the addresses under "ip".
        $allocation = $this->placement->allocate(1, ['name' => 'eu-1', 'ip' => ['203.0.113.5']], [27000, 27100], 3);

        $this->assertSame('203.0.113.5', $allocation['ip']);
    }

    public function testNodeWithoutAddressesIsACapacityError(): void
    {
        $this->http->on('GET', '/api/nodes/1/busy_ports', []);
        $this->http->on('GET', '/api/nodes/1/ip_list', []);

        $this->expectException(ModuleException::class);
        $this->expectExceptionMessage('no IP addresses');

        $this->placement->allocate(1, ['name' => 'eu-1'], [27000, 27100], 3);
    }
}
