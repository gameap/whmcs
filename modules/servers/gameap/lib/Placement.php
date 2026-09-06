<?php

namespace WHMCS\Module\Server\Gameap;

/**
 * Chooses where a new server goes: which node, which IP address, which ports.
 *
 * The panel exposes the two facts this needs — a node's addresses and the
 * ports already taken on it — so placement is a decision, not a reimplementation
 * of the panel's bookkeeping.
 *
 * Known limitation: the panel does not check that an address:port pair is
 * unique, so if another order (or an administrator in the panel UI) claims the
 * same ports between the busy-ports read and the create call, both servers are
 * created and the collision only shows when the second one fails to bind.
 * WHMCS processes orders one at a time under normal operation, which keeps the
 * window small; the free-port scan is the only guard there is.
 */
class Placement
{
    private PanelClient $panel;

    public function __construct(PanelClient $panel)
    {
        $this->panel = $panel;
    }

    /**
     * @param array<int,string> $nodePool node names; empty means any enabled node
     *
     * @return array<int,array<string,mixed>> candidates, least loaded first
     */
    public function candidateNodes(array $nodePool): array
    {
        $wanted = array_map(static fn ($name) => mb_strtolower(trim($name)), $nodePool);

        $candidates = [];

        foreach ($this->panel->listNodes() as $node) {
            if (!($node['enabled'] ?? true)) {
                continue;
            }

            $name = (string) ($node['name'] ?? '');

            if ($wanted !== [] && !in_array(mb_strtolower($name), $wanted, true)) {
                continue;
            }

            $candidates[] = $node;
        }

        if ($candidates === []) {
            throw ModuleException::capacity(
                $nodePool === []
                    ? 'No enabled nodes are available in GameAP.'
                    : 'None of the configured nodes are available: ' . implode(', ', $nodePool) . '.'
            );
        }

        if (count($candidates) === 1) {
            return $candidates;
        }

        // One request per node, read before the sort: usort asks about the
        // same node several times, and each comparison used to be its own
        // round trip to the panel.
        $load = [];
        foreach ($candidates as $candidate) {
            $nodeId = (int) $candidate['id'];
            $load[$nodeId] ??= $this->serverCount($nodeId);
        }

        usort($candidates, static function (array $left, array $right) use ($load): int {
            $leftId = (int) $left['id'];
            $rightId = (int) $right['id'];

            $byLoad = $load[$leftId] <=> $load[$rightId];

            // Ties break on id so placement is reproducible, which matters
            // when an order fails and an administrator retries it.
            return $byLoad !== 0 ? $byLoad : ($leftId <=> $rightId);
        });

        return $candidates;
    }

    /**
     * Picks an address and a contiguous block of free ports on the node.
     *
     * @param array<string,mixed> $node
     * @param array{0:int,1:int}  $portRange
     *
     * @return array{ip:string,ports:array<int,int>}
     */
    public function allocate(int $nodeId, array $node, array $portRange, int $portsPerServer): array
    {
        $busyByIp = $this->panel->nodeBusyPorts($nodeId);

        $ips = $this->panel->nodeIpList($nodeId);
        if ($ips === []) {
            // The node list carries the addresses under "ip".
            $ips = array_values(array_filter((array) ($node['ip'] ?? $node['ips'] ?? []), 'is_string'));
        }

        if ($ips === []) {
            throw ModuleException::capacity(
                'Node "' . ($node['name'] ?? $nodeId) . '" has no IP addresses configured.'
            );
        }

        // Least crowded address first, so servers spread across the node's
        // addresses instead of piling onto the first one.
        usort($ips, static fn ($left, $right) => count($busyByIp[$left] ?? []) <=> count($busyByIp[$right] ?? []));

        [$from, $to] = $portRange;

        foreach ($ips as $ip) {
            $busy = array_flip($busyByIp[$ip] ?? []);

            for ($port = $from; $port + $portsPerServer - 1 <= $to; $port += $portsPerServer) {
                $block = range($port, $port + $portsPerServer - 1);

                if (self::blockIsFree($block, $busy)) {
                    return ['ip' => $ip, 'ports' => $block];
                }
            }
        }

        throw ModuleException::capacity(
            'No free port block of ' . $portsPerServer . ' ports in ' . $from . '-' . $to
            . ' on node "' . ($node['name'] ?? $nodeId) . '".'
        );
    }

    /**
     * @param array<int,int>  $block
     * @param array<int,int>  $busy port => index
     */
    private static function blockIsFree(array $block, array $busy): bool
    {
        foreach ($block as $port) {
            if (isset($busy[$port])) {
                return false;
            }
        }

        return true;
    }

    private function serverCount(int $nodeId): int
    {
        $response = $this->panel->listServers([
            'filter[ds_id]' => $nodeId,
            'page[size]' => 1,
        ]);

        return (int) ($response['total'] ?? 0);
    }
}
