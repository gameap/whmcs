<?php

namespace WHMCS\Module\Server\Gameap;

/**
 * What "Test Connection" actually tests.
 *
 * Reaching the panel is the easy half. The half that costs support tickets is
 * a token that works today and fails on the first order because it lacks one
 * ability, so every ability the module needs is probed here and reported by
 * name. The probes are side-effect free: the write endpoints are called with
 * an empty object, which the panel rejects at validation — after the ability
 * check, which is the part being measured.
 */
class Diagnostics
{
    /** Abilities that cannot be checked without an existing server. */
    public const UNVERIFIABLE_ABILITIES = [
        'server:start',
        'server:stop',
        'server:restart',
        'server:settings-manage',
    ];

    private PanelClient $panel;

    private HttpClient $http;

    public function __construct(HttpClient $http, PanelClient $panel)
    {
        $this->http = $http;
        $this->panel = $panel;
    }

    /**
     * @return array{ok:bool,problems:array<int,string>,notes:array<int,string>}
     */
    public function run(): array
    {
        $problems = [];
        $notes = [];

        $probes = [
            'server:list' => fn () => $this->http->request('GET', '/api/servers', null, ['page[size]' => 1]),
            'admin:node:read' => fn () => $this->http->request('GET', '/api/nodes'),
            'admin:game:read' => fn () => $this->http->request('GET', '/api/games'),
            'admin:user:read' => fn () => $this->http->request(
                'GET',
                '/api/users',
                null,
                ['filter[email]' => 'connection-test@invalid.localhost']
            ),
            // admin:server:create also covers updating and deleting servers.
            'admin:server:create' => fn () => $this->http->request('POST', '/api/servers', []),
            'admin:user:manage' => fn () => $this->http->request('POST', '/api/users', []),
            'admin:user:sso' => fn () => $this->http->request('POST', '/api/auth/sso/tickets', []),
        ];

        foreach ($probes as $ability => $probe) {
            try {
                $probe();
            } catch (ModuleException $exception) {
                if ($exception->errorCode() === ModuleException::CODE_AUTH) {
                    return [
                        'ok' => false,
                        'problems' => ['The API token was rejected. Check the server Password (or Access Hash) field.'],
                        'notes' => [],
                    ];
                }

                if ($exception->errorCode() === ModuleException::CODE_TRANSPORT) {
                    return ['ok' => false, 'problems' => [$exception->getMessage()], 'notes' => []];
                }

                if ($exception->errorCode() === ModuleException::CODE_FORBIDDEN) {
                    // The panel checks that the token's owner is an
                    // administrator before it looks at abilities at all, so
                    // this one failure would otherwise show up as every
                    // ability missing at once.
                    if ($exception->panelDetailContains('admin permissions required')) {
                        return [
                            'ok' => false,
                            'problems' => [
                                'The token belongs to a panel user who is not an administrator. '
                                . 'Create the token from an administrator account.',
                            ],
                            'notes' => [],
                        ];
                    }

                    $problems[] = 'The token is missing the "' . $ability . '" ability.';

                    continue;
                }

                // A validation error means the ability check passed and the
                // panel got as far as reading the (deliberately empty) body.
                if ($exception->httpStatus() >= 500) {
                    $problems[] = 'Panel error while checking "' . $ability . '": ' . $exception->getMessage();
                }
            }
        }

        if ($problems === []) {
            $notes = $this->inventory();
            $notes[] = 'Not verifiable without a server: ' . implode(', ', self::UNVERIFIABLE_ABILITIES) . '.';
        }

        return ['ok' => $problems === [], 'problems' => $problems, 'notes' => $notes];
    }

    /**
     * @return array<int,string>
     */
    private function inventory(): array
    {
        try {
            $nodes = $this->panel->listNodes();
            $enabled = array_filter($nodes, static fn ($node) => (bool) ($node['enabled'] ?? true));

            $notes = ['Nodes available: ' . count($enabled) . ' of ' . count($nodes) . '.'];

            if ($enabled === []) {
                $notes[] = 'No enabled nodes — provisioning will fail until one is enabled.';
            }

            return $notes;
        } catch (ModuleException $exception) {
            return [];
        }
    }
}
