<?php

namespace WHMCS\Module\Server\Gameap;

/**
 * Typed access to the GameAP panel REST API (GameAP 4.5.0 or newer).
 *
 * Every method here maps to one endpoint the provisioning flow needs. Keeping
 * the mapping in one class — rather than assembling paths at each call site —
 * is what makes the required token abilities auditable: the probes in
 * Diagnostics are derived from these methods.
 */
class PanelClient
{
    private HttpClient $http;

    public function __construct(HttpClient $http)
    {
        $this->http = $http;
    }

    // -----------------------------------------------------------------
    // Users
    // -----------------------------------------------------------------

    /**
     * Exact-match lookup. The panel lower-cases addresses on both sides and
     * turns a repeated filter into an IN (...), so sending the address as the
     * biller stores it plus its lower-cased form costs nothing and covers a
     * panel that predates the normalisation.
     *
     * @return array<string,mixed>|null
     */
    public function findUserByEmail(string $email): ?array
    {
        $spellings = array_values(array_unique(array_filter([$email, mb_strtolower($email)])));

        $users = $this->http->request('GET', '/api/users', null, ['filter[email]' => $spellings]);

        foreach ($users as $user) {
            if (!is_array($user) || !isset($user['email'])) {
                continue;
            }

            // Defensive: never accept "some user came back" as "the right
            // user came back". Binding a service to whichever account the
            // panel listed first is a real bug in other WHMCS modules.
            if (mb_strtolower((string) $user['email']) === mb_strtolower($email)) {
                return $user;
            }
        }

        return null;
    }

    /**
     * @return array<string,mixed>
     */
    public function getUser(int $userId): array
    {
        return $this->http->request('GET', '/api/users/' . $userId);
    }

    /**
     * @param array<string,mixed> $payload
     *
     * @return array<string,mixed>
     */
    public function createUser(array $payload): array
    {
        return $this->http->request('POST', '/api/users', $payload);
    }

    /**
     * Idempotent: attaching a server that is already attached is a no-op on
     * the panel side. This is deliberately used instead of PUT /api/users/{id},
     * which replaces the user's whole server list and role set.
     */
    public function attachServerToUser(int $userId, int $serverId): void
    {
        $this->http->request('PUT', '/api/users/' . $userId . '/servers/' . $serverId);
    }

    public function detachServerFromUser(int $userId, int $serverId): void
    {
        $this->http->request('DELETE', '/api/users/' . $userId . '/servers/' . $serverId);
    }

    /**
     * @param array<string,bool> $permissions
     */
    public function setServerPermissions(int $userId, int $serverId, array $permissions): void
    {
        $payload = [];
        foreach ($permissions as $permission => $value) {
            $payload[] = ['permission' => $permission, 'value' => $value];
        }

        $this->http->request(
            'PUT',
            '/api/users/' . $userId . '/servers/' . $serverId . '/permissions',
            $payload
        );
    }

    // -----------------------------------------------------------------
    // Servers
    // -----------------------------------------------------------------

    /**
     * The panel answers {"message":"success","result":{"taskId":N,"serverId":N}};
     * the id is lifted to the top level so callers never depend on that
     * envelope. A bare "id" is accepted too in case a later panel flattens it.
     *
     * @param array<string,mixed> $payload
     *
     * @return array{id:int,task_id:int}
     */
    public function createServer(array $payload): array
    {
        $response = $this->http->request('POST', '/api/servers', $payload);

        $result = is_array($response['result'] ?? null) ? $response['result'] : [];

        return [
            'id' => (int) ($result['serverId'] ?? $response['id'] ?? 0),
            'task_id' => (int) ($result['taskId'] ?? 0),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function getServer(int $serverId): array
    {
        return $this->http->request('GET', '/api/servers/' . $serverId);
    }

    /**
     * @param array<string,mixed> $payload
     *
     * @return array<string,mixed>
     */
    public function updateServer(int $serverId, array $payload): array
    {
        return $this->http->request('PUT', '/api/servers/' . $serverId, $payload);
    }

    /**
     * With delete_files the panel queues its own stop-and-delete task for the
     * node and removes the record; without it the panel refuses (409) while
     * the server is running or has a task queued, and leaves the files behind.
     */
    public function deleteServer(int $serverId, bool $deleteFiles = true): void
    {
        $this->http->request('DELETE', '/api/servers/' . $serverId, ['delete_files' => $deleteFiles]);
    }

    /**
     * @param array<string,mixed> $query
     *
     * @return array<mixed>
     */
    public function listServers(array $query = []): array
    {
        return $this->http->request('GET', '/api/servers', null, $query);
    }

    public function controlServer(int $serverId, string $action): void
    {
        $this->http->request('POST', '/api/servers/' . $serverId . '/' . $action);
    }

    /**
     * The endpoint takes a list of name/value pairs, not a map.
     *
     * @param array<string,scalar> $settings
     */
    public function updateServerSettings(int $serverId, array $settings): void
    {
        if ($settings === []) {
            return;
        }

        $payload = [];
        foreach ($settings as $name => $value) {
            $payload[] = ['name' => (string) $name, 'value' => $value];
        }

        $this->http->request('PUT', '/api/servers/' . $serverId . '/settings', $payload);
    }

    // -----------------------------------------------------------------
    // Placement inputs
    // -----------------------------------------------------------------

    /**
     * @return array<int,array<string,mixed>>
     */
    public function listNodes(): array
    {
        $nodes = $this->http->request('GET', '/api/nodes');

        return array_values(array_filter($nodes, 'is_array'));
    }

    /**
     * @return array<string,array<int,int>> ip => busy ports (game, query and RCON)
     */
    public function nodeBusyPorts(int $nodeId): array
    {
        $response = $this->http->request('GET', '/api/nodes/' . $nodeId . '/busy_ports');

        $ports = [];
        foreach ($response as $ip => $list) {
            if (!is_array($list)) {
                continue;
            }

            $ports[(string) $ip] = array_map('intval', array_values($list));
        }

        return $ports;
    }

    /**
     * @return array<int,string>
     */
    public function nodeIpList(int $nodeId): array
    {
        $response = $this->http->request('GET', '/api/nodes/' . $nodeId . '/ip_list');

        $ips = [];
        foreach ($response as $ip) {
            if (is_string($ip) && $ip !== '') {
                $ips[] = $ip;
            }
        }

        return $ips;
    }

    // -----------------------------------------------------------------
    // Catalogue
    // -----------------------------------------------------------------

    /**
     * @return array<int,array<string,mixed>>
     */
    public function listGames(): array
    {
        $games = $this->http->request('GET', '/api/games');

        return array_values(array_filter($games, 'is_array'));
    }

    /**
     * Sorted by name by the panel; an unknown game code yields an empty list
     * rather than a 404.
     *
     * @return array<int,array<string,mixed>>
     */
    public function listGameMods(string $gameCode): array
    {
        $mods = $this->http->request('GET', '/api/games/' . rawurlencode($gameCode) . '/mods');

        return array_values(array_filter($mods, 'is_array'));
    }

    // -----------------------------------------------------------------
    // Single sign-on
    // -----------------------------------------------------------------

    /**
     * @return array<string,mixed> {ticket, expires_in, redirect_to}
     */
    public function issueSsoTicket(int $userId, string $redirectTo = '', string $clientIp = ''): array
    {
        $payload = ['user_id' => $userId];

        if ($redirectTo !== '') {
            $payload['redirect_to'] = $redirectTo;
        }

        if ($clientIp !== '') {
            $payload['client_ip'] = $clientIp;
        }

        return $this->http->request('POST', '/api/auth/sso/tickets', $payload);
    }
}
