<?php

namespace GameAP\Whmcs\Tests;

use PHPUnit\Framework\TestCase;
use WHMCS\Module\Server\Gameap\Config;
use WHMCS\Module\Server\Gameap\ModuleException;
use WHMCS\Module\Server\Gameap\PanelClient;
use WHMCS\Module\Server\Gameap\Provisioner;
use WHMCS\Module\Server\Gameap\ServiceState;

class ProvisionerTest extends TestCase
{
    private const MB = 1048576;

    private FakeHttpClient $http;

    private FakeServiceModel $model;

    protected function setUp(): void
    {
        $this->http = new FakeHttpClient();
        $this->model = new FakeServiceModel();
    }

    /**
     * @param array<string,mixed> $overrides
     */
    private function params(array $overrides = []): array
    {
        return array_merge([
            'serviceid' => 1042,
            'userid' => 55,
            'model' => $this->model,
            'clientsdetails' => [
                'id' => 55,
                'email' => 'ann@example.com',
                'firstname' => 'Ann',
                'lastname' => 'Bee',
            ],
            // game, slots, ram, cpu, port range, name template, mod variables
            'configoption1' => 'cs2',
            'configoption4' => '24',
            'configoption5' => '4096',
            'configoption6' => '200',
            'configoption8' => '27000-27100',
            'configoption10' => '{game} #{service_id}',
            'configoption12' => 'maxplayers={slots}',
        ], $overrides);
    }

    /**
     * @param array<string,mixed> $overrides
     */
    private function provisioner(array $overrides = []): array
    {
        $params = $this->params($overrides);
        $state = new ServiceState($params);
        $provisioner = new Provisioner(new PanelClient($this->http), new Config($params), $state, $params);

        return [$provisioner, $state];
    }

    /**
     * @param array<string,mixed> $overrides
     *
     * @return array<string,mixed>
     */
    private function server(array $overrides = []): array
    {
        return array_merge([
            'id' => 314,
            'name' => 'cs2 #1042',
            'game_id' => 'cs2',
            'ds_id' => 1,
            'game_mod_id' => 4,
            'server_ip' => '10.0.0.1',
            'internal_server_ip' => '10.0.0.1',
            'server_port' => 27000,
            'blocked' => false,
            'online' => false,
            'metadata' => [],
        ], $overrides);
    }

    private function stubCreateFlow(): void
    {
        $this->http
            ->on('GET', '/api/users', [])
            ->on('POST', '/api/users', ['id' => 77, 'login' => 'whmcs55'])
            ->on('GET', '/api/games/cs2/mods', [['id' => 4, 'name' => 'Classic'], ['id' => 5, 'name' => 'Deathmatch']])
            ->on('GET', '/api/nodes', [['id' => 1, 'name' => 'eu-1', 'enabled' => true]])
            ->on('GET', '/api/servers', ['total' => 2, 'data' => []])
            ->on('GET', '/api/nodes/1/busy_ports', ['10.0.0.1' => []])
            ->on('GET', '/api/nodes/1/ip_list', ['10.0.0.1'])
            // The panel wraps the new id: {"message":"success","result":{"taskId":0,"serverId":N}}.
            ->on('POST', '/api/servers', ['message' => 'success', 'result' => ['taskId' => 9, 'serverId' => 314]])
            ->on('GET', '/api/servers/314', $this->server(['metadata' => ['docker_image' => 'gameap/debian']]))
            ->on('PUT', '/api/servers/314', ['status' => 'ok'])
            ->on('GET', '/api/users/77', [
                'id' => 77,
                'login' => 'whmcs55',
                'email' => 'ann@example.com',
                'roles' => ['user'],
            ])
            ->on('PUT', '/api/users/77/servers/314', [])
            ->on('PUT', '/api/users/77/servers/314/permissions', []);
    }

    /**
     * @param array<string,mixed> $overrides
     */
    private function stubServerRead(array $overrides = []): void
    {
        $this->http->on('GET', '/api/servers/314', $this->server($overrides));
        $this->http->on('PUT', '/api/servers/314', ['status' => 'ok']);
    }

    private function linkServer(): void
    {
        $this->model->serviceProperties->save([
            ServiceState::FIELD_SERVER_ID => '314',
            ServiceState::FIELD_USER_ID => '77',
        ]);
    }

    // -----------------------------------------------------------------
    // Create
    // -----------------------------------------------------------------

    public function testCreateProvisionsAndRecordsTheLink(): void
    {
        $this->stubCreateFlow();
        [$provisioner, $state] = $this->provisioner();

        $provisioner->create();

        $create = $this->http->callsTo('POST', '/api/servers')[0]['body'];
        $this->assertSame('cs2 #1042', $create['name']);
        $this->assertSame('cs2', $create['game_id']);
        $this->assertSame(4, $create['game_mod_id']);
        $this->assertSame(1, $create['ds_id']);
        $this->assertSame('10.0.0.1', $create['server_ip']);
        $this->assertSame([27000, 27001, 27002], [$create['server_port'], $create['query_port'], $create['rcon_port']]);
        $this->assertSame([['name' => 'maxplayers', 'value' => '24']], $create['settings']);
        $this->assertTrue($create['install'], 'installation is queued unless the product says otherwise');

        $this->assertSame(314, $state->serverId());
        $this->assertSame(77, $state->userId());
        $this->assertSame('eu-1', $this->model->serviceProperties->get(ServiceState::FIELD_NODE));
    }

    public function testCreateReadsTheServerIdFromThePanelEnvelope(): void
    {
        // A panel that answered {"id": N} would be accepted as well, but the
        // real 4.5 envelope is what has to work: reading a bare "id" from it
        // yields 0 and the flow used to walk on to the next node.
        $this->stubCreateFlow();
        $this->http->on('GET', '/api/nodes', [
            ['id' => 1, 'name' => 'eu-1', 'enabled' => true],
            ['id' => 2, 'name' => 'eu-2', 'enabled' => true],
        ]);
        $this->http->on('GET', '/api/nodes/2/busy_ports', ['10.0.0.2' => []]);
        $this->http->on('GET', '/api/nodes/2/ip_list', ['10.0.0.2']);
        [$provisioner, $state] = $this->provisioner();

        $provisioner->create();

        $this->assertCount(1, $this->http->callsTo('POST', '/api/servers'), 'exactly one server is created');
        $this->assertSame(314, $state->serverId());
    }

    public function testCreateFailsLoudlyWhenThePanelReturnsNoServerId(): void
    {
        $this->stubCreateFlow();
        $this->http->on('POST', '/api/servers', ['message' => 'success']);
        [$provisioner, $state] = $this->provisioner();

        try {
            $provisioner->create();
            $this->fail('expected a state error');
        } catch (ModuleException $exception) {
            $this->assertSame(ModuleException::CODE_STATE, $exception->errorCode());
            $this->assertStringContainsString('returned no id', $exception->getMessage());
        }

        $this->assertCount(1, $this->http->callsTo('POST', '/api/servers'), 'never tries another node after a create');
        $this->assertSame(0, $state->serverId());
    }

    public function testCreateAppliesLimitsInPanelUnitsAndMergesMetadata(): void
    {
        $this->stubCreateFlow();
        [$provisioner] = $this->provisioner();

        $provisioner->create();

        $patch = $this->http->callsTo('PUT', '/api/servers/314')[0]['body'];

        // 200 % of a core is 2000 millicores; 4096 MB is bytes on the panel.
        $this->assertSame(2000, $patch['cpu_limit']);
        $this->assertSame(4096 * self::MB, $patch['ram_limit']);

        // Metadata is a whole-record replace on the panel side, so anything
        // already there has to survive the write.
        $this->assertSame('gameap/debian', $patch['metadata']['docker_image']);
        $this->assertSame('1042', $patch['metadata']['whmcs_service_id']);
        $this->assertSame('55', $patch['metadata']['whmcs_client_id']);

        // The required fields are echoed back, or the panel would reject it.
        $this->assertSame('cs2 #1042', $patch['name']);
        $this->assertSame(1, $patch['ds_id']);
    }

    public function testCreateGrantsPermissionsOnTheServer(): void
    {
        $this->stubCreateFlow();
        [$provisioner] = $this->provisioner();

        $provisioner->create();

        $granted = $this->http->callsTo('PUT', '/api/users/77/servers/314/permissions')[0]['body'];

        $this->assertNotEmpty($granted);
        foreach ($granted as $entry) {
            $this->assertTrue($entry['value']);
        }

        $names = array_column($granted, 'permission');
        $this->assertContains('game-server-start', $names);
        $this->assertContains('game-server-pause', $names);
        $this->assertContains('game-server-console-view', $names);
        $this->assertCount(14, $names, 'the default set is the panel\'s full built-in catalogue');
    }

    public function testCreateAttachesTheServerThroughTheDedicatedRoute(): void
    {
        $this->stubCreateFlow();
        [$provisioner] = $this->provisioner();

        $provisioner->create();

        // PUT /api/users/{id} replaces the whole server list and role set;
        // the attach route touches nothing else the customer owns.
        $this->assertTrue($this->http->called('PUT', '/api/users/77/servers/314'));
        $this->assertFalse($this->http->called('PUT', '/api/users/77'));
    }

    public function testCreateDoesNotCreateASecondServerWhenOneIsLinked(): void
    {
        $this->stubCreateFlow();
        $this->linkServer();
        [$provisioner] = $this->provisioner();

        $provisioner->create();

        // WHMCS retries setup, and a second paid-for server must never
        // appear because of it — but the finishing steps still converge.
        $this->assertFalse($this->http->called('POST', '/api/servers'));
        $this->assertFalse($this->http->called('POST', '/api/users'));
        $this->assertTrue($this->http->called('PUT', '/api/servers/314'));
        $this->assertTrue($this->http->called('PUT', '/api/users/77/servers/314'));
        $this->assertTrue($this->http->called('PUT', '/api/users/77/servers/314/permissions'));
    }

    public function testCreateResumesAfterAFailedFinishingStep(): void
    {
        $this->stubCreateFlow();
        $this->http->onEach('PUT', '/api/servers/314', [
            new ModuleException(ModuleException::CODE_PANEL, 'panel restarted', 502),
            ['status' => 'ok'],
        ]);
        [$provisioner, $state] = $this->provisioner();

        try {
            $provisioner->create();
            $this->fail('the first attempt should surface the panel error');
        } catch (ModuleException $exception) {
            $this->assertSame('panel restarted', $exception->getMessage());
        }

        // The ids were recorded the moment the panel handed them out …
        $this->assertSame(314, $state->serverId());
        $this->assertSame(77, $state->userId());

        // … so the retry finishes the job without a second server or user.
        $provisioner->create();

        $this->assertCount(1, $this->http->callsTo('POST', '/api/servers'));
        $this->assertCount(1, $this->http->callsTo('POST', '/api/users'));
        $this->assertTrue($this->http->called('PUT', '/api/users/77/servers/314/permissions'));
    }

    public function testCreateRecordsTheNewAccountBeforeAnythingElseCanFail(): void
    {
        $this->stubCreateFlow();
        $this->http->on('GET', '/api/games/cs2/mods', new ModuleException(ModuleException::CODE_TRANSPORT, 'timeout'));
        [$provisioner, $state] = $this->provisioner();

        try {
            $provisioner->create();
            $this->fail('expected the transport error');
        } catch (ModuleException $exception) {
            $this->assertSame(ModuleException::CODE_TRANSPORT, $exception->errorCode());
        }

        // A retry would find the account by email, but the generated password
        // would be gone with it.
        $this->assertSame(77, $state->userId());
    }

    public function testCreateReusesAPanelAccountFoundByEmail(): void
    {
        $this->stubCreateFlow();
        $this->http->on('GET', '/api/users', [['id' => 77, 'login' => 'ann', 'email' => 'ANN@example.com']]);
        [$provisioner, $state] = $this->provisioner();

        $provisioner->create();

        // Matching is case-insensitive on our side, and no duplicate account
        // is created for a returning customer.
        $this->assertFalse($this->http->called('POST', '/api/users'));
        $this->assertSame(77, $state->userId());
    }

    public function testCreateIgnoresAnUnrelatedAccountInTheSearchResult(): void
    {
        // The panel answers with someone else.
        $this->stubCreateFlow();
        $this->http->on('GET', '/api/users', [['id' => 999, 'login' => 'bob', 'email' => 'bob@example.com']]);
        [$provisioner] = $this->provisioner();

        $provisioner->create();

        // Binding the service to whichever user came back first is a real bug
        // in other WHMCS modules; a new account is created instead.
        $this->assertTrue($this->http->called('POST', '/api/users'));
        $this->assertTrue($this->http->called('PUT', '/api/users/77/servers/314'));
    }

    public function testCreateTriesTheNextNodeWhenPlacementFails(): void
    {
        $this->stubCreateFlow();
        $this->http->on('GET', '/api/nodes', [
            ['id' => 1, 'name' => 'eu-1', 'enabled' => true],
            ['id' => 2, 'name' => 'eu-2', 'enabled' => true],
        ]);
        // Node 1 is full; node 2 has room.
        $this->http->on('GET', '/api/nodes/1/busy_ports', ['10.0.0.1' => range(27000, 27100)]);
        $this->http->on('GET', '/api/nodes/2/busy_ports', ['10.0.0.2' => []]);
        $this->http->on('GET', '/api/nodes/2/ip_list', ['10.0.0.2']);
        [$provisioner] = $this->provisioner();

        $provisioner->create();

        $create = $this->http->callsTo('POST', '/api/servers')[0]['body'];
        $this->assertSame(2, $create['ds_id']);
        $this->assertSame('10.0.0.2', $create['server_ip']);
    }

    public function testCreateStopsImmediatelyWhenTheCreateCallIsRefused(): void
    {
        $this->stubCreateFlow();
        $this->http->on('GET', '/api/nodes', [
            ['id' => 1, 'name' => 'eu-1', 'enabled' => true],
            ['id' => 2, 'name' => 'eu-2', 'enabled' => true],
        ]);
        $this->http->on('GET', '/api/nodes/2/busy_ports', ['10.0.0.2' => []]);
        $this->http->on('GET', '/api/nodes/2/ip_list', ['10.0.0.2']);
        $this->http->on(
            'POST',
            '/api/servers',
            new ModuleException(ModuleException::CODE_FORBIDDEN, 'missing ability', 403)
        );
        [$provisioner] = $this->provisioner();

        // Retrying every node would bury the real cause under a "no capacity"
        // message — and the panel never answers 409, so there is nothing a
        // second node could fix.
        $this->expectException(ModuleException::class);
        $this->expectExceptionMessage('missing ability');

        $provisioner->create();
    }

    public function testCreateTellsAnUnknownGameFromAGameWithoutMods(): void
    {
        $this->stubCreateFlow();
        $this->http->on('GET', '/api/games/cs2/mods', []);
        $this->http->on('GET', '/api/games', [['code' => 'minecraft']]);
        [$provisioner] = $this->provisioner();

        try {
            $provisioner->create();
            $this->fail('expected a config error');
        } catch (ModuleException $exception) {
            $this->assertStringContainsString('does not exist', $exception->getMessage());
        }

        $this->http->on('GET', '/api/games', [['code' => 'cs2']]);
        [$provisioner] = $this->provisioner();

        try {
            $provisioner->create();
            $this->fail('expected a config error');
        } catch (ModuleException $exception) {
            $this->assertStringContainsString('has no mods', $exception->getMessage());
        }
    }

    public function testCreateRefusesABrokenProductBeforeTouchingThePanel(): void
    {
        [$provisioner] = $this->provisioner(['configoption8' => '28000-27000']);

        try {
            $provisioner->create();
            $this->fail('expected a config error');
        } catch (ModuleException $exception) {
            $this->assertStringContainsString('Port range', $exception->getMessage());
        }

        $this->assertSame([], $this->http->calls, 'the product is validated before the panel is contacted');
    }

    // -----------------------------------------------------------------
    // Suspend / unsuspend
    // -----------------------------------------------------------------

    public function testSuspendStopsAndBlocksAndMarksTheBlockAsItsOwn(): void
    {
        $this->linkServer();
        $this->stubServerRead(['metadata' => ['docker_image' => 'gameap/debian']]);
        $this->http->on('POST', '/api/servers/314/stop', ['gdaemonTaskId' => 1]);
        [$provisioner] = $this->provisioner();

        $provisioner->suspend();

        $this->assertTrue($this->http->called('POST', '/api/servers/314/stop'));

        $patch = $this->http->callsTo('PUT', '/api/servers/314')[0]['body'];
        $this->assertTrue($patch['blocked']);
        $this->assertTrue($patch['metadata'][Provisioner::METADATA_BLOCKED]);
        $this->assertSame('gameap/debian', $patch['metadata']['docker_image']);
    }

    public function testSuspendBlockOnlyLeavesTheServerRunning(): void
    {
        $this->linkServer();
        $this->stubServerRead();
        [$provisioner] = $this->provisioner(['configoption17' => 'block_only']);

        $provisioner->suspend();

        $this->assertFalse($this->http->called('POST', '/api/servers/314/stop'));
        $this->assertTrue($this->http->callsTo('PUT', '/api/servers/314')[0]['body']['blocked']);
    }

    public function testSuspendStopOnlyDoesNotBlock(): void
    {
        $this->linkServer();
        $this->http->on('POST', '/api/servers/314/stop', []);
        [$provisioner] = $this->provisioner(['configoption17' => 'stop_only']);

        $provisioner->suspend();

        $this->assertTrue($this->http->called('POST', '/api/servers/314/stop'));
        $this->assertFalse($this->http->called('PUT', '/api/servers/314'));
    }

    public function testUnsuspendLiftsTheBlockAndClearsTheMark(): void
    {
        $this->linkServer();
        $this->stubServerRead(['blocked' => true, 'metadata' => [Provisioner::METADATA_BLOCKED => true]]);
        [$provisioner] = $this->provisioner();

        $provisioner->unsuspend();

        $patch = $this->http->callsTo('PUT', '/api/servers/314')[0]['body'];
        $this->assertFalse($patch['blocked']);
        // Written as false, not removed: the panel replaces metadata wholesale
        // and the module merges, so a key can only ever be changed.
        $this->assertFalse($patch['metadata'][Provisioner::METADATA_BLOCKED]);
        $this->assertFalse($this->http->called('POST', '/api/servers/314/start'));
    }

    public function testUnsuspendStartsTheServerWhenTheProductSaysSo(): void
    {
        $this->linkServer();
        $this->stubServerRead(['blocked' => true]);
        $this->http->on('POST', '/api/servers/314/start', []);
        [$provisioner] = $this->provisioner(['configoption16' => 'on']);

        $provisioner->unsuspend();

        $this->assertTrue($this->http->called('POST', '/api/servers/314/start'));
    }

    public function testUpdatesEchoTheInternalAddressNotThePublishedOne(): void
    {
        $this->linkServer();
        $this->stubServerRead([
            'server_ip' => '203.0.113.5',
            'internal_server_ip' => '10.0.0.1',
            'metadata' => ['public_ip' => '203.0.113.5'],
        ]);
        $this->http->on('POST', '/api/servers/314/stop', []);
        [$provisioner] = $this->provisioner();

        $provisioner->suspend();

        // server_ip in the panel's answer is the address customers see; the
        // one the server binds to must not be overwritten with it.
        $this->assertSame('10.0.0.1', $this->http->callsTo('PUT', '/api/servers/314')[0]['body']['server_ip']);
    }

    // -----------------------------------------------------------------
    // Terminate
    // -----------------------------------------------------------------

    public function testTerminateRemovesTheServerWithItsFilesAndClearsTheLink(): void
    {
        $this->linkServer();
        $this->stubServerRead();
        $this->http
            ->on('DELETE', '/api/users/77/servers/314', [])
            ->on('DELETE', '/api/servers/314', []);
        [$provisioner, $state] = $this->provisioner();

        $provisioner->terminate();

        $this->assertTrue($this->http->called('DELETE', '/api/users/77/servers/314'));

        // The panel queues its own stop-and-delete; a stop sent first would
        // leave a task that makes it refuse the delete.
        $this->assertFalse($this->http->called('POST', '/api/servers/314/stop'));
        $this->assertSame(['delete_files' => true], $this->http->callsTo('DELETE', '/api/servers/314')[0]['body']);

        $this->assertSame(0, $state->serverId());
    }

    public function testTerminateToleratesAnAccountItMayNotTouch(): void
    {
        $this->linkServer();
        $this->stubServerRead();
        $this->http
            ->on('DELETE', '/api/users/77/servers/314', new ModuleException(
                ModuleException::CODE_FORBIDDEN,
                'refused',
                403,
                null,
                'personal access tokens cannot modify administrators'
            ))
            ->on('DELETE', '/api/servers/314', []);
        [$provisioner, $state] = $this->provisioner();

        $provisioner->terminate();

        $this->assertTrue($this->http->called('DELETE', '/api/servers/314'));
        $this->assertSame(0, $state->serverId());
    }

    public function testTerminateCanKeepTheServer(): void
    {
        $this->linkServer();
        $this->stubServerRead();
        $this->http
            ->on('POST', '/api/servers/314/stop', [])
            ->on('PUT', '/api/users/77/servers/314/permissions', []);
        [$provisioner] = $this->provisioner(['configoption18' => 'disable_only']);

        $provisioner->terminate();

        $this->assertFalse($this->http->called('DELETE', '/api/servers/314'));
        $this->assertTrue($this->http->called('POST', '/api/servers/314/stop'));

        $revoked = $this->http->callsTo('PUT', '/api/users/77/servers/314/permissions')[0]['body'];
        foreach ($revoked as $entry) {
            $this->assertFalse($entry['value']);
        }

        $patch = $this->http->callsTo('PUT', '/api/servers/314')[0]['body'];
        $this->assertTrue($patch['blocked']);
        $this->assertFalse($patch['enabled']);
    }

    public function testTerminateWithoutAServerIsANoOp(): void
    {
        [$provisioner] = $this->provisioner();

        $provisioner->terminate();

        $this->assertSame([], $this->http->calls);
    }

    public function testTerminateForgetsAServerThatIsAlreadyGone(): void
    {
        $this->linkServer();
        $this->http->on('GET', '/api/servers/314', new ModuleException(ModuleException::CODE_NOT_FOUND, 'gone', 404));
        [$provisioner, $state] = $this->provisioner();

        $provisioner->terminate();

        $this->assertFalse($this->http->called('DELETE', '/api/servers/314'));
        $this->assertSame(0, $state->serverId());
    }

    // -----------------------------------------------------------------
    // Change package
    // -----------------------------------------------------------------

    public function testChangePackageLeavesTheGameAlone(): void
    {
        $this->linkServer();
        $this->stubServerRead();
        $this->http
            ->on('PUT', '/api/servers/314/settings', [])
            ->on('PUT', '/api/users/77/servers/314/permissions', []);
        [$provisioner] = $this->provisioner(['configoption5' => '8192']);

        $provisioner->changePackage();

        // A downgrade must not silently switch the customer's game, which is
        // what re-sending the resolved game id would risk.
        $patch = $this->http->callsTo('PUT', '/api/servers/314')[0]['body'];

        $this->assertSame(8192 * self::MB, $patch['ram_limit']);
        $this->assertSame('cs2', $patch['game_id'], 'echoed from the current server, not re-resolved');
        $this->assertSame(4, $patch['game_mod_id']);
        $this->assertFalse($this->http->called('GET', '/api/games/cs2/mods'));

        $settings = $this->http->callsTo('PUT', '/api/servers/314/settings')[0]['body'];
        $this->assertSame([['name' => 'maxplayers', 'value' => '24']], $settings);
    }

    public function testChangePackageCanRemoveALimitButLeavesABlankOneAlone(): void
    {
        $this->linkServer();
        $this->stubServerRead();
        $this->http
            ->on('PUT', '/api/servers/314/settings', [])
            ->on('PUT', '/api/users/77/servers/314/permissions', []);

        // An explicit 0 means "no limit" on the panel; blank means "not ours to change".
        [$provisioner] = $this->provisioner(['configoption5' => '0', 'configoption6' => '']);

        $provisioner->changePackage();

        $patch = $this->http->callsTo('PUT', '/api/servers/314')[0]['body'];
        $this->assertSame(0, $patch['ram_limit']);
        $this->assertArrayNotHasKey('cpu_limit', $patch);
    }

    // -----------------------------------------------------------------
    // Reconcile
    // -----------------------------------------------------------------

    public function testReconcileBlocksASuspendedServiceThatIsStillOpen(): void
    {
        $this->linkServer();
        $this->stubServerRead(['blocked' => false, 'online' => true]);
        $this->http->on('POST', '/api/servers/314/stop', []);
        [$provisioner] = $this->provisioner();

        $note = $provisioner->reconcile('Suspended');

        $this->assertStringContainsString('stopped and blocked server #314', (string) $note);
        $this->assertTrue($this->http->called('POST', '/api/servers/314/stop'));

        $patch = $this->http->callsTo('PUT', '/api/servers/314')[0]['body'];
        $this->assertTrue($patch['blocked']);
        $this->assertTrue($patch['metadata'][Provisioner::METADATA_BLOCKED]);
    }

    public function testReconcileMirrorsTheSuspendMode(): void
    {
        $this->linkServer();
        $this->stubServerRead(['blocked' => false, 'online' => true]);
        $this->http->on('POST', '/api/servers/314/stop', []);
        [$provisioner] = $this->provisioner(['configoption17' => 'stop_only']);

        $note = $provisioner->reconcile('Suspended');

        // stop_only promised the merchant the server is never blocked.
        $this->assertStringContainsString('stopped server #314', (string) $note);
        $this->assertFalse($this->http->called('PUT', '/api/servers/314'));
    }

    public function testReconcileDoesNotStopAServerThatIsAlreadyDown(): void
    {
        $this->linkServer();
        $this->stubServerRead([
            'blocked' => true,
            'online' => false,
            'metadata' => [Provisioner::METADATA_BLOCKED => true],
        ]);
        [$provisioner] = $this->provisioner();

        $this->assertNull($provisioner->reconcile('Suspended'));
        $this->assertFalse($this->http->called('POST', '/api/servers/314/stop'));
    }

    public function testReconcileUnblocksOnlyABlockItPlacedItself(): void
    {
        $this->linkServer();
        $this->stubServerRead(['blocked' => true, 'metadata' => [Provisioner::METADATA_BLOCKED => true]]);
        [$provisioner] = $this->provisioner();

        $note = $provisioner->reconcile('Active');

        // A customer who stopped their own server must not find it running
        // again because a cron job ran.
        $this->assertStringContainsString('unblocked server #314', (string) $note);
        $this->assertFalse($this->http->callsTo('PUT', '/api/servers/314')[0]['body']['blocked']);
        $this->assertFalse($this->http->called('POST', '/api/servers/314/start'));
    }

    public function testReconcileLeavesAnAdministratorsBlockAlone(): void
    {
        $this->linkServer();
        $this->stubServerRead(['blocked' => true, 'metadata' => []]);
        [$provisioner] = $this->provisioner();

        $note = $provisioner->reconcile('Active');

        $this->assertStringContainsString('left alone', (string) $note);
        $this->assertFalse($this->http->called('PUT', '/api/servers/314'));
    }

    public function testReconcileDoesNothingWhenStateAgrees(): void
    {
        $this->linkServer();
        $this->stubServerRead(['blocked' => false]);
        [$provisioner] = $this->provisioner();

        $this->assertNull($provisioner->reconcile('Active'));
        $this->assertFalse($this->http->called('PUT', '/api/servers/314'));
    }

    public function testReconcileReportsAServerThatDisappeared(): void
    {
        $this->linkServer();
        $this->http->on('GET', '/api/servers/314', new ModuleException(ModuleException::CODE_NOT_FOUND, 'gone', 404));
        [$provisioner] = $this->provisioner();

        $this->assertStringContainsString('no longer exists', (string) $provisioner->reconcile('Active'));
    }

    // -----------------------------------------------------------------
    // Status
    // -----------------------------------------------------------------

    public function testStatusReportsLimitsInHumanUnits(): void
    {
        $this->linkServer();
        $this->http->on('GET', '/api/servers/314', $this->server([
            'cpu_limit' => 2000,
            'ram_limit' => 4096 * self::MB,
            'installed' => 2,
            'game' => ['code' => 'cs2', 'name' => 'Counter-Strike 2'],
        ]));
        [$provisioner] = $this->provisioner();

        $status = $provisioner->status();

        $this->assertSame(200, $status['data']['cpu_limit']);
        $this->assertSame(4096, $status['data']['ram_limit']);
        $this->assertSame(2, $status['data']['installed']);
        $this->assertSame('Counter-Strike 2', $status['data']['game']);
        $this->assertSame('10.0.0.1:27000', $status['data']['address']);
    }

    public function testStatusServesTheSnapshotWhenThePanelIsDown(): void
    {
        $this->linkServer();
        $this->model->serviceProperties->save([
            ServiceState::FIELD_SNAPSHOT => json_encode([
                'at' => time() - 3600,
                'data' => ['address' => '10.0.0.1:27000'],
            ]),
        ]);
        $this->http->on('GET', '/api/servers/314', new ModuleException(ModuleException::CODE_TRANSPORT, 'down'));
        [$provisioner] = $this->provisioner();

        $status = $provisioner->status();

        $this->assertTrue($status['stale']);
        $this->assertSame('10.0.0.1:27000', $status['data']['address']);
    }
}
