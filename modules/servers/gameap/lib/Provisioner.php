<?php

namespace WHMCS\Module\Server\Gameap;

/**
 * The provisioning flow, kept out of the WHMCS entry points so it can be read
 * and tested as ordinary code.
 */
class Provisioner
{
    /**
     * Granted to the customer on their own server when the product does not
     * name a set explicitly. Deliberately excludes anything that would let one
     * customer affect another: everything here is scoped to the one server.
     * Matches the panel's built-in server permission catalogue.
     */
    public const DEFAULT_PERMISSIONS = [
        'game-server-common',
        'game-server-start',
        'game-server-stop',
        'game-server-restart',
        'game-server-pause',
        'game-server-update',
        'game-server-files',
        'game-server-tasks',
        'game-server-settings',
        'game-server-console-view',
        'game-server-console-send',
        'game-server-rcon-console',
        'game-server-rcon-players',
        'game-server-metrics',
    ];

    /**
     * Server metadata key set when this module blocks a server. The nightly
     * sweep only ever lifts a block that carries it, so a server an
     * administrator blocked by hand in the panel stays blocked.
     */
    public const METADATA_BLOCKED = 'whmcs_blocked';

    /** How long a client-area status snapshot is served before refreshing. */
    private const SNAPSHOT_TTL = 60;

    /** The panel counts CPU in millicores: 100 % = one core = 1000. */
    private const MILLICORES_PER_PERCENT = 10;

    /** The panel counts RAM in bytes. */
    private const BYTES_PER_MB = 1048576;

    private PanelClient $panel;

    private Config $config;

    private ServiceState $state;

    /** @var array<string,mixed> */
    private array $params;

    /**
     * @param array<string,mixed> $params
     */
    public function __construct(PanelClient $panel, Config $config, ServiceState $state, array $params)
    {
        $this->panel = $panel;
        $this->config = $config;
        $this->state = $state;
        $this->params = $params;
    }

    // -----------------------------------------------------------------
    // Lifecycle
    // -----------------------------------------------------------------

    /**
     * Converges rather than runs once. WHMCS retries a failed setup and an
     * administrator can press Create again, so every step either finds its
     * work already done or does it, and the ids are recorded the moment the
     * panel hands them out: a second server for the same paid service is the
     * worst outcome this module could produce.
     */
    public function create(): void
    {
        $errors = $this->config->validate();
        if ($errors !== []) {
            throw ModuleException::config(implode(' ', $errors));
        }

        $client = $this->clientDetails();

        $userId = $this->ensureUser($client);
        $serverId = $this->ensureServer($client);

        $this->finish($serverId, $userId);
    }

    public function suspend(): void
    {
        $serverId = $this->requireServerId();
        $mode = $this->config->suspendMode();

        if ($mode !== Config::SUSPEND_BLOCK_ONLY) {
            $this->stopQuietly($serverId);
        }

        if ($mode !== Config::SUSPEND_STOP_ONLY) {
            $this->block($serverId);
        }
    }

    /**
     * Lifts the block unconditionally: unsuspending is an explicit decision in
     * the billing system, unlike the nightly sweep, which only undoes its own
     * blocks.
     */
    public function unsuspend(): void
    {
        $serverId = $this->requireServerId();

        $this->unblock($serverId);

        if ($this->config->bool('start_after_install')) {
            $this->startQuietly($serverId);
        }
    }

    public function terminate(): void
    {
        $serverId = $this->state->serverId();
        if ($serverId === 0) {
            // Nothing was ever provisioned; terminating is a no-op rather than
            // an error, so a cancelled order does not stick in WHMCS.
            return;
        }

        if ($this->existingServer() === null) {
            // Already gone from the panel: only the link is left to clear.
            $this->forgetServer();

            return;
        }

        if ($this->config->terminateMode() === Config::TERMINATE_DISABLE_ONLY) {
            $this->stopQuietly($serverId);
            $this->revokePermissions($serverId);
            $this->patchServer($serverId, [
                'blocked' => true,
                'enabled' => false,
                'metadata' => [self::METADATA_BLOCKED => true],
            ]);

            return;
        }

        $this->detachFromUser($serverId);

        // The panel queues its own stop-and-delete task for the node; sending
        // a stop first would only leave a queued task that makes the panel
        // refuse the delete.
        try {
            $this->panel->deleteServer($serverId, true);
        } catch (ModuleException $exception) {
            if (!$exception->isNotFound()) {
                throw $exception;
            }
        }

        $this->forgetServer();
    }

    public function changePackage(): void
    {
        $serverId = $this->requireServerId();

        $changes = $this->limitChanges();

        $name = $this->serverName();
        if ($name !== '') {
            $changes['name'] = $name;
        }

        // The game and mod are deliberately left alone. Re-sending them on a
        // downgrade is how a customer's world silently becomes a different
        // game in other WHMCS modules; changing them is a reinstall, which is
        // an explicit administrator decision, not a side effect of a package
        // change.
        $this->patchServer($serverId, $changes);
        $this->panel->updateServerSettings($serverId, $this->modSettings());
        $this->grantPermissions($serverId, $this->state->userId());
    }

    /**
     * Brings the panel in line with what WHMCS believes about the service.
     *
     * Deliberately narrow: it mirrors what suspend() would do for the
     * product's suspend mode, and lifts only a block this module placed. It
     * never starts a server — a customer who stopped their own server should
     * not find it running again because a cron job ran — and it never
     * creates or deletes anything. Drift that needs a decision is reported,
     * not guessed at.
     *
     * @return string|null what was corrected or noticed, or null when nothing was
     */
    public function reconcile(string $whmcsStatus): ?string
    {
        $serverId = $this->state->serverId();
        if ($serverId === 0) {
            return $whmcsStatus === 'Active' ? 'active service has no server in GameAP' : null;
        }

        try {
            $server = $this->panel->getServer($serverId);
        } catch (ModuleException $exception) {
            if ($exception->isNotFound()) {
                return 'server #' . $serverId . ' no longer exists in GameAP';
            }

            throw $exception;
        }

        $blocked = (bool) ($server['blocked'] ?? false);
        $online = (bool) ($server['online'] ?? false);
        $mode = $this->config->suspendMode();

        if ($whmcsStatus === 'Suspended') {
            $done = [];

            if ($mode !== Config::SUSPEND_BLOCK_ONLY && $online) {
                $this->stopQuietly($serverId);
                $done[] = 'stopped';
            }

            if ($mode !== Config::SUSPEND_STOP_ONLY && !$blocked) {
                $this->block($serverId);
                $done[] = 'blocked';
            }

            if ($done === []) {
                return null;
            }

            return 'suspended service was still accessible; ' . implode(' and ', $done) . ' server #' . $serverId;
        }

        if ($whmcsStatus === 'Active' && $blocked) {
            if (!self::blockedByModule($server)) {
                return 'server #' . $serverId . ' is blocked in the panel by an administrator; left alone';
            }

            $this->unblock($serverId);

            return 'active service was blocked; unblocked server #' . $serverId;
        }

        return null;
    }

    public function power(string $action): void
    {
        $this->panel->controlServer($this->requireServerId(), $action);
    }

    // -----------------------------------------------------------------
    // Reads
    // -----------------------------------------------------------------

    /**
     * Client-area status. The panel is contacted at most once a minute, and
     * the caller hands in a client with a short timeout, so a slow or
     * unreachable panel cannot make the customer's service page hang: the
     * previous snapshot is shown instead, flagged as stale.
     *
     * @return array<string,mixed>
     */
    public function status(bool $force = false): array
    {
        $cached = $this->state->snapshot();
        $age = $cached === null ? null : time() - (int) ($cached['at'] ?? 0);

        if (!$force && $cached !== null && $age !== null && $age < self::SNAPSHOT_TTL) {
            return ['data' => $cached['data'] ?? [], 'stale' => false, 'age' => $age];
        }

        $serverId = $this->state->serverId();
        if ($serverId === 0) {
            return ['data' => [], 'stale' => false, 'age' => 0, 'missing' => true];
        }

        try {
            $server = $this->panel->getServer($serverId);
        } catch (ModuleException $exception) {
            return [
                'data' => $cached['data'] ?? [],
                'stale' => true,
                'age' => $age ?? 0,
                'error' => $exception->getMessage(),
            ];
        }

        $data = [
            'server_id' => $serverId,
            'name' => $server['name'] ?? '',
            'address' => self::connectAddress($server),
            'online' => (bool) ($server['online'] ?? false),
            'installed' => (int) ($server['installed'] ?? 0),
            'blocked' => (bool) ($server['blocked'] ?? false),
            'enabled' => (bool) ($server['enabled'] ?? true),
            'game' => $server['game']['name'] ?? ($server['game_id'] ?? ''),
            'cpu_limit' => self::millicoresToPercent($server['cpu_limit'] ?? null),
            'ram_limit' => self::bytesToMb($server['ram_limit'] ?? null),
        ];

        $this->state->storeSnapshot($data);

        return ['data' => $data, 'stale' => false, 'age' => 0];
    }

    /**
     * @return array<string,mixed>|null
     */
    public function existingServer(): ?array
    {
        $serverId = $this->state->serverId();
        if ($serverId === 0) {
            return null;
        }

        try {
            return $this->panel->getServer($serverId);
        } catch (ModuleException $exception) {
            if ($exception->isNotFound()) {
                return null;
            }

            throw $exception;
        }
    }

    // -----------------------------------------------------------------
    // Create steps
    // -----------------------------------------------------------------

    /**
     * @param array<string,mixed> $client
     *
     * @return int panel user id
     */
    private function ensureUser(array $client): int
    {
        $recorded = $this->state->userId();
        if ($recorded > 0) {
            try {
                $this->panel->getUser($recorded);

                return $recorded;
            } catch (ModuleException $exception) {
                if (!$exception->isNotFound()) {
                    throw $exception;
                }
            }
        }

        $email = (string) ($client['email'] ?? '');
        if ($email === '') {
            throw ModuleException::state('The WHMCS client has no email address.');
        }

        $existing = $this->panel->findUserByEmail($email);
        if ($existing !== null) {
            $userId = (int) $existing['id'];
            $this->state->store([ServiceState::FIELD_USER_ID => (string) $userId]);

            return $userId;
        }

        $login = Naming::login($this->config->string('user_login_template'), ['id' => $this->clientId()] + $client);
        $password = Naming::password();

        $created = $this->panel->createUser([
            'login' => $login,
            'email' => $email,
            'name' => trim(($client['firstname'] ?? '') . ' ' . ($client['lastname'] ?? '')) ?: null,
            'password' => $password,
            'roles' => [],
            'servers' => [],
        ]);

        $userId = (int) ($created['id'] ?? 0);
        if ($userId === 0) {
            throw ModuleException::state('The panel accepted the new user "' . $login . '" but returned no id.');
        }

        // Recorded before anything else can fail. A retry would find the
        // account by email, but the generated password would be gone with it.
        $this->state->store([ServiceState::FIELD_USER_ID => (string) $userId]);
        $this->state->setCredentials($login, $password);

        return $userId;
    }

    /**
     * @param array<string,mixed> $client
     *
     * @return int panel server id
     */
    private function ensureServer(array $client): int
    {
        if ($this->existingServer() !== null) {
            return $this->state->serverId();
        }

        $gameCode = $this->config->string('game');
        $gameModId = $this->resolveGameMod($gameCode);

        $placement = new Placement($this->panel);
        $nodes = $placement->candidateNodes($this->config->csv('node_pool'));
        $portRange = $this->config->portRange() ?? [27000, 28000];
        $portsPerServer = $this->config->portsPerServer();

        $lastError = null;

        foreach ($nodes as $node) {
            $nodeId = (int) $node['id'];

            try {
                $allocation = $placement->allocate($nodeId, $node, $portRange, $portsPerServer);
            } catch (ModuleException $exception) {
                // A full node is the only reason to try the next one. A bad
                // token or a misconfigured product fails the same way
                // everywhere, and retrying would bury the real cause under a
                // "no capacity" message.
                if ($exception->errorCode() !== ModuleException::CODE_CAPACITY) {
                    throw $exception;
                }

                $lastError = $exception;

                continue;
            }

            $payload = $this->buildServerPayload($nodeId, $allocation, $gameCode, $gameModId, $client);
            $created = $this->panel->createServer($payload);

            $serverId = $created['id'];
            if ($serverId === 0) {
                throw ModuleException::state(
                    'The panel accepted the new server "' . $payload['name'] . '" but returned no id. '
                    . 'Check the panel and link the server to this service by hand before retrying.'
                );
            }

            // Recorded the moment the panel hands out the id. Every later step
            // is idempotent, so a retry picks up from here instead of creating
            // a second server.
            $this->state->store([
                ServiceState::FIELD_SERVER_ID => (string) $serverId,
                ServiceState::FIELD_NODE => (string) ($node['name'] ?? ''),
            ]);

            return $serverId;
        }

        throw $lastError ?? ModuleException::capacity('Could not place the server on any configured node.');
    }

    /**
     * Everything after the server exists. Safe to repeat: the update echoes
     * the current record, attaching is a no-op when already attached, and the
     * permission set is absolute.
     */
    private function finish(int $serverId, int $userId): void
    {
        $server = $this->patchServer($serverId, $this->limitChanges() + [
            'metadata' => [
                'whmcs_service_id' => (string) $this->state->serviceId(),
                'whmcs_client_id' => (string) $this->clientId(),
            ],
        ]);

        $this->panel->attachServerToUser($userId, $serverId);
        $this->grantPermissions($serverId, $userId);

        $this->state->setConnectAddress(self::connectAddress($server));
    }

    private function resolveGameMod(string $gameCode): int
    {
        $mods = $this->panel->listGameMods($gameCode);
        if ($mods === []) {
            // The panel answers an empty list for an unknown code too, so the
            // catalogue decides which of the two messages is the true one.
            $codes = array_map(static fn ($game) => (string) ($game['code'] ?? ''), $this->panel->listGames());

            throw ModuleException::config(
                in_array($gameCode, $codes, true)
                    ? 'Game "' . $gameCode . '" has no mods in GameAP.'
                    : 'Game "' . $gameCode . '" does not exist in GameAP.'
            );
        }

        $wanted = $this->config->string('game_mod');
        if ($wanted === '') {
            return (int) ($mods[0]['id'] ?? 0);
        }

        foreach ($mods as $mod) {
            if (mb_strtolower((string) ($mod['name'] ?? '')) === mb_strtolower($wanted)) {
                return (int) $mod['id'];
            }
        }

        $available = implode(', ', array_map(static fn ($mod) => (string) ($mod['name'] ?? ''), $mods));

        throw ModuleException::config(
            'Game mod "' . $wanted . '" not found for game "' . $gameCode . '". Available: ' . $available . '.'
        );
    }

    /**
     * @param array{ip:string,ports:array<int,int>} $allocation
     * @param array<string,mixed>                   $client
     *
     * @return array<string,mixed>
     */
    private function buildServerPayload(
        int $nodeId,
        array $allocation,
        string $gameCode,
        int $gameModId,
        array $client
    ): array {
        $ports = $allocation['ports'];

        $payload = [
            'name' => $this->serverName(),
            'game_id' => $gameCode,
            'game_mod_id' => $gameModId,
            'ds_id' => $nodeId,
            'server_ip' => $allocation['ip'],
            'server_port' => $ports[0],
            'install' => $this->config->bool('install_on_create', true),
        ];

        if (isset($ports[1])) {
            $payload['query_port'] = $ports[1];
        }

        if (isset($ports[2])) {
            $payload['rcon_port'] = $ports[2];
        }

        $suUser = $this->config->string('su_user');
        if ($suUser !== '') {
            $payload['su_user'] = $suUser;
        }

        $settings = $this->modSettings();
        if ($settings !== []) {
            $payload['settings'] = array_map(
                static fn ($name, $value) => ['name' => $name, 'value' => $value],
                array_keys($settings),
                array_values($settings)
            );
        }

        return $payload;
    }

    // -----------------------------------------------------------------
    // Shared steps
    // -----------------------------------------------------------------

    private function block(int $serverId): void
    {
        $this->patchServer($serverId, ['blocked' => true, 'metadata' => [self::METADATA_BLOCKED => true]]);
    }

    /**
     * The flag is written as false rather than removed: the panel replaces
     * metadata wholesale and this module merges, so a key can be changed but
     * never dropped.
     */
    private function unblock(int $serverId): void
    {
        $this->patchServer($serverId, ['blocked' => false, 'metadata' => [self::METADATA_BLOCKED => false]]);
    }

    private function detachFromUser(int $serverId): void
    {
        $userId = $this->state->userId();
        if ($userId === 0) {
            return;
        }

        try {
            $this->panel->detachServerFromUser($userId, $serverId);
        } catch (ModuleException $exception) {
            // Gone already, or an account the token may not touch (an
            // administrator's): neither should keep the server from being
            // removed, and both are in the module log.
            $tolerated = [ModuleException::CODE_NOT_FOUND, ModuleException::CODE_FORBIDDEN];
            if (!in_array($exception->errorCode(), $tolerated, true)) {
                throw $exception;
            }
        }
    }

    private function grantPermissions(int $serverId, int $userId): void
    {
        if ($userId === 0) {
            return;
        }

        $this->panel->setServerPermissions($userId, $serverId, $this->permissionMap(true));
    }

    private function revokePermissions(int $serverId): void
    {
        $userId = $this->state->userId();
        if ($userId === 0) {
            return;
        }

        try {
            $this->panel->setServerPermissions($userId, $serverId, $this->permissionMap(false));
        } catch (ModuleException $exception) {
            if (!$exception->isNotFound()) {
                throw $exception;
            }
        }
    }

    private function forgetServer(): void
    {
        $this->state->store([
            ServiceState::FIELD_SERVER_ID => '',
            ServiceState::FIELD_SNAPSHOT => '',
        ]);
        $this->state->setConnectAddress('');
    }

    /**
     * @return array<string,bool>
     */
    private function permissionMap(bool $value): array
    {
        $configured = $this->config->lines('client_permissions');
        $permissions = $configured === [] ? self::DEFAULT_PERMISSIONS : $configured;

        return array_fill_keys($permissions, $value);
    }

    /**
     * Product limits in the panel's units. A blank setting leaves the panel
     * value alone; an explicit 0 removes the limit.
     *
     * @return array<string,int>
     */
    private function limitChanges(): array
    {
        $changes = [];

        $cpu = $this->config->intOrNull('cpu_percent');
        if ($cpu !== null && $cpu >= 0) {
            $changes['cpu_limit'] = $cpu * self::MILLICORES_PER_PERCENT;
        }

        $ram = $this->config->intOrNull('ram_mb');
        if ($ram !== null && $ram >= 0) {
            $changes['ram_limit'] = $ram * self::BYTES_PER_MB;
        }

        return $changes;
    }

    /**
     * @return array<string,string>
     */
    private function modSettings(): array
    {
        $settings = $this->config->pairs('server_settings');
        $slots = $this->config->string('slots');

        foreach ($settings as $name => $value) {
            $settings[$name] = str_replace('{slots}', $slots, $value);
        }

        return $settings;
    }

    private function serverName(): string
    {
        $client = $this->clientDetails();

        return Naming::serverName($this->config->string('server_name'), [
            '{game}' => $this->config->string('game'),
            '{service_id}' => (string) $this->state->serviceId(),
            '{client_id}' => (string) $this->clientId(),
            '{client_name}' => trim(($client['firstname'] ?? '') . ' ' . ($client['lastname'] ?? '')),
            '{domain}' => (string) ($this->params['domain'] ?? ''),
        ]);
    }

    /**
     * Read-modify-write. PUT requires the identifying fields every time, so
     * they are echoed back from the current record, and metadata is merged
     * rather than replaced — the panel overwrites the whole map, and writing
     * it blind would drop keys the panel or another integration owns.
     *
     * @param array<string,mixed> $changes
     *
     * @return array<string,mixed> the record as it should now read
     */
    private function patchServer(int $serverId, array $changes): array
    {
        $server = $this->panel->getServer($serverId);

        if (isset($changes['metadata']) && is_array($changes['metadata'])) {
            $existing = is_array($server['metadata'] ?? null) ? $server['metadata'] : [];
            $changes['metadata'] = array_merge($existing, $changes['metadata']);
        }

        $payload = [
            'name' => (string) ($server['name'] ?? ''),
            'game_id' => (string) ($server['game_id'] ?? ''),
            'ds_id' => (int) ($server['ds_id'] ?? 0),
            'game_mod_id' => (int) ($server['game_mod_id'] ?? 0),
            // The panel reports the published address as server_ip (the
            // public_ip metadata when set) and the address the server binds
            // to as internal_server_ip. Only the latter may be echoed back.
            'server_ip' => (string) ($server['internal_server_ip'] ?? $server['server_ip'] ?? ''),
            'server_port' => (int) ($server['server_port'] ?? 0),
        ];

        $this->panel->updateServer($serverId, array_merge($payload, $changes));

        return array_merge($server, $changes);
    }

    private function stopQuietly(int $serverId): void
    {
        $this->controlQuietly($serverId, 'stop');
    }

    private function startQuietly(int $serverId): void
    {
        $this->controlQuietly($serverId, 'start');
    }

    /**
     * A server that is already stopped, or whose node is briefly unreachable,
     * must not block the state change the billing system asked for.
     */
    private function controlQuietly(int $serverId, string $action): void
    {
        try {
            $this->panel->controlServer($serverId, $action);
        } catch (ModuleException $exception) {
            // Recorded by the HTTP client's module log entry.
        }
    }

    private function requireServerId(): int
    {
        $serverId = $this->state->serverId();

        if ($serverId === 0) {
            throw ModuleException::state(
                'This service is not linked to a GameAP server. Create it first, or link an existing server.'
            );
        }

        return $serverId;
    }

    /**
     * @return array<string,mixed>
     */
    private function clientDetails(): array
    {
        $client = $this->params['clientsdetails'] ?? [];

        return is_array($client) ? $client : [];
    }

    /**
     * WHMCS documents the owner as $params['userid']; the id inside
     * clientsdetails is read only as a fallback.
     */
    private function clientId(): int
    {
        $client = $this->clientDetails();

        return (int) ($this->params['userid'] ?? $client['id'] ?? $client['userid'] ?? 0);
    }

    /**
     * @param array<string,mixed> $server
     */
    private static function blockedByModule(array $server): bool
    {
        $metadata = is_array($server['metadata'] ?? null) ? $server['metadata'] : [];

        return filter_var($metadata[self::METADATA_BLOCKED] ?? false, FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * @param array<string,mixed> $server
     */
    public static function connectAddress(array $server): string
    {
        $metadata = is_array($server['metadata'] ?? null) ? $server['metadata'] : [];
        $ip = (string) ($metadata['public_ip'] ?? $server['server_ip'] ?? '');
        $port = (int) ($server['server_port'] ?? 0);

        if ($ip === '') {
            return '';
        }

        return $port > 0 ? $ip . ':' . $port : $ip;
    }

    /**
     * @param mixed $millicores
     */
    public static function millicoresToPercent($millicores): ?int
    {
        if ($millicores === null || !is_numeric($millicores)) {
            return null;
        }

        return (int) round((int) $millicores / self::MILLICORES_PER_PERCENT);
    }

    /**
     * @param mixed $bytes
     */
    public static function bytesToMb($bytes): ?int
    {
        if ($bytes === null || !is_numeric($bytes)) {
            return null;
        }

        return (int) round((float) $bytes / self::BYTES_PER_MB);
    }
}
